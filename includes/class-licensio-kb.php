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
 * @package Licensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Licensio Knowledge Base plugin container.
 *
 * @since 1.0.0
 */
final class Licensio_KB {

    /**
     * The single instance of the class.
     *
     * @since 1.0.0
     * @var Licensio_KB|null
     */
    private static $instance = null;

    /**
     * Settings store.
     *
     * @since 1.0.0
     * @var Licensio_KB_Settings|null
     */
    private $settings = null;

    /**
     * Post type and taxonomy registrar.
     *
     * @since 1.0.0
     * @var Licensio_KB_Post_Types|null
     */
    private $post_types = null;

    /**
     * Capability model.
     *
     * @since 1.0.0
     * @var Licensio_KB_Capabilities|null
     */
    private $capabilities = null;

    /**
     * Permalink settings.
     *
     * @since 1.0.0
     * @var Licensio_KB_Permalinks|null
     */
    private $permalinks = null;

    /**
     * Category term meta registrar.
     *
     * @since 1.0.0
     * @var Licensio_KB_Term_Meta|null
     */
    private $term_meta = null;

    /**
     * Build asset loader.
     *
     * @since 1.0.0
     * @var Licensio_KB_Assets|null
     */
    private $assets = null;

    /**
     * Classic template layer.
     *
     * @since 1.0.0
     * @var Licensio_KB_Templates|null
     */
    private $templates = null;

    /**
     * Block template layer.
     *
     * @since 1.0.0
     * @var Licensio_KB_Block_Templates|null
     */
    private $block_templates = null;

    /**
     * Blocks and their shortcodes.
     *
     * @since 1.0.0
     * @var Licensio_KB_Blocks|null
     */
    private $blocks = null;

    /**
     * Privacy-safe visitor identity helper.
     *
     * @since 1.0.0
     * @var Licensio_KB_Visitor|null
     */
    private $visitor = null;

    /**
     * REST route registrar.
     *
     * @since 1.0.0
     * @var Licensio_KB_REST_API|null
     */
    private $rest_api = null;

    /**
     * Article feedback and voting.
     *
     * @since 1.0.0
     * @var Licensio_KB_Feedback|null
     */
    private $feedback = null;

    /**
     * Search and article-view capture.
     *
     * @since 1.0.0
     * @var Licensio_KB_Analytics|null
     */
    private $analytics = null;

    /**
     * Analytics retention job.
     *
     * @since 1.0.0
     * @var Licensio_KB_Analytics_Retention|null
     */
    private $analytics_retention = null;

    /**
     * Personal-data exporter and eraser.
     *
     * @since 1.0.0
     * @var Licensio_KB_Analytics_Privacy|null
     */
    private $analytics_privacy = null;

    /**
     * The settings REST surface.
     *
     * @since 1.0.0
     * @var Licensio_KB_Settings_API|null
     */
    private $settings_api = null;

    /**
     * Article heading ids and the table of contents.
     *
     * @since 1.0.0
     * @var Licensio_KB_TOC|null
     */
    private $toc = null;

    /**
     * Data importer.
     *
     * @since 1.0.0
     * @var Licensio_KB_Importer|null
     */
    private $importer = null;

    /**
     * The content manager switch and its REST controllers.
     *
     * @since 1.0.0
     * @var Licensio_KB_Content_Manager|null
     */
    private $content_manager = null;

    /**
     * Shared term move and merge operations.
     *
     * @since 1.0.0
     * @var Licensio_KB_Term_Operations|null
     */
    private $term_operations = null;

    /**
     * Return the single instance of the plugin.
     *
     * @since 1.0.0
     *
     * @return Licensio_KB
     */
    public static function instance() {
        if ( ! self::$instance instanceof self ) {
            self::$instance = new self();
            self::$instance->boot();
        }

        return self::$instance;
    }

