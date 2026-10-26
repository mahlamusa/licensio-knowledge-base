<?php
/**
 * Unit tests for the shared view hero.
 *
 * The hero is the one heading block every knowledge base view renders, so the
 * assertions here fall into two halves: what the template itself puts on the
 * page, and the fact that all five views go through it rather than each
 * carrying a heading of their own. The second half is the point of the file —
 * a view that grew its own `<h1>` back would still render a correct-looking
 * page, and only a count of the heroes on it catches that.
 *
 * The archive assertions cover what it stopped rendering: the article count on
 * its cards and the latest-articles list, neither of which the documentation
 * design has.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

/**
 * Tests templates/hero.php and the five views that render it.
 *
 * @since 1.0.0
 */
class Licensio_KB_Hero_Test extends Licensio_KB_Test_Case {

    /**
     * A throwaway theme root used by the override test.
     *
     * Lives in the system temp directory so an aborted run cannot leave a file
     * where PHPCS would lint it or git would offer to commit it.
     *
     * @since 1.0.0
     * @var string
     */
    private $theme_dir = '';

    /**
     * The articles the stubbed query answers the uncategorised list with.
     *
     * Empty by default, which is the state of a well-organised site and the
     * one every other test in this file assumes.
     *
     * @since 1.0.0
     * @var WP_Post[]
     */
    private $uncategorised = array();

    /**
     * Stub the WordPress surface the templates touch.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        $this->uncategorised = array();

        Functions\when( 'untrailingslashit' )->alias(
            function ( $value ) {
                return rtrim( (string) $value, '/\\' );
            }
        );

        Functions\when( 'wp_is_block_theme' )->justReturn( false );
        Functions\when( 'locate_template' )->justReturn( '' );
        Functions\when( 'do_blocks' )->returnArg();
        Functions\when( 'get_stylesheet_directory' )->justReturn( LICENSIO_KB_ABSPATH . 'tests/no-such-theme' );
        Functions\when( 'get_template_directory' )->justReturn( LICENSIO_KB_ABSPATH . 'tests/no-such-theme' );

        Functions\when( 'home_url' )->alias(
            function ( $path = '' ) {
                return 'https://example.test' . ( '' === $path ? '' : $path );
            }
        );

        Functions\when( 'get_post_type_archive_link' )->justReturn( 'https://example.test/docs/' );
        Functions\when( 'number_format_i18n' )->returnArg();
        Functions\when( 'wp_kses_post' )->returnArg();
        Functions\when( 'get_header' )->justReturn( null );
        Functions\when( 'get_footer' )->justReturn( null );
        Functions\when( 'have_posts' )->justReturn( false );
        Functions\when( 'get_search_query' )->justReturn( '' );

        $this->stub_conditionals();

        $this->theme_dir = sys_get_temp_dir() . '/licensio-kb-hero-theme-' . uniqid();

        mkdir( $this->theme_dir . '/licensio-kb', 0777, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
    }

    /**
     * Remove the throwaway theme.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function tearDown(): void {
        if ( '' !== $this->theme_dir && is_dir( $this->theme_dir . '/licensio-kb' ) ) {
            foreach ( (array) glob( $this->theme_dir . '/licensio-kb/*' ) as $file ) {
                unlink( (string) $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.WP.AlternativeFunctions.file_system_operations_unlink
            }

            rmdir( $this->theme_dir . '/licensio-kb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
            rmdir( $this->theme_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
        }

        parent::tearDown();
    }

    /**
     * Point every template conditional at one view.
     *
     * @since 1.0.0
     * @since 1.0.0 Scopes the search view to the article post type.
     *
     * @param string $view One of `single`, `category`, `tag`, `archive`, `search` or `` for none.
     *
     * @return void
     */
    private function stub_conditionals( $view = '' ) {
        Functions\when( 'is_singular' )->alias(
            function ( $type = '' ) use ( $view ) {
                return 'single' === $view && Licensio_KB_Post_Types::POST_TYPE === $type;
            }
        );

        Functions\when( 'is_tax' )->alias(
            function ( $taxonomy = '' ) use ( $view ) {
                if ( 'category' === $view ) {
                    return Licensio_KB_Post_Types::TAXONOMY_CATEGORY === $taxonomy;
                }

                if ( 'tag' === $view ) {
                    return Licensio_KB_Post_Types::TAXONOMY_TAG === $taxonomy;
                }

                return false;
            }
        );

        Functions\when( 'is_post_type_archive' )->alias(
            function ( $type = '' ) use ( $view ) {
                return 'archive' === $view && Licensio_KB_Post_Types::POST_TYPE === $type;
            }
        );

        Functions\when( 'is_search' )->justReturn( 'search' === $view );

        // A knowledge base search is one scoped to the article post type.
        Functions\when( 'get_query_var' )->alias(
            function ( $name ) use ( $view ) {
                return 'post_type' === $name && 'search' === $view ? Licensio_KB_Post_Types::POST_TYPE : '';
            }
        );
    }

    /**
     * The absolute path of one of the plugin's own templates.
     *
     * @since 1.0.0
     *
     * @param string $name The template name.
     *
     * @return string The path.
     */
    private function plugin_template( $name ) {
        return LICENSIO_KB_ABSPATH . 'templates/' . $name . '.php';
    }

    /**
     * Render templates/hero.php on its own.
     *
     * The template is included directly rather than located through the theme
     * lookup: the lookup is a separate concern with its own tests, and going
     * through it would put a second layer between the assertion and the file
     * the assertion is about.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $overrides Template arguments to add or replace.
     *
     * @return string The rendered markup.
     */
    private function render_hero( array $overrides = array() ) {
        $args = array_merge( array( 'title' => 'Licensio Documentation' ), $overrides );

        ob_start();

        include $this->plugin_template( 'hero' );

        return (string) ob_get_clean();
    }

