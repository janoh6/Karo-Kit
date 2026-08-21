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

	/**
	 * The exact bug this seeding logic exists to fix: Karo_Kit_Gate_Auth's
	 * own filter on option_users_can_register returns false whenever
	 * karo_kit_gate_registration_on is unset -- which is precisely the
	 * state seeding runs in. Reading through that filter would make the
	 * seed always observe false and invert the fix. This confirms the seed
	 * bypasses it and reads WordPress core's actual, unfiltered value.
	 */
	public function test_seeding_reads_the_unfiltered_core_value(): void {
		update_option( 'users_can_register', 1 );

		// Reproduce exactly the condition the real filter creates: it's
		// registered (as it always is via Karo_Kit_Gate_Auth::init()) and
		// karo_kit_gate_registration_on is unset -- the filter will return
		// false for any read that doesn't bypass it.
		$this->assertFalse( get_option( 'users_can_register' ), 'Precondition: the real Gate filter should already be making this read false.' );

		Karo_Kit::maybe_seed_defaults();

		$this->assertSame( '1', get_option( 'karo_kit_gate_registration_on' ), 'Seeding must bypass the filter and read the true core value.' );
	}
}
