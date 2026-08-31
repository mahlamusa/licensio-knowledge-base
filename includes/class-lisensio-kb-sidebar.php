<?php
/**
 * Sidebar composition.
 *
 * #29 registered `archive_sidebar_position`, `article_sidebar_position`,
 * `category_sidebar_position` and `sticky_sidebar` for a theme to consume, and
 * nothing rendered a sidebar. This is the consumer: it answers which side the
 * sidebar sits on for a given view, whether it follows the reader down the
 * page, and what goes in it. No new position setting is introduced — the three
 * that exist are the three that are read.
 *
 * The settings are read through a store this class creates for itself rather
 * than one handed to it, because the callers are templates, and a template
 * that has to be given a settings object is a template a theme cannot override
 * without knowing how the plugin is wired.
 *
 * Consumed by: #36.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Resolves sidebar position, stickiness and contents.
 *
 * @since 1.0.0
 */
class Lisensio_KB_Sidebar {

    /**
     * The single article view.
     *
     * @since 1.0.0
     * @var string
     */
    const CONTEXT_ARTICLE = 'article';

    /**
     * The category archive view.
     *
     * @since 1.0.0
     * @var string
     */
    const CONTEXT_CATEGORY = 'category';

    /**
     * The knowledge base archive view.
     *
     * @since 1.0.0
     * @var string
     */
    const CONTEXT_ARCHIVE = 'archive';

    /**
     * The setting each view reads its position from.
     *
     * @since 1.0.0
     * @var array<string, string>
     */
    const POSITION_SETTINGS = array(
        self::CONTEXT_ARTICLE  => 'article_sidebar_position',
        self::CONTEXT_CATEGORY => 'category_sidebar_position',
        self::CONTEXT_ARCHIVE  => 'archive_sidebar_position',
    );

    /**
     * The position that renders no sidebar at all.
     *
     * @since 1.0.0
     * @var string
     */
    const POSITION_NONE = 'none';

    /**
     * How many tags a sidebar lists before it stops.
     *
     * The reference implementation lists forty; the cap exists so that a site
     * with three thousand tags does not put all of them in a sidebar.
     *
     * @since 1.0.0
     * @var int
     */
    const TAG_LIMIT = 40;

    /**
     * The settings store, created on first use.
     *
     * @since 1.0.0
     * @var Lisensio_KB_Settings|null
     */
    private static $settings = null;

    /**
     * The settings store this class reads.
     *
     * @since 1.0.0
     *
     * @return Lisensio_KB_Settings The store.
     */
    public static function settings() {
        if ( ! self::$settings instanceof Lisensio_KB_Settings ) {
            self::$settings = new Lisensio_KB_Settings();
        }

        return self::$settings;
    }

    /**
     * Drop the memoized settings store.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public static function reset() {
        self::$settings = null;
    }

    /**
     * Which side the sidebar sits on for a view.
     *
     * @since 1.0.0
     *
     * @param string $context One of the CONTEXT_* constants.
     *
     * @return string `left`, `right` or `none`.
     */
    public static function position( $context ) {
        $context = (string) $context;
        $key     = isset( self::POSITION_SETTINGS[ $context ] ) ? self::POSITION_SETTINGS[ $context ] : '';

        if ( '' === $key ) {
            return self::POSITION_NONE;
        }

        $position = (string) self::settings()->get( $key );

        if ( ! in_array( $position, Lisensio_KB_Settings::SIDEBAR_POSITIONS, true ) ) {
            $position = self::POSITION_NONE;
        }

        /**
         * Filter the sidebar position for one view.
         *
         * @since 1.0.0
         *
         * @param string $position `left`, `right` or `none`.
         * @param string $context  The view the position was asked for.
         */
        $position = (string) apply_filters( 'lisensio_sidebar_position', $position, $context );

        return in_array( $position, Lisensio_KB_Settings::SIDEBAR_POSITIONS, true ) ? $position : self::POSITION_NONE;
    }

    /**
     * Whether the sidebar follows the reader down a long page.
     *
     * @since 1.0.0
     *
     * @return bool True when the sidebar is sticky.
     */
    public static function is_sticky() {
        return (bool) self::settings()->get( 'sticky_sidebar' );
    }

