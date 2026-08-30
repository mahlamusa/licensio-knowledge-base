<?php
/**
 * Unit tests for the REST layer.
 *
 * Covers the three shared permission callbacks, the rate-limit seam, the
 * controller registrar's type checking and the reserved-route stub table. The
 * permission callbacks are the only thing standing between the internet and
 * #8's feedback writes, and shipped with no tests.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

/**
 * A controller that is not one of ours, used to prove the type check.
 *
 * Records whether it was ever constructed.
 *
 * @since 1.0.0
 */
class Lisensio_KB_Foreign_Controller {

    /**
     * How many times this class has been constructed.
     *
     * @since 1.0.0
     * @var int
     */
    public static $constructed = 0;

    /**
     * Constructor.
     *
     * @since 1.0.0
     */
    public function __construct() {
        ++self::$constructed;
    }

    /**
     * Stand-in for the controller interface.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function register_routes() {
    }
}

/**
 * Concrete subclass so the abstract base can be exercised.
 *
 * @since 1.0.0
 */
class Lisensio_KB_Test_Controller extends Lisensio_KB_REST_Controller {

    /**
     * Register no routes.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function register_routes() {
    }

    /**
     * Expose the protected rate-limit seam to the tests.
     *
     * @since 1.0.0
     *
     * @param string $bucket The bucket name.
     * @param int    $limit  Requests per window.
     * @param int    $window Window length in seconds.
     *
     * @return true|WP_Error The rate-limit verdict.
     */
    public function rate_limit( $bucket, $limit, $window ) {
        return $this->check_rate_limit( $bucket, $limit, $window );
    }
}

/**
 * Tests the shared REST controller behaviour and the registrar.
 *
 * @since 1.0.0
 */
class Lisensio_KB_REST_Test extends Lisensio_KB_Test_Case {

    /**
     * The in-memory transient store.
     *
     * @since 1.0.0
     * @var array<string, mixed>
     */
    private $transients = array();

