<?php
/**
 * Redactor tests.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Tests;

use Noformat\Logger\Privacy\Redactor;

final class RedactorTest extends TestCase {

	private function redactor(): Redactor {
		$fx = self::fixture( 'redaction-vectors.json' );
		return new Redactor( $fx['sensitive_patterns'], $fx['safe_keys'], $fx['salt'] );
	}

	public function test_sensitive_keys(): void {
		$redactor = $this->redactor();
		foreach ( self::fixture( 'redaction-vectors.json' )['is_sensitive_key'] as $case ) {
			self::assertSame( $case['sensitive'], $redactor->isSensitiveKey( $case['key'] ), $case['key'] );
		}
		self::assertFalse( $redactor->isSensitiveKey( 0 ), 'integer keys are never sensitive' );
	}

	public function test_redact_value_vectors(): void {
		$redactor = $this->redactor();
		foreach ( self::fixture( 'redaction-vectors.json' )['redact_value'] as $case ) {
			$value = isset( $case['value_repeat'] ) ? str_repeat( $case['value_repeat']['char'], $case['value_repeat']['count'] ) : $case['value'];
			$expected = ! empty( $case['expected_is_input'] ) ? $value : $case['expected'];
			self::assertSame( $expected, $redactor->redactValue( $case['key'], $value ), $case['key'] );
		}
	}

	public function test_nested_keys_inside_serialized_strings_are_redacted(): void {
		$redactor = $this->redactor();
		$value    = serialize( [ 'contact' => [ 'email' => 'jane@example.com' ] ] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
		$out      = $redactor->redactValue( 'payload', $value );
		self::assertIsArray( $out );
		self::assertTrue( $out['contact']['email']['redacted'] );
		self::assertStringNotContainsString( 'jane', (string) json_encode( $out ) );
	}

	public function test_regex_patterns(): void {
		$redactor = new Redactor( [ '/^x_\d+$/' ], [], 'salt' );
		self::assertTrue( $redactor->isSensitiveKey( 'X_12' ) );
		self::assertFalse( $redactor->isSensitiveKey( 'x_a' ) );
	}

	public function test_diff_vectors(): void {
		$redactor = $this->redactor();
		foreach ( self::fixture( 'redaction-vectors.json' )['diff'] as $case ) {
			self::assertSame( $case['expected'], $redactor->diff( $case['old'], $case['new'], $case['field'] ), $case['name'] );
		}
	}

	public function test_unserialize_never_instantiates_objects(): void {
		$value = Redactor::maybeUnserialize( 'O:8:"DateTime":0:{}' );
		self::assertInstanceOf( \__PHP_Incomplete_Class::class, $value );
		self::assertFalse( Redactor::maybeUnserialize( 'b:0;' ) );
		self::assertSame( 'not serialized', Redactor::maybeUnserialize( 'not serialized' ) );
	}

	public function test_diff_is_capped(): void {
		$old     = array_fill( 0, 150, 'a' );
		$new     = array_fill( 0, 150, 'b' );
		$changes = $this->redactor()->diff( $old, $new, 'list' );
		self::assertCount( Redactor::MAX_CHANGES + 1, $changes );
		self::assertSame( '*', $changes[ Redactor::MAX_CHANGES ]['field'] );
		self::assertSame( 50, $changes[ Redactor::MAX_CHANGES ]['new']['omitted'] );
	}

	public function test_username_hash_and_content_summary(): void {
		$redactor = $this->redactor();
		$fx       = self::fixture( 'redaction-vectors.json' );
		foreach ( $fx['hash_username'] as $case ) {
			self::assertSame( $case['expected'], $redactor->hashUsername( $case['username'] ) );
		}
		foreach ( $fx['content_summary'] as $case ) {
			self::assertSame( $case['expected'], $redactor->contentSummary( $case['text'], $case['old'] ) );
		}
		self::assertSame( $redactor->hashUsername( 'Admin' ), $redactor->hashUsername( ' admin ' ) );
		self::assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $redactor->hashUsername( 'x' ) );
	}

	public function test_salt_changes_hash(): void {
		$a = new Redactor( [ 'email' ], [], 'salt-a' );
		$b = new Redactor( [ 'email' ], [], 'salt-b' );
		self::assertNotSame( $a->hash8( 'x@y.z' ), $b->hash8( 'x@y.z' ) );
	}

	public function test_fixture_patterns_match_config_defaults(): void {
		$fx = self::fixture( 'redaction-vectors.json' );
		self::assertSame( \Noformat\Logger\Config::defaultSensitiveKeys(), $fx['sensitive_patterns'] );
		self::assertSame( \Noformat\Logger\Config::defaultSafeKeys(), $fx['safe_keys'] );
	}

	public function test_diff_leaves_are_scrubbed(): void {
		$changes = $this->redactor()->diff( [ 'note' => 'old' ], [ 'note' => 'mail me: a.b@example.com' ], 'widget_text' );
		self::assertSame( 'mail me: [email]', $changes[0]['new'] );
		$serialized = serialize( [ 'msg' => 'from 10.0.0.12' ] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
		self::assertSame( [ 'msg' => 'from [ip]' ], $this->redactor()->redactValue( 'payload', $serialized ) );
	}

	public function test_gravity_forms_presigned_urls_are_redacted_in_option_diffs(): void {
		$url  = 'https://gravityforms.s3.amazonaws.com/zips/gravityforms_2.9.1.zip?AWSAccessKeyId=AKIAEXAMPLEKEY123&Expires=1700000000&Signature=abc%2Bdef%2FXYZ%3D';
		$url2 = 'https://gravityforms.s3.amazonaws.com/zips/gravityformsrecaptcha_2.2.3.zip?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIAEXAMPLE%2F20261030&X-Amz-Signature=0123abcd&Expires=1700000001';
		$old  = serialize( [ 'version' => '2.9.0', 'url' => 'https://gravityforms.s3.amazonaws.com/zips/gravityforms_2.9.0.zip?AWSAccessKeyId=AKIAOLD&Expires=1690000000&Signature=old%3D' ] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
		$new  = serialize( [ 'version' => '2.9.1', 'url' => $url, 'offerings' => [ 'gravityformsrecaptcha' => [ 'url' => $url2 ] ], 'raw' => wp_json_encode( [ 'download' => $url ] ) ] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
		$changes = $this->redactor()->diff( $old, $new, 'gform_version_info' );
		$json    = (string) json_encode( $changes );
		foreach ( [ 'AKIAEXAMPLEKEY123', 'abc%2Bdef', 'AKIAOLD', 'old%3D', '0123abcd', 'AKIAEXAMPLE%2F' ] as $secret ) {
			self::assertStringNotContainsString( $secret, $json, $secret );
		}
		$byField = array_column( $changes, 'new', 'field' );
		self::assertSame( 'https://gravityforms.s3.amazonaws.com/zips/gravityforms_2.9.1.zip?AWSAccessKeyId=[redacted]&Expires=1700000000&Signature=[redacted]', $byField['gform_version_info.url'] );
		self::assertSame( 'https://gravityforms.s3.amazonaws.com/zips/gravityformsrecaptcha_2.2.3.zip?X-Amz-Algorithm=[redacted]&X-Amz-Credential=[redacted]&X-Amz-Signature=[redacted]&Expires=1700000001', $byField['gform_version_info.offerings.gravityformsrecaptcha.url'] );
		self::assertStringContainsString( 'Expires=1700000000', $byField['gform_version_info.raw'] );
		self::assertStringContainsString( 'Signature=[redacted]', $byField['gform_version_info.raw'] );
		self::assertSame( '2.9.1', $byField['gform_version_info.version'] );
	}

	public function test_plain_urls_are_unchanged(): void {
		$redactor = $this->redactor();
		foreach ( [
			'https://example.com/shop/?page=2&orderby=price&Expires=1700000000',
			'https://example.com/path/to/file.zip',
			'https://example.com/?s=keyboard&monkey=1&passport=x&tokens=3',
			'version 2.9.1 = latest',
		] as $url ) {
			self::assertSame( $url, $redactor->redactValue( 'link', $url ), $url );
		}
	}

	public function test_mixed_query_string_parameters(): void {
		$redactor = $this->redactor();
		self::assertSame(
			'utm_source=news&token=[redacted]&page=3&API_KEY=[redacted]&sid=[redacted]&lang=de#top',
			$redactor->redactValue( 'qs', 'utm_source=news&token=t0k3n&page=3&API_KEY=k-123&sid=s1&lang=de#top' )
		);
		self::assertSame( 'key=[redacted]&a=1', $redactor->redactValue( 'qs', 'key=abc&a=1' ), 'leading parameter' );
		self::assertSame(
			'<a href="https://x.test/d?id=4&amp;sig=[redacted]&amp;x-amz-date=[redacted]">get</a>',
			$redactor->redactValue( 'html', '<a href="https://x.test/d?id=4&amp;sig=deadbeef&amp;x-amz-date=20261030">get</a>' ),
			'HTML-encoded ampersands'
		);
		self::assertSame( 'Download failed: https://s3.test/f?Signature=[redacted]', \Noformat\Logger\Errors\Support\Text::scrub( 'Download failed: https://s3.test/f?Signature=a+b/c=' ) );
	}
}
