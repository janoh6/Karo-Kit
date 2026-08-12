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

	const ENABLED_OPT    = 'karo_kit_etch_board_on';
	const ORDER_OPT      = 'karo_kit_etch_order';
	const THUMB_OPT      = 'karo_kit_etch_thumbs';
	const STATUS_OPT     = 'karo_kit_etch_status';
	const THRESHOLD_OPT  = 'karo_kit_etch_thumb_threshold';
	const AUTO_LIMIT_OPT = 'karo_kit_etch_thumb_auto_limit';

	/**
	 * Upload subdirectory for generated thumbnails. Deliberately keeps the
	 * standalone plugin's name so images already on disk survive the move —
	 * on a local site they cannot be regenerated (see generate_thumbnail).
	 */
	const THUMB_DIR = 'etb-thumbnails';

	/** Cached component index; rebuilt whenever a template or component is saved. */
	const COMPONENTS_CACHE = 'karo_kit_etch_component_index';

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

		// Component usage is derived from template and component markup, so any
		// edit to either invalidates it.
		add_action( 'save_post_wp_template', array( __CLASS__, 'flush_component_index' ) );
		add_action( 'save_post_wp_block', array( __CLASS__, 'flush_component_index' ) );
		add_action( 'deleted_post', array( __CLASS__, 'flush_component_index_on_delete' ), 10, 2 );
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

		$nonce = wp_create_nonce( 'wp_rest' );

		return array(
			'styles'  => array( Karo_Kit_Etch::asset_url( 'assets/etch/board.css' ) ),
			'data'    => array(
				'name'  => 'KaroKitEtchData',
				'value' => array(
					'restBase'      => esc_url_raw( rest_url( self::REST_NAMESPACE . '/etch' ) ),
					'nonce'         => $nonce,
					'autoThumbLimit' => self::get_auto_limit(),
				),
			),
			// Bridge first (exposes the bridge global), then the board that consumes it.
			'scripts' => array(
				Karo_Kit_Etch::asset_url( 'assets/etch/vendor/snapdom.min.js' ),
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
				'slug'  => array( 'required' => true, 'type' => 'string' ),
				'image' => array( 'required' => true, 'type' => 'string' ),
			),
		) );

		// Minted on demand rather than handed out with the template list: a
		// token only lives ~60s, so one issued at page load would be stale long
		// before anyone captured anything.
		register_rest_route( $ns, '/etch/preview-url', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'get_preview_url' ),
			'permission_callback' => array( __CLASS__, 'can_edit' ),
			'args'                => array(
				'slug' => array( 'required' => true, 'type' => 'string' ),
			),
		) );

		// Guarded by a minted token rather than the usual capability check: the
		// capture library issues this request itself, and decides whether to
		// send credentials from the *original* image's origin — so for the
		// cross-origin images this exists to serve, it strips cookies even
		// though the proxy is same-origin. Cookie auth therefore can't apply.
		register_rest_route( $ns, '/etch/image-proxy', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'proxy_image' ),
			'permission_callback' => array( __CLASS__, 'check_proxy_token' ),
			'args'                => array(
				'url'   => array( 'required' => true, 'type' => 'string' ),
				'token' => array( 'required' => true, 'type' => 'string' ),
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

	/**
	 * How many missing/stale thumbnails the board captures on its own per
	 * visit, before leaving the rest for a manual "Generate thumbnail".
	 *
	 * Capture now happens in the admin's own browser rather than on a remote
	 * server, so every auto-queued thumbnail is real time in that tab — a
	 * board with a lot of missing thumbnails at once (a fresh install, or the
	 * first open after upgrading from the old remote-screenshot version) would
	 * otherwise start working through all of them unasked. 0 turns auto-
	 * generation off entirely; every thumbnail is then a deliberate action.
	 */
	public static function get_auto_limit(): int {
		return max( 0, (int) get_option( self::AUTO_LIMIT_OPT, 6 ) );
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

	/** Drop the cached component index. */
	public static function flush_component_index(): void {
		delete_transient( self::COMPONENTS_CACHE );
	}

	/**
	 * Same, on delete — but only for the two post types the index is built
	 * from. `deleted_post` fires for everything, and routine revision cleanup
	 * would otherwise throw the cache away constantly.
	 *
	 * @param int          $post_id Deleted post ID.
	 * @param WP_Post|null $post    The post, where WordPress passed one.
	 */
	public static function flush_component_index_on_delete( $post_id, $post = null ): void {
		$type = $post instanceof WP_Post ? $post->post_type : get_post_type( $post_id );
		if ( 'wp_template' === $type || 'wp_block' === $type ) {
			self::flush_component_index();
		}
	}

	/**
	 * Everything the board knows about component usage, built in one pass.
	 *
	 * Both board endpoints need this, and each was parsing the same markup
	 * separately — the templates route to list each template's composition, the
	 * components route to aggregate usage — so every template's blocks were
	 * parsed twice per board open, on top of every component's blocks. Parsing
	 * is the expensive part (on a real build here, ~470 KB of markup through
	 * parse_blocks() each time), and none of it changes until something is
	 * saved, which is exactly when the transient is dropped.
	 *
	 * @return array{byTemplate:array<string,array>,components:array<string,array>}
	 */
	private static function component_index(): array {
		$cached = get_transient( self::COMPONENTS_CACHE );
		if ( is_array( $cached ) && isset( $cached['byTemplate'], $cached['components'] ) ) {
			return $cached;
		}

		$by_template = array();
		$components  = array();

		$record = static function ( $key, $label, $bucket, $where ) use ( &$components ) {
			if ( ! isset( $components[ $key ] ) ) {
				$components[ $key ] = array( 'label' => $label, 'usedIn' => array(), 'usedInComponents' => array() );
			}
			// A component referenced twice in the same place is one usage, not two.
			if ( ! in_array( $where, $components[ $key ][ $bucket ], true ) ) {
				$components[ $key ][ $bucket ][] = $where;
			}
		};

		foreach ( get_block_templates( array(), 'wp_template' ) as $t ) {
			$list = array();
			foreach ( self::extract_components( $t->content ?? '' ) as $id => $label ) {
				$list[] = array( 'id' => (string) $id, 'label' => $label );
				$record( (string) $id, $label, 'usedIn', $t->slug );
			}
			$by_template[ $t->slug ] = $list;
		}

		$posts = get_posts( array(
			'post_type'        => 'wp_block',
			'post_status'      => array( 'publish', 'draft', 'private' ),
			'posts_per_page'   => -1,
			'suppress_filters' => false,
		) );

		foreach ( $posts as $p ) {
			foreach ( self::extract_components( $p->post_content ?? '' ) as $id => $label ) {
				if ( (string) $id === (string) $p->ID ) {
					continue; // a component can't count as nesting itself
				}
				$record( (string) $id, $label, 'usedInComponents', $p->post_title ? $p->post_title : ( 'Component #' . $p->ID ) );
			}
		}

		$index = array( 'byTemplate' => $by_template, 'components' => $components );
		set_transient( self::COMPONENTS_CACHE, $index, DAY_IN_SECONDS );

		return $index;
	}

	/** Components referenced by one template, as a list of {id, label}. */
	private static function components_list( $template ): array {
		$index = self::component_index();
		return $index['byTemplate'][ $template->slug ] ?? array();
	}

	/** @return array<string,string> component id => label, deduped. */
	private static function extract_components( $content ): array {
		$found = array();

		/**
		 * Block names that reference a reusable component post.
		 *
		 * Etch's own components are `etch/component` blocks carrying a `ref` to
		 * a wp_block post — the same indirection `core/block` uses for WordPress
		 * synced patterns, under a different block name. Detecting only
		 * `core/block` (as this did) found nothing at all on an Etch site, which
		 * left the graph's whole shared-component layer permanently empty.
		 */
		$names = apply_filters( 'karo_kit_etch_component_block_names', array( 'core/block', 'etch/component' ) );

		$walk = static function ( $blocks ) use ( &$walk, &$found, $names ) {
			foreach ( $blocks as $b ) {
				$name = $b['blockName'] ?? '';

				if ( $name && in_array( $name, $names, true ) ) {
					if ( ! empty( $b['attrs']['ref'] ) ) {
						$ref_id = (int) $b['attrs']['ref'];
						$ref    = get_post( $ref_id );
						if ( $ref ) {
							$found[ $ref_id ] = $ref->post_title ? $ref->post_title : ( 'Component #' . $ref_id );
						}
					} elseif ( ! empty( $b['attrs']['id'] ) ) {
						// A registered block that identifies itself inline rather
						// than pointing at a post. An id is required: Etch also
						// emits ref-less component blocks (unsynced placeholders),
						// and keying those on the bare block name would collapse
						// every one of them into a single bogus "etch/component".
						$id           = (string) $b['attrs']['id'];
						$found[ $id ] = ! empty( $b['attrs']['name'] ) ? $b['attrs']['name'] : $name;
					}
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
	 * Aggregate component usage: id => { label, usedIn, usedInComponents }.
	 * Powers the shared-component layer in Graph view.
	 *
	 * Only components something actually references are reported. Etch sites
	 * carry far more components than templates (59 against 7 on the build this
	 * was measured on, most of them unused), so listing every component post
	 * would send mostly-empty rows the graph immediately discards.
	 *
	 * Usage is counted from two directions: a component nested inside another
	 * component is still in use, and the graph shows that in a node's panel as
	 * blast radius that template links alone would miss.
	 */
	public static function get_components() {
		$index = self::component_index();
		return rest_ensure_response( $index['components'] );
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

	/* ---- Standalone template preview (capture target) -------------------- */

	/** How long a minted preview link stays valid before it 404s outright. */
	const PREVIEW_TOKEN_TTL = 60;

	/** Transient prefix for image-proxy tokens, issued with each preview URL. */
	const PROXY_TOKEN_PREFIX = 'karo_kit_etch_proxy_';

	/**
	 * Time-boxed URL that renders a template standalone.
	 *
	 * This route was once keyed on the template slug directly — permanent and
	 * guessable (`index`, `single`, `404`...) — and had to be public so an
	 * outside screenshot service could fetch it, which together meant anyone
	 * who guessed a slug could browse every template on an unlaunched or NDA'd
	 * site indefinitely. Captures now happen in the admin's own browser, so the
	 * route requires a capability; the token adds a second gate, scoping a link
	 * to one template for PREVIEW_TOKEN_TTL seconds.
	 *
	 * Not single-use: the capture may read it more than once (the iframe load
	 * plus any retry), and the TTL is what actually bounds exposure.
	 */
	private static function preview_url( $slug ): string {
		$token = wp_generate_password( 32, false, false );
		set_transient( 'karo_kit_etch_preview_' . $token, $slug, self::PREVIEW_TOKEN_TTL );
		return add_query_arg( 'karo_kit_etch_preview', $token, home_url( '/' ) );
	}

	/**
	 * Render a template standalone at /?karo_kit_etch_preview={token}.
	 *
	 * Requires the same capability as the rest of the board. This route existed
	 * only because an outside screenshot service had to fetch it with no login
	 * of its own, which forced it to be public; thumbnails are now captured in
	 * the admin's own browser, so the one reason to leave it open is gone. The
	 * short-lived token stays as a second gate on top.
	 */
	public static function maybe_render_preview(): void {
		$token = sanitize_text_field( (string) get_query_var( 'karo_kit_etch_preview' ) );
		if ( '' === $token ) {
			return;
		}

		header( 'X-Robots-Tag: noindex, nofollow', true );

		if ( ! self::can_edit() ) {
			status_header( 404 );
			echo 'Template not found';
			exit;
		}

		// The capture now runs as a signed-in administrator, so the admin bar
		// would render into the thumbnail and push the whole page down by its
		// own height. The old remote screenshotter was a logged-out stranger and
		// never saw it.
		add_filter( 'show_admin_bar', '__return_false' );

		$slug     = get_transient( 'karo_kit_etch_preview_' . $token );
		$template = $slug ? self::find_template( (string) $slug ) : null;
		if ( ! $template ) {
			status_header( 404 );
			echo 'Template not found';
			exit;
		}

		self::setup_preview_context( (string) $slug );
		status_header( 200 );

		// Render the blocks BEFORE the head, for the reason core's own
		// template-canvas.php gives where it does exactly the same thing:
		// "This needs to run before <head> so that blocks can add scripts and
		// styles in wp_head()." Etch is a case in point — it accumulates the
		// CSS for each element as that element renders, then prints the lot on
		// wp_head at priority 99. Rendering into the body after wp_head meant
		// that hook fired with nothing collected yet, so all it emitted were
		// the handful of always-on :root rules (~3 KB of the ~40 KB a real
		// page gets) and every class-based layout rule was missing — which is
		// why captures came out as a bare left-aligned stack.
		$template_html = self::render_template_html( (string) ( $template->content ?? '' ) );

		echo '<!doctype html>';
		echo '<html ' . get_language_attributes() . '>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<head><meta charset="' . esc_attr( get_bloginfo( 'charset' ) ) . '">';
		echo '<meta name="viewport" content="width=1280, initial-scale=1">';
		wp_head();
		// body_class() carries the theme/ACSS's own selectors (`home`,
		// `wp-singular`, `page-template-default`, `wp-theme-*`, …) that real
		// front-end pages get for free; this route rendered a bare
		// class="karo-kit-etch-preview" instead, so anything keyed off one of
		// them styled differently here than on the page being previewed.
		// Nothing errors when they're missing — it just quietly renders wrong,
		// which is why it went unnoticed until someone looked at a thumbnail.
		echo '<body ' . self::body_class_attr( 'karo-kit-etch-preview' ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo $template_html; // phpcs:ignore WordPress.Security.EscapeOutput
		wp_footer();
		echo '</body></html>';
		exit;
	}

	/**
	 * Run a template's block markup through the same pipeline core applies in
	 * get_the_block_template_html(), including the .wp-site-blocks wrapper
	 * themes hang descendant styles off (`.wp-site-blocks > *`). Core's
	 * skip-link is deliberately left out: it exists for keyboard navigation
	 * on a real page and would only add markup to a thumbnail.
	 */
	private static function render_template_html( string $content ): string {
		global $wp_embed;

		if ( $wp_embed instanceof WP_Embed ) {
			$content = $wp_embed->run_shortcode( $content );
			$content = $wp_embed->autoembed( $content );
		}
		$content = shortcode_unautop( $content );
		$content = do_shortcode( $content );
		$content = do_blocks( $content );
		$content = wptexturize( $content );
		$content = convert_smilies( $content );
		$content = wp_filter_content_tags( $content, 'template' );
		$content = str_replace( ']]>', ']]&gt;', $content );

		return '<div class="wp-site-blocks">' . $content . '</div>';
	}

	/** WordPress's own get_body_class(), pre-joined into a class="" attribute. */
	private static function body_class_attr( string $extra ): string {
		return 'class="' . esc_attr( implode( ' ', get_body_class( $extra ) ) ) . '"';
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
	 *
	 * core/post-content (what a template's main content usually boils down
	 * to) reads its post from block context, not just the $post global —
	 * and WordPress only seeds that context from $post if $post is actually
	 * a WP_Post when render_block() runs. Every slug needs something set,
	 * not only the single-prefixed/single/page ones: "index" here is itself
	 * just a bare core/post-content block (see the theme's templates/index.html),
	 * so leaving $post unset for it meant its whole main content silently
	 * rendered as an empty <main>, identical across every capture.
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

		$representative = null;
		if ( $pt ) {
			$recent         = get_posts( array( 'post_type' => $pt, 'posts_per_page' => 1, 'post_status' => 'publish' ) );
			$representative = $recent[0] ?? null;
		} else {
			// No slug-specific post type — fall back to whatever the site
			// would actually show as its front page, so templates like
			// "index" that are just a bare post-content block still have
			// something real to render instead of coming up empty.
			if ( 'page' === get_option( 'show_on_front' ) ) {
				$front_id       = (int) get_option( 'page_on_front' );
				$representative = $front_id ? get_post( $front_id ) : null;
			}
			if ( ! $representative ) {
				$recent         = get_posts( array( 'post_type' => 'page', 'posts_per_page' => 1, 'post_status' => 'publish' ) );
				$representative = $recent[0] ?? null;
			}
			if ( ! $representative ) {
				$recent         = get_posts( array( 'post_type' => 'post', 'posts_per_page' => 1, 'post_status' => 'publish' ) );
				$representative = $recent[0] ?? null;
			}
		}

		if ( $representative ) {
			global $post;
			$post = $representative; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
			setup_postdata( $post );
		}
	}

	/* ---- Thumbnails ------------------------------------------------------ */

	/** Largest capture accepted, before resizing. Generous for a 1280px page. */
	const MAX_CAPTURE_BYTES = 12582912; // 12 MB

	/**
	 * Store a thumbnail captured by the board.
	 *
	 * The image arrives as a data URL produced in the browser: the board renders
	 * the template in a hidden iframe and rasterises it there. Previously this
	 * endpoint took only a slug and asked WordPress.com's mShots service to
	 * screenshot the site from outside, which meant template markup left the
	 * site altogether and the preview route had to be publicly reachable for a
	 * stranger's browser to fetch. Capturing in the admin's own already-
	 * authenticated browser removes both of those.
	 */
	public static function make_thumbnail( $request ) {
		$slug = sanitize_title( (string) $request->get_param( 'slug' ) );
		if ( '' === $slug ) {
			return new WP_Error( 'karo_kit_etch_bad_slug', 'Missing slug', array( 'status' => 400 ) );
		}

		$data_url = (string) $request->get_param( 'image' );
		if ( ! preg_match( '#^data:image/png;base64,#', $data_url ) ) {
			return new WP_Error( 'karo_kit_etch_bad_image', 'Expected a PNG data URL', array( 'status' => 400 ) );
		}

		$raw = base64_decode( substr( $data_url, strlen( 'data:image/png;base64,' ) ), true );
		if ( false === $raw || '' === $raw ) {
			return new WP_Error( 'karo_kit_etch_bad_image', 'Could not decode image', array( 'status' => 400 ) );
		}
		if ( strlen( $raw ) > self::MAX_CAPTURE_BYTES ) {
			return new WP_Error( 'karo_kit_etch_image_too_big', 'Capture exceeds the size limit', array( 'status' => 413 ) );
		}
		// Trust the bytes, not the declared type: the data URL prefix is just a
		// string the client chose.
		if ( "\x89PNG\r\n\x1a\n" !== substr( $raw, 0, 8 ) ) {
			return new WP_Error( 'karo_kit_etch_bad_image', 'Payload is not a PNG', array( 'status' => 400 ) );
		}

		$result = self::store_capture( $slug, $raw );
		if ( is_wp_error( $result ) ) {
			return new WP_Error( 'karo_kit_etch_thumb_failed', $result->get_error_message(), array( 'status' => 500 ) );
		}

		$opt          = get_option( self::THUMB_OPT, array() );
		$opt[ $slug ] = array( 'file' => basename( $result ), 'time' => time(), 'saves' => 0, 'stale' => false );
		update_option( self::THUMB_OPT, $opt, false );

		return rest_ensure_response( array( 'thumbUrl' => self::thumb_url_for( $slug ) ) );
	}

	/**
	 * A fresh, short-lived preview URL for one template, plus the image-proxy
	 * prefix the capture should use. Both expire together.
	 */
	public static function get_preview_url( $request ) {
		$slug = sanitize_title( (string) $request->get_param( 'slug' ) );
		if ( '' === $slug || ! self::find_template( $slug ) ) {
			return new WP_Error( 'karo_kit_etch_bad_slug', 'Unknown template', array( 'status' => 404 ) );
		}

		$token = wp_generate_password( 32, false, false );
		set_transient( self::PROXY_TOKEN_PREFIX . $token, 1, self::PREVIEW_TOKEN_TTL );

		return rest_ensure_response( array(
			'url' => self::preview_url( $slug ),
			// Trailing separator: the capture library appends `url=<encoded>`.
			'proxy' => add_query_arg(
				array( 'token' => $token ),
				rest_url( self::REST_NAMESPACE . '/etch/image-proxy' )
			) . '&',
		) );
	}

	/** Does this request carry a live proxy token? */
	public static function check_proxy_token( $request ): bool {
		$token = sanitize_text_field( (string) $request->get_param( 'token' ) );
		if ( '' === $token ) {
			return false;
		}
		return (bool) get_transient( self::PROXY_TOKEN_PREFIX . $token );
	}

	/**
	 * Fetch a cross-origin image server-side and re-serve it same-origin.
	 *
	 * The capture library reads pixels back out of a canvas, and the browser
	 * refuses that for images from another origin unless that origin opts in
	 * with CORS headers — which pattern-library and CDN hosts generally do not.
	 * Those images would silently come out blank. Re-serving them from this
	 * site's own origin makes them same-origin as far as the canvas is
	 * concerned, so they capture normally.
	 *
	 * wp_safe_remote_get() is deliberate: it refuses private, loopback and
	 * link-local addresses, so an authenticated caller can't turn this into a
	 * probe of the host's internal network.
	 */
	public static function proxy_image( $request ) {
		$url = esc_url_raw( (string) $request->get_param( 'url' ) );
		if ( '' === $url || ! wp_http_validate_url( $url ) ) {
			return new WP_Error( 'karo_kit_etch_bad_proxy_url', 'Invalid URL', array( 'status' => 400 ) );
		}

		$resp = wp_safe_remote_get( $url, array( 'timeout' => 10, 'redirection' => 2 ) );
		if ( is_wp_error( $resp ) ) {
			return new WP_Error( 'karo_kit_etch_proxy_failed', $resp->get_error_message(), array( 'status' => 502 ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$type = (string) wp_remote_retrieve_header( $resp, 'content-type' );
		$body = wp_remote_retrieve_body( $resp );

		if ( 200 !== $code || 0 !== strpos( $type, 'image/' ) ) {
			return new WP_Error( 'karo_kit_etch_proxy_not_image', 'Upstream did not return an image', array( 'status' => 502 ) );
		}
		if ( strlen( $body ) > self::MAX_CAPTURE_BYTES ) {
			return new WP_Error( 'karo_kit_etch_proxy_too_big', 'Upstream image too large', array( 'status' => 502 ) );
		}

		// Streamed straight out rather than returned as REST JSON: the caller is
		// an <img>/fetch inside the capture, which wants image bytes.
		header( 'Content-Type: ' . sanitize_text_field( $type ) );
		header( 'Cache-Control: private, max-age=300' );
		header( 'X-Content-Type-Options: nosniff' );
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
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
	 * Write a captured PNG out as the slug's 4:3 JPEG thumbnail.
	 *
	 * 4:3 matches the card's own thumbnail box (see `.etb-card__thumb` in
	 * assets/etch/board.css). It has to: the card fills that box with
	 * `object-fit: cover`, so storing a different shape means the browser
	 * crops the difference away. This used to write 16:9, which cover then
	 * trimmed down the sides of — on top of the top-band crop below — leaving
	 * a thin slice of the page rather than a view of it.
	 *
	 * @param string $slug Template slug.
	 * @param string $png  Raw PNG bytes, already validated by the caller.
	 * @return string|WP_Error Saved file path, or an error.
	 */
	private static function store_capture( string $slug, string $png ) {
		$dir = self::thumb_dir();
		if ( ! wp_mkdir_p( $dir['path'] ) ) {
			return new WP_Error( 'karo_kit_etch_mkdir_failed', 'Could not create ' . esc_html( $dir['path'] ) );
		}

		$raw   = trailingslashit( $dir['path'] ) . $slug . '-raw.png';
		$final = trailingslashit( $dir['path'] ) . $slug . '.jpg';

		if ( false === file_put_contents( $raw, $png ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return new WP_Error( 'karo_kit_etch_write_failed', 'Could not write thumbnail to ' . esc_html( $dir['path'] ) );
		}

		$editor = wp_get_image_editor( $raw );
		if ( is_wp_error( $editor ) ) {
			wp_delete_file( $raw );
			return new WP_Error( 'karo_kit_etch_editor_failed', 'Capture was not a readable image' );
		}

		// A capture is the full page height, so crop to the top 4:3 band rather
		// than squashing a very tall page into a card-shaped thumbnail.
		$size = $editor->get_size();
		$w    = (int) ( $size['width'] ?? 0 );
		$h    = (int) ( $size['height'] ?? 0 );
		if ( $w > 0 && $h > 0 ) {
			$band = (int) round( $w * 3 / 4 );
			if ( $h > $band ) {
				$editor->crop( 0, 0, $w, $band );
			}
		}

		$editor->resize( 640, 480, true );
		$editor->set_quality( 82 );
		$saved = $editor->save( $final, 'image/jpeg' );
		wp_delete_file( $raw );

		if ( is_wp_error( $saved ) || empty( $saved['path'] ) ) {
			return new WP_Error( 'karo_kit_etch_save_failed', 'Could not save resized thumbnail' );
		}
		return $saved['path'];
	}
}
