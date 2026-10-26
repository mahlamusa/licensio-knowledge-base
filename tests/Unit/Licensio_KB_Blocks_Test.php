<?php
/**
 * Unit tests for the knowledge base blocks and their shortcodes.
 *
 * The suite exercises the two entry points the way WordPress does: the block
 * callback is handed typed, camelCase attributes and the shortcode callback is
 * handed the lower-cased strings `shortcode_parse_atts()` produces, and the two
 * results are compared. Everything else — theme overrides, escaping, ordering
 * and the empty knowledge base — is driven through the real templates on disk.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

/**
 * Tests the four knowledge base blocks and the shortcodes that share their
 * render callbacks.
 *
 * @since 1.0.0
 */
class Licensio_KB_Blocks_Test extends Licensio_KB_Test_Case {

    /**
     * The registrar under test.
     *
     * @since 1.0.0
     * @var Licensio_KB_Blocks
     */
    private $blocks;

    /**
     * Shortcode tag to the callback it was registered with.
     *
     * @since 1.0.0
     * @var array<string, callable>
     */
    private $shortcodes = array();

    /**
     * Block metadata path to the arguments it was registered with.
     *
     * @since 1.0.0
     * @var array<string, array<string, mixed>>
     */
    private $registered = array();

    /**
     * The arguments of the last get_posts() call.
     *
     * @since 1.0.0
     * @var array<string, mixed>
     */
    private $query_args = array();

    /**
     * The articles get_posts() answers with.
     *
     * @since 1.0.0
     * @var WP_Post[]
     */
    private $articles = array();

    /**
     * The categories a `parent` term query answers with, keyed by parent ID.
     *
     * @since 1.0.0
     * @var array<int, WP_Term[]>
     */
    private $categories = array();

    /**
     * A throwaway theme root used by the override test.
     *
     * @since 1.0.0
     * @var string
     */
    private $theme_dir = '';

    /**
     * Stub the WordPress surface the blocks and their templates touch.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        $this->shortcodes = array();
        $this->registered = array();
        $this->query_args = array();
        $this->articles   = array();
        $this->categories = array();

        Functions\when( 'untrailingslashit' )->alias(
            function ( $value ) {
                return rtrim( (string) $value, '/\\' );
            }
        );

        Functions\when( 'absint' )->alias(
            function ( $value ) {
                return abs( (int) $value );
            }
        );

        Functions\when( 'wp_is_block_theme' )->justReturn( false );
        Functions\when( 'locate_template' )->justReturn( '' );
        Functions\when( 'do_blocks' )->returnArg();
        Functions\when( 'get_stylesheet_directory' )->justReturn( LICENSIO_KB_ABSPATH . 'tests/no-such-theme' );
        Functions\when( 'get_template_directory' )->justReturn( LICENSIO_KB_ABSPATH . 'tests/no-such-theme' );
        Functions\when( 'number_format_i18n' )->returnArg();
        Functions\when( 'get_term_meta' )->justReturn( '' );
        Functions\when( 'wp_get_attachment_image' )->justReturn( '' );
        Functions\when( 'wp_kses_post' )->returnArg();
        Functions\when( 'wp_register_style' )->justReturn( true );

        Functions\when( 'home_url' )->alias(
            function ( $path = '' ) {
                return 'https://example.test' . ( '' === $path ? '' : $path );
            }
        );

        Functions\when( 'get_post_type_archive_link' )->justReturn( 'https://example.test/docs/' );

        Functions\when( 'add_shortcode' )->alias(
            function ( $tag, $callback ) {
                $this->shortcodes[ $tag ] = $callback;
            }
        );

        Functions\when( 'register_block_type' )->alias(
            function ( $metadata, $args = array() ) {
                $this->registered[ (string) $metadata ] = (array) $args;

                return null;
            }
        );

        Functions\when( 'get_posts' )->alias(
            function ( $args = array() ) {
                $this->query_args = (array) $args;

                return $this->articles;
            }
        );

        Functions\when( 'get_terms' )->alias(
            function ( $args = array() ) {
                if ( isset( $args['child_of'] ) ) {
                    return array();
                }

                $parent = isset( $args['parent'] ) ? (int) $args['parent'] : 0;

                return isset( $this->categories[ $parent ] ) ? $this->categories[ $parent ] : array();
            }
        );

        Functions\when( 'get_term' )->alias(
            function ( $term ) {
                return $term instanceof WP_Term ? $term : null;
            }
        );

        Functions\when( 'get_term_link' )->alias(
            function ( $term ) {
                return $term instanceof WP_Term
                    ? 'https://example.test/docs-category/' . $term->slug . '/'
                    : '';
            }
        );

        Functions\when( 'get_term_by' )->justReturn( false );
        Functions\when( 'get_ancestors' )->justReturn( array() );
        Functions\when( 'get_the_terms' )->justReturn( array() );

        $this->stub_view( '' );

        $this->theme_dir = sys_get_temp_dir() . '/licensio-kb-blocks-theme-' . uniqid();

        mkdir( $this->theme_dir . '/licensio-kb', 0777, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir

        $this->blocks = new Licensio_KB_Blocks();
        $this->blocks->register();
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
     * The four blocks, their shortcodes and a matching pair of attribute sets.
     *
     * The first set is what the block editor sends: camelCase names carrying
     * real integers and booleans. The second is what WordPress hands a shortcode
     * callback: every name lower-cased and every value a string.
     *
     * @since 1.0.0
     *
     * @return array<string, array<string, mixed>> Block name to its fixture.
     */
    private function pairs() {
        return array(
            'licensio-kb/article-list'  => array(
                'shortcode'  => 'licensio_kb_articles',
                'attributes' => array(
                    'category'    => 'billing',
                    'orderBy'     => 'title',
                    'order'       => 'asc',
                    'count'       => 3,
                    'columns'     => 2,
                    'showExcerpt' => false,
                ),
                'atts'       => array(
                    'category'    => 'billing',
                    'orderby'     => 'title',
                    'order'       => 'asc',
                    'count'       => '3',
                    'columns'     => '2',
                    'showexcerpt' => 'false',
                ),
            ),
            'licensio-kb/category-grid' => array(
                'shortcode'  => 'licensio_kb_categories',
                'attributes' => array(
                    'parent'       => '',
                    'orderBy'      => 'count',
                    'order'        => 'desc',
                    'count'        => 2,
                    'columns'      => 4,
                    'showCount'    => false,
                    'showIcon'     => false,
                    'showChildren' => false,
                ),
                'atts'       => array(
                    'parent'       => '',
                    'orderby'      => 'count',
                    'order'        => 'desc',
                    'count'        => '2',
                    'columns'      => '4',
                    'showcount'    => 'no',
                    'showicon'     => '0',
                    'showchildren' => 'false',
                ),
            ),
            'licensio-kb/breadcrumbs'   => array(
                'shortcode'  => 'licensio_kb_breadcrumbs',
                'attributes' => array(
                    'showHome' => false,
                ),
                'atts'       => array(
                    'showhome' => 'false',
                ),
            ),
            'licensio-kb/faq'           => array(
                'shortcode'  => 'licensio_kb_faq',
                'attributes' => array(
                    'category'    => 'billing',
                    'orderBy'     => 'title',
                    'order'       => 'desc',
                    'count'       => 4,
                    'showExcerpt' => true,
                    'openFirst'   => true,
                ),
                'atts'       => array(
                    'category'    => 'billing',
                    'orderby'     => 'title',
                    'order'       => 'desc',
                    'count'       => '4',
                    'showexcerpt' => 'yes',
                    'openfirst'   => 'true',
                ),
            ),
        );
    }

