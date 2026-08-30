<?php
/**
 * Unit tests for the article list table columns.
 *
 * Covers the column set and its ordering, the placeholder every metric column
 * renders until #8 and #9 supply data, and the escaping of the taxonomy cells.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

/**
 * Tests Lisensio_KB_Admin_Columns.
 *
 * @since 1.0.0
 */
class Lisensio_KB_Admin_Columns_Test extends Lisensio_KB_Test_Case {

    /**
     * Terms the fake get_the_terms() returns, by taxonomy.
     *
     * @since 1.0.0
     * @var array<string, mixed>
     */
    private $terms = array();

    /**
     * Stub the list table surface.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        $this->terms = array();

        Functions\when( 'get_the_terms' )->alias(
            function ( $post_id, $taxonomy ) {
                unset( $post_id );

                return isset( $this->terms[ $taxonomy ] ) ? $this->terms[ $taxonomy ] : false;
            }
        );

        Functions\when( 'add_query_arg' )->alias(
            function ( $args, $url ) {
                return $url . '?' . http_build_query( $args );
            }
        );

        Functions\when( 'wp_kses_post' )->returnArg();
    }

    /**
     * The default core column set, as WP_Posts_List_Table builds it.
     *
     * @since 1.0.0
     *
     * @return array<string, string> The core columns.
     */
    private function core_columns() {
        return array(
            'cb'     => '<input type="checkbox" />',
            'title'  => 'Title',
            'author' => 'Author',
            'date'   => 'Date',
        );
    }

    /**
     * Every knowledge base column lands between the author and the date.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_columns_are_inserted_before_the_date() {
        $columns = new Lisensio_KB_Admin_Columns();

        $this->assertSame(
            array( 'cb', 'title', 'author', 'lisensio_category', 'lisensio_tag', 'lisensio_views', 'lisensio_usefulness', 'date' ),
            array_keys( $columns->filter_columns( $this->core_columns() ) )
        );
    }

    /**
     * The taxonomy columns are registered here, not inherited.
     *
     * `show_admin_column` was removed from both taxonomies in the scaffold, so
     * nothing else in the plugin puts a category or a tag on the list table.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_taxonomy_columns_are_present() {
        $columns  = new Lisensio_KB_Admin_Columns();
        $filtered = $columns->filter_columns( $this->core_columns() );

        $this->assertArrayHasKey( 'lisensio_category', $filtered );
        $this->assertArrayHasKey( 'lisensio_tag', $filtered );
    }

    /**
     * A column another plugin added keeps its place.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_third_party_columns_are_preserved() {
        $columns = new Lisensio_KB_Admin_Columns();

        $existing             = $this->core_columns();
        $existing['seo']      = 'SEO';
        $existing['comments'] = 'Comments';

        $filtered = $columns->filter_columns( $existing );

        $this->assertSame( 'SEO', $filtered['seo'] );
        $this->assertSame( 'Comments', $filtered['comments'] );
        $this->assertLessThan(
            array_search( 'seo', array_keys( $filtered ), true ),
            array_search( 'date', array_keys( $filtered ), true ),
            'A column that followed date must still follow it.'
        );
    }

    /**
     * With no date column the knowledge base columns are appended.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_columns_are_appended_when_there_is_no_date_column() {
        $columns = new Lisensio_KB_Admin_Columns();

        $filtered = $columns->filter_columns(
            array(
                'cb'    => '',
                'title' => 'Title',
            )
        );

        $this->assertSame(
            array( 'cb', 'title', 'author', 'lisensio_category', 'lisensio_tag', 'lisensio_views', 'lisensio_usefulness' ),
            array_keys( $filtered )
        );
    }

    /**
     * The author column is guaranteed even if core stops adding it.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_author_column_is_guaranteed() {
        $columns = new Lisensio_KB_Admin_Columns();

        $filtered = $columns->filter_columns(
            array(
                'cb'    => '',
                'title' => 'Title',
                'date'  => 'Date',
            )
        );

        $this->assertArrayHasKey( 'author', $filtered );
    }

    /**
     * An empty knowledge base renders an em dash in every column, with no notices.
     *
     * This is the acceptance criterion for a knowledge base with no articles,
     * no terms, no views and no votes — the state every install starts in.
     *
     * @since 1.0.0
     *
     * @dataProvider column_provider
     *
     * @param string $column The column to render.
     *
     * @return void
     */
    public function test_an_empty_knowledge_base_renders_a_placeholder( $column ) {
        $columns = new Lisensio_KB_Admin_Columns();

        ob_start();
        $columns->render_column( $column, 0 );
        $markup = (string) ob_get_clean();

        $this->assertSame( '—', $markup );
    }

