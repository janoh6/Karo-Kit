<?php
/**
 * Gate module — settings (registration, sections, fields).
 *
 * @package Karo_Kit\Gate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Karo_Kit_Gate_Settings {

	/** Sub-tab id => label. */
	const TABS = array(
		'site-access' => 'Site Access',
		'maintenance' => 'Maintenance',
		'etch'        => 'Etch',
	);

	public static function init(): void {
		add_action( 'admin_init', array( __CLASS__, 'register' ) );

		// Config changes worth an audit trail. update_option_* only fires when
		// the value actually changed, so saving an unchanged form logs nothing.
		add_action( 'update_option_karo_kit_gate_maintenance_on', array( __CLASS__, 'log_maintenance_toggle' ), 10, 2 );
		add_action( 'update_option_karo_kit_gate_registration_on', array( __CLASS__, 'log_registration_toggle' ), 10, 2 );
		add_action( 'update_option_karo_kit_gate_hide_login', array( __CLASS__, 'log_hide_login_toggle' ), 10, 2 );
	}

	public static function log_maintenance_toggle( $old, $new ): void {
		$mode = Karo_Kit_Gate_Mode::from_option( get_option( 'karo_kit_gate_maintenance_mode', 'maintenance' ) );
		Karo_Kit_Log::add(
			'maintenance',
			$new
				/* translators: %s: gate mode label, e.g. "Coming soon" */
				? sprintf( __( 'Maintenance on — %s', 'karo-kit' ), $mode->label() )
				: __( 'Maintenance off', 'karo-kit' )
		);
	}

	public static function log_registration_toggle( $old, $new ): void {
		Karo_Kit_Log::add(
			'registration',
			$new ? __( 'Registration opened', 'karo-kit' ) : __( 'Registration closed', 'karo-kit' )
		);
	}

	public static function log_hide_login_toggle( $old, $new ): void {
		Karo_Kit_Log::add(
			'hide_login',
			$new ? __( 'Hidden login on', 'karo-kit' ) : __( 'Hidden login off', 'karo-kit' )
		);
	}

	/** Render the active section's body. Its own nav lives in the top bar (see Karo_Kit::nav_sections()). */
	public static function render_page(): void {
		$active = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : 'site-access';
		if ( ! isset( self::TABS[ $active ] ) ) {
			$active = 'site-access';
		}

		$heads = array(
			'site-access' => array(
				__( 'Site Access', 'karo-kit' ),
				__( 'Which pages serve your login flow, and who is allowed through.', 'karo-kit' ),
			),
			'maintenance' => array(
				__( 'Maintenance', 'karo-kit' ),
				__( 'Close the site to visitors while admins keep working.', 'karo-kit' ),
			),
			'etch'        => array(
				__( 'Etch', 'karo-kit' ),
				__( 'Dynamic data and shortcodes for building your pages in Etch.', 'karo-kit' ),
			),
		);
		self::render_pagehead( $heads[ $active ][0], $heads[ $active ][1] );

		if ( 'etch' === $active ) {
			echo '<div class="kk-content"><section class="kk-card">';
			do_settings_sections( 'karo-kit-gate-etch' );
			echo '</section></div>';
			return;
		}

		self::render_settings_form( 'karo-kit-gate-' . $active );
	}

	private static function render_pagehead( string $title, string $subtitle ): void {
		printf(
			'<div class="kk-pagehead"><h1 class="kk-pagehead__title">%s</h1><p class="kk-pagehead__sub">%s</p></div>',
			esc_html( $title ),
			esc_html( $subtitle )
		);
	}

	/**
	 * One card per registered settings section, all inside a single form, so a
	 * tab reads as distinct groups rather than one undifferentiated column of
	 * fields. Replaces do_settings_sections(), which renders them all inline.
	 */
	private static function render_settings_form( string $slug ): void {
		global $wp_settings_sections, $wp_settings_fields;

		echo '<form action="options.php" method="post">';
		settings_fields( Karo_Kit_Gate::option_group() );

		echo '<div class="kk-content kk-content--grid">';
		foreach ( (array) ( $wp_settings_sections[ $slug ] ?? array() ) as $section ) {
			echo '<section class="kk-card">';

			if ( $section['title'] ) {
				echo '<div class="kk-card__header"><span class="kk-card__title">' . esc_html( $section['title'] ) . '</span></div>';
			}
			if ( $section['callback'] ) {
				call_user_func( $section['callback'], $section );
			}
			if ( ! empty( $wp_settings_fields[ $slug ][ $section['id'] ] ) ) {
				echo '<table class="form-table" role="presentation">';
				do_settings_fields( $slug, $section['id'] );
				echo '</table>';
			}

			echo '</section>';
		}
		echo '</div>';

		echo '<div class="kk-actions">';
		submit_button( null, 'primary', 'submit', false );
		echo '</div>';
		echo '</form>';
	}

	public static function register(): void {
		$group = Karo_Kit_Gate::option_group();   // karo_kit_gate

		// Login-flow page selectors.
		foreach ( array_keys( Karo_Kit_Gate::PAGE_OPTIONS ) as $key ) {
			register_setting( $group, "karo_kit_gate_{$key}", array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'default'           => 0,
			) );
		}

		// Maintenance controls.
		register_setting( $group, 'karo_kit_gate_maintenance_page', array(
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 0,
		) );
		register_setting( $group, 'karo_kit_gate_maintenance_on', array(
			'type'              => 'boolean',
			'sanitize_callback' => array( __CLASS__, 'sanitize_bool' ),
			'default'           => 0,
		) );
		register_setting( $group, 'karo_kit_gate_maintenance_mode', array(
			'type'              => 'string',
			'sanitize_callback' => array( __CLASS__, 'sanitize_mode' ),
			'default'           => 'maintenance',
		) );

		// Security controls.
		register_setting( $group, 'karo_kit_gate_registration_on', array(
			'type'              => 'boolean',
			'sanitize_callback' => array( __CLASS__, 'sanitize_bool' ),
			'default'           => 0,
		) );
		register_setting( $group, 'karo_kit_gate_lockout_max', array(
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 5,
		) );
		register_setting( $group, 'karo_kit_gate_lockout_window', array(
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 15,
		) );
		register_setting( $group, 'karo_kit_gate_lockout_cooldown', array(
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 60,
		) );
		register_setting( $group, 'karo_kit_gate_hide_login', array(
			'type'              => 'boolean',
			'sanitize_callback' => array( __CLASS__, 'sanitize_bool' ),
			'default'           => 0,
		) );
		register_setting( $group, 'karo_kit_gate_login_slug', array(
			'type'              => 'string',
			'sanitize_callback' => array( __CLASS__, 'sanitize_slug' ),
			'default'           => '',
		) );
		register_setting( $group, 'karo_kit_gate_denied_page', array(
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 0,
		) );

		// --- Section: Site Access - Pages ---
		add_settings_section( 'karo_kit_gate_site_access_pages', __( 'Pages', 'karo-kit' ),
			array( __CLASS__, 'section_site_access_pages_intro' ), 'karo-kit-gate-site-access' );

		foreach ( Karo_Kit_Gate::PAGE_OPTIONS as $key => $label ) {
			add_settings_field(
				"karo_kit_gate_{$key}",
				esc_html( $label ),
				array( __CLASS__, 'field_page_dropdown' ),
				'karo-kit-gate-site-access',
				'karo_kit_gate_site_access_pages',
				array( 'key' => $key )
			);
		}

		// --- Section: Site Access - Registration ---
		add_settings_section( 'karo_kit_gate_site_access_registration', __( 'Registration', 'karo-kit' ),
			array( __CLASS__, 'section_site_access_registration_intro' ), 'karo-kit-gate-site-access' );

		add_settings_field( 'karo_kit_gate_registration_on', __( 'Allow registration', 'karo-kit' ),
			array( __CLASS__, 'field_registration_on' ), 'karo-kit-gate-site-access', 'karo_kit_gate_site_access_registration' );

		// --- Section: Site Access - Security ---
		add_settings_section( 'karo_kit_gate_site_access_security', __( 'Security & Restrictions', 'karo-kit' ),
			array( __CLASS__, 'section_site_access_security_intro' ), 'karo-kit-gate-site-access' );

		add_settings_field( 'karo_kit_gate_hide_login', __( 'Hide login URL', 'karo-kit' ),
			array( __CLASS__, 'field_hide_login' ), 'karo-kit-gate-site-access', 'karo_kit_gate_site_access_security' );
		add_settings_field( 'karo_kit_gate_login_slug', __( 'Secret word', 'karo-kit' ),
			array( __CLASS__, 'field_login_slug' ), 'karo-kit-gate-site-access', 'karo_kit_gate_site_access_security' );
		add_settings_field( 'karo_kit_gate_denied_page', __( 'Blocked page', 'karo-kit' ),
			array( __CLASS__, 'field_page_dropdown' ), 'karo-kit-gate-site-access', 'karo_kit_gate_site_access_security',
			array( 'key' => 'denied_page', 'desc' => __( 'Optional. Blocked login/admin URLs are redirected here (e.g. your homepage). Leave as None to show the theme 404 template instead.', 'karo-kit' ) ) );
		add_settings_field( 'karo_kit_gate_lockout_max', __( 'Max login attempts', 'karo-kit' ),
			array( __CLASS__, 'field_number' ), 'karo-kit-gate-site-access', 'karo_kit_gate_site_access_security',
			array( 'option' => 'karo_kit_gate_lockout_max', 'default' => 5, 'suffix' => __( 'failed attempts', 'karo-kit' ) ) );
		add_settings_field( 'karo_kit_gate_lockout_window', __( 'Within', 'karo-kit' ),
			array( __CLASS__, 'field_number' ), 'karo-kit-gate-site-access', 'karo_kit_gate_site_access_security',
			array( 'option' => 'karo_kit_gate_lockout_window', 'default' => 15, 'suffix' => __( 'minutes', 'karo-kit' ) ) );
		add_settings_field( 'karo_kit_gate_lockout_cooldown', __( 'Lock for', 'karo-kit' ),
			array( __CLASS__, 'field_number' ), 'karo-kit-gate-site-access', 'karo_kit_gate_site_access_security',
			array( 'option' => 'karo_kit_gate_lockout_cooldown', 'default' => 60, 'suffix' => __( 'minutes', 'karo-kit' ) ) );

		// --- Section: Maintenance ---
		add_settings_section( 'karo_kit_gate_maintenance', __( 'Maintenance', 'karo-kit' ),
			array( __CLASS__, 'section_maintenance_intro' ), 'karo-kit-gate-maintenance' );

		add_settings_field( 'karo_kit_gate_maintenance_page', __( 'Page to show', 'karo-kit' ),
			array( __CLASS__, 'field_page_dropdown' ), 'karo-kit-gate-maintenance', 'karo_kit_gate_maintenance',
			array( 'key' => 'maintenance_page' ) );
		add_settings_field( 'karo_kit_gate_maintenance_on', __( 'Status', 'karo-kit' ),
			array( __CLASS__, 'field_maint_on' ), 'karo-kit-gate-maintenance', 'karo_kit_gate_maintenance' );
		add_settings_field( 'karo_kit_gate_maintenance_mode', __( 'Mode', 'karo-kit' ),
			array( __CLASS__, 'field_maint_mode' ), 'karo-kit-gate-maintenance', 'karo_kit_gate_maintenance' );

		// --- Section: Etch ---
		add_settings_section( 'karo_kit_gate_etch', __( 'Etch Integration', 'karo-kit' ),
			array( __CLASS__, 'section_etch_intro' ), 'karo-kit-gate-etch' );
	}

	/* ---- Section intros -------------------------------------------------- */

	public static function section_site_access_pages_intro(): void {
		echo '<p>' . esc_html__( 'Pick the pages you built in Etch. Selections are stored by ID, so renaming a page never breaks anything. Leave a selector blank to let WordPress core handle that flow.', 'karo-kit' ) . '</p>';
	}

	public static function section_site_access_registration_intro(): void {
		echo '<p>' . esc_html__( 'Registration here is controlled by this toggle, independent of the WordPress core "Anyone can register" setting.', 'karo-kit' ) . '</p>';
	}

	public static function section_site_access_security_intro(): void {
		echo '<p>' . esc_html__( 'Rate limiting temporarily locks an IP after too many failed login or registration attempts.', 'karo-kit' ) . '</p>';
	}

	public static function section_maintenance_intro(): void {
		echo '<p>' . wp_kses_post( __( 'Users with <code>manage_options</code> always bypass the gate, so you can keep working on the live site. <strong>Coming soon</strong> returns HTTP 200 (indexable, for pre-launch). <strong>Maintenance</strong> returns 503 + Retry-After + noindex (for a live site that is temporarily down).', 'karo-kit' ) ) . '</p>';
	}

	public static function section_etch_intro(): void {
		echo '<p>' . wp_kses_post( __( 'Bind these in Etch. The user values come from the <code>etch/dynamic_data/user</code> integration and appear under <strong>user</strong> in Etch&#8217;s dynamic data picker.', 'karo-kit' ) ) . '</p>';

		$bindings = array(
			array( '{user.isLoggedIn}', __( 'true / false — bind to a condition to show/hide login vs. account UI', 'karo-kit' ) ),
			array( '{user.logoutUrl}', __( 'Logout link (redirects to the home page). Use as a link href.', 'karo-kit' ) ),
			array( '{user.loginUrl}', __( 'Your selected login page', 'karo-kit' ) ),
			array( '{user.accountUrl}', __( 'Your selected account page', 'karo-kit' ) ),
			array( '{user.displayName}', __( 'Current user&#8217;s display name (empty when logged out)', 'karo-kit' ) ),
			array( '{user.avatarUrl}', __( 'Avatar image URL (empty when logged out)', 'karo-kit' ) ),
		);

		echo '<table class="widefat striped" style="max-width:680px;margin-bottom:1em">';
		echo '<thead><tr><th>' . esc_html__( 'Dynamic data', 'karo-kit' ) . '</th><th>' . esc_html__( 'Outputs', 'karo-kit' ) . '</th></tr></thead><tbody>';
		foreach ( $bindings as $row ) {
			echo '<tr><td><code>' . esc_html( $row[0] ) . '</code></td><td>' . esc_html( $row[1] ) . '</td></tr>';
		}
		echo '</tbody></table>';

		$shortcodes = array(
			array( '[karo_kit_field action="login"]', __( 'Nonce + redirect field — place inside your login form', 'karo-kit' ) ),
			array( '[karo_kit_field action="register"]', __( 'Nonce + honeypot — place inside your registration form', 'karo-kit' ) ),
			array( '[karo_kit_message]', __( 'Shows login / registration error notices', 'karo-kit' ) ),
			array( '[karo_kit_logout]', __( 'A ready-made logout link', 'karo-kit' ) ),
		);

		echo '<p>' . esc_html__( 'Shortcodes (for spots where dynamic data is awkward):', 'karo-kit' ) . '</p>';
		echo '<table class="widefat striped" style="max-width:680px">';
		echo '<thead><tr><th>' . esc_html__( 'Shortcode', 'karo-kit' ) . '</th><th>' . esc_html__( 'Purpose', 'karo-kit' ) . '</th></tr></thead><tbody>';
		foreach ( $shortcodes as $row ) {
			echo '<tr><td><code>' . esc_html( $row[0] ) . '</code></td><td>' . esc_html( $row[1] ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/* ---- Field renderers ------------------------------------------------- */

	/** Dropdown across pages + all public CPTs (Meta Box included). */
	public static function field_page_dropdown( array $args ): void {
		$key      = $args['key'];
		$name     = "karo_kit_gate_{$key}";
		$selected = (int) get_option( $name );

		$types = get_post_types( array( 'public' => true ), 'objects' );
		unset( $types['attachment'] );

		echo '<select name="' . esc_attr( $name ) . '" id="' . esc_attr( $name ) . '">';
		echo '<option value="0">' . esc_html__( '— None —', 'karo-kit' ) . '</option>';

		foreach ( $types as $type ) {
			$posts = get_posts( array(
				'post_type'      => $type->name,
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			) );
			if ( ! $posts ) {
				continue;
			}
			echo '<optgroup label="' . esc_attr( $type->labels->name ) . '">';
			foreach ( $posts as $p ) {
				printf(
					'<option value="%d" %s>%s</option>',
					$p->ID,
					selected( $selected, $p->ID, false ),
					esc_html( $p->post_title ? $p->post_title : '(no title) #' . $p->ID )
				);
			}
			echo '</optgroup>';
		}
		echo '</select>';
		if ( ! empty( $args['desc'] ) ) {
			echo '<p class="description">' . esc_html( $args['desc'] ) . '</p>';
		}
	}

	/**
	 * A toggle switch. Still a real checkbox underneath — the visual is CSS on
	 * the sibling track — so it posts, tabs and announces exactly as before.
	 */
	public static function switch_field( string $option, string $label ): void {
		$on = (int) get_option( $option );
		printf(
			'<label class="kk-switch"><input type="checkbox" name="%s" value="1" %s><span class="kk-switch__track"></span><span class="kk-switch__label">%s</span></label>',
			esc_attr( $option ),
			checked( 1, $on, false ),
			esc_html( $label )
		);
	}

	public static function field_maint_on(): void {
		self::switch_field( 'karo_kit_gate_maintenance_on', __( 'Enable the gate', 'karo-kit' ) );
	}

	public static function field_maint_mode(): void {
		$current = Karo_Kit_Gate_Mode::from_option( get_option( 'karo_kit_gate_maintenance_mode', 'maintenance' ) );
		echo '<select name="karo_kit_gate_maintenance_mode">';
		foreach ( Karo_Kit_Gate_Mode::cases() as $case ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $case->value ),
				selected( $current->value, $case->value, false ),
				esc_html( $case->label() )
			);
		}
		echo '</select>';
	}

	public static function field_registration_on(): void {
		self::switch_field( 'karo_kit_gate_registration_on', __( 'Allow visitors to register via the registration page', 'karo-kit' ) );
	}

	public static function field_number( array $args ): void {
		$option  = $args['option'];
		$default = isset( $args['default'] ) ? (int) $args['default'] : 0;
		$suffix  = $args['suffix'] ?? '';
		$val     = (int) get_option( $option, $default );
		echo '<input type="number" min="1" class="small-text" name="' . esc_attr( $option ) . '" value="' . esc_attr( $val ) . '"> ' . esc_html( $suffix );
	}

	public static function field_hide_login(): void {
		self::switch_field( 'karo_kit_gate_hide_login', __( 'Block wp-login.php and wp-admin unless the secret word is used', 'karo-kit' ) );
		echo '<p class="description">' . wp_kses_post( __( 'Obscurity, not a lock — keep rate limiting on too. Recovery if locked out: add <code>define( \'KARO_KIT_DISABLE_HIDE_LOGIN\', true );</code> to wp-config.php, or deactivate the plugin.', 'karo-kit' ) ) . '</p>';
	}

	public static function field_login_slug(): void {
		$val = (string) get_option( 'karo_kit_gate_login_slug', '' );
		echo '<input type="text" class="regular-text" name="karo_kit_gate_login_slug" value="' . esc_attr( $val ) . '" placeholder="e.g. way-in">';
		if ( '' !== $val ) {
			$has_page = (int) get_option( 'karo_kit_gate_login_page' ) > 0;
			$dest     = $has_page
				? esc_html__( 'your custom login page (which is then blocked unless reached this way)', 'karo-kit' )
				: esc_html__( 'the WordPress login', 'karo-kit' );
			echo '<p class="description">'
				. esc_html__( 'Login entry:', 'karo-kit' ) . ' <code>' . esc_html( home_url( '/' . $val ) ) . '</code> &rarr; '
				. esc_html( $dest ) . '.</p>';
		}
	}

	/* ---- Sanitizers ------------------------------------------------------ */

	public static function sanitize_bool( $v ): int {
		return $v ? 1 : 0;
	}

	public static function sanitize_mode( $v ): string {
		return Karo_Kit_Gate_Mode::from_option( $v )->value;
	}

	public static function sanitize_slug( $v ): string {
		$slug     = sanitize_title( (string) $v );
		$reserved = array( 'wp-admin', 'wp-login', 'admin', 'login', 'dashboard' );
		return in_array( $slug, $reserved, true ) ? '' : $slug;
	}
}
