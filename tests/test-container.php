<?php
/**
 * Container holds Module instances; StaticModuleAdapter makes an existing
 * static module class satisfy the Module interface without changing it.
 *
 * @package Karo_Kit
 */

use KaroKit\Core\Module\Container;
use KaroKit\Core\Module\Module;
use KaroKit\Core\Module\StaticModuleAdapter;

/** A minimal stand-in for a static module class, mirroring Karo_Kit_Gate's shape. */
class Test_Fixture_Static_Module {
	public static bool $booted = false;

	public static function id(): string {
		return 'fixture';
	}
	public static function label(): string {
		return 'Fixture';
	}
	public static function option_group(): string {
		return 'karo_kit_fixture';
	}
	public static function options(): array {
		return array();
	}
	public static function init(): void {
		self::$booted = true;
	}
	public static function render_page(): void {
		echo 'fixture page';
	}
	public static function dashboard_groups(): array {
		return array( 'fixture-group' );
	}
	public static function nav_sections(): array {
		return array( 'fixture-section' => 'Fixture Section' );
	}
}

class Test_Container extends WP_UnitTestCase {

	public function test_static_module_adapter_implements_module(): void {
		$adapter = new StaticModuleAdapter( Test_Fixture_Static_Module::class );
		$this->assertInstanceOf( Module::class, $adapter );
	}

	public function test_adapter_forwards_id_and_label(): void {
		$adapter = new StaticModuleAdapter( Test_Fixture_Static_Module::class );
		$this->assertSame( 'fixture', $adapter->id() );
		$this->assertSame( 'Fixture', $adapter->label() );
	}

	public function test_adapter_forwards_option_group(): void {
		$adapter = new StaticModuleAdapter( Test_Fixture_Static_Module::class );
		$this->assertSame( 'karo_kit_fixture', $adapter->optionGroup() );
	}

	public function test_adapter_forwards_options(): void {
		$adapter = new StaticModuleAdapter( Test_Fixture_Static_Module::class );
		$this->assertSame( array(), $adapter->options() );
	}

	public function test_adapter_boot_forwards_to_the_static_classes_init(): void {
		Test_Fixture_Static_Module::$booted = false;
		$adapter                            = new StaticModuleAdapter( Test_Fixture_Static_Module::class );
		$adapter->boot();
		$this->assertTrue( Test_Fixture_Static_Module::$booted );
	}

	public function test_adapter_render_page_forwards_and_echoes(): void {
		$adapter = new StaticModuleAdapter( Test_Fixture_Static_Module::class );
		$this->expectOutputString( 'fixture page' );
		$adapter->renderPage();
	}

	public function test_adapter_forwards_dashboard_groups_and_nav_sections(): void {
		$adapter = new StaticModuleAdapter( Test_Fixture_Static_Module::class );
		$this->assertSame( array( 'fixture-group' ), $adapter->dashboardGroups() );
		$this->assertSame( array( 'fixture-section' => 'Fixture Section' ), $adapter->navSections() );
	}

	public function test_container_registers_and_lists_modules(): void {
		$container = new Container();
		$adapter   = new StaticModuleAdapter( Test_Fixture_Static_Module::class );
		$container->register( $adapter );

		$this->assertSame( array( $adapter ), $container->modules() );
	}

	public function test_container_get_finds_a_module_by_id(): void {
		$container = new Container();
		$adapter   = new StaticModuleAdapter( Test_Fixture_Static_Module::class );
		$container->register( $adapter );

		$this->assertSame( $adapter, $container->get( 'fixture' ) );
	}

	public function test_container_get_returns_null_for_an_unknown_id(): void {
		$container = new Container();
		$this->assertNull( $container->get( 'does-not-exist' ) );
	}
}
