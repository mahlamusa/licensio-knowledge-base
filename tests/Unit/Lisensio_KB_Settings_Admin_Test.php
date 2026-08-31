<?php
/**
 * The settings screen and its REST registration.
 *
 * The screen owns no menu code of its own: it asks Lisensio_KB_Admin_Menu for a
 * page through `lisensio_admin_menu_pages`, so what is worth proving is that it
 * asks correctly, that it loads its bundle on its own screen and nowhere else,
 * and that the REST route is announced independently of wp-admin.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

/**
 * Drives Lisensio_KB_Settings_Admin and Lisensio_KB_Settings_API.
 *
 * @since 1.0.0
 */
class Lisensio_KB_Settings_Admin_Test extends Lisensio_KB_Test_Case {

    /**
     * The hook suffix WordPress hands the settings screen.
     *
     * @since 1.0.0
     * @var string
     */
    const HOOK_SUFFIX = 'knowledge-base_page_lisensio-kb-settings';

    /**
     * Boot the environment.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        Lisensio_KB_Admin_Menu::reset();

        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\when( 'wp_create_nonce' )->justReturn( 'a-nonce' );
        Functions\when( 'wp_localize_script' )->justReturn( true );
        Functions\when( 'wp_set_script_translations' )->justReturn( true );
        Functions\when( 'esc_url_raw' )->returnArg();
        Functions\when( 'rest_url' )->alias(
            function ( $path = '' ) {
                return 'https://example.test/wp-json/' . ltrim( (string) $path, '/' );
            }
        );
    }

    /**
     * The page is registered through the shared menu seam.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_page_is_registered_through_the_shared_menu_filter() {
        $admin = new Lisensio_KB_Settings_Admin( new Lisensio_KB_Recording_Assets() );

        $admin->init_hooks();

        $this->assertNotFalse(
            has_filter( 'lisensio_admin_menu_pages', array( $admin, 'register_page' ) )
        );
        $this->assertNotFalse(
            has_action( 'admin_enqueue_scripts', array( $admin, 'enqueue' ) )
        );
    }

    /**
     * The screen never registers a menu page itself.
     *
     * The menu seam owns the parent slug, the capability default and the
     * ordering. A screen that called add_submenu_page() directly would take a
     * second copy of all three, which is what the seam exists to prevent.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_screen_never_calls_add_submenu_page_itself() {
        Functions\expect( 'add_submenu_page' )->never();

        $admin = new Lisensio_KB_Settings_Admin( new Lisensio_KB_Recording_Assets() );

        $admin->init_hooks();
        $admin->register_page( array() );

        $this->assertFalse( has_action( 'admin_menu', array( $admin, 'register_page' ) ) );
    }

    /**
     * The registered page is well formed for the menu seam.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_registered_page_is_well_formed() {
        $admin = new Lisensio_KB_Settings_Admin( new Lisensio_KB_Recording_Assets() );

        $pages = $admin->register_page( array() );

        $this->assertCount( 1, $pages );
        $this->assertSame( Lisensio_KB_Settings_Admin::MENU_SLUG, $pages[0]['menu_slug'] );
        $this->assertSame( array( $admin, 'render' ), $pages[0]['callback'] );
        $this->assertMatchesRegularExpression( '/^[a-z0-9_-]+$/', $pages[0]['menu_slug'] );
        $this->assertIsInt( $pages[0]['position'] );
    }

    /**
     * The page is preserved when it arrives beside another consumer's.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_page_is_added_beside_the_analytics_page() {
        $assets    = new Lisensio_KB_Recording_Assets();
        $analytics = new Lisensio_KB_Analytics_Admin( $assets );
        $settings  = new Lisensio_KB_Settings_Admin( $assets );

        $pages = $settings->register_page( $analytics->register_page( array() ) );

        $this->assertCount( 2, $pages );
        $this->assertSame( Lisensio_KB_Analytics_Admin::MENU_SLUG, $pages[0]['menu_slug'] );
        $this->assertSame( Lisensio_KB_Settings_Admin::MENU_SLUG, $pages[1]['menu_slug'] );
        $this->assertGreaterThan( $pages[0]['position'], $pages[1]['position'] );
    }

    /**
     * The bundle loads on the settings screen.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_bundle_loads_on_its_own_screen() {
        $assets = new Lisensio_KB_Recording_Assets();
        $admin  = new Lisensio_KB_Settings_Admin( $assets );

        $this->register_menu( $admin );

        $admin->enqueue( self::HOOK_SUFFIX );

        $this->assertSame(
            array( Lisensio_KB_Settings_Admin::SCRIPT_HANDLE => Lisensio_KB_Settings_Admin::APP_ENTRY ),
            $assets->recorded
        );
    }

    /**
     * The bundle does not load on any other screen.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_bundle_does_not_load_elsewhere() {
        $assets = new Lisensio_KB_Recording_Assets();
        $admin  = new Lisensio_KB_Settings_Admin( $assets );

        $this->register_menu( $admin );

        $admin->enqueue( 'edit.php' );

        $this->assertSame( array(), $assets->recorded );
    }

    /**
     * Nothing is enqueued before the menu has been registered.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_nothing_is_enqueued_before_the_menu_exists() {
        $assets = new Lisensio_KB_Recording_Assets();

        ( new Lisensio_KB_Settings_Admin( $assets ) )->enqueue( '' );

        $this->assertSame( array(), $assets->recorded );
    }

    /**
     * A user without the capability gets nothing enqueued.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_user_without_the_capability_gets_no_bundle() {
        $assets = new Lisensio_KB_Recording_Assets();
        $admin  = new Lisensio_KB_Settings_Admin( $assets );

        $this->register_menu( $admin );

        Functions\when( 'current_user_can' )->justReturn( false );

        $admin->enqueue( self::HOOK_SUFFIX );

        $this->assertSame( array(), $assets->recorded );
    }

    /**
     * The screen prints the mount point the app takes over.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_screen_prints_a_mount_point() {
        $admin = new Lisensio_KB_Settings_Admin( new Lisensio_KB_Recording_Assets() );

        ob_start();
        $admin->render();
        $markup = (string) ob_get_clean();

        $this->assertStringContainsString( 'id="' . Lisensio_KB_Settings_Admin::SCRIPT_HANDLE . '"', $markup );
    }

    /**
     * A user without the capability cannot open the screen.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_user_without_the_capability_cannot_open_the_screen() {
        Functions\when( 'current_user_can' )->justReturn( false );

        /*
         * Real wp_die() never returns. A double that did would let the screen
         * carry on and print its mount point, and an assertion about what was
         * printed would then be measuring the double rather than the guard.
         */
        Functions\when( 'wp_die' )->alias(
            function ( $message ) {
                unset( $message );

                throw new RuntimeException( 'wp_die' );
            }
        );

