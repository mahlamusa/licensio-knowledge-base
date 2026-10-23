<?php
/**
 * The content manager switch, hidden pages and redirect map.
 *
 * The class ships inert, so most of what it holds is only reachable through
 * a subclass that reports itself active. Those tests stand in for the
 * switch-on: they prove the decision table, the hooks and the redirect map are
 * ready before anything turns them on.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

/**
 * Drives Licensio_KB_Content_Manager.
 *
 * @since 1.0.0
 */
class Licensio_KB_Content_Manager_Test extends Licensio_KB_Test_Case {

    /**
     * Stub the URL helpers the redirect map builds on.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        Functions\when( 'admin_url' )->alias(
            function ( $path = '' ) {
                return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' );
            }
        );

        // Like core, appends the values as given: add_query_arg() does not encode them.
        Functions\when( 'add_query_arg' )->alias(
            function ( $args, $url ) {
                foreach ( $args as $key => $value ) {
                    $url .= '&' . $key . '=' . $value;
                }

                return $url;
            }
        );

        Functions\when( 'absint' )->alias(
            function ( $value ) {
                return abs( (int) $value );
            }
        );
    }

    /**
     * A settings store holding the classic screens setting.
     *
     * @since 1.0.0
     *
     * @param bool $classic Whether classic screens are on.
     *
     * @return Licensio_KB_Settings_Spy The store.
     */
    private function settings( $classic = false ) {
        return new Licensio_KB_Settings_Spy(
            array(
                'classic_screens' => array(
                    'type'    => 'bool',
                    'default' => false,
                ),
            ),
            array( 'classic_screens' => $classic )
        );
    }

    /**
     * A content manager that reports itself switched on.
     *
     * @since 1.0.0
     *
     * @param bool $classic Whether classic screens are on.
     *
     * @return Licensio_KB_Content_Manager The active content manager.
     */
    private function active( $classic = false ) {
        return new Licensio_KB_Active_Content_Manager( $this->settings( $classic ) );
    }

