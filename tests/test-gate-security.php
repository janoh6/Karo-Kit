<?php
/**
 * Rate limiter — window, increment and lockout semantics.
 *
 * @package Karo_Kit
 */

class Test_Gate_Security extends WP_UnitTestCase {

	private const CONTEXT  = 'login';
	private const IDENTITY = 'alice';

	public function set_up(): void {
		parent::set_up();

		Karo_Kit_Gate_Security::install();

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'DELETE FROM ' . Karo_Kit_Gate_Security::table() );

		update_option( 'karo_kit_gate_lockout_max', 5 );
		update_option( 'karo_kit_gate_lockout_window', 15 );
		update_option( 'karo_kit_gate_lockout_cooldown', 60 );

		$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
	}

	public function test_first_failure_counts_as_one(): void {
		$result = Karo_Kit_Gate_Security::register_failure( self::CONTEXT, self::IDENTITY );

		$this->assertSame( 1, $result['attempts'] );
		$this->assertFalse( $result['locked'] );
	}

	public function test_failures_accumulate_within_the_window(): void {
		Karo_Kit_Gate_Security::register_failure( self::CONTEXT, self::IDENTITY );
		Karo_Kit_Gate_Security::register_failure( self::CONTEXT, self::IDENTITY );
		$result = Karo_Kit_Gate_Security::register_failure( self::CONTEXT, self::IDENTITY );

		$this->assertSame( 3, $result['attempts'] );
	}

	public function test_threshold_trips_the_lockout(): void {
		for ( $i = 1; $i < 5; $i++ ) {
			$result = Karo_Kit_Gate_Security::register_failure( self::CONTEXT, self::IDENTITY );
			$this->assertFalse( $result['locked'], "attempt {$i} should not lock" );
		}
		$this->assertFalse( Karo_Kit_Gate_Security::is_locked( self::CONTEXT, self::IDENTITY ) );

		$result = Karo_Kit_Gate_Security::register_failure( self::CONTEXT, self::IDENTITY );

		$this->assertTrue( $result['locked'] );
		$this->assertTrue( Karo_Kit_Gate_Security::is_locked( self::CONTEXT, self::IDENTITY ) );
	}

	public function test_a_stale_window_starts_a_fresh_count(): void {
		global $wpdb;
		$table = Karo_Kit_Gate_Security::table();

		Karo_Kit_Gate_Security::register_failure( self::CONTEXT, self::IDENTITY );
		Karo_Kit_Gate_Security::register_failure( self::CONTEXT, self::IDENTITY );

		// Age every window past the 15-minute setting above.
		$stale = gmdate( 'Y-m-d H:i:s', time() - ( 16 * MINUTE_IN_SECONDS ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET window_start = %s", $stale ) );

		$result = Karo_Kit_Gate_Security::register_failure( self::CONTEXT, self::IDENTITY );

		$this->assertSame( 1, $result['attempts'] );
	}

	public function test_success_clears_the_account_counter(): void {
		Karo_Kit_Gate_Security::register_failure( self::CONTEXT, self::IDENTITY );
		Karo_Kit_Gate_Security::register_failure( self::CONTEXT, self::IDENTITY );

		Karo_Kit_Gate_Security::clear( self::CONTEXT, self::IDENTITY );

		$result = Karo_Kit_Gate_Security::register_failure( self::CONTEXT, self::IDENTITY );
		$this->assertSame( 1, $result['attempts'] );
	}

	public function test_the_ip_wide_counter_tolerates_more_than_one_account(): void {
		// Five failures against one account locks that account but must not
		// lock the address, which is allowed 5 x IP_WIDE_MULTIPLIER.
		for ( $i = 0; $i < 5; $i++ ) {
			Karo_Kit_Gate_Security::register_failure( self::CONTEXT, self::IDENTITY );
		}

		$this->assertTrue( Karo_Kit_Gate_Security::is_locked( self::CONTEXT, self::IDENTITY ) );
		$this->assertFalse( Karo_Kit_Gate_Security::is_locked( self::CONTEXT, 'bob' ) );
	}
}