    /**
     * Whether a table of contents should be built at all.
     *
     * Reads two settings, not one. The list lives in the sidebar, so an
     * article with no sidebar has nowhere to put it — and because the content
     * filter asks this same question, `article_sidebar_position = none` also
     * means the article body is handed back exactly as it renders today,
     * without so much as an `id` added to it.
     *
     * @since 1.0.0
     *
     * @return bool True when the table of contents is wanted.
     */
    public static function is_toc_enabled() {
        $enabled = (bool) self::settings()->get( 'show_table_of_contents' )
            && self::POSITION_NONE !== self::position( self::CONTEXT_ARTICLE );

        /**
         * Filter whether the article table of contents is built.
         *
         * Returning true on a view whose sidebar position is `none` gives the
         * headings their ids without rendering a list, which is how a theme
         * that renders its own table of contents gets something to link to.
         *
         * @since 1.0.0
         *
         * @param bool $enabled Whether the table of contents is wanted.
         */
        return (bool) apply_filters( 'lisensio_toc_enabled', $enabled );
    }

    /**
     * The modifier class that encodes the sidebar position.
     *
     * Follows the reference implementation, which carries the side in the
     * layout class (`--sidebarright`) rather than in an inline style, so a
     * theme can restyle or ignore it with a selector.
     *
     * @since 1.0.0
     *
     * @param string $position `left`, `right` or `none`.
     *
     * @return string The class name, or an empty string when there is no sidebar.
     */
    public static function modifier_class( $position ) {
        $position = (string) $position;

        if ( ! in_array( $position, Lisensio_KB_Settings::SIDEBAR_POSITIONS, true ) || self::POSITION_NONE === $position ) {
            return '';
        }

        return 'lisensio-kb--sidebar' . $position;
    }

    /**
     * The categories the sidebar lists.
     *
     * The top-level categories of the whole knowledge base, not the ones the
     * article happens to be filed under. A sidebar is for leaving the page you
     * are on: the two categories this article belongs to are already named in
     * the breadcrumb trail directly above it, so listing them again is a
     * navigation aid that navigates nowhere. The reference implementation
     * lists eight, which is the site's categories rather than one article's.
     *
     * @since 1.0.0
     *
     * @return WP_Term[] The categories, empty when none have articles.
     */
    public static function categories() {
        return Lisensio_KB_Templates::top_level_categories();
    }

    /**
     * The tags the sidebar lists.
     *
     * An article's own tags when there is an article, because those are the
     * lateral links a reader of that article wants. A category view has no
     * single article to ask, so it lists the tags in use across the knowledge
     * base instead of rendering an empty section.
     *
     * @since 1.0.0
     *
     * @param WP_Post|null $post The article, or null on a view that has none.
     *
     * @return WP_Term[] The tags.
     */
    public static function tags( $post = null ) {
        if ( $post instanceof WP_Post ) {
            $terms = get_the_terms( $post, Lisensio_KB_Post_Types::TAXONOMY_TAG );

            return is_array( $terms ) ? array_values( array_filter( $terms, array( __CLASS__, 'is_term' ) ) ) : array();
        }

        $terms = get_terms(
            array(
                'taxonomy'   => Lisensio_KB_Post_Types::TAXONOMY_TAG,
                'hide_empty' => true,
                'orderby'    => 'name',
                'order'      => 'ASC',
                'number'     => self::TAG_LIMIT,
            )
        );

        return is_array( $terms ) ? array_values( array_filter( $terms, array( __CLASS__, 'is_term' ) ) ) : array();
    }

    /**
     * The category an article is filed in, for marking the list.
     *
     * An article can sit in several categories; the sidebar highlights one, so
     * this returns the lowest term ID - the earliest created, which for a
     * hierarchy built top-down is the broadest. It is an arbitrary rule, but a
     * stable one: the alternative is whichever order the database happened to
     * return, so the highlight would move between requests on the same article.
     *
     * @since 1.0.0
     *
     * @param WP_Post|null $post The article, or null for none.
     *
     * @return int The term ID, or 0 when the article is uncategorised.
     */
    public static function current_category( $post = null ) {
        if ( ! $post instanceof WP_Post ) {
            return 0;
        }

        $terms = get_the_terms( $post, Lisensio_KB_Post_Types::TAXONOMY_CATEGORY );

        if ( ! is_array( $terms ) || empty( $terms ) ) {
            return 0;
        }

        $ids = array();

        foreach ( $terms as $term ) {
            if ( self::is_term( $term ) ) {
                $ids[] = (int) $term->term_id;
            }
        }

        return empty( $ids ) ? 0 : min( $ids );
    }

    /**
     * Whether a value is a term.
     *
     * `get_the_terms()` and `get_terms()` both answer with a WP_Error or with
     * term ids depending on how they are called and what went wrong, so the
     * arrays they return are filtered rather than trusted.
     *
     * @since 1.0.0
     *
     * @param mixed $term The candidate.
     *
     * @return bool True when the value is a WP_Term.
     */
    public static function is_term( $term ) {
        return $term instanceof WP_Term;
    }
}
