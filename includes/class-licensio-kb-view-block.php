<?php
/**
 * The knowledge base view block.
 *
 * `licensio-kb/view` is what the plugin's block templates render between the
 * theme's header and footer. It outputs the same `*-body.php` view body the
 * classic templates include, through `Licensio_KB_Templates::render_view()`,
 * so a block theme and a classic theme serve one markup and one stylesheet.
 *
 * It is a block rather than the `[licensio_kb_view]` shortcode because core
 * runs a singular block template inside its own loop over the main query.
 * Shortcodes in a template are expanded before that loop starts, so a
 * shortcode body that loops the article itself makes the main query's loop
 * hooks fire twice. A block renders inside core's loop, so the single article
 * body can render the article core has already set up. A dynamic block is
 * also not run through `wpautop`, and the Site Editor can preview it.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders a knowledge base view body inside a block template.
 *
 * @since 1.0.0
 */
class Licensio_KB_View_Block extends Licensio_KB_Block {

    /**
     * The globals an editor preview changes and puts back.
     *
     * The main query, the global post, and the postdata globals
     * `WP_Query::setup_postdata()` sets for every post a loop visits.
     *
     * @since 1.0.0
     * @var string[]
     */
    const PREVIEW_GLOBALS = array(
        'wp_query',
        'wp_the_query',
        'post',
        'id',
        'authordata',
        'currentday',
        'currentmonth',
        'page',
        'pages',
        'multipage',
        'more',
        'numpages',
    );

    /**
     * The block name.
     *
     * @since 1.0.0
     *
     * @return string The block name.
     */
    public static function block_name() {
        return 'licensio-kb/view';
    }

    /**
     * The shortcode tag that renders the same view bodies.
     *
     * The shortcode is registered by `Licensio_KB_Templates`, not here; see
     * `register()`.
     *
     * @since 1.0.0
     *
     * @return string The shortcode tag.
     */
    public static function shortcode_tag() {
        return Licensio_KB_Templates::VIEW_SHORTCODE_TAG;
    }

    /**
     * The `blocks/` sub-directory holding the block metadata.
     *
     * @since 1.0.0
     *
     * @return string The directory name.
     */
    public static function directory() {
        return 'view';
    }

    /**
     * The BEM modifier used on the block wrapper.
     *
     * Unused: the view bodies carry their own `.licensio-kb` wrappers, so the
     * block adds none of its own.
     *
     * @since 1.0.0
     *
     * @return string The modifier.
     */
    public static function modifier() {
        return 'view';
    }

    /**
     * The attributes the block accepts, and their defaults.
     *
     * @since 1.0.0
     *
     * @return array<string, bool|int|string> Attribute name to default value.
     */
    public static function defaults() {
        return array(
            'view' => 'archive',
        );
    }

    /**
     * Keep the view to the ones the template layer can render.
     *
     * @since 1.0.0
     *
     * @param array<string, bool|int|string> $values The typed attributes.
     *
     * @return array<string, bool|int|string> The sanitised attributes.
     */
    protected static function sanitize( array $values ) {
        $values['view'] = self::enum( $values['view'], array_keys( Licensio_KB_Templates::VIEW_BODIES ), '' );

        return $values;
    }

    /**
     * Register the block.
     *
     * Unlike the other blocks, no shortcode is registered here. The
     * `[licensio_kb_view]` shortcode belongs to `Licensio_KB_Templates` and
     * renders from template level only, while this block renders inside
     * core's loop, so the two entry points cannot share one callback.
     *
     * The block's metadata keeps it out of the inserter, because a view
     * placed in a post or page renders nothing there. The Site Editor, where
     * templates are built, offers it again; see `offer_in_site_editor()`.
     *
     * @since 1.0.0
     * @since 1.0.0 Offers the block in the inserter of the Site Editor only.
     *
     * @return void
     */
    public function register() {
        $metadata = static::metadata_path();

        if ( '' === $metadata || ! function_exists( 'register_block_type' ) ) {
            return;
        }

        add_filter( 'block_type_metadata', array( $this, 'offer_in_site_editor' ) );

        register_block_type(
            $metadata,
            array(
                'render_callback' => array( $this, 'render' ),
            )
        );
    }

