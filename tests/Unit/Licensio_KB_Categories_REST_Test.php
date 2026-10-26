<?php
/**
 * The category tree routes behind the Categories app.
 *
 * The core term functions are backed by an in-memory store: terms with
 * parents, term meta and the articles assigned to each term. Every write is
 * recorded as an event, in order, so a test can assert both where things
 * ended up and the order they were written in.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;

/**
 * Drives Licensio_KB_Categories_REST_Controller.
 *
 * @since 1.0.0
 */
class Licensio_KB_Categories_REST_Test extends Licensio_KB_Test_Case {

    use Licensio_KB_Term_Assignments;

    /**
     * Every route registered during the test, keyed by route path.
     *
     * @since 1.0.0
     * @var array<string, array<int, array<string, mixed>>>
     */
    private $routes = array();

    /**
     * Term ID to its fields.
     *
     * @since 1.0.0
     * @var array<int, array<string, mixed>>
     */
    private $terms = array();

    /**
     * Term ID to meta key to value.
     *
     * @since 1.0.0
     * @var array<int, array<string, mixed>>
     */
    private $meta = array();

    /**
     * Every current_user_can() call, as its argument list.
     *
     * @since 1.0.0
     * @var array<int, array<int, mixed>>
     */
    private $cap_calls = array();

    /**
     * Capabilities the user lacks, as `cap` or `cap:id`.
     *
     * @since 1.0.0
     * @var string[]
     */
    private $denied = array();

    /**
     * What get_taxonomy() answers.
     *
     * @since 1.0.0
     * @var object|false
     */
    private $taxonomy = false;

    /**
     * Attachment ID to its sized image URL.
     *
     * @since 1.0.0
     * @var array<int, string>
     */
    private $image_urls = array();

    /**
     * Attachment ID to its file URL.
     *
     * @since 1.0.0
     * @var array<int, string>
     */
    private $file_urls = array();

    /**
     * The arguments of each wp_insert_term() and wp_update_term() call.
     *
     * @since 1.0.0
     * @var array<int, array<string, mixed>>
     */
    private $term_writes = array();

    /**
     * The controller under test.
     *
     * @since 1.0.0
     * @var Licensio_KB_Categories_REST_Controller
     */
    private $controller;

    /**
     * Back the core term functions with a small tree.
     *
     * The tree is Getting started (1) › Install (2) › Requirements (3), plus
     * Billing & invoices (4) › Refunds (5).
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        $this->routes      = array();
        $this->events      = array();
        $this->cap_calls   = array();
        $this->denied      = array();
        $this->image_urls  = array();
        $this->file_urls   = array();
        $this->term_writes = array();
        $this->meta        = array();
        $this->terms       = array(
            1 => $this->fields( 'Getting started', 0 ),
            2 => $this->fields( 'Install', 1 ),
            3 => $this->fields( 'Requirements', 2 ),
            4 => $this->fields( 'Billing &amp; invoices', 0 ),
            5 => $this->fields( 'Refunds', 4 ),
        );
        $this->assignments = array(
            1 => array( 101 ),
            2 => array( 102, 103 ),
            3 => array(),
            4 => array( 104 ),
            5 => array(),
        );
        $this->taxonomy    = (object) array(
            'cap' => (object) array(
                'manage_terms' => 'manage_kb_cats',
                'edit_terms'   => 'edit_kb_cats',
                'delete_terms' => 'delete_kb_cats',
            ),
        );

        $this->stub_core();

        $this->controller = new Licensio_KB_Categories_REST_Controller();
        $this->controller->register_routes();
    }

    /**
     * Drop the database double so it cannot leak into another suite.
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
     * Stub the core functions the controller and term operations call.
     *
     * @since 1.0.0
     *
     * @return void
     */
    private function stub_core() {
        Functions\when( 'register_rest_route' )->alias(
            function ( $rest_namespace, $route, $args ) {
                $this->assertSame( 'licensio-kb/v1', $rest_namespace );

                $this->routes[ $route ] = $args;

                return true;
            }
        );
        Functions\when( 'rest_ensure_response' )->returnArg();
        Functions\when( 'rest_authorization_required_code' )->justReturn( 403 );
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
        Functions\when( 'current_user_can' )->alias(
            function ( $cap, ...$args ) {
                $this->cap_calls[] = array_merge( array( $cap ), $args );

                if ( in_array( $cap, $this->denied, true ) ) {
                    return false;
                }

                return ! ( isset( $args[0] ) && in_array( $cap . ':' . $args[0], $this->denied, true ) );
            }
        );
        Functions\when( 'get_taxonomy' )->alias(
            function () {
                return $this->taxonomy;
            }
        );
        Functions\when( 'get_term' )->alias(
            function ( $term_id, $taxonomy ) {
                $this->assertSame( 'licensio_category', $taxonomy );

                return isset( $this->terms[ (int) $term_id ] ) ? $this->term( (int) $term_id ) : null;
            }
        );
        Functions\when( 'get_terms' )->alias(
            function ( $args ) {
                $this->assertSame( 'licensio_category', $args['taxonomy'] );
                $this->assertFalse( $args['hide_empty'] );

                if ( isset( $args['fields'] ) && 'id=>parent' === $args['fields'] ) {
                    return array_map(
                        function ( $fields ) {
                            return (string) $fields['parent'];
                        },
                        $this->terms
                    );
                }

                return array_map( array( $this, 'term' ), array_keys( $this->terms ) );
            }
        );
        Functions\when( 'get_term_meta' )->alias(
            function ( $term_id, $key ) {
                return isset( $this->meta[ (int) $term_id ][ $key ] ) ? $this->meta[ (int) $term_id ][ $key ] : '';
            }
        );
        Functions\when( 'update_term_meta' )->alias(
            function ( $term_id, $key, $value ) {
                $value                                = self::core_unslash( $value );
                $this->events[]                       = 'meta ' . $term_id . ' ' . $key . '=' . $value;
                $this->meta[ (int) $term_id ][ $key ] = $value;

                return true;
            }
        );
        Functions\when( 'wp_get_attachment_image_url' )->alias(
            function ( $attachment_id ) {
                return isset( $this->image_urls[ $attachment_id ] ) ? $this->image_urls[ $attachment_id ] : false;
            }
        );
        Functions\when( 'wp_get_attachment_url' )->alias(
            function ( $attachment_id ) {
                return isset( $this->file_urls[ $attachment_id ] ) ? $this->file_urls[ $attachment_id ] : false;
            }
        );
        Functions\when( '_prime_post_caches' )->justReturn( null );
        Functions\when( '_prime_term_caches' )->justReturn( null );
        Functions\when( 'wp_defer_term_counting' )->justReturn( false );
        Functions\when( 'get_term_by' )->alias(
            function ( $field, $value, $taxonomy ) {
                $this->assertSame( 'slug', $field );
                $this->assertSame( 'licensio_category', $taxonomy );

                foreach ( $this->terms as $term_id => $fields ) {
                    if ( $fields['slug'] === $value ) {
                        return $this->term( $term_id );
                    }
                }

                return false;
            }
        );
        Functions\when( 'wp_insert_term' )->alias(
            function ( $name, $taxonomy, $args ) {
                $this->assertSame( 'licensio_category', $taxonomy );

                $name                = self::core_unslash( $name );
                $args                = self::core_unslash( $args );
                $this->term_writes[] = $args;
                $term_id             = max( array_keys( $this->terms ) ) + 1;

                $this->terms[ $term_id ] = array_merge(
                    $this->fields( $name, isset( $args['parent'] ) ? $args['parent'] : 0 ),
                    isset( $args['description'] ) ? array( 'description' => $args['description'] ) : array()
                );
                $this->events[]          = 'insert ' . $term_id;

                return array(
                    'term_id'          => $term_id,
                    'term_taxonomy_id' => $term_id,
                );
            }
        );
        Functions\when( 'wp_update_term' )->alias(
            function ( $term_id, $taxonomy, $args ) {
                $this->assertSame( 'licensio_category', $taxonomy );

                $args                = self::core_unslash( $args );
                $this->term_writes[] = $args;

                foreach ( $args as $field => $value ) {
                    $this->terms[ (int) $term_id ][ $field ] = $value;
                }

                $this->events[] = isset( $args['parent'] ) ? 'update ' . $term_id . ' parent=' . $args['parent'] : 'update ' . $term_id;

                return array(
                    'term_id'          => (int) $term_id,
                    'term_taxonomy_id' => (int) $term_id,
                );
            }
        );
        Functions\when( 'wp_delete_term' )->alias(
            function ( $term_id ) {
                $this->events[] = 'delete ' . $term_id;
                unset( $this->terms[ (int) $term_id ], $this->assignments[ (int) $term_id ] );

                return true;
            }
        );
        $this->stub_term_assignments();
    }

