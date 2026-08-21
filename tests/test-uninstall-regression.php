<?php
/**
 * The Registry's merged uninstall list must be a superset of every option
 * uninstall.php's OLD hardcoded array deleted, plus exactly the one
 * approved addition (karo_kit_accent_custom -- Deviation 5). Never a
 * subset: losing an entry means uninstall silently stops cleaning up data
 * it used to.
 *
 * @package Karo_Kit
 */

class Test_Uninstall_Regression extends WP_UnitTestCase {

	/** The exact 33 entries uninstall.php's hardcoded array held before this refactor. */
	private function original_list(): array {
		return array(
			'karo_kit_version',
			'karo_kit_seed_version', // deleted as a constant in Task 7; still expected in the merged uninstall list below via a dedicated assertion, since a site that ran v0.16.9 has a real row to clean up
			'karo_kit_accent_source',
			'karo_kit_gate_login_page',
			'karo_kit_gate_register_page',
			'karo_kit_gate_account_page',
			'karo_kit_gate_lostpw_page',
			'karo_kit_gate_maintenance_page',
			'karo_kit_gate_maintenance_on',
			'karo_kit_gate_maintenance_mode',
			'karo_kit_gate_registration_on',
			'karo_kit_gate_lockout_max',
			'karo_kit_gate_lockout_window',
			'karo_kit_gate_lockout_cooldown',
			'karo_kit_gate_hide_login',
			'karo_kit_gate_login_slug',
			'karo_kit_gate_denied_page',
			'karo_kit_log',
			'karo_kit_log_db_version',
			'karo_kit_gate_throttle_db_version',
			'karo_kit_etch_order',
			'karo_kit_etch_thumbs',
			'karo_kit_etch_status',
			'karo_kit_etch_board_on',
			'karo_kit_etch_thumb_threshold',
			'karo_kit_etch_thumb_auto_limit',
			'karo_kit_etch_structure_on',
			'karo_kit_etch_structure_dwell',
			'karo_kit_etch_structure_placement',
			'karo_kit_etch_structure_show_disabled',
			'karo_kit_etch_sidebar_on',
			'karo_kit_etch_sidebar_remember',
			'karo_kit_etch_migrated',
		);
	}

	public function test_registry_uninstall_names_cover_every_option_the_old_list_deleted(): void {
		$names = Karo_Kit::registry()->uninstallNames();
		foreach ( $this->original_list() as $expected ) {
			if ( 'karo_kit_seed_version' === $expected ) {
				continue; // asserted separately: it's in uninstall.php's static list directly, not the Registry, since Task 7 deleted the constant it used to be declared from
			}
			$this->assertContains( $expected, $names, "{$expected} is missing from the Registry's uninstall list -- this is a regression, not an approved change" );
		}
	}

	public function test_registry_uninstall_names_also_include_the_one_approved_addition(): void {
		$this->assertContains( 'karo_kit_accent_custom', Karo_Kit::registry()->uninstallNames(), 'karo_kit_accent_custom should now be deleted on uninstall -- see Deviation 5' );
	}

	public function test_export_map_still_excludes_login_slug_and_thumbs(): void {
		$map = Karo_Kit::registry()->exportMap();
		$this->assertArrayNotHasKey( 'karo_kit_gate_login_slug', $map );
		$this->assertArrayNotHasKey( Karo_Kit_Etch_Board::THUMB_OPT, $map );
	}

	public function test_transfer_payload_uses_the_registry_not_class_string_iteration(): void {
		$payload = Karo_Kit_Transfer::payload();
		$this->assertArrayHasKey( 'karo_kit_gate_login_page', $payload['modules']['gate'] );
		$this->assertSame( 'page', $payload['modules']['gate']['karo_kit_gate_login_page']['kind'] );
	}
}