    /**
     * The columns this class renders.
     *
     * @since 1.0.0
     *
     * @return array<string, array{0: string}> Test cases.
     */
    public function column_provider() {
        return array(
            'categories' => array( 'lisensio_category' ),
            'tags'       => array( 'lisensio_tag' ),
            'views'      => array( 'lisensio_views' ),
            'usefulness' => array( 'lisensio_usefulness' ),
        );
    }

    /**
     * A taxonomy error is treated as no terms rather than rendered.
     *
     * `get_the_terms()` returns a WP_Error for a taxonomy that is not
     * registered, which is what an admin request that reached the list table
     * before `init` would see.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_taxonomy_error_renders_a_placeholder() {
        $this->terms['lisensio_category'] = new WP_Error( 'invalid_taxonomy', 'Invalid taxonomy.' );

        $columns = new Lisensio_KB_Admin_Columns();

        ob_start();
        $columns->render_column( 'lisensio_category', 11 );
        $markup = (string) ob_get_clean();

        $this->assertSame( '—', $markup );
    }

    /**
     * Terms are rendered as links that filter the list table.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_terms_are_rendered_as_filter_links() {
        $this->terms['lisensio_category'] = array(
            (object) array(
                'slug' => 'getting-started',
                'name' => 'Getting started',
            ),
            (object) array(
                'slug' => 'billing',
                'name' => 'Billing',
            ),
        );

        $columns = new Lisensio_KB_Admin_Columns();

        ob_start();
        $columns->render_column( 'lisensio_category', 11 );
        $markup = (string) ob_get_clean();

        $this->assertStringContainsString( 'post_type=lisensio_article', $markup );
        $this->assertStringContainsString( 'lisensio_category=getting-started', $markup );
        $this->assertStringContainsString( '>Getting started</a>', $markup );
        $this->assertStringContainsString( '>Billing</a>', $markup );
        $this->assertStringContainsString( '</a>, <a', $markup );
    }

    /**
     * A term name is escaped before it reaches the list table.
     *
     * Term names are author-supplied and a category can be created by anybody
     * holding the management capability, so the cell must not be a stored XSS
     * sink for every other administrator who opens the list table.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_term_name_is_escaped() {
        $this->terms['lisensio_tag'] = array(
            (object) array(
                'slug' => 'x',
                'name' => '<script>alert(1)</script>',
            ),
        );

        $columns = new Lisensio_KB_Admin_Columns();

        ob_start();
        $columns->render_column( 'lisensio_tag', 11 );
        $markup = (string) ob_get_clean();

        $this->assertStringNotContainsString( '<script>', $markup );
        $this->assertStringContainsString( '&lt;script&gt;', $markup );
    }

    /**
     * A supplied metric replaces the placeholder.
     *
     * The seam #8 and #9 attach to. Brain Monkey does not run `add_filter`
     * callbacks, so the filtered value is supplied directly rather than by
     * registering one and hoping.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_supplied_view_count_replaces_the_placeholder() {
        Filters\expectApplied( 'lisensio_article_views_column' )
            ->once()
            ->with( '', 11 )
            ->andReturn( '1,204' );

        $columns = new Lisensio_KB_Admin_Columns();

        ob_start();
        $columns->render_column( 'lisensio_views', 11 );
        $markup = (string) ob_get_clean();

        $this->assertSame( '1,204', $markup );
    }

    /**
     * A supplied usefulness score replaces the placeholder, escaped.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_supplied_usefulness_score_is_escaped() {
        Filters\expectApplied( 'lisensio_article_usefulness_column' )
            ->once()
            ->with( '', 11 )
            ->andReturn( '<b>92%</b>' );

        $columns = new Lisensio_KB_Admin_Columns();

        ob_start();
        $columns->render_column( 'lisensio_usefulness', 11 );
        $markup = (string) ob_get_clean();

        $this->assertStringNotContainsString( '<b>', $markup );
        $this->assertStringContainsString( '92%', $markup );
    }

    /**
     * An unknown column renders nothing at all.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_unknown_column_renders_nothing() {
        $columns = new Lisensio_KB_Admin_Columns();

        ob_start();
        $columns->render_column( 'title', 11 );

        $this->assertSame( '', (string) ob_get_clean() );
    }

    /**
     * The columns are hooked onto the article list table.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_columns_are_hooked_onto_the_article_list_table() {
        $columns = new Lisensio_KB_Admin_Columns();
        $columns->init_hooks();

        $this->assertNotFalse(
            has_filter( 'manage_lisensio_article_posts_columns', array( $columns, 'filter_columns' ) )
        );
        $this->assertNotFalse(
            has_action( 'manage_lisensio_article_posts_custom_column', array( $columns, 'render_column' ) )
        );
    }
}
