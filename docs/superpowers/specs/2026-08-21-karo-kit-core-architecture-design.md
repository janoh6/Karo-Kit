# Karo Kit — Core Architecture Refactor

**Date:** 2026-08-21
**Status:** Approved for planning
**Baseline:** v0.16.8

## Context

Karo Kit is a live, released WordPress plugin distributed through GitHub
Releases with an in-admin update checker. It works. This refactor is not a
rescue; it removes three structural frictions that will otherwise compound as
features are added:

1. **An option is declared in four places.** `register_setting()`,
   `export_map()`, `export_labels()` and `uninstall.php` each carry their own
   copy of the option list. Adding a setting means remembering four edits, and
   forgetting one fails silently.
2. **Static-only classes cannot be tested.** Every class is a bag of static
   methods with no injection points. There is no way to substitute a clock, a
   database, or a logger, so the security-critical paths — rate limiting,
   the login handler, the maintenance gate — have no regression net.
3. **Every file is loaded unconditionally.** `karo-kit.php` carries a manual
   `require_once` list that grows with every feature, and disabled modules are
   still parsed on every request.

A fourth issue is a genuine bug rather than friction, and it turns out to share
a root cause with (1): see [Security fixes](#1-v0169--security-fixes).

## Goals

- One declaration per option, from which registration, defaults, export,
  import, uninstall and the AJAX allowlist are all derived.
- Modules as injectable instances, so behaviour can be tested.
- A real test suite covering the security-critical paths.
- No behaviour change visible to an existing install at any point.

## Non-goals

- No UI redesign. The admin shell, its CSS, and the visual design stay as they are.
- No new features.
- No REST API changes.
- **No option renames.** Existing installs depend on the current option names;
  the registry declares those exact names.
- No change to the Etch capture pipeline's behaviour.

---

## Release ladder

Four releases. Each is independently shippable and each has a crisp done-state.

| Release | Contains | Behaviour change |
|---|---|---|
| **v0.16.9** | Security and correctness fixes | Bug fixes only |
| **v0.17.0** | Composer, option registry, module container, test harness. Gate and Etch run unchanged behind an adapter | **None** |
| **v0.18.0** | Gate ported to the new contract, plus Gate tests | None |
| **v0.19.0** | Etch ported, plus Etch tests. Adapter removed | None |

v0.17.0 shipping with zero behaviour change is deliberate: if something breaks
after it, the foundation is the suspect, not a feature.

**On planning:** this spec covers four releases and is too large for a single
implementation plan. Each release gets its own plan, written when that release
is next. Start with v0.16.9.

---

## 1. v0.16.9 — Security fixes

Three self-contained fixes plus one piece of hardening. No structural change.
This ships before any refactor work begins so live sites are not waiting on it.

### 1.1 Atomic rate-limiter increment

`Karo_Kit_Gate_Security::bump()` currently reads the counter, computes the new
value in PHP, and writes it back. Two concurrent failed logins both read the
same value and both write the same increment, so the counter advances by one
instead of two. Under sustained concurrency the lockout threshold is reached
later than configured.

Replace the read-modify-write with an atomic upsert:

```sql
INSERT INTO {$table} (ip_hash, context, attempts, window_start)
VALUES (%s, %s, 1, %s)          -- $key, $context, $now
ON DUPLICATE KEY UPDATE
  attempts     = IF(window_start >= %s, attempts + 1, 1),   -- $cutoff
  window_start = IF(window_start >= %s, window_start, %s)   -- $cutoff, $now
```

Bound parameters, in order: `$key`, `$context`, `$now`, `$cutoff`, `$cutoff`,
`$now` — where `$now` is `gmdate('Y-m-d H:i:s')` and `$cutoff` is that minus
`window_minutes`. Both timestamps are computed once in PHP and passed in; do
not compute them in SQL.

Three details that matter:

- **Assignment order is load-bearing.** MySQL evaluates `ON DUPLICATE KEY
  UPDATE` assignments left to right, and later assignments observe earlier
  ones. `attempts` must be assigned *before* `window_start` so it compares
  against the old window. Reversing these two lines silently breaks window
  expiry.
- **The comparison is against `$cutoff`, not `$now`.** `window_start >=
  $cutoff` asks "is the existing window still live?". Comparing against `$now`
  would be false for every existing row and reset the counter on every
  attempt — that is, it would disable rate limiting entirely while appearing
  to work.
- **`VALUES()` is deliberately avoided.** It is deprecated in MySQL 8.0.20+ in
  favour of row-alias syntax that MariaDB does not share. Repeating the bound
  parameters instead works on every database WordPress supports.

The lockout decision becomes a second, conditional statement:

```sql
UPDATE {$table}
SET locked_until = %s, attempts = 0
WHERE ip_hash = %s AND context = %s AND attempts >= %d
```

`$wpdb->rows_affected === 1` means *this* request tripped the lock. That is
strictly better than the current code, which can log the same lockout twice
when two requests cross the threshold together.

Cost: two queries on the ordinary failure path, three when a lock trips. Both
are the rare path, and both are already doing I/O.

### 1.2 Explicit option defaults at activation

`Karo_Kit_Gate_Auth::filter_users_can_register()` returns `false` whenever
`get_option( 'karo_kit_gate_registration_on' )` is falsy — which includes "the
option has never been written". On a fresh install of Karo Kit onto a site that
already had core's *Anyone can register* enabled, registration closes silently.

Interim fix for this release: seed the Gate boolean options with `add_option()`
so "unset" and "explicitly off" stop being the same state. v0.17.0 generalises
this to every declared option (§2.2), at which point the special case is
deleted.

**Activation alone is not sufficient.** An in-place update from v0.16.8 never
fires `register_activation_hook`, so a fix that only runs in
`Karo_Kit::activate()` would reach new installs and miss every existing one —
exactly the sites the bug is already affecting. Seeding must therefore follow
the versioned-bootstrap pattern the codebase already uses in
`Karo_Kit_Log::maybe_install()` and `Karo_Kit_Gate_Security::maybe_install()`:
an `admin_init` check against a stored version option, which no-ops on every
request after the first. Call it from activation *and* from that hook.

### 1.3 Timezone-correct template dates

`Karo_Kit_Etch_Board::last_modified()` reads `$post->post_modified` — the
local-time column — and passes it to `strtotime()`, which interprets it in the
*PHP* timezone. When the server's PHP timezone differs from the WordPress
timezone, template dates are wrong by the offset, sometimes landing on the
previous or next day.

Use the GMT column with an explicit suffix, and `wp_date()` for rendering, which
is the pattern `Karo_Kit_Log::when()` and `Karo_Kit_Gate_Security::to_time()`
already follow:

```php
$ts = strtotime( $post->post_modified_gmt . ' UTC' );
return wp_date( 'M j', $ts );
```

The existing `'0000-00-00 00:00:00'` guard stays.

### 1.4 Explicit returns in auth guards

In `handle_login()` and `handle_register()`, every `redirect_back()` call is
followed by nothing. The code is correct today only because `redirect_back()`
always calls `exit`. That is an invisible invariant: any future change to that
method — telemetry, a test double, a filter — silently turns the nonce check
and the lockout check into no-ops that fall through into credential
verification.

Add `return;` after each call. Zero behaviour change, and the guard becomes
readable as a guard.

### 1.5 Import no longer counts unchanged rows as applied

`Karo_Kit_Transfer::handle_apply()` treats `status === 'same'` as applicable:
it calls `update_option()` for rows whose value already matches, firing every
sanitize callback and option hook for no reason, then reports them as applied.
Importing a file exported from the same site logs "15 applied, 0 skipped" when
nothing changed.

Skip `same` rows and count them in their own bucket, so the log distinguishes
*changed* from *already matching*. This is grouped into v0.16.9 rather than the
foundation release to keep v0.17.0 strictly free of behaviour change.

---

## 2. v0.17.0 — Core foundation

### 2.1 Composer, without a mass rename

PSR-4 requires namespaces, and namespacing the existing 20-odd classes would
produce a very large diff in the release whose entire value proposition is "no
behaviour change". So the two are separated:

- **New code** lives in `src/`, namespaced `KaroKit\`, loaded by PSR-4.
- **Existing code** stays exactly where it is under its current class names,
  loaded by a Composer **classmap** over `includes/` and `modules/`.

No file is renamed in this release. As Gate and Etch are ported (v0.18.0,
v0.19.0) they move into `src/` and gain namespaces; by v0.19.0 the classmap
covers nothing and is dropped. The manual `require_once` list in
`karo-kit.php` is replaced by a single `vendor/autoload.php` require.

```
src/
  Core/Options/Option.php
  Core/Options/Registry.php
  Core/Options/Repository.php
  Core/Module/Module.php              (interface)
  Core/Module/Container.php
  Core/Module/StaticModuleAdapter.php
  Core/Clock/Clock.php                (interface)
  Core/Clock/SystemClock.php
  Core/Uninstall/Uninstallable.php    (interface)
includes/    (legacy, classmapped)
modules/     (legacy, classmapped)
tests/
```

**Deferred:** the vendored Plugin Update Checker in
`includes/vendor/plugin-update-checker/` stays vendored for now, even though
Composer could manage it. If the update checker breaks it fails *silently* —
sites simply stop being offered updates — and that is not a risk worth folding
into the foundation release. Revisit once the test harness exists.

### 2.2 The option registry

One immutable value object per option:

```php
final class Option {
    public function __construct(
        public readonly string  $name,
        public readonly string  $type,       // see below
        public readonly mixed   $default = null,
        public readonly ?string $label = null,
        public readonly bool    $setting = true,    // register_setting + AJAX-writable
        public readonly bool    $export = false,
        public readonly bool    $uninstall = true,
        public readonly bool    $autoload = true,
        public readonly ?array  $enum = null,
        public readonly ?int    $min = null,
        public readonly ?int    $max = null,
    ) {}
}
```

Types and their sanitizers: `bool` (cast to 0/1), `int` (absint, clamped to
`min`/`max` when given), `string` (`sanitize_text_field`), `key`
(`sanitize_key`), `enum` (membership test, falls back to `default`), `page`
(absint post ID), `hex` (the existing `normalise_hex` logic), `array`
(passthrough with a per-element sanitizer).

The `setting` flag matters: options such as `karo_kit_etch_order`,
`karo_kit_etch_thumbs` and `karo_kit_etch_status` are internal state written
directly by REST handlers, not user settings. They need uninstall cleanup but
must **not** be registered or become AJAX-writable. Declaring them with
`setting: false` keeps them out of the allowlist by construction.

From one `Option[]` list the kit derives all of:

| Derived | From |
|---|---|
| `register_setting()` + sanitize callback | `setting`, `type`, `default` |
| `add_option()` seeding at activation | `name`, `default`, `autoload` |
| `export_map()` equivalent | `export`, `type` (`page` travels as slug + post type) |
| `export_labels()` equivalent | `label` |
| `uninstall.php` delete list | `uninstall` |
| `ajax_save_setting` allowlist | `setting` |
| Typed accessors | `type` |

Seeding every declared default at activation is what actually fixes §1.2, for
every option at once, structurally. **The registration bug and the
four-places problem are the same bug**: both come from the option list not
having a single home. No future option can reintroduce either.

`Repository` wraps reads with types, replacing the scattered casts:

```php
$options->bool( 'karo_kit_gate_registration_on' );
$options->int( 'karo_kit_gate_lockout_max' );      // default from the registry
$options->string( 'karo_kit_gate_login_slug' );
```

### 2.3 Module contract

`Karo_Kit_Module` becomes an interface implemented by instances:

```php
interface Module {
    public static function id(): string;
    public function label(): string;
    /** @return Option[] */
    public function options(): array;
    public function boot(): void;
    public function dashboardGroups(): array;
    public function navSections(): array;
}
```

A small `Container` constructs modules with their dependencies —
`new Gate( $options, $log, $clock )`. Injecting a `Clock` is what makes the
rate limiter and the log testable: tests advance time rather than sleeping
through it.

Constructors only store dependencies; `boot()` registers hooks. That split lets
`uninstall.php` instantiate modules cheaply to collect their `options()`
without booting WordPress hooks.

### 2.4 The adapter

`StaticModuleAdapter` wraps an existing static module class and satisfies
`Module` by forwarding to its static methods. Roughly 30 lines. This is what
buys the one-module-at-a-time migration: in v0.17.0 both Gate and Etch run
through the adapter, entirely unmodified.

### 2.5 Uninstall

`uninstall.php` becomes: require the autoloader, build the container,
instantiate each module, delete every option declared with `uninstall: true`.
The hardcoded 30-entry list disappears.

Non-option cleanup — dropping the two tables, the component-index transient,
per-user import transients and theme meta, and the shared thumbnail directory —
does not fit the option registry. Modules that own such resources implement an
optional `Uninstallable` interface with a single `cleanup(): void`, so that
logic lives with the module that created it rather than in a growing god-file.
The `ETB_VERSION` guard protecting the shared thumbnail directory is preserved
exactly.

---

## 3. Test harness (v0.17.0)

`wp-cli scaffold plugin-tests` provides `tests/bootstrap.php` and
`phpunit.xml.dist`. A new `.github/workflows/tests.yml` runs on push and pull
request with a MySQL service container, matrixed over PHP 8.4 × WordPress
6.8 and latest.

First tests, in priority order:

1. **Rate limiter** — window expiry resets the counter; the threshold trips a
   lock exactly once; a lock blocks subsequent attempts; the IP-wide companion
   counter tolerates `IP_WIDE_MULTIPLIER`× the per-account one; success clears
   the account counter but not the IP-wide one.
2. **Import/export round-trip** — export then import on a site with different
   post IDs resolves pages by slug; an unmatched slug reports `missing` and
   writes nothing; a same-valued row is not counted as applied.
3. **Maintenance gate** — exempt pages render; a non-exempt page is swapped;
   coming-soon returns 200 and maintenance returns 503; an admin bypasses.
4. **Pure functions** — `from_oklch`, `luminance`, `contrast`, `ink`,
   `normalise_hex`, `derive_type`, `title_aliases`.

**On testing the concurrency fix honestly:** PHPUnit is single-threaded, so
true parallel requests are not reproducible in-process. What the suite verifies
instead is that the increment is expressed as a *single atomic statement*
rather than a read-then-write — by executing the upsert over two separate
live `wpdb` connections in an interleaved order that reliably fails against
the current implementation and passes against the fix. That is a real
regression test for the defect. It is not a proof of correctness under
arbitrary concurrency, and the spec does not claim to be one.

---

## 4. Build pipeline

`release.yml` gains a dependency install step before the zip is built:

```yaml
- name: Install runtime dependencies
  run: composer install --no-dev --optimize-autoloader --no-interaction
```

and the zip step copies the two new directories:

```yaml
cp -r assets includes languages modules src vendor build/karo-kit/
```

`composer.lock` is currently gitignored; un-ignore and commit it so release
builds are reproducible. The build step also drops a `<?php // Silence.`
`index.php` into `src/` and `vendor/`, matching the convention every other
directory in the plugin follows.

The changelog contract is unchanged: `README.md` keeps its `= X.Y.Z =`
sections, and the existing `awk` extraction keeps working.

---

## 5. Review findings — where each lands

Every finding from the 2026-08-21 code review has a home in the ladder:

| Finding | Release |
|---|---|
| Rate-limiter TOCTOU | v0.16.9 |
| Unset option closes registration | v0.16.9 (interim), v0.17.0 (structural) |
| `last_modified` timezone | v0.16.9 |
| Auth guards rely on `exit` | v0.16.9 |
| Import counts unchanged rows as applied | v0.16.9 |
| `TABS` constant unextractable by i18n | v0.18.0 |
| `get_block_templates()` called twice | v0.19.0 |
| `THUMB_OPT` deserialized twice per template | v0.19.0 |

---

## 6. Preserved deliberately

These are right and are not to be "improved" during the port:

- The activity log's append-only table. The commit history shows it was
  deliberately moved off a read-modify-write option because that turned
  logging into a brute-force amplifier.
- The preview proxy-token scheme — short TTL, capability gate, no public route.
- The dual per-account / per-IP throttle strategy. Only the increment's
  atomicity changes; the strategy does not.
- The two-step import review (upload → review → apply).
- The Etch capture fixes: rendering blocks before `wp_head()`, real
  `get_body_class()` output, and the 4:3 crop matching the card box. Each was
  found the hard way and none is obvious from reading the code.

## 7. Risk

The Etch port (v0.19.0) is the riskiest step. `class-karo-kit-etch-board.php`
is ~1100 lines encoding a lot of behaviour discovered through failed captures,
and much of it is not verifiable by reading. Mitigations: it goes **last**, so
the test harness exists first; characterization tests for `derive_type`,
`title_aliases`, `front_end_url` and `extract_components` are written **before**
any code moves; and the capture pipeline itself (§6) is moved verbatim rather
than refactored.

Secondary risk: adding Composer makes the release zip dependent on a build step
that has never run before. Mitigation — v0.17.0 is verified by installing the
built zip onto a clean site and confirming both activation and an update from
v0.16.9, before the tag is pushed.
