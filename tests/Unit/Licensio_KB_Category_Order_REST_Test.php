<?php
/**
 * The per-category article order routes.
 *
 * The routes ship as stubs. What these tests pin is the contract the
 * categories app and the per-category article order build against: the two
 * paths, their methods, their argument schema, the permission check and the
 * not-implemented answer.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

use Brain\Monkey\Functions;

/**
 * Drives Licensio_KB_Category_Order_REST_Controller.
 *
 * @since 1.0.0
 */
class Licensio_KB_Category_Order_REST_Test extends Licensio_KB_Test_Case {

    /**
     * Every route registered during the test, keyed by route path.
     *
     * @since 1.0.0
     * @var array<string, array<int, array<string, mixed>>>
     */
    private $routes = array();

    /**
     * Capture route registration.
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
                $this->assertSame( 'licensio-kb/v1', $rest_namespace );

                $this->routes[ $route ] = $args;

                return true;
            }
        );

        Functions\when( 'rest_authorization_required_code' )->justReturn( 403 );

        ( new Licensio_KB_Category_Order_REST_Controller() )->register_routes();
    }

    /**
     * The list route is a GET with paging capped at 200.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_list_route_is_registered_with_its_arguments() {
        $route = '/categories/(?P<id>[\d]+)/articles';

        $this->assertArrayHasKey( $route, $this->routes );
        $this->assertSame( 'GET', $this->routes[ $route ][0]['methods'] );

        $args = $this->routes[ $route ][0]['args'];

        $this->assertTrue( $args['id']['required'] );
        $this->assertSame( 'integer', $args['id']['type'] );
        $this->assertSame( 1, $args['id']['minimum'] );
        $this->assertSame( 1, $args['page']['default'] );
        $this->assertSame( 200, $args['per_page']['default'] );
        $this->assertSame( 200, $args['per_page']['maximum'] );
    }

    /**
     * The order route is a PUT taking a list of unique article IDs.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_order_route_is_registered_with_its_arguments() {
        $route = '/categories/(?P<id>[\d]+)/articles/order';

        $this->assertArrayHasKey( $route, $this->routes );
        $this->assertSame( 'PUT', $this->routes[ $route ][0]['methods'] );

        $ids = $this->routes[ $route ][0]['args']['ids'];

        $this->assertTrue( $ids['required'] );
        $this->assertSame( 'array', $ids['type'] );
        $this->assertTrue( $ids['uniqueItems'] );
        $this->assertSame( 'integer', $ids['items']['type'] );
        $this->assertSame( 1, $ids['items']['minimum'] );
    }

    /**
     * Both routes require the management capability.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_both_routes_require_the_management_capability() {
        Functions\when( 'current_user_can' )->justReturn( false );

        foreach ( $this->routes as $route => $handlers ) {
            $permission = $handlers[0]['permission_callback'];

            $this->assertSame( 'manage_permissions_check', $permission[1], $route );

            $result = call_user_func( $permission, new WP_REST_Request() );

            $this->assertInstanceOf( 'WP_Error', $result, $route );
            $this->assertSame( 403, $result->get_error_data()['status'] );
        }
    }

    /**
     * Both routes answer 501 until they are built.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_both_routes_answer_not_implemented() {
        foreach ( $this->routes as $route => $handlers ) {
            $result = call_user_func( $handlers[0]['callback'], new WP_REST_Request( array(), '/licensio-kb/v1/categories/5/articles' ) );

            $this->assertInstanceOf( 'WP_Error', $result, $route );
            $this->assertSame( 'licensio_kb_not_implemented', $result->get_error_code() );
            $this->assertSame( array( 'status' => 501 ), $result->get_error_data() );
        }
    }
}
