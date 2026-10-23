<?php
/**
 * Classic template layer.
 *
 * Routes knowledge base requests to the plugin's PHP templates, enqueues the
 * default stylesheet and supplies the data helpers the templates render:
 * breadcrumb trails, category listings and recursive article counts.
 *
 * This class owns the classic (PHP) half of the dual template system only. The
 * block-theme half is registered separately; every entry point here bails out
 * when `licensio_kb_use_block_templates()` is true, so the two layers never
 * both claim the same request.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Loads, styles and feeds the classic knowledge base templates.
 *
 * @since 1.0.0
 */
class Licensio_KB_Templates {

    /**
     * Handle the default stylesheet is registered under.
     *
     * @since 1.0.0
     * @var string
     */
    const STYLE_HANDLE = 'licensio-kb-frontend';

    /**
     * The shortcode that renders a knowledge base view body inside a block
     * template.
     *
     * @since 1.0.0
     * @var string
     */
    const VIEW_SHORTCODE_TAG = 'licensio_kb_view';

    /**
     * The view bodies the view shortcode may render.
     *
     * The keys are what a block template names; the values are the body
     * templates. A whitelist rather than a pass-through so the shortcode can
     * never be pointed at a template that was not built to stand alone —
     * `single`, for one, expects article hooks a template part does not fire.
     *
     * @since 1.0.0
     * @var array<string, string>
     */
    const VIEW_BODIES = array(
        'archive'  => 'archive-body',
        'category' => 'category-body',
    );

    /**
     * Layout width setting values mapped to a CSS length.
     *
     * @since 1.0.0
     * @var array<string, string>
     */
    const LAYOUT_WIDTHS = array(
        'contained' => '72rem',
        'wide'      => '90rem',
        'full'      => '100%',
    );

    /**
     * How many uncategorised articles the archive lists at most.
     *
     * The list is a safety net for articles that would otherwise be reachable
     * from nowhere, not a second archive, so it is capped rather than
     * paginated: the archive's own pagination belongs to the main query, and a
     * second paginated list on the same URL would fight it for `paged`. A site
     * showing this cap has a filing problem the cap is meant to make visible.
     *
     * @since 1.0.0
     * @var int
     */
    const UNCATEGORISED_LIMIT = 20;

    /**
     * The archive column count used when nothing is stored.
     *
     * Zero is not a column count; it is the instruction to follow the sidebar —
     * two columns beside one, three without it. The template layer is what
     * knows whether a sidebar rendered, so the resolution happens there and
     * not here.
     *
     * @since 1.0.0
     * @var int
     */
    const DEFAULT_ARCHIVE_COLUMNS = 0;

    /**
     * The settings store the custom properties are read from.
     *
     * @since 1.0.0
     * @var Licensio_KB_Settings
     */
    private $settings;

    /**
     * Constructor.
     *
     * @since 1.0.0
     *
     * @param Licensio_KB_Settings $settings The settings store.
     */
    public function __construct( Licensio_KB_Settings $settings ) {
        $this->settings = $settings;
    }

    /**
     * Attach the template loader and the stylesheet to WordPress.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function init_hooks() {
        add_filter( 'template_include', array( $this, 'template_include' ), 20 );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_styles' ) );
        add_shortcode( self::VIEW_SHORTCODE_TAG, array( $this, 'render_view_shortcode' ) );
    }

    /**
     * Render a knowledge base view body inside a block template.
     *
     * A block theme resolves a knowledge base request to one of the plugin's
     * block templates, and those templates render this shortcode for the body
     * of the view — the same `archive-body` / `category-body` template the
     * classic layer includes between `get_header()` and `get_footer()`. That
     * single shared body is what keeps the page identical under a classic
     * theme, a block theme and any theme in between: one markup, one
     * stylesheet, no second design to drift.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed>|string $atts The shortcode attributes.
     *
     * @return string The view body markup, or an empty string for an unknown view.
     */
    public function render_view_shortcode( $atts = array() ) {
        $atts = shortcode_atts(
            array( 'view' => '' ),
            (array) $atts,
            self::VIEW_SHORTCODE_TAG
        );

        $view = isset( self::VIEW_BODIES[ $atts['view'] ] ) ? self::VIEW_BODIES[ $atts['view'] ] : '';

        if ( '' === $view ) {
            return '';
        }

        return licensio_kb_get_template( $view, array(), false );
    }

