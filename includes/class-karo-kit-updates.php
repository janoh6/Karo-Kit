<?php
/**
 * Karo Kit — update checks from GitHub Releases.
 *
 * There's no wp.org listing for this plugin, so nothing polls for a newer
 * version unless something here does. Wraps the vendored Plugin Update
 * Checker library rather than reimplementing it — GitHub's release/tag API,
 * version comparison, and the "Update now" flow in wp-admin are exactly the
 * kind of thing not worth writing from scratch.
 *
 * @package Karo_Kit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Karo_Kit_Updates {

	const REPO = 'https://github.com/janoh6/Karo-Kit/';

	/**
	 * Must run outside any hook, or on plugins_loaded at the very latest —
	 * the library's own docs are explicit that initialising it only inside an
	 * admin_* hook means WP-CLI and the background update-check cron never
	 * see it, only a logged-in admin who happens to be looking at wp-admin.
	 */
	public static function init(): void {
		require_once KARO_KIT_DIR . 'includes/vendor/plugin-update-checker/plugin-update-checker.php';

		$checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			self::REPO,
			KARO_KIT_FILE,
			'karo-kit'
		);

		// The zip .github/workflows/release.yml attaches to each release —
		// built from just the plugin's runtime files, correctly rooted at a
		// karo-kit/ folder — rather than GitHub's own auto-generated source
		// archive, which unpacks to Karo-Kit-x.y.z/ and still carries the dev
		// doc and CI config that don't belong in an installed copy.
		$checker->getVcsApi()->enableReleaseAssets( '/\.zip$/i' );

		/**
		 * A GitHub token, only needed for as long as the repo stays private.
		 * Deliberately not shipped here: a public repo needs none of this,
		 * and a private one would mean every site running Karo Kit holding
		 * the same shared token — a real thing to distribute and rotate — so
		 * that stays an opt-in choice made in wp-config, not built in.
		 *
		 *   add_filter( 'karo_kit_update_token', fn() => 'github_pat_xxxx' );
		 */
		$token = (string) apply_filters( 'karo_kit_update_token', '' );
		if ( '' !== $token ) {
			$checker->setAuthentication( $token );
		}
	}
}
