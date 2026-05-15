<?php
/**
 * Asset loader — front-end orchestrator.
 *
 * @package Starisian\Sparxstar\Photon
 * @since   1.0.0
 */

declare(strict_types=1);

namespace Starisian\Sparxstar\Photon;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Asset loader / front-end orchestrator.
 *
 * Enqueues the plugin stylesheet, main script, and QR library, then passes
 * per-user card data to JavaScript via wp_add_inline_script.
 *
 * Data sources per field:
 *  – Name, email, website — WordPress core user object (display_name, user_email, user_url)
 *  – Company, title, address, phones, channels, social — ACF spx_* fields
 *  – Photo — ACF spx_img_brand_blob (when set); falls back to WP avatar / Gravatar
 *            when no ACF image is available; suppressed when spx_state_img_pub is false
 *
 * Multiple users may be localized in one request (e.g. pages with several
 * [spx_photon_vcard user_id="..."] shortcodes).  Each user's payload is
 * appended to the global window.SPX_PHOTON_VCARD_USERS map keyed by user ID.
 * The first user registered in the request becomes window.SPX_PHOTON_VCARD_DEFAULT.
 *
 * Instantiated as a singleton by {@see Bootloader::init()}.  External code
 * (e.g. the [spx_photon_vcard] shortcode) calls the public static helper
 * {@see AssetLoader::enqueue_for_user()} directly.
 *
 * @package Starisian\Sparxstar\Photon
 * @since   1.0.0
 * @version 0.5.0
 */
final class AssetLoader
{

	/**
	 * Singleton instance.
	 *
	 * @var AssetLoader|null
	 */
	private static ?AssetLoader $instance = null;

	/**
	 * Tracks whether plugin CSS/JS assets have already been enqueued.
	 *
	 * Assets are enqueued once; card data for each additional user is appended
	 * via wp_add_inline_script without re-enqueueing the files.
	 *
	 * @var bool
	 */
	private static bool $assets_enqueued = false;

	/**
	 * User IDs for which card data has already been localized.
	 *
	 * Prevents duplicate inline scripts when the same user ID appears in
	 * multiple [spx_photon_vcard] shortcodes on the same page.
	 *
	 * @var int[]
	 */
	private static array $localized_users = [];

	/**
	 * Check whether card assets have been enqueued for the given user ID.
	 *
	 * Used by PwaController to decide whether to inject the PWA manifest
	 * link into the page head.
	 *
	 * @param int $user_id The user ID to check.
	 * @return bool         True when the user's card is already enqueued.
	 */
	public static function has_card_enqueued( int $user_id ): bool
	{
		return in_array( $user_id, self::$localized_users, true );
	}

	/**
	 * Private constructor — registers the wp_enqueue_scripts hook.
	 */
	private function __construct()
	{
		add_action( 'wp_enqueue_scripts', [$this, 'enqueue_assets'] );
	}

