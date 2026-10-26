<?php
/**
 * The Categories app page and its REST registration.
 *
 * What the page owes the rest of the plugin: the categories controller in the
 * REST registrar, the `/categories` stub released, a menu page (hidden with
 * the classic screens), and the app
 * with the media modal loaded on that page and nowhere else.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

use Brain\Monkey\Functions;

/**
 * Drives Licensio_KB_Categories_Admin.
 *
 * @since 1.0.0
 */
class Licensio_KB_Categories_Admin_Test extends Licensio_KB_Test_Case {

    /**
     * The hook suffix WordPress hands a page under `options.php`.
     *
     * @since 1.0.0
     * @var string
     */
    const HOOK_SUFFIX = 'admin_page_licensio-kb-categories';

    /**
     * The submenu pages that were registered, as their argument lists.
     *
     * @since 1.0.0
     * @var array<int, array<int, mixed>>
     */
    private $submenus = array();

    /**
     * The wp_localize_script() calls, as their argument lists.
     *
     * @since 1.0.0
     * @var array<int, array<int, mixed>>
     */
    private $localized = array();

    /**
     * Whether the current user holds the management capability.
     *
     * @since 1.0.0
     * @var bool
     */
    private $can_manage = true;

    /**
     * Whether the classic screens setting is on.
     *
     * @since 1.0.0
     * @var bool
     */
    private $classic = false;

    /**
     * The apps the page asked to load, keyed by handle.
     *
     * @since 1.0.0
     * @var array<string, array{entry: string, deps: string[]}>
     */
    private $apps = array();

    /**
     * The asset loader double, recording each app with its dependencies.
     *
     * @since 1.0.0
     * @var Licensio_KB_Assets
     */
    private $assets;

    /**
     * The page under test.
     *
     * @since 1.0.0
     * @var Licensio_KB_Categories_Admin
     */
    private $admin;

    /**
     * Stub the WordPress surface the page touches.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        $this->submenus   = array();
        $this->localized  = array();
        $this->can_manage = true;
        $this->classic    = false;
        $this->apps       = array();

        Functions\when( 'add_submenu_page' )->alias(
            function ( ...$args ) {
                $this->submenus[] = $args;

                return self::HOOK_SUFFIX;
            }
        );
        Functions\when( 'current_user_can' )->alias(
            function ( $cap ) {
                return Licensio_KB_Capabilities::CAPABILITY === $cap && $this->can_manage;
            }
        );
        Functions\when( 'wp_localize_script' )->alias(
            function ( ...$args ) {
                $this->localized[] = $args;

                return true;
            }
        );
        Functions\when( 'licensio_kb' )->justReturn( $this );
        Functions\when( 'wp_create_nonce' )->justReturn( 'a-nonce' );
        Functions\when( 'esc_url_raw' )->returnArg();
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
        Functions\when( 'wp_die' )->alias(
            function ( $message ) {
                throw new RuntimeException( esc_html( (string) $message ) );
            }
        );

        $this->assets = new class(
            function ( $handle, $entry, $deps ) {
                $this->apps[ $handle ] = array(
                    'entry' => $entry,
                    'deps'  => $deps,
                );
            }
        ) extends Licensio_KB_Assets {

            /**
             * Called with each app the page asks for.
             *
             * @since 1.0.0
             * @var callable
             */
            private $recorder;

