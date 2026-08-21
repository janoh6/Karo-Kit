<?php
/**
 * Karo_Kit::register()/modules()/registry() operate on Module instances,
 * not class-strings.
 *
 * @package Karo_Kit
 */

use KaroKit\Core\Module\StaticModuleAdapter;
use KaroKit\Core\Options\Option;
use KaroKit\Core\Options\Registry;

class Test_Fixture_Boot_Module {
	public static function id(): string {
		return 'boot-fixture';
	}
	public static function label(): string {
		return 'Boot Fixture';
	}
	public static function option_group(): string {
		return 'karo_kit_boot_fixture';
	}
	public static function options(): array {
		return array( new Option( 'karo_kit_boot_fixture_flag', 'bool', default: false ) );
	}
	public static function init(): void {}
	public static function render_page(): void {}
	public static function dashboard_groups(): array {
		return array();
	}
	public static function nav_sections(): array {
		return array();
	}
}

class Test_Karo_Kit_Boot extends WP_UnitTestCase {

	public function test_register_accepts_a_module_instance_and_modules_returns_it_keyed_by_id(): void {
		$reflection = new ReflectionClass( Karo_Kit::class );
		$modules    = $reflection->getProperty( 'modules' );
		$modules->setAccessible( true );
		$original = $modules->getValue();

		$adapter = new StaticModuleAdapter( Test_Fixture_Boot_Module::class );
		Karo_Kit::register( $adapter );

		$this->assertArrayHasKey( 'boot-fixture', Karo_Kit::modules() );
		$this->assertSame( $adapter, Karo_Kit::modules()['boot-fixture'] );

		$modules->setValue( null, $original ); // restore, since $modules is a static property shared across tests
	}

	public function test_registry_merges_every_registered_modules_options_with_the_kits_own(): void {
		$reflection = new ReflectionClass( Karo_Kit::class );
		$modules    = $reflection->getProperty( 'modules' );
		$modules->setAccessible( true );
		$original = $modules->getValue();
		$modules->setValue( null, array() );

		Karo_Kit::register( new StaticModuleAdapter( Test_Fixture_Boot_Module::class ) );
		$registry = Karo_Kit::registry();

		$this->assertInstanceOf( Registry::class, $registry );
		$this->assertTrue( $registry->has( 'karo_kit_boot_fixture_flag' ) );

		$modules->setValue( null, $original );
	}
}
