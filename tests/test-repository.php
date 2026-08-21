<?php
/**
 * Repository: typed reads, falling back to each option's declared default
 * when nothing has been written yet.
 *
 * @package Karo_Kit
 */

use KaroKit\Core\Options\Option;
use KaroKit\Core\Options\Registry;
use KaroKit\Core\Options\Repository;

class Test_Repository extends WP_UnitTestCase {

	private Repository $repository;

	public function set_up(): void {
		parent::set_up();

		delete_option( 'karo_kit_test_bool' );
		delete_option( 'karo_kit_test_int' );
		delete_option( 'karo_kit_test_string' );

		$registry = new Registry();
		$registry->add( new Option( 'karo_kit_test_bool', 'bool', default: false ) );
		$registry->add( new Option( 'karo_kit_test_int', 'int', default: 42 ) );
		$registry->add( new Option( 'karo_kit_test_string', 'string', default: 'fallback' ) );

		$this->repository = new Repository( $registry );
	}

	public function test_bool_reads_the_declared_default_when_unset(): void {
		$this->assertFalse( $this->repository->bool( 'karo_kit_test_bool' ) );
	}

	public function test_bool_reads_a_stored_value(): void {
		update_option( 'karo_kit_test_bool', '1' );
		$this->assertTrue( $this->repository->bool( 'karo_kit_test_bool' ) );
	}

	public function test_int_reads_the_declared_default_when_unset(): void {
		$this->assertSame( 42, $this->repository->int( 'karo_kit_test_int' ) );
	}

	public function test_int_reads_a_stored_value(): void {
		update_option( 'karo_kit_test_int', '7' );
		$this->assertSame( 7, $this->repository->int( 'karo_kit_test_int' ) );
	}

	public function test_string_reads_the_declared_default_when_unset(): void {
		$this->assertSame( 'fallback', $this->repository->string( 'karo_kit_test_string' ) );
	}

	public function test_string_reads_a_stored_value(): void {
		update_option( 'karo_kit_test_string', 'stored' );
		$this->assertSame( 'stored', $this->repository->string( 'karo_kit_test_string' ) );
	}

	public function test_reading_an_undeclared_option_falls_back_to_a_type_appropriate_zero_value(): void {
		delete_option( 'karo_kit_test_undeclared' );
		$this->assertFalse( $this->repository->bool( 'karo_kit_test_undeclared' ) );
		$this->assertSame( 0, $this->repository->int( 'karo_kit_test_undeclared' ) );
		$this->assertSame( '', $this->repository->string( 'karo_kit_test_undeclared' ) );
	}
}