    /**
     * Point the template conditionals at one view.
     *
     * @since 1.0.0
     *
     * @param string $view `single` or an empty string for none.
     *
     * @return void
     */
    private function stub_view( $view ) {
        Functions\when( 'is_singular' )->alias(
            function ( $type = '' ) use ( $view ) {
                return 'single' === $view && Licensio_KB_Post_Types::POST_TYPE === $type;
            }
        );

        Functions\when( 'is_tax' )->justReturn( false );
        Functions\when( 'is_search' )->justReturn( false );
        Functions\when( 'is_post_type_archive' )->justReturn( false );
    }

    /**
     * Stub the post functions around a set of articles.
     *
     * @since 1.0.0
     *
     * @param array<int, array{id: int, title: string, excerpt?: string}> $articles The articles.
     *
     * @return void
     */
    private function stub_articles( array $articles ) {
        $posts    = array();
        $titles   = array();
        $excerpts = array();

        foreach ( $articles as $article ) {
            $id = (int) $article['id'];

            $posts[]         = new WP_Post(
                array(
                    'ID'         => $id,
                    'post_title' => (string) $article['title'],
                    'post_type'  => Licensio_KB_Post_Types::POST_TYPE,
                )
            );
            $titles[ $id ]   = (string) $article['title'];
            $excerpts[ $id ] = isset( $article['excerpt'] ) ? (string) $article['excerpt'] : 'A short summary.';
        }

        $this->articles = $posts;

        Functions\when( 'get_post' )->alias(
            function ( $post = null ) {
                return $post instanceof WP_Post ? $post : null;
            }
        );

        Functions\when( 'get_the_title' )->alias(
            function ( $post = null ) use ( $titles ) {
                return $post instanceof WP_Post && isset( $titles[ $post->ID ] ) ? $titles[ $post->ID ] : '';
            }
        );

        Functions\when( 'get_the_excerpt' )->alias(
            function ( $post = null ) use ( $excerpts ) {
                return $post instanceof WP_Post && isset( $excerpts[ $post->ID ] ) ? $excerpts[ $post->ID ] : '';
            }
        );

        Functions\when( 'get_permalink' )->alias(
            function ( $post = null ) {
                return $post instanceof WP_Post ? 'https://example.test/docs/' . $post->ID . '/' : '';
            }
        );
    }

