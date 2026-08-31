<?php
/**
 * Unit tests for the sidebar and the table of contents.
 *
 * Three layers, and each of them can fail while the other two look fine.
 *
 * The content filter is the layer that rewrites an article, so most of the
 * assertions here are about what it must NOT do: touch a heading inside a
 * `<pre>` block, rewrite an article the reader is not looking at, run twice
 * and suffix every id again, or change a byte of an article that has no
 * headings at all.
 *
 * The template is the layer that renders the list, and its assertions are
 * about the sections it skips rather than the ones it prints.
 *
 * The third layer is the wiring, and it is the one that has bitten this
 * repository: a test that calls the filter directly still passes when the
 * `add_filter` is deleted, which leaves the feature entirely absent behind a
 * green suite. `test_the_content_filter_is_registered` is the assertion that
 * catches that, and the mutation that proves it is deleting the `add_filter`
 * line in Lisensio_KB_TOC::init_hooks().
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

/**
 * Tests Lisensio_KB_TOC, Lisensio_KB_Sidebar and templates/sidebar.php.
 *
 * @since 1.0.0
 */
class Lisensio_KB_Sidebar_Test extends Lisensio_KB_Test_Case {

    /**
     * The ID of the article the stubs describe.
     *
     * @since 1.0.0
     * @var int
     */
    const ARTICLE_ID = 42;

    /**
     * A throwaway theme root used by the override test.
     *
     * @since 1.0.0
     * @var string
     */
    private $theme_dir = '';

    /**
     * Stub the WordPress surface the templates and the filter touch.
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
        Functions\when( 'is_feed' )->justReturn( false );
        Functions\when( 'in_the_loop' )->justReturn( true );
        Functions\when( 'get_search_query' )->justReturn( '' );
        Functions\when( 'number_format_i18n' )->returnArg();
        Functions\when( 'wp_kses_post' )->returnArg();
        Functions\when( 'get_term_link' )->alias(
            function ( $term ) {
                return 'https://example.test/docs-category/' . ( $term instanceof WP_Term ? $term->slug : '' ) . '/';
            }
        );
        Functions\when( 'get_the_ID' )->justReturn( self::ARTICLE_ID );
        Functions\when( 'get_queried_object_id' )->justReturn( self::ARTICLE_ID );

        $this->stub_view( 'single' );

        Functions\when( 'get_post' )->justReturn( $this->article() );

        $this->theme_dir = sys_get_temp_dir() . '/lisensio-kb-sidebar-theme-' . uniqid();

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
     * The article the stubs describe.
     *
     * @since 1.0.0
     *
     * @param string $post_type The post type to claim. Defaults to the article type.
     *
     * @return WP_Post The article.
     */
    private function article( $post_type = Lisensio_KB_Post_Types::POST_TYPE ) {
        return new WP_Post(
            array(
                'ID'         => self::ARTICLE_ID,
                'post_title' => 'Where can I upload my avatar',
                'post_type'  => $post_type,
            )
        );
    }

    /**
     * Point the template conditionals at one view.
     *
     * @since 1.0.0
     *
     * @param string $view One of `single`, `category` or `` for none.
     *
     * @return void
     */
    private function stub_view( $view ) {
        Functions\when( 'is_singular' )->alias(
            function ( $type = '' ) use ( $view ) {
                return 'single' === $view && Lisensio_KB_Post_Types::POST_TYPE === $type;
            }
        );

        Functions\when( 'is_tax' )->alias(
            function ( $taxonomy = '' ) use ( $view ) {
                return 'category' === $view && Lisensio_KB_Post_Types::TAXONOMY_CATEGORY === $taxonomy;
            }
        );

        Functions\when( 'is_post_type_archive' )->justReturn( false );
        Functions\when( 'is_search' )->justReturn( false );
    }

