<?php
/**
 * Uninstall — remove Karo Kit data when the plugin is deleted.
 *
 * @package Karo_Kit
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/includes/class-karo-kit-module.php';
require_once __DIR__ . '/includes/class-karo-kit.php';
require_once __DIR__ . '/includes/class-karo-kit-accent.php';
require_once __DIR__ . '/modules/gate/enum-karo-kit-gate-mode.php';
require_once __DIR__ . '/modules/gate/class-karo-kit-gate.php';
require_once __DIR__ . '/modules/gate/class-karo-kit-gate-security.php';
require_once __DIR__ . '/modules/etch/class-karo-kit-etch-board.php';
require_once __DIR__ . '/modules/etch/class-karo-kit-etch-structure.php';
require_once __DIR__ . '/modules/etch/class-karo-kit-etch-sidebar.php';
require_once __DIR__ . '/modules/etch/class-karo-kit-etch.php';

// Instantiate cheaply, without booting hooks, purely to collect options().
Karo_Kit::register( new KaroKit\Core\Module\StaticModuleAdapter( 'Karo_Kit_Gate' ) );
Karo_Kit::register( new KaroKit\Core\Module\StaticModuleAdapter( 'Karo_Kit_Etch' ) );

foreach ( Karo_Kit::registry()->uninstallNames() as $karo_kit_option ) {
	delete_option( $karo_kit_option );
}

// karo_kit_seed_version predates the Registry (v0.16.9's now-deleted bespoke
// seeding pass) and was never declared as an Option -- delete it directly so
// a site that ran v0.16.9 doesn't keep this one orphaned row forever.
delete_option( 'karo_kit_seed_version' );

// karo_kit_log (Karo_Kit_Log::LEGACY_OPTION) is a one-time, pre-1.0
// migration artifact that Karo_Kit_Log::migrate_legacy() explicitly
// deletes once and is meant to stay gone -- unlike the other lifecycle
// flags, it must never be declared as a seedable Option, or
// Registry::seedDefaults() would resurrect an empty, autoloaded row on
// every already-migrated site forever. Delete it directly instead.
delete_option( Karo_Kit_Log::LEGACY_OPTION );

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