    /**
     * Private constructor — use Licensio_KB::instance().
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
        Licensio_KB_DB::init();

        $this->settings     = new Licensio_KB_Settings();
        $this->capabilities = new Licensio_KB_Capabilities();
        $this->permalinks   = new Licensio_KB_Permalinks( $this->settings );
        $this->term_meta    = new Licensio_KB_Term_Meta();
        $this->post_types   = new Licensio_KB_Post_Types( $this->settings );
        $this->assets       = new Licensio_KB_Assets();
        $this->templates    = new Licensio_KB_Templates( $this->settings );
        $this->visitor      = new Licensio_KB_Visitor();
        $this->rest_api     = new Licensio_KB_REST_API();
        $this->feedback     = new Licensio_KB_Feedback( $this->settings );

        $this->block_templates = new Licensio_KB_Block_Templates();
        $this->blocks          = new Licensio_KB_Blocks();

        $this->analytics           = new Licensio_KB_Analytics( $this->settings, $this->visitor );
        $this->analytics_retention = new Licensio_KB_Analytics_Retention( $this->settings );
        $this->analytics_privacy   = new Licensio_KB_Analytics_Privacy();

        $this->settings_api = new Licensio_KB_Settings_API();
        $this->toc          = new Licensio_KB_TOC();

        /*
         * Built on every request, not only in wp-admin: their REST routes and
         * the category delete hook must work for REST and WP-CLI requests too.
         * The content manager stays inert until it is switched on.
         */
        $this->content_manager = new Licensio_KB_Content_Manager( $this->settings );
        $this->term_operations = new Licensio_KB_Term_Operations();

        /*
         * Permalinks first. It adds a key to the settings registry, and
         * Licensio_KB_Settings memoizes the resolved settings on first read, so
         * a registry filter attached after something has read a setting would be
         * missing from that memo for the rest of the request.
         */
        $this->permalinks->init_hooks();
        $this->capabilities->init_hooks();
        $this->term_meta->init_hooks();
        $this->post_types->init_hooks();
        $this->assets->init_hooks();
        $this->templates->init_hooks();
        $this->block_templates->init_hooks();
        $this->blocks->init_hooks();
        $this->visitor->init_hooks();
        $this->rest_api->init_hooks();
        $this->feedback->init_hooks();
        $this->analytics->init_hooks();
        $this->analytics_retention->init_hooks();
        $this->analytics_privacy->init_hooks();
        $this->settings_api->init_hooks();
        $this->toc->init_hooks();
        $this->content_manager->init_hooks();
        $this->term_operations->init_hooks();
        Licensio_KB_Sidebar::init_hooks();

        // The Articles app; built on every request so its REST routes register.
        $articles_admin = new Licensio_KB_Articles_Admin();
        $articles_admin->init_hooks();

        $article_order = new Licensio_KB_Article_Order();
        $article_order->init_hooks();

        if ( ! is_admin() ) {
            $import_redirects = new Licensio_KB_Import_Redirect_Server();
            $import_redirects->init_hooks();
        }

        if ( is_admin() ) {
            $term_fields = new Licensio_KB_Term_Fields();
            $term_fields->init_hooks();

            $admin_columns = new Licensio_KB_Admin_Columns();
            $admin_columns->init_hooks();

            /*
             * Shared menu registration, promoted out of #9 and #10 because both
             * need pages under the Knowledge Base menu. Consumers register
             * through `licensio_admin_menu_pages`; nothing calls
             * add_submenu_page() itself.
             */
            $admin_menu = new Licensio_KB_Admin_Menu();
            $admin_menu->init_hooks();

            $analytics_admin = new Licensio_KB_Analytics_Admin( $this->assets );
            $analytics_admin->init_hooks();

            $settings_admin = new Licensio_KB_Settings_Admin( $this->assets );
            $settings_admin->init_hooks();

            $tools_admin = new Licensio_KB_Tools_Admin( $this->assets );
            $tools_admin->init_hooks();

            $import_admin = new Licensio_KB_Import_Admin( $this->importer() );
            $import_admin->init_hooks();

            $search_index_admin = new Licensio_KB_Search_Index_Admin();
            $search_index_admin->init_hooks();

            $maintenance_admin = new Licensio_KB_Maintenance_Admin( $this->analytics_retention() );
            $maintenance_admin->init_hooks();

            $duplicates_admin = new Licensio_KB_Duplicates_Admin();
            $duplicates_admin->init_hooks();
        }

        add_action( 'init', array( $this, 'load_textdomain' ) );

