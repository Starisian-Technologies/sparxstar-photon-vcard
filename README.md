<img width="1280" height="640" alt="SPARXSTAR Photon VCard" src="https://github.com/user-attachments/assets/9a80fc9d-a64b-4d9b-b277-39a2e5a0c158" />


SPARXSTAR Photon VCard
======================

**Version:** 0.5.0
**Status:** Production / Master Edition\
**Scope:** WordPress plugin (PHP + client-side JavaScript)
**Author:** Starisian Technologies (Max Barrett)
**License:** Starisian Technologies Proprietary

Copyright (c) 2025 Starisian Technologies. All rights reserved.

[![CodeQL](https://github.com/Starisian-Technologies/sparxstar-photon-vcard/actions/workflows/github-code-scanning/codeql/badge.svg)](https://github.com/Starisian-Technologies/sparxstar-photon-vcard/actions/workflows/github-code-scanning/codeql)  [![Copilot code review](https://github.com/Starisian-Technologies/sparxstar-photon-vcard/actions/workflows/copilot-pull-request-reviewer/copilot-pull-request-reviewer/badge.svg)](https://github.com/Starisian-Technologies/sparxstar-photon-vcard/actions/workflows/copilot-pull-request-reviewer/copilot-pull-request-reviewer)  [![Copilot coding agent](https://github.com/Starisian-Technologies/sparxstar-photon-vcard/actions/workflows/copilot-swe-agent/copilot/badge.svg)](https://github.com/Starisian-Technologies/sparxstar-photon-vcard/actions/workflows/copilot-swe-agent/copilot)  [![Lint](https://github.com/Starisian-Technologies/sparxstar-photon-vcard/actions/workflows/lint.yml/badge.svg)](https://github.com/Starisian-Technologies/sparxstar-photon-vcard/actions/workflows/lint.yml)

[![Build & Release](https://github.com/Starisian-Technologies/sparxstar-photon-vcard/actions/workflows/build-release.yml/badge.svg)](https://github.com/Starisian-Technologies/sparxstar-photon-vcard/actions/workflows/build-release.yml)

* * * * *

Overview
--------

The **SPARXSTAR Photon VCard** plugin delivers a secure, accessible, and resilient digital business card overlay for WordPress/WooCommerce sites. It pulls contact data from ACF, WooCommerce billing fields, WordPress core, and Gravatar, then renders a polished card overlay that visitors can share as a `.vcf` file or transmit via AirDrop / Nearby Share.

The card overlay can be triggered via:

-   **Shortcode button** — place anywhere in content with `[spx_photon_vcard]`

-   **Device shake** — 3 acceleration events > 18 m/s² within 1.2 s

-   **Face-down flip** — single face-down orientation event (beta ≥ 145°)

-   **Touch fallback** — long-press anywhere on the page

-   **Keyboard fallback** — `Shift + V`

The system is designed for **legacy hardware**, **high-latency networks**, **accessibility compliance**, and **enterprise deployment constraints**, including kiosk and private browsing environments.

* * * * *

Design Principles
-----------------

-   **Fail-fast execution** --- no work is done unless explicitly permitted

-   **Zero external dependencies**

-   **Inline delivery** for low-bandwidth environments

-   **Battery-aware sensor usage**

-   **Accessibility-first modal behavior**

-   **Graceful degradation on broken or unavailable sensors**

-   **Auditor-friendly security model**

* * * * *

Shortcode
---------

Place the card trigger button anywhere in post/page content using the `[spx_photon_vcard]` shortcode.

```
[spx_photon_vcard]
[spx_photon_vcard text="View My Card"]
[spx_photon_vcard text="Share Contact" class="my-class" id="hero-card-btn"]
[spx_photon_vcard user_id="42" text="Jane's Card"]
```

### Attributes

| Attribute | Default | Description |
|-----------|---------|-------------|
| `text` | `"View My Card"` | Button label |
| `class` | `""` | Extra CSS classes added to the button |
| `id` | `""` | HTML `id` attribute on the button |
| `user_id` | Explicit attribute, otherwise current post author, otherwise current logged-in user | WordPress user ID whose card data to display |

### Behaviour

-   The shortcode renders a `<button>` that opens the business card overlay for the specified user when clicked.

-   If the target user's `spx_display_business_card` ACF toggle is **off**, or their role is not permitted, the shortcode returns **an empty string** — no button is rendered and no assets are enqueued.

-   When any shortcode trigger is present on the page, the floating auto-injected button hides itself automatically to avoid duplication.

-   Multiple shortcodes with different `user_id` values on the same page are fully supported — each user's card data is loaded independently.

* * * * *

Data Layer
----------

Card data is assembled in layers. Because this is a commercial plugin, different deployments will have different combinations of data sources installed. The resolver is designed for that reality: each layer enriches or overrides what the layer below provides.

### Layer 1 — WordPress core (always available)

Every WordPress installation provides the baseline: `display_name` (name), `user_email`, and `user_url`. These fields are always resolved and are the universal fallback for any field not supplied by a higher layer.

### Layer 2 — ACF / SCF custom fields (when ACF or Secure Custom Fields is installed)

ACF `spx_*` fields are the primary data source for dedicated business-card data. When ACF is active they take precedence over WP core for every field they cover:

-   **Title** — `spx_title`
-   **Phones** — `spx_mobile` (CELL), `spx_work_phone` (WORK), `spx_fax` (FAX)
-   **WhatsApp** — `spx_whatsapp_phone`
-   **Website** — `spx_website` (falls back to `user_url` when empty)
-   **Display toggle** — `spx_display_business_card` (card is suppressed when false)
-   **Company / address** — `spx_company`, `spx_address_1/2`, `spx_city`, `spx_state`, `spx_postcode`, `spx_country` (only registered and used when WooCommerce is absent)

### Layer 3 — Gravatar (profile photo)

The profile photo is fetched via WordPress's `get_avatar_url()` using the user's email hash (`size` 200, `default '404'`). The JS overlay hides the `<img>` element when the URL returns 404 (no Gravatar uploaded).

### Layer 4 — WooCommerce billing meta (when WooCommerce is installed)

When WooCommerce is present it contributes billing-specific data that is preferred over ACF fallback values for the fields it covers:

-   **Name** — `billing_first_name` + `billing_last_name` (preferred over `display_name`)
-   **Company** — `billing_company` (falls back to ACF `spx_company` when empty)
-   **Email** — `billing_email` (overrides `user_email` when present)
-   **Phone** — `billing_phone` (used as CELL fallback only when no ACF phone fields are set)
-   **Address** — `billing_address_1/2`, `billing_city`, `billing_state`, `billing_postcode`, `billing_country` (replaces ACF address fields entirely when WooCommerce is installed)

The enriched payload is localized to JavaScript as `window.SPX_PHOTON_VCARD_USERS[uid]`. When the plugin auto-enqueues for the current user (non-shortcode path), the default uid is also stored in `window.SPX_PHOTON_VCARD_DEFAULT`.

Phone entries carry a `type` field (`CELL`, `WORK`, or `FAX`) that maps directly to the vCard `TEL;TYPE=` attribute and determines the icon displayed in the overlay.

* * * * *

ACF Field Groups
----------------

The plugin registers two ACF local field groups automatically — no manual field creation is required.

### Base Group (always registered)

| Field key | Label | Type |
|-----------|-------|------|
| `spx_title` | Job Title | Text |
| `spx_work_phone` | Work Phone | Text (max 25 chars) |
| `spx_mobile` | Mobile | Text (max 25 chars) |
| `spx_fax` | Fax | Text (max 25 chars) |
| `spx_whatsapp_phone` | WhatsApp | Text (max 25 chars) |
| `spx_display_business_card` | Display Business Card | True/False |

### Fallback Group (registered only when WooCommerce is absent)

| Field key | Label | Type |
|-----------|-------|------|
| `spx_company` | Company | Text |
| `spx_address_1` | Address Line 1 | Text |
| `spx_address_2` | Address Line 2 | Text |
| `spx_city` | City | Text |
| `spx_state` | State / County | Text |
| `spx_postcode` | Postcode / ZIP | Text |
| `spx_country` | Country | Text |
| `spx_website` | Website URL | URL |

All fields are attached to the **User** post type and appear under the user profile in WP Admin.

To show a user's card, set **Display Business Card** to `true` on their profile. When `false`, neither the floating button nor any shortcode button will render for that user.

* * * * *

Business Card UI
----------------

The overlay is styled as a dark-gradient business card:

-   **Background:** `linear-gradient(145deg, #1c1c1e, #2c2c2e)`

-   **Profile photo:** circular Gravatar (60 × 60 px) with CSP-safe `error` fallback

-   **Identity block:** name, job title, company

-   **Contact rows:** tap-to-call phone numbers, WhatsApp deep-link (`wa.me`), `mailto:` email, website, formatted address

-   **QR section:** QR code encoding the full vCard 3.0 payload

-   **Action buttons:**

    -   **Send** — opens the device share flow for sending the contact from the overlay

    -   **Share Link** — shares or copies the contact link, depending on platform support

    -   **Save Contact** — saves the vCard/contact to the device

    -   **Close** — dismisses the overlay

-   **Motion permission button:** `#spax-photon-sensor-grant` is a separate floating control labeled **Enable Motion Trigger** that requests `DeviceOrientationEvent` and `DeviceMotionEvent` permissions (iOS 13+) so shake and flip triggers work
* * * * *

Trigger Methods
---------------

### 1\. Motion Triggers (Shake + Flip)

**Shake** (new):

-   Three `devicemotion` acceleration-delta events > 18 m/s² within a 1.2 s window trigger the overlay

-   Requires motion permission on iOS 13+ (requested via the "Enable Motion Trigger" button)

**Flip** (refined):

-   A single face-down `deviceorientation` event with `beta ≥ 145°` triggers the overlay

-   Stabilisation delay reduced; threshold lowered from 155° to 145°

Both triggers share the same `requestSensorAccess()` / `enableSensors()` permission flow. On iOS 13+, both `DeviceOrientationEvent.requestPermission()` and `DeviceMotionEvent.requestPermission()` are called; each listener is registered only when its permission is granted.

-   Automatically disabled when:

    -   Reduced motion is enabled

    -   Sensors are disabled server-side

    -   The overlay is active

### 2\. Touch Fallback (Always Available)

-   Long-press anywhere on the page

-   Includes:

    -   Scroll-movement guard

    -   Active-overlay suppression

-   Designed for:

    -   Broken sensors

    -   Desktop touch screens

    -   Low-end Android devices

### 3\. Keyboard Fallback (Desktop / Kiosk)

-   `Shift + V`

-   Automatically disabled while the modal is open

-   Includes `Escape` key handling to close the modal

* * * * *

Accessibility Compliance
------------------------

The runtime implements a **WCAG-aligned modal pattern**:

-   `role="dialog"`

-   `aria-modal="true"`

-   `aria-live="assertive"`

-   Keyboard focus trapping

-   Visible focus outlines

-   Escape key dismissal

-   Respects `prefers-reduced-motion`

No animation or motion is required for operation.

* * * * *

Security Model
--------------

### Data Handling

-   Only the following fields are exposed client-side:

    -   Name, job title, company

    -   Phone numbers (CELL, WORK, FAX), WhatsApp

    -   Email, website

    -   Address (street, city, state, postcode, country)

    -   Gravatar photo URL

    -   Logo URL

-   Data is JSON-encoded with hex escaping

-   No cookies are used

-   No PII is persisted beyond runtime memory

### XSS Prevention

-   All text is escaped via DOM text nodes

-   URLs are strictly validated to allow only:

    -   `http`

    -   `https`

    -   `mailto`

    -   `tel`

-   Invalid or unsafe URLs are silently discarded

* * * * *

Storage Safety
--------------

The runtime uses a guarded local storage accessor:

-   Prevents crashes in:

    -   Private browsing mode

    -   Locked kiosk environments

    -   Restricted WebViews

-   Storage failures fail silently and do not block execution

* * * * *

Sensor Lifecycle & Battery Hygiene
----------------------------------

-   Sensors are:

    -   Bound only after permission is granted

    -   Unbound when the overlay is active

    -   Rebound only if motion is allowed on close

-   Orientation events are throttled to ~10Hz

-   Stabilization prevents false positives on noisy hardware

* * * * *

Analytics Contract
------------------

The runtime emits custom DOM events without requiring analytics vendors.

### Events Dispatched

-   `spx-photon-card-event`

    -   type: `open`

    -   method: `button | shortcode | flip | shake`

    -   timestamp

-   `spx-photon-card-event`

    -   type: `close`

    -   timestamp

These events can be consumed by any analytics or logging system without modifying the runtime.

* * * * *

Server-Side Control
-------------------

Sensor usage can be disabled **entirely** via a WordPress filter before JavaScript execution.

This allows compliance with:

-   Government environments

-   Education systems

-   Privacy-restricted deployments

Fallback triggers remain functional when sensors are disabled.

* * * * *

Browser & Device Support
------------------------

Tested and designed for:

-   Android (low-end and legacy devices)

-   iOS Safari

-   Desktop Chrome / Firefox

-   Kiosk and embedded WebViews

-   High-latency mobile networks

Graceful degradation is guaranteed when features are unavailable.

* * * * *

What This Runtime Does NOT Do
-----------------------------

-   No external network requests

-   No tracking pixels

-   No third-party scripts

-   No framework usage

-   No persistent UI elements

-   No automatic activation without user intent

* * * * *

Intended Usage
--------------

This plugin is intended to be:

-   Installed on WordPress / WooCommerce sites

-   Loaded only on authorized pages (per-user `spx_display_business_card` toggle)

-   Available on rendered pages to site visitors and authenticated users; viewing the card is not authentication-gated by default

-   Governed by server-side role permissions where enforced by the host site, configurable via the `sparxstar_photon_vcard_allowed_roles` filter

-   Governed by server-side permissions

It is **not** designed as a standalone library.

* * * * *

License & Deployment
--------------------

This runtime is designed for **controlled distribution** and enterprise deployment.

Usage, redistribution, and modification should follow the licensing terms defined by the parent project.

* * * * *

Final Notes
-----------

This implementation prioritizes **real-world reliability** over abstraction.

If something fails:

-   It fails silently

-   It does not block the page

-   It does not degrade accessibility

-   It does not leak data

That behavior is intentional.
