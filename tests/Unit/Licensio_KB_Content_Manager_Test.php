<?php
/**
 * The content manager switch, app pages, redirects and escape hatch.
 *
 * Covers the decision table (the classic screens setting and the
 * `licensio_kb_use_content_manager` filter, each both ways), where the app
 * pages are registered, which core menu items are removed, and which core
 * screen requests are redirected and where to.
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
     * The add_submenu_page() calls recorded by record_submenus().
     *
     * @since 1.0.0
     * @var array<int, array<int, mixed>>
     */
    private $submenus = array();

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
     * A content manager.
     *
     * @since 1.0.0
     *
     * @param bool $classic Whether classic screens are on.
     *
     * @return Licensio_KB_Content_Manager The content manager.
     */
    private function active( $classic = false ) {
        return new Licensio_KB_Content_Manager( $this->settings( $classic ) );
    }

    /**
     * The content manager ships switched on.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_it_ships_active() {
        $this->assertTrue( Licensio_KB_Content_Manager::is_active() );
    }

    /**
     * It is enabled by default.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_it_is_enabled_by_default() {
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
     * An asset loader whose manifest holds the given app entries.
     *
     * @since 1.0.0
     *
     * @param string[] $built The app entries the manifest has a file for.
     * @param bool     $hmr   Whether the dev server serves the apps.
     *
     * @return Licensio_KB_Assets The asset loader.
     */
    private function assets( array $built, $hmr = false ) {
        return new class( $built, $hmr ) extends Licensio_KB_Assets {

            /**
             * The app entries the manifest has a file for.
             *
             * @since 1.0.0
             * @var string[]
             */
            private $built;

            /**
             * Whether the dev server serves the apps.
             *
             * @since 1.0.0
             * @var bool
             */
            private $hmr;

            /**
             * Build the double.
             *
             * @since 1.0.0
             *
             * @param string[] $built The app entries the manifest has a file for.
             * @param bool     $hmr   Whether the dev server serves the apps.
             */
            public function __construct( array $built, $hmr ) {
                $this->built = $built;
                $this->hmr   = $hmr;
            }

            /**
             * The manifest chunk of an entry.
             *
             * @since 1.0.0
             *
             * @param string $entry The entry.
             *
             * @return array<string, mixed> The chunk, or an empty array when it was not built.
             */
            public function manifest_entry( $entry ) {
                return in_array( $entry, $this->built, true ) ? array( 'file' => 'apps/x.js' ) : array();
            }

            /**
             * Whether the dev server serves the apps.
             *
             * @since 1.0.0
             *
             * @return bool The configured answer.
             */
            public function is_hmr_enabled() {
                return $this->hmr;
            }
        };
    }

    /**
     * Without a build of every app the core screens stay, unless the dev server serves them.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_missing_app_build_keeps_the_core_screens() {
        $apps = array( 'apps/articles/index.ts', 'apps/categories/index.ts', 'apps/tags/index.ts' );

        $this->assertTrue( ( new Licensio_KB_Content_Manager( $this->settings(), $this->assets( $apps ) ) )->enabled() );
        $this->assertFalse( ( new Licensio_KB_Content_Manager( $this->settings(), $this->assets( array() ) ) )->enabled() );
        $this->assertFalse( ( new Licensio_KB_Content_Manager( $this->settings(), $this->assets( array_slice( $apps, 0, 2 ) ) ) )->enabled() );
        $this->assertTrue( ( new Licensio_KB_Content_Manager( $this->settings(), $this->assets( array(), true ) ) )->enabled() );
    }

    /**
     * Without a build the core menu items stay.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_missing_app_build_keeps_the_core_menu_items() {
        Functions\expect( 'remove_submenu_page' )->never();

        ( new Licensio_KB_Content_Manager( $this->settings(), $this->assets( array() ) ) )->remove_core_menu_items();
    }

    /**
     * The REST controllers, the menu removal and the redirects are hooked.
     *
     * Hooked whatever the setting says: the callbacks read the escape hatch
     * when they run.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_redirects_and_menu_changes_are_hooked() {
        $manager = $this->active( true );

        $manager->init_hooks();

        $this->assertSame( 10, has_filter( 'licensio_rest_controllers', array( $manager, 'register_rest_controllers' ) ) );
        $this->assertSame( 999, has_action( 'admin_menu', array( $manager, 'remove_core_menu_items' ) ) );
        $this->assertSame( 10, has_filter( 'submenu_file', array( $manager, 'current_submenu_file' ) ) );
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
    }

    /**
     * The filter keeps the core items, and removes them again once it allows.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_filter_keeps_the_core_items_both_ways() {
        $removed = 0;

        Functions\when( 'remove_submenu_page' )->alias(
            function () use ( &$removed ) {
                ++$removed;

                return true;
            }
        );

        Filters\expectApplied( 'licensio_kb_use_content_manager' )->twice()->with( true )->andReturn( false, true );

        $manager = $this->active();

        $manager->remove_core_menu_items();

        $this->assertSame( 0, $removed, 'The filter returning false keeps the core items.' );

        $manager->remove_core_menu_items();

        $this->assertSame( 3, $removed, 'Without the filter the core items are removed.' );
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
            'https://example.test/wp-admin/admin.php?page=licensio-kb-articles&status=draft&search=reset%20password&category=12&tag=5&paged=3',
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
     * The article list's author, sort and page go to the Articles app.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_article_list_redirects_with_its_author_sort_and_page() {
        $manager = new Licensio_KB_Content_Manager( $this->settings() );

        $this->assertSame(
            'https://example.test/wp-admin/admin.php?page=licensio-kb-articles&author=3&orderby=title&order=asc&paged=2',
            $manager->redirect_url(
                'edit.php',
                array(
                    'post_type' => 'licensio_article',
                    'author'    => '3',
                    'orderby'   => 'title',
                    'order'     => 'ASC',
                    'paged'     => '2',
                )
            )
        );
    }

    /**
     * An author, sort or page the app would not read is dropped.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_unusable_author_sort_or_page_is_dropped() {
        $manager = new Licensio_KB_Content_Manager( $this->settings() );

        $this->assertSame(
            'https://example.test/wp-admin/admin.php?page=licensio-kb-articles',
            $manager->redirect_url(
                'edit.php',
                array(
                    'post_type' => 'licensio_article',
                    'author'    => 'abc',
                    'orderby'   => 'comment_count',
                    'order'     => 'sideways',
                    'paged'     => '1',
                )
            )
        );

        $this->assertSame(
            'https://example.test/wp-admin/admin.php?page=licensio-kb-articles',
            $manager->redirect_url(
                'edit.php',
                array(
                    'post_type' => 'licensio_article',
                    'author'    => '-3',
                    'orderby'   => array( 'title' ),
                    'order'     => array( 'asc' ),
                    'paged'     => '-4',
                )
            ),
            'Zero, negative and non-scalar values are dropped.'
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

    /**
     * Run maybe_redirect() for a GET request and report where it went.
     *
     * `wp_safe_redirect()` records the target and throws instead of
     * redirecting, so the `exit` after it never runs.
     *
     * @since 1.0.0
     *
     * @param Licensio_KB_Content_Manager $manager The content manager.
     * @param string                      $screen  The admin file being loaded.
     * @param array<string, mixed>        $query   The query arguments.
     *
     * @return string The redirect target, or an empty string when nothing redirected.
     */
    private function redirect_for( Licensio_KB_Content_Manager $manager, $screen, array $query ) {
        global $pagenow;

        $pagenow                   = $screen; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The screen under test.
        $_GET                      = $query;
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $target = '';

        Functions\when( 'wp_safe_redirect' )->alias(
            function ( $location ) use ( &$target ) {
                $target = $location;

                throw new UnexpectedValueException( 'Redirected.' );
            }
        );

        try {
            $manager->maybe_redirect();
        } catch ( UnexpectedValueException $redirect ) {
            unset( $redirect );
        } finally {
            $_GET = array();
            unset( $_SERVER['REQUEST_METHOD'] );
        }

        return $target;
    }

    /**
     * Every core list and term screen redirects to its app with its filters.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_each_core_screen_redirects_to_its_app() {
        Functions\when( 'get_term_by' )->alias(
            function ( $field, $value, $taxonomy ) {
                if ( 'getting-started' === $value && 'licensio_category' === $taxonomy ) {
                    return new WP_Term( array( 'term_id' => 12 ) );
                }

                if ( 'how-to' === $value && 'licensio_tag' === $taxonomy ) {
                    return new WP_Term( array( 'term_id' => 5 ) );
                }

                return false;
            }
        );

        $base    = 'https://example.test/wp-admin/admin.php?page=';
        $manager = $this->active();

        $this->assertSame(
            $base . 'licensio-kb-articles&status=pending&search=sso&category=12&tag=5',
            $this->redirect_for(
                $manager,
                'edit.php',
                array(
                    'post_type'         => 'licensio_article',
                    'post_status'       => 'pending',
                    's'                 => 'sso',
                    'licensio_category' => 'getting-started',
                    'licensio_tag'      => 'how-to',
                )
            )
        );
        $this->assertSame(
            $base . 'licensio-kb-categories&search=billing',
            $this->redirect_for(
                $manager,
                'edit-tags.php',
                array(
                    'taxonomy' => 'licensio_category',
                    's'        => 'billing',
                )
            )
        );
        $this->assertSame(
            $base . 'licensio-kb-tags',
            $this->redirect_for( $manager, 'edit-tags.php', array( 'taxonomy' => 'licensio_tag' ) )
        );
        $this->assertSame(
            $base . 'licensio-kb-categories&term=42',
            $this->redirect_for(
                $manager,
                'term.php',
                array(
                    'taxonomy' => 'licensio_category',
                    'tag_ID'   => '42',
                )
            )
        );
        $this->assertSame(
            $base . 'licensio-kb-tags&term=9',
            $this->redirect_for(
                $manager,
                'term.php',
                array(
                    'taxonomy' => 'licensio_tag',
                    'tag_ID'   => '9',
                )
            )
        );
    }

    /**
     * The editor, actions, other post types and other taxonomies stay on core.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_editor_and_foreign_screens_are_not_redirected() {
        $manager = $this->active();

        $this->assertSame(
            '',
            $this->redirect_for(
                $manager,
                'post.php',
                array(
                    'post'   => '4',
                    'action' => 'edit',
                )
            )
        );
        $this->assertSame( '', $this->redirect_for( $manager, 'post-new.php', array( 'post_type' => 'licensio_article' ) ) );
        $this->assertSame(
            '',
            $this->redirect_for(
                $manager,
                'edit.php',
                array(
                    'post_type' => 'licensio_article',
                    'action'    => 'trash',
                )
            )
        );
        $this->assertSame( '', $this->redirect_for( $manager, 'edit.php', array( 'post_type' => 'post' ) ) );
        $this->assertSame( '', $this->redirect_for( $manager, 'edit.php', array() ) );
        $this->assertSame( '', $this->redirect_for( $manager, 'edit-tags.php', array( 'taxonomy' => 'category' ) ) );
        $this->assertSame(
            '',
            $this->redirect_for(
                $manager,
                'term.php',
                array(
                    'taxonomy' => 'post_tag',
                    'tag_ID'   => '3',
                )
            )
        );
    }

    /**
     * The classic screens setting stops every redirect.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_classic_screens_setting_stops_the_redirects() {
        $this->assertSame( '', $this->redirect_for( $this->active( true ), 'edit.php', array( 'post_type' => 'licensio_article' ) ) );
        $this->assertSame( '', $this->redirect_for( $this->active( true ), 'edit-tags.php', array( 'taxonomy' => 'licensio_tag' ) ) );
    }

    /**
     * The filter stops the redirects, and they come back once it allows them.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_filter_stops_the_redirects_both_ways() {
        Filters\expectApplied( 'licensio_kb_use_content_manager' )->twice()->with( true )->andReturn( false, true );

        $manager = $this->active();

        $this->assertSame( '', $this->redirect_for( $manager, 'edit.php', array( 'post_type' => 'licensio_article' ) ) );
        $this->assertSame(
            'https://example.test/wp-admin/admin.php?page=licensio-kb-articles',
            $this->redirect_for( $manager, 'edit.php', array( 'post_type' => 'licensio_article' ) )
        );
    }

    /**
     * Record add_submenu_page() calls in `$submenus`.
     *
     * @since 1.0.0
     *
     * @return void
     */
    private function record_submenus() {
        $this->submenus = array();

        Functions\when( 'add_submenu_page' )->alias(
            function ( ...$args ) {
                $this->submenus[] = $args;

                return 'admin_page_' . $args[4];
            }
        );
    }

    /**
     * An app page.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed> The page definition.
     */
    private function app_page() {
        return array(
            'menu_slug'        => Licensio_KB_Content_Manager::ARTICLES_SLUG,
            'menu_title'       => 'All Articles',
            'page_title'       => 'Articles',
            'callback'         => '__return_true',
            'position'         => 1,
            'submenu_position' => 1,
        );
    }

    /**
     * While enabled an app page goes into the menu at its submenu position.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_app_page_is_in_the_menu_when_enabled() {
        $this->record_submenus();

        $suffix = $this->active()->register_page( $this->app_page() );

        $this->assertSame( 'admin_page_licensio-kb-articles', $suffix );
        $this->assertCount( 1, $this->submenus );
        $this->assertSame( Licensio_KB_Admin_Menu::PARENT_SLUG, $this->submenus[0][0] );
        $this->assertSame( 'Articles', $this->submenus[0][1] );
        $this->assertSame( 'All Articles', $this->submenus[0][2] );
        $this->assertSame( Licensio_KB_Capabilities::CAPABILITY, $this->submenus[0][3] );
        $this->assertSame( 'licensio-kb-articles', $this->submenus[0][4] );
        $this->assertSame( 1, $this->submenus[0][6] );
    }

    /**
     * With the classic screens an app page is hidden, so it stays reachable.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_app_page_is_hidden_with_the_classic_screens() {
        $this->record_submenus();

        $this->active( true )->register_page( $this->app_page() );

        $this->assertCount( 1, $this->submenus );
        $this->assertSame( Licensio_KB_Content_Manager::HIDDEN_PARENT, $this->submenus[0][0] );
        $this->assertSame( 'licensio-kb-articles', $this->submenus[0][4] );
    }

    /**
     * The filter hides an app page too.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_app_page_is_hidden_when_the_filter_says_so() {
        Filters\expectApplied( 'licensio_kb_use_content_manager' )->once()->andReturn( false );

        $this->record_submenus();

        $this->active()->register_page( $this->app_page() );

        $this->assertSame( Licensio_KB_Content_Manager::HIDDEN_PARENT, $this->submenus[0][0] );
    }

    /**
     * An unusable or refused app page registers nothing in the menu.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_unusable_app_page_is_not_registered() {
        Functions\expect( 'add_submenu_page' )->once()->andReturn( false );

        $this->assertSame( '', $this->active()->register_page( array( 'menu_slug' => 'no-callback' ) ) );
        $this->assertSame( '', $this->active()->register_page( $this->app_page() ) );
    }

    /**
     * On an app page the app's menu item is the current one, unless the
     * classic screens are in use.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_app_page_is_the_current_submenu_item() {
        global $plugin_page;

        $plugin_page = Licensio_KB_Content_Manager::TAGS_SLUG; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The page under test.

        try {
            $this->assertSame( 'licensio-kb-tags', $this->active()->current_submenu_file( null ) );
            $this->assertSame( 'core.php', $this->active( true )->current_submenu_file( 'core.php' ) );

            $plugin_page = 'licensio-kb-settings'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Another plugin page.

            $this->assertSame( 'core.php', $this->active()->current_submenu_file( 'core.php' ) );

            $plugin_page = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- A core screen.

            $this->assertNull( $this->active()->current_submenu_file( null ) );
        } finally {
            $plugin_page = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Reset.
        }
    }

    /**
     * On the article editor the Articles app stands in for the removed
     * core list item, while Add New keeps its own item.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_article_editor_marks_the_articles_app_as_current() {
        global $plugin_page;

        $plugin_page = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- A core screen.

        try {
            $this->assertSame( 'licensio-kb-articles', $this->active()->current_submenu_file( 'edit.php?post_type=licensio_article' ) );
            $this->assertSame( 'post-new.php?post_type=licensio_article', $this->active()->current_submenu_file( 'post-new.php?post_type=licensio_article' ) );
            $this->assertSame( 'edit.php?post_type=page', $this->active()->current_submenu_file( 'edit.php?post_type=page' ) );
            $this->assertSame(
                'edit.php?post_type=licensio_article',
                $this->active( true )->current_submenu_file( 'edit.php?post_type=licensio_article' ),
                'With the classic screens the core list item is still there.'
            );
        } finally {
            $plugin_page = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Reset.
        }
    }
}
