# Architecture

This document describes the architectural intent, layer responsibilities, execution flow, and governance assumptions of **SPARXSTAR Photon VCard**.

---

## Repository Purpose

SPARXSTAR Photon VCard is a **production-grade WordPress plugin** that delivers a secure, accessible, and network-resilient digital business card overlay. It is designed for:

- Enterprise and personal brand deployment on WordPress / WooCommerce sites
- Controlled distribution and white-label licensing
- Offline-capable home-screen installation via a Stealth PWA
- Multi-user and WordPress Multisite environments

The plugin is **not** a general-purpose library. It is tightly scoped to a single feature (the business card overlay), intentionally has zero external runtime dependencies, and is designed to fail silently without degrading the host page.

---

## Design Invariants

These properties must be preserved across all future changes:

1. **No external runtime dependencies** — The plugin does not load scripts, fonts, analytics, or vendor application data from third-party origins. A documented exception exists for WordPress avatar rendering: if `get_avatar_url()` falls back to Gravatar, the browser may request the avatar image from Gravatar during normal operation.
2. **No tracking or analytics** — No pixels, beacons, or vendor analytics code. Custom DOM events are emitted for host-site consumption only.
3. **Zero page-blocking** — The plugin never blocks page rendering. If assets fail to load, the page continues to function normally.
4. **Fail-silent execution** — All runtime errors (broken sensors, localStorage failure, missing fields) are caught and swallowed without user-visible impact.
5. **Accessibility parity** — Every trigger has a keyboard-accessible equivalent. The modal implements a WCAG 2.1 focus trap.
6. **Multisite-first** — All activation, deactivation, uninstall, and option logic is aware of WordPress Multisite from the start.
7. **No PII persistence** — No contact data is stored beyond the in-memory JavaScript runtime of the current page view.

---

## Technology Stack

| Layer | Technology |
|-------|-----------|
| Platform | WordPress 6.8+ (Multisite-aware) |
| Server language | PHP 8.2+ |
| Namespace | `Starisian\Sparxstar\Photon` |
| Custom fields | Advanced Custom Fields (ACF) or Secure Custom Fields (SCF) |
| Front-end | Vanilla JS (ES2020, IIFE, no framework) |
| Styles | CSS custom properties, no preprocessor |
| QR generation | `qrcode` npm library (bundled, no CDN) |
| Build tooling | `clean-css-cli` (CSS), `uglify-js` (JS) |
| PHP quality | PHPCS (WordPress VIP + PSR-12), PHPStan Level 5+ |
| JS quality | ESLint (flat config, ES2020 globals) |
| CSS quality | Stylelint (standard config) |

---

## PHP Class Responsibilities

```
Bootloader           Activation / deactivation hooks; plugins_loaded init; requirement checks.
AssetLoader          Front-end orchestrator: wp_enqueue_scripts; user data localization;
                     per-user card payload assembly (build_card_data).
Shortcode            [spx_photon_vcard] shortcode: attribute parsing, user resolution, button output.
AcfFields            ACF local field group registration (acf/include_fields hook).
AcfHelper            ACF image field value normalizer: resolves 'array' and 'url' return formats.
PwaController        Stealth PWA: rewrite rules, manifest JSON, service-worker JS, head injection,
                     cookie extension, profile-version bumping.
Uninstaller          On-delete cleanup: options, transients, user meta, upload directory.
```

All classes are `final` — inheritance is not part of the extension model. Extension is via WordPress filters and actions.

---

## Execution Flow

### Request lifecycle (public page)

```
WordPress init
  └── plugins_loaded
        └── Bootloader::init()
              ├── load_plugin_textdomain()
              ├── AssetLoader::get_instance()          → registers wp_enqueue_scripts hook
              ├── AcfFields::register()                → registers acf/include_fields hook
              ├── Shortcode::register()                → registers [spx_photon_vcard] shortcode
              └── PwaController::register()            → registers rewrite, query_var, template_redirect,
                                                          wp_head, auth_cookie_expiration, profile_update hooks

wp_enqueue_scripts
  └── AssetLoader::enqueue_assets()
        ├── Scans $wp_query->posts for [spx_photon_vcard] shortcodes
        ├── Resolves user IDs (shortcode attr → post author → current user)
        └── For each permitted user: AssetLoader::enqueue_for_user()
              ├── Role check (sparxstar_photon_vcard_allowed_roles filter)
              ├── ACF spx_state_card_active check
              ├── [First user only] wp_enqueue_style + wp_enqueue_script + map init inline script
              ├── build_card_data() → sanitized PHP array → wp_json_encode → wp_add_inline_script
              └── Sets window.SPX_PHOTON_VCARD_DEFAULT on first user

wp_head
  └── PwaController::inject_pwa_head()         → <link rel="manifest"> when card is active

template_redirect (priority 1)
  └── PwaController::handle_virtual_routes()
        ├── spx_pwa_action=manifest → serve_manifest() (auth-gated; 404 for guests)
        ├── spx_pwa_action=sw       → serve_sw() (intentionally public)
        └── ?spx_app=1 + guest      → wp_safe_redirect to wp-login (with open-redirect guard)

Browser
  └── <script> SpxPhotonVCard IIFE
        ├── Reads window.SPX_PHOTON_VCARD_USERS / window.SPX_PHOTON_VCARD_DEFAULT
        ├── Builds DOM overlay lazily on first open
        ├── Registers event listeners: shortcode buttons, keyboard (Shift+V), long-press
        └── Optionally: motion/orientation sensors (after explicit user permission grant)
```

