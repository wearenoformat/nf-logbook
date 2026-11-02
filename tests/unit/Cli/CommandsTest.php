<?php
/**
 * CLI helpers.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Tests\Cli;

use Noformat\Logger\Cli\Commands;
use Noformat\Logger\Tests\TestCase;

final class CommandsTest extends TestCase {

	public function test_integrity_problems(): void {
		$ok = [
			'core'    => [ 'status' => 'ok' ],
			'plugins' => [ 'status' => 'ok' ],
			'chain'   => [ 'ok' => true ],
		];
		self::assertSame( [], Commands::integrityProblems( $ok ) );
		self::assertSame( [], Commands::integrityProblems( array_merge( $ok, [ 'core' => [ 'status' => 'unavailable' ] ] ) ), 'unreachable API is not a failure' );
		self::assertSame( [], Commands::integrityProblems( array_merge( $ok, [ 'core' => [ 'status' => 'partial' ] ] ) ) );
		self::assertSame(
			[ 'core mismatch', 'plugin mismatch', 'chain break' ],
			Commands::integrityProblems(
				[
					'core'    => [ 'status' => 'mismatch' ],
					'plugins' => [ 'status' => 'mismatch' ],
					'chain'   => [ 'ok' => false ],
				]
			)
		);
	}
}
