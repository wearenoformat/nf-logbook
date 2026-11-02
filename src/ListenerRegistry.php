<?php
/**
 * Listener registry.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Registers listeners with per-listener guards and module status.
 *
 * Listeners available at boot register immediately (the error handlers must be
 * in place before other plugins load). Enabled listeners whose isAvailable() is
 * false at boot, typically because their dependency (ACF, WooCommerce) is a
 * plugin that loads after this one, are re-checked once by registerDeferred(),
 * which Plugin hooks on plugins_loaded at priority 0.
 */
final class ListenerRegistry {

	private const REGISTERED  = 'registered';
	private const UNAVAILABLE = 'unavailable';
	private const FAILED      = 'failed';

	/**
	 * Module status by short name.
	 *
	 * @var array<string, array{enabled: bool, available: bool, registered: bool, error: string|null}>
	 */
	private array $modules = [];

	/**
	 * Enabled listeners that were not available at boot, in registry order.
	 *
	 * @var list<class-string<Listener>>
	 */
	private array $deferred = [];

	/**
	 * Constructor.
	 *
	 * @param Config                                 $config   Config (module toggles).
	 * @param \Closure(class-string<Listener>): void $register Instantiates and registers one listener.
	 * @param \Closure(string, \Throwable): void     $failure  Records an internal failure (label, exception).
	 */
	public function __construct(
		private Config $config,
		private \Closure $register,
		private \Closure $failure
	) {
	}

	/**
	 * Register every enabled, available listener now; remember the unavailable ones.
	 *
	 * @param list<class-string<Listener>> $listenerClasses Listener classes in registration order.
	 */
	public function registerAll( array $listenerClasses ): void {
		foreach ( $listenerClasses as $listenerClass ) {
			$slug                   = Config::moduleSlug( $listenerClass );
			$status                 = [
				'enabled'    => $this->config->isEnabled( $listenerClass ),
				'available'  => false,
				'registered' => false,
				'error'      => null,
			];
			$this->modules[ $slug ] = $status;
			if ( ! $status['enabled'] ) {
				continue;
			}
			if ( self::UNAVAILABLE === $this->tryRegister( $listenerClass ) ) {
				$this->deferred[] = $listenerClass;
			}
		}
	}

	/**
	 * Re-check the listeners that were unavailable at boot (plugins_loaded, priority 0). Runs once.
	 */
	public function registerDeferred(): void {
		$deferred       = $this->deferred;
		$this->deferred = [];
		foreach ( $deferred as $listenerClass ) {
			$this->tryRegister( $listenerClass );
		}
	}

	/**
	 * Whether listeners are waiting for registerDeferred().
	 */
	public function hasDeferred(): bool {
		return [] !== $this->deferred;
	}

	/**
	 * Deferred listener classes.
	 *
	 * @return list<class-string<Listener>>
	 */
	public function deferred(): array {
		return $this->deferred;
	}

	/**
	 * Module status by short name.
	 *
	 * @return array<string, array{enabled: bool, available: bool, registered: bool, error: string|null}>
	 */
	public function modules(): array {
		return $this->modules;
	}

	/**
	 * Check availability and register one listener; never throws.
	 *
	 * @param class-string<Listener> $listenerClass Listener class.
	 * @return string One of the REGISTERED, UNAVAILABLE, FAILED constants.
	 */
	private function tryRegister( string $listenerClass ): string {
		$slug = Config::moduleSlug( $listenerClass );
		try {
			$available                           = class_exists( $listenerClass ) && $listenerClass::isAvailable();
			$this->modules[ $slug ]['available'] = $available;
			if ( ! $available ) {
				return self::UNAVAILABLE;
			}
			( $this->register )( $listenerClass );
			$this->modules[ $slug ]['registered'] = true;
			return self::REGISTERED;
		} catch ( \Throwable $e ) {
			$this->modules[ $slug ]['error'] = get_class( $e );
			( $this->failure )( 'register.' . $slug, $e );
			return self::FAILED;
		}
	}
}