    /**
     * Offer the block in the inserter while the Site Editor is open.
     *
     * The view block only renders in a template, so it is kept out of the
     * inserter everywhere else: the post editor, the widget screens and the
     * Customizer. The editors read the block's settings from the server when
     * the screen loads, so the inserter follows the screen being loaded.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $metadata The block metadata read from `block.json`.
     *
     * @return array<string, mixed> The metadata, with the inserter on in the Site Editor.
     */
    public function offer_in_site_editor( $metadata ) {
        if ( ! is_array( $metadata ) || ! isset( $metadata['name'] ) || static::block_name() !== $metadata['name'] ) {
            return $metadata;
        }

        if ( is_admin() && isset( $GLOBALS['pagenow'] ) && 'site-editor.php' === $GLOBALS['pagenow'] ) {
            $metadata['supports']['inserter'] = true;
        }

        return $metadata;
    }

    /**
     * Render the block or its editor preview.
     *
     * The view body is returned as it is, without the block wrapper, because
     * every body already opens its own `.licensio-kb` wrappers.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed>|string $attributes The block attributes.
     * @param string                      $content    Inner content. Unused; the block has none.
     * @param mixed                       $block      The WP_Block instance. Unused.
     *
     * @return string The view body markup, or an empty string when the view cannot render here.
     */
    public function render( $attributes = array(), $content = '', $block = null ) {
        unset( $content, $block );

        return $this->content( static::normalize( $attributes ) );
    }

    /**
     * The view body for the current request, or a preview of it.
     *
     * Core loops the main query around a singular block template, so on a
     * single article this block runs inside that loop and the single body
     * renders the article core set up. Any other view inside a loop, or the
     * single view for a post that is not the queried article, is a view
     * placed somewhere it was not built for, and renders nothing.
     *
     * @since 1.0.0
     * @since 1.0.0 Lets a theme override that runs its own loop render the article.
     *
     * @param array<string, bool|int|string> $attributes The normalised attributes.
     *
     * @return string The view body markup, or an empty string when the view cannot render here.
     */
    public function content( array $attributes ) {
        $view = (string) $attributes['view'];

        if ( '' === $view ) {
            return '';
        }

        if ( self::is_preview_request() ) {
            return $this->preview( $view );
        }

        if ( ! in_the_loop() ) {
            return Licensio_KB_Templates::render_view( $view );
        }

        if ( 'single' !== $view || (int) get_the_ID() !== (int) get_queried_object_id() ) {
            return '';
        }

        return $this->render_in_main_loop( $view );
    }

    /**
     * Render the single article body inside core's loop over the main query.
     *
     * Core has already moved the main query onto its one article, so a theme
     * override of `single-body.php` that opens the usual
     * `while ( have_posts() ) : the_post();` loop would find no posts left,
     * rewind the query and render an empty article. The query is put back to
     * the start of its loop while the body renders, so such an override
     * loops over the article once, and is then returned to where core left
     * it so core's own loop ends normally. The shipped body checks
     * `in_the_loop()`, which stays true, and renders the article without
     * looping at all.
     *
     * @since 1.0.0
     *
     * @param string $view The view name.
     *
     * @return string The view body markup.
     */
    private function render_in_main_loop( $view ) {
        $query = isset( $GLOBALS['wp_query'] ) ? $GLOBALS['wp_query'] : null;

        if ( ! $query instanceof WP_Query ) {
            return Licensio_KB_Templates::render_view( $view );
        }

        $current_post = $query->current_post;
        $query_post   = $query->post;
        $global_post  = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;

        $query->current_post = -1;

        try {
            $markup = Licensio_KB_Templates::render_view( $view );
        } finally {
            $query->current_post = $current_post;
            $query->in_the_loop  = true;
            $query->post         = $query_post;
            $GLOBALS['post']     = $global_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Put back the post core's loop set up.
        }

        return $markup;
    }

