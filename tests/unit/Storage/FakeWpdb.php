<?php
/**
 * Minimal wpdb stand-in for Db tests (only loaded when WordPress' class is absent).
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

// phpcs:ignoreFile

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

/**
 * Records queries; can simulate an error.
 */
class wpdb {

	public string $prefix = 'wp_';

	public string $last_error = '';

	public string $last_query = '';

	public string $failWith = '';

	/** @var list<string> */
	public array $ran = [];

	/** @var list<array<string, mixed>> */
	public array $results = [];

	/** In-memory table emulation (MemoryDb), optional. */
	public ?object $handler = null;

	private bool $suppress = false;

	private bool $show = true;

	public function __construct( $user = '', $password = '', $name = '', $host = '' ) {
	}

	public function suppress_errors( $suppress = true ) {
		$previous       = $this->suppress;
		$this->suppress = (bool) $suppress;
		return $previous;
	}

	public function hide_errors() {
		$previous   = $this->show;
		$this->show = false;
		return $previous;
	}

	public function show_errors( $show = true ) {
		$previous   = $this->show;
		$this->show = (bool) $show;
		return $previous;
	}

	public function query( $query ) {
		$this->ran[]      = (string) $query;
		$this->last_query = (string) $query;
		if ( '' !== $this->failWith ) {
			$this->last_error = $this->failWith;
			return false;
		}
		return null === $this->handler ? 1 : $this->handler->query( (string) $query );
	}

	public function get_var( $query ) {
		$this->ran[]      = (string) $query;
		$this->last_query = (string) $query;
		return null === $this->handler ? null : $this->handler->getVar( (string) $query );
	}

	public function get_row( $query, $output = 'OBJECT' ) {
		$this->ran[]      = (string) $query;
		$this->last_query = (string) $query;
		return null === $this->handler ? null : $this->handler->getRow( (string) $query );
	}

	public function esc_like( $text ) {
		return addcslashes( (string) $text, '_%\\' );
	}

	public function prepare( $query, ...$args ) {
		$i = 0;
		return (string) preg_replace_callback(
			'/%[isd]/',
			static function ( $m ) use ( &$i, $args ) {
				$value = $args[ $i++ ] ?? '';
				return '%s' === $m[0] ? "'" . addslashes( (string) $value ) . "'" : ( '%i' === $m[0] ? '`' . $value . '`' : (string) (int) $value );
			},
			(string) $query
		);
	}

	public function get_results( $query, $output = 'OBJECT' ) {
		$this->ran[]      = (string) $query;
		$this->last_query = (string) $query;
		return null === $this->handler ? $this->results : $this->handler->getResults( (string) $query );
	}
}
