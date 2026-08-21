<?php
/**
 * Etch module — settings + reference panels.
 *
 * @package Karo_Kit\Etch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Karo_Kit_Etch_Settings {

	public static function init(): void {
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		Karo_Kit::on_option_change( Karo_Kit_Etch_Board::ENABLED_OPT, array( __CLASS__, 'log_board_toggle' ) );
		Karo_Kit::on_option_change( Karo_Kit_Etch_Structure::ENABLED_OPT, array( __CLASS__, 'log_structure_toggle' ) );
		Karo_Kit::on_option_change( Karo_Kit_Etch_Sidebar::ENABLED_OPT, array( __CLASS__, 'log_sidebar_toggle' ) );
	}

	public static function log_board_toggle( $new ): void {
		Karo_Kit_Log::add(
			'etch_board',
			$new ? __( 'Template Board enabled', 'karo-kit' ) : __( 'Template Board disabled', 'karo-kit' )
		);
	}

	public static function log_structure_toggle( $new ): void {
		Karo_Kit_Log::add(
			'etch_structure',
			$new ? __( 'Structure arrows enabled', 'karo-kit' ) : __( 'Structure arrows disabled', 'karo-kit' )
		);
	}

	public static function log_sidebar_toggle( $new ): void {
		Karo_Kit_Log::add(
			'etch_sidebar',
			$new ? __( 'Sidebar tabs enabled', 'karo-kit' ) : __( 'Sidebar tabs disabled', 'karo-kit' )
		);
	}

	public static function register(): void {
		$slug  = Karo_Kit_Etch::settings_slug();  // karo-kit-etch

		// --- Section: Template Board ---
		add_settings_section( 'karo_kit_etch_board', __( 'Template Board', 'karo-kit' ),
			array( __CLASS__, 'section_board_intro' ), $slug );

		add_settings_field( Karo_Kit_Etch_Board::ENABLED_OPT, __( 'Template Board', 'karo-kit' ),
			array( __CLASS__, 'field_board_on' ), $slug, 'karo_kit_etch_board' );

		add_settings_field( Karo_Kit_Etch_Board::THRESHOLD_OPT, __( 'Regenerate thumbnail after', 'karo-kit' ),
			array( __CLASS__, 'field_threshold' ), $slug, 'karo_kit_etch_board' );

		add_settings_field( Karo_Kit_Etch_Board::AUTO_LIMIT_OPT, __( 'Auto-generate up to', 'karo-kit' ),
			array( __CLASS__, 'field_auto_limit' ), $slug, 'karo_kit_etch_board' );

		// --- Section: Structure Arrows ---
		add_settings_section( 'karo_kit_etch_structure', __( 'Structure Arrows', 'karo-kit' ),
			array( __CLASS__, 'section_structure_intro' ), $slug );

		add_settings_field( Karo_Kit_Etch_Structure::ENABLED_OPT, __( 'Structure arrows', 'karo-kit' ),
			array( __CLASS__, 'field_structure_on' ), $slug, 'karo_kit_etch_structure' );
		add_settings_field( Karo_Kit_Etch_Structure::DWELL_OPT, __( 'Reveal indent arrows after', 'karo-kit' ),
			array( __CLASS__, 'field_dwell' ), $slug, 'karo_kit_etch_structure' );
		add_settings_field( Karo_Kit_Etch_Structure::PLACEMENT_OPT, __( 'Arrow position', 'karo-kit' ),
			array( __CLASS__, 'field_placement' ), $slug, 'karo_kit_etch_structure' );
		add_settings_field( Karo_Kit_Etch_Structure::DISABLED_OPT, __( 'Unavailable moves', 'karo-kit' ),
			array( __CLASS__, 'field_show_disabled' ), $slug, 'karo_kit_etch_structure' );

		// --- Section: Sidebar Tabs ---
		add_settings_section( 'karo_kit_etch_sidebar', __( 'Sidebar Tabs', 'karo-kit' ),
			array( __CLASS__, 'section_sidebar_intro' ), $slug );

		add_settings_field( Karo_Kit_Etch_Sidebar::ENABLED_OPT, __( 'Sidebar tabs', 'karo-kit' ),
			array( __CLASS__, 'field_sidebar_on' ), $slug, 'karo_kit_etch_sidebar' );
		add_settings_field( Karo_Kit_Etch_Sidebar::REMEMBER_OPT, __( 'Remember state', 'karo-kit' ),
			array( __CLASS__, 'field_sidebar_remember' ), $slug, 'karo_kit_etch_sidebar' );

		// --- Section: Reference (no fields, just a help panel) ---
		add_settings_section( 'karo_kit_etch_reference', __( 'Reference', 'karo-kit' ),
			array( __CLASS__, 'section_reference_intro' ), $slug );
	}

	public static function render_page(): void {
		printf(
			'<div class="kk-pagehead"><h1 class="kk-pagehead__title">%s</h1><p class="kk-pagehead__sub">%s</p></div>',
			esc_html__( 'Etch', 'karo-kit' ),
			esc_html__( 'Builder features and the dynamic data and shortcodes for building your pages.', 'karo-kit' )
		);

		self::render_settings_form( Karo_Kit_Etch::settings_slug() );
	}

	/**
	 * One card per registered settings section, all inside a single form.
	 * Mirrors the Gate module's renderer — see the note there on why this
	 * replaces do_settings_sections().
	 */
	private static function render_settings_form( string $slug ): void {
		global $wp_settings_sections, $wp_settings_fields;

		echo '<form action="options.php" method="post">';
		settings_fields( Karo_Kit_Etch::option_group() );

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

	/* ---- Sections -------------------------------------------------------- */

	public static function section_board_intro(): void {
		echo '<p>' . esc_html__( 'Replaces Etch\'s Templates screen with a board: columns, drag-to-reorder, search, status badges and a component graph. Create, open and delete still run through Etch\'s own controls.', 'karo-kit' ) . '</p>';

		// Thumbnails are captured in your own browser, so a local or otherwise
		// unreachable site is no longer a problem worth warning about.
	}

	public static function field_board_on(): void {
		Karo_Kit::switch_field(
			Karo_Kit_Etch_Board::ENABLED_OPT,
			__( 'Replace Etch\'s Templates screen with the board', 'karo-kit' ),
			1
		);
		echo '<p class="description">' . esc_html__( 'Off returns Etch to its native Templates screen. Your columns, statuses and thumbnails are kept, so switching back on restores them.', 'karo-kit' ) . '</p>';
	}

	public static function field_threshold(): void {
		printf(
			'<input type="number" min="1" class="small-text" name="%s" value="%d"> %s',
			esc_attr( Karo_Kit_Etch_Board::THRESHOLD_OPT ),
			(int) Karo_Kit_Etch_Board::get_threshold(),
			esc_html__( 'saves', 'karo-kit' )
		);
		echo '<p class="description">' . esc_html__( 'How many times a template is saved before its thumbnail is treated as stale and regenerated.', 'karo-kit' ) . '</p>';
	}

	public static function field_auto_limit(): void {
		printf(
			'<input type="number" min="0" class="small-text" name="%s" value="%d"> %s',
			esc_attr( Karo_Kit_Etch_Board::AUTO_LIMIT_OPT ),
			Karo_Kit_Etch_Board::get_auto_limit(),
			esc_html__( 'thumbnails per visit', 'karo-kit' )
		);
		echo '<p class="description">' . esc_html__( 'Thumbnails are captured in your own browser, so each one takes a few real seconds. The board generates up to this many missing or stale thumbnails automatically when you open it; the rest wait for "Generate thumbnail" from a card\'s menu. 0 turns off automatic generation entirely.', 'karo-kit' ) . '</p>';
	}

	/* ---- Structure Arrows ------------------------------------------------ */

	public static function section_structure_intro(): void {
		echo '<p>' . esc_html__( 'Hover a row in Etch\'s structure panel to get up / down arrows for reordering among siblings. Keep hovering and the outdent / indent arrows appear for changing nesting depth. Only moves that are valid for that block are shown.', 'karo-kit' ) . '</p>';
	}

	public static function field_structure_on(): void {
		Karo_Kit::switch_field(
			Karo_Kit_Etch_Structure::ENABLED_OPT,
			__( 'Show move arrows in the structure panel', 'karo-kit' ),
			1
		);
	}

	public static function field_dwell(): void {
		printf(
			'<input type="number" min="0" step="50" class="small-text" name="%s" value="%d"> %s',
			esc_attr( Karo_Kit_Etch_Structure::DWELL_OPT ),
			(int) Karo_Kit_Etch_Structure::dwell(),
			esc_html__( 'ms', 'karo-kit' )
		);
		echo '<p class="description">' . esc_html__( 'How long to keep hovering before the indent / outdent arrows appear. Reordering arrows show immediately. 0 reveals everything at once.', 'karo-kit' ) . '</p>';
	}

	public static function field_placement(): void {
		$current = Karo_Kit_Etch_Structure::placement();
		$options = array(
			'prepend' => __( 'Before the row\'s other icons', 'karo-kit' ),
			'append'  => __( 'After the row\'s other icons', 'karo-kit' ),
		);
		printf( '<select name="%s">', esc_attr( Karo_Kit_Etch_Structure::PLACEMENT_OPT ) );
		foreach ( $options as $value => $label ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $value ),
				selected( $current, $value, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
	}

	public static function field_show_disabled(): void {
		Karo_Kit::switch_field(
			Karo_Kit_Etch_Structure::DISABLED_OPT,
			__( 'Also show unavailable moves, greyed out', 'karo-kit' )
		);
		echo '<p class="description">' . esc_html__( 'Off by default: only the moves you can actually make are drawn. On, the rest appear dimmed and unclickable, which makes the rules easier to learn.', 'karo-kit' ) . '</p>';
	}

	public static function sanitize_placement( $v ): string {
		$v = sanitize_key( (string) $v );
		return in_array( $v, Karo_Kit_Etch_Structure::PLACEMENTS, true ) ? $v : 'prepend';
	}

	/* ---- Sidebar Tabs ----------------------------------------------------- */

	public static function section_sidebar_intro(): void {
		echo '<p>' . esc_html__( 'Puts a small tab on each edge of the builder that collapses the panel beside it, for more canvas on a narrow screen. Panels reopen at whatever width you had resized them to, and the tabs can be dragged up and down.', 'karo-kit' ) . '</p>';

		$shortcuts = array(
			array( 'Alt + [', __( 'Collapse / expand the left panel', 'karo-kit' ) ),
			array( 'Alt + ]', __( 'Collapse / expand the right panel', 'karo-kit' ) ),
			array( 'Alt + \\', __( 'Hide both, or bring both back', 'karo-kit' ) ),
		);
		echo '<table class="kk-table kk-table--wide"><tbody>';
		foreach ( $shortcuts as $row ) {
			echo '<tr><td><code>' . esc_html( $row[0] ) . '</code></td><td>' . esc_html( $row[1] ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	public static function field_sidebar_on(): void {
		Karo_Kit::switch_field(
			Karo_Kit_Etch_Sidebar::ENABLED_OPT,
			__( 'Show collapse tabs on the builder panels', 'karo-kit' ),
			1
		);
	}

	public static function field_sidebar_remember(): void {
		Karo_Kit::switch_field(
			Karo_Kit_Etch_Sidebar::REMEMBER_OPT,
			__( 'Keep panels as you left them between sessions', 'karo-kit' ),
			1
		);
		echo '<p class="description">' . esc_html__( 'Off means the builder always opens with both panels showing, and the tabs back at their default position.', 'karo-kit' ) . '</p>';
	}

	/**
	 * Bindings and shortcodes exposed to the Etch builder.
	 *
	 * The user values are produced by the Gate module (see
	 * Karo_Kit_Gate_Etch) — documented here because this is the Etch-facing
	 * surface, while the filter itself stays with the feature that owns it.
	 */
	public static function section_reference_intro(): void {
		echo '<p>' . wp_kses_post( __( 'Bind these in Etch. The user values come from the <code>etch/dynamic_data/user</code> integration and appear under <strong>user</strong> in Etch&#8217;s dynamic data picker.', 'karo-kit' ) ) . '</p>';

		$bindings = array(
			array( '{user.isLoggedIn}', __( 'true / false — bind to a condition to show/hide login vs. account UI', 'karo-kit' ) ),
			array( '{user.logoutUrl}', __( 'Logout link (redirects to the home page). Use as a link href.', 'karo-kit' ) ),
			array( '{user.loginUrl}', __( 'Your selected login page', 'karo-kit' ) ),
			array( '{user.accountUrl}', __( 'Your selected account page', 'karo-kit' ) ),
			array( '{user.displayName}', __( 'Current user&#8217;s display name (empty when logged out)', 'karo-kit' ) ),
			array( '{user.avatarUrl}', __( 'Avatar image URL (empty when logged out)', 'karo-kit' ) ),
		);

		echo '<table class="kk-table kk-table--wide"><tbody>';
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

		echo '<p>' . esc_html__( 'Shortcodes, for spots where dynamic data is awkward:', 'karo-kit' ) . '</p>';
		echo '<table class="kk-table kk-table--wide"><tbody>';
		foreach ( $shortcodes as $row ) {
			echo '<tr><td><code>' . esc_html( $row[0] ) . '</code></td><td>' . esc_html( $row[1] ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}
}
