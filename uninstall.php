<?php
/**
 * Uninstall — remove Karo Kit data when the plugin is deleted.
 *
 * @package Karo_Kit
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$karo_kit_options = array(
	'karo_kit_version',
	// Gate — pages
	'karo_kit_gate_login_page',
	'karo_kit_gate_register_page',
	'karo_kit_gate_account_page',
	'karo_kit_gate_lostpw_page',
	// Gate — maintenance
	'karo_kit_gate_maintenance_page',
	'karo_kit_gate_maintenance_on',
	'karo_kit_gate_maintenance_mode',
	// Gate — security
	'karo_kit_gate_registration_on',
	'karo_kit_gate_lockout_max',
	'karo_kit_gate_lockout_window',
	'karo_kit_gate_lockout_cooldown',
	'karo_kit_gate_hide_login',
	'karo_kit_gate_login_slug',
	'karo_kit_gate_denied_page',
);

foreach ( $karo_kit_options as $karo_kit_option ) {
	delete_option( $karo_kit_option );
}