### Shortcode path

When `[spx_photon_vcard user_id="N"]` is in post content, the `AssetLoader::enqueue_assets()` pre-scan detects it and enqueues the user's data before `wp_head` fires. The shortcode callback `Shortcode::render()` then outputs the button; `AssetLoader::enqueue_for_user()` is idempotent so the second call is a no-op.

---

## Data Layer

Card data is assembled server-side in `AssetLoader::build_card_data()` and injected into the page as:

```js
window.SPX_PHOTON_VCARD_USERS[uid] = { /* sanitized card payload */ };
window.SPX_PHOTON_VCARD_DEFAULT    = uid;  // set on first user only
```

### Field resolution

| Field | Source |
|-------|--------|
| Name | `WP_User::display_name` |
| Email | `WP_User::user_email` |
| Website | `WP_User::user_url` |
| Title | ACF `spx_role_title` |
| Company | ACF `spx_org_name` |
| Phones | ACF `spx_rel_com_matrix` (phone-type rows: mobile, work, home, direct, fax) |
| Channels | ACF `spx_rel_com_matrix` (messaging-type rows: WhatsApp, Telegram, Signal, …) |
| Social | ACF `spx_rel_social_matrix` (platform + URL repeater) |
| Address | ACF `spx_loc_*` fields |
| Photo / Logo | ACF `spx_img_brand_blob` → Gravatar fallback (suppressed when `spx_state_img_pub` is false) |
| Card visible? | ACF `spx_state_card_active` (false → no assets enqueued, no button rendered) |
| Sensors disabled? | `sparxstar_photon_vcard_disable_sensors` filter (or `vip_motion_disable_sensors` alias) |

### Sanitization pipeline

```
get_field() / WP_User fields
  → sanitize_text_field / sanitize_email / esc_url_raw / sanitize_key
  → wp_json_encode(…, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP)
  → wp_add_inline_script()
```

No raw user data touches the DOM. JavaScript consumes the pre-sanitized JSON and inserts all text via `textContent` (never `innerHTML`).

---

## Namespace Conventions

| Type | Convention | Example |
|------|-----------|---------|
| PHP namespace | `Starisian\Sparxstar\Photon` | `use Starisian\Sparxstar\Photon\AssetLoader;` |
| PHP global prefix | `SPARXSTAR_PHOTON_VCARD_` (constants) | `SPARXSTAR_PHOTON_VCARD_VERSION` |
| WP hook prefix | `sparxstar_photon_vcard_` | `sparxstar_photon_vcard_allowed_roles` |
| WP option prefix | `sparxstar_photon_vcard_` | `sparxstar_photon_vcard_options` |
| ACF field key prefix | `spx_` | `spx_org_name`, `spx_state_card_active` |
| CSS id/class prefix | `spax-photon-` | `#spax-photon-card-overlay` |
| JS global prefix | `SPX_PHOTON_` | `window.SPX_PHOTON_VCARD_USERS` |
| JS class name | `SpxPhotonVCard` | — |
| Script/style handles | `spx-photon-` | `spx-photon-vcard`, `spx-photon-qrcode` |

---

## Extension Points (Filters and Actions)

### Filters

| Hook | Default | Description |
|------|---------|-------------|
| `sparxstar_photon_vcard_allowed_roles` | `['administrator', 'vip_business_user', 'editor']` | User roles permitted to have a card. Receives `$user_id` as second argument. |
| `sparxstar_photon_vcard_allowed_post_types` | All registered public post types | Post types for which the plugin auto-enqueues the post author's card when no shortcode is present. Pass an empty array to disable auto-enqueue. |
| `sparxstar_photon_vcard_disable_sensors` | `false` | Set to `true` to disable all motion/orientation triggers site-wide (e.g. for GDPR-restricted environments). Receives `$user_id` and `$post_id`. |
| `vip_motion_disable_sensors` | Inherits from above | Backwards-compatible alias for `sparxstar_photon_vcard_disable_sensors`. |

