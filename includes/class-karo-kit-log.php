<?php
/**
 * Karo Kit — activity log.
 *
 * A capped ring buffer in a single option. Deliberately not a custom table:
 * the volume here is security/config events (not traffic), the log is
 * write-rarely / read-rarely, and an option needs no schema migration. If a
 * module ever needs high-frequency logging, that module should own its own
 * storage rather than widening this.
 *
 * @package Karo_Kit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Karo_Kit_Log {

	const OPTION = 'karo_kit_log';

	/** Hard cap on retained entries; oldest are dropped first. */
	const MAX = 200;

	/** How many entries the dashboard's "Recent activity" card shows. */
	const RECENT = 5;

	/**
	 * Record one event.
	 *
	 * @param string $type    Machine key, e.g. 'login_failed', 'lockout'.
	 * @param string $message Human-readable, already translated.
	 * @param array  $context Optional extras; 'ip' is added automatically.
	 */
	public static function add( string $type, string $message, array $context = array() ): void {
		$entry = array(
			'time'    => time(),
			'type'    => $type,
			'message' => $message,
			'ip'      => $context['ip'] ?? self::ip(),
		);

		$log = self::all();
		array_unshift( $log, $entry );
		if ( count( $log ) > self::MAX ) {
			$log = array_slice( $log, 0, self::MAX );
		}

		// autoload=false: the log is only read on our own admin screens.
		update_option( self::OPTION, $log, false );
	}

	/** @return array<int,array{time:int,type:string,message:string,ip:string}> newest first */
	public static function all(): array {
		$log = get_option( self::OPTION, array() );
		return is_array( $log ) ? $log : array();
	}

	/** @return array<int,array{time:int,type:string,message:string,ip:string}> */
	public static function recent( int $limit = self::RECENT ): array {
		return array_slice( self::all(), 0, $limit );
	}

	public static function clear(): void {
		delete_option( self::OPTION );
	}

	/** Visitor IP, reusing Gate's filterable resolver when that module is loaded. */
	private static function ip(): string {
		if ( class_exists( 'Karo_Kit_Gate_Security' ) ) {
			return Karo_Kit_Gate_Security::client_ip();
		}
		return isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
	}

	/** "3 minutes ago" for recent entries, absolute date once it's stale. */
	public static function when( int $time ): string {
		$diff = time() - $time;
		if ( $diff < DAY_IN_SECONDS ) {
			/* translators: %s: human-readable time difference, e.g. "3 mins" */
			return sprintf( __( '%s ago', 'karo-kit' ), human_time_diff( $time ) );
		}
		return wp_date( (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ), $time );
	}
}
