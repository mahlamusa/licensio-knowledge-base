<?php
/**
 * Admin notice for a missing admin app build.
 *
 * The admin screens are Vue apps loaded from `dist/`. When the build is
 * missing, for example after an incomplete deploy or on a git checkout that
 * was never built, the Articles, Categories and Tags screens fall back to the
 * classic WordPress screens and the other app screens never get past their
 * loading message. This notice says why and how to fix it. It shows only to
 * knowledge base managers, only on the knowledge base screens, and never
 * when the build is present or the Vite dev server is serving the apps.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Explains a missing admin app build on the knowledge base screens.
 *
 * @since 1.0.0
 */
class Licensio_KB_Build_Notice {

    /**
     * The screen ID fragment every plugin admin page carries.
     *
     * Matches both pages under the Knowledge Base menu and the hidden app
     * pages registered under `options.php` while the classic screens are used.
     *
     * @since 1.0.0
     * @var string
     */
    const PAGE_ID_FRAGMENT = '_page_licensio-kb-';

    /**
     * The build asset loader.
     *
     * @since 1.0.0
     * @var Licensio_KB_Assets
     */
    private $assets;

    /**
     * Constructor.
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
        add_action( 'admin_notices', array( $this, 'maybe_render' ) );
    }

    /**
     * The Vite entries of every admin app.
     *
     * @since 1.0.0
     *
     * @return string[] Source paths relative to the plugin root.
     */
    public static function app_entries() {
        return array(
            Licensio_KB_Articles_Admin::APP_ENTRY,
            Licensio_KB_Categories_Admin::APP_ENTRY,
            Licensio_KB_Tags_Admin::APP_ENTRY,
            Licensio_KB_Feedback_Admin::APP_ENTRY,
            Licensio_KB_Analytics_Admin::APP_ENTRY,
            Licensio_KB_Settings_Admin::APP_ENTRY,
            Licensio_KB_Tools_Admin::APP_ENTRY,
        );
    }

    /**
     * Whether a screen belongs to the knowledge base.
     *
     * True for the plugin's own admin pages, the core article list and the
     * core category and tag screens. The article editor is left out: it does
     * not use the admin apps.
     *
     * @since 1.0.0
     *
     * @param mixed $screen The current screen, as get_current_screen() returns it.
     *
     * @return bool True on a knowledge base screen.
     */
    public static function is_kb_screen( $screen ) {
        if ( ! $screen instanceof WP_Screen ) {
            return false;
        }

        if ( false !== strpos( (string) $screen->id, self::PAGE_ID_FRAGMENT ) ) {
            return true;
        }

        if ( 'edit' === $screen->base ) {
            return Licensio_KB_Post_Types::POST_TYPE === $screen->post_type;
        }

        if ( 'edit-tags' === $screen->base || 'term' === $screen->base ) {
            return in_array( $screen->taxonomy, array( Licensio_KB_Post_Types::TAXONOMY_CATEGORY, Licensio_KB_Post_Types::TAXONOMY_TAG ), true );
        }

        return false;
    }

    /**
     * Print the notice when the build is missing on a knowledge base screen.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function maybe_render() {
        if ( ! Licensio_KB_Capabilities::current_user_can_manage() ) {
            return;
        }

        if ( ! self::is_kb_screen( function_exists( 'get_current_screen' ) ? get_current_screen() : null ) ) {
            return;
        }

        if ( $this->assets->apps_can_load( self::app_entries() ) ) {
            return;
        }

        printf(
            '<div class="notice notice-error licensio-kb-build-notice"><p>%1$s</p><p>%2$s</p></div>',
            esc_html__( 'Licensio Knowledge Base cannot load its admin apps because their built files are missing from the plugin’s dist folder. Until they are restored, the Articles, Categories and Tags screens use the classic WordPress screens and the other Knowledge Base screens do not load.', 'licensio-knowledge-base' ),
            sprintf(
                /* translators: 1: the npm ci command, 2: the npm run build command. */
                esc_html__( 'To fix this, reinstall the plugin from its release zip, or run %1$s and then %2$s in the plugin folder.', 'licensio-knowledge-base' ),
                '<code>npm ci</code>',
                '<code>npm run build</code>'
            )
        );
    }
}
