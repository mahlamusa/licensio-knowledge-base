<?php
/**
 * The Articles app page.
 *
 * Registers the Articles Vue app as a hidden admin page
 * (`admin.php?page=licensio-kb-articles`), loads its bundle on that page only,
 * and announces its REST controller. The page has no menu entry and nothing
 * redirects to it yet: the content manager switch-on moves it into the menu
 * and sends the core article list here.
 *
 * Constructed on every request, not only in wp-admin, because the REST
 * controller it announces must be registered for REST requests too.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the Articles app page and its REST controller.
 *
 * @since 1.0.0
 */
class Licensio_KB_Articles_Admin {

    /**
     * The handle the app's script is registered under.
     *
     * @since 1.0.0
     * @var string
     */
    const APP_HANDLE = 'licensio-kb-articles-app';

    /**
     * The Vite entry the app is built from.
     *
     * @since 1.0.0
     * @var string
     */
    const APP_ENTRY = 'apps/articles/index.ts';

    /**
     * The element the app mounts into.
     *
     * Matches the page slug, which the browser test helpers rely on.
     *
     * @since 1.0.0
     * @var string
     */
    const MOUNT_ID = Licensio_KB_Content_Manager::ARTICLES_SLUG;

    /**
     * The hook suffix WordPress assigned to the page.
     *
     * @since 1.0.0
     * @var string
     */
    private $hook_suffix = '';

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
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_app' ) );
    }

    /**
     * Add the articles controller to the registrar's list.
     *
     * @since 1.0.0
     *
     * @param string[]|mixed $controllers The registered controller class names.
     *
     * @return string[] The list, with the articles controller added.
     */
    public function register_rest_controller( $controllers ) {
        $controllers   = is_array( $controllers ) ? $controllers : array();
        $controllers[] = 'Licensio_KB_Articles_REST_Controller';

        return $controllers;
    }

    /**
     * Register the hidden page.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function register_page() {
        $this->hook_suffix = Licensio_KB_Content_Manager::register_hidden_page(
            array(
                'menu_slug'  => Licensio_KB_Content_Manager::ARTICLES_SLUG,
                'menu_title' => __( 'All Articles', 'licensio-knowledge-base' ),
                'page_title' => __( 'Articles', 'licensio-knowledge-base' ),
                'callback'   => array( $this, 'render_page' ),
            )
        );
    }

    /**
     * The hook suffix of the page.
     *
     * @since 1.0.0
     *
     * @return string The hook suffix, or an empty string before the page is registered.
     */
    public function hook_suffix() {
        return $this->hook_suffix;
    }

    /**
     * Enqueue the app on its own page only.
     *
     * @since 1.0.0
     *
     * @param string $hook_suffix The screen being loaded.
     *
     * @return void
     */
    public function enqueue_app( $hook_suffix ) {
        if ( '' === $this->hook_suffix || $hook_suffix !== $this->hook_suffix ) {
            return;
        }

        if ( ! Licensio_KB_Capabilities::current_user_can_manage() ) {
            return;
        }

        if ( ! licensio_kb()->assets()->enqueue_app( self::APP_HANDLE, self::APP_ENTRY, array( 'wp-i18n' ) ) ) {
            return;
        }

        wp_set_script_translations( self::APP_HANDLE, 'licensio-knowledge-base' );

        wp_localize_script( self::APP_HANDLE, 'licensioKbArticles', $this->app_data() );
    }

    /**
     * The configuration the app reads.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed> The localised configuration.
     */
    public function app_data() {
        $metrics = Licensio_KB_Article_Metrics::from_settings( licensio_kb()->settings() );

        return array(
            'root'        => esc_url_raw( Licensio_KB_REST_API::url() ),
            'nonce'       => wp_create_nonce( 'wp_rest' ),
            'settingsUrl' => Licensio_KB_Admin_Page::settings_url(),
            'newUrl'      => admin_url( 'post-new.php?post_type=' . Licensio_KB_Post_Types::POST_TYPE ),
            'feedbackUrl' => admin_url( 'edit.php?post_type=' . Licensio_KB_Post_Types::POST_TYPE . '&page=' . Licensio_KB_Feedback_Admin::PAGE_SLUG ),
            'preferences' => Licensio_KB_Articles_REST_Controller::preferences( get_current_user_id() ),
            'lowRating'   => array(
                'threshold' => $metrics->threshold(),
                'minVotes'  => $metrics->min_votes(),
            ),
        );
    }

    /**
     * Render the mount point.
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
            __( 'Articles', 'licensio-knowledge-base' ),
            self::MOUNT_ID,
            __( 'Loading articles…', 'licensio-knowledge-base' )
        );
    }
}
