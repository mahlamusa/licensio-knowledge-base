<?php
/**
 * Unit tests for the search REST endpoint.
 *
 * The endpoint is public and unauthenticated, so its parameter handling, its
 * rate limit and the shape of what it hands back are the whole of its contract
 * with the internet.
 *
 * The search runner and the log writer are injected as recording doubles rather
 * than mocked away, so the tests can assert what the controller asked them for —
 * particularly that the visitor's paging arithmetic reaches the driver intact,
 * and that one search produces exactly one log row.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

/**
 * A search runner that records its calls and returns a canned result.
 *
 * @since 1.0.0
 */
class Lisensio_KB_Recording_Search_Query extends Lisensio_KB_Search_Query {

    /**
     * The arguments of every search() call.
     *
     * @since 1.0.0
     * @var array<int, array{term: string, args: array<string, mixed>}>
     */
    public $calls = array();

    /**
     * The posts hydrate() returns.
     *
     * @since 1.0.0
     * @var WP_Post[]
     */
    public $posts = array();

    /**
     * The total to report.
     *
     * @since 1.0.0
     * @var int
     */
    public $total = 0;

    /**
     * Record and answer a search.
     *
     * @since 1.0.0
     *
     * @param string               $raw_term The term.
     * @param array<string, mixed> $args     The arguments.
     *
     * @return array{term: string, tokens: string[], ids: int[], total: int, driver: string} The canned result.
     */
    public function search( $raw_term, array $args = array() ) {
        $this->calls[] = array(
            'term' => $raw_term,
            'args' => $args,
        );

        $ids = array();

        foreach ( $this->posts as $post ) {
            $ids[] = (int) $post->ID;
        }

        return array(
            'term'   => (string) $raw_term,
            'tokens' => array( 'licence' ),
            'ids'    => $ids,
            'total'  => $this->total,
            'driver' => 'fulltext',
        );
    }

    /**
     * Return the canned posts.
     *
     * @since 1.0.0
     *
     * @param int[] $ids The IDs.
     *
     * @return WP_Post[] The canned posts.
     */
    public function hydrate( array $ids ) {
        unset( $ids );

        return $this->posts;
    }
}

/**
 * A log writer that records what it was asked to write.
 *
 * @since 1.0.0
 */
class Lisensio_KB_Recording_Search_Log extends Lisensio_KB_Search_Log {

    /**
     * Every recorded search.
     *
     * @since 1.0.0
     * @var array<int, array{term: string, count: int, source: string}>
     */
    public $records = array();

    /**
     * Constructor.
     *
     * @since 1.0.0
     */
    public function __construct() {
    }

    /**
     * Record instead of writing.
     *
     * @since 1.0.0
     *
     * @param string $term          The term.
     * @param int    $results_count The result count.
     * @param string $source        The source.
     *
     * @return bool Always true.
     */
    public function record( $term, $results_count, $source = 'site' ) {
        $this->records[] = array(
            'term'   => (string) $term,
            'count'  => (int) $results_count,
            'source' => (string) $source,
        );

        return true;
    }
}

/**
 * Tests the search REST controller.
 *
 * @since 1.0.0
 */
class Lisensio_KB_Search_REST_Test extends Lisensio_KB_Test_Case {

    /**
     * The recording search runner.
     *
     * @since 1.0.0
     * @var Lisensio_KB_Recording_Search_Query
     */
    private $query;

    /**
     * The recording log writer.
     *
     * @since 1.0.0
     * @var Lisensio_KB_Recording_Search_Log
     */
    private $log;

    /**
     * The controller under test.
     *
     * @since 1.0.0
     * @var Lisensio_KB_REST_Search_Controller
     */
    private $controller;

    /**
     * The in-memory transient store backing the rate limit.
     *
     * @since 1.0.0
     * @var array<string, mixed>
     */
    private $transients = array();

