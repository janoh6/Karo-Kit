<?php
/**
 * Gate module — Etch dynamic data integration.
 *
 * Surfaces auth/session values to Etch's builder so they can be bound as
 * dynamic data on elements, e.g. {user.logoutUrl} and {user.isLoggedIn}.
 *
 * The filter only fires when Etch is active, so this is inert on non-Etch sites.
 *
 * @package Karo_Kit\Gate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Karo_Kit_Gate_Etch {

	public static function init(): void {
		add_filter( 'etch/dynamic_data/user', array( __CLASS__, 'user_data' ), 10, 2 );
	}

	/**
	 * Add auth/session values to Etch's user dynamic data.
	 *
	 * @param mixed $data    Existing user data (array; guarded just in case).
	 * @param int   $user_id Current user ID (0 when logged out).
	 * @return array
	 */
	public static function user_data( $data, $user_id ): array {
		$data = is_array( $data ) ? $data : array();

		$logged_in = is_user_logged_in();

		$data['isLoggedIn'] = $logged_in;
		$data['logoutUrl']  = wp_logout_url( home_url() );
		$data['loginUrl']   = Karo_Kit_Gate::url( 'login' );
		$data['accountUrl'] = Karo_Kit_Gate::url( 'account' );

		$user                = $logged_in ? wp_get_current_user() : null;
		$data['displayName'] = $user ? $user->display_name : '';
		$data['avatarUrl']   = $user ? (string) get_avatar_url( $user->ID ) : '';

		return $data;
	}
}
