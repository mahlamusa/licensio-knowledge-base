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

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-lisensio-kb-heroic-schema.php';
require_once __DIR__ . '/class-lisensio-kb-heroic-map.php';
require_once __DIR__ . '/class-lisensio-kb-heroic-state.php';
require_once __DIR__ . '/class-lisensio-kb-heroic-report.php';
require_once __DIR__ . '/class-lisensio-kb-heroic-source.php';
require_once __DIR__ . '/class-lisensio-kb-heroic-redirects.php';
require_once __DIR__ . '/class-lisensio-kb-heroic-content-migrator.php';
require_once __DIR__ . '/class-lisensio-kb-heroic-data-migrator.php';
require_once __DIR__ . '/class-lisensio-kb-heroic-cli.php';

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
    if ( ! class_exists( 'Lisensio_KB_Post_Types' ) ) {
        if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
            WP_CLI::warning( 'The Lisensio Knowledge Base plugin is not active; the migrator has nothing to write into.' );
        }

        return;
    }

    Lisensio_KB_Heroic_CLI::register();
}

add_action( 'plugins_loaded', 'lisensio_kb_register_heroic_migrator', 20 );
