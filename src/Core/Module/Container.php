<?php
/**
 * Holds every registered Module instance, keyed by id.
 *
 * @package Karo_Kit
 */

namespace KaroKit\Core\Module;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Container {

	/** @var array<string,Module> */
	private array $modules = array();

	public function register( Module $module ): void {
		$this->modules[ $module->id() ] = $module;
	}

	/** @return Module[] */
	public function modules(): array {
		return array_values( $this->modules );
	}

	public function get( string $id ): ?Module {
		return $this->modules[ $id ] ?? null;
	}
}
