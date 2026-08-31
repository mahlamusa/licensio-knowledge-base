<?php
/**
 * Plugin Name: Lisensio Knowledge Base
 * Plugin URI: https://licenseserver.io/plugins/lisensio-knowledge-base
 * Description: A fast, standalone WordPress knowledge base with block-first authoring, relevant search and privacy-safe analytics.
 * Version: 1.0.0
 * Author: Lisensio
 * Author URI: https://licenseserver.io
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: lisensio-knowledge-base
 * Domain Path: /languages
 * Requires at least: 6.4
 * Requires PHP: 7.4
 *
 * Bootstrap for the Lisensio Knowledge Base plugin. Defines the plugin
 * constants, registers the lifecycle hooks and hands control to the
 * Lisensio_KB singleton on plugins_loaded.
 *
 * The plugin is fully standalone: it requires neither SLSWC nor WooCommerce.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

define( 'LISENSIO_KB_VERSION', '1.0.0' );
define( 'LISENSIO_KB_DB_VERSION', '1.0.0' );
define( 'LISENSIO_KB_MIN_PHP', '7.4' );
define( 'LISENSIO_KB_MIN_WP', '6.4' );
define( 'LISENSIO_KB_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'LISENSIO_KB_ABSPATH', plugin_dir_path( __FILE__ ) );
define( 'LISENSIO_KB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'LISENSIO_KB_ASSETS_URL', LISENSIO_KB_PLUGIN_URL . 'assets/' );
define( 'LISENSIO_KB_DIST_URL', LISENSIO_KB_PLUGIN_URL . 'dist/' );
define( 'LISENSIO_KB_DIST_PATH', LISENSIO_KB_ABSPATH . 'dist/' );
define( 'LISENSIO_KB_REST_NAMESPACE', 'lisensio-kb/v1' );

require_once LISENSIO_KB_ABSPATH . 'includes/class-lisensio-kb-autoloader.php';
Lisensio_KB_Autoloader::register();

require_once LISENSIO_KB_ABSPATH . 'includes/lisensio-kb-template-functions.php';
require_once LISENSIO_KB_ABSPATH . 'includes/lisensio-kb-hooks.php';
require_once LISENSIO_KB_ABSPATH . 'includes/lisensio-kb-search-functions.php';

register_activation_hook( __FILE__, array( 'Lisensio_KB_Lifecycle', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Lisensio_KB_Lifecycle', 'deactivate' ) );

/*
 * Install-time work that belongs to a component rather than to the lifecycle.
 *
 * These are registered here rather than in Lisensio_KB::boot() because boot()
 * never runs during an activation request: WordPress includes this file after
 * `plugins_loaded` has already fired, then fires the activation hook. They are
 * hooked to `lisensio_activated` rather than to the activation hook itself so
 * that a network activation runs them inside each site, where roles and rewrite
 * rules actually live.
 */
add_action( 'lisensio_activated', array( 'Lisensio_KB_Capabilities', 'add_capabilities' ) );
add_action( 'lisensio_activated', array( 'Lisensio_KB_Permalinks', 'request_flush' ) );
add_action( 'lisensio_activated', array( 'Lisensio_KB_Analytics_Retention', 'schedule' ) );

/*
 * Teardown that belongs to a component rather than to the uninstaller.
 *
 * Registered here for the same reason: `uninstall.php` includes this file and
 * calls Lisensio_KB_Uninstaller::run() directly, without ever booting the
 * plugin, so a listener attached inside Lisensio_KB::boot() would never be
 * present when `lisensio_uninstalled` fires.
 */
add_action( 'lisensio_uninstalled', array( 'Lisensio_KB_Analytics_Retention', 'unschedule' ) );

/**
 * Return the main plugin instance.
 *
 * Convenience accessor so consumers never have to reach for the class name
 * directly.
 *
 * @since 1.0.0
 *
 * @return Lisensio_KB The main plugin instance.
 */
function lisensio_kb() {
    return Lisensio_KB::instance();
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
function lisensio_kb_boot() {
    Lisensio_KB::instance();
}

add_action( 'plugins_loaded', 'lisensio_kb_boot', 5 );