    /**
     * The stored fields of a new term.
     *
     * @since 1.0.0
     *
     * @param string $name      The term name, as core stores it.
     * @param int    $parent_id The parent term ID.
     *
     * @return array<string, mixed> The fields.
     */
    private function fields( $name, $parent_id ) {
        return array(
            'name'        => $name,
            'slug'        => strtolower( str_replace( ' ', '-', $name ) ),
            'parent'      => $parent_id,
            'description' => '',
            'count'       => 0,
        );
    }

    /**
     * A term double built from the store.
     *
     * @since 1.0.0
     *
     * @param int $term_id The term ID.
     *
     * @return WP_Term The term.
     */
    public function term( $term_id ) {
        return new WP_Term(
            array_merge(
                $this->terms[ $term_id ],
                array(
                    'term_id'          => $term_id,
                    'term_taxonomy_id' => self::tt_id_of( $term_id ),
                    'taxonomy'         => 'licensio_category',
                )
            )
        );
    }

    /**
     * A request carrying the given parameters.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $params The request parameters.
     *
     * @return WP_REST_Request The request.
     */
    private function request( array $params ) {
        return new WP_REST_Request( array(), '/licensio-kb/v1/categories', $params );
    }

    /**
     * The parent of every category, as the controller reads it.
     *
     * @since 1.0.0
     *
     * @return array<int, int> Category ID to parent ID.
     */
    private function parents() {
        return array(
            1 => 0,
            2 => 1,
            3 => 2,
            4 => 0,
            5 => 4,
        );
    }

    /**
     * Assert a result is an error with the given code and status.
     *
     * @since 1.0.0
     *
     * @param string $code   The expected error code.
     * @param int    $status The expected HTTP status.
     * @param mixed  $result The result.
     *
     * @return void
     */
    private function assert_error( $code, $status, $result ) {
        $this->assertInstanceOf( 'WP_Error', $result );
        $this->assertSame( $code, $result->get_error_code() );
        $this->assertSame( array( 'status' => $status ), $result->get_error_data() );
    }

    /**
     * Forbid every write to terms, term meta and assignments.
     *
     * @since 1.0.0
     *
     * @return void
     */
    private function expect_no_writes() {
        Functions\expect( 'wp_insert_term' )->never();
        Functions\expect( 'wp_update_term' )->never();
        Functions\expect( 'wp_delete_term' )->never();
        Functions\expect( 'update_term_meta' )->never();
        $this->forbid_assignment_writes();
    }

    /**
     * Fail the test if any article assignment is written.
     *
     * @since 1.0.0
     *
     * @return void
     */
    private function forbid_assignment_writes() {
        $this->refuse_write = function () {
            $this->fail( 'No article assignment may be written.' );
        };
    }

    /**
     * The category list a handler answered.
     *
     * @since 1.0.0
     *
     * @param mixed $result The handler's result.
     *
     * @return array<int, array<string, mixed>> The categories.
     */
    private function as_list( $result ) {
        $this->assertIsArray( $result );

        return $result;
    }

    /**
     * The collection route lists and creates; only creating needs a name.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_collection_route_lists_and_creates() {
        $route = $this->routes['/categories'];

        $this->assertSame( 'GET', $route[0]['methods'] );
        $this->assertSame( array( $this->controller, 'get_items' ), $route[0]['callback'] );
        $this->assertSame( 'get_items_permissions_check', $route[0]['permission_callback'][1] );
        $this->assertSame( 'POST', $route[1]['methods'] );
        $this->assertSame( 'create_item', $route[1]['callback'][1] );
        $this->assertSame( 'create_item_permissions_check', $route[1]['permission_callback'][1] );
        $this->assertTrue( $route[1]['args']['name']['required'] );
        $this->assertSame( 1, $route[1]['args']['name']['minLength'] );
        $this->assertSame( 0, $route[1]['args']['parent']['minimum'] );
        $this->assertSame( array( 'Licensio_KB_Categories_REST_Controller', 'sanitize_icon_svg_arg' ), $route[1]['args']['icon_svg']['sanitize_callback'] );
        $this->assertSame( array( 'Licensio_KB_Categories_REST_Controller', 'sanitize_icon_data_arg' ), $route[1]['args']['icon_data']['sanitize_callback'] );
        $this->assertSame( array( 'Licensio_KB_Categories_REST_Controller', 'sanitize_image_arg' ), $route[1]['args']['image_id']['sanitize_callback'] );
        $this->assertSame( array( 'Licensio_KB_Categories_REST_Controller', 'sanitize_image_arg' ), $route[1]['args']['icon_id']['sanitize_callback'] );
    }

    /**
     * The single route updates and deletes, with the delete targets checked.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_single_route_updates_and_deletes() {
        $route = $this->routes['/categories/(?P<id>[\d]+)'];

        $this->assertSame( 'PATCH', $route[0]['methods'] );
        $this->assertSame( 'update_item', $route[0]['callback'][1] );
        $this->assertSame( 'update_item_permissions_check', $route[0]['permission_callback'][1] );
        $this->assertTrue( $route[0]['args']['id']['required'] );
        $this->assertSame( 1, $route[0]['args']['id']['minimum'] );
        $this->assertFalse( $route[0]['args']['name']['required'], 'An update may leave the name alone.' );

        $this->assertSame( 'DELETE', $route[1]['methods'] );
        $this->assertSame( 'delete_item', $route[1]['callback'][1] );
        $this->assertSame( 'delete_item_permissions_check', $route[1]['permission_callback'][1] );

        $args = $route[1]['args'];

        $this->assertTrue( $args['id']['required'] );
        $this->assertSame( 'none', $args['reassign_articles']['default'] );
        $this->assertSame( 'parent', $args['reassign_children']['default'] );

        foreach ( array( 'parent', 'none', '12' ) as $valid ) {
            $this->assertSame( 1, preg_match( '/' . $args['reassign_articles']['pattern'] . '/', $valid ) );
        }

        foreach ( array( '0', '012', 'children', '-3', '' ) as $invalid ) {
            $this->assertSame( 0, preg_match( '/' . $args['reassign_articles']['pattern'] . '/', $invalid ) );
        }

        $this->assertSame( 1, preg_match( '/' . $args['reassign_children']['pattern'] . '/', 'parent' ) );
        $this->assertSame( 1, preg_match( '/' . $args['reassign_children']['pattern'] . '/', '7' ) );
        $this->assertSame( 0, preg_match( '/' . $args['reassign_children']['pattern'] . '/', 'none' ), 'Children always need a home.' );
    }

    /**
     * The order and merge routes cap and de-duplicate their ID lists.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_order_and_merge_routes_cap_their_lists() {
        $order = $this->routes['/categories/order'][0];

        $this->assertSame( 'POST', $order['methods'] );
        $this->assertSame( 'update_order', $order['callback'][1] );
        $this->assertSame( 'update_order_permissions_check', $order['permission_callback'][1] );
        $this->assertTrue( $order['args']['parent']['required'] );
        $this->assertSame( 0, $order['args']['parent']['minimum'] );
        $this->assertTrue( $order['args']['ids']['required'] );
        $this->assertSame( Licensio_KB_Categories_REST_Controller::MAX_ORDER_IDS, $order['args']['ids']['maxItems'] );
        $this->assertTrue( $order['args']['ids']['uniqueItems'] );

        $merge = $this->routes['/categories/merge'][0];

        $this->assertSame( 'POST', $merge['methods'] );
        $this->assertSame( 'merge', $merge['callback'][1] );
        $this->assertSame( 'merge_permissions_check', $merge['permission_callback'][1] );
        $this->assertSame( 50, $merge['args']['sources']['maxItems'] );
        $this->assertSame( 1, $merge['args']['sources']['minItems'] );
        $this->assertTrue( $merge['args']['sources']['uniqueItems'] );
        $this->assertSame( 1, $merge['args']['sources']['items']['minimum'] );
        $this->assertTrue( $merge['args']['target']['required'] );
        $this->assertSame( 1, $merge['args']['target']['minimum'] );
    }

    /**
     * A user who cannot manage the knowledge base is refused everywhere.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_user_who_cannot_manage_is_refused() {
        $this->denied = array( 'manage_licensio_kb', 'manage_options' );

        $this->assert_error( 'licensio_forbidden', 403, $this->controller->get_items_permissions_check( $this->request( array() ) ) );
        $this->assert_error( 'licensio_forbidden', 403, $this->controller->update_item_permissions_check( $this->request( array( 'id' => 2 ) ) ) );
    }

    /**
     * Each route asks for the taxonomy capability that matches it.
     *
     * @since 1.0.0
     *
     * @param string               $method The permission callback.
     * @param array<string, mixed> $params The request parameters.
     * @param string               $cap    The taxonomy capability it needs.
     *
     * @dataProvider provide_taxonomy_caps
     *
     * @return void
     */
    public function test_each_route_needs_its_taxonomy_capability( $method, array $params, $cap ) {
        $this->assertTrue( $this->controller->$method( $this->request( $params ) ) );
        $this->assertContains( array( $cap ), $this->cap_calls );

        $this->denied = array( $cap );

        $this->assert_error( 'licensio_kb_category_forbidden', 403, $this->controller->$method( $this->request( $params ) ) );
    }

