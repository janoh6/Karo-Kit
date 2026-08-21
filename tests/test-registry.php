<?php
/**
 * Registry: a flat collection of Option declarations, and every
 * cross-cutting view (settings, export, uninstall, seeding) derived from it.
 *
 * @package Karo_Kit
 */

use KaroKit\Core\Options\Option;
use KaroKit\Core\Options\Registry;

class Test_Registry extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( 'karo_kit_test_a' );
		delete_option( 'karo_kit_test_b' );
		delete_option( 'karo_kit_test_c' );
	}

	private function registry(): Registry {
		$registry = new Registry();
		$registry->add( new Option( 'karo_kit_test_a', 'bool', default: false, label: 'A', export: true ) );
		$registry->add( new Option( 'karo_kit_test_b', 'int', default: 5, label: 'B', setting: false, export: true, uninstall: false ) );
		$registry->add( new Option( 'karo_kit_test_c', 'page', default: 0 ) ); // setting:true, export:false (default) -- a page selector
		return $registry;
	}

	public function test_get_and_has(): void {
		$registry = $this->registry();

		$this->assertTrue( $registry->has( 'karo_kit_test_a' ) );
		$this->assertFalse( $registry->has( 'karo_kit_test_missing' ) );
		$this->assertSame( 'bool', $registry->get( 'karo_kit_test_a' )->type );
		$this->assertNull( $registry->get( 'karo_kit_test_missing' ) );
	}

	public function test_all_returns_every_added_option(): void {
		$registry = $this->registry();
		$this->assertCount( 3, $registry->all() );
	}

	public function test_setting_names_excludes_setting_false(): void {
		$registry = $this->registry();
		$this->assertSame( array( 'karo_kit_test_a', 'karo_kit_test_c' ), $registry->settingNames() );
	}

	public function test_uninstall_names_excludes_uninstall_false(): void {
		$registry = $this->registry();
		$this->assertSame( array( 'karo_kit_test_a', 'karo_kit_test_c' ), $registry->uninstallNames() );
	}

	public function test_export_map_only_includes_export_true_and_marks_page_kind(): void {
		$registry = $this->registry();
		$this->assertSame(
			array( 'karo_kit_test_a' => 'value', 'karo_kit_test_b' => 'value' ),
			$registry->exportMap()
		);
	}

	public function test_export_map_marks_page_type_options_as_page_kind(): void {
		$registry = new Registry();
		$registry->add( new Option( 'karo_kit_test_page', 'page', default: 0, export: true ) );
		$this->assertSame( array( 'karo_kit_test_page' => 'page' ), $registry->exportMap() );
	}

	public function test_export_labels_only_includes_exported_options_with_a_label(): void {
		$registry = $this->registry();
		$this->assertSame( array( 'karo_kit_test_a' => 'A', 'karo_kit_test_b' => 'B' ), $registry->exportLabels() );
	}

	public function test_export_labels_omits_an_exported_option_with_no_label(): void {
		$registry = new Registry();
		$registry->add( new Option( 'karo_kit_test_unlabeled', 'string', default: '', export: true ) ); // no label

		$this->assertArrayHasKey( 'karo_kit_test_unlabeled', $registry->exportMap() );
		$this->assertArrayNotHasKey( 'karo_kit_test_unlabeled', $registry->exportLabels() );
	}

	public function test_seed_defaults_writes_static_defaults(): void {
		$this->registry()->seedDefaults();

		// add_option() caches the raw, pre-SQL-cast PHP value (false) into the
		// 'alloptions' object cache, while the row actually written to the DB
		// holds the SQL-cast string (''). get_option() reads the cache first,
		// so within this same request it still sees the raw false -- forcing
		// a cache reload is what actually proves seedDefaults() persisted the
		// row, rather than just asserting a value get_option() would also
		// return for an option that was never written at all.
		wp_cache_delete( 'alloptions', 'options' );
		$this->assertSame( '', get_option( 'karo_kit_test_a' ) );
		$this->assertSame( '5', get_option( 'karo_kit_test_b' ) );
	}

	public function test_seed_defaults_never_overwrites_an_existing_choice(): void {
		update_option( 'karo_kit_test_b', '99' );

		$this->registry()->seedDefaults();

		$this->assertSame( '99', get_option( 'karo_kit_test_b' ) );
	}

	public function test_seed_defaults_uses_the_callback_when_present(): void {
		$registry = new Registry();
		$registry->add( new Option(
			name: 'karo_kit_test_computed',
			type: 'string',
			default: 'never used',
			defaultCallback: static fn() => 'computed value',
		) );

		$registry->seedDefaults();

		$this->assertSame( 'computed value', get_option( 'karo_kit_test_computed' ) );
		delete_option( 'karo_kit_test_computed' );
	}

	public function test_wp_type_maps_every_declared_type(): void {
		$this->assertSame( 'boolean', Registry::wpType( 'bool' ) );
		$this->assertSame( 'integer', Registry::wpType( 'int' ) );
		$this->assertSame( 'integer', Registry::wpType( 'page' ) );
		$this->assertSame( 'string', Registry::wpType( 'string' ) );
		$this->assertSame( 'string', Registry::wpType( 'key' ) );
		$this->assertSame( 'string', Registry::wpType( 'enum' ) );
		$this->assertSame( 'string', Registry::wpType( 'hex' ) );
		$this->assertSame( 'array', Registry::wpType( 'array' ) );
	}

	public function test_sanitizer_for_bool_casts_truthy_to_one_or_zero(): void {
		$fn = Registry::sanitizerFor( new Option( 'x', 'bool' ) );
		$this->assertSame( 1, $fn( 'anything truthy' ) );
		$this->assertSame( 0, $fn( '' ) );
		$this->assertSame( 0, $fn( null ) );
	}

	public function test_sanitizer_for_int_uses_absint_and_clamps_to_min_max(): void {
		$fn = Registry::sanitizerFor( new Option( 'x', 'int', min: 1, max: 10 ) );
		$this->assertSame( 1, $fn( 0 ) );      // absint(0) = 0, clamped up to min 1
		$this->assertSame( 10, $fn( 999 ) );   // absint(999) = 999, clamped down to max 10
		$this->assertSame( 5, $fn( 5 ) );      // already in range, untouched
	}

	public function test_sanitizer_for_int_without_bounds_just_absints(): void {
		$fn = Registry::sanitizerFor( new Option( 'x', 'int' ) );
		$this->assertSame( 5, $fn( '5abc' ) );
	}

	public function test_sanitizer_for_page_absints(): void {
		$fn = Registry::sanitizerFor( new Option( 'x', 'page' ) );
		$this->assertSame( 42, $fn( '42' ) );
	}

	public function test_sanitizer_for_key_uses_sanitize_key(): void {
		$fn = Registry::sanitizerFor( new Option( 'x', 'key' ) );
		$this->assertSame( 'myslug', $fn( 'My Slug!!' ) );
	}

	public function test_sanitizer_for_enum_falls_back_to_default_when_not_a_member(): void {
		$fn = Registry::sanitizerFor( new Option( 'x', 'enum', default: 'a', enum: array( 'a', 'b' ) ) );
		$this->assertSame( 'b', $fn( 'b' ) );
		$this->assertSame( 'a', $fn( 'not-a-member' ) );
	}

	public function test_sanitizer_for_hex_uses_normalise_hex(): void {
		$fn = Registry::sanitizerFor( new Option( 'x', 'hex' ) );
		$this->assertSame( '#ff0000', $fn( '#F00' ) );
		$this->assertSame( '', $fn( 'not a hex value' ) );
	}

	public function test_sanitizer_for_string_uses_sanitize_text_field(): void {
		$fn = Registry::sanitizerFor( new Option( 'x', 'string' ) );
		$this->assertSame( 'plain text', $fn( '  plain text  ' ) );
	}

	public function test_sanitizer_for_array_passes_through(): void {
		$fn = Registry::sanitizerFor( new Option( 'x', 'array' ) );
		$this->assertSame( array( 1, 2, 3 ), $fn( array( 1, 2, 3 ) ) );
	}
}
