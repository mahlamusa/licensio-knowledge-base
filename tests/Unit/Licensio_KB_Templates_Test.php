<?php
/**
 * Unit tests for the classic template layer.
 *
 * Covers template routing, a real theme override winning over a real plugin
 * template, the frontend hook contract firing once per article render, the
 * settings-driven custom properties and the breadcrumb hierarchy.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

/**
 * Tests Licensio_KB_Templates and the templates it renders.
 *
 * @since 1.0.0
 */
class Licensio_KB_Templates_Test extends Licensio_KB_Test_Case {

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
     * @var Licensio_KB_Templates
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

        $this->stub_conditionals();

        $this->theme_dir = sys_get_temp_dir() . '/licensio-kb-classic-theme-' . uniqid();

        mkdir( $this->theme_dir . '/licensio-kb', 0777, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir

        $this->templates = new Licensio_KB_Templates( new Licensio_KB_Settings() );
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
     *
     * @param string $view      One of `single`, `category`, `tag`, `archive`, `search` or `` for none.
     * @param string $post_type The post type the query asked for, used by the search tests.
     *
     * @return void
     */
    private function stub_conditionals( $view = '', $post_type = '' ) {
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
        return LICENSIO_KB_ABSPATH . 'templates/' . $name . '.php';
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
        $this->stub_conditionals( 'search', Licensio_KB_Post_Types::POST_TYPE );

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
        $override = $this->theme_dir . '/licensio-kb/article-card.php';

        file_put_contents( $override, '<?php echo "THEME ARTICLE CARD"; ' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

        $this->stub_theme_lookup();
        $this->stub_article( 7, 'Resetting your password' );

        $this->assertSame( $override, licensio_kb_locate_template( 'article-card' ) );
        $this->assertSame( 'THEME ARTICLE CARD', licensio_kb_get_template( 'article-card', array( 'post' => 7 ), false ) );

        unlink( $override ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.WP.AlternativeFunctions.file_system_operations_unlink

        $this->assertSame( $this->plugin_template( 'article-card' ), licensio_kb_locate_template( 'article-card' ) );

        $plugin_markup = licensio_kb_get_template( 'article-card', array( 'post' => 7 ), false );

        $this->assertStringContainsString( 'licensio-kb-card--article', $plugin_markup );
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
        $override = $this->theme_dir . '/licensio-kb/single.php';

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
                licensio_kb_locate_template( $name ),
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
        Actions\expectDone( 'licensio_before_article' )->once();
        Actions\expectDone( 'licensio_after_article' )->once();
        Actions\expectDone( 'licensio_article_footer' )->once();

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

        $markup = licensio_kb_get_template( 'article-card', array( 'post' => 12 ), false );

        $this->assertStringNotContainsString( '<script>', $markup );
        $this->assertStringContainsString( '&lt;script&gt;', $markup );
    }

    /**
     * The article card swaps a theme's excerpt "more" link for an ellipsis.
     *
     * The excerpt is printed escaped, so a link a theme adds through
     * `excerpt_more` would show up on the card as raw HTML.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_article_card_replaces_the_theme_excerpt_more_link() {
        $this->stub_article( 12, 'Creating an account' );

        // Stands in for core: the theme's link, unless something outranks it.
        Functions\when( 'get_the_excerpt' )->alias(
            function () {
                return 'A short summary' . ( has_filter( 'excerpt_more' ) ? "\u{2026}" : '<a class="more-link" href="#">Continue reading</a>' );
            }
        );

        $markup = licensio_kb_get_template( 'article-card', array( 'post' => 12 ), false );

        $this->assertStringContainsString( "A short summary\u{2026}", $markup );
        $this->assertStringNotContainsString( 'more-link', $markup );
        $this->assertFalse( has_filter( 'excerpt_more' ), 'The override must not outlive the card.' );
    }

    /**
     * The archive heading reaches the page header plain and whole.
     *
     * The redesigned archive carries no gradient hero: the heading is the
     * setting's text, printed as one escaped run inside the page header, with
     * the hairline rule the header carries below it.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_archive_heading_reaches_the_page_header_whole() {
        $markup = $this->render_archive( 'Licensio Documentation' );

        $this->assertStringContainsString( '<header class="licensio-kb__pagehead">', $markup );
        $this->assertStringContainsString( '<h1 class="licensio-kb__pagehead-title">Licensio Documentation</h1>', $markup );
        $this->assertStringNotContainsString( 'licensio-kb__title-accent', $markup );
    }

    /**
     * The archive heading reaches the page escaped.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_archive_escapes_the_title() {
        $markup = $this->render_archive( '<script>alert(1)</script> <img onerror=x>' );

        /*
         * The value is sanitised on its way into the store and escaped on its
         * way out, so what reaches the page is neither markup nor an entity:
         * the tags are gone by the time the heading is printed.
         */
        $this->assertStringNotContainsString( '<script>', $markup );
        $this->assertStringNotContainsString( '<img', $markup );
        $this->assertStringContainsString( 'alert(1)', $markup );
    }

    /**
     * The archive grid follows the sidebar: two divided columns beside one.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_archive_grid_asks_for_two_divided_columns_beside_a_sidebar() {
        $markup = $this->render_archive();

        $this->assertStringContainsString(
            'licensio-kb__grid licensio-kb__grid--divided licensio-kb__grid--cols-2',
            $markup
        );
    }

    /**
     * Each archive card carries its articles, its count and a View all link.
     *
     * The redesigned card fronts every article with the document icon, keeps
     * the article count beside the title, and closes with the View all link.
     * The category icon is not shown on the archive grid.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_archive_cards_list_their_articles_and_show_their_icons() {
        $markup = $this->render_archive();

        $this->assertStringContainsString( 'licensio-kb-card__articles', $markup );
        $this->assertStringContainsString(
            '<a class="licensio-kb-card__article-link" href="https://example.test/docs/article-7/">',
            $markup
        );
        $this->assertStringContainsString( 'Quick start', $markup );
        $this->assertStringContainsString( 'licensio-kb-card__article-icon', $markup );
        $this->assertStringContainsString( 'licensio-kb-card__count', $markup );
        $this->assertStringContainsString( 'licensio-kb-card__view-all', $markup );
        $this->assertStringNotContainsString( 'dashicons dashicons-sos', $markup );
    }

    /**
     * The archive header takes the header image as its background.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_archive_header_shows_the_header_image() {
        $this->stub_header_image( 42, 'https://example.test/uploads/header.jpg' );

        $markup = $this->render_archive( 'Help Topics', array( 'header_image_id' => 42 ) );

        $this->assertStringContainsString( 'class="licensio-kb__pagehead licensio-kb__pagehead--image"', $markup );
        $this->assertStringContainsString( '--licensio-kb-pagehead-image:url(&quot;https://example.test/uploads/header.jpg&quot;)', $markup );
        $this->assertStringContainsString( '<h1 class="licensio-kb__pagehead-title">Help Topics</h1>', $markup );
    }

    /**
     * Without a header image the archive header stays plain.
     *
     * A solid or gradient style an older install saved is not read any more,
     * so it renders the same plain header as no style at all.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_archive_header_is_plain_without_an_image() {
        $this->stub_header_image( 42, 'https://example.test/uploads/header.jpg' );

        foreach ( array( array(), array( 'header_style' => 'solid' ), array( 'header_style' => 'gradient' ) ) as $stored ) {
            $markup = $this->render_archive( 'Help Topics', $stored );

            $this->assertStringContainsString( '<header class="licensio-kb__pagehead">', $markup );
            $this->assertStringNotContainsString( 'licensio-kb__pagehead--image', $markup );
            $this->assertStringNotContainsString( 'url(', $markup );
        }
    }

    /**
     * A legacy header style does not hide a header image that is set.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_legacy_header_style_keeps_the_header_image() {
        $this->stub_header_image( 42, 'https://example.test/uploads/header.jpg' );

        $markup = $this->render_archive(
            'Help Topics',
            array(
                'header_style'    => 'solid',
                'header_image_id' => 42,
            )
        );

        $this->assertStringContainsString( 'licensio-kb__pagehead--image', $markup );
    }

    /**
     * An attachment that has no image URL leaves the header plain.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_attachment_without_an_image_leaves_the_header_plain() {
        $this->stub_header_image( 42, false );

        $markup = $this->render_archive( 'Help Topics', array( 'header_image_id' => 42 ) );

        $this->assertStringContainsString( '<header class="licensio-kb__pagehead">', $markup );
        $this->assertStringNotContainsString( 'url(', $markup );
    }

    /**
     * Stub the attachment lookups behind the header image.
     *
     * @since 1.0.0
     *
     * @param int          $attachment_id The one ID that is an attachment.
     * @param string|false $url           What the image URL lookup answers for it.
     *
     * @return void
     */
    private function stub_header_image( $attachment_id, $url ) {
        Functions\when( 'get_post_type' )->alias(
            function ( $id ) use ( $attachment_id ) {
                return (int) $id === $attachment_id ? 'attachment' : false;
            }
        );
        Functions\when( 'wp_get_attachment_image_url' )->alias(
            function ( $id ) use ( $attachment_id, $url ) {
                return (int) $id === $attachment_id ? $url : false;
            }
        );
    }

    /**
     * Render templates/archive.php against one category holding one article.
     *
     * @since 1.0.0
     *
     * @param string               $title    The post type archive title.
     * @param array<string, mixed> $settings Optional. Other stored settings. Default empty.
     *
     * @return string The rendered markup.
     */
    private function render_archive( $title = 'Knowledge Base', array $settings = array() ) {
        $this->stub_conditionals( 'archive' );

        /*
         * The redesigned archive reads its heading from the
         * archive_header_text setting, so the title a test asks for is routed
         * through the stored option rather than through the stubbed
         * post_type_archive_title() the old hero used to read.
         */
        Functions\when( 'get_option' )->alias(
            function ( $name, $default_value = false ) use ( $title, $settings ) {
                if ( Licensio_KB_Settings::OPTION_NAME === $name ) {
                    return array_merge( array( 'archive_header_text' => $title ), $settings );
                }

                return $default_value;
            }
        );

        $term = new WP_Term(
            array(
                'term_id'     => 5,
                'name'        => 'Getting started',
                'slug'        => 'getting-started',
                'taxonomy'    => Licensio_KB_Post_Types::TAXONOMY_CATEGORY,
                'count'       => 1,
                'description' => 'From purchase to first licensed sale.',
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
        Functions\when( 'get_term_meta' )->alias(
            function ( $term_id, $key, $single = false ) {
                unset( $term_id, $single );

                return 'licensio_icon' === $key ? 'dashicons-sos' : '';
            }
        );

        /*
         * The archive makes two article queries: the cards ask for the articles
         * in one category, and the uncategorised list asks for the articles in
         * none. This site has none, so the second query answers empty and the
         * list renders nothing — which is the state these archive tests are
         * about.
         */
        Functions\when( 'get_posts' )->alias(
            function ( $args = array() ) use ( $article ) {
                $clause = isset( $args['tax_query'][0] ) ? (array) $args['tax_query'][0] : array();

                return isset( $clause['operator'] ) && 'NOT EXISTS' === $clause['operator']
                    ? array()
                    : array( $article );
            }
        );

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
        $this->assertSame( array( '', 'Documentation' ), Licensio_KB_Templates::split_title( 'Documentation' ) );
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
            array( 'The Licensio', 'Documentation' ),
            Licensio_KB_Templates::split_title( 'The Licensio Documentation' )
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
            array( 'Licensio', 'Docs' ),
            Licensio_KB_Templates::split_title( '  Licensio  Docs  ' )
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

        // The hero now carries the search field, which reads the current query.
        Functions\when( 'get_search_query' )->justReturn( '' );

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

        // The sidebar asks the taxonomies what to list; this file is about the hooks, so it lists nothing.
        Functions\when( 'get_terms' )->justReturn( array() );
        Functions\when( 'get_the_terms' )->justReturn( array() );
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
                    'taxonomy' => Licensio_KB_Post_Types::TAXONOMY_CATEGORY,
                )
            ),
            2 => new WP_Term(
                array(
                    'term_id'  => 2,
                    'name'     => 'Billing',
                    'slug'     => 'billing',
                    'parent'   => 1,
                    'taxonomy' => Licensio_KB_Post_Types::TAXONOMY_CATEGORY,
                )
            ),
            3 => new WP_Term(
                array(
                    'term_id'  => 3,
                    'name'     => 'Invoices',
                    'slug'     => 'invoices',
                    'parent'   => 2,
                    'taxonomy' => Licensio_KB_Post_Types::TAXONOMY_CATEGORY,
                )
            ),
            4 => new WP_Term(
                array(
                    'term_id'  => 4,
                    'name'     => 'Refunds',
                    'slug'     => 'refunds',
                    'parent'   => 3,
                    'taxonomy' => Licensio_KB_Post_Types::TAXONOMY_CATEGORY,
                )
            ),
        );

        $this->stub_conditionals( 'single' );
        $this->stub_article( 42, 'How refunds are issued' );
        $this->stub_terms( $terms, array( 4 => array( 3, 2, 1 ) ) );

        Functions\when( 'get_the_terms' )->justReturn( array( $terms[4] ) );

        $items = Licensio_KB_Templates::breadcrumb_items();

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
                'taxonomy' => Licensio_KB_Post_Types::TAXONOMY_CATEGORY,
            )
        );

