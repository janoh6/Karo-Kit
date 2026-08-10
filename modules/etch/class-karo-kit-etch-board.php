<?php
/**
 * Etch module — Template Board REST layer + template lifecycle hooks.
 *
 * Ported from the standalone "Etch Template Board" plugin (v0.14.2).
 *
 * The Etch public API (etch.navigation.listTemplatesAsync) is the authoritative
 * source for {id, title, slug} and is used for opening templates. It does NOT
 * expose type, status, thumbnails or component usage. This endpoint fills
 * exactly that gap by reading the underlying wp_template posts, keyed by title
 * so the board can merge it onto the API list.
 *
 * @package Karo_Kit\Etch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Karo_Kit_Etch_Board {

	const REST_NAMESPACE = 'karo-kit/v1';

	const ENABLED_OPT   = 'karo_kit_etch_board_on';
	const ORDER_OPT     = 'karo_kit_etch_order';
	const THUMB_OPT     = 'karo_kit_etch_thumbs';
	const STATUS_OPT    = 'karo_kit_etch_status';
	const THRESHOLD_OPT = 'karo_kit_etch_thumb_threshold';

	/**
	 * Upload subdirectory for generated thumbnails. Deliberately keeps the
	 * standalone plugin's name so images already on disk survive the move —
	 * on a local site they cannot be regenerated (see generate_thumbnail).
	 */
	const THUMB_DIR = 'etb-thumbnails';

	const STATUSES = array( 'wip', 'review', 'ready', 'live' );

	public static function init(): void {
		// Migration runs whether or not the board is enabled — turning the
		// feature off must never strand data. In-place plugin updates don't
		// fire the activation hook, hence the admin_init hook too; the guard
		// option is autoloaded, so the check is free once it has run.
		add_action( 'admin_init', array( __CLASS__, 'maybe_migrate' ) );

		if ( ! self::enabled() ) {
			return; // switched off: no assets, no routes, no bookkeeping.
		}

		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );

		// Standalone template preview used as the thumbnail screenshot target.
		add_filter( 'query_vars', static function ( $vars ) {
			$vars[] = 'karo_kit_etch_preview';
			return $vars;
		} );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render_preview' ) );

		// Bump the thumbnail save-counter when a template is saved, and default
		// newly created templates to WIP.
		add_action( 'save_post_wp_template', array( __CLASS__, 'on_template_save' ), 10, 3 );
		add_action( 'rest_after_insert_wp_template', array( __CLASS__, 'on_template_rest_insert' ), 10, 3 );
		// Clean up stored status/thumbnail on delete, so a recreated template
		// with the same slug starts fresh instead of inheriting them.
		add_action( 'before_delete_post', array( __CLASS__, 'on_template_delete' ) );
	}

	/**
	 * Is the Template Board switched on?
	 *
	 * Defaults to on, so sites migrating from the standalone plugin keep the
	 * board they already had. Turning it off leaves stored board state intact,
	 * so switching back on restores columns, statuses and thumbnails.
	 */
	public static function enabled(): bool {
		return (bool) get_option( self::ENABLED_OPT, 1 );
	}

	/**
	 * Carry over board state from the standalone plugin, once.
	 *
	 * Column order, statuses and thumbnail bookkeeping are all keyed by slug and
	 * would otherwise be silently lost when switching to the bundled version.
	 * Old options are left in place so the standalone plugin still works if it
	 * is reactivated.
	 */
	public static function maybe_migrate(): void {
		if ( get_option( 'karo_kit_etch_migrated' ) ) {
			return;
		}

		$map = array(
			'etb_column_order'    => self::ORDER_OPT,
			'etb_thumbs'          => self::THUMB_OPT,
			'etb_status'          => self::STATUS_OPT,
			'etb_thumb_threshold' => self::THRESHOLD_OPT,
		);
		foreach ( $map as $old => $new ) {
			$value = get_option( $old, null );
			if ( null !== $value && false === get_option( $new, false ) ) {
				update_option( $new, $value, false );
			}
		}

		update_option( 'karo_kit_etch_migrated', 1 ); // autoloaded: read on every admin request
	}

	/* ---- Assets ---------------------------------------------------------- */

	/**
	 * What the loader should fetch once it sees the Etch API, or null if the
	 * board shouldn't run for this request.
	 *
	 * These assets are not enqueued directly: the builder is a front-end route
	 * with no reliable server-side marker, so enqueueing meant shipping them on
	 * every front-end page just to no-op. See assets/etch/loader.js.
	 */
	public static function loader_bundle(): ?array {
		// The switch is checked here as well as in init(): the loader asks every
		// feature directly, so init()'s early return no longer gates assets.
		if ( ! self::enabled() ) {
			return null;
		}

		/** Filter whether the board assets load for this request. */
		if ( ! apply_filters( 'karo_kit_etch_should_enqueue', true ) ) {
			return null;
		}


		return array(
			'styles'  => array( Karo_Kit_Etch::asset_url( 'assets/etch/board.css' ) ),
			'data'    => array(
				'name'  => 'KaroKitEtchData',
				'value' => array(
					'restBase' => esc_url_raw( rest_url( self::REST_NAMESPACE . '/etch' ) ),
					'nonce'    => wp_create_nonce( 'wp_rest' ),
				),
			),
			// Bridge first (exposes the bridge global), then the board that consumes it.
			'scripts' => array(
				Karo_Kit_Etch::asset_url( 'assets/etch/bridge.js' ),
				Karo_Kit_Etch::asset_url( 'assets/etch/board.js' ),
			),
		);
	}

	/* ---- REST ------------------------------------------------------------ */

	public static function register_routes(): void {
		$ns = self::REST_NAMESPACE;

		register_rest_route( $ns, '/etch/templates', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'get_templates' ),
			'permission_callback' => array( __CLASS__, 'can_edit' ),
		) );

		register_rest_route( $ns, '/etch/components', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'get_components' ),
			'permission_callback' => array( __CLASS__, 'can_edit' ),
		) );

		register_rest_route( $ns, '/etch/thumbnail', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'make_thumbnail' ),
			'permission_callback' => array( __CLASS__, 'can_edit' ),
			'args'                => array(
				'slug' => array( 'required' => true, 'type' => 'string' ),
			),
		) );

		register_rest_route( $ns, '/etch/status', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'set_status' ),
			'permission_callback' => array( __CLASS__, 'can_edit' ),
			'args'                => array(
				'slug'   => array( 'required' => true, 'type' => 'string' ),
				'status' => array( 'required' => true, 'type' => 'string', 'enum' => self::STATUSES ),
			),
		) );

		register_rest_route( $ns, '/etch/order', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_order' ),
				'permission_callback' => array( __CLASS__, 'can_edit' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'set_order' ),
				'permission_callback' => array( __CLASS__, 'can_edit' ),
				'args'                => array(
					'order' => array( 'required' => true, 'type' => 'array' ),
				),
			),
		) );
	}

	public static function can_edit(): bool {
		return current_user_can( Karo_Kit_Etch::CAP );
	}

	public static function get_threshold(): int {
		$t = (int) get_option( self::THRESHOLD_OPT, 3 );
		return $t > 0 ? $t : 3;
	}

	/* ---- Template lifecycle ---------------------------------------------- */

	/** Bump the save counter for a template; mark its thumbnail stale past threshold. */
	public static function on_template_save( $post_id, $post = null, $update = null ): void {
		$post = $post ? $post : get_post( $post_id );
		if ( ! $post || 'wp_template' !== $post->post_type ) {
			return;
		}
		$slug           = $post->post_name;
		$opt            = get_option( self::THUMB_OPT, array() );
		$entry          = $opt[ $slug ] ?? array( 'file' => '', 'time' => 0, 'saves' => 0, 'stale' => false );
		$entry['saves'] = (int) $entry['saves'] + 1;

		/** Filter how many saves before a thumbnail is regenerated. */
		$threshold = (int) apply_filters( 'karo_kit_etch_thumb_save_threshold', self::get_threshold() );
		if ( $entry['saves'] >= $threshold ) {
			$entry['stale'] = true;
		}
		$opt[ $slug ] = $entry;
		update_option( self::THUMB_OPT, $opt, false );

		// A brand-new template (non-REST insert) defaults to WIP.
		if ( false === $update ) {
			self::maybe_set_initial_status( $slug );
		}
	}

	/** Templates created via the REST/site-editor flow: default new ones to WIP. */
	public static function on_template_rest_insert( $post, $request, $creating ): void {
		if ( ! $post || ! isset( $post->ID ) ) {
			return;
		}
		self::on_template_save( $post->ID ); // keep the thumbnail counter in sync
		if ( $creating ) {
			self::maybe_set_initial_status( $post->post_name );
		}
	}

	/**
	 * Stamp an initial 'wip' status for a newly created template — but only if
	 * it has no status entry yet, so existing templates and any user-set status
	 * are never overridden.
	 */
	private static function maybe_set_initial_status( $slug ): void {
		$slug = sanitize_title( (string) $slug );
		if ( '' === $slug ) {
			return;
		}
		$opt = get_option( self::STATUS_OPT, array() );
		if ( isset( $opt[ $slug ] ) ) {
			return;
		}
		$opt[ $slug ] = 'wip';
		update_option( self::STATUS_OPT, $opt, false );
	}

	/**
	 * When a template is deleted, drop its stored status and thumbnail so a
	 * later template reusing the same slug starts clean rather than inheriting
	 * the deleted template's annotations.
	 */
	public static function on_template_delete( $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post || 'wp_template' !== $post->post_type ) {
			return;
		}
		$slug = $post->post_name;

		$status = get_option( self::STATUS_OPT, array() );
		if ( isset( $status[ $slug ] ) ) {
			unset( $status[ $slug ] );
			update_option( self::STATUS_OPT, $status, false );
		}

		self::delete_thumb( $slug );
	}

	/* ---- Templates ------------------------------------------------------- */

	/**
	 * Type + front-end URL enrichment, keyed by template title. Grouping/order
	 * come from the native DOM; this only refines the type badge and provides a
	 * representative front-end URL for the card "Open" action.
	 */
	public static function get_templates() {
		$templates = get_block_templates( array(), 'wp_template' );
		$out       = array();

		foreach ( $templates as $t ) {
			$title = is_string( $t->title ) ? $t->title : ( $t->title->rendered ?? $t->slug );
			$entry = array(
				'slug'         => $t->slug,
				'type'         => self::derive_type( $t->slug ),
				'url'          => self::front_end_url( $t->slug ),
				'previewUrl'   => self::preview_url( $t->slug ),
				'thumbUrl'     => self::thumb_url_for( $t->slug ),
				'stale'        => self::is_stale( $t->slug ),
				'status'       => self::get_status( $t->slug ),
				'lastModified' => self::last_modified( $t ),
				'components'   => self::components_list( $t ),
			);
			// Primary key is the stored title; alias keys cover the labels Etch
			// shows for system / CPT templates (e.g. "404 Error Page" → 404),
			// so the board resolves them via its existing title lookup.
			$out[ $title ] = $entry;
			foreach ( self::title_aliases( $t->slug ) as $alias ) {
				if ( ! isset( $out[ $alias ] ) ) {
					$out[ $alias ] = $entry;
				}
			}
		}

		return rest_ensure_response( $out );
	}

	private static function derive_type( $slug ): string {
		if ( in_array( $slug, array( 'index', '404', 'search', 'front-page', 'home' ), true ) ) {
			return 'system';
		}
		if ( 0 === strpos( $slug, 'archive' ) ) {
			return 'archive';
		}
		return 'single';
	}

	/**
	 * Alternate display titles Etch may show for a slug, so title-based lookup
	 * still resolves. Covers the standard system templates and generates
	 * "Single X" / "X Archive" strings per registered post type.
	 */
	private static function title_aliases( $slug ): array {
		$fixed = array(
			'index'      => array( 'Index' ),
			'404'        => array( '404', '404 Error Page', 'Error 404', 'Not Found', 'Page Not Found' ),
			'search'     => array( 'Search', 'Search Results', 'Search results' ),
			'front-page' => array( 'Front Page', 'Homepage', 'Home Page' ),
			'home'       => array( 'Home', 'Blog', 'Blog Home', 'Posts Page' ),
			'single'     => array( 'Single', 'Single Post', 'Single Posts' ),
			'page'       => array( 'Page', 'Pages', 'Single Page' ),
			'archive'    => array( 'Archive', 'Archives' ),
		);
		if ( isset( $fixed[ $slug ] ) ) {
			return $fixed[ $slug ];
		}

		$aliases = array();
		if ( 0 === strpos( $slug, 'single-' ) || 0 === strpos( $slug, 'archive-' ) ) {
			$is_archive = 0 === strpos( $slug, 'archive-' );
			$base       = substr( $slug, $is_archive ? 8 : 7 );
			$pt         = get_post_type_object( $base );
			$singular   = $pt ? $pt->labels->singular_name : ucfirst( str_replace( array( '-', '_' ), ' ', $base ) );
			$plural     = $pt ? $pt->labels->name : $singular . 's';
			$aliases    = $is_archive
				? array( $plural . ' Archive', 'Archive ' . $singular, $plural . ' archive', $plural )
				: array( 'Single ' . $singular, $singular );
		}
		return $aliases;
	}

	/**
	 * Best-effort representative front-end URL for a template slug. Templates
	 * map to query contexts rather than single URLs, so for "single-*" and
	 * "archive-*" we resolve a live example and fall back to the site home.
	 */
	private static function front_end_url( $slug ): string {
		if ( in_array( $slug, array( 'index', 'home', 'front-page' ), true ) ) {
			return home_url( '/' );
		}
		if ( '404' === $slug ) {
			return home_url( '/?p=999999999' );
		}
		if ( 'search' === $slug ) {
			return home_url( '/?s=' );
		}
		if ( 0 === strpos( $slug, 'archive-' ) ) {
			$link = get_post_type_archive_link( substr( $slug, 8 ) );
			return $link ? $link : home_url( '/' );
		}
		if ( 0 === strpos( $slug, 'single-' ) || in_array( $slug, array( 'single', 'page', 'post' ), true ) ) {
			$pt     = 0 === strpos( $slug, 'single-' ) ? substr( $slug, 7 ) : ( 'page' === $slug ? 'page' : 'post' );
			$recent = get_posts( array(
				'post_type'      => $pt,
				'posts_per_page' => 1,
				'post_status'    => 'publish',
				'fields'         => 'ids',
			) );
			if ( ! empty( $recent ) ) {
				$link = get_permalink( $recent[0] );
				if ( $link ) {
					return $link;
				}
			}
		}
		return home_url( '/' );
	}

	/**
	 * Human-readable last-modified date, or null. Only templates saved through
	 * WordPress (source=custom, i.e. they have a wp_template post row) have a
	 * meaningful modified date; unmodified theme-file templates return null
	 * rather than a misleading date.
	 */
	private static function last_modified( $template ) {
		if ( empty( $template->wp_id ) ) {
			return null;
		}
		$post = get_post( $template->wp_id );
		if ( ! $post || '0000-00-00 00:00:00' === $post->post_modified_gmt ) {
			return null;
		}
		return date_i18n( 'M j', strtotime( $post->post_modified ) );
	}

	/* ---- Status (dev-progress annotation: wip / review / ready / live) ---- */

	private static function get_status( $slug ): string {
		$opt = get_option( self::STATUS_OPT, array() );
		$s   = $opt[ $slug ] ?? 'live';
		return in_array( $s, self::STATUSES, true ) ? $s : 'live';
	}

	public static function set_status( $request ) {
		$slug   = sanitize_title( (string) $request->get_param( 'slug' ) );
		$status = sanitize_key( (string) $request->get_param( 'status' ) );
		if ( '' === $slug || ! in_array( $status, self::STATUSES, true ) ) {
			return new WP_Error( 'karo_kit_etch_bad_status', 'Invalid slug or status', array( 'status' => 400 ) );
		}
		$opt = get_option( self::STATUS_OPT, array() );
		if ( 'live' === $status ) {
			unset( $opt[ $slug ] ); // 'live' is the default; no need to store it
		} else {
			$opt[ $slug ] = $status;
		}
		update_option( self::STATUS_OPT, $opt, false );
		return rest_ensure_response( array( 'saved' => true, 'slug' => $slug, 'status' => $status ) );
	}

	/* ---- Shared components ----------------------------------------------- */

	/**
	 * Components referenced by one template, as a list of {id, label}.
	 *
	 * Detection covers WordPress' standard reusable-block/synced-pattern
	 * mechanism (a `core/block` with a `ref` to a `wp_block` post). If Etch's
	 * own component system uses a different underlying block, register its
	 * block name via the 'karo_kit_etch_component_block_names' filter.
	 */
	private static function components_list( $template ): array {
		$content = $template->content ?? '';
		if ( '' === trim( $content ) ) {
			return array();
		}
		$out = array();
		foreach ( self::extract_components( $content ) as $id => $label ) {
			$out[] = array( 'id' => (string) $id, 'label' => $label );
		}
		return $out;
	}

	/** @return array<string,string> component id => label, deduped. */
	private static function extract_components( $content ): array {
		$found = array();
		$names = apply_filters( 'karo_kit_etch_component_block_names', array() ); // e.g. array( 'etch/component' )

		$walk = static function ( $blocks ) use ( &$walk, &$found, $names ) {
			foreach ( $blocks as $b ) {
				$name = $b['blockName'] ?? '';

				if ( 'core/block' === $name && ! empty( $b['attrs']['ref'] ) ) {
					$ref_id = (int) $b['attrs']['ref'];
					$ref    = get_post( $ref_id );
					if ( $ref ) {
						$found[ $ref_id ] = $ref->post_title ? $ref->post_title : ( 'Component #' . $ref_id );
					}
				} elseif ( $name && in_array( $name, $names, true ) ) {
					$label        = ! empty( $b['attrs']['name'] ) ? $b['attrs']['name'] : $name;
					$id           = ! empty( $b['attrs']['id'] ) ? (string) $b['attrs']['id'] : $name;
					$found[ $id ] = $label;
				}

				if ( ! empty( $b['innerBlocks'] ) ) {
					$walk( $b['innerBlocks'] );
				}
			}
		};
		$walk( parse_blocks( $content ) );
		return $found;
	}

	/**
	 * Aggregate component usage across every template: id => { label, usedIn }.
	 * Powers the shared-component layer in Graph view.
	 */
	public static function get_components() {
		$agg = array();
		foreach ( get_block_templates( array(), 'wp_template' ) as $t ) {
			foreach ( self::extract_components( $t->content ?? '' ) as $id => $label ) {
				$key = (string) $id;
				if ( ! isset( $agg[ $key ] ) ) {
					$agg[ $key ] = array( 'label' => $label, 'usedIn' => array() );
				}
				$agg[ $key ]['usedIn'][] = $t->slug;
			}
		}
		return rest_ensure_response( $agg );
	}

	/* ---- Column order ---------------------------------------------------- */

	public static function get_order() {
		return rest_ensure_response( get_option( self::ORDER_OPT, array() ) );
	}

	public static function set_order( $request ) {
		$order = array_map( 'sanitize_key', (array) $request->get_param( 'order' ) );
		update_option( self::ORDER_OPT, $order, false );
		return rest_ensure_response( array( 'saved' => true, 'order' => $order ) );
	}

	/* ---- Standalone template preview (screenshot target) ----------------- */

	/** Public URL that renders a template standalone for screenshotting. */
	private static function preview_url( $slug ): string {
		return add_query_arg( 'karo_kit_etch_preview', rawurlencode( $slug ), home_url( '/' ) );
	}

	/**
	 * Render a template standalone at /?karo_kit_etch_preview={slug}. Must be
	 * public so the screenshot service can reach it; only renders known
	 * template slugs and is marked noindex.
	 */
	public static function maybe_render_preview(): void {
		$slug = sanitize_title( (string) get_query_var( 'karo_kit_etch_preview' ) );
		if ( '' === $slug ) {
			return;
		}

		header( 'X-Robots-Tag: noindex, nofollow', true );

		$template = self::find_template( $slug );
		if ( ! $template ) {
			status_header( 404 );
			echo 'Template not found';
			exit;
		}

		self::setup_preview_context( $slug );
		status_header( 200 );

		$content = $template->content ?? '';
		echo '<!doctype html>';
		echo '<html ' . get_language_attributes() . '>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<head><meta charset="' . esc_attr( get_bloginfo( 'charset' ) ) . '">';
		echo '<meta name="viewport" content="width=1280, initial-scale=1">';
		wp_head();
		echo '</head><body class="karo-kit-etch-preview">';
		echo do_blocks( $content ); // phpcs:ignore WordPress.Security.EscapeOutput
		wp_footer();
		echo '</body></html>';
		exit;
	}

	private static function find_template( $slug ) {
		$template = get_block_template( get_stylesheet() . '//' . $slug, 'wp_template' );
		if ( $template ) {
			return $template;
		}
		foreach ( get_block_templates( array(), 'wp_template' ) as $t ) {
			if ( $t->slug === $slug ) {
				return $t;
			}
		}
		return null;
	}

	/**
	 * Give dynamic templates a representative context so post-content/title
	 * blocks render something. Best-effort — enough for a thumbnail.
	 */
	private static function setup_preview_context( $slug ): void {
		$pt = null;
		if ( 0 === strpos( $slug, 'single-' ) ) {
			$pt = substr( $slug, 7 );
		} elseif ( 'single' === $slug ) {
			$pt = 'post';
		} elseif ( 'page' === $slug ) {
			$pt = 'page';
		}
		if ( ! $pt ) {
			return;
		}
		$recent = get_posts( array( 'post_type' => $pt, 'posts_per_page' => 1, 'post_status' => 'publish' ) );
		if ( ! empty( $recent ) ) {
			global $post;
			$post = $recent[0]; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
			setup_postdata( $post );
		}
	}

	/* ---- Thumbnails ------------------------------------------------------ */

	/** On-demand generation for one template, called by the board for missing/stale thumbs. */
	public static function make_thumbnail( $request ) {
		$slug = sanitize_title( (string) $request->get_param( 'slug' ) );
		if ( '' === $slug ) {
			return new WP_Error( 'karo_kit_etch_bad_slug', 'Missing slug', array( 'status' => 400 ) );
		}
		$result = self::generate_thumbnail( $slug );
		if ( is_array( $result ) && ! empty( $result['pending'] ) ) {
			return rest_ensure_response( array( 'pending' => true ) );
		}
		if ( is_wp_error( $result ) ) {
			return new WP_Error( 'karo_kit_etch_thumb_failed', $result->get_error_message(), array( 'status' => 500 ) );
		}

		$opt          = get_option( self::THUMB_OPT, array() );
		$opt[ $slug ] = array( 'file' => basename( $result ), 'time' => time(), 'saves' => 0, 'stale' => false );
		update_option( self::THUMB_OPT, $opt, false );

		return rest_ensure_response( array( 'thumbUrl' => self::thumb_url_for( $slug ) ) );
	}

	private static function thumb_dir(): array {
		$updir = wp_upload_dir();
		return array(
			'path' => trailingslashit( $updir['basedir'] ) . self::THUMB_DIR,
			'url'  => trailingslashit( $updir['baseurl'] ) . self::THUMB_DIR,
		);
	}

	private static function thumb_url_for( $slug ) {
		$opt = get_option( self::THUMB_OPT, array() );
		if ( empty( $opt[ $slug ]['file'] ) ) {
			return null;
		}
		$dir  = self::thumb_dir();
		$path = trailingslashit( $dir['path'] ) . $opt[ $slug ]['file'];
		if ( ! file_exists( $path ) ) {
			return null;
		}
		return trailingslashit( $dir['url'] ) . $opt[ $slug ]['file'] . '?t=' . (int) $opt[ $slug ]['time'];
	}

	private static function is_stale( $slug ): bool {
		$opt = get_option( self::THUMB_OPT, array() );
		return ! empty( $opt[ $slug ]['stale'] );
	}

	/** Remove a cached thumbnail (file + option entry). */
	private static function delete_thumb( $slug ): void {
		$opt = get_option( self::THUMB_OPT, array() );
		if ( ! isset( $opt[ $slug ] ) ) {
			return;
		}
		if ( ! empty( $opt[ $slug ]['file'] ) ) {
			$dir  = self::thumb_dir();
			$path = trailingslashit( $dir['path'] ) . $opt[ $slug ]['file'];
			if ( file_exists( $path ) ) {
				wp_delete_file( $path );
			}
		}
		unset( $opt[ $slug ] );
		update_option( self::THUMB_OPT, $opt, false );
	}

	/** How many templates currently have a usable thumbnail. */
	public static function thumb_count(): int {
		$n = 0;
		foreach ( array_keys( get_option( self::THUMB_OPT, array() ) ) as $slug ) {
			if ( self::thumb_url_for( $slug ) ) {
				$n++;
			}
		}
		return $n;
	}

	/**
	 * Fetch a screenshot from WordPress.com mShots and store a resized JPEG.
	 * mShots generates asynchronously: while it is still working it redirects
	 * or returns a small placeholder, so this returns array('pending'=>true)
	 * and the board retries.
	 *
	 * Note: the target URL must be publicly reachable — mShots runs on
	 * WordPress.com's servers and cannot see local or staging sites.
	 *
	 * @return string|array|WP_Error Saved file path, array('pending'=>true), or error.
	 */
	private static function generate_thumbnail( $slug ) {
		$url = self::preview_url( $slug );

		/** Filter the source URL sent to the screenshot service. */
		$url = apply_filters( 'karo_kit_etch_thumbnail_source_url', $url, $slug );

		// Verify the preview URL actually returns 200 before spending an mShots
		// call — otherwise mShots returns its "404" graphic and we would cache
		// that as if it were a real screenshot. If the loopback check itself
		// fails (some hosts block it), fall through and let mShots try anyway.
		$check = wp_remote_get( $url, array( 'timeout' => 12, 'redirection' => 3 ) );
		if ( ! is_wp_error( $check ) ) {
			$ccode = (int) wp_remote_retrieve_response_code( $check );
			if ( 200 !== $ccode ) {
				self::delete_thumb( $slug ); // drop any previously-cached bad image
				return new WP_Error(
					'karo_kit_etch_preview_status',
					'Preview returned HTTP ' . $ccode . ' for ' . esc_html( $url ) . ' — not screenshotting.'
				);
			}
		}

		$mshots = 'https://s0.wp.com/mshots/v1/' . rawurlencode( $url ) . '?w=1280&h=720';

		$resp = wp_remote_get( $mshots, array(
			'timeout'     => 20,
			'redirection' => 0, // a redirect means "still generating"
		) );
		if ( is_wp_error( $resp ) ) {
			return new WP_Error( 'karo_kit_etch_mshots_http', 'mShots request failed: ' . $resp->get_error_message() );
		}

		$code  = (int) wp_remote_retrieve_response_code( $resp );
		$body  = wp_remote_retrieve_body( $resp );
		$ctype = (string) wp_remote_retrieve_header( $resp, 'content-type' );

		// Still generating: redirect, non-image, or a tiny placeholder image.
		if ( $code >= 300 && $code < 400 ) {
			return array( 'pending' => true );
		}
		if ( 200 !== $code || false === strpos( $ctype, 'image' ) || strlen( $body ) < 10000 ) {
			return array( 'pending' => true );
		}

		$dir = self::thumb_dir();
		wp_mkdir_p( $dir['path'] );
		$raw   = trailingslashit( $dir['path'] ) . $slug . '-raw.img';
		$final = trailingslashit( $dir['path'] ) . $slug . '.jpg';

		if ( false === file_put_contents( $raw, $body ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return new WP_Error( 'karo_kit_etch_write_failed', 'Could not write thumbnail to ' . esc_html( $dir['path'] ) );
		}

		// Normalise/crop to a 16:9 JPEG; if the body was not a valid image,
		// treat it as a placeholder and keep retrying.
		$editor = wp_get_image_editor( $raw );
		if ( is_wp_error( $editor ) ) {
			wp_delete_file( $raw );
			return array( 'pending' => true );
		}
		$editor->resize( 640, 360, true );
		$editor->set_quality( 82 );
		$saved = $editor->save( $final, 'image/jpeg' );
		wp_delete_file( $raw );
		if ( is_wp_error( $saved ) || empty( $saved['path'] ) ) {
			return new WP_Error( 'karo_kit_etch_save_failed', 'Could not save resized thumbnail' );
		}
		return $saved['path'];
	}
}
