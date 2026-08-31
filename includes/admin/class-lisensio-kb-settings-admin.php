<?php
/**
 * The settings screen.
 *
 * Registers **Knowledge Base → Settings** through the shared admin menu seam
 * and mounts the Vue settings app on it. The screen itself renders nothing but
 * a mount point: every field, label and group comes from the schema the REST
 * route answers with, so adding a setting to the registry never means editing
 * a template here.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * The Settings admin screen.
 *
 * @since 1.0.0
 */
class Lisensio_KB_Settings_Admin {

    /**
     * The page slug.
     *
     * @since 1.0.0
     * @var string
     */
    const MENU_SLUG = 'lisensio-kb-settings';

    /**
     * The script handle the settings app is registered under.
     *
     * @since 1.0.0
     * @var string
     */
    const SCRIPT_HANDLE = 'lisensio-kb-settings';

    /**
     * The Vite entry, relative to the plugin root.
     *
     * @since 1.0.0
     * @var string
     */
    const APP_ENTRY = 'apps/settings/index.ts';

    /**
     * The build asset loader.
     *
     * @since 1.0.0
     * @var Lisensio_KB_Assets
     */
    private $assets;

    /**
     * Build the screen.
     *
     * @since 1.0.0
     *
     * @param Lisensio_KB_Assets $assets The build asset loader.
     */
    public function __construct( Lisensio_KB_Assets $assets ) {
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
        add_filter( 'lisensio_admin_menu_pages', array( $this, 'register_page' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
    }

    /**
     * Add the Settings page to the shared Knowledge Base menu.
     *
     * Registered through `lisensio_admin_menu_pages` rather than by calling
     * `add_submenu_page()` here, so the parent slug, the capability default and
     * the ordering stay owned by Lisensio_KB_Admin_Menu.
     *
     * @since 1.0.0
     *
     * @param array<int, array<string, mixed>>|mixed $pages The registered pages.
     *
     * @return array<int, array<string, mixed>> The pages, with Settings added.
     */
    public function register_page( $pages ) {
        $pages   = is_array( $pages ) ? $pages : array();
        $pages[] = array(
            'menu_slug'  => self::MENU_SLUG,
            'page_title' => __( 'Knowledge Base Settings', 'lisensio-knowledge-base' ),
            'menu_title' => __( 'Settings', 'lisensio-knowledge-base' ),
            'callback'   => array( $this, 'render' ),
            'position'   => 40,
        );

        return $pages;
    }

    /**
     * Render the mount point the Vue app takes over.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function render() {
        if ( ! Lisensio_KB_Capabilities::current_user_can_manage() ) {
            wp_die( esc_html__( 'You are not allowed to manage the knowledge base settings.', 'lisensio-knowledge-base' ) );
        }

        printf(
            '<div class="wrap"><div id="%1$s" class="lisensio-kb-settings-app"><p>%2$s</p></div></div>',
            esc_attr( self::SCRIPT_HANDLE ),
            esc_html__( 'Loading the knowledge base settings…', 'lisensio-knowledge-base' )
        );
    }

    /**
     * Enqueue the settings app on its own screen and nowhere else.
     *
     * @since 1.0.0
     *
     * @param string $hook_suffix The screen the enqueue is running for.
     *
     * @return void
     */
    public function enqueue( $hook_suffix ) {
        $expected = Lisensio_KB_Admin_Menu::hook_suffix( self::MENU_SLUG );

        if ( '' === $expected || $hook_suffix !== $expected ) {
            return;
        }

        if ( ! Lisensio_KB_Capabilities::current_user_can_manage() ) {
            return;
        }

        if ( ! $this->assets->enqueue_app( self::SCRIPT_HANDLE, self::APP_ENTRY, array( 'wp-i18n' ) ) ) {
            return;
        }

        wp_set_script_translations( self::SCRIPT_HANDLE, 'lisensio-knowledge-base' );

        wp_localize_script( self::SCRIPT_HANDLE, 'lisensioKbSettings', $this->app_data() );
    }

    /**
     * The bootstrap data the settings app reads.
     *
     * Only the endpoint and the nonce. Everything else — the fields, their
     * groups, their bounds, their current values — is fetched from the endpoint,
     * so the screen cannot show a stale field list after another request has
     * changed the registry.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed> The app configuration.
     */
    public function app_data() {
        return array(
            'restUrl' => esc_url_raw( Lisensio_KB_REST_API::url( ltrim( Lisensio_KB_REST_Settings_Controller::ROUTE, '/' ) ) ),
            'nonce'   => wp_create_nonce( 'wp_rest' ),
        );
    }
}