### Actions

| Hook | When fired | Description |
|------|-----------|-------------|
| `sparxstar_photon_vcard_missing_qr_asset` | During `wp_enqueue_scripts` when `WP_DEBUG` is true | Fired when `assets/js/qrcode.min.js` is absent. Use for deployment diagnostics. |

---

## Security Boundaries

| Boundary | Mechanism |
|----------|----------|
| Card visibility | ACF `spx_state_card_active` toggle (server-side; no JS bypass possible) |
| Role enforcement | `sparxstar_photon_vcard_allowed_roles` filter checked before any data is exposed |
| Data sanitization | PHP sanitization before JSON encoding; JS uses `textContent` only |
| XSS prevention | `JSON_HEX_*` encoding + DOM text-node insertion |
| URL validation | `esc_url_raw` on server; JS validates `http/https/mailto/tel` schemes before rendering |
| PWA manifest auth | `serve_manifest()` returns HTTP 404 for unauthenticated requests |
| Open-redirect guard | `?spx_app=1` redirect validates `REQUEST_URI` is site-relative only |
| Storage safety | `localStorage` access wrapped in try/catch; failure is silent |
| Sensor permissions | Motion events require explicit user gesture on iOS 13+ |
| DB queries | All database access via `$wpdb->prepare()` |

---

## Dependency Boundaries

| Plugin | Status | Behaviour when absent |
|--------|--------|----------------------|
| ACF / SCF | Optional | All ACF fields (title, company, phones, address, photo, toggles) default to empty; WP core fields (name, email, website) still work |
| WooCommerce | Not used | WooCommerce billing data is no longer read (removed in 0.5.0) |
| Gravatar | Optional | `get_avatar_url()` returns 404; JS `img.onerror` hides the broken image |

---

## Multisite Considerations

- Activation iterates every site in the network when network-activated (`switch_to_blog` loop).
- Deactivation removes the activation transient per site.
- Uninstall iterates every site for options/transients; user meta is deleted globally (single pass on `$wpdb->usermeta`).
- PWA URLs are derived from `home_url()` so they resolve correctly on Mercator-mapped domains.
- Options are stored per-site, never as network options.

---

## Caching and Proxy Awareness

The system runs behind Cloudflare → Nginx → Varnish → Apache → PHP-FPM → MariaDB → Redis.

- Plugin assets are versioned by `SPARXSTAR_PHOTON_VCARD_VERSION`, so CDN and browser caches are invalidated on each release.
- The PWA manifest and service worker are served with `Cache-Control: no-store, no-cache, must-revalidate` to prevent stale installs.
- The service worker uses a cache-first strategy for plugin assets and a network-first strategy for dynamic content — this is intentional so offline operation degrades gracefully.
- `delete_option('rewrite_rules')` on deactivation forces WordPress to regenerate rewrite rules on the next request, without re-registering the plugin's own rules while the plugin is still in memory.

---

## Performance Model

The plugin is designed for **2G/3G networks and low-end Android devices first**:

- Assets are minified and served as a single CSS file + single JS file (plus the QR library).
- Sensors are event-driven; no polling or `setInterval` except for the optional 10Hz orientation throttle while sensors are active.
- Sensors are unbound while the overlay is open and rebound only on close, preventing unnecessary battery drain.
- The card overlay DOM is built lazily on first open; it does not exist in the DOM until needed.
- Wake lock is acquired only while the overlay is visible.

---

## Future Cleanup Recommendations

The following items are tracked for future attention but are out of scope for the current release:

1. **PHP unit tests**: No PHPUnit suite exists. Adding test coverage for `build_card_data()`, the shortcode renderer, and the PWA controller would significantly reduce regression risk.
2. **JS unit tests**: No Jest suite exists. The trigger logic and overlay builder are good candidates for unit coverage.
3. **E2E tests**: Playwright smoke tests covering shortcode rendering, overlay open/close, and vCard download would automate manual regression testing.
4. **I18n**: String translation files (`languages/*.po`) do not yet exist. The PHP strings are correctly wrapped with `__()` / `esc_html__()` but need a `.pot` file and shipped translations.
5. **ACF field version migration**: If ACF field schemas change in a future version, a migration routine will be needed to rename user meta keys in existing installations.
