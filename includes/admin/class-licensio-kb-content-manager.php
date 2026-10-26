<?php
/**
 * The content manager switch, hidden app pages and core screen redirects.
 *
 * The content manager replaces the core article list and the category and tag
 * term screens with three Vue apps: Articles, Categories and Tags. This class
 * is the one place that decides whether those apps have taken over, and it
 * holds everything the takeover needs: the app page registration, the map
 * from a core screen URL to the matching app URL, and the list of core menu
 * items the apps replace.
 *
 * While it is enabled the apps sit in the Knowledge Base menu in place of the
 * core items, and the core screens redirect to them. The classic screens
 * setting and the `licensio_kb_use_content_manager` filter turn it off: the
 * core screens and menu items come back, and the apps stay reachable by URL
 * as hidden pages.
 *
 * Constructed on every request, not only in wp-admin, because the REST
 * controllers it announces must be registered for REST requests too.
 *
 * Consumed by the articles, categories and tags apps.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether the Vue content manager replaces the core screens.
 *
 * @since 1.0.0
 */
class Licensio_KB_Content_Manager {

    /**
     * The Articles app page slug.
     *
     * @since 1.0.0
     * @var string
     */
    const ARTICLES_SLUG = 'licensio-kb-articles';

    /**
     * The Categories app page slug.
     *
     * @since 1.0.0
     * @var string
     */
    const CATEGORIES_SLUG = 'licensio-kb-categories';

    /**
     * The Tags app page slug.
     *
     * @since 1.0.0
     * @var string
     */
    const TAGS_SLUG = 'licensio-kb-tags';

    /**
     * The parent a hidden page is registered under.
     *
     * `options.php` has no menu entry of its own, so a page registered under
     * it is reachable at `admin.php?page={slug}` without appearing in any
     * menu. An empty parent would also hide the page, but core then cannot
     * find the page's title, and the browser tab and the admin header show an
     * empty title.
     *
     * @since 1.0.0
     * @var string
     */
    const HIDDEN_PARENT = 'options.php';

    /**
     * The setting that turns the content manager off.
     *
     * @since 1.0.0
     * @var string
     */
    const CLASSIC_SCREENS_SETTING = 'classic_screens';

    /**
     * Query arguments that mean a core list request is an action, not a view.
     *
     * Core runs its bulk actions and "Empty Trash" through `edit.php` itself.
     * A request carrying any of these is left alone so the core handler runs.
     *
     * @since 1.0.0
     * @var string[]
     */
    const ACTION_ARGS = array( 'action', 'action2', 'delete_all', 'delete_all2' );

    /**
     * Query arguments that mean a core term screen request is an action.
     *
     * Core runs single and bulk term deletes through `edit-tags.php` itself.
     * A request carrying any of these is left alone so the core handler runs.
     *
     * @since 1.0.0
     * @var string[]
     */
    const TERM_ACTION_ARGS = array( 'action', 'action2', 'delete_tags' );

    /**
     * The settings store.
     *
     * @since 1.0.0
     * @var Licensio_KB_Settings
     */
    private $settings;

    /**
     * The build asset loader, or null when the build is not checked.
     *
     * @since 1.0.0
     * @var Licensio_KB_Assets|null
     */
    private $assets;

    /**
     * Build the content manager.
     *
     * @since 1.0.0
     *
     * @param Licensio_KB_Settings    $settings The settings store.
     * @param Licensio_KB_Assets|null $assets   The build asset loader, whose manifest
     *                                          says whether the apps were built. Null
     *                                          skips that check.
     */
    public function __construct( Licensio_KB_Settings $settings, ?Licensio_KB_Assets $assets = null ) {
        $this->settings = $settings;
        $this->assets   = $assets;
    }

