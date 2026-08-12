=== Karo Kit ===
Contributors: karo
Tags: login, registration, maintenance, coming-soon, etch, acss
Requires at least: 6.8
Requires PHP: 8.4
Stable tag: 0.16.8
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
* Structure Arrows - hover a row in the structure panel to get up / down
  arrows for reordering among siblings; keep hovering and outdent / indent
  arrows appear for changing nesting depth. Only valid moves are drawn.
* Sidebar Tabs - a draggable tab on each edge of the builder that collapses the
  panel beside it, with Alt+[ , Alt+] and Alt+\\ shortcuts. Panels reopen at
  whatever width you resized them to.
* Reference - every dynamic-data binding and shortcode the kit exposes to Etch.

Each feature has its own on/off switch. Inert without Etch: the scripts
self-gate on the Etch API, so nothing renders and nothing breaks on a site
that doesn't run it.

= Settings transfer =

Export the whole configuration to JSON and import it on another site. Page
selections travel by slug and are re-matched on the target, so they survive
differing post IDs; the import shows exactly what it will change, and what it
couldn't match, before anything is written.

== Installation ==

1. Upload the `karo-kit` folder to `/wp-content/plugins/`.
2. Activate "Karo Kit" through the Plugins menu.
3. Configure via the "Karo Kit" item in the admin menu.

== Changelog ==

= 0.16.8 =
* Fix: Template Board thumbnails still came out as a narrow sliver of the
  page. 0.16.2 gave the card's thumbnail box a 4:3 shape but left the
  server writing the file at 16:9 (640×360), and the card fills that box
  with `object-fit: cover` — so the browser cropped the sides off an image
  the server had already cropped to a top 16:9 band, twice-trimming it down
  to a strip. Captures are now stored at 4:3 (640×480), the same shape as
  the box that displays them, so the stored image appears in full with
  nothing cropped away.

= 0.16.7 =
* Fix: Template Board thumbnails captured the page almost completely
  unstyled — everything left-aligned in a bare vertical stack, with the
  nav flattened into a list of every link including submenu items that
  should have stayed collapsed. The capture route rendered the template's
  blocks into the body *after* `wp_head()` had already run. Etch builds its
  per-page CSS from the elements it sees as they render and prints the
  result on `wp_head` at priority 99, so that hook fired before a single
  block had rendered: it emitted only the always-on `:root` rules — about
  3 KB against the ~40 KB the same template produces on the front end — and
  every class-based layout rule was simply absent. Blocks are now rendered
  ahead of the head, which is what WordPress core's own template-canvas.php
  does, for the reason its comment states: "This needs to run before <head>
  so that blocks can add scripts and styles in wp_head()." The captured
  page now matches the real one.
* Fix: the capture route skipped the content filters and the
  `.wp-site-blocks` wrapper that core applies around a block template, so
  shortcodes and embeds in a template went unprocessed and any theme rule
  written as a descendant of that wrapper (`.wp-site-blocks > *`, which is
  what core added it for) never applied.

= 0.16.6 =
* Fix: every Template Board thumbnail captured an empty page body, so they
  all came out looking identical — just the shared header and footer, with
  nothing of the template itself between them. The capture route renders a
  template's blocks directly, and `core/post-content` (which most templates
  reduce to) takes its post from block context, which WordPress only fills
  in when a real post is set up first. That only happened for `single-*`,
  `single` and `page` slugs; everything else — including `index`, which in
  this theme is nothing *but* a post-content block — rendered an empty
  `<main>`. Any slug without one of those prefixes now falls back to the
  site's front page, or failing that any published page or post.
* Fix: the capture route emitted a bare `<body class="karo-kit-etch-preview">`
  and never called `body_class()`, so none of the classes real front-end
  pages carry (`home`, `wp-singular`, `wp-theme-*`, …) were present, and
  anything a theme or ACSS styles off one of them rendered differently in
  the thumbnail than on the actual page.