        /*
         * Upgrades run in the admin only. Hooking this to `plugins_loaded` ran
         * schema DDL on every anonymous front-end request, where concurrent hits
         * can race dbDelta and where the work is pure overhead. WordPress plugins
         * conventionally settle upgrades when an administrator loads wp-admin.
         */
        add_action( 'admin_init', array( 'Licensio_KB_Lifecycle', 'maybe_upgrade' ) );

        // A subsite created after a network activation gets its own install.
        add_action( 'wp_initialize_site', array( 'Licensio_KB_Lifecycle', 'initialize_site' ), 20 );

        add_action( 'admin_notices', array( $this, 'maybe_render_library_notice' ) );

        /**
         * Fires once every scaffolded component is constructed and hooked.
         *
         * Sub-issues attach their own components here.
         *
         * @since 1.0.0
         *
         * @param Licensio_KB $plugin The plugin container.
         */
        do_action( 'licensio_loaded', $this );
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
        if ( Licensio_KB_DB::is_available() || ! current_user_can( 'activate_plugins' ) ) {
            return;
        }

        printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            esc_html__(
                'Licensio Knowledge Base is missing its bundled database library, so its tables cannot be created. Reinstall the plugin, or run "composer install" in the plugin directory.',
                'licensio-knowledge-base'
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
            'licensio-knowledge-base',
            false,
            dirname( LICENSIO_KB_PLUGIN_BASENAME ) . '/languages'
        );
    }

    /**
     * Get the settings store.
     *
     * @since 1.0.0
     *
     * @return Licensio_KB_Settings
     */
    public function settings() {
        if ( ! $this->settings instanceof Licensio_KB_Settings ) {
            $this->settings = new Licensio_KB_Settings();
        }

        return $this->settings;
    }

    /**
     * Get the post type registrar.
     *
     * @since 1.0.0
     *
     * @return Licensio_KB_Post_Types
     */
    public function post_types() {
        if ( ! $this->post_types instanceof Licensio_KB_Post_Types ) {
            $this->post_types = new Licensio_KB_Post_Types( $this->settings() );
        }

        return $this->post_types;
    }

    /**
     * Get the capability model.
     *
     * @since 1.0.0
     *
     * @return Licensio_KB_Capabilities
     */
    public function capabilities() {
        if ( ! $this->capabilities instanceof Licensio_KB_Capabilities ) {
            $this->capabilities = new Licensio_KB_Capabilities();
        }

        return $this->capabilities;
    }

    /**
     * Get the permalink settings.
     *
     * @since 1.0.0
     *
     * @return Licensio_KB_Permalinks
     */
    public function permalinks() {
        if ( ! $this->permalinks instanceof Licensio_KB_Permalinks ) {
            $this->permalinks = new Licensio_KB_Permalinks( $this->settings() );
        }

        return $this->permalinks;
    }

    /**
     * Get the category term meta registrar.
     *
     * @since 1.0.0
     *
     * @return Licensio_KB_Term_Meta
     */
    public function term_meta() {
        if ( ! $this->term_meta instanceof Licensio_KB_Term_Meta ) {
            $this->term_meta = new Licensio_KB_Term_Meta();
        }

        return $this->term_meta;
    }

    /**
     * Get the build asset loader.
     *
     * @since 1.0.0
     *
     * @return Licensio_KB_Assets
     */
    public function assets() {
        if ( ! $this->assets instanceof Licensio_KB_Assets ) {
            $this->assets = new Licensio_KB_Assets();
        }

        return $this->assets;
    }

    /**
     * Get the classic template layer.
     *
     * @since 1.0.0
     *
     * @return Licensio_KB_Templates
     */
    public function templates() {
        if ( ! $this->templates instanceof Licensio_KB_Templates ) {
            $this->templates = new Licensio_KB_Templates( $this->settings() );
        }

        return $this->templates;
    }

    /**
     * Get the block template layer.
     *
     * @since 1.0.0
     *
     * @return Licensio_KB_Block_Templates
     */
    public function block_templates() {
        if ( ! $this->block_templates instanceof Licensio_KB_Block_Templates ) {
            $this->block_templates = new Licensio_KB_Block_Templates();
        }

        return $this->block_templates;
    }

    /**
     * Get the block and shortcode registrar.
     *
     * @since 1.0.0
     *
     * @return Licensio_KB_Blocks
     */
    public function blocks() {
        if ( ! $this->blocks instanceof Licensio_KB_Blocks ) {
            $this->blocks = new Licensio_KB_Blocks();
        }

        return $this->blocks;
    }

    /**
     * Get the visitor identity helper.
     *
     * @since 1.0.0
     *
     * @return Licensio_KB_Visitor
     */
    public function visitor() {
        if ( ! $this->visitor instanceof Licensio_KB_Visitor ) {
            $this->visitor = new Licensio_KB_Visitor();
        }

        return $this->visitor;
    }

    /**
     * Get the REST route registrar.
     *
     * @since 1.0.0
     *
     * @return Licensio_KB_REST_API
     */
    public function rest_api() {
        if ( ! $this->rest_api instanceof Licensio_KB_REST_API ) {
            $this->rest_api = new Licensio_KB_REST_API();
        }

        return $this->rest_api;
    }

    /**
     * Get the feedback and voting component.
     *
     * @since 1.0.0
     *
     * @return Licensio_KB_Feedback
     */
    public function feedback() {
        if ( ! $this->feedback instanceof Licensio_KB_Feedback ) {
            $this->feedback = new Licensio_KB_Feedback( $this->settings() );
        }

        return $this->feedback;
    }

    /**
     * Get the search and article-view recorder.
     *
     * @since 1.0.0
     *
     * @return Licensio_KB_Analytics
     */
    public function analytics() {
        if ( ! $this->analytics instanceof Licensio_KB_Analytics ) {
            $this->analytics = new Licensio_KB_Analytics( $this->settings(), $this->visitor() );
        }

        return $this->analytics;
    }

    /**
     * Get the analytics retention job.
     *
     * @since 1.0.0
     *
     * @return Licensio_KB_Analytics_Retention
     */
    public function analytics_retention() {
        if ( ! $this->analytics_retention instanceof Licensio_KB_Analytics_Retention ) {
            $this->analytics_retention = new Licensio_KB_Analytics_Retention( $this->settings() );
        }

        return $this->analytics_retention;
    }

    /**
     * Get the personal-data exporter and eraser.
     *
     * @since 1.0.0
     *
     * @return Licensio_KB_Analytics_Privacy
     */
    public function analytics_privacy() {
        if ( ! $this->analytics_privacy instanceof Licensio_KB_Analytics_Privacy ) {
            $this->analytics_privacy = new Licensio_KB_Analytics_Privacy();
        }

        return $this->analytics_privacy;
    }

    /**
     * Get the table of contents component.
     *
     * @since 1.0.0
     *
     * @return Licensio_KB_TOC
     */
    public function toc() {
        if ( ! $this->toc instanceof Licensio_KB_TOC ) {
            $this->toc = new Licensio_KB_TOC();
        }

        return $this->toc;
    }

    /**
     * Get the content manager.
     *
     * @since 1.0.0
     *
     * @return Licensio_KB_Content_Manager
     */
    public function content_manager() {
        if ( ! $this->content_manager instanceof Licensio_KB_Content_Manager ) {
            $this->content_manager = new Licensio_KB_Content_Manager( $this->settings() );
        }

        return $this->content_manager;
    }

    /**
     * Get the shared term operations.
     *
     * Use this instance rather than constructing another, so the delete hook
     * is only registered once.
     *
     * @since 1.0.0
     *
     * @return Licensio_KB_Term_Operations
     */
    public function term_operations() {
        if ( ! $this->term_operations instanceof Licensio_KB_Term_Operations ) {
            $this->term_operations = new Licensio_KB_Term_Operations();
        }

        return $this->term_operations;
    }

    /**
     * Get the data importer.
     *
     * @since 1.0.0
     *
     * @return Licensio_KB_Importer
     */
    public function importer() {
        if ( ! $this->importer instanceof Licensio_KB_Importer ) {
            $this->importer = new Licensio_KB_Importer();
        }

        return $this->importer;
    }
}
