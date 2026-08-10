<?php
/**
 * Gate module — hide the WordPress login/admin behind a secret word.
 *
 * SECURITY NOTE: this is *obscurity*, not authentication. It cuts automated
 * traffic to wp-login.php but only matters alongside the rate limiting in this
 * module. Recovery if the secret is lost — add to wp-config.php:
 *
 *   define( 'KARO_KIT_DISABLE_HIDE_LOGIN', true );
 *
 * ...or deactivate the plugin.
 *
 * How it works: visiting /{secret} sets a short-lived session key cookie and
 * forwards to the real login. Direct requests to wp-login.php / wp-admin
 * without that key return 404. Transactional actions (logout, password-reset
 * links) are allowed through so nothing breaks.
 *
 * @package Karo_Kit\Gate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Karo_Kit_Gate_Login_Url {

	const COOKIE = 'karo_kit_gate_key';

	public static function init(): void {
		if ( ! self::active() ) {
			return; // zero overhead when off.
		}
		add_action( 'wp_loaded', array( __CLASS__, 'handle' ) );
		add_action( 'template_redirect', array( __CLASS__, 'gate_login_page' ) );
	}

	/** Feature enabled, slug set, and not disabled via the wp-config hatch. */
	public static function active(): bool {
		if ( defined( 'KARO_KIT_DISABLE_HIDE_LOGIN' ) && KARO_KIT_DISABLE_HIDE_LOGIN ) {
			return false;
		}
		return (bool) get_option( 'karo_kit_gate_hide_login' ) && '' !== self::slug();
	}

	public static function slug(): string {
		return (string) get_option( 'karo_kit_gate_login_slug', '' );
	}

	public static function handle(): void {
		// 1) The secret word -> grant a session key, forward to the login page.
		//    Prefer the custom (Etch) login page; fall back to wp-login.php.
		if ( self::current_path() === self::slug() ) {
			self::set_key();
			wp_safe_redirect( self::login_destination() );
			exit;
		}

		$has_key = self::has_key();

		// 2) Direct wp-login.php without the key -> 404, except transactional
		//    actions that must keep working (email reset links, logout, etc.).
		if ( isset( $GLOBALS['pagenow'] ) && 'wp-login.php' === $GLOBALS['pagenow'] ) {
			$action  = isset( $_REQUEST['action'] ) ? sanitize_key( $_REQUEST['action'] ) : '';
			$allowed = array( 'logout', 'rp', 'resetpass', 'postpass', 'confirmaction' );
			if ( ! $has_key && ! in_array( $action, $allowed, true ) ) {
				self::deny();
			}
			return;
		}

		// 3) wp-admin for logged-out users without the key -> 404, so the login
		//    URL isn't leaked via WordPress's usual auth redirect. AJAX and
		//    logged-in users pass straight through.
		if ( is_admin() && ! wp_doing_ajax() && ! is_user_logged_in() && ! $has_key ) {
			self::deny();
		}
	}

	/**
	 * Gate the custom login page behind the secret key, so it can only be
	 * reached by first visiting /{secret}. Without this, the custom login page
	 * is a public URL that bypasses the secret entirely.
	 */
	public static function gate_login_page(): void {
		$login_id = (int) get_option( 'karo_kit_gate_login_page' );
		if ( ! $login_id || get_queried_object_id() !== $login_id ) {
			return; // not the login page (or none set).
		}
		if ( self::has_key() || is_user_logged_in() ) {
			return; // arrived via the secret word, or already signed in.
		}
		self::deny();
	}

	/** Where the secret word sends you: custom login page if set, else wp-login. */
	private static function login_destination(): string {
		$login_id = (int) get_option( 'karo_kit_gate_login_page' );
		if ( $login_id ) {
			$url = get_permalink( $login_id );
			if ( $url ) {
				return $url;
			}
		}
		return site_url( 'wp-login.php' );
	}

	/* ---- Session key (cookie) ------------------------------------------- */

	private static function key_hash(): string {
		return hash_hmac( 'sha256', 'karo_kit_login', wp_salt( 'auth' ) . self::slug() );
	}

	private static function has_key(): bool {
		$cookie = isset( $_COOKIE[ self::COOKIE ] ) ? (string) $_COOKIE[ self::COOKIE ] : '';
		return '' !== $cookie && hash_equals( self::key_hash(), $cookie );
	}

	private static function set_key(): void {
		setcookie( self::COOKIE, self::key_hash(), array(
			'expires'  => 0, // session cookie — clears on browser close.
			'path'     => ( defined( 'COOKIEPATH' ) && COOKIEPATH ) ? COOKIEPATH : '/',
			'domain'   => ( defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ) ? COOKIE_DOMAIN : '',
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		) );
	}

	/* ---- Helpers --------------------------------------------------------- */

	/** Request path relative to the site root, no query string, trimmed. */
	private static function current_path(): string {
		$uri  = (string) ( wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ) ?? '' );
		$home = (string) ( wp_parse_url( home_url(), PHP_URL_PATH ) ?? '' );
		if ( '' !== $home && '/' !== $home && str_starts_with( $uri, $home ) ) {
			$uri = substr( $uri, strlen( $home ) );
		}
		return trim( $uri, '/' );
	}

	private static function deny(): void {
		Karo_Kit_Log::add(
			'blocked',
			sprintf(
				/* translators: %s: requested path */
				__( 'Blocked /%s', 'karo-kit' ),
				self::current_path()
			)
		);

		$page_id  = (int) get_option( 'karo_kit_gate_denied_page' );
		$login_id = (int) get_option( 'karo_kit_gate_login_page' );

		// A page is chosen -> redirect there (e.g. the homepage), shown normally.
		// Guard against picking the login page itself, which would loop.
		if ( $page_id && $page_id !== $login_id ) {
			$url = get_permalink( $page_id );
			if ( $url ) {
				wp_safe_redirect( $url );
				exit;
			}
		}

		// Otherwise render the site's real 404 template in place (Etch's, via
		// template_include), so a blocked URL looks like any not-found page.
		global $wp_query;
		if ( $wp_query instanceof WP_Query ) {
			$wp_query->set_404();
		}
		status_header( 404 );
		nocache_headers();

		$template = apply_filters( 'template_include', get_query_template( '404' ) );
		if ( $template && file_exists( $template ) ) {
			include $template;
			exit;
		}

		// Fallback if no template resolves.
		wp_die(
			esc_html__( 'Page not found.', 'karo-kit' ),
			esc_html__( 'Not found', 'karo-kit' ),
			array( 'response' => 404 )
		);
	}
}
