<?php
/**
 * Template last-modified dates render in the site's timezone.
 *
 * @package Karo_Kit
 */

class Test_Etch_Board_Dates extends WP_UnitTestCase {

	/**
	 * WordPress forces PHP's own timezone to UTC, so a site timezone well away
	 * from it is what exposes the bug: reading the local-time column and
	 * handing it to strtotime() parses it as UTC and lands on the wrong day.
	 */
	public function test_last_modified_uses_the_site_timezone(): void {
		update_option( 'timezone_string', 'Pacific/Kiritimati' ); // UTC+14

		$post_id = self::factory()->post->create( array( 'post_type' => 'wp_template' ) );

		global $wpdb;
		// 06:00 UTC on 1 March is 20:00 on 1 March at UTC+14.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_modified'     => '2026-03-01 20:00:00',
				'post_modified_gmt' => '2026-03-01 06:00:00',
			),
			array( 'ID' => $post_id )
		);
		clean_post_cache( $post_id );

		$method = new ReflectionMethod( Karo_Kit_Etch_Board::class, 'last_modified' );
		$method->setAccessible( true );

		$this->assertSame( 'Mar 1', $method->invoke( null, (object) array( 'wp_id' => $post_id ) ) );
	}

	public function test_a_template_with_no_post_row_has_no_date(): void {
		$method = new ReflectionMethod( Karo_Kit_Etch_Board::class, 'last_modified' );
		$method->setAccessible( true );

		$this->assertNull( $method->invoke( null, (object) array( 'wp_id' => 0 ) ) );
	}
}
