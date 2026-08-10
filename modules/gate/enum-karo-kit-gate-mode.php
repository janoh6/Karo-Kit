<?php
/**
 * Gate maintenance mode — typed set of the two gate behaviours.
 *
 * @package Karo_Kit\Gate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

enum Karo_Kit_Gate_Mode: string {

	case Maintenance = 'maintenance';
	case ComingSoon  = 'comingsoon';

	/** Admin-facing label. */
	public function label(): string {
		return match ( $this ) {
			self::Maintenance => __( 'Maintenance (503)', 'karo-kit' ),
			self::ComingSoon  => __( 'Coming soon (200)', 'karo-kit' ),
		};
	}

	/** Resolve a stored option value to a case, defaulting to Maintenance. */
	public static function from_option( mixed $value ): self {
		return self::tryFrom( (string) $value ) ?? self::Maintenance;
	}
}
