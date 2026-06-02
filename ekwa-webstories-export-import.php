<?php
/**
 * Plugin Name:       Ekwa Web Stories Export &amp; Import
 * Plugin URI:        https://www.ekwa.com/
 * Description:        Export Google Web Stories (with every image, video, poster and publisher logo asset) from one WordPress site and import them — fully re-linked — into another. Built for site redesigns / migrations.
 * Version:           1.2.6
 * Requires at least: 5.6
 * Requires PHP:      7.2
 * Author:            Ekwa
 * Author URI:        https://www.ekwa.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ekwa-wsei
 *
 * @package Ekwa\WebStoriesExportImport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'EKWA_WSEI_VERSION', '1.2.6' );
define( 'EKWA_WSEI_FILE', __FILE__ );
define( 'EKWA_WSEI_DIR', plugin_dir_path( __FILE__ ) );
define( 'EKWA_WSEI_URL', plugin_dir_url( __FILE__ ) );
define( 'EKWA_WSEI_BASENAME', plugin_basename( __FILE__ ) );

/**
 * The post type slug used by the Google Web Stories plugin.
 */
define( 'EKWA_WSEI_POST_TYPE', 'web-story' );

/**
 * The export bundle format identifier and version. Bump the version if the
 * manifest structure ever changes in a backwards-incompatible way.
 */
define( 'EKWA_WSEI_FORMAT', 'ekwa-webstories-bundle' );
define( 'EKWA_WSEI_FORMAT_VERSION', '1.0' );

require_once EKWA_WSEI_DIR . 'includes/class-wsei-helpers.php';
require_once EKWA_WSEI_DIR . 'includes/class-wsei-exporter.php';
require_once EKWA_WSEI_DIR . 'includes/class-wsei-importer.php';
require_once EKWA_WSEI_DIR . 'includes/class-wsei-plugin.php';

/*
 * -------------------------------------------------------------------------
 * Self-hosted updates from GitHub
 * -------------------------------------------------------------------------
 * Uses the bundled Plugin Update Checker library (v5) by YahnisElsts, set up
 * the same way as the ekwa-video-block plugin. WordPress will then offer plugin
 * updates straight from the GitHub repository below.
 *
 * To publish a new version: bump the "Version:" header at the top of this file,
 * commit it, then create a matching release/tag (e.g. 1.1.1) on the repo.
 */
require_once EKWA_WSEI_DIR . 'includes/plugin-update-checker/plugin-update-checker.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$ekwa_wsei_update_checker = PucFactory::buildUpdateChecker(
	'https://github.com/agskanchana/ekwa-webstories-export-import/',
	EKWA_WSEI_FILE,
	'ekwa-webstories-export-import'
);

/*
 * Optional GitHub authentication. The repo is public, so no token is required,
 * but supplying one avoids GitHub API rate limits (and is needed if the repo is
 * ever made private). Define EKWA_WSEI_GITHUB_TOKEN in wp-config.php, or store
 * the 'ekwa_wsei_github_token' option.
 */
$ekwa_wsei_github_token = defined( 'EKWA_WSEI_GITHUB_TOKEN' )
	? EKWA_WSEI_GITHUB_TOKEN
	: get_option( 'ekwa_wsei_github_token', '' );
if ( ! empty( $ekwa_wsei_github_token ) ) {
	$ekwa_wsei_update_checker->setAuthentication( $ekwa_wsei_github_token );
}

/**
 * Boot the plugin.
 */
function ekwa_wsei() {
	return \Ekwa\WSEI\Plugin::instance();
}

ekwa_wsei();
