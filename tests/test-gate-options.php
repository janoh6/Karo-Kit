<?php
/**
 * Karo_Kit_Gate::options() declares every Gate option, matching the current
 * register_setting() calls' types, defaults and sanitizers exactly.
 *
 * @package Karo_Kit
 */

use KaroKit\Core\Options\Registry;

class Test_Gate_Options extends WP_UnitTestCase {

	private function registry(): Registry {
		$registry = new Registry();
		foreach ( Karo_Kit_Gate::options() as $option ) {
			$registry->add( $option );
		}
		return $registry;
	}

	public function test_declares_all_fifteen_options(): void {
		$this->assertCount( 15, Karo_Kit_Gate::options() );
	}

	/** @dataProvider page_options */
	public function test_page_selectors_are_type_page_default_zero_exported_and_uninstalled( string $name ): void {
		$option = $this->registry()->get( $name );
		$this->assertNotNull( $option, "missing declaration for {$name}" );
		$this->assertSame( 'page', $option->type );
		$this->assertSame( 0, $option->default );
		$this->assertTrue( $option->export );
		$this->assertTrue( $option->uninstall );
	}

	public static function page_options(): array {
		return array(
			array( 'karo_kit_gate_login_page' ),
			array( 'karo_kit_gate_register_page' ),
			array( 'karo_kit_gate_account_page' ),
			array( 'karo_kit_gate_lostpw_page' ),
			array( 'karo_kit_gate_maintenance_page' ),
			array( 'karo_kit_gate_denied_page' ),
		);
	}

	public function test_maintenance_on_is_bool_default_false(): void {
		$option = $this->registry()->get( 'karo_kit_gate_maintenance_on' );
		$this->assertSame( 'bool', $option->type );
		$this->assertFalse( $option->default );
	}

	public function test_maintenance_mode_is_enum_default_maintenance(): void {
		$option = $this->registry()->get( 'karo_kit_gate_maintenance_mode' );
		$this->assertSame( 'enum', $option->type );
		$this->assertSame( 'maintenance', $option->default );
		$fn = Registry::sanitizerFor( $option );
		$this->assertSame( 'comingsoon', $fn( 'comingsoon' ) );
		$this->assertSame( 'maintenance', $fn( 'not-a-real-mode' ) );
	}

	public function test_lockout_options_are_int_with_the_original_defaults(): void {
		$this->assertSame( 5, $this->registry()->get( 'karo_kit_gate_lockout_max' )->default );
		$this->assertSame( 15, $this->registry()->get( 'karo_kit_gate_lockout_window' )->default );
		$this->assertSame( 60, $this->registry()->get( 'karo_kit_gate_lockout_cooldown' )->default );
	}

	public function test_hide_login_is_bool_default_false(): void {
		$option = $this->registry()->get( 'karo_kit_gate_hide_login' );
		$this->assertSame( 'bool', $option->type );
		$this->assertFalse( $option->default );
	}

	public function test_login_slug_is_slug_type_not_exported_but_is_uninstalled(): void {
		$option = $this->registry()->get( 'karo_kit_gate_login_slug' );
		// 'slug' -- not 'key' -- restores the reserved-word blocklist that
		// plain sanitize_key() dropped (see the final-review fix wave).
		$this->assertSame( 'slug', $option->type );
		$this->assertFalse( $option->export );
		$this->assertTrue( $option->uninstall );

		$fn = Registry::sanitizerFor( $option );
		// sanitize_title() (via sanitize_slug()), not sanitize_key() -- hyphenates
		// spaces instead of stripping them.
		$this->assertSame( 'my-secret', $fn( 'My Secret!' ) );

		// The whole point of the 'slug' type over plain 'key': reserved WP
		// paths are rejected, not handed out as the hide-login bypass secret.
		$this->assertSame( '', $fn( 'wp-admin' ) );
	}

	public function test_registration_on_has_a_default_callback_not_a_static_default(): void {
		$option = $this->registry()->get( 'karo_kit_gate_registration_on' );
		$this->assertNotNull( $option->defaultCallback );
	}

	public function test_registration_on_default_callback_reads_the_unfiltered_core_value(): void {
		update_option( 'users_can_register', 1 );
		$option = $this->registry()->get( 'karo_kit_gate_registration_on' );

		// Reproduce the exact condition the real filter creates: it's
		// always registered by Karo_Kit_Gate_Auth::init(), and the option
		// this callback is computing is, by definition, unset right now.
		$this->assertFalse( get_option( 'users_can_register' ), 'Precondition: Gate\'s own filter should be making this read false.' );

		$value = ( $option->defaultCallback )();
		$this->assertSame( '1', $value );
	}

	public function test_registration_default_callback_returns_closed_when_core_is_closed(): void {
		update_option( 'users_can_register', 0 );
		$option = $this->registry()->get( 'karo_kit_gate_registration_on' );

		$value = ( $option->defaultCallback )();
		$this->assertSame( '0', $value );
	}

	public function test_registration_default_callback_never_writes_back_to_the_core_setting(): void {
		update_option( 'users_can_register', 1 );
		$option = $this->registry()->get( 'karo_kit_gate_registration_on' );

		( $option->defaultCallback )();

		// karo_kit_gate_registration_on is unset in this test, which makes
		// Karo_Kit_Gate_Auth::filter_users_can_register() force every read of
		// users_can_register to false regardless of the row's real value --
		// the same confound the callback itself works around. Remove that
		// filter here too, so this assertion is actually reading the raw
		// stored row rather than the always-false filtered view of it.
		remove_filter( 'option_users_can_register', array( 'Karo_Kit_Gate_Auth', 'filter_users_can_register' ) );
		$this->assertTrue( (bool) get_option( 'users_can_register' ), 'The seed callback must only READ users_can_register, never write to it.' );
		add_filter( 'option_users_can_register', array( 'Karo_Kit_Gate_Auth', 'filter_users_can_register' ) );
	}

	public function test_throttle_db_version_is_not_a_setting_or_exported_but_is_uninstalled(): void {
		$option = $this->registry()->get( 'karo_kit_gate_throttle_db_version' );
		$this->assertNotNull( $option );
		$this->assertFalse( $option->setting );
		$this->assertFalse( $option->export );
		$this->assertTrue( $option->uninstall );
	}

	public function test_every_page_and_bool_and_int_option_is_also_a_setting(): void {
		$names = $this->registry()->settingNames();
		foreach ( self::page_options() as [ $name ] ) {
			$this->assertContains( $name, $names );
		}
		$this->assertContains( 'karo_kit_gate_maintenance_on', $names );
		$this->assertContains( 'karo_kit_gate_hide_login', $names );
	}
}
