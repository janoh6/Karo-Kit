<?php
/**
 * Karo_Kit_Etch::options() declares every Etch option, including the
 * two incidental fixes approved in this plan's Deviation 5.
 *
 * @package Karo_Kit
 */

use KaroKit\Core\Options\Registry;

class Test_Etch_Options extends WP_UnitTestCase {

	private function registry(): Registry {
		$registry = new Registry();
		foreach ( Karo_Kit_Etch::options() as $option ) {
			$registry->add( $option );
		}
		return $registry;
	}

	public function test_declares_all_thirteen_options(): void {
		$this->assertCount( 13, Karo_Kit_Etch::options() );
	}

	public function test_board_on_is_bool_default_true(): void {
		$option = $this->registry()->get( Karo_Kit_Etch_Board::ENABLED_OPT );
		$this->assertSame( 'bool', $option->type );
		$this->assertTrue( $option->default );
	}

	public function test_thumb_auto_limit_is_now_exported_closing_the_pre_existing_gap(): void {
		$option = $this->registry()->get( Karo_Kit_Etch_Board::AUTO_LIMIT_OPT );
		$this->assertTrue( $option->export, 'karo_kit_etch_thumb_auto_limit should now export -- see Deviation 5' );
	}

	public function test_order_status_and_thumbs_are_internal_state_not_settings(): void {
		$this->assertFalse( $this->registry()->get( Karo_Kit_Etch_Board::ORDER_OPT )->setting );
		$this->assertFalse( $this->registry()->get( Karo_Kit_Etch_Board::STATUS_OPT )->setting );
		$this->assertFalse( $this->registry()->get( Karo_Kit_Etch_Board::THUMB_OPT )->setting );
	}

	public function test_order_and_status_export_but_thumbs_does_not(): void {
		$this->assertTrue( $this->registry()->get( Karo_Kit_Etch_Board::ORDER_OPT )->export );
		$this->assertTrue( $this->registry()->get( Karo_Kit_Etch_Board::STATUS_OPT )->export );
		$this->assertFalse( $this->registry()->get( Karo_Kit_Etch_Board::THUMB_OPT )->export );
	}

	public function test_structure_placement_is_enum_default_prepend(): void {
		$option = $this->registry()->get( Karo_Kit_Etch_Structure::PLACEMENT_OPT );
		$this->assertSame( 'enum', $option->type );
		$this->assertSame( 'prepend', $option->default );
		$fn = Registry::sanitizerFor( $option );
		$this->assertSame( 'append', $fn( 'append' ) );
		$this->assertSame( 'prepend', $fn( 'not-a-real-placement' ) );
	}

	public function test_structure_dwell_default_700(): void {
		$this->assertSame( 700, $this->registry()->get( Karo_Kit_Etch_Structure::DWELL_OPT )->default );
	}

	public function test_sidebar_options_default_true(): void {
		$this->assertTrue( $this->registry()->get( Karo_Kit_Etch_Sidebar::ENABLED_OPT )->default );
		$this->assertTrue( $this->registry()->get( Karo_Kit_Etch_Sidebar::REMEMBER_OPT )->default );
	}

	public function test_migrated_flag_is_not_a_setting_or_exported_but_is_uninstalled(): void {
		$option = $this->registry()->get( 'karo_kit_etch_migrated' );
		$this->assertNotNull( $option );
		$this->assertFalse( $option->setting );
		$this->assertFalse( $option->export );
		$this->assertTrue( $option->uninstall );
	}

	public function test_export_map_matches_the_original_ten_plus_the_one_newly_closed_gap(): void {
		$map = $this->registry()->exportMap();
		// Original ten from Karo_Kit_Etch::export_map(), plus thumb_auto_limit newly closed.
		$this->assertArrayHasKey( Karo_Kit_Etch_Board::ENABLED_OPT, $map );
		$this->assertArrayHasKey( Karo_Kit_Etch_Board::THRESHOLD_OPT, $map );
		$this->assertArrayHasKey( Karo_Kit_Etch_Board::ORDER_OPT, $map );
		$this->assertArrayHasKey( Karo_Kit_Etch_Board::STATUS_OPT, $map );
		$this->assertArrayHasKey( Karo_Kit_Etch_Board::AUTO_LIMIT_OPT, $map ); // the newly closed gap
		$this->assertArrayHasKey( Karo_Kit_Etch_Structure::ENABLED_OPT, $map );
		$this->assertArrayHasKey( Karo_Kit_Etch_Structure::DWELL_OPT, $map );
		$this->assertArrayHasKey( Karo_Kit_Etch_Structure::PLACEMENT_OPT, $map );
		$this->assertArrayHasKey( Karo_Kit_Etch_Structure::DISABLED_OPT, $map );
		$this->assertArrayHasKey( Karo_Kit_Etch_Sidebar::ENABLED_OPT, $map );
		$this->assertArrayHasKey( Karo_Kit_Etch_Sidebar::REMEMBER_OPT, $map );
		$this->assertArrayNotHasKey( Karo_Kit_Etch_Board::THUMB_OPT, $map ); // deliberately excluded, unchanged
		$this->assertCount( 11, $map );
	}
}
