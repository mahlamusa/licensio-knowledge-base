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
     * How many articles the Popular Articles widget lists.
     *
     * @since 1.0.0
     * @var int
     */
    const POPULAR_LIMIT = 5;

    /**
     * The shortcode twin of the sidebar, for the block template layer.
     *
     * @since 1.0.0
     * @var string
     */
    const SHORTCODE_TAG = 'lisensio_kb_sidebar';

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
     * Register the sidebar shortcode.
     *
     * The classic templates call `render()` directly; the block template layer
     * cannot, because a block template is static markup. The shortcode is the
     * one bridge both can cross, which is what keeps a block theme serving the
     * same sidebar the PHP templates do.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public static function init_hooks() {
        add_shortcode( self::SHORTCODE_TAG, array( __CLASS__, 'shortcode' ) );
    }

    /**
     * Render the sidebar shortcode.
     *
     * Accepts a `context` attribute — `archive` or `category` — so the same
     * tag serves both views with their own widget composition. An unknown
     * context renders nothing rather than guessing.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed>|string $atts The shortcode attributes.
     *
     * @return string The sidebar markup, or an empty string.
     */
    public static function shortcode( $atts = array() ) {
        $atts = shortcode_atts(
            array( 'context' => self::CONTEXT_ARCHIVE ),
            (array) $atts,
            self::SHORTCODE_TAG
        );

        return self::render( (string) $atts['context'] );
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
     * The rendered sidebar for a view.
     *
     * One composition per context, resolved here rather than repeated in every
     * template, so the archive and the category archives cannot drift apart
     * and the shortcode serves both with the same markup:
     *
     * - archive:  Popular Articles, then the Need Support card.
     * - category: the category list with the current one marked, then both of
     *   the archive widgets — the list is the wayfinding a category view adds.
     *
     * The article view keeps its own composition (table of contents,
     * categories, tags) and is not routed through here: its contents depend on
     * the article being rendered, which no context constant can name.
     *
     * @since 1.0.0
     *
     * @param string $context One of the CONTEXT_* constants.
     *
     * @return string The `<aside>` markup, or an empty string when the position is `none` or every section is empty.
     */
    public static function render( $context ) {
        if ( self::POSITION_NONE === self::position( $context ) ) {
            return '';
        }

        $args = array(
            'sticky'  => self::is_sticky(),
            'popular' => self::popular_articles(),
            'support' => self::support_url(),
        );

        if ( self::CONTEXT_CATEGORY === $context ) {
            $queried = get_queried_object();

            $args['categories'] = self::categories();
            $args['current']    = $queried instanceof WP_Term && Lisensio_KB_Post_Types::TAXONOMY_CATEGORY === $queried->taxonomy
                ? (int) $queried->term_id
                : 0;
        }

        return lisensio_kb_get_template( 'sidebar', $args, false );
    }

    /**
     * The articles the Popular Articles widget lists.
     *
     * The most-viewed articles when analytics are both enabled and non-empty,
     * and the most recently published ones otherwise — a knowledge base with
     * no recorded views yet still has a widget worth reading rather than an
     * empty panel on a fresh install.
     *
     * @since 1.0.0
     *
     * @param int $limit How many articles to list. Default self::POPULAR_LIMIT.
     *
     * @return WP_Post[] The articles, most viewed or newest first.
     */
    public static function popular_articles( $limit = self::POPULAR_LIMIT ) {
        $limit = max( 1, (int) apply_filters( 'lisensio_kb_popular_limit', (int) $limit ) );
        $posts = array();

        if ( (bool) self::settings()->get( 'enable_analytics', true ) ) {
            $rows = Lisensio_KB_Analytics_Repository::top_articles( '0000-00-00 00:00:00', gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ), $limit );

            foreach ( $rows as $row ) {
                $post = get_post( (int) $row['article_id'] );

                if ( $post instanceof WP_Post && 'publish' === $post->post_status ) {
                    $posts[] = $post;
                }

                if ( count( $posts ) >= $limit ) {
                    break;
                }
            }
        }

        if ( count( $posts ) < $limit ) {
            $recent = get_posts(
                array(
                    'post_type'              => Lisensio_KB_Post_Types::POST_TYPE,
                    'post_status'            => 'publish',
                    'posts_per_page'         => $limit,
                    'orderby'                => 'date',
                    'order'                  => 'DESC',
                    'ignore_sticky_posts'    => true,
                    'no_found_rows'          => true,
                    'update_post_term_cache' => false,
                )
            );

            foreach ( is_array( $recent ) ? $recent : array() as $candidate ) {
                $exists = false;

                foreach ( $posts as $post ) {
                    if ( (int) $post->ID === (int) $candidate->ID ) {
                        $exists = true;
                        break;
                    }
                }

                if ( ! $exists ) {
                    $posts[] = $candidate;
                }

                if ( count( $posts ) >= $limit ) {
                    break;
                }
            }
        }

        return $posts;
    }

    /**
     * The URL the Contact Support button leads to.
     *
     * Empty means the Need Support card is not shown at all: a button with
     * nowhere to go is worse than no button.
     *
     * @since 1.0.0
     *
     * @return string The URL, or an empty string.
     */
    public static function support_url() {
        /**
         * Filter the URL behind the sidebar Contact Support button.
         *
         * @since 1.0.0
         *
         * @param string $url The URL from the support_url setting, or an empty string.
         */
        $url = (string) apply_filters( 'lisensio_kb_support_url', (string) self::settings()->get( 'support_url' ) );

        return '' === $url ? '' : esc_url( $url );
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

    /**
     * Whether a value is a published article.
     *
     * The popular list is built from analytics rows and a recent-posts
     * fallback, and either source can hand back less than a live post: an
     * article deleted after its views were counted, or a value no caller
     * should have passed at all. The list is filtered rather than trusted.
     *
     * @since 1.0.0
     *
     * @param mixed $post The candidate.
     *
     * @return bool True when the value is a published article.
     */
    public static function is_post( $post ) {
        return $post instanceof WP_Post && 'publish' === $post->post_status
            && Lisensio_KB_Post_Types::POST_TYPE === $post->post_type;
    }
}
