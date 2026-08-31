<?php
/**
 * Unit tests for the classic template layer.
 *
 * Covers template routing, a real theme override winning over a real plugin
 * template, the frontend hook contract firing once per article render, the
 * settings-driven custom properties and the breadcrumb hierarchy.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

/**
 * Tests Lisensio_KB_Templates and the templates it renders.
 *
 * @since 1.0.0
 */
class Lisensio_KB_Templates_Test extends Lisensio_KB_Test_Case {

    /**
     * A throwaway theme root used by the override tests.
     *
     * Lives in the system temp directory so an aborted run cannot leave a file
     * where PHPCS would lint it or git would offer to commit it.
     *
     * @since 1.0.0
     * @var string
     */
    private $theme_dir = '';

    /**
     * The template layer under test.
     *
     * @since 1.0.0
     * @var Lisensio_KB_Templates
     */
    private $templates;

    /**
     * Stub the WordPress surface the template layer touches.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        Functions\when( 'untrailingslashit' )->alias(
            function ( $value ) {
                return rtrim( (string) $value, '/\\' );
            }
        );

        Functions\when( 'wp_is_block_theme' )->justReturn( false );
        Functions\when( 'locate_template' )->justReturn( '' );
        Functions\when( 'do_blocks' )->returnArg();
        Functions\when( 'get_stylesheet_directory' )->justReturn( LISENSIO_KB_ABSPATH . 'tests/no-such-theme' );
        Functions\when( 'get_template_directory' )->justReturn( LISENSIO_KB_ABSPATH . 'tests/no-such-theme' );

        Functions\when( 'home_url' )->alias(
            function ( $path = '' ) {
                return 'https://example.test' . ( '' === $path ? '' : $path );
            }
        );

        Functions\when( 'get_post_type_archive_link' )->justReturn( 'https://example.test/docs/' );
        Functions\when( 'number_format_i18n' )->returnArg();
        Functions\when( 'wp_kses_post' )->returnArg();

        $this->stub_conditionals();

        $this->theme_dir = sys_get_temp_dir() . '/lisensio-kb-classic-theme-' . uniqid();

        mkdir( $this->theme_dir . '/lisensio-kb', 0777, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir

        $this->templates = new Lisensio_KB_Templates( new Lisensio_KB_Settings() );
    }

    /**
     * Remove the throwaway theme.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function tearDown(): void {
        if ( '' !== $this->theme_dir && is_dir( $this->theme_dir . '/lisensio-kb' ) ) {
            foreach ( (array) glob( $this->theme_dir . '/lisensio-kb/*' ) as $file ) {
                unlink( (string) $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.WP.AlternativeFunctions.file_system_operations_unlink
            }

            rmdir( $this->theme_dir . '/lisensio-kb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
            rmdir( $this->theme_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
        }

        parent::tearDown();
    }

    /**
     * Point every template conditional at one view.
     *
     * @since 1.0.0
     *
     * @param string $view      One of `single`, `category`, `tag`, `archive`, `search` or `` for none.
     * @param string $post_type The post type the query asked for, used by the search tests.
     *
     * @return void
     */
    private function stub_conditionals( $view = '', $post_type = '' ) {
        Functions\when( 'is_singular' )->alias(
            function ( $type = '' ) use ( $view ) {
                return 'single' === $view && Lisensio_KB_Post_Types::POST_TYPE === $type;
            }
        );

        Functions\when( 'is_tax' )->alias(
            function ( $taxonomy = '' ) use ( $view ) {
                if ( 'category' === $view ) {
                    return Lisensio_KB_Post_Types::TAXONOMY_CATEGORY === $taxonomy;
                }

                if ( 'tag' === $view ) {
                    return Lisensio_KB_Post_Types::TAXONOMY_TAG === $taxonomy;
                }

                return false;
            }
        );

        Functions\when( 'is_post_type_archive' )->alias(
            function ( $type = '' ) use ( $view ) {
                return 'archive' === $view && Lisensio_KB_Post_Types::POST_TYPE === $type;
            }
        );

        Functions\when( 'is_search' )->justReturn( 'search' === $view );

        Functions\when( 'get_query_var' )->alias(
            function ( $name ) use ( $post_type ) {
                return 'post_type' === $name ? $post_type : '';
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
        return LISENSIO_KB_ABSPATH . 'templates/' . $name . '.php';
    }

    /**
     * Make locate_template() search the throwaway theme, as WordPress would.
     *
     * @since 1.0.0
     *
     * @return void
     */
    private function stub_theme_lookup() {
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
    }

    /**
     * A single article is answered with the single template.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_single_article_loads_the_single_template() {
        $this->stub_conditionals( 'single' );

        $this->assertSame( 'single', $this->templates->template_name() );
        $this->assertSame( $this->plugin_template( 'single' ), $this->templates->template_include( 'theme/index.php' ) );
    }

    /**
     * Each taxonomy archive is answered with its own template.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_each_taxonomy_archive_loads_its_own_template() {
        $this->stub_conditionals( 'category' );

        $this->assertSame( $this->plugin_template( 'taxonomy-category' ), $this->templates->template_include( 'theme/index.php' ) );

        $this->stub_conditionals( 'tag' );

        $this->assertSame( $this->plugin_template( 'taxonomy-tag' ), $this->templates->template_include( 'theme/index.php' ) );
    }

    /**
     * The post type archive is answered with the archive template.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_post_type_archive_loads_the_archive_template() {
        $this->stub_conditionals( 'archive' );

        $this->assertSame( $this->plugin_template( 'archive' ), $this->templates->template_include( 'theme/index.php' ) );
    }

    /**
     * A search scoped to articles is answered with the search results template.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_article_search_loads_the_search_results_template() {
        $this->stub_conditionals( 'search', Lisensio_KB_Post_Types::POST_TYPE );

        $this->assertSame( $this->plugin_template( 'search-results' ), $this->templates->template_include( 'theme/search.php' ) );
    }

    /**
     * A site-wide search is left to the theme.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_site_wide_search_is_left_to_the_theme() {
        $this->stub_conditionals( 'search', '' );

        $this->assertSame( '', $this->templates->template_name() );
        $this->assertSame( 'theme/search.php', $this->templates->template_include( 'theme/search.php' ) );
    }

    /**
     * A request that is not a knowledge base view keeps the theme's template.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_non_knowledge_base_request_keeps_the_theme_template() {
        $this->assertSame( '', $this->templates->template_name() );
        $this->assertSame( 'theme/index.php', $this->templates->template_include( 'theme/index.php' ) );
    }

    /**
     * Under a block theme the classic loader stands aside.
     *
     * The block template layer answers the same requests through the Site
     * Editor; a classic `template_include` takeover there would bypass it.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_classic_loader_stands_aside_under_a_block_theme() {
        $this->stub_conditionals( 'single' );

        Functions\when( 'wp_is_block_theme' )->justReturn( true );

        $this->assertSame( 'theme/index.php', $this->templates->template_include( 'theme/index.php' ) );
    }

    /**
     * A theme copy of a template wins over the plugin's own.
     *
     * Exercised end to end through a real file on disk rather than a mocked
     * resolver: the theme copy is written, rendered and compared against the
     * plugin copy rendered from the same call.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_theme_override_wins_over_the_plugin_template() {
        $override = $this->theme_dir . '/lisensio-kb/article-card.php';

        file_put_contents( $override, '<?php echo "THEME ARTICLE CARD"; ' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

        $this->stub_theme_lookup();
        $this->stub_article( 7, 'Resetting your password' );

        $this->assertSame( $override, lisensio_kb_locate_template( 'article-card' ) );
        $this->assertSame( 'THEME ARTICLE CARD', lisensio_kb_get_template( 'article-card', array( 'post' => 7 ), false ) );

        unlink( $override ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.WP.AlternativeFunctions.file_system_operations_unlink

        $this->assertSame( $this->plugin_template( 'article-card' ), lisensio_kb_locate_template( 'article-card' ) );

        $plugin_markup = lisensio_kb_get_template( 'article-card', array( 'post' => 7 ), false );

        $this->assertStringContainsString( 'lisensio-kb-card--article', $plugin_markup );
        $this->assertStringNotContainsString( 'THEME ARTICLE CARD', $plugin_markup );
    }

    /**
     * The template loader routes to a theme override too.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_loader_routes_to_a_theme_override() {
        $override = $this->theme_dir . '/lisensio-kb/single.php';

        file_put_contents( $override, '<?php // Theme single. ' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

        $this->stub_theme_lookup();
        $this->stub_conditionals( 'single' );

        $this->assertSame( $override, $this->templates->template_include( 'theme/index.php' ) );
    }

    /**
     * Every template named by the specification ships with the plugin.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_whole_template_set_ships() {
        $names = array(
            'archive',
            'single',
            'taxonomy-category',
            'taxonomy-tag',
            'search-results',
            'hero',
            'breadcrumbs',
            'article-card',
            'category-card',
            'no-results',
        );

        foreach ( $names as $name ) {
            $this->assertSame(
                $this->plugin_template( $name ),
                lisensio_kb_locate_template( $name ),
                $name . ' is missing from templates/'
            );
        }
    }

    /**
     * Each of the three frontend hooks fires exactly once per article render.
     *
     * Feedback and analytics attach to these and must not be able to run twice
     * on one page, so the count is asserted rather than just the presence.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_three_article_hooks_fire_exactly_once_per_render() {
        Actions\expectDone( 'lisensio_before_article' )->once();
        Actions\expectDone( 'lisensio_after_article' )->once();
        Actions\expectDone( 'lisensio_article_footer' )->once();

        $markup = $this->render_single_article();

        $this->assertStringContainsString( 'ARTICLE BODY', $markup );
    }

    /**
     * The article title reaches the page escaped.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_single_template_escapes_the_article_title() {
        $markup = $this->render_single_article( '<script>alert(1)</script>' );

        $this->assertStringNotContainsString( '<script>', $markup );
        $this->assertStringContainsString( '&lt;script&gt;', $markup );
    }

    /**
     * The article card escapes the title it links.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_article_card_escapes_the_article_title() {
        $this->stub_article( 12, '<script>alert(1)</script>' );

        $markup = lisensio_kb_get_template( 'article-card', array( 'post' => 12 ), false );

        $this->assertStringNotContainsString( '<script>', $markup );
        $this->assertStringContainsString( '&lt;script&gt;', $markup );
    }

    /**
     * The archive hero paints the last word of its heading, and only that word.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_archive_hero_accents_the_last_word_of_the_title() {
        $markup = $this->render_archive( 'Lisensio Documentation' );

        $this->assertStringContainsString( '<header class="lisensio-kb__hero">', $markup );
        $this->assertStringContainsString( 'Lisensio', $markup );
        $this->assertStringContainsString(
            '<span class="lisensio-kb__title-accent">Documentation</span>',
            $markup
        );
    }

    /**
     * A one-word archive title is accented whole rather than dropped.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_one_word_archive_title_is_accented_whole() {
        $markup = $this->render_archive( 'Documentation' );

        $this->assertStringContainsString(
            '<span class="lisensio-kb__title-accent">Documentation</span>',
            $markup
        );
    }

    /**
     * The archive title reaches the page escaped, both halves of it.
     *
     * The heading is now built from two pieces, so the escaping has to be
     * asserted on both: escaping only the accented word would leave the lead
     * words as an injection point.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_archive_escapes_both_halves_of_the_title() {
        $markup = $this->render_archive( '<script>alert(1)</script> <img onerror=x>' );

        $this->assertStringNotContainsString( '<script>', $markup );
        $this->assertStringNotContainsString( '<img', $markup );
        $this->assertStringContainsString( '&lt;script&gt;', $markup );
        $this->assertStringContainsString( '&lt;img', $markup );
    }

    /**
     * The archive lays its categories out in three columns.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_archive_grid_asks_for_three_columns() {
        $markup = $this->render_archive();

        $this->assertStringContainsString(
            '<div class="lisensio-kb__grid lisensio-kb__grid--cols-3">',
            $markup
        );
    }

    /**
     * Each archive card carries its category's articles and its icon.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_archive_cards_list_their_articles_and_show_their_icons() {
        $markup = $this->render_archive();

        $this->assertStringContainsString( 'lisensio-kb-card__articles', $markup );
        $this->assertStringContainsString(
            '<a class="lisensio-kb-card__article-link" href="https://example.test/docs/article-7/">',
            $markup
        );
        $this->assertStringContainsString( 'Quick start', $markup );
        $this->assertStringContainsString( 'dashicons dashicons-sos', $markup );
    }

    /**
     * Render templates/archive.php against one category holding one article.
     *
     * @since 1.0.0
     *
     * @param string $title The post type archive title.
     *
     * @return string The rendered markup.
     */
    private function render_archive( $title = 'Knowledge Base' ) {
        $this->stub_conditionals( 'archive' );

        $term = new WP_Term(
            array(
                'term_id'     => 5,
                'name'        => 'Getting started',
                'slug'        => 'getting-started',
                'taxonomy'    => Lisensio_KB_Post_Types::TAXONOMY_CATEGORY,
                'count'       => 1,
                'description' => 'From purchase to first licensed sale.',
            )
        );

        $article = new WP_Post(
            array(
                'ID'         => 7,
                'post_title' => 'Quick start',
                'post_type'  => Lisensio_KB_Post_Types::POST_TYPE,
            )
        );

        Functions\when( 'get_terms' )->alias(
            function ( $args = array() ) use ( $term ) {
                return isset( $args['child_of'] ) || 0 !== (int) $args['parent'] ? array() : array( $term );
            }
        );

        Functions\when( 'get_term' )->alias(
            function ( $value ) use ( $term ) {
                return $value instanceof WP_Term ? $value : $term;
            }
        );

        Functions\when( 'get_term_link' )->justReturn( 'https://example.test/docs-category/getting-started/' );
        Functions\when( 'get_term_meta' )->alias(
            function ( $term_id, $key, $single = false ) {
                unset( $term_id, $single );

                return 'lisensio_icon' === $key ? 'dashicons-sos' : '';
            }
        );

        Functions\when( 'get_posts' )->justReturn( array( $article ) );
        Functions\when( 'get_permalink' )->alias(
            function ( $post ) {
                return $post instanceof WP_Post ? 'https://example.test/docs/article-' . $post->ID . '/' : '';
            }
        );
        Functions\when( 'get_the_title' )->alias(
            function ( $post ) {
                return $post instanceof WP_Post ? $post->post_title : '';
            }
        );

        Functions\when( 'post_type_archive_title' )->justReturn( $title );
        Functions\when( 'get_search_query' )->justReturn( '' );
        Functions\when( 'have_posts' )->justReturn( false );
        Functions\when( 'get_header' )->justReturn( null );
        Functions\when( 'get_footer' )->justReturn( null );

        ob_start();

        include $this->plugin_template( 'archive' );

        return (string) ob_get_clean();
    }

    /**
     * A title with no space is returned whole as the accented half.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_split_title_accents_a_single_word_whole() {
        $this->assertSame( array( '', 'Documentation' ), Lisensio_KB_Templates::split_title( 'Documentation' ) );
    }

    /**
     * A multi-word title is split at its last space.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_split_title_splits_at_the_last_space() {
        $this->assertSame(
            array( 'The Lisensio', 'Documentation' ),
            Lisensio_KB_Templates::split_title( 'The Lisensio Documentation' )
        );
    }

    /**
     * Surrounding and doubled whitespace does not produce an empty accent.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_split_title_ignores_surrounding_whitespace() {
        $this->assertSame(
            array( 'Lisensio', 'Docs' ),
            Lisensio_KB_Templates::split_title( '  Lisensio  Docs  ' )
        );
    }

    /**
     * Render templates/single.php against a one-post loop.
     *
     * @since 1.0.0
     *
     * @param string $title The article title.
     *
     * @return string The rendered markup.
     */
    private function render_single_article( $title = 'Resetting your password' ) {
        $this->stub_conditionals( 'single' );
        $this->stub_article( 42, $title );

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

        Functions\when( 'the_post' )->justReturn( null );
        Functions\when( 'get_header' )->justReturn( null );
        Functions\when( 'get_footer' )->justReturn( null );
        Functions\when( 'get_the_term_list' )->justReturn( '' );
        Functions\when( 'get_the_modified_date' )->justReturn( '1 January 2026' );
        Functions\when( 'the_content' )->alias(
            function () {
                echo 'ARTICLE BODY';
            }
        );

        ob_start();

        include $this->plugin_template( 'single' );

        return (string) ob_get_clean();
    }

    /**
     * Stub the post functions around one article.
     *
     * @since 1.0.0
     *
     * @param int    $id    The article ID.
     * @param string $title The article title.
     *
     * @return void
     */
    private function stub_article( $id, $title ) {
        $article = new WP_Post(
            array(
                'ID'         => $id,
                'post_title' => $title,
                'post_type'  => Lisensio_KB_Post_Types::POST_TYPE,
            )
        );

        Functions\when( 'get_post' )->justReturn( $article );
        Functions\when( 'get_the_title' )->justReturn( $title );
        Functions\when( 'get_permalink' )->justReturn( 'https://example.test/docs/article/' );
        Functions\when( 'get_the_excerpt' )->justReturn( 'A short summary.' );
        Functions\when( 'get_the_terms' )->justReturn( array() );
    }

    /**
     * The breadcrumb trail walks the whole category hierarchy.
     *
     * A four-level category must produce all four levels in top-down order,
     * not just the article's immediate parent.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_breadcrumbs_walk_a_deep_category_hierarchy() {
        $terms = array(
            1 => new WP_Term(
                array(
                    'term_id'  => 1,
                    'name'     => 'Getting started',
                    'slug'     => 'getting-started',
                    'taxonomy' => Lisensio_KB_Post_Types::TAXONOMY_CATEGORY,
                )
            ),
            2 => new WP_Term(
                array(
                    'term_id'  => 2,
                    'name'     => 'Billing',
                    'slug'     => 'billing',
                    'parent'   => 1,
                    'taxonomy' => Lisensio_KB_Post_Types::TAXONOMY_CATEGORY,
                )
            ),
            3 => new WP_Term(
                array(
                    'term_id'  => 3,
                    'name'     => 'Invoices',
                    'slug'     => 'invoices',
                    'parent'   => 2,
                    'taxonomy' => Lisensio_KB_Post_Types::TAXONOMY_CATEGORY,
                )
            ),
            4 => new WP_Term(
                array(
                    'term_id'  => 4,
                    'name'     => 'Refunds',
                    'slug'     => 'refunds',
                    'parent'   => 3,
                    'taxonomy' => Lisensio_KB_Post_Types::TAXONOMY_CATEGORY,
                )
            ),
        );

        $this->stub_conditionals( 'single' );
        $this->stub_article( 42, 'How refunds are issued' );
        $this->stub_terms( $terms, array( 4 => array( 3, 2, 1 ) ) );

        Functions\when( 'get_the_terms' )->justReturn( array( $terms[4] ) );

        $items = Lisensio_KB_Templates::breadcrumb_items();

        $this->assertSame(
            array( 'Home', 'Knowledge Base', 'Getting started', 'Billing', 'Invoices', 'Refunds', 'How refunds are issued' ),
            array_column( $items, 'label' )
        );

        $this->assertSame( 'https://example.test/docs-category/refunds/', $items[5]['url'] );
        $this->assertSame( '', $items[6]['url'] );
    }

    /**
     * The deepest assigned category is the one the trail runs through.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_deepest_assigned_category_wins() {
        $parent = new WP_Term(
            array(
                'term_id'  => 1,
                'name'     => 'Guides',
                'slug'     => 'guides',
                'taxonomy' => Lisensio_KB_Post_Types::TAXONOMY_CATEGORY,
            )
        );

        $child = new WP_Term(
            array(
                'term_id'  => 2,
                'name'     => 'Advanced guides',
                'slug'     => 'advanced-guides',
                'parent'   => 1,
                'taxonomy' => Lisensio_KB_Post_Types::TAXONOMY_CATEGORY,
            )
        );

        $this->stub_terms(
            array(
                1 => $parent,
                2 => $child,
            ),
            array( 2 => array( 1 ) )
        );

        Functions\when( 'get_the_terms' )->justReturn( array( $parent, $child ) );

        $primary = Lisensio_KB_Templates::primary_category( 42 );

        $this->assertInstanceOf( 'WP_Term', $primary );
        $this->assertSame( 2, $primary->term_id );
    }

    /**
     * Stub the term functions around a set of categories.
     *
     * @since 1.0.0
     *
     * @param array<int, WP_Term>        $terms     Term ID to term.
     * @param array<int, array<int,int>> $ancestors Term ID to its ancestor IDs, nearest first.
     *
     * @return void
     */
    private function stub_terms( array $terms, array $ancestors = array() ) {
        Functions\when( 'get_term' )->alias(
            function ( $term ) use ( $terms ) {
                if ( $term instanceof WP_Term ) {
                    return $term;
                }

                return isset( $terms[ (int) $term ] ) ? $terms[ (int) $term ] : null;
            }
        );

        Functions\when( 'get_ancestors' )->alias(
            function ( $term_id ) use ( $ancestors ) {
                return isset( $ancestors[ (int) $term_id ] ) ? $ancestors[ (int) $term_id ] : array();
            }
        );

        Functions\when( 'get_term_link' )->alias(
            function ( $term ) {
                return 'https://example.test/docs-category/' . $term->slug . '/';
            }
        );
    }

    /**
     * A category's article count includes everything filed beneath it.
     *
     * A parent that exists purely to group its children would otherwise report
     * zero articles while listing dozens.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_category_count_includes_its_sub_categories() {
        $parent = new WP_Term(
            array(
                'term_id'  => 1,
                'name'     => 'Guides',
                'slug'     => 'guides',
                'count'    => 2,
                'taxonomy' => Lisensio_KB_Post_Types::TAXONOMY_CATEGORY,
            )
        );

        $children = array(
            new WP_Term(
                array(
                    'term_id' => 2,
                    'name'    => 'Setup',
                    'slug'    => 'setup',
                    'parent'  => 1,
                    'count'   => 3,
                )
            ),
            new WP_Term(
                array(
                    'term_id' => 3,
                    'name'    => 'Deep setup',
                    'slug'    => 'deep-setup',
                    'parent'  => 2,
                    'count'   => 5,
                )
            ),
        );

        Functions\when( 'get_term' )->justReturn( $parent );
        Functions\when( 'get_terms' )->alias(
            function ( $args ) use ( $children ) {
                return isset( $args['child_of'] ) && 1 === (int) $args['child_of'] ? $children : array();
            }
        );

        $this->assertSame( 10, Lisensio_KB_Templates::category_article_count( $parent ) );
    }

    /**
     * A category with nothing anywhere beneath it is hidden from a listing.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_entirely_empty_category_is_hidden() {
        $full = new WP_Term(
            array(
                'term_id' => 1,
                'name'    => 'Guides',
                'slug'    => 'guides',
                'count'   => 0,
            )
        );

        $empty = new WP_Term(
            array(
                'term_id' => 5,
                'name'    => 'Empty',
                'slug'    => 'empty',
                'count'   => 0,
            )
        );

        $child = new WP_Term(
            array(
                'term_id' => 2,
                'name'    => 'Setup',
                'slug'    => 'setup',
                'parent'  => 1,
                'count'   => 4,
            )
        );

        Functions\when( 'get_term' )->returnArg();
        Functions\when( 'get_terms' )->alias(
            function ( $args ) use ( $full, $empty, $child ) {
                if ( isset( $args['child_of'] ) ) {
                    return 1 === (int) $args['child_of'] ? array( $child ) : array();
                }

                return array( $full, $empty );
            }
        );

        $listed = Lisensio_KB_Templates::top_level_categories();

        $this->assertCount( 1, $listed );
        $this->assertSame( 'Guides', $listed[0]->name );
    }

    /**
     * The brand colour reaches the page as a custom property.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_brand_colour_is_emitted_as_a_custom_property() {
        $this->options[ Lisensio_KB_Settings::OPTION_NAME ] = array( 'brand_color' => '#ff0000' );

        $css = $this->templates->inline_css();

        $this->assertStringContainsString( '--lisensio-kb-brand:#ff0000', $css );
        $this->assertStringContainsString( '--lisensio-kb-brand-rgb:255,0,0', $css );
        $this->assertStringStartsWith( '.lisensio-kb{', $css );
    }

    /**
     * A light brand colour gets dark text, a dark one gets light text.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_contrast_colour_follows_the_brand_colour() {
        $this->options[ Lisensio_KB_Settings::OPTION_NAME ] = array( 'brand_color' => '#ffffff' );

        $this->assertStringContainsString( '--lisensio-kb-brand-contrast:#000000', $this->templates->inline_css() );

        $this->options[ Lisensio_KB_Settings::OPTION_NAME ] = array( 'brand_color' => '#111111' );
        $this->templates                                    = new Lisensio_KB_Templates( new Lisensio_KB_Settings() );

        $this->assertStringContainsString( '--lisensio-kb-brand-contrast:#ffffff', $this->templates->inline_css() );
    }

    /**
     * The layout width and card style settings drive custom properties only.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_layout_and_card_settings_drive_custom_properties() {
        $this->options[ Lisensio_KB_Settings::OPTION_NAME ] = array(
            'layout_width' => 'wide',
            'card_style'   => 'border',
        );

        $properties = $this->templates->custom_properties();

        $this->assertSame( '90rem', $properties['--lisensio-kb-max-width'] );
        $this->assertSame( 'none', $properties['--lisensio-kb-card-shadow'] );
        $this->assertSame( '1px solid var(--lisensio-kb-line)', $properties['--lisensio-kb-card-border'] );

        foreach ( array_keys( $properties ) as $name ) {
            $this->assertStringStartsWith( '--lisensio-kb-', $name );
        }
    }

    /**
     * The stylesheet is enqueued on a knowledge base view and nowhere else.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_stylesheet_is_enqueued_on_knowledge_base_views_only() {
        $enqueued = array();

        Functions\when( 'wp_register_style' )->justReturn( true );
        Functions\when( 'wp_add_inline_style' )->justReturn( true );
        Functions\when( 'wp_enqueue_style' )->alias(
            function ( $handle ) use ( &$enqueued ) {
                $enqueued[] = $handle;
            }
        );

        $this->templates->enqueue_styles();

        $this->assertSame( array(), $enqueued );

        $this->stub_conditionals( 'archive' );

        $this->templates->enqueue_styles();

        $this->assertSame( array( Lisensio_KB_Templates::STYLE_HANDLE ), $enqueued );
    }

    /**
     * The template loader is registered on template_include.
     *
     * Brain Monkey does not run callbacks added with add_filter(), so the
     * registration itself is what is asserted here.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_loader_registers_itself() {
        $this->templates->init_hooks();

        $this->assertSame( 20, has_filter( 'template_include', array( $this->templates, 'template_include' ) ) );
        $this->assertSame( 10, has_action( 'wp_enqueue_scripts', array( $this->templates, 'enqueue_styles' ) ) );
    }

    /**
     * A traversing template name from the filter is refused.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_traversing_template_name_is_refused() {
        $this->stub_conditionals( 'single' );

        Filters\expectApplied( 'lisensio_template_name' )
            ->once()
            ->with( 'single' )
            ->andReturn( '../../../../wp-config' );

        $this->assertSame( '', $this->templates->template_name() );
    }

    /**
     * The template layer re-validates the settings it prints, on its own.
     *
     * The settings store already sanitizes on read, so a hostile value written
     * straight into the option never reaches here in practice. That also means
     * a test that goes through the option cannot tell whether this layer does
     * any validation of its own: it passes either way. Handing the layer a
     * settings store that returns hostile values directly is the only way to
     * hold the second line of defence to account.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_template_layer_re_validates_the_values_it_prints() {
        $templates = new Lisensio_KB_Templates( new Lisensio_KB_Hostile_Settings() );

        $properties = $templates->custom_properties();
        $css        = $templates->inline_css();

        $this->assertSame( '#2ba377', $properties['--lisensio-kb-brand'] );
        $this->assertSame( '72rem', $properties['--lisensio-kb-max-width'] );
        $this->assertSame( '0 4px 14px rgba(30,122,90,0.08)', $properties['--lisensio-kb-card-shadow'] );

        $this->assertStringNotContainsString( 'evil.test', $css );
        $this->assertStringNotContainsString( '}body{', $css );
        $this->assertStringNotContainsString( '</style>', $css );
    }

    /**
     * The last-resort brand colour is the Lisensio green, not a stray blue.
     *
     * The registered default is what a real settings store hands back, so the
     * literal in the template layer is only reached by a store that does not
     * declare one at all. That makes it the one value nothing else can correct,
     * and the one place a stale colour could sit unnoticed.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_last_resort_brand_colour_is_the_lisensio_green() {
        $templates = new Lisensio_KB_Templates( new Lisensio_KB_Defaultless_Settings() );

        $properties = $templates->custom_properties();

        $this->assertSame( '#2ba377', $properties['--lisensio-kb-brand'] );
        $this->assertSame( '43,163,119', $properties['--lisensio-kb-brand-rgb'] );
    }

    /**
     * A colour that is not a colour still resolves to the Lisensio green.
     *
     * `custom_properties()` validates before it splits, so this fallback is only
     * reached by a direct call — which is exactly why it is the sort of value
     * that goes stale unnoticed.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_unusable_hex_falls_back_to_the_lisensio_green() {
        $this->assertSame( array( 43, 163, 119 ), Lisensio_KB_Templates::hex_to_rgb( 'not-a-colour' ) );
        $this->assertSame( array( 43, 163, 119 ), Lisensio_KB_Templates::hex_to_rgb( '' ) );
        $this->assertSame( array( 43, 163, 119 ), Lisensio_KB_Templates::hex_to_rgb( '#2ba3' ) );
    }

    /**
     * The stylesheet pins the Lisensio design tokens.
     *
     * Radius, card shadow and heading font are what make the knowledge base
     * look like the rest of the site, and nothing else asserts them: the brand
     * colour is covered by the settings tests and the list arrow by its own
     * cross-layer contract test, but these three can be edited to any value
     * and every other test still passes. A regression here is invisible in CI
     * and obvious on the page, which is the worst combination, so this pins
     * them the same way the arrow contract is pinned.
     *
     * Each is asserted as `var( --theme-token, <lisensio default> )` because
     * both halves matter: the fallback keeps a standalone install on the
     * Lisensio design, and the `var()` lets an active theme that defines the
     * token win without the plugin knowing the theme exists.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_stylesheet_pins_the_lisensio_design_tokens() {
        $css = (string) file_get_contents( LISENSIO_KB_ABSPATH . 'assets/css/lisensio-kb.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

        $this->assertStringContainsString( '--lisensio-kb-radius: var(--radius, 18px);', $css );
        $this->assertStringContainsString( '--lisensio-kb-card-shadow: 0 4px 14px rgba(30, 122, 90, 0.08);', $css );
        $this->assertStringContainsString( '--lisensio-kb-font-head: var(--font-head, "Plus Jakarta Sans"', $css );
    }
}

/**
 * A settings store that declares no defaults at all.
 *
 * Every fallback in the template layer is written against a missing registered
 * default, and a store that has one hides all of them.
 *
 * @since 1.0.0
 */
class Lisensio_KB_Defaultless_Settings extends Lisensio_KB_Settings {

    /**
     * No registered defaults.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed> An empty set.
     */
    public function defaults() {
        return array();
    }

    /**
     * No stored values either.
     *
     * @since 1.0.0
     *
     * @param string $key      The setting name.
     * @param mixed  $fallback Value returned for a setting this double does not fake.
     *
     * @return mixed An empty string, so every value falls through to a default.
     */
    public function get( $key, $fallback = null ) {
        unset( $key, $fallback );

        return '';
    }
}

/**
 * A settings store that hands the template layer values it must refuse.
 *
 * Bypasses Lisensio_KB_Settings::all(), so the only thing standing between
 * these values and the style element is the template layer's own validation.
 *
 * @since 1.0.0
 */
class Lisensio_KB_Hostile_Settings extends Lisensio_KB_Settings {

    /**
     * Return a hostile value for every setting the stylesheet reads.
     *
     * @since 1.0.0
     *
     * @param string $key      The setting name.
     * @param mixed  $fallback Value returned for a setting this double does not fake.
     *
     * @return mixed The hostile value, or the fallback.
     */
    public function get( $key, $fallback = null ) {
        $hostile = array(
            'brand_color'  => 'red;}body{background:url(//evil.test/x)}.x{color:',
            'layout_width' => '1px;}</style><script>alert(1)</script>',
            'card_style'   => 'shadow;}body{display:none',
        );

        return array_key_exists( $key, $hostile ) ? $hostile[ $key ] : $fallback;
    }
}
