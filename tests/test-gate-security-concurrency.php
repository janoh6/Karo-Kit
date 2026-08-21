<?php
/**
 * @group concurrency
 *
 * Proves the rate-limiter increment survives genuine contention.
 *
 * Deliberately NOT a WP_UnitTestCase. That base class wraps every test in a
 * database transaction and rolls it back afterwards, so the forked children
 * below — which hold their own connections — would neither see this test's
 * setup nor have their writes seen by it. A plain TestCase commits, which is
 * what makes the contention observable.
 *
 * TEMPORARY DIAGNOSTIC BUILD — not for merge. Emits evidence to STDERR to
 * root-cause a CI failure (see debug/concurrency-diagnostics branch). Every
 * line tagged with fwrite( STDERR, ... ) is instrumentation only and will be
 * removed once the root cause is confirmed.
 *
 * @package Karo_Kit
 */

use PHPUnit\Framework\TestCase;

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

	/** DIAGNOSTIC: one line of evidence, tagged and flushed immediately. */
	private static function diag( string $label, $value ): void {
		fwrite( STDERR, sprintf( "[DIAG pid=%d] %s = %s\n", getmypid(), $label, var_export( $value, true ) ) );
	}

	public function test_parallel_failures_are_every_one_counted(): void {
		if ( ! function_exists( 'pcntl_fork' ) ) {
			$this->markTestSkipped( 'ext-pcntl is required to create genuine parallel writers.' );
		}

		global $wpdb;
		self::diag( 'parent pre-fork thread_id', mysqli_thread_id( $wpdb->dbh ) );
		self::diag( 'parent pre-fork autocommit', $wpdb->get_var( 'SELECT @@autocommit' ) );

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
				self::diag( 'child pre-reconnect thread_id (inherited)', mysqli_thread_id( $GLOBALS['wpdb']->dbh ) );

				$GLOBALS['wpdb']->db_connect();

				self::diag( 'child post-reconnect thread_id', mysqli_thread_id( $GLOBALS['wpdb']->dbh ) );
				self::diag( 'child post-reconnect autocommit', $GLOBALS['wpdb']->get_var( 'SELECT @@autocommit' ) );
				self::diag( 'child post-reconnect last_error', $GLOBALS['wpdb']->last_error );

				$wait = (int) round( ( $start - microtime( true ) ) * 1000000 );
				if ( $wait > 0 ) {
					usleep( $wait );
				}

				$result = Karo_Kit_Gate_Security::register_failure( self::CONTEXT, self::IDENTITY );
				self::diag( 'child register_failure result', $result );
				self::diag( 'child last_error after register_failure', $GLOBALS['wpdb']->last_error );

				// DIAGNOSTIC ONLY: raw row read, same connection, right before exit.
				$row = $GLOBALS['wpdb']->get_row(
					$GLOBALS['wpdb']->prepare(
						'SELECT attempts FROM ' . Karo_Kit_Gate_Security::table() . ' WHERE context = %s',
						'login'
					)
				);
				self::diag( 'child raw row read pre-exit', $row );

				exit( 0 );
			}

			$pids[] = $pid;
		}

		foreach ( $pids as $pid ) {
			pcntl_waitpid( $pid, $status );
			$this->assertSame( 0, pcntl_wexitstatus( $status ), 'A worker exited non-zero.' );
		}

		// DIAGNOSTIC ONLY: what does the PARENT see on its OLD (pre-fork,
		// inherited-by-children) connection right now, before reconnecting?
		self::diag( 'parent thread_id before its own reconnect', mysqli_thread_id( $wpdb->dbh ) );
		$row_before_reconnect = $wpdb->get_row(
			$wpdb->prepare( 'SELECT attempts FROM ' . Karo_Kit_Gate_Security::table() . ' WHERE context = %s', 'login' )
		);
		self::diag( 'parent raw row read on OLD connection', $row_before_reconnect );

		// The children replaced their inherited copy of the connection; make
		// sure this process is holding a live one of its own.
		$GLOBALS['wpdb']->db_connect();

		self::diag( 'parent thread_id after reconnect', mysqli_thread_id( $wpdb->dbh ) );
		$row_after_reconnect = $wpdb->get_row(
			$wpdb->prepare( 'SELECT attempts FROM ' . Karo_Kit_Gate_Security::table() . ' WHERE context = %s', 'login' )
		);
		self::diag( 'parent raw row read on NEW connection', $row_after_reconnect );

		// This process's own attempt reports the running total.
		$result = Karo_Kit_Gate_Security::register_failure( self::CONTEXT, self::IDENTITY );
		self::diag( 'parent final register_failure result', $result );

		$this->assertSame(
			self::WORKERS + 1,
			$result['attempts'],
			'Lost updates under contention — the increment is not atomic.'
		);
	}
}