= 0.16.5 =
* Fix: `Fatal error: Uncaught Error: Class "Parsedown" not found` on the
  Plugins screen. The GitHub-Releases update checker added in 0.16.0
  vendored Plugin Update Checker's `Puc/` classes but not its own `vendor/`
  subfolder — Parsedown and PucReadmeParser, which `Vcs/Api.php` and
  `Vcs/GitHubApi.php` load at runtime to render a release's Markdown body
  into the changelog shown in wp-admin. That folder looked like the dev
  tooling the rest of the trim was meant to drop, but it's a real runtime
  dependency; since update checks return early with nothing to render until
  a token is set or the repo is public, the gap stayed invisible until both
  became true and a check finally reached an actual release. All three
  files are vendored now.

= 0.16.4 =
* Change: the gap between cards inside a Template Board column now matches
  the gap between columns themselves (`--etb-gap`), instead of a tighter
  0.5rem that made cards feel more cramped together than the columns
  holding them.

= 0.16.3 =
* Change: a Template Board column used to scroll its cards internally once
  they didn't fit the visible height — which, combined with the taller 4:3
  thumbnails added in 0.16.2, meant even a column with only 3 cards could
  end up with its own private scrollbar. Columns now just grow to fit their
  cards, and the board scrolls vertically as a whole, the same way it
  already scrolled horizontally — one scrollbar for the whole board instead
  of a separate one on every column that happens to run long.

= 0.16.2 =
* Change: Template Board card thumbnails are now a 4:3 box instead of a
  fixed 84px-tall strip that cropped almost the entire captured page down
  to a thin sliver. Captures are full-page-height screenshots at a fixed
  width, so the box still can't show everything — the crop now anchors to
  the top of the page (its header/hero) instead of an arbitrary centered
  strip, which is the part that actually identifies a template at a glance.
* Fix: the thumbnail's bottom corners were square where the top corners
  were rounded. The card's own rounded-corner clip only reaches the thumb's
  top edge, since that's the only part touching the card's actual boundary
  — the bottom of the thumb is an internal seam against the footer below,
  which nothing had rounded. The thumb now rounds its own corners directly
  so all four stay consistent regardless of where the footer sits.

= 0.16.1 =
* Fix: a Template Board column with more cards than fit its visible height
  squashed every card in it down toward zero height instead of leaving them
  full-size and scrolling. `.etb-column__list` is a flex column with
  `overflow-y: auto`, but its cards never opted out of the default
  `flex-shrink: 1` — and since each card also sets `overflow: hidden`, its
  automatic minimum height resolved to 0 rather than its content size,
  leaving nothing to stop flexbox shrinking it arbitrarily far. Cards now
  set `flex-shrink: 0`, matching the same protection already used elsewhere
  in the board (thumbnails, column-header buttons); overflow now scrolls
  the column instead of crushing its contents.

= 0.16.0 =
* Add: Karo Kit can now check GitHub Releases for updates from wp-admin.
  Nothing polled GitHub before this, since the plugin isn't on wp.org — the
  update checker (vendored Plugin Update Checker, MIT) is initialised
  unconditionally rather than behind an admin-only hook, so WP-CLI and the
  background update cron see it too, not just an admin looking at the
  Plugins screen. The repo is currently private, which it can't reach
  without a token; that's exposed as an opt-in `karo_kit_update_token`
  filter rather than a token shipped in the plugin, since a shared secret
  baked into every install isn't one you can rotate. Does nothing until
  either that filter is set or the repo goes public.
* Fix: downloading the plugin from GitHub used its auto-generated "Source
  code" archive, which unpacks to a folder named after the repo
  (`Karo-Kit-x.y.z`) rather than `karo-kit` — so a manual download-and-
  reinstall created an unrelated second, deactivated copy instead of
  offering to replace the existing one. Releases now ship an explicit zip
  rooted at `karo-kit/`, containing only the runtime files, and it's what
  the update checker above fetches too.
* Fix: every page-picker select in Gate settings (Login/Registration/
  Account/Lost-password) went effectively blank on hover or keyboard focus.
  WordPress core sets `color: #1e1e1e` on `.wp-core-ui select:focus` and
  `:hover`, at the same CSS specificity as this plugin's own rule and
  loaded after it — so focusing or hovering any select silently reverted
  its text to near-black against Karo Kit's near-black dark theme.