	/**
	 * Return (or create) the singleton instance.
	 *
	 * @return AssetLoader
	 */
	public static function get_instance(): AssetLoader
	{
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Pre-enqueue card assets during wp_enqueue_scripts.
	 *
	 * Scans every post in the current query ($wp_query->posts) so assets load
	 * in <head> regardless of whether the shortcode appears on a singular page,
	 * an archive, a block template, or any other view.  This solves the timing
	 * problem where wp_enqueue_style() called from inside the_content (after
	 * wp_head) cannot inject a <link> into the document head.
	 *
	 * Behaviour per post:
	 *   • Posts containing [spx_photon_vcard] — all referenced user IDs are
	 *     extracted and enqueued.  The shortcode callback's enqueue_for_user()
	 *     is idempotent and becomes a no-op for already-registered users.
	 *   • Singular posts without the shortcode — the post author's card is
	 *     enqueued when the post type is in the allowed list (filter below).
	 *
	 * Allowed post types for auto-author enqueue are controlled via the filter:
	 *   apply_filters( 'sparxstar_photon_vcard_allowed_post_types', string[] )
	 * Default: all public post types.  Pass an empty array to disable entirely.
	 *
	 * @return void
	 */
	public function enqueue_assets(): void
	{
		global $wp_query;

		if ( empty( $wp_query->posts ) || ! is_array( $wp_query->posts ) ) {
			return;
		}

		/**
		 * Filter the post types for which the plugin auto-enqueues the post
		 * author's business card when no shortcode is present in the content.
		 *
		 * @param string[] $post_types Allowed post type slugs. Default: all
		 *                             registered public post types.
		 */
		static $default_post_types = null;
		if ( null === $default_post_types ) {
			$default_post_types = array_keys( get_post_types( ['public' => true] ) );
		}

		$allowed_post_types = (array) apply_filters(
			'sparxstar_photon_vcard_allowed_post_types',
			$default_post_types
		);

		foreach ( $wp_query->posts as $post_obj ) {
			if ( ! $post_obj instanceof \WP_Post ) {
				continue;
			}

			// Posts containing the shortcode: pre-enqueue all referenced users.
			if ( has_shortcode( $post_obj->post_content, 'spx_photon_vcard' ) ) {
				foreach ( self::extract_shortcode_user_ids( $post_obj ) as $uid ) {
					self::enqueue_for_user( $uid, $post_obj->ID );
				}
				continue;
			}

			// On singular views only: auto-enqueue the post author when the
			// post type is in the allowed list and there is no explicit shortcode.
			if (
				is_singular()
				&& in_array( $post_obj->post_type, $allowed_post_types, true )
			) {
				$author_id = (int) $post_obj->post_author;
				if ( $author_id > 0 ) {
					self::enqueue_for_user( $author_id, $post_obj->ID );
				}
			}
		}
	}

	/**
	 * Extract resolved user IDs from [spx_photon_vcard] shortcodes in post content.
	 *
	 * Uses the same resolution chain as Shortcode::render():
	 *   explicit user_id attribute → post author → logged-in user.
	 * De-duplicates the result while preserving declaration order.
	 *
	 * @param  \WP_Post $post_obj The post whose content is parsed.
	 * @return int[]              Ordered, unique resolved user IDs (>0).
	 */
	private static function extract_shortcode_user_ids( \WP_Post $post_obj ): array
	{
		$uids  = [];
		$regex = get_shortcode_regex( ['spx_photon_vcard'] );

		if ( ! preg_match_all( '/' . $regex . '/s', $post_obj->post_content, $matches, PREG_SET_ORDER ) ) {
			// has_shortcode() said yes but regex found nothing — defensive fallback.
			$uid = (int) $post_obj->post_author > 0
				? (int) $post_obj->post_author
				: get_current_user_id();
			if ( $uid > 0 ) {
				$uids[] = $uid;
			}
			return $uids;
		}

		foreach ( $matches as $m ) {
			$raw_atts = shortcode_parse_atts( $m[3] ?? '' );
			$atts     = is_array( $raw_atts ) ? $raw_atts : [];

			if ( ! empty( $atts['user_id'] ) && (int) $atts['user_id'] > 0 ) {
				$uid = (int) $atts['user_id'];
			} elseif ( (int) $post_obj->post_author > 0 ) {
				$uid = (int) $post_obj->post_author;
			} else {
				$uid = get_current_user_id();
			}

			if ( $uid > 0 && ! in_array( $uid, $uids, true ) ) {
				$uids[] = $uid;
			}
		}

		return $uids;
	}

	/**
	 * Enqueue all card assets for the given user.
	 *
	 * Idempotent per user ID: a second call for the same user is a no-op.
	 * Multiple distinct user IDs may be registered in one request — each
	 * user's card data is appended to the global users map via an inline
	 * script.  Assets (CSS/JS) are enqueued only on the first call.
	 *
	 * @param int $user_id  Author / card owner user ID.
	 * @param int $post_id  Associated post ID (used for filter hooks). 0 for shortcode context.
	 * @return bool          True when assets and data were successfully enqueued, false when skipped.
	 */
	public static function enqueue_for_user( int $user_id, int $post_id = 0 ): bool
	{
		// Already localized for this user — nothing to do.
		if ( in_array( $user_id, self::$localized_users, true ) ) {
			return true;
		}

		if ( $user_id <= 0 ) {
			return false;
		}

		$user = get_userdata( $user_id );
		if ( ! $user instanceof \WP_User ) {
			return false;
		}

		// Permission check — only allowed roles receive a card.
		/**
		 * Filter the user roles permitted to receive a business card.
		 *
		 * Return an array of role slugs. Users whose role set does not intersect
		 * with this list will not have a card enqueued, and no button will render
		 * for them in shortcode output.
		 *
		 * @param string[] $allowed_roles Default: ['administrator', 'vip_business_user', 'editor'].
		 * @param int      $user_id       The user ID being evaluated.
		 */
		$allowed_roles = apply_filters(
			'sparxstar_photon_vcard_allowed_roles',
			['administrator', 'vip_business_user', 'editor'],
			$user_id
		);
		$allowed_roles = array_values(
			array_filter(
				array_map(
					static fn( $role ): string => (string) $role,
					(array) $allowed_roles
				),
				static fn( string $role ): bool => '' !== $role
			)
		);

		if ( empty( array_intersect( $allowed_roles, (array) $user->roles ) ) ) {
			return false;
		}

		// Honour the spx_state_card_active ACF toggle.
		if ( function_exists( 'get_field' ) ) {
			$display = get_field( 'spx_state_card_active', 'user_' . $user_id );
			// Explicit false means "hide the card"; null / unset means default on.
			if ( false === $display ) {
				return false;
			}
		}

		$debug   = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG;
		$css_url = $debug
			? SPARXSTAR_PHOTON_VCARD_PLUGIN_URL . 'src/css/sparxstar-photon-vcard.css'
			: SPARXSTAR_PHOTON_VCARD_PLUGIN_URL . 'assets/css/sparxstar-photon-vcard.min.css';
		$js_url  = $debug
			? SPARXSTAR_PHOTON_VCARD_PLUGIN_URL . 'src/js/sparxstar-photon-vcard.js'
			: SPARXSTAR_PHOTON_VCARD_PLUGIN_URL . 'assets/js/sparxstar-photon-vcard.min.js';

		// Enqueue CSS/JS only once (first user registered in the request).
		if ( ! self::$assets_enqueued ) {
			// QR library (local asset — no CDN dependency).
			$script_deps = [];
			$qr_path     = SPARXSTAR_PHOTON_VCARD_PLUGIN_PATH . 'assets/js/qrcode.min.js';
			if ( file_exists( $qr_path ) ) {
				wp_register_script(
					'spx-photon-qrcode',
					SPARXSTAR_PHOTON_VCARD_PLUGIN_URL . 'assets/js/qrcode.min.js',
					[],
					SPARXSTAR_PHOTON_VCARD_VERSION,
					true
				);
				wp_enqueue_script( 'spx-photon-qrcode' );
				$script_deps[] = 'spx-photon-qrcode';
			} elseif ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				do_action(
					'sparxstar_photon_vcard_missing_qr_asset',
					'SPARXSTAR Photon VCard: qrcode.min.js not found in assets/js/. The QR code feature will be unavailable.'
				);
			}

			wp_enqueue_style(
				'spx-photon-vcard',
				$css_url,
				[],
				SPARXSTAR_PHOTON_VCARD_VERSION
			);

			wp_enqueue_script(
				'spx-photon-vcard',
				$js_url,
				$script_deps,
				SPARXSTAR_PHOTON_VCARD_VERSION,
				true
			);

			// Initialize the users map exactly once.
			wp_add_inline_script(
				'spx-photon-vcard',
				'window.SPX_PHOTON_VCARD_USERS=window.SPX_PHOTON_VCARD_USERS||{};',
				'before'
			);

			self::$assets_enqueued = true;
		}

		// Enterprise sensor override filter.
		/**
		 * Filter to disable all motion and orientation triggers for the card overlay.
		 *
		 * Set to true in environments where sensor access is prohibited by policy
		 * (e.g. government, education, or privacy-restricted deployments). Keyboard
		 * and touch fallback triggers remain functional when sensors are disabled.
		 *
		 * @param bool $disable  Whether to disable sensors. Default false.
		 * @param int  $user_id  The card owner's user ID.
		 * @param int  $post_id  The associated post ID (0 in shortcode-only context).
		 */
		$disable_sensors = apply_filters( 'sparxstar_photon_vcard_disable_sensors', false, $user_id, $post_id );
		// Backwards-compat alias.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- intentional enterprise override hook; prefix would break existing integrations.
		$disable_sensors = apply_filters( 'vip_motion_disable_sensors', $disable_sensors, $user_id, $post_id );

		$card_data = self::build_card_data( $user, $disable_sensors );

		// Append this user's data to the map.
		// The first user registered also becomes the default (for motion/keyboard triggers).
		$is_first  = empty( self::$localized_users );
		$json_data = wp_json_encode( $card_data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP );

		$inline = 'window.SPX_PHOTON_VCARD_USERS[' . $user_id . ']=' . $json_data . ';';
		if ( $is_first ) {
			$inline .= 'window.SPX_PHOTON_VCARD_DEFAULT=' . $user_id . ';';
		}

		wp_add_inline_script( 'spx-photon-vcard', $inline, 'before' );

		self::$localized_users[] = $user_id;

		return true;
	}

