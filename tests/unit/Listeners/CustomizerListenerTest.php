<?php
/**
 * CustomizerListener tests.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Tests\Listeners;

use Noformat\Logger\Listeners\CustomizerListener;

final class CustomizerListenerTest extends ListenerTestCase {

	public function test_handles(): void {
		self::assertTrue( CustomizerListener::handles( 'blogname' ) );
		self::assertTrue( CustomizerListener::handles( 'header_textcolor' ) );
		self::assertFalse( CustomizerListener::handles( 'nav_menu_locations[primary]' ) );
		self::assertFalse( CustomizerListener::handles( 'nav_menu_item[12]' ) );
		self::assertFalse( CustomizerListener::handles( 'sidebars_widgets[sidebar-1]' ) );
		self::assertFalse( CustomizerListener::handles( 'widget_text[2]' ) );
	}

	public function test_save_flow_one_event_per_changed_setting(): void {
		$values  = [ 'blogdescription' => 'Old', 'header_textcolor' => '000' ];
		$setting = static fn( string $id ) => new class( $id, $values ) {
			/**
			 * @param array<string, string> $values
			 */
			public function __construct( public string $id, private array &$values ) {
			}
			public function value(): mixed {
				return $this->values[ $this->id ];
			}
		};
		$manager = new class( $values ) {
			/**
			 * @param array<string, string> $values
			 */
			public function __construct( public array &$values ) {
			}
			/**
			 * @return array<string, mixed>
			 */
			public function unsanitized_post_values( array $args = [] ): array {
				unset( $args );
				return [ 'blogdescription' => 'x', 'header_textcolor' => 'y', 'nav_menu_locations[primary]' => 1 ];
			}
			public function get_setting( string $id ): object {
				$values = &$this->values;
				return new class( $id, $values ) {
					/**
					 * @param array<string, string> $values
					 */
					public function __construct( public string $id, private array &$values ) {
					}
					public function value(): mixed {
						return $this->values[ $this->id ] ?? null;
					}
				};
			}
			public function changeset_uuid(): string {
				return 'uuid-1';
			}
		};
		unset( $setting );
		$listener = ( new CustomizerListener() )->bind( $this->logger, $this->config );
		$listener->beforeSave( $manager );
		$manager->values['blogdescription'] = 'New tagline';
		$listener->afterSave( $manager );
		self::assertSame( [ 'customizer.saved' ], $this->actions() );
		self::assertSame( 'blogdescription', $this->events[0]->getObject()['id'] ?? null );
		self::assertSame( [ [ 'field' => 'blogdescription', 'old' => 'Old', 'new' => 'New tagline' ] ], $this->events[0]->getChanges() );
		self::assertSame( 'uuid-1', $this->events[0]->getContext()['changeset_uuid'] );
	}

	public function test_custom_css_summarised(): void {
		$listener = ( new CustomizerListener() )->bind( $this->logger, $this->config );
		$listener->emitSetting( 'custom_css[twentytwentyfour]', 'custom', 'a{}', 'body{color:red}' );
		$listener->emitSetting( 'blogname', 'option', 'Same', 'Same' );
		self::assertSame( [ 'customizer.saved' ], $this->actions() );
		self::assertSame( 15, $this->events[0]->getChanges()[0]['new']['length'] );
	}
}
