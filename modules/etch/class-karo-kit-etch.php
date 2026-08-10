<?php
/**
 * Etch module — builder integration.
 *
 * Two concerns live here:
 *  - Template Board: replaces Etch's native Templates screen with a board view
 *    (columns, reorder, search, thumbnails, graph), delegating create/delete/
 *    open back to Etch's own flow.
 *  - Structure Arrows: hover-reveal up/down/outdent/indent controls in the
 *    structure panel, so blocks can be reordered without dragging.
 *  - Reference: the dynamic-data bindings and shortcodes other modules expose
 *    to the Etch builder.
 *
 * Inert without Etch: both features' scripts self-gate on window.etch, and
 * the REST routes simply go unused.
 *
 * @package Karo_Kit\Etch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Karo_Kit_Etch extends Karo_Kit_Module {

	public static function id(): string {
		return 'etch';
	}

	public static function label(): string {
		return __( 'Etch', 'karo-kit' );
	}

	public static function init(): void {
		Karo_Kit_Etch_Board::init();
		Karo_Kit_Etch_Structure::init();
		Karo_Kit_Etch_Settings::init();
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_loader' ) );
	}

	/**
	 * Ship one small loader on the front end; it pulls in the real assets only
	 * once the Etch API is present. Each feature decides whether it wants in.
	 */
	public static function enqueue_loader(): void {
		$bundles = array();
		$styles  = array();

		foreach ( array( 'Karo_Kit_Etch_Board', 'Karo_Kit_Etch_Structure' ) as $feature ) {
			$bundle = $feature::loader_bundle();
			if ( ! $bundle ) {
				continue;
			}
			if ( ! empty( $bundle['styles'] ) ) {
				$styles = array_merge( $styles, $bundle['styles'] );
				unset( $bundle['styles'] );
			}
			$bundles[] = $bundle;
		}

		if ( ! $bundles ) {
			return; // nothing enabled for this visitor — not even the loader
		}

		$js = KARO_KIT_DIR . 'assets/etch/loader.js';
		wp_enqueue_script(
			'karo-kit-etch-loader',
			KARO_KIT_URL . 'assets/etch/loader.js',
			array(),
			file_exists( $js ) ? (string) filemtime( $js ) : KARO_KIT_VER,
			true
		);
		wp_add_inline_script(
			'karo-kit-etch-loader',
			'window.KaroKitEtchLoader = ' . wp_json_encode( array(
				'styles'  => $styles,
				'bundles' => $bundles,
			) ) . ';',
			'before'
		);
	}

	public static function render_page(): void {
		Karo_Kit_Etch_Settings::render_page();
	}

	/**
	 * @inheritDoc
	 *
	 * Board column order and per-template statuses are keyed by template slug,
	 * so they port cleanly. Thumbnails don't travel — they're generated files
	 * that regenerate on the target site.
	 */
	public static function export_map(): array {
		return array(
			Karo_Kit_Etch_Board::ENABLED_OPT       => 'value',
			Karo_Kit_Etch_Board::THRESHOLD_OPT     => 'value',
			Karo_Kit_Etch_Board::ORDER_OPT         => 'value',
			Karo_Kit_Etch_Board::STATUS_OPT        => 'value',
			Karo_Kit_Etch_Structure::ENABLED_OPT   => 'value',
			Karo_Kit_Etch_Structure::DWELL_OPT     => 'value',
			Karo_Kit_Etch_Structure::PLACEMENT_OPT => 'value',
			Karo_Kit_Etch_Structure::DISABLED_OPT  => 'value',
		);
	}

	/** @inheritDoc */
	public static function export_labels(): array {
		return array(
			Karo_Kit_Etch_Board::ENABLED_OPT       => __( 'Template Board', 'karo-kit' ),
			Karo_Kit_Etch_Board::THRESHOLD_OPT     => __( 'Thumbnail refresh threshold', 'karo-kit' ),
			Karo_Kit_Etch_Board::ORDER_OPT         => __( 'Board column order', 'karo-kit' ),
			Karo_Kit_Etch_Board::STATUS_OPT        => __( 'Template statuses', 'karo-kit' ),
			Karo_Kit_Etch_Structure::ENABLED_OPT   => __( 'Structure arrows', 'karo-kit' ),
			Karo_Kit_Etch_Structure::DWELL_OPT     => __( 'Dwell delay', 'karo-kit' ),
			Karo_Kit_Etch_Structure::PLACEMENT_OPT => __( 'Arrow position', 'karo-kit' ),
			Karo_Kit_Etch_Structure::DISABLED_OPT  => __( 'Show unavailable moves', 'karo-kit' ),
		);
	}

	/** @inheritDoc */
	public static function dashboard_groups(): array {
		$on        = Karo_Kit_Etch_Board::enabled();
		$arrows_on = Karo_Kit_Etch_Structure::enabled();
		$status    = array(
			array(
				'label' => __( 'Template Board', 'karo-kit' ),
				'value' => $on ? __( 'On', 'karo-kit' ) : __( 'Off', 'karo-kit' ),
				'on'    => $on,
			),
			array(
				'label' => __( 'Structure arrows', 'karo-kit' ),
				'value' => $arrows_on ? __( 'On', 'karo-kit' ) : __( 'Off', 'karo-kit' ),
				'on'    => $arrows_on,
			),
		);

		// Counts only mean something while the board is running them.
		if ( $on ) {
			$templates = function_exists( 'get_block_templates' )
				? count( get_block_templates( array(), 'wp_template' ) )
				: 0;
			$thumbs    = Karo_Kit_Etch_Board::thumb_count();

			$status[] = array(
				'label' => __( 'Templates', 'karo-kit' ),
				'value' => (string) $templates,
				'on'    => $templates > 0,
			);
			$status[] = array(
				'label' => __( 'Thumbnails', 'karo-kit' ),
				'value' => (string) $thumbs,
				'on'    => $thumbs > 0,
			);
		}

		return array(
			array(
				'label'   => __( 'Etch', 'karo-kit' ),
				'section' => '',
				'status'  => $status,
				'pages'   => array(),
			),
		);
	}
}