    /**
     * Store settings for the request.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $values The settings to store.
     *
     * @return void
     */
    private function settings( array $values ) {
        $this->options[ Lisensio_KB_Settings::OPTION_NAME ] = $values;

        Lisensio_KB_Sidebar::reset();
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
     * Render templates/sidebar.php on its own.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $args Template arguments.
     *
     * @return string The rendered markup.
     */
    private function render_sidebar( array $args ) {
        ob_start();

        include $this->plugin_template( 'sidebar' );

        return (string) ob_get_clean();
    }

    /**
     * A term the sidebar can list.
     *
     * @since 1.0.0
     *
     * @param int    $id       The term ID.
     * @param string $name     The term name.
     * @param string $taxonomy The taxonomy.
     *
     * @return WP_Term The term.
     */
    private function term( $id, $name, $taxonomy = Lisensio_KB_Post_Types::TAXONOMY_CATEGORY ) {
        return new WP_Term(
            array(
                'term_id'     => $id,
                'name'        => $name,
                'slug'        => strtolower( str_replace( ' ', '-', $name ) ),
                'taxonomy'    => $taxonomy,
                'count'       => 3,
                'description' => '',
                'parent'      => 0,
            )
        );
    }

    /**
     * Stub the taxonomy functions a full view render reaches.
     *
     * @since 1.0.0
     *
     * @param WP_Term[] $categories The categories the taxonomy answers with.
     * @param WP_Term[] $tags       The tags the article carries.
     *
     * @return void
     */
    private function stub_taxonomies( array $categories, array $tags ) {
        Functions\when( 'get_terms' )->alias(
            function ( $args = array() ) use ( $categories, $tags ) {
                $taxonomy = isset( $args['taxonomy'] ) ? $args['taxonomy'] : '';

                if ( Lisensio_KB_Post_Types::TAXONOMY_TAG === $taxonomy ) {
                    return $tags;
                }

                // The recursive call that counts a category's children must not re-answer with the parents.
                return isset( $args['child_of'] ) ? array() : $categories;
            }
        );

        Functions\when( 'get_term' )->returnArg();
        Functions\when( 'get_the_terms' )->justReturn( $tags );
        Functions\when( 'get_term_link' )->alias(
            function ( $term ) {
                return 'https://example.test/docs-category/' . ( $term instanceof WP_Term ? $term->slug : '' ) . '/';
            }
        );
        Functions\when( 'get_ancestors' )->justReturn( array() );
        Functions\when( 'get_posts' )->justReturn( array() );
    }

    /**
     * Render templates/single.php against a one-post loop.
     *
     * `the_content` is stubbed to run the real content filter, so the ids the
     * filter writes into the body and the hrefs the sidebar writes into the
     * list are produced by the same code path that produces them on a page.
     *
     * @since 1.0.0
     *
     * @param string $content The raw article content.
     *
     * @return string The rendered markup.
     */
    private function render_single( $content ) {
        Functions\when( 'get_header' )->justReturn( null );
        Functions\when( 'get_footer' )->justReturn( null );
        Functions\when( 'get_the_title' )->justReturn( 'Where can I upload my avatar' );
        Functions\when( 'get_the_term_list' )->justReturn( '' );
        Functions\when( 'get_the_modified_date' )->justReturn( '1 January 2026' );
        Functions\when( 'the_post' )->justReturn( null );
        Functions\when( 'home_url' )->justReturn( 'https://example.test/' );
        Functions\when( 'get_post_type_archive_link' )->justReturn( 'https://example.test/docs/' );

        $toc = new Lisensio_KB_TOC();

        Functions\when( 'the_content' )->alias(
            function () use ( $toc, $content ) {
                echo $toc->filter_content( $content ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
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
     * @return string The rendered markup.
     */
    private function render_category() {
        $this->stub_view( 'category' );

        $term = $this->term( 5, 'Getting started' );

        Functions\when( 'get_header' )->justReturn( null );
        Functions\when( 'get_footer' )->justReturn( null );
        Functions\when( 'get_queried_object' )->justReturn( $term );
        Functions\when( 'single_term_title' )->justReturn( 'Getting started' );
        Functions\when( 'have_posts' )->justReturn( false );
        Functions\when( 'home_url' )->justReturn( 'https://example.test/' );
        Functions\when( 'get_post_type_archive_link' )->justReturn( 'https://example.test/docs/' );

        ob_start();

        include $this->plugin_template( 'taxonomy-category' );

        return (string) ob_get_clean();
    }

    /**
     * Every heading that lacked an id gets one made from its text.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_headings_get_an_id_made_from_their_text() {
        $result = Lisensio_KB_TOC::process( '<h2>Overview</h2><p>x</p><h3>The tabs</h3>' );

        $this->assertStringContainsString( '<h2 id="overview">Overview</h2>', $result['content'] );
        $this->assertStringContainsString( '<h3 id="the-tabs">The tabs</h3>', $result['content'] );

        $this->assertSame(
            array(
                array(
                    'id'    => 'overview',
                    'text'  => 'Overview',
                    'level' => 2,
                ),
                array(
                    'id'    => 'the-tabs',
                    'text'  => 'The tabs',
                    'level' => 3,
                ),
            ),
            $result['headings']
        );
    }

    /**
     * An id an author set by hand is left exactly as it was.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_author_set_id_is_never_overwritten() {
        $result = Lisensio_KB_TOC::process( '<h2 id="my-own-anchor">Overview</h2><h2>Second</h2>' );

        $this->assertStringContainsString( '<h2 id="my-own-anchor">Overview</h2>', $result['content'] );
        $this->assertStringNotContainsString( 'id="overview"', $result['content'] );
        $this->assertSame( 'my-own-anchor', $result['headings'][0]['id'] );
    }

    /**
     * A heading inside a code block is not a heading.
     *
     * This is the case a regular expression gets wrong, and getting it wrong
     * means editing the sample code an article is teaching from.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_headings_inside_pre_or_code_are_left_alone() {
        $result = Lisensio_KB_TOC::process( '<pre><h2>Sample</h2></pre><code><h3>Snippet</h3></code><h2>Real</h2><h2>Also real</h2>' );

        $this->assertStringContainsString( '<pre><h2>Sample</h2></pre>', $result['content'] );
        $this->assertStringContainsString( '<code><h3>Snippet</h3></code>', $result['content'] );
        $this->assertStringContainsString( '<h2 id="real">Real</h2>', $result['content'] );
        $this->assertCount( 2, $result['headings'] );
    }

    /**
     * A heading quoted inside a comment is not a heading either.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_heading_inside_a_comment_is_not_a_heading() {
        $result = Lisensio_KB_TOC::process( '<!-- <h2>Commented out</h2> --><h2>Real</h2><h2>Also real</h2>' );

        $this->assertStringContainsString( '<!-- <h2>Commented out</h2> -->', $result['content'] );
        $this->assertCount( 2, $result['headings'] );
    }

    /**
     * Two headings with the same text are told apart by a suffix.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_repeated_heading_text_is_told_apart_by_a_suffix() {
        $result = Lisensio_KB_TOC::process( '<h2>Settings</h2><h2>Settings</h2><h2>Settings</h2>' );

        $this->assertSame( 'settings', $result['headings'][0]['id'] );
        $this->assertSame( 'settings-2', $result['headings'][1]['id'] );
        $this->assertSame( 'settings-3', $result['headings'][2]['id'] );
    }

    /**
     * An id does not move when an editor adds a heading above it.
     *
     * The whole reason the slug comes from the text and not from the position
     * of the heading: a bookmark to `#overview` has to keep pointing at
     * Overview after the article grows an introduction above it.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_id_does_not_move_when_a_heading_is_added_above_it() {
        $before = Lisensio_KB_TOC::process( '<h2>Overview</h2><h2>Tabs</h2>' );
        $after  = Lisensio_KB_TOC::process( '<h2>Introduction</h2><h2>Overview</h2><h2>Tabs</h2>' );

        $this->assertSame( 'overview', $before['headings'][0]['id'] );
        $this->assertSame( 'overview', $after['headings'][1]['id'] );
        $this->assertStringContainsString( '<h2 id="overview">Overview</h2>', $after['content'] );
    }

    /**
     * Running the filter twice produces the same document and the same list.
     *
     * A second pass must not re-slug the ids the first pass wrote, which is
     * what would happen if an assigned id were treated as a collision.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_processing_twice_changes_nothing() {
        $once  = Lisensio_KB_TOC::process( '<h2>Overview</h2><h3>Tabs</h3><h2>Overview</h2>' );
        $twice = Lisensio_KB_TOC::process( $once['content'] );

        $this->assertSame( $once['content'], $twice['content'] );
        $this->assertSame( $once['headings'], $twice['headings'] );
        $this->assertStringNotContainsString( 'overview-2-2', $twice['content'] );
    }

    /**
     * An article with no headings is handed back as the identical string.
     *
     * No parse, therefore no round trip, therefore nothing to report: the
     * bytes that went in are the bytes that come out.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_content_without_headings_is_returned_byte_for_byte() {
        $html = "<p>Body &amp; more &nbsp; text</p>\n<h4>Detail</h4>\n<img src=\"a.png\" alt=\"x\" />";

        $result = Lisensio_KB_TOC::process( $html );

        $this->assertSame( $html, $result['content'] );
        $this->assertSame( array(), $result['headings'] );
    }

    /**
     * What the round trip through the parser does and does not preserve.
     *
     * Documented as an assertion rather than as a comment, because "the filter
     * changed the article" is the complaint this feature would generate, and
     * the exact set of changes has to be a thing that fails a test when it
     * grows.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_round_trip_preserves_markup_entities_and_block_delimiters() {
        $html = "<!-- wp:heading -->\n<h2>Caf\xc3\xa9 &amp; bar</h2>\n<!-- /wp:heading -->\n<p>&lt;tag&gt;</p><img src=\"a.png\" alt=\"x\"><br><h2>Second</h2>";

        $result = Lisensio_KB_TOC::process( $html );

        $this->assertStringContainsString( "<!-- wp:heading -->\n", $result['content'] );
        $this->assertStringContainsString( "<!-- /wp:heading -->\n", $result['content'] );
        $this->assertStringContainsString( 'Caf&#xE9;', str_replace( "Caf\xc3\xa9", 'Caf&#xE9;', $result['content'] ) );
        $this->assertStringContainsString( '&amp; bar', $result['content'] );
        $this->assertStringContainsString( '&lt;tag&gt;', $result['content'] );
        $this->assertStringContainsString( '<img src="a.png" alt="x">', $result['content'] );
        $this->assertStringContainsString( '<br>', $result['content'] );
    }

    /**
     * A character reference that names a printable character comes back decoded.
     *
     * The one documented difference the round trip makes. It is asserted so
     * that it is a known, bounded change rather than a surprise.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_round_trip_decodes_character_references() {
        $result = Lisensio_KB_TOC::process( '<h2>One</h2><p>a&nbsp;b &#8212; c</p><h2>Two</h2>' );

        $this->assertStringContainsString( "a\xc2\xa0b \xe2\x80\x94 c", $result['content'] );
    }

    /**
     * Headings below h3 are not listed and not given ids.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_headings_below_h3_are_not_listed() {
        $result = Lisensio_KB_TOC::process( '<h2>One</h2><h4>Detail</h4><h2>Two</h2>' );

        $this->assertStringContainsString( '<h4>Detail</h4>', $result['content'] );
        $this->assertCount( 2, $result['headings'] );
    }

    /**
     * A heading with no text is skipped rather than given a fallback anchor.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_empty_heading_is_skipped() {
        $result = Lisensio_KB_TOC::process( '<h2></h2><h2>One</h2><h2>Two</h2>' );

        $this->assertStringContainsString( '<h2></h2>', $result['content'] );
        $this->assertCount( 2, $result['headings'] );
    }

    /**
     * Content carrying a document type declaration is refused, not parsed.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_content_with_a_document_type_declaration_is_refused() {
        $html = '<!DOCTYPE html><h2>One</h2><h2>Two</h2>';

        $result = Lisensio_KB_TOC::process( $html );

        $this->assertSame( $html, $result['content'] );
        $this->assertSame( array(), $result['headings'] );
    }

    /**
     * An article longer than the cap is handed back untouched.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_oversized_article_is_not_parsed() {
        $html = '<h2>One</h2><h2>Two</h2>' . str_repeat( 'x', Lisensio_KB_TOC::MAX_LENGTH );

        $result = Lisensio_KB_TOC::process( $html );

        $this->assertSame( $html, $result['content'] );
        $this->assertSame( array(), $result['headings'] );
    }

    /**
     * Sub-sections nest under the section above them.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_sub_headings_nest_under_the_heading_above_them() {
        $tree = Lisensio_KB_TOC::tree(
            array(
                array(
                    'id'    => 'overview',
                    'text'  => 'Overview',
                    'level' => 2,
                ),
                array(
                    'id'    => 'tabs',
                    'text'  => 'Tabs',
                    'level' => 3,
                ),
                array(
                    'id'    => 'general',
                    'text'  => 'General',
                    'level' => 3,
                ),
                array(
                    'id'    => 'next',
                    'text'  => 'Next',
                    'level' => 2,
                ),
            )
        );

        $this->assertCount( 2, $tree );
        $this->assertCount( 2, $tree[0]['children'] );
        $this->assertSame( 'tabs', $tree[0]['children'][0]['id'] );
        $this->assertSame( 'general', $tree[0]['children'][1]['id'] );
        $this->assertSame( array(), $tree[1]['children'] );
    }

    /**
     * A sub-heading with no section above it stays at the top level.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_sub_heading_with_no_section_above_it_stays_top_level() {
        $tree = Lisensio_KB_TOC::tree(
            array(
                array(
                    'id'    => 'first',
                    'text'  => 'First',
                    'level' => 3,
                ),
                array(
                    'id'    => 'second',
                    'text'  => 'Second',
                    'level' => 3,
                ),
            )
        );

        $this->assertCount( 2, $tree );
    }

    /**
     * The content filter is actually registered.
     *
     * Everything else in this file calls the filter directly, and every one of
     * those assertions still passes with the `add_filter` deleted — leaving an
     * article with no heading ids, a sidebar with no table of contents, and a
     * green suite. This is the only test that fails for that.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_content_filter_is_registered() {
        Filters\expectAdded( 'the_content' )->once()->with( Mockery::type( 'array' ), Lisensio_KB_TOC::PRIORITY );

        $toc = new Lisensio_KB_TOC();
        $toc->init_hooks();
    }

    /**
     * Another post type's content is not rewritten.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_filter_leaves_another_post_type_alone() {
        $this->settings( array() );

        Functions\when( 'get_post' )->justReturn( $this->article( 'page' ) );

        $toc  = new Lisensio_KB_TOC();
        $html = '<h2>One</h2><h2>Two</h2>';

        $this->assertSame( $html, $toc->filter_content( $html ) );
        $this->assertSame( array(), Lisensio_KB_TOC::headings( self::ARTICLE_ID ) );
    }

    /**
     * An article rendered beside the one being read is not rewritten.
     *
     * A related-articles list, a card grid, a widget: all of them run
     * `the_content` on a post that is not the queried one, and all of them
     * would have their ids stolen by the article the reader is looking at.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_filter_leaves_a_secondary_loop_alone() {
        $this->settings( array() );

        Functions\when( 'get_queried_object_id' )->justReturn( 999 );

        $toc  = new Lisensio_KB_TOC();
        $html = '<h2>One</h2><h2>Two</h2>';

        $this->assertSame( $html, $toc->filter_content( $html ) );
    }

    /**
     * Content filtered outside the loop is not rewritten.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_filter_does_nothing_outside_the_loop() {
        $this->settings( array() );

        Functions\when( 'in_the_loop' )->justReturn( false );

        $toc  = new Lisensio_KB_TOC();
        $html = '<h2>One</h2><h2>Two</h2>';

        $this->assertSame( $html, $toc->filter_content( $html ) );
    }

    /**
     * A REST response is never rewritten.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_filter_leaves_a_rest_response_alone() {
        $this->settings( array() );

        Functions\when( 'is_singular' )->justReturn( false );

        $toc  = new Lisensio_KB_TOC();
        $html = '<h2>One</h2><h2>Two</h2>';

        $this->assertSame( $html, $toc->filter_content( $html ) );
    }

    /**
     * Turning the setting off leaves the article exactly as it was.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_filter_does_nothing_when_the_setting_is_off() {
        $this->settings( array( 'show_table_of_contents' => false ) );

        $toc  = new Lisensio_KB_TOC();
        $html = '<h2>One</h2><h2>Two</h2>';

        $this->assertSame( $html, $toc->filter_content( $html ) );
    }

    /**
     * An article with no sidebar keeps its content untouched.
     *
     * `article_sidebar_position = none` has to return the article exactly as
     * it renders today, and "exactly" includes not gaining heading ids for a
     * list nothing is going to render.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_filter_does_nothing_when_the_article_has_no_sidebar() {
        $this->settings( array( 'article_sidebar_position' => 'none' ) );

        $toc  = new Lisensio_KB_TOC();
        $html = '<h2>One</h2><h2>Two</h2>';

        $this->assertSame( $html, $toc->filter_content( $html ) );
    }

    /**
     * The filter remembers the headings it assigned, keyed by article.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_filter_collects_the_headings_it_assigned() {
        $this->settings( array() );

        $toc = new Lisensio_KB_TOC();
        $toc->filter_content( '<h2>Overview</h2><h3>Tabs</h3>' );

        $headings = Lisensio_KB_TOC::headings( self::ARTICLE_ID );

        $this->assertCount( 2, $headings );
        $this->assertSame( 'overview', $headings[0]['id'] );
        $this->assertSame( array(), Lisensio_KB_TOC::headings( 999 ) );
    }

    /**
     * Each view reads its own position setting, not a shared one.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_each_view_reads_its_own_position_setting() {
        $this->settings(
            array(
                'article_sidebar_position'  => 'left',
                'category_sidebar_position' => 'none',
                'archive_sidebar_position'  => 'right',
            )
        );

        $this->assertSame( 'left', Lisensio_KB_Sidebar::position( Lisensio_KB_Sidebar::CONTEXT_ARTICLE ) );
        $this->assertSame( 'none', Lisensio_KB_Sidebar::position( Lisensio_KB_Sidebar::CONTEXT_CATEGORY ) );
        $this->assertSame( 'right', Lisensio_KB_Sidebar::position( Lisensio_KB_Sidebar::CONTEXT_ARCHIVE ) );
    }

    /**
     * A context nothing registered a setting for has no sidebar.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_unknown_context_has_no_sidebar() {
        $this->settings( array() );

        $this->assertSame( 'none', Lisensio_KB_Sidebar::position( 'nonsense' ) );
    }

    /**
     * The position is carried by a modifier class, as in the reference.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_position_becomes_a_modifier_class() {
        $this->assertSame( 'lisensio-kb--sidebarright', Lisensio_KB_Sidebar::modifier_class( 'right' ) );
        $this->assertSame( 'lisensio-kb--sidebarleft', Lisensio_KB_Sidebar::modifier_class( 'left' ) );
        $this->assertSame( '', Lisensio_KB_Sidebar::modifier_class( 'none' ) );
        $this->assertSame( '', Lisensio_KB_Sidebar::modifier_class( 'sideways' ) );
    }

    /**
     * The three sections render in the order the reference uses.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_sidebar_renders_the_three_sections_in_order() {
        $markup = $this->render_sidebar(
            array(
                'toc'        => array(
                    array(
                        'id'    => 'overview',
                        'text'  => 'Overview',
                        'level' => 2,
                    ),
                    array(
                        'id'    => 'tabs',
                        'text'  => 'Tabs',
                        'level' => 3,
                    ),
                ),
                'categories' => array( $this->term( 5, 'Getting started' ) ),
                'tags'       => array( $this->term( 9, 'Avatar', Lisensio_KB_Post_Types::TAXONOMY_TAG ) ),
            )
        );

        $toc        = strpos( $markup, 'lisensio-kb-widget--toc' );
        $categories = strpos( $markup, 'lisensio-kb-widget--categories' );
        $tags       = strpos( $markup, 'lisensio-kb-widget--tags' );

        $this->assertNotFalse( $toc );
        $this->assertNotFalse( $categories );
        $this->assertNotFalse( $tags );
        $this->assertLessThan( $categories, $toc );
        $this->assertLessThan( $tags, $categories );

        $this->assertStringContainsString( 'On this page', $markup );
        $this->assertStringContainsString( 'Getting started', $markup );
        $this->assertStringContainsString( 'Avatar', $markup );
    }

    /**
     * The table of contents is a nested ordered list of anchor links.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_table_of_contents_nests_its_sub_headings() {
        $markup = $this->render_sidebar(
            array(
                'toc' => array(
                    array(
                        'id'    => 'overview',
                        'text'  => 'Overview',
                        'level' => 2,
                    ),
                    array(
                        'id'    => 'tabs',
                        'text'  => 'Tabs',
                        'level' => 3,
                    ),
                ),
            )
        );

        $this->assertStringContainsString( 'href="#overview"', $markup );
        $this->assertStringContainsString( 'href="#tabs"', $markup );
        $this->assertStringContainsString( 'lisensio-kb-toc--nested', $markup );
        $this->assertSame( 2, substr_count( $markup, '<ol ' ) + substr_count( $markup, '<ol>' ) );
    }

    /**
     * An article with one heading gets no table of contents.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_single_heading_gets_no_table_of_contents() {
        $markup = $this->render_sidebar(
            array(
                'toc'        => array(
                    array(
                        'id'    => 'overview',
                        'text'  => 'Overview',
                        'level' => 2,
                    ),
                ),
                'categories' => array( $this->term( 5, 'Getting started' ) ),
            )
        );

        $this->assertStringNotContainsString( 'lisensio-kb-widget--toc', $markup );
        $this->assertStringContainsString( 'lisensio-kb-widget--categories', $markup );
    }

    /**
     * A section with nothing to show is not printed.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_section_with_nothing_to_show_is_skipped() {
        $markup = $this->render_sidebar( array( 'categories' => array( $this->term( 5, 'Getting started' ) ) ) );

        $this->assertStringContainsString( 'lisensio-kb-widget--categories', $markup );
        $this->assertStringNotContainsString( 'lisensio-kb-widget--toc', $markup );
        $this->assertStringNotContainsString( 'lisensio-kb-widget--tags', $markup );
    }

    /**
     * A sidebar with nothing in any section prints nothing at all.
     *
     * Not even the wrapper: the calling template decides whether to open a
     * two-column layout by looking at whether this file produced anything.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_empty_sidebar_prints_nothing() {
        $this->assertSame( '', trim( $this->render_sidebar( array() ) ) );
    }

    /**
     * The sticky setting reaches the markup.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_sticky_setting_reaches_the_markup() {
        $args = array( 'categories' => array( $this->term( 5, 'Getting started' ) ) );

        $this->assertStringContainsString(
            'lisensio-kb__sidebar--sticky',
            $this->render_sidebar( array_merge( $args, array( 'sticky' => true ) ) )
        );

        $this->assertStringNotContainsString(
            'lisensio-kb__sidebar--sticky',
            $this->render_sidebar( array_merge( $args, array( 'sticky' => false ) ) )
        );
    }

    /**
     * The single view puts the article and the sidebar in a two-column layout.
     *
     * The links are checked against the ids in the body rather than against a
     * literal, because a table of contents whose anchors do not resolve is the
     * one failure that looks completely correct in a screenshot.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_single_view_renders_a_sidebar_whose_links_resolve() {
        $this->settings( array( 'article_sidebar_position' => 'right' ) );
        $this->stub_taxonomies(
            array( $this->term( 5, 'Getting started' ) ),
            array( $this->term( 9, 'Avatar', Lisensio_KB_Post_Types::TAXONOMY_TAG ) )
        );

        $markup = $this->render_single( '<h2>Overview</h2><p>x</p><h3>The tabs</h3>' );

        $this->assertStringContainsString( 'lisensio-kb--sidebarright', $markup );
        $this->assertStringContainsString( 'lisensio-kb__layout', $markup );
        $this->assertStringContainsString( 'lisensio-kb__main', $markup );
        $this->assertStringContainsString( '<aside class="lisensio-kb__sidebar', $markup );

        $this->assertStringContainsString( '<h2 id="overview">Overview</h2>', $markup );
        $this->assertStringContainsString( '<h3 id="the-tabs">The tabs</h3>', $markup );
        $this->assertStringContainsString( 'href="#overview"', $markup );
        $this->assertStringContainsString( 'href="#the-tabs"', $markup );

        // The article comes before the sidebar in the document; the side is a class, not an order.
        $this->assertLessThan( strpos( $markup, '<aside' ), strpos( $markup, 'lisensio-kb-article' ) );
    }

    /**
     * The left position is the same markup with a different modifier.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_left_position_only_changes_the_modifier_class() {
        $this->settings( array( 'article_sidebar_position' => 'left' ) );
        $this->stub_taxonomies( array( $this->term( 5, 'Getting started' ) ), array() );

        $markup = $this->render_single( '<h2>Overview</h2><h2>Tabs</h2>' );

        $this->assertStringContainsString( 'lisensio-kb--sidebarleft', $markup );
        $this->assertStringNotContainsString( 'lisensio-kb--sidebarright', $markup );
        $this->assertLessThan( strpos( $markup, '<aside' ), strpos( $markup, 'lisensio-kb-article' ) );
    }

    /**
     * No sidebar returns the full width article, unchanged.
     *
     * No layout wrapper, no modifier class, no aside — and no heading ids
     * either, because nothing asked for them.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_no_sidebar_returns_the_full_width_article() {
        $this->settings( array( 'article_sidebar_position' => 'none' ) );
        $this->stub_taxonomies( array( $this->term( 5, 'Getting started' ) ), array() );

        $markup = $this->render_single( '<h2>Overview</h2><h2>Tabs</h2>' );

        $this->assertStringContainsString( '<div class="lisensio-kb lisensio-kb--single">', $markup );
        $this->assertStringNotContainsString( 'lisensio-kb__layout', $markup );
        $this->assertStringNotContainsString( 'lisensio-kb__sidebar', $markup );
        $this->assertStringNotContainsString( '<aside', $markup );
        $this->assertStringContainsString( '<h2>Overview</h2><h2>Tabs</h2>', $markup );
    }

    /**
     * A sidebar with nothing to put in it leaves the article full width.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_empty_sidebar_leaves_the_article_full_width() {
        $this->settings( array( 'article_sidebar_position' => 'right' ) );
        $this->stub_taxonomies( array(), array() );

        $markup = $this->render_single( '<p>No headings here.</p>' );

        $this->assertStringContainsString( '<div class="lisensio-kb lisensio-kb--single">', $markup );
        $this->assertStringNotContainsString( 'lisensio-kb__layout', $markup );
    }

    /**
     * The category view gets a sidebar, with no table of contents in it.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_category_view_renders_a_sidebar_without_a_table_of_contents() {
        $this->settings( array( 'category_sidebar_position' => 'right' ) );
        $this->stub_taxonomies(
            array( $this->term( 5, 'Getting started' ) ),
            array( $this->term( 9, 'Avatar', Lisensio_KB_Post_Types::TAXONOMY_TAG ) )
        );

        $markup = $this->render_category();

        $this->assertStringContainsString( 'lisensio-kb--sidebarright', $markup );
        $this->assertStringContainsString( '<aside class="lisensio-kb__sidebar', $markup );
        $this->assertStringContainsString( 'lisensio-kb-widget--categories', $markup );
        $this->assertStringContainsString( 'lisensio-kb-widget--tags', $markup );
        $this->assertStringNotContainsString( 'lisensio-kb-widget--toc', $markup );
    }

    /**
     * The category view honours its own setting, not the article's.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_category_view_reads_the_category_setting() {
        $this->settings(
            array(
                'category_sidebar_position' => 'none',
                'article_sidebar_position'  => 'right',
            )
        );
        $this->stub_taxonomies( array( $this->term( 5, 'Getting started' ) ), array() );

        $markup = $this->render_category();

        $this->assertStringNotContainsString( '<aside', $markup );
        $this->assertStringNotContainsString( 'lisensio-kb__layout', $markup );
    }

    /**
     * The category the reader is in is marked, and only that one.
     *
     * Marked twice over: a class for the eye and `aria-current` for everything
     * that cannot see it. A highlight that is only a colour is not a highlight
     * for a screen reader.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_current_category_is_marked_in_the_list() {
        $markup = $this->render_sidebar(
            array(
                'categories' => array( $this->term( 5, 'Getting started' ), $this->term( 6, 'Billing' ) ),
                'current'    => 6,
            )
        );

        $this->assertSame( 1, substr_count( $markup, 'is-current' ) );
        $this->assertSame( 1, substr_count( $markup, 'aria-current="page"' ) );
        $this->assertLessThan( strpos( $markup, 'Billing' ), strpos( $markup, 'is-current' ) );
        $this->assertGreaterThan( strpos( $markup, 'Getting started' ), strpos( $markup, 'is-current' ) );
    }

    /**
     * Nothing is marked when no category is the current one.
     *
     * Zero is the "no category" answer, and it must not match the term whose
     * id happens to cast to it.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_no_category_is_marked_when_there_is_no_current_one() {
        $markup = $this->render_sidebar(
            array(
                'categories' => array( $this->term( 5, 'Getting started' ), $this->term( 0, 'Orphan' ) ),
            )
        );

        $this->assertStringNotContainsString( 'is-current', $markup );
        $this->assertStringNotContainsString( 'aria-current', $markup );
    }

    /**
     * The current category of an article is chosen deterministically.
     *
     * An article in three categories must highlight the same one on every
     * request; whichever order the database returns them in is not an answer.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_current_category_of_an_article_is_stable() {
        $terms = array( $this->term( 12, 'Billing' ), $this->term( 4, 'Getting started' ), $this->term( 9, 'Account' ) );

        Functions\when( 'get_the_terms' )->justReturn( $terms );

        $this->assertSame( 4, Lisensio_KB_Sidebar::current_category( $this->article() ) );

        Functions\when( 'get_the_terms' )->justReturn( array_reverse( $terms ) );

        $this->assertSame( 4, Lisensio_KB_Sidebar::current_category( $this->article() ) );
    }

    /**
     * An uncategorised article marks nothing.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_uncategorised_article_has_no_current_category() {
        Functions\when( 'get_the_terms' )->justReturn( array() );

        $this->assertSame( 0, Lisensio_KB_Sidebar::current_category( $this->article() ) );
        $this->assertSame( 0, Lisensio_KB_Sidebar::current_category( null ) );
    }

    /**
     * The category view marks the category it is showing.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_category_view_marks_the_category_it_is_showing() {
        $this->settings( array( 'category_sidebar_position' => 'right' ) );
        $this->stub_taxonomies( array( $this->term( 5, 'Getting started' ), $this->term( 6, 'Billing' ) ), array() );

        $markup = $this->render_category();

        // Scoped to the aside: the breadcrumb trail sets `aria-current` on its own last item.
        $aside = substr( $markup, (int) strpos( $markup, '<aside' ) );

        $this->assertSame( 1, substr_count( $aside, 'aria-current="page"' ) );
        $this->assertLessThan( strpos( $aside, 'Billing' ), strpos( $aside, 'is-current' ) );
    }

    /**
     * A theme overrides the sidebar the way it overrides every other template.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_theme_can_override_the_sidebar_template() {
        $override = $this->theme_dir . '/lisensio-kb/sidebar.php';

        file_put_contents( $override, '<?php echo "THEME SIDEBAR";' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents

        Functions\when( 'locate_template' )->alias(
            function ( $names ) use ( $override ) {
                return in_array( 'lisensio-kb/sidebar.php', (array) $names, true ) ? $override : '';
            }
        );

        $this->assertSame( 'THEME SIDEBAR', lisensio_kb_get_template( 'sidebar', array(), false ) );
    }
}
