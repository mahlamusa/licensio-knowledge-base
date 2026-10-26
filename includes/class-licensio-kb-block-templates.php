<?php
/**
 * Block template layer.
 *
 * The block-theme half of the dual template system. `Licensio_KB_Templates`
 * owns the classic PHP half and stands aside under a block theme; this class
 * owns everything the Site Editor sees.
 *
 * It does four things:
 *
 * 1. Surfaces the plugin's `block-templates/` and `block-template-parts/`
 *    files in the Site Editor through `get_block_templates`, and answers
 *    front-end template resolution through `get_block_file_template`, so the
 *    five knowledge base templates and the `licensio-kb-header` part can be
 *    edited, saved, reset and exported like theme templates.
 * 2. Keeps the precedence the scaffold documented: a user customisation in the
 *    database beats a theme's `templates/{slug}.html`, which beats the
 *    plugin's own copy. Both of the first two arrive in the query result core
 *    hands this class, so honouring them is a matter of never overwriting a
 *    slug that is already there.
 * 3. Fires the `licensio_before_article` / `licensio_after_article` /
 *    `licensio_article_footer` contract around `core/post-content` on a single
 *    article, for a template the theme or a user builds from core blocks. The
 *    plugin's own Single Article template has no `core/post-content` block:
 *    its view block renders `single-body.php`, which fires the hooks itself.
 *    Binding to the block rather than to a template means the feedback form
 *    and view tracking still attach once when a theme ships its own
 *    `single-licensio_article.html`.
 * 4. Restores the two things core does not do for a custom post type: a
 *    `search-licensio_article` template is not part of core's search hierarchy,
 *    and a `core/search` block has no way to scope itself to a post type.
 *
 * Every template renders its view through the `licensio-kb/view` block, so a
 * block theme serves the same body templates the classic layer does. The `licensio-kb-header` part is no longer placed in any
 * of them, because each view's hero carries its own search field; it stays
 * registered as a scoped search bar a site can add to a template of its own.
 * The richer type-ahead search is the `licensio-kb/search` block.
 *
 * @package Licensio_KB
 * @since   1.0.0
 * @since   1.0.0 Every template renders its view through the view block.
 * @since   1.0.0 Documented which templates the article hook filter serves.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers, resolves and decorates the knowledge base block templates.
 *
 * @since 1.0.0
 * @since 1.0.0 Described the header part as an optional search bar.
 */
class Licensio_KB_Block_Templates {

    /**
     * The `wp_template` post type name.
     *
     * @since 1.0.0
     * @var string
     */
    const TEMPLATE_TYPE = 'wp_template';

    /**
     * The `wp_template_part` post type name.
     *
     * @since 1.0.0
     * @var string
     */
    const PART_TYPE = 'wp_template_part';

    /**
     * The plugin directory template parts are read from.
     *
     * @since 1.0.0
     * @var string
     */
    const PART_DIRECTORY = 'block-template-parts';

    /**
     * The theme directory template part overrides are read from.
     *
     * @since 1.0.0
     * @var string
     */
    const THEME_PART_DIRECTORY = 'parts';

    /**
     * The template part slug of the knowledge base search bar.
     *
     * @since 1.0.0
     * @var string
     */
    const HEADER_PART = 'licensio-kb-header';

    /**
     * The class that marks a `core/search` block as a knowledge base search.
     *
     * @since 1.0.0
     * @var string
     */
    const SEARCH_FORM_CLASS = 'licensio-kb__search';

