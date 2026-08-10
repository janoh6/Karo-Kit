<?php
/**
 * Etch module — structure-panel move arrows.
 *
 * Ported from the standalone "Etch Structure Move Arrows" plugin (v1.1.1).
 *
 * Adds hover-reveal arrows to each row of Etch's structure panel so blocks can
 * be reordered without dragging: up/down on hover (reorder among siblings),
 * outdent/indent after a dwell delay (change nesting depth).
 *
 * Built entirely on Etch's public block API. There is no container query in
 * that API, so "can this block accept children?" is a heuristic — backstopped
 * by catching WRONG_BLOCK_TYPE on the move itself, which leaves the block in
 * place. A wrong guess is a no-op, never a corrupted tree.
 *
 * @package Karo_Kit\Etch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Karo_Kit_Etch_Structure {

	const ENABLED_OPT   = 'karo_kit_etch_structure_on';
	const DWELL_OPT     = 'karo_kit_etch_structure_dwell';
	const PLACEMENT_OPT = 'karo_kit_etch_structure_placement';
	const DISABLED_OPT  = 'karo_kit_etch_structure_show_disabled';

	/** Capability to use the arrows — the same one Etch requires to build. */
	const CAP = 'edit_posts';

	const PLACEMENTS = array( 'prepend', 'append' );

	public static function init(): void {
		if ( ! self::enabled() ) {
			return;
		}
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/** Is the feature switched on? Defaults to on — it is additive and reversible. */
	public static function enabled(): bool {
		return (bool) get_option( self::ENABLED_OPT, 1 );
	}

	public static function dwell(): int {
		$ms = (int) get_option( self::DWELL_OPT, 700 );
		return $ms >= 0 ? $ms : 700;
	}

	public static function placement(): string {
		$p = (string) get_option( self::PLACEMENT_OPT, 'prepend' );
		return in_array( $p, self::PLACEMENTS, true ) ? $p : 'prepend';
	}

	public static function show_disabled(): bool {
		return (bool) get_option( self::DISABLED_OPT, 0 );
	}

	/**
	 * The Etch builder is a front-end route, so this is a front-end enqueue
	 * gated on capability — ordinary visitors never receive the scripts.
	 *
	 * The standalone plugin also enqueued on every admin screen "in case a
	 * future Etch build loads the builder there". That is dropped: it shipped
	 * three scripts to every wp-admin page for every editor, and the boot
	 * script would poll ~10s before warning to the console each time. If Etch
	 * ever moves into wp-admin, add the hook back then.
	 */
	public static function enqueue_assets(): void {
		if ( ! is_user_logged_in() || ! current_user_can( self::CAP ) ) {
			return;
		}

		/** Filter whether the structure arrows load for this request. */
		if ( ! apply_filters( 'karo_kit_etch_structure_should_enqueue', true ) ) {
			return;
		}

		// Dependency order: logic -> render -> boot.
		wp_enqueue_script( 'karo-kit-etch-structure', KARO_KIT_URL . 'assets/etch/structure.js', array(), KARO_KIT_VER, true );
		wp_enqueue_script( 'karo-kit-etch-structure-render', KARO_KIT_URL . 'assets/etch/structure-render.js', array( 'karo-kit-etch-structure' ), KARO_KIT_VER, true );
		wp_enqueue_script( 'karo-kit-etch-structure-boot', KARO_KIT_URL . 'assets/etch/structure-boot.js', array( 'karo-kit-etch-structure-render' ), KARO_KIT_VER, true );

		// wp_localize_script() stringifies scalars, which would turn the delay
		// into "700" and the boolean into "1"/"" — so hand over real JSON.
		wp_add_inline_script(
			'karo-kit-etch-structure-boot',
			'window.KaroKitEtchStructureData = ' . wp_json_encode( array(
				'dwellDelay'          => self::dwell(),
				'placement'           => self::placement(),
				'showDisabledOnDwell' => self::show_disabled(),
			) ) . ';',
			'before'
		);
	}
}
