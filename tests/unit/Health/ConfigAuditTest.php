<?php
/**
 * ConfigAudit state diffing tests.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Tests\Health;

use Noformat\Logger\Security\ConfigAudit;
use Noformat\Logger\Tests\TestCase;

final class ConfigAuditTest extends TestCase {

	/**
	 * Finding.
	 *
	 * @param string               $id       Id.
	 * @param string               $severity Severity.
	 * @param array<string, mixed> $detail   Detail.
	 * @return array{id: string, severity: string, state: string, detail: array<string, mixed>}
	 */
	private static function finding( string $id, string $severity = 'warning', array $detail = [] ): array {
		return [
			'id'       => $id,
			'severity' => $severity,
			'state'    => 'present',
			'detail'   => $detail,
		];
	}

	public function test_first_run_reports_every_finding(): void {
		$findings = [
			'xmlrpc_enabled'    => self::finding( 'xmlrpc_enabled' ),
			'file_edit_allowed' => self::finding( 'file_edit_allowed', 'error' ),
		];
		$diff     = ConfigAudit::diff( [], $findings );
		self::assertCount( 2, $diff );
		self::assertSame( [ 'present', 'present' ], array_column( $diff, 'state' ) );
		self::assertSame( 'error', $diff[1]['severity'] );
	}

	public function test_unchanged_state_reports_nothing(): void {
		$findings = [
			'admin_username' => self::finding( 'admin_username', 'warning', [ 'user_id' => 1 ] ),
		];
		self::assertSame( [], ConfigAudit::diff( ConfigAudit::state( $findings ), $findings ) );
	}

	public function test_changed_detail_or_severity_and_resolution(): void {
		$before = ConfigAudit::state(
			[
				'admin_username' => self::finding( 'admin_username', 'warning', [ 'user_id' => 1 ] ),
				'xmlrpc_enabled' => self::finding( 'xmlrpc_enabled' ),
			]
		);
		$after  = [
			'admin_username' => self::finding( 'admin_username', 'warning', [ 'user_id' => 7 ] ),
		];
		$diff   = ConfigAudit::diff( $before, $after );
		self::assertCount( 2, $diff );
		self::assertSame( 'changed', $diff[0]['state'] );
		self::assertSame( [ 'user_id' => 7 ], $diff[0]['detail'] );
		self::assertSame( 'xmlrpc_enabled', $diff[1]['id'] );
		self::assertSame( 'resolved', $diff[1]['state'] );
		self::assertSame( 'info', $diff[1]['severity'] );
		self::assertSame( 'warning', $diff[1]['previous_severity'] );

		$event = ConfigAudit::eventFor( $diff[1] );
		self::assertSame( 'config.risky_setting', $event->getAction() );
		self::assertSame( 'info', $event->getSeverity()->value );
		self::assertSame( 'resolved', $event->getContext()['state'] );
		self::assertSame( 'xmlrpc_enabled', $event->getObject()['id'] ?? null );
	}

	public function test_skipped_http_checks_keep_previous_state(): void {
		$previous = ConfigAudit::state( [ 'readme_exposed' => self::finding( 'readme_exposed', 'warning', [ 'http_status' => 200 ] ) ] );
		$current  = ConfigAudit::carryOver( [], $previous, ConfigAudit::HTTP_CHECKS );
		self::assertArrayHasKey( 'readme_exposed', $current );
		self::assertSame( [], ConfigAudit::diff( $previous, $current ), 'no spurious resolution' );
	}
}
