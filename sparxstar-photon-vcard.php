<?php
/**
 * SPARXSTAR Photon VCard
 *
 * @version           0.5.0
 * @package           sparxstar-photon-vcard
 * @author            Starisian Technologies (Max Barrett) <support@starisian.com>
 * @copyright         2025 Starisian Technologies. All rights reserved.
 * @license           Starisian Technologies Proprietary
 *
 * @wordpress-plugin
 * Plugin Name:       SPARXSTAR Photon VCard
 * Plugin URI:        https://starisian.com/sparxstar/sparxstar-photon-vcard
 * Description:       Production-grade digital business card with motion/touch triggers. Finalized for accessibility, security, and legacy hardware resilience.
 * Version:           0.5.0
 * Requires at least: 6.8
 * Requires PHP:      8.2
 * Author:            Starisian Technologies (Max Barrett) <support@starisian.com>
 * Author URI:        https://starisian.com
 * Text Domain:       sparxstar-photon-vcard
 * License:           Starisian Technologies Proprietary
 * License URI:       https://github.com/Starisian-Technologies/sparxstar-photon-vcard/blob/main/LICENSE.md
 * Update URI:        https://starisian.com/sparxstar/sparxstar-photon-vcard/update
 */

declare(strict_types=1);

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Current plugin version. */
define( 'SPARXSTAR_PHOTON_VCARD_VERSION', '0.5.0' );

/** Minimum PHP version required. */
define( 'SPARXSTAR_PHOTON_VCARD_MIN_PHP_VERSION', '8.2' );

/** Minimum WordPress version required. */
define( 'SPARXSTAR_PHOTON_VCARD_MIN_WP_VERSION', '6.8' );

/** Plugin base path (with trailing slash). */
define( 'SPARXSTAR_PHOTON_VCARD_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );

/** Plugin base URL (with trailing slash). */
define( 'SPARXSTAR_PHOTON_VCARD_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once SPARXSTAR_PHOTON_VCARD_PLUGIN_PATH . 'src/includes/class-spx-bootloader.php';

\Starisian\Sparxstar\Photon\Bootloader::boot( __FILE__ );
