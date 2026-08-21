<?php
/**
 * SystemClock: the real, wall-clock implementation of Clock. Nothing to
 * verify beyond "it returns the current time" -- the value of the interface
 * is in what it lets a FUTURE test replace it with, not in this class's own
 * behaviour.
 *
 * @package Karo_Kit
 */

use KaroKit\Core\Clock\Clock;
use KaroKit\Core\Clock\SystemClock;

class Test_Clock extends WP_UnitTestCase {

	public function test_system_clock_implements_the_interface(): void {
		$this->assertInstanceOf( Clock::class, new SystemClock() );
	}

	public function test_system_clock_returns_the_current_time(): void {
		$before = time();
		$now    = ( new SystemClock() )->now();
		$after  = time();

		$this->assertGreaterThanOrEqual( $before, $now );
		$this->assertLessThanOrEqual( $after, $now );
	}
}
