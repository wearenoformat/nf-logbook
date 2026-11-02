<?php
/**
 * User change helpers.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Listeners\Support\System;

use Noformat\Logger\Severity;

defined( 'ABSPATH' ) || exit;

/**
 * Pure helpers for UserListener: changed field names (never values) and role criticality.
 */
final class UserRules {

	/**
	 * Columns of wp_users compared on profile_update (ID excluded).
	 *
	 * @var list<string>
	 */
	public const FIELDS = [
		'user_login',
		'user_pass',
		'user_nicename',
		'user_email',
		'user_url',
		'user_registered',
		'user_activation_key',
		'user_status',
		'display_name',
	];

	/**
	 * Names of the wp_users fields that differ. Values are never returned.
	 *
	 * @param mixed $before Old WP_User, its ->data object, or an array.
	 * @param mixed $after  New WP_User, its ->data object, or an array.
	 * @return list<string>
	 */
	public static function changedFields( mixed $before, mixed $after ): array {
		$a       = self::fields( $before );
		$b       = self::fields( $after );
		$changed = [];
		foreach ( self::FIELDS as $field ) {
			if ( ! array_key_exists( $field, $a ) || ! array_key_exists( $field, $b ) ) {
				continue;
			}
			if ( (string) $a[ $field ] !== (string) $b[ $field ] ) {
				$changed[] = $field;
			}
		}
		return $changed;
	}

	/**
	 * Roles that make a grant critical (PLAN §5), after noformat_logger/privileged_roles.
	 *
	 * @return list<string>
	 */
	public static function privilegedRoles(): array {
		$roles = [ 'administrator' ];
		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filter the roles whose grant is logged as critical.
			 *
			 * @param list<string> $roles Role names.
			 */
			$roles = apply_filters( 'noformat_logger/privileged_roles', $roles );
		}
		return is_array( $roles ) ? array_values( array_map( 'strval', $roles ) ) : [ 'administrator' ];
	}

	/**
	 * Whether a role is privileged.
	 *
	 * @param string|null $role Role.
	 */
	public static function isPrivileged( ?string $role ): bool {
		return null !== $role && '' !== $role && in_array( $role, self::privilegedRoles(), true );
	}

	/**
	 * Severity of granting a role: critical for a privileged role newly granted, else notice.
	 *
	 * @param string|null  $newRole  Granted role.
	 * @param list<string> $oldRoles Roles held before.
	 */
	public static function grantSeverity( ?string $newRole, array $oldRoles = [] ): Severity {
		if ( self::isPrivileged( $newRole ) && ! in_array( $newRole, $oldRoles, true ) ) {
			return Severity::CRITICAL;
		}
		return Severity::NOTICE;
	}

	/**
	 * Clean role list.
	 *
	 * @param mixed $roles Roles.
	 * @return list<string>
	 */
	public static function roleList( mixed $roles ): array {
		if ( ! is_array( $roles ) ) {
			return [];
		}
		$out = [];
		foreach ( $roles as $role ) {
			if ( is_string( $role ) && '' !== $role ) {
				$out[] = substr( $role, 0, 64 );
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Field map from a user-ish value.
	 *
	 * @param mixed $user User.
	 * @return array<string, mixed>
	 */
	private static function fields( mixed $user ): array {
		if ( $user instanceof \WP_User ) {
			$user = $user->data;
		}
		if ( is_object( $user ) ) {
			$user = get_object_vars( $user );
		}
		return is_array( $user ) ? $user : [];
	}
}