            /**
             * Build the double.
             *
             * @since 1.0.0
             *
             * @param callable $recorder Called with the handle, the entry and the dependencies.
             */
            public function __construct( callable $recorder ) {
                $this->recorder = $recorder;
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
                call_user_func( $this->recorder, (string) $handle, (string) $entry, (array) $deps );

                return true;
            }
        };

        $this->admin = new Licensio_KB_Categories_Admin( $this->assets );
    }

    /**
     * The page registers its filters and actions.
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
        $this->assertSame( 10, has_action( 'admin_enqueue_scripts', array( $this->admin, 'enqueue' ) ) );
    }

    /**
     * The categories controller joins the REST registrar's list.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_controller_is_added_to_the_registrar() {
        $this->assertSame(
            array( 'Licensio_KB_Terms_REST_Controller', 'Licensio_KB_Categories_REST_Controller' ),
            $this->admin->register_rest_controller( array( 'Licensio_KB_Terms_REST_Controller' ) )
        );
        $this->assertSame(
            array( 'Licensio_KB_Categories_REST_Controller' ),
            $this->admin->register_rest_controller( 'not a list' ),
            'A broken filter value is replaced, not appended to.'
        );
    }

    /**
     * Only the categories stub is released; the others stay reserved.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_only_the_categories_stub_is_released() {
        $stub = array(
            'methods'    => 'GET',
            'permission' => 'manage_permissions_check',
        );

        $this->assertSame(
            array(
                '/categories/(?P<id>[\d]+)/articles' => $stub,
                '/settings'                          => $stub,
            ),
            $this->admin->release_stub_route(
                array(
                    '/categories'                        => $stub,
                    '/categories/(?P<id>[\d]+)/articles' => $stub,
                    '/settings'                          => $stub,
                )
            )
        );
        $this->assertNull( $this->admin->release_stub_route( null ), 'A value that is not a list is passed on untouched.' );
    }

    /**
     * The page is in the menu after Add New and keeps its hook suffix.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_page_is_in_the_menu() {
        $this->assertSame( '', $this->admin->hook_suffix() );

        $this->admin->register_page();

        $this->assertCount( 1, $this->submenus );
        $this->assertSame( Licensio_KB_Admin_Menu::PARENT_SLUG, $this->submenus[0][0] );
        $this->assertSame( 'Categories', $this->submenus[0][1] );
        $this->assertSame( 'licensio-kb-categories', $this->submenus[0][4] );
        $this->assertSame( 3, $this->submenus[0][6] );
        $this->assertSame( self::HOOK_SUFFIX, $this->admin->hook_suffix() );
    }

    /**
     * With the classic screens the page is hidden under `options.php`.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_page_is_registered_hidden_with_the_classic_screens() {
        $this->classic = true;

        $this->admin->register_page();

        $this->assertCount( 1, $this->submenus );
        $this->assertSame( 'options.php', $this->submenus[0][0] );
        $this->assertSame( 'Categories', $this->submenus[0][1] );
        $this->assertSame( Licensio_KB_Capabilities::CAPABILITY, $this->submenus[0][3] );
        $this->assertSame( 'licensio-kb-categories', $this->submenus[0][4] );
        $this->assertSame( array( $this->admin, 'render_page' ), $this->submenus[0][5] );
        $this->assertSame( self::HOOK_SUFFIX, $this->admin->hook_suffix() );
    }

    /**
     * Nothing loads on another screen, or before the page exists.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_nothing_loads_on_another_screen() {
        Functions\expect( 'wp_enqueue_media' )->never();
        Functions\expect( 'wp_set_script_translations' )->never();

        $this->admin->enqueue( '' );
        $this->admin->enqueue( self::HOOK_SUFFIX );

        $this->admin->register_page();
        $this->admin->enqueue( 'edit.php' );

        $this->assertSame( array(), $this->apps );
        $this->assertSame( array(), $this->localized );
    }

    /**
     * Nothing loads for a user who cannot manage the knowledge base.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_nothing_loads_without_the_capability() {
        Functions\expect( 'wp_enqueue_media' )->never();

        $this->can_manage = false;

        $this->admin->register_page();
        $this->admin->enqueue( self::HOOK_SUFFIX );

        $this->assertSame( array(), $this->apps );
        $this->assertSame( array(), $this->localized );
    }

    /**
     * On its own screen the app loads with the media modal and its configuration.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_app_loads_on_its_own_screen() {
        Functions\expect( 'wp_enqueue_media' )->once();
        Functions\expect( 'wp_set_script_translations' )
            ->once()
            ->with( 'licensio-kb-categories-app', 'licensio-knowledge-base' );

        $this->admin->register_page();
        $this->admin->enqueue( self::HOOK_SUFFIX );

        $this->assertSame(
            array(
                'licensio-kb-categories-app' => array(
                    'entry' => 'apps/categories/index.ts',
                    'deps'  => array( 'wp-i18n' ),
                ),
            ),
            $this->apps
        );
        $this->assertSame(
            array(
                array(
                    'licensio-kb-categories-app',
                    'licensioKbCategories',
                    array(
                        'root'        => 'https://example.test/wp-json/licensio-kb/v1/',
                        'nonce'       => 'a-nonce',
                        'settingsUrl' => 'https://example.test/wp-admin/edit.php?post_type=licensio_article&page=licensio-kb-settings',
                        'articlesUrl' => 'https://example.test/wp-admin/admin.php?page=licensio-kb-articles',
                    ),
                ),
            ),
            $this->localized
        );
    }

    /**
     * The page prints the shell the app mounts on.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_page_prints_the_mount_point() {
        ob_start();
        $this->admin->render_page();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString( 'id="licensio-kb-categories"', $html );
        $this->assertStringContainsString( '<h1 class="lkb-page__title">Categories</h1>', $html );
        $this->assertStringContainsString( 'Loading categories', $html );
    }

    /**
     * A user who cannot manage the knowledge base is turned away.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_page_turns_away_a_user_without_the_capability() {
        $this->can_manage = false;

        $this->expectException( RuntimeException::class );
        $this->expectExceptionMessage( 'You are not allowed to manage the knowledge base.' );

        $this->admin->render_page();
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
}
