<?php
/**
 * Unit tests for the search component's wiring.
 *
 * A feature that is never attached to WordPress ships as dead code, and the
 * wiring is exactly the part a test suite normally forgets: everything else
 * here can be green while `/search` still answers 501 because the stub route
 * was never released.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;

/**
 * Tests component registration, the main-query integration and the CLI command.
 *
 * @since 1.0.0
 */
class Licensio_KB_Search_Component_Test extends Licensio_KB_Test_Case {

    /**
     * The component under test.
     *
     * @since 1.0.0
     * @var Licensio_KB_Search
     */
    private $search;

    /**
     * Boot the component and reset the WP-CLI double.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        $this->search = Licensio_KB_Search::instance();

        WP_CLI::reset();
    }

    /**
     * The component attaches itself to the container's ready hook.
     *
     * Driven through the registration function rather than by loading the file,
     * because a file-scope `add_action()` runs once per process and could never
     * be observed by a test.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_component_is_attached_to_the_container() {
        Actions\expectAdded( 'licensio_loaded' )
            ->once()
            ->with( 'licensio_kb_boot_search' );

        licensio_kb_register_search_bootstrap();
    }

    /**
     * The search controller joins the plugin's REST registrar.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_controller_is_added_to_the_registrar() {
        $controllers = $this->search->register_rest_controller( array( 'Some_Other_Controller' ) );

        $this->assertContains( 'Licensio_KB_REST_Search_Controller', $controllers );
        $this->assertContains( 'Some_Other_Controller', $controllers );
    }

    /**
     * The controller class named by the filter really exists and is one of ours.
     *
     * The registrar skips any class that is not a subclass of the plugin's base
     * controller, silently. A typo in the name would leave `/search` answering
     * 501 with nothing in the logs.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_named_controller_class_is_a_plugin_controller() {
        $controllers = $this->search->register_rest_controller( array() );

        $this->assertTrue( class_exists( $controllers[0] ) );
        $this->assertTrue( is_subclass_of( $controllers[0], 'Licensio_KB_REST_Controller' ) );
    }

    /**
     * The `/search` stub is released so the real controller owns the route.
     *
     * The scaffold registers `/search` as a 501 stub. Leaving it in place would
     * not break the route — stubs register later — but the reserved list is the
     * plugin's own record of what is unimplemented, and search is implemented.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_search_stub_route_is_released() {
        $routes = $this->search->release_stub_route(
            array(
                '/search'   => array( 'methods' => 'GET' ),
                '/feedback' => array( 'methods' => 'POST' ),
            )
        );

        $this->assertArrayNotHasKey( '/search', $routes );
        $this->assertArrayHasKey( '/feedback', $routes, 'Only this issue\'s route may be released.' );
    }

    /**
     * The scaffold really does reserve `/search`, so releasing it means
     * something.
     *
     * Without this, the release test above would keep passing if the scaffold
     * ever renamed the route, and the plugin would quietly register two
     * handlers for different paths.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_scaffold_reserves_the_route_this_issue_releases() {
        $stubs = new Licensio_KB_REST_Stubs_Controller();

        $this->assertArrayHasKey( '/search', $stubs->reserved_routes() );
    }

    /**
     * Booting the component registers the hooks the feature needs.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_booting_registers_the_feature_hooks() {
        Actions\expectAdded( 'pre_get_posts' )->once();
        Actions\expectAdded( 'wp' )->once();

        $this->search->init_hooks();
    }

    /**
     * The full results page is ranked by the search index.
     *
     * Pressing Enter without choosing a suggestion lands on this query. Left
     * alone it would be core's LIKE search over title and content, which cannot
     * see term names and orders by date — so the page and the type-ahead would
     * disagree about the best answer for the same words.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_main_search_query_is_ranked_by_the_index() {
        $query = $this->fake_query(
            array(
                's'         => 'licence',
                'post_type' => 'licensio_article',
            )
        );

        $this->rank( $query, array( 9, 4, 7 ) );

        $this->assertSame( array( 9, 4, 7 ), $query->get( 'post__in' ) );
        $this->assertSame( 'post__in', $query->get( 'orderby' ) );
    }

    /**
     * A term the index does not match returns nothing on the page too.
     *
     * Leaving `post__in` unset would hand the query back to core's own search,
     * so a term with no indexed match would show results on the results page and
     * none in the type-ahead.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_term_with_no_matches_narrows_the_query_to_nothing() {
        $query = $this->fake_query(
            array(
                's'         => 'licence',
                'post_type' => 'licensio_article',
            )
        );

        $this->rank( $query, array() );

        $this->assertSame( array( 0 ), $query->get( 'post__in' ) );
    }

    /**
     * A search that is not scoped to the knowledge base is left alone.
     *
     * `pre_get_posts` fires for every query on the site. Taking over the blog's
     * search results would be a spectacular way to break a host theme.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_search_outside_the_knowledge_base_is_untouched() {
        $query = $this->fake_query(
            array(
                's'         => 'licence',
                'post_type' => 'post',
            )
        );

        $this->rank( $query, array( 9 ) );

        $this->assertNull( $query->get( 'post__in' ) );
        $this->assertNull( $query->get( 'orderby' ) );
    }

    /**
     * A query that is not a search is left alone.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_non_search_query_is_untouched() {
        $query = $this->fake_query(
            array( 'post_type' => 'licensio_article' ),
            true,
            false
        );

        $this->rank( $query, array( 9 ) );

        $this->assertNull( $query->get( 'post__in' ) );
    }

    /**
     * A secondary query is left alone.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_secondary_query_is_untouched() {
        $query = $this->fake_query(
            array(
                's'         => 'licence',
                'post_type' => 'licensio_article',
            ),
            false
        );

        $this->rank( $query, array( 9 ) );

        $this->assertNull( $query->get( 'post__in' ) );
    }

    /**
     * The WP-CLI command is registered under the documented name.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_cli_command_is_registered() {
        $this->assertTrue( Licensio_KB_Search_CLI::register() );

        $this->assertCount( 1, WP_CLI::$commands );
        $this->assertSame( 'licensio-kb', WP_CLI::$commands[0]['name'] );
        $this->assertTrue(
            method_exists( WP_CLI::$commands[0]['handler'], 'reindex' ),
            'wp licensio-kb reindex has no reindex method to dispatch to.'
        );
    }

    /**
     * Reindexing walks every article and reports what it did.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_reindex_rebuilds_and_reports() {
        Functions\when( 'number_format_i18n' )->alias(
            function ( $number, $decimals = 0 ) {
                return number_format( (float) $number, (int) $decimals );
            }
        );

        $index   = new Licensio_KB_Recording_Index();
        $command = new Licensio_KB_Search_CLI( $index );

        $command->reindex( array(), array() );

        $this->assertTrue( $index->rebuilt );
        $this->assertFalse( $index->truncated );
        $this->assertCount( 1, WP_CLI::$successes );
        $this->assertStringContainsString( '120', WP_CLI::$successes[0] );
    }

    /**
     * The flush flag empties the index before rebuilding it.
     *
     * Without it, rows for articles that are no longer indexable survive a
     * rebuild, because a rebuild only ever visits articles that still exist.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_flush_flag_empties_the_index_first() {
        Functions\when( 'number_format_i18n' )->justReturn( '0.01' );

        $index   = new Licensio_KB_Recording_Index();
        $command = new Licensio_KB_Search_CLI( $index );

        $command->reindex( array(), array( 'flush' => true ) );

        $this->assertTrue( $index->truncated );
        $this->assertTrue( $index->rebuilt );
    }

    /**
     * A query double flagged as the main query and a search, or not.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $vars   The query variables.
     * @param bool                 $main   Whether this is the main query.
     * @param bool                 $search Whether this is a search.
     *
     * @return WP_Query The query.
     */
    private function fake_query( array $vars = array(), $main = true, $search = true ) {
        $query            = new WP_Query( $vars );
        $query->main      = (bool) $main;
        $query->is_search = (bool) $search;

        return $query;
    }

