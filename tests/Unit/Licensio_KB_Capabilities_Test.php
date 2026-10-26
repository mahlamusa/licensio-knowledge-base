<?php
/**
 * Unit tests for the capability model.
 *
 * Covers which roles are granted the management and article capabilities,
 * that the grant is filterable, and that the capabilities are actually mapped
 * onto the post type and both taxonomies rather than only being handed out.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

/**
 * Tests Licensio_KB_Capabilities.
 *
 * @since 1.0.0
 */
class Licensio_KB_Capabilities_Test extends Licensio_KB_Test_Case {

    /**
     * The roles the fake get_role() knows about, by name.
     *
     * @since 1.0.0
     * @var array<string, WP_Role>
     */
    private $roles = array();

    /**
     * Stand up a fake role store.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        $this->roles = array(
            'administrator' => new WP_Role( 'administrator', array( 'manage_options' => true ) ),
            'editor'        => new WP_Role( 'editor', array( 'edit_others_posts' => true ) ),
            'author'        => new WP_Role( 'author', array( 'publish_posts' => true ) ),
            'contributor'   => new WP_Role( 'contributor', array( 'edit_posts' => true ) ),
            'subscriber'    => new WP_Role( 'subscriber', array( 'read' => true ) ),
        );

        Functions\when( 'get_role' )->alias(
            function ( $name ) {
                return isset( $this->roles[ $name ] ) ? $this->roles[ $name ] : null;
            }
        );

        Functions\when( 'wp_roles' )->alias(
            function () {
                return (object) array( 'role_objects' => $this->roles );
            }
        );
    }

    /**
     * Administrators and editors receive the management capability.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_administrator_and_editor_are_granted_the_capability() {
        Licensio_KB_Capabilities::add_capabilities();

        $this->assertTrue( $this->roles['administrator']->has_cap( 'manage_licensio_kb' ) );
        $this->assertTrue( $this->roles['editor']->has_cap( 'manage_licensio_kb' ) );
    }

    /**
     * No other role is touched.
     *
     * The acceptance criterion is explicit that a subscriber can do none of it,
     * and the only thing standing between a subscriber and the whole knowledge
     * base is this capability.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_subscriber_is_not_granted_the_capability() {
        Licensio_KB_Capabilities::add_capabilities();

        $this->assertFalse( $this->roles['subscriber']->has_cap( 'manage_licensio_kb' ) );
        $this->assertSame( array( 'read' => true ), $this->roles['subscriber']->capabilities );
    }

    /**
     * Granting a capability a role already holds writes nothing.
     *
     * The grant runs on `admin_init`, so it happens on every admin request.
     * Core's `WP_Role::add_cap()` rewrites the autoloaded `wp_user_roles`
     * option each time it is called, so an unguarded grant would mean a
     * database write on every single page of wp-admin.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_granting_a_capability_twice_writes_once() {
        Licensio_KB_Capabilities::add_capabilities();
        Licensio_KB_Capabilities::add_capabilities();
        Licensio_KB_Capabilities::add_capabilities();

        $granted = 1 + count( Licensio_KB_Capabilities::ARTICLE_CAPABILITIES );

        $this->assertSame( $granted, $this->roles['administrator']->add_cap_calls );
        $this->assertSame( $granted, $this->roles['editor']->add_cap_calls );
        $this->assertTrue( $this->roles['administrator']->has_cap( 'manage_licensio_kb' ) );
    }

    /**
     * A role that does not exist is skipped rather than fatalling.
     *
     * Plenty of sites remove the editor role outright.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_missing_role_is_skipped() {
        unset( $this->roles['editor'] );

        Licensio_KB_Capabilities::add_capabilities();

        $this->assertTrue( $this->roles['administrator']->has_cap( 'manage_licensio_kb' ) );
    }

    /**
     * The grant honours the `licensio_capabilities` filter.
     *
     * Brain Monkey does not run `add_filter` callbacks, so a test that
     * registered one and asserted on the result would pass no matter what the
     * code did. The filtered value is supplied here instead, which exercises
     * the same branch honestly.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_granted_roles_are_filterable() {
        $this->roles['shop_manager'] = new WP_Role( 'shop_manager' );

        Filters\expectApplied( 'licensio_capabilities' )
            ->zeroOrMoreTimes()
            ->andReturn( array( 'shop_manager' => array( 'manage_licensio_kb' ) ) );

        Licensio_KB_Capabilities::add_capabilities();

        $this->assertTrue( $this->roles['shop_manager']->has_cap( 'manage_licensio_kb' ) );
        $this->assertTrue( $this->roles['shop_manager']->has_cap( 'edit_others_licensio_articles' ), 'Managing includes the articles.' );
        $this->assertFalse( $this->roles['administrator']->has_cap( 'manage_licensio_kb' ) );
        $this->assertFalse( $this->roles['author']->has_cap( 'edit_licensio_articles' ) );
    }

    /**
     * A filter that returns something unusable does not fatal.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_non_array_filter_return_is_ignored() {
        Filters\expectApplied( 'licensio_capabilities' )->zeroOrMoreTimes()->andReturn( 'nope' );

        Licensio_KB_Capabilities::add_capabilities();

        $this->assertFalse( $this->roles['administrator']->has_cap( 'manage_licensio_kb' ) );
    }

    /**
     * Removing the capability takes it away again.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_capabilities_can_be_removed() {
        Licensio_KB_Capabilities::add_capabilities();
        Licensio_KB_Capabilities::remove_capabilities();

        $this->assertFalse( $this->roles['administrator']->has_cap( 'manage_licensio_kb' ) );
        $this->assertFalse( $this->roles['editor']->has_cap( 'manage_licensio_kb' ) );
        $this->assertTrue( $this->roles['administrator']->has_cap( 'manage_options' ) );

        foreach ( array( 'administrator', 'editor', 'author', 'contributor' ) as $role ) {
            foreach ( Licensio_KB_Capabilities::ARTICLE_CAPABILITIES as $capability ) {
                $this->assertFalse( $this->roles[ $role ]->has_cap( $capability ), sprintf( '%s kept %s.', $role, $capability ) );
            }
        }
    }

    /**
     * Removal sweeps every role for the plugin's own capabilities, and nothing else.
     *
     * A role granted a capability by hand, or through a filter that has
     * since been removed, is not in the filtered role list at uninstall time.
     * An explicit denial is residue as well. Core capabilities, including any
     * a filter lists, are left on roles the filter does not name.
     *
     * @since 1.0.0 Sweeps the article capabilities too.
     *
     * @return void
     */
    public function test_removal_sweeps_every_role_for_the_plugin_capabilities() {
        $this->roles['subscriber']->add_cap( 'manage_licensio_kb' );
        $this->roles['subscriber']->add_cap( 'edit_licensio_articles' );
        $this->roles['author'] = new WP_Role(
            'author',
            array(
                'edit_posts'         => true,
                'manage_licensio_kb' => false,
            )
        );

        Licensio_KB_Capabilities::remove_capabilities();

        $this->assertArrayNotHasKey( 'manage_licensio_kb', $this->roles['subscriber']->capabilities );
        $this->assertArrayNotHasKey( 'edit_licensio_articles', $this->roles['subscriber']->capabilities );
        $this->assertArrayNotHasKey( 'manage_licensio_kb', $this->roles['author']->capabilities, 'An explicit denial is residue too.' );
        $this->assertTrue( $this->roles['subscriber']->has_cap( 'read' ) );
        $this->assertTrue( $this->roles['author']->has_cap( 'edit_posts' ) );
        $this->assertTrue( $this->roles['editor']->has_cap( 'edit_others_posts' ) );
    }