    /**
     * Stub the REST and template surface.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        $this->transients = array();

        $_SERVER['REMOTE_ADDR']     = '198.51.100.4';
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Lisensio Test Runner)';

        Functions\when( 'get_transient' )->alias(
            function ( $key ) {
                return array_key_exists( $key, $this->transients ) ? $this->transients[ $key ] : false;
            }
        );

        Functions\when( 'set_transient' )->alias(
            function ( $key, $value ) {
                $this->transients[ $key ] = $value;

                return true;
            }
        );

        Functions\when( 'is_wp_error' )->alias(
            function ( $thing ) {
                return $thing instanceof WP_Error;
            }
        );

        Functions\when( 'rest_authorization_required_code' )->justReturn( 401 );
        Functions\when( 'wp_strip_all_tags' )->alias(
            function ( $value ) {
                return strip_tags( (string) $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
            }
        );
        Functions\when( 'strip_shortcodes' )->returnArg();
        Functions\when( 'get_the_title' )->alias(
            function ( $post ) {
                return $post->post_title;
            }
        );
        Functions\when( 'get_permalink' )->alias(
            function ( $post ) {
                return 'https://example.test/docs/' . $post->ID . '/';
            }
        );
        Functions\when( 'get_the_terms' )->justReturn(
            array(
                (object) array(
                    'term_id' => 12,
                    'name'    => 'Getting started',
                ),
            )
        );
        Functions\when( 'get_term_link' )->justReturn( 'https://example.test/docs-category/getting-started/' );

        $this->query      = new Lisensio_KB_Recording_Search_Query();
        $this->log        = new Lisensio_KB_Recording_Search_Log();
        $this->controller = new Lisensio_KB_REST_Search_Controller( $this->query, $this->log );
    }

    /**
     * The route is registered under the plugin namespace, as a public GET.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_route_is_registered_publicly() {
        $registered = array();

        Functions\when( 'register_rest_route' )->alias(
            function ( $route_namespace, $route, $args ) use ( &$registered ) {
                $registered[] = array(
                    'namespace' => $route_namespace,
                    'route'     => $route,
                    'args'      => $args,
                );

                return true;
            }
        );

        $this->controller->register_routes();

        $this->assertCount( 1, $registered );
        $this->assertSame( 'lisensio-kb/v1', $registered[0]['namespace'] );
        $this->assertSame( '/search', $registered[0]['route'] );
        $this->assertSame( 'GET', $registered[0]['args'][0]['methods'] );
        $this->assertSame(
            array( $this->controller, 'search_permissions_check' ),
            $registered[0]['args'][0]['permission_callback']
        );
    }

    /**
     * The term is sanitised by the endpoint's own argument definition.
     *
     * Boolean operators have to be stripped before the term reaches the query,
     * and a `sanitize_callback` is how REST guarantees that for every caller
     * rather than for the ones the handler remembers to clean.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_term_argument_sanitizes_boolean_operators() {
        $params = $this->controller->get_collection_params();

        $this->assertTrue( $params['term']['required'] );
        $this->assertSame(
            array( 'Lisensio_KB_Search_Query', 'sanitize_term' ),
            $params['term']['sanitize_callback']
        );
    }

    /**
     * Paging is bounded, so one request cannot ask for the whole index.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_paging_parameters_are_bounded() {
        $params = $this->controller->get_collection_params();

        $this->assertSame( 1, $params['per_page']['minimum'] );
        $this->assertSame( 50, $params['per_page']['maximum'] );
        $this->assertSame( 1, $params['page']['minimum'] );
        $this->assertSame( Lisensio_KB_Search_Log::SOURCES, $params['source']['enum'] );
    }

    /**
     * A visitor within budget may search.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_visitor_within_budget_may_search() {
        $this->assertTrue( $this->controller->search_permissions_check( new WP_REST_Request() ) );
    }

    /**
     * Rotating the user agent does not buy a fresh budget.
     *
     * The user agent is a header the caller supplies. While the limiter keyed
     * on an identity that included it, a client varying it minted a new
     * identity - and a new budget - on every request, so the limit restrained
     * nobody who did not want to be restrained. Demonstrated against the live
     * site: 30 requests on a fixed agent then 429s, while a fresh agent was
     * served immediately at the same instant.
     *
     * Spends the budget under one agent, then changes it and asserts the
     * refusal stands.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_rotating_user_agent_does_not_reset_the_budget() {
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (budget spender)';

        for ( $i = 0; $i < Lisensio_KB_REST_Search_Controller::RATE_LIMIT; $i++ ) {
            $this->assertTrue( $this->controller->search_permissions_check( new WP_REST_Request() ) );
        }

        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (a completely different client)';

        $refused = $this->controller->search_permissions_check( new WP_REST_Request() );

        $this->assertInstanceOf( 'WP_Error', $refused, 'a new user agent was handed a fresh budget' );
        $this->assertSame( 'lisensio_rate_limited', $refused->get_error_code() );
    }

    /**
     * A visitor over budget is refused with 429.
     *
     * The endpoint is public and writes a row to the search log on every call,
     * so without a limit an anonymous caller can fill the analytics tables with
     * a term of their choosing and poison every report built on them.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_visitor_over_budget_is_refused() {
        for ( $i = 0; $i < Lisensio_KB_REST_Search_Controller::RATE_LIMIT; $i++ ) {
            $this->assertTrue( $this->controller->search_permissions_check( new WP_REST_Request() ) );
        }

        $refused = $this->controller->search_permissions_check( new WP_REST_Request() );

        $this->assertInstanceOf( 'WP_Error', $refused );
        $this->assertSame( 'lisensio_rate_limited', $refused->get_error_code() );
        $this->assertSame( 429, $refused->get_error_data()['status'] );
    }

    /**
     * The rate limit is filterable.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_rate_limit_is_filterable() {
        Filters\expectApplied( 'lisensio_search_rate_limit' )
            ->once()
            ->with( Lisensio_KB_REST_Search_Controller::RATE_LIMIT )
            ->andReturn( 1 );

        $this->assertTrue( $this->controller->search_permissions_check( new WP_REST_Request() ) );

        Filters\expectApplied( 'lisensio_search_rate_limit' )->andReturn( 1 );

        $this->assertInstanceOf(
            'WP_Error',
            $this->controller->search_permissions_check( new WP_REST_Request() )
        );
    }

    /**
     * A result carries every field the type-ahead renders.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_result_carries_the_documented_fields() {
        $this->query->posts = array( $this->article() );
        $this->query->total = 1;

        $items = $this->controller->get_items( $this->request() )->get_data();

        $this->assertCount( 1, $items );
        $this->assertSame( 55, $items[0]['id'] );
        $this->assertSame( 'Licence activation', $items[0]['title'] );
        $this->assertSame( 'https://example.test/docs/55/', $items[0]['url'] );
        $this->assertSame( 'Getting started', $items[0]['category']['name'] );
        $this->assertStringContainsString( 'mark', $items[0]['snippet'] );
        $this->assertNotSame( '', $items[0]['excerpt'] );
    }

    /**
     * An article with no category still produces a well-formed result.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_uncategorised_article_still_returns_a_category_shape() {
        Functions\when( 'get_the_terms' )->justReturn( false );

        $this->query->posts = array( $this->article() );

        $items = $this->controller->get_items( $this->request() )->get_data();

        $this->assertSame( 0, $items[0]['category']['id'] );
        $this->assertSame( '', $items[0]['category']['name'] );
        $this->assertSame( '', $items[0]['category']['url'] );
    }

    /**
     * The visitor's page maps to the driver's offset.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_paging_reaches_the_driver_as_an_offset() {
        $this->controller->get_items(
            $this->request(
                array(
                    'page'     => 3,
                    'per_page' => 8,
                )
            )
        );

        $this->assertSame(
            array(
                'limit'  => 8,
                'offset' => 16,
            ),
            $this->query->calls[0]['args']
        );
    }

    /**
     * The response carries the paging headers and the driver that answered.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_response_carries_paging_headers() {
        $this->query->posts = array( $this->article() );
        $this->query->total = 21;

        $headers = $this->controller->get_items( $this->request( array( 'per_page' => 10 ) ) )->get_headers();

        $this->assertSame( '21', $headers['X-WP-Total'] );
        $this->assertSame( '3', $headers['X-WP-TotalPages'] );
        $this->assertSame( 'fulltext', $headers['X-Lisensio-Search-Driver'] );
    }

    /**
     * Every search is logged, with the source the caller declared.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_every_search_is_logged_with_its_source() {
        $this->query->total = 4;

        $this->controller->get_items( $this->request( array( 'source' => 'live' ) ) );

        $this->assertCount( 1, $this->log->records );
        $this->assertSame( 'licence', $this->log->records[0]['term'] );
        $this->assertSame( 4, $this->log->records[0]['count'] );
        $this->assertSame( 'live', $this->log->records[0]['source'] );
    }

    /**
     * An unknown source is coerced rather than stored.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_unknown_source_is_coerced() {
        $this->controller->get_items( $this->request( array( 'source' => 'javascript:alert(1)' ) ) );

        $this->assertSame( 'site', $this->log->records[0]['source'] );
    }

    /**
     * Paging through one result set logs one search, not one per page.
     *
     * Counting each page separately would inflate every "top searches" figure by
     * however deep visitors happen to browse.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_paging_does_not_log_the_search_again() {
        $this->controller->get_items( $this->request( array( 'page' => 2 ) ) );

        $this->assertSame( array(), $this->log->records );
    }

    /**
     * A zero-result search is logged, because that is the interesting one.
     *
     * The whole point of the search log is finding the documentation gaps, and a
     * gap is a search that returned nothing.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_zero_result_search_is_still_logged() {
        $this->query->posts = array();
        $this->query->total = 0;

        $response = $this->controller->get_items( $this->request() );

        $this->assertSame( array(), $response->get_data() );
        $this->assertCount( 1, $this->log->records );
        $this->assertSame( 0, $this->log->records[0]['count'] );
    }

    /**
     * An article to return from the fake search.
     *
     * @since 1.0.0
     *
     * @return WP_Post The article.
     */
    private function article() {
        return new WP_Post(
            array(
                'ID'           => 55,
                'post_type'    => 'lisensio_article',
                'post_title'   => 'Licence activation',
                'post_content' => 'Everything about how a licence is activated on a new site.',
            )
        );
    }

    /**
     * A request with the endpoint's defaults applied.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $params Parameters to override.
     *
     * @return WP_REST_Request The request.
     */
    private function request( array $params = array() ) {
        $defaults = array(
            'term'     => 'licence',
            'page'     => 1,
            'per_page' => 10,
            'source'   => 'site',
        );

        return new WP_REST_Request( array(), '/lisensio-kb/v1/search', array_merge( $defaults, $params ) );
    }
}
