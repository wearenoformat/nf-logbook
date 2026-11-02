<?php
/**
 * Redactor.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Privacy;

use Noformat\Logger\Errors\Support\Text;
use Noformat\Logger\Storage\HashChain;

defined( 'ABSPATH' ) || exit;

/**
 * Applies the CONTRACT §2 rules: sensitive keys are replaced by {"redacted":true,"sha256_8":…},
 * long strings by {"truncated":true,"length":n,"sha256_8":…}, and changes are
 * expressed as per-field diffs with dotted paths.
 *
 * The sha256_8 value is the first 8 hex characters of HMAC-SHA256(site_salt, value), so a
 * change is visible but short values (emails, phone numbers) cannot be
 * dictionary-reversed without the site salt.
 *
 * Key names are not the only guard: every string leaf that survives is also
 * scrubbed of e-mail addresses and IPv4/IPv6 addresses ("[email]", "[ip]"),
 * so values under ordinary keys (a "note", a serialized form entry) cannot
 * carry them.
 */
final class Redactor {

	public const MAX_STRING  = 2048;
	public const MAX_DEPTH   = 8;
	public const MAX_CHANGES = 100;

	/**
	 * Lower-cased plain patterns.
	 *
	 * @var list<string>
	 */
	private array $plain = [];

	/**
	 * Regex patterns.
	 *
	 * @var list<string>
	 */
	private array $regex = [];

	/**
	 * Lower-cased safe keys.
	 *
	 * @var array<string, true>
	 */
	private array $safe = [];

	/**
	 * Salt provider.
	 *
	 * @var \Closure(): string
	 */
	private \Closure $saltProvider;

	private ?string $salt = null;

	/**
	 * Constructor.
	 *
	 * @param list<string>             $sensitive Sensitive key patterns (substring, or /regex/).
	 * @param list<string>             $safe      Exact keys never redacted.
	 * @param string|callable():string $salt    Site salt or a lazy provider.
	 * @param int                      $maxString Truncation threshold in bytes.
	 */
	public function __construct( array $sensitive, array $safe, string|callable $salt, private int $maxString = self::MAX_STRING ) {
		foreach ( $sensitive as $pattern ) {
			$pattern = trim( (string) $pattern );
			if ( '' === $pattern ) {
				continue;
			}
			if ( strlen( $pattern ) > 2 && '/' === $pattern[0] && '/' === substr( $pattern, -1 ) ) {
				$this->regex[] = $pattern . 'i';
			} else {
				$this->plain[] = strtolower( $pattern );
			}
		}
		foreach ( $safe as $key ) {
			$this->safe[ strtolower( (string) $key ) ] = true;
		}
		if ( is_string( $salt ) ) {
			$this->salt         = $salt;
			$this->saltProvider = static fn(): string => $salt;
		} else {
			$this->saltProvider = \Closure::fromCallable( $salt );
		}
	}

