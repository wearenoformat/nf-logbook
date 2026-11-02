<?php
/**
 * ListenerRegistry: immediate vs deferred registration.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Tests;

use Noformat\Logger\Config;
use Noformat\Logger\Listener;
use Noformat\Logger\ListenerRegistry;
use Noformat\Logger\Logger;

final class RegistryAlwaysListener implements Listener {
	public static function isAvailable(): bool {
		return true;
	}
	public function register( Logger $logger, Config $config ): void {
	}
}

final class RegistryLateListener implements Listener {
	public static bool $available = false;
	public static function isAvailable(): bool {
		return self::$available;
	}
	public function register( Logger $logger, Config $config ): void {
	}
}

final class RegistryNeverListener implements Listener {
	public static function isAvailable(): bool {
		return false;
	}
	public function register( Logger $logger, Config $config ): void {
	}
}

final class RegistryBrokenListener implements Listener {
	public static function isAvailable(): bool {
		throw new \LogicException( 'boom' );
	}
	public function register( Logger $logger, Config $config ): void {
	}
}

final class ListenerRegistryTest extends TestCase {

	/** @var list<string> */
	private array $registered = [];

	/** @var list<string> */
	private array $failures = [];

	private function registry( Config $config ): ListenerRegistry {
		$this->registered = [];
		$this->failures   = [];
		return new ListenerRegistry(
			$config,
			function ( string $class ): void {
				$this->registered[] = $class;
			},
			function ( string $where, \Throwable $e ): void {
				$this->failures[] = $where . ':' . get_class( $e );
			}
		);
	}

	public function test_available_listeners_register_at_once_unavailable_ones_are_deferred(): void {
		RegistryLateListener::$available = false;
		$registry                        = $this->registry( new Config() );
		$registry->registerAll( [ RegistryAlwaysListener::class, RegistryLateListener::class, RegistryNeverListener::class ] );

		self::assertSame( [ RegistryAlwaysListener::class ], $this->registered );
		self::assertTrue( $registry->hasDeferred() );
		self::assertSame( [ RegistryLateListener::class, RegistryNeverListener::class ], $registry->deferred() );
		self::assertFalse( $registry->modules()['RegistryLateListener']['registered'] );

		// The dependency plugin loaded (plugins_loaded, priority 0).
		RegistryLateListener::$available = true;
		$registry->registerDeferred();

		self::assertSame( [ RegistryAlwaysListener::class, RegistryLateListener::class ], $this->registered );
		self::assertFalse( $registry->hasDeferred(), 'runs once' );
		$modules = $registry->modules();
		self::assertTrue( $modules['RegistryLateListener']['available'] );
		self::assertTrue( $modules['RegistryLateListener']['registered'] );
		self::assertFalse( $modules['RegistryNeverListener']['available'] );
		self::assertFalse( $modules['RegistryNeverListener']['registered'] );
		self::assertTrue( $modules['RegistryAlwaysListener']['registered'] );
	}

	public function test_disabled_listeners_are_neither_registered_nor_deferred(): void {
		RegistryLateListener::$available = true;
		$registry                        = $this->registry( new Config( [], [ 'disabled_modules' => 'RegistryLateListener,RegistryNeverListener' ] ) );
		$registry->registerAll( [ RegistryLateListener::class, RegistryNeverListener::class ] );
		self::assertSame( [], $this->registered );
		self::assertFalse( $registry->hasDeferred() );
		self::assertFalse( $registry->modules()['RegistryLateListener']['enabled'] );
	}

	public function test_a_failing_listener_is_recorded_and_not_retried(): void {
		$registry = $this->registry( new Config() );
		$registry->registerAll( [ RegistryBrokenListener::class, RegistryAlwaysListener::class ] );
		self::assertSame( [ RegistryAlwaysListener::class ], $this->registered );
		self::assertSame( [ 'register.RegistryBrokenListener:LogicException' ], $this->failures );
		self::assertSame( 'LogicException', $registry->modules()['RegistryBrokenListener']['error'] );
		self::assertFalse( $registry->hasDeferred() );
	}
}
