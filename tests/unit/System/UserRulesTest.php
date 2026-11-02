<?php
/**
 * UserRules tests: changed field names, role criticality.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Tests\System;

use Brain\Monkey\Filters;
use Noformat\Logger\Listeners\Support\System\UserRules;
use Noformat\Logger\Severity;
use Noformat\Logger\Tests\TestCase;

final class UserRulesTest extends TestCase {

	public function test_changed_field_names_only(): void {
		$old = (object) [
			'ID'            => 4,
			'user_login'    => 'jane',
			'user_pass'     => '$P$old',
			'user_email'    => 'jane@example.com',
			'display_name'  => 'Jane',
			'user_url'      => '',
			'user_nicename' => 'jane',
		];
		$new = [
			'ID'            => 4,
			'user_login'    => 'jane',
			'user_pass'     => '$P$new',
			'user_email'    => 'jane@other.example',
			'display_name'  => 'Jane',
			'user_url'      => 'https://jane.example',
			'user_nicename' => 'jane',
		];
		$fields = UserRules::changedFields( $old, $new );
		self::assertSame( [ 'user_pass', 'user_email', 'user_url' ], $fields );
		$json = (string) json_encode( $fields );
		self::assertStringNotContainsString( 'jane', $json );
		self::assertStringNotContainsString( '$P$', $json );
	}

	public function test_no_change_and_garbage(): void {
		$user = [
			'user_login' => 'a',
			'user_email' => 'a@b.c',
		];
		self::assertSame( [], UserRules::changedFields( $user, $user ) );
		self::assertSame( [], UserRules::changedFields( null, 'x' ) );
		self::assertSame( [], UserRules::changedFields( [ 'user_email' => 'a' ], [] ), 'fields missing on one side are not reported' );
	}

	public function test_grant_severity(): void {
		self::assertSame( Severity::CRITICAL, UserRules::grantSeverity( 'administrator' ) );
		self::assertSame( Severity::CRITICAL, UserRules::grantSeverity( 'administrator', [ 'editor' ] ) );
		self::assertSame( Severity::NOTICE, UserRules::grantSeverity( 'administrator', [ 'administrator' ] ), 'not an elevation' );
		self::assertSame( Severity::NOTICE, UserRules::grantSeverity( 'editor', [ 'administrator' ] ) );
		self::assertSame( Severity::NOTICE, UserRules::grantSeverity( null ) );
	}

	public function test_privileged_roles_filter(): void {
		Filters\expectApplied( 'noformat_logger/privileged_roles' )->andReturn( [ 'administrator', 'shop_manager' ] );
		self::assertSame( Severity::CRITICAL, UserRules::grantSeverity( 'shop_manager', [ 'customer' ] ) );
	}

	public function test_role_list(): void {
		self::assertSame( [ 'editor', 'author' ], UserRules::roleList( [ 'editor', '', 3, 'author', 'editor' ] ) );
		self::assertSame( [], UserRules::roleList( 'editor' ) );
	}
}