    /**
     * Register the WordPress hooks.
     *
     * The REST controllers are announced on every request. The menu removal
     * and the redirects are hooked only once the content manager is active;
     * while it is inactive they are not hooked at all. Their callbacks check
     * `enabled()` when they run rather than here, because the setting and the
     * filter must be read at request time, not while plugins are loading.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function init_hooks() {
        add_filter( 'licensio_rest_controllers', array( $this, 'register_rest_controllers' ) );

        if ( ! static::is_active() ) {
            return;
        }

        add_action( 'admin_menu', array( $this, 'remove_core_menu_items' ), 999 );
        add_filter( 'submenu_file', array( $this, 'current_submenu_file' ) );
        add_action( 'load-edit.php', array( $this, 'maybe_redirect' ) );
        add_action( 'load-edit-tags.php', array( $this, 'maybe_redirect' ) );
        add_action( 'load-term.php', array( $this, 'maybe_redirect' ) );
    }

    /**
     * Whether the content manager is switched on in this build.
     *
     * The build-time switch, separate from the per-site escape hatch in
     * `enabled()`. It is on now that the articles, categories and tags apps
     * are finished.
     *
     * @since 1.0.0
     *
     * @return bool Always true.
     */
    public static function is_active() {
        return true;
    }

    /**
     * Whether the Vue apps replace the core screens on this request.
     *
     * True only when the content manager is active, the classic screens
     * setting is off, the apps can load, and the
     * `licensio_kb_use_content_manager` filter agrees. Without a build of the
     * apps (and no dev server) the core screens stay, rather than redirecting
     * to apps that never finish loading.
     *
     * @since 1.0.0
     *
     * @return bool True when the core screens should redirect to the apps.
     */
    public function enabled() {
        if ( ! static::is_active() ) {
            return false;
        }

        if ( $this->settings->get( self::CLASSIC_SCREENS_SETTING, false ) ) {
            return false;
        }

        if ( ! $this->apps_built() ) {
            return false;
        }

        /**
         * Filter whether the content manager replaces the core screens.
         *
         * Return false to keep the core article, category and tag screens.
         * The Vue apps stay reachable by URL either way.
         *
         * @since 1.0.0
         *
         * @param bool $use_content_manager Whether to use the content manager. Default true.
         */
        return (bool) apply_filters( 'licensio_kb_use_content_manager', true );
    }

    /**
     * Whether the Articles, Categories and Tags apps can load.
     *
     * True when the build manifest has a file for each app, or when the
     * Vite dev server serves them.
     *
     * @since 1.0.0
     * @since 1.0.0 Asks the asset loader whether the apps can load.
     * @since 1.0.0 Reads the apps from content_entries().
     *
     * @return bool True when the apps can load, or when no asset loader was given.
     */
    private function apps_built() {
        if ( null === $this->assets ) {
            return true;
        }

        return $this->assets->apps_can_load( self::content_entries() );
    }

    /**
     * The Vite entries of the Articles, Categories and Tags apps.
     *
     * The apps that replace the core screens. Without all three built the
     * core screens stay. The build notice reads the same list to say so.
     *
     * @since 1.0.0
     *
     * @return string[] Source paths relative to the plugin root.
     */
    public static function content_entries() {
        return array(
            Licensio_KB_Articles_Admin::APP_ENTRY,
            Licensio_KB_Categories_Admin::APP_ENTRY,
            Licensio_KB_Tags_Admin::APP_ENTRY,
        );
    }

    /**
     * Add the content manager's REST controllers to the registrar's list.
     *
     * @since 1.0.0
     *
     * @param string[]|mixed $controllers The registered controller class names.
     *
     * @return string[] The list, with the content manager controllers added.
     */
    public function register_rest_controllers( $controllers ) {
        $controllers   = is_array( $controllers ) ? $controllers : array();
        $controllers[] = 'Licensio_KB_Terms_REST_Controller';
        $controllers[] = 'Licensio_KB_Category_Order_REST_Controller';

        return $controllers;
    }

    /**
     * Register an app page in the menu, or hidden when the apps are off.
     *
     * While the content manager is enabled the page goes into the Knowledge
     * Base menu at its `submenu_position`, which core counts from the start of
     * the submenu. The core items are still there when this runs (they are
     * removed on `admin_menu` at priority 999), so position 1 puts All Articles
     * straight after the core list item and before Add New, and positions 3
     * and 4 put Categories and Tags after Add New. Once the core items are
     * removed the order is All Articles, Add New, Categories, Tags.
     *
     * While the classic screens are in use the page is registered hidden, so
     * the menu does not list two All Articles items and the app stays
     * reachable by URL. Call it from an `admin_menu` callback.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $page The page definition, with the keys of a
     *                                   `licensio_admin_menu_pages` entry.
     *
     * @return string The hook suffix, or an empty string when the page was not registered.
     */
    public function register_page( array $page ) {
        if ( ! $this->enabled() ) {
            return static::register_hidden_page( $page );
        }

        $page = Licensio_KB_Admin_Menu::normalize_page( $page );

        if ( null === $page ) {
            return '';
        }

        $hook_suffix = add_submenu_page(
            Licensio_KB_Admin_Menu::PARENT_SLUG,
            $page['page_title'],
            $page['menu_title'],
            $page['capability'],
            $page['menu_slug'],
            $page['callback'],
            $page['submenu_position']
        );

        return is_string( $hook_suffix ) ? $hook_suffix : '';
    }