= 0.15.1 =
* Security: the Registration toggle only ever gated Karo Kit's own front-end
  form. WordPress's native wp-login.php?action=register runs on a separate
  core check, "Anyone can register", that this toggle never touched — and the
  native screen is only routed to your own page once both a login page and a
  registration page are chosen in Gate settings. Short of that, turning
  registration off here did not reliably close it: the native screen stayed
  open, governed purely by core's setting. Verified against a real site: the
  exact gap (toggle off, core's setting on, no login page configured) used to
  render the native form; it now redirects to `?registration=disabled`. Never
  forces registration open the other way — core's own setting still decides
  that on its own.
* Change: "Register page" is now "Registration page" throughout — the
  dashboard's Site Access card, the Gate settings field, and Settings
  Transfer's labels.

= 0.15.0 =
* Change: Template Board thumbnails are now captured in your own browser
  instead of being generated by WordPress.com's mShots service. Capture used
  to mean handing the site's URL to an outside server with no login of its
  own, so the template preview page it loaded had to be reachable by anyone;
  it now requires the same capability as the rest of the board, since nothing
  outside the site needs to reach it any more. Thumbnails also now work on
  local and staging sites, which mShots could never see.
* Add: images the capture can't otherwise read back off a canvas — a
  component's stock photo hosted on another domain, say — are fetched
  through a same-origin proxy instead of coming out blank. The proxy is
  guarded against being pointed at the site's own internal network.
* Add: "Auto-generate up to" (Template Board settings) — how many missing or
  stale thumbnails are captured automatically when the board opens, since
  each one now costs a few real seconds of your own browser rather than
  running on someone else's server. Default 6; the rest wait for "Generate
  thumbnail" from a card's menu. 0 turns off automatic generation entirely.

= 0.14.1 =
* Security: a failed login locked out the whole IP address, so five wrong
  passwords from an office network — or any address shared behind CGNAT — shut
  out every other person on it. A login now counts against the account being
  tried as well as the address, with a looser address-wide limit still in place
  so that trying a few passwords against each of a long list of usernames is
  no way around it. Registration is unchanged: it has no account to defend.
* Security: the rate limiter keyed its rows on an unsalted MD5 of the visitor's
  IP, which reverses by brute force in minutes — the whole address space is
  only four billion values — so it read as anonymised without being so. It now
  uses WordPress's own salted hash. Existing rows stop matching and are cleared
  by the daily purge; an active lockout may restart, which is harmless.
* Performance: opening the Template Board re-parsed every template and every
  component from scratch, twice over for templates, since the two data sources
  behind the board each parsed the same markup separately. They now share one
  index, built in a single pass and kept until a template or component is
  saved. On a build with 59 components that is roughly 470 KB of markup parsed
  per visit, down to none once warm.

= 0.14.0 =
* Fix: Graph view never showed a single shared component on an Etch site. It
  looked for `core/block`, the block WordPress uses for synced patterns; Etch
  components are `etch/component`, pointing at the same kind of reusable post
  under a different name. On the build this was found on, no template used
  `core/block` at all — so the layer the graph exists to show was always empty.
* Fix: a component used only inside another component counted as used by
  nothing, because usage was read from templates alone. Component posts are
  now walked too, and that kind of nesting is listed separately from template
  use — "nothing uses this" and "no template uses this directly" are very
  different things to say about something you may be about to delete.
* Fix: a component placed twice in one template counted as two uses.
* Fix: template status was drawn on the node border, where it overrode the
  border colour carrying template type — and WIP's amber is barely
  distinguishable from the archive amber, so a WIP single template looked like
  an archive and disagreed with the legend beside it. Status now sits in a
  corner dot and type keeps the border. Legend swatches show the colour a node
  is actually drawn in, rather than a more saturated version of it.
* Change: Graph view is laid out radially — category hubs ring a centre point
  and each hub's templates fan out around it, instead of hubs in a row with
  their templates stacked underneath. The graph also opens centred in the
  view rather than pinned to the top-left corner.
* Add: hovering or selecting a node fades everything except that node, its
  connections, and whatever sits on the other end of them.
* Add: shared components carry their usage count, with heavily-reused ones
  drawn more prominently; components used by only one template are left out,
  since a component used once is that template's composition and is already
  listed in its panel.
* Add: the component layer can be switched off from the legend, and the legend
  itself can be dragged out of the way. Both the position and the layer choice
  are remembered per site.

= 0.13.0 =
* Add: a "Custom colour" option on the accent picker — a plain hex field,
  always available regardless of whether Automatic.css is active. Sites
  without ACSS previously had no way to pick their own accent at all.
* Fix: Sidebar Tabs' collapse pull-tab stayed on screen even while a
  different tab than Structure was showing in that side panel, since it only
  tracked whether the panel container existed, not which of Etch's internal
  panes was active. It now watches Etch's own pane state and hides itself
  while Structure isn't the one in view.

= 0.12.2 =
* Fix: the accent picker showed each family's Automatic.css factory default
  instead of the colour actually in use. ACSS keeps two copies of a colour —
  a hex field and a set of OKLCH components — and only the OKLCH components
  update when you edit a colour in its picker; the hex field is never
  touched again. The generated CSS is built from OKLCH, so that's what the
  picker now reads too, converting it to hex itself.

= 0.12.1 =
* Fix: the accent picker always said no Automatic.css brand colours were set,
  even on a site with a full palette. It looked for the family name on its own
  (`primary`); ACSS stores the hex one key over, under `color-primary`.
* Families switched off in ACSS are now left out of the picker. An off family
  still has a stored hex, but it generates nothing on the site — borrowing it
  would mean matching a colour the build doesn't actually use.

= 0.12.0 =
* Add: the dashboard can borrow its accent colour from this site's
  Automatic.css palette, so the kit matches the build it belongs to instead of
  asking you to pick a brand colour twice. Choose Primary, Secondary,
  Tertiary, Accent or Base on the Dashboard tab.
* Only the accent is borrowed. Surfaces stay neutral, since they carry the
  light and dark themes and a brand palette driving them is how an admin
  screen becomes unreadable.
* Text colour on the accent is computed, not fixed — a dark brand colour gets
  light text automatically — along with the hover shade and focus ring. If a
  colour still falls below 4.5:1 the settings card says so rather than
  silently shipping poor contrast.
* The choice travels with settings export as a family name rather than a hex,
  so "use this site's primary" stays meaningful on a target site with its own
  palette.
* Falls back to the kit's own colour whenever ACSS is absent, the family is
  unset, or the stored value isn't a hex (ACSS also stores hsl() and var()
  references).

