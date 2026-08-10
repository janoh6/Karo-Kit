<?php
/**
 * Gate module — shortcodes for building the pages in Etch.
 *
 * @package Karo_Kit\Gate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Karo_Kit_Gate_Shortcodes {

	public static function init(): void {
		add_shortcode( 'karo_kit_field', array( __CLASS__, 'field' ) );
		add_shortcode( 'karo_kit_message', array( __CLASS__, 'message' ) );
		add_shortcode( 'karo_kit_logout', array( __CLASS__, 'logout' ) );
	}

	/**
	 * [karo_kit_field action="login"]  or  action="register"
	 * NB: $atts is left untyped — WordPress passes an empty string (not array)
	 * when a shortcode has no attributes, so typing it `array` would fatal.
	 */
	public static function field( $atts ): string {
		$atts   = shortcode_atts( array( 'action' => 'login' ), $atts );
		$action = ( 'register' === $atts['action'] ) ? 'register' : 'login';

		ob_start();
		wp_nonce_field( "karo_kit_{$action}", "karo_kit_{$action}_nonce" );

		if ( 'login' === $action && ! empty( $_GET['redirect_to'] ) ) {
			printf(
				'<input type="hidden" name="redirect_to" value="%s">',
				esc_attr( esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) )
			);
		}
		if ( 'register' === $action ) {
			echo '<input type="text" name="karo_kit_hp" class="karo-hp" tabindex="-1" autocomplete="off" aria-hidden="true">';
		}
		return (string) ob_get_clean();
	}

	/** [karo_kit_message] — renders any pending error/success notice. */
	public static function message(): string {
		if ( empty( $_GET['karo_msg'] ) ) {
			return '';
		}
		$map = array(
			'badnonce'         => __( 'Your session expired. Please try again.', 'karo-kit' ),
			'locked'           => __( 'Too many attempts. Please wait a while and try again.', 'karo-kit' ),
			'login_failed'     => __( 'Incorrect username or password.', 'karo-kit' ),
			'reg_closed'       => __( 'Registration is currently closed.', 'karo-kit' ),
			'reg_generic'      => __( 'We could not create that account. Please try different details.', 'karo-kit' ),
			'reg_email_format' => __( 'Please enter a valid email address.', 'karo-kit' ),
			'reg_user_short'   => __( 'Username must be at least 3 characters.', 'karo-kit' ),
			'reg_pass'         => __( 'Password must be at least 8 characters.', 'karo-kit' ),
		);
		$code = sanitize_key( $_GET['karo_msg'] );
		$text = $map[ $code ] ?? '';
		return $text ? '<div class="karo-kit-message" role="alert">' . esc_html( $text ) . '</div>' : '';
	}

	/** [karo_kit_logout label="Log out"] — outputs a logout link to home. */
	public static function logout( $atts ): string {
		$atts = shortcode_atts( array( 'label' => __( 'Log out', 'karo-kit' ) ), $atts );
		return '<a href="' . esc_url( wp_logout_url( home_url() ) ) . '">' . esc_html( $atts['label'] ) . '</a>';
	}
}
