<?php
/**
 * Shared admin menu registration.
 *
 * Scaffold infrastructure, promoted here because it has two named consumers:
 * the analytics dashboard (#9) and the settings screen (#10). Both need a page
 * under the Knowledge Base menu, and the scaffold ledger's two-consumer rule
 * makes a shared seam the correct owner rather than whichever issue happened to
 * land first. Inlining `add_submenu_page()` in each consumer would duplicate
 * the parent slug, the capability decision and the ordering rules across two
 * branches that never see each other.
 *
 * A consumer registers by filtering `licensio_admin_menu_pages`; it never calls
 * `add_submenu_page()` itself. Registration happens in one place, in one order,
 * with one capability default, and every page lands under the article post
 * type's own menu so the Knowledge Base menu stays a single tree.
 *
 * Consumed by: #9, #10.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the plugin's admin submenu pages.
 *
 * @since 1.0.0
 */
class Licensio_KB_Admin_Menu {

    /**
     * The parent menu every knowledge base page hangs from.
     *
     * The article post type is registered with `show_in_menu => true` and a
     * `menu_name` of "Knowledge Base", so core already renders the top-level
     * menu. Adding pages to it keeps Articles, Categories, Tags, Feedback,
     * Analytics and Settings in one tree instead of two.
     *
     * @since 1.0.0
     * @var string
     */
    const PARENT_SLUG = 'edit.php?post_type=' . Licensio_KB_Post_Types::POST_TYPE;

    /**
     * Hook suffixes returned by `add_submenu_page()`, keyed by menu slug.
     *
     * Static so a consumer can ask for its own hook suffix from an
     * `admin_enqueue_scripts` callback without holding a reference to the
     * instance that registered it.
     *
     * @since 1.0.0
     * @var array<string, string>
     */
    private static $hook_suffixes = array();

    /**
     * Register the WordPress hooks.
     *
     * Priority 20 so the post type's own menu — registered by core on `init`
     * and rendered from `admin_menu` at the default priority — already exists.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function init_hooks() {
        add_action( 'admin_menu', array( $this, 'register_pages' ), 20 );
        add_action( 'admin_enqueue_scripts', array( $this, 'style_menu_icon' ) );
    }

    /**
     * Size the menu icon like the rest of the rail.
     *
     * WordPress draws a plugin's SVG menu icon as an `img` and gives it
     * `padding: 9px 0 0` without a height, so the icon renders 9px taller than
     * the dashicon glyphs above it and sits low in its row. The rule is scoped
     * to this one menu item by its slug, so nothing else in the rail moves.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function style_menu_icon() {
        $selector = '#adminmenu .menu-icon-' . Licensio_KB_Post_Types::POST_TYPE . ' .wp-menu-image img';

        wp_add_inline_style(
            'wp-admin',
            $selector . '{width:20px;height:20px;padding:7px 0 0;}'
        );
    }

    /**
     * The pages consumers have asked for, normalised and ordered.
     *
     * @since 1.0.0
     *
     * @return array<int, array<string, mixed>> The page definitions, lowest position first.
     */
    public function pages() {
        /**
         * Filter the submenu pages registered under the Knowledge Base menu.
         *
         * Each entry is an array with:
         *
         * - `menu_slug`  — required, the page slug. `[a-z0-9_-]` only.
         * - `callback`   — required, the callable that renders the page.
         * - `page_title` — the browser title. Defaults to `menu_title`.
         * - `menu_title` — the menu label. Defaults to `menu_slug`.
         * - `capability` — defaults to `manage_licensio_kb`.
         * - `position`   — sort order among these pages. Defaults to 10.
         * - `submenu_position` — optional. Passed to `add_submenu_page()` as
         *   its `$position`, the index in the whole submenu, so a page can sit
         *   before core's own items. Left out, nothing is passed and the page
         *   is appended after core's items, as before.
         *
         * @since 1.0.0
         *
         * @param array<int, array<string, mixed>> $pages The page definitions.
         */
        $pages = apply_filters( 'licensio_admin_menu_pages', array() );

        $normalized = array();

        foreach ( (array) $pages as $page ) {
            $page = self::normalize_page( $page );

            if ( null !== $page ) {
                $normalized[] = $page;
            }
        }

        /*
         * A stable sort. usort() is not stable before PHP 8.0, and this plugin
         * supports 7.4, so two pages registered at the same position would
         * otherwise change places between PHP versions.
         */
        $ordered = array();

        foreach ( $normalized as $index => $page ) {
            $ordered[] = array( $page['position'], $index, $page );
        }

        usort(
            $ordered,
            function ( $left, $right ) {
                return ( $left[0] === $right[0] ) ? ( $left[1] <=> $right[1] ) : ( $left[0] <=> $right[0] );
            }
        );

        return array_map(
            function ( $entry ) {
                return $entry[2];
            },
            $ordered
        );
    }

