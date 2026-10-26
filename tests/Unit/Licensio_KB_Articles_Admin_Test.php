<?php
/**
 * Unit tests for the Articles app page.
 *
 * What this class owes the rest of the plugin: a hidden page registered
 * through the content manager, a bundle loaded on that page only with the
 * script translations it needs, its REST controller announced, and the
 * scaffold's `/articles` stub released so the controller owns the route.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

use Brain\Monkey\Functions;

/**
 * Tests Licensio_KB_Articles_Admin.
 *
 * @since 1.0.0
 */
class Licensio_KB_Articles_Admin_Test extends Licensio_KB_Test_Case {

    /**
     * The page under test.
     *
     * @since 1.0.0
     * @var Licensio_KB_Articles_Admin
     */
    private $admin;

    /**
     * The submenu pages that were registered.
     *
     * @since 1.0.0
     * @var array<int, array<int, mixed>>
     */
    private $submenus = array();

    /**
     * Whether the classic screens setting is on.
     *
     * @since 1.0.0
     * @var bool
     */
    private $classic = false;

    /**
     * The script dependencies the page asked for, keyed by script handle.
     *
     * Filled by the asset loader double, which records the dependencies that
     * Licensio_KB_Recording_Assets drops.
     *
     * @since 1.0.0
     * @var ArrayObject<string, string[]>
     */
    private $enqueued;

    /**
     * Stub the WordPress surface the page touches.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        $this->submenus = array();
        $this->enqueued = new ArrayObject();
        $this->classic  = false;

        $assets = new class( $this->enqueued ) extends Licensio_KB_Assets {

            /**
             * The dependencies requested, keyed by script handle.
             *
             * @since 1.0.0
             * @var ArrayObject<string, string[]>
             */
            private $deps;

            /**
             * Keep the store the dependencies are recorded in.
             *
             * @since 1.0.0
             *
             * @param ArrayObject<string, string[]> $deps The dependency store.
             */
            public function __construct( $deps ) {
                $this->deps = $deps;
            }

