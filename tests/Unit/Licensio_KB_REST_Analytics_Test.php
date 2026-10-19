<?php
/**
 * The analytics REST surface.
 *
 * The report bodies come from queries that only a real database can answer, so
 * those are proven in `tests/Integration`. What is proven here is the surface:
 * which routes exist, what gates them, what a range request resolves to, and
 * that a zero-result term really does carry a link that pre-fills a new article.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

use Brain\Monkey\Functions;

/**
 * Drives Licensio_KB_REST_Analytics_Controller.
 *
 * @since 1.0.0
 */
class Licensio_KB_REST_Analytics_Test extends Licensio_KB_Test_Case {

    /**
     * Every route registered during the test, keyed by route path.
     *
     * @since 1.0.0
     * @var array<string, array<int, array<string, mixed>>>
     */
    private $routes = array();

    /**
     * Boot the environment.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        $this->routes = array();

        Functions\when( 'register_rest_route' )->alias(
            function ( $rest_namespace, $route, $args ) {
                unset( $rest_namespace );

                $this->routes[ $route ] = $args;

                return true;
            }
        );

        Functions\when( 'admin_url' )->alias(
            function ( $path = '' ) {
                return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' );
            }
        );

        Functions\when( 'add_query_arg' )->alias(
            function ( $args, $url ) {
                return $url . '?' . http_build_query( $args );
            }
        );

        Functions\when( 'current_time' )->alias(
            function ( $format ) {
                return 'Y-m-d' === $format ? '2026-08-30' : '2026-08-30 12:00:00';
            }
        );
    }

    /**
     * A route is registered for every report the issue names.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_route_is_registered_for_every_report() {
        ( new Licensio_KB_REST_Analytics_Controller() )->register_routes();

        $this->assertSame(
            array(
                '/analytics/overview',
                '/analytics/searches',
                '/analytics/articles',
                '/analytics/feedback',
            ),
            array_keys( $this->routes )
        );
    }

    /**
     * Every route is gated on the shared management permission callback.
     *
     * The reports describe what a site's visitors searched for and read. A
     * route registered with a public callback, or with none at all, would put
     * that in front of anybody who could guess the URL.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_every_route_is_gated_on_the_management_callback() {
        $controller = new Licensio_KB_REST_Analytics_Controller();

        $controller->register_routes();

        $this->assertNotEmpty( $this->routes );

        foreach ( $this->routes as $route => $definition ) {
            $this->assertSame(
                array( $controller, 'manage_permissions_check' ),
                $definition[0]['permission_callback'],
                sprintf( 'Route %s is not gated on the management capability.', $route )
            );
            $this->assertSame( 'GET', $definition[0]['methods'] );
        }
    }

    /**
     * A caller without the management capability is refused.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_caller_without_the_capability_is_refused() {
        Functions\when( 'current_user_can' )->justReturn( false );
        Functions\when( 'rest_authorization_required_code' )->justReturn( 403 );

        $result = ( new Licensio_KB_REST_Analytics_Controller() )
            ->manage_permissions_check( new WP_REST_Request() );

        $this->assertInstanceOf( 'WP_Error', $result );
        $this->assertSame( 'licensio_forbidden', $result->get_error_code() );
    }

    /**
     * A caller holding `manage_licensio_kb` is allowed.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_caller_holding_the_capability_is_allowed() {
        Functions\when( 'current_user_can' )->alias(
            function ( $capability ) {
                return 'manage_licensio_kb' === $capability;
            }
        );

        $this->assertTrue(
            ( new Licensio_KB_REST_Analytics_Controller() )
                ->manage_permissions_check( new WP_REST_Request() )
        );
    }

    /**
     * A date that matches the pattern but is not a real day is rejected.
     *
     * @since 1.0.0
     *
     * @dataProvider provide_dates
     *
     * @param mixed $value    The submitted value.
     * @param bool  $expected Whether it should validate.
     *
     * @return void
     */
    public function test_date_validation( $value, $expected ) {
        $this->assertSame(
            $expected,
            Licensio_KB_REST_Analytics_Controller::is_valid_date( $value )
        );
    }

