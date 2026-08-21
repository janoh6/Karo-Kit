<?php
/**
 * Karo_Kit::options() declares every kit-level option -- those belonging to
 * no module.
 *
 * @package Karo_Kit
 */

class Test_Kit_Options extends WP_UnitTestCase {

	private function names(): array {
		return array_map( static fn( $o ) => $o->name, Karo_Kit::options() );
	}

	public function test_declares_accent_source(): void {
		$this->assertContains( 'karo_kit_accent_source', $this->names() );
	}

	public function test_declares_accent_custom(): void {
		$this->assertContains( 'karo_kit_accent_custom', $this->names() );
	}

	public function test_declares_version(): void {
		$this->assertContains( 'karo_kit_version', $this->names() );
	}

	public function test_accent_source_is_exported_with_a_label(): void {
		$registry = new \KaroKit\Core\Options\Registry();
		foreach ( Karo_Kit::options() as $option ) {
			$registry->add( $option );
		}
		$this->assertArrayHasKey( 'karo_kit_accent_source', $registry->exportMap() );
		$this->assertArrayHasKey( 'karo_kit_accent_source', $registry->exportLabels() );
	}

	public function test_accent_custom_is_not_exported_but_is_deleted_on_uninstall(): void {
		$registry = new \KaroKit\Core\Options\Registry();
		foreach ( Karo_Kit::options() as $option ) {
			$registry->add( $option );
		}
		$this->assertArrayNotHasKey( 'karo_kit_accent_custom', $registry->exportMap() );
		$this->assertContains( 'karo_kit_accent_custom', $registry->uninstallNames() );
	}

	public function test_version_is_neither_a_setting_nor_exported(): void {
		$registry = new \KaroKit\Core\Options\Registry();
		foreach ( Karo_Kit::options() as $option ) {
			$registry->add( $option );
		}
		$this->assertNotContains( 'karo_kit_version', $registry->settingNames() );
		$this->assertArrayNotHasKey( 'karo_kit_version', $registry->exportMap() );
		$this->assertContains( 'karo_kit_version', $registry->uninstallNames() );
	}

	public function test_accent_source_sanitizer_matches_the_original_behaviour(): void {
		$option    = null;
		foreach ( Karo_Kit::options() as $o ) {
			if ( 'karo_kit_accent_source' === $o->name ) {
				$option = $o;
			}
		}
		$this->assertNotNull( $option );
		$fn = \KaroKit\Core\Options\Registry::sanitizerFor( $option );

		$this->assertSame( 'primary', $fn( 'primary' ) );
		$this->assertSame( 'custom', $fn( 'custom' ) );
		$this->assertSame( '', $fn( 'not-a-real-family' ) );
	}

	public function test_accent_custom_sanitizer_matches_the_original_normalise_hex(): void {
		$option    = null;
		foreach ( Karo_Kit::options() as $o ) {
			if ( 'karo_kit_accent_custom' === $o->name ) {
				$option = $o;
			}
		}
		$this->assertNotNull( $option );
		$fn = \KaroKit\Core\Options\Registry::sanitizerFor( $option );

		$this->assertSame( '#ff0000', $fn( '#F00' ) );
		$this->assertSame( '', $fn( 'garbage' ) );
	}
}
