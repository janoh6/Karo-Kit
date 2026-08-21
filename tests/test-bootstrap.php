<?php
/**
 * Proves the harness boots WordPress with this plugin loaded.
 *
 * @package Karo_Kit
 */

class Test_Bootstrap extends WP_UnitTestCase {

	public function test_plugin_constants_are_defined(): void {
		$this->assertTrue( defined( 'KARO_KIT_VER' ) );
	}

	public function test_plugin_classes_are_loaded(): void {
		$this->assertTrue( class_exists( 'Karo_Kit' ) );
		$this->assertTrue( class_exists( 'Karo_Kit_Gate_Security' ) );
		$this->assertTrue( class_exists( 'Karo_Kit_Etch_Board' ) );
	}
}
