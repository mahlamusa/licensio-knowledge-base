<?php
/**
 * Plugin lifecycle.
 *
 * Activation, deactivation and version upgrades. Activation runs before
 * `plugins_loaded` fires for this plugin, so it bootstraps the pieces it
 * needs itself rather than reaching for the Lisensio_KB singleton.
 *
 * Consumed by: #3, #4, #5, #6, #7, #8, #9, #10, #11, #12.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles activation, deactivation and upgrades.
 *
 * @since 1.0.0
 */
class Lisensio_KB_Lifecycle {

    /**
     * Option storing the installed plugin version.
     *
     * @since 1.0.0
     * @var string
     */
    const VERSION_OPTION = 'lisensio_version';

    /**
     * Option holding the upgrade lock while schema work is in progress.
     *
     * @since 1.0.0
     *
     * @var string
     */
    const UPGRADE_LOCK_OPTION = 'lisensio_upgrade_lock';

    /**
     * How long an upgrade lock is honoured before it is treated as abandoned.
     *
     * @since 1.0.0
     *
     * @var int
     */
    const UPGRADE_LOCK_TTL = 300;

    /**
     * Run on plugin activation.
     *
     * Refuses to activate on an unsupported PHP or WordPress version, then runs
     * the per-site install routine. On a network activation the routine runs for
     * every site on the network, because the tables, the visitor salt and — most
     * visibly — the rewrite rules are all per-site: without the loop every
     * subsite would 404 on `/docs/…` until someone re-saved its permalinks.
     *
     * @since 1.0.0
     *
     * @param bool $network_wide Whether the plugin is being activated for the whole network.
     *
     * @return void
     */
    public static function activate( $network_wide = false ) {
        $blocker = self::requirements_error();

        if ( '' !== $blocker ) {
            deactivate_plugins( LISENSIO_KB_PLUGIN_BASENAME );
            wp_die(
                esc_html( $blocker ),
                esc_html__( 'Lisensio Knowledge Base', 'lisensio-knowledge-base' ),
                array( 'back_link' => true )
            );
        }

        if ( $network_wide && is_multisite() ) {
            self::activate_network();

            return;
        }

        self::activate_site();
    }

    /**
     * Run the install routine on every site of the network.
     *
     * @since 1.0.0
     *
     * @return void
     */
    private static function activate_network() {
        /*
         * A large network cannot be walked inside a single activation request
         * without timing out. Those sites are not left broken: maybe_upgrade()
         * installs the tables and requests a rewrite flush the first time an
         * administrator loads wp-admin on each one.
         */
        if ( function_exists( 'wp_is_large_network' ) && wp_is_large_network() ) {
            return;
        }

        $site_ids = get_sites(
            array(
                'fields' => 'ids',
                'number' => 0,
            )
        );

        foreach ( $site_ids as $site_id ) {
            switch_to_blog( (int) $site_id );

            self::activate_site();

            restore_current_blog();
        }
    }