    /**
     * Render templates/archive.php against one category holding one article.
     *
     * @since 1.0.0
     *
     * @param bool $with_categories Whether the taxonomy answers with a category at all.
     *
     * @return string The rendered markup.
     */
    private function render_archive( $with_categories = true ) {
        $this->stub_conditionals( 'archive' );
        $this->stub_one_category();

        if ( ! $with_categories ) {
            Functions\when( 'get_terms' )->justReturn( array() );
        }

        Functions\when( 'post_type_archive_title' )->justReturn( 'Knowledge Base' );

        ob_start();

        include $this->plugin_template( 'archive' );

        return (string) ob_get_clean();
    }

    /**
     * Render templates/single.php against a one-post loop.
     *
     * @since 1.0.0
     *
     * @return string The rendered markup.
     */
    private function render_single() {
        $this->stub_conditionals( 'single' );

        $article = new WP_Post(
            array(
                'ID'         => 42,
                'post_title' => 'Resetting your password',
                'post_type'  => Licensio_KB_Post_Types::POST_TYPE,
            )
        );

        Functions\when( 'get_post' )->justReturn( $article );

        /*
         * A single article view has exactly one post, so the queried object and
         * the post the loop yields are the same article. Both are stubbed
         * because the hero may be resolved from either, depending on whether it
         * renders inside the loop or above it.
         */
        Functions\when( 'get_queried_object' )->justReturn( $article );
        Functions\when( 'get_the_title' )->justReturn( 'Resetting your password' );
        Functions\when( 'get_the_terms' )->justReturn( array() );
        Functions\when( 'get_the_term_list' )->justReturn( '' );
        Functions\when( 'get_the_modified_date' )->justReturn( '1 January 2026' );
        Functions\when( 'the_post' )->justReturn( null );
        Functions\when( 'the_content' )->alias(
            function () {
                echo 'ARTICLE BODY';
            }
        );

        $remaining = 1;

        Functions\when( 'have_posts' )->alias(
            function () use ( &$remaining ) {
                if ( $remaining > 0 ) {
                    --$remaining;

                    return true;
                }

                return false;
            }
        );

        ob_start();

        include $this->plugin_template( 'single' );

        return (string) ob_get_clean();
    }

    /**
     * Render templates/taxonomy-category.php against one empty category.
     *
     * @since 1.0.0
     *
     * @param string $description The term description.
     *
     * @return string The rendered markup.
     */
    private function render_category( $description = 'Everything you need to get going.' ) {
        $this->stub_conditionals( 'category' );

        $term = new WP_Term(
            array(
                'term_id'     => 5,
                'name'        => 'Getting started',
                'slug'        => 'getting-started',
                'taxonomy'    => Licensio_KB_Post_Types::TAXONOMY_CATEGORY,
                'count'       => 1,
                'description' => $description,
                'parent'      => 0,
            )
        );

        Functions\when( 'get_queried_object' )->justReturn( $term );
        Functions\when( 'get_terms' )->justReturn( array() );
        Functions\when( 'get_term' )->justReturn( $term );
        Functions\when( 'get_term_link' )->justReturn( 'https://example.test/docs-category/getting-started/' );
        Functions\when( 'get_ancestors' )->justReturn( array() );
        Functions\when( 'single_term_title' )->justReturn( 'Getting started' );
        Functions\when( 'get_posts' )->justReturn( array() );

        ob_start();

        include $this->plugin_template( 'taxonomy-category' );

        return (string) ob_get_clean();
    }

    /**
     * Render templates/taxonomy-tag.php against one empty tag.
     *
     * @since 1.0.0
     * @since 1.0.0 Added the view shortcode path.
     *
     * @param string $description   The term description.
     * @param bool   $via_shortcode Whether to render through the view shortcode a block template uses.
     *
     * @return string The rendered markup.
     */
    private function render_tag( $description = 'Everything filed under content.', $via_shortcode = false ) {
        $this->stub_conditionals( 'tag' );

        $term = new WP_Term(
            array(
                'term_id'     => 8,
                'name'        => 'Content',
                'slug'        => 'content',
                'taxonomy'    => Licensio_KB_Post_Types::TAXONOMY_TAG,
                'count'       => 1,
                'description' => $description,
            )
        );

        Functions\when( 'get_queried_object' )->justReturn( $term );
        Functions\when( 'single_term_title' )->justReturn( 'Content' );

        if ( $via_shortcode ) {
            return $this->render_view( 'tag' );
        }

        ob_start();

        include $this->plugin_template( 'taxonomy-tag' );

        return (string) ob_get_clean();
    }

    /**
     * Render templates/search-results.php against an empty result set.
     *
     * @since 1.0.0
     * @since 1.0.0 Added the view shortcode path.
     *
     * @param bool $via_shortcode Whether to render through the view shortcode a block template uses.
     *
     * @return string The rendered markup.
     */
    private function render_search( $via_shortcode = false ) {
        $this->stub_conditionals( 'search' );

        Functions\when( 'get_search_query' )->justReturn( 'licence key' );

        if ( $via_shortcode ) {
            return $this->render_view( 'search' );
        }

        ob_start();

        include $this->plugin_template( 'search-results' );

        return (string) ob_get_clean();
    }