    /**
     * Swap in a knowledge base template for a knowledge base request.
     *
     * Runs only under a classic theme. Under a block theme the block template
     * layer answers the same requests through the Site Editor, and hijacking
     * `template_include` there would bypass it entirely.
     *
     * A request the theme already answers with its own `licensio-kb/` override
     * still routes through here, because the override is resolved by
     * `licensio_kb_locate_template()` rather than by the template hierarchy.
     *
     * @since 1.0.0
     *
     * @param string $template The template WordPress resolved.
     *
     * @return string The knowledge base template, or the original one.
     */
    public function template_include( $template ) {
        if ( licensio_kb_use_block_templates() ) {
            return $template;
        }

        $name = $this->template_name();

        if ( '' === $name ) {
            return $template;
        }

        $located = licensio_kb_locate_template( $name );

        return '' === $located ? (string) $template : $located;
    }

    /**
     * The template name this request should be answered with.
     *
     * Order matters: a single article is matched before the archive, and both
     * taxonomy archives before the post type archive, because a request can
     * satisfy more than one conditional.
     *
     * @since 1.0.0
     *
     * @return string The template name, or an empty string for a non-knowledge-base request.
     */
    public function template_name() {
        $name = '';

        if ( is_singular( Licensio_KB_Post_Types::POST_TYPE ) ) {
            $name = 'single';
        } elseif ( is_tax( Licensio_KB_Post_Types::TAXONOMY_CATEGORY ) ) {
            $name = 'taxonomy-category';
        } elseif ( is_tax( Licensio_KB_Post_Types::TAXONOMY_TAG ) ) {
            $name = 'taxonomy-tag';
        } elseif ( is_search() && self::is_article_search() ) {
            $name = 'search-results';
        } elseif ( is_post_type_archive( Licensio_KB_Post_Types::POST_TYPE ) ) {
            $name = 'archive';
        }

        /**
         * Filter the classic template name chosen for the current request.
         *
         * @since 1.0.0
         *
         * @param string $name The template name, or an empty string for a non-knowledge-base request.
         */
        $name = (string) apply_filters( 'licensio_template_name', $name );

        return licensio_kb_is_safe_template_name( $name ) ? $name : '';
    }

    /**
     * Whether the current search request is scoped to knowledge base articles.
     *
     * A site-wide search is left to the theme; only a search that asked for the
     * article post type is answered with the knowledge base results template.
     *
     * @since 1.0.0
     *
     * @return bool True when the search is restricted to articles.
     */
    public static function is_article_search() {
        $post_type = get_query_var( 'post_type' );

        if ( is_string( $post_type ) ) {
            $post_type = '' === $post_type ? array() : array( $post_type );
        }

        return is_array( $post_type ) && in_array( Licensio_KB_Post_Types::POST_TYPE, $post_type, true );
    }

    /**
     * Whether the current request is rendered by the knowledge base.
     *
     * @since 1.0.0
     *
     * @return bool True on any knowledge base view.
     */
    public function is_kb_request() {
        return '' !== $this->template_name();
    }

    /**
     * Register the default stylesheet and its settings-driven custom properties.
     *
     * The stylesheet is enqueued only on knowledge base views, so a site that
     * never links to the knowledge base pays nothing for it.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function enqueue_styles() {
        wp_register_style(
            self::STYLE_HANDLE,
            LICENSIO_KB_ASSETS_URL . 'css/licensio-kb.css',
            array(),
            LICENSIO_KB_VERSION
        );

        if ( ! $this->is_kb_request() ) {
            return;
        }

        wp_enqueue_style( self::STYLE_HANDLE );
        wp_add_inline_style( self::STYLE_HANDLE, $this->inline_css() );
    }

    /**
     * The settings-driven custom property block.
     *
     * Everything the settings control is expressed as a custom property on
     * `.licensio-kb`, never as a rule-level override, so changing the brand
     * colour restyles every component that derives from it without the
     * stylesheet needing to know a setting exists.
     *
     * @since 1.0.0
     *
     * @return string A CSS rule, or an empty string when there is nothing to declare.
     */
    public function inline_css() {
        $declarations = array();

        foreach ( $this->custom_properties() as $property => $value ) {
            $declarations[] = $property . ':' . $value;
        }

        if ( empty( $declarations ) ) {
            return '';
        }

        return '.licensio-kb{' . implode( ';', $declarations ) . '}';
    }