    /**
     * Drive rank_main_query() with a fake query and a canned result.
     *
     * @since 1.0.0
     *
     * @param WP_Query $query The query to pass through.
     * @param int[]    $ids   The IDs the search should return.
     *
     * @return void
     */
    private function rank( $query, array $ids ) {
        Functions\when( 'wp_strip_all_tags' )->alias(
            function ( $value ) {
                return strip_tags( (string) $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
            }
        );
        Functions\when( 'is_admin' )->justReturn( false );

        $runner = new Licensio_KB_Search_Query();
        $runner->set_driver( new Licensio_KB_Search_Fake_Driver( $ids, count( $ids ) ) );

        $reflection = new ReflectionProperty( 'Licensio_KB_Search', 'query' );
        $reflection->setAccessible( true );
        $reflection->setValue( $this->search, $runner );

        $this->search->rank_main_query( $query );

        $reflection->setValue( $this->search, null );
    }
}

/**
 * An index that records what the command asked it to do.
 *
 * @since 1.0.0
 */
class Licensio_KB_Recording_Index extends Licensio_KB_Search_Index {

    /**
     * Whether truncate() was called.
     *
     * @since 1.0.0
     * @var bool
     */
    public $truncated = false;

    /**
     * Whether rebuild_all() was called.
     *
     * @since 1.0.0
     * @var bool
     */
    public $rebuilt = false;

    /**
     * Record the flush.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function truncate() {
        $this->truncated = true;
    }

    /**
     * Record the rebuild.
     *
     * @since 1.0.0
     *
     * @param callable|null $progress The progress callback.
     *
     * @return int The number of articles processed.
     */
    public function rebuild_all( $progress = null ) {
        $this->rebuilt = true;

        if ( is_callable( $progress ) ) {
            call_user_func( $progress, 50 );
            call_user_func( $progress, 120 );
        }

        return 120;
    }

    /**
     * The number of indexed rows.
     *
     * @since 1.0.0
     *
     * @return int The row count.
     */
    public function count() {
        return 120;
    }
}
