<?php
/**
 * UserListener.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Listeners;

use Noformat\Logger\Category;
use Noformat\Logger\Config;
use Noformat\Logger\Event;
use Noformat\Logger\Listener;
use Noformat\Logger\Listeners\Support\System\UserRules;
use Noformat\Logger\Logger;
use Noformat\Logger\Severity;

defined( 'ABSPATH' ) || exit;

/**
 * Users and roles: user_register, profile_update, set/add/remove_user_role, deleted_user, super admin, application passwords, password reset (PLAN §4).
 *
 * Role events are held until shutdown (priority 5, before the Logger flush) so one
 * WP_User::set_role() call yields one user.role_changed instead of an extra
 * add/remove pair, and a role set while a user is being created is folded into
 * user.created. Only user IDs, role names and changed field names are logged.
 */
final class UserListener implements Listener {

	private Logger $logger;

	/**
	 * Deferred role events per user id.
	 *
	 * @var array<int, array<string, Event>>
	 */
	private array $pending = [];

	private bool $flushHooked = false;

	/**
	 * Availability.
	 */
	public static function isAvailable(): bool {
		return true;
	}

	/**
	 * Add hooks.
	 *
	 * @param Logger $logger Logger.
	 * @param Config $config Config.
	 */
	public function register( Logger $logger, Config $config ): void {
		unset( $config );
		$this->logger = $logger;
		add_action( 'user_register', [ $this, 'onRegister' ], 10, 1 );
		add_action( 'profile_update', [ $this, 'onProfileUpdate' ], 10, 2 );
		add_action( 'set_user_role', [ $this, 'onSetRole' ], 10, 3 );
		add_action( 'add_user_role', [ $this, 'onAddRole' ], 10, 2 );
		add_action( 'remove_user_role', [ $this, 'onRemoveRole' ], 10, 2 );
		add_action( 'deleted_user', [ $this, 'onDeleted' ], 10, 3 );
		add_action( 'grant_super_admin', [ $this, 'onGrantSuperAdmin' ], 10, 1 );
		add_action( 'revoke_super_admin', [ $this, 'onRevokeSuperAdmin' ], 10, 1 );
		add_action( 'wp_create_application_password', [ $this, 'onAppPasswordCreated' ], 10, 2 );
		add_action( 'wp_delete_application_password', [ $this, 'onAppPasswordDeleted' ], 10, 2 );
		add_action( 'password_reset', [ $this, 'onPasswordReset' ], 10, 1 );
		add_action( 'retrieve_password', [ $this, 'onRetrievePassword' ], 10, 1 );
	}

	/**
	 * User created.
	 *
	 * @param mixed $userId User id.
	 */
	public function onRegister( mixed $userId ): void {
		$this->logger->guard(
			'user.created',
			function () use ( $userId ): void {
				$id = (int) $userId;
				unset( $this->pending[ $id ] );
				$roles = self::rolesOf( $id );
				$role  = $roles[0] ?? null;
				$this->logger->log(
					Event::make( Category::USER, 'user.created', UserRules::grantSeverity( $role ) )
						->object( 'user', $id, $role )
						->summary( sprintf( 'User #%d created with role %s', $id, $role ?? '(none)' ) )
						->context(
							[
								'roles'      => $roles,
								'privileged' => UserRules::isPrivileged( $role ),
							]
						)
				);
			}
		);
	}

	/**
	 * Profile updated: changed field names only.
	 *
	 * @param mixed $userId      User id.
	 * @param mixed $oldUserData WP_User before the update.
	 */
	public function onProfileUpdate( mixed $userId, mixed $oldUserData = null ): void {
		$this->logger->guard(
			'user.updated',
			function () use ( $userId, $oldUserData ): void {
				$id  = (int) $userId;
				$new = get_userdata( $id );
				if ( ! $new instanceof \WP_User ) {
					return;
				}
				$fields = UserRules::changedFields( $oldUserData, $new );
				if ( [] === $fields ) {
					return;
				}
				$this->logger->log(
					Event::make( Category::USER, 'user.updated', Severity::NOTICE )
						->object( 'user', $id, self::rolesOf( $id )[0] ?? null )
						->summary( sprintf( 'User #%d updated: %s', $id, implode( ', ', $fields ) ) )
						->context( [ 'changed_fields' => $fields ] )
				);
			}
		);
	}

