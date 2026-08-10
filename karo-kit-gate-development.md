# Karo Kit — Gate Module Development Plan

**Module:** Gate (`Karo_Kit_Gate`)
**Status:** v0.1 shipped (page selectors, front-end login + registration, `wp-login.php` routing, maintenance/coming-soon gate)
**Target:** v1.0 — feature parity with Bricks' built-in login/maintenance, plus lead-capture and hardening it doesn't have
**Scope boundary:** the Gate owns *site access* — who gets in, when, and through which screens. It deliberately does **not** become a membership/content-restriction or SEO plugin.

---

## 1. Conventions (carry through every feature)

| Thing | Convention | Example |
|---|---|---|
| Option prefix | `karo_kit_gate_` | `karo_kit_gate_lockout_max` |
| Settings group | `karo_kit_gate` | `register_setting( 'karo_kit_gate', … )` |
| Settings page slug | `karo-kit` | `do_settings_sections( 'karo-kit' )` |
| Class | `Karo_Kit_Gate`, static methods | `Karo_Kit_Gate::url()` |
| Shortcodes | `[karo_kit_*]` | `[karo_kit_countdown]` |
| Transients | `karo_kit_gate_*` | `karo_kit_gate_lockout_{hash}` |
| User meta | `karo_kit_*` | `karo_kit_unverified` |
| Filters/actions | `karo_kit_gate_*` | `apply_filters( 'karo_kit_gate_client_ip', … )` |

**Structural note.** The single mu-plugin file is fine through ~v0.5 but will get unwieldy. At the point Phase 3 lands, promote to a plugin folder:

```
karo-kit/
  karo-kit.php            # bootstrap, defines KARO_KIT_VER, requires includes
  includes/
    class-gate.php        # orchestrator + settings
    gate/
      class-gate-access.php     # bypass, schedule, preview tokens
      class-gate-comingsoon.php # countdown, lead capture
      class-gate-auth.php       # login/register handlers
      class-gate-security.php   # lockout, captcha, login url
      class-gate-email.php      # branded transactional mail
  assets/
```

Each sub-class exposes a static `init()` the orchestrator calls. This is also the seam the future **module registry** plugs into.

**Settings architecture.** v0.1 uses stacked `add_settings_section` blocks. Before Phase 2, convert the Karo Kit page to **tabs** (`?page=karo-kit&tab=gate`) so the Gate gets its own tab now and future modules get theirs. Tabs are just a `$_GET['tab']` switch around `do_settings_sections()`; the option group stays one per module.

---

## 2. Phased build order

| Phase | Theme | Features | Why this order |
|---|---|---|---|
| **1** | Gate parity | Per-role bypass · Preview tokens · Scheduled windows | Cheap, high-value, finishes the maintenance half |
| **2** | Coming-soon as a tool | Countdown · Email capture + CSV | Turns the dead-end splash into a lead magnet |
| **3** | Auth hardening | Rate-limit/lockout · CAPTCHA · Login-URL control · Role redirects | Recovers protection lost by moving login off `wp-login.php` |
| **4** | Registration depth | Email verification · Custom fields → user meta | Real signups need verification + profile data |
| **5** | Branded mail | From name/address · Templated new-user & reset emails | Keeps the whole flow on-brand; depends on SMTP being sane |

Do Phase 1 before anything else. Treat SMTP as a **separate future module**, not part of the Gate — Phase 5 templating assumes mail already sends reliably.

---

## 3. Feature specifications

Each spec lists: **Goal · Options · Hooks · Implementation · Data · UI · Security · Acceptance.**

### Phase 1

#### 3.1 Per-role bypass

**Goal.** Let chosen roles see the live site while the gate is up (today only `manage_options` bypasses).

**Options.**
- `karo_kit_gate_bypass_roles` — array of role slugs (multi-select).

**Hooks.** Reuses the existing `karo_kit_gate_can_bypass` filter — fold the role check into `can_bypass()`.

**Implementation.** In `can_bypass()`: keep the `manage_options` short-circuit, then `array_intersect( (array) wp_get_current_user()->roles, get_option('karo_kit_gate_bypass_roles', []) )`.

**UI.** Checklist of `get_editable_roles()` in the Maintenance section.

**Security.** Never allow bypass to be set to "all logged-in" silently — make it explicit.

