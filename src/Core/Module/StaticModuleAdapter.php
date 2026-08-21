<?php
/**
 * Wraps an existing static module class so it satisfies Module without
 * changing it. This is what lets Gate and Etch keep running entirely
 * unmodified in this release -- porting either to a real Module instance is
 * v0.18.0's and v0.19.0's job, not this one's.
 *
 * @package Karo_Kit
 */

namespace KaroKit\Core\Module;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class StaticModuleAdapter implements Module {

	/** @param class-string $staticClass A class exposing the same static methods Karo_Kit_Module's subclasses always have. */
	public function __construct( private readonly string $staticClass ) {}

	public function id(): string {
		return ( $this->staticClass )::id();
	}

	public function label(): string {
		return ( $this->staticClass )::label();
	}

	public function optionGroup(): string {
		return ( $this->staticClass )::option_group();
	}

	public function options(): array {
		return ( $this->staticClass )::options();
	}

	public function boot(): void {
		( $this->staticClass )::init();
	}

	public function renderPage(): void {
		( $this->staticClass )::render_page();
	}

	public function dashboardGroups(): array {
		return ( $this->staticClass )::dashboard_groups();
	}

	public function navSections(): array {
		return ( $this->staticClass )::nav_sections();
	}
}
