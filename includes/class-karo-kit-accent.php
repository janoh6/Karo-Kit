<?php
/**
 * Karo Kit — accent colour, sourced from Automatic.css.
 *
 * Most people running this kit already have a palette defined in ACSS for the
 * site they're building. Rather than ask them to pick a colour twice, the
 * dashboard can borrow one of those families and re-tint itself to match the
 * build it belongs to.
 *
 * Only the accent is borrowed. Surfaces stay neutral on purpose: they carry
 * the light/dark themes, and letting a brand palette drive them is how an
 * admin screen ends up unreadable.
 *
 * @package Karo_Kit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Karo_Kit_Accent {

	const SOURCE_OPT = 'karo_kit_accent_source';

	/** A hand-picked hex, used when SOURCE_OPT is 'custom'. */
	const CUSTOM_OPT = 'karo_kit_accent_custom';

	/** Where ACSS keeps its settings. Read directly — no dependency on its classes. */
	const ACSS_OPTION = 'automatic_css_settings';

	/** The kit's own colour, used when no source is chosen or ACSS is absent. */
	const DEFAULT_ACCENT = '#ffb020';

	/**
	 * ACSS colour families worth borrowing, in the order they're offered.
	 * Its semantic colours (success/danger/warning/info) are deliberately
	 * excluded — they mean something, and shouldn't become UI furniture.
	 */
	const FAMILIES = array(
		'primary'   => 'Primary',
		'secondary' => 'Secondary',
		'tertiary'  => 'Tertiary',
		'accent'    => 'Accent',
		'base'      => 'Base',
	);

	public static function init(): void {
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
	}

	public static function register(): void {
		register_setting( Karo_Kit::OPTION_GROUP, self::SOURCE_OPT, array(
			'type'              => 'string',
			'sanitize_callback' => array( __CLASS__, 'sanitize_source' ),
			'default'           => '',
		) );
		register_setting( Karo_Kit::OPTION_GROUP, self::CUSTOM_OPT, array(
			'type'              => 'string',
			'sanitize_callback' => array( __CLASS__, 'normalise_hex' ),
			'default'           => '',
		) );
	}

	public static function sanitize_source( $value ): string {
		$value = sanitize_key( (string) $value );
		if ( 'custom' === $value || isset( self::FAMILIES[ $value ] ) ) {
			return $value;
		}
		return '';
	}

	/* ---- Reading ACSS ----------------------------------------------------- */

	public static function acss_active(): bool {
		return is_array( get_option( self::ACSS_OPTION ) );
	}

	/**
	 * Families ACSS actually has a usable colour for, as key => [label, hex].
	 *
	 * ACSS stores each family two ways: a "color-<family>" hex, and
	 * "<family>-l/c/h-oklch" components. The CSS it actually ships is built
	 * from the OKLCH components — editing a colour in ACSS's picker updates
	 * those, not the hex field, so the hex is only ever the factory default.
	 * The OKLCH triplet is read first for that reason, with the hex used
	 * only when it's missing (an older ACSS version, say).
	 *
	 * Families switched off in ACSS generate no variables on the site, so
	 * borrowing one would mean matching a colour the build doesn't use; those
	 * are skipped too.
	 *
	 * @return array<string,array{label:string,hex:string}>
	 */
	public static function available(): array {
		$settings = get_option( self::ACSS_OPTION );
		if ( ! is_array( $settings ) ) {
			return array();
		}

		$out = array();
		foreach ( self::FAMILIES as $key => $label ) {
			if ( 'on' !== ( $settings[ 'option-' . $key . '-clr' ] ?? '' ) ) {
				continue;
			}
			$hex = self::oklch_hex( $settings, $key );
			if ( '' === $hex ) {
				$hex = self::normalise_hex( $settings[ 'color-' . $key ] ?? '' );
			}
			if ( '' === $hex ) {
				continue;
			}
			$out[ $key ] = array( 'label' => $label, 'hex' => $hex );
		}
		return $out;
	}

	/**
	 * The family's colour as stored in ACSS's OKLCH components, converted to
	 * hex. '' if any component is missing or not numeric.
	 */
	private static function oklch_hex( array $settings, string $key ): string {
		$l = $settings[ $key . '-l-oklch' ] ?? null;
		$c = $settings[ $key . '-c-oklch' ] ?? null;
		$h = $settings[ $key . '-h-oklch' ] ?? null;
		if ( ! is_numeric( $l ) || ! is_numeric( $c ) || ! is_numeric( $h ) ) {
			return '';
		}
		return self::from_oklch( (float) $l, (float) $c, (float) $h );
	}

	/**
	 * OKLCH → sRGB hex, the inverse of the matrices ACSS uses to go the other
	 * way (its PHPColors library only converts hex to OKLCH, not back).
	 * Standard OKLab transform (Björn Ottosson) — out-of-gamut channels are
	 * clamped rather than gamut-mapped, which is fine for a UI accent.
	 */
	public static function from_oklch( float $l, float $c, float $h ): string {
		$hr = deg2rad( $h );
		$a  = $c * cos( $hr );
		$b  = $c * sin( $hr );

		$l_ = $l + 0.3963377774 * $a + 0.2158037573 * $b;
		$m_ = $l - 0.1055613458 * $a - 0.0638541728 * $b;
		$s_ = $l - 0.0894841775 * $a - 1.2914855480 * $b;

		$ll = $l_ ** 3;
		$mm = $m_ ** 3;
		$ss = $s_ ** 3;

		$r = 4.0767416621 * $ll - 3.3077115913 * $mm + 0.2309699292 * $ss;
		$g = -1.2684380046 * $ll + 2.6097574011 * $mm - 0.3413193965 * $ss;
		$bl = -0.0041960863 * $ll - 0.7034186147 * $mm + 1.7076147010 * $ss;

		$to_srgb = static function ( float $v ): int {
			$v = max( 0.0, min( 1.0, $v ) );
			$v = $v <= 0.0031308 ? $v * 12.92 : 1.055 * ( $v ** ( 1 / 2.4 ) ) - 0.055;
			return (int) round( max( 0.0, min( 1.0, $v ) ) * 255 );
		};

		return sprintf( '#%02x%02x%02x', $to_srgb( $r ), $to_srgb( $g ), $to_srgb( $bl ) );
	}

	/** The chosen source key, 'custom', or '' for the kit's own colour. */
	public static function source(): string {
		$source = (string) get_option( self::SOURCE_OPT, '' );
		if ( 'custom' === $source || isset( self::FAMILIES[ $source ] ) ) {
			return $source;
		}
		return '';
	}

	/** The hand-picked hex from the custom-colour field, or '' if unset/invalid. */
	public static function custom_hex(): string {
		return self::normalise_hex( get_option( self::CUSTOM_OPT, '' ) );
	}

	/** The accent actually in force. Falls back whenever the source can't be resolved. */
	public static function accent(): string {
		$source = self::source();
		if ( 'custom' === $source ) {
			$hex = self::custom_hex();
			return '' !== $hex ? $hex : self::DEFAULT_ACCENT;
		}
		if ( '' === $source ) {
			return self::DEFAULT_ACCENT;
		}
		$available = self::available();
		return $available[ $source ]['hex'] ?? self::DEFAULT_ACCENT;
	}

	/* ---- Colour maths ----------------------------------------------------- */

	/**
	 * Accept the shapes ACSS might store — #rgb, #rrggbb, or bare hex — and
	 * reject anything else (it also stores hsl() and var() references).
	 */
	public static function normalise_hex( $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$value = trim( $value );
		if ( ! preg_match( '/^#?([0-9a-f]{3}|[0-9a-f]{6})$/i', $value, $m ) ) {
			return '';
		}
		$hex = strtolower( $m[1] );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		return '#' . $hex;
	}

	/** @return array{0:int,1:int,2:int} */
	private static function rgb( string $hex ): array {
		$hex = ltrim( $hex, '#' );
		return array(
			(int) hexdec( substr( $hex, 0, 2 ) ),
			(int) hexdec( substr( $hex, 2, 2 ) ),
			(int) hexdec( substr( $hex, 4, 2 ) ),
		);
	}

	/** WCAG relative luminance, 0 (black) to 1 (white). */
	public static function luminance( string $hex ): float {
		$channels = array();
		foreach ( self::rgb( $hex ) as $c ) {
			$c          = $c / 255;
			$channels[] = $c <= 0.03928 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
		}
		return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
	}

	/** WCAG contrast ratio between two hex colours, 1 to 21. */
	public static function contrast( string $a, string $b ): float {
		$la = self::luminance( $a );
		$lb = self::luminance( $b );
		return ( max( $la, $lb ) + 0.05 ) / ( min( $la, $lb ) + 0.05 );
	}

	/**
	 * Text colour for sitting *on* the accent.
	 *
	 * The accent is a background — for the brand mark, the primary button, the
	 * switch. A fixed near-black works on amber and is unreadable on navy, so
	 * this picks whichever of dark/light actually contrasts.
	 */
	public static function ink( string $accent ): string {
		$dark  = '#1a1a1d';
		$light = '#ffffff';
		return self::contrast( $accent, $dark ) >= self::contrast( $accent, $light ) ? $dark : $light;
	}

	/** Hover shade: away from the ink, so light accents darken and dark ones lift. */
	public static function hover( string $accent ): string {
		$toward = '#1a1a1d' === self::ink( $accent ) ? 0 : 255; // darken vs lighten
		$out    = '';
		foreach ( self::rgb( $accent ) as $c ) {
			$out .= str_pad( dechex( (int) round( $c + ( $toward - $c ) * 0.12 ) ), 2, '0', STR_PAD_LEFT );
		}
		return '#' . $out;
	}

	public static function ring( string $accent ): string {
		[ $r, $g, $b ] = self::rgb( $accent );
		return "rgba( {$r}, {$g}, {$b}, 0.35 )";
	}

	/* ---- Output ----------------------------------------------------------- */

	/**
	 * Overrides for the accent tokens, or '' when the kit's own colour is in
	 * force (nothing to override, so nothing is emitted).
	 */
	public static function inline_css(): string {
		$accent = self::accent();
		if ( self::DEFAULT_ACCENT === $accent ) {
			return '';
		}

		return sprintf(
			'body.karo-kit-app{--kk-accent:%s;--kk-accent-hover:%s;--kk-accent-ink:%s;--kk-focus-ring:%s;}',
			$accent,
			self::hover( $accent ),
			self::ink( $accent ),
			self::ring( $accent )
		);
	}

	/* ---- Settings card ---------------------------------------------------- */

	public static function render_card(): void {
		$available = self::available();
		$source    = self::source();
		$accent    = self::accent();
		$custom    = self::custom_hex();

		echo '<div class="kk-content"><section class="kk-card">';
		echo '<div class="kk-card__header"><span class="kk-card__title">' . esc_html__( 'Accent colour', 'karo-kit' ) . '</span></div>';

		if ( $available ) {
			echo '<p class="kk-empty">' . esc_html__( 'Borrow a colour from this site\'s Automatic.css palette, or set one of your own below. Only the accent changes — surfaces stay neutral, since they carry the light and dark themes.', 'karo-kit' ) . '</p>';
		} elseif ( self::acss_active() ) {
			echo '<p class="kk-empty">' . esc_html__( 'Automatic.css is active but none of its brand colour families are switched on, so there\'s nothing to borrow yet. Enable one under Colors (Palette) there, or set a colour of your own below.', 'karo-kit' ) . '</p>';
		} else {
			echo '<p class="kk-empty">' . esc_html__( 'Automatic.css isn\'t active on this site, so there is no palette to borrow. Set a colour of your own below, or leave this on the kit\'s default.', 'karo-kit' ) . '</p>';
		}

		echo '<form action="options.php" method="post">';
		settings_fields( Karo_Kit::OPTION_GROUP );
		echo '<table class="form-table" role="presentation">';

		echo '<tr><th>' . esc_html__( 'Source', 'karo-kit' ) . '</th><td>';
		printf( '<select name="%s">', esc_attr( self::SOURCE_OPT ) );
		printf(
			'<option value="" %s>%s</option>',
			selected( $source, '', false ),
			esc_html__( 'Karo Kit default', 'karo-kit' )
		);
		foreach ( $available as $key => $family ) {
			printf(
				'<option value="%s" %s>%s — %s</option>',
				esc_attr( $key ),
				selected( $source, $key, false ),
				esc_html( $family['label'] ),
				esc_html( $family['hex'] )
			);
		}
		printf(
			'<option value="custom" %s>%s</option>',
			selected( $source, 'custom', false ),
			esc_html__( 'Custom colour', 'karo-kit' )
		);
		echo '</select>';

		printf(
			'<span class="kk-swatch" style="background:%s;color:%s" aria-hidden="true">Aa</span>',
			esc_attr( $accent ),
			esc_attr( self::ink( $accent ) )
		);

		// The accent carries text, so a poor pairing is worth flagging — the
		// choice is still theirs, it just shouldn't be silent.
		$ratio = self::contrast( $accent, self::ink( $accent ) );
		if ( $ratio < 4.5 ) {
			echo '<p class="kk-notice kk-notice--warn">' . esc_html(
				sprintf(
					/* translators: %s: contrast ratio, e.g. "3.2" */
					__( 'Text on this colour reaches only %s:1, below the 4.5:1 usually wanted for readable text. It will still be used.', 'karo-kit' ),
					number_format_i18n( $ratio, 1 )
				)
			) . '</p>';
		}
		echo '</td></tr>';

		echo '<tr><th><label for="kk-accent-custom">' . esc_html__( 'Custom colour', 'karo-kit' ) . '</label></th><td>';
		printf(
			'<input type="text" id="kk-accent-custom" class="regular-text" name="%s" value="%s" placeholder="#ffb020" maxlength="7" />',
			esc_attr( self::CUSTOM_OPT ),
			esc_attr( $custom )
		);
		echo '<p class="description">' . esc_html__( 'A hex value. Only used while Source above is set to "Custom colour".', 'karo-kit' ) . '</p>';
		echo '</td></tr>';

		echo '</table>';
		echo '<p class="submit" style="margin-top:8px">';
		submit_button( null, 'primary', 'submit', false );
		echo '</p></form>';

		echo '</section></div>';
	}
}
