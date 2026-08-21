<?php
/**
 * A source of the current time, injectable so tests can advance it instead
 * of sleeping through window/cooldown expiry.
 *
 * @package Karo_Kit
 */

namespace KaroKit\Core\Clock;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface Clock {

	/** Current Unix timestamp. */
	public function now(): int;
}
