<?php
/**
 * Tools admin screen.
 *
 * The home for the plugin's operational tools. The screen itself is one Vue
 * app with a tab per tool; the Migration wizard lives there today and later
 * tools join it as further tabs.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * The Tools admin screen.
 *
 * @since 1.0.0
 */
class Licensio_KB_Tools_Admin {

    /**
     * The page slug.
     *
     * @since 1.0.0
     * @var string
     */
    const MENU_SLUG = 'licensio-kb-tools';

    /**
     * The script handle the tools app is registered under.
     *
     * @since 1.0.0
     * @var string
     */
    const SCRIPT_HANDLE = 'licensio-kb-tools';

    /**
     * The Vite entry, relative to the plugin root.
     *
     * @since 1.0.0
     * @var string
     */
    const APP_ENTRY = 'apps/tools/index.ts';

    /**
     * The build asset loader.
     *
     * @since 1.0.0
     * @var Licensio_KB_Assets
     */
    private $assets;

    /**
     * Build the screen.
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
        add_filter( 'licensio_admin_menu_pages', array( $this, 'register_page' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
    }

    /**
     * Add the Tools page to the shared Knowledge Base menu.
     *
     * @since 1.0.0
     *
     * @param array<int, array<string, mixed>> $pages The registered pages.
     *
     * @return array<int, array<string, mixed>> The page list, with Tools added.
     */
    public function register_page( $pages ) {
        $pages   = is_array( $pages ) ? $pages : array();
        $pages[] = array(
            'menu_slug'  => self::MENU_SLUG,
            'page_title' => __( 'Knowledge Base Tools', 'licensio-knowledge-base' ),
            'menu_title' => __( 'Tools', 'licensio-knowledge-base' ),
            'callback'   => array( $this, 'render' ),
            'position'   => 35,
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
        if ( ! Licensio_KB_Capabilities::current_user_can_manage() ) {
            wp_die( esc_html__( 'You are not allowed to use the knowledge base tools.', 'licensio-knowledge-base' ) );
        }

        Licensio_KB_Admin_Page::shell(
            __( 'Tools', 'licensio-knowledge-base' ),
            self::SCRIPT_HANDLE,
            __( 'Loading the knowledge base tools…', 'licensio-knowledge-base' ),
            'licensio-kb-tools-app'
        );
    }

    /**
     * Enqueue the tools app on its own screen and nowhere else.
     *
     * @since 1.0.0
     *
     * @param string $hook_suffix The screen the enqueue is running for.
     *
     * @return void
     */
    public function enqueue( $hook_suffix ) {
        $expected = Licensio_KB_Admin_Menu::hook_suffix( self::MENU_SLUG );

        if ( '' === $expected || $hook_suffix !== $expected ) {
            return;
        }

        if ( ! $this->assets->enqueue_app( self::SCRIPT_HANDLE, self::APP_ENTRY, array( 'wp-i18n' ) ) ) {
            return;
        }

        wp_set_script_translations( self::SCRIPT_HANDLE, 'licensio-knowledge-base' );

        wp_localize_script( self::SCRIPT_HANDLE, 'licensioKbTools', $this->app_data() );
    }

    /**
     * The bootstrap data the tools app reads.
     *
     * Tools add their own keys through `licensio_kb_tools_app_data` — the
     * screen owns the mount, not the tools on it.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed> The app configuration.
     */
    public function app_data() {
        return apply_filters(
            'licensio_kb_tools_app_data',
            array(
                'settingsUrl' => Licensio_KB_Admin_Page::settings_url(),
                'articlesUrl' => admin_url( 'edit.php?post_type=' . rawurlencode( Licensio_KB_Post_Types::POST_TYPE ) ),
            )
        );
    }
}
