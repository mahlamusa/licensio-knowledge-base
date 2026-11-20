<?php
/**
 * Plugin Name: Licensio KB Extension Example
 * Description: Test plugin for the Licensio Knowledge Base extension API. Loads one file per feature from its features folder.
 * Version: 1.1.0
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 *
 * Exercises the extension API of the Articles, Categories and Tags apps the
 * way another plugin would: it declares items on
 * `licensio_kb_register_extensions` and enqueues scripts on
 * `licensio_kb_enqueue_app_extensions`. Symlink or copy this folder into
 * `wp-content/plugins/` of the site the browser tests run against, and
 * activate it.
 *
 * Each feature (columns, filters, bulk actions, row actions, the term apps)
 * lives in its own file under `features/`, loaded in name order, so a feature
 * is added by adding a file and this loader never changes. Nothing is
 * declared while the extension API is missing.
 *
 * @package Licensio_KB
 * @since   1.1.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'plugins_loaded', 'licensio_kb_example_load_features' );

/**
 * Load every feature file, once the knowledge base has loaded.
 *
 * Hooked to `plugins_loaded` because this plugin's folder sorts before the
 * knowledge base's, so it loads first and the extension API constant is not
 * defined yet when this file runs.
 *
 * @since 1.1.0
 *
 * @return void
 */
function licensio_kb_example_load_features() {
    if ( ! defined( 'LICENSIO_KB_EXTENSIONS_API' ) ) {
        return;
    }

    $features = glob( __DIR__ . '/features/*.php' );

    foreach ( false === $features ? array() : $features as $feature ) {
        require_once $feature;
    }
}