    /**
     * Install the plugin for the current site.
     *
     * Registers the content model, installs the tables, seeds the visitor salt,
     * schedules cron and flushes the rewrite rules. Taxonomies are registered
     * before the post type so the post type's `taxonomies` argument resolves.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public static function activate_site() {
        $settings   = new Lisensio_KB_Settings();
        $post_types = new Lisensio_KB_Post_Types( $settings );

        $post_types->register_taxonomies();
        $post_types->register_post_type();

        Lisensio_KB_DB_Installer::install();

        $visitor = new Lisensio_KB_Visitor();
        $visitor->get_salts();
        $visitor->schedule_rotation();

        update_option( self::VERSION_OPTION, LISENSIO_KB_VERSION, true );

        flush_rewrite_rules();

        /**
         * Fires at the end of plugin activation.
         *
         * @since 1.0.0
         */
        do_action( 'lisensio_activated' );
    }

    /**
     * Install the plugin on a subsite created after network activation.
     *
     * Hooked to `wp_initialize_site`. Without it a new subsite gets no tables,
     * no salt and no rewrite rules, and 404s on every knowledge base URL.
     *
     * @since 1.0.0
     *
     * @param WP_Site $new_site The site that was just created.
     *
     * @return void
     */
    public static function initialize_site( $new_site ) {
        if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        if ( ! is_plugin_active_for_network( LISENSIO_KB_PLUGIN_BASENAME ) ) {
            return;
        }

        switch_to_blog( (int) $new_site->blog_id );

        self::activate_site();

        restore_current_blog();
    }

    /**
     * Run on plugin deactivation.
     *
     * Clears the plugin's scheduled events and flushes the rewrite rules.
     * Data is deliberately left in place; removal happens on uninstall.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public static function deactivate() {
        $visitor = new Lisensio_KB_Visitor();
        $visitor->unschedule_rotation();

        flush_rewrite_rules();

        /**
         * Fires at the end of plugin deactivation.
         *
         * Sub-issues that schedule their own cron events unschedule them here.
         *
         * @since 1.0.0
         */
        do_action( 'lisensio_deactivated' );
    }

    /**
     * Bring an already-active install up to date after a plugin update.
     *
     * Also the repair path for a subsite that was never activated directly — a
     * site created before network activation, or one skipped because the network
     * was too large to walk. The version marker is only written once the install
     * actually succeeded, so a site whose database library is missing retries on
     * the next admin request instead of recording a version it never reached.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public static function maybe_upgrade() {
        $installed = (string) get_option( self::VERSION_OPTION, '' );

        if ( version_compare( $installed, LISENSIO_KB_VERSION, '>=' ) ) {
            Lisensio_KB_DB_Installer::maybe_install();

            return;
        }

        /*
         * Hold a short lock across the upgrade.
         *
         * The version marker is only written once install() has returned, so
         * without a lock two admin requests arriving together both read the old
         * version and both run the schema DDL. This runs on `admin_init`, where
         * concurrent requests from one administrator are routine rather than
         * exotic. add_option() is used for the claim because it is atomic on a
         * single row: the loser gets false and backs off, and the upgrade simply
         * happens on its next admin page load.
         */
        if ( ! add_option( self::UPGRADE_LOCK_OPTION, time(), '', false ) ) {
            $claimed_at = (int) get_option( self::UPGRADE_LOCK_OPTION, 0 );

            // Reclaim a lock abandoned by a fatal mid-upgrade.
            if ( $claimed_at > 0 && ( time() - $claimed_at ) < self::UPGRADE_LOCK_TTL ) {
                return;
            }

            update_option( self::UPGRADE_LOCK_OPTION, time(), false );
        }

        if ( ! Lisensio_KB_DB_Installer::install() ) {
            delete_option( self::UPGRADE_LOCK_OPTION );

            return;
        }

        update_option( self::VERSION_OPTION, LISENSIO_KB_VERSION, true );

        if ( '' === $installed ) {
            /*
             * First install on this site, which on multisite means the rewrite
             * rules were never built for it. Flushing here would run before the
             * permastructs are registered, so request one for `init` instead.
             */
            Lisensio_KB_Settings::request_rewrite_flush();
        }

        delete_option( self::UPGRADE_LOCK_OPTION );

        /**
         * Fires after the plugin has upgraded from an older installed version.
         *
         * @since 1.0.0
         *
         * @param string $installed The version that was installed before the upgrade.
         */
        do_action( 'lisensio_upgraded', $installed );
    }

    /**
     * The reason the plugin cannot run here, if any.
     *
     * @since 1.0.0
     *
     * @return string A translated, human-readable error, or an empty string when requirements are met.
     */
    public static function requirements_error() {
        if ( version_compare( PHP_VERSION, LISENSIO_KB_MIN_PHP, '<' ) ) {
            return sprintf(
                /* translators: 1: required PHP version, 2: running PHP version */
                __( 'Lisensio Knowledge Base requires PHP %1$s or newer. This site runs PHP %2$s.', 'lisensio-knowledge-base' ),
                LISENSIO_KB_MIN_PHP,
                PHP_VERSION
            );
        }

        $wp_version = get_bloginfo( 'version' );

        if ( version_compare( $wp_version, LISENSIO_KB_MIN_WP, '<' ) ) {
            return sprintf(
                /* translators: 1: required WordPress version, 2: running WordPress version */
                __( 'Lisensio Knowledge Base requires WordPress %1$s or newer. This site runs WordPress %2$s.', 'lisensio-knowledge-base' ),
                LISENSIO_KB_MIN_WP,
                $wp_version
            );
        }

        return '';
    }
}
