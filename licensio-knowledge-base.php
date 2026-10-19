<?php
/**
 * Plugin Name: Licensio Knowledge Base
 * Plugin URI: https://licenseserver.io/plugins/licensio-knowledge-base
 * Description: A fast, standalone WordPress knowledge base with block-first authoring, relevant search and privacy-safe analytics.
 * Version: 1.0.0
 * Author: Licensio
 * Author URI: https://licenseserver.io
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: licensio-knowledge-base
 * Domain Path: /languages
 * Requires at least: 6.4
 * Requires PHP: 7.4
 *
 * Bootstrap for the Licensio Knowledge Base plugin. Defines the plugin
 * constants, registers the lifecycle hooks and hands control to the
 * Licensio_KB singleton on plugins_loaded.
 *
 * The plugin is fully standalone: it requires neither SLSWC nor WooCommerce.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

define( 'LICENSIO_KB_VERSION', '1.0.0' );
define( 'LICENSIO_KB_DB_VERSION', '1.0.1' );
define( 'LICENSIO_KB_MIN_PHP', '7.4' );
define( 'LICENSIO_KB_MIN_WP', '6.4' );
define( 'LICENSIO_KB_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'LICENSIO_KB_ABSPATH', plugin_dir_path( __FILE__ ) );
define( 'LICENSIO_KB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'LICENSIO_KB_ASSETS_URL', LICENSIO_KB_PLUGIN_URL . 'assets/' );
define( 'LICENSIO_KB_DIST_URL', LICENSIO_KB_PLUGIN_URL . 'dist/' );
define( 'LICENSIO_KB_DIST_PATH', LICENSIO_KB_ABSPATH . 'dist/' );
define( 'LICENSIO_KB_REST_NAMESPACE', 'licensio-kb/v1' );

require_once LICENSIO_KB_ABSPATH . 'includes/class-licensio-kb-autoloader.php';
Licensio_KB_Autoloader::register();

require_once LICENSIO_KB_ABSPATH . 'includes/licensio-kb-template-functions.php';
require_once LICENSIO_KB_ABSPATH . 'includes/licensio-kb-hooks.php';
require_once LICENSIO_KB_ABSPATH . 'includes/licensio-kb-search-functions.php';

register_activation_hook( __FILE__, array( 'Licensio_KB_Lifecycle', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Licensio_KB_Lifecycle', 'deactivate' ) );

/*
 * Install-time work that belongs to a component rather than to the lifecycle.
 *
 * These are registered here rather than in Licensio_KB::boot() because boot()
 * never runs during an activation request: WordPress includes this file after
 * `plugins_loaded` has already fired, then fires the activation hook. They are
 * hooked to `licensio_activated` rather than to the activation hook itself so
 * that a network activation runs them inside each site, where roles and rewrite
 * rules actually live.
 */
add_action( 'licensio_activated', array( 'Licensio_KB_Capabilities', 'add_capabilities' ) );
add_action( 'licensio_activated', array( 'Licensio_KB_Permalinks', 'request_flush' ) );
add_action( 'licensio_activated', array( 'Licensio_KB_Analytics_Retention', 'schedule' ) );

/*
 * Teardown that belongs to a component rather than to the uninstaller.
 *
 * Registered here for the same reason: `uninstall.php` includes this file and
 * calls Licensio_KB_Uninstaller::run() directly, without ever booting the
 * plugin, so a listener attached inside Licensio_KB::boot() would never be
 * present when `licensio_uninstalled` fires.
 */
add_action( 'licensio_uninstalled', array( 'Licensio_KB_Analytics_Retention', 'unschedule' ) );

/**
 * Return the main plugin instance.
 *
 * Convenience accessor so consumers never have to reach for the class name
 * directly.
 *
 * @since 1.0.0
 *
 * @return Licensio_KB The main plugin instance.
 */
function licensio_kb() {
    return Licensio_KB::instance();
}

/**
 * Boot the plugin.
 *
 * Hooked to `plugins_loaded` so every component is constructed before any
 * other plugin or theme runs its own `plugins_loaded` callbacks.
 *
 * @since 1.0.0
 *
 * @return void
 */
function licensio_kb_boot() {
    Licensio_KB::instance();
}

add_action( 'plugins_loaded', 'licensio_kb_boot', 5 );