**Acceptance.** A user in a bypass role sees the live site; a user not in one sees the gate; logged-out always sees the gate.

---

#### 3.2 Preview / bypass token links

**Goal.** Show a client the work-in-progress behind the gate without an account — a shareable link.

**Options.**
- `karo_kit_gate_preview_token` — random 32-char string (regenerate button).
- `karo_kit_gate_preview_ttl` — minutes the cookie lasts (default 1440).

**Implementation.**
1. Visiting `?karo_preview={token}` sets a short-lived signed cookie, then redirects to strip the query arg.
2. `can_bypass()` returns true if the cookie is present and valid.
3. "Regenerate token" invalidates all outstanding links.

**Data.** Cookie `karo_kit_gate_preview` = HMAC of token + expiry, verified with `hash_equals()`.

**UI.** Read-only preview URL with copy button + regenerate, in the Maintenance section.

**Security.** Constant-time compare; HMAC with `wp_salt()`; never expose the raw token in markup; honour the TTL.

**Acceptance.** Incognito + valid link → sees site for the TTL; expired/tampered link → sees gate; regenerate → old links dead.

---

#### 3.3 Scheduled maintenance windows

**Goal.** Auto-enable/disable the gate between a start and end datetime.

**Options.**
- `karo_kit_gate_schedule_on` — bool (use schedule vs manual toggle).
- `karo_kit_gate_start` / `karo_kit_gate_end` — datetime-local, stored as site-timezone strings.

**Implementation.** Inline check in `maybe_gate()` using `current_datetime()` (timezone-aware). No cron needed — statelessly compute "is now within the window". Manual toggle still works as an override when `schedule_on` is false.

**UI.** Two `datetime-local` inputs + a toggle, in the Maintenance section. Show the resolved next on/off in site time as a sanity readout.

**Security.** n/a. **Edge case.** end < start → treat as disabled and surface an admin notice.

**Acceptance.** Inside window → gate shows (respecting bypass); outside → live; DST boundary behaves because it's timezone-aware.

---

### Phase 2

#### 3.4 Countdown shortcode

**Goal.** A launch countdown on the coming-soon page.

**Options.** None global — driven by shortcode atts.

**UI / shortcode.**
`[karo_kit_countdown to="2026-09-01 09:00" tz="Europe/London" expired="We're live!"]`

**Implementation.** Server renders the target as a `data-` attribute; a tiny vanilla JS file (enqueued only when the shortcode runs) ticks it down. No framework. Markup is BEM (`karo-countdown__unit`) so ACSS/your own CSS styles it.

**Security.** Escape all atts. **Acceptance.** Counts down accurately across timezones; swaps to the expired message at zero without a reload.

---

#### 3.5 Email capture + storage + CSV export

**Goal.** Collect leads on the coming-soon page; export them.

**Options.**
- `karo_kit_gate_leads_notify` — admin email to ping on new lead (optional).

**Shortcode.** `[karo_kit_optin]` renders an email field + nonce + honeypot, posting to the same handler pattern as login.

**Data — custom table** (cleaner than options for a growing list):

```sql
{$wpdb->prefix}karo_kit_leads (
  id        BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email     VARCHAR(190) NOT NULL,
  source    VARCHAR(100) NULL,          -- e.g. 'comingsoon'
  ip        VARBINARY(16) NULL,
  created   DATETIME NOT NULL,
  UNIQUE KEY email (email)
)
```

**Table creation caveat.** mu-plugins have no activation hook. Gate creation behind a version option:

```php
if ( get_option('karo_kit_gate_db_ver') !== KARO_KIT_VER ) {
    require_once ABSPATH.'wp-admin/includes/upgrade.php';
    dbDelta( $schema );
    update_option('karo_kit_gate_db_ver', KARO_KIT_VER);
}
```

**UI.** A "Leads" subview: paginated `WP_List_Table`, count, and an **Export CSV** button (streamed via `admin-post.php`, nonce-protected, `fputcsv` to `php://output`).

**Security.** `is_email()` validate; honeypot + nonce; rate-limit by IP (reuse 3.6); `UNIQUE` email dedupes; cap stored fields to email only (no marketing-data sprawl). GDPR: provide a delete-by-email action.

**Acceptance.** Valid email stores once; duplicate is a friendly "already on the list"; CSV downloads with correct headers; delete removes the row.

---

### Phase 3