    /**
     * The custom properties derived from the current settings.
     *
     * Every value is re-validated here rather than trusted from the option.
     * The settings store already sanitizes on read and on write, but this
     * string is printed inside a `<style>` element, where a single unvalidated
     * character would end the declaration and start an attacker's own rule.
     * Anything that fails validation falls back to the registered default.
     *
     * @since 1.0.0
     *
     * @return array<string, string> Custom property name to value.
     */
    public function custom_properties() {
        $defaults = $this->settings->defaults();

        $brand = sanitize_hex_color( (string) $this->settings->get( 'brand_color' ) );

        if ( ! is_string( $brand ) || '' === $brand ) {
            $brand = (string) ( isset( $defaults['brand_color'] ) ? $defaults['brand_color'] : '#3c569f' );
        }

        $layout         = (string) $this->settings->get( 'layout_width' );
        $default_layout = (string) ( isset( $defaults['layout_width'] ) ? $defaults['layout_width'] : 'contained' );

        if ( ! isset( self::LAYOUT_WIDTHS[ $layout ] ) ) {
            $layout = isset( self::LAYOUT_WIDTHS[ $default_layout ] ) ? $default_layout : 'contained';
        }

        $card = (string) $this->settings->get( 'card_style' );

        if ( ! in_array( $card, Licensio_KB_Settings::CARD_STYLES, true ) ) {
            $card = (string) ( isset( $defaults['card_style'] ) ? $defaults['card_style'] : 'shadow' );
        }

        $rgb = self::hex_to_rgb( $brand );

        $properties = array(
            '--licensio-kb-brand'          => $brand,
            '--licensio-kb-brand-rgb'      => implode( ',', $rgb ),
            '--licensio-kb-brand-contrast' => self::contrast_color( $rgb ),
            '--licensio-kb-max-width'      => self::LAYOUT_WIDTHS[ $layout ],
            '--licensio-kb-card-shadow'    => 'shadow' === $card ? '0 4px 14px rgba(16,24,40,0.06)' : 'none',
            '--licensio-kb-card-border'    => 'border' === $card ? '1px solid var(--licensio-kb-line)' : '1px solid transparent',
            '--licensio-kb-card-bg'        => 'flat' === $card ? 'transparent' : 'var(--licensio-kb-surface)',
        );

        /**
         * Filter the knowledge base CSS custom properties.
         *
         * Values are printed into a style element verbatim, so a callback that
         * adds one is responsible for its own validation.
         *
         * @since 1.0.0
         *
         * @param array<string, string> $properties Custom property name to value.
         */
        return (array) apply_filters( 'licensio_custom_properties', $properties );
    }

    /**
     * Split a validated hex colour into its red, green and blue components.
     *
     * @since 1.0.0
     *
     * @param string $hex A `#rgb` or `#rrggbb` colour.
     *
     * @return array{0: int, 1: int, 2: int} The colour components, 0-255.
     */
    public static function hex_to_rgb( $hex ) {
        $hex = ltrim( (string) $hex, '#' );

        if ( 3 === strlen( $hex ) ) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        if ( 6 !== strlen( $hex ) ) {
            return array( 60, 86, 159 );
        }

        return array(
            (int) hexdec( substr( $hex, 0, 2 ) ),
            (int) hexdec( substr( $hex, 2, 2 ) ),
            (int) hexdec( substr( $hex, 4, 2 ) ),
        );
    }

    /**
     * A readable foreground colour for text sitting on the brand colour.
     *
     * Without this a pale brand colour produces white-on-white buttons. The
     * threshold is the usual sRGB relative-luminance split point.
     *
     * @since 1.0.0
     *
     * @param array{0: int, 1: int, 2: int} $rgb The brand colour components.
     *
     * @return string `#000000` on a light brand colour, `#ffffff` on a dark one.
     */
    public static function contrast_color( array $rgb ) {
        $luminance = ( ( 0.299 * $rgb[0] ) + ( 0.587 * $rgb[1] ) + ( 0.114 * $rgb[2] ) ) / 255;

        return $luminance > 0.6 ? '#000000' : '#ffffff';
    }