    /**
     * Attach the block template layer to WordPress.
     *
     * Every callback re-checks `licensio_kb_use_block_templates()` rather than
     * this method doing it once: `boot()` runs on `plugins_loaded`, before
     * `setup_theme`, where `wp_is_block_theme()` cannot yet answer.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function init_hooks() {
        add_filter( 'get_block_templates', array( $this, 'add_block_templates' ), 10, 3 );
        add_filter( 'get_block_file_template', array( $this, 'add_block_file_template' ), 10, 3 );
        add_filter( 'search_template_hierarchy', array( $this, 'search_template_hierarchy' ) );
        add_filter( 'render_block', array( $this, 'render_article_hooks' ), 10, 2 );
        add_filter( 'render_block', array( $this, 'scope_search_form' ), 10, 2 );
    }

    /**
     * The `wp_template` files this plugin ships.
     *
     * @since 1.0.0
     *
     * @return array<string, array{title: string, description: string}> Slug to template information.
     */
    public function templates() {
        return array(
            'single-' . Licensio_KB_Post_Types::POST_TYPE  => array(
                'title'       => __( 'Single Article', 'licensio-knowledge-base' ),
                'description' => __( 'Displays a single knowledge base article.', 'licensio-knowledge-base' ),
            ),
            'archive-' . Licensio_KB_Post_Types::POST_TYPE => array(
                'title'       => __( 'Article Archive', 'licensio-knowledge-base' ),
                'description' => __( 'Displays the knowledge base home, listing every published article.', 'licensio-knowledge-base' ),
            ),
            'taxonomy-' . Licensio_KB_Post_Types::TAXONOMY_CATEGORY => array(
                'title'       => __( 'Article Category', 'licensio-knowledge-base' ),
                'description' => __( 'Displays the articles filed under one knowledge base category.', 'licensio-knowledge-base' ),
            ),
            'taxonomy-' . Licensio_KB_Post_Types::TAXONOMY_TAG => array(
                'title'       => __( 'Article Tag', 'licensio-knowledge-base' ),
                'description' => __( 'Displays the articles carrying one knowledge base tag.', 'licensio-knowledge-base' ),
            ),
            'search-' . Licensio_KB_Post_Types::POST_TYPE  => array(
                'title'       => __( 'Article Search Results', 'licensio-knowledge-base' ),
                'description' => __( 'Displays the results of a search scoped to knowledge base articles.', 'licensio-knowledge-base' ),
            ),
        );
    }

    /**
     * The `wp_template_part` files this plugin ships.
     *
     * @since 1.0.0
     * @since 1.0.0 Described the header part as an optional search bar.
     *
     * @return array<string, array{title: string, description: string, area: string}> Slug to part information.
     */
    public function parts() {
        return array(
            self::HEADER_PART => array(
                'title'       => __( 'Knowledge Base Header', 'licensio-knowledge-base' ),
                'description' => __( 'A search bar scoped to knowledge base articles. Add it to any template that needs one.', 'licensio-knowledge-base' ),
                'area'        => 'header',
            ),
        );
    }

    /**
     * Add the plugin's templates to a Site Editor or front-end lookup.
     *
     * Anything already in the query result wins, which is the whole of the
     * precedence rule: core has already put the user's saved customisation and
     * the active theme's own file there before this filter runs.
     *
     * @since 1.0.0
     *
     * @param WP_Block_Template[]|mixed $query_result  The templates core found.
     * @param array<string, mixed>      $query         The lookup arguments.
     * @param string                    $template_type Either `wp_template` or `wp_template_part`.
     *
     * @return WP_Block_Template[]|mixed The templates, with the plugin's own appended.
     */
    public function add_block_templates( $query_result, $query = array(), $template_type = self::TEMPLATE_TYPE ) {
        if ( ! is_array( $query_result ) || ! licensio_kb_use_block_templates() ) {
            return $query_result;
        }

        $query         = is_array( $query ) ? $query : array();
        $template_type = (string) $template_type;

        /*
         * `post_type` asks which templates a given post may be assigned in the
         * editor sidebar, and `wp_id` asks for one specific saved post. Neither
         * describes a file this plugin ships.
         */
        if ( ! empty( $query['post_type'] ) || ! empty( $query['wp_id'] ) ) {
            return $query_result;
        }

        $definitions = $this->definitions( $template_type );
        $slugs       = array_keys( $definitions );

        if ( isset( $query['slug__in'] ) && is_array( $query['slug__in'] ) ) {
            $slugs = array_values( array_intersect( $slugs, $query['slug__in'] ) );
        }

        $found = array();

        foreach ( $query_result as $existing ) {
            if ( ! $existing instanceof WP_Block_Template || ! isset( $definitions[ $existing->slug ] ) ) {
                continue;
            }

            $found[] = $existing->slug;

            $this->mark_resettable( $existing );
        }

        foreach ( $slugs as $slug ) {
            if ( in_array( $slug, $found, true ) ) {
                continue;
            }

            if ( ! empty( $query['area'] ) && ( ! isset( $definitions[ $slug ]['area'] ) || $query['area'] !== $definitions[ $slug ]['area'] ) ) {
                continue;
            }

            $template = $this->build_template( $slug, $template_type );

            if ( $template instanceof WP_Block_Template ) {
                $query_result[] = $template;
            }
        }

        return $query_result;
    }