    /**
     * Register an admin page that has no menu entry.
     *
     * `register_page()` uses this while the classic screens are in use: the
     * page is reachable at
     * `admin.php?page={menu_slug}` (see `page_url()`) but nothing in the menu
     * links to it. Call it from an `admin_menu` callback.
     *
     * The definition takes the same keys as a `licensio_admin_menu_pages`
     * entry. A hidden page has no menu position, so `position` and
     * `submenu_position` are ignored.
     *
     * The returned hook suffix is `admin_page_{menu_slug}`. Compare it with
     * the `$hook_suffix` passed to `admin_enqueue_scripts` to load assets on
     * this page only.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $page {
     *     The page definition.
     *
     *     @type string   $menu_slug  Required. The page slug, `[a-z0-9_-]` only.
     *     @type callable $callback   Required. Renders the page.
     *     @type string   $page_title The browser title. Defaults to `menu_title`, then the slug.
     *     @type string   $capability Defaults to `manage_licensio_kb`.
     * }
     *
     * @return string The hook suffix, or an empty string when the page was not registered.
     */
    public static function register_hidden_page( array $page ) {
        $page = Licensio_KB_Admin_Menu::normalize_page( $page );

        if ( null === $page ) {
            return '';
        }

        $hook_suffix = add_submenu_page(
            self::HIDDEN_PARENT,
            $page['page_title'],
            $page['page_title'],
            $page['capability'],
            $page['menu_slug'],
            $page['callback']
        );

        return is_string( $hook_suffix ) ? $hook_suffix : '';
    }

    /**
     * The admin URL of a content manager page.
     *
     * The `admin.php?page=` form works whether the page is in the menu or,
     * with the classic screens, hidden, so a link or bookmark built with it
     * keeps working when the setting changes.
     *
     * @since 1.0.0
     *
     * @param string               $menu_slug The page slug.
     * @param array<string, mixed> $args      Optional query arguments to add.
     *
     * @return string The absolute admin URL.
     */
    public static function page_url( $menu_slug, array $args = array() ) {
        $url = admin_url( 'admin.php?page=' . rawurlencode( (string) $menu_slug ) );

        if ( empty( $args ) ) {
            return $url;
        }

        return add_query_arg( array_map( 'rawurlencode', array_map( 'strval', $args ) ), $url );
    }

    /**
     * The core submenu items the apps replace.
     *
     * Spelled exactly as core registers them, including the `&amp;` in the
     * taxonomy items, because `remove_submenu_page()` compares slugs as-is.
     * Add New stays in the menu.
     *
     * @since 1.0.0
     *
     * @return string[] The submenu slugs under the Knowledge Base menu.
     */
    public function core_menu_items() {
        $post_type = Licensio_KB_Post_Types::POST_TYPE;

        return array(
            'edit.php?post_type=' . $post_type,
            'edit-tags.php?taxonomy=' . Licensio_KB_Post_Types::TAXONOMY_CATEGORY . '&amp;post_type=' . $post_type,
            'edit-tags.php?taxonomy=' . Licensio_KB_Post_Types::TAXONOMY_TAG . '&amp;post_type=' . $post_type,
        );
    }

