<?php
/**
 * Etch module — builder integration.
 *
 * Two concerns live here:
 *  - Template Board: replaces Etch's native Templates screen with a board view
 *    (columns, reorder, search, thumbnails, graph), delegating create/delete/
 *    open back to Etch's own flow.
 *  - Reference: the dynamic-data bindings and shortcodes other modules expose
 *    to the Etch builder.
 *
 * Inert without Etch: the board's scripts self-gate on window.etch, and the
 * REST routes simply go unused.
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
		Karo_Kit_Etch_Settings::init();
	}

	public static function render_page(): void {
		Karo_Kit_Etch_Settings::render_page();
	}

	/** @inheritDoc */
	public static function dashboard_groups(): array {
		$on     = Karo_Kit_Etch_Board::enabled();
		$status = array(
			array(
				'label' => __( 'Template Board', 'karo-kit' ),
				'value' => $on ? __( 'On', 'karo-kit' ) : __( 'Off', 'karo-kit' ),
				'on'    => $on,
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