    /**
     * Validate and fill in one page definition.
     *
     * Also used by `Licensio_KB_Content_Manager::register_hidden_page()`, so
     * menu pages and hidden pages follow the same rules.
     *
     * @since 1.0.0
     *
     * @param mixed $page The raw definition supplied by a consumer.
     *
     * @return array<string, mixed>|null The normalised definition, or null when it is unusable.
     */
    public static function normalize_page( $page ) {
        if ( ! is_array( $page ) || empty( $page['menu_slug'] ) || empty( $page['callback'] ) ) {
            return null;
        }

        $slug = (string) $page['menu_slug'];

        /*
         * The slug becomes part of a URL and part of a hook name. Holding it to
         * a plain slug keeps a filter-supplied value from becoming either.
         */
        if ( ! preg_match( '/^[a-z0-9_-]+$/', $slug ) || ! is_callable( $page['callback'] ) ) {
            return null;
        }

        $menu_title = isset( $page['menu_title'] ) ? (string) $page['menu_title'] : $slug;

        return array(
            'menu_slug'        => $slug,
            'callback'         => $page['callback'],
            'menu_title'       => $menu_title,
            'page_title'       => isset( $page['page_title'] ) ? (string) $page['page_title'] : $menu_title,
            'capability'       => isset( $page['capability'] ) ? (string) $page['capability'] : Licensio_KB_Capabilities::CAPABILITY,
            'position'         => isset( $page['position'] ) ? (int) $page['position'] : 10,
            'submenu_position' => isset( $page['submenu_position'] ) && is_numeric( $page['submenu_position'] ) ? (int) $page['submenu_position'] : null,
        );
    }

    /**
     * Add every registered page to the Knowledge Base menu.
     *
     * A page's `submenu_position` is passed on as core's `$position`; a page
     * without one passes null, which is core's own default.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function register_pages() {
        foreach ( $this->pages() as $page ) {
            $hook_suffix = add_submenu_page(
                self::PARENT_SLUG,
                $page['page_title'],
                $page['menu_title'],
                $page['capability'],
                $page['menu_slug'],
                $page['callback'],
                $page['submenu_position']
            );

            if ( is_string( $hook_suffix ) && '' !== $hook_suffix ) {
                self::$hook_suffixes[ $page['menu_slug'] ] = $hook_suffix;
            }
        }
    }

    /**
     * The hook suffix a registered page was given.
     *
     * Consumers compare this against the `$hook_suffix` passed to
     * `admin_enqueue_scripts` so they load their assets on their own screen and
     * nowhere else.
     *
     * @since 1.0.0
     *
     * @param string $menu_slug The page slug.
     *
     * @return string The hook suffix, or an empty string when the page is not registered.
     */
    public static function hook_suffix( $menu_slug ) {
        return isset( self::$hook_suffixes[ $menu_slug ] ) ? self::$hook_suffixes[ $menu_slug ] : '';
    }

    /**
     * The admin URL of a registered page.
     *
     * @since 1.0.0
     *
     * @param string $menu_slug The page slug.
     *
     * @return string The absolute admin URL.
     */
    public static function page_url( $menu_slug ) {
        return admin_url( self::PARENT_SLUG . '&page=' . rawurlencode( (string) $menu_slug ) );
    }

    /**
     * Forget every recorded hook suffix.
     *
     * Only needed so a test can register the menu more than once in one
     * process; nothing in the plugin calls it.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public static function reset() {
        self::$hook_suffixes = array();
    }
}