    /**
     * Answer the category queries with one category holding one article.
     *
     * @since 1.0.0
     *
     * @return void
     */
    private function stub_one_category() {
        $term = new WP_Term(
            array(
                'term_id'     => 5,
                'name'        => 'Getting started',
                'slug'        => 'getting-started',
                'taxonomy'    => Licensio_KB_Post_Types::TAXONOMY_CATEGORY,
                'count'       => 1,
                'description' => 'From purchase to first licensed sale.',
                'parent'      => 0,
            )
        );

        $article = new WP_Post(
            array(
                'ID'         => 7,
                'post_title' => 'Quick start',
                'post_type'  => Licensio_KB_Post_Types::POST_TYPE,
            )
        );

        Functions\when( 'get_terms' )->alias(
            function ( $args = array() ) use ( $term ) {
                $taxonomy = isset( $args['taxonomy'] ) ? (string) $args['taxonomy'] : '';
                $parent   = isset( $args['parent'] ) ? (int) $args['parent'] : 0;

                /*
                 * The sidebar asks the tag taxonomy as well, and asks it
                 * without a `parent` because tags are flat. This file is about
                 * the category cards, so a tag query answers with nothing.
                 */

                /*
                 * One tag, so the archive sidebar has something to render.
                 * Without it a switched-off sidebar and an empty one look the
                 * same, and the tests below cannot tell them apart.
                 */
                if ( Licensio_KB_Post_Types::TAXONOMY_TAG === $taxonomy ) {
                    return array(
                        new WP_Term(
                            array(
                                'term_id'  => 11,
                                'name'     => 'licensing',
                                'slug'     => 'licensing',
                                'taxonomy' => Licensio_KB_Post_Types::TAXONOMY_TAG,
                                'count'    => 1,
                                'parent'   => 0,
                            )
                        ),
                    );
                }

                if ( Licensio_KB_Post_Types::TAXONOMY_CATEGORY !== $taxonomy ) {
                    return array();
                }

                return isset( $args['child_of'] ) || 0 !== $parent ? array() : array( $term );
            }
        );

        Functions\when( 'get_term' )->alias(
            function ( $value ) use ( $term ) {
                return $value instanceof WP_Term ? $value : $term;
            }
        );

        Functions\when( 'get_term_link' )->justReturn( 'https://example.test/docs-category/getting-started/' );
        Functions\when( 'get_term_meta' )->justReturn( '' );
        Functions\when( 'get_permalink' )->justReturn( 'https://example.test/docs/article-7/' );

        /*
         * The archive makes two article queries: the cards ask for the articles
         * in one category, and the uncategorised list asks for the articles in
         * none. A double that answered both the same way would report the
         * uncategorised list as always populated, which is precisely the state
         * the template is supposed to be able to distinguish, so the double
         * discriminates on the operator the way the database would.
         */
        Functions\when( 'get_posts' )->alias(
            function ( $args = array() ) use ( $article ) {
                return $this->is_uncategorised_query( $args )
                    ? array_slice( $this->uncategorised, 0, isset( $args['posts_per_page'] ) ? (int) $args['posts_per_page'] : count( $this->uncategorised ) )
                    : array( $article );
            }
        );

        Functions\when( 'get_the_title' )->alias(
            function ( $post ) {
                return $post instanceof WP_Post ? $post->post_title : '';
            }
        );

        Functions\when( 'get_post' )->alias(
            function ( $post = null ) {
                return $post instanceof WP_Post ? $post : null;
            }
        );

        Functions\when( 'get_the_excerpt' )->justReturn( '' );
    }

    /**
     * Whether a query asked for the articles filed under no category.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $args The query arguments.
     *
     * @return bool True when the query carries a NOT EXISTS clause for the category taxonomy.
     */
    private function is_uncategorised_query( array $args ) {
        if ( ! isset( $args['tax_query'][0] ) || ! is_array( $args['tax_query'][0] ) ) {
            return false;
        }

        $clause = $args['tax_query'][0];

        return isset( $clause['taxonomy'], $clause['operator'] )
            && Licensio_KB_Post_Types::TAXONOMY_CATEGORY === $clause['taxonomy']
            && 'NOT EXISTS' === $clause['operator'];
    }

    /**
     * Build an uncategorised article the stubbed query can answer with.
     *
     * @since 1.0.0
     *
     * @param int    $id    The post ID.
     * @param string $title The article title.
     *
     * @return WP_Post The article.
     */
    private function orphan( $id, $title ) {
        return new WP_Post(
            array(
                'ID'         => $id,
                'post_title' => $title,
                'post_type'  => Licensio_KB_Post_Types::POST_TYPE,
            )
        );
    }

    /**
     * Render a view body through the view shortcode a block template uses.
     *
     * @since 1.0.0
     *
     * @param string $view The view name the block template passes.
     *
     * @return string The rendered markup.
     */
    private function render_view( $view ) {
        Functions\when( 'shortcode_atts' )->alias(
            function ( $pairs, $atts ) {
                return array_merge( $pairs, array_intersect_key( (array) $atts, $pairs ) );
            }
        );

        // The view shortcode renders for block templates only.
        Functions\when( 'wp_is_block_theme' )->justReturn( true );

        $templates = new Licensio_KB_Templates( new Licensio_KB_Settings() );

        return $templates->render_view_shortcode( array( 'view' => $view ) );
    }

