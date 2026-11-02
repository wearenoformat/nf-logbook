<?php
/**
 * WidgetListener.
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
use Noformat\Logger\Privacy\Redactor;
use Noformat\Logger\Severity;

defined( 'ABSPATH' ) || exit;

/**
 * Widgets: sidebars_widgets and widget_* options (PLAN §4).
 *
 * OptionListener skips `sidebars_widgets` and `widget_*`, so these options are
 * logged once, here. Free text (`text`, `content`, block widget markup) is a
 * content summary.
 */
final class WidgetListener implements Listener {

	use Hooks;

	/**
	 * Instance keys holding free text.
	 *
	 * @var list<string>
	 */
	public const TEXT_KEYS = [ 'text', 'content' ];

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
		$this->onAction( 'updated_option', 'optionUpdated', 10, 3 );
		$this->onAction( 'added_option', fn( $option, $value ) => $this->optionUpdated( $option, [], $value ), 10, 2 );
	}

	/**
	 * Route sidebars_widgets / widget_* writes.
	 *
	 * @param mixed $option Option name.
	 * @param mixed $oldValue    Old value.
	 * @param mixed $newValue    New value.
	 */
	public function optionUpdated( mixed $option, mixed $oldValue, mixed $newValue ): void {
		if ( ! is_string( $option ) || ( null !== $this->config && $this->config->isIgnoredOption( $option ) ) ) {
			return;
		}
		if ( 'sidebars_widgets' === $option ) {
			$this->sidebarsChanged( $oldValue, $newValue );
		} elseif ( str_starts_with( $option, 'widget_' ) && strlen( $option ) > 7 ) {
			$this->widgetsChanged( substr( $option, 7 ), $oldValue, $newValue );
		}
	}

	/**
	 * One sidebar.updated per sidebar whose widget list changed.
	 *
	 * @param mixed $oldValue Old sidebars_widgets.
	 * @param mixed $newValue New sidebars_widgets.
	 */
	public function sidebarsChanged( mixed $oldValue, mixed $newValue ): void {
		foreach ( self::sidebarDiff( $oldValue, $newValue ) as $sidebar => $diff ) {
			$this->emit(
				Event::make( Category::THEME, 'sidebar.updated', Severity::NOTICE )
					->object( 'sidebar', $sidebar )
					->summary( sprintf( 'Sidebar %s updated: +%d −%d%s', $sidebar, count( $diff['added'] ), count( $diff['removed'] ), $diff['reordered'] ? ', reordered' : '' ) )
					->change( 'widgets', $diff['old'], $diff['new'] )
					->context(
						[
							'added'     => $diff['added'],
							'removed'   => $diff['removed'],
							'reordered' => $diff['reordered'],
						]
					)
			);
		}
	}

	/**
	 * Per-sidebar widget id changes.
	 *
	 * @param mixed $oldValue Old sidebars_widgets.
	 * @param mixed $newValue New sidebars_widgets.
	 * @return array<string, array{added: list<string>, removed: list<string>, reordered: bool, old: list<string>, new: list<string>}>
	 */
	public static function sidebarDiff( mixed $oldValue, mixed $newValue ): array {
		$oldValue = Redactor::maybeUnserialize( $oldValue );
		$newValue = Redactor::maybeUnserialize( $newValue );
		$oldValue = is_array( $oldValue ) ? $oldValue : [];
		$newValue = is_array( $newValue ) ? $newValue : [];
		unset( $oldValue['array_version'], $newValue['array_version'] );
		$out = [];
		foreach ( array_unique( array_merge( array_keys( $oldValue ), array_keys( $newValue ) ) ) as $sidebar ) {
			$a = is_array( $oldValue[ $sidebar ] ?? null ) ? array_values( array_map( 'strval', $oldValue[ $sidebar ] ) ) : [];
			$b = is_array( $newValue[ $sidebar ] ?? null ) ? array_values( array_map( 'strval', $newValue[ $sidebar ] ) ) : [];
			if ( $a === $b ) {
				continue;
			}
			$added                    = array_values( array_diff( $b, $a ) );
			$removed                  = array_values( array_diff( $a, $b ) );
			$common                   = array_values( array_intersect( $a, $b ) );
			$commonB                  = array_values( array_intersect( $b, $a ) );
			$out[ (string) $sidebar ] = [
				'added'     => $added,
				'removed'   => $removed,
				'reordered' => $common !== $commonB,
				'old'       => $a,
				'new'       => $b,
			];
		}
		return $out;
	}

	/**
	 * One widget.updated per changed instance of a widget type.
	 *
	 * @param string $idBase Widget id base (e.g. "block", "text").
	 * @param mixed  $oldValue    Old option value.
	 * @param mixed  $newValue    New option value.
	 */
	public function widgetsChanged( string $idBase, mixed $oldValue, mixed $newValue ): void {
		if ( null === $this->logger ) {
			return;
		}
		$oldValue = Redactor::maybeUnserialize( $oldValue );
		$newValue = Redactor::maybeUnserialize( $newValue );
		$oldValue = is_array( $oldValue ) ? $oldValue : [];
		$newValue = is_array( $newValue ) ? $newValue : [];
		unset( $oldValue['_multiwidget'], $newValue['_multiwidget'] );
		foreach ( array_unique( array_merge( array_keys( $oldValue ), array_keys( $newValue ) ) ) as $number ) {
			$a = $oldValue[ $number ] ?? null;
			$b = $newValue[ $number ] ?? null;
			if ( $a === $b ) {
				continue;
			}
			$changes = $this->instanceDiff( is_array( $a ) ? $a : null, is_array( $b ) ? $b : null );
			if ( [] === $changes ) {
				continue;
			}
			$widgetId  = $idBase . '-' . $number;
			$operation = null === $a ? 'added' : ( null === $b ? 'removed' : 'updated' );
			$this->emit(
				Event::make( Category::THEME, 'widget.updated', Severity::NOTICE )
					->object( 'widget', $widgetId, $idBase )
					->summary( sprintf( 'Widget %s %s', $widgetId, $operation ) )
					->changes( $changes )
					->context( [ 'operation' => $operation ] )
			);
		}
	}

	/**
	 * Diff of one widget instance; free-text keys become content summaries.
	 *
	 * @param array<string|int, mixed>|null $oldValue Old instance.
	 * @param array<string|int, mixed>|null $newValue New instance.
	 * @return list<array{field: string, old: mixed, new: mixed}>
	 */
	public function instanceDiff( ?array $oldValue, ?array $newValue ): array {
		if ( null === $this->logger ) {
			return [];
		}
		$changes = [];
		foreach ( self::TEXT_KEYS as $key ) {
			$a = $oldValue[ $key ] ?? null;
			$b = $newValue[ $key ] ?? null;
			if ( null === $a && null === $b ) {
				continue;
			}
			if ( $a !== $b ) {
				$changes[] = [
					'field' => $key,
					'old'   => null === $a ? null : $this->contentSummary( $a ),
					'new'   => null === $b ? null : $this->contentSummary( $b, $a ),
				];
			}
			unset( $oldValue[ $key ], $newValue[ $key ] );
		}
		if ( null === $oldValue && null === $newValue ) {
			return $changes;
		}
		return array_merge( $changes, $this->logger->redactor()->diff( $oldValue ?? [], $newValue ?? [] ) );
	}
}