= 0.11.2 =
* Fix: the dashboard header left a pale gutter down its left side and hung
  short of the admin menu, because WordPress pads its content wrapper 20px
  while our shell draws a full-bleed bar. That padding is now zeroed on our
  screen only, along with the 65px reserved for the footer we hide.

= 0.11.1 =
* Fix: dropdown options were unreadable in dark mode. Only the closed select
  was styled, so options and group labels kept a transparent background and
  their appearance was left to however the browser paints its popup. Both now
  carry explicit colours, measured at 14.8:1 for options and 6.5:1 for group
  labels against WCAG AA's 4.5:1.
* Change: form-control styling moved from .kk-card to .kk-app scope. It only
  ever reached controls inside a card, so any select added elsewhere would
  have silently fallen back to WordPress core's white-on-white.

= 0.11.0 =
* Add: Sidebar Tabs in the Etch module — collapse either builder panel to free
  up canvas, by tab or by keyboard (Alt+[ , Alt+] , Alt+\\). Draggable, with
  its own on/off switch and an option for whether state persists between
  sessions.
* Performance: Etch assets are now gated on the builder route itself rather
  than loaded everywhere to no-op. Etch opens the builder at the front page
  with ?etch=magic and gates its own app on exactly that, so ordinary
  front-end pages now carry nothing at all — not even the loader.
* Fix: the Etch features required edit_posts while Etch itself requires
  manage_options to render the builder, so authors were served assets for a
  builder that would never load for them. Now matched to Etch's own bar.
