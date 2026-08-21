<?php
/**
 * Karo Kit — settings export / import.
 *
 * Moves configuration between sites. The hard part is that several Gate
 * settings are post IDs, which are meaningless on another install: restoring
 * them verbatim would silently point the login page at whatever post happens
 * to hold that ID. So page settings travel as slug + post type and are
 * re-resolved on import; anything that can't be matched is reported and left
 * alone rather than guessed at.
 *
 * Import is two-step — upload, review, apply — because a settings restore is
 * hard to undo and worth seeing first.
 *
 * @package Karo_Kit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Karo_Kit_Transfer {

	/** Bumped only if the payload shape changes incompatibly. */
	const FORMAT = 'karo-kit/1';

	/** Where a reviewed payload waits between upload and apply. */
	const STASH = 'karo_kit_import_';

	const STASH_TTL = 900; // 15 minutes

	const MAX_UPLOAD = 1048576; // 1 MB — these files are a few KB.

	public static function init(): void {
		// Before any output: export sends headers, the others redirect.
		add_action( 'admin_init', array( __CLASS__, 'handle_export' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_upload' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_apply' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_discard' ) );
	}

	/* ---- Export ---------------------------------------------------------- */

	/**
	 * Everything that contributes settings: the kit itself, then each module.
	 * Keyed the way they appear in the payload.
	 *
	 * @return array<string,class-string>
	 */
	private static function sources(): array {
		return array( '_kit' => Karo_Kit::class ) + Karo_Kit::modules();
	}

	/** @return array The full payload, ready to encode. */
	public static function payload(): array {
		$modules = array();

		foreach ( self::sources() as $id => $class ) {
			$options = array();

			foreach ( $class::export_map() as $option => $kind ) {
				$options[ $option ] = ( 'page' === $kind )
					? self::page_ref( (int) get_option( $option ) )
					: array( 'kind' => 'value', 'value' => get_option( $option ) );
			}

			if ( $options ) {
				$modules[ $id ] = $options;
			}
		}

		return array(
			'format'   => self::FORMAT,
			'plugin'   => KARO_KIT_VER,
			'exported' => gmdate( 'c' ),
			'site'     => home_url(),
			'modules'  => $modules,
		);
	}

	/** A page setting as slug + type, so it can be matched on another site. */
	private static function page_ref( int $id ): array {
		$post = $id ? get_post( $id ) : null;
		if ( ! $post ) {
			return array( 'kind' => 'page', 'slug' => '', 'post_type' => '', 'title' => '' );
		}
		return array(
			'kind'      => 'page',
			'slug'      => $post->post_name,
			'post_type' => $post->post_type,
			'title'     => $post->post_title,
		);
	}

	public static function handle_export(): void {
		if ( empty( $_POST['karo_kit_export'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( 'karo_kit_transfer' );

		$json = wp_json_encode( self::payload(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		$name = 'karo-kit-settings-' . gmdate( 'Y-m-d' ) . '.json';

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		header( 'Content-Length: ' . strlen( $json ) );
		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput — JSON body, not markup.
		exit;
	}

	/* ---- Upload + review -------------------------------------------------- */

	public static function handle_upload(): void {
		if ( empty( $_POST['karo_kit_import'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( 'karo_kit_transfer' );

		$error = static function ( string $code ) {
			wp_safe_redirect( admin_url( 'admin.php?page=karo-kit&tab=transfer&error=' . $code ) );
			exit;
		};

		if ( empty( $_FILES['karo_kit_file'] ) || ! isset( $_FILES['karo_kit_file']['tmp_name'] ) ) {
			$error( 'nofile' );
		}
		$file = $_FILES['karo_kit_file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! empty( $file['error'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			$error( 'upload' );
		}
		if ( (int) $file['size'] > self::MAX_UPLOAD ) {
			$error( 'toobig' );
		}

		$raw  = file_get_contents( $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$data = json_decode( (string) $raw, true );

		if ( ! is_array( $data ) || ! isset( $data['format'] ) || self::FORMAT !== $data['format'] ) {
			$error( 'format' );
		}
		if ( empty( $data['modules'] ) || ! is_array( $data['modules'] ) ) {
			$error( 'empty' );
		}

		set_transient( self::STASH . get_current_user_id(), $data, self::STASH_TTL );
		wp_safe_redirect( admin_url( 'admin.php?page=karo-kit&tab=transfer' ) );
		exit;
	}

	private static function stashed(): ?array {
		$data = get_transient( self::STASH . get_current_user_id() );
		return is_array( $data ) ? $data : null;
	}

	public static function handle_discard(): void {
		if ( empty( $_POST['karo_kit_import_discard'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( 'karo_kit_transfer' );

		delete_transient( self::STASH . get_current_user_id() );
		wp_safe_redirect( admin_url( 'admin.php?page=karo-kit&tab=dashboard' ) );
		exit;
	}

	/**
	 * Work out what an import would do, without doing it.
	 *
	 * @return array<int,array{module:string,option:string,label:string,status:string,from:string,to:string}>
	 */
	public static function plan( array $data ): array {
		$rows    = array();
		$modules = self::sources();

		foreach ( (array) $data['modules'] as $module_id => $options ) {
			if ( ! isset( $modules[ $module_id ] ) || ! is_array( $options ) ) {
				continue; // a module this site doesn't have
			}
			$class  = $modules[ $module_id ];
			$map    = $class::export_map();
			$labels = $class::export_labels();

			foreach ( $options as $option => $incoming ) {
				// Only options this module still declares are importable —
				// the file can't introduce arbitrary option writes.
				if ( ! isset( $map[ $option ] ) || ! is_array( $incoming ) ) {
					continue;
				}

				$row = array(
					'module' => Karo_Kit::class === $class ? __( 'Karo Kit', 'karo-kit' ) : $class::label(),
					'option' => $option,
					'label'  => $labels[ $option ] ?? $option,
					'status' => 'ok',
					'from'   => '',
					'to'     => '',
				);

				if ( 'page' === $map[ $option ] ) {
					$current      = (int) get_option( $option );
					$row['from']  = $current ? get_the_title( $current ) : __( 'Not set', 'karo-kit' );
					$slug         = (string) ( $incoming['slug'] ?? '' );

					if ( '' === $slug ) {
						$row['status'] = 'skip';
						$row['to']     = __( 'Not set in the file', 'karo-kit' );
					} else {
						$match = self::find_page( $slug, (string) ( $incoming['post_type'] ?? 'page' ) );
						if ( $match ) {
							$row['to']    = get_the_title( $match );
							$row['value'] = $match;
						} else {
							$row['status'] = 'missing';
							/* translators: %s: page slug from the imported file */
							$row['to'] = sprintf( __( 'No page matching "%s"', 'karo-kit' ), $slug );
						}
					}
				} else {
					$row['from']  = self::readable( get_option( $option ) );
					$row['to']    = self::readable( $incoming['value'] ?? null );
					$row['value'] = $incoming['value'] ?? null;
				}

				if ( 'ok' === $row['status'] && $row['from'] === $row['to'] ) {
					$row['status'] = 'same';
				}

				$rows[] = $row;
			}
		}

		return $rows;
	}

	/** Resolve a page by slug within its post type. */
	private static function find_page( string $slug, string $post_type ) {
		$found = get_posts( array(
			'name'             => $slug,
			'post_type'        => $post_type ? $post_type : 'page',
			'post_status'      => 'publish',
			'posts_per_page'   => 1,
			'fields'           => 'ids',
			'no_found_rows'    => true,
			'suppress_filters' => false,
		) );
		return $found ? (int) $found[0] : 0;
	}

	private static function readable( $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? __( 'On', 'karo-kit' ) : __( 'Off', 'karo-kit' );
		}
		if ( is_array( $value ) ) {
			/* translators: %d: number of stored entries */
			return sprintf( _n( '%d entry', '%d entries', count( $value ), 'karo-kit' ), count( $value ) );
		}
		if ( '' === $value || null === $value ) {
			return __( 'Empty', 'karo-kit' );
		}
		return (string) $value;
	}

	/* ---- Apply ------------------------------------------------------------ */

	/**
	 * Write a reviewed plan.
	 *
	 * Rows whose value already matches are counted apart from rows actually
	 * written. Importing a file exported from this same site should report
	 * that nothing changed, not that everything was applied — and it should
	 * not spend an update_option() (with every sanitize callback and option
	 * hook behind it) rewriting a value to itself.
	 *
	 * @param array<int,array<string,mixed>> $rows Output of plan().
	 * @return array{applied:int,unchanged:int,skipped:int}
	 */
	public static function apply_plan( array $rows ): array {
		$applied   = 0;
		$unchanged = 0;
		$skipped   = 0;

		foreach ( $rows as $row ) {
			if ( ! array_key_exists( 'value', $row )
				|| ! in_array( $row['status'], array( 'ok', 'same' ), true ) ) {
				$skipped++;
				continue;
			}

			// Compare the stored value, not plan()'s display string: readable()
			// collapses arrays to "%d entries", so two different arrays of
			// equal length would look identical there and be wrongly treated
			// as unchanged.
			if ( get_option( $row['option'] ) == $row['value'] ) { // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison
				$unchanged++;
				continue;
			}

			update_option( $row['option'], $row['value'] );
			$applied++;
		}

		return array(
			'applied'   => $applied,
			'unchanged' => $unchanged,
			'skipped'   => $skipped,
		);
	}

	public static function handle_apply(): void {
		if ( empty( $_POST['karo_kit_import_apply'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( 'karo_kit_transfer' );

		$data = self::stashed();
		if ( ! $data ) {
			wp_safe_redirect( admin_url( 'admin.php?page=karo-kit&tab=transfer&error=expired' ) );
			exit;
		}

		// Re-plan rather than trusting anything posted back: the allowlist and
		// page resolution are applied again at write time.
		$counts = self::apply_plan( self::plan( $data ) );

		delete_transient( self::STASH . get_current_user_id() );

		Karo_Kit_Log::add(
			'import',
			sprintf(
				/* translators: 1: settings written, 2: settings already matching, 3: settings skipped */
				__( 'Settings imported — %1$d applied, %2$d already set, %3$d skipped', 'karo-kit' ),
				$counts['applied'],
				$counts['unchanged'],
				$counts['skipped']
			)
		);

		wp_safe_redirect( admin_url( 'admin.php?page=karo-kit&tab=dashboard&imported=' . $counts['applied'] ) );
		exit;
	}

	/* ---- UI --------------------------------------------------------------- */

	/** The dashboard card: export, plus the file picker that starts an import. */
	public static function render_card(): void {
		echo '<div class="kk-content"><section class="kk-card">';
		echo '<div class="kk-card__header"><span class="kk-card__title">' . esc_html__( 'Settings transfer', 'karo-kit' ) . '</span></div>';
		echo '<p class="kk-empty">' . esc_html__( 'Move this configuration to another site. Pages travel by slug, not by ID, and are matched on the target — anything that can\'t be matched is reported rather than guessed. The hidden-login secret word, the activity log and generated thumbnails are never included.', 'karo-kit' ) . '</p>';

		echo '<form method="post" enctype="multipart/form-data" class="kk-transfer">';
		wp_nonce_field( 'karo_kit_transfer' );
		printf(
			'<button type="submit" name="karo_kit_export" value="1" class="kk-btn--quiet">%s</button>',
			esc_html__( 'Export settings', 'karo-kit' )
		);
		echo '<span class="kk-transfer__sep"></span>';
		echo '<input type="file" name="karo_kit_file" accept="application/json,.json">';
		printf(
			'<button type="submit" name="karo_kit_import" value="1" class="kk-btn--quiet">%s</button>',
			esc_html__( 'Review import', 'karo-kit' )
		);
		echo '</form>';

		echo '</section></div>';
	}

	/** The review screen — what an import would change, before it changes it. */
	public static function render_preview(): void {
		$errors = array(
			'nofile'  => __( 'Choose a file to import.', 'karo-kit' ),
			'upload'  => __( 'That file could not be uploaded.', 'karo-kit' ),
			'toobig'  => __( 'That file is too large to be a Karo Kit export.', 'karo-kit' ),
			'format'  => __( 'That is not a Karo Kit settings file.', 'karo-kit' ),
			'empty'   => __( 'That file contains no settings.', 'karo-kit' ),
			'expired' => __( 'The review expired. Upload the file again.', 'karo-kit' ),
		);
		$code = isset( $_GET['error'] ) ? sanitize_key( wp_unslash( $_GET['error'] ) ) : '';

		$data = self::stashed();
		$rows = $data ? self::plan( $data ) : array();

		Karo_Kit::pagehead(
			__( 'Review import', 'karo-kit' ),
			$data
				? sprintf(
					/* translators: 1: source site URL, 2: export date */
					__( 'From %1$s, exported %2$s. Nothing has been changed yet.', 'karo-kit' ),
					(string) ( $data['site'] ?? __( 'an unknown site', 'karo-kit' ) ),
					(string) ( $data['exported'] ?? '' )
				)
				: __( 'Upload a Karo Kit settings file from the dashboard to begin.', 'karo-kit' )
		);

		echo '<div class="kk-content"><section class="kk-card">';

		if ( $code && isset( $errors[ $code ] ) ) {
			echo '<p class="kk-notice kk-notice--warn">' . esc_html( $errors[ $code ] ) . '</p>';
		}

		if ( ! $rows ) {
			echo '<p class="kk-empty">' . esc_html__( 'Nothing to review.', 'karo-kit' ) . '</p>';
			printf(
				'<p class="kk-empty"><a class="kk-card__link" href="%s">%s</a></p>',
				esc_url( admin_url( 'admin.php?page=karo-kit&tab=dashboard' ) ),
				esc_html__( 'Back to dashboard', 'karo-kit' )
			);
			echo '</section></div>';
			return;
		}

		$tones = array(
			'ok'      => array( __( 'Will change', 'karo-kit' ), 'warn' ),
			'same'    => array( __( 'Already set', 'karo-kit' ), 'muted' ),
			'missing' => array( __( 'No match', 'karo-kit' ), 'alert' ),
			'skip'    => array( __( 'Skipped', 'karo-kit' ), 'muted' ),
		);

		echo '<table class="kk-log"><thead><tr>';
		printf( '<th>%s</th>', esc_html__( 'Setting', 'karo-kit' ) );
		printf( '<th>%s</th>', esc_html__( 'Now', 'karo-kit' ) );
		printf( '<th>%s</th>', esc_html__( 'After import', 'karo-kit' ) );
		printf( '<th>%s</th>', esc_html__( 'Status', 'karo-kit' ) );
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$tone = $tones[ $row['status'] ] ?? $tones['skip'];
			printf(
				'<tr><td>%s<span class="kk-transfer__module">%s</span></td><td class="kk-log__time">%s</td><td>%s</td><td class="kk-log__ip"><span class="kk-log__dot kk-log__dot--%s"></span>%s</td></tr>',
				esc_html( $row['label'] ),
				esc_html( $row['module'] ),
				esc_html( $row['from'] ),
				esc_html( $row['to'] ),
				esc_attr( $tone[1] ),
				esc_html( $tone[0] )
			);
		}
		echo '</tbody></table>';

		echo '<form method="post" class="kk-transfer kk-card__foot">';
		wp_nonce_field( 'karo_kit_transfer' );
		printf(
			'<button type="submit" name="karo_kit_import_apply" value="1" class="button button-primary">%s</button>',
			esc_html__( 'Apply these settings', 'karo-kit' )
		);
		printf(
			'<button type="submit" name="karo_kit_import_discard" value="1" class="kk-btn--quiet">%s</button>',
			esc_html__( 'Discard', 'karo-kit' )
		);
		echo '</form>';

		echo '</section></div>';
	}
}