    /**
     * Administrators and editors hold every article capability.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_administrator_and_editor_hold_every_article_capability() {
        Licensio_KB_Capabilities::add_capabilities();

        foreach ( array( 'administrator', 'editor' ) as $role ) {
            foreach ( Licensio_KB_Capabilities::ARTICLE_CAPABILITIES as $capability ) {
                $this->assertTrue( $this->roles[ $role ]->has_cap( $capability ), sprintf( '%s lacks %s.', $role, $capability ) );
            }
        }
    }

    /**
     * Authors write, publish and delete their own articles, and manage nothing.
     *
     * The same capabilities core gives an author for posts.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_author_gets_the_core_author_article_capabilities() {
        Licensio_KB_Capabilities::add_capabilities();

        $expected = array(
            'edit_licensio_articles',
            'edit_published_licensio_articles',
            'publish_licensio_articles',
            'delete_licensio_articles',
            'delete_published_licensio_articles',
        );

        foreach ( Licensio_KB_Capabilities::ARTICLE_CAPABILITIES as $capability ) {
            $this->assertSame( in_array( $capability, $expected, true ), $this->roles['author']->has_cap( $capability ), $capability );
        }

        $this->assertFalse( $this->roles['author']->has_cap( 'manage_licensio_kb' ) );
    }

    /**
     * Contributors write and delete their own drafts only.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_contributor_gets_the_core_contributor_article_capabilities() {
        Licensio_KB_Capabilities::add_capabilities();

        $expected = array( 'edit_licensio_articles', 'delete_licensio_articles' );

        foreach ( Licensio_KB_Capabilities::ARTICLE_CAPABILITIES as $capability ) {
            $this->assertSame( in_array( $capability, $expected, true ), $this->roles['contributor']->has_cap( $capability ), $capability );
        }

        $this->assertFalse( $this->roles['contributor']->has_cap( 'manage_licensio_kb' ) );
    }

    /**
     * Every article primitive capability resolves to an article capability a role is granted.
     *
     * Granting a capability that nothing is mapped onto would leave a holder
     * with a capability and no access, which is exactly the failure this test
     * exists to catch. `read` is the deliberate exception: it is what
     * `map_meta_cap()` returns for a published article.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_every_article_capability_maps_to_a_granted_article_capability() {
        $capabilities = new Licensio_KB_Capabilities();
        $args         = $capabilities->filter_post_type_args( array( 'capability_type' => 'post' ) );

        $this->assertArrayHasKey( 'capabilities', $args );
        $this->assertTrue( $args['map_meta_cap'], 'Meta capability mapping must stay on.' );
        $this->assertSame( 'read', $args['capabilities']['read'] );

        foreach ( $args['capabilities'] as $capability => $mapped ) {
            if ( 'read' === $capability ) {
                continue;
            }

            $this->assertContains(
                $mapped,
                Licensio_KB_Capabilities::role_capabilities()['administrator'],
                sprintf( 'The %s capability must map onto a capability administrators are granted.', $capability )
            );
        }

        foreach ( array( 'create_posts', 'edit_posts', 'edit_others_posts', 'publish_posts', 'delete_posts' ) as $required ) {
            $this->assertArrayHasKey( $required, $args['capabilities'] );
        }

        $this->assertSame( 'edit_licensio_articles', $args['capabilities']['create_posts'], 'Creating follows editing, as in core.' );
    }

    /**
     * `manage_options` is never what grants access.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_nothing_is_mapped_onto_manage_options() {
        $this->assertNotContains( 'manage_options', Licensio_KB_Capabilities::post_type_capabilities() );
        $this->assertNotContains( 'manage_options', Licensio_KB_Capabilities::taxonomy_capabilities() );
    }

    /**
     * Both taxonomies map every term capability, including assigning terms.
     *
     * Managing terms needs the management capability. Assigning existing
     * terms follows the article capability, as in core, rather than core's
     * `edit_posts`, which would let anyone who writes blog posts file
     * knowledge base articles.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_taxonomy_capabilities_cover_assigning_terms() {
        $capabilities = new Licensio_KB_Capabilities();
        $args         = $capabilities->filter_taxonomy_args( array( 'hierarchical' => true ) );

        $this->assertSame(
            array(
                'manage_terms' => 'manage_licensio_kb',
                'edit_terms'   => 'manage_licensio_kb',
                'delete_terms' => 'manage_licensio_kb',
                'assign_terms' => 'edit_licensio_articles',
            ),
            $args['capabilities']
        );

        $this->assertTrue( $args['hierarchical'], 'Unrelated arguments must survive the filter.' );
    }

    /**
     * The mapping is attached to the post type and to BOTH taxonomies.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_mapping_is_hooked_onto_the_whole_content_model() {
        $capabilities = new Licensio_KB_Capabilities();

        $capabilities->init_hooks();

        $this->assertNotFalse( has_filter( 'licensio_article_post_type_args', array( $capabilities, 'filter_post_type_args' ) ) );
        $this->assertNotFalse( has_filter( 'licensio_category_taxonomy_args', array( $capabilities, 'filter_taxonomy_args' ) ) );
        $this->assertNotFalse( has_filter( 'licensio_tag_taxonomy_args', array( $capabilities, 'filter_taxonomy_args' ) ) );
        $this->assertNotFalse(
            has_action( 'admin_init', array( 'Licensio_KB_Capabilities', 'add_capabilities' ) ),
            'A site whose code changed without an activation must still receive the capability.'
        );
    }

    /**
     * The shared "may manage" question asks for the custom capability.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_current_user_can_manage_checks_the_custom_capability() {
        $asked = array();

        Functions\when( 'current_user_can' )->alias(
            function ( $capability ) use ( &$asked ) {
                $asked[] = $capability;

                return 'manage_licensio_kb' === $capability;
            }
        );

        $this->assertTrue( Licensio_KB_Capabilities::current_user_can_manage() );
        $this->assertSame( array( 'manage_licensio_kb' ), $asked );
    }

    /**
     * The post type map declares no meta capabilities.
     *
     * edit_post, read_post and delete_post are meta capabilities. With
     * map_meta_cap => true, core derives them from the primitives against a
     * specific post. Naming them in the map puts this plugin's own capability
     * into the meta-cap position, and core's map_meta_cap() then answers
     * do_not_allow for every check made WITHOUT an object id - which is every
     * admin screen check. On a clean install that locked an administrator out
     * of every knowledge base page with a 403.
     *
     * Unit tests and CI both passed while that was true, because neither
     * resolves a capability through core. This asserts the invariant directly.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_post_type_map_declares_no_meta_capabilities() {
        $map = Licensio_KB_Capabilities::post_type_capabilities();

        foreach ( array( 'edit_post', 'read_post', 'delete_post' ) as $meta_cap ) {
            $this->assertArrayNotHasKey(
                $meta_cap,
                $map,
                sprintf( 'The meta capability %s must be left to core to derive.', $meta_cap )
            );
        }
    }

    /**
     * The primitives core needs to derive those meta capabilities are present.
     *
     * Removing the meta caps only works because core can reach the primitives.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_primitives_core_derives_from_are_all_mapped() {
        $map = Licensio_KB_Capabilities::post_type_capabilities();

        foreach ( array( 'edit_posts', 'edit_others_posts', 'edit_published_posts', 'edit_private_posts', 'delete_posts', 'delete_others_posts', 'delete_published_posts', 'delete_private_posts', 'read_private_posts', 'publish_posts' ) as $primitive ) {
            $this->assertArrayHasKey( $primitive, $map, sprintf( 'Core needs %s to derive the meta capabilities.', $primitive ) );
            $this->assertSame( Licensio_KB_Capabilities::ARTICLE_CAPABILITIES[ $primitive ], $map[ $primitive ] );
        }
    }
}