	/**
	 * Role set (replaces all roles).
	 *
	 * @param mixed $userId   User id.
	 * @param mixed $role     New role.
	 * @param mixed $oldRoles Previous roles.
	 */
	public function onSetRole( mixed $userId, mixed $role, mixed $oldRoles = [] ): void {
		$this->logger->guard(
			'user.role_changed',
			function () use ( $userId, $role, $oldRoles ): void {
				$id  = (int) $userId;
				$new = is_string( $role ) && '' !== $role ? $role : null;
				$old = UserRules::roleList( $oldRoles );
				// Drop the add/remove pair fired by set_role() itself.
				$this->pending[ $id ] = array_filter(
					$this->pending[ $id ] ?? [],
					static fn( string $key ): bool => ! str_starts_with( $key, 'add:' ) && ! str_starts_with( $key, 'remove:' ),
					ARRAY_FILTER_USE_KEY
				);
				if ( [ $new ] === $old || ( null === $new && [] === $old ) ) {
					return;
				}
				$severity = UserRules::grantSeverity( $new, $old );
				$this->defer(
					$id,
					'set',
					Event::make( Category::USER, 'user.role_changed', $severity )
						->object( 'user', $id, $new )
						->summary( sprintf( 'User #%d role changed from %s to %s', $id, [] === $old ? '(none)' : implode( ', ', $old ), $new ?? '(none)' ) )
						->change( 'role', [] === $old ? null : implode( ',', $old ), $new )
						->context(
							[
								'old_roles' => $old,
								'new_role'  => $new,
								'elevated'  => Severity::CRITICAL === $severity,
							]
						)
				);
			}
		);
	}

	/**
	 * Role added.
	 *
	 * @param mixed $userId User id.
	 * @param mixed $role   Role.
	 */
	public function onAddRole( mixed $userId, mixed $role ): void {
		$this->logger->guard(
			'user.role_added',
			function () use ( $userId, $role ): void {
				$id       = (int) $userId;
				$name     = substr( (string) $role, 0, 64 );
				$severity = UserRules::grantSeverity( $name );
				$this->defer(
					$id,
					'add:' . $name,
					Event::make( Category::USER, 'user.role_added', $severity )
						->object( 'user', $id, $name )
						->summary( sprintf( 'User #%d was given role %s', $id, $name ) )
						->change( 'roles', null, $name )
						->context(
							[
								'role'     => $name,
								'elevated' => Severity::CRITICAL === $severity,
							]
						)
				);
			}
		);
	}

	/**
	 * Role removed.
	 *
	 * @param mixed $userId User id.
	 * @param mixed $role   Role.
	 */
	public function onRemoveRole( mixed $userId, mixed $role ): void {
		$this->logger->guard(
			'user.role_removed',
			function () use ( $userId, $role ): void {
				$id   = (int) $userId;
				$name = substr( (string) $role, 0, 64 );
				$this->defer(
					$id,
					'remove:' . $name,
					Event::make( Category::USER, 'user.role_removed', Severity::NOTICE )
						->object( 'user', $id, $name )
						->summary( sprintf( 'User #%d lost role %s', $id, $name ) )
						->change( 'roles', $name, null )
						->context( [ 'role' => $name ] )
				);
			}
		);
	}

	/**
	 * User deleted.
	 *
	 * @param mixed $userId   User id.
	 * @param mixed $reassign Reassign posts to this user id (null: deleted).
	 * @param mixed $user     WP_User (WordPress ≥ 5.5).
	 */
	public function onDeleted( mixed $userId, mixed $reassign = null, mixed $user = null ): void {
		$this->logger->guard(
			'user.deleted',
			function () use ( $userId, $reassign, $user ): void {
				$id = (int) $userId;
				unset( $this->pending[ $id ] );
				$roles      = $user instanceof \WP_User ? UserRules::roleList( array_values( (array) $user->roles ) ) : [];
				$reassignTo = null === $reassign || '' === $reassign ? null : (int) $reassign;
				$this->logger->log(
					Event::make( Category::USER, 'user.deleted', Severity::NOTICE )
						->object( 'user', $id, $roles[0] ?? null )
						->summary(
							null === $reassignTo
								? sprintf( 'User #%d deleted', $id )
								: sprintf( 'User #%d deleted, content reassigned to user #%d', $id, $reassignTo )
						)
						->context(
							[
								'reassign_id' => $reassignTo,
								'roles'       => $roles,
							]
						)
				);
			}
		);
	}

	/**
	 * Super admin granted (multisite).
	 *
	 * @param mixed $userId User id.
	 */
	public function onGrantSuperAdmin( mixed $userId ): void {
		$this->simple( 'user.super_admin_granted', Category::USER, Severity::CRITICAL, (int) $userId, 'User #%d granted super admin' );
	}

	/**
	 * Super admin revoked (multisite).
	 *
	 * @param mixed $userId User id.
	 */
	public function onRevokeSuperAdmin( mixed $userId ): void {
		$this->simple( 'user.super_admin_revoked', Category::USER, Severity::NOTICE, (int) $userId, 'User #%d super admin revoked' );
	}

	/**
	 * Application password created (the password itself is never seen by the logger).
	 *
	 * @param mixed $userId User id.
	 * @param mixed $item   Stored item: uuid, app_id, name, created….
	 */
	public function onAppPasswordCreated( mixed $userId, mixed $item = [] ): void {
		$this->logger->guard( 'user.app_password_created', fn() => $this->appPassword( 'user.app_password_created', 'created for', (int) $userId, $item ) );
	}

