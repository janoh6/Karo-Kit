<?php
/**
 * Etch module — sidebar collapse/expand tabs.
 *
 * Ported from the standalone "Etch Sidebar Toggle" snippet.
 *
 * Adds a draggable tab to each edge of the builder that collapses the panel
 * beside it, with Alt+[ , Alt+] and Alt+\ as shortcuts. The collapse works by
 * constraining Etch's own .etch-sidebar element, so a panel reopens at
 * whatever width it was resized to.
 *
 * @package Karo_Kit\Etch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Karo_Kit_Etch_Sidebar {

	const ENABLED_OPT  = 'karo_kit_etch_sidebar_on';
	const REMEMBER_OPT = 'karo_kit_etch_sidebar_remember';

	/** Nothing to hook — this is front-end behaviour delivered by the loader. */
	public static function init(): void {}

	/** Is the feature switched on? Defaults to on; it is additive and reversible. */
	public static function enabled(): bool {
		return (bool) get_option( self::ENABLED_OPT, 1 );
	}

	/** Should collapsed state and tab position survive a reload? */
	public static function remember(): bool {
		return (bool) get_option( self::REMEMBER_OPT, 1 );
	}

	/**
	 * What the loader should fetch, or null if the tabs shouldn't run.
	 *
	 * Capability and the builder-route test are already applied by
	 * Karo_Kit_Etch::enqueue_loader(), so this only decides the feature switch.
	 */
	public static function loader_bundle(): ?array {
		if ( ! self::enabled() ) {
			return null;
		}

		/** Filter whether the sidebar tabs load for this request. */
		if ( ! apply_filters( 'karo_kit_etch_sidebar_should_enqueue', true ) ) {
			return null;
		}

		$v = '?ver=' . rawurlencode( KARO_KIT_VER );

		return array(
			'styles'  => array( KARO_KIT_URL . 'assets/etch/sidebar.css' . $v ),
			'data'    => array(
				'name'  => 'KaroKitEtchSidebarData',
				'value' => array( 'remember' => self::remember() ),
			),
			'scripts' => array( KARO_KIT_URL . 'assets/etch/sidebar.js' . $v ),
		);
	}
}
