<?php
/**
 * The Tags app page.
 *
 * Registers the Tags Vue app on a hidden admin page,
 * `admin.php?page=licensio-kb-tags`, loads its bundle there only, and
 * announces the tag REST routes it talks to. The page has no menu entry and
 * no core screen redirects to it until the content manager is switched on.
 *
 * Built on every request, not only in wp-admin, because the REST controller
 * it announces must be registered for REST requests too. The admin hooks
 * simply never fire outside wp-admin.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the Tags app page and its REST controller.
 *
 * @since 1.0.0
 */
class Licensio_KB_Tags_Admin {

    /**
     * The script handle the app is registered under.
     *
     * @since 1.0.0
     * @var string
     */
    const SCRIPT_HANDLE = 'licensio-kb-tags';

    /**
     * The Vite entry, relative to the plugin root.
     *
     * @since 1.0.0
     * @var string
     */
    const APP_ENTRY = 'apps/tags/index.ts';

    /**
     * How many tags a page shows.
     *
     * @since 1.0.0
     * @var int
     */
    const PER_PAGE = 20;

    /**
     * The build asset loader.
     *
     * @since 1.0.0
     * @var Licensio_KB_Assets
     */
    private $assets;

    /**
     * The hook suffix WordPress gave the page.
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
        add_action( 'admin_menu', array( $this, 'register_page' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
    }

    /**
     * Add the tag controller to the plugin's REST registrar.
     *
     * @since 1.0.0
     *
     * @param string[]|mixed $controllers The registered controller class names.
     *
     * @return string[] The list, with the tag controller added.
     */
    public function register_rest_controller( $controllers ) {
        $controllers   = is_array( $controllers ) ? $controllers : array();
        $controllers[] = 'Licensio_KB_Tags_REST_Controller';

        return $controllers;
    }

    /**
     * Register the hidden Tags page.
     *
     * The position, after Categories, is where the page sits once the content
     * manager puts it in the menu; a hidden page ignores it.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function register_page() {
        $this->hook_suffix = Licensio_KB_Content_Manager::register_hidden_page(
            array(
                'menu_slug'  => Licensio_KB_Content_Manager::TAGS_SLUG,
                'page_title' => __( 'Tags', 'licensio-knowledge-base' ),
                'menu_title' => __( 'Tags', 'licensio-knowledge-base' ),
                'callback'   => array( $this, 'render' ),
                'position'   => 4,
            )
        );
    }

    /**
     * Render the mount point the app takes over.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function render() {
        if ( ! Licensio_KB_Capabilities::current_user_can_manage() ) {
            wp_die( esc_html__( 'You are not allowed to manage the knowledge base.', 'licensio-knowledge-base' ) );
        }

        Licensio_KB_Admin_Page::shell(
            __( 'Tags', 'licensio-knowledge-base' ),
            Licensio_KB_Content_Manager::TAGS_SLUG,
            __( 'Loading tags…', 'licensio-knowledge-base' )
        );
    }

    /**
     * Enqueue the app on the Tags page and nowhere else.
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

        if ( ! $this->assets->enqueue_app( self::SCRIPT_HANDLE, self::APP_ENTRY, array( 'wp-i18n' ) ) ) {
            return;
        }

        wp_set_script_translations( self::SCRIPT_HANDLE, 'licensio-knowledge-base' );

        wp_localize_script( self::SCRIPT_HANDLE, 'licensioKbTags', $this->app_data() );
    }

    /**
     * The configuration the app reads.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed> The localised configuration.
     */
    public function app_data() {
        return array(
            'root'        => Licensio_KB_REST_API::url(),
            'nonce'       => wp_create_nonce( 'wp_rest' ),
            'perPage'     => self::PER_PAGE,
            'settingsUrl' => Licensio_KB_Admin_Page::settings_url(),
        );
    }
}