    /**
     * Stub a set of top-level categories.
     *
     * @since 1.0.0
     *
     * @param array<int, array{id: int, name: string, count: int}> $categories The categories, in query order.
     *
     * @return void
     */
    private function stub_categories( array $categories ) {
        $terms = array();

        foreach ( $categories as $category ) {
            $terms[] = new WP_Term(
                array(
                    'term_id'  => (int) $category['id'],
                    'name'     => (string) $category['name'],
                    'slug'     => 'term-' . (int) $category['id'],
                    'count'    => (int) $category['count'],
                    'taxonomy' => Licensio_KB_Post_Types::TAXONOMY_CATEGORY,
                )
            );
        }

        $this->categories = array( 0 => $terms );
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
     * The render callback `register_block_type()` was given for a block.
     *
     * @since 1.0.0
     *
     * @param string $block_name The block name.
     *
     * @return callable The render callback.
     */
    private function render_callback( $block_name ) {
        foreach ( $this->registered as $path => $args ) {
            $metadata = json_decode( (string) file_get_contents( $path . '/block.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

            if ( is_array( $metadata ) && isset( $metadata['name'] ) && $block_name === $metadata['name'] ) {
                return $args['render_callback'];
            }
        }

        $this->fail( $block_name . ' was never registered.' );
    }

    /**
     * Every block is registered, and its shortcode shares its render callback.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_every_block_registers_its_block_and_its_shortcode() {
        $this->assertCount( 5, $this->registered, 'Five blocks are expected.' );

        foreach ( $this->pairs() as $block_name => $pair ) {
            $this->assertArrayHasKey(
                $pair['shortcode'],
                $this->shortcodes,
                $pair['shortcode'] . ' was never registered as a shortcode.'
            );

            $this->assertSame(
                $this->shortcodes[ $pair['shortcode'] ],
                $this->render_callback( $block_name ),
                $block_name . ' and its shortcode must share one callable.'
            );
        }
    }

    /**
     * The view block is registered without a shortcode of its own.
     *
     * The `[licensio_kb_view]` shortcode belongs to the template layer and
     * renders from template level only; the block renders inside core's loop.
     * They must not share a callback.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_view_block_registers_no_shortcode() {
        $callback = $this->render_callback( 'licensio-kb/view' );

        $this->assertIsCallable( $callback );
        $this->assertArrayNotHasKey( Licensio_KB_Templates::VIEW_SHORTCODE_TAG, $this->shortcodes );
    }

    /**
     * The editor preview points each view at real, public content.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_view_block_previews_each_view_against_published_content() {
        $block = new Licensio_KB_View_Block();
        $term  = new WP_Term(
            array(
                'term_id' => 3,
                'slug'    => 'billing',
                'name'    => 'Billing',
            )
        );

        $this->articles = array(
            new WP_Post(
                array(
                    'ID'         => 42,
                    'post_title' => 'Resetting your password',
                    'post_type'  => Licensio_KB_Post_Types::POST_TYPE,
                )
            ),
        );

        $term_args = array();

        Functions\when( 'get_terms' )->alias(
            function ( $args ) use ( $term, &$term_args ) {
                $term_args = $args;

                return array( $term );
            }
        );

        $this->assertSame( array( 'post_type' => Licensio_KB_Post_Types::POST_TYPE ), $block->preview_query_args( 'archive' ) );

        $this->assertSame(
            array(
                'p'         => 42,
                'post_type' => Licensio_KB_Post_Types::POST_TYPE,
            ),
            $block->preview_query_args( 'single' )
        );
        $this->assertSame( 'publish', $this->query_args['post_status'] );
        $this->assertFalse( $this->query_args['has_password'], 'A protected article is never previewed.' );

        $this->assertSame(
            array(
                's'         => 'Resetting your password',
                'post_type' => Licensio_KB_Post_Types::POST_TYPE,
            ),
            $block->preview_query_args( 'search' )
        );

        $this->assertSame(
            array(
                'taxonomy' => Licensio_KB_Post_Types::TAXONOMY_TAG,
                'term'     => 'billing',
            ),
            $block->preview_query_args( 'tag' )
        );
        $this->assertTrue( $term_args['hide_empty'] );

        $this->assertSame(
            array(
                'taxonomy' => Licensio_KB_Post_Types::TAXONOMY_CATEGORY,
                'term'     => 'billing',
            ),
            $block->preview_query_args( 'category' )
        );
    }

    /**
     * With nothing published there is nothing to preview.
     *
     * The editor then shows its labelled placeholder.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_view_block_has_nothing_to_preview_on_an_empty_site() {
        $block = new Licensio_KB_View_Block();

        $this->articles = array();

        Functions\when( 'get_terms' )->justReturn( array() );

        foreach ( array( 'single', 'search', 'tag', 'category' ) as $view ) {
            $this->assertSame( array(), $block->preview_query_args( $view ), $view );
            $this->assertSame( '', $block->preview( $view ), $view );
        }
    }

    /**
     * Stand a view body in for the preview, through the request conditional it asks first.
     *
     * The archive body is the one view whose preview needs no stored content,
     * so `is_post_type_archive()` is where the preview's render first reaches
     * the template layer. The stand-in moves the globals a view body's loop
     * moves, then either says the request is not an archive or throws.
     *
     * @since 1.0.0
     *
     * @param bool $fails Whether the stand-in body throws.
     *
     * @return void
     */
    private function stub_preview_body( $fails ) {
        Functions\when( 'wp_is_block_theme' )->justReturn( true );
        Functions\when( 'is_post_type_archive' )->alias(
            function () use ( $fails ) {
                // phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- The stand-in moves the globals a real view body moves.
                $GLOBALS['post']       = new WP_Post( array( 'ID' => 8 ) );
                $GLOBALS['id']         = 8;
                $GLOBALS['authordata'] = 'sample author';
                $GLOBALS['pages']      = array( 'sample page' );
                $GLOBALS['numpages']   = 1;
                // phpcs:enable WordPress.WP.GlobalVariablesOverride.Prohibited

                if ( $fails ) {
                    throw new RuntimeException( 'The view body failed.' );
                }

                return false;
            }
        );
    }

    /**
     * Data for the preview restore tests: a body that returns and one that throws.
     *
     * @since 1.0.0
     *
     * @return array<string, array{0: bool}> Whether the body throws.
     */
    public function preview_outcomes() {
        return array(
            'returns' => array( false ),
            'throws'  => array( true ),
        );
    }

    /**
     * A preview puts back every global it moved.
     *
     * The edited post's query, global post and postdata are what the rest of
     * the request renders with, so they come back exactly as they were.
     *
     * @since 1.0.0
     *
     * @dataProvider preview_outcomes
     *
     * @param bool $fails Whether the view body throws.
     *
     * @return void
     */
    public function test_the_view_block_preview_restores_the_globals_it_moved( $fails ) {
        $query  = new WP_Query();
        $edited = new WP_Post( array( 'ID' => 5 ) );

        // phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- The test stands up the request the preview runs in.
        $GLOBALS['wp_query']     = $query;
        $GLOBALS['wp_the_query'] = $query;
        $GLOBALS['post']         = $edited;
        $GLOBALS['id']           = 5;
        $GLOBALS['authordata']   = 'edited author';
        $GLOBALS['pages']        = array( 'edited page' );
        // phpcs:enable WordPress.WP.GlobalVariablesOverride.Prohibited
        unset( $GLOBALS['numpages'] );

        $this->stub_preview_body( $fails );

        $thrown = false;

        try {
            $this->assertSame( '', ( new Licensio_KB_View_Block() )->preview( 'archive' ) );
        } catch ( RuntimeException $exception ) {
            $thrown = true;
        }

        $this->assertSame( $fails, $thrown, 'The stand-in body ran.' );

        $this->assertSame( $query, $GLOBALS['wp_query'] );
        $this->assertSame( $query, $GLOBALS['wp_the_query'] );
        $this->assertSame( $edited, $GLOBALS['post'] );
        $this->assertSame( 5, $GLOBALS['id'] );
        $this->assertSame( 'edited author', $GLOBALS['authordata'] );
        $this->assertSame( array( 'edited page' ), $GLOBALS['pages'] );
        $this->assertArrayNotHasKey( 'numpages', $GLOBALS, 'A global that was not set is unset again.' );

        unset( $GLOBALS['wp_query'], $GLOBALS['wp_the_query'], $GLOBALS['post'], $GLOBALS['id'], $GLOBALS['authordata'], $GLOBALS['pages'] );
    }

    /**
     * A preview with no post of its own leaves no sample post behind.
     *
     * The Site Editor previews a template with no global post and no
     * postdata, so every global the sample article's loop set is unset again.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_view_block_preview_leaves_no_sample_postdata_without_a_post() {
        foreach ( Licensio_KB_View_Block::PREVIEW_GLOBALS as $name ) {
            unset( $GLOBALS[ $name ] );
        }

        $this->stub_preview_body( false );

        $this->assertSame( '', ( new Licensio_KB_View_Block() )->preview( 'archive' ) );

        foreach ( Licensio_KB_View_Block::PREVIEW_GLOBALS as $name ) {
            $this->assertArrayNotHasKey( $name, $GLOBALS, $name );
        }
    }

    /**
     * A request that is not a REST request is never an editor preview.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_request_outside_rest_is_not_a_preview() {
        Functions\when( 'current_user_can' )->justReturn( true );

        $this->assertFalse( Licensio_KB_View_Block::is_preview_request() );
    }

    /**
     * A REST request from a user who can edit posts is an editor preview.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_rest_request_from_an_editor_is_a_preview() {
        define( 'REST_REQUEST', true );

        Functions\expect( 'current_user_can' )->once()->with( 'edit_posts' )->andReturn( true );

        $this->assertTrue( Licensio_KB_View_Block::is_preview_request() );
    }

    /**
     * A REST request from a user who cannot edit posts is not a preview.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_rest_request_from_a_reader_is_not_a_preview() {
        define( 'REST_REQUEST', true );

        Functions\when( 'current_user_can' )->justReturn( false );

        $this->assertFalse( Licensio_KB_View_Block::is_preview_request() );
    }

    /**
     * Post content rendered for a REST response is not a preview.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_post_content_in_a_rest_response_is_not_a_preview() {
        define( 'REST_REQUEST', true );

        Functions\when( 'current_user_can' )->justReturn( true );

        Filters\expectApplied( 'the_content' )->once()->andReturnUsing(
            function () {
                return Licensio_KB_View_Block::is_preview_request() ? 'PREVIEW' : 'NOT A PREVIEW';
            }
        );

        $this->assertSame( 'NOT A PREVIEW', apply_filters( 'the_content', '' ) );
    }

    /**
     * The view block is kept out of the inserter outside the Site Editor.
     *
     * A view placed in a post or page renders nothing on the front end, so
     * only the Site Editor, where templates are built, offers it.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_view_block_is_offered_in_the_site_editor_only() {
        $block    = new Licensio_KB_View_Block();
        $path     = LICENSIO_KB_ABSPATH . 'blocks/view/block.json';
        $metadata = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

        $this->assertFalse( $metadata['supports']['inserter'], 'The block is out of the inserter by default.' );

        Functions\when( 'is_admin' )->justReturn( true );

        foreach ( array( 'post.php', 'post-new.php', 'widgets.php', 'customize.php' ) as $screen ) {
            $GLOBALS['pagenow'] = $screen; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The test picks the admin screen.

            $this->assertFalse( $block->offer_in_site_editor( $metadata )['supports']['inserter'], $screen );
        }

        $GLOBALS['pagenow'] = 'site-editor.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The test picks the admin screen.

        $this->assertTrue( $block->offer_in_site_editor( $metadata )['supports']['inserter'] );

        $other = array(
            'name'     => 'licensio-kb/faq',
            'supports' => array(),
        );

        $this->assertSame( $other, $block->offer_in_site_editor( $other ), 'Other blocks are left alone.' );

        unset( $GLOBALS['pagenow'] );
    }

    /**
     * The view block's editor preview can load the feedback form styles.
     *
     * The Single Article preview renders the feedback form, and the widget
     * registers its stylesheet only on the front end, so the block registrar
     * registers the same handle, source and version on `init`.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_feedback_stylesheet_is_registered_for_the_editor() {
        $styles = array();

        Functions\when( 'wp_register_style' )->alias(
            function ( $handle, $src ) use ( &$styles ) {
                $styles[ $handle ] = $src;

                return true;
            }
        );

        $this->blocks->register_assets();

        $this->assertSame( LICENSIO_KB_ASSETS_URL . 'css/feedback.css', $styles[ Licensio_KB_Feedback_Widget::STYLE_HANDLE ] ?? null );
    }

    /**
     * Every block's metadata agrees with the PHP that renders it.
     *
     * A block.json attribute the render callback does not know about is silently
     * dropped by `normalize()`, and an attribute the callback expects but the
     * metadata omits never reaches the editor, so the two lists are asserted
     * against each other rather than eyeballed.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_block_metadata_matches_the_render_callback() {
        $expected = array(
            'article-list'  => 'Licensio_KB_Article_List_Block',
            'category-grid' => 'Licensio_KB_Category_Grid_Block',
            'breadcrumbs'   => 'Licensio_KB_Breadcrumbs_Block',
            'faq'           => 'Licensio_KB_FAQ_Block',
            'view'          => 'Licensio_KB_View_Block',
        );

        foreach ( $expected as $directory => $class_name ) {
            $path = LICENSIO_KB_ABSPATH . 'blocks/' . $directory . '/block.json';

            $this->assertFileExists( $path );

            $metadata = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

            $this->assertIsArray( $metadata, $directory . '/block.json is not valid JSON.' );
            $this->assertSame( $class_name::block_name(), $metadata['name'] );
            $this->assertSame( Licensio_KB_Blocks::CATEGORY, $metadata['category'] );
            $this->assertSame( 'file:./index.js', $metadata['editorScript'] );
            $this->assertSame( Licensio_KB_Blocks::STYLE_HANDLE, $metadata['style'] );
            $this->assertSame(
                'view' === $directory ? array( Licensio_KB_Blocks::EDITOR_STYLE_HANDLE, Licensio_KB_Feedback_Widget::STYLE_HANDLE ) : Licensio_KB_Blocks::EDITOR_STYLE_HANDLE,
                $metadata['editorStyle'],
                $directory . ' declares a different editor style.'
            );

            $this->assertSame(
                array_keys( $class_name::defaults() ),
                array_keys( $metadata['attributes'] ),
                $directory . ' declares different attributes to the ones it renders.'
            );

            foreach ( $class_name::defaults() as $name => $default ) {
                $this->assertSame(
                    $default,
                    $metadata['attributes'][ $name ]['default'],
                    $directory . '.' . $name . ' defaults differently in block.json.'
                );
            }
        }
    }

    /**
     * For every block, the block and its shortcode emit identical markup.
     *
     * The attribute sets are deliberately not the same shape: the block gets
     * camelCase names with real integers and booleans, and the shortcode gets
     * exactly what `shortcode_parse_atts()` produces — lower-cased names and
     * string values. Identical output is therefore a statement about the
     * normalisation, not a tautology about one callable being called twice.
     *
     * Agreement alone is not enough, though: a normalisation that dropped every
     * attribute would leave both paths rendering the defaults and agreeing
     * perfectly. Each pair is therefore also asserted to differ from the same
     * block rendered with no attributes at all, so the two paths have to agree
     * on something the attributes actually changed.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_block_and_the_shortcode_emit_identical_markup() {
        $this->stub_articles(
            array(
                array(
                    'id'    => 12,
                    'title' => 'Resetting your password',
                ),
                array(
                    'id'    => 4,
                    'title' => 'Updating your billing details',
                ),
            )
        );

        $this->stub_categories(
            array(
                array(
                    'id'    => 11,
                    'name'  => 'Charlie',
                    'count' => 5,
                ),
                array(
                    'id'    => 7,
                    'name'  => 'Alpha',
                    'count' => 2,
                ),
            )
        );

        foreach ( $this->pairs() as $block_name => $pair ) {
            $block     = $this->render_callback( $block_name );
            $shortcode = $this->shortcodes[ $pair['shortcode'] ];

            $from_block     = $block( $pair['attributes'], '', null );
            $from_shortcode = $shortcode( $pair['atts'], '', $pair['shortcode'] );
            $from_defaults  = $block( array(), '', null );

            $this->assertNotSame( '', $from_block, $block_name . ' rendered nothing.' );
            $this->assertSame(
                $from_block,
                $from_shortcode,
                $block_name . ' and ' . $pair['shortcode'] . ' disagree on the markup.'
            );
            $this->assertNotSame(
                $from_defaults,
                $from_block,
                $block_name . ' ignored its attributes: both paths only agree on the defaults.'
            );
        }
    }

    /**
     * A lower-cased shortcode attribute reaches the markup.
     *
     * The direct counterpart to the comparison above: WordPress lower-cases
     * every shortcode attribute name, so a normalisation that matched names
     * case-sensitively would silently ignore everything a page builder set.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_lower_cased_shortcode_attribute_reaches_the_markup() {
        $this->stub_articles(
            array(
                array(
                    'id'      => 12,
                    'title'   => 'Resetting your password',
                    'excerpt' => 'A distinctive summary.',
                ),
            )
        );

        $shortcode = $this->shortcodes['licensio_kb_articles'];

        $markup = $shortcode(
            array(
                'columns'     => '3',
                'showexcerpt' => 'false',
            ),
            '',
            'licensio_kb_articles'
        );

        $this->assertStringContainsString( 'licensio-kb__grid--cols-3', $markup );
        $this->assertStringNotContainsString( 'A distinctive summary.', $markup );
    }

    /**
     * A shortcode with no attributes at all matches the block with none.
     *
     * WordPress hands a shortcode callback an empty string, not an empty array,
     * when the tag carries no attributes.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_bare_shortcode_matches_a_bare_block() {
        $this->stub_articles(
            array(
                array(
                    'id'    => 12,
                    'title' => 'Resetting your password',
                ),
            )
        );

        foreach ( $this->pairs() as $block_name => $pair ) {
            $block     = $this->render_callback( $block_name );
            $shortcode = $this->shortcodes[ $pair['shortcode'] ];

            $this->assertSame(
                $block( array(), '', null ),
                $shortcode( '', '', $pair['shortcode'] ),
                $pair['shortcode'] . ' with no attributes must match the block with none.'
            );
        }
    }

    /**
     * A theme override of the article card changes the block's output too.
     *
     * Exercised through a real file on disk: the block renders its list, the
     * list renders each card through `licensio_kb_get_template()`, and the
     * theme's copy of the card is what comes back.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_theme_override_of_the_article_card_changes_the_block_output() {
        $override = $this->theme_dir . '/licensio-kb/article-card.php';

        file_put_contents( $override, '<?php echo "THEME ARTICLE CARD"; ' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

        $this->stub_theme_lookup();
        $this->stub_articles(
            array(
                array(
                    'id'    => 12,
                    'title' => 'Resetting your password',
                ),
            )
        );

        $block = $this->render_callback( 'licensio-kb/article-list' );

        $overridden = $block( array(), '', null );

        $this->assertStringContainsString( 'THEME ARTICLE CARD', $overridden );
        $this->assertStringNotContainsString( 'licensio-kb-card--article', $overridden );

        unlink( $override ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.WP.AlternativeFunctions.file_system_operations_unlink

        $plugin_markup = $block( array(), '', null );

        $this->assertStringContainsString( 'licensio-kb-card--article', $plugin_markup );
        $this->assertStringNotContainsString( 'THEME ARTICLE CARD', $plugin_markup );
    }

    /**
     * A hostile article title is escaped by the article list.
     *
     * The double hands the title back unchanged, so the template's escaping is
     * the only thing standing between the title and the page.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_article_list_escapes_a_hostile_article_title() {
        $this->stub_articles(
            array(
                array(
                    'id'    => 12,
                    'title' => '<script>alert(1)</script>',
                ),
            )
        );

        $markup = $this->render_callback( 'licensio-kb/article-list' )( array(), '', null );

        $this->assertStringNotContainsString( '<script>', $markup );
        $this->assertStringContainsString( '&lt;script&gt;', $markup );
    }

    /**
     * A hostile article title and excerpt are escaped by the FAQ.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_faq_escapes_a_hostile_question_and_answer() {
        $this->stub_articles(
            array(
                array(
                    'id'      => 12,
                    'title'   => '<script>alert("q")</script>',
                    'excerpt' => '<img src=x onerror=alert("a")>',
                ),
            )
        );

        $markup = $this->render_callback( 'licensio-kb/faq' )( array(), '', null );

        $this->assertStringNotContainsString( '<script>', $markup );
        $this->assertStringNotContainsString( '<img', $markup );
        $this->assertStringContainsString( '&lt;script&gt;', $markup );
        $this->assertStringContainsString( '&lt;img', $markup );
    }

    /**
     * A hostile category name is escaped by the category grid.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_category_grid_escapes_a_hostile_category_name() {
        $this->stub_categories(
            array(
                array(
                    'id'    => 3,
                    'name'  => '<script>alert(1)</script>',
                    'count' => 2,
                ),
            )
        );

        $markup = $this->render_callback( 'licensio-kb/category-grid' )( array(), '', null );

        $this->assertStringNotContainsString( '<script>', $markup );
        $this->assertStringContainsString( '&lt;script&gt;', $markup );
    }

    /**
     * A hostile article title is escaped by the breadcrumb trail.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_breadcrumbs_escape_a_hostile_article_title() {
        $this->stub_view( 'single' );
        $this->stub_articles(
            array(
                array(
                    'id'    => 12,
                    'title' => '<script>alert(1)</script>',
                ),
            )
        );

        Functions\when( 'get_the_title' )->justReturn( '<script>alert(1)</script>' );

        $markup = $this->render_callback( 'licensio-kb/breadcrumbs' )( array(), '', null );

        $this->assertStringNotContainsString( '<script>', $markup );
        $this->assertStringContainsString( '&lt;script&gt;', $markup );
    }

    /**
     * Every block renders an empty knowledge base without notices.
     *
     * PHPUnit is configured to turn a notice into a failure, so reaching the
     * assertions at all is half the test; the other half is that the listing
     * blocks say so rather than emitting an empty grid.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_every_block_renders_an_empty_knowledge_base() {
        $this->stub_articles( array() );

        foreach ( $this->pairs() as $block_name => $pair ) {
            $markup = $this->render_callback( $block_name )( array(), '', null );

            $this->assertNotSame( '', $markup, $block_name . ' rendered nothing at all.' );

            if ( 'licensio-kb/breadcrumbs' !== $block_name ) {
                $this->assertStringContainsString(
                    'licensio-kb__empty',
                    $markup,
                    $block_name . ' must show an empty state.'
                );
            }
        }
    }

    /**
     * The category grid sorts by article count, not by whatever order the term
     * query happened to return.
     *
     * The fixture is chosen so that no expected order matches the term IDs
     * ascending, the term IDs descending, or the order the terms were supplied
     * in: an implementation that simply passed the query result through, or
     * sorted by ID, would fail every one of the three assertions.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_category_grid_orders_by_name_and_by_article_count() {
        $this->stub_categories(
            array(
                array(
                    'id'    => 11,
                    'name'  => 'Charlie',
                    'count' => 5,
                ),
                array(
                    'id'    => 7,
                    'name'  => 'Alpha',
                    'count' => 2,
                ),
                array(
                    'id'    => 3,
                    'name'  => 'Bravo',
                    'count' => 9,
                ),
            )
        );

        $block = new Licensio_KB_Category_Grid_Block();

        $this->assertSame(
            array( 7, 3, 11 ),
            $this->term_ids( $block->categories( Licensio_KB_Category_Grid_Block::normalize( array( 'orderBy' => 'name' ) ) ) ),
            'By name, ascending.'
        );

        $this->assertSame(
            array( 11, 3, 7 ),
            $this->term_ids(
                $block->categories(
                    Licensio_KB_Category_Grid_Block::normalize(
                        array(
                            'orderBy' => 'name',
                            'order'   => 'desc',
                        )
                    )
                )
            ),
            'By name, descending.'
        );

        $this->assertSame(
            array( 3, 11, 7 ),
            $this->term_ids(
                $block->categories(
                    Licensio_KB_Category_Grid_Block::normalize(
                        array(
                            'orderBy' => 'count',
                            'order'   => 'desc',
                        )
                    )
                )
            ),
            'By article count, most first.'
        );
    }

    /**
     * The category grid trims the list to the requested count after ordering.
     *
     * Trimming before ordering would answer with the two categories the term
     * query happened to return first, which is a different pair.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_category_grid_trims_after_ordering() {
        $this->stub_categories(
            array(
                array(
                    'id'    => 11,
                    'name'  => 'Charlie',
                    'count' => 5,
                ),
                array(
                    'id'    => 7,
                    'name'  => 'Alpha',
                    'count' => 2,
                ),
                array(
                    'id'    => 3,
                    'name'  => 'Bravo',
                    'count' => 9,
                ),
            )
        );

        $block = new Licensio_KB_Category_Grid_Block();

        $this->assertSame(
            array( 3, 11 ),
            $this->term_ids(
                $block->categories(
                    Licensio_KB_Category_Grid_Block::normalize(
                        array(
                            'orderBy' => 'count',
                            'order'   => 'desc',
                            'count'   => 2,
                        )
                    )
                )
            )
        );
    }

    /**
     * The term IDs of a list of categories.
     *
     * @since 1.0.0
     *
     * @param array<int, mixed> $terms The categories.
     *
     * @return int[] The term IDs, in order.
     */
    private function term_ids( array $terms ) {
        $ids = array();

        foreach ( $terms as $term ) {
            $ids[] = (int) $term->term_id;
        }

        return $ids;
    }

    /**
     * A category grid pointed at a category nobody has created lists nothing.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_category_grid_pointed_at_a_missing_category_lists_nothing() {
        $this->stub_categories(
            array(
                array(
                    'id'    => 3,
                    'name'  => 'Bravo',
                    'count' => 9,
                ),
            )
        );

        $block = new Licensio_KB_Category_Grid_Block();

        $this->assertSame(
            array(),
            $block->categories( Licensio_KB_Category_Grid_Block::normalize( array( 'parent' => 'no-such-category' ) ) )
        );
    }

    /**
     * The article query carries the requested category, ordering and count.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_article_query_carries_the_requested_attributes() {
        $this->stub_articles( array() );

        $this->render_callback( 'licensio-kb/article-list' )(
            array(
                'category' => 'billing',
                'orderBy'  => 'title',
                'order'    => 'asc',
                'count'    => 7,
            ),
            '',
            null
        );

        $this->assertSame( Licensio_KB_Post_Types::POST_TYPE, $this->query_args['post_type'] );
        $this->assertSame( 'publish', $this->query_args['post_status'] );
        $this->assertSame( 'title', $this->query_args['orderby'] );
        $this->assertSame( 'ASC', $this->query_args['order'] );
        $this->assertSame( 7, $this->query_args['posts_per_page'] );
        $this->assertSame(
            array(
                array(
                    'taxonomy' => Licensio_KB_Post_Types::TAXONOMY_CATEGORY,
                    'field'    => 'slug',
                    'terms'    => 'billing',
                ),
            ),
            $this->query_args['tax_query']
        );
    }

    /**
     * Menu order within one category becomes that category's article order.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_menu_order_in_one_category_uses_the_per_category_order() {
        $this->stub_articles( array() );

        $this->render_callback( 'licensio-kb/article-list' )(
            array(
                'category' => 'billing',
                'orderBy'  => 'menu_order',
            ),
            '',
            null
        );

        $this->assertSame( Licensio_KB_Article_Order::ORDERBY, $this->query_args['orderby'] );
    }

    /**
     * The block's order setting is passed on, so descending reverses the category order.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_order_setting_applies_to_the_per_category_order() {
        foreach ( array(
            'desc' => 'DESC',
            'asc'  => 'ASC',
        ) as $order => $expected ) {
            $this->stub_articles( array() );

            $this->render_callback( 'licensio-kb/article-list' )(
                array(
                    'category' => 'billing',
                    'orderBy'  => 'menu_order',
                    'order'    => $order,
                ),
                '',
                null
            );

            $this->assertSame( Licensio_KB_Article_Order::ORDERBY, $this->query_args['orderby'] );
            $this->assertSame( $expected, $this->query_args['order'] );
        }
    }

    /**
     * Menu order across every category stays menu order.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_menu_order_without_a_category_stays_menu_order() {
        $this->stub_articles( array() );

        $this->render_callback( 'licensio-kb/article-list' )(
            array( 'orderBy' => 'menu_order' ),
            '',
            null
        );

        $this->assertSame( 'menu_order', $this->query_args['orderby'] );
    }

    /**
     * An article list with no category filter does not constrain the taxonomy.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_unfiltered_article_list_has_no_taxonomy_clause() {
        $this->stub_articles( array() );

        $this->render_callback( 'licensio-kb/article-list' )( array(), '', null );

        $this->assertArrayNotHasKey( 'tax_query', $this->query_args );
    }

    /**
     * The article query is filterable.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_article_query_is_filterable() {
        $this->stub_articles( array() );

        Filters\expectApplied( 'licensio_block_article_query_args' )
            ->once()
            ->with( \Mockery::type( 'array' ), 'licensio-kb/article-list' );

        $this->render_callback( 'licensio-kb/article-list' )( array(), '', null );

        $this->assertNotSame( array(), $this->query_args, 'The filtered arguments must reach the query.' );
    }

    /**
     * The category listing is filterable.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_category_listing_is_filterable() {
        $this->stub_categories(
            array(
                array(
                    'id'    => 3,
                    'name'  => 'Billing',
                    'count' => 9,
                ),
            )
        );

        Filters\expectApplied( 'licensio_block_category_grid_terms' )
            ->once()
            ->with( \Mockery::type( 'array' ), \Mockery::type( 'array' ) );

        $block = new Licensio_KB_Category_Grid_Block();

        $this->assertCount( 1, $block->categories( Licensio_KB_Category_Grid_Block::normalize( array() ) ) );
    }

    /**
     * A category slug is reduced to a slug before it reaches the query.
     *
     * The attribute crosses from the block or the shortcode into a taxonomy
     * clause, so this asserts the layer this plugin owns: whatever the editor or
     * the page builder supplies, what leaves `normalize()` is a slug.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_hostile_category_attribute_is_reduced_to_a_slug() {
        $normalised = Licensio_KB_Article_List_Block::normalize(
            array( 'category' => '<script>alert(1)</script>' )
        );

        $this->assertSame( 'alert-1', $normalised['category'] );
        $this->assertDoesNotMatchRegularExpression( '/[<>"\']/', (string) $normalised['category'] );
    }

    /**
     * Out-of-range and unknown attribute values are clamped to something safe.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_attributes_are_clamped_and_whitelisted() {
        $normalised = Licensio_KB_Article_List_Block::normalize(
            array(
                'columns' => '99',
                'count'   => '-4',
                'orderBy' => 'rand',
                'order'   => 'sideways',
            )
        );

        $this->assertSame( 4, $normalised['columns'] );
        $this->assertSame( 1, $normalised['count'] );
        $this->assertSame( 'date', $normalised['orderBy'] );
        $this->assertSame( 'desc', $normalised['order'] );

        $this->assertSame(
            1,
            Licensio_KB_Article_List_Block::normalize( array( 'columns' => '0' ) )['columns']
        );

        $this->assertSame(
            0,
            Licensio_KB_Category_Grid_Block::normalize( array( 'count' => '0' ) )['count'],
            'A category grid keeps zero as "no limit".'
        );
    }

    /**
     * An attribute the block never declared is dropped.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_undeclared_attribute_is_dropped() {
        $normalised = Licensio_KB_Article_List_Block::normalize(
            array(
                'columns'   => 2,
                'post_type' => 'page',
            )
        );

        $this->assertArrayNotHasKey( 'post_type', $normalised );
        $this->assertSame( array_keys( Licensio_KB_Article_List_Block::defaults() ), array_keys( $normalised ) );
    }

    /**
     * The strings a page builder supplies are read as the booleans they denote.
     *
     * `"false"` is a non-empty string and therefore truthy to PHP, so a plain
     * cast would turn every attempt to switch something off into switching it on.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_boolean_attributes_survive_the_trip_through_a_shortcode() {
        foreach ( array( 'false', 'FALSE', 'no', 'off', '0', '' ) as $off ) {
            $this->assertFalse(
                Licensio_KB_Article_List_Block::normalize( array( 'showexcerpt' => $off ) )['showExcerpt'],
                var_export( $off, true ) . ' must switch excerpts off.' // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
            );
        }

        foreach ( array( 'true', 'yes', '1', 'on' ) as $on ) {
            $this->assertTrue(
                Licensio_KB_Article_List_Block::normalize( array( 'showexcerpt' => $on ) )['showExcerpt'],
                var_export( $on, true ) . ' must switch excerpts on.' // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
            );
        }
    }

    /**
     * The column count reaches the grid.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_column_count_reaches_the_grid() {
        $this->stub_articles(
            array(
                array(
                    'id'    => 12,
                    'title' => 'Resetting your password',
                ),
            )
        );

        $markup = $this->render_callback( 'licensio-kb/article-list' )( array( 'columns' => 3 ), '', null );

        $this->assertStringContainsString( 'licensio-kb__grid--cols-3', $markup );
        $this->assertStringNotContainsString( 'licensio-kb__grid--cols-1', $markup );
    }

    /**
     * Switching excerpts off removes them from the cards.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_switching_excerpts_off_removes_them_from_the_cards() {
        $this->stub_articles(
            array(
                array(
                    'id'      => 12,
                    'title'   => 'Resetting your password',
                    'excerpt' => 'A distinctive summary.',
                ),
            )
        );

        $block = $this->render_callback( 'licensio-kb/article-list' );

        $this->assertStringContainsString( 'A distinctive summary.', $block( array(), '', null ) );
        $this->assertStringNotContainsString(
            'A distinctive summary.',
            $block( array( 'showExcerpt' => false ), '', null )
        );
    }

    /**
     * Switching the article count off removes it from the category cards.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_switching_the_article_count_off_removes_it_from_the_cards() {
        $this->stub_categories(
            array(
                array(
                    'id'    => 3,
                    'name'  => 'Billing',
                    'count' => 9,
                ),
            )
        );

        $block = $this->render_callback( 'licensio-kb/category-grid' );

        $this->assertStringContainsString( 'licensio-kb-card__count', $block( array(), '', null ) );
        $this->assertStringContainsString( '(9 Articles)', $block( array(), '', null ) );
        $this->assertStringNotContainsString(
            'licensio-kb-card__count',
            $block( array( 'showCount' => false ), '', null )
        );
    }

    /**
     * The category icon is rendered only when the block asks for it.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_category_icon_is_rendered_only_when_asked_for() {
        $this->stub_categories(
            array(
                array(
                    'id'    => 3,
                    'name'  => 'Billing',
                    'count' => 9,
                ),
            )
        );

        Functions\when( 'get_term_meta' )->alias(
            function ( $term_id, $key ) {
                return Licensio_KB_Term_Meta::META_ICON === $key ? 'dashicons-book-alt' : '';
            }
        );

        $block = $this->render_callback( 'licensio-kb/category-grid' );

        $this->assertStringContainsString( 'dashicons-book-alt', $block( array(), '', null ) );
        $this->assertStringNotContainsString(
            'dashicons-book-alt',
            $block( array( 'showIcon' => false ), '', null )
        );
    }

    /**
     * Dropping the home link shortens the breadcrumb trail by exactly one item.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_home_link_can_be_dropped_from_the_breadcrumb_trail() {
        $block = new Licensio_KB_Breadcrumbs_Block();

        $with    = $block->items( true );
        $without = $block->items( false );

        $this->assertSame( 'Home', $with[0]['label'] );
        $this->assertCount( count( $with ) - 1, $without );
        $this->assertSame( array_slice( $with, 1 ), $without );
    }

    /**
     * The FAQ opens its first question only when asked to.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_faq_opens_the_first_question_only_when_asked_to() {
        $this->stub_articles(
            array(
                array(
                    'id'    => 12,
                    'title' => 'How do I reset my password?',
                ),
                array(
                    'id'    => 4,
                    'title' => 'How do I update my card?',
                ),
            )
        );

        $block = $this->render_callback( 'licensio-kb/faq' );

        $this->assertSame( 0, substr_count( $block( array(), '', null ), '<details class="licensio-kb-faq__item" open>' ) );
        $this->assertSame(
            1,
            substr_count(
                $block( array( 'openFirst' => true ), '', null ),
                '<details class="licensio-kb-faq__item" open>'
            )
        );
    }

    /**
     * The block category is added once, at the front of the inserter.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_block_category_is_registered_once() {
        $categories = $this->blocks->register_category( array( array( 'slug' => 'widgets' ) ) );

        $this->assertSame( Licensio_KB_Blocks::CATEGORY, $categories[0]['slug'] );
        $this->assertCount( 2, $categories );

        $this->assertSame(
            $categories,
            $this->blocks->register_category( $categories ),
            'Registering twice must not add the category twice.'
        );
    }

    /**
     * Every block resolves a block.json to register itself from.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_every_block_resolves_its_metadata_directory() {
        $classes = array(
            'Licensio_KB_Article_List_Block',
            'Licensio_KB_Category_Grid_Block',
            'Licensio_KB_Breadcrumbs_Block',
            'Licensio_KB_FAQ_Block',
        );

        foreach ( $classes as $class_name ) {
            $path = $class_name::metadata_path();

            $this->assertNotSame( '', $path, $class_name . ' found no block.json.' );
            $this->assertSame( $class_name::directory(), basename( $path ) );
            $this->assertFileExists( $path . '/block.json' );

            /*
             * A checkout that has never been built has no `dist/`, and the block
             * must still register from source there — only the editor bundle is
             * missing. Asserting the preference list rather than the resolved
             * path keeps that true whether or not this run happens to be built.
             */
            $this->assertSame(
                array(
                    LICENSIO_KB_DIST_PATH . 'blocks/' . $class_name::directory(),
                    LICENSIO_KB_ABSPATH . 'blocks/' . $class_name::directory(),
                ),
                $class_name::metadata_candidates(),
                $class_name . ' must prefer the built copy and fall back to source.'
            );
        }
    }
}
