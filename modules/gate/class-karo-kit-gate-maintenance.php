<?php
/**
 * Gate module — maintenance / coming-soon gate.
 *
 * @package Karo_Kit\Gate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Karo_Kit_Gate_Maintenance {

	public static function init(): void {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_gate' ) );
	}

	public static function maybe_gate(): void {
		if ( ! get_option( 'karo_kit_gate_maintenance_on' ) ) {
			return;
		}
		if ( Karo_Kit_Gate::can_bypass() ) {
			return;
		}

		$page_id = (int) get_option( 'karo_kit_gate_maintenance_page' );
		if ( ! $page_id ) {
			return; // no page chosen -> fail open, never white-screen the site.
		}

		// Pages that must stay reachable during maintenance so visitors can still
		// authenticate: the maintenance page itself (avoids a render loop) plus
		// the login, registration and lost-password pages. Filterable per site.
		$exempt = array_filter( array(
			$page_id,
			(int) get_option( 'karo_kit_gate_login_page' ),
			(int) get_option( 'karo_kit_gate_register_page' ),
			(int) get_option( 'karo_kit_gate_lostpw_page' ),
		) );
		/** Filter the page IDs that bypass the maintenance gate. */
		$exempt = array_map( 'intval', (array) apply_filters( 'karo_kit_gate_exempt_ids', $exempt ) );

		// Exempt pages, feeds/robots, and REST are left alone.
		if ( in_array( get_queried_object_id(), $exempt, true )
			|| is_feed() || is_robots() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		$mode = Karo_Kit_Gate_Mode::from_option( get_option( 'karo_kit_gate_maintenance_mode', 'maintenance' ) );

		// Swap the MAIN query to the maintenance page so WordPress renders it
		// through its normal template pipeline (theme + ACSS + Etch styles load
		// exactly as on a direct visit). page_id for pages, p+post_type for CPTs.
		$args = ( 'page' === get_post_type( $page_id ) )
			? array( 'page_id' => $page_id )
			: array( 'p' => $page_id, 'post_type' => get_post_type( $page_id ) );

		global $wp_query, $wp_the_query;
		$wp_query     = new WP_Query( $args );
		$wp_the_query = $wp_query;

		if ( Karo_Kit_Gate_Mode::ComingSoon === $mode ) {
			status_header( 200 );
		} else {
			status_header( 503 );
			header( 'Retry-After: 3600' );
			header( 'X-Robots-Tag: noindex' );
			nocache_headers();
		}

		// Do NOT exit / consume the loop: returning lets the template loader
		// pick and render the page template normally.
	}
}