    /**
     * Answer a template lookup that neither the database nor the theme could.
     *
     * `get_block_template()` consults the saved `wp_template` posts first and
     * `get_block_file_template()` consults the theme next, so by the time this
     * filter is reached with a null template both higher-precedence sources
     * have already declined.
     *
     * @since 1.0.0
     *
     * @param WP_Block_Template|null $block_template The template core resolved, if any.
     * @param string                 $id             The template ID, as `{theme}//{slug}`.
     * @param string                 $template_type  Either `wp_template` or `wp_template_part`.
     *
     * @return WP_Block_Template|null The plugin's template, or whatever core resolved.
     */
    public function add_block_file_template( $block_template, $id, $template_type = self::TEMPLATE_TYPE ) {
        if ( null !== $block_template || ! licensio_kb_use_block_templates() ) {
            return $block_template;
        }

        $parts = explode( '//', (string) $id, 2 );

        if ( count( $parts ) < 2 || get_stylesheet() !== $parts[0] ) {
            return $block_template;
        }

        $template = $this->build_template( $parts[1], (string) $template_type );

        return $template instanceof WP_Block_Template ? $template : $block_template;
    }

    /**
     * Offer `search-licensio_article` for a search scoped to articles.
     *
     * Core's search hierarchy is a single `search` entry; there is no
     * `search-{post_type}` step the way there is for singles and archives. A
     * knowledge base search would therefore land on the theme's generic search
     * template no matter what this plugin shipped.
     *
     * @since 1.0.0
     *
     * @param string[]|mixed $templates The template slugs core will try, most specific first.
     *
     * @return string[]|mixed The hierarchy, with the article search template in front.
     */
    public function search_template_hierarchy( $templates ) {
        if ( ! is_array( $templates ) || ! licensio_kb_use_block_templates() ) {
            return $templates;
        }

        if ( ! Licensio_KB_Templates::is_article_search() ) {
            return $templates;
        }

        array_unshift( $templates, 'search-' . Licensio_KB_Post_Types::POST_TYPE );

        return array_values( array_unique( $templates ) );
    }

    /**
     * Fire the article hook contract around the `core/post-content` block.
     *
     * Only a theme's or a user's own single article template reaches this:
     * the plugin's Single Article template renders `single-body.php` through
     * the view block, and that body fires the hooks itself. Bound to the block
     * rather than to a template so the contract still holds when a theme
     * ships its own `single-licensio_article.html`, and skipped entirely under
     * a classic theme where the PHP templates already fire it.
     *
     * The listing guard matters: a Query Loop on an archive renders
     * `core/post-content` once per result, and a feedback form under every
     * card would be one per article listed rather than one per article page.
     *
     * @since 1.0.0
     * @since 1.0.0 Documented that only theme and user templates reach it.
     *
     * @param string|mixed        $block_content The rendered block markup.
     * @param array<string,mixed> $block         The parsed block.
     *
     * @return string|mixed The markup, with the hook output wrapped around it.
     */
    public function render_article_hooks( $block_content, $block ) {
        if ( ! licensio_kb_use_block_templates() || 'core/post-content' !== $this->block_name( $block ) ) {
            return $block_content;
        }

        if ( ! is_singular( Licensio_KB_Post_Types::POST_TYPE ) ) {
            return $block_content;
        }

        $post = get_post();

        if ( ! $post instanceof WP_Post || (int) $post->ID !== (int) get_queried_object_id() ) {
            return $block_content;
        }

        $before = $this->capture( 'licensio_kb_before_article', $post );
        $after  = $this->capture( 'licensio_kb_after_article', $post );
        $footer = $this->capture( 'licensio_kb_article_footer', $post );

        if ( '' !== $footer ) {
            $footer = '<footer class="licensio-kb-article__footer">' . $footer . '</footer>';
        }

        return $before . (string) $block_content . $after . $footer;
    }

