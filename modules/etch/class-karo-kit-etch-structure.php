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

	const PLACEMENTS = array( 'prepend', 'append' );

	/**
	 * Nothing to hook: the arrows are pure front-end behaviour, delivered by
	 * the module's loader. See loader_bundle().
	 */
	public static function init(): void {}

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
	 * What the loader should fetch once it sees the Etch API, or null if the
	 * arrows shouldn't run for this request.
	 *
	 * Not enqueued directly — see assets/etch/loader.js for why. The options
	 * travel as real JSON rather than through wp_localize_script(), which
	 * stringifies scalars and would turn the delay into "700" and the boolean
	 * into "1"/"".
	 *
	 * The standalone plugin also enqueued on every admin screen "in case a
	 * future Etch build loads the builder there". That is dropped: it shipped
	 * three scripts to every wp-admin page for every editor. If Etch ever moves
	 * into wp-admin, add it back then.
	 */
	public static function loader_bundle(): ?array {
		if ( ! self::enabled() ) {
			return null;
		}

		/** Filter whether the structure arrows load for this request. */
		if ( ! apply_filters( 'karo_kit_etch_structure_should_enqueue', true ) ) {
			return null;
		}


		return array(
			'data'    => array(
				'name'  => 'KaroKitEtchStructureData',
				'value' => array(
					'dwellDelay'          => self::dwell(),
					'placement'           => self::placement(),
					'showDisabledOnDwell' => self::show_disabled(),
				),
			),
			// Dependency order: logic -> render -> boot.
			'scripts' => array(
				Karo_Kit_Etch::asset_url( 'assets/etch/structure.js' ),
				Karo_Kit_Etch::asset_url( 'assets/etch/structure-render.js' ),
				Karo_Kit_Etch::asset_url( 'assets/etch/structure-boot.js' ),
			),
		);
	}
}