	/**
	 * Assemble the card data array for use in the JavaScript users map.
	 *
	 * Data resolution strategy:
	 *   - Name:     WP display_name.
	 *   - Title:    ACF spx_role_title.
	 *   - Company:  ACF spx_org_name.
	 *   - Phones:   ACF spx_rel_com_matrix repeater (phone-type channels).
	 *   - Channels: ACF spx_rel_com_matrix repeater (messaging-type channels).
	 *   - Social:   ACF spx_rel_social_matrix repeater.
	 *   - Email:    WP user_email (canonical).
	 *   - Website:  WP user_url.
	 *   - Address:  ACF spx_loc_* fields.
	 *   - Photo:    ACF spx_img_brand_blob → WP avatar / Gravatar fallback (unless spx_state_img_pub is explicitly false).
	 *
	 * @param \WP_User $user            The card owner.
	 * @param bool     $disable_sensors Whether motion triggers are disabled.
	 * @return array<string,mixed>      Sanitized card data for inline script injection via wp_add_inline_script.
	 */
	private static function build_card_data( \WP_User $user, bool $disable_sensors ): array
	{
		$uid = (int) $user->ID;
		$acf = function_exists( 'get_field' );

		// ── Name ────────────────────────────────────────────────────────────────────────
		$name = $user->display_name;

		// ── Job title ────────────────────────────────────────────────────────────────
		$title = '';
		if ( $acf ) {
			$title = (string) ( get_field( 'spx_role_title', 'user_' . $uid ) ?: '' );
		}

		// ── Company ───────────────────────────────────────────────────────────────────
		$company = '';
		if ( $acf ) {
			$company = (string) ( get_field( 'spx_org_name', 'user_' . $uid ) ?: '' );
		}

		// ── Communication channels (phones + messaging) ──────────────────────────────
		// Phone-type channel keys map directly to vCard TEL TYPE values.
		$phone_type_map = [
			'mobile' => 'CELL',
			'work'   => 'WORK',
			'home'   => 'HOME',
			'direct' => 'WORK',
			'fax'    => 'FAX',
		];
		// Messaging-type keys are carried as custom channels (not vCard TEL).
		$messaging_types = ['whatsapp', 'telegram', 'signal', 'wechat', 'viber', 'line', 'zalo', 'kakao', 'teams', 'zoom'];

		$phones   = [];
		$channels = [];

		if ( $acf ) {
			$com_matrix = get_field( 'spx_rel_com_matrix', 'user_' . $uid );
			if ( is_array( $com_matrix ) ) {
				foreach ( $com_matrix as $row ) {
					if ( ! is_array( $row ) ) {
						continue;
					}
					$key = (string) ( $row['spx_node_key'] ?? '' );
					$val = (string) ( $row['spx_node_val'] ?? '' );
					if ( '' === $val ) {
						continue;
					}
					if ( isset( $phone_type_map[ $key ] ) ) {
						$phones[] = [
							'type'   => $phone_type_map[ $key ],
							'number' => $val,
						];
					} elseif ( in_array( $key, $messaging_types, true ) ) {
						$channels[] = [
							'key' => $key,
							'val' => $val,
						];
					}
				}
			}
		}

		// ── Social media ─────────────────────────────────────────────────────────────────
		$social = [];
		if ( $acf ) {
			$social_matrix = get_field( 'spx_rel_social_matrix', 'user_' . $uid );
			if ( is_array( $social_matrix ) ) {
				foreach ( $social_matrix as $row ) {
					if ( ! is_array( $row ) ) {
						continue;
					}
					$key = (string) ( $row['spx_node_key'] ?? '' );
					$val = (string) ( $row['spx_node_val'] ?? '' );
					if ( '' !== $key && '' !== $val ) {
						$social[] = [
							'key' => $key,
							'val' => $val,
						];
					}
				}
			}
		}

		// ── Email ────────────────────────────────────────────────────────────────────────
		$email = $user->user_email;

		// ── Website (WP core user_url) ───────────────────────────────────────────────────
		$website = $user->user_url ?: '';

		// ── Postal address ───────────────────────────────────────────────────────────────
		$address = [];
		if ( $acf ) {
			$address = [
				'street1'  => (string) ( get_field( 'spx_loc_addr_01', 'user_' . $uid ) ?: '' ),
				'street2'  => (string) ( get_field( 'spx_loc_addr_02', 'user_' . $uid ) ?: '' ),
				'city'     => (string) ( get_field( 'spx_loc_city', 'user_' . $uid ) ?: '' ),
				'state'    => (string) ( get_field( 'spx_loc_region', 'user_' . $uid ) ?: '' ),
				'postcode' => (string) ( get_field( 'spx_loc_postcode', 'user_' . $uid ) ?: '' ),
				'country'  => (string) ( get_field( 'spx_loc_country_code', 'user_' . $uid ) ?: '' ),
			];
		}

		// ── Profile photo ────────────────────────────────────────────────────────────────
		// ACF spx_img_brand_blob takes priority. Gravatar is the fallback when no
		// ACF image is set. Both are suppressed when spx_state_img_pub is false.
		$photo = '';
		if ( $acf ) {
			$img_pub = get_field( 'spx_state_img_pub', 'user_' . $uid );
		} else {
			$img_pub = null;
		}

		// ACF true_false fields commonly return false, 0, '0', 1, or '1'.
		// Treat explicit falsey opt-out values as non-public while preserving
		// the existing default-on behavior for null/unset values.
		$is_photo_public = null === $img_pub || ( false !== $img_pub && 0 !== $img_pub && '0' !== $img_pub );

		if ( $is_photo_public ) {
			if ( $acf ) {
				$img   = AcfHelper::resolve_image( get_field( 'spx_img_brand_blob', 'user_' . $uid ) );
				$photo = $img['url'];
			}
			// Fall back to Gravatar only when no ACF image is available.
			if ( '' === $photo ) {
				// Request a 404 when the user has no Gravatar so the JS img.onerror
				// handler can reliably hide the broken image.
				$photo = (string) get_avatar_url(
					$uid,
					[
						'size'    => 200,
						'default' => '404',
					]
				);
			}
		}

		return [
			'name'     => sanitize_text_field( $name ),
			'company'  => sanitize_text_field( $company ),
			'title'    => sanitize_text_field( $title ),
			'phones'   => array_map(
				static fn( array $p ): array => [
					'type'   => strtoupper( sanitize_key( $p['type'] ) ),
					'number' => sanitize_text_field( $p['number'] ),
				],
				$phones
			),
			'channels' => array_map(
				static fn( array $c ): array => [
					'key' => sanitize_key( $c['key'] ),
					'val' => sanitize_text_field( $c['val'] ),
				],
				$channels
			),
			'social'   => array_map(
				static fn( array $s ): array => [
					'key' => sanitize_text_field( $s['key'] ),
					'val' => esc_url_raw( $s['val'] ),
				],
				$social
			),
			'email'    => sanitize_email( $email ),
			'website'  => esc_url_raw( $website ),
			'photo'    => esc_url_raw( $photo ),
			'address'  => array_map( 'sanitize_text_field', $address ),
			'noSensor' => (bool) $disable_sensors,
		];
	}
}
