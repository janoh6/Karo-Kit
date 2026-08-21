<?php
/**
 * Contract every Karo Kit module satisfies. Every method is an instance
 * method, including id() -- a static id() cannot survive StaticModuleAdapter,
 * which wraps a different underlying class per instance.
 *
 * renderPage() and optionGroup() exist here because the admin shell needs
 * both to render a module's settings tab and to know which settings group
 * an option belongs to -- carried over from the current Karo_Kit_Module
 * base class, not new surface area.
 *
 * @package Karo_Kit
 */

namespace KaroKit\Core\Module;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface Module {

	/** Machine id, e.g. 'gate'. */
	public function id(): string;

	/** Human label shown on the settings tab. */
	public function label(): string;

	/** Settings group this module's options register under, e.g. 'karo_kit_gate'. */
	public function optionGroup(): string;

	/** @return \KaroKit\Core\Options\Option[] */
	public function options(): array;

	/** Wire this module's hooks. Called on plugins_loaded. */
	public function boot(): void;

	/** Render this module's settings tab. */
	public function renderPage(): void;

	/** @return array<int,array<string,mixed>> Dashboard status cards for this module. */
	public function dashboardGroups(): array;

	/** @return array<string,string> Sub-section id => label, for modules with more than one top-level tab. */
	public function navSections(): array;
}