    /**
     * Permission callbacks and the taxonomy capability each one checks.
     *
     * @since 1.0.0
     *
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: string}> The cases.
     */
    public function provide_taxonomy_caps() {
        return array(
            'list'   => array( 'get_items_permissions_check', array(), 'manage_kb_cats' ),
            'create' => array( 'create_item_permissions_check', array(), 'edit_kb_cats' ),
            'update' => array( 'update_item_permissions_check', array( 'id' => 2 ), 'edit_kb_cats' ),
            'delete' => array( 'delete_item_permissions_check', array( 'id' => 2 ), 'delete_kb_cats' ),
            'order'  => array( 'update_order_permissions_check', array( 'ids' => array( 2 ) ), 'edit_kb_cats' ),
            'merge'  => array(
                'merge_permissions_check',
                array(
                    'sources' => array( 2 ),
                    'target'  => 4,
                ),
                'delete_kb_cats',
            ),
        );
    }

    /**
     * Without the taxonomy registered, nobody is allowed in.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_missing_taxonomy_refuses_everyone() {
        $this->taxonomy = false;

        $this->assert_error( 'licensio_kb_category_forbidden', 403, $this->controller->get_items_permissions_check( $this->request( array() ) ) );
    }

    /**
     * Updating and deleting check the per-category capability.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_per_category_capability_is_checked() {
        $this->denied = array( 'edit_term:2', 'delete_term:3' );

        $this->assert_error( 'licensio_kb_category_forbidden', 403, $this->controller->update_item_permissions_check( $this->request( array( 'id' => 2 ) ) ) );
        $this->assertTrue( $this->controller->update_item_permissions_check( $this->request( array( 'id' => 3 ) ) ) );
        $this->assert_error( 'licensio_kb_category_forbidden', 403, $this->controller->delete_item_permissions_check( $this->request( array( 'id' => 3 ) ) ) );
        $this->assertTrue( $this->controller->delete_item_permissions_check( $this->request( array( 'id' => 2 ) ) ) );
        $this->assert_error( 'licensio_kb_category_forbidden', 403, $this->controller->update_order_permissions_check( $this->request( array( 'ids' => array( 3, 2 ) ) ) ) );
    }

    /**
     * A category that does not exist answers 404, not forbidden.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_missing_category_answers_not_found() {
        $this->assert_error( 'licensio_kb_category_not_found', 404, $this->controller->update_item_permissions_check( $this->request( array( 'id' => 99 ) ) ) );
        $this->assert_error( 'licensio_kb_category_not_found', 404, $this->controller->delete_item_permissions_check( $this->request( array( 'id' => 99 ) ) ) );
        $this->assertNotContains( array( 'edit_term', 99 ), $this->cap_calls );
    }

    /**
     * A merge needs delete rights on every source and edit rights on the target.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_merge_checks_every_source_and_the_target() {
        $request = $this->request(
            array(
                'sources' => array( 2, 3 ),
                'target'  => 4,
            )
        );

        $this->assertTrue( $this->controller->merge_permissions_check( $request ) );
        $this->assertContains( array( 'delete_term', 2 ), $this->cap_calls );
        $this->assertContains( array( 'delete_term', 3 ), $this->cap_calls );
        $this->assertContains( array( 'edit_kb_cats' ), $this->cap_calls );
        $this->assertContains( array( 'edit_term', 4 ), $this->cap_calls );

        $this->denied = array( 'delete_term:3' );
        $this->assert_error( 'licensio_kb_category_forbidden', 403, $this->controller->merge_permissions_check( $request ) );

        $this->denied = array( 'edit_term:4' );
        $this->assert_error( 'licensio_kb_category_forbidden', 403, $this->controller->merge_permissions_check( $request ) );

        $this->denied = array();
        $request->set_param( 'target', 99 );
        $this->assert_error( 'licensio_kb_category_not_found', 404, $this->controller->merge_permissions_check( $request ) );
    }

    /**
     * The list answers every field: names decoded, descriptions as stored.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_list_answers_plain_text_and_every_field() {
        $this->terms[4]['description'] = 'Tom&#039;s &quot;tips&quot; &amp; tricks';
        $this->terms[4]['count']       = 3;
        $this->meta[4]                 = array(
            'licensio_order'    => '20',
            'licensio_icon'     => 'dashicons-money',
            'licensio_icon_id'  => '7',
            'licensio_image_id' => '8',
        );
        $this->image_urls              = array(
            7 => 'https://example.test/icon-150x150.png',
            8 => 'https://example.test/image-300x200.png',
        );

        $items   = $this->as_list( $this->controller->get_items( $this->request( array() ) ) );
        $billing = array_values(
            array_filter(
                $items,
                function ( $item ) {
                    return 4 === $item['id'];
                }
            )
        );

        $this->assertSame(
            array(
                'id'          => 4,
                'name'        => 'Billing & invoices',
                'slug'        => 'billing-&amp;-invoices',
                'parent'      => 0,
                'description' => 'Tom&#039;s &quot;tips&quot; &amp; tricks',
                'count'       => 3,
                'order'       => 20,
                'icon'        => 'dashicons-money',
                'icon_id'     => 7,
                'icon_svg'    => '',
                'icon_data'   => '',
                'image_id'    => 8,
                'icon_url'    => 'https://example.test/icon-150x150.png',
                'image_url'   => 'https://example.test/image-300x200.png',
            ),
            $billing[0]
        );
    }

    /**
     * A description with escaped and real markup survives a list and a rename unchanged.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_description_round_trips_through_a_rename_unchanged() {
        $stored                        = 'Use the &lt;head&gt; element, not <b>body</b>.';
        $this->terms[4]['description'] = $stored;

        $listed = array_column( $this->as_list( $this->controller->get_items( $this->request( array() ) ) ), null, 'id' );

        $this->assertSame( $stored, $listed[4]['description'], 'Answered exactly as stored.' );

        $item = $this->controller->update_item(
            $this->request(
                array(
                    'id'   => 4,
                    'name' => 'Billing',
                )
            )
        );

        $this->assertSame( array( array( 'name' => 'Billing' ) ), $this->term_writes, 'A rename does not write the description.' );
        $this->assertSame( $stored, $this->terms[4]['description'] );
        $this->assertSame( $stored, $item['description'] );
    }

    /**
     * Every argument that declares a limit has core validate it.
     *
     * Core skips schema validation for an argument with its own sanitiser,
     * so without this a negative parent would be turned positive and an
     * overlong name would reach the database.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_every_limited_argument_is_validated() {
        $limits  = array( 'minimum', 'maximum', 'minLength', 'maxLength', 'enum', 'pattern' );
        $checked = 0;

        foreach ( $this->routes as $route => $endpoints ) {
            foreach ( $endpoints as $endpoint ) {
                foreach ( $endpoint['args'] as $name => $arg ) {
                    if ( ! isset( $arg['sanitize_callback'] ) || ! array_intersect( $limits, array_keys( $arg ) ) ) {
                        continue;
                    }

                    $this->assertSame( 'rest_validate_request_arg', isset( $arg['validate_callback'] ) ? $arg['validate_callback'] : null, $route . ' ' . $name );
                    ++$checked;
                }
            }
        }

        $this->assertGreaterThanOrEqual( 8, $checked );
    }

    /**
     * Categories sort by order, then natural name ignoring case, then ID.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_list_sorts_by_order_then_name_then_id() {
        $this->terms = array(
            1 => $this->fields( 'b10', 0 ),
            2 => $this->fields( 'B2', 0 ),
            3 => $this->fields( 'a', 0 ),
            4 => $this->fields( 'Same', 0 ),
            5 => $this->fields( 'Same', 0 ),
            6 => $this->fields( 'Zed', 0 ),
        );
        $this->meta  = array(
            6 => array( 'licensio_order' => '-5' ),
            4 => array( 'licensio_order' => '10' ),
            5 => array( 'licensio_order' => '10' ),
        );

        $items = $this->as_list( $this->controller->get_items( $this->request( array() ) ) );

        $this->assertSame( array( 3, 2, 1, 6, 4, 5 ), array_column( $items, 'id' ), 'A negative order is stored as 0.' );
    }

    /**
     * An attachment with no image sizes falls back to its file URL.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_attachment_urls_fall_back_to_the_file() {
        $this->meta[1]   = array(
            'licensio_icon_id'  => 7,
            'licensio_image_id' => 0,
        );
        $this->file_urls = array( 7 => 'https://example.test/icon.svg' );

        $item = $this->controller->prepare_category( $this->term( 1 ) );

        $this->assertSame( 'https://example.test/icon.svg', $item['icon_url'] );
        $this->assertSame( '', $item['image_url'] );
        $this->assertSame( array(), $this->controller->prepare_category( null ) );
    }

    /**
     * A stored inline SVG is sanitised on the way out.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_inline_svg_is_sanitised_on_the_way_out() {
        $this->meta[1] = array( 'licensio_icon_svg' => '<svg viewBox="0 0 24 24"><path d="M4 4h16"/><script>alert(1)</script></svg>' );

        $item = $this->controller->prepare_category( $this->term( 1 ) );

        $this->assertSame( '<svg viewBox="0 0 24 24"><path d="M4 4h16"/></svg>', $item['icon_svg'] );
    }

    /**
     * A query error is answered as is.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_query_error_is_returned() {
        Functions\when( 'get_terms' )->justReturn( new WP_Error( 'invalid_taxonomy', 'Nope' ) );

        $this->assertSame( 'invalid_taxonomy', $this->controller->get_items( $this->request( array() ) )->get_error_code() );
    }

    /**
     * Descendants are found at every depth, nearest first, never the category itself.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_descendants_are_found_at_every_depth() {
        $this->assertSame( array( 2, 3 ), Licensio_KB_Categories_REST_Controller::descendants_of( 1, $this->parents() ) );
        $this->assertSame( array( 5 ), Licensio_KB_Categories_REST_Controller::descendants_of( 4, $this->parents() ) );
        $this->assertSame( array(), Licensio_KB_Categories_REST_Controller::descendants_of( 3, $this->parents() ) );
        $this->assertSame(
            array( 2 ),
            Licensio_KB_Categories_REST_Controller::descendants_of(
                1,
                array(
                    1 => 2,
                    2 => 1,
                )
            ),
            'Stored loops do not hang the walk.'
        );
    }

    /**
     * A move under the category itself or a descendant is a cycle.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_move_under_itself_or_a_descendant_is_a_cycle() {
        $parents = $this->parents();

        $this->assertTrue( Licensio_KB_Categories_REST_Controller::would_cycle( 1, 1, $parents ) );
        $this->assertTrue( Licensio_KB_Categories_REST_Controller::would_cycle( 1, 3, $parents ) );
        $this->assertFalse( Licensio_KB_Categories_REST_Controller::would_cycle( 3, 1, $parents ) );
        $this->assertFalse( Licensio_KB_Categories_REST_Controller::would_cycle( 1, 4, $parents ) );
        $this->assertFalse( Licensio_KB_Categories_REST_Controller::would_cycle( 1, 0, $parents ) );
    }

    /**
     * Order requests are checked before anything is written.
     *
     * @since 1.0.0
     *
     * @param int         $parent_id The parent.
     * @param int[]       $ids       The categories, in order.
     * @param string|null $code      The expected error code, null for none.
     *
     * @dataProvider provide_order_checks
     *
     * @return void
     */
    public function test_order_requests_are_checked( $parent_id, array $ids, $code ) {
        $result = Licensio_KB_Categories_REST_Controller::check_order( $parent_id, $ids, $this->parents() );

        if ( null === $code ) {
            $this->assertTrue( $result );
            return;
        }

        $this->assert_error( $code, 400, $result );
    }

