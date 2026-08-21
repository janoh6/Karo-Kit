<?php
/**
 * The real clock: wraps PHP's own time().
 *
 * @package Karo_Kit
 */

namespace KaroKit\Core\Clock;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SystemClock implements Clock {

	public function now(): int {
		return time();
	}
}
