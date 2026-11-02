<?php
/**
 * Tools → Noformat Logbook → Status.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Admin;

use Noformat\Logger\Cron\Scheduler;
use Noformat\Logger\Plugin;
use Noformat\Logger\Rest\KeyStore;
use Noformat\Logger\Storage\Schema;
use Noformat\Logger\Update\Updater;

defined( 'ABSPATH' ) || exit;

/**
 * Status cards: Controlroom connection (and key management for
 * administrators), storage and the hash chain (verify inline), site health,
 * the plugin's scheduled jobs and its updates (Check now for administrators). Readable by everyone who may open the menu;
 * key actions need manage_options.
 */
final class StatusPage {

	public const NONCE = 'nflog_key_action';

	/**
	 * One-time key display.
	 *
	 * @var array{id: string, secret: string}|null
	 */
	private ?array $newKey = null;

	private string $notice = '';

	private string $noticeType = 'success';

	private ?Updater $updater = null;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( private Plugin $plugin ) {
	}

	/**
	 * Page URL.
	 */
	public static function url(): string {
		return Menu::url( Menu::STATUS );
	}

	/**
	 * Load-{hook}: generate / rotate / revoke before output.
	 */
	public function load(): void {
		$this->handleKeyAction();
		$this->handleUpdateCheck();
	}