    /**
     * Order requests and what check_order() makes of them.
     *
     * @since 1.0.0
     *
     * @return array<string, array{0: int, 1: int[], 2: string|null}> The cases.
     */
    public function provide_order_checks() {
        return array(
            'no ids'                   => array( 0, array(), 'licensio_kb_invalid_category' ),
            'unknown parent'           => array( 99, array( 1 ), 'licensio_kb_invalid_category' ),
            'unknown id'               => array( 0, array( 1, 99 ), 'licensio_kb_invalid_category' ),
            'parent is one of the ids' => array( 4, array( 4, 1 ), 'licensio_kb_category_cycle' ),
            'parent is a descendant'   => array( 3, array( 1 ), 'licensio_kb_category_cycle' ),
            'top level'                => array( 0, array( 4, 1 ), null ),
            'under another branch'     => array( 4, array( 5, 1 ), null ),
        );
    }

    /**
     * Merge requests are checked before anything is written.
     *
     * @since 1.0.0
     *
     * @param int[]       $sources The categories to merge away.
     * @param int         $target  The category that remains.
     * @param string|null $code    The expected error code, null for none.
     *
     * @dataProvider provide_merge_checks
     *
     * @return void
     */
    public function test_merge_requests_are_checked( array $sources, $target, $code ) {
        $result = Licensio_KB_Categories_REST_Controller::check_merge( $sources, $target, $this->parents() );

        if ( null === $code ) {
            $this->assertTrue( $result );
            return;
        }

        $this->assert_error( $code, 400, $result );
    }

    /**
     * Merge requests and what check_merge() makes of them.
     *
     * @since 1.0.0
     *
     * @return array<string, array{0: int[], 1: int, 2: string|null}> The cases.
     */
    public function provide_merge_checks() {
        return array(
            'no sources'                   => array( array(), 1, 'licensio_kb_invalid_category' ),
            'target among the sources'     => array( array( 1, 4 ), 4, 'licensio_kb_same_category' ),
            'unknown source'               => array( array( 1, 99 ), 4, 'licensio_kb_invalid_category' ),
            'unknown target'               => array( array( 1 ), 99, 'licensio_kb_invalid_category' ),
            'target inside a source'       => array( array( 4, 1 ), 3, 'licensio_kb_category_cycle' ),
            'sources into another branch'  => array( array( 2, 5 ), 4, null ),
            'a child into its grandparent' => array( array( 3 ), 1, null ),
        );
    }

    /**
     * Delete targets are worked out, or refused, before anything is written.
     *
     * @since 1.0.0
     *
     * @param int                       $id       The category being deleted.
     * @param int                       $parent_id Its parent.
     * @param string                    $articles Where its articles go.
     * @param string                    $children Where its children go.
     * @param array<string, int>|string $expected The plan, or the expected error code.
     *
     * @dataProvider provide_delete_plans
     *
     * @return void
     */
    public function test_delete_targets_are_planned( $id, $parent_id, $articles, $children, $expected ) {
        $plan = Licensio_KB_Categories_REST_Controller::plan_delete( $id, $parent_id, $articles, $children, $this->parents() );

        if ( is_string( $expected ) ) {
            $this->assert_error( $expected, 400, $plan );
            return;
        }

        $this->assertSame( $expected, $plan );
    }

