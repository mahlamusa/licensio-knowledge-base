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
 * @package Lisensio_KB
 * @since   1.0.0
 */

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;

/**
 * Tests templates/hero.php and the five views that render it.
 *
 * @since 1.0.0
 */
class Lisensio_KB_Hero_Test extends Lisensio_KB_Test_Case {

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
     * Stub the WordPress surface the templates touch.
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
        Functions\when( 'get_header' )->justReturn( null );
        Functions\when( 'get_footer' )->justReturn( null );
        Functions\when( 'have_posts' )->justReturn( false );
        Functions\when( 'get_search_query' )->justReturn( '' );

        $this->stub_conditionals();

        $this->theme_dir = sys_get_temp_dir() . '/lisensio-kb-hero-theme-' . uniqid();

        mkdir( $this->theme_dir . '/lisensio-kb', 0777, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
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
     * @param string $view One of `single`, `category`, `tag`, `archive`, `search` or `` for none.
     *
     * @return void
     */
    private function stub_conditionals( $view = '' ) {
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
        $args = array_merge( array( 'title' => 'Lisensio Documentation' ), $overrides );

        ob_start();

        include $this->plugin_template( 'hero' );

        return (string) ob_get_clean();
    }

    /**
     * Render templates/archive.php against one category holding one article.
     *
     * @since 1.0.0
     *
     * @return string The rendered markup.
     */
    private function render_archive() {
        $this->stub_conditionals( 'archive' );
        $this->stub_one_category();

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
                'post_type'  => Lisensio_KB_Post_Types::POST_TYPE,
            )
        );

        Functions\when( 'get_post' )->justReturn( $article );
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
                'taxonomy'    => Lisensio_KB_Post_Types::TAXONOMY_CATEGORY,
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

        ob_start();

        include $this->plugin_template( 'taxonomy-category' );