    /**
     * A block theme's tag and search views match the classic templates.
     *
     * The block templates render the same body files through the view
     * shortcode, so the markup between the theme's header and footer is the
     * same under either kind of theme.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_view_shortcode_renders_the_classic_tag_and_search_markup() {
        $tag = $this->render_tag( 'Everything filed under content.', true );

        $this->assertSame( $this->render_tag(), $tag );
        $this->assertStringContainsString( 'class="licensio-kb__hero"', $tag );
        $this->assertStringContainsString( 'licensio-kb licensio-kb--tag', $tag );

        $search = $this->render_search( true );

        $this->assertSame( $this->render_search(), $search );
        $this->assertStringContainsString( 'class="licensio-kb__hero"', $search );
        $this->assertStringContainsString( 'licensio-kb licensio-kb--search', $search );
    }

    /**
     * Every knowledge base view, rendered.
     *
     * @since 1.0.0
     *
     * @return array<string, string> The markup of each view, keyed by view name.
     */
    private function render_every_view() {
        return array(
            'archive'  => $this->render_archive(),
            'single'   => $this->render_single(),
            'category' => $this->render_category(),
            'tag'      => $this->render_tag(),
            'search'   => $this->render_search(),
        );
    }

    /**
     * The views that still render the hero.
     *
     * The redesigned archive and category views open with the page header
     * instead; the hero remains the single article, tag and search opening.
     *
     * @since 1.0.0
     *
     * @return array<string, string> View name to rendered markup.
     */
    private function render_hero_views() {
        $all = $this->render_every_view();

        return array_intersect_key( $all, array_flip( array( 'single', 'tag', 'search' ) ) );
    }

    /**
     * The hero paints the last word of its heading, and only that word.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_hero_accents_the_last_word_of_its_title() {
        $markup = $this->render_hero();

        $this->assertStringContainsString( '<header class="licensio-kb__hero">', $markup );
        $this->assertStringContainsString( 'Licensio', $markup );
        $this->assertStringContainsString(
            '<span class="licensio-kb__title-accent">Documentation</span>',
            $markup
        );
    }

    /**
     * A one-word title is accented whole rather than dropped.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_one_word_hero_title_is_accented_whole() {
        $markup = $this->render_hero( array( 'title' => 'Documentation' ) );

        $this->assertStringContainsString(
            '<span class="licensio-kb__title-accent">Documentation</span>',
            $markup
        );
        $this->assertSame( 1, substr_count( $markup, '<h1 class="licensio-kb__title">' ) );
    }

    /**
     * Both halves of the heading reach the page escaped.
     *
     * The heading is built from two pieces, so escaping only the accented word
     * would leave the lead words as an injection point.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_hero_escapes_both_halves_of_its_title() {
        $markup = $this->render_hero( array( 'title' => '<script>alert(1)</script> <img onerror=x>' ) );

        $this->assertStringContainsString( '&lt;script&gt;', $markup );
        $this->assertStringContainsString( '&lt;img', $markup );
        $this->assertStringNotContainsString( '<script>', $markup );
        $this->assertStringNotContainsString( '<img', $markup );
    }

    /**
     * A subtitle is rendered as the standfirst, escaped.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_hero_renders_a_subtitle_when_one_is_given() {
        $markup = $this->render_hero( array( 'subtitle' => 'Everything <you> need.' ) );

        $this->assertStringContainsString(
            '<p class="licensio-kb__description">Everything &lt;you&gt; need.</p>',
            $markup
        );
    }

    /**
     * An empty subtitle produces no paragraph at all.
     *
     * An empty `<p>` would still take its margin, so the assertion is that the
     * element is absent rather than that it is blank.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_hero_omits_the_subtitle_paragraph_when_it_is_empty() {
        $markup = $this->render_hero();

        $this->assertStringContainsString( '<h1 class="licensio-kb__title">', $markup );
        $this->assertSame( 0, substr_count( $markup, 'licensio-kb__description' ) );
    }

    /**
     * The trail is rendered unless a caller turns it off.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_hero_renders_breadcrumbs_by_default() {
        $markup = $this->render_hero();

        $this->assertStringContainsString( 'class="licensio-kb__breadcrumbs"', $markup );
        $this->assertStringContainsString( '<a href="https://example.test/">Home</a>', $markup );
        $this->assertStringContainsString( 'Knowledge Base', $markup );
    }

    /**
     * A caller can turn the trail off and keep the heading.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_hero_drops_the_breadcrumbs_when_they_are_turned_off() {
        $markup = $this->render_hero( array( 'breadcrumbs' => false ) );

        $this->assertStringContainsString( '<h1 class="licensio-kb__title">', $markup );
        $this->assertSame( 0, substr_count( $markup, 'licensio-kb__breadcrumbs' ) );
    }

    /**
     * The hero is rendered outside the contained wrapper, in a bleed wrapper.
     *
     * This is a layout contract, not a cosmetic one. A theme overrides
     * `licensio-kb/hero.php` to render its own site-wide hero band, and that
     * band is only able to reach the viewport edges if nothing above it caps
     * its width. `.licensio-kb` sets `max-width` and horizontal padding, and a
     * child element cannot escape its parent, so a hero rendered inside it is
     * permanently inset however the theme styles it.
     *
     * The bleed wrapper still carries `.licensio-kb` because the design tokens
     * and the settings-driven inline block are both scoped to that class.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_hero_is_rendered_outside_the_contained_wrapper() {
        foreach ( $this->render_hero_views() as $view => $markup ) {
            $bleed = strpos( $markup, 'class="licensio-kb licensio-kb--bleed"' );
            $hero  = strpos( $markup, '<header class="licensio-kb__hero">' );

            $this->assertNotFalse( $bleed, $view . ' renders no bleed wrapper' );
            $this->assertNotFalse( $hero, $view . ' renders no hero' );
            $this->assertLessThan( $hero, $bleed, $view . ' opens its hero before the bleed wrapper' );

            /*
             * The contained wrapper must open after the hero has closed. If it
             * opened first the hero would be nested inside it, which is the
             * exact defect this guards.
             *
             * The class list may carry a sidebar position modifier after the
             * view modifier, so the match runs to the closing quote rather
             * than assuming the view modifier is the last class on the tag.
             */
            $contained = preg_match( '/<div class="licensio-kb licensio-kb--(?!bleed)[a-z]+[^"]*"/', $markup, $m, PREG_OFFSET_CAPTURE );

