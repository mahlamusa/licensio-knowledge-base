<?php
/**
 * The Categories app page.
 *
 * Registers the Categories Vue app on its content manager page
 * (`admin.php?page=licensio-kb-categories`), loads it with the WordPress media
 * modal on that page only, and announces the categories REST controller.
 *
 * While the content manager is enabled the page is in the menu and the core
 * category screens redirect to it; with the classic screens it stays
 * reachable as a hidden page.
 *
 * Constructed on every request, not only in wp-admin, because the REST
 * controller it announces must be registered for REST requests too.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the Categories app page and its REST controller.
 *
 * @since 1.0.0
 */
class Licensio_KB_Categories_Admin {

    /**
     * The handle the app script is registered under.
     *
     * @since 1.0.0
     * @var string
     */
    const APP_HANDLE = 'licensio-kb-categories-app';

    /**
     * The Vite entry the app is built from.
     *
     * @since 1.0.0
     * @var string
     */
    const APP_ENTRY = 'apps/categories/index.ts';

    /**
     * The route this app takes over from the not-implemented stubs.
     *
     * @since 1.0.0
     * @var string
     */
    const STUB_ROUTE = '/categories';

    /**
     * The build asset loader.
     *
     * @since 1.0.0
     * @var Licensio_KB_Assets
     */
    private $assets;

    /**
     * The hook suffix WordPress assigned to the page.
     *
     * @since 1.0.0
     * @var string
     */
    private $hook_suffix = '';

    /**
     * Build the page.
     *
     * @since 1.0.0
     *
     * @param Licensio_KB_Assets $assets The build asset loader.
     */
    public function __construct( Licensio_KB_Assets $assets ) {
        $this->assets = $assets;
    }

    /**
     * Register the WordPress hooks.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function init_hooks() {
        add_filter( 'licensio_rest_controllers', array( $this, 'register_rest_controller' ) );
        add_filter( 'licensio_rest_stub_routes', array( $this, 'release_stub_route' ) );
        add_action( 'admin_menu', array( $this, 'register_page' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
    }

    /**
     * Add the categories controller to the plugin's REST registrar.
     *
     * @since 1.0.0
     *
     * @param string[]|mixed $controllers The registered controller class names.
     *
     * @return string[] The list, with the categories controller added.
     */
    public function register_rest_controller( $controllers ) {
        $controllers   = is_array( $controllers ) ? $controllers : array();
        $controllers[] = 'Licensio_KB_Categories_REST_Controller';

        return $controllers;
    }

    /**
     * Withdraw `GET /categories` from the not-implemented stub table.
     *
     * @since 1.0.0
     *
     * @param array<string, array<string, string>>|mixed $routes The reserved routes.
     *
     * @return array<string, array<string, string>>|mixed The reserved routes, without the categories one.
     */
    public function release_stub_route( $routes ) {
        if ( ! is_array( $routes ) ) {
            return $routes;
        }

        unset( $routes[ self::STUB_ROUTE ] );

        return $routes;
    }

    /**
     * Register the Categories page, after Add New in the menu.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function register_page() {
        $this->hook_suffix = licensio_kb()->content_manager()->register_page(
            array(
                'menu_slug'        => Licensio_KB_Content_Manager::CATEGORIES_SLUG,
                'page_title'       => __( 'Categories', 'licensio-knowledge-base' ),
                'menu_title'       => __( 'Categories', 'licensio-knowledge-base' ),
                'callback'         => array( $this, 'render_page' ),
                'position'         => 3,
                'submenu_position' => 3,
            )
        );
    }

    /**
     * The hook suffix of the Categories page.
     *
     * @since 1.0.0
     *
     * @return string The hook suffix, or an empty string before the page is registered.
     */
    public function hook_suffix() {
        return $this->hook_suffix;
    }

    /**
     * Enqueue the app and the media modal on the Categories page only.
     *
     * @since 1.0.0
     *
     * @param string $hook_suffix The screen being loaded.
     *
     * @return void
     */
    public function enqueue( $hook_suffix ) {
        if ( '' === $this->hook_suffix || $hook_suffix !== $this->hook_suffix ) {
            return;
        }

        if ( ! Licensio_KB_Capabilities::current_user_can_manage() ) {
            return;
        }

        // The icon and image fields pick files through the media modal.
        wp_enqueue_media();

        if ( ! $this->assets->enqueue_app( self::APP_HANDLE, self::APP_ENTRY, array( 'wp-i18n' ) ) ) {
            return;
        }

        wp_set_script_translations( self::APP_HANDLE, 'licensio-knowledge-base' );

        wp_localize_script( self::APP_HANDLE, 'licensioKbCategories', $this->app_data() );
    }

    /**
     * The configuration the app reads.
     *
     * @since 1.0.0
     *
     * @return array<string, string> The localised configuration.
     */
    public function app_data() {
        return array(
            'root'        => esc_url_raw( Licensio_KB_REST_API::url() ),
            'nonce'       => wp_create_nonce( 'wp_rest' ),
            'settingsUrl' => Licensio_KB_Admin_Page::settings_url(),
        );
    }

    /**
     * Render the mount point the app takes over.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function render_page() {
        if ( ! Licensio_KB_Capabilities::current_user_can_manage() ) {
            wp_die( esc_html__( 'You are not allowed to manage the knowledge base.', 'licensio-knowledge-base' ) );
        }

        Licensio_KB_Admin_Page::shell(
            __( 'Categories', 'licensio-knowledge-base' ),
            Licensio_KB_Content_Manager::CATEGORIES_SLUG,
            __( 'Loading categories…', 'licensio-knowledge-base' )
        );
    }
}