        return (string) ob_get_clean();
    }

    /**
     * Render templates/taxonomy-tag.php against one empty tag.
     *
     * @since 1.0.0
     *
     * @param string $description The term description.
     *
     * @return string The rendered markup.
     */
    private function render_tag( $description = 'Everything filed under content.' ) {
        $this->stub_conditionals( 'tag' );

        $term = new WP_Term(
            array(
                'term_id'     => 8,
                'name'        => 'Content',
                'slug'        => 'content',
                'taxonomy'    => Lisensio_KB_Post_Types::TAXONOMY_TAG,
                'count'       => 1,
                'description' => $description,
            )
        );

        Functions\when( 'get_queried_object' )->justReturn( $term );
        Functions\when( 'single_term_title' )->justReturn( 'Content' );

        ob_start();

        include $this->plugin_template( 'taxonomy-tag' );

        return (string) ob_get_clean();
    }

    /**
     * Render templates/search-results.php against an empty result set.
     *
     * @since 1.0.0
     *
     * @return string The rendered markup.
     */
    private function render_search() {
        $this->stub_conditionals( 'search' );

        Functions\when( 'get_search_query' )->justReturn( 'licence key' );

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
                'taxonomy'    => Lisensio_KB_Post_Types::TAXONOMY_CATEGORY,
                'count'       => 1,
                'description' => 'From purchase to first licensed sale.',
                'parent'      => 0,
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
        Functions\when( 'get_term_meta' )->justReturn( '' );
        Functions\when( 'get_posts' )->justReturn( array( $article ) );
        Functions\when( 'get_permalink' )->justReturn( 'https://example.test/docs/article-7/' );
        Functions\when( 'get_the_title' )->alias(
            function ( $post ) {
                return $post instanceof WP_Post ? $post->post_title : '';
            }
        );
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
     * The hero paints the last word of its heading, and only that word.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_hero_accents_the_last_word_of_its_title() {
        $markup = $this->render_hero();

        $this->assertStringContainsString( '<header class="lisensio-kb__hero">', $markup );
        $this->assertStringContainsString( 'Lisensio', $markup );
        $this->assertStringContainsString(
            '<span class="lisensio-kb__title-accent">Documentation</span>',
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
            '<span class="lisensio-kb__title-accent">Documentation</span>',
            $markup
        );
        $this->assertSame( 1, substr_count( $markup, '<h1 class="lisensio-kb__title">' ) );
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
            '<p class="lisensio-kb__description">Everything &lt;you&gt; need.</p>',
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

        $this->assertStringContainsString( '<h1 class="lisensio-kb__title">', $markup );
        $this->assertSame( 0, substr_count( $markup, 'lisensio-kb__description' ) );
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

        $this->assertStringContainsString( 'class="lisensio-kb__breadcrumbs"', $markup );
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

        $this->assertStringContainsString( '<h1 class="lisensio-kb__title">', $markup );
        $this->assertSame( 0, substr_count( $markup, 'lisensio-kb__breadcrumbs' ) );
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
        foreach ( $this->render_every_view() as $view => $markup ) {
            $this->assertSame(
                1,
                substr_count( $markup, '<header class="lisensio-kb__hero">' ),
                $view . ' does not render exactly one hero'
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
            'archive'  => 'Knowledge Base',
            'single'   => 'Resetting your password',
            'category' => 'Getting started',
            'tag'      => 'Content',
            'search'   => 'Search results for “licence key”',
        );

        foreach ( $this->render_every_view() as $view => $markup ) {
            $heading = '';

            if ( preg_match( '#<h1 class="lisensio-kb__title">(.*?)</h1>#s', $markup, $matches ) ) {
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
            '<p class="lisensio-kb__description">Everything you need to get going.</p>',
            $this->render_category()
        );

        $this->assertStringContainsString(
            '<p class="lisensio-kb__description">Everything filed under content.</p>',
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

        $this->assertStringContainsString( '<header class="lisensio-kb__hero">', $markup );
        $this->assertSame( 0, substr_count( $markup, 'lisensio-kb__description' ) );
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
            $this->theme_dir . '/lisensio-kb/hero.php',
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

        foreach ( $this->render_every_view() as $view => $markup ) {
            $this->assertStringContainsString(
                '<section class="theme-page-hero"><h1>THEME HERO</h1></section>',
                $markup,
                $view . ' did not take the theme override'
            );
            $this->assertSame(
                0,
                substr_count( $markup, 'lisensio-kb__hero' ),
                $view . ' rendered the plugin hero alongside the override'
            );
        }
    }

    /**
     * The archive cards carry no article count.
     *
     * The documentation design's cards are icon, title, description and article
     * list. `category-card.php` keeps counting by default because the grid
     * block and the category view both want it; the archive turns it off.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_archive_cards_do_not_show_an_article_count() {
        $markup = $this->render_archive();

        $this->assertStringContainsString( 'lisensio-kb-card--category', $markup );
        $this->assertStringContainsString( 'Getting started', $markup );
        $this->assertSame( 0, substr_count( $markup, 'lisensio-kb-card__meta' ) );
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
                'taxonomy'    => Lisensio_KB_Post_Types::TAXONOMY_CATEGORY,
                'count'       => 3,
                'description' => '',
            )
        );

        $this->stub_one_category();

        $markup = lisensio_kb_get_template(
            'category-card',
            array(
                'term'          => $term,
                'show_children' => false,
            ),
            false
        );

        $this->assertStringContainsString( 'lisensio-kb-card__meta', $markup );
        $this->assertStringContainsString( '3 articles', $markup );
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

        $this->assertStringContainsString( 'lisensio-kb__grid--cols-3', $markup );
        $this->assertSame( 0, substr_count( $markup, 'Latest articles' ) );
        $this->assertSame( 0, substr_count( $markup, 'lisensio-kb__section-title' ) );
        $this->assertSame( 0, substr_count( $markup, 'lisensio-kb-card--article' ) );
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
        Actions\expectDone( 'lisensio_kb_after_archive' )->once();

        $markup = $this->render_archive();

        $this->assertStringContainsString( 'lisensio-kb--archive', $markup );
    }

    /**
     * The search form survives the move out of the hero.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_archive_keeps_its_search_form_beside_the_hero() {
        $markup = $this->render_archive();

        $this->assertStringContainsString( '</header>', $markup );
        $this->assertStringContainsString( 'class="lisensio-kb__search"', $markup );
        $this->assertLessThan(
            strpos( $markup, 'class="lisensio-kb__search"' ),
            strpos( $markup, '</header>' ),
            'the search form must follow the hero, not sit inside it'
        );
    }
}
