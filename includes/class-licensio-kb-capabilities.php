<?php
/**
 * Capability model.
 *
 * The knowledge base is managed through a single custom capability,
 * `manage_licensio_kb`, granted to `administrator` and `editor` when the
 * plugin is installed on a site. Every primitive capability of the
 * `licensio_article` post type and of both taxonomies is mapped onto it, so
 * granting or revoking that one capability is the whole access decision.
 *
 * A custom capability rather than `manage_options` is deliberate: the people
 * who write documentation are usually not the people who administer the site,
 * and `manage_options` is effectively "can change anything", including the
 * things that break a site. `Licensio_KB_REST_Controller::MANAGE_CAPABILITY`
 * already names this capability, so the REST management endpoints inherit the
 * same model with no further wiring.
 *
 * Consumed by: #4, #8, #9, #10.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Grants and maps the knowledge base management capability.
 *
 * @since 1.0.0
 */
class Licensio_KB_Capabilities {

    /**
     * The capability every knowledge base management action requires.
     *
     * @since 1.0.0
     * @var string
     */
    const CAPABILITY = 'manage_licensio_kb';

    /**
     * Register the WordPress hooks.
     *
     * The capability is granted on activation — see the plugin bootstrap, which
     * hooks `licensio_activated` so a network activation grants it inside each
     * site's own install routine.
     *
     * `admin_init` is the repair path, following the same pattern
     * Licensio_KB_Lifecycle already uses to settle an install whose state is
     * behind the code. Without it, any site whose code changed without an
     * activation — a subsite of a network too large to walk, a site created
     * before network activation, a deploy that swapped the plugin files in
     * place — would hold a post type nobody can edit and would simply lose the
     * Knowledge Base menu with no visible cause.
     *
     * The check is free on the overwhelmingly common path: roles are already in
     * memory from the autoloaded `wp_user_roles` option, and has_cap() means
     * nothing is written unless a capability is genuinely absent. The cost of
     * that is that a capability revoked by hand is granted back on the next
     * admin request; `licensio_capabilities` is the supported way to change who
     * holds it.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function init_hooks() {
        add_filter( 'licensio_article_post_type_args', array( $this, 'filter_post_type_args' ) );
        add_filter( 'licensio_category_taxonomy_args', array( $this, 'filter_taxonomy_args' ) );
        add_filter( 'licensio_tag_taxonomy_args', array( $this, 'filter_taxonomy_args' ) );
        add_action( 'admin_init', array( __CLASS__, 'add_capabilities' ), 5 );
    }

    /**
     * The roles that receive knowledge base capabilities, and what they receive.
     *
     * @since 1.0.0
     *
     * @return array<string, string[]> Role name to the capabilities granted to it.
     */
    public static function role_capabilities() {
        $roles = array(
            'administrator' => array( self::CAPABILITY ),
            'editor'        => array( self::CAPABILITY ),
        );

        /**
         * Filter the roles granted knowledge base capabilities.
         *
         * Applied when capabilities are granted and when they are removed, so a
         * site that adds a role here also has it cleaned up. Changes take effect
         * the next time the plugin is activated or upgraded; call
         * Licensio_KB_Capabilities::add_capabilities() directly to apply them
         * immediately.
         *
         * @since 1.0.0
         *
         * @param array<string, string[]> $roles Role name to the capabilities granted to it.
         */
        $roles = apply_filters( 'licensio_capabilities', $roles );

        return is_array( $roles ) ? $roles : array();
    }

    /**
     * Grant the knowledge base capabilities to their roles.
     *
     * Idempotent, and safe to run on every activation and upgrade. Roles are
     * stored per site, so this must run inside the site it is granting for.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public static function add_capabilities() {
        foreach ( self::role_capabilities() as $role_name => $capabilities ) {
            $role = get_role( (string) $role_name );

            if ( ! $role instanceof WP_Role ) {
                continue;
            }

            foreach ( (array) $capabilities as $capability ) {
                $capability = (string) $capability;

                if ( '' === $capability || $role->has_cap( $capability ) ) {
                    continue;
                }

                $role->add_cap( $capability );
            }
        }
    }

    /**
     * Revoke the knowledge base capabilities from their roles.
     *
     * Not called on deactivation: deactivating a plugin is routinely done to
     * troubleshoot something unrelated, and silently rewriting the site's roles
     * each time would lose any capability an administrator had granted by hand.
     * Exposed for the uninstall path and for tests.
     *
     * After the roles named by `licensio_capabilities` are revoked, every role
     * of the current site is swept for the plugin's own capability. A role
     * that was granted it by hand, or through a filter that has since been
     * removed, would otherwise keep it after the plugin is gone. The sweep
     * touches only `manage_licensio_kb`, never the capabilities a filter
     * lists, which may be core capabilities the role still needs. An explicit
     * denial (granted as false) is removed too, so isset() is the check
     * rather than has_cap().
     *
     * @since 1.0.0
     *
     * @return void
     */
    public static function remove_capabilities() {
        foreach ( self::role_capabilities() as $role_name => $capabilities ) {
            $role = get_role( (string) $role_name );

            if ( ! $role instanceof WP_Role ) {
                continue;
            }

            foreach ( (array) $capabilities as $capability ) {
                $role->remove_cap( (string) $capability );
            }
        }

        foreach ( wp_roles()->role_objects as $role ) {
            if ( $role instanceof WP_Role && isset( $role->capabilities[ self::CAPABILITY ] ) ) {
                $role->remove_cap( self::CAPABILITY );
            }
        }
    }

