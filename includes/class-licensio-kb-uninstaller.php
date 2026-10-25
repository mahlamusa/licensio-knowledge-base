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
 * - Data is kept unless the site owner opted in through the
 *   `delete_data_on_uninstall` setting. Data is the five tables (feedback
 *   comments and votes, the search index, search and view history, and the
 *   import log), the settings (which hold the
 *   article, category and tag slugs, so every permalink survives a delete and
 *   reinstall), the import options (the served redirect map, the resume
 *   cursors and the run reports) and the import URL map files. Deleting
 *   support history or the redirects that keep old links alive, on a plugin
 *   delete that may well be an upgrade or a migration, is not recoverable;
 *   keeping it is. Kept options are switched to not autoload, so a site
 *   without the plugin does not load them on every request.
 * - Plugin state - the version markers, the visitor salt, the one-shot flags,
 *   the upgrade lock, the cron events and per-user admin preferences - is always
 *   removed, on every site of the network. A scheduled hook or a user meta row
 *   that outlives the plugin that owns it is pure residue.
 *
 * Articles, categories and tags are never removed by uninstall.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Removes the plugin's state, and its data when the site owner opted in.
 *
 * @since 1.0.0
 */
class Licensio_KB_Uninstaller {

    /**
     * The plugin state options, removed on every uninstall.
     *
     * @since 1.0.0
     * @var string[]
     */
    const OPTIONS = array(
        'licensio_visitor_salt',
        'licensio_version',
        'licensio_db_version',
        'licensio_fulltext_supported',
        'licensio_flush_rewrite_rules',
        'licensio_search_reindex_state',
        Licensio_KB_Search_Index::BUILT_OPTION,
        Licensio_KB_Lifecycle::UPGRADE_LOCK_OPTION,
    );

    /**
     * The data options outside the importer, kept unless the site owner opted in.
     *
     * @since 1.0.0
     * @var string[]
     */
    const DATA_OPTIONS = array(
        Licensio_KB_Settings::OPTION_NAME,
    );

    /**
     * Every cron hook the plugin schedules, cleared on every site.
     *
     * Deactivation clears them too, but a network deactivation runs only on
     * the main site, so a subsite's events would otherwise outlive the plugin.
     *
     * @since 1.0.0
     * @var string[]
     */
    const CRON_HOOKS = array(
        Licensio_KB_Visitor::CRON_HOOK,
        Licensio_KB_Search_Index::CRON_HOOK,
        Licensio_KB_Analytics_Retention::CRON_HOOK,
    );

    /**
     * Every user meta key the plugin owns.
     *
     * User meta is stored once for the whole network, not per site.
     *
     * @since 1.0.0
     * @var string[]
     */
    const USER_META = array(
        Licensio_KB_Articles_REST_Controller::PREFERENCES_META,
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
     * Removes the plugin state always, and the data only when the site owner
     * turned on Delete everything on uninstall. Kept data options are switched
     * to not autoload.
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

            foreach ( self::data_options() as $option ) {
                delete_option( $option );
            }

            Licensio_KB_Import_Redirects::delete_directory();
        } else {
            wp_set_option_autoload_values( array_fill_keys( self::data_options(), false ) );
        }

        foreach ( self::OPTIONS as $option ) {
            delete_option( $option );
        }

        foreach ( self::CRON_HOOKS as $hook ) {
            wp_clear_scheduled_hook( $hook );
        }

        /*
         * Delete the key for every user. The users table is shared by the
         * network, so on multisite the later sites find nothing left to delete.
         */
        foreach ( self::USER_META as $meta_key ) {
            delete_metadata( 'user', 0, $meta_key, '', true );
        }

        /**
         * Fires while the plugin is being removed from a site.
         *
         * Sub-issues that store their own options or schedule their own cron
         * events clean them up here. Fires once per site on multisite, inside
         * `switch_to_blog()`.
         *
         * @since 1.0.0
         *
         * @param bool $deleted_data Whether the data was deleted: the tables, the data options and the URL map files.
         */
        do_action( 'licensio_uninstalled', $delete_data );
    }

    /**
     * Every data option, kept unless the site owner opted in.
     *
     * @since 1.0.0
     *
     * @return string[] Option names.
     */
    public static function data_options() {
        return array_values( array_unique( array_merge( self::DATA_OPTIONS, self::import_options() ) ) );
    }

    /**
     * The state, report, redirect and URL map options every registered importer keeps.
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
     * Whether the site owner asked for the data to be deleted.
     *
     * Defaults to false. The tables hold feedback comments and votes written by
     * visitors, which a site owner may reasonably treat as support history, the
     * settings hold the permalink slugs and the import options hold the
     * redirects for old links, and a plugin delete is not always a decision to
     * discard them.
     *
     * @since 1.0.0
     *
     * @return bool True when the tables, data options and URL map files should be deleted.
     */
    public static function should_delete_data() {
        $settings = new Licensio_KB_Settings();

        return (bool) $settings->get( 'delete_data_on_uninstall', false );
    }
}
