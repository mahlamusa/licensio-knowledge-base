<?php
/**
 * The feedback inbox screen.
 *
 * Adds **Knowledge Base → Feedback**, a Vue application mounted into an
 * otherwise empty admin page, and fills in the usefulness column that
 * Lisensio_KB_Admin_Columns renders as an em dash until this issue supplies
 * the data.
 *
 * The screen is a submenu of the article post type's own top-level menu, which
 * is the "Knowledge Base" menu the content model already registers, so no
 * menu is invented here and the analytics and settings screens can attach
 * beside it in the same way.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the feedback inbox screen and the usefulness column data.
 *
 * @since 1.0.0
 */
class Lisensio_KB_Feedback_Admin {

    /**
     * The admin page slug.
     *
     * @since 1.0.0
     * @var string
     */
    const PAGE_SLUG = 'lisensio-kb-feedback';

    /**
     * The handle the inbox application is registered under.
     *
     * @since 1.0.0
     * @var string
     */
    const APP_HANDLE = 'lisensio-kb-feedback-app';

    /**
     * The Vite entry the inbox is built from.
     *
     * @since 1.0.0
     * @var string
     */
    const APP_ENTRY = 'apps/feedback/index.ts';

    /**
     * The element the application mounts into.
     *
     * @since 1.0.0
     * @var string
     */
    const MOUNT_ID = 'lisensio-kb-feedback-app';

    /**
     * The hook suffix WordPress assigned to the inbox screen.
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
        add_action( 'admin_menu', array( $this, 'register_page' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_app' ) );
        add_filter( 'lisensio_article_usefulness_column', array( $this, 'usefulness_column' ), 10, 2 );
    }

    /**
     * Add the inbox to the Knowledge Base menu.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function register_page() {
        $hook = add_submenu_page(
            'edit.php?post_type=' . Lisensio_KB_Post_Types::POST_TYPE,
            __( 'Feedback', 'lisensio-knowledge-base' ),
            __( 'Feedback', 'lisensio-knowledge-base' ),
            Lisensio_KB_Capabilities::CAPABILITY,
            self::PAGE_SLUG,
            array( $this, 'render_page' )
        );

        $this->hook_suffix = is_string( $hook ) ? $hook : '';
    }

    /**
     * The hook suffix of the inbox screen.
     *
     * @since 1.0.0
     *
     * @return string The hook suffix, or an empty string before the menu is registered.
     */
    public function hook_suffix() {
        return $this->hook_suffix;
    }

    /**
     * Enqueue the inbox application on its own screen only.
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

        if ( ! Lisensio_KB_Capabilities::current_user_can_manage() ) {
            return;
        }

        $assets = lisensio_kb()->assets();

        if ( ! $assets->enqueue_app( self::APP_HANDLE, self::APP_ENTRY ) ) {
            return;
        }

        wp_localize_script( self::APP_HANDLE, 'lisensioKBFeedbackInbox', $this->app_data() );
    }

    /**
     * The configuration the inbox application needs.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed> The localised configuration.
     */
    public function app_data() {
        return array(
            'root'        => Lisensio_KB_REST_API::url( 'feedback' ),
            'nonce'       => wp_create_nonce( 'wp_rest' ),
            'perPage'     => Lisensio_KB_Feedback::DEFAULT_PER_PAGE,
            'statuses'    => Lisensio_KB_Feedback::statuses(),
            'settingsUrl' => Lisensio_KB_Admin_Page::settings_url(),
        );
    }

    /**
     * Render the mount point.
     *
     * The application draws the whole screen; the shared shell prints the
     * heading so the page is named before the bundle has parsed.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function render_page() {
        if ( ! Lisensio_KB_Capabilities::current_user_can_manage() ) {
            wp_die( esc_html__( 'You are not allowed to manage the knowledge base.', 'lisensio-knowledge-base' ) );
        }

        Lisensio_KB_Admin_Page::shell(
            __( 'Knowledge Base Feedback', 'lisensio-knowledge-base' ),
            self::MOUNT_ID,
            __( 'Loading feedback…', 'lisensio-knowledge-base' )
        );
    }

    /**
     * Supply the usefulness column with the cached score.
     *
     * Hooked to the filter Lisensio_KB_Admin_Columns exposes rather than
     * changing that class, so the column keeps its em-dash fallback for an
     * article nobody has voted on.
     *
     * @since 1.0.0
     *
     * @param string $value   The value the column would otherwise render.
     * @param int    $post_id The article being rendered.
     *
     * @return string The rendered value, or the untouched fallback.
     */
    public function usefulness_column( $value, $post_id ) {
        $figures = Lisensio_KB_Feedback::get_score( (int) $post_id );

        if ( null === $figures['score'] ) {
            return $value;
        }

        $total = $figures['up'] + $figures['down'];

        return sprintf(
            /* translators: 1: usefulness percentage, 2: total number of votes. */
            _n( '%1$d%% (%2$d vote)', '%1$d%% (%2$d votes)', $total, 'lisensio-knowledge-base' ),
            (int) $figures['score'],
            $total
        );
    }
}
