<?php
/**
 * Plugin teardown.
 *
 * The whole of what `uninstall.php` does, expressed as a class so the multisite
 * loop and the data-retention decision are unit-testable. `uninstall.php` itself
 * is only a guard plus a single call.
 *
 * Two rules govern what is removed:
 *
 * - Visitor-written content — feedback comments, votes, search and view history
 *   — is preserved unless the site owner opted in through the
 *   `delete_data_on_uninstall` setting. Deleting support history that a visitor
 *   wrote, on a plugin delete that may well be an upgrade or a migration, is not
 *   recoverable; keeping it is.
 * - Plugin state — options and the salt-rotation cron event — is always removed,
 *   on every site of the network. An autoloaded option and a scheduled hook that
 *   outlive the plugin that owns them are pure residue.
 *
 * Consumed by: #9, #11, #12.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Removes the plugin's tables, options and scheduled events.
 *
 * @since 1.0.0
 */
class Licensio_KB_Uninstaller {

    /**
     * Every option the plugin owns.
     *
     * @since 1.0.0
     * @var string[]
     */
    const OPTIONS = array(
        'licensio_settings',
        'licensio_visitor_salt',
        'licensio_version',
        'licensio_db_version',
        'licensio_fulltext_supported',
        'licensio_flush_rewrite_rules',
        'licensio_search_reindex_state',
    );

    /**
     * Tear the plugin down across the whole installation.
     *
     * On multisite this walks every site. Teardown that touched only the current
     * blog left orphaned `wp_N_licensio_*` tables and a live salt-rotation cron
     * event on every other site of the network after a "clean" delete.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public static function run() {
        if ( is_multisite() ) {
            $site_ids = get_sites(
                array(
                    'fields' => 'ids',
                    'number' => 0,
                )
            );

            foreach ( $site_ids as $site_id ) {
                switch_to_blog( (int) $site_id );

                self::uninstall_site();

                restore_current_blog();
            }

            return;
        }

        self::uninstall_site();
    }

    /**
     * Tear the plugin down for the current site.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public static function uninstall_site() {
        // Read before the options are deleted; afterwards the setting is gone.
        $delete_data = self::should_delete_data();

        if ( $delete_data ) {
            try {
                Licensio_KB_DB_Installer::drop_tables();
            } catch ( RuntimeException $e ) {
                /*
                 * The vendored query builder is missing, so the tables cannot be
                 * dropped. Swallowing this is deliberate: an uncaught throw here
                 * skipped the option deletions and the cron clear below, leaving
                 * the tables AND the options AND a cron event firing for a plugin
                 * that no longer exists.
                 */
                unset( $e );
            }
        }

        foreach ( array_merge( self::OPTIONS, self::import_options() ) as $option ) {
            delete_option( $option );
        }

        wp_clear_scheduled_hook( Licensio_KB_Visitor::CRON_HOOK );

        /**
         * Fires while the plugin is being removed from a site.
         *
         * Sub-issues that store their own options or schedule their own cron
         * events clean them up here. Fires once per site on multisite, inside
         * `switch_to_blog()`.
         *
         * @since 1.0.0
         *
         * @param bool $deleted_data Whether the data tables were dropped.
         */
        do_action( 'licensio_uninstalled', $delete_data );
    }

    /**
     * The state, report and redirect options every registered importer keeps.
     *
     * @since 1.0.0
     *
     * @return string[] Option names.
     */
    public static function import_options() {
        $importer = new Licensio_KB_Importer();

        return $importer->option_names();
    }

    /**
     * Whether the site owner asked for the data tables to be dropped.
     *
     * Defaults to false. The tables hold feedback comments and votes written by
     * visitors, which a site owner may reasonably treat as support history, and
     * a plugin delete is not always a decision to discard it.
     *
     * @since 1.0.0
     *
     * @return bool True when the data tables should be dropped.
     */
    public static function should_delete_data() {
        $settings = new Licensio_KB_Settings();

        return (bool) $settings->get( 'delete_data_on_uninstall', false );
    }
}