#### 3.6 Rate limiting / lockout

**Goal.** Recover brute-force protection lost by moving login off `wp-login.php`. Applies to login, registration, and opt-in.

**Options.**
- `karo_kit_gate_lockout_max` (default 5)
- `karo_kit_gate_lockout_window` minutes (default 15)
- `karo_kit_gate_lockout_cooldown` minutes (default 60)

**Data.** Transient per identity: `karo_kit_gate_lockout_{md5(ip)}` = `{ count, first_ts }`, expiring after the window.

**Client IP resolver** (proxies/Cloudflare break `REMOTE_ADDR`):

```php
function karo_kit_client_ip() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    return apply_filters( 'karo_kit_gate_client_ip', $ip ); // site can map CF-Connecting-IP
}
```

**Implementation.** A `check_lockout()` guard called at the top of each handler; increment on failure, clear on success. On lockout, redirect back with a `locked` message and a `Retry-After`-style hint.

**Security.** Never trust forwarded headers by default — only via the filter, per-site. Lock on *failures*, not attempts, to avoid self-DoS. Consider also a global cap to blunt distributed attempts.

**Acceptance.** N failures in the window → locked for the cooldown; a success resets the counter; the filter lets a Cloudflare site resolve the real IP.

---

#### 3.7 CAPTCHA (Turnstile / hCaptcha)

**Goal.** Optional bot challenge on login/register/opt-in.

**Options.**
- `karo_kit_gate_captcha_provider` — none | turnstile | hcaptcha
- `karo_kit_gate_captcha_site` / `karo_kit_gate_captcha_secret`
- `karo_kit_gate_captcha_on` — array of forms to protect.

**Shortcode/markup.** `[karo_kit_captcha]` prints the provider widget div + script (enqueued conditionally).

**Implementation.** Server-side verify the response token via `wp_remote_post()` to the provider's `siteverify` endpoint before processing the form; fail closed on a bad/empty token.

**Security.** Secret only server-side; verify the `success` flag *and* hostname; short timeout + graceful fail message on provider outage. **Acceptance.** Missing/invalid token blocks submission; valid token passes; toggling provider to "none" removes the widget cleanly.

---

#### 3.8 Login-URL control

**Goal.** Reduce automated hits on `wp-login.php`.

**Options.**
- `karo_kit_gate_hide_login` — bool
- `karo_kit_gate_login_slug` — secret slug (e.g. `way-in`)

**Implementation.** When enabled: requests to `wp-login.php`/`wp-admin` (for logged-out users) without the secret slug return a 404; the secret slug serves the (custom or default) login. Always preserve the existing `?karo=default` escape hatch and the action passthrough (`logout`, `rp`, `postpass`, …).

**Security.** This is **obscurity, not protection** — document it as such; it only works paired with 3.6. **Compatibility.** Allowlist endpoints other plugins need (WooCommerce `my-account`, REST auth, app passwords). Provide a clearly-documented recovery: rename the plugin folder via FTP disables it.

**Acceptance.** Default login URL 404s for guests; secret slug works; admin AJAX/REST unaffected; recovery path verified.

---

#### 3.9 Role-based redirects

**Goal.** Send users to the right place after login/logout by role.

**Options.**
- `karo_kit_gate_redirects` — map of `role => url|page_id` (repeater).
- `karo_kit_gate_logout_redirect` — page/url.

**Implementation.** Resolve in the `handle_login()` success branch and via the `login_redirect` filter (covers default-flow logins too); `logout_redirect` filter for logout. Fall back to the Account page, then home.

**Acceptance.** Admin → wp-admin, subscriber → account, custom role → its mapped URL; logout lands on the configured page.

---

### Phase 4

#### 3.10 Email verification (double opt-in)

**Goal.** New accounts must confirm their email before they can log in.

**Options.**
- `karo_kit_gate_verify_on` — bool
- `karo_kit_gate_verify_ttl` hours (default 48)

**Data — user meta.**
- `karo_kit_unverified` = 1 until confirmed
- `karo_kit_verify_token` = hashed token
- `karo_kit_verify_expires` = timestamp

**Flow.**
1. `handle_register()` creates the user, sets the meta, **does not** auto-login.
2. Sends a verification email with `?karo_verify={token}&uid={id}`.
3. The verify endpoint validates token + expiry (`hash_equals`), clears the meta, optionally assigns the real role, then logs them in.
4. Login is blocked for unverified users — hook `wp_authenticate_user` to return a `WP_Error`, and short-circuit `handle_login()` the same way.