    /**
     * Delete requests and the plan each one makes.
     *
     * @since 1.0.0
     *
     * @return array<string, array{0: int, 1: int, 2: string, 3: string, 4: array<string, int>|string}> The cases.
     */
    public function provide_delete_plans() {
        return array(
            'defaults on a child'              => array(
                2,
                1,
                'none',
                'parent',
                array(
                    'articles' => 0,
                    'children' => 1,
                ),
            ),
            'articles to the parent'           => array(
                2,
                1,
                'parent',
                'parent',
                array(
                    'articles' => 1,
                    'children' => 1,
                ),
            ),
            'articles to the parent of a root' => array( 1, 0, 'parent', 'parent', 'licensio_kb_no_parent' ),
            'both to chosen categories'        => array(
                2,
                1,
                '4',
                '5',
                array(
                    'articles' => 4,
                    'children' => 5,
                ),
            ),
            'articles onto itself'             => array( 2, 1, '2', 'parent', 'licensio_kb_same_category' ),
            'articles to an unknown category'  => array( 2, 1, '99', 'parent', 'licensio_kb_invalid_category' ),
            'children to an unknown category'  => array( 2, 1, 'none', '99', 'licensio_kb_invalid_category' ),
            'children onto itself'             => array( 2, 1, 'none', '2', 'licensio_kb_category_cycle' ),
            'children into a descendant'       => array( 1, 0, 'none', '3', 'licensio_kb_category_cycle' ),
            'children of a root to the top'    => array(
                1,
                0,
                'none',
                'parent',
                array(
                    'articles' => 0,
                    'children' => 0,
                ),
            ),
        );
    }

    /**
     * A new category is created with only the meta that was sent.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_category_is_created_with_the_meta_sent() {
        $this->image_urls = array( 7 => 'https://example.test/icon-150x150.png' );

        $response = $this->controller->create_item(
            $this->request(
                array(
                    'name'        => 'Payments',
                    'parent'      => 4,
                    'description' => 'About payments',
                    'icon'        => 'dashicons-money',
                    'icon_id'     => 7,
                    'icon_svg'    => null,
                )
            )
        );

        $this->assertInstanceOf( 'WP_REST_Response', $response );
        $this->assertSame( 201, $response->get_status() );
        $this->assertSame(
            array(
                'name'        => 'Payments',
                'description' => 'About payments',
                'parent'      => 4,
            ),
            $this->term_writes[0]
        );
        $this->assertSame(
            array( 'insert 6', 'meta 6 licensio_icon=dashicons-money', 'meta 6 licensio_icon_id=7' ),
            $this->events,
            'A null or absent field writes no meta.'
        );

        $data = $response->get_data();

        $this->assertSame( 6, $data['id'] );
        $this->assertSame( 'Payments', $data['name'] );
        $this->assertSame( 4, $data['parent'] );
        $this->assertSame( 'dashicons-money', $data['icon'] );
        $this->assertSame( 7, $data['icon_id'] );
        $this->assertSame( 'https://example.test/icon-150x150.png', $data['icon_url'] );
    }

    /**
     * A new category under a parent that does not exist is refused.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_new_category_under_an_unknown_parent_is_refused() {
        $this->expect_no_writes();

        $this->assert_error(
            'licensio_kb_invalid_category',
            400,
            $this->controller->create_item(
                $this->request(
                    array(
                        'name'   => 'Orphan',
                        'parent' => 99,
                    )
                )
            )
        );
    }

    /**
     * A name clash core reports is a 409, as in the Tags routes.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_core_name_clash_is_a_conflict() {
        Functions\when( 'wp_insert_term' )->justReturn( new WP_Error( 'term_exists', 'A term with the name provided already exists.', 4 ) );
        Functions\expect( 'update_term_meta' )->never();

        $result = $this->controller->create_item( $this->request( array( 'name' => 'Billing' ) ) );

        $this->assert_error( 'term_exists', 409, $result );
        $this->assertSame( 'A term with the name provided already exists.', $result->get_error_message() );
    }

    /**
     * Any other core insert error is answered as a 400 with the same code.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_another_core_insert_error_becomes_a_bad_request() {
        Functions\when( 'wp_insert_term' )->justReturn( new WP_Error( 'empty_term_name', 'A name is required for this term.' ) );

        $this->assert_error( 'empty_term_name', 400, $this->controller->create_item( $this->request( array( 'name' => 'Billing' ) ) ) );
    }

    /**
     * An edit to a slug another category uses is refused before anything is written.
     *
     * The edit also moves the category, where core would quietly pick
     * another slug.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_edit_to_a_taken_slug_is_a_conflict() {
        $this->expect_no_writes();

        $result = $this->controller->update_item(
            $this->request(
                array(
                    'id'     => 2,
                    'slug'   => 'refunds',
                    'parent' => 4,
                )
            )
        );

        $this->assertInstanceOf( 'WP_Error', $result );
        $this->assertSame( 'licensio_kb_slug_taken', $result->get_error_code() );
        $this->assertSame( 409, $result->get_error_data()['status'] );
        $this->assertSame( 'Another category already uses the slug “refunds”. Choose a different slug.', $result->get_error_data()['params']['slug'] );
    }

    /**
     * A category keeps its own slug on an edit.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_edit_keeping_its_own_slug_is_saved() {
        $result = $this->controller->update_item(
            $this->request(
                array(
                    'id'   => 2,
                    'slug' => 'install',
                    'name' => 'Installing',
                )
            )
        );

        $this->assertSame( 'install', $result['slug'] );
        $this->assertSame( 'Installing', $result['name'] );
    }

    /**
     * An update writes only the fields that were sent.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_update_writes_only_the_fields_sent() {
        $item = $this->controller->update_item(
            $this->request(
                array(
                    'id'   => 3,
                    'name' => 'Reqs',
                )
            )
        );

        $this->assertSame( array( array( 'name' => 'Reqs' ) ), $this->term_writes );
        $this->assertSame( array( 'update 3' ), $this->events );
        $this->assertSame( 'Reqs', $item['name'] );
        $this->assertSame( 2, $item['parent'] );
    }

    /**
     * An update of meta alone leaves the term itself untouched.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_meta_only_update_leaves_the_term_alone() {
        Functions\expect( 'wp_update_term' )->never();

        $this->meta[3] = array( 'licensio_image_id' => 8 );

        $item = $this->controller->update_item(
            $this->request(
                array(
                    'id'       => 3,
                    'image_id' => 0,
                )
            )
        );

        $this->assertSame( array( 'meta 3 licensio_image_id=0' ), $this->events );
        $this->assertSame( 0, $item['image_id'] );
    }

    /**
     * Saving one icon source clears the others.
     *
     * The app sends every icon field, empty for the sources not picked, so no
     * hidden icon is left to win by precedence.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_saving_one_icon_source_clears_the_others() {
        $png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

        $this->meta[3] = array(
            'licensio_icon'     => 'dashicons-money',
            'licensio_icon_id'  => 7,
            'licensio_icon_svg' => '<svg viewBox="0 0 24 24"><path d="M4 4h16"/></svg>',
        );

        $item = $this->controller->update_item(
            $this->request(
                array(
                    'id'        => 3,
                    'icon'      => '',
                    'icon_id'   => 0,
                    'icon_svg'  => '',
                    'icon_data' => Licensio_KB_Categories_REST_Controller::sanitize_icon_data_arg( $png ),
                )
            )
        );

        $this->assertSame(
            array(
                'meta 3 licensio_icon=',
                'meta 3 licensio_icon_id=0',
                'meta 3 licensio_icon_svg=',
                'meta 3 licensio_icon_data=' . $png,
            ),
            $this->events
        );
        $this->assertSame( '', $item['icon'] );
        $this->assertSame( 0, $item['icon_id'] );
        $this->assertSame( '', $item['icon_svg'] );
        $this->assertSame( $png, $item['icon_data'] );
    }

    /**
     * A Base64 icon that is not an allowed image is refused, not dropped.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_bad_base64_icon_is_refused() {
        Functions\when( 'size_format' )->justReturn( '64 KB' );

        $png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
        $svg = 'data:image/svg+xml;base64,PHN2ZyB2aWV3Qm94PSIwIDAgMjQgMjQiPjxwYXRoIGQ9Ik00IDRoMTYiLz48L3N2Zz4=';

        $this->assertSame( '', Licensio_KB_Categories_REST_Controller::sanitize_icon_data_arg( '' ), 'Empty clears the icon.' );
        $this->assertSame( $png, Licensio_KB_Categories_REST_Controller::sanitize_icon_data_arg( $png ) );
        $this->assert_error( 'licensio_kb_invalid_icon', 400, Licensio_KB_Categories_REST_Controller::sanitize_icon_data_arg( $svg ) );
        $this->assert_error( 'licensio_kb_invalid_icon', 400, Licensio_KB_Categories_REST_Controller::sanitize_icon_data_arg( 'data:image/png;base64,PHNjcmlwdD4=' ) );
        $this->assert_error( 'licensio_kb_invalid_icon', 400, Licensio_KB_Categories_REST_Controller::sanitize_icon_data_arg( 'https://example.test/icon.png' ) );
        $this->assert_error( 'licensio_kb_invalid_icon', 400, Licensio_KB_Categories_REST_Controller::sanitize_icon_data_arg( array( $png ) ) );
        $this->assert_error( 'licensio_kb_invalid_icon', 400, Licensio_KB_Categories_REST_Controller::sanitize_icon_data_arg( null ) );

        $this->assertSame(
            '<svg viewBox="0 0 24 24"><path d="M4 4h16"/></svg>',
            Licensio_KB_Categories_REST_Controller::sanitize_icon_svg_arg( $svg ),
            'A Base64 SVG is decoded and sanitised.'
        );
        $this->assertSame( '', Licensio_KB_Categories_REST_Controller::sanitize_icon_svg_arg( '<p>not svg</p>' ), 'Markup keeps the quiet sanitiser.' );
        $this->assert_error( 'licensio_kb_invalid_icon', 400, Licensio_KB_Categories_REST_Controller::sanitize_icon_svg_arg( 'data:image/svg+xml;base64,PHNjcmlwdD4=' ) );
        $this->assert_error( 'licensio_kb_invalid_icon', 400, Licensio_KB_Categories_REST_Controller::sanitize_icon_svg_arg( $png ) );
        $this->assert_error( 'licensio_kb_invalid_icon', 400, Licensio_KB_Categories_REST_Controller::sanitize_icon_svg_arg( array( $svg ) ) );
        $this->assertSame( '', Licensio_KB_Categories_REST_Controller::sanitize_icon_svg_arg( '' ), 'Empty clears the icon.' );

        $padded = substr( $svg, 0, 30 ) . str_repeat( "\n", 200000 ) . substr( $svg, 30 );

        $this->assert_error( 'licensio_kb_invalid_icon', 400, Licensio_KB_Categories_REST_Controller::sanitize_icon_svg_arg( $padded ) );
    }

    /**
     * A category can move under another branch.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_update_moves_a_category() {
        $item = $this->controller->update_item(
            $this->request(
                array(
                    'id'     => 1,
                    'parent' => 4,
                )
            )
        );

        $this->assertSame( array( 'update 1 parent=4' ), $this->events );
        $this->assertSame( 4, $item['parent'] );
    }

    /**
     * An update that would put a category inside itself writes nothing.
     *
     * @since 1.0.0
     *
     * @param int    $parent_id The parent asked for.
     * @param string $code      The expected error code.
     *
     * @dataProvider provide_bad_parents
     *
     * @return void
     */
    public function test_an_update_to_a_bad_parent_writes_nothing( $parent_id, $code ) {
        $this->expect_no_writes();

        $result = $this->controller->update_item(
            $this->request(
                array(
                    'id'     => 1,
                    'parent' => $parent_id,
                    'icon'   => 'dashicons-book',
                )
            )
        );

        $this->assert_error( $code, 400, $result );
    }