        $child = new WP_Term(
            array(
                'term_id'  => 2,
                'name'     => 'Advanced guides',
                'slug'     => 'advanced-guides',
                'parent'   => 1,
                'taxonomy' => Licensio_KB_Post_Types::TAXONOMY_CATEGORY,
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

        $primary = Licensio_KB_Templates::primary_category( 42 );

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
                'taxonomy' => Licensio_KB_Post_Types::TAXONOMY_CATEGORY,
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

        $this->assertSame( 10, Licensio_KB_Templates::category_article_count( $parent ) );
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

        $listed = Licensio_KB_Templates::top_level_categories();

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
        $this->options[ Licensio_KB_Settings::OPTION_NAME ] = array( 'brand_color' => '#ff0000' );

        $css = $this->templates->inline_css();

        $this->assertStringContainsString( '--licensio-kb-brand:#ff0000', $css );
        $this->assertStringContainsString( '--licensio-kb-brand-rgb:255,0,0', $css );
        $this->assertStringStartsWith( '.licensio-kb{', $css );
        $this->assertStringContainsString( '.licensio-kb a,.licensio-kb a:hover,.licensio-kb a:focus{color:var(--licensio-kb-brand)}', $css );
    }

    /**
     * With no brand colour the theme's colours are left alone.
     *
     * The stylesheet's `var()` fallbacks only apply while the brand properties
     * are undeclared, and links keep the theme's colour only while no rule
     * paints them, so an empty brand colour must print neither.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_empty_brand_colour_declares_nothing_brand_related() {
        $this->options[ Licensio_KB_Settings::OPTION_NAME ] = array( 'brand_color' => '' );

        $properties = $this->templates->custom_properties();
        $css        = $this->templates->inline_css();

        $this->assertArrayNotHasKey( '--licensio-kb-brand', $properties );
        $this->assertArrayNotHasKey( '--licensio-kb-brand-rgb', $properties );
        $this->assertArrayNotHasKey( '--licensio-kb-brand-contrast', $properties );
        $this->assertStringNotContainsString( 'licensio-kb-brand', $css );
        $this->assertStringContainsString( '--licensio-kb-max-width:72rem', $css );
    }

    /**
     * A light brand colour gets dark text, a dark one gets light text.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_contrast_colour_follows_the_brand_colour() {
        $this->options[ Licensio_KB_Settings::OPTION_NAME ] = array( 'brand_color' => '#ffffff' );

        $this->assertStringContainsString( '--licensio-kb-brand-contrast:#000000', $this->templates->inline_css() );

        $this->options[ Licensio_KB_Settings::OPTION_NAME ] = array( 'brand_color' => '#111111' );
        $this->templates                                    = new Licensio_KB_Templates( new Licensio_KB_Settings() );

        $this->assertStringContainsString( '--licensio-kb-brand-contrast:#ffffff', $this->templates->inline_css() );
    }

    /**
     * The layout width and card style settings drive custom properties only.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_layout_and_card_settings_drive_custom_properties() {
        $this->options[ Licensio_KB_Settings::OPTION_NAME ] = array(
            'layout_width' => 'wide',
            'card_style'   => 'border',
        );

        $properties = $this->templates->custom_properties();

        $this->assertSame( '90rem', $properties['--licensio-kb-max-width'] );
        $this->assertSame( 'none', $properties['--licensio-kb-card-shadow'] );
        $this->assertSame( '1px solid var(--licensio-kb-line)', $properties['--licensio-kb-card-border'] );

        foreach ( array_keys( $properties ) as $name ) {
            $this->assertStringStartsWith( '--licensio-kb-', $name );
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

        $this->assertSame( array( Licensio_KB_Templates::STYLE_HANDLE ), $enqueued );
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
        Functions\when( 'add_shortcode' )->justReturn( null );

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

        Filters\expectApplied( 'licensio_template_name' )
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
        $templates = new Licensio_KB_Templates( new Licensio_KB_Hostile_Settings() );

        $properties = $templates->custom_properties();
        $css        = $templates->inline_css();

        $this->assertArrayNotHasKey( '--licensio-kb-brand', $properties );
        $this->assertSame( '72rem', $properties['--licensio-kb-max-width'] );
        $this->assertSame( Licensio_KB_Templates::CARD_SHADOW, $properties['--licensio-kb-card-shadow'] );

        $this->assertStringNotContainsString( 'evil.test', $css );
        $this->assertStringNotContainsString( '}body{', $css );
        $this->assertStringNotContainsString( '</style>', $css );
    }

    /**
     * A store with no registered defaults still invents no brand colour.
     *
     * Without a brand colour the knowledge base takes the theme's colours, so
     * the template layer must not supply one of its own when the store has
     * nothing to hand back.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_store_without_defaults_invents_no_brand_colour() {
        $templates = new Licensio_KB_Templates( new Licensio_KB_Defaultless_Settings() );

        $properties = $templates->custom_properties();

        $this->assertArrayNotHasKey( '--licensio-kb-brand', $properties );
        $this->assertArrayNotHasKey( '--licensio-kb-brand-rgb', $properties );
        $this->assertSame( '72rem', $properties['--licensio-kb-max-width'] );
    }

    /**
     * A colour that is not a colour still resolves to the Licensio green.
     *
     * `custom_properties()` validates before it splits, so this fallback is only
     * reached by a direct call — which is exactly why it is the sort of value
     * that goes stale unnoticed.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_unusable_hex_falls_back_to_the_licensio_indigo() {
        $this->assertSame( array( 60, 86, 159 ), Licensio_KB_Templates::hex_to_rgb( 'not-a-colour' ) );
        $this->assertSame( array( 60, 86, 159 ), Licensio_KB_Templates::hex_to_rgb( '' ) );
        $this->assertSame( array( 60, 86, 159 ), Licensio_KB_Templates::hex_to_rgb( '#2ba3' ) );
    }

    /**
     * The front-end stylesheets set no colour and no font of their own.
     *
     * The knowledge base inherits its text colour, link colour and typeface
     * from the active theme, and separates its panels with tints mixed from
     * `currentColor`. A fixed hex or rgb colour, or a font family, would make
     * it look pasted on to any theme that differs from the one it was written
     * against, and nothing on the page would fail loudly, so the stylesheets
     * are checked for them directly. The radius is pinned because the layout
     * is meant to stay the same.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_front_end_stylesheets_set_no_colour_or_font() {
        foreach ( array( 'licensio-kb.css', 'search.css', 'feedback.css', 'blocks.css' ) as $file ) {
            $css = (string) file_get_contents( LICENSIO_KB_ABSPATH . 'assets/css/' . $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
            $css = (string) preg_replace( '#/\*.*?\*/#s', '', $css );

            $this->assertDoesNotMatchRegularExpression( '/#[0-9a-f]{3,8}\b/i', $css, $file . ' sets a fixed hex colour.' );
            $this->assertDoesNotMatchRegularExpression( '/\b(rgb|rgba|hsl|hsla)\s*\(/i', $css, $file . ' sets a fixed colour function.' );
            $this->assertDoesNotMatchRegularExpression( '/font-family\s*:/i', $css, $file . ' sets a font family.' );
        }

