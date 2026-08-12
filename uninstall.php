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
	'karo_kit_accent_source',
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
	// Activity log
	'karo_kit_log',
	'karo_kit_log_db_version',
	'karo_kit_gate_throttle_db_version',
	// Etch — template board
	'karo_kit_etch_order',
	'karo_kit_etch_thumbs',
	'karo_kit_etch_status',
	'karo_kit_etch_board_on',
	'karo_kit_etch_thumb_threshold',
	'karo_kit_etch_structure_on',
	'karo_kit_etch_structure_dwell',
	'karo_kit_etch_structure_placement',
	'karo_kit_etch_structure_show_disabled',
	'karo_kit_etch_sidebar_on',
	'karo_kit_etch_sidebar_remember',
	'karo_kit_etch_migrated',
	// Import review stash is a transient, cleaned up separately below.
);

foreach ( $karo_kit_options as $karo_kit_option ) {
	delete_option( $karo_kit_option );
}

// The activity log has its own table.
global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'karo_kit_log' );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'karo_kit_throttle' );

wp_clear_scheduled_hook( 'karo_kit_log_trim' );
wp_clear_scheduled_hook( 'karo_kit_daily' );

// Derived cache, rebuilt on demand — no data of its own to lose.
delete_transient( 'karo_kit_etch_component_index' );

// Per-user leftovers: the import review stash and the dashboard theme choice.
foreach ( get_users( array( 'fields' => 'ID' ) ) as $karo_kit_user ) {
	delete_transient( 'karo_kit_import_' . $karo_kit_user );
	delete_user_meta( $karo_kit_user, 'karo_kit_theme' );
}

/*
 * Generated template thumbnails. Left in place if the standalone Etch Template
 * Board plugin is still installed — the directory is shared with it, and
 * deleting it here would take that plugin's images too.
 */
if ( ! defined( 'ETB_VERSION' ) ) {
	$karo_kit_updir = wp_upload_dir();
	$karo_kit_thumbs = trailingslashit( $karo_kit_updir['basedir'] ) . 'etb-thumbnails';
	if ( is_dir( $karo_kit_thumbs ) ) {
		foreach ( (array) glob( $karo_kit_thumbs . '/*' ) as $karo_kit_file ) {
			if ( is_file( $karo_kit_file ) ) {
				wp_delete_file( $karo_kit_file );
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.directory_rmdir
		@rmdir( $karo_kit_thumbs );
	}
}