	/**
	 * Whether a key name is sensitive.
	 *
	 * @param string|int $key Key.
	 */
	public function isSensitiveKey( string|int $key ): bool {
		if ( is_int( $key ) ) {
			return false;
		}
		$lower = strtolower( $key );
		if ( '' === $lower || isset( $this->safe[ $lower ] ) ) {
			return false;
		}
		if ( 1 === preg_match( '/_(id|ids|hash|count)$/', $lower ) ) {
			return false;
		}
		foreach ( $this->plain as $pattern ) {
			if ( str_contains( $lower, $pattern ) ) {
				return true;
			}
		}
		foreach ( $this->regex as $pattern ) {
			if ( 1 === @preg_match( $pattern, $key ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- user supplied pattern.
				return true;
			}
		}
		return false;
	}

	/**
	 * Redact a value under a key: marker for sensitive keys, otherwise recurse.
	 *
	 * @param string|int $key   Key.
	 * @param mixed      $value Value.
	 */
	public function redactValue( string|int $key, mixed $value ): mixed {
		if ( $this->isSensitiveKey( $key ) ) {
			return null === $value ? null : $this->marker( $value );
		}
		return $this->redactTree( $value, 0 );
	}

	/**
	 * Redact every entry of a map.
	 *
	 * @param array<string|int, mixed> $data Data.
	 * @return array<string|int, mixed>
	 */
	public function redact( array $data ): array {
		$out = [];
		foreach ( $data as $key => $value ) {
			$out[ $key ] = $this->redactValue( $key, $value );
		}
		return $out;
	}

	/**
	 * Redaction marker.
	 *
	 * @param mixed $value Value.
	 * @return array{redacted: true, sha256_8: string}
	 */
	public function marker( mixed $value ): array {
		return [
			'redacted' => true,
			'sha256_8' => $this->hash8( $value ),
		];
	}

	/**
	 * First 8 hex chars of HMAC-SHA256(salt, value).
	 *
	 * @param mixed $value Value (non-strings are canonical-JSON encoded).
	 */
	public function hash8( mixed $value ): string {
		return substr( hash_hmac( 'sha256', self::stringify( $value ), $this->salt() ), 0, 8 );
	}

	/**
	 * Scrub e-mail addresses and IPs, then truncate strings above the threshold.
	 *
	 * @param string $value String.
	 * @return string|array{truncated: true, length: int, sha256_8: string}
	 */
	public function truncate( string $value ): string|array {
		$value = self::scrub( $value );
		if ( strlen( $value ) <= $this->maxString ) {
			return $value;
		}
		return [
			'truncated' => true,
			'length'    => strlen( $value ),
			'sha256_8'  => $this->hash8( $value ),
		];
	}

	/**
	 * Replace e-mail addresses and IPv4/IPv6 addresses inside a string and redact
	 * credential query parameters (`Signature=…`, `AWSAccessKeyId=…`, `token=…`) (Text::scrub()).
	 *
	 * @param string $value String.
	 */
	public static function scrub( string $value ): string {
		if ( '' === $value || ( ! str_contains( $value, '@' ) && ! str_contains( $value, '.' ) && ! str_contains( $value, ':' ) && ! str_contains( $value, '=' ) ) ) {
			return $value;
		}
		return Text::scrub( $value );
	}

	/**
	 * Salted, irreversible username hash for failed-login events (32 hex).
	 *
	 * @param string $username Attempted username or email.
	 */
	public function hashUsername( string $username ): string {
		return substr( hash_hmac( 'sha256', 'username:' . strtolower( trim( $username ) ), $this->salt() ), 0, 32 );
	}

	/**
	 * Summary of long content: hash and length (characters), plus delta vs old.
	 *
	 * @param string      $text Text.
	 * @param string|null $old  Previous text.
	 * @return array{sha256_8: string, length: int, length_delta?: int, changed?: bool}
	 */
	public function contentSummary( string $text, ?string $old = null ): array {
		$summary = [
			'sha256_8' => $this->hash8( $text ),
			'length'   => self::length( $text ),
		];
		if ( null !== $old ) {
			$summary['length_delta'] = $summary['length'] - self::length( $old );
			$summary['changed']      = $old !== $text;
		}
		return $summary;
	}

	/**
	 * Per-field diff with dotted paths. Serialized strings are unserialized first
	 * (no object instantiation). Values are redacted and truncated.
	 *
	 * @param mixed  $oldValue Old value.
	 * @param mixed  $newValue New value.
	 * @param string $field    Root field name (e.g. the option name or meta key).
	 * @return list<array{field: string, old: mixed, new: mixed}>
	 */
	public function diff( mixed $oldValue, mixed $newValue, string $field = '' ): array {
		$changes = [];
		$omitted = 0;
		$this->diffInto( self::toPlain( self::maybeUnserialize( $oldValue ) ), self::toPlain( self::maybeUnserialize( $newValue ) ), $field, '' === $field ? null : $field, 0, $changes, $omitted );
		if ( $omitted > 0 ) {
			$changes[] = [
				'field' => '*',
				'old'   => null,
				'new'   => [
					'truncated' => true,
					'omitted'   => $omitted,
				],
			];
		}
		return $changes;
	}

	/**
	 * Unserialize PHP-serialized strings without instantiating objects.
	 *
	 * @param mixed $value Value.
	 */
	public static function maybeUnserialize( mixed $value ): mixed {
		if ( ! is_string( $value ) || ! self::isSerialized( $value ) ) {
			return $value;
		}
		$trimmed = trim( $value );
		if ( 'b:0;' === $trimmed ) {
			return false;
		}
		$result = @unserialize( $trimmed, [ 'allowed_classes' => false ] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.NoSilencedErrors.Discouraged -- no classes allowed; malformed input tolerated.
		return false === $result ? $value : $result;
	}

	/**
	 * Strict check for PHP-serialized data (port of WordPress is_serialized()).
	 *
	 * @param string $data Data.
	 */
	public static function isSerialized( string $data ): bool {
		$data = trim( $data );
		if ( 'N;' === $data ) {
			return true;
		}
		if ( strlen( $data ) < 4 || ':' !== $data[1] ) {
			return false;
		}
		$last = substr( $data, -1 );
		if ( ';' !== $last && '}' !== $last ) {
			return false;
		}
		$token = $data[0];
		switch ( $token ) {
			case 's':
				return '"' === substr( $data, -2, 1 ) && 1 === preg_match( '/^s:[0-9]+:"/s', $data );
			case 'a':
			case 'O':
			case 'E':
				return 1 === preg_match( "/^{$token}:[0-9]+:/s", $data );
			case 'b':
			case 'i':
			case 'd':
				return 1 === preg_match( "/^{$token}:[0-9.E+-]+;\$/", $data );
		}
		return false;
	}

	/**
	 * Recursive redaction of nested keys and truncation of strings.
	 *
	 * @param mixed $value Value.
	 * @param int   $depth Depth.
	 */
	private function redactTree( mixed $value, int $depth ): mixed {
		$value = self::toPlain( self::maybeUnserialize( $value ) );
		if ( is_string( $value ) ) {
			return $this->truncate( $value );
		}
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( $depth >= self::MAX_DEPTH ) {
			return [
				'truncated' => true,
				'depth'     => $depth,
				'sha256_8'  => $this->hash8( $value ),
			];
		}
		$out = [];
		foreach ( $value as $key => $item ) {
			$out[ $key ] = $this->isSensitiveKey( $key )
				? ( null === $item ? null : $this->marker( $item ) )
				: $this->redactTree( $item, $depth + 1 );
		}
		return $out;
	}

	/**
	 * Diff worker.
	 *
	 * @param mixed                                              $oldValue     Old.
	 * @param mixed                                              $newValue     New.
	 * @param string                                             $path    Dotted path.
	 * @param string|int|null                                    $key     Last key segment.
	 * @param int                                                $depth   Depth.
	 * @param list<array{field: string, old: mixed, new: mixed}> $changes Accumulator.
	 * @param int                                                $omitted Omitted counter.
	 */
	private function diffInto( mixed $oldValue, mixed $newValue, string $path, string|int|null $key, int $depth, array &$changes, int &$omitted ): void {
		if ( self::same( $oldValue, $newValue ) ) {
			return;
		}
		if ( null !== $key && $this->isSensitiveKey( $key ) ) {
			$this->push(
				$changes,
				$omitted,
				$path,
				null === $oldValue ? null : $this->marker( $oldValue ),
				null === $newValue ? null : $this->marker( $newValue )
			);
			return;
		}
		$oldIsMap = is_array( $oldValue ) || null === $oldValue;
		$newIsMap = is_array( $newValue ) || null === $newValue;
		if ( ( is_array( $oldValue ) || is_array( $newValue ) ) && $oldIsMap && $newIsMap && $depth < self::MAX_DEPTH ) {
			$oldValue = (array) $oldValue;
			$newValue = (array) $newValue;
			$keys     = array_keys( $oldValue );
			foreach ( array_keys( $newValue ) as $k ) {
				if ( ! array_key_exists( $k, $oldValue ) ) {
					$keys[] = $k;
				}
			}
			foreach ( $keys as $k ) {
				$sub = '' === $path ? (string) $k : $path . '.' . $k;
				$this->diffInto( $oldValue[ $k ] ?? null, $newValue[ $k ] ?? null, $sub, $k, $depth + 1, $changes, $omitted );
			}
			return;
		}
		$this->push(
			$changes,
			$omitted,
			'' === $path ? 'value' : $path,
			$this->redactTree( $oldValue, $depth ),
			$this->redactTree( $newValue, $depth )
		);
	}

	/**
	 * Append a change unless the cap is reached.
	 *
	 * @param list<array{field: string, old: mixed, new: mixed}> $changes Accumulator.
	 * @param int                                                $omitted Omitted counter.
	 * @param string                                             $field   Field.
	 * @param mixed                                              $oldValue     Old.
	 * @param mixed                                              $newValue     New.
	 */
	private function push( array &$changes, int &$omitted, string $field, mixed $oldValue, mixed $newValue ): void {
		if ( count( $changes ) >= self::MAX_CHANGES ) {
			++$omitted;
			return;
		}
		$changes[] = [
			'field' => $field,
			'old'   => $oldValue,
			'new'   => $newValue,
		];
	}

	/**
	 * Loose equality suitable for meta/options (stored as strings).
	 *
	 * @param mixed $a A.
	 * @param mixed $b B.
	 */
	private static function same( mixed $a, mixed $b ): bool {
		if ( is_scalar( $a ) && is_scalar( $b ) ) {
			return ( is_bool( $a ) || is_bool( $b ) ) ? $a === $b : (string) $a === (string) $b;
		}
		return $a === $b;
	}

	/**
	 * Objects (including __PHP_Incomplete_Class) to arrays, recursively.
	 *
	 * @param mixed $value Value.
	 */
	private static function toPlain( mixed $value ): mixed {
		if ( is_object( $value ) ) {
			$array = [];
			foreach ( (array) $value as $k => $v ) {
				$k = is_string( $k ) ? (string) preg_replace( '/^\x00[^\x00]*\x00/', '', $k ) : $k;
				if ( '__PHP_Incomplete_Class_Name' === $k ) {
					$k = '__class';
				}
				$array[ $k ] = $v;
			}
			$value = $array;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $k => $v ) {
				if ( is_object( $v ) ) {
					$value[ $k ] = self::toPlain( $v );
				}
			}
		}
		if ( is_resource( $value ) ) {
			return 'resource';
		}
		return $value;
	}

	/**
	 * Value to string for hashing.
	 *
	 * @param mixed $value Value.
	 */
	private static function stringify( mixed $value ): string {
		if ( is_string( $value ) ) {
			return $value;
		}
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return (string) $value;
		}
		if ( null === $value ) {
			return '';
		}
		return HashChain::canonicalize( self::toPlain( $value ) );
	}

	/**
	 * Character length.
	 *
	 * @param string $text Text.
	 */
	private static function length( string $text ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : strlen( $text );
	}

	/**
	 * Lazy salt.
	 */
	private function salt(): string {
		if ( null === $this->salt ) {
			try {
				$this->salt = (string) ( $this->saltProvider )();
			} catch ( \Throwable $e ) {
				$this->salt = 'nflog-fallback-salt';
			}
		}
		return $this->salt;
	}
}