            $this->assertSame( 1, $contained, $view . ' renders no contained wrapper' );
            $this->assertGreaterThan( $hero, $m[0][1], $view . ' nests its hero inside the contained wrapper' );
        }
    }

    /**
     * The stylesheet lets the bleed wrapper span its container.
     *
     * The markup contract above is only half of it: if `--bleed` still
     * inherited the contained wrapper's `max-width`, moving the hero out would
     * change nothing on the page. Asserted against the stylesheet for the same
     * reason the list arrow is - the two layers have to agree, and nothing
     * else fails when they stop agreeing.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_stylesheet_releases_the_bleed_wrapper_from_its_max_width() {
        $css = (string) file_get_contents( LICENSIO_KB_ABSPATH . 'assets/css/licensio-kb.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

        $this->assertMatchesRegularExpression(
            '/\.licensio-kb\.licensio-kb--bleed\s*\{[^}]*max-width:\s*none;/',
            $css,
            'the bleed wrapper does not release its max-width'
        );
        $this->assertMatchesRegularExpression(
            '/\.licensio-kb\.licensio-kb--bleed\s*\{[^}]*padding:\s*0;/',
            $css,
            'the bleed wrapper does not drop its padding'
        );
    }

    /**
     * The plugin's own hero supplies the containment the wrapper used to.
     *
     * Releasing the bleed wrapper is only safe because the hero inside it
     * re-supplies both halves of what the contained wrapper was giving it: the
     * measure, and the gutter. Without the measure the heading runs the width
     * of the viewport; without the gutter the band runs edge to edge below the
     * max width and presses its corner radius flat.
     *
     * Only a theme should opt into a full-bleed band, so a plugin default that
     * bled would be wrong on every theme but the one it was written against.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_stylesheet_keeps_the_plugin_hero_contained_inside_the_bleed_wrapper() {
        $css = (string) file_get_contents( LICENSIO_KB_ABSPATH . 'assets/css/licensio-kb.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

        $this->assertMatchesRegularExpression(
            '/\.licensio-kb--bleed > \.licensio-kb__hero\s*\{[^}]*max-width:\s*var\(\s*--licensio-kb-max-width\s*\);/',
            $css,
            'the hero does not restore its measure outside the contained wrapper'
        );
        $this->assertMatchesRegularExpression(
            '/\.licensio-kb--bleed > \.licensio-kb__hero\s*\{[^}]*width:\s*calc\(\s*100% - 2rem\s*\);/',
            $css,
            'the hero does not restore its gutter outside the contained wrapper'
        );
    }

    /**
     * Every view renders one hero, and exactly one.
     *
     * Zero means a view still collides with a theme's fixed header, which is
     * the bug this template exists to close. Two means a view kept its own
     * heading and stacked the hero on top of it.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_every_view_renders_exactly_one_hero() {
        foreach ( $this->render_hero_views() as $view => $markup ) {
            $this->assertSame(
                1,
                substr_count( $markup, '<header class="licensio-kb__hero">' ),
                $view . ' does not render exactly one hero'
            );
            $this->assertSame(
                1,
                substr_count( $markup, '<h1' ),
                $view . ' does not render exactly one first-level heading'
            );
        }

        /*
         * The redesigned views open with the page header instead: no hero
         * band, still exactly one first-level heading.
         */
        foreach ( array(
            'archive'  => $this->render_archive(),
            'category' => $this->render_category(),
        ) as $view => $markup ) {
            $this->assertSame(
                0,
                substr_count( $markup, '<header class="licensio-kb__hero">' ),
                $view . ' renders a hero the redesign replaced'
            );
            $this->assertSame(
                1,
                substr_count( $markup, '<h1' ),
                $view . ' does not render exactly one first-level heading'
            );
        }
    }

    /**
     * Each view's hero carries that view's own heading.
     *
     * A shared template is only uniform if it is also correct on each view, so
     * the count above is paired with the text each view puts through it.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_each_view_puts_its_own_title_through_the_hero() {
        $expected = array(
            'archive'  => 'Help Topics',
            'single'   => 'Resetting your password',
            'category' => 'Getting started',
            'tag'      => 'Content',
            'search'   => 'Search results for “licence key”',
        );

        foreach ( $this->render_every_view() as $view => $markup ) {
            $heading = '';

            if ( preg_match( '#<h1 class="licensio-kb__(?:title|pagehead-title)">(.*?)</h1>#s', $markup, $matches ) ) {
                $heading = trim( preg_replace( '#\s+#', ' ', wp_strip_all_tags( $matches[1] ) ) );
            }

            $this->assertSame( $expected[ $view ], $heading, $view . ' has the wrong heading' );
        }
    }

    /**
     * A term description becomes the standfirst on both taxonomy views.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_term_description_becomes_the_hero_subtitle() {
        $this->assertStringContainsString(
            '<p class="licensio-kb__pagehead-subtitle">Everything you need to get going.</p>',
            $this->render_category()
        );

        $this->assertStringContainsString(
            '<p class="licensio-kb__description">Everything filed under content.</p>',
            $this->render_tag()
        );
    }

    /**
     * A term with no description gets no empty standfirst.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_term_without_a_description_gets_no_hero_subtitle() {
        $markup = $this->render_category( '' );

        $this->assertStringContainsString( '<header class="licensio-kb__pagehead">', $markup );
        $this->assertSame( 0, substr_count( $markup, 'licensio-kb__pagehead-subtitle' ) );
        $this->assertSame( 0, substr_count( $markup, 'licensio-kb__description' ) );
    }

    /**
     * One theme override replaces the hero on all five views at once.
     *
     * This is the acceptance criterion of the whole change: the theme hands the
     * knowledge base its own hero by copying one file, not five.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_theme_override_of_the_hero_reaches_every_view() {
        file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents
            $this->theme_dir . '/licensio-kb/hero.php',
            '<section class="theme-page-hero"><h1>THEME HERO</h1></section>'
        );

        $theme_dir = $this->theme_dir;

        Functions\when( 'locate_template' )->alias(
            function ( $files ) use ( $theme_dir ) {
                foreach ( (array) $files as $file ) {
                    $candidate = $theme_dir . '/' . $file;

                    if ( is_readable( $candidate ) ) {
                        return $candidate;
                    }
                }

                return '';
            }
        );

        foreach ( $this->render_hero_views() as $view => $markup ) {
            $this->assertStringContainsString(
                '<section class="theme-page-hero"><h1>THEME HERO</h1></section>',
                $markup,
                $view . ' did not take the theme override'
            );
            $this->assertSame(
                0,
                substr_count( $markup, 'licensio-kb__hero' ),
                $view . ' rendered the plugin hero alongside the override'
            );
        }
    }

    /**
     * The archive cards carry their article count beside the title.
     *
     * The redesign's cards are title, count, article list and View all link.
     * `category-card.php` counts by default, and the archive keeps it on.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_archive_cards_show_their_article_count() {
        $markup = $this->render_archive();

        $this->assertStringContainsString( 'licensio-kb-card--category', $markup );
        $this->assertStringContainsString( 'Getting started', $markup );
        $this->assertStringContainsString( '<span class="licensio-kb-card__count">', $markup );
        $this->assertStringContainsString( '(1 Article)', $markup );
    }

    /**
     * The category view still counts, so the archive did not change the default.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_card_still_counts_when_nothing_turns_the_count_off() {
        $term = new WP_Term(
            array(
                'term_id'     => 5,
                'name'        => 'Getting started',
                'taxonomy'    => Licensio_KB_Post_Types::TAXONOMY_CATEGORY,
                'count'       => 3,
                'description' => '',
            )
        );

        $this->stub_one_category();

        $markup = licensio_kb_get_template(
            'category-card',
            array(
                'term'          => $term,
                'show_children' => false,
            ),
            false
        );

        $this->assertStringContainsString( 'licensio-kb-card__count', $markup );
        $this->assertStringContainsString( '(3 Articles)', $markup );
    }

    /**
     * The archive no longer renders a latest-articles list.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_archive_does_not_render_a_latest_articles_section() {
        $markup = $this->render_archive();

        $this->assertStringContainsString( 'licensio-kb__grid--divided', $markup );
        $this->assertStringContainsString( 'licensio-kb__grid--cols-2', $markup );
        $this->assertSame( 0, substr_count( $markup, 'Latest articles' ) );
        $this->assertSame( 0, substr_count( $markup, 'licensio-kb__section-title' ) );
        $this->assertSame( 0, substr_count( $markup, 'licensio-kb-card--article' ) );
    }

    /**
     * An article filed under no category is still reachable from the archive.
     *
     * The archive renders categories and the articles inside them, so an
     * article with no category is linked from nowhere on `/docs/` — published
     * and unreachable. The list exists to close that hole, and this is the
     * assertion that it does.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_uncategorised_article_is_listed_on_the_archive() {
        $this->uncategorised = array( $this->orphan( 31, 'A stray note' ) );

        $markup = $this->render_archive();

        $this->assertStringContainsString( 'Uncategorised', $markup );
        $this->assertStringContainsString( 'licensio-kb-card--article', $markup );
        $this->assertStringContainsString( 'A stray note', $markup );
    }

    /**
     * The list renders nothing at all when every article is filed.
     *
     * Design parity depends on this: on a well-organised site the archive is
     * the grid and nothing else, exactly as the documentation design has it.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_uncategorised_list_is_absent_when_every_article_is_filed() {
        $markup = $this->render_archive();

        $this->assertStringContainsString( 'licensio-kb__grid--cols-2', $markup );
        $this->assertSame( 0, substr_count( $markup, 'Uncategorised' ) );
        $this->assertSame( 0, substr_count( $markup, 'licensio-kb__section' ) );
    }

    /**
     * The uncategorised query asks for exactly that, and respects the cap.
     *
     * `NOT EXISTS` against the category taxonomy is the whole behaviour: any
     * other clause would return categorised articles too and the list would
     * duplicate the grid.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_uncategorised_query_asks_for_articles_in_no_category() {
        $seen = array();

        Functions\when( 'get_posts' )->alias(
            function ( $args = array() ) use ( &$seen ) {
                $seen = (array) $args;

                return array();
            }
        );

        Licensio_KB_Templates::uncategorised_articles();

        $this->assertSame( Licensio_KB_Post_Types::POST_TYPE, $seen['post_type'] );
        $this->assertSame( 'publish', $seen['post_status'] );
        $this->assertSame( Licensio_KB_Templates::UNCATEGORISED_LIMIT, $seen['posts_per_page'] );
        $this->assertSame(
            array(
                'taxonomy' => Licensio_KB_Post_Types::TAXONOMY_CATEGORY,
                'operator' => 'NOT EXISTS',
            ),
            $seen['tax_query'][0]
        );
    }

    /**
     * The cap is honoured, and a filter can move it.
     *
     * The list is capped rather than paginated, so the cap is the only thing
     * standing between a badly filed site and an unbounded query.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_uncategorised_cap_is_applied_and_filterable() {
        $requested = 0;

        Functions\when( 'get_posts' )->alias(
            function ( $args = array() ) use ( &$requested ) {
                $requested = (int) $args['posts_per_page'];

                return array();
            }
        );

        Licensio_KB_Templates::uncategorised_articles( 5 );

        $this->assertSame( 5, $requested );

        Filters\expectApplied( 'licensio_uncategorised_limit' )->andReturn( 2 );

        Licensio_KB_Templates::uncategorised_articles();

        $this->assertSame( 2, $requested );
    }

    /**
     * A cap of zero turns the list off without running a query.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_zero_uncategorised_cap_runs_no_query() {
        $ran = false;

        Functions\when( 'get_posts' )->alias(
            function () use ( &$ran ) {
                $ran = true;

                return array();
            }
        );

        $this->assertSame( array(), Licensio_KB_Templates::uncategorised_articles( 0 ) );
        $this->assertFalse( $ran );
    }

    /**
     * The empty state waits until there is neither a category nor a stray.
     *
     * A site whose only content is uncategorised must not be told it is empty
     * while that content is on the page underneath the message.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_empty_state_yields_to_an_uncategorised_article() {
        $this->uncategorised = array( $this->orphan( 31, 'A stray note' ) );

        $markup = $this->render_archive( false );

        $this->assertStringContainsString( 'A stray note', $markup );
        $this->assertSame( 0, substr_count( $markup, 'The knowledge base is empty' ) );
    }

    /**
     * With no categories and no strays the archive says it is empty.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_archive_with_nothing_on_it_says_so() {
        $markup = $this->render_archive( false );

        $this->assertStringContainsString( 'The knowledge base is empty', $markup );
        $this->assertSame( 0, substr_count( $markup, 'licensio-kb__grid--cols-3' ) );
    }

    /**
     * The archive closes with its seam hook, once.
     *
     * A theme attaches its call to action band here, and a band rendered twice
     * would be as wrong as one never rendered at all.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_archive_fires_its_closing_hook_once() {
        Actions\expectDone( 'licensio_kb_after_archive' )->once();

        $markup = $this->render_archive();

        $this->assertStringContainsString( 'licensio-kb--archive', $markup );
    }

    /**
     * The archive renders no search form.
     *
     * The redesign's archive is the Help Topics page: a heading, a rule, the
     * divided grid and the sidebar, with no hero band and no search box. The
     * search view still opens with the search form, so the form itself is
     * asserted there instead.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_archive_renders_no_search_form() {
        $markup = $this->render_archive();

        $this->assertSame( 0, substr_count( $markup, 'licensio-kb__search' ) );
        $this->assertSame( 0, substr_count( $markup, '<form role="search"' ) );

        $search = $this->render_search();

        $this->assertStringContainsString( 'class="licensio-kb__search"', $search );
    }

    /**
     * The search form exists once, in one template.
     *
     * The archive and the search results page both render a search form. They
     * used to carry their own copy of the markup, so a fix to one silently
     * left the other behind. Both now go through the hero, which renders the
     * single `search-field` template.
     *
     * The name matters: `search-form` is already the search block's override
     * seam, and a template at that name would replace the block's ARIA
     * combobox with this plain form.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_only_one_template_carries_the_search_form_markup() {
        $carriers = array();

        foreach ( glob( LICENSIO_KB_ABSPATH . 'templates/*.php' ) as $template ) {
            $contents = (string) file_get_contents( $template ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

            if ( false !== strpos( $contents, '<form role="search"' ) ) {
                $carriers[] = basename( $template );
            }
        }

        $this->assertSame( array( 'search-field.php' ), $carriers );
    }
    /**
     * The archive sidebar carries the redesign widgets, not a category list.
     *
     * The main column of the archive IS the category list, so repeating those
     * links in a narrow column beside the cards that already name them is a
     * navigation aid that navigates nowhere. The redesign's sidebar is the
     * Popular Articles and Need Support pair.
     *
     * Asserted rather than left to a comment, because adding the categories
     * back is an obvious-looking change that nothing else would fail on.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_archive_sidebar_carries_popular_articles_but_not_categories() {
        $markup = $this->render_archive();

        $at = strpos( $markup, 'licensio-kb__sidebar' );

        $this->assertNotFalse( $at, 'the archive renders no sidebar' );

        $sidebar = substr( $markup, (int) $at );

        $this->assertStringContainsString( 'licensio-kb-widget--popular', $sidebar );
        $this->assertStringNotContainsString( 'licensio-kb-widget--categories', $sidebar );
        $this->assertStringNotContainsString( 'licensio-kb-widget--toc', $sidebar );
        $this->assertStringNotContainsString( 'licensio-kb-widget--tags', $sidebar );
    }

    /**
     * The archive reads its own position setting, not another view's.
     *
     * #29 registered one position key per context, so a view reading the wrong
     * one behaves correctly on a site that has never changed the defaults -
     * they are all `right` - and silently ignores the setting on a site that
     * has. Nothing about the rendered page reveals it.
     *
     * Caught by switching off the archive's own key and requiring the sidebar
     * to disappear. A view reading `article_sidebar_position` instead would
     * still render one, because that key is untouched.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_archive_reads_its_own_position_setting() {
        $this->assertStringContainsString(
            'licensio-kb__sidebar',
            $this->render_archive(),
            'the archive renders no sidebar with the default settings'
        );

        Functions\when( 'get_option' )->alias(
            function ( $name, $default_value = false ) {
                if ( Licensio_KB_Settings::OPTION_NAME === $name ) {
                    return array( 'archive_sidebar_position' => Licensio_KB_Sidebar::POSITION_NONE );
                }

                return $default_value;
            }
        );

        Licensio_KB_Sidebar::reset();

        $markup = $this->render_archive();

        Licensio_KB_Sidebar::reset();

        $this->assertStringNotContainsString(
            'licensio-kb__sidebar',
            $markup,
            'the archive kept its sidebar after its own position setting was turned off'
        );
    }
    /**
     * The archive grid honours the column setting.
     *
     * `archive_columns` was registered by #29 and consumed by nothing: the
     * archive hardcoded three, so changing the setting did nothing and said
     * nothing about it. A setting that silently does not apply is worse than
     * an absent one, because it invites someone to conclude the feature is
     * broken rather than unwired.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_archive_grid_honours_the_column_setting() {
        Functions\when( 'get_option' )->alias(
            function ( $name, $default_value = false ) {
                if ( Licensio_KB_Settings::OPTION_NAME === $name ) {
                    return array( 'archive_columns' => 2 );
                }

                return $default_value;
            }
        );

        $markup = $this->render_archive();

        $this->assertStringContainsString( 'licensio-kb__grid--cols-2', $markup );
        $this->assertStringNotContainsString( 'licensio-kb__grid--cols-3', $markup );
    }

    /**
     * A column count the stylesheet has no class for is clamped.
     *
     * The grid rules stop at four. A stored 9 would emit `--cols-9`, which no
     * rule matches, and the grid would collapse to a single column - a worse
     * outcome than the value simply being out of range.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_out_of_range_column_count_is_clamped() {
        Functions\when( 'get_option' )->alias(
            function ( $name, $default_value = false ) {
                if ( Licensio_KB_Settings::OPTION_NAME === $name ) {
                    return array( 'archive_columns' => 9 );
                }

                return $default_value;
            }
        );

        $this->assertStringContainsString( 'licensio-kb__grid--cols-4', $this->render_archive() );
    }

    /**
     * The public archive is titled Documentation, not Articles.
     *
     * WordPress derives an archive title from the post type's plural label.
     * That put "Articles" on a page whose menu item says Docs and whose
     * address is /docs/ - a fourth word for something already called three
     * things. The filter is asserted rather than the rendered heading alone,
     * because a template hardcoding the string would satisfy the page while
     * leaving breadcrumbs, feeds and SEO output still saying Articles.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_archive_is_titled_documentation() {
        $types = new Licensio_KB_Post_Types( new Licensio_KB_Settings() );

        $this->assertSame(
            'Documentation',
            $types->filter_archive_title( 'Articles', Licensio_KB_Post_Types::POST_TYPE )
        );
    }

    /**
     * Another post type's archive title is left alone.
     *
     * The counterpart: a filter that returned Documentation unconditionally
     * would pass the test above and rename every archive on the site.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_another_archive_title_is_untouched() {
        $types = new Licensio_KB_Post_Types( new Licensio_KB_Settings() );

        $this->assertSame( 'Products', $types->filter_archive_title( 'Products', 'product' ) );
    }

    /**
     * The archive crumb in the breadcrumb trail says Documentation.
     *
     * The heading and the trail are produced by two different filters reading
     * two different sources, so renaming the heading left the trail underneath
     * it still saying Articles.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_breadcrumb_archive_crumb_is_renamed() {
        Functions\when( 'get_post_type_archive_link' )->justReturn( 'https://example.test/docs/' );

        $types = new Licensio_KB_Post_Types( new Licensio_KB_Settings() );

        $items = $types->filter_breadcrumb_items(
            array(
                array(
                    'name' => 'Home',
                    'url'  => 'https://example.test/',
                ),
                array(
                    'name' => 'Articles',
                    'url'  => 'https://example.test/docs/',
                ),
            )
        );

        $this->assertSame( 'Home', $items[0]['name'] );
        $this->assertSame( 'Documentation', $items[1]['name'] );
    }

    /**
     * The crumb is found wherever the trail puts it.
     *
     * A single article's trail is Home > archive > category > article, so the
     * archive is no longer the last item. An implementation that renamed a
     * fixed position would pass the archive test above and rename the wrong
     * crumb here.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_crumb_is_renamed_part_way_along_the_trail() {
        Functions\when( 'get_post_type_archive_link' )->justReturn( 'https://example.test/docs/' );

        $types = new Licensio_KB_Post_Types( new Licensio_KB_Settings() );

        $items = $types->filter_breadcrumb_items(
            array(
                array(
                    'name' => 'Home',
                    'url'  => 'https://example.test/',
                ),
                array(
                    'name' => 'Articles',
                    'url'  => 'https://example.test/docs/',
                ),
                array(
                    'name' => 'Account Management',
                    'url'  => 'https://example.test/docs-category/account/',
                ),
                array(
                    'name' => 'How do I Change my Password?',
                    'url'  => 'https://example.test/docs/password/',
                ),
            )
        );

        $this->assertSame( 'Documentation', $items[1]['name'] );
        $this->assertSame( 'Account Management', $items[2]['name'] );
        $this->assertSame( 'How do I Change my Password?', $items[3]['name'] );
    }

    /**
     * Another plugin's crumb reading "Articles" is left alone.
     *
     * The counterpart to matching on the URL: renaming by text would reach
     * into a trail this plugin has nothing to do with.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_another_trail_saying_articles_is_untouched() {
        Functions\when( 'get_post_type_archive_link' )->justReturn( 'https://example.test/docs/' );

        $types = new Licensio_KB_Post_Types( new Licensio_KB_Settings() );

        $items = $types->filter_breadcrumb_items(
            array(
                array(
                    'name' => 'Articles',
                    'url'  => 'https://example.test/blog/articles/',
                ),
            )
        );

        $this->assertSame( 'Articles', $items[0]['name'] );
    }
}
