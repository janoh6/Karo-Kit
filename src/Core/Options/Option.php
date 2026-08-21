<?php
/**
 * One option's declaration: name, type, default, and where it should appear
 * (a registered setting, an export field, an uninstall target) -- collected
 * by Registry into every view the kit used to maintain by hand in four
 * separate places.
 *
 * @package Karo_Kit
 */

namespace KaroKit\Core\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Option {

	/**
	 * @param string    $name            The option's row name in wp_options. Never renamed once shipped.
	 * @param string    $type            One of: bool, int, string, key, enum, page, hex, array.
	 * @param mixed     $default         Static default, used at seed time and as register_setting()'s default.
	 * @param ?\Closure $defaultCallback Computed default, used instead of $default at seed time when present.
	 *                                   Reserved for options whose correct value depends on other site state
	 *                                   at seed time (see karo_kit_gate_registration_on) -- every other option
	 *                                   should use a plain $default.
	 * @param ?string   $label           Human label, shown in the export/import preview.
	 * @param bool      $setting         Registered via register_setting() and AJAX-writable.
	 * @param bool      $export          Travels with an export.
	 * @param bool      $uninstall       Deleted when the plugin is uninstalled.
	 * @param bool      $autoload        Whether add_option() autoloads this row.
	 * @param ?array    $enum            Allowed values, for type 'enum'.
	 * @param ?int      $min             Lower bound, for type 'int'.
	 * @param ?int      $max             Upper bound, for type 'int'.
	 */
	public function __construct(
		public readonly string    $name,
		public readonly string    $type,
		public readonly mixed     $default = null,
		public readonly ?\Closure $defaultCallback = null,
		public readonly ?string   $label = null,
		public readonly bool      $setting = true,
		public readonly bool      $export = false,
		public readonly bool      $uninstall = true,
		public readonly bool      $autoload = true,
		public readonly ?array    $enum = null,
		public readonly ?int      $min = null,
		public readonly ?int      $max = null,
	) {}
}