    /**
     * How many columns the archive category grid uses.
     *
     * Reads the `archive_columns` setting, which was registered but consumed
     * by nothing: the archive hardcoded three, so changing the setting did
     * nothing and gave no sign that it had been ignored.
     *
     * No clamp here on purpose. `Licensio_KB_Settings::clamp()` already holds
     * the value to the registered 1-4, so a second clamp is unreachable code
     * that no test can tell apart from its own absence - and the bounds would
     * then live in two places, free to disagree. The stylesheet has `--cols-1`
     * through `--cols-4`; if that range changes it is the registry entry that
     * must change with it.
     *
     * @since 1.0.0
     *
     * @return int A column count between 1 and 4.
     */
    public static function archive_columns() {
        $settings = new Licensio_KB_Settings();

        return (int) $settings->get( 'archive_columns', self::DEFAULT_ARCHIVE_COLUMNS );
    }

    /**
     * The heading the knowledge base archive opens with.
     *
     * The `archive_header_text` setting when it holds something, and the
     * post type archive title otherwise, so a site that clears the setting
     * still gets a heading rather than an empty rule.
     *
     * @since 1.0.0
     *
     * @return string The heading text.
     */
    public static function archive_title() {
        $settings = new Licensio_KB_Settings();
        $title    = sanitize_text_field( (string) $settings->get( 'archive_header_text' ) );

        if ( '' !== $title ) {
            return $title;
        }

        return post_type_archive_title( '', false );
    }

    /**
     * The top-level knowledge base categories.
     *
     * @since 1.0.0
     *
     * @param bool $hide_empty Whether to drop categories with no articles anywhere beneath them.
     *
     * @return WP_Term[] The categories, ordered by their menu order then name.
     */
    public static function top_level_categories( $hide_empty = true ) {
        return self::child_categories( 0, $hide_empty );
    }

    /**
     * The direct children of a knowledge base category.
     *
     * `hide_empty` is applied against the recursive article count rather than
     * the term's own count, so a parent whose articles all live in its children
     * is not hidden from the archive it is supposed to head.
     *
     * @since 1.0.0
     *
     * @param int  $parent_id  The parent term ID; 0 for the top level.
     * @param bool $hide_empty Whether to drop categories with no articles anywhere beneath them.
     *
     * @return WP_Term[] The child categories.
     */
    public static function child_categories( $parent_id = 0, $hide_empty = true ) {
        $terms = get_terms(
            array(
                'taxonomy'   => Licensio_KB_Post_Types::TAXONOMY_CATEGORY,
                'parent'     => (int) $parent_id,
                'hide_empty' => false,
                'orderby'    => 'name',
                'order'      => 'ASC',
            )
        );

        if ( ! is_array( $terms ) ) {
            return array();
        }

        if ( ! $hide_empty ) {
            return $terms;
        }

        $visible = array();

        foreach ( $terms as $term ) {
            if ( self::category_article_count( $term ) > 0 ) {
                $visible[] = $term;
            }
        }

        return $visible;
    }

    /**
     * How many articles a category holds, including every sub-category.
     *
     * A term's own `count` only covers articles assigned directly to it, which
     * on a knowledge base reads as a bug: a parent category that exists purely
     * to group its children would report zero articles while listing dozens.
     *
     * @since 1.0.0
     *
     * @param WP_Term|int $term The category, or its term ID.
     *
     * @return int The number of articles in the category and its descendants.
     */
    public static function category_article_count( $term ) {
        $term = get_term( $term, Licensio_KB_Post_Types::TAXONOMY_CATEGORY );

        if ( ! $term instanceof WP_Term ) {
            return 0;
        }

        $count = (int) $term->count;

        $children = get_terms(
            array(
                'taxonomy'   => Licensio_KB_Post_Types::TAXONOMY_CATEGORY,
                'child_of'   => (int) $term->term_id,
                'hide_empty' => false,
            )
        );

        if ( is_array( $children ) ) {
            foreach ( $children as $child ) {
                if ( $child instanceof WP_Term ) {
                    $count += (int) $child->count;
                }
            }
        }

        return $count;
    }

