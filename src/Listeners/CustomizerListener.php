<?php
/**
 * CustomizerListener.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Listeners;

use Noformat\Logger\Category;
use Noformat\Logger\Config;
use Noformat\Logger\Event;
use Noformat\Logger\Listener;
use Noformat\Logger\Listeners\Support\Hooks;
use Noformat\Logger\Logger;
use Noformat\Logger\Severity;

defined( 'ABSPATH' ) || exit;

/**
 * Customizer: customize_save (old values) and customize_save_after (new values), one event per changed setting (PLAN §4).
 *
 * Both hooks fire once per changeset publish (also for scheduled changesets
 * published by cron). Settings stored elsewhere and logged by their own
 * listener (menus, menu locations, widgets, sidebars) are skipped.
 */
final class CustomizerListener implements Listener {

	use Hooks;

	/**
	 * Setting id prefixes owned by other listeners.
	 *
	 * @var list<string>
	 */
	public const SKIP_PREFIXES = [ 'nav_menu', 'nav_menus_created_posts', 'sidebars_widgets', 'widget_' ];

	/**
	 * Setting ids whose values are free text (content summary).
	 *
	 * @var list<string>
	 */
	public const TEXT_SETTINGS = [ 'custom_css' ];

	/**
	 * Values before the publish, by setting id.
	 *
	 * @var array<string, mixed>
	 */
	private array $before = [];

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
		$this->bind( $logger, $config );
		$this->onAction( 'customize_save', 'beforeSave', 10, 1 );
		$this->onAction( 'customize_save_after', 'afterSave', 10, 1 );
	}

	/**
	 * Whether a setting id is logged here.
	 *
	 * @param string $settingId Setting id.
	 */
	public static function handles( string $settingId ): bool {
		$base = explode( '[', $settingId, 2 )[0];
		foreach ( self::SKIP_PREFIXES as $prefix ) {
			if ( str_starts_with( $base, $prefix ) ) {
				return false;
			}
		}
		return '' !== $base;
	}

	/**
	 * Capture current values of the settings about to be saved.
	 *
	 * @param mixed $manager WP_Customize_Manager.
	 */
	public function beforeSave( mixed $manager ): void {
		$this->before = [];
		foreach ( self::changedSettings( $manager ) as $id => $setting ) {
			$this->before[ $id ] = $setting->value();
		}
	}

	/**
	 * Diff after the save, one customizer.saved per changed setting.
	 *
	 * @param mixed $manager WP_Customize_Manager.
	 */
	public function afterSave( mixed $manager ): void {
		$before       = $this->before;
		$this->before = [];
		$uuid         = is_object( $manager ) && method_exists( $manager, 'changeset_uuid' ) ? (string) $manager->changeset_uuid() : null;
		foreach ( self::changedSettings( $manager ) as $id => $setting ) {
			if ( ! array_key_exists( $id, $before ) ) {
				continue;
			}
			$type = isset( $setting->type ) && is_string( $setting->type ) ? $setting->type : null;
			$this->emitSetting( $id, $type, $before[ $id ], $setting->value(), $uuid );
		}
	}

	/**
	 * Build and queue one setting event (none when the value did not change).
	 *
	 * @param string      $id   Setting id.
	 * @param string|null $type Setting type (theme_mod, option, custom…).
	 * @param mixed       $oldValue  Old value.
	 * @param mixed       $newValue  New value.
	 * @param string|null $uuid Changeset uuid.
	 */
	public function emitSetting( string $id, ?string $type, mixed $oldValue, mixed $newValue, ?string $uuid = null ): void {
		if ( null === $this->logger ) {
			return;
		}
		if ( in_array( $id, self::TEXT_SETTINGS, true ) || str_starts_with( $id, 'custom_css[' ) ) {
			$a       = is_scalar( $oldValue ) ? (string) $oldValue : '';
			$b       = is_scalar( $newValue ) ? (string) $newValue : '';
			$changes = $a === $b ? [] : [
				[
					'field' => $id,
					'old'   => $this->contentSummary( $a ),
					'new'   => $this->contentSummary( $b, $a ),
				],
			];
		} else {
			$changes = $this->logger->redactor()->diff( $oldValue, $newValue, $id );
		}
		if ( [] === $changes ) {
			return;
		}
		$this->emit(
			Event::make( Category::THEME, 'customizer.saved', Severity::NOTICE )
				->object( 'customizer', $id, $id )
				->summary( sprintf( 'Customizer setting %s saved', $id ) )
				->changes( $changes )
				->context(
					[
						'setting_id'     => $id,
						'setting_type'   => $type,
						'changeset_uuid' => $uuid,
					]
				)
		);
	}

	/**
	 * Settings in the changeset being published, keyed by id.
	 *
	 * @param mixed $manager WP_Customize_Manager.
	 * @return array<string, object>
	 */
	private static function changedSettings( mixed $manager ): array {
		if ( ! is_object( $manager ) || ! method_exists( $manager, 'unsanitized_post_values' ) || ! method_exists( $manager, 'get_setting' ) ) {
			return [];
		}
		$values = $manager->unsanitized_post_values(
			[
				'exclude_post_data' => true,
				'exclude_changeset' => false,
			]
		);
		$out    = [];
		foreach ( is_array( $values ) ? array_keys( $values ) : [] as $id ) {
			$id = (string) $id;
			if ( ! self::handles( $id ) ) {
				continue;
			}
			$setting = $manager->get_setting( $id );
			if ( is_object( $setting ) && method_exists( $setting, 'value' ) ) {
				$out[ $id ] = $setting;
			}
		}
		return $out;
	}
}