**Security.** Store only the *hash* of the token; enforce TTL; rate-limit resends; generic messaging so verification can't enumerate accounts.

**Acceptance.** Unverified user can't log in; valid link verifies + logs in; expired link offers a resend; resend is rate-limited.

---

#### 3.11 Custom registration fields → user meta

**Goal.** Capture extra profile data at signup.

**Approach (dev-first, UI later).** A filter returns field definitions; the handler validates + saves to user meta. A simple admin repeater can wrap this later.

```php
add_filter( 'karo_kit_gate_register_fields', function() {
  return [
    [ 'name'=>'first_name', 'meta'=>'first_name', 'required'=>true,  'type'=>'text' ],
    [ 'name'=>'company',    'meta'=>'company',     'required'=>false, 'type'=>'text' ],
  ];
});
```

**Implementation.** `[karo_kit_field action="register"]` already prints the nonce/honeypot; extend the handler to loop the field defs, sanitize per `type`, validate `required`, and `update_user_meta()`. You still hand-author the visible inputs in Etch (markup stays yours) — the defs drive validation + storage.

**Security.** Per-type sanitizers; never map to protected meta keys (`role`, `caps`); allowlist `meta` keys.

**Acceptance.** Required field empty → error back to the form; valid submit stores meta; field visible on the user's admin profile.

---

### Phase 5

#### 3.12 Branded transactional email

**Goal.** New-user and password-reset emails look like the site, not raw WordPress.

**Options.**
- `karo_kit_gate_mail_from_name` / `karo_kit_gate_mail_from`
- `karo_kit_gate_mail_logo` (attachment ID)
- `karo_kit_gate_mail_template` (HTML with `{placeholders}`)

**Implementation.** Filters: `wp_mail_from`, `wp_mail_from_name`, `wp_mail_content_type` (text/html), `wp_new_user_notification_email`, `retrieve_password_message`/`retrieve_password_title`. Wrap bodies in the template with `{site_name}`, `{action_url}`, `{logo}` placeholders.

**Dependency.** Assumes deliverable mail — **build/enable an SMTP module first** (separate module: host, port, encryption, auth, `phpmailer_init`). Don't bundle SMTP into the Gate.

**Security.** Sanitize from-address (`is_email`); escape placeholders; keep a plaintext alternative part. **Acceptance.** New-user + reset emails render branded HTML, deliver, and links work; reverting to defaults is one toggle.

---

## 4. Cross-cutting concerns

**Data storage strategy.**
- *Settings* → options (autoloaded; keep values small).
- *Ephemeral counters/cookies* → transients (lockout, preview).
- *Lists* (leads) → one custom table, version-gated via `dbDelta`.
- *Per-user state* (verification) → user meta.

**Security model (summary).**
- Every front-end write: nonce + capability/identity check + honeypot.
- Every redirect target: `wp_safe_redirect` + `esc_url_raw`.
- Every external call (CAPTCHA): server-side, fail-closed, short timeout.
- Login-URL hiding is obscurity; rate-limiting is the real control.
- Always preserve the two escape hatches: `?karo=default` (login) and "gate fails open when no page set" (maintenance).

**Internationalisation.** Wrap user-facing strings in `__()`/`esc_html__()` with text domain `karo-kit` from the start — cheap now, painful to retrofit.

**Uninstall.** Provide `uninstall.php` (or a "delete data on removal" toggle) that drops the leads table, options, and meta. mu-plugins skip uninstall hooks, so document manual cleanup if installed that way.

---

## 5. Testing checklist

**Regression (run after every phase).**
- [ ] Admin sees live site with gate on; logged-out sees gate.
- [ ] `?karo=default` reaches real login.
- [ ] Logout, lost-password, reset still work (not intercepted).
- [ ] No page selected anywhere → no lock-out, no white screen.
- [ ] Meta Box CPT pages selectable and render correctly.

