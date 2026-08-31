<?php
/**
 * Analytics dashboard screen.
 *
 * Registers the Analytics page through the shared admin menu seam, mounts the
 * Vue app on it, and pre-fills the new-article screen when an editor follows the
 * "create an article for this" action from a zero-result search term.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * The Analytics admin screen.
 *
 * @since 1.0.0
 */
class Lisensio_KB_Analytics_Admin {

    /**
     * The page slug.
     *
     * @since 1.0.0
     * @var string
     */
    const MENU_SLUG = 'lisensio-kb-analytics';

    /**
     * The script handle the dashboard app is registered under.
     *
     * @since 1.0.0
     * @var string
     */
    const SCRIPT_HANDLE = 'lisensio-kb-analytics';

    /**
     * The Vite entry, relative to the plugin root.
     *
     * @since 1.0.0
     * @var string
     */
    const APP_ENTRY = 'apps/analytics/index.ts';

    /**
     * The query argument carrying a search term into the new-article screen.
     *
     * @since 1.0.0
     * @var string
     */
    const PREFILL_QUERY_ARG = 'lisensio_kb_term';

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
        add_filter( 'default_title', array( $this, 'prefill_article_title' ), 10, 2 );
    }

    /**
     * Add the Analytics page to the shared Knowledge Base menu.
     *
     * Registered through `lisensio_admin_menu_pages` rather than by calling
     * `add_submenu_page()` here, so the parent slug, the capability default and
     * the ordering stay owned by one class shared with #10.
     *
     * @since 1.0.0
     *
     * @param array<int, array<string, mixed>> $pages The registered pages.
     *
     * @return array<int, array<string, mixed>> The pages, with Analytics added.
     */
    public function register_page( $pages ) {
        $pages   = is_array( $pages ) ? $pages : array();
        $pages[] = array(
            'menu_slug'  => self::MENU_SLUG,
            'page_title' => __( 'Knowledge Base Analytics', 'lisensio-knowledge-base' ),
            'menu_title' => __( 'Analytics', 'lisensio-knowledge-base' ),
            'callback'   => array( $this, 'render' ),
            'position'   => 30,
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
            wp_die( esc_html__( 'You are not allowed to view the knowledge base analytics.', 'lisensio-knowledge-base' ) );
        }

        printf(
            '<div class="wrap"><div id="%1$s" class="lisensio-kb-analytics-app"><p>%2$s</p></div></div>',
            esc_attr( self::SCRIPT_HANDLE ),
            esc_html__( 'Loading the knowledge base analytics…', 'lisensio-knowledge-base' )
        );
    }

    /**
     * Enqueue the dashboard app on its own screen and nowhere else.
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

        if ( ! $this->assets->enqueue_app( self::SCRIPT_HANDLE, self::APP_ENTRY, array( 'wp-i18n' ) ) ) {
            return;
        }

        wp_set_script_translations( self::SCRIPT_HANDLE, 'lisensio-knowledge-base' );

        wp_localize_script( self::SCRIPT_HANDLE, 'lisensioKbAnalytics', $this->app_data() );
    }

    /**
     * The bootstrap data the dashboard app reads.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed> The app configuration.
     */
    public function app_data() {
        $retention = new Lisensio_KB_Analytics_Retention( new Lisensio_KB_Settings() );

        return array(
            'restUrl'       => esc_url_raw( Lisensio_KB_REST_API::url( 'analytics/' ) ),
            'nonce'         => wp_create_nonce( 'wp_rest' ),
            'defaultDays'   => Lisensio_KB_REST_Analytics_Controller::DEFAULT_DAYS,
            'maxDays'       => Lisensio_KB_REST_Analytics_Controller::MAX_DAYS,
            'defaultLimit'  => Lisensio_KB_Analytics_Repository::DEFAULT_LIMIT,
            'retentionDays' => $retention->retention_days(),
        );
    }

    /**
     * Pre-fill the new-article title from a zero-result search term.
     *
     * This is what makes the dashboard's "create an article for this" action a
     * one-click move from a documentation gap to a draft that answers it.
     *
     * @since 1.0.0
     *
     * @param string       $title The default title core would use.
     * @param WP_Post|null $post  The post being created.
     *
     * @return string The pre-filled title, or the incoming default.
     */
    public function prefill_article_title( $title, $post = null ) {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pre-fill of an empty draft; nothing is written and no state changes.
        if ( ! isset( $_GET[ self::PREFILL_QUERY_ARG ] ) ) {
            return $title;
        }

        $post_type = ( $post instanceof WP_Post ) ? (string) $post->post_type : '';

        if ( Lisensio_KB_Post_Types::POST_TYPE !== $post_type ) {
            return $title;
        }

        if ( ! Lisensio_KB_Capabilities::current_user_can_manage() ) {
            return $title;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- See above.
        $term = sanitize_text_field( wp_unslash( $_GET[ self::PREFILL_QUERY_ARG ] ) );

        return '' === $term ? $title : $term;
    }
}
