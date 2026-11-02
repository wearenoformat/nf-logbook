<?php
/**
 * Guarded hook registration for listeners.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Listeners\Support;

use Noformat\Logger\Config;
use Noformat\Logger\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Shared plumbing for the content listeners: every callback runs inside
 * Logger::guard(), and observing filters always return their first argument
 * unchanged (also when the body fails).
 */
trait Hooks {

	/**
	 * Logger (set by bind()).
	 *
	 * @var Logger|null
	 */
	protected ?Logger $logger = null;

	/**
	 * Config (set by bind()).
	 *
	 * @var Config|null
	 */
	protected ?Config $config = null;

	/**
	 * Attach the services. Also used by unit tests.
	 *
	 * @param Logger $logger Logger.
	 * @param Config $config Config.
	 */
	public function bind( Logger $logger, Config $config ): static {
		$this->logger = $logger;
		$this->config = $config;
		return $this;
	}

	/**
	 * Add a guarded action. $callback is a public method name on the listener or a callable.
	 *
	 * @param string          $hook     Hook name.
	 * @param string|callable $callback Method name or callable.
	 * @param int             $priority Priority.
	 * @param int             $args     Accepted args.
	 */
	protected function onAction( string $hook, string|callable $callback, int $priority = 10, int $args = 1 ): void {
		$callback = is_string( $callback ) ? [ $this, $callback ] : $callback;
		$logger   = $this->logger;
		if ( null === $logger || ! is_callable( $callback ) ) {
			return;
		}
		add_action(
			$hook,
			static function ( mixed ...$params ) use ( $logger, $callback, $hook ): void {
				$logger->guard( 'listener.' . $hook, static fn() => $callback( ...$params ) );
			},
			$priority,
			$args
		);
	}

	/**
	 * Add a guarded, observe-only filter: the first argument is always returned unchanged.
	 *
	 * @param string          $hook     Hook name.
	 * @param string|callable $callback Method name or callable (return value ignored).
	 * @param int             $priority Priority.
	 * @param int             $args     Accepted args.
	 */
	protected function onFilter( string $hook, string|callable $callback, int $priority = 10, int $args = 1 ): void {
		$callback = is_string( $callback ) ? [ $this, $callback ] : $callback;
		$logger   = $this->logger;
		if ( null === $logger || ! is_callable( $callback ) ) {
			return;
		}
		add_filter(
			$hook,
			static function ( mixed ...$params ) use ( $logger, $callback, $hook ): mixed {
				$logger->guard( 'listener.' . $hook, static fn() => $callback( ...$params ) );
				return $params[0] ?? null;
			},
			$priority,
			$args
		);
	}

	/**
	 * Queue an event (no-op when unbound).
	 *
	 * @param \Noformat\Logger\Event $event Event.
	 */
	protected function emit( \Noformat\Logger\Event $event ): void {
		if ( null !== $this->logger ) {
			$this->logger->log( $event );
		}
	}

	/**
	 * Salted 8-char hash of a label (title, file name) via the Redactor.
	 *
	 * @param mixed $value Value.
	 */
	protected function hash8( mixed $value ): ?string {
		if ( null === $this->logger || null === $value || '' === $value ) {
			return null;
		}
		return $this->logger->redactor()->hash8( $value );
	}

	/**
	 * Redactor::contentSummary() for a possibly non-string value.
	 *
	 * @param mixed $text Text.
	 * @param mixed $old  Previous text (null: no delta).
	 * @return array<string, mixed>|null
	 */
	protected function contentSummary( mixed $text, mixed $old = null ): ?array {
		if ( null === $this->logger ) {
			return null;
		}
		return $this->logger->redactor()->contentSummary(
			is_scalar( $text ) ? (string) $text : '',
			null === $old ? null : ( is_scalar( $old ) ? (string) $old : '' )
		);
	}

	/**
	 * Request id used to scope fingerprints to one request/CLI run.
	 */
	protected function requestId(): string {
		return null === $this->logger ? '' : $this->logger->context()->requestId();
	}

	/**
	 * Run a WordPress filter that lets sites extend a list; non-arrays fall back.
	 *
	 * @param string       $hook    Filter (documented noformat_logger/... name).
	 * @param list<string> $fallback Default list.
	 * @return list<string>
	 */
	protected static function filteredList( string $hook, array $fallback ): array {
		if ( ! function_exists( 'apply_filters' ) ) {
			return $fallback;
		}
		$list = apply_filters( $hook, $fallback ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- documented noformat_logger/* names only.
		return is_array( $list ) ? array_values( array_map( 'strval', $list ) ) : $fallback;
	}

	/**
	 * Integer id from mixed input (0 when not numeric).
	 *
	 * @param mixed $value Value.
	 */
	protected static function intId( mixed $value ): int {
		if ( is_object( $value ) && isset( $value->ID ) ) {
			$value = $value->ID;
		}
		return is_numeric( $value ) ? (int) $value : 0;
	}
}
