<?php
/**
 * Gate module — front-end auth: login, registration, and wp-login routing.
 *
 * @package Karo_Kit\Gate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Karo_Kit_Gate_Auth {

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'handle_login' ) );
		add_action( 'init', array( __CLASS__, 'handle_register' ) );

		add_action( 'login_init', array( __CLASS__, 'redirect_wp_login' ) );
		add_filter( 'login_url', array( __CLASS__, 'filter_login_url' ), 10, 3 );
		add_filter( 'lostpassword_url', array( __CLASS__, 'filter_lostpassword_url' ), 10, 2 );
		add_filter( 'option_users_can_register', array( __CLASS__, 'filter_users_can_register' ) );
	}

	/**
	 * Make WordPress's own registration screen agree when this toggle says
	 * closed. redirect_wp_login() only sends wp-login.php's ?action=register
	 * to our own page when a login page *and* a register page are both
	 * chosen; anywhere short of that, the native screen was still reachable
	 * and governed only by core's "Anyone can register" — so turning this
	 * option off did not reliably close registration site-wide.
	 *
	 * One-directional on purpose: this only forces the native check to
	 * *false*, never to true. Whether core's own setting is on is this site's
	 * own decision, independent of whether our form is enabled — a visitor
	 * hitting the native screen while our form is on shouldn't get waved
	 * through just because we're enabled.
	 *
	 * Filters the read only. Nothing is written to users_can_register, so
	 * deactivating Karo Kit leaves the site's own original setting exactly as
	 * it was, rather than depending on this filter having ever run.
	 */
	public static function filter_users_can_register( $value ) {
		return get_option( 'karo_kit_gate_registration_on' ) ? $value : false;
	}

	/* ---- Login ----------------------------------------------------------- */

	public static function handle_login(): void {
		if ( empty( $_POST['karo_kit_login_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( $_POST['karo_kit_login_nonce'], 'karo_kit_login' ) ) {
			self::redirect_back( 'login', 'badnonce' );
		}

		$login = sanitize_user( wp_unslash( $_POST['log'] ?? '' ) );

		// Rate limit. Scoped to the account being tried as well as the address,
		// so one attacker can't lock out everyone sharing an office connection.
		if ( Karo_Kit_Gate_Security::is_locked( 'login', $login ) ) {
			self::redirect_back( 'login', 'locked' );
		}

		$creds = array(
			'user_login'    => $login,
			'user_password' => (string) ( $_POST['pwd'] ?? '' ),
			'remember'      => ! empty( $_POST['rememberme'] ),
		);

		$user = wp_signon( $creds, is_ssl() );

		if ( is_wp_error( $user ) ) {
			Karo_Kit_Gate_Security::register_failure( 'login', $login );
			self::redirect_back( 'login', 'login_failed' );
		}

		Karo_Kit_Gate_Security::clear( 'login', $login );

		$redirect = ! empty( $_POST['redirect_to'] )
			? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) )
			: Karo_Kit_Gate::url( 'account' );

		wp_safe_redirect( $redirect );
		exit;
	}

	/* ---- Registration ---------------------------------------------------- */

	public static function handle_register(): void {
		if ( empty( $_POST['karo_kit_register_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( $_POST['karo_kit_register_nonce'], 'karo_kit_register' ) ) {
			self::redirect_back( 'register', 'badnonce' );
		}

		// Registration must be enabled here — independent of the WordPress core
		// "Anyone can register" setting.
		if ( ! get_option( 'karo_kit_gate_registration_on' ) ) {
			self::redirect_back( 'register', 'reg_closed' );
		}

		// Rate limit.
		if ( Karo_Kit_Gate_Security::is_locked( 'register' ) ) {
			self::redirect_back( 'register', 'locked' );
		}

		// Honeypot: real users leave this empty. A hit is a bot -> counts.
		if ( ! empty( $_POST['karo_kit_hp'] ) ) {
			Karo_Kit_Gate_Security::register_failure( 'register' );
			self::redirect_back( 'register', 'reg_generic' );
		}

		$email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
		$user  = sanitize_user( wp_unslash( $_POST['username'] ?? '' ) );
		$pass  = (string) ( $_POST['password'] ?? '' );

		// Format/length checks are safe to be specific — they reveal nothing
		// about which accounts exist, and don't count toward lockout (a real
		// user's typo shouldn't lock them out).
		if ( ! is_email( $email ) ) {
			self::redirect_back( 'register', 'reg_email_format' );
		}
		if ( strlen( $user ) < 3 ) {
			self::redirect_back( 'register', 'reg_user_short' );
		}
		if ( strlen( $pass ) < 8 ) {
			self::redirect_back( 'register', 'reg_pass' );
		}

		// Existence collisions -> ONE generic message so an attacker can't tell
		// whether the email or the username already existed. Counts toward
		// lockout, since probing for valid accounts is the abuse case.
		if ( email_exists( $email ) || username_exists( $user ) ) {
			Karo_Kit_Gate_Security::register_failure( 'register' );
			self::redirect_back( 'register', 'reg_generic' );
		}

		$user_id = wp_create_user( $user, $pass, $email );
		if ( is_wp_error( $user_id ) ) {
			Karo_Kit_Gate_Security::register_failure( 'register' );
			self::redirect_back( 'register', 'reg_generic' );
		}

		Karo_Kit_Gate_Security::clear( 'register' );

		Karo_Kit_Log::add(
			'registered',
			sprintf(
				/* translators: %s: new account's username */
				__( 'Registered — %s', 'karo-kit' ),
				$user
			)
		);

		// Role is taken from Settings -> General default; set explicitly if needed:
		// ( new WP_User( $user_id ) )->set_role( 'subscriber' );

		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, true, is_ssl() );

		wp_safe_redirect( Karo_Kit_Gate::url( 'account' ) );
		exit;
	}

	/** Redirect back to a chosen page carrying a message code. */
	private static function redirect_back( string $page_key, string $code ): void {
		$url = add_query_arg( 'karo_msg', rawurlencode( $code ), Karo_Kit_Gate::url( $page_key ) );
		wp_safe_redirect( $url );
		exit;
	}

	/* ---- wp-login.php routing (with safety guards) ----------------------- */

	public static function redirect_wp_login(): void {
		// When hide-login is active it governs wp-login.php access instead.
		if ( Karo_Kit_Gate_Login_Url::active() ) {
			return;
		}

		$login_id = (int) get_option( 'karo_kit_gate_login_page' );
		if ( ! $login_id ) {
			return; // nothing chosen -> never touch wp-login.php (no lock-out).
		}

		// Safety valve: /wp-login.php?karo=default always shows the real page.
		if ( isset( $_GET['karo'] ) && 'default' === $_GET['karo'] ) {
			return;
		}

		$action = isset( $_REQUEST['action'] ) ? $_REQUEST['action'] : 'login';

		// Let core own everything except the plain login view.
		$passthrough = array( 'logout', 'lostpassword', 'retrievepassword',
			'resetpass', 'rp', 'postpass', 'confirmaction', 'confirm_admin_email' );
		if ( in_array( $action, $passthrough, true ) ) {
			return;
		}

		// Send register to the register page if one is chosen.
		if ( 'register' === $action ) {
			$reg_id = (int) get_option( 'karo_kit_gate_register_page' );
			if ( $reg_id ) {
				wp_safe_redirect( get_permalink( $reg_id ) );
				exit;
			}
			return;
		}

		// Never intercept form POSTs to wp-login.php.
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] ) {
			return;
		}

		$target = get_permalink( $login_id );
		if ( ! empty( $_REQUEST['redirect_to'] ) ) {
			$target = add_query_arg( 'redirect_to',
				rawurlencode( esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) ) ), $target );
		}
		wp_safe_redirect( $target );
		exit;
	}

	public static function filter_login_url( $login_url, $redirect, $force_reauth ): string {
		$id  = (int) get_option( 'karo_kit_gate_login_page' );
		$url = $id ? get_permalink( $id ) : '';
		if ( ! $url ) {
			return (string) $login_url; // page unset/trashed -> leave core URL.
		}
		if ( $redirect ) {
			$url = add_query_arg( 'redirect_to', rawurlencode( $redirect ), $url );
		}
		return $url;
	}

	public static function filter_lostpassword_url( $url, $redirect ): string {
		$id     = (int) get_option( 'karo_kit_gate_lostpw_page' );
		$custom = $id ? get_permalink( $id ) : '';
		return $custom ?: (string) $url;
	}
}