* Fixed while porting the sidebar snippet: a remounted panel left its old tab
  orphaned in the DOM and grew a new one each time; startup waited on
  DOMContentLoaded, which has already fired by the time the loader injects the
  script; and shortcuts could act on a panel Etch had since removed.

= 0.10.0 =
* Fix: the activity log made brute-force attacks more expensive to absorb than
  no logging at all. Every failed login and blocked request read the whole
  200-entry log, unserialised it, prepended, re-serialised and wrote back a
  ~27KB blob. It now lives in its own table, so an append is one INSERT and
  retention is a daily DELETE instead of work on every write. Existing entries
  migrate automatically.
* Fix: rate-limit state lived in transients, which a persistent object cache
  can evict under memory pressure and any cache-purge plugin can wipe —
  silently dropping an active lockout. Attempt counters and locks now live in
  their own table. IPs are stored hashed; the table counts abuse and doesn't
  need readable addresses to do it.
* Change: a sustained attack no longer writes one log row per attempt. The
  first failure and the resulting lockout are recorded; the attempts between
  them are suppressed. Fifty failures produce two rows, not fifty.
* Performance: the Etch board and structure arrows shipped ~94KB of JS/CSS on
  every front-end page view for anyone who can edit, purely so they could
  no-op when not on the builder. A ~2.6KB loader now waits for the Etch API
  and injects the real assets only then — a 97% cut to the always-on payload.
* Housekeeping: log retention and rate-limit cleanup share one daily event
  rather than scheduling one each. Both tables are dropped on uninstall.

= 0.9.0 =
* Add: settings export / import, from a card on the Dashboard. Export writes a
  JSON file; import is two-step — upload, review exactly what would change,
  then apply — because a settings restore is hard to undo.
* Page settings (login, register, account, lost-password, maintenance, blocked)
  travel as slug + post type rather than post ID, and are re-matched on the
  target site. Post IDs mean nothing on another install; copying them verbatim
  would silently point your login page at an unrelated post. Anything that
  can't be matched is listed as "No match" and left untouched.
* Never included: the hidden-login secret word (an export file gets emailed
  around), the activity log, and generated thumbnails.
* Import only writes options a module still declares, so a hand-edited file
  can't be used to set arbitrary WordPress options.

= 0.8.2 =
* Fix: toggling a setting for the first time was never recorded in the
  activity log. WordPress routes update_option() through add_option() while a
  setting still holds its registered default, and only add_option_* fires in
  that path — so on a fresh site, where every setting is at its default, no
  toggle was logged at all. Both hooks are now handled.

= 0.8.1 =
* Add: dark mode for the Karo Kit dashboard. Follows your system setting by
  default, with an auto / light / dark switcher in the top bar. The choice is
  saved per user (it's a personal display preference, not a site setting) and
  applied server-side, so the page never flashes the wrong theme on load.
* Note: this themes Karo Kit's own screens only. WordPress's admin menu and
  toolbar keep whatever admin colour scheme is set in your profile.

= 0.8.0 =
* Add: Structure Arrows in the Etch module — hover-reveal up / down / outdent /
  indent controls on each row of Etch's structure panel, so blocks can be
  reordered without dragging. Only valid moves are drawn; reordering shows on
  hover, nesting after a short dwell.
* Add: its own on/off switch (on by default), plus settings for the dwell
  delay, which side of the row the arrows sit on, and whether unavailable
  moves are drawn greyed out as a teaching layer. These were previously
  edit-the-source constants in the standalone plugin.
* Change: the arrows load on the front end for capable users only. The
  standalone plugin also enqueued on every wp-admin screen for a hypothetical
  future Etch build; that is dropped, since it shipped three scripts to every
  admin page and polled ~10s before warning to the console.
* Note: moves go through Etch's public block API and are persisted with a
  debounced saveAsync. Etch exposes no "can this contain children?" query, so
  indent uses a heuristic backstopped by catching WRONG_BLOCK_TYPE — a wrong
  guess is a no-op, never a corrupted tree.

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