    /**
     * Whether the block is being rendered for an editor preview.
     *
     * The editor previews a dynamic block through the REST block renderer.
     * Post content rendered for a REST response is not a preview, and only a
     * user who can edit posts can reach the block renderer.
     *
     * @since 1.0.0
     *
     * @return bool True for an editor preview request.
     */
    public static function is_preview_request() {
        return defined( 'REST_REQUEST' ) && REST_REQUEST && ! doing_filter( 'the_content' ) && current_user_can( 'edit_posts' );
    }

    /**
     * Render a view against a sample of the site's own content.
     *
     * A template has no article, category or tag of its own, so the preview
     * points the main query at the newest published article (or at a
     * category or tag that has articles) while the view body renders, then
     * puts the real query back. Only published, unprotected content is used.
     *
     * The view body's loop sets up the postdata globals (`$id`, `$authordata`,
     * `$pages` and the rest) for the sample content, so those are put back
     * too, exactly as they were: a global that was not set before the preview
     * is unset again rather than left pointing at the sample.
     *
     * @since 1.0.0
     * @since 1.0.0 Restores the postdata globals, including ones that were not set.
     *
     * @param string $view The view name.
     *
     * @return string The view body markup, or an empty string when there is nothing to preview.
     */
    public function preview( $view ) {
        $args = $this->preview_query_args( $view );

        if ( empty( $args ) ) {
            return '';
        }

        $saved = array();

        foreach ( self::PREVIEW_GLOBALS as $name ) {
            if ( array_key_exists( $name, $GLOBALS ) ) {
                $saved[ $name ] = $GLOBALS[ $name ];
            }
        }

        $query = new WP_Query( $args );

        // phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- The preview stands in for the main query and is put back below.
        $GLOBALS['wp_query']     = $query;
        $GLOBALS['wp_the_query'] = $query;
        $GLOBALS['post']         = isset( $args['p'] ) ? get_post( (int) $args['p'] ) : null;

        try {
            $markup = Licensio_KB_Templates::render_view( $view );
        } finally {
            foreach ( self::PREVIEW_GLOBALS as $name ) {
                if ( array_key_exists( $name, $saved ) ) {
                    $GLOBALS[ $name ] = $saved[ $name ];
                } else {
                    unset( $GLOBALS[ $name ] );
                }
            }
            // phpcs:enable WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
        }

        return $markup;
    }

    /**
     * The query a view is previewed with.
     *
     * @since 1.0.0
     *
     * @param string $view The view name.
     *
     * @return array<string, mixed> The `WP_Query` arguments, or an empty array when there is nothing to preview.
     */
    public function preview_query_args( $view ) {
        if ( 'archive' === $view ) {
            return array( 'post_type' => Licensio_KB_Post_Types::POST_TYPE );
        }

        if ( 'category' === $view || 'tag' === $view ) {
            $taxonomy = 'tag' === $view ? Licensio_KB_Post_Types::TAXONOMY_TAG : Licensio_KB_Post_Types::TAXONOMY_CATEGORY;
            $terms    = get_terms(
                array(
                    'taxonomy'   => $taxonomy,
                    'hide_empty' => true,
                    'number'     => 1,
                    'orderby'    => 'count',
                    'order'      => 'DESC',
                )
            );

            if ( ! is_array( $terms ) || ! isset( $terms[0] ) || ! $terms[0] instanceof WP_Term ) {
                return array();
            }

            return array(
                'taxonomy' => $taxonomy,
                'term'     => $terms[0]->slug,
            );
        }

        $articles = get_posts(
            array(
                'post_type'      => Licensio_KB_Post_Types::POST_TYPE,
                'post_status'    => 'publish',
                'has_password'   => false,
                'posts_per_page' => 1,
                'orderby'        => 'date',
                'order'          => 'DESC',
                'no_found_rows'  => true,
            )
        );

        if ( ! is_array( $articles ) || ! isset( $articles[0] ) || ! $articles[0] instanceof WP_Post ) {
            return array();
        }

        if ( 'single' === $view ) {
            return array(
                'p'         => (int) $articles[0]->ID,
                'post_type' => Licensio_KB_Post_Types::POST_TYPE,
            );
        }

        return array(
            's'         => $articles[0]->post_title,
            'post_type' => Licensio_KB_Post_Types::POST_TYPE,
        );
    }
}