**Per feature.**
- [ ] Bypass roles: in-role bypasses, out-of-role doesn't.
- [ ] Preview token: valid works, expired/tampered/regenerated fails.
- [ ] Schedule: inside window on, outside off, DST-safe.
- [ ] Countdown: timezone-correct, expiry swap.
- [ ] Leads: store, dedupe, CSV export, delete-by-email.
- [ ] Lockout: locks on N failures, resets on success, IP filter respected.
- [ ] CAPTCHA: blocks on bad/empty token, passes on valid.
- [ ] Login-URL hide: guest 404, secret works, REST/AJAX intact, recovery works.
- [ ] Redirects: each role lands correctly; logout redirect.
- [ ] Verification: unverified blocked, valid verifies+logs in, expiry resend, resend throttled.
- [ ] Custom fields: required enforced, meta stored, shows on profile.
- [ ] Branded mail: HTML renders, delivers, links valid, revert works.

**Environments.** Classic theme + block/FSE theme; with and without Cloudflare; PHP 8.1–8.3.

---

## 6. Settings reference (v1.0 target)

Full option inventory — also the schema for future **export/import** and WP-CLI.

| Option | Type | Default | Section |
|---|---|---|---|
| `karo_kit_gate_login_page` | int | 0 | Pages |
| `karo_kit_gate_register_page` | int | 0 | Pages |
| `karo_kit_gate_account_page` | int | 0 | Pages |
| `karo_kit_gate_lostpw_page` | int | 0 | Pages |
| `karo_kit_gate_maintenance_page` | int | 0 | Maintenance |
| `karo_kit_gate_maintenance_on` | bool | 0 | Maintenance |
| `karo_kit_gate_maintenance_mode` | enum | maintenance | Maintenance |
| `karo_kit_gate_bypass_roles` | array | [] | Maintenance |
| `karo_kit_gate_preview_token` | string | random | Maintenance |
| `karo_kit_gate_preview_ttl` | int | 1440 | Maintenance |
| `karo_kit_gate_schedule_on` | bool | 0 | Maintenance |
| `karo_kit_gate_start` | datetime | '' | Maintenance |
| `karo_kit_gate_end` | datetime | '' | Maintenance |
| `karo_kit_gate_leads_notify` | string | '' | Coming soon |
| `karo_kit_gate_lockout_max` | int | 5 | Security |
| `karo_kit_gate_lockout_window` | int | 15 | Security |
| `karo_kit_gate_lockout_cooldown` | int | 60 | Security |
| `karo_kit_gate_captcha_provider` | enum | none | Security |
| `karo_kit_gate_captcha_site` | string | '' | Security |
| `karo_kit_gate_captcha_secret` | string | '' | Security |
| `karo_kit_gate_captcha_on` | array | [] | Security |
| `karo_kit_gate_hide_login` | bool | 0 | Security |
| `karo_kit_gate_login_slug` | string | '' | Security |
| `karo_kit_gate_redirects` | array | [] | Redirects |
| `karo_kit_gate_logout_redirect` | int/string | 0 | Redirects |
| `karo_kit_gate_verify_on` | bool | 0 | Registration |
| `karo_kit_gate_verify_ttl` | int | 48 | Registration |
| `karo_kit_gate_mail_from_name` | string | '' | Email |
| `karo_kit_gate_mail_from` | string | '' | Email |
| `karo_kit_gate_mail_logo` | int | 0 | Email |
| `karo_kit_gate_mail_template` | text | default | Email |
| `karo_kit_gate_db_ver` | string | '' | (internal) |

**Shortcodes (v1.0):** `[karo_kit_field]`, `[karo_kit_message]`, `[karo_kit_logout]`, `[karo_kit_countdown]`, `[karo_kit_optin]`, `[karo_kit_captcha]`.

**Filters/actions (v1.0):** `karo_kit_gate_can_bypass`, `karo_kit_gate_client_ip`, `karo_kit_gate_register_fields`.

---

## 7. Decisions to settle before coding

1. **Tabs now or at Phase 3?** Recommend now — it's a 30-minute refactor and every later screen assumes it.
2. **Folder structure now or single file until Phase 3?** Recommend folder at the start of Phase 3.
3. **Leads: custom table vs CPT?** Table is leaner for export; CPT gives a free admin UI. Recommend table + a minimal `WP_List_Table`.
4. **Distribution:** wp.org-style or private GitHub-updater? Affects whether you need a text domain shipped and an updater module.
5. **How much registration depth** before it bleeds into "membership"? Hold the line at verification + basic meta; defer anything subscription-like to a separate module.
