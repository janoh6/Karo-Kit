<?php
/**
 * Typed reads over the Registry's declared defaults, replacing the scattered
 * (bool)/(int)/(string) casts around get_option() calls.
 *
 * @package Karo_Kit
 */

namespace KaroKit\Core\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Repository {

	public function __construct( private readonly Registry $registry ) {}

	public function bool( string $name ): bool {
		return (bool) get_option( $name, $this->defaultFor( $name, false ) );
	}

	public function int( string $name ): int {
		return (int) get_option( $name, $this->defaultFor( $name, 0 ) );
	}

	public function string( string $name ): string {
		return (string) get_option( $name, $this->defaultFor( $name, '' ) );
	}

	private function defaultFor( string $name, mixed $fallback ): mixed {
		$option = $this->registry->get( $name );
		return $option ? $option->default : $fallback;
	}
}
