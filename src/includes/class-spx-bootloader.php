<?php
/**
 * Plugin bootloader.
 *
 * Wires WordPress activation / deactivation hooks and dispatches runtime
 * initialisation to {@see AssetLoader} at plugins_loaded time.  All public
 * surface is static so the main plugin file needs only a single call to
 * {@see Bootloader::boot()}.
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
 * Plugin bootloader.
 *
 * Wires WordPress activation / deactivation hooks and dispatches runtime
 * initialisation to {@see AssetLoader} at plugins_loaded time.  All public
 * surface is static so the main plugin file needs only a single call to
 * {@see Bootloader::boot()}.
 *
 * @package Starisian\Sparxstar\Photon
 * @since   1.0.0
 */
final class Bootloader
{

	/**
	 * Absolute path to the main plugin file (sparxstar-photon-vcard.php).
	 *
	 * Stored so that activation-error deactivation can reference the correct
	 * plugin slug without depending on __FILE__ of this class file.
	 *
	 * @var string
	 */
	private static string $plugin_file = '';

	/**
	 * Register all WordPress lifecycle hooks.
	 *
	 * Must be called exactly once from the main plugin file, passing __FILE__.
	 *
	 * @param  string $plugin_file Absolute path to the main plugin file.
	 * @return void
	 */
	public static function boot( string $plugin_file ): void
	{
		self::$plugin_file = $plugin_file;

		register_activation_hook( $plugin_file, [self::class, 'activate'] );
		register_deactivation_hook( $plugin_file, [self::class, 'deactivate'] );
		add_action( 'plugins_loaded', [self::class, 'init'] );
	}

	/**
	 * Activation hook callback.
	 *
	 * Verifies requirements, then writes default options and sets an activation
	 * transient for the welcome notice.  On multisite network activation the
	 * per-site work is repeated for every site in the network.
	 *
	 * @param bool $network_wide Whether the plugin is being activated for all sites in the network.
	 *
	 * @return void
	 */
	public static function activate( bool $network_wide = false ): void
	{
		if ( ! self::check_requirements() ) {
			deactivate_plugins( plugin_basename( self::$plugin_file ) );
			wp_die(
				sprintf(
					/* translators: 1: Required PHP version, 2: Required WordPress version */
					esc_html__( 'SPARXSTAR Photon VCard could not be activated. Please ensure you are running PHP %1$s or higher and WordPress %2$s or higher.', 'sparxstar-photon-vcard' ),
					esc_html( SPARXSTAR_PHOTON_VCARD_MIN_PHP_VERSION ),
					esc_html( SPARXSTAR_PHOTON_VCARD_MIN_WP_VERSION )
				),
				esc_html__( 'Plugin Activation Error', 'sparxstar-photon-vcard' ),
				['back_link' => true]
			);
		}

		if ( $network_wide && is_multisite() ) {
			$site_ids = get_sites( ['fields' => 'ids'] );

			if ( ! empty( $site_ids ) ) {
				foreach ( $site_ids as $site_id ) {
					// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.switch_to_blog_switch_to_blog -- multisite activation iteration is the documented use case.
					switch_to_blog( (int) $site_id );
					try {
						self::activate_for_site();
					} finally {
						restore_current_blog();
					}
				}
			}

			return;
		}

		self::activate_for_site();
	}

	/**
	 * Write default options and activation transient for the current site.
	 *
	 * Also registers the PWA rewrite rules and flushes the rewrite cache so
	 * /spx-pwa-manifest.json and /spx-pwa-sw.js resolve immediately after
	 * activation without requiring a manual Settings → Permalinks save.
	 *
	 * @return void
	 */
	private static function activate_for_site(): void
	{
		add_option(
			'sparxstar_photon_vcard_options',
			[
				'version'    => SPARXSTAR_PHOTON_VCARD_VERSION,
				'activated'  => time(),
				'configured' => false,
			]
		);

		set_transient( 'sparxstar_photon_vcard_activation_notice', true, 60 );

		// Register PWA rewrite rules so flush_rewrite_rules captures them.
		require_once SPARXSTAR_PHOTON_VCARD_PLUGIN_PATH . 'src/includes/class-spx-acf-helper.php';
		if ( ! class_exists( PwaController::class ) ) {
			require_once SPARXSTAR_PHOTON_VCARD_PLUGIN_PATH . 'src/includes/class-spx-pwa.php';
		}
		PwaController::register_rewrite_rules();
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.flush_rewrite_rules_flush_rewrite_rules -- required during plugin activation to register custom PWA routes immediately.
		flush_rewrite_rules( false );
	}