    /**
     * Dates and whether they are valid.
     *
     * @since 1.0.0
     *
     * @return array<int, array{0: mixed, 1: bool}> The cases.
     */
    public function provide_dates() {
        return array(
            array( '2026-08-30', true ),
            array( '2024-02-29', true ),
            array( '2026-02-29', false ),
            array( '2026-02-31', false ),
            array( '2026-13-01', false ),
            array( '30-08-2026', false ),
            array( '2026-08-30 00:00:00', false ),
            array( '', false ),
            array( 20260830, false ),
            array( array( '2026-08-30' ), false ),
        );
    }

    /**
     * The range defaults to the window ending today.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_range_defaults_to_the_requested_window() {
        $range = $this->resolve( array( 'days' => 7 ) );

        $this->assertSame( '2026-08-24 00:00:00', $range['start'] );
        $this->assertSame( '2026-08-30 23:59:59', $range['end'] );
    }

    /**
     * An explicit range is used as given, with whole-day bounds.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_explicit_range_is_bounded_to_whole_days() {
        $range = $this->resolve(
            array(
                'start' => '2026-01-01',
                'end'   => '2026-01-31',
            )
        );

        $this->assertSame( '2026-01-01 00:00:00', $range['start'] );
        $this->assertSame( '2026-01-31 23:59:59', $range['end'] );
    }

    /**
     * A range submitted backwards is answered as the range it describes.
     *
     * Left as-is, `start > end` produces a WHERE clause no row can satisfy and
     * a dashboard of zeroes that looks like "nobody used the knowledge base"
     * rather than like a mistake.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_backwards_range_is_swapped() {
        $range = $this->resolve(
            array(
                'start' => '2026-01-31',
                'end'   => '2026-01-01',
            )
        );

        $this->assertSame( '2026-01-01 00:00:00', $range['start'] );
        $this->assertSame( '2026-01-31 23:59:59', $range['end'] );
    }

    /**
     * The create-article link carries the term into a new knowledge base article.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_create_article_link_pre_fills_the_term() {
        $url = Licensio_KB_REST_Analytics_Controller::create_article_url( 'reset my password' );

        $this->assertStringStartsWith( 'https://example.test/wp-admin/post-new.php?', $url );

        parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- wp_parse_url() is not loaded in the unit process.

        $this->assertSame( 'licensio_article', $query['post_type'] );
        $this->assertSame(
            'reset my password',
            rawurldecode( $query[ Licensio_KB_Analytics_Admin::PREFILL_QUERY_ARG ] )
        );
    }

    /**
     * The reported range comes back as plain dates, not as datetimes.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_reported_range_is_dates_only() {
        $controller = new Licensio_KB_REST_Analytics_Controller();
        $method     = new ReflectionMethod( $controller, 'range_payload' );

        $method->setAccessible( true );

        $this->assertSame(
            array(
                'start' => '2026-01-01',
                'end'   => '2026-01-31',
            ),
            $method->invoke(
                $controller,
                $this->resolve(
                    array(
                        'start' => '2026-01-01',
                        'end'   => '2026-01-31',
                    )
                )
            )
        );
    }

    /**
     * The row limit is bounded by the repository's own maximum.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_limit_argument_is_bounded() {
        $args = ( new Licensio_KB_REST_Analytics_Controller() )->range_args();

        $this->assertSame( 1, $args['limit']['minimum'] );
        $this->assertSame( Licensio_KB_Analytics_Repository::MAX_LIMIT, $args['limit']['maximum'] );
        $this->assertSame( Licensio_KB_Analytics_Repository::DEFAULT_LIMIT, $args['limit']['default'] );
    }

    /**
     * Resolve a range the way a request would.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $params The request parameters.
     *
     * @return array<string, string> The resolved range.
     */
    private function resolve( array $params ) {
        $controller = new Licensio_KB_REST_Analytics_Controller();
        $method     = new ReflectionMethod( $controller, 'resolve_range' );

        $method->setAccessible( true );

        return (array) $method->invoke(
            $controller,
            new WP_REST_Request( array(), '', $params + array( 'days' => 30 ) )
        );
    }
}