    /**
     * Parents an update must refuse.
     *
     * @since 1.0.0
     *
     * @return array<string, array{0: int, 1: string}> The cases.
     */
    public function provide_bad_parents() {
        return array(
            'itself'       => array( 1, 'licensio_kb_category_cycle' ),
            'a grandchild' => array( 3, 'licensio_kb_category_cycle' ),
            'unknown'      => array( 99, 'licensio_kb_invalid_category' ),
        );
    }

    /**
     * Updating a category that does not exist answers 404.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_updating_a_missing_category_answers_not_found() {
        $this->expect_no_writes();

        $this->assert_error( 'licensio_kb_category_not_found', 404, $this->controller->update_item( $this->request( array( 'id' => 99 ) ) ) );
    }

    /**
     * By default a delete leaves the articles and moves the children up.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_default_delete_moves_the_children_up_first() {
        $result = $this->controller->delete_item(
            $this->request(
                array(
                    'id'                => 1,
                    'reassign_articles' => 'none',
                    'reassign_children' => 'parent',
                )
            )
        );

        $this->assertSame(
            array(
                'deleted'    => 1,
                'moved'      => 0,
                'reparented' => array( 2 ),
            ),
            $result
        );
        $this->assertSame( array( 'update 2 parent=0', 'delete 1' ), $this->events );
    }

    /**
     * Deleting a child moves its articles and children to its parent first.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_delete_moves_articles_and_children_to_the_parent() {
        $result = $this->controller->delete_item(
            $this->request(
                array(
                    'id'                => 2,
                    'reassign_articles' => 'parent',
                    'reassign_children' => 'parent',
                )
            )
        );

        $this->assertSame(
            array(
                'deleted'    => 2,
                'moved'      => 2,
                'reparented' => array( 3 ),
            ),
            $result
        );
        $this->assertSame(
            array(
                'assign 102,103 to 1',
                'unassign 102,103 from 2',
                'update 3 parent=1',
                'delete 2',
            ),
            $this->events
        );
        $this->assertSame( array( 101, 102, 103 ), $this->assignments[1] );
        $this->assertSame( 1, $this->terms[3]['parent'] );
    }

    /**
     * A delete can send articles and children to chosen categories.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_delete_moves_to_chosen_categories() {
        $result = $this->controller->delete_item(
            $this->request(
                array(
                    'id'                => 4,
                    'reassign_articles' => '3',
                    'reassign_children' => '1',
                )
            )
        );

        $this->assertSame(
            array(
                'deleted'    => 4,
                'moved'      => 1,
                'reparented' => array( 5 ),
            ),
            $result
        );
        $this->assertSame( array( 104 ), $this->assignments[3] );
        $this->assertSame( array( 'assign 104 to 3', 'unassign 104 from 4', 'update 5 parent=1', 'delete 4' ), $this->events );
    }

    /**
     * A delete with an unusable target writes nothing.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_delete_with_a_bad_target_writes_nothing() {
        $this->expect_no_writes();

        $this->assert_error(
            'licensio_kb_no_parent',
            400,
            $this->controller->delete_item(
                $this->request(
                    array(
                        'id'                => 1,
                        'reassign_articles' => 'parent',
                        'reassign_children' => 'parent',
                    )
                )
            )
        );
        $this->assert_error(
            'licensio_kb_category_cycle',
            400,
            $this->controller->delete_item(
                $this->request(
                    array(
                        'id'                => 1,
                        'reassign_articles' => 'none',
                        'reassign_children' => '3',
                    )
                )
            )
        );
        $this->assert_error( 'licensio_kb_category_not_found', 404, $this->controller->delete_item( $this->request( array( 'id' => 99 ) ) ) );
    }

    /**
     * A delete core refuses is reported, not answered as a success.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_refused_delete_is_reported() {
        Functions\when( 'wp_delete_term' )->justReturn( false );

        $result = $this->controller->delete_item(
            $this->request(
                array(
                    'id'                => 3,
                    'reassign_articles' => 'none',
                    'reassign_children' => 'parent',
                )
            )
        );

        $this->assert_error( 'licensio_kb_category_not_deleted', 500, $result );
    }

    /**
     * Ordering moves each category under the parent and numbers them in steps of ten.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_ordering_places_and_numbers_the_categories() {
        $result = $this->controller->update_order(
            $this->request(
                array(
                    'parent' => 1,
                    'ids'    => array( 5, 3, 2 ),
                )
            )
        );
        $items  = $this->as_list( $result );

        $this->assertSame(
            array(
                'update 5 parent=1',
                'update 3 parent=1',
                'meta 5 licensio_order=10',
                'meta 3 licensio_order=20',
                'meta 2 licensio_order=30',
            ),
            $this->events,
            'Every parent change is written before any position, and a category already under the parent is not rewritten.'
        );
        $this->assertCount( 5, $items, 'The whole list is answered.' );
        $this->assertSame( array( 4, 1, 5, 3, 2 ), array_column( $items, 'id' ) );
    }

    /**
     * An order that would put a category inside itself writes nothing.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_order_with_a_cycle_writes_nothing() {
        $this->expect_no_writes();

        $result = $this->controller->update_order(
            $this->request(
                array(
                    'parent' => 3,
                    'ids'    => array( 4, 1 ),
                )
            )
        );

        $this->assert_error( 'licensio_kb_category_cycle', 400, $result );
    }

    /**
     * A merge moves the sources' children to the target before deleting anything.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_merge_reparents_children_then_merges() {
        Actions\expectDone( 'licensio_kb_terms_merged' )
            ->once()
            ->with( array( 2, 4 ), 1, 'licensio_category' )
            ->whenHappen(
                function () {
                    $this->events[] = 'merged';
                }
            );

        $result = $this->controller->merge(
            $this->request(
                array(
                    'sources' => array( 2, 4 ),
                    'target'  => 1,
                )
            )
        );

        $this->assertSame(
            array(
                'moved'      => 3,
                'deleted'    => array( 2, 4 ),
                'reparented' => array( 3, 5 ),
            ),
            $result
        );
        $this->assertSame(
            array(
                'update 3 parent=1',
                'update 5 parent=1',
                'assign 102,103 to 1',
                'unassign 102,103 from 2',
                'assign 104 to 1',
                'unassign 104 from 4',
                'merged',
                'delete 2',
                'delete 4',
            ),
            $this->events
        );
        $this->assertSame( array( 101, 102, 103, 104 ), $this->assignments[1] );
    }

    /**
     * A source that is a child of another source is merged, not re-parented.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_source_inside_another_source_is_not_reparented() {
        $result = $this->controller->merge(
            $this->request(
                array(
                    'sources' => array( 1, 2 ),
                    'target'  => 4,
                )
            )
        );

        $this->assertSame( array( 3 ), $result['reparented'] );
        $this->assertSame( array( 1, 2 ), $result['deleted'] );
        $this->assertSame( 4, $this->terms[3]['parent'] );
    }

    /**
     * A merge into one of the sources' own descendants writes nothing.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_merge_with_a_cycle_writes_nothing() {
        $this->expect_no_writes();
        Actions\expectDone( 'licensio_kb_terms_merged' )->never();

        $result = $this->controller->merge(
            $this->request(
                array(
                    'sources' => array( 1 ),
                    'target'  => 3,
                )
            )
        );

        $this->assert_error( 'licensio_kb_category_cycle', 400, $result );
    }

    /**
     * The slug sanitiser takes the extra REST arguments without misreading them.
     *
     * The REST server calls a sanitize callback with the request and the
     * parameter name as well. An empty slug must stay an empty string, so the
     * term gets a slug made from its name.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_slug_sanitiser_ignores_the_extra_rest_arguments() {
        $callback = $this->routes['/categories'][1]['args']['slug']['sanitize_callback'];
        $request  = new WP_REST_Request( array(), '/licensio-kb/v1/categories', array() );

        $this->assertSame( '', call_user_func( $callback, '', $request, 'slug' ) );
        $this->assertSame( 'getting-started', call_user_func( $callback, 'Getting Started', $request, 'slug' ) );
        $this->assertSame( '', call_user_func( $callback, array( 'x' ), $request, 'slug' ) );
    }

    /**
     * Children of the parent left out of an order follow the listed ones, in tree order.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_order_renumbers_the_unlisted_children_after_the_listed_ones() {
        $this->terms[6] = $this->fields( 'Accounts', 0 );
        $this->terms[7] = $this->fields( 'Zebra', 0 );
        $this->meta[7]  = array( 'licensio_order' => '5' );
        $this->meta[1]  = array( 'licensio_order' => '40' );

        $items = $this->as_list(
            $this->controller->update_order(
                $this->request(
                    array(
                        'parent' => 0,
                        'ids'    => array( 4 ),
                    )
                )
            )
        );

        $this->assertSame(
            array(
                'meta 4 licensio_order=10',
                'meta 6 licensio_order=20',
                'meta 7 licensio_order=30',
                'meta 1 licensio_order=40',
            ),
            $this->events,
            'Unlisted siblings keep their relative order (order, then name) after the listed one.'
        );
        $this->assertSame(
            array( 4, 6, 7, 1 ),
            array_values(
                array_column(
                    array_filter(
                        $items,
                        function ( $item ) {
                            return 0 === $item['parent'];
                        }
                    ),
                    'id'
                )
            )
        );
    }

    /**
     * A failed parent change puts back the moves already made and writes no position.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_failed_order_undoes_its_parent_changes() {
        Functions\when( 'wp_update_term' )->alias(
            function ( $term_id, $taxonomy, $args ) {
                unset( $taxonomy );

                if ( 3 === (int) $term_id && 1 === $args['parent'] ) {
                    return new WP_Error( 'db_update_error', 'Could not update the term.' );
                }

                $this->terms[ (int) $term_id ]['parent'] = $args['parent'];
                $this->events[]                          = 'update ' . $term_id . ' parent=' . $args['parent'];

                return array(
                    'term_id'          => (int) $term_id,
                    'term_taxonomy_id' => (int) $term_id,
                );
            }
        );
        Functions\expect( 'update_term_meta' )->never();

        $result = $this->controller->update_order(
            $this->request(
                array(
                    'parent' => 1,
                    'ids'    => array( 5, 3, 2 ),
                )
            )
        );

        $this->assert_error( 'db_update_error', 400, $result );
        $this->assertSame( array( 'update 5 parent=1', 'update 5 parent=4' ), $this->events );
        $this->assertSame( 4, $this->terms[5]['parent'] );
    }

    /**
     * Make wp_update_term() fail when it moves one category under one parent.
     *
     * Moves back to an old parent still succeed, so the undo can be checked.
     *
     * @since 1.0.0
     *
     * @param int $failing_id     The category whose move fails.
     * @param int $failing_parent The parent that move is refused for.
     *
     * @return void
     */
    private function fail_parent_change( $failing_id, $failing_parent ) {
        Functions\when( 'wp_update_term' )->alias(
            function ( $term_id, $taxonomy, $args ) use ( $failing_id, $failing_parent ) {
                unset( $taxonomy );

                if ( $failing_id === (int) $term_id && $failing_parent === $args['parent'] ) {
                    return new WP_Error( 'db_update_error', 'Could not update the term.' );
                }

                $this->terms[ (int) $term_id ]['parent'] = $args['parent'];
                $this->events[]                          = 'update ' . $term_id . ' parent=' . $args['parent'];

                return array(
                    'term_id'          => (int) $term_id,
                    'term_taxonomy_id' => (int) $term_id,
                );
            }
        );
    }

