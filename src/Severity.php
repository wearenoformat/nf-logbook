<?php
/**
 * Severity enum.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Event severity, highest priority first (CONTRACT §1).
 *
 * The integer priority is what is stored in the database (0 critical … 5 debug),
 * so "min_severity=warning" means "priority <= 2".
 */
enum Severity: string {

	case CRITICAL = 'critical';
	case ERROR    = 'error';
	case WARNING  = 'warning';
	case NOTICE   = 'notice';
	case INFO     = 'info';
	case DEBUG    = 'debug';

	/**
	 * Storage priority: 0 critical … 5 debug.
	 */
	public function priority(): int {
		return match ( $this ) {
			self::CRITICAL => 0,
			self::ERROR    => 1,
			self::WARNING  => 2,
			self::NOTICE   => 3,
			self::INFO     => 4,
			self::DEBUG    => 5,
		};
	}

	/**
	 * Inverse of priority(); unknown values fall back to info.
	 *
	 * @param int $priority Stored priority.
	 */
	public static function fromPriority( int $priority ): self {
		foreach ( self::cases() as $case ) {
			if ( $case->priority() === $priority ) {
				return $case;
			}
		}
		return self::INFO;
	}

	/**
	 * Lenient parser for user input ("warning", "WARNING", "2").
	 *
	 * @param string $value Raw input.
	 */
	public static function parse( string $value ): ?self {
		$value = strtolower( trim( $value ) );
		if ( '' === $value ) {
			return null;
		}
		if ( ctype_digit( $value ) ) {
			$priority = (int) $value;
			return ( $priority >= 0 && $priority <= 5 ) ? self::fromPriority( $priority ) : null;
		}
		return self::tryFrom( $value );
	}
}
