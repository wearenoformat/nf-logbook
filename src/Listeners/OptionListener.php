<?php
/**
 * OptionListener.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Listeners;

use Noformat\Logger\Category;
use Noformat\Logger\Config;
use Noformat\Logger\Event;
use Noformat\Logger\Listener;
use Noformat\Logger\Listeners\Support\System\OptionRules;
use Noformat\Logger\Logger;
use Noformat\Logger\Severity;

defined( 'ABSPATH' ) || exit;

/**
 * Settings: added/updated/deleted_option with ignore list and critical options (PLAN §4, §5).
 *
 * Skipped: the Config ignore list, options owned by other listeners (widgets,
 * sidebars, template/stylesheet), theme_mods_* during a Customizer save
 * (CustomizerListener) and active_plugins changed from the plugins screen or
 * WP-CLI (PluginThemeListener). active_plugins changed by anything else is
 * critical. nav_menu_locations inside theme_mods_* belongs to MenuListener.
 * ACF option page fields ({page}_{field}, _{page}_{field}) are skipped while
 * AcfListener saves that page (acf.options_saved).
 *
 * Machine state is logged at debug with context.technical (dropped unless
 * log_debug is on): option names matching Config::isTechnicalKey() and any write
 * during a read request, cron or heartbeat (RequestContext::machineWriteReason()).
 * Notice and critical options are exempt.
 */
final class OptionListener implements Listener {

	private Logger $logger;

	private Config $config;

	/**
	 * Values captured on delete_option, consumed by deleted_option.
	 *
	 * @var array<string, mixed>
	 */
	private array $deleting = [];

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
		$this->logger = $logger;
		$this->config = $config;
		add_action( 'added_option', [ $this, 'onAdded' ], 10, 2 );
		add_action( 'updated_option', [ $this, 'onUpdated' ], 10, 3 );
		add_action( 'delete_option', [ $this, 'onDeleting' ], 10, 1 );
		add_action( 'deleted_option', [ $this, 'onDeleted' ], 10, 1 );
	}

	/**
	 * Option added.
	 *
	 * @param mixed $option Option name.
	 * @param mixed $value  Value.
	 */
	public function onAdded( mixed $option, mixed $value ): void {
		$this->logger->guard( 'option.added', fn() => $this->record( 'option.added', (string) $option, null, $value ) );
	}

	/**
	 * Option updated.
	 *
	 * @param mixed $option   Option name.
	 * @param mixed $oldValue Old value.
	 * @param mixed $newValue New value.
	 */
	public function onUpdated( mixed $option, mixed $oldValue, mixed $newValue ): void {
		$this->logger->guard( 'option.updated', fn() => $this->record( 'option.updated', (string) $option, $oldValue, $newValue ) );
	}

	/**
	 * Before deletion: remember the value so the event can show what was removed.
	 *
	 * @param mixed $option Option name.
	 */
	public function onDeleting( mixed $option ): void {
		$this->logger->guard(
			'option.deleting',
			function () use ( $option ): void {
				$name = (string) $option;
				if ( null === $this->skipReason( $name ) && count( $this->deleting ) < 50 ) {
					$this->deleting[ $name ] = get_option( $name );
				}
			}
		);
	}

	/**
	 * Option deleted.
	 *
	 * @param mixed $option Option name.
	 */
	public function onDeleted( mixed $option ): void {
		$this->logger->guard(
			'option.deleted',
			function () use ( $option ): void {
				$name = (string) $option;
				$old  = $this->deleting[ $name ] ?? null;
				unset( $this->deleting[ $name ] );
				$this->record( 'option.deleted', $name, $old, null );
			}
		);
	}

	/**
	 * Build and log one option event.
	 *
	 * @param string $action   Action.
	 * @param string $name     Option name.
	 * @param mixed  $oldValue Old value (null when added).
	 * @param mixed  $newValue New value (null when deleted).
	 */
	private function record( string $action, string $name, mixed $oldValue, mixed $newValue ): void {
		if ( null !== $this->skipReason( $name ) ) {
			return;
		}
		$context = [];
		if ( 'active_plugins' === $name ) {
			$diff = OptionRules::pluginListDiff( $oldValue, $newValue );
			if ( [] === $diff['added'] && [] === $diff['removed'] ) {
				return;
			}
			$changes = [
				[
					'field' => 'active_plugins',
					'old'   => $diff['removed'],
					'new'   => $diff['added'],
				],
			];
			$context = $diff;
		} else {
			if ( str_starts_with( $name, 'theme_mods_' ) ) {
				// Keys owned by MenuListener or written lazily by WordPress.
				$oldValue = OptionRules::stripThemeMods( $oldValue );
				$newValue = OptionRules::stripThemeMods( $newValue );
			}
			$themeMods = str_starts_with( $name, 'theme_mods_' );
			$changes   = $this->logger->redactor()->diff( $oldValue, $newValue, $name );
			if ( [] === $changes && ( 'option.updated' === $action || $themeMods ) ) {
				return; // No-op update, only keys owned by another listener changed, or an empty theme_mods_* row.
			}
		}

		$severity  = OptionRules::severity( $name, $newValue );
		$technical = null;
		if ( $severity->priority() >= Severity::INFO->priority() ) {
			// Notice and critical options (siteurl, home, admin_email, default_role, …) are always exempt.
			$technical = $this->config->isTechnicalKey( $name ) ? 'key_pattern' : $this->logger->context()->machineWriteReason();
		}
		if ( null !== $technical ) {
			$severity                    = Severity::DEBUG;
			$context['technical']        = true;
			$context['technical_reason'] = $technical;
		}
		$verb    = substr( $action, strlen( 'option.' ) );
		$summary = sprintf( 'Option %s %s', $name, $verb );
		if ( 'active_plugins' === $name ) {
			$summary = sprintf( 'Active plugins changed outside the plugins screen (%d added, %d removed)', count( $context['added'] ), count( $context['removed'] ) );
		}

		$event = Event::make( Category::SETTINGS, $action, $severity )
			->object( 'option', $name )
			->summary( $summary )
			->changes( $changes );
		if ( [] !== $context ) {
			$event->context( $context );
		}
		if ( Severity::CRITICAL === $severity ) {
			$event->context( [ 'critical_option' => true ] );
		}
		$this->logger->log( $event );
	}

	/**
	 * Skip decision for the current request.
	 *
	 * @param string $name Option name.
	 */
	private function skipReason( string $name ): ?string {
		$reason = OptionRules::skipReason( $name, $this->config, $this->logger->context()->source(), self::customizing() );
		if ( null === $reason && AcfListener::isSavingOption( $name ) ) {
			return 'acf_options'; // acf.options_saved carries these fields.
		}
		return $reason;
	}

	/**
	 * Whether a Customizer save is in progress (CustomizerListener logs those settings).
	 */
	private static function customizing(): bool {
		return did_action( 'customize_save' ) > did_action( 'customize_save_after' );
	}
}
