<?php
/**
 * Tools → Noformat Logbook → Settings.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Admin;

use Noformat\Logger\Config;
use Noformat\Logger\Plugin;
use Noformat\Logger\Rest\Authenticator;

defined( 'ABSPATH' ) || exit;

/**
 * Settings (manage_options), registered with the Settings API under
 * nflog_settings_group and saved through options.php.
 */
final class SettingsPage {

	public const GROUP = 'nflog_settings_group';

	/** Module scope (Content / System heading) by slug. */
	private const MODULES = [
		'PostListener'        => 'content',
		'MetaListener'        => 'content',
		'TermListener'        => 'content',
		'MenuListener'        => 'content',
		'MediaListener'       => 'content',
		'CommentListener'     => 'content',
		'WidgetListener'      => 'content',
		'CustomizerListener'  => 'content',
		'AcfListener'         => 'content',
		'OptionListener'      => 'content',
		'PluginThemeListener' => 'content',
		'CoreListener'        => 'content',
		'UserListener'        => 'content',
		'PrivacyListener'     => 'content',
		'ErrorHandler'        => 'system',
		'FatalHandler'        => 'system',
		'DbErrorListener'     => 'system',
		'DeprecationListener' => 'system',
		'HttpApiListener'     => 'system',
		'MailListener'        => 'system',
		'AuthListener'        => 'system',
		'RestProbeListener'   => 'system',
		'XmlRpcListener'      => 'system',
		'ProbeListener'       => 'system',
		'ConfigAudit'         => 'system',
		'IntegrityCheck'      => 'system',
		'HealthReport'        => 'system',
	];

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( private Plugin $plugin ) {
	}

