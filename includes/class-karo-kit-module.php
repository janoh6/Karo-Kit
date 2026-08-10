<?php
/**
 * Base class every Karo Kit module extends.
 *
 * @package Karo_Kit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class Karo_Kit_Module {

	/** Machine id, e.g. 'gate'. */
	abstract public static function id(): string;

	/** Human label shown on the settings tab. */
	abstract public static function label(): string;

	/** Wire all of this module's hooks. Called on plugins_loaded. */
	abstract public static function init(): void;

	/** Settings page slug for this module's tab, e.g. 'karo-kit-gate'. */
	public static function settings_slug(): string {
		return 'karo-kit-' . static::id();
	}

	/** Settings option group, e.g. 'karo_kit_gate'. */
	public static function option_group(): string {
		return 'karo_kit_' . static::id();
	}

	/** Render this module's page — override for multi-tab layouts. Default: single-tab settings form. */
	public static function render_page(): void {
		echo '<div class="kk-card"><form action="options.php" method="post">';
		static::render_settings();
		submit_button();
		echo '</form></div>';
	}

	/** Render this module's settings (the body of its tab). */
	public static function render_settings(): void {
		settings_fields( static::option_group() );
		do_settings_sections( static::settings_slug() );
	}

	/**
	 * Dashboard cards for this module — one per functional area, each pairing
	 * that area's live status with the pages it governs, and linking to the
	 * tab that configures it. Optional; return empty to stay off the dashboard.
	 *
	 * @return array<int,array{
	 *     label:string,
	 *     section:string,
	 *     status:array<int,array{label:string,value:string,on:bool}>,
	 *     pages:array<int,array{label:string,option:string}>
	 * }>
	 */
	public static function dashboard_groups(): array {
		return array();
	}

	/**
	 * Optional: id => label pairs for sections that should appear as their own
	 * top-level tabs (e.g. Site Access / Maintenance / Etch) instead of
	 * being grouped behind one tab bearing this module's label(). Leave empty
	 * for a module simple enough to need only its own single tab.
	 *
	 * @return array<string,string>
	 */
	public static function nav_sections(): array {
		return array();
	}
}
