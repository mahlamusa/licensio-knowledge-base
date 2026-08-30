<?php
/**
 * Main plugin class.
 *
 * Singleton loaded on `plugins_loaded`. Owns the boot order for every
 * scaffolded component and exposes them to consumers through typed accessors
 * so no sub-issue has to instantiate shared infrastructure itself.
 *
 * Consumed by: #3, #4, #5, #6, #7, #8, #9, #10, #11, #12.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Lisensio Knowledge Base plugin container.
 *
 * @since 1.0.0
 */
final class Lisensio_KB {

    /**
     * The single instance of the class.
     *
     * @since 1.0.0
     * @var Lisensio_KB|null
     */
    private static $instance = null;

    /**
     * Settings store.
     *
     * @since 1.0.0
     * @var Lisensio_KB_Settings|null
     */
    private $settings = null;

    /**
     * Post type and taxonomy registrar.
     *
     * @since 1.0.0
     * @var Lisensio_KB_Post_Types|null
     */
    private $post_types = null;

    /**
     * Build asset loader.
     *
     * @since 1.0.0
     * @var Lisensio_KB_Assets|null
     */
    private $assets = null;

    /**
     * Privacy-safe visitor identity helper.
     *
     * @since 1.0.0
     * @var Lisensio_KB_Visitor|null
     */
    private $visitor = null;

    /**
     * REST route registrar.
     *
     * @since 1.0.0
     * @var Lisensio_KB_REST_API|null
     */
    private $rest_api = null;

    /**
     * Return the single instance of the plugin.
     *
     * @since 1.0.0
     *
     * @return Lisensio_KB
     */
    public static function instance() {
        if ( ! self::$instance instanceof self ) {
            self::$instance = new self();
            self::$instance->boot();
        }

        return self::$instance;
    }

    /**
     * Private constructor — use Lisensio_KB::instance().
     *
     * @since 1.0.0
     */
    private function __construct() {
    }

    /**
     * Prevent cloning of the singleton.
     *
     * @since 1.0.0
     *
     * @return void
     */
    private function __clone() {
    }

    /**
     * Wire the plugin together.
     *
     * @since 1.0.0
     *
     * @return void
     */
    private function boot() {
        Lisensio_KB_DB::init();

        $this->settings   = new Lisensio_KB_Settings();
        $this->post_types = new Lisensio_KB_Post_Types( $this->settings );
        $this->assets     = new Lisensio_KB_Assets();
        $this->visitor    = new Lisensio_KB_Visitor();
        $this->rest_api   = new Lisensio_KB_REST_API();

        $this->post_types->init_hooks();
        $this->assets->init_hooks();
        $this->visitor->init_hooks();
        $this->rest_api->init_hooks();

        add_action( 'init', array( $this, 'load_textdomain' ) );

        /*
         * Upgrades run in the admin only. Hooking this to `plugins_loaded` ran
         * schema DDL on every anonymous front-end request, where concurrent hits
         * can race dbDelta and where the work is pure overhead. WordPress plugins
         * conventionally settle upgrades when an administrator loads wp-admin.
         */
        add_action( 'admin_init', array( 'Lisensio_KB_Lifecycle', 'maybe_upgrade' ) );

        // A subsite created after a network activation gets its own install.
        add_action( 'wp_initialize_site', array( 'Lisensio_KB_Lifecycle', 'initialize_site' ), 20 );

        add_action( 'admin_notices', array( $this, 'maybe_render_library_notice' ) );

        /**
         * Fires once every scaffolded component is constructed and hooked.
         *
         * Sub-issues attach their own components here.
         *
         * @since 1.0.0
         *
         * @param Lisensio_KB $plugin The plugin container.
         */
        do_action( 'lisensio_loaded', $this );
    }

    /**
     * Warn an administrator when the vendored database library is missing.
     *
     * The plugin ships `libraries/`, so this only fires on a broken install — a
     * partial checkout, or a `composer install --no-dev` that skipped Strauss.
     * The database layer degrades to a no-op rather than throwing so the admin
     * stays reachable; without a notice the plugin would simply appear to do
     * nothing at all.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function maybe_render_library_notice() {
        if ( Lisensio_KB_DB::is_available() || ! current_user_can( 'activate_plugins' ) ) {
            return;
        }

        printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            esc_html__(
                'Lisensio Knowledge Base is missing its bundled database library, so its tables cannot be created. Reinstall the plugin, or run "composer install" in the plugin directory.',
                'lisensio-knowledge-base'
            )
        );
    }

    /**
     * Load the plugin translations.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'lisensio-knowledge-base',
            false,
            dirname( LISENSIO_KB_PLUGIN_BASENAME ) . '/languages'
        );
    }

    /**
     * Get the settings store.
     *
     * @since 1.0.0
     *
     * @return Lisensio_KB_Settings
     */
    public function settings() {
        if ( ! $this->settings instanceof Lisensio_KB_Settings ) {
            $this->settings = new Lisensio_KB_Settings();
        }

        return $this->settings;
    }

    /**
     * Get the post type registrar.
     *
     * @since 1.0.0
     *
     * @return Lisensio_KB_Post_Types
     */
    public function post_types() {
        if ( ! $this->post_types instanceof Lisensio_KB_Post_Types ) {
            $this->post_types = new Lisensio_KB_Post_Types( $this->settings() );
        }

        return $this->post_types;
    }

    /**
     * Get the build asset loader.
     *
     * @since 1.0.0
     *
     * @return Lisensio_KB_Assets
     */
    public function assets() {
        if ( ! $this->assets instanceof Lisensio_KB_Assets ) {
            $this->assets = new Lisensio_KB_Assets();
        }

        return $this->assets;
    }

    /**
     * Get the visitor identity helper.
     *
     * @since 1.0.0
     *
     * @return Lisensio_KB_Visitor
     */
    public function visitor() {
        if ( ! $this->visitor instanceof Lisensio_KB_Visitor ) {
            $this->visitor = new Lisensio_KB_Visitor();
        }

        return $this->visitor;
    }

    /**
     * Get the REST route registrar.
     *
     * @since 1.0.0
     *
     * @return Lisensio_KB_REST_API
     */
    public function rest_api() {
        if ( ! $this->rest_api instanceof Lisensio_KB_REST_API ) {
            $this->rest_api = new Lisensio_KB_REST_API();
        }

        return $this->rest_api;
    }
}
