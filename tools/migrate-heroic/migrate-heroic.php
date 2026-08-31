<?php
/**
 * Entry point for the disposable Heroic Knowledge Base migrator.
 *
 * This is NOT a plugin feature. It is a one-time tool, excluded from both build
 * artifacts and from the wordpress.org submission, and deleted once the Lisensio
 * site has been migrated. Nothing here is autoloaded and nothing is wired into
 * `Lisensio_KB::boot()`; the operator loads it explicitly, which is what keeps
 * it out of every request that is not this migration:
 *
 *     wp --require=wp-content/plugins/lisensio-knowledge-base/tools/migrate-heroic/migrate-heroic.php \
 *        lisensio-kb migrate-heroic --dry-run
 *
 * The plugin itself must be active, because the migrator writes through the
 * plugin's own components — `Lisensio_KB_DB`, `Lisensio_KB_Feedback`,
 * `Lisensio_KB_Term_Meta`, `Lisensio_KB_Visitor` — rather than reimplementing
 * their row shapes. Heroic does not need to be active, and normally is not.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

/*
 * This file is loaded with `wp --require=`, which WP-CLI processes BEFORE it
 * bootstraps WordPress. ABSPATH is therefore not defined yet, so the usual
 * `defined( 'ABSPATH' ) || exit;` guard terminated the whole WP-CLI process
 * silently and the command could never be reached at all.
 *
 * Guarding on WP_CLI keeps the file unreachable over the web, which is what the
 * ABSPATH guard was there for, while letting the tool actually load.
 */
if ( ! defined( 'WP_CLI' ) ) {
    return;
}


/**
 * Register the migration command once WordPress and the plugin are up.
 *
 * Hooked rather than called at include time: `--require` files are loaded before
 * the plugin, so the classes the migrator writes through do not exist yet.
 *
 * @since 1.0.0
 *
 * @return void
 */
function lisensio_kb_register_heroic_migrator() {
    /*
     * Required here rather than at file scope. Each class carries the standard
     * direct-access guard, and WP-CLI loads a --require file before WordPress,
     * so requiring them at the top meant the first guard exited the process
     * before the command could be registered.
     */
    require_once __DIR__ . '/class-lisensio-kb-heroic-schema.php';
    require_once __DIR__ . '/class-lisensio-kb-heroic-map.php';
    require_once __DIR__ . '/class-lisensio-kb-heroic-state.php';
    require_once __DIR__ . '/class-lisensio-kb-heroic-report.php';
    require_once __DIR__ . '/class-lisensio-kb-heroic-source.php';
    require_once __DIR__ . '/class-lisensio-kb-heroic-redirects.php';
    require_once __DIR__ . '/class-lisensio-kb-heroic-content-migrator.php';
    require_once __DIR__ . '/class-lisensio-kb-heroic-data-migrator.php';
    require_once __DIR__ . '/class-lisensio-kb-heroic-cli.php';


    if ( ! class_exists( 'Lisensio_KB_Post_Types' ) ) {
        if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
            WP_CLI::warning( 'The Lisensio Knowledge Base plugin is not active; the migrator has nothing to write into.' );
        }

        return;
    }

    Lisensio_KB_Heroic_CLI::register();
}

/*
 * WP_CLI::add_hook( 'after_wp_load' ), not add_action( 'plugins_loaded' ).
 * A --require file is loaded before WordPress, so add_action() does not exist
 * yet and plugins_loaded has already fired by the time WP-CLI hands over. This
 * is WP-CLI's own hook for work that needs a loaded WordPress.
 */
WP_CLI::add_hook( 'after_wp_load', 'lisensio_kb_register_heroic_migrator' );