	/**
	 * Settings API registration.
	 */
	public function registerSettings(): void {
		register_setting(
			self::GROUP,
			Config::OPTION,
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize' ],
				'default'           => [],
				'show_in_rest'      => false,
			]
		);
	}

	/**
	 * Sanitize the settings form.
	 *
	 * @param mixed $input Raw input.
	 * @return array<string, mixed>
	 */
	public function sanitize( mixed $input ): array {
		$input    = is_array( $input ) ? $input : [];
		$previous = get_option( Config::OPTION, [] );
		$previous = is_array( $previous ) ? $previous : [];
		if ( ! current_user_can( 'manage_options' ) ) {
			return $previous;
		}
		$clean                          = $previous;
		$clean['retention_days']        = self::clampInt( $input['retention_days'] ?? 90, 1, 3650 );
		$clean['hard_max_days']         = max( $clean['retention_days'], self::clampInt( $input['hard_max_days'] ?? 180, 1, 3650 ) );
		$clean['max_rows']              = self::clampInt( $input['max_rows'] ?? 500000, 1000, 50000000 );
		$clean['events_visible_to']     = Access::CAPABILITY_ONLY === ( $input['events_visible_to'] ?? '' ) ? Access::CAPABILITY_ONLY : Access::ADMINISTRATORS;
		$clean['system_log_visible_to'] = Access::CAPABILITY_ONLY === ( $input['system_log_visible_to'] ?? '' ) ? Access::CAPABILITY_ONLY : Access::ADMINISTRATORS;
		foreach ( [ 'log_debug', 'full_content_diff', 'delete_data_on_uninstall' ] as $flag ) {
			$clean[ $flag ] = ! empty( $input[ $flag ] );
		}
		$allow = [];
		$raw   = isset( $input['ip_allowlist'] ) ? sanitize_textarea_field( (string) $input['ip_allowlist'] ) : '';
		foreach ( preg_split( '/[\s,]+/', $raw ) ? preg_split( '/[\s,]+/', $raw ) : [] as $entry ) {
			$entry = trim( $entry );
			if ( '' !== $entry && Authenticator::isValidAllowlistEntry( $entry ) ) {
				$allow[] = $entry;
			}
		}
		$clean['ip_allowlist'] = $allow;
		$modules               = [];
		$posted                = isset( $input['modules'] ) && is_array( $input['modules'] ) ? $input['modules'] : [];
		foreach ( Plugin::LISTENERS as $class ) {
			$slug             = Config::moduleSlug( $class );
			$modules[ $slug ] = ! empty( $posted[ $slug ] );
		}
		$clean['modules'] = $modules;
		return $clean;
	}

	/**
	 * Render the page (manage_options; the menu enforces it too).
	 *
	 * @param string $tabs Tab bar (escaped HTML, see Menu::tabBar()).
	 */
	public function render( string $tabs = '' ): void {
		echo '<div class="wrap nflog-wrap"><div class="nflog-page nflog-page--settings">' . $tabs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Menu::tabBar() escapes.
		echo Ui::header( __( 'Settings', 'nf-logbook' ), __( 'Retention, privacy, access and what is recorded', 'nf-logbook' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui escapes.
		if ( ! current_user_can( 'manage_options' ) ) {
			echo '<div class="nflog-card">' . Ui::empty( __( 'Only administrators can change these settings.', 'nf-logbook' ), '' ) . '</div></div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui escapes.
			return;
		}
		if ( isset( $_GET['settings-updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
			echo Ui::notice( __( 'Settings saved.', 'nf-logbook' ), 'success' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui escapes.
		}
		$config = $this->plugin->config();
		echo '<form method="post" action="options.php" class="nflog-settings">';
		settings_fields( self::GROUP );

		$this->section(
			__( 'Storage & retention', 'nf-logbook' ),
			__( 'How long events are kept in this site\'s database.', 'nf-logbook' ),
			function () use ( $config ): void {
				$numbers = [
					'retention_days' => [ __( 'Retention (days)', 'nf-logbook' ), __( 'Events older than this are pruned daily unless the Controlroom has not acknowledged them yet.', 'nf-logbook' ) ],
					'hard_max_days'  => [ __( 'Hard maximum (days)', 'nf-logbook' ), __( 'Events older than this are pruned even if unacknowledged.', 'nf-logbook' ) ],
					'max_rows'       => [ __( 'Maximum stored events', 'nf-logbook' ), __( 'When exceeded, the oldest acknowledged or low-severity events are pruned first.', 'nf-logbook' ) ],
				];
				echo '<div class="nflog-grid nflog-grid--3">';
				foreach ( $numbers as $name => [ $label, $description ] ) {
					printf(
						'<div class="nflog-field"><label class="nflog-label" for="nflog-%1$s">%2$s</label><input type="number" min="1" step="1" inputmode="numeric" class="nflog-input" id="nflog-%1$s" name="%3$s[%1$s]" value="%4$s" %5$s aria-describedby="nflog-%1$s-description"><p class="nflog-help" id="nflog-%1$s-description">%6$s</p></div>',
						esc_attr( $name ),
						esc_html( $label ),
						esc_attr( Config::OPTION ),
						esc_attr( (string) $config->int( $name ) ),
						disabled( $config->isPinned( $name ), true, false ),
						esc_html( $description . $this->pinnedNote( $name ) )
					);
				}
				echo '</div>';
				$this->toggle( 'log_debug', __( 'Debug events', 'nf-logbook' ), __( 'Also store debug-severity events, including technical fields such as login timestamps and screen preferences (noisy).', 'nf-logbook' ) );
				$this->toggle( 'delete_data_on_uninstall', __( 'Delete data on uninstall', 'nf-logbook' ), __( 'Drop the log tables, settings and key when the plugin is deleted.', 'nf-logbook' ) );
			}
		);

		$this->section(
			__( 'Privacy', 'nf-logbook' ),
			__( 'The log keeps ids and hashes; names and titles are only looked up while you view it.', 'nf-logbook' ),
			function (): void {
				$this->toggle( 'full_content_diff', __( 'Full content diffs', 'nf-logbook' ), __( 'Store full text diffs of post content instead of a hash and size delta. Off by default for privacy.', 'nf-logbook' ) );
			}
		);

		$this->section(
			__( 'Access', 'nf-logbook' ),
			__( 'Who can read the logs in wp-admin. The capability noformat_logger_view_events is assigned to no role on install.', 'nf-logbook' ),
			function () use ( $config ): void {
				echo '<div class="nflog-grid nflog-grid--2">';
				$this->accessChoice( 'events_visible_to', __( 'Content log, its export and the dashboard widget', 'nf-logbook' ), Access::mode( $config ), 'NOFORMAT_LOGGER_EVENTS_VISIBLE_TO' );
				$this->accessChoice( 'system_log_visible_to', __( 'System log (developer console), its export and live tail', 'nf-logbook' ), Access::systemMode( $config ), 'NOFORMAT_LOGGER_SYSTEM_LOG_VISIBLE_TO' );
				echo '</div>';
			}
		);

		$this->section(
			__( 'Controlroom API', 'nf-logbook' ),
			__( 'The Noformat Controlroom reads the log through the signed API. Keys are managed on the Status page.', 'nf-logbook' ),
			function () use ( $config ): void {
				$settings = $config->all();
				$allow    = is_array( $settings['ip_allowlist'] ?? null ) ? implode( "\n", $settings['ip_allowlist'] ) : '';
				printf(
					'<div class="nflog-field"><label class="nflog-label" for="nflog-ip">%1$s</label><textarea id="nflog-ip" rows="3" class="nflog-input nflog-input--mono nflog-textarea" aria-describedby="nflog-ip-description" name="%2$s[ip_allowlist]">%3$s</textarea><p class="nflog-help" id="nflog-ip-description">%4$s</p></div>',
					esc_html__( 'API IP allowlist', 'nf-logbook' ),
					esc_attr( Config::OPTION ),
					esc_textarea( $allow ),
					esc_html__( 'Addresses allowed to call the signed Controlroom API. One IP or CIDR per line; empty allows any IP (the signature is still required).', 'nf-logbook' )
				);
			}
		);

		$this->section(
			__( 'Modules', 'nf-logbook' ),
			__( 'Untick to disable a listener on this site.', 'nf-logbook' ),
			function () use ( $config ): void {
				$modules = $this->plugin->modules();
				foreach ( [
					'content' => __( 'Content', 'nf-logbook' ),
					'system'  => __( 'System', 'nf-logbook' ),
				] as $scope => $heading ) {
					echo '<h3 class="nflog-subheading">' . esc_html( $heading ) . '</h3><div class="nflog-modules">';
					foreach ( $modules as $slug => $status ) {
						if ( ( self::MODULES[ $slug ] ?? 'system' ) !== $scope ) {
							continue;
						}
						printf(
							'<label class="nflog-module%1$s"><input type="checkbox" name="%2$s[modules][%3$s]" value="1"%4$s%5$s><span class="nflog-module__text"><span class="nflog-module__name">%6$s <code>%3$s</code></span><span class="nflog-module__desc">%7$s</span>%8$s</span></label>',
							$status['available'] ? '' : ' is-unavailable',
							esc_attr( Config::OPTION ),
							esc_attr( $slug ),
							checked( $status['enabled'], true, false ),
							disabled( $config->isPinned( 'module:' . $slug ), true, false ),
							esc_html( self::moduleLabel( $slug ) ),
							esc_html( self::moduleDescription( $slug ) ),
							$status['available'] ? '' : '<span class="nflog-module__note">' . esc_html__( 'Not available on this site', 'nf-logbook' ) . '</span>'
						);
					}
					echo '</div>';
				}
			}
		);

		echo '<div class="nflog-settings__actions"><button type="submit" class="nflog-btn nflog-btn--primary" name="submit" id="submit">' . esc_html__( 'Save settings', 'nf-logbook' ) . '</button></div>';
		echo '</form></div></div>';
	}

	/**
	 * A settings section card.
	 *
	 * @param string   $title       Title.
	 * @param string   $description Description.
	 * @param callable $body        Body renderer.
	 */
	private function section( string $title, string $description, callable $body ): void {
		echo '<section class="nflog-card nflog-section"><div class="nflog-section__head"><h2 class="nflog-section__title">' . esc_html( $title ) . '</h2><p class="nflog-section__desc">' . esc_html( $description ) . '</p></div><div class="nflog-section__body">';
		$body();
		echo '</div></section>';
	}

	/**
	 * A checkbox setting rendered as a switch.
	 *
	 * @param string $name        Setting.
	 * @param string $label       Label.
	 * @param string $description Description.
	 */
	private function toggle( string $name, string $label, string $description ): void {
		$config = $this->plugin->config();
		printf(
			'<label class="nflog-switch"><input type="checkbox" role="switch" name="%1$s[%2$s]" value="1"%3$s%4$s aria-describedby="nflog-%2$s-description"><span class="nflog-switch__track" aria-hidden="true"></span><span class="nflog-switch__text"><span class="nflog-switch__label">%5$s</span><span class="nflog-help" id="nflog-%2$s-description">%6$s</span></span></label>',
			esc_attr( Config::OPTION ),
			esc_attr( $name ),
			checked( $config->bool( $name ), true, false ),
			disabled( $config->isPinned( $name ), true, false ),
			esc_html( $label ),
			esc_html( $description . $this->pinnedNote( $name ) )
		);
	}

	/**
	 * Radio group for an access setting.
	 *
	 * @param string $name     Setting.
	 * @param string $legend   Legend.
	 * @param string $mode     Current mode.
	 * @param string $constant Constant name.
	 */
	private function accessChoice( string $name, string $legend, string $mode, string $constant ): void {
		$config  = $this->plugin->config();
		$choices = [
			Access::ADMINISTRATORS  => [ __( 'Administrators', 'nf-logbook' ), __( 'manage_options, plus anyone with the noformat_logger_view_events capability', 'nf-logbook' ) ],
			Access::CAPABILITY_ONLY => [ __( 'Capability only', 'nf-logbook' ), __( 'only users with noformat_logger_view_events (client sites)', 'nf-logbook' ) ],
		];
		echo '<fieldset class="nflog-radios"><legend class="nflog-label">' . esc_html( $legend ) . '</legend>';
		foreach ( $choices as $value => [ $label, $help ] ) {
			printf(
				'<label class="nflog-radio"><input type="radio" name="%1$s[%2$s]" value="%3$s"%4$s%5$s><span><span class="nflog-radio__label">%6$s</span><span class="nflog-help">%7$s</span></span></label>',
				esc_attr( Config::OPTION ),
				esc_attr( $name ),
				esc_attr( $value ),
				checked( $mode, $value, false ),
				disabled( $config->isPinned( $name ), true, false ),
				esc_html( $label ),
				esc_html( $help )
			);
		}
		/* translators: %s: constant name */
		echo '<p class="nflog-help">' . esc_html( sprintf( __( 'Constant: %s.', 'nf-logbook' ), $constant ) . $this->pinnedNote( $name ) ) . '</p></fieldset>';
	}

	/**
	 * " Set in wp-config.php." when a constant pins the setting.
	 *
	 * @param string $name Setting.
	 */
	private function pinnedNote( string $name ): string {
		return $this->plugin->config()->isPinned( $name ) ? ' ' . __( 'Set in wp-config.php.', 'nf-logbook' ) : '';
	}

	/**
	 * Translated module label.
	 *
	 * @param string $slug Slug.
	 */
	private static function moduleLabel( string $slug ): string {
		return match ( $slug ) {
			'PostListener'        => __( 'Posts and pages', 'nf-logbook' ),
			'MetaListener'        => __( 'Custom fields (meta)', 'nf-logbook' ),
			'TermListener'        => __( 'Categories and tags', 'nf-logbook' ),
			'MenuListener'        => __( 'Menus', 'nf-logbook' ),
			'MediaListener'       => __( 'Media library', 'nf-logbook' ),
			'CommentListener'     => __( 'Comments', 'nf-logbook' ),
			'WidgetListener'      => __( 'Widgets', 'nf-logbook' ),
			'CustomizerListener'  => __( 'Customizer', 'nf-logbook' ),
			'AcfListener'         => __( 'Advanced Custom Fields', 'nf-logbook' ),
			'OptionListener'      => __( 'Settings', 'nf-logbook' ),
			'PluginThemeListener' => __( 'Plugins and themes', 'nf-logbook' ),
			'CoreListener'        => __( 'WordPress updates', 'nf-logbook' ),
			'UserListener'        => __( 'Users', 'nf-logbook' ),
			'PrivacyListener'     => __( 'Export, import and privacy', 'nf-logbook' ),
			'ErrorHandler'        => __( 'PHP errors', 'nf-logbook' ),
			'FatalHandler'        => __( 'Fatal errors', 'nf-logbook' ),
			'DbErrorListener'     => __( 'Database errors', 'nf-logbook' ),
			'DeprecationListener' => __( 'Deprecations', 'nf-logbook' ),
			'HttpApiListener'     => __( 'Outbound HTTP', 'nf-logbook' ),
			'MailListener'        => __( 'Mail', 'nf-logbook' ),
			'AuthListener'        => __( 'Logins', 'nf-logbook' ),
			'RestProbeListener'   => __( 'REST probing', 'nf-logbook' ),
			'XmlRpcListener'      => __( 'XML-RPC', 'nf-logbook' ),
			'ProbeListener'       => __( 'Suspicious requests', 'nf-logbook' ),
			'ConfigAudit'         => __( 'Configuration audit', 'nf-logbook' ),
			'IntegrityCheck'      => __( 'File integrity', 'nf-logbook' ),
			'HealthReport'        => __( 'Health', 'nf-logbook' ),
			default               => $slug,
		};
	}

	/**
	 * Translated module description.
	 *
	 * @param string $slug Slug.
	 */
	private static function moduleDescription( string $slug ): string {
		return match ( $slug ) {
			'PostListener'        => __( 'Created, edited, published, trashed and deleted posts, pages and custom post types.', 'nf-logbook' ),
			'MetaListener'        => __( 'Added, changed and removed meta values on posts, terms, users and comments.', 'nf-logbook' ),
			'TermListener'        => __( 'Terms created, renamed and deleted, and which terms a post has.', 'nf-logbook' ),
			'MenuListener'        => __( 'Menus, menu items and menu locations.', 'nf-logbook' ),
			'MediaListener'       => __( 'Uploads, edited details, regenerated sizes and deleted files.', 'nf-logbook' ),
			'CommentListener'     => __( 'New, edited, approved, spammed and deleted comments.', 'nf-logbook' ),
			'WidgetListener'      => __( 'Widgets added, changed, removed or moved between sidebars.', 'nf-logbook' ),
			'CustomizerListener'  => __( 'Settings saved in the Customizer, including Additional CSS.', 'nf-logbook' ),
			'AcfListener'         => __( 'Field values, options pages and field group changes.', 'nf-logbook' ),
			'OptionListener'      => __( 'Changes to site settings; critical options such as the site address are flagged.', 'nf-logbook' ),
			'PluginThemeListener' => __( 'Installed, activated, updated and deleted plugins and themes, and file editor use.', 'nf-logbook' ),
			'CoreListener'        => __( 'Core updates and the results of automatic updates.', 'nf-logbook' ),
			'UserListener'        => __( 'New and deleted users, role changes, application passwords and password resets.', 'nf-logbook' ),
			'PrivacyListener'     => __( 'Content exports and imports, personal data exports and erasures.', 'nf-logbook' ),
			'ErrorHandler'        => __( 'Warnings, notices and deprecations, aggregated by file, line and message.', 'nf-logbook' ),
			'FatalHandler'        => __( 'Fatal errors and uncaught exceptions that stop a request.', 'nf-logbook' ),
			'DbErrorListener'     => __( 'Failed database queries (normalised, without values).', 'nf-logbook' ),
			'DeprecationListener' => __( 'Deprecated functions, hooks, arguments and incorrect usage notices.', 'nf-logbook' ),
			'HttpApiListener'     => __( 'Failed requests to other servers (host and status only).', 'nf-logbook' ),
			'MailListener'        => __( 'E-mails WordPress could not send.', 'nf-logbook' ),
			'AuthListener'        => __( 'Logins, logouts, failed logins and bursts of failures.', 'nf-logbook' ),
			'RestProbeListener'   => __( 'User enumeration and REST authentication errors.', 'nf-logbook' ),
			'XmlRpcListener'      => __( 'XML-RPC calls, including multicall login attempts.', 'nf-logbook' ),
			'ProbeListener'       => __( '404s matching known attack patterns (wp-config, .env, backups …).', 'nf-logbook' ),
			'ConfigAudit'         => __( 'Risky settings such as debug display or file editing, checked daily.', 'nf-logbook' ),
			'IntegrityCheck'      => __( 'Core and plugin files compared with the official checksums, weekly.', 'nf-logbook' ),
			'HealthReport'        => __( 'Versions, cron, pending updates and Site Health for the Controlroom.', 'nf-logbook' ),
			default               => '',
		};
	}

	/**
	 * Clamp to an integer range.
	 *
	 * @param mixed $value Value.
	 * @param int   $min   Min.
	 * @param int   $max   Max.
	 */
	private static function clampInt( mixed $value, int $min, int $max ): int {
		$value = is_numeric( $value ) ? (int) $value : $min;
		return max( $min, min( $max, $value ) );
	}
}