    /**
     * Stub the REST surface.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        $this->transients = array();

        Lisensio_KB_Foreign_Controller::$constructed = 0;

        $_SERVER['REMOTE_ADDR']     = '198.51.100.4';
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Lisensio Test Runner)';

        Functions\when( 'rest_authorization_required_code' )->justReturn( 401 );
        Functions\when( 'is_wp_error' )->alias(
            function ( $thing ) {
                return $thing instanceof WP_Error;
            }
        );
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
    }

    /**
     * Drop the request superglobals.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function tearDown(): void {
        unset( $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT'] );

        parent::tearDown();
    }

    /**
     * Public read endpoints are open.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_public_reads_are_permitted() {
        $controller = new Lisensio_KB_Test_Controller();

        $this->assertTrue( $controller->public_permissions_check( new WP_REST_Request() ) );
    }

    /**
     * A public write without a nonce is refused with a 403.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_public_write_without_a_nonce_is_refused() {
        Functions\when( 'wp_verify_nonce' )->justReturn( false );

        $controller = new Lisensio_KB_Test_Controller();
        $result     = $controller->public_write_permissions_check( new WP_REST_Request() );

        $this->assertInstanceOf( 'WP_Error', $result );
        $this->assertSame( 'lisensio_invalid_nonce', $result->get_error_code() );
        $this->assertSame( 403, $result->error_data['lisensio_invalid_nonce']['status'] );
    }

    /**
     * A public write with a valid nonce is permitted.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_public_write_with_a_valid_nonce_is_permitted() {
        Functions\when( 'wp_verify_nonce' )->justReturn( 1 );

        $controller = new Lisensio_KB_Test_Controller();
        $request    = new WP_REST_Request( array( 'X-WP-Nonce' => 'a-nonce' ) );

        $this->assertTrue( $controller->public_write_permissions_check( $request ) );
    }

    /**
     * Management endpoints are refused without the capability.
     *
     * This is the guarantee that the analytics, settings and feedback
     * moderation routes cannot be public before #3 lands its capability map.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_management_endpoints_are_refused_without_a_capability() {
        Functions\when( 'current_user_can' )->justReturn( false );

        $controller = new Lisensio_KB_Test_Controller();
        $result     = $controller->manage_permissions_check( new WP_REST_Request() );

        $this->assertInstanceOf( 'WP_Error', $result );
        $this->assertSame( 'lisensio_forbidden', $result->get_error_code() );
    }

    /**
     * Management endpoints are permitted with the capability.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_management_endpoints_are_permitted_with_a_capability() {
        Functions\when( 'current_user_can' )->justReturn( true );

        $controller = new Lisensio_KB_Test_Controller();

        $this->assertTrue( $controller->manage_permissions_check( new WP_REST_Request() ) );
    }

    /**
     * The rate-limit seam permits a caller inside their budget and then refuses.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_rate_limit_seam_refuses_once_the_budget_is_spent() {
        $controller = new Lisensio_KB_Test_Controller();

        $this->assertTrue( $controller->rate_limit( 'feedback', 2, 60 ) );
        $this->assertTrue( $controller->rate_limit( 'feedback', 2, 60 ) );

        $result = $controller->rate_limit( 'feedback', 2, 60 );

        $this->assertInstanceOf( 'WP_Error', $result );
        $this->assertSame( 'lisensio_rate_limited', $result->get_error_code() );
        $this->assertSame( 429, $result->error_data['lisensio_rate_limited']['status'] );
    }

    /**
     * Separate buckets hold separate budgets.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_rate_limit_buckets_are_independent() {
        $controller = new Lisensio_KB_Test_Controller();

        $this->assertTrue( $controller->rate_limit( 'feedback', 1, 60 ) );
        $this->assertInstanceOf( 'WP_Error', $controller->rate_limit( 'feedback', 1, 60 ) );

        $this->assertTrue( $controller->rate_limit( 'views', 1, 60 ) );
    }

    /**
     * A salt rotation inside the window does not hand out a fresh budget.
     *
     * The counter is read across every hash the visitor could be known by, so
     * spending the budget and then rotating does not reset it. Without this a
     * visitor could double their allowance by waiting for a rotation.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_salt_rotation_does_not_reset_the_rate_limit() {
        $controller = new Lisensio_KB_Test_Controller();

        $this->assertTrue( $controller->rate_limit( 'feedback', 1, 60 ) );

        $visitor = new Lisensio_KB_Visitor();
        $visitor->rotate_salt();

        $this->assertInstanceOf( 'WP_Error', $controller->rate_limit( 'feedback', 1, 60 ) );
    }

    /**
     * The rate-limit key never contains the raw visitor hash or an IP.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_rate_limit_keys_leak_neither_ip_nor_hash() {
        $controller = new Lisensio_KB_Test_Controller();
        $controller->rate_limit( 'feedback', 5, 60 );

        $visitor = new Lisensio_KB_Visitor();
        $hash    = $visitor->get_hash();

        foreach ( array_keys( $this->transients ) as $key ) {
            $this->assertStringNotContainsString( '198.51.100.4', $key );
            $this->assertStringNotContainsString( $hash, $key );
        }
    }

    /**
     * A class that is not one of ours is never constructed.
     *
     * The registrar used to instantiate first and type-check afterwards, so the
     * constructor of whatever the filter named ran regardless.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_foreign_controller_is_never_constructed() {
        Filters\expectApplied( 'lisensio_rest_controllers' )
            ->andReturn( array( 'Lisensio_KB_Foreign_Controller' ) );

        $api = new Lisensio_KB_REST_API();
        $api->register_controllers();

        $this->assertSame( 0, Lisensio_KB_Foreign_Controller::$constructed );
    }

    /**
     * A class name that does not exist is skipped without erroring.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_unknown_controller_class_is_skipped() {
        $registered = 0;

        Functions\when( 'register_rest_route' )->alias(
            function () use ( &$registered ) {
                $registered++;

                return true;
            }
        );

        Filters\expectApplied( 'lisensio_rest_controllers' )
            ->andReturn( array( 'Lisensio_KB_No_Such_Controller', 42, null ) );

        $api = new Lisensio_KB_REST_API();
        $api->register_controllers();

        $this->assertSame( 0, $registered, 'An unknown controller must register no routes.' );
    }

    /**
     * The namespace has exactly one source of truth.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_rest_namespace_has_one_source_of_truth() {
        $controller = new Lisensio_KB_Test_Controller();

        $this->assertSame( LISENSIO_KB_REST_NAMESPACE, Lisensio_KB_REST_API::NAMESPACE_V1 );
        $this->assertSame( LISENSIO_KB_REST_NAMESPACE, Lisensio_KB_REST_API::get_namespace() );
        $this->assertSame( LISENSIO_KB_REST_NAMESPACE, $controller->get_namespace() );
    }

    /**
     * Every reserved route declares a permission callback that exists.
     *
     * A route whose permission callback is missing or misspelled would be
     * treated by WordPress as having none at all.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_every_reserved_route_has_a_real_permission_callback() {
        $stubs = new Lisensio_KB_REST_Stubs_Controller();

        $routes = $stubs->reserved_routes();

        $this->assertNotEmpty( $routes );

        foreach ( $routes as $route => $definition ) {
            $this->assertArrayHasKey( 'permission', $definition, $route );
            $this->assertNotEmpty( $definition['permission'], $route );
            $this->assertTrue(
                method_exists( $stubs, $definition['permission'] ),
                sprintf( 'Route %s names a permission callback that does not exist.', $route )
            );
        }
    }

    /**
     * No reserved route is publicly writable without the nonce check.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_no_write_route_is_open_to_the_public() {
        $stubs = new Lisensio_KB_REST_Stubs_Controller();

        foreach ( $stubs->reserved_routes() as $route => $definition ) {
            if ( false === strpos( strtoupper( $definition['methods'] ), 'GET' ) ) {
                $this->assertNotSame(
                    'public_permissions_check',
                    $definition['permission'],
                    sprintf( 'Write route %s must not use the open read permission.', $route )
                );
            }
        }
    }

    /**
     * A reserved route answers 501 rather than doing anything.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_reserved_route_answers_not_implemented() {
        $stubs = new Lisensio_KB_REST_Stubs_Controller();

        $result = $stubs->handle_stub( new WP_REST_Request( array(), '/lisensio-kb/v1/search' ) );

        $this->assertInstanceOf( 'WP_Error', $result );
        $this->assertSame( 'lisensio_not_implemented', $result->get_error_code() );
        $this->assertSame( 501, $result->error_data['lisensio_not_implemented']['status'] );
    }
}