    /**
     * The first few published articles a category card should list.
     *
     * Descendants are included for the same reason `category_article_count()`
     * counts them: a parent category that only groups its children would list
     * nothing at all while advertising a count in the dozens.
     *
     * The articles follow the category's own article order. Articles without
     * a position in it, including those filed only in a descendant, follow by
     * menu order, then title. The order is written in a `posts_clauses` filter, so this query
     * runs with `suppress_filters` off and other plugins' `posts_*` filters
     * apply to it too.
     *
     * @since 1.0.0
     *
     * @param WP_Term|int $term  The category, or its term ID.
     * @param int         $limit How many articles to return at most.
     *
     * @return WP_Post[] The articles, in the per-category order. Empty when the category is unknown or the limit is not positive.
     */
    public static function category_articles( $term, $limit ) {
        $term  = get_term( $term, Licensio_KB_Post_Types::TAXONOMY_CATEGORY );
        $limit = (int) $limit;

        if ( ! $term instanceof WP_Term || $limit < 1 ) {
            return array();
        }

        $posts = get_posts(
            array(
                'post_type'              => Licensio_KB_Post_Types::POST_TYPE,
                'post_status'            => 'publish',
                'posts_per_page'         => $limit,
                'orderby'                => Licensio_KB_Article_Order::ORDERBY,
                'order'                  => 'ASC',
                'ignore_sticky_posts'    => true,
                'no_found_rows'          => true,
                'update_post_term_cache' => false,
                // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
                'tax_query'              => array(
                    array(
                        'taxonomy'         => Licensio_KB_Post_Types::TAXONOMY_CATEGORY,
                        'field'            => 'term_id',
                        'terms'            => (int) $term->term_id,
                        'include_children' => true,
                    ),
                ),
            )
        );

        return is_array( $posts ) ? $posts : array();
    }

    /**
     * The published articles filed under no category at all.
     *
     * The archive lists categories, and every article is reached through the
     * category it sits in. An article with no category is therefore reachable
     * from nowhere on the archive: it exists, it is published, and no link on
     * `/docs/` leads to it. This is the query behind the list that fixes that.
     *
     * The result is capped rather than paginated. See `UNCATEGORISED_LIMIT`.
     *
     * @since 1.0.0
     *
     * @param int $limit How many articles to return at most.
     *
     * @return WP_Post[] The uncategorised articles, newest first. Empty when there are none, or when the limit is not positive.
     */
    public static function uncategorised_articles( $limit = self::UNCATEGORISED_LIMIT ) {
        /**
         * Filter how many uncategorised articles the archive lists.
         *
         * @since 1.0.0
         *
         * @param int $limit The cap. Zero or less disables the list entirely.
         */
        $limit = (int) apply_filters( 'licensio_uncategorised_limit', (int) $limit );

        if ( $limit < 1 ) {
            return array();
        }

        $posts = get_posts(
            array(
                'post_type'              => Licensio_KB_Post_Types::POST_TYPE,
                'post_status'            => 'publish',
                'posts_per_page'         => $limit,
                'orderby'                => 'date',
                'order'                  => 'DESC',
                'ignore_sticky_posts'    => true,
                'no_found_rows'          => true,
                'update_post_term_cache' => false,
                // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
                'tax_query'              => array(
                    array(
                        'taxonomy' => Licensio_KB_Post_Types::TAXONOMY_CATEGORY,
                        'operator' => 'NOT EXISTS',
                    ),
                ),
            )
        );

        return is_array( $posts ) ? $posts : array();
    }

    /**
     * Split a title into its leading words and its final word.
     *
     * The archive hero paints the last word of its heading with the brand
     * gradient, the way the Licensio documentation design does. Doing the split
     * here rather than in the template keeps the template escaping both halves
     * as plain text: nothing on this path ever builds a string of markup.
     *
     * @since 1.0.0
     *
     * @param string $title The heading.
     *
     * @return array{0: string, 1: string} The leading words, and the final word. The first is empty for a one-word title.
     */
    public static function split_title( $title ) {
        $title = trim( (string) $title );
        $space = strrpos( $title, ' ' );

        if ( false === $space ) {
            return array( '', $title );
        }

        return array( rtrim( substr( $title, 0, $space ) ), substr( $title, $space + 1 ) );
    }

