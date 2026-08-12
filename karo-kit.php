<?php
/**
 * Plugin Name:       Karo Kit
 * Plugin URI:        https://karo.ioannesa.com/
 * Description:       Modular site-utility kit for Etch / ACSS WordPress builds. Gate: front-end login & registration plus a maintenance / coming-soon gate, wired to pages you pick from dropdowns. Etch: a Template Board replacing the native Templates screen, plus the dynamic-data and shortcode reference.
 * Version:           0.16.4
 * Author:            Karo
 * License:           GPL-2.0-or-later
 * Text Domain:       karo-kit
 * Domain Path:       /languages
 * Requires at least: 6.8
 * Requires PHP:      8.4
 *
 * @package Karo_Kit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * Environment guard. WordPress blocks activation below the header minimums,
 * but this also bails gracefully (with a notice) if the plugin is loaded on an
 * unsupported environment some other way, instead of risking a fatal.
 */
$karo_kit_php_min = '8.4';
$karo_kit_wp_min  = '6.8';
if ( version_compare( PHP_VERSION, $karo_kit_php_min, '<' )
	|| version_compare( get_bloginfo( 'version' ), $karo_kit_wp_min, '<' ) ) {
	add_action( 'admin_notices', static function () use ( $karo_kit_php_min, $karo_kit_wp_min ) {
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html( sprintf(
				/* translators: 1: required PHP version, 2: required WordPress version */
				'Karo Kit requires PHP %1$s+ and WordPress %2$s+, so it was not loaded.',
				$karo_kit_php_min,
				$karo_kit_wp_min
			) )
		);
	} );
	return;
}

define( 'KARO_KIT_VER', '0.16.4' );
define( 'KARO_KIT_FILE', __FILE__ );
define( 'KARO_KIT_DIR', plugin_dir_path( __FILE__ ) );
define( 'KARO_KIT_URL', plugin_dir_url( __FILE__ ) );

/* ---- Core ---------------------------------------------------------------- */
require_once KARO_KIT_DIR . 'includes/class-karo-kit-module.php';
require_once KARO_KIT_DIR . 'includes/class-karo-kit-log.php';
require_once KARO_KIT_DIR . 'includes/class-karo-kit-accent.php';
require_once KARO_KIT_DIR . 'includes/class-karo-kit-transfer.php';
require_once KARO_KIT_DIR . 'includes/class-karo-kit-updates.php';
require_once KARO_KIT_DIR . 'includes/class-karo-kit.php';

// Outside any hook, per the update checker's own guidance — see the class.
Karo_Kit_Updates::init();

/* ---- Module: Gate -------------------------------------------------------- */
require_once KARO_KIT_DIR . 'modules/gate/enum-karo-kit-gate-mode.php';
require_once KARO_KIT_DIR . 'modules/gate/class-karo-kit-gate.php';
require_once KARO_KIT_DIR . 'modules/gate/class-karo-kit-gate-settings.php';
require_once KARO_KIT_DIR . 'modules/gate/class-karo-kit-gate-security.php';
require_once KARO_KIT_DIR . 'modules/gate/class-karo-kit-gate-login-url.php';
require_once KARO_KIT_DIR . 'modules/gate/class-karo-kit-gate-auth.php';
require_once KARO_KIT_DIR . 'modules/gate/class-karo-kit-gate-maintenance.php';
require_once KARO_KIT_DIR . 'modules/gate/class-karo-kit-gate-shortcodes.php';
require_once KARO_KIT_DIR . 'modules/gate/class-karo-kit-gate-etch.php';

/* ---- Module: Etch -------------------------------------------------------- */
require_once KARO_KIT_DIR . 'modules/etch/class-karo-kit-etch-board.php';
require_once KARO_KIT_DIR . 'modules/etch/class-karo-kit-etch-structure.php';
require_once KARO_KIT_DIR . 'modules/etch/class-karo-kit-etch-sidebar.php';
require_once KARO_KIT_DIR . 'modules/etch/class-karo-kit-etch-settings.php';
require_once KARO_KIT_DIR . 'modules/etch/class-karo-kit-etch.php';

// Register modules with the core. Future modules add one line here.
Karo_Kit::register( 'Karo_Kit_Gate' );
Karo_Kit::register( 'Karo_Kit_Etch' );

// Boot once all plugins are loaded.
add_action( 'plugins_loaded', static function () {
	load_plugin_textdomain( 'karo-kit', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	Karo_Kit::boot();
} );

register_activation_hook( __FILE__, array( 'Karo_Kit', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Karo_Kit', 'deactivate' ) );