        $admin = new Lisensio_KB_Settings_Admin( new Lisensio_KB_Recording_Assets() );

        $this->expectException( 'RuntimeException' );

        ob_start();

        try {
            $admin->render();
        } finally {
            $markup = (string) ob_get_clean();

            $this->assertStringNotContainsString( 'id="' . Lisensio_KB_Settings_Admin::SCRIPT_HANDLE . '"', $markup );
        }
    }

    /**
     * The app is handed the settings endpoint and a REST nonce.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_app_is_handed_the_endpoint_and_a_nonce() {
        $data = ( new Lisensio_KB_Settings_Admin( new Lisensio_KB_Recording_Assets() ) )->app_data();

        $this->assertSame( 'https://example.test/wp-json/lisensio-kb/v1/settings', $data['restUrl'] );
        $this->assertSame( 'a-nonce', $data['nonce'] );
    }

    /**
     * The controller is announced to the REST registrar.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_controller_is_announced_to_the_registrar() {
        $this->assertSame(
            array( 'Lisensio_KB_REST_Settings_Controller' ),
            ( new Lisensio_KB_Settings_API() )->register_rest_controller( array() )
        );
    }

    /**
     * The settings stub is released, and only the settings stub.
     *
     * Left in place, the scaffold's 501 stub is registered against the same
     * route; released too eagerly, a sibling issue's route disappears.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_only_the_settings_stub_is_released() {
        $stubs = ( new Lisensio_KB_REST_Stubs_Controller() )->reserved_routes();

        $this->assertArrayHasKey( '/settings', $stubs );

        $released = ( new Lisensio_KB_Settings_API() )->release_stub_route( $stubs );

        $this->assertArrayNotHasKey( '/settings', $released );
        $this->assertSame(
            array( '/settings' ),
            array_values( array_diff( array_keys( $stubs ), array_keys( $released ) ) )
        );
    }

    /**
     * Both REST hooks are registered.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_rest_hooks_are_registered() {
        $api = new Lisensio_KB_Settings_API();

        $api->init_hooks();

        $this->assertNotFalse( has_filter( 'lisensio_rest_controllers', array( $api, 'register_rest_controller' ) ) );
        $this->assertNotFalse( has_filter( 'lisensio_rest_stub_routes', array( $api, 'release_stub_route' ) ) );
    }

    /**
     * Register the menu so the screen learns its own hook suffix.
     *
     * Brain Monkey does not run `add_filter` callbacks on `apply_filters`, so
     * the menu's own filter is answered directly rather than by registering the
     * screen's callback and hoping it is called.
     *
     * @since 1.0.0
     *
     * @param Lisensio_KB_Settings_Admin $admin The screen.
     *
     * @return void
     */
    private function register_menu( Lisensio_KB_Settings_Admin $admin ) {
        Functions\when( 'add_submenu_page' )->justReturn( self::HOOK_SUFFIX );

        Filters\expectApplied( 'lisensio_admin_menu_pages' )
            ->once()
            ->andReturn( $admin->register_page( array() ) );

        ( new Lisensio_KB_Admin_Menu() )->register_pages();
    }
}
