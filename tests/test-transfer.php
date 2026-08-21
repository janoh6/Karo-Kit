<?php
/**
 * Import applies what changed and says so accurately.
 *
 * @package Karo_Kit
 */

class Test_Transfer extends WP_UnitTestCase {

	public function test_rows_already_matching_are_not_rewritten(): void {
		update_option( 'karo_kit_gate_lockout_max', 5 );

		$counts = Karo_Kit_Transfer::apply_plan( array(
			array(
				'option' => 'karo_kit_gate_lockout_max',
				'status' => 'same',
				'value'  => '5',
			),
		) );

		$this->assertSame( 0, $counts['applied'] );
		$this->assertSame( 1, $counts['unchanged'] );
		$this->assertSame( 0, $counts['skipped'] );
	}

	public function test_changed_rows_are_written_and_counted(): void {
		update_option( 'karo_kit_gate_lockout_max', 5 );

		$counts = Karo_Kit_Transfer::apply_plan( array(
			array(
				'option' => 'karo_kit_gate_lockout_max',
				'status' => 'ok',
				'value'  => '9',
			),
		) );

		$this->assertSame( 1, $counts['applied'] );
		$this->assertSame( '9', get_option( 'karo_kit_gate_lockout_max' ) );
	}

	public function test_unresolvable_rows_are_skipped(): void {
		$counts = Karo_Kit_Transfer::apply_plan( array(
			array(
				'option' => 'karo_kit_gate_login_page',
				'status' => 'missing',
				'to'     => 'No page matching "pricing"',
			),
		) );

		$this->assertSame( 0, $counts['applied'] );
		$this->assertSame( 1, $counts['skipped'] );
	}
}