    /**
     * The `capabilities` argument for the article post type.
     *
     * Every primitive capability resolves to `manage_licensio_kb`. `read` is
     * deliberately left as core's `read`: it is what `map_meta_cap()` returns
     * for a published article, and remapping it would make reading a public
     * article require the management capability.
     *
     * @since 1.0.0
     *
     * @return array<string, string> Capability name to the capability that satisfies it.
     */
    public static function post_type_capabilities() {
        /*
         * Only PRIMITIVE capabilities belong here.
         *
         * edit_post, read_post and delete_post are META capabilities: with
         * map_meta_cap => true core derives them from the primitives below,
         * against a specific post. Naming them here put this plugin's own
         * capability into the meta-cap position, so core's map_meta_cap()
         * answered do_not_allow whenever anything asked for it WITHOUT an
         * object - which is every admin screen check. The result was a clean
         * install where an administrator got 403 on every knowledge base page,
         * plus a stream of _doing_it_wrong notices about delete_post.
         */
        return array(
            'create_posts'           => self::CAPABILITY,
            'edit_posts'             => self::CAPABILITY,
            'edit_others_posts'      => self::CAPABILITY,
            'edit_published_posts'   => self::CAPABILITY,
            'edit_private_posts'     => self::CAPABILITY,
            'publish_posts'          => self::CAPABILITY,
            'read_private_posts'     => self::CAPABILITY,
            'delete_posts'           => self::CAPABILITY,
            'delete_others_posts'    => self::CAPABILITY,
            'delete_published_posts' => self::CAPABILITY,
            'delete_private_posts'   => self::CAPABILITY,
            'read'                   => 'read',
        );
    }

    /**
     * The `capabilities` argument for both knowledge base taxonomies.
     *
     * `assign_terms` is mapped too. Core defaults it to `edit_posts`, which on a
     * site with the default roles would let any contributor or author file their
     * own posts into knowledge base categories.
     *
     * @since 1.0.0
     *
     * @return array<string, string> Capability name to the capability that satisfies it.
     */
    public static function taxonomy_capabilities() {
        return array(
            'manage_terms' => self::CAPABILITY,
            'edit_terms'   => self::CAPABILITY,
            'delete_terms' => self::CAPABILITY,
            'assign_terms' => self::CAPABILITY,
        );
    }

    /**
     * Map the article post type onto the management capability.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $args The post type registration arguments.
     *
     * @return array<string, mixed> The arguments, with the capability mapping applied.
     */
    public function filter_post_type_args( $args ) {
        if ( ! is_array( $args ) ) {
            return $args;
        }

        $args['capabilities'] = self::post_type_capabilities();

        /*
         * Meta capability mapping must stay on. Without it `edit_post` and
         * friends are checked as literal capabilities that no role holds, and
         * the block editor refuses to open an article for anybody.
         */
        $args['map_meta_cap'] = true;

        return $args;
    }

    /**
     * Map a knowledge base taxonomy onto the management capability.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $args The taxonomy registration arguments.
     *
     * @return array<string, mixed> The arguments, with the capability mapping applied.
     */
    public function filter_taxonomy_args( $args ) {
        if ( ! is_array( $args ) ) {
            return $args;
        }

        $args['capabilities'] = self::taxonomy_capabilities();

        return $args;
    }

    /**
     * Whether the current user may manage the knowledge base.
     *
     * The single question every admin screen, term meta authorisation callback
     * and management endpoint asks, so the answer is defined once.
     *
     * @since 1.0.0
     *
     * @return bool True when the current user holds the management capability.
     */
    public static function current_user_can_manage() {
        return current_user_can( self::CAPABILITY );
    }
}
