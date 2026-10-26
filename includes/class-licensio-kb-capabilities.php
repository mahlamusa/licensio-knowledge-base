<?php
/**
 * Capability model.
 *
 * Two layers, following the core post model.
 *
 * Articles have their own primitive capabilities (`edit_licensio_articles`,
 * `publish_licensio_articles`, `delete_others_licensio_articles` and so on),
 * mapped like core posts and granted per role the way core grants the post
 * capabilities: administrators and editors get all of them, authors write,
 * publish and delete their own articles, and contributors write and delete
 * their own drafts. With `map_meta_cap` on, core works out `edit_post`,
 * `delete_post` and `read_post` for each article from these, so per-article
 * ownership and status rules are core's own. Assigning existing categories
 * and tags follows `edit_licensio_articles`, as `assign_terms` follows
 * `edit_posts` in core.
 *
 * Everything else — creating, editing and deleting categories and tags, and
 * the Categories, Tags, Settings, Tools, Analytics and Feedback screens — is
 * managed through a single custom capability, `manage_licensio_kb`, granted
 * to `administrator` and `editor`.
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
 * Grants and maps the knowledge base article and management capabilities.
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
     * The article post type's primitive capabilities, by core's name for each.
     *
     * @since 1.0.0
     * @var array<string, string>
     */
    const ARTICLE_CAPABILITIES = array(
        'edit_posts'             => 'edit_licensio_articles',
        'edit_others_posts'      => 'edit_others_licensio_articles',
        'edit_published_posts'   => 'edit_published_licensio_articles',
        'edit_private_posts'     => 'edit_private_licensio_articles',
        'publish_posts'          => 'publish_licensio_articles',
        'read_private_posts'     => 'read_private_licensio_articles',
        'delete_posts'           => 'delete_licensio_articles',
        'delete_others_posts'    => 'delete_others_licensio_articles',
        'delete_published_posts' => 'delete_published_licensio_articles',
        'delete_private_posts'   => 'delete_private_licensio_articles',
    );

    /**
     * The article capabilities core gives an author for posts.
     *
     * @since 1.0.0
     * @var string[]
     */
    const AUTHOR_ARTICLE_CAPABILITIES = array( 'edit_posts', 'edit_published_posts', 'publish_posts', 'delete_posts', 'delete_published_posts' );

    /**
     * The article capabilities core gives a contributor for posts.
     *
     * @since 1.0.0
     * @var string[]
     */
    const CONTRIBUTOR_ARTICLE_CAPABILITIES = array( 'edit_posts', 'delete_posts' );

    /**
     * Register the WordPress hooks.
     *
     * The capabilities are granted on activation and after an upgrade — see the
     * plugin bootstrap, which hooks `licensio_activated` and
     * `licensio_upgraded`, so a network activation grants them inside each
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
     * nothing is written unless a capability is genuinely absent. It is also
     * what gives an existing install the article capabilities the first time an
     * administrator loads wp-admin after the plugin files change. The cost of
     * that is that a capability revoked by hand is granted back on the next
     * admin request; `licensio_capabilities` is the supported way to change who
     * holds it.
     *
     * @since 1.0.0 Grants the article capabilities to authors and contributors too.
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
     * Administrators and editors manage the knowledge base and hold every
     * article capability. Authors and contributors get the article
     * capabilities core gives them for posts, and nothing to manage.
     *
     * A role that manages the knowledge base, including one added through
     * the filter, always holds every article capability too.
     *
     * @since 1.0.0 Adds the article capabilities and the author and contributor roles.
     *
     * @return array<string, string[]> Role name to the capabilities granted to it.
     */
    public static function role_capabilities() {
        $all     = self::own_capabilities();
        $article = static function ( array $names ) {
            return array_values( array_intersect_key( self::ARTICLE_CAPABILITIES, array_flip( $names ) ) );
        };

        $roles = array(
            'administrator' => $all,
            'editor'        => $all,
            'author'        => $article( self::AUTHOR_ARTICLE_CAPABILITIES ),
            'contributor'   => $article( self::CONTRIBUTOR_ARTICLE_CAPABILITIES ),
        );

        /**
         * Filter the roles granted knowledge base capabilities.
         *
         * Applied when capabilities are granted and when they are removed, so a
         * site that adds a role here also has it cleaned up. Changes take effect
         * the next time the plugin is activated or upgraded; call
         * Licensio_KB_Capabilities::add_capabilities() directly to apply them
         * immediately. A role given `manage_licensio_kb` here also receives
         * every article capability, so managing the knowledge base still
         * includes managing its articles.
         *
         * @since 1.0.0
         *
         * @param array<string, string[]> $roles Role name to the capabilities granted to it.
         */
        $roles = apply_filters( 'licensio_capabilities', $roles );

        if ( ! is_array( $roles ) ) {
            return array();
        }

        foreach ( $roles as $role_name => $capabilities ) {
            if ( is_array( $capabilities ) && in_array( self::CAPABILITY, $capabilities, true ) ) {
                $roles[ $role_name ] = array_values( array_unique( array_merge( $capabilities, array_values( self::ARTICLE_CAPABILITIES ) ) ) );
            }
        }

        return $roles;
    }

    /**
     * The plugin's own capabilities: the management one and every article one.
     *
     * @since 1.0.0
     *
     * @return string[] The capability names.
     */
    private static function own_capabilities() {
        return array_merge( array( self::CAPABILITY ), array_values( self::ARTICLE_CAPABILITIES ) );
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
     * of the current site is swept for the plugin's own capabilities. A role
     * that was granted one by hand, or through a filter that has since been
     * removed, would otherwise keep it after the plugin is gone. The sweep
     * touches only `manage_licensio_kb` and the article capabilities, never
     * the capabilities a filter lists, which may be core capabilities the
     * role still needs. An explicit denial (granted as false) is removed too,
     * so isset() is the check rather than has_cap().
     *
     * @since 1.0.0 Sweeps the article capabilities too.
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
            if ( ! $role instanceof WP_Role ) {
                continue;
            }

            foreach ( self::own_capabilities() as $capability ) {
                if ( isset( $role->capabilities[ $capability ] ) ) {
                    $role->remove_cap( $capability );
                }
            }
        }
    }

    /**
     * The `capabilities` argument for the article post type.
     *
     * Every primitive capability resolves to the article's own capability, as
     * core's `post` resolves to `edit_posts` and friends. `create_posts` is
     * named explicitly, because core only derives it from `edit_posts` when
     * the key is absent. `read` is deliberately left as core's `read`: it is
     * what `map_meta_cap()` returns for a published article, and remapping it
     * would make reading a public article require an article capability.
     *
     * @since 1.0.0 Maps onto the article capabilities instead of the management capability.
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
        return array_merge(
            array( 'create_posts' => self::ARTICLE_CAPABILITIES['edit_posts'] ),
            self::ARTICLE_CAPABILITIES,
            array( 'read' => 'read' )
        );
    }

    /**
     * The `capabilities` argument for both knowledge base taxonomies.
     *
     * Creating, editing and deleting terms needs the management capability.
     * `assign_terms` follows `edit_licensio_articles`, as core's follows
     * `edit_posts`: anyone who may write an article may file it into existing
     * categories and tags, but not create new ones. It is the article
     * capability rather than core's `edit_posts`, so being able to write blog
     * posts does not let anybody file knowledge base articles.
     *
     * @since 1.0.0 Lets article writers assign existing terms.
     *
     * @return array<string, string> Capability name to the capability that satisfies it.
     */
    public static function taxonomy_capabilities() {
        return array(
            'manage_terms' => self::CAPABILITY,
            'edit_terms'   => self::CAPABILITY,
            'delete_terms' => self::CAPABILITY,
            'assign_terms' => self::ARTICLE_CAPABILITIES['edit_posts'],
        );
    }

    /**
     * Map the article post type onto the article capabilities.
     *
     * @since 1.0.0 Maps onto the article capabilities.
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
     * Map a knowledge base taxonomy onto the management and article capabilities.
     *
     * @since 1.0.0 Assigning terms follows the article capability.
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
