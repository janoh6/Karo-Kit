<?php
/**
 * Gate module — site access control.
 *
 * @package Karo_Kit\Gate
 */

use KaroKit\Core\Options\Option;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Karo_Kit_Gate extends Karo_Kit_Module {

	/**
	 * Login-flow page selectors (rendered under "Gate — Pages").
	 * The maintenance page lives with the maintenance controls in its own section.
	 */
	const PAGE_OPTIONS = array(
		'login_page'    => 'Login page',
		'register_page' => 'Registration page',
		'account_page'  => 'Account page (redirect after login)',
		'lostpw_page'   => 'Lost-password page',
	);

	public static function id(): string {
		return 'gate';
	}

	public static function label(): string {
		return __( 'Gate', 'karo-kit' );
	}

	public static function init(): void {
		Karo_Kit_Gate_Security::init();
		Karo_Kit_Gate_Settings::init();
		Karo_Kit_Gate_Auth::init();
		Karo_Kit_Gate_Maintenance::init();
		Karo_Kit_Gate_Shortcodes::init();
		Karo_Kit_Gate_Etch::init();
		Karo_Kit_Gate_Login_Url::init();
	}

	public static function render_page(): void {
		Karo_Kit_Gate_Settings::render_page();
	}

	/** @return \KaroKit\Core\Options\Option[] */
	public static function options(): array {
		$pages = array();
		foreach ( array_keys( self::PAGE_OPTIONS ) as $key ) {
			$pages[] = new Option(
				name: "karo_kit_gate_{$key}",
				type: 'page',
				default: 0,
				label: self::PAGE_OPTIONS[ $key ],
				export: true,
			);
		}

		return array_merge( $pages, array(
			new Option(
				name: 'karo_kit_gate_maintenance_page',
				type: 'page',
				default: 0,
				label: __( 'Maintenance page', 'karo-kit' ),
				export: true,
			),
			new Option(
				name: 'karo_kit_gate_maintenance_on',
				type: 'bool',
				default: false,
				label: __( 'Maintenance gate', 'karo-kit' ),
				export: true,
			),
			new Option(
				name: 'karo_kit_gate_maintenance_mode',
				type: 'enum',
				default: 'maintenance',
				label: __( 'Maintenance mode', 'karo-kit' ),
				export: true,
				enum: array_map( static fn( $case ) => $case->value, Karo_Kit_Gate_Mode::cases() ),
			),
			new Option(
				name: 'karo_kit_gate_registration_on',
				type: 'bool',
				default: false,
				defaultCallback: static function () {
					// Karo_Kit_Gate_Auth::filter_users_can_register() returns
					// false whenever this option is unset -- exactly the
					// condition seeding runs under. Reading through that
					// filter would make this read always observe the very
					// bug it exists to fix. Read core's own unfiltered value.
					$had_filter = remove_filter( 'option_users_can_register', array( 'Karo_Kit_Gate_Auth', 'filter_users_can_register' ) );
					$core_value = get_option( 'users_can_register' );
					if ( $had_filter ) {
						add_filter( 'option_users_can_register', array( 'Karo_Kit_Gate_Auth', 'filter_users_can_register' ) );
					}
					return $core_value ? '1' : '0';
				},
				label: __( 'Registration', 'karo-kit' ),
				export: true,
			),
			new Option(
				name: 'karo_kit_gate_lockout_max',
				type: 'int',
				default: 5,
				label: __( 'Max attempts', 'karo-kit' ),
				export: true,
			),
			new Option(
				name: 'karo_kit_gate_lockout_window',
				type: 'int',
				default: 15,
				label: __( 'Attempt window', 'karo-kit' ),
				export: true,
			),
			new Option(
				name: 'karo_kit_gate_lockout_cooldown',
				type: 'int',
				default: 60,
				label: __( 'Lockout length', 'karo-kit' ),
				export: true,
			),
			new Option(
				name: 'karo_kit_gate_hide_login',
				type: 'bool',
				default: false,
				label: __( 'Hide login URL', 'karo-kit' ),
				export: true,
			),
			new Option(
				name: 'karo_kit_gate_login_slug',
				type: 'slug',
				default: '',
				// Deliberately not exported: this is the secret that hides
				// the login URL, and an export file is something people
				// email around.
			),
			new Option(
				name: 'karo_kit_gate_denied_page',
				type: 'page',
				default: 0,
				label: __( 'Blocked page', 'karo-kit' ),
				export: true,
			),
			new Option(
				name: Karo_Kit_Gate_Security::DB_VERSION_OPTION,
				type: 'int',
				default: 0,
				setting: false,
			),
		) );
	}

	/** Site Access / Maintenance / Etch appear as their own top-level tabs. */
	public static function nav_sections(): array {
		return Karo_Kit_Gate_Settings::TABS;
	}

	/**
	 * @inheritDoc
	 *
	 * karo_kit_gate_login_slug is deliberately absent: it is the secret that
	 * hides the login URL, and an export file is something people email around.
	 */
	public static function export_map(): array {
		return array(
			'karo_kit_gate_login_page'        => 'page',
			'karo_kit_gate_register_page'     => 'page',
			'karo_kit_gate_account_page'      => 'page',
			'karo_kit_gate_lostpw_page'       => 'page',
			'karo_kit_gate_maintenance_page'  => 'page',
			'karo_kit_gate_denied_page'       => 'page',
			'karo_kit_gate_maintenance_on'    => 'value',
			'karo_kit_gate_maintenance_mode'  => 'value',
			'karo_kit_gate_registration_on'   => 'value',
			'karo_kit_gate_hide_login'        => 'value',
			'karo_kit_gate_lockout_max'       => 'value',
			'karo_kit_gate_lockout_window'    => 'value',
			'karo_kit_gate_lockout_cooldown'  => 'value',
		);
	}

	/** @inheritDoc */
	public static function export_labels(): array {
		return array(
			'karo_kit_gate_login_page'        => __( 'Login page', 'karo-kit' ),
			'karo_kit_gate_register_page'     => __( 'Registration page', 'karo-kit' ),
			'karo_kit_gate_account_page'      => __( 'Account page', 'karo-kit' ),
			'karo_kit_gate_lostpw_page'       => __( 'Lost-password page', 'karo-kit' ),
			'karo_kit_gate_maintenance_page'  => __( 'Maintenance page', 'karo-kit' ),
			'karo_kit_gate_denied_page'       => __( 'Blocked page', 'karo-kit' ),
			'karo_kit_gate_maintenance_on'    => __( 'Maintenance gate', 'karo-kit' ),
			'karo_kit_gate_maintenance_mode'  => __( 'Maintenance mode', 'karo-kit' ),
			'karo_kit_gate_registration_on'   => __( 'Registration', 'karo-kit' ),
			'karo_kit_gate_hide_login'        => __( 'Hide login URL', 'karo-kit' ),
			'karo_kit_gate_lockout_max'       => __( 'Max attempts', 'karo-kit' ),
			'karo_kit_gate_lockout_window'    => __( 'Attempt window', 'karo-kit' ),
			'karo_kit_gate_lockout_cooldown'  => __( 'Lockout length', 'karo-kit' ),
		);
	}

	/** @inheritDoc */
	public static function dashboard_groups(): array {
		$maint_on   = (int) get_option( 'karo_kit_gate_maintenance_on' );
		$maint_mode = Karo_Kit_Gate_Mode::from_option( get_option( 'karo_kit_gate_maintenance_mode', 'maintenance' ) );
		$reg_on     = (int) get_option( 'karo_kit_gate_registration_on' );
		$hide_on    = (int) get_option( 'karo_kit_gate_hide_login' );

		$access_pages = array();
		foreach ( self::PAGE_OPTIONS as $key => $label ) {
			$access_pages[] = array( 'label' => $label, 'option' => "karo_kit_gate_{$key}" );
		}
		$access_pages[] = array( 'label' => __( 'Blocked page', 'karo-kit' ), 'option' => 'karo_kit_gate_denied_page' );

		return array(
			array(
				'label'   => __( 'Site Access', 'karo-kit' ),
				'section' => 'site-access',
				'status'  => array(
					array(
						'label' => __( 'Registration', 'karo-kit' ),
						'value' => $reg_on ? __( 'Open', 'karo-kit' ) : __( 'Closed', 'karo-kit' ),
						'on'    => (bool) $reg_on,
					),
					array(
						'label' => __( 'Hidden login', 'karo-kit' ),
						'value' => $hide_on ? __( 'Enabled', 'karo-kit' ) : __( 'Disabled', 'karo-kit' ),
						'on'    => (bool) $hide_on,
					),
				),
				'pages'   => $access_pages,
			),
			array(
				'label'   => __( 'Maintenance', 'karo-kit' ),
				'section' => 'maintenance',
				'status'  => array(
					array(
						'label' => __( 'Gate', 'karo-kit' ),
						'value' => $maint_on ? $maint_mode->label() : __( 'Off', 'karo-kit' ),
						'on'    => (bool) $maint_on,
					),
				),
				'pages'   => array(
					array( 'label' => __( 'Maintenance page', 'karo-kit' ), 'option' => 'karo_kit_gate_maintenance_page' ),
				),
			),
		);
	}

	/* ---- Shared helpers -------------------------------------------------- */

	/** Resolve an option page ID to a URL at runtime. No IDs anywhere else. */
	public static function url( string $key, string $fallback = '/' ): string {
		$id        = (int) get_option( "karo_kit_gate_{$key}_page" );
		$permalink = $id ? get_permalink( $id ) : false;
		return $permalink ? $permalink : home_url( $fallback );
	}

	/** Whether the current visitor may bypass the maintenance gate. */
	public static function can_bypass(): bool {
		$can = current_user_can( 'manage_options' );
		/** Filter who may bypass the gate, e.g. to allow 'editor' through. */
		return (bool) apply_filters( 'karo_kit_gate_can_bypass', $can );
	}
}
