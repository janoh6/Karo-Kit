<?php
/**
 * Seeding the Gate toggles with explicit rows.
 *
 * @package Karo_Kit
 */

class Test_Option_Seeding extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( Karo_Kit::SEED_VERSION_OPTION );
		delete_option( 'karo_kit_gate_registration_on' );
	}

	public function test_registration_is_seeded_from_the_core_setting(): void {
		update_option( 'users_can_register', 1 );

		Karo_Kit::maybe_seed_defaults();

		$this->assertSame( '1', get_option( 'karo_kit_gate_registration_on' ) );
	}

	public function test_registration_is_seeded_closed_when_core_is_closed(): void {
		update_option( 'users_can_register', 0 );

		Karo_Kit::maybe_seed_defaults();

		$this->assertSame( '0', get_option( 'karo_kit_gate_registration_on' ) );
	}

	public function test_an_existing_choice_is_never_overwritten(): void {
		update_option( 'karo_kit_gate_registration_on', '0' );
		update_option( 'users_can_register', 1 );

		Karo_Kit::maybe_seed_defaults();

		$this->assertSame( '0', get_option( 'karo_kit_gate_registration_on' ) );
	}

	/**
	 * The bug this whole task exists for: with no row of its own, the Gate
	 * filter read "unset" as "closed" and shut a registration screen the site
	 * had deliberately enabled.
	 */
	public function test_native_registration_is_no_longer_closed_silently(): void {
		update_option( 'users_can_register', 1 );

		Karo_Kit::maybe_seed_defaults();

		$this->assertTrue( (bool) get_option( 'users_can_register' ) );
	}

	public function test_seeding_runs_only_once(): void {
		update_option( 'users_can_register', 1 );
		Karo_Kit::maybe_seed_defaults();

		// A later deliberate change must survive a second seeding pass.
		update_option( 'karo_kit_gate_registration_on', '0' );
		Karo_Kit::maybe_seed_defaults();

		$this->assertSame( '0', get_option( 'karo_kit_gate_registration_on' ) );
	}
}