    /**
     * A delete whose child move fails puts back the children already moved and keeps the category.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_failed_delete_undoes_its_child_moves() {
        $this->terms[6]       = $this->fields( 'Updates', 1 );
        $this->assignments[6] = array();
        $this->fail_parent_change( 6, 4 );
        Functions\expect( 'wp_delete_term' )->never();

        $result = $this->controller->delete_item(
            $this->request(
                array(
                    'id'                => 1,
                    'reassign_articles' => 'none',
                    'reassign_children' => '4',
                )
            )
        );

        $this->assert_error( 'db_update_error', 400, $result );
        $this->assertSame( array( 'update 2 parent=4', 'update 2 parent=1' ), $this->events );
        $this->assertSame( 1, $this->terms[2]['parent'] );
        $this->assertSame( 1, $this->terms[6]['parent'] );
    }

    /**
     * A merge whose child move fails puts back the children already moved and merges nothing.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_failed_merge_undoes_its_child_moves() {
        $this->fail_parent_change( 5, 1 );
        Actions\expectDone( 'licensio_kb_terms_merged' )->never();
        $this->forbid_assignment_writes();
        Functions\expect( 'wp_delete_term' )->never();

        $result = $this->controller->merge(
            $this->request(
                array(
                    'sources' => array( 2, 4 ),
                    'target'  => 1,
                )
            )
        );

        $this->assert_error( 'db_update_error', 400, $result );
        $this->assertSame( array( 'update 3 parent=1', 'update 3 parent=2' ), $this->events );
        $this->assertSame( 2, $this->terms[3]['parent'] );
        $this->assertSame( 4, $this->terms[5]['parent'] );
    }

    /**
     * Ordering needs edit rights on the parent the categories go under.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_order_checks_the_parent() {
        $request = $this->request(
            array(
                'parent' => 4,
                'ids'    => array( 2 ),
            )
        );

        $this->assertTrue( $this->controller->update_order_permissions_check( $request ) );
        $this->assertContains( array( 'edit_term', 4 ), $this->cap_calls );

        $this->denied = array( 'edit_term:4' );
        $this->assert_error( 'licensio_kb_category_forbidden', 403, $this->controller->update_order_permissions_check( $request ) );

        $this->denied = array();
        $request->set_param( 'parent', 99 );
        $this->assertTrue( $this->controller->update_order_permissions_check( $request ), 'A missing parent is left to the handler, which answers 400.' );
    }

    /**
     * Ordering needs edit rights on the children it renumbers without being told to.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_order_checks_the_unlisted_children_it_renumbers() {
        $request = $this->request(
            array(
                'parent' => 0,
                'ids'    => array( 4 ),
            )
        );

        $this->assertTrue( $this->controller->update_order_permissions_check( $request ) );
        $this->assertContains( array( 'edit_term', 1 ), $this->cap_calls );

        $this->denied = array( 'edit_term:1' );
        $this->assert_error( 'licensio_kb_category_forbidden', 403, $this->controller->update_order_permissions_check( $request ) );

        $request->set_param( 'ids', array( 4, 1 ) );
        $this->assert_error( 'licensio_kb_category_forbidden', 403, $this->controller->update_order_permissions_check( $request ) );

        $this->denied = array( 'edit_term:5' );
        $request->set_param( 'parent', 4 );
        $request->set_param( 'ids', array( 2 ) );
        $this->assert_error( 'licensio_kb_category_forbidden', 403, $this->controller->update_order_permissions_check( $request ) );
    }

    /**
     * A delete needs edit rights on the chosen targets and on each child that moves.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_delete_checks_its_targets_and_children() {
        $request = $this->request(
            array(
                'id'                => 4,
                'reassign_articles' => '3',
                'reassign_children' => '1',
            )
        );

        $this->assertTrue( $this->controller->delete_item_permissions_check( $request ) );
        $this->assertContains( array( 'edit_term', 3 ), $this->cap_calls );
        $this->assertContains( array( 'edit_term', 1 ), $this->cap_calls );
        $this->assertContains( array( 'edit_term', 5 ), $this->cap_calls );

        foreach ( array( 'edit_term:3', 'edit_term:1', 'edit_term:5' ) as $denied ) {
            $this->denied = array( $denied );
            $this->assert_error( 'licensio_kb_category_forbidden', 403, $this->controller->delete_item_permissions_check( $request ) );
        }

        $this->denied = array();
        $request->set_param( 'reassign_articles', '99' );
        $this->assertTrue( $this->controller->delete_item_permissions_check( $request ), 'A missing target is left to the handler, which answers 400.' );
    }

    /**
     * A merge needs edit rights on each child of a source it moves.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_merge_checks_the_children_it_moves() {
        $request = $this->request(
            array(
                'sources' => array( 2 ),
                'target'  => 4,
            )
        );

        $this->assertTrue( $this->controller->merge_permissions_check( $request ) );
        $this->assertContains( array( 'edit_term', 3 ), $this->cap_calls );

        $this->denied = array( 'edit_term:3' );
        $this->assert_error( 'licensio_kb_category_forbidden', 403, $this->controller->merge_permissions_check( $request ) );
    }

    /**
     * The icon and image must be readable image attachments.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_image_sanitiser_needs_a_readable_image() {
        $types = array(
            7  => 'attachment',
            8  => 'attachment',
            9  => 'attachment',
            10 => 'post',
            11 => 'attachment',
        );
        $mimes = array(
            7  => 'image/png',
            8  => 'image/svg+xml',
            9  => 'application/pdf',
            10 => '',
            11 => 'image/jpeg',
        );

        Functions\when( 'get_post_type' )->alias(
            function ( $id ) use ( $types ) {
                return isset( $types[ $id ] ) ? $types[ $id ] : false;
            }
        );
        Functions\when( 'get_post_mime_type' )->alias(
            function ( $id ) use ( $mimes ) {
                return isset( $mimes[ $id ] ) ? $mimes[ $id ] : false;
            }
        );
        $this->denied = array( 'read_post:11' );

        $this->assertSame( 0, Licensio_KB_Categories_REST_Controller::sanitize_image_arg( 0 ) );
        $this->assertSame( 0, Licensio_KB_Categories_REST_Controller::sanitize_image_arg( array( 7 ) ) );
        $this->assertSame( 7, Licensio_KB_Categories_REST_Controller::sanitize_image_arg( '7' ) );
        $this->assertSame( 8, Licensio_KB_Categories_REST_Controller::sanitize_image_arg( 8 ), 'An uploaded SVG icon is an image.' );
        $this->assertContains( array( 'read_post', 7 ), $this->cap_calls );

        foreach ( array( 9, 10, 11, 404 ) as $id ) {
            $this->assert_error( 'licensio_kb_invalid_image', 400, Licensio_KB_Categories_REST_Controller::sanitize_image_arg( $id ) );
        }
    }

    /**
     * A delete whose final step fails puts the articles and children back.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_failed_final_delete_puts_everything_back() {
        Functions\when( 'wp_delete_term' )->justReturn( false );

        $result = $this->controller->delete_item(
            $this->request(
                array(
                    'id'                => 1,
                    'reassign_articles' => '4',
                    'reassign_children' => '4',
                )
            )
        );

        $this->assert_error( 'licensio_kb_category_not_deleted', 500, $result );
        $this->assertSame( array( 101 ), $this->assignments[1] );
        $this->assertSame( array( 104 ), $this->assignments[4] );
        $this->assertSame( 1, $this->terms[2]['parent'] );
    }

    /**
     * A delete whose child move fails puts the articles back too.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_failed_child_move_puts_the_articles_back() {
        $this->fail_parent_change( 2, 4 );
        Functions\expect( 'wp_delete_term' )->never();

        $result = $this->controller->delete_item(
            $this->request(
                array(
                    'id'                => 1,
                    'reassign_articles' => '4',
                    'reassign_children' => '4',
                )
            )
        );

        $this->assert_error( 'db_update_error', 400, $result );
        $this->assertSame( array( 101 ), $this->assignments[1] );
        $this->assertSame( array( 104 ), $this->assignments[4] );
    }

    /**
     * A merge whose article move fails puts the children back and merges nothing.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_failed_article_move_in_a_merge_puts_the_children_back() {
        Actions\expectDone( 'licensio_kb_terms_merged' )->never();
        Functions\expect( 'wp_delete_term' )->never();
        $this->refuse_write = function ( $action, $term_id, $object_ids ) {
            return 'remove' === $action && 2 === $term_id && in_array( 103, $object_ids, true );
        };

        $result = $this->controller->merge(
            $this->request(
                array(
                    'sources' => array( 2 ),
                    'target'  => 4,
                )
            )
        );

        $this->assertInstanceOf( 'WP_Error', $result );
        $this->assertSame( 2, $this->terms[3]['parent'] );
        $this->assertEqualsCanonicalizing( array( 102, 103 ), $this->assignments[2] );
        $this->assertSame( array( 104 ), $this->assignments[4] );
    }

    /**
     * A backslash in a new category's name, description and SVG icon is kept.
     *
     * REST values arrive unslashed and `wp_insert_term()` unslashes again,
     * so `Paths C:\Temp` would otherwise be stored as `Paths C:Temp`.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_created_category_keeps_every_backslash() {
        $svg = '<svg viewBox="0 0 24 24"><title>C:\Temp</title></svg>';

        $this->controller->create_item(
            $this->request(
                array(
                    'name'        => 'Paths C:\Temp',
                    'description' => 'Use C:\Temp.',
                    'icon_svg'    => $svg,
                )
            )
        );

        $this->assertSame( 'Paths C:\Temp', $this->terms[6]['name'] );
        $this->assertSame( 'Use C:\Temp.', $this->terms[6]['description'] );
        $this->assertSame( $svg, $this->meta[6][ Licensio_KB_Term_Meta::META_ICON_SVG ] );
    }

    /**
     * A backslash in an edited category's name and description is kept.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_updated_category_keeps_every_backslash() {
        $this->controller->update_item(
            $this->request(
                array(
                    'id'          => 3,
                    'name'        => 'Paths C:\Temp',
                    'description' => 'Use C:\Temp.',
                )
            )
        );

        $this->assertSame(
            array(
                array(
                    'name'        => 'Paths C:\Temp',
                    'description' => 'Use C:\Temp.',
                ),
            ),
            $this->term_writes
        );
    }
}
