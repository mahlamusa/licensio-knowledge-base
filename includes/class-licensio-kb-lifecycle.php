<?php
/**
 * Plugin lifecycle.
 *
 * Activation and deactivation. Activation runs before
 * `plugins_loaded` fires for this plugin, so it bootstraps the pieces it
 * needs itself rather than reaching for the Licensio_KB singleton.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles activation and deactivation.
 *
 * @since 1.0.0
 */
class Licensio_KB_Lifecycle {

    /**
     * Run on plugin activation.
     *
     * Refuses to activate on an unsupported PHP or WordPress version, or network
     * wide on a large network, then runs the per-site install routine. On a network activation the routine runs for
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
            deactivate_plugins( LICENSIO_KB_PLUGIN_BASENAME );
            wp_die(
                esc_html( $blocker ),
                esc_html__( 'Licensio Knowledge Base', 'licensio-knowledge-base' ),
                array( 'back_link' => true )
            );
        }

        if ( $network_wide && is_multisite() ) {
            /*
             * A large network cannot be walked inside one activation request
             * without timing out, and a subsite that is never installed has no
             * tables and 404s on every knowledge base URL. Refuse instead.
             */
            if ( wp_is_large_network() ) {
                deactivate_plugins( LICENSIO_KB_PLUGIN_BASENAME, false, true );
                wp_die(
                    esc_html__( 'Licensio Knowledge Base cannot be network activated on a network this large, because installing it on every site would time out. Activate it on each site that needs it instead.', 'licensio-knowledge-base' ),
                    esc_html__( 'Licensio Knowledge Base', 'licensio-knowledge-base' ),
                    array( 'back_link' => true )
                );
            }

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
        $settings   = new Licensio_KB_Settings();
        $post_types = new Licensio_KB_Post_Types( $settings );

        $post_types->register_taxonomies();
        $post_types->register_post_type();

        Licensio_KB_DB_Installer::install();

        $visitor = new Licensio_KB_Visitor();
        $visitor->get_salts();
        $visitor->schedule_rotation();

        flush_rewrite_rules();

        /**
         * Fires at the end of plugin activation.
         *
         * @since 1.0.0
         */
        do_action( 'licensio_activated' );
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

        if ( ! is_plugin_active_for_network( LICENSIO_KB_PLUGIN_BASENAME ) ) {
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
        $visitor = new Licensio_KB_Visitor();
        $visitor->unschedule_rotation();

        flush_rewrite_rules();

        /**
         * Fires at the end of plugin deactivation.
         *
         * Sub-issues that schedule their own cron events unschedule them here.
         *
         * @since 1.0.0
         */
        do_action( 'licensio_deactivated' );
    }

    /**
     * The reason the plugin cannot run here, if any.
     *
     * @since 1.0.0
     *
     * @return string A translated, human-readable error, or an empty string when requirements are met.
     */
    public static function requirements_error() {
        if ( version_compare( PHP_VERSION, LICENSIO_KB_MIN_PHP, '<' ) ) {
            return sprintf(
                /* translators: 1: required PHP version, 2: running PHP version */
                __( 'Licensio Knowledge Base requires PHP %1$s or newer. This site runs PHP %2$s.', 'licensio-knowledge-base' ),
                LICENSIO_KB_MIN_PHP,
                PHP_VERSION
            );
        }

        $wp_version = get_bloginfo( 'version' );

        if ( version_compare( $wp_version, LICENSIO_KB_MIN_WP, '<' ) ) {
            return sprintf(
                /* translators: 1: required WordPress version, 2: running WordPress version */
                __( 'Licensio Knowledge Base requires WordPress %1$s or newer. This site runs WordPress %2$s.', 'licensio-knowledge-base' ),
                LICENSIO_KB_MIN_WP,
                $wp_version
            );
        }

        return '';
    }
}
