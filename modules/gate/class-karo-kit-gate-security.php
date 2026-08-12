<?php
/**
 * Gate module — security helpers (rate limiting / lockout).
 *
 * State lives in its own table rather than transients. Transients are not
 * durable storage: with a persistent object cache they live in memory, where
 * they can be evicted under memory pressure or wiped wholesale by any cache-
 * purge plugin. A rate limiter that silently forgets an active lockout is a
 * security control resting on a cache, so the authoritative record is a row.
 *
 * A row holds the attempt counter, the window it started in, and when the
 * lockout expires — which also gives callers the attempt number, so logging can
 * record the first failure and the lockout rather than every attempt in between.
 *
 * Rows are keyed by a hashed identity plus a context. Where the caller can name
 * *who* was being attacked (a login naming an account), two counters run at
 * once: a tight one per account-from-this-IP, and a looser IP-wide one. Keying
 * on the IP alone punished everyone sharing an address — one attacker on an
 * office network or behind CGNAT locked out every colleague. Keying on the
 * account alone would be worse: an attacker could try five passwords against
 * each of a thousand usernames and never trip anything. Both together bound
 * each account and still blunt a spray.
 *
 * @package Karo_Kit\Gate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Karo_Kit_Gate_Security {

	/** Bump when the schema changes; drives dbDelta on upgrade. */
	const DB_VERSION = 1;

	const DB_VERSION_OPTION = 'karo_kit_gate_throttle_db_version';

	/**
	 * How much more the IP-wide counter tolerates than the per-account one.
	 * With the default of 5 attempts, one address gets 25 failures across all
	 * accounts before it is shut out entirely — loose enough that a shared
	 * office address survives a few people fumbling passwords, tight enough
	 * that spraying a user list from one host still ends.
	 */
	const IP_WIDE_MULTIPLIER = 5;

	/** Context suffix for the IP-wide companion counter. */
	const IP_WIDE_SUFFIX = '_ip';

	public static function init(): void {
		// Activation alone isn't enough — in-place updates skip that hook.
		add_action( 'admin_init', array( __CLASS__, 'maybe_install' ) );
		add_action( Karo_Kit::CRON_DAILY, array( __CLASS__, 'purge' ) );
	}

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'karo_kit_throttle';
	}

	/* ---- Schema ----------------------------------------------------------- */

	public static function maybe_install(): void {
		if ( (int) get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
			return;
		}
		self::install();
	}

	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		// The identity is stored as a salted hash: this table exists to count
		// abuse, and it doesn't need to retain readable addresses to do that.
		// Rows keyed on an older, unsalted digest simply stop matching after an
		// upgrade — a throttle counter is ephemeral, and purge() clears them.
		dbDelta(
			"CREATE TABLE {$table} (
				ip_hash char(32) NOT NULL,
				context varchar(32) NOT NULL,
				attempts smallint(5) unsigned NOT NULL DEFAULT 0,
				window_start datetime NOT NULL,
				locked_until datetime DEFAULT NULL,
				PRIMARY KEY  (ip_hash,context),
				KEY locked_until (locked_until)
			) {$collate};"
		);

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/** Drop rows that are neither locked nor inside a live attempt window. */
	public static function purge(): void {
		global $wpdb;

		$table  = self::table();
		$window = self::window_minutes();
		$stale  = gmdate( 'Y-m-d H:i:s', time() - ( $window * MINUTE_IN_SECONDS ) );
		$now    = gmdate( 'Y-m-d H:i:s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE window_start < %s AND ( locked_until IS NULL OR locked_until < %s )",
				$stale,
				$now
			)
		);
	}

	/* ---- Settings --------------------------------------------------------- */

	private static function max_attempts(): int {
		return max( 1, (int) get_option( 'karo_kit_gate_lockout_max', 5 ) );
	}

	private static function window_minutes(): int {
		return max( 1, (int) get_option( 'karo_kit_gate_lockout_window', 15 ) );
	}

	private static function cooldown_minutes(): int {
		return max( 1, (int) get_option( 'karo_kit_gate_lockout_cooldown', 60 ) );
	}

	/* ---- Identity --------------------------------------------------------- */

	/**
	 * Resolve the visitor's IP. Only REMOTE_ADDR is trusted by default —
	 * forwarded headers are spoofable. Sites behind a proxy/Cloudflare map the
	 * real header via the filter, e.g.
	 *   add_filter( 'karo_kit_gate_client_ip', fn() => $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '' );
	 */
	public static function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? trim( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) ) : '';
		return (string) apply_filters( 'karo_kit_gate_client_ip', $ip );
	}

	/**
	 * Key for a throttle row.
	 *
	 * Salted via wp_hash() rather than a bare md5: the whole IPv4 space is four
	 * billion values, so an unsalted digest of an address is reversible by
	 * exhaustion in minutes and pseudonymises nothing. wp_hash() also returns 32
	 * hex characters, so the existing column still fits.
	 *
	 * Passing an identity scopes the row to that account from this address;
	 * omitting it gives the IP-wide row.
	 */
	private static function key_for( string $identity = '' ): string {
		$ip = self::client_ip();
		// Usernames are matched case-insensitively at login, so vary only the
		// case and you would otherwise get a fresh allowance each time.
		return wp_hash( '' === $identity ? $ip : $ip . '|' . strtolower( $identity ) );
	}

	/* ---- State ------------------------------------------------------------ */

	/** @return object|null Row for one key + context. */
	private static function row( string $key, string $context ) {
		global $wpdb;

		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT attempts, window_start, locked_until FROM {$table} WHERE ip_hash = %s AND context = %s",
				$key,
				sanitize_key( $context )
			)
		);
	}

	private static function to_time( ?string $datetime ): int {
		return $datetime ? (int) strtotime( $datetime . ' UTC' ) : 0;
	}

	private static function row_locked( string $key, string $context ): bool {
		$row = self::row( $key, $context );
		return $row && self::to_time( $row->locked_until ) > time();
	}

	/**
	 * Is this visitor currently locked out?
	 *
	 * @param string $context  Handler name, e.g. 'login'.
	 * @param string $identity Account being targeted, where the caller knows it.
	 *                         Given one, the IP-wide counter is consulted too.
	 */
	public static function is_locked( string $context, string $identity = '' ): bool {
		if ( self::row_locked( self::key_for( $identity ), $context ) ) {
			return true;
		}
		return '' !== $identity
			&& self::row_locked( self::key_for(), $context . self::IP_WIDE_SUFFIX );
	}

	/**
	 * Add one to a single counter, tripping its lockout at the threshold.
	 *
	 * @return array{attempts:int,max:int,locked:bool}
	 */
	private static function bump( string $key, string $context, int $max ): array {
		global $wpdb;

		$window   = self::window_minutes();
		$cooldown = self::cooldown_minutes();
		$now      = time();

		$row      = self::row( $key, $context );
		$existing = (int) ( $row->attempts ?? 0 );
		$started  = self::to_time( $row->window_start ?? null );

		// A stale window starts a fresh count rather than accumulating forever.
		$in_window = $row && ( $now - $started ) < ( $window * MINUTE_IN_SECONDS );
		$attempts  = $in_window ? $existing + 1 : 1;

		$locked       = $attempts >= $max;
		$locked_until = $locked ? gmdate( 'Y-m-d H:i:s', $now + ( $cooldown * MINUTE_IN_SECONDS ) ) : null;

		$data = array(
			'ip_hash'      => $key,
			'context'      => sanitize_key( $context ),
			'attempts'     => $locked ? 0 : $attempts, // counter resets behind the lock
			'window_start' => $in_window ? gmdate( 'Y-m-d H:i:s', $started ) : gmdate( 'Y-m-d H:i:s', $now ),
			'locked_until' => $locked_until,
		);

		if ( $row ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				self::table(),
				array(
					'attempts'     => $data['attempts'],
					'window_start' => $data['window_start'],
					'locked_until' => $data['locked_until'],
				),
				array( 'ip_hash' => $data['ip_hash'], 'context' => $data['context'] )
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->insert( self::table(), $data );
		}

		return array( 'attempts' => $attempts, 'max' => $max, 'locked' => $locked );
	}

	/**
	 * Record one failed attempt; trip a lockout once the threshold is hit.
	 *
	 * With an identity, two counters move at once — this account from this
	 * address, and this address across every account.
	 *
	 * @param string $context  Handler name, e.g. 'login'.
	 * @param string $identity Account being targeted, where the caller knows it.
	 * @return array{attempts:int,max:int,locked:bool,cooldown:int} So callers can
	 *         log the first failure and the lockout, not everything between.
	 */
	public static function register_failure( string $context, string $identity = '' ): array {
		$cooldown = self::cooldown_minutes();
		$result   = self::bump( self::key_for( $identity ), $context, self::max_attempts() );

		if ( '' !== $identity ) {
			$wide = self::bump(
				self::key_for(),
				$context . self::IP_WIDE_SUFFIX,
				self::max_attempts() * self::IP_WIDE_MULTIPLIER
			);
			// Report whichever bound was actually reached, so the log and the
			// caller describe the lockout the visitor will run into.
			if ( $wide['locked'] && ! $result['locked'] ) {
				$result = $wide;
			}
		}

		self::log_failure( $context, $result['attempts'], $result['max'], $result['locked'], $cooldown );

		return $result + array( 'cooldown' => $cooldown );
	}

	/**
	 * Log the shape of an attack, not every packet of it.
	 *
	 * A row per failed attempt means a sustained run buries everything else in
	 * the log and writes once per hostile request. The first failure says
	 * "someone is probing" and the lockout says "and they kept going"; the
	 * attempts in between add nothing a reader would act on.
	 */
	private static function log_failure( string $context, int $attempts, int $max, bool $locked, int $cooldown ): void {
		if ( $locked ) {
			Karo_Kit_Log::add(
				'lockout',
				sprintf(
					/* translators: 1: context (login/register), 2: attempts made, 3: cooldown in minutes */
					__( 'Lockout — %1$s after %2$d attempts (%3$d min)', 'karo-kit' ),
					$context,
					$max,
					$cooldown
				)
			);
			return;
		}

		if ( 1 === $attempts ) {
			Karo_Kit_Log::add(
				'failed_' . $context,
				sprintf(
					/* translators: 1: context (login/register), 2: lockout threshold */
					__( 'Failed %1$s — further attempts suppressed until lockout at %2$d', 'karo-kit' ),
					$context,
					$max
				)
			);
		}
	}

	/**
	 * Clear the counter for a context (call on success).
	 *
	 * Only the identity's own counter is cleared. The IP-wide one is left to
	 * expire with its window: an attacker holding one valid account on the site
	 * could otherwise wipe the spray counter at will simply by logging in.
	 */
	public static function clear( string $context, string $identity = '' ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			self::table(),
			array( 'ip_hash' => self::key_for( $identity ), 'context' => sanitize_key( $context ) )
		);
	}
}