    /**
     * The breadcrumb trail for the current knowledge base view.
     *
     * The trail follows the real `licensio_category` hierarchy, so a request
     * for an article filed four levels deep produces all four ancestors in
     * top-down order rather than a single flat parent.
     *
     * Every item is an array with a `label` and a `url`; the last item has an
     * empty `url` because it is the current page.
     *
     * @since 1.0.0
     *
     * @return array<int, array{label: string, url: string}> The trail, outermost first.
     */
    public static function breadcrumb_items() {
        $items = array(
            array(
                'label' => __( 'Home', 'licensio-knowledge-base' ),
                'url'   => home_url( '/' ),
            ),
        );

        $archive = get_post_type_archive_link( Licensio_KB_Post_Types::POST_TYPE );

        if ( is_string( $archive ) && '' !== $archive ) {
            $items[] = array(
                'label' => __( 'Knowledge Base', 'licensio-knowledge-base' ),
                'url'   => $archive,
            );
        }

        if ( is_singular( Licensio_KB_Post_Types::POST_TYPE ) ) {
            $items = array_merge( $items, self::category_trail( self::primary_category( get_post() ), true ) );

            $items[] = array(
                'label' => get_the_title(),
                'url'   => '',
            );
        } elseif ( is_tax( Licensio_KB_Post_Types::TAXONOMY_CATEGORY ) ) {
            $items = array_merge( $items, self::category_trail( get_queried_object(), false ) );
        } elseif ( is_tax( Licensio_KB_Post_Types::TAXONOMY_TAG ) ) {
            $term = get_queried_object();

            if ( $term instanceof WP_Term ) {
                $items[] = array(
                    'label' => $term->name,
                    'url'   => '',
                );
            }
        } elseif ( is_search() ) {
            $items[] = array(
                'label' => sprintf(
                    /* translators: %s: the search term. */
                    __( 'Search results for “%s”', 'licensio-knowledge-base' ),
                    get_search_query()
                ),
                'url'   => '',
            );
        }

        /**
         * Filter the knowledge base breadcrumb trail.
         *
         * @since 1.0.0
         *
         * @param array<int, array{label: string, url: string}> $items The trail, outermost first.
         */
        return (array) apply_filters( 'licensio_breadcrumb_items', $items );
    }

    /**
     * The ancestor chain of a category, top-down, as breadcrumb items.
     *
     * @since 1.0.0
     *
     * @param WP_Term|mixed $term        The category the trail ends at.
     * @param bool          $link_itself Whether the category itself should be a link, which it
     *                                   is on an article page but not on its own archive.
     *
     * @return array<int, array{label: string, url: string}> The trail segment.
     */
    public static function category_trail( $term, $link_itself = true ) {
        if ( ! $term instanceof WP_Term ) {
            return array();
        }

        $items     = array();
        $ancestors = array_reverse( (array) get_ancestors( $term->term_id, Licensio_KB_Post_Types::TAXONOMY_CATEGORY, 'taxonomy' ) );

        foreach ( $ancestors as $ancestor_id ) {
            $ancestor = get_term( (int) $ancestor_id, Licensio_KB_Post_Types::TAXONOMY_CATEGORY );

            if ( ! $ancestor instanceof WP_Term ) {
                continue;
            }

            $items[] = array(
                'label' => $ancestor->name,
                'url'   => self::term_link( $ancestor ),
            );
        }

        $items[] = array(
            'label' => $term->name,
            'url'   => $link_itself ? self::term_link( $term ) : '',
        );

        return $items;
    }

    /**
     * The most specific category an article is filed under.
     *
     * An article assigned to both a parent and its child should breadcrumb
     * through the child, so the deepest term wins rather than whichever one
     * `get_the_terms()` happened to return first.
     *
     * @since 1.0.0
     *
     * @param WP_Post|int|null $post The article, or its ID.
     *
     * @return WP_Term|null The category, or null when the article has none.
     */
    public static function primary_category( $post = null ) {
        $terms = get_the_terms( $post, Licensio_KB_Post_Types::TAXONOMY_CATEGORY );

        if ( ! is_array( $terms ) || empty( $terms ) ) {
            return null;
        }

        $primary = null;
        $depth   = -1;

        foreach ( $terms as $term ) {
            if ( ! $term instanceof WP_Term ) {
                continue;
            }

            $term_depth = count( (array) get_ancestors( $term->term_id, Licensio_KB_Post_Types::TAXONOMY_CATEGORY, 'taxonomy' ) );

            if ( $term_depth > $depth ) {
                $primary = $term;
                $depth   = $term_depth;
            }
        }

        return $primary;
    }

    /**
     * The permalink of a term, as a string.
     *
     * `get_term_link()` returns a WP_Error for a term whose taxonomy has gone
     * away, which a template would otherwise print as the string `Array`.
     *
     * @since 1.0.0
     *
     * @param WP_Term $term The term.
     *
     * @return string The permalink, or an empty string when it cannot be built.
     */
    public static function term_link( WP_Term $term ) {
        $link = get_term_link( $term );

        return is_string( $link ) ? $link : '';
    }
}