	/**
	 * Deactivation hook callback.
	 *
	 * On multisite network deactivation the transient is removed for every
	 * site in the network.
	 *
	 * @param bool $network_wide Whether the plugin is being deactivated for all sites in the network.
	 *
	 * @return void
	 */
	public static function deactivate( bool $network_wide = false ): void
	{
		if ( $network_wide && is_multisite() ) {
			$site_ids = get_sites( ['fields' => 'ids'] );

			if ( ! empty( $site_ids ) ) {
				foreach ( $site_ids as $site_id ) {
					// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.switch_to_blog_switch_to_blog -- multisite deactivation iteration is the documented use case.
					switch_to_blog( (int) $site_id );
					try {
						self::deactivate_for_site();
					} finally {
						restore_current_blog();
					}
				}
			}

			return;
		}

		self::deactivate_for_site();
	}

	/**
	 * Clean up transients and cached rewrite rules for the current site.
	 *
	 * Intentionally deletes the `rewrite_rules` option rather than calling
	 * flush_rewrite_rules(): during the deactivation request the plugin's
	 * hooks (including PwaController::register_rewrite_rules) are still
	 * registered in memory, so flush_rewrite_rules() would immediately
	 * regenerate and re-persist the plugin's rules.  Deleting the option
	 * clears the cache unconditionally; WordPress regenerates a clean set
	 * on the next request, when the plugin is no longer active.
	 *
	 * @return void
	 */
	private static function deactivate_for_site(): void
	{
		delete_transient( 'sparxstar_photon_vcard_activation_notice' );
		delete_option( 'rewrite_rules' );
	}

	/**
	 * Plugins_loaded callback.
	 *
	 * Verifies requirements, loads the text domain, shows the activation
	 * welcome notice when the transient is present, and boots the asset
	 * loader singleton.
	 *
	 * @return void
	 */
	public static function init(): void
	{
		if ( ! self::check_requirements() ) {
			return;
		}

		load_plugin_textdomain(
			'sparxstar-photon-vcard',
			false,
			dirname( plugin_basename( self::$plugin_file ) ) . '/languages'
		);

		if ( get_transient( 'sparxstar_photon_vcard_activation_notice' ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'SPARXSTAR Photon VCard has been activated successfully!', 'sparxstar-photon-vcard' ); ?></p>
				</div>
					<?php
				}
			);
		}

		require_once SPARXSTAR_PHOTON_VCARD_PLUGIN_PATH . 'src/includes/class-spx-acf-helper.php';
		require_once SPARXSTAR_PHOTON_VCARD_PLUGIN_PATH . 'src/includes/class-spx-asset-loader.php';
		require_once SPARXSTAR_PHOTON_VCARD_PLUGIN_PATH . 'src/includes/class-spx-acf-fields.php';
		require_once SPARXSTAR_PHOTON_VCARD_PLUGIN_PATH . 'src/includes/class-spx-shortcode.php';
		require_once SPARXSTAR_PHOTON_VCARD_PLUGIN_PATH . 'src/includes/class-spx-pwa.php';

		AssetLoader::get_instance();
		AcfFields::register();
		Shortcode::register();
		PwaController::register();
	}

	/**
	 * Verify PHP and WordPress version requirements.
	 *
	 * Registers admin_notices on failure (idempotent across multiple calls
	 * within a single request via a static flag).
	 *
	 * @return bool True when all requirements are satisfied.
	 */
	private static function check_requirements(): bool
	{
		$errors = [];

		if ( version_compare( PHP_VERSION, SPARXSTAR_PHOTON_VCARD_MIN_PHP_VERSION, '<' ) ) {
			$errors[] = sprintf(
				/* translators: 1: Current PHP version, 2: Required PHP version */
				__( 'SPARXSTAR Photon VCard requires PHP version %2$s or higher. You are running version %1$s.', 'sparxstar-photon-vcard' ),
				PHP_VERSION,
				SPARXSTAR_PHOTON_VCARD_MIN_PHP_VERSION
			);
		}

		global $wp_version;
		if ( version_compare( $wp_version, SPARXSTAR_PHOTON_VCARD_MIN_WP_VERSION, '<' ) ) {
			$errors[] = sprintf(
				/* translators: 1: Current WordPress version, 2: Required WordPress version */
				__( 'SPARXSTAR Photon VCard requires WordPress version %2$s or higher. You are running version %1$s.', 'sparxstar-photon-vcard' ),
				$wp_version,
				SPARXSTAR_PHOTON_VCARD_MIN_WP_VERSION
			);
		}

		if ( ! empty( $errors ) ) {
			static $notices_added = false;

			if ( ! $notices_added ) {
				foreach ( $errors as $error ) {
					add_action(
						'admin_notices',
						static function () use ( $error ): void {
							?>
						<div class="notice notice-error">
							<p><?php echo esc_html( $error ); ?></p>
						</div>
							<?php
						}
					);
				}
				$notices_added = true;
			}

			return false;
		}

		return true;
	}
}