        $css = (string) file_get_contents( LICENSIO_KB_ABSPATH . 'assets/css/licensio-kb.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

        $this->assertStringContainsString( '--licensio-kb-radius: 10px;', $css );
        $this->assertStringNotContainsString( '--licensio-kb-brand:', $css, 'The stylesheet must not declare a brand colour of its own.' );
    }
    /**
     * An article card exposes exactly one link.
     *
     * The whole card is clickable, but by stretching a pseudo-element from the
     * title's link - not by wrapping the card in an anchor, and not by adding a
     * second hidden one. Either of those would give a screen reader two links
     * to one destination, or one whose accessible name is the entire card.
     *
     * Asserted on the count because the visual result is identical either way:
     * a card that became clickable the wrong way would look correct and read
     * badly, which no screenshot would catch.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_article_card_exposes_exactly_one_link() {
        $this->stub_article( 77, 'Rotating a licence key' );

        ob_start();
        licensio_kb_get_template( 'article-card', array( 'post' => 77 ) );
        $markup = (string) ob_get_clean();

        $this->assertSame( 1, substr_count( $markup, '<a ' ), 'an article card must expose exactly one link' );
        $this->assertStringContainsString( 'licensio-kb-card__link', $markup );
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
class Licensio_KB_Defaultless_Settings extends Licensio_KB_Settings {

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
 * Bypasses Licensio_KB_Settings::all(), so the only thing standing between
 * these values and the style element is the template layer's own validation.
 *
 * @since 1.0.0
 */
class Licensio_KB_Hostile_Settings extends Licensio_KB_Settings {

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
