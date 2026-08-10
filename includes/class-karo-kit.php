<?php
/**
 * Karo Kit core — module registry + the custom admin shell.
 *
 * @package Karo_Kit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Karo_Kit {

	/** @var array<string,class-string<Karo_Kit_Module>> id => module class */
	private static array $modules = array();

	/** Hook suffix of our admin page, set once add_menu_page() runs. */
	private static string $hook = '';

	/**
	 * One daily maintenance event that features attach their housekeeping to,
	 * rather than each scheduling its own.
	 */
	const CRON_DAILY = 'karo_kit_daily';

	/** Register a module class (must extend Karo_Kit_Module). */
	public static function register( string $class ): void {
		if ( is_subclass_of( $class, Karo_Kit_Module::class ) ) {
			self::$modules[ $class::id() ] = $class;
		}
	}

	/** Boot every registered module + the admin shell. */
	public static function boot(): void {
		foreach ( self::$modules as $class ) {
			$class::init();
		}
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_filter( 'admin_body_class', array( __CLASS__, 'body_class' ) );
		add_action( 'wp_ajax_karo_kit_save_setting', array( __CLASS__, 'ajax_save_setting' ) );
		add_action( 'wp_ajax_karo_kit_save_theme', array( __CLASS__, 'ajax_save_theme' ) );
		// Must run before any output: this handler redirects, and the page
		// callback fires far too late to send headers.
		add_action( 'admin_init', array( __CLASS__, 'handle_log_actions' ) );
		Karo_Kit_Transfer::init();
		Karo_Kit_Log::init();
		add_action( 'admin_init', array( __CLASS__, 'ensure_cron' ) );
	}

	/**
	 * Autosave one setting.
	 *
	 * Writes through update_option() so the sanitize_callback each setting was
	 * registered with still runs — this endpoint adds no second, divergent
	 * validation path. Only options registered to one of our own modules'
	 * option groups are writable; anything else is refused, so this can't be
	 * turned into a general-purpose option writer.
	 */
	public static function ajax_save_setting(): void {
		check_ajax_referer( 'karo_kit_autosave', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'karo-kit' ) ), 403 );
		}

		$option = isset( $_POST['option'] ) ? sanitize_key( wp_unslash( $_POST['option'] ) ) : '';
		if ( ! in_array( $option, self::writable_options(), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown setting.', 'karo-kit' ) ), 400 );
		}

		// Scalars only — an array here would reach sanitizers like absint() and fatal.
		$raw = isset( $_POST['value'] ) ? wp_unslash( $_POST['value'] ) : '';
		if ( ! is_scalar( $raw ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid value.', 'karo-kit' ) ), 400 );
		}

		// Each setting's own sanitize_callback runs inside update_option().
		update_option( $option, (string) $raw );

		wp_send_json_success( array( 'value' => get_option( $option ) ) );
	}

	/** Option names registered to our modules' settings groups. */
	private static function writable_options(): array {
		$groups = array();
		foreach ( self::$modules as $class ) {
			$groups[] = $class::option_group();
		}

		$allowed = array();
		foreach ( get_registered_settings() as $name => $args ) {
			if ( isset( $args['group'] ) && in_array( $args['group'], $groups, true ) ) {
				$allowed[] = $name;
			}
		}
		return $allowed;
	}

	/** @return array<string,class-string<Karo_Kit_Module>> */
	public static function modules(): array {
		return self::$modules;
	}

	/**
	 * Run a callback whenever an option's value changes.
	 *
	 * Both hooks are needed, not just update_option_*: when an option is still
	 * at its registered default it has no DB row yet, so update_option()
	 * delegates to add_option() and fires only add_option_* (see
	 * wp-includes/option.php). Hooking update alone silently misses the first
	 * change of every setting — which, on a fresh site, is all of them.
	 *
	 * The two hooks pass their arguments in different orders; this normalises
	 * them so the callback just receives the new value.
	 *
	 * @param string   $option   Option name.
	 * @param callable $callback Receives the new value.
	 */
	public static function on_option_change( string $option, callable $callback ): void {
		add_action( "add_option_{$option}", static function ( $name, $value ) use ( $callback ) {
			$callback( $value );
		}, 10, 2 );

		add_action( "update_option_{$option}", static function ( $old, $new ) use ( $callback ) {
			$callback( $new );
		}, 10, 2 );
	}

	/**
	 * A toggle switch, for any module's settings.
	 *
	 * Still a real checkbox underneath — the visual is CSS on the sibling
	 * track — so it posts, tabs and announces to assistive tech exactly as a
	 * checkbox does.
	 */
	public static function switch_field( string $option, string $label, int $default = 0 ): void {
		$on = (int) get_option( $option, $default );
		printf(
			'<label class="kk-switch"><input type="checkbox" name="%s" value="1" %s><span class="kk-switch__track"></span><span class="kk-switch__label">%s</span></label>',
			esc_attr( $option ),
			checked( 1, $on, false ),
			esc_html( $label )
		);
	}

	public static function add_menu(): void {
		self::$hook = add_menu_page(
			__( 'Karo Kit', 'karo-kit' ),
			__( 'Karo Kit', 'karo-kit' ),
			'manage_options',
			'karo-kit',
			array( __CLASS__, 'render_page' ),
			'dashicons-shield',
			80
		);
	}

	/** Our own assets only — never loaded on any other wp-admin screen. */
	public static function enqueue_assets( string $hook ): void {
		if ( $hook !== self::$hook ) {
			return;
		}

		$css = KARO_KIT_DIR . 'assets/css/admin.css';
		wp_enqueue_style(
			'karo-kit-admin',
			KARO_KIT_URL . 'assets/css/admin.css',
			array(),
			file_exists( $css ) ? (string) filemtime( $css ) : KARO_KIT_VER
		);

		$js = KARO_KIT_DIR . 'assets/js/admin.js';
		wp_enqueue_script(
			'karo-kit-admin',
			KARO_KIT_URL . 'assets/js/admin.js',
			array(),
			file_exists( $js ) ? (string) filemtime( $js ) : KARO_KIT_VER,
			true
		);
		wp_localize_script( 'karo-kit-admin', 'karoKitAutosave', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'karo_kit_autosave' ),
			'i18n'    => array(
				'saving' => __( 'Saving…', 'karo-kit' ),
				'saved'  => __( 'Saved', 'karo-kit' ),
				'error'  => __( 'Could not save — reload and try again', 'karo-kit' ),
			),
		) );
	}

	/** Flags our screen so admin.css can hide the default wp-admin chrome, scoped to us only. */
	public static function body_class( string $classes ): string {
		if ( self::$hook && function_exists( 'get_current_screen' ) ) {
			$screen = get_current_screen();
			if ( $screen && self::$hook === $screen->id ) {
				$classes .= ' karo-kit-app';
				// Emitted server-side so the page paints in the right theme
				// straight away — applying it from JS would flash the wrong one.
				$theme = self::theme();
				if ( 'auto' !== $theme ) {
					$classes .= ' kk-theme-' . $theme;
				}
			}
		}
		return $classes;
	}

	const THEMES    = array( 'auto', 'light', 'dark' );
	const THEME_KEY = 'karo_kit_theme';

	/**
	 * The current user's theme choice: 'auto' (follow the OS), 'light' or
	 * 'dark'. Per-user rather than a site option — it's a personal display
	 * preference, not site configuration.
	 */
	public static function theme(): string {
		$theme = (string) get_user_meta( get_current_user_id(), self::THEME_KEY, true );
		return in_array( $theme, self::THEMES, true ) ? $theme : 'auto';
	}

	public static function ajax_save_theme(): void {
		check_ajax_referer( 'karo_kit_autosave', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'karo-kit' ) ), 403 );
		}

		$theme = isset( $_POST['theme'] ) ? sanitize_key( wp_unslash( $_POST['theme'] ) ) : '';
		if ( ! in_array( $theme, self::THEMES, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown theme.', 'karo-kit' ) ), 400 );
		}

		update_user_meta( get_current_user_id(), self::THEME_KEY, $theme );
		wp_send_json_success( array( 'theme' => $theme ) );
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$active = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dashboard';
		// 'log' and 'transfer' are reachable by link only — deliberately absent
		// from the top nav.
		$extra = array( 'dashboard', 'log', 'transfer' );
		if ( ! in_array( $active, $extra, true ) && ! isset( self::$modules[ $active ] ) ) {
			$active = 'dashboard';
		}
		$active_section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : '';

		echo '<div class="kk-app">';
		self::render_topbar( $active, $active_section );

		echo '<main>';
		if ( 'dashboard' === $active ) {
			self::render_dashboard();
		} elseif ( 'log' === $active ) {
			self::render_log();
		} elseif ( 'transfer' === $active ) {
			Karo_Kit_Transfer::render_preview();
		} else {
			self::$modules[ $active ]::render_page();
		}
		echo '</main>';
		echo '</div>';
	}

	/**
	 * Clearing the log is destructive, so it needs a nonce and a POST-redirect.
	 * Runs on admin_init — early enough to still send redirect headers.
	 */
	public static function handle_log_actions(): void {
		// Cheapest check first: this fires on every admin page load.
		if ( empty( $_POST['karo_kit_clear_log'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( 'karo_kit_clear_log' );

		Karo_Kit_Log::clear();
		wp_safe_redirect( admin_url( 'admin.php?page=karo-kit&tab=log&cleared=1' ) );
		exit;
	}

	private static function render_topbar( string $active, string $active_section ): void {
		echo '<header class="kk-topbar"><div class="kk-topbar__inner">';
		echo '<div class="kk-brand"><div class="kk-brand__mark">K</div><span class="kk-brand__name">' . esc_html__( 'Karo Kit', 'karo-kit' ) . '</span></div>';

		echo '<nav class="kk-tabs">';
		printf(
			'<a class="kk-tab %s" href="%s">%s</a>',
			( 'dashboard' === $active ) ? 'kk-tab--active' : '',
			esc_url( admin_url( 'admin.php?page=karo-kit&tab=dashboard' ) ),
			esc_html__( 'Dashboard', 'karo-kit' )
		);
		foreach ( self::$modules as $id => $class ) {
			$sections = $class::nav_sections();

			// No sub-sections: one tab bearing the module's own label.
			if ( ! $sections ) {
				printf(
					'<a class="kk-tab %s" href="%s">%s</a>',
					( $active === $id ) ? 'kk-tab--active' : '',
					esc_url( admin_url( 'admin.php?page=karo-kit&tab=' . $id ) ),
					esc_html( $class::label() )
				);
				continue;
			}

			// Sub-sections: flatten them straight into the top bar as peer tabs.
			$first = array_key_first( $sections );
			foreach ( $sections as $section_id => $label ) {
				$is_active = ( $active === $id ) && ( $active_section === $section_id || ( '' === $active_section && $first === $section_id ) );
				printf(
					'<a class="kk-tab %s" href="%s">%s</a>',
					$is_active ? 'kk-tab--active' : '',
					esc_url( admin_url( 'admin.php?page=karo-kit&tab=' . $id . '&section=' . $section_id ) ),
					esc_html__( $label, 'karo-kit' )
				);
			}
		}
		echo '</nav>';

		echo '<div class="kk-topbar__spacer"></div>';
		echo '<div class="kk-utility">';
		self::render_theme_switcher();
		echo '<span class="kk-utility__version">v' . esc_html( KARO_KIT_VER ) . '</span>';
		echo '</div>';
		echo '</div></header>';
	}

	/** Auto / light / dark, as three pressed-state buttons. */
	private static function render_theme_switcher(): void {
		$current = self::theme();

		// Lucide-style glyphs, stroked with currentColor so they follow the theme.
		$icons = array(
			'auto'  => '<circle cx="12" cy="12" r="9"/><path d="M12 3v18"/><path d="M12 8a4 4 0 0 1 0 8"/>',
			'light' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M2 12h2M20 12h2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M19.1 4.9l-1.4 1.4M6.3 17.7l-1.4 1.4"/>',
			'dark'  => '<path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/>',
		);
		$labels = array(
			'auto'  => __( 'Match system theme', 'karo-kit' ),
			'light' => __( 'Light theme', 'karo-kit' ),
			'dark'  => __( 'Dark theme', 'karo-kit' ),
		);

		printf( '<div class="kk-theme" role="group" aria-label="%s">', esc_attr__( 'Theme', 'karo-kit' ) );
		foreach ( $icons as $key => $path ) {
			printf(
				'<button type="button" class="kk-theme__btn" data-kk-theme="%s" aria-pressed="%s" title="%s" aria-label="%s"><svg viewBox="0 0 24 24" aria-hidden="true">%s</svg></button>',
				esc_attr( $key ),
				( $current === $key ) ? 'true' : 'false',
				esc_attr( $labels[ $key ] ),
				esc_attr( $labels[ $key ] ),
				$path // phpcs:ignore WordPress.Security.EscapeOutput — static inline SVG paths.
			);
		}
		echo '</div>';
	}

	/**
	 * One card per functional area, each pairing that area's status with the
	 * pages it governs — so a question ("is registration open, and which page
	 * serves it?") is answered without crossing the page.
	 */
	private static function render_dashboard(): void {
		self::pagehead(
			(string) wp_parse_url( home_url(), PHP_URL_HOST ),
			__( 'Status across every installed module.', 'karo-kit' )
		);

		$groups = array();
		foreach ( self::$modules as $id => $class ) {
			foreach ( $class::dashboard_groups() as $group ) {
				$group['module'] = $id;
				$groups[]        = $group;
			}
		}

		if ( ! $groups ) {
			echo '<div class="kk-content"><div class="kk-card"><p>' . esc_html__( 'No modules are reporting status yet.', 'karo-kit' ) . '</p></div></div>';
			return;
		}

		// Status cards sit in columns (they're read, not filled in); the activity
		// table below spans the full width because it has real columns of its own.
		echo '<div class="kk-content kk-content--cols">';
		foreach ( $groups as $group ) {
			echo '<section class="kk-card">';

			echo '<div class="kk-card__header">';
			echo '<span class="kk-card__title">' . esc_html( $group['label'] ) . '</span>';
			$target = 'admin.php?page=karo-kit&tab=' . $group['module'];
			if ( ! empty( $group['section'] ) ) {
				$target .= '&section=' . $group['section'];
			}
			printf(
				'<a class="kk-card__link" href="%s">%s</a>',
				esc_url( admin_url( $target ) ),
				esc_html__( 'Configure', 'karo-kit' )
			);
			echo '</div>';

			if ( ! empty( $group['status'] ) ) {
				echo '<div class="kk-statuses">';
				foreach ( $group['status'] as $stat ) {
					printf(
						'<span class="kk-status %s"><span class="kk-status__dot"></span>%s<strong>%s</strong></span>',
						$stat['on'] ? 'kk-status--on' : '',
						esc_html( $stat['label'] ),
						esc_html( $stat['value'] )
					);
				}
				echo '</div>';
			}

			if ( ! empty( $group['pages'] ) ) {
				echo '<table class="kk-table"><tbody>';
				foreach ( $group['pages'] as $row ) {
					self::render_page_row( $row['label'], $row['option'] );
				}
				echo '</tbody></table>';
			}

			echo '</section>';
		}
		echo '</div>';

		self::render_activity_card();
		Karo_Kit_Transfer::render_card();
	}

	/** Last few log entries, with a way through to the full log. */
	private static function render_activity_card(): void {
		$recent = Karo_Kit_Log::recent();

		echo '<div class="kk-content"><section class="kk-card">';
		echo '<div class="kk-card__header">';
		echo '<span class="kk-card__title">' . esc_html__( 'Recent activity', 'karo-kit' ) . '</span>';
		if ( $recent ) {
			printf(
				'<a class="kk-card__link" href="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=karo-kit&tab=log' ) ),
				esc_html__( 'View all logs', 'karo-kit' )
			);
		}
		echo '</div>';

		if ( ! $recent ) {
			echo '<p class="kk-empty">' . esc_html__( 'Nothing logged yet. Failed logins, lockouts, blocked URLs, new registrations and gate changes appear here.', 'karo-kit' ) . '</p>';
			echo '</section></div>';
			return;
		}

		self::render_log_table( $recent );
		echo '</section></div>';
	}

	/** @param array<int,array{time:int,type:string,message:string,ip:string}> $entries */
	private static function render_log_table( array $entries ): void {
		echo '<table class="kk-log"><thead><tr>';
		printf( '<th>%s</th>', esc_html__( 'Time', 'karo-kit' ) );
		printf( '<th>%s</th>', esc_html__( 'Event', 'karo-kit' ) );
		printf( '<th>%s</th>', esc_html__( 'IP address', 'karo-kit' ) );
		echo '</tr></thead><tbody>';

		foreach ( $entries as $entry ) {
			printf(
				'<tr><td class="kk-log__time">%s</td><td><span class="kk-log__dot kk-log__dot--%s"></span>%s</td><td class="kk-log__ip">%s</td></tr>',
				esc_html( Karo_Kit_Log::when( (int) $entry['time'] ) ),
				esc_attr( self::log_tone( $entry['type'] ) ),
				esc_html( $entry['message'] ),
				esc_html( $entry['ip'] ? $entry['ip'] : '—' )
			);
		}
		echo '</tbody></table>';
	}

	/** Full log — reachable from the dashboard card, not from the top nav. */
	private static function render_log(): void {
		self::pagehead(
			__( 'Activity log', 'karo-kit' ),
			sprintf(
				/* translators: %d: maximum retained log entries */
				__( 'The most recent %d events. Older entries are dropped automatically.', 'karo-kit' ),
				Karo_Kit_Log::MAX
			)
		);

		$log = Karo_Kit_Log::all();

		echo '<div class="kk-content"><section class="kk-card">';

		echo '<div class="kk-card__header">';
		echo '<span class="kk-card__title">' . esc_html__( 'Events', 'karo-kit' ) . '</span>';
		printf(
			'<a class="kk-card__link" href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=karo-kit&tab=dashboard' ) ),
			esc_html__( 'Back to dashboard', 'karo-kit' )
		);
		echo '</div>';

		if ( isset( $_GET['cleared'] ) ) {
			echo '<p class="kk-empty">' . esc_html__( 'Log cleared.', 'karo-kit' ) . '</p>';
		}

		if ( ! $log ) {
			echo '<p class="kk-empty">' . esc_html__( 'Nothing logged yet.', 'karo-kit' ) . '</p>';
			echo '</section></div>';
			return;
		}

		self::render_log_table( $log );

		echo '<form method="post" class="kk-card__foot">';
		wp_nonce_field( 'karo_kit_clear_log' );
		printf(
			'<button type="submit" name="karo_kit_clear_log" value="1" class="kk-btn kk-btn--quiet">%s</button>',
			esc_html__( 'Clear log', 'karo-kit' )
		);
		echo '</form>';

		echo '</section></div>';
	}

	/** Colour cue for a log entry: attention-worthy vs. routine. */
	private static function log_tone( string $type ): string {
		return match ( true ) {
			'lockout' === $type                => 'alert',
			str_starts_with( $type, 'failed' ) => 'warn',
			'blocked' === $type                => 'warn',
			default                            => 'muted',
		};
	}

	/** Shared page header. Public so module/feature classes can use it. */
	public static function pagehead( string $title, string $subtitle = '' ): void {
		echo '<div class="kk-pagehead">';
		echo '<h1 class="kk-pagehead__title">' . esc_html( $title ) . '</h1>';
		if ( '' !== $subtitle ) {
			echo '<p class="kk-pagehead__sub">' . esc_html( $subtitle ) . '</p>';
		}
		echo '</div>';
	}

	private static function render_page_row( string $label, string $option ): void {
		$id = (int) get_option( $option );
		if ( ! $id ) {
			printf(
				'<tr><td>%s</td><td class="kk-table__unset">%s</td></tr>',
				esc_html( $label ),
				esc_html__( 'Not set', 'karo-kit' )
			);
			return;
		}
		$title = get_the_title( $id );
		$edit  = get_edit_post_link( $id );
		printf(
			'<tr><td>%s</td><td><a href="%s">%s</a></td></tr>',
			esc_html( $label ),
			esc_url( $edit ? $edit : '#' ),
			esc_html( $title ? $title : '(no title) #' . $id )
		);
	}

	/** Idempotent; also covers in-place updates, which skip activation. */
	public static function ensure_cron(): void {
		if ( ! wp_next_scheduled( self::CRON_DAILY ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_DAILY );
		}
	}

	public static function activate(): void {
		update_option( 'karo_kit_version', KARO_KIT_VER );
		Karo_Kit_Log::install();
		Karo_Kit_Gate_Security::install();
		self::ensure_cron();
		if ( class_exists( 'Karo_Kit_Etch_Board' ) ) {
			Karo_Kit_Etch_Board::maybe_migrate();
		}
		do_action( 'karo_kit_activate' );
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CRON_DAILY );
		do_action( 'karo_kit_deactivate' );
	}
}