    /**
     * Remove the core list and term screens from the Knowledge Base menu.
     *
     * Hooked to `admin_menu` only once the content manager is active.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function remove_core_menu_items() {
        if ( ! $this->enabled() ) {
            return;
        }

        foreach ( $this->core_menu_items() as $item ) {
            remove_submenu_page( Licensio_KB_Admin_Menu::PARENT_SLUG, $item );
        }
    }

    /**
     * Mark an app's menu item as the current one.
     *
     * Core links the apps' menu items to
     * `edit.php?post_type=licensio_article&page={slug}`, as they sit under the
     * article post type's menu, and the apps also open at
     * `admin.php?page={slug}`, where redirects and the Knowledge Base menu's
     * own link send them. On that URL core opens the right menu but does not
     * mark the app's item as current, because the item's parent is a post
     * type screen, not `admin.php`.
     * Naming the app as the current submenu file is the comparison core
     * makes. Hooked to `submenu_file`.
     *
     * The article editor names the core article list as its submenu file.
     * That item is removed while the content manager is active, so the
     * Articles app takes its place. The Add New screen keeps its own item.
     *
     * @since 1.0.0
     *
     * @param string|null $submenu_file The submenu file core worked out.
     *
     * @return string|null The app's slug on an app page, the Articles app's slug for the core article list, otherwise the value unchanged.
     */
    public function current_submenu_file( $submenu_file ) {
        global $plugin_page;

        $apps = array( self::ARTICLES_SLUG, self::CATEGORIES_SLUG, self::TAGS_SLUG );

        if ( is_string( $plugin_page ) && in_array( $plugin_page, $apps, true ) && $this->enabled() ) {
            return $plugin_page;
        }

        if ( 'edit.php?post_type=' . Licensio_KB_Post_Types::POST_TYPE === $submenu_file && $this->enabled() ) {
            return self::ARTICLES_SLUG;
        }

        return $submenu_file;
    }

    /**
     * Send a core list or term screen request to the matching app.
     *
     * Hooked to `load-edit.php`, `load-edit-tags.php` and `load-term.php`
     * only once the content manager is active. A POST is always a core form
     * submission (adding, editing or bulk deleting terms), so it is never
     * redirected.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function maybe_redirect() {
        global $pagenow;

        if ( ! $this->enabled() ) {
            return;
        }

        if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) ) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only: the query only chooses where to send a screen view.
        $query  = wp_unslash( $_GET );
        $target = $this->redirect_url( is_string( $pagenow ) ? $pagenow : '', (array) $query );

        if ( '' === $target ) {
            return;
        }

        wp_safe_redirect( $target );
        exit;
    }

    /**
     * The app URL a core screen request should be sent to.
     *
     * - `edit.php` for the article post type, without an action, goes to the
     *   Articles app. `post_status`, `s`, `licensio_category` and
     *   `licensio_tag` become `status`, `search`, `category` and `tag`. The
     *   category and tag query vars always carry a slug, which becomes its ID.
     *   `author`, `orderby`, `order` and `paged` keep their names.
     * - `edit-tags.php` for either plugin taxonomy, without an action, goes
     *   to the Categories or Tags app, with `s` as `search`.
     * - `term.php` for either plugin taxonomy goes to the same app with the
     *   term's ID as `term`, so the app opens that term.
     *
     * Only these known arguments are carried over. Everything else returns an
     * empty string, meaning no redirect.
     *
     * @since 1.0.0
     *
     * @param string               $screen The admin file being loaded, e.g. `edit.php`.
     * @param array<string, mixed> $query  The request's query arguments, unslashed.
     *
     * @return string The absolute app URL, or an empty string when the request is not redirected.
     */
    public function redirect_url( $screen, array $query ) {
        if ( 'edit.php' === $screen ) {
            return $this->article_list_redirect( $query );
        }

        if ( 'edit-tags.php' !== $screen && 'term.php' !== $screen ) {
            return '';
        }

        $taxonomy = self::arg( $query, 'taxonomy' );
        $slugs    = array(
            Licensio_KB_Post_Types::TAXONOMY_CATEGORY => self::CATEGORIES_SLUG,
            Licensio_KB_Post_Types::TAXONOMY_TAG      => self::TAGS_SLUG,
        );

        if ( ! isset( $slugs[ $taxonomy ] ) ) {
            return '';
        }

        if ( 'edit-tags.php' === $screen && self::has_action( $query, self::TERM_ACTION_ARGS ) ) {
            return '';
        }

        $args = array();

        if ( 'term.php' === $screen ) {
            $term_id = absint( self::arg( $query, 'tag_ID' ) );

            if ( $term_id > 0 ) {
                $args['term'] = $term_id;
            }
        } elseif ( '' !== trim( self::arg( $query, 's' ) ) ) {
            $args['search'] = sanitize_text_field( self::arg( $query, 's' ) );
        }

        return self::page_url( $slugs[ $taxonomy ], $args );
    }

