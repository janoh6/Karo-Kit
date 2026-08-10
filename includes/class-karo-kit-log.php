<?php
/**
 * Karo Kit — activity log.
 *
 * Backed by its own table. This started as a capped ring buffer in a single
 * option, which was wrong for the access pattern: every failed login and every
 * blocked request had to read the whole log, unserialise it, prepend,
 * re-serialise and write it back. Under a brute-force run that turned the
 * logging into an amplifier — each hostile request cost a full serialize plus
 * a DB write, so the plugin made an attack more expensive to absorb than
 * having no logging at all.
 *
 * An append-only log with bounded retention wants INSERT, not read-modify-
 * write. Retention is a periodic DELETE rather than work on every write.
 *
 * The public API is unchanged: entries still read back as
 * { time, type, message, ip }, newest first.
 *
 * @package Karo_Kit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Karo_Kit_Log {

	/** Bump when the schema changes; drives dbDelta on upgrade. */
	const DB_VERSION = 1;

	const DB_VERSION_OPTION = 'karo_kit_log_db_version';

	/** The pre-1.0 option-backed log, migrated then removed. */
	const LEGACY_OPTION = 'karo_kit_log';

	/** Rows retained; the oldest beyond this are trimmed on a daily event. */
	const MAX = 200;

	/** How many entries the dashboard's "Recent activity" card shows. */
	const RECENT = 5;

	/** Superseded by Karo_Kit::CRON_DAILY; unscheduled on install. */
	const LEGACY_CRON = 'karo_kit_log_trim';

	public static function init(): void {
		// Schema work can't live on activation alone: an in-place plugin
		// update never fires the activation hook.
		add_action( 'admin_init', array( __CLASS__, 'maybe_install' ) );
		add_action( Karo_Kit::CRON_DAILY, array( __CLASS__, 'trim' ) );
	}

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'karo_kit_log';
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

		// dbDelta is whitespace- and format-sensitive; keep two spaces after
		// PRIMARY KEY and one field per line.
		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created datetime NOT NULL,
				type varchar(32) NOT NULL DEFAULT '',
				message text NOT NULL,
				ip varchar(100) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				KEY created (created)
			) {$collate};"
		);

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );

		self::migrate_legacy();

		// Retention moved onto the shared daily event.
		wp_clear_scheduled_hook( self::LEGACY_CRON );
	}

	/** Carry entries over from the old option-backed log, once. */
	private static function migrate_legacy(): void {
		$legacy = get_option( self::LEGACY_OPTION );
		if ( ! is_array( $legacy ) || ! $legacy ) {
			delete_option( self::LEGACY_OPTION );
			return;
		}

		// Oldest first, so auto-increment ids end up in chronological order.
		foreach ( array_reverse( $legacy ) as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			self::insert(
				(string) ( $entry['type'] ?? '' ),
				(string) ( $entry['message'] ?? '' ),
				(string) ( $entry['ip'] ?? '' ),
				(int) ( $entry['time'] ?? time() )
			);
		}

		delete_option( self::LEGACY_OPTION );
	}

	/* ---- Writing ---------------------------------------------------------- */

	/**
	 * Record one event.
	 *
	 * @param string $type    Machine key, e.g. 'lockout', 'blocked'.
	 * @param string $message Human-readable, already translated.
	 * @param array  $context Optional extras; 'ip' is resolved automatically.
	 */
	public static function add( string $type, string $message, array $context = array() ): void {
		self::insert( $type, $message, (string) ( $context['ip'] ?? self::ip() ), time() );
	}

	private static function insert( string $type, string $message, string $ip, int $time ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			self::table(),
			array(
				'created' => gmdate( 'Y-m-d H:i:s', $time ),
				'type'    => substr( $type, 0, 32 ),
				'message' => $message,
				'ip'      => substr( $ip, 0, 100 ),
			),
			array( '%s', '%s', '%s', '%s' )
		);
	}

	/** Drop everything past MAX. Runs daily, not on every write. */
	public static function trim(): void {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$cutoff = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$table} ORDER BY id DESC LIMIT 1 OFFSET %d", self::MAX )
		);
		if ( ! $cutoff ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id <= %d", (int) $cutoff ) );
	}

	public static function clear(): void {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$table}" );
	}

	/* ---- Reading ---------------------------------------------------------- */

	/** @return array<int,array{time:int,type:string,message:string,ip:string}> newest first */
	public static function recent( int $limit = self::RECENT ): array {
		global $wpdb;

		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT created, type, message, ip FROM {$table} ORDER BY id DESC LIMIT %d", max( 1, $limit ) )
		);

		return array_map(
			static function ( $row ) {
				return array(
					// Stored in UTC; the renderers still expect a timestamp.
					'time'    => (int) strtotime( $row->created . ' UTC' ),
					'type'    => (string) $row->type,
					'message' => (string) $row->message,
					'ip'      => (string) $row->ip,
				);
			},
			(array) $rows
		);
	}

	/** @return array<int,array{time:int,type:string,message:string,ip:string}> */
	public static function all(): array {
		return self::recent( self::MAX );
	}

	/* ---- Helpers ---------------------------------------------------------- */

	/** Visitor IP, reusing Gate's filterable resolver when that module is loaded. */
	private static function ip(): string {
		if ( class_exists( 'Karo_Kit_Gate_Security' ) ) {
			return Karo_Kit_Gate_Security::client_ip();
		}
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}

	/** "3 minutes ago" for recent entries, absolute date once it's stale. */
	public static function when( int $time ): string {
		if ( time() - $time < DAY_IN_SECONDS ) {
			/* translators: %s: human-readable time difference, e.g. "3 mins" */
			return sprintf( __( '%s ago', 'karo-kit' ), human_time_diff( $time ) );
		}
		return wp_date( (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ), $time );
	}
}