    /**
     * Scope a knowledge base `core/search` block to the article post type.
     *
     * The core search block has no post type control, so without this a
     * search box carrying the knowledge base class, such as the one in the
     * optional `licensio-kb-header` part, would run a site-wide search and the
     * article search template would never be reached. The shipped templates
     * no longer place that part; each view's hero carries its own search field.
     *
     * @since 1.0.0
     * @since 1.0.0 Documented the header part as optional.
     *
     * @param string|mixed        $block_content The rendered block markup.
     * @param array<string,mixed> $block         The parsed block.
     *
     * @return string|mixed The markup, with a hidden post type field added.
     */
    public function scope_search_form( $block_content, $block ) {
        if ( ! licensio_kb_use_block_templates() || 'core/search' !== $this->block_name( $block ) ) {
            return $block_content;
        }

        $class_name = isset( $block['attrs']['className'] ) ? (string) $block['attrs']['className'] : '';
        $classes    = preg_split( '/\s+/', trim( $class_name ) );

        if ( ! is_array( $classes ) || ! in_array( self::SEARCH_FORM_CLASS, $classes, true ) ) {
            return $block_content;
        }

        $content  = (string) $block_content;
        $position = strripos( $content, '</form>' );

        if ( false === $position ) {
            return $block_content;
        }

        $field = '<input type="hidden" name="post_type" value="' . esc_attr( Licensio_KB_Post_Types::POST_TYPE ) . '" />';

        return substr( $content, 0, $position ) . $field . substr( $content, $position );
    }

    /**
     * Build a `WP_Block_Template` from one of the plugin's files.
     *
     * The slug is looked up in the shipped definitions before it is ever turned
     * into a path, so only the templates this plugin declares can be produced —
     * a stray or attacker-planted file under `block-templates/` is not
     * addressable through the Site Editor.
     *
     * @since 1.0.0
     *
     * @param string $slug          The template slug.
     * @param string $template_type Either `wp_template` or `wp_template_part`.
     *
     * @return WP_Block_Template|null The template, or null when the plugin does not ship it.
     */
    public function build_template( $slug, $template_type = self::TEMPLATE_TYPE ) {
        $slug        = (string) $slug;
        $definitions = $this->definitions( (string) $template_type );

        if ( ! isset( $definitions[ $slug ] ) ) {
            return null;
        }

        $path = $this->locate( $slug, (string) $template_type );

        if ( '' === $path ) {
            return null;
        }

        $theme   = (string) get_stylesheet();
        $content = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $content = $this->inject_theme_attribute( $content, $theme );

        $template                 = new WP_Block_Template();
        $template->id             = $theme . '//' . $slug;
        $template->theme          = $theme;
        $template->slug           = $slug;
        $template->type           = (string) $template_type;
        $template->content        = $content;
        $template->title          = $definitions[ $slug ]['title'];
        $template->description    = $definitions[ $slug ]['description'];
        $template->source         = 'plugin';
        $template->origin         = 'plugin';
        $template->status         = 'publish';
        $template->has_theme_file = true;
        $template->is_custom      = false;
        $template->post_types     = array();
        $template->author         = null;

        if ( isset( $definitions[ $slug ]['area'] ) ) {
            $template->area = $definitions[ $slug ]['area'];
        }

        return $template;
    }

    /**
     * Let the Site Editor reset a customisation back to the plugin's file.
     *
     * A saved `wp_template` post is built by core with `has_theme_file` set
     * from the theme directories alone, so a customisation of a plugin template
     * reports that there is nothing to revert to and the editor hides its Reset
     * action. The plugin does still ship the original, so the flag is corrected
     * here; `get_block_file_template` above serves the file the revert then
     * fetches.
     *
     * @since 1.0.0
     *
     * @param WP_Block_Template $template A template core already resolved.
     *
     * @return void
     */
    private function mark_resettable( WP_Block_Template $template ) {
        if ( 'custom' !== $template->source || $template->has_theme_file ) {
            return;
        }

        if ( '' === $this->locate( $template->slug, (string) $template->type ) ) {
            return;
        }

        $template->has_theme_file = true;
        $template->origin         = 'plugin';
    }

