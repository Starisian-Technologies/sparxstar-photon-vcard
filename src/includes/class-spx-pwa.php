<?php
/**
 * PWA Controller — Stealth PWA for owner-only home-screen installation.
 *
 * @package Starisian\Sparxstar\Photon
 * @since   0.5.0
 */

declare(strict_types=1);

namespace Starisian\Sparxstar\Photon;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PWA Controller — Stealth PWA for owner-only home-screen installation.
 *
 * Implements a "Stealth PWA":
 *
 *   – Web App Manifest (/spx-pwa-manifest.json) is auth-gated: unauthenticated
 *     requests receive HTTP 404, so the browser never shows an install prompt
 *     to public visitors.
 *
 *   – Service Worker (/spx-pwa-sw.js) is intentionally PUBLIC: browsers and
 *     already-installed PWAs must be able to fetch an updated worker file at
 *     any time — including when the owner's auth cookie has expired — so they
 *     can receive cache-invalidation and bug-fix updates without requiring a
 *     fresh login.  No sensitive data is embedded in the SW script itself;
 *     the precache list is personalised only when a valid session exists, and
 *     falls back to plugin-level assets only when the request is unauthenticated.
 *
 * Virtual routes handled via WordPress rewrite rules:
 *   /spx-pwa-manifest.json  — Owner-only Web App Manifest (auth-gated: 404 for guests)
 *   /spx-pwa-sw.js          — Service Worker script (intentionally public for update flow)
 *
 * Cookie persistence:
 *   When the installed PWA opens with ?spx_app=1, the auth-cookie expiration
 *   is extended to one year so the owner stays logged in on their device.
 *
 * Multisite / Mercator-aware:
 *   All URLs are derived from home_url() so they resolve correctly against
 *   each site's mapped domain, never the network root.
 *
 * Nginx complement (add to the site's server block):
 *   Do not hardcode Service-Worker-Allowed "/" here. The controller sends
 *   that header dynamically from home_url('/') so root installs use "/"
 *   and subdirectory/path-based multisite installs use their site base path
 *   (for example "/blog/"). If you choose to set the header in Nginx
 *   instead, it must exactly match this site's base path.
 * {@code
 * location ~ ^/spx-pwa-(manifest\.json|sw\.js)$ {
 *     try_files $uri $uri/ /index.php?$args;
 *     add_header Cache-Control "no-store, no-cache, must-revalidate, proxy-revalidate, max-age=0";
 *     include fastcgi_params;
 *     fastcgi_param SCRIPT_FILENAME $document_root/index.php;
 *     fastcgi_pass unix:/run/php/php-fpm.sock;
 * }
 * }
 *
 * @package Starisian\Sparxstar\Photon
 * @since   0.5.0
 */
final class PwaController {

	/**
	 * Maximum character length for the PWA short_name field.
	 *
	 * The Web App Manifest spec recommends keeping short_name under 12
	 * characters so it fits beneath the icon on most home-screen launchers.
	 */
	private const PWA_SHORT_NAME_MAX_LENGTH = 12;

	/**
	 * Minimum icon dimension (width and height) required for a PWA maskable icon.
	 *
	 * The PWA manifest spec requires icons of at least 192×192 px for the
	 * launcher icon and 512×512 px for the splash screen.  Images smaller than
	 * this threshold are reported with size 'any' rather than an explicit WxH
	 * string, letting the browser decide how to use them.
	 */
	private const PWA_MIN_ICON_SIZE = 192;

	/**
	 * Return the site's base URL path, used as the SW scope and Service-Worker-Allowed value.
	 *
	 * Returns '/' for root installs and '/blog/' (with trailing slash) for
	 * subdirectory installs, matching the path component of home_url('/').
	 * Consistent across serve_manifest(), serve_sw(), and inject_pwa_head().
	 *
	 * @return string Absolute URL path (always starts and ends with '/').
	 */
	private static function home_path(): string {
		$path = wp_parse_url( home_url( '/' ), PHP_URL_PATH ) ?: '/';
		// Ensure a trailing slash so the SW scope is always a directory path.
		return rtrim( $path, '/' ) . '/';
	}

	/**
	 * Register all WordPress hooks for the PWA controller.
	 *
	 * Called once from {@see Bootloader::init()}.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'init', [ self::class, 'register_rewrite_rules' ] );
		add_filter( 'query_vars', [ self::class, 'add_query_vars' ] );
		add_action( 'template_redirect', [ self::class, 'handle_virtual_routes' ], 1 );
		add_action( 'wp_head', [ self::class, 'inject_pwa_head' ] );
		add_filter( 'auth_cookie_expiration', [ self::class, 'extend_cookie_for_pwa' ], 10, 3 );
		add_action( 'profile_update', [ self::class, 'bump_profile_version' ] );
		add_action( 'acf/save_post', [ self::class, 'bump_profile_version_acf' ] );
	}

	/**
	 * Register WordPress rewrite rules for the PWA virtual files.
	 *
	 * Maps the two PWA file paths to WordPress's front controller so that
	 * PHP (and our authentication checks) can handle the response.
	 *
	 * @return void
	 */
	public static function register_rewrite_rules(): void {
		add_rewrite_rule( '^spx-pwa-manifest\.json$', 'index.php?spx_pwa_action=manifest', 'top' );
		add_rewrite_rule( '^spx-pwa-sw\.js$', 'index.php?spx_pwa_action=sw', 'top' );
	}

	/**
	 * Register the spx_pwa_action query variable with WordPress.
	 *
	 * @param  string[] $vars Registered query variable names.
	 * @return string[]
	 */
	public static function add_query_vars( array $vars ): array {
		$vars[] = 'spx_pwa_action';
		return $vars;
	}

	/**
	 * Route virtual PWA file requests and handle the PWA session redirect.
	 *
	 * Fires on template_redirect at priority 1 (before default handlers).
	 *
	 * Behaviour:
	 *   • If ?spx_app=1 is present and the user is not logged in, redirect
	 *     once to wp-login.php with the card URL as the return destination,
	 *     preserving the ?spx_app=1 parameter so cookie persistence activates
	 *     on successful login.
	 *   • If spx_pwa_action=manifest, serve the Web App Manifest.
	 *   • If spx_pwa_action=sw, serve the Service Worker script.
	 *
	 * @return void
	 */
	public static function handle_virtual_routes(): void {
		// Dispatch PWA virtual routes first so the SW endpoint (intentionally
		// public) and the manifest endpoint (returns 404 for guests) are never
		// caught by the ?spx_app=1 login-redirect below.
		$action = (string) get_query_var( 'spx_pwa_action', '' );

		if ( 'manifest' === $action ) {
			self::serve_manifest();
		} elseif ( 'sw' === $action ) {
			self::serve_sw();
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$spx_app = isset( $_GET['spx_app'] ) ? sanitize_key( $_GET['spx_app'] ) : '';
		// phpcs:enable

		// Session persistence: if the installed PWA opens with ?spx_app=1 and
		// the cookie has expired, send the owner to wp-login once, preserving
		// the full current URL (including ?spx_app=1) as the return destination.
		if ( '1' === $spx_app && ! is_user_logged_in() ) {
			// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$request_uri = isset( $_SERVER['REQUEST_URI'] )
				? wp_unslash( (string) $_SERVER['REQUEST_URI'] )
				: '/';
			// phpcs:enable
			// Guard against open-redirect: the URI must be a site-relative path only
			// (not protocol-relative '//host/…' or absolute with scheme '://').
			if ( ! str_starts_with( $request_uri, '/' )
				|| str_starts_with( $request_uri, '//' )
				|| str_contains( $request_uri, '://' ) ) {
				$request_uri = '/';
			}
			// Normalize: ensure exactly one leading slash on the validated value.
			$request_uri = '/' . ltrim( $request_uri, '/' );

			$return_url = esc_url_raw(
				add_query_arg(
					'spx_app',
					'1',
					home_url( $request_uri )
				)
			);
			wp_safe_redirect( wp_login_url( $return_url ), 302 );
			exit;
		}
	}

	/**
	 * Serve the Web App Manifest.
	 *
	 * Returns HTTP 404 for unauthenticated visitors (the "stealth" layer).
	 * For a logged-in card owner, returns a full JSON manifest that triggers
	 * the browser's "Add to Home Screen" prompt.
	 *
	 * @return never
	 */
	private static function serve_manifest(): never {
		if ( ! is_user_logged_in() ) {
			status_header( 404 );
			nocache_headers();
			exit;
		}

		$uid  = get_current_user_id();
		$user = get_userdata( $uid );

		if ( ! $user instanceof \WP_User ) {
			status_header( 404 );
			nocache_headers();
			exit;
		}

		// ── App name ─────────────────────────────────────────────────────────────
		// Priority: ACF spx_org_name → WP display_name.
		$app_name = '';
		if ( function_exists( 'get_field' ) ) {
			$app_name = (string) ( get_field( 'spx_org_name', 'user_' . $uid ) ?: '' );
		}
		if ( '' === $app_name ) {
			$app_name = $user->display_name;
		}
		$app_name = sanitize_text_field( $app_name );
		if ( function_exists( 'mb_strimwidth' ) ) {
			$short_name = mb_strimwidth( $app_name, 0, self::PWA_SHORT_NAME_MAX_LENGTH, '…' );
		} else {
			$short_name = wp_html_excerpt( $app_name, self::PWA_SHORT_NAME_MAX_LENGTH, '…' );
		}

		// ── Icon ─────────────────────────────────────────────────────────────────
		// Priority: spx_img_brand_blob (ACF image field) → scf_business_logo_url → Gravatar.
		// Capture mime type and dimensions alongside the URL so the manifest entry
		// reflects what the server will actually serve (the site may convert
		// uploads to avif/webp depending on browser support).
		$icon_url    = '';
		$icon_mime   = 'image/jpeg';
		$icon_width  = 0;
		$icon_height = 0;

		if ( function_exists( 'get_field' ) ) {
			$img         = AcfHelper::resolve_image( get_field( 'spx_img_brand_blob', 'user_' . $uid ) );
			$icon_url    = $img['url'];
			$icon_mime   = $img['mime'];
			$icon_width  = $img['width'];
			$icon_height = $img['height'];
		}
		if ( '' === $icon_url ) {
			$scf_logo = (string) ( get_user_meta( $uid, 'scf_business_logo_url', true ) ?: '' );
			if ( '' !== $scf_logo ) {
				$img       = AcfHelper::resolve_image( $scf_logo );
				$icon_url  = $img['url'];
				$icon_mime = $img['mime'];
			}
		}
		if ( '' === $icon_url ) {
			$icon_url    = esc_url_raw(
				(string) get_avatar_url(
					$uid,
					[
						'size'    => 512,
						'default' => '404',
					]
				)
			);
			$icon_mime   = 'image/jpeg';
			$icon_width  = 512;
			$icon_height = 512;
		}

		// ── start_url ─────────────────────────────────────────────────────────────
		// The ?spx_app=1 parameter triggers the 1-year cookie extension on login,
		// ensuring the owner stays logged in on their device indefinitely.
		$start_url = esc_url_raw( add_query_arg( 'spx_app', '1', home_url( '/' ) ) );

		// ── Icons array ──────────────────────────────────────────────────────────
		// Use the actual dimensions and mime type captured during icon resolution.
		// The size string uses 'any' when exact dimensions are unavailable.
		$icons = [];
		if ( '' !== $icon_url ) {
			$size_str = ( $icon_width >= self::PWA_MIN_ICON_SIZE && $icon_height >= self::PWA_MIN_ICON_SIZE )
				? "{$icon_width}x{$icon_height}"
				: 'any';
			$icons[]  = [
				'src'     => $icon_url,
				'sizes'   => $size_str,
				'type'    => $icon_mime,
				'purpose' => 'any maskable',
			];
		}

		$manifest = [
			'name'             => $app_name,
			'short_name'       => $short_name,
			'description'      => sprintf(
				/* translators: %s: Card owner's app/organisation name */
				__( '%s — Digital Business Card', 'sparxstar-photon-vcard' ),
				$app_name
			),
			'start_url'        => $start_url,
			'scope'            => self::home_path(),
			'display'          => 'standalone',
			'orientation'      => 'portrait',
			'background_color' => '#1c1c1e',
			'theme_color'      => '#1c1c1e',
			'icons'            => $icons,
		];

		$sw_allowed_path = self::home_path();

		status_header( 200 );
		header( 'Content-Type: application/manifest+json; charset=utf-8' );
		header( 'Service-Worker-Allowed: ' . $sw_allowed_path );
		header( 'Cache-Control: no-store, no-cache, must-revalidate, proxy-revalidate, max-age=0' );
		header( 'X-Content-Type-Options: nosniff' );

		$json = wp_json_encode( $manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
		if ( false === $json ) {
			status_header( 500 );
			exit;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $json;
		exit;
	}

	/**
	 * Serve the Service Worker script.
	 *
	 * Outputs a personalized vanilla-JavaScript service worker.  The SW
	 * implements:
	 *
	 *  – Install: pre-caches the plugin CSS, JS, and the owner's profile photo.
	 *  – Fetch (static assets):  cache-first strategy for CSS/JS/images.
	 *  – Fetch (HTML / data):    network-first strategy so the QR code and
	 *    contact data are always current; falls back to cache on failure.
	 *  – Offline: returns a minimal embedded offline page when both the
	 *    network and cache are unavailable.
	 *
	 * The SW is served without auth check so already-installed PWAs can
	 * update the worker file transparently.  The precache list is
	 * personalised to the logged-in user when a session exists.
	 *
	 * @return never
	 */
	private static function serve_sw(): never {
		$uid     = is_user_logged_in() ? get_current_user_id() : 0;
		$version = SPARXSTAR_PHOTON_VCARD_VERSION;

		$debug = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG;

		$css_url = esc_url_raw(
			$debug
				? SPARXSTAR_PHOTON_VCARD_PLUGIN_URL . 'src/css/sparxstar-photon-vcard.css'
				: SPARXSTAR_PHOTON_VCARD_PLUGIN_URL . 'assets/css/sparxstar-photon-vcard.min.css'
		);
		$js_url  = esc_url_raw(
			$debug
				? SPARXSTAR_PHOTON_VCARD_PLUGIN_URL . 'src/js/sparxstar-photon-vcard.js'
				: SPARXSTAR_PHOTON_VCARD_PLUGIN_URL . 'assets/js/sparxstar-photon-vcard.min.js'
		);

		$photo_url = '';
		if ( $uid > 0 ) {
			$photo_url = esc_url_raw(
				(string) get_avatar_url(
					$uid,
					[
						'size'    => 200,
						'default' => '404',
					]
				)
			);
		}

		$precache_urls        = array_values( array_filter( [ $css_url, $js_url, $photo_url ] ) );
		$precache_json_result = wp_json_encode( $precache_urls, JSON_UNESCAPED_SLASHES );
		$precache_json        = is_string( $precache_json_result ) ? $precache_json_result : '[]';
		$cache_name           = 'spx-vcard-v' . $version;

		// Derive the site's base path for the SW scope (Service-Worker-Allowed header)
		// and for path-restriction guards in the SW fetch handler.
		$home_path = self::home_path();

		$sw = self::build_sw_script( $cache_name, $precache_json, $home_path );

		status_header( 200 );
		header( 'Content-Type: application/javascript; charset=utf-8' );
		header( 'Cache-Control: no-store, no-cache, must-revalidate, proxy-revalidate, max-age=0' );
		header( 'Service-Worker-Allowed: ' . $home_path );
		header( 'X-Content-Type-Options: nosniff' );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $sw;
		exit;
	}

	/**
	 * Build the Service Worker JavaScript string.
	 *
	 * @param  string $cache_name     Cache storage key (versioned).
	 * @param  string $precache_json  JSON-encoded array of URLs to pre-cache.
	 * @param  string $home_path      Site base path (e.g. '/' or '/blog/') for path guards.
	 * @return string                 Complete SW JavaScript source.
	 */
	private static function build_sw_script( string $cache_name, string $precache_json, string $home_path = '/' ): string {
		$cache_name_js   = wp_json_encode( $cache_name );
		$offline_html_js = wp_json_encode( self::offline_html() );
		$home_path_js    = wp_json_encode( rtrim( $home_path, '/' ) );

		// Ensure json_encode failures do not produce invalid JS.
		if ( false === $cache_name_js || false === $offline_html_js || false === $home_path_js ) {
			$cache_name_js   = '"spx-vcard"';
			$offline_html_js = '"<html><body>Offline</body></html>"';
			$home_path_js    = '""';
		}

		return <<<JS
/* SPARXSTAR Photon VCard — Service Worker
 * Auto-generated by PwaController. Do not edit directly.
 */
'use strict';

var CACHE_NAME   = {$cache_name_js};
var PRECACHE     = {$precache_json};
var OFFLINE_HTML = {$offline_html_js};
var SPX_BASE     = {$home_path_js};

var STATIC_EXTS = /\.(css|js|png|jpg|jpeg|svg|gif|webp|avif|bmp|jxl|heic|heif|woff2?|ttf|otf|eot|ico|webmanifest)(\?.*)?$/i;

		/* ── Install: pre-cache static assets ───────────────────────────────── */
self.addEventListener('install', function (event) {
  event.waitUntil(
    caches.open(CACHE_NAME).then(function (cache) {
      var precacheUrls = PRECACHE.filter(Boolean);

      return Promise.all(
        precacheUrls.map(function (url) {
          var fetchOptions = {};
          var isAbsoluteHttpUrl = /^https?:\/\//i.test(url);
          var isCrossOriginUrl = false;

          if (isAbsoluteHttpUrl) {
            try {
              isCrossOriginUrl = new URL(url, self.location.origin).origin !== self.location.origin;
            } catch (error) {
              isCrossOriginUrl = false;
            }
          }

          if (isCrossOriginUrl) {
            fetchOptions.mode = 'no-cors';
          }

          return fetch(url, fetchOptions).then(function (response) {
            if (!response) {
              return null;
            }

            if (!response.ok && response.type !== 'opaque') {
              return null;
            }
            return cache.put(url, response.clone());
          }).catch(function () {
            return null;
          });
        })
      );
    }).then(function () {
      return self.skipWaiting();
    })
  );
});

/* ── Activate: purge stale caches ────────────────────────────────────── */
self.addEventListener('activate', function (event) {
  event.waitUntil(
    caches.keys().then(function (keys) {
      return Promise.all(
        // Only delete caches owned by this plugin ('spx-vcard-*').
        // CacheStorage is origin-wide; other features/plugins may create their
        // own entries and must not be wiped by this worker's activate handler.
        keys.filter(function (key) {
          return key.startsWith('spx-vcard-') && key !== CACHE_NAME;
        }).map(function (key) { return caches.delete(key); })
      );
    }).then(function () {
      return self.clients.claim();
    })
  );
});

/* ── Fetch ───────────────────────────────────────────────────────────── */
self.addEventListener('fetch', function (event) {
  var req = event.request;

  // Only handle GET requests for http(s) URLs.
  if (req.method !== 'GET' || !req.url.startsWith('http')) return;

  // Never intercept wp-admin, wp-login, or wp-cron — these are sensitive
  // authenticated routes that must never be cached or served stale.
  // Guard both the site base-path variant (for subdirectory single-site) and
  // the root variant (WordPress core admin is always at /wp-admin/ and
  // /wp-login.php even on multisite subdirectory sub-sites).
  var pathname = new URL(req.url).pathname;
  if (pathname === '/wp-login.php' ||
      pathname === SPX_BASE + '/wp-login.php' ||
      pathname.startsWith('/wp-admin') ||
      pathname.startsWith(SPX_BASE + '/wp-admin') ||
      pathname === '/wp-cron.php' ||
      pathname === SPX_BASE + '/wp-cron.php') return;

  if (STATIC_EXTS.test(req.url)) {
    // Cache-first: CSS, JS, images, fonts.
    event.respondWith(cacheFirst(event, req));
  } else if (req.mode === 'navigate') {
    // Network-first: HTML navigation requests only.
    event.respondWith(networkFirst(event, req));
  }
  // Non-navigate, non-static requests (XHR/fetch API): pass through without caching.
});

/* ── Strategies ──────────────────────────────────────────────────────── */
function cacheFirst(event, req) {
  return caches.match(req).then(function (cached) {
    if (cached) return cached;
    return fetch(req).then(function (response) {
      if (response && response.status === 200) {
        var clone = response.clone();
        // Tie the cache write to the event lifetime so the SW is not terminated
        // before the put completes.
        event.waitUntil(
          caches.open(CACHE_NAME).then(function (cache) { cache.put(req, clone); })
        );
      }
      return response;
    });
    // Static assets: no offline HTML fallback — let the network error propagate.
  });
}

function networkFirst(event, req) {
  return fetch(req).then(function (response) {
    if (response && response.status === 200) {
      var clone = response.clone();
      // Tie the cache write to the event lifetime so the SW is not terminated
      // before the put completes.
      event.waitUntil(
        caches.open(CACHE_NAME).then(function (cache) { cache.put(req, clone); })
      );
    }
    return response;
  }).catch(function () {
    return caches.match(req).then(function (cached) {
      // networkFirst is only called for navigate requests, so the offline
      // HTML fallback is always an appropriate response type here.
      return cached || offlinePage();
    });
  });
}

function offlinePage() {
  return new Response(OFFLINE_HTML, {
    status: 200,
    headers: { 'Content-Type': 'text/html; charset=utf-8' }
  });
}
JS;
	}

	/**
	 * Return the minimal offline fallback HTML string.
	 *
	 * Matches the plugin's neon-dark card aesthetic so the offline state
	 * feels intentional rather than broken.
	 *
	 * @return string Plain HTML (no closing </html> tag required by spec).
	 */
	private static function offline_html(): string {
		return '<!DOCTYPE html>'
			. '<html lang="en">'
			. '<head>'
			. '<meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width,initial-scale=1">'
			. '<title>Offline</title>'
			. '<style>'
			. '*{margin:0;padding:0;box-sizing:border-box}'
			. 'body{background:#1c1c1e;color:#f5f5f7;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;'
			. 'min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;'
			. 'gap:16px;padding:24px;text-align:center}'
			. '.icon{font-size:48px}'
			. '.title{font-size:22px;font-weight:600}'
			. '.msg{font-size:15px;color:#aeaeb2;max-width:280px;line-height:1.5}'
			. '</style>'
			. '</head>'
			. '<body>'
			. '<div class="icon">&#x1F4F5;</div>'
			. '<div class="title">You&#8217;re Offline</div>'
			. '<div class="msg">Your business card will be available again once you&#8217;re back online.</div>'
			. '</body></html>';
	}

	/**
	 * Inject the PWA manifest link and Apple web-app meta tags into wp_head.
	 *
	 * Only fires when all conditions are true:
	 *   1. The current visitor is logged in.
	 *   2. The logged-in user's card has been enqueued for this page
	 *      (i.e. the card owner is viewing their own card page).
	 *
	 * The manifest href includes a ?v= cache-buster derived from the user's
	 * last profile-update timestamp so stale manifests are never served after
	 * a profile change.
	 *
	 * @return void
	 */
	public static function inject_pwa_head(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$uid = get_current_user_id();

		if ( ! AssetLoader::has_card_enqueued( $uid ) ) {
			return;
		}

		$user = get_userdata( $uid );
		if ( ! $user instanceof \WP_User ) {
			return;
		}

		// Version: prefer the profile-update timestamp; fall back to registration.
		$profile_version = (int) get_user_meta( $uid, 'spx_pwa_profile_version', true );
		if ( $profile_version <= 0 ) {
			$profile_version = (int) strtotime( $user->user_registered );
		}

		$manifest_url = add_query_arg( 'v', $profile_version, home_url( '/spx-pwa-manifest.json' ) );

		?>
		<link rel="manifest" href="<?php echo esc_url( $manifest_url ); ?>">
		<meta name="mobile-web-app-capable" content="yes">
		<meta name="apple-mobile-web-app-capable" content="yes">
		<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
		<meta name="theme-color" content="#1c1c1e">
		<script>
		if ('serviceWorker' in navigator) {
			navigator.serviceWorker.register(<?php echo wp_json_encode( home_url( '/spx-pwa-sw.js' ) ); ?>, { scope: <?php echo wp_json_encode( self::home_path() ); ?> })
				.catch(function(){});
		}
		</script>
		<?php
	}

	/**
	 * Extend the auth-cookie lifetime to one year for PWA sessions.
	 *
	 * Fires on the auth_cookie_expiration filter during wp_set_auth_cookie().
	 * Extends the expiration to YEAR_IN_SECONDS when ALL conditions are true:
	 *   • $remember is true (the user explicitly opted in to staying logged in), AND
	 *   • ?spx_app=1 is present in the current GET request, OR
	 *     the login form's redirect_to parameter contains spx_app=1
	 *     (the owner just logged in from the PWA's redirect loop).
	 *
	 * Requiring $remember preserves WordPress's "remember me" semantics —
	 * the extended lifetime is only granted when the user has explicitly
	 * opted in, preventing silent year-long sessions on shared devices.
	 *
	 * @param  int  $expiration Default expiration in seconds.
	 * @param  int  $user_id    The user ID being authenticated (unused here).
	 * @param  bool $remember   Whether "remember me" was checked.
	 * @return int              Extended or default expiration.
	 */
	public static function extend_cookie_for_pwa( int $expiration, int $user_id, bool $remember ): int {
		if ( ! $remember ) {
			return $expiration;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
		$in_get = isset( $_GET['spx_app'] ) && '1' === sanitize_key( $_GET['spx_app'] );

		$in_redirect = false;
		if ( isset( $_POST['redirect_to'] ) ) {
			$redirect_to = sanitize_text_field( wp_unslash( (string) $_POST['redirect_to'] ) );
			$parsed      = wp_parse_url( $redirect_to );
			$qs          = isset( $parsed['query'] ) ? $parsed['query'] : '';
			$params      = array();
			wp_parse_str( $qs, $params );
			$in_redirect = isset( $params['spx_app'] ) && '1' === $params['spx_app'];
		}
		// phpcs:enable

		if ( $in_get || $in_redirect ) {
			return YEAR_IN_SECONDS;
		}

		return $expiration;
	}

	/**
	 * Bump the profile version meta when WordPress saves a user profile.
	 *
	 * Triggers on the profile_update action so the manifest's ?v= cache-buster
	 * changes whenever the owner updates their profile, forcing browsers to
	 * re-fetch the manifest and pick up any new app name or icon.
	 *
	 * @param  int $user_id The user whose profile was saved.
	 * @return void
	 */
	public static function bump_profile_version( int $user_id ): void {
		update_user_meta( $user_id, 'spx_pwa_profile_version', time() );
	}

	/**
	 * Bump the profile version meta when an ACF user field group is saved.
	 *
	 * Fires on acf/save_post.  Only processes user post IDs (formatted as
	 * "user_{id}" by ACF) to avoid unnecessary updates for other post types.
	 *
	 * @param  int|string $post_id The ACF post identifier.
	 * @return void
	 */
	public static function bump_profile_version_acf( int|string $post_id ): void {
		$post_id_str = (string) $post_id;
		if ( str_starts_with( $post_id_str, 'user_' ) ) {
			$uid = (int) substr( $post_id_str, 5 );
			if ( $uid > 0 ) {
				update_user_meta( $uid, 'spx_pwa_profile_version', time() );
			}
		}
	}
}
