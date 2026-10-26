<?php
/**
 * The per-category article order routes.
 *
 * Pins the contract the categories app builds against: the two paths, their
 * methods, their argument schema and the permission check. Then the handlers:
 * the category check, the listing shape and the validation the save runs
 * before it writes anything.
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
        Functions\when( 'rest_ensure_response' )->returnArg();
        Functions\when( 'absint' )->alias(
            function ( $value ) {
                return abs( (int) $value );
            }
        );
        Functions\when( 'is_wp_error' )->alias(
            function ( $thing ) {
                return $thing instanceof WP_Error;
            }
        );

        ( new Licensio_KB_Category_Order_REST_Controller() )->register_routes();
    }

    /**
     * Drop the $wpdb double a save test installs.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function tearDown(): void {
        unset( $GLOBALS['wpdb'] );

        parent::tearDown();
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
        $this->assertSame( 1000, $ids['maxItems'] );
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
     * Both routes answer 404 for an ID that is not a knowledge base category.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_both_routes_reject_an_unknown_category() {
        Functions\when( 'get_term' )->justReturn( null );
        Functions\expect( 'update_post_meta' )->never();

        foreach ( $this->routes as $route => $handlers ) {
            $result = call_user_func( $handlers[0]['callback'], $this->request( 5, array( 1, 2 ) ) );

            $this->assertInstanceOf( 'WP_Error', $result, $route );
            $this->assertSame( 'licensio_kb_invalid_term', $result->get_error_code() );
            $this->assertSame( 404, $result->get_error_data()['status'] );
        }
    }

    /**
     * The list answers items in query order with their position, or null.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_list_answers_items_with_their_positions() {
        $this->stub_category( 5 );

        $query              = new WP_Query();
        $query->found_posts = 3;
        $query->posts       = array(
            new WP_Post(
                array(
                    'ID'          => 11,
                    'post_title'  => 'Fish &amp; chips',
                    'post_status' => 'publish',
                )
            ),
            new WP_Post(
                array(
                    'ID'          => 12,
                    'post_title'  => 'Billing',
                    'post_status' => 'draft',
                )
            ),
        );

        $meta = array(
            11 => '0',
            12 => '',
        );

        Functions\when( 'get_post_meta' )->alias(
            function ( $id, $key ) use ( $meta ) {
                $this->assertSame( 'licensio_order_5', $key );

                return $meta[ $id ];
            }
        );
        Functions\when( 'get_edit_post_link' )->alias(
            function ( $id ) {
                return 11 === $id ? 'https://example.test/wp-admin/post.php?post=11&action=edit' : null;
            }
        );

        $controller = new Licensio_KB_Category_Order_REST_Controller_Double( $query );
        $result     = $controller->get_items( $this->request( 5, array(), array( 'per_page' => 2 ) ) );

        $this->assertSame( 'licensio_order', $controller->args['orderby'] );
        $this->assertSame( 'ASC', $controller->args['order'] );
        $this->assertSame( 'any', $controller->args['post_status'] );
        $this->assertSame( 2, $controller->args['posts_per_page'] );
        $this->assertFalse( $controller->args['tax_query'][0]['include_children'] );
        $this->assertSame( 5, $controller->args['tax_query'][0]['terms'] );
        $this->assertSame(
            array(
                'items' => array(
                    array(
                        'id'       => 11,
                        'title'    => 'Fish & chips',
                        'status'   => 'publish',
                        'order'    => 0,
                        'edit_url' => 'https://example.test/wp-admin/post.php?post=11&action=edit',
                    ),
                    array(
                        'id'       => 12,
                        'title'    => 'Billing',
                        'status'   => 'draft',
                        'order'    => null,
                        'edit_url' => '',
                    ),
                ),
                'total' => 3,
                'pages' => 2,
            ),
            $result
        );
    }

    /**
     * A page past the end still reports the real total.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_page_past_the_end_still_reports_the_total() {
        $this->stub_category( 5 );

        $empty              = new WP_Query();
        $count              = new WP_Query();
        $count->found_posts = 3;

        $controller = new Licensio_KB_Category_Order_REST_Controller_Double( $empty, $count );
        $result     = $controller->get_items(
            $this->request(
                5,
                array(),
                array(
                    'per_page' => 2,
                    'page'     => 4,
                )
            )
        );

        $this->assertCount( 2, $controller->calls );
        $this->assertSame( 4, $controller->calls[0]['paged'] );
        $this->assertSame( 1, $controller->calls[1]['paged'] );
        $this->assertSame( 'ids', $controller->calls[1]['fields'] );
        $this->assertSame(
            array(
                'items' => array(),
                'total' => 3,
                'pages' => 2,
            ),
            $result
        );
    }

    /**
     * An ID outside the category stops the save before anything is written.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_save_rejects_an_article_outside_the_category() {
        $this->stub_category( 5 );

        Functions\when( 'get_posts' )->justReturn( array( 11 ) );
        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\expect( 'update_post_meta' )->never();

        $result = ( new Licensio_KB_Category_Order_REST_Controller() )->update_order( $this->request( 5, array( 11, 99 ) ) );

        $this->assertInstanceOf( 'WP_Error', $result );
        $this->assertSame( 'licensio_kb_not_in_category', $result->get_error_code() );
        $this->assertSame( 400, $result->get_error_data()['status'] );
        $this->assertSame( array( 99 ), $result->get_error_data()['ids'] );
    }

    /**
     * An article the user may not edit stops the save before anything is written.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_save_requires_edit_rights_on_every_article() {
        $this->stub_category( 5 );

        Functions\when( 'get_posts' )->justReturn( array( 11, 12 ) );
        Functions\when( 'current_user_can' )->alias(
            function ( $cap, $id = 0 ) {
                return 'edit_post' === $cap && 11 === $id;
            }
        );
        Functions\expect( 'update_post_meta' )->never();

        $result = ( new Licensio_KB_Category_Order_REST_Controller() )->update_order( $this->request( 5, array( 11, 12 ) ) );

        $this->assertInstanceOf( 'WP_Error', $result );
        $this->assertSame( 'licensio_kb_cannot_edit', $result->get_error_code() );
        $this->assertSame( 403, $result->get_error_data()['status'] );
    }

    /**
     * A valid save writes 10, 20, 30 in the order given and answers it.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_save_writes_positions_in_steps_of_ten() {
        $this->stub_category( 5 );

        $checked = array();
        $written = array();

        $GLOBALS['wpdb'] = new Licensio_KB_Order_WPDB( // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
            function () {
                return array();
            }
        );

        Functions\when( 'get_posts' )->alias(
            function ( $args ) use ( &$checked ) {
                $checked = $args;

                return array( 13, 11, 12 );
            }
        );
        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\when( 'update_post_meta' )->alias(
            function ( $id, $key, $value ) use ( &$written ) {
                $written[] = array( $id, $key, $value );

                return true;
            }
        );

        $result = ( new Licensio_KB_Category_Order_REST_Controller() )->update_order( $this->request( 5, array( 12, 11, 13 ) ) );

        $this->assertSame( array( 'ids' => array( 12, 11, 13 ) ), $result );
        $this->assertSame( array( 12, 11, 13 ), $checked['post__in'] );
        $this->assertFalse( $checked['tax_query'][0]['include_children'] );
        $this->assertSame( 'any', $checked['post_status'] );
        $this->assertSame(
            array(
                array( 12, 'licensio_order_5', 10 ),
                array( 11, 'licensio_order_5', 20 ),
                array( 13, 'licensio_order_5', 30 ),
            ),
            $written
        );
    }

    /**
     * An empty list skips the article checks and closes the gaps in the existing positions.
     *
     * Nothing is listed first, so the positioned articles are renumbered
     * 10, 20… in their previous order; an article already at its new
     * position is not written again.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_empty_save_renumbers_the_existing_positions() {
        $this->stub_category( 5 );

        $written = array();

        $GLOBALS['wpdb'] = new Licensio_KB_Order_WPDB( // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
            function () {
                return array(
                    21 => array( 'licensio_order_5' => '10' ),
                    22 => array( 'licensio_order_5' => '40' ),
                    23 => array( 'licensio_order_5' => '70' ),
                );
            }
        );

        Functions\expect( 'get_posts' )->never();
        Functions\expect( 'current_user_can' )->never();
        Functions\when( 'update_post_meta' )->alias(
            function ( $id, $key, $value ) use ( &$written ) {
                $written[] = array( $id, $key, $value );

                return true;
            }
        );

        $result = ( new Licensio_KB_Category_Order_REST_Controller() )->update_order( $this->request( 5, array() ) );

        $this->assertSame( array( 'ids' => array() ), $result );
        $this->assertSame(
            array(
                array( 22, 'licensio_order_5', 20 ),
                array( 23, 'licensio_order_5', 30 ),
            ),
            $written
        );
    }

    /**
     * Make `get_term()` find one knowledge base category.
     *
     * @since 1.0.0
     *
     * @param int $term_id The category term ID.
     *
     * @return void
     */
    private function stub_category( $term_id ) {
        Functions\when( 'get_term' )->alias(
            function ( $id, $taxonomy ) use ( $term_id ) {
                if ( (int) $id !== $term_id || Licensio_KB_Post_Types::TAXONOMY_CATEGORY !== $taxonomy ) {
                    return null;
                }

                return new WP_Term(
                    array(
                        'term_id'  => $term_id,
                        'taxonomy' => $taxonomy,
                    )
                );
            }
        );
    }

    /**
     * A request for a category, with an ID list and extra parameters.
     *
     * @since 1.0.0
     *
     * @param int                  $term_id The category term ID.
     * @param int[]                $ids     The article IDs.
     * @param array<string, mixed> $extra   More parameters.
     *
     * @return WP_REST_Request The request.
     */
    private function request( $term_id, array $ids, array $extra = array() ) {
        return new WP_REST_Request(
            array(),
            '/licensio-kb/v1/categories/' . $term_id . '/articles',
            array_merge(
                array(
                    'id'       => $term_id,
                    'ids'      => $ids,
                    'page'     => 1,
                    'per_page' => 200,
                ),
                $extra
            )
        );
    }
}

/**
 * The order controller with its post query replaced.
 *
 * @since 1.0.0
 */
class Licensio_KB_Category_Order_REST_Controller_Double extends Licensio_KB_Category_Order_REST_Controller {

    /**
     * The queries the controller gets back, in turn; the last one repeats.
     *
     * @since 1.0.0
     * @var WP_Query[]
     */
    private $results;

    /**
     * The arguments of the first query.
     *
     * @since 1.0.0
     * @var array<string, mixed>
     */
    public $args = array();

    /**
     * The arguments of every query, in order.
     *
     * @since 1.0.0
     * @var array<int, array<string, mixed>>
     */
    public $calls = array();

    /**
     * Constructor.
     *
     * @since 1.0.0
     *
     * @param WP_Query ...$results The queries to answer with, in turn.
     */
    public function __construct( WP_Query ...$results ) {
        $this->results = $results;
    }

    /**
     * Record the arguments and answer the next prepared query.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $args The query arguments.
     *
     * @return WP_Query The prepared query.
     */
    protected function query( array $args ) {
        if ( empty( $this->calls ) ) {
            $this->args = $args;
        }

        $this->calls[] = $args;

        return $this->results[ min( count( $this->calls ), count( $this->results ) ) - 1 ];
    }
}