	/**
	 * Application password deleted.
	 *
	 * @param mixed $userId User id.
	 * @param mixed $item   Deleted item.
	 */
	public function onAppPasswordDeleted( mixed $userId, mixed $item = [] ): void {
		$this->logger->guard( 'user.app_password_deleted', fn() => $this->appPassword( 'user.app_password_deleted', 'deleted for', (int) $userId, $item ) );
	}

	/**
	 * Password reset completed (fires before the new password is stored).
	 *
	 * @param mixed $user WP_User.
	 */
	public function onPasswordReset( mixed $user ): void {
		$id = $user instanceof \WP_User ? (int) $user->ID : 0;
		$this->simple( 'user.password_reset', Category::USER, Severity::NOTICE, $id, 'Password of user #%d reset' );
	}

	/**
	 * Password reset requested. The login is only used to resolve the id.
	 *
	 * @param mixed $userLogin User login.
	 */
	public function onRetrievePassword( mixed $userLogin ): void {
		$this->logger->guard(
			'user.password_reset_requested',
			function () use ( $userLogin ): void {
				$user = is_string( $userLogin ) && '' !== $userLogin ? get_user_by( 'login', $userLogin ) : false;
				$id   = $user instanceof \WP_User ? (int) $user->ID : 0;
				if ( $id <= 0 ) {
					return;
				}
				$this->logger->log(
					Event::make( Category::USER, 'user.password_reset_requested', Severity::NOTICE )
						->object( 'user', $id )
						->summary( sprintf( 'Password reset requested for user #%d', $id ) )
				);
			}
		);
	}

	/**
	 * Log the deferred role events (shutdown, priority 5).
	 */
	public function flushPending(): void {
		$this->logger->guard(
			'user.flush',
			function (): void {
				$pending       = $this->pending;
				$this->pending = [];
				foreach ( $pending as $events ) {
					foreach ( $events as $event ) {
						$this->logger->log( $event );
					}
				}
			}
		);
	}

	/**
	 * Hold a role event until shutdown.
	 *
	 * @param int    $userId User id.
	 * @param string $key    Slot.
	 * @param Event  $event  Event.
	 */
	private function defer( int $userId, string $key, Event $event ): void {
		$this->pending[ $userId ][ $key ] = $event;
		if ( ! $this->flushHooked ) {
			$this->flushHooked = true;
			add_action( 'shutdown', [ $this, 'flushPending' ], 5, 0 );
		}
	}

	/**
	 * Application password event.
	 *
	 * @param string $action Action.
	 * @param string $verb   Summary verb.
	 * @param int    $userId User id.
	 * @param mixed  $item   Item.
	 */
	private function appPassword( string $action, string $verb, int $userId, mixed $item ): void {
		$item     = is_array( $item ) ? $item : [];
		$uuid     = isset( $item['uuid'] ) && is_string( $item['uuid'] ) ? substr( $item['uuid'], 0, 64 ) : null;
		$name     = isset( $item['name'] ) && is_string( $item['name'] ) ? $item['name'] : null;
		$nameHash = null === $name ? null : $this->logger->redactor()->hash8( $name );
		$this->logger->log(
			Event::make( Category::SECURITY, $action, Severity::NOTICE )
				->object( 'user', $userId, null, $nameHash )
				->summary( sprintf( 'Application password %s user #%d', $verb, $userId ) )
				->context(
					[
						'uuid'      => $uuid,
						'app_id'    => isset( $item['app_id'] ) && is_string( $item['app_id'] ) && '' !== $item['app_id'] ? substr( $item['app_id'], 0, 64 ) : null,
						'name_hash' => $nameHash,
					]
				)
		);
	}

	/**
	 * Event with only a user id.
	 *
	 * @param string   $action   Action.
	 * @param Category $category Category.
	 * @param Severity $severity Severity.
	 * @param int      $userId   User id.
	 * @param string   $format   Summary format with one %d.
	 */
	private function simple( string $action, Category $category, Severity $severity, int $userId, string $format ): void {
		$this->logger->guard(
			$action,
			function () use ( $action, $category, $severity, $userId, $format ): void {
				if ( $userId <= 0 ) {
					return;
				}
				$this->logger->log(
					Event::make( $category, $action, $severity )
						->object( 'user', $userId )
						->summary( sprintf( $format, $userId ) )
				);
			}
		);
	}

	/**
	 * Current roles of a user (fresh object).
	 *
	 * @param int $userId User id.
	 * @return list<string>
	 */
	private static function rolesOf( int $userId ): array {
		$user = get_userdata( $userId );
		return $user instanceof \WP_User ? UserRules::roleList( array_values( (array) $user->roles ) ) : [];
	}
}
