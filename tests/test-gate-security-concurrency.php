<?php
/**
 * Proves the rate-limiter increment survives genuine contention.
 *
 * @group concurrency
 * @package Karo_Kit
 */

use PHPUnit\Framework\TestCase;

/**
 * Deliberately NOT a WP_UnitTestCase. That base class wraps every test in a
 * database transaction and rolls it back afterwards, so the forked children
 * below — which hold their own connections — would neither see this test's
 * setup nor have their writes seen by it. A plain TestCase commits, which is
 * what makes the contention observable.
 */
class Test_Gate_Security_Concurrency extends TestCase {

	private const WORKERS  = 10;
	private const CONTEXT  = 'login';
	private const IDENTITY = 'alice';

	protected function setUp(): void {
		parent::setUp();

		$_SERVER['REMOTE_ADDR'] = '203.0.113.20';

		Karo_Kit_Gate_Security::install();
		$this->truncate();

		/*
		 * Far above the number of workers. A lockout zeroes the counter, which
		 * would mask the very undercount this test looks for.
		 */
		update_option( 'karo_kit_gate_lockout_max', 1000 );
		update_option( 'karo_kit_gate_lockout_window', 60 );
	}

	protected function tearDown(): void {
		$this->truncate();
		parent::tearDown();
	}

	private function truncate(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'DELETE FROM ' . Karo_Kit_Gate_Security::table() );
	}

	public function test_parallel_failures_are_every_one_counted(): void {
		if ( ! function_exists( 'pcntl_fork' ) ) {
			$this->markTestSkipped( 'ext-pcntl is required to create genuine parallel writers.' );
		}

		// A shared wall-clock start, so the children contend rather than
		// spreading out over their own startup times.
		$start = microtime( true ) + 0.5;
		$pids  = array();

		for ( $i = 0; $i < self::WORKERS; $i++ ) {
			$pid = pcntl_fork();
			$this->assertNotSame( -1, $pid, 'Could not fork a worker.' );

			if ( 0 === $pid ) {
				// Child. A MySQL socket cannot be shared across processes, so
				// take a connection of our own before touching the database.
				$GLOBALS['wpdb']->db_connect();

				$wait = (int) round( ( $start - microtime( true ) ) * 1000000 );
				if ( $wait > 0 ) {
					usleep( $wait );
				}

				Karo_Kit_Gate_Security::register_failure( self::CONTEXT, self::IDENTITY );
				exit( 0 );
			}

			$pids[] = $pid;
		}

		foreach ( $pids as $pid ) {
			pcntl_waitpid( $pid, $status );
			$this->assertSame( 0, pcntl_wexitstatus( $status ), 'A worker exited non-zero.' );
		}

		// The children replaced their inherited copy of the connection; make
		// sure this process is holding a live one of its own.
		$GLOBALS['wpdb']->db_connect();

		// This process's own attempt reports the running total.
		$result = Karo_Kit_Gate_Security::register_failure( self::CONTEXT, self::IDENTITY );

		$this->assertSame(
			self::WORKERS + 1,
			$result['attempts'],
			'Lost updates under contention — the increment is not atomic.'
		);
	}
}