    /**
     * The Articles app URL for a core article list request.
     *
     * Besides the filters, the author, the sort and the list page are
     * carried over, checked the way the app reads them: the author only as
     * a positive ID, the sort only when the app can sort by it, and the page
     * only past the first. A negative number is dropped, not made positive.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $query The request's query arguments, unslashed.
     *
     * @return string The absolute app URL, or an empty string when the request is not redirected.
     */
    private function article_list_redirect( array $query ) {
        if ( Licensio_KB_Post_Types::POST_TYPE !== self::arg( $query, 'post_type' ) ) {
            return '';
        }

        if ( self::has_action( $query, self::ACTION_ARGS ) ) {
            return '';
        }

        $args   = array();
        $status = sanitize_key( self::arg( $query, 'post_status' ) );

        if ( '' !== $status ) {
            $args['status'] = $status;
        }

        if ( '' !== trim( self::arg( $query, 's' ) ) ) {
            $args['search'] = sanitize_text_field( self::arg( $query, 's' ) );
        }

        $filters = array(
            'category' => Licensio_KB_Post_Types::TAXONOMY_CATEGORY,
            'tag'      => Licensio_KB_Post_Types::TAXONOMY_TAG,
        );

        foreach ( $filters as $arg => $taxonomy ) {
            $term_id = $this->term_id( self::arg( $query, $taxonomy ), $taxonomy );

            if ( $term_id > 0 ) {
                $args[ $arg ] = $term_id;
            }
        }

        $author = (int) self::arg( $query, 'author' );

        if ( $author > 0 ) {
            $args['author'] = $author;
        }

        $orderby = sanitize_key( self::arg( $query, 'orderby' ) );

        if ( in_array( $orderby, Licensio_KB_Articles_REST_Controller::ORDERBY, true ) ) {
            $args['orderby'] = $orderby;
        }

        $order = strtolower( self::arg( $query, 'order' ) );

        if ( 'asc' === $order || 'desc' === $order ) {
            $args['order'] = $order;
        }

        $paged = (int) self::arg( $query, 'paged' );

        if ( $paged > 1 ) {
            $args['paged'] = $paged;
        }

        return self::page_url( self::ARTICLES_SLUG, $args );
    }

    /**
     * Whether a request carries any of the given action arguments.
     *
     * The empty bulk action placeholder `-1` is not an action.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $query The query arguments.
     * @param string[]             $keys  The action argument names.
     *
     * @return bool True when the request is a core action.
     */
    private static function has_action( array $query, array $keys ) {
        foreach ( $keys as $key ) {
            if ( isset( $query[ $key ] ) && '' !== $query[ $key ] && '-1' !== $query[ $key ] ) {
                return true;
            }
        }

        return false;
    }

    /**
     * One query argument as a string.
     *
     * An argument sent as an array (`?s[]=x`) reads as empty rather than as
     * the string "Array".
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $query The query arguments.
     * @param string               $key   The argument name.
     *
     * @return string The value, or an empty string when it is missing or not scalar.
     */
    private static function arg( array $query, $key ) {
        return isset( $query[ $key ] ) && is_scalar( $query[ $key ] ) ? (string) $query[ $key ] : '';
    }

    /**
     * Resolve a term given by slug to its ID.
     *
     * The `licensio_category` and `licensio_tag` query vars always carry a
     * slug, so an all-digit value such as `2024` is a slug, never an ID.
     *
     * @since 1.0.0
     *
     * @param string $value    A term slug.
     * @param string $taxonomy The taxonomy.
     *
     * @return int The term ID, or 0 when no such term exists.
     */
    private function term_id( $value, $taxonomy ) {
        if ( '' === $value ) {
            return 0;
        }

        $term = get_term_by( 'slug', sanitize_title( $value ), $taxonomy );

        return $term instanceof WP_Term ? (int) $term->term_id : 0;
    }
}