    /**
     * The content manager ships switched off.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_it_ships_inactive() {
        $this->assertFalse( Licensio_KB_Content_Manager::is_active() );
    }

    /**
     * While inactive it is never enabled, whatever the setting and filter say.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_inactive_content_manager_is_never_enabled() {
        Filters\expectApplied( 'licensio_kb_use_content_manager' )->never();

        $this->assertFalse( ( new Licensio_KB_Content_Manager( $this->settings( false ) ) )->enabled() );
    }

    /**
     * Once active it is enabled by default.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_active_content_manager_is_enabled_by_default() {
        $this->assertTrue( $this->active( false )->enabled() );
    }

    /**
     * The classic screens setting turns it off.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_classic_screens_setting_turns_it_off() {
        $this->assertFalse( $this->active( true )->enabled() );
    }

    /**
     * The filter turns it off.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_filter_turns_it_off() {
        Filters\expectApplied( 'licensio_kb_use_content_manager' )->once()->with( true )->andReturn( false );

        $this->assertFalse( $this->active( false )->enabled() );
    }

    /**
     * While inactive only the REST controllers are hooked.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_inactive_content_manager_hooks_no_redirects_or_menu_changes() {
        $manager = new Licensio_KB_Content_Manager( $this->settings() );

        $manager->init_hooks();

        $this->assertSame( 10, has_filter( 'licensio_rest_controllers', array( $manager, 'register_rest_controllers' ) ) );
        $this->assertFalse( has_action( 'admin_menu', array( $manager, 'remove_core_menu_items' ) ) );
        $this->assertFalse( has_action( 'load-edit.php', array( $manager, 'maybe_redirect' ) ) );
        $this->assertFalse( has_action( 'load-edit-tags.php', array( $manager, 'maybe_redirect' ) ) );
        $this->assertFalse( has_action( 'load-term.php', array( $manager, 'maybe_redirect' ) ) );
    }

    /**
     * Once active the menu removal and the redirects are hooked.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_active_content_manager_hooks_redirects_and_menu_changes() {
        $manager = $this->active();

        $manager->init_hooks();

        $this->assertSame( 999, has_action( 'admin_menu', array( $manager, 'remove_core_menu_items' ) ) );
        $this->assertSame( 10, has_action( 'load-edit.php', array( $manager, 'maybe_redirect' ) ) );
        $this->assertSame( 10, has_action( 'load-edit-tags.php', array( $manager, 'maybe_redirect' ) ) );
        $this->assertSame( 10, has_action( 'load-term.php', array( $manager, 'maybe_redirect' ) ) );
    }

    /**
     * Both content manager REST controllers are announced.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_rest_controllers_are_announced() {
        $controllers = ( new Licensio_KB_Content_Manager( $this->settings() ) )->register_rest_controllers( array( 'Existing' ) );

        $this->assertSame(
            array( 'Existing', 'Licensio_KB_Terms_REST_Controller', 'Licensio_KB_Category_Order_REST_Controller' ),
            $controllers
        );

        foreach ( array_slice( $controllers, 1 ) as $class_name ) {
            $this->assertTrue( is_subclass_of( $class_name, 'Licensio_KB_REST_Controller' ) );
        }
    }

    /**
     * A hidden page is registered under a parent with no menu entry.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_hidden_page_has_no_menu_parent() {
        $added = array();

        Functions\when( 'add_submenu_page' )->alias(
            function ( $parent_slug, $page_title, $menu_title, $capability, $slug ) use ( &$added ) {
                $added[] = compact( 'parent_slug', 'page_title', 'menu_title', 'capability', 'slug' );

                return 'admin_page_' . $slug;
            }
        );

        $suffix = Licensio_KB_Content_Manager::register_hidden_page(
            array(
                'menu_slug'  => Licensio_KB_Content_Manager::ARTICLES_SLUG,
                'page_title' => 'Articles',
                'callback'   => '__return_true',
            )
        );

        $this->assertSame( 'admin_page_licensio-kb-articles', $suffix );
        $this->assertCount( 1, $added );
        $this->assertSame( 'options.php', $added[0]['parent_slug'] );
        $this->assertSame( 'Articles', $added[0]['page_title'] );
        $this->assertSame( 'manage_licensio_kb', $added[0]['capability'] );
        $this->assertSame( 'licensio-kb-articles', $added[0]['slug'] );
    }

    /**
     * An unusable definition registers nothing.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_unusable_hidden_page_is_not_registered() {
        Functions\expect( 'add_submenu_page' )->never();

        $this->assertSame( '', Licensio_KB_Content_Manager::register_hidden_page( array( 'menu_slug' => 'no-callback' ) ) );
        $this->assertSame(
            '',
            Licensio_KB_Content_Manager::register_hidden_page(
                array(
                    'menu_slug' => 'Bad Slug',
                    'callback'  => '__return_true',
                )
            )
        );
        $this->assertSame(
            '',
            Licensio_KB_Content_Manager::register_hidden_page(
                array(
                    'menu_slug' => 'good-slug',
                    'callback'  => 'no_such_function_exists_here',
                )
            )
        );
    }

    /**
     * A page the user may not see returns no hook suffix.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_refused_hidden_page_returns_no_hook_suffix() {
        Functions\when( 'add_submenu_page' )->justReturn( false );

        $this->assertSame(
            '',
            Licensio_KB_Content_Manager::register_hidden_page(
                array(
                    'menu_slug' => Licensio_KB_Content_Manager::TAGS_SLUG,
                    'callback'  => '__return_true',
                )
            )
        );
    }

    /**
     * The page URL uses the form that works hidden or in the menu.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_page_url_uses_admin_php() {
        $this->assertSame(
            'https://example.test/wp-admin/admin.php?page=licensio-kb-categories',
            Licensio_KB_Content_Manager::page_url( Licensio_KB_Content_Manager::CATEGORIES_SLUG )
        );
        $this->assertSame(
            'https://example.test/wp-admin/admin.php?page=licensio-kb-categories&term=7',
            Licensio_KB_Content_Manager::page_url( Licensio_KB_Content_Manager::CATEGORIES_SLUG, array( 'term' => 7 ) )
        );
    }

    /**
     * The core items are spelled exactly as core registers them.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_core_menu_items_match_core() {
        $this->assertSame(
            array(
                'edit.php?post_type=licensio_article',
                'edit-tags.php?taxonomy=licensio_category&amp;post_type=licensio_article',
                'edit-tags.php?taxonomy=licensio_tag&amp;post_type=licensio_article',
            ),
            ( new Licensio_KB_Content_Manager( $this->settings() ) )->core_menu_items()
        );
    }

    /**
     * An enabled content manager removes the three core items.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_core_items_are_removed_when_enabled() {
        $removed = array();

        Functions\when( 'remove_submenu_page' )->alias(
            function ( $parent_slug, $slug ) use ( &$removed ) {
                $removed[] = array( $parent_slug, $slug );

                return true;
            }
        );

        $manager = $this->active();
        $manager->remove_core_menu_items();

        $this->assertCount( 3, $removed );

        foreach ( $removed as $index => $item ) {
            $this->assertSame( Licensio_KB_Admin_Menu::PARENT_SLUG, $item[0] );
            $this->assertSame( $manager->core_menu_items()[ $index ], $item[1] );
        }
    }

    /**
     * The classic screens setting keeps the core items.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_core_items_stay_when_not_enabled() {
        Functions\expect( 'remove_submenu_page' )->never();

        $this->active( true )->remove_core_menu_items();
        ( new Licensio_KB_Content_Manager( $this->settings() ) )->remove_core_menu_items();
    }

    /**
     * The article list goes to the Articles app with its filters.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_article_list_redirects_with_its_filters() {
        Functions\when( 'get_term_by' )->alias(
            function ( $field, $value, $taxonomy ) {
                if ( 'slug' === $field && 'getting-started' === $value && 'licensio_category' === $taxonomy ) {
                    return new WP_Term( array( 'term_id' => 12 ) );
                }

                if ( 'slug' === $field && 'how-to' === $value && 'licensio_tag' === $taxonomy ) {
                    return new WP_Term( array( 'term_id' => 5 ) );
                }

                return false;
            }
        );

        $manager = new Licensio_KB_Content_Manager( $this->settings() );

        $this->assertSame(
            'https://example.test/wp-admin/admin.php?page=licensio-kb-articles',
            $manager->redirect_url( 'edit.php', array( 'post_type' => 'licensio_article' ) )
        );

        $this->assertSame(
            'https://example.test/wp-admin/admin.php?page=licensio-kb-articles&status=draft&search=reset%20password&category=12&tag=5',
            $manager->redirect_url(
                'edit.php',
                array(
                    'post_type'         => 'licensio_article',
                    'post_status'       => 'draft',
                    's'                 => 'reset password',
                    'licensio_category' => 'getting-started',
                    'licensio_tag'      => 'how-to',
                    'paged'             => '3',
                )
            )
        );
    }

    /**
     * An unknown category slug is dropped rather than carried as zero.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_unknown_term_slug_is_dropped() {
        Functions\when( 'get_term_by' )->justReturn( false );

        $this->assertSame(
            'https://example.test/wp-admin/admin.php?page=licensio-kb-articles',
            ( new Licensio_KB_Content_Manager( $this->settings() ) )->redirect_url(
                'edit.php',
                array(
                    'post_type'         => 'licensio_article',
                    'licensio_category' => 'no-such-category',
                )
            )
        );
    }

    /**
     * An all-digit category slug is looked up as a slug, not read as an ID.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_numeric_term_slug_is_looked_up_as_a_slug() {
        Functions\when( 'get_term_by' )->alias(
            function ( $field, $value, $taxonomy ) {
                if ( 'slug' === $field && '2024' === $value && 'licensio_category' === $taxonomy ) {
                    return new WP_Term( array( 'term_id' => 31 ) );
                }

                return false;
            }
        );

        $manager = new Licensio_KB_Content_Manager( $this->settings() );

        $this->assertSame(
            'https://example.test/wp-admin/admin.php?page=licensio-kb-articles&category=31',
            $manager->redirect_url(
                'edit.php',
                array(
                    'post_type'         => 'licensio_article',
                    'licensio_category' => '2024',
                )
            )
        );

        $this->assertSame(
            'https://example.test/wp-admin/admin.php?page=licensio-kb-articles',
            $manager->redirect_url(
                'edit.php',
                array(
                    'post_type'    => 'licensio_article',
                    'licensio_tag' => '7',
                )
            ),
            'A number that is no tag slug is dropped rather than read as an ID.'
        );
    }

    /**
     * Core bulk actions and other post types are left alone.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_article_list_actions_and_other_post_types_are_not_redirected() {
        $manager = new Licensio_KB_Content_Manager( $this->settings() );

        $this->assertSame( '', $manager->redirect_url( 'edit.php', array() ) );
        $this->assertSame( '', $manager->redirect_url( 'edit.php', array( 'post_type' => 'page' ) ) );

        foreach ( array( 'action', 'action2', 'delete_all', 'delete_all2' ) as $action ) {
            $this->assertSame(
                '',
                $manager->redirect_url(
                    'edit.php',
                    array(
                        'post_type' => 'licensio_article',
                        $action     => 'trash',
                    )
                ),
                $action . ' must reach the core handler.'
            );
        }

        $this->assertSame(
            'https://example.test/wp-admin/admin.php?page=licensio-kb-articles',
            $manager->redirect_url(
                'edit.php',
                array(
                    'post_type' => 'licensio_article',
                    'action'    => '-1',
                )
            ),
            'The empty bulk action placeholder is not an action.'
        );
    }

    /**
     * The term screens go to the Categories and Tags apps.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_term_screens_redirect_to_their_apps() {
        $manager = new Licensio_KB_Content_Manager( $this->settings() );

        $this->assertSame(
            'https://example.test/wp-admin/admin.php?page=licensio-kb-categories&search=billing',
            $manager->redirect_url(
                'edit-tags.php',
                array(
                    'taxonomy'  => 'licensio_category',
                    'post_type' => 'licensio_article',
                    's'         => 'billing',
                )
            )
        );

        $this->assertSame(
            'https://example.test/wp-admin/admin.php?page=licensio-kb-tags',
            $manager->redirect_url( 'edit-tags.php', array( 'taxonomy' => 'licensio_tag' ) )
        );

        $this->assertSame(
            'https://example.test/wp-admin/admin.php?page=licensio-kb-categories&term=42',
            $manager->redirect_url(
                'term.php',
                array(
                    'taxonomy' => 'licensio_category',
                    'tag_ID'   => '42',
                )
            )
        );

        $this->assertSame(
            'https://example.test/wp-admin/admin.php?page=licensio-kb-tags&term=9',
            $manager->redirect_url(
                'term.php',
                array(
                    'taxonomy' => 'licensio_tag',
                    'tag_ID'   => '9',
                )
            )
        );
    }

    /**
     * Core term deletes and bulk actions on the term screens are left alone.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_term_screen_actions_are_not_redirected() {
        $manager = new Licensio_KB_Content_Manager( $this->settings() );

        $actions = array(
            'action'      => 'delete',
            'action2'     => 'bulk-delete',
            'delete_tags' => array( '3', '4' ),
        );

        foreach ( $actions as $action => $value ) {
            $this->assertSame(
                '',
                $manager->redirect_url(
                    'edit-tags.php',
                    array(
                        'taxonomy' => 'licensio_tag',
                        $action    => $value,
                    )
                ),
                $action . ' must reach the core handler.'
            );
        }

        $this->assertSame(
            'https://example.test/wp-admin/admin.php?page=licensio-kb-categories',
            $manager->redirect_url(
                'edit-tags.php',
                array(
                    'taxonomy' => 'licensio_category',
                    'action'   => '-1',
                    'action2'  => '-1',
                )
            ),
            'The empty bulk action placeholder is not an action.'
        );
    }

    /**
     * A POST to a core screen is a form submission and is never redirected.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_post_request_is_not_redirected() {
        global $pagenow;

        $pagenow                   = 'edit-tags.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The screen under test.
        $_GET                      = array( 'taxonomy' => 'licensio_tag' );
        $_SERVER['REQUEST_METHOD'] = 'POST';

        // Throw instead of redirecting so a wrong redirect fails the test before exit runs.
        Functions\when( 'wp_safe_redirect' )->alias(
            function () {
                throw new RuntimeException( 'A POST request was redirected.' );
            }
        );

        try {
            $this->active()->maybe_redirect();
            $this->addToAssertionCount( 1 );
        } finally {
            $_GET = array();
            unset( $_SERVER['REQUEST_METHOD'] );
        }
    }

    /**
     * Other taxonomies, other screens and malformed arguments are left alone.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_other_screens_are_not_redirected() {
        $manager = new Licensio_KB_Content_Manager( $this->settings() );

        $this->assertSame( '', $manager->redirect_url( 'edit-tags.php', array( 'taxonomy' => 'category' ) ) );
        $this->assertSame( '', $manager->redirect_url( 'term.php', array( 'taxonomy' => 'post_tag' ) ) );
        $this->assertSame( '', $manager->redirect_url( 'post.php', array( 'post_type' => 'licensio_article' ) ) );
        $this->assertSame( '', $manager->redirect_url( 'post-new.php', array( 'post_type' => 'licensio_article' ) ) );
        $this->assertSame( '', $manager->redirect_url( 'edit-tags.php', array( 'taxonomy' => array( 'licensio_tag' ) ) ) );
    }
}
