=== Karo Kit ===
Contributors: karo
Tags: login, registration, maintenance, coming-soon, etch, acss
Requires at least: 6.8
Requires PHP: 8.4
Stable tag: 0.7.0
License: GPLv2 or later

Modular site-utility kit for Etch / ACSS WordPress builds.

== Description ==

Karo Kit is a modular utility plugin. Each feature is a self-contained
"module" that registers itself with the core and contributes its own tabs
to the Karo Kit dashboard (its own top-level admin menu item).

= Module 1 - Gate =

Site access control:

* Front-end login & registration wired to pages you pick from dropdowns
  (works with standard Pages and Meta Box / other public custom post types).
* Routes wp-login.php to your chosen pages, with guards that preserve logout,
  password reset and post-password flows, plus a ?karo=default escape hatch.
* Maintenance / coming-soon gate: render any page as a 503 maintenance splash
  or a 200 indexable coming-soon screen, with admins bypassing automatically.

Shortcodes for building the pages in Etch:

* [karo_kit_field action="login|register"] - nonce + honeypot + redirect passthrough
* [karo_kit_message] - shows validation / error notices
* [karo_kit_logout] - a logout link

= Module 2 - Etch =

Builder integration:

* Template Board - replaces Etch's Templates screen with a board view: columns
  mirrored from Etch's own grouping, drag-to-reorder, search, per-template
  status (WIP / Review / Ready / Live), thumbnails, and a graph of shared
  component usage. Create, open, delete and reset are delegated back to Etch's
  native controls rather than reimplemented.
* Reference - every dynamic-data binding and shortcode the kit exposes to Etch.

Inert without Etch: the board's scripts self-gate on the Etch API, so nothing
renders and nothing breaks on a site that doesn't run it.

== Installation ==

1. Upload the `karo-kit` folder to `/wp-content/plugins/`.
2. Activate "Karo Kit" through the Plugins menu.
3. Configure via the "Karo Kit" item in the admin menu.

== Changelog ==

= 0.7.0 =
* Add: Module 2 — Etch. Bundles the Etch Template Board (kanban view of your
  templates with columns, drag-to-reorder, search, status badges, thumbnails
  and a shared-component graph), replacing Etch's native Templates screen
  while create/open/delete still run through Etch's own controls.
* Add: a switch to turn the Template Board on or off. On by default; off
  returns Etch to its native Templates screen and unhooks the board entirely
  (no assets, no REST routes, no per-save bookkeeping). Stored columns,
  statuses and thumbnails are kept, so switching back on restores them.
* Change: the Etch tab now belongs to this module and carries both the board's
  settings and the dynamic-data / shortcode reference (previously under Gate).
  Gate keeps Site Access and Maintenance.
* Note: board state from the standalone "Etch Template Board" plugin (column
  order, statuses, thumbnails) migrates automatically on first load. The old
  options are left untouched, so that plugin still works if reactivated —
  deactivate it to avoid running both boards at once.
* Note: thumbnails come from WordPress.com mShots and need a publicly
  reachable site; the Etch tab says so when the site is local.

= 0.6.0 =
* Change: Karo Kit moved out from under Settings to its own top-level admin
  menu item, with a custom dashboard shell (replaces the old native
  Settings-API tab layout).
* Add: an Overview tab summarising status (maintenance, registration, hidden
  login) and page assignments across every installed module.
* Change: Gate's settings regrouped by workflow — Site Access, Maintenance,
  References — shown directly in the top nav instead of nested under a
  "Gate" tab.
* Add: an activity log recording lockouts, failed login/registration attempts,
  blocked hidden-login requests, new registrations and gate toggles. The last
  few appear on the Overview; the full log (capped at 200 entries) lives on
  its own page, linked from that card rather than the top nav.

= 0.5.4 =
* Change: the Blocked page now *redirects* to the chosen page (e.g. your
  homepage, shown normally) instead of rendering it at a 404. Unset still
  shows the theme 404 template. Guards against selecting the login page.

= 0.5.3 =
* Add: optional "Blocked page" selector — choose any page (or CPT) to show
  when a hidden login/admin URL is blocked. Rendered at a 404 status; falls
  back to the theme 404 template when unset.

= 0.5.2 =
* Change: blocked login/admin URLs now render the site's real 404 template
  (Etch's, via template_include) with a proper 404 status, instead of the
  generic wp_die page — so a hidden URL looks like any other not-found page.

= 0.5.1 =
* Fix: hide-login now forwards the secret word to your custom login page
  (when set) instead of wp-login.php, and blocks direct access to that
  custom page unless reached via the secret. The original wp-login stays
  hidden; the custom page can no longer be used to bypass the secret.

= 0.5.0 =
* Add: hide login URL behind a secret word. Visiting /{secret} forwards to
  the real login; direct hits on wp-login.php / wp-admin without it 404.
  Transactional actions (logout, reset links) still work. Recovery hatch:
  define( 'KARO_KIT_DISABLE_HIDE_LOGIN', true ) in wp-config.php.
* Note: obscurity, not authentication — keep rate limiting enabled.

= 0.4.1 =
* Add: more Etch user dynamic data — {user.loginUrl}, {user.accountUrl},
  {user.displayName}, {user.avatarUrl} (alongside logoutUrl/isLoggedIn).
* Add: an "Etch reference" panel on the Gate settings tab listing every
  dynamic-data binding and shortcode.

= 0.4.0 =
* Add: Etch dynamic data integration (etch/dynamic_data/user) exposing
  {user.logoutUrl} and {user.isLoggedIn} for binding in the Etch builder.

= 0.3.2 =
* Fix: the login, registration and lost-password pages now stay reachable
  while maintenance mode is active, so visitors/admins can still log in.
  Exempt page IDs are filterable via 'karo_kit_gate_exempt_ids'.

= 0.3.1 =
* Internal: modernised for PHP 8.4 — typed properties and return types,
  a Karo_Kit_Gate_Mode enum replacing magic-string mode validation, match
  expressions, and ::class references. No change to behaviour.

= 0.3.0 =
* Security: dedicated front-end registration toggle, independent of the WP
  core "Anyone can register" setting (off by default).
* Security: IP-based rate limiting / lockout on login and registration,
  with configurable threshold, window and cooldown.
* Security: generic registration error on existing email/username to prevent
  account enumeration.
* Requirements: raised minimums to WordPress 6.8+ and PHP 8.4+ (active-support
  runtime); added a graceful environment guard.

= 0.2.1 =
* Fix: maintenance/coming-soon now renders the page through the normal
  template pipeline, so theme/ACSS/Etch styles load (previously page-scoped
  CSS was stripped by the custom splash renderer).

= 0.2.0 =
* Restructured from a single file into a proper modular plugin (core +
  Gate module split into settings/auth/maintenance/shortcodes).
* Tabbed settings shell ready for additional modules.
* Activation/uninstall lifecycle.

= 0.1.0 =
* Initial Gate module: page selectors, front-end login/registration,
  wp-login routing, maintenance/coming-soon gate.
