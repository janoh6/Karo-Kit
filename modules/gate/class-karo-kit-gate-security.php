<?php
/**
 * Gate module — security helpers (rate limiting / lockout).
 *
 * @package Karo_Kit\Gate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Karo_Kit_Gate_Security {

	/**
	 * Resolve the visitor's IP. Only REMOTE_ADDR is trusted by default —
	 * forwarded headers are spoofable. Sites behind a proxy/Cloudflare map the
	 * real header via the filter, e.g.
	 *   add_filter( 'karo_kit_gate_client_ip', fn() => $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '' );
	 */
	public static function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? trim( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return (string) apply_filters( 'karo_kit_gate_client_ip', $ip );
	}

	private static function key( string $prefix, string $context ): string {
		return 'kk_' . $prefix . '_' . sanitize_key( $context ) . '_' . md5( self::client_ip() );
	}

	/** Is this IP currently locked out for the given context? */
	public static function is_locked( string $context ): bool {
		return (bool) get_transient( self::key( 'lock', $context ) );
	}

	/** Record one failed attempt; trip a lockout once the threshold is hit. */
	public static function register_failure( string $context ): void {
		$max      = max( 1, (int) get_option( 'karo_kit_gate_lockout_max', 5 ) );
		$window   = max( 1, (int) get_option( 'karo_kit_gate_lockout_window', 15 ) );
		$cooldown = max( 1, (int) get_option( 'karo_kit_gate_lockout_cooldown', 60 ) );

		$att_key = self::key( 'att', $context );
		$count   = (int) get_transient( $att_key ) + 1;
		set_transient( $att_key, $count, $window * MINUTE_IN_SECONDS );

		if ( $count >= $max ) {
			set_transient( self::key( 'lock', $context ), 1, $cooldown * MINUTE_IN_SECONDS );
			delete_transient( $att_key );

			Karo_Kit_Log::add(
				'lockout',
				sprintf(
					/* translators: 1: context (login/register), 2: cooldown in minutes */
					__( 'Lockout — %1$s (%2$d min)', 'karo-kit' ),
					$context,
					$cooldown
				)
			);
			return;
		}

		Karo_Kit_Log::add(
			'failed_' . $context,
			sprintf(
				/* translators: 1: context (login/register), 2: attempt count, 3: threshold */
				__( 'Failed %1$s (%2$d/%3$d)', 'karo-kit' ),
				$context,
				$count,
				$max
			)
		);
	}

	/** Clear counters/lock for a context (call on success). */
	public static function clear( string $context ): void {
		delete_transient( self::key( 'att', $context ) );
		delete_transient( self::key( 'lock', $context ) );
	}
}