	/**
	 * "Check now" on the Updates card: forget the cached release and check GitHub again.
	 */
	public function handleUpdateCheck(): void {
		if ( ! isset( $_POST['nflog_update_check'] ) ) {
			return;
		}
		check_admin_referer( Updater::CHECK_NONCE );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to check for Noformat Logbook updates.', 'nf-logbook' ), 403 );
		}
		$state            = $this->updater()->checkNow();
		$this->notice     = '' === $state['error'] ? __( 'Update check done.', 'nf-logbook' ) : __( 'Update check failed:', 'nf-logbook' ) . ' ' . $state['error'];
		$this->noticeType = '' === $state['error'] ? 'success' : 'error';
		nocache_headers();
	}

	/**
	 * The updater (lazily).
	 */
	private function updater(): Updater {
		return $this->updater ??= new Updater( $this->plugin );
	}

	/**
	 * Handle generate/rotate/revoke.
	 */
	public function handleKeyAction(): void {
		if ( ! isset( $_POST['nflog_key_action'] ) ) {
			return;
		}
		check_admin_referer( self::NONCE );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage the Noformat Logbook key.', 'nf-logbook' ), 403 );
		}
		$action = sanitize_key( wp_unslash( (string) $_POST['nflog_key_action'] ) );
		$keys   = $this->plugin->keys();
		try {
			switch ( $action ) {
				case 'generate':
					$this->newKey = $keys->generate();
					$this->plugin->logKeyChange( 'generated', $this->newKey['id'] );
					$this->notice = __( 'Key generated. Copy the secret now: it is shown only once.', 'nf-logbook' );
					break;
				case 'rotate':
					$pair         = $keys->rotate();
					$this->newKey = [
						'id'     => $pair['id'],
						'secret' => $pair['secret'],
					];
					$this->plugin->logKeyChange( 'rotated', $pair['id'], $pair['previous_id'] );
					$this->notice = __( 'Key rotated. The previous key stays valid for 24 hours. Copy the new secret now: it is shown only once.', 'nf-logbook' );
					break;
				case 'revoke':
					$previous = $keys->describe()['previous_id'];
					$keys->revoke( false );
					$this->plugin->logKeyChange( 'revoked', $previous );
					$this->notice = __( 'Previous key revoked.', 'nf-logbook' );
					break;
			}
		} catch ( \Throwable $e ) {
			$this->notice     = $e->getMessage();
			$this->noticeType = 'error';
		}
		nocache_headers();
	}

	/**
	 * Render the page.
	 *
	 * @param string $tabs Tab bar (escaped HTML, see Menu::tabBar()).
	 */
	public function render( string $tabs = '' ): void {
		$canManage = current_user_can( 'manage_options' );
		$manifest  = $this->plugin->manifest();
		echo '<div class="wrap nflog-wrap"><div class="nflog-page nflog-page--status">' . $tabs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Menu::tabBar() escapes.
		echo Ui::header( __( 'Status', 'nf-logbook' ), __( 'Connection, storage, health and scheduled jobs', 'nf-logbook' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui escapes.
		if ( '' !== $this->notice ) {
			echo Ui::notice( $this->notice, $this->noticeType ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui escapes.
		}
		if ( null !== $this->newKey ) {
			$this->secret( $this->newKey );
		}
		echo '<div class="nflog-cards">';
		$this->connection( $manifest, $canManage );
		$this->storage( $manifest );
		$this->health();
		$this->jobs();
		$this->updates( $canManage );
		echo '</div></div></div>';
	}

	/**
	 * One-time secret display.
	 *
	 * @param array{id: string, secret: string} $key New key.
	 */
	private function secret( array $key ): void {
		echo '<section class="nflog-card nflog-secret"><h2 class="nflog-card__title">' . Ui::icon( 'lock' ) . esc_html__( 'New API key', 'nf-logbook' ) . '</h2>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG.
		echo '<dl class="nflog-kv"><div><dt>' . esc_html__( 'Key id', 'nf-logbook' ) . '</dt><dd><code>' . esc_html( $key['id'] ) . '</code></dd></div>';
		echo '<div><dt>' . esc_html__( 'Secret (shown once)', 'nf-logbook' ) . '</dt><dd><code class="nflog-secret__value">' . esc_html( $key['secret'] ) . '</code></dd></div></dl>';
		echo '<p class="nflog-help">' . esc_html__( 'Recommended: move the key to wp-config.php (it then no longer lives in the database):', 'nf-logbook' ) . '</p>';
		echo '<pre class="nflog-code">' . esc_html( KeyStore::configLines( $key['id'], $key['secret'] ) ) . '</pre></section>';
	}

	/**
	 * Connection card.
	 *
	 * @param array<string, mixed> $manifest  Manifest.
	 * @param bool                 $canManage manage_options.
	 */
	private function connection( array $manifest, bool $canManage ): void {
		$key      = $this->plugin->keys()->describe();
		$lastPull = is_array( $manifest['last_pull'] ?? null ) ? (int) ( $manifest['last_pull']['at'] ?? 0 ) : 0;
		$sources  = [
			'constant' => __( 'wp-config.php constants', 'nf-logbook' ),
			'option'   => __( 'database (encrypted)', 'nf-logbook' ),
			'none'     => __( 'none', 'nf-logbook' ),
		];
		$state    = null === $key['id'] ? [ 'warning', __( 'No key', 'nf-logbook' ) ] : ( $lastPull > 0 && time() - $lastPull < 2 * DAY_IN_SECONDS ? [ 'ok', __( 'Connected', 'nf-logbook' ) ] : [ 'idle', __( 'Waiting for the Controlroom', 'nf-logbook' ) ] );
		$this->cardOpen( __( 'Connection', 'nf-logbook' ), 'shield', $state );
		$rows = [
			__( 'Key id', 'nf-logbook' )        => null === $key['id'] ? '—' : '<code>' . esc_html( $key['id'] ) . '</code>',
			__( 'Secret source', 'nf-logbook' ) => esc_html( $sources[ $key['source'] ] ?? $key['source'] ),
			__( 'Previous key', 'nf-logbook' )  => null === $key['previous_id'] ? '—' : '<code>' . esc_html( $key['previous_id'] ) . '</code>' . ( null === $key['previous_valid_until'] ? '' : ' <span class="nflog-muted">' . esc_html(
				/* translators: %s: date */
				sprintf( __( 'valid until %s', 'nf-logbook' ), gmdate( 'Y-m-d H:i', $key['previous_valid_until'] ) . ' UTC' )
			) . '</span>' ),
			__( 'Last pull', 'nf-logbook' )     => $lastPull > 0 ? self::ago( $lastPull ) : esc_html__( 'never', 'nf-logbook' ),
			__( 'Ack cursor', 'nf-logbook' )    => '<span class="nflog-num">#' . esc_html( number_format_i18n( (int) ( $manifest['ack_cursor'] ?? 0 ) ) ) . '</span>',
			__( 'API', 'nf-logbook' )           => '<a href="' . esc_url( rest_url( 'noformat-logger/v1' ) ) . '">' . esc_html__( 'API index (JSON)', 'nf-logbook' ) . '</a> · <a href="' . esc_url( plugins_url( 'docs/API.md', NOFORMAT_LOGGER_FILE ) ) . '">' . esc_html__( 'API docs', 'nf-logbook' ) . '</a>',
		];
		self::kv( $rows );
		if ( $canManage ) {
			echo '<form method="post" class="nflog-card__actions" action="' . esc_url( self::url() ) . '">';
			wp_nonce_field( self::NONCE );
			$constant = 'constant' === $key['source'];
			if ( $constant ) {
				echo '<p class="nflog-help">' . esc_html__( 'The key is defined in wp-config.php. Rotate it there (or with `wp nflog key generate --print-config`).', 'nf-logbook' ) . '</p>';
			} elseif ( null === $key['id'] ) {
				echo '<button type="submit" class="nflog-btn nflog-btn--primary" name="nflog_key_action" value="generate">' . esc_html__( 'Generate key', 'nf-logbook' ) . '</button>';
			} else {
				echo '<button type="submit" class="nflog-btn nflog-btn--secondary" name="nflog_key_action" value="rotate">' . esc_html__( 'Rotate key', 'nf-logbook' ) . '</button>';
				if ( null !== $key['previous_id'] ) {
					echo '<button type="submit" class="nflog-btn nflog-btn--ghost" name="nflog_key_action" value="revoke">' . esc_html__( 'Revoke previous key now', 'nf-logbook' ) . '</button>';
				}
			}
			echo '</form>';
		}
		echo '</section>';
	}

	/**
	 * Storage card with the inline chain verification.
	 *
	 * @param array<string, mixed> $manifest Manifest.
	 */
	private function storage( array $manifest ): void {
		$storage   = is_array( $manifest['storage'] ?? null ) ? $manifest['storage'] : [];
		$head      = is_array( $manifest['chain_head'] ?? null ) ? $manifest['chain_head'] : [];
		$available = (bool) ( $storage['available'] ?? false );
		$failures  = (int) ( $manifest['failures']['count'] ?? 0 );
		$this->cardOpen( __( 'Storage', 'nf-logbook' ), 'refresh', $available ? ( $failures > 0 ? [ 'warning', __( 'Available, with failures', 'nf-logbook' ) ] : [ 'ok', __( 'Available', 'nf-logbook' ) ] ) : [ 'error', __( 'Unavailable', 'nf-logbook' ) ] );
		echo '<div class="nflog-metric"><span class="nflog-metric__value">' . esc_html( number_format_i18n( (int) ( $storage['events'] ?? 0 ) ) ) . '</span><span class="nflog-metric__label">' . esc_html__( 'stored events', 'nf-logbook' ) . '</span></div>';
		$hash = (string) ( $head['hash'] ?? '' );
		$rows = [
			__( 'Event ids', 'nf-logbook' )         => '<span class="nflog-num">#' . esc_html( number_format_i18n( (int) ( $storage['oldest_id'] ?? 0 ) ) ) . ' – #' . esc_html( number_format_i18n( (int) ( $storage['newest_id'] ?? 0 ) ) ) . '</span>',
			__( 'Chain head', 'nf-logbook' )        => '<code title="' . esc_attr( $hash ) . '">#' . esc_html( (string) (int) ( $head['id'] ?? 0 ) ) . ' ' . esc_html( substr( $hash, 0, 16 ) ) . '…</code>',
			__( 'Schema', 'nf-logbook' )            => esc_html(
				/* translators: 1: installed schema version, 2: expected version */
				sprintf( __( '%1$d (expected %2$d)', 'nf-logbook' ), Schema::installedVersion(), Schema::VERSION )
			),
			__( 'Retention', 'nf-logbook' )         => esc_html(
				/* translators: 1: retention days, 2: hard maximum days, 3: maximum rows */
				sprintf( __( '%1$s days (hard max %2$s), at most %3$s events', 'nf-logbook' ), number_format_i18n( (int) ( $manifest['retention']['retention_days'] ?? 0 ) ), number_format_i18n( (int) ( $manifest['retention']['hard_max_days'] ?? 0 ) ), number_format_i18n( (int) ( $manifest['retention']['max_rows'] ?? 0 ) ) )
			),
			__( 'Internal failures', 'nf-logbook' ) => '<span class="nflog-num">' . esc_html( number_format_i18n( $failures ) ) . '</span>',
			__( 'Plugin version', 'nf-logbook' )    => esc_html( NOFORMAT_LOGGER_VERSION ),
		];
		self::kv( $rows );
		printf(
			'<div class="nflog-card__actions"><button type="button" class="nflog-btn nflog-btn--secondary" data-nflog-verify data-url="%1$s" data-nonce="%2$s">%3$s<span>%4$s</span></button><p class="nflog-verify-result" data-nflog-verify-result role="status" aria-live="polite"></p></div>',
			esc_url( admin_url( 'admin-ajax.php' ) ),
			esc_attr( wp_create_nonce( Ajax::VERIFY_NONCE ) ),
			Ui::icon( 'check' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG.
			esc_html(
				/* translators: %s: number of events */
				sprintf( __( 'Verify chain (last %s events)', 'nf-logbook' ), number_format_i18n( Ajax::VERIFY_ROWS ) )
			)
		);
		echo '</section>';
	}

	/**
	 * Health card (snapshot: cheap parts live, heavy parts from the 15 min cache).
	 */
	private function health(): void {
		try {
			$snapshot = $this->plugin->health()->snapshot();
		} catch ( \Throwable $e ) {
			$snapshot = [];
		}
		$versions  = is_array( $snapshot['versions'] ?? null ) ? $snapshot['versions'] : [];
		$cron      = is_array( $snapshot['cron'] ?? null ) ? $snapshot['cron'] : [];
		$updates   = is_array( $snapshot['updates'] ?? null ) ? $snapshot['updates'] : [];
		$integrity = is_array( $snapshot['integrity'] ?? null ) ? $snapshot['integrity'] : [];
		$overdue   = (int) ( $cron['overdue_count'] ?? 0 );
		$pending   = (int) ( $updates['core'] ?? 0 ) + (int) ( $updates['plugins'] ?? 0 ) + (int) ( $updates['themes'] ?? 0 );
		$intOk     = self::integrityOk( $integrity );
		$state     = false === $intOk ? [ 'error', __( 'Integrity mismatch', 'nf-logbook' ) ] : ( $overdue > 0 || $pending > 0 ? [ 'warning', __( 'Needs attention', 'nf-logbook' ) ] : [ 'ok', __( 'Healthy', 'nf-logbook' ) ] );
		$this->cardOpen( __( 'Health', 'nf-logbook' ), 'pulse', $state );
		$db   = trim( (string) ( $versions['db_engine'] ?? '' ) . ' ' . (string) ( $versions['db_server'] ?? '' ) );
		$rows = [
			__( 'PHP', 'nf-logbook' )             => esc_html( (string) ( $versions['php'] ?? PHP_VERSION ) ),
			__( 'WordPress', 'nf-logbook' )       => esc_html( (string) ( $versions['wordpress'] ?? get_bloginfo( 'version' ) ) ),
			__( 'Database', 'nf-logbook' )        => esc_html( '' === $db ? '—' : $db ),
			__( 'Environment', 'nf-logbook' )     => esc_html( is_string( $snapshot['environment'] ?? null ) ? $snapshot['environment'] : '—' ),
			__( 'Cron', 'nf-logbook' )            => self::pill( 0 === $overdue ? 'ok' : 'warning', 0 === $overdue ? __( 'on time', 'nf-logbook' ) : sprintf( /* translators: %s: number of jobs */ _n( '%s job overdue', '%s jobs overdue', $overdue, 'nf-logbook' ), number_format_i18n( $overdue ) ) ) . ( isset( $cron['last_run_age'] ) && is_numeric( $cron['last_run_age'] ) ? ' <span class="nflog-muted">' . esc_html(
				/* translators: %s: human readable time difference */
				sprintf( __( 'last run %s ago', 'nf-logbook' ), human_time_diff( time() - (int) $cron['last_run_age'] ) )
			) . '</span>' : '' ),
			__( 'Pending updates', 'nf-logbook' ) => [] === $updates ? '—' : self::pill(
				0 === $pending ? 'ok' : 'warning',
				esc_html(
				/* translators: 1: core updates, 2: plugin updates, 3: theme updates */
					sprintf( __( '%1$s core · %2$s plugins · %3$s themes', 'nf-logbook' ), number_format_i18n( (int) ( $updates['core'] ?? 0 ) ), number_format_i18n( (int) ( $updates['plugins'] ?? 0 ) ), number_format_i18n( (int) ( $updates['themes'] ?? 0 ) ) )
				)
			),
			__( 'Integrity', 'nf-logbook' )       => null === $intOk ? '<span class="nflog-muted">' . esc_html__( 'not checked yet', 'nf-logbook' ) . '</span>' : self::pill( $intOk ? 'ok' : 'error', $intOk ? __( 'core, plugins and chain intact', 'nf-logbook' ) : __( 'mismatch found, see the System log', 'nf-logbook' ) ) . ( is_string( $integrity['ran_at'] ?? null ) ? ' <span class="nflog-muted">' . esc_html( self::agoIso( $integrity['ran_at'] ) ) . '</span>' : '' ),
		];
		self::kv( $rows );
		echo '</section>';
	}

	/**
	 * Scheduled jobs card.
	 */
	private function jobs(): void {
		$runs = Scheduler::nextRuns();
		$late = array_filter( $runs, static fn( ?int $next ): bool => null === $next || $next < time() - HOUR_IN_SECONDS );
		$this->cardOpen( __( 'Scheduled jobs', 'nf-logbook' ), 'clock', [] === $late ? [ 'ok', __( 'Scheduled', 'nf-logbook' ) ] : [ 'warning', __( 'Check cron', 'nf-logbook' ) ] );
		echo '<table class="nflog-table nflog-table--compact"><thead><tr><th scope="col">' . esc_html__( 'Job', 'nf-logbook' ) . '</th><th scope="col">' . esc_html__( 'Every', 'nf-logbook' ) . '</th><th scope="col">' . esc_html__( 'Next run', 'nf-logbook' ) . '</th></tr></thead><tbody>';
		$schedules = function_exists( 'wp_get_schedules' ) ? wp_get_schedules() : [];
		foreach ( $runs as $hook => $next ) {
			$recurrence = Scheduler::JOBS[ $hook ] ?? '';
			$every      = is_array( $schedules[ $recurrence ] ?? null ) && isset( $schedules[ $recurrence ]['display'] ) ? (string) $schedules[ $recurrence ]['display'] : $recurrence;
			$name       = substr( $hook, (int) strrpos( $hook, '/' ) + 1 );
			printf(
				'<tr><td><span class="nflog-job">%1$s</span><code class="nflog-job__hook">%2$s</code></td><td>%3$s</td><td class="nflog-num">%4$s</td></tr>',
				esc_html( Humanizer::humanize( $name ) ),
				esc_html( $hook ),
				esc_html( $every ),
				null === $next ? self::pill( 'warning', __( 'not scheduled', 'nf-logbook' ) ) : '<time datetime="' . esc_attr( gmdate( 'c', $next ) ) . '" title="' . esc_attr( gmdate( 'Y-m-d H:i:s', $next ) . ' UTC' ) . '">' . esc_html( $next > time() ? sprintf( /* translators: %s: human readable time difference */ __( 'in %s', 'nf-logbook' ), human_time_diff( $next ) ) : sprintf( /* translators: %s: human readable time difference */ __( '%s overdue', 'nf-logbook' ), human_time_diff( $next ) ) ) . '</time>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pill() and the time element are escaped.
			);
		}
		echo '</tbody></table></section>';
	}

	/**
	 * Updates card (cached state only: rendering never calls GitHub).
	 *
	 * @param bool $canManage manage_options.
	 */
	private function updates( bool $canManage ): void {
		$client  = $this->updater()->client();
		$summary = Updater::summary( $client->cached() ?? [], NOFORMAT_LOGGER_VERSION, $client->sourceLabel() );
		$state   = 0 === $summary['checked_at'] ? [ 'idle', __( 'Not checked yet', 'nf-logbook' ) ] : ( ! $summary['ok'] ? [ 'error', __( 'Check failed', 'nf-logbook' ) ] : ( $summary['update_available'] ? [ 'warning', __( 'Update available', 'nf-logbook' ) ] : [ 'ok', __( 'Up to date', 'nf-logbook' ) ] ) );
		$this->cardOpen( __( 'Updates', 'nf-logbook' ), 'download', $state );
		$latest = null === $summary['latest'] ? '<span class="nflog-muted">' . esc_html__( 'unknown', 'nf-logbook' ) . '</span>' : esc_html( $summary['latest'] );
		if ( $summary['update_available'] && current_user_can( 'update_plugins' ) ) {
			$latest .= ' · <a href="' . esc_url( admin_url( 'update-core.php' ) ) . '">' . esc_html__( 'Update in Dashboard → Updates', 'nf-logbook' ) . '</a>';
		}
		$rows = [
			__( 'Installed', 'nf-logbook' )  => esc_html( $summary['installed'] ),
			__( 'Latest', 'nf-logbook' )     => $latest,
			__( 'Source', 'nf-logbook' )     => esc_html( $summary['source'] ),
			__( 'Last check', 'nf-logbook' ) => 0 === $summary['checked_at'] ? esc_html__( 'never', 'nf-logbook' ) : self::ago( $summary['checked_at'] ),
			__( 'Result', 'nf-logbook' )     => 0 === $summary['checked_at'] ? '—' : ( $summary['ok'] ? self::pill( 'ok', __( 'ok', 'nf-logbook' ) ) : '<span class="nflog-update-error">' . esc_html( $summary['error'] ) . '</span>' ),
		];
		self::kv( $rows );
		if ( $canManage ) {
			echo '<form method="post" class="nflog-card__actions" action="' . esc_url( self::url() ) . '">';
			wp_nonce_field( Updater::CHECK_NONCE );
			echo '<button type="submit" class="nflog-btn nflog-btn--secondary" name="nflog_update_check" value="1">' . Ui::icon( 'refresh' ) . '<span>' . esc_html__( 'Check now', 'nf-logbook' ) . '</span></button>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG.
			echo '<p class="nflog-help">' . esc_html__( 'Releases are checked every 6 hours; WordPress installs them like any other plugin update.', 'nf-logbook' ) . '</p>';
			echo '</form>';
		}
		echo '</section>';
	}

	/**
	 * Card header with a status pill.
	 *
	 * @param string                      $title Title.
	 * @param string                      $icon  Icon.
	 * @param array{0: string, 1: string} $state [tone, label].
	 */
	private function cardOpen( string $title, string $icon, array $state ): void {
		echo '<section class="nflog-card nflog-status-card"><header class="nflog-card__head"><h2 class="nflog-card__title">' . Ui::icon( $icon ) . esc_html( $title ) . '</h2>' . self::pill( $state[0], $state[1] ) . '</header>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped parts.
	}

	/**
	 * Status pill.
	 *
	 * @param string $tone  ok|warning|error|idle.
	 * @param string $label Label.
	 */
	private static function pill( string $tone, string $label ): string {
		return '<span class="nflog-pill nflog-pill--' . esc_attr( $tone ) . '"><span class="nflog-pill__dot" aria-hidden="true"></span>' . esc_html( $label ) . '</span>';
	}

	/**
	 * Key/value list.
	 *
	 * @param array<string, string> $rows Label => HTML (escaped).
	 */
	private static function kv( array $rows ): void {
		echo '<dl class="nflog-kv">';
		foreach ( $rows as $label => $html ) {
			echo '<div><dt>' . esc_html( $label ) . '</dt><dd>' . $html . '</dd></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		}
		echo '</dl>';
	}

	/**
	 * "5 minutes ago" with the UTC time in the title.
	 *
	 * @param int $time Unix time.
	 */
	private static function ago( int $time ): string {
		return '<time datetime="' . esc_attr( gmdate( 'c', $time ) ) . '" title="' . esc_attr( gmdate( 'Y-m-d H:i:s', $time ) . ' UTC' ) . '">' . esc_html(
			/* translators: %s: human readable time difference */
			sprintf( __( '%s ago', 'nf-logbook' ), human_time_diff( $time ) )
		) . '</time>';
	}

	/**
	 * "checked 3 hours ago" from an ISO date.
	 *
	 * @param string $iso ISO 8601 UTC.
	 */
	private static function agoIso( string $iso ): string {
		$time = strtotime( $iso );
		/* translators: %s: human readable time difference */
		return false === $time ? $iso : sprintf( __( 'checked %s ago', 'nf-logbook' ), human_time_diff( $time ) );
	}

	/**
	 * Integrity summary: true intact, false mismatch, null never ran.
	 *
	 * @param array<mixed> $integrity Last summary.
	 */
	private static function integrityOk( array $integrity ): ?bool {
		if ( [] === $integrity ) {
			return null;
		}
		$core    = is_array( $integrity['core'] ?? null ) ? (string) ( $integrity['core']['status'] ?? 'ok' ) : 'ok';
		$plugins = is_array( $integrity['plugins'] ?? null ) ? (string) ( $integrity['plugins']['status'] ?? 'ok' ) : 'ok';
		$chain   = is_array( $integrity['chain'] ?? null ) ? (bool) ( $integrity['chain']['ok'] ?? true ) : true;
		return in_array( $core, [ 'ok', 'skipped', 'unavailable' ], true ) && in_array( $plugins, [ 'ok', 'skipped', 'unavailable' ], true ) && $chain;
	}
}