    /**
     * Stamp the active theme onto every unqualified template part reference.
     *
     * `core/template-part` only resolves a part whose `theme` attribute matches
     * the active stylesheet, and a file this plugin ships cannot name a theme
     * ahead of time. Core does the same thing for theme templates; its own
     * helper for it is deprecated, so the walk is done here.
     *
     * The content is only re-serialized when something actually changed, so a
     * template that references no parts is passed through byte for byte.
     *
     * @since 1.0.0
     *
     * @param string $content The block markup read from disk.
     * @param string $theme   The active stylesheet.
     *
     * @return string The markup, with every template part reference qualified.
     */
    private function inject_theme_attribute( $content, $theme ) {
        $changed = false;
        $blocks  = $this->qualify_template_parts( (array) parse_blocks( $content ), $theme, $changed );

        return $changed ? (string) serialize_blocks( $blocks ) : $content;
    }

    /**
     * Add the theme attribute to the template part blocks in a block tree.
     *
     * @since 1.0.0
     *
     * @param array<int, array<string, mixed>> $blocks  The parsed blocks.
     * @param string                           $theme   The active stylesheet.
     * @param bool                             $changed Set to true when a block was rewritten.
     *
     * @return array<int, array<string, mixed>> The blocks.
     */
    private function qualify_template_parts( array $blocks, $theme, &$changed ) {
        foreach ( $blocks as $index => $block ) {
            if ( isset( $block['blockName'] ) && 'core/template-part' === $block['blockName'] && ! isset( $block['attrs']['theme'] ) ) {
                $blocks[ $index ]['attrs']['theme'] = $theme;

                $changed = true;
            }

            if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
                $blocks[ $index ]['innerBlocks'] = $this->qualify_template_parts( $block['innerBlocks'], $theme, $changed );
            }
        }

        return $blocks;
    }

    /**
     * The shipped definitions for one template type.
     *
     * @since 1.0.0
     *
     * @param string $template_type Either `wp_template` or `wp_template_part`.
     *
     * @return array<string, array<string, string>> Slug to template information.
     */
    private function definitions( $template_type ) {
        return self::PART_TYPE === $template_type ? $this->parts() : $this->templates();
    }

    /**
     * The file backing a template slug, theme overrides first.
     *
     * Full templates go through the scaffold's resolver. Template parts have no
     * scaffold resolver because nothing outside the block layer consumes them,
     * so the same order is applied here against `parts/` and
     * `block-template-parts/`, reusing the scaffold's traversal guard.
     *
     * @since 1.0.0
     *
     * @param string $slug          The template slug.
     * @param string $template_type Either `wp_template` or `wp_template_part`.
     *
     * @return string An absolute path, or an empty string when no file exists.
     */
    private function locate( $slug, $template_type ) {
        if ( self::PART_TYPE !== $template_type ) {
            return licensio_kb_locate_block_template( $slug );
        }

        if ( ! licensio_kb_is_safe_template_name( $slug ) ) {
            return '';
        }

        foreach ( $this->theme_directories() as $directory ) {
            $candidate = trailingslashit( $directory ) . self::THEME_PART_DIRECTORY . '/' . $slug . '.html';

            if ( is_readable( $candidate ) ) {
                return $candidate;
            }
        }

        $path = LICENSIO_KB_ABSPATH . self::PART_DIRECTORY . '/' . $slug . '.html';

        return is_readable( $path ) ? $path : '';
    }

    /**
     * The active theme directories, child theme first.
     *
     * @since 1.0.0
     *
     * @return string[] Absolute directory paths.
     */
    private function theme_directories() {
        $directories = array();

        if ( function_exists( 'get_stylesheet_directory' ) ) {
            $directories[] = (string) get_stylesheet_directory();
        }

        if ( function_exists( 'get_template_directory' ) ) {
            $directories[] = (string) get_template_directory();
        }

        return array_values( array_unique( array_filter( $directories ) ) );
    }

    /**
     * The name of a parsed block, if it has one.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed>|mixed $block The parsed block.
     *
     * @return string The block name, or an empty string.
     */
    private function block_name( $block ) {
        if ( ! is_array( $block ) || ! isset( $block['blockName'] ) || ! is_string( $block['blockName'] ) ) {
            return '';
        }

        return $block['blockName'];
    }

    /**
     * Run one of the article hook functions and capture what it printed.
     *
     * @since 1.0.0
     *
     * @param callable $callback The hook function to call.
     * @param WP_Post  $post     The article being rendered.
     *
     * @return string Everything the hook's callbacks printed.
     */
    private function capture( callable $callback, WP_Post $post ) {
        ob_start();

        $callback( $post );

        return (string) ob_get_clean();
    }
}
