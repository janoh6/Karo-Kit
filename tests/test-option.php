<?php
/**
 * The Option value object is a plain, immutable declaration -- this test
 * only proves it stores what it's given and defaults sensibly.
 *
 * @package Karo_Kit
 */

use KaroKit\Core\Options\Option;

class Test_Option extends WP_UnitTestCase {

	public function test_stores_required_fields(): void {
		$option = new Option( 'karo_kit_test_flag', 'bool' );

		$this->assertSame( 'karo_kit_test_flag', $option->name );
		$this->assertSame( 'bool', $option->type );
	}

	public function test_defaults(): void {
		$option = new Option( 'karo_kit_test_flag', 'bool' );

		$this->assertNull( $option->default );
		$this->assertNull( $option->defaultCallback );
		$this->assertNull( $option->label );
		$this->assertTrue( $option->setting );
		$this->assertFalse( $option->export );
		$this->assertTrue( $option->uninstall );
		$this->assertTrue( $option->autoload );
		$this->assertNull( $option->enum );
		$this->assertNull( $option->min );
		$this->assertNull( $option->max );
	}

	public function test_every_field_is_settable(): void {
		$callback = static fn() => '1';
		$option   = new Option(
			name: 'karo_kit_test_enum',
			type: 'enum',
			default: 'a',
			defaultCallback: $callback,
			label: 'Test Enum',
			setting: false,
			export: true,
			uninstall: false,
			autoload: false,
			enum: array( 'a', 'b', 'c' ),
			min: 1,
			max: 10,
		);

		$this->assertSame( 'karo_kit_test_enum', $option->name );
		$this->assertSame( 'enum', $option->type );
		$this->assertSame( 'a', $option->default );
		$this->assertSame( $callback, $option->defaultCallback );
		$this->assertSame( 'Test Enum', $option->label );
		$this->assertFalse( $option->setting );
		$this->assertTrue( $option->export );
		$this->assertFalse( $option->uninstall );
		$this->assertFalse( $option->autoload );
		$this->assertSame( array( 'a', 'b', 'c' ), $option->enum );
		$this->assertSame( 1, $option->min );
		$this->assertSame( 10, $option->max );
	}

	public function test_readonly_properties_cannot_be_reassigned(): void {
		$option = new Option( 'karo_kit_test_flag', 'bool' );

		$this->expectException( Error::class );
		// @phpstan-ignore-next-line -- deliberately violating readonly to prove it's enforced.
		$option->name = 'karo_kit_other';
	}
}
