# Changelog

All notable changes to **SPARXSTAR Photon VCard** are documented in this file.

This project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

---

## [Unreleased]

_No unreleased changes at this time._

---

## [0.5.0] — 2025-12-30

### Added

- **Shake trigger**: Three `devicemotion` acceleration-delta events > 18 m/s² within a 1.2 s window now trigger the card overlay, in addition to the existing face-down flip.
- **Communication channels repeater** (`spx_rel_com_matrix`): Replaces individual phone ACF fields. Supports mobile, work, home, direct, fax, WhatsApp, Telegram, Signal, WeChat, Viber, Line, Zalo, KakaoTalk, Microsoft Teams, and Zoom.
- **Social media repeater** (`spx_rel_social_matrix`): Replaces individual social field. Supports 50+ platforms including LinkedIn, Instagram, TikTok, Bluesky, Mastodon, YouTube, and region-specific networks.
- **Logo / brand image** (`spx_img_brand_blob`): ACF image field for the card owner's logo. Displayed in the overlay and used as the PWA icon when available.
- **Share Profile Image toggle** (`spx_state_img_pub`): Controls whether any photo (ACF or Gravatar) is included in the client-side payload.
- **Fullscreen QR mode**: Tapping the QR code expands it to fill the viewport (black on white) for easy across-desk scanning.
- **WhatsApp share button**: Renders a `wa.me` deep-link when a WhatsApp channel is configured.
- **Stealth PWA** (`PwaController`): Owner-only home-screen installation. Web App Manifest is auth-gated (404 for guests); Service Worker is intentionally public for update delivery.
- **Country field** (`spx_loc_country_code`): ISO 3166-1 alpha-3 select with all UN member states and observer states, rendered in each country's own language.
- **AcfHelper utility class**: Centralises ACF image field resolution to prevent consumer drift.
- **Wake lock**: Screen stays on while the card overlay is visible.
- **`sparxstar_photon_vcard_missing_qr_asset` action**: Fired in `WP_DEBUG` mode when `qrcode.min.js` is absent, aiding deployment diagnostics.

### Changed

- **Flip threshold lowered** from 155° to 145° (`beta` angle) for faster face-down detection.
- **ACF field schema refactored**: Consolidated two field groups (base + WooCommerce fallback) into a single "User Details" group. Field keys and names updated throughout.
- **Display toggle renamed** from `spx_display_business_card` to `spx_state_card_active` to align with the new `spx_state_*` naming convention.
- **Data localization**: `window.SPX_PHOTON_VCARD_USERS` now includes `channels` and `social` arrays alongside the existing `phones` array.
- **PHP namespace**: All server-side classes live in `Starisian\Sparxstar\Photon`.
- **Minimum requirements**: PHP 8.2+ and WordPress 6.8+.
- **Asset handles**: CSS handle `spx-photon-vcard`; JS handle `spx-photon-vcard`; QR library handle `spx-photon-qrcode`.
- **Sensor permission flow**: Both `DeviceOrientationEvent.requestPermission()` and `DeviceMotionEvent.requestPermission()` are called together on the "Enable Motion Trigger" button press (iOS 13+).

### Removed

- **WooCommerce billing data layer**: No longer used. Address and company data are sourced exclusively from ACF `spx_loc_*` / `spx_org_name` fields.
- **Individual phone ACF fields** (`spx_mobile`, `spx_work_phone`, `spx_fax`, `spx_whatsapp_phone`): Superseded by `spx_rel_com_matrix` repeater.
- **WooCommerce fallback ACF group**: Address, company, and website fields are now always registered (single consolidated group).

### Security

- All card data is sanitized server-side (`sanitize_text_field`, `sanitize_email`, `esc_url_raw`, `sanitize_key`) before JSON-encoding with `JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP`.
- Open-redirect guard added to the PWA `?spx_app=1` login-redirect flow.
- `spx_pwa_manifest.json` returns HTTP 404 for unauthenticated requests, preventing install-prompt exposure to public visitors.

---

## [0.4.x and earlier]

Earlier versions were internal development builds. No public changelog exists for pre-0.5.0 releases.

---

[Unreleased]: https://github.com/Starisian-Technologies/sparxstar-photon-vcard/compare/v0.5.0...HEAD
[0.5.0]: https://github.com/Starisian-Technologies/sparxstar-photon-vcard/releases/tag/v0.5.0