            /**
             * Record an enqueue instead of performing one.
             *
             * @since 1.0.0
             *
             * @param string   $handle The script handle.
             * @param string   $entry  The Vite entry, relative to the plugin root.
             * @param string[] $deps   The script dependencies.
             *
             * @return bool Always true, so the page carries on to localise.
             */
            public function enqueue_app( $handle, $entry, $deps = array() ) {
                unset( $entry );

                $this->deps[ (string) $handle ] = (array) $deps;

                return true;
            }
        };

        $plugin = new class( $assets, array( $this, 'content_manager' ) ) {

            /**
             * The asset loader handed out.
             *
             * @since 1.0.0
             * @var Licensio_KB_Assets
             */
            private $assets;

            /**
             * Builds the content manager handed out.
             *
             * @since 1.0.0
             * @var callable
             */
            private $content_manager;

            /**
             * Keep the asset loader and the content manager factory.
             *
             * @since 1.0.0
             *
             * @param Licensio_KB_Assets $assets          The asset loader.
             * @param callable           $content_manager Builds the content manager.
             */
            public function __construct( $assets, callable $content_manager ) {
                $this->assets          = $assets;
                $this->content_manager = $content_manager;
            }

            /**
             * The content manager.
             *
             * @since 1.0.0
             *
             * @return Licensio_KB_Content_Manager The content manager.
             */
            public function content_manager() {
                return call_user_func( $this->content_manager );
            }

            /**
             * The asset loader.
             *
             * @since 1.0.0
             *
             * @return Licensio_KB_Assets The asset loader.
             */
            public function assets() {
                return $this->assets;
            }

            /**
             * The settings, read from the test's option store.
             *
             * @since 1.0.0
             *
             * @return Licensio_KB_Settings The settings.
             */
            public function settings() {
                return new Licensio_KB_Settings();
            }
        };

        Functions\when( 'licensio_kb' )->justReturn( $plugin );
        Functions\when( 'add_submenu_page' )->alias(
            function ( ...$args ) {
                $this->submenus[] = $args;

                return 'admin_page_' . $args[4];
            }
        );
        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\when( 'get_post_type_object' )->justReturn(
            (object) array(
                'cap' => (object) Licensio_KB_Capabilities::post_type_capabilities(),
            )
        );
        Functions\when( 'get_taxonomy' )->justReturn(
            (object) array(
                'cap' => (object) Licensio_KB_Capabilities::taxonomy_capabilities(),
            )
        );
        Functions\when( 'wp_create_nonce' )->justReturn( 'a-nonce' );
        Functions\when( 'esc_url_raw' )->returnArg();
        Functions\when( 'get_current_user_id' )->justReturn( 1 );
        Functions\when( 'get_user_meta' )->justReturn( '' );
        Functions\when( 'admin_url' )->alias(
            function ( $path = '' ) {
                return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' );
            }
        );
        Functions\when( 'rest_url' )->alias(
            function ( $path = '' ) {
                return 'https://example.test/wp-json/' . ltrim( (string) $path, '/' );
            }
        );

        $this->admin = new Licensio_KB_Articles_Admin();
    }

    /**
     * The page registers its hooks, including the stub release.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_page_registers_its_hooks() {
        $this->admin->init_hooks();

        $this->assertSame( 10, has_filter( 'licensio_rest_controllers', array( $this->admin, 'register_rest_controller' ) ) );
        $this->assertSame( 10, has_filter( 'licensio_rest_stub_routes', array( $this->admin, 'release_stub_route' ) ) );
        $this->assertSame( 10, has_action( 'admin_menu', array( $this->admin, 'register_page' ) ) );
        $this->assertSame( 10, has_action( 'admin_enqueue_scripts', array( $this->admin, 'enqueue_app' ) ) );
    }

    /**
     * The articles controller is added to the registrar's list.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_rest_controller_is_announced() {
        $this->assertSame(
            array( 'Licensio_KB_Other_Controller', 'Licensio_KB_Articles_REST_Controller' ),
            $this->admin->register_rest_controller( array( 'Licensio_KB_Other_Controller' ) )
        );
    }

    /**
     * The `/articles` stub is released, and nothing else.
     *
     * The articles controller registers the real, manager-only route. Left in
     * the stub list, the 501 stub would add a second, public GET handler for
     * the same route to the REST index.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_articles_stub_route_is_released() {
        $routes = $this->admin->release_stub_route(
            array(
                '/articles'           => array( 'methods' => 'GET' ),
                '/analytics/articles' => array( 'methods' => 'GET' ),
                '/categories'         => array( 'methods' => 'GET' ),
            )
        );

        $this->assertArrayNotHasKey( '/articles', $routes );
        $this->assertArrayHasKey( '/analytics/articles', $routes, 'Only the articles route may be released.' );
        $this->assertArrayHasKey( '/categories', $routes, 'Only the articles route may be released.' );
    }

    /**
     * The scaffold really does reserve `/articles`, so releasing it means something.
     *
     * Without this, the release test above would keep passing if the stub
     * table ever renamed the route, and the plugin would quietly keep a public
     * stub beside the real controller.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_scaffold_reserves_the_articles_route() {
        $reserved = ( new Licensio_KB_REST_Stubs_Controller() )->reserved_routes();

        $this->assertArrayHasKey( '/articles', $reserved );

        $released = $this->admin->release_stub_route( $reserved );

        $this->assertArrayNotHasKey( '/articles', $released );
        $this->assertCount( count( $reserved ) - 1, $released, 'The articles page owns no other reserved route.' );
    }

    /**
     * A non-array filter value becomes an empty route list.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_broken_stub_list_is_replaced_with_an_empty_one() {
        $this->assertSame( array(), $this->admin->release_stub_route( null ) );
    }

    /**
     * The page is the menu's first item, gated on the article capability.
     *
     * Authors and contributors reach it as well as managers.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_page_is_first_in_the_menu() {
        $this->admin->register_page();

        $this->assertCount( 1, $this->submenus );

        $page = $this->submenus[0];

        $this->assertSame( Licensio_KB_Admin_Menu::PARENT_SLUG, $page[0] );
        $this->assertSame( 'All Articles', $page[2] );
        $this->assertSame( 'edit_licensio_articles', $page[3] );
        $this->assertSame( Licensio_KB_Content_Manager::ARTICLES_SLUG, $page[4] );
        $this->assertSame( array( $this->admin, 'render_page' ), $page[5] );
        $this->assertSame( 1, $page[6], 'Position 1 puts it before Add New.' );
        $this->assertSame( 'admin_page_licensio-kb-articles', $this->admin->hook_suffix() );
    }

    /**
     * With the classic screens the page is hidden but still registered.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_page_is_hidden_with_the_classic_screens() {
        $this->classic = true;

        $this->admin->register_page();

        $this->assertCount( 1, $this->submenus );
        $this->assertSame( Licensio_KB_Content_Manager::HIDDEN_PARENT, $this->submenus[0][0] );
        $this->assertSame( Licensio_KB_Content_Manager::ARTICLES_SLUG, $this->submenus[0][4] );
        $this->assertSame( 'admin_page_licensio-kb-articles', $this->admin->hook_suffix() );
    }

    /**
     * The app is not enqueued on any other screen.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_app_is_not_enqueued_on_another_screen() {
        Functions\expect( 'wp_set_script_translations' )->never();
        Functions\expect( 'wp_localize_script' )->never();

        $this->admin->register_page();
        $this->admin->enqueue_app( 'edit.php' );

        $this->assertCount( 0, $this->enqueued );
    }

    /**
     * Nothing is enqueued before the page has been registered.
     *
     * An empty hook suffix must not match an empty screen name.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_nothing_is_enqueued_before_the_page_exists() {
        Functions\expect( 'wp_localize_script' )->never();

        $this->admin->enqueue_app( '' );

        $this->assertCount( 0, $this->enqueued );
    }

    /**
     * A user without the capability gets nothing enqueued.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_nothing_is_enqueued_without_the_capability() {
        Functions\when( 'current_user_can' )->justReturn( false );
        Functions\expect( 'wp_localize_script' )->never();

        $this->admin->register_page();
        $this->admin->enqueue_app( 'admin_page_licensio-kb-articles' );

        $this->assertCount( 0, $this->enqueued );
    }

    /**
     * On its own page the app loads with wp-i18n, wp-a11y and its translations.
     *
     * The app translates its strings through the page's wp.i18n, so without
     * the dependency and the script translations it shows English only. The
     * Undo toast announces the change through wp.a11y, so without it screen
     * reader users are not told what changed.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_app_is_enqueued_with_its_translations() {
        Functions\expect( 'wp_set_script_translations' )
            ->once()
            ->with( Licensio_KB_Articles_Admin::APP_HANDLE, 'licensio-knowledge-base' );
        Functions\expect( 'wp_localize_script' )
            ->once()
            ->with( Licensio_KB_Articles_Admin::APP_HANDLE, 'licensioKbArticles', Mockery::type( 'array' ) );

        $this->admin->register_page();
        $this->admin->enqueue_app( 'admin_page_licensio-kb-articles' );

        $this->assertArrayHasKey( Licensio_KB_Articles_Admin::APP_HANDLE, $this->enqueued );
        $this->assertContains( 'wp-i18n', $this->enqueued[ Licensio_KB_Articles_Admin::APP_HANDLE ] );
        $this->assertContains( 'wp-a11y', $this->enqueued[ Licensio_KB_Articles_Admin::APP_HANDLE ] );
    }

    /**
     * A content manager whose classic screens setting is `$classic`.
     *
     * @since 1.0.0
     *
     * @return Licensio_KB_Content_Manager The content manager.
     */
    public function content_manager() {
        return new Licensio_KB_Content_Manager(
            new Licensio_KB_Settings_Spy(
                array(
                    'classic_screens' => array(
                        'type'    => 'bool',
                        'default' => false,
                    ),
                ),
                array( 'classic_screens' => $this->classic )
            )
        );
    }

    /**
     * The app is told what the user may do, and links they cannot open are left out.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_app_data_carries_what_the_user_may_do() {
        Functions\when( 'current_user_can' )->alias(
            function ( $capability ) {
                return in_array( $capability, array( 'edit_licensio_articles', 'publish_licensio_articles' ), true );
            }
        );

        $data = $this->admin->app_data();

        $this->assertSame(
            array(
                'editOthers' => false,
                'publish'    => true,
                'createTags' => false,
            ),
            $data['can']
        );
        $this->assertSame( '', $data['settingsUrl'], 'Authors cannot open Settings.' );
        $this->assertSame( '', $data['feedbackUrl'], 'Authors cannot open Feedback.' );
    }

    /**
     * A manager gets every flag and both links.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_manager_may_do_everything() {
        $data = $this->admin->app_data();

        $this->assertSame( array( true ), array_values( array_unique( $data['can'] ) ) );
        $this->assertNotSame( '', $data['settingsUrl'] );
        $this->assertNotSame( '', $data['feedbackUrl'] );
    }
}
