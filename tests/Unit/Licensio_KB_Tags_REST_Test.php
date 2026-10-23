<?php
/**
 * The tag routes behind the Tags app.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

use Brain\Monkey\Functions;

/**
 * Drives Licensio_KB_Tags_REST_Controller with the term API stubbed.
 *
 * @since 1.0.0
 */
class Licensio_KB_Tags_REST_Test extends Licensio_KB_Test_Case {

    /**
     * Every route registered during the test, keyed by route path.
     *
     * @since 1.0.0
     * @var array<string, array<int, array<string, mixed>>>
     */
    private $routes = array();

    /**
     * The capabilities the current user holds.
     *
     * @since 1.0.0
     * @var string[]
     */
    private $caps = array();

    /**
     * The terms that exist, keyed by taxonomy and then ID.
     *
     * @since 1.0.0
     * @var array<string, array<int, WP_Term>>
     */
    private $terms = array();

    /**
     * Every write made through the term API, in order.
     *
     * @since 1.0.0
     * @var array<int, array<int, mixed>>
     */
    private $calls = array();

    /**
     * Capture the routes and stub the term API over a small set of terms.
     *
     * Tags: Billing (10, slug `billing`), Invoices (11). Categories: Help (20),
     * and Billing (21) which has the same slug as the Billing tag.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        $this->routes = array();
        $this->calls  = array();
        $this->caps   = array( 'manage_licensio_kb', 'manage_tags', 'edit_tags', 'delete_tags', 'edit_categories', 'assign_categories' );
        $this->terms  = array(
            'licensio_tag'      => array(
                10 => $this->term( 10, 'Billing', 'licensio_tag', 'About money' ),
                11 => $this->term( 11, 'Invoices', 'licensio_tag' ),
            ),
            'licensio_category' => array(
                20 => $this->term( 20, 'Help', 'licensio_category' ),
                21 => $this->term( 21, 'Billing', 'licensio_category' ),
            ),
        );

        Functions\when( 'register_rest_route' )->alias(
            function ( $rest_namespace, $route, $args ) {
                unset( $rest_namespace );

                $this->routes[ $route ] = $args;

                return true;
            }
        );

        Functions\when( 'rest_ensure_response' )->returnArg();
        Functions\when( 'rest_authorization_required_code' )->justReturn( 403 );
        Functions\when( 'wp_slash' )->returnArg();
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
            function ( $cap ) {
                return in_array( $cap, $this->caps, true );
            }
        );
        Functions\when( 'get_taxonomy' )->alias(
            function ( $taxonomy ) {
                $caps = array(
                    'licensio_tag'      => array( 'manage_tags', 'edit_tags', 'delete_tags', 'assign_tags' ),
                    'licensio_category' => array( 'manage_categories', 'edit_categories', 'delete_categories', 'assign_categories' ),
                );

                if ( ! isset( $caps[ $taxonomy ] ) ) {
                    return false;
                }

                return (object) array(
                    'cap' => (object) array_combine( array( 'manage_terms', 'edit_terms', 'delete_terms', 'assign_terms' ), $caps[ $taxonomy ] ),
                );
            }
        );
        Functions\when( 'get_term' )->alias(
            function ( $id, $taxonomy ) {
                return isset( $this->terms[ $taxonomy ][ (int) $id ] ) ? $this->terms[ $taxonomy ][ (int) $id ] : null;
            }
        );
        Functions\when( 'get_term_by' )->alias(
            function ( $field, $value, $taxonomy ) {
                unset( $field );

                foreach ( $this->terms[ $taxonomy ] as $term ) {
                    if ( $term->slug === $value ) {
                        return $term;
                    }
                }

                return false;
            }
        );
        Functions\when( 'get_objects_in_term' )->justReturn( array( '100', '101' ) );
        Functions\when( 'wp_set_object_terms' )->alias(
            function ( ...$args ) {
                $this->calls[] = array_merge( array( 'set' ), $args );

                return array( 1 );
            }
        );
        Functions\when( 'wp_delete_term' )->alias(
            function ( $id, $taxonomy ) {
                $this->calls[] = array( 'delete', (int) $id, $taxonomy );
                unset( $this->terms[ $taxonomy ][ (int) $id ] );

                return true;
            }
        );
        Functions\when( 'wp_insert_term' )->alias(
            function ( $name, $taxonomy, $args = array() ) {
                $this->calls[] = array( 'insert', $name, $taxonomy, $args );

                $this->terms[ $taxonomy ][30] = $this->term( 30, $name, $taxonomy, isset( $args['description'] ) ? $args['description'] : '', isset( $args['parent'] ) ? $args['parent'] : 0, isset( $args['slug'] ) ? $args['slug'] : '' );

                return array(
                    'term_id'          => 30,
                    'term_taxonomy_id' => 30,
                );
            }
        );

        ( new Licensio_KB_Tags_REST_Controller() )->register_routes();
    }

    /**
     * A term double.
     *
     * @since 1.0.0
     *
     * @param int    $id          The term ID.
     * @param string $name        The term name.
     * @param string $taxonomy    The taxonomy.
     * @param string $description The description.
     * @param int    $parent_id   The parent term ID.
     * @param string $slug        The slug. Defaults to the lowercased name.
     *
     * @return WP_Term The term.
     */
    private function term( $id, $name, $taxonomy, $description = '', $parent_id = 0, $slug = '' ) {
        return new WP_Term(
            array(
                'term_id'     => $id,
                'name'        => $name,
                'slug'        => '' === $slug ? strtolower( $name ) : $slug,
                'taxonomy'    => $taxonomy,
                'description' => $description,
                'parent'      => $parent_id,
                'count'       => 2,
            )
        );
    }

    /**
     * Run one route handler.
     *
     * @since 1.0.0
     *
     * @param string               $route  The route path.
     * @param string               $method The HTTP method.
     * @param array<string, mixed> $params The request parameters.
     * @param string               $key    `callback` or `permission_callback`.
     *
     * @return mixed What the handler returned.
     */
    private function call( $route, $method, array $params = array(), $key = 'callback' ) {
        foreach ( $this->routes[ $route ] as $handler ) {
            if ( $method === $handler['methods'] ) {
                return call_user_func( $handler[ $key ], new WP_REST_Request( array(), '/licensio-kb/v1' . $route, $params ) );
            }
        }

        $this->fail( 'No ' . $method . ' handler for ' . $route );
    }

    /**
     * Every route is registered with a capped, typed argument schema.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_routes_are_registered_with_their_arguments() {
        $list = $this->routes['/tags'][0];

        $this->assertSame( 'GET', $list['methods'] );
        $this->assertSame( array( 'name', 'count' ), $list['args']['orderby']['enum'] );
        $this->assertSame( array( 'asc', 'desc' ), $list['args']['order']['enum'] );
        $this->assertSame( 100, $list['args']['per_page']['maximum'] );
        $this->assertSame( 100, $list['args']['include']['maxItems'] );
        $this->assertSame( 'boolean', $list['args']['unused']['type'] );

        $this->assertSame( 'POST', $this->routes['/tags'][1]['methods'] );
        $this->assertTrue( $this->routes['/tags'][1]['args']['name']['required'] );
        $this->assertSame( 'PATCH', $this->routes['/tags/(?P<id>[\d]+)'][0]['methods'] );
        $this->assertFalse( $this->routes['/tags/(?P<id>[\d]+)'][0]['args']['name']['required'] );
        $this->assertSame( 'sanitize_title', $this->routes['/tags/(?P<id>[\d]+)'][0]['args']['slug']['sanitize_callback'] );

        foreach ( array(
            '/tags/bulk-delete' => 'ids',
            '/tags/merge'       => 'sources',
            '/tags/convert'     => 'ids',
        ) as $route => $arg ) {
            $schema = $this->routes[ $route ][0]['args'][ $arg ];

            $this->assertSame( 'POST', $this->routes[ $route ][0]['methods'], $route );
            $this->assertTrue( $schema['required'], $route );
            $this->assertSame( 1, $schema['minItems'], $route );
            $this->assertSame( 100, $schema['maxItems'], $route );
            $this->assertSame( 'integer', $schema['items']['type'], $route );
        }

        $this->assertTrue( $this->routes['/tags/merge'][0]['args']['target']['required'] );
        $this->assertSame( 0, $this->routes['/tags/convert'][0]['args']['parent']['minimum'] );
        $this->assertSame( 0, $this->routes['/tags/convert'][0]['args']['parent']['default'] );
    }

    /**
     * Each route asks for the management capability and its taxonomy capabilities.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_each_route_checks_its_capabilities() {
        $cases = array(
            array( '/tags', 'GET', 'manage_tags' ),
            array( '/tags', 'POST', 'edit_tags' ),
            array( '/tags/(?P<id>[\d]+)', 'PATCH', 'edit_tags' ),
            array( '/tags/bulk-delete', 'POST', 'delete_tags' ),
            array( '/tags/merge', 'POST', 'edit_tags' ),
            array( '/tags/merge', 'POST', 'delete_tags' ),
            array( '/tags/convert', 'POST', 'delete_tags' ),
            array( '/tags/convert', 'POST', 'edit_categories' ),
            array( '/tags/convert', 'POST', 'assign_categories' ),
        );

        $all = $this->caps;

        foreach ( $cases as $case ) {
            $this->caps = $all;
            $this->assertTrue( $this->call( $case[0], $case[1], array(), 'permission_callback' ), $case[0] . ' with every capability' );

            $this->caps = array_values( array_diff( $all, array( $case[2] ) ) );
            $denied     = $this->call( $case[0], $case[1], array(), 'permission_callback' );

            $this->assertInstanceOf( WP_Error::class, $denied, $case[0] . ' without ' . $case[2] );
            $this->assertSame( 403, $denied->get_error_data()['status'] );

            $this->caps = array_values( array_diff( $all, array( 'manage_licensio_kb' ) ) );
            $this->assertInstanceOf( WP_Error::class, $this->call( $case[0], $case[1], array(), 'permission_callback' ), $case[0] . ' without the management capability' );
        }
    }

    /**
     * A new slug that is taken is made unique; the current slug is left alone.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_edited_slug_is_made_unique() {
        $updates = array();

        Functions\expect( 'wp_unique_term_slug' )->once()->with( 'invoices', $this->terms['licensio_tag'][10] )->andReturn( 'invoices-2' );
        Functions\when( 'wp_update_term' )->alias(
            function ( $id, $taxonomy, $args ) use ( &$updates ) {
                $updates[] = array( $id, $taxonomy, $args );

                $this->terms[ $taxonomy ][ $id ]->slug = isset( $args['slug'] ) ? $args['slug'] : $this->terms[ $taxonomy ][ $id ]->slug;

                return array( 'term_id' => $id );
            }
        );

        $edited = $this->call(
            '/tags/(?P<id>[\d]+)',
            'PATCH',
            array(
                'id'   => 10,
                'slug' => 'Invoices',
            )
        );

        $this->assertSame( 'invoices-2', $edited['slug'] );
        $this->assertSame( array( array( 10, 'licensio_tag', array( 'slug' => 'invoices-2' ) ) ), $updates );

        $updates = array();

        $this->call(
            '/tags/(?P<id>[\d]+)',
            'PATCH',
            array(
                'id'   => 11,
                'slug' => 'invoices',
                'name' => 'Bills & receipts',
            )
        );

        $this->assertSame( array( array( 11, 'licensio_tag', array( 'name' => 'Bills & receipts' ) ) ), $updates, 'The unchanged slug is not sent.' );
    }

    /**
     * Edits reject an empty name and an unknown tag.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_bad_edits_are_refused() {
        Functions\expect( 'wp_update_term' )->never();

        $empty = $this->call(
            '/tags/(?P<id>[\d]+)',
            'PATCH',
            array(
                'id'   => 10,
                'name' => '   ',
            )
        );

        $this->assertSame( 'licensio_kb_empty_name', $empty->get_error_code() );
        $this->assertSame( 400, $empty->get_error_data()['status'] );

        $missing = $this->call( '/tags/(?P<id>[\d]+)', 'PATCH', array( 'id' => 20 ) );

        $this->assertSame( 404, $missing->get_error_data()['status'], 'A category ID is not a tag.' );
    }

    /**
     * A name core says exists is a conflict, not a server error.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_duplicate_new_tag_is_a_conflict() {
        Functions\when( 'wp_insert_term' )->justReturn( new WP_Error( 'term_exists', 'A term with the name provided already exists.' ) );

        $result = $this->call( '/tags', 'POST', array( 'name' => 'Billing' ) );

        $this->assertSame( 'term_exists', $result->get_error_code() );
        $this->assertSame( 409, $result->get_error_data()['status'] );
    }

    /**
     * Names and descriptions are answered as plain text.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_new_tag_is_answered_as_plain_text() {
        Functions\when( 'wp_insert_term' )->alias(
            function ( $name, $taxonomy, $args ) {
                $this->terms[ $taxonomy ][31] = $this->term( 31, 'Q&amp;A', $taxonomy, 'Questions &amp; answers', 0, 'q-a' );

                unset( $name, $args );

                return array( 'term_id' => 31 );
            }
        );

        $response = $this->call( '/tags', 'POST', array( 'name' => 'Q&A' ) );

        $this->assertInstanceOf( WP_REST_Response::class, $response );
        $this->assertSame( 201, $response->get_status() );
        $this->assertSame(
            array(
                'id'          => 31,
                'name'        => 'Q&A',
                'slug'        => 'q-a',
                'description' => 'Questions & answers',
                'count'       => 2,
            ),
            $response->get_data()
        );
    }

    /**
     * Converting reuses the category that already has the tag's slug.
     *
     * The category stays where it is, gains every article the tag had, and
     * the tag is deleted after the assignments are made.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_convert_reuses_a_category_with_the_same_slug() {
        $result = $this->call(
            '/tags/convert',
            'POST',
            array(
                'ids'    => array( 10 ),
                'parent' => 20,
            )
        );

        $this->assertSame( array(), $result['failed'] );
        $this->assertTrue( $result['converted'][0]['reused'] );
        $this->assertSame( 21, $result['converted'][0]['category']['id'] );
        $this->assertSame( 0, $result['converted'][0]['category']['parent'], 'A reused category is not moved.' );
        $this->assertSame( 2, $result['converted'][0]['articles'] );
        $this->assertSame(
            array(
                array( 'set', 100, array( 21 ), 'licensio_category', true ),
                array( 'set', 101, array( 21 ), 'licensio_category', true ),
                array( 'delete', 10, 'licensio_tag' ),
            ),
            $this->calls
        );
    }

    /**
     * Converting creates a category under the parent when none has the slug.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_convert_creates_a_category_under_the_parent() {
        $result = $this->call(
            '/tags/convert',
            'POST',
            array(
                'ids'    => array( 11 ),
                'parent' => 20,
            )
        );

        $this->assertFalse( $result['converted'][0]['reused'] );
        $this->assertSame(
            array(
                'insert',
                'Invoices',
                'licensio_category',
                array(
                    'slug'        => 'invoices',
                    'parent'      => 20,
                    'description' => '',
                ),
            ),
            $this->calls[0]
        );
        $this->assertSame( array( 'set', 100, array( 30 ), 'licensio_category', true ), $this->calls[1] );
        $this->assertSame( array( 'delete', 11, 'licensio_tag' ), $this->calls[3] );
        $this->assertSame( 20, $result['converted'][0]['category']['parent'] );
    }

    /**
     * A missing parent stops the whole conversion before anything is written.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_convert_refuses_a_missing_parent() {
        $result = $this->call(
            '/tags/convert',
            'POST',
            array(
                'ids'    => array( 10 ),
                'parent' => 10,
            )
        );

        $this->assertSame( 'licensio_kb_invalid_parent', $result->get_error_code() );
        $this->assertSame( array(), $this->calls );
    }

    /**
     * A tag whose assignment fails is kept and reported.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_convert_keeps_a_tag_whose_assignment_fails() {
        Functions\when( 'wp_set_object_terms' )->justReturn( new WP_Error( 'db_insert_error', 'Could not insert term relationship.' ) );
        Functions\expect( 'wp_delete_term' )->never();

        $result = $this->call( '/tags/convert', 'POST', array( 'ids' => array( 10, 99 ) ) );

        $this->assertSame( array(), $result['converted'] );
        $this->assertSame( array( 10, 99 ), array_column( $result['failed'], 'id' ) );
    }

    /**
     * Merge hands the tag taxonomy to the shared term operations.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_merge_uses_the_term_operations_for_tags() {
        $operations = new class() extends Licensio_KB_Term_Operations {

            /**
             * The arguments merge_terms() was called with.
             *
             * @since 1.0.0
             * @var array<int, mixed>
             */
            public $args = array();

            /**
             * Record the call.
             *
             * @since 1.0.0
             *
             * @param int[]  $sources  The terms to merge away.
             * @param int    $target   The term that remains.
             * @param string $taxonomy The taxonomy.
             *
             * @return array{moved: int, deleted: int[]} A fixed result.
             */
            public function merge_terms( array $sources, $target, $taxonomy ) {
                $this->args = array( $sources, $target, $taxonomy );

                return array(
                    'moved'   => 3,
                    'deleted' => array( 11 ),
                );
            }
        };

        $this->routes = array();
        ( new Licensio_KB_Tags_REST_Controller( $operations ) )->register_routes();

        $result = $this->call(
            '/tags/merge',
            'POST',
            array(
                'sources' => array( '11', 11 ),
                'target'  => 10,
            )
        );

        $this->assertSame( array( array( 11 ), 10, 'licensio_tag' ), $operations->args );
        $this->assertSame( 3, $result['moved'] );
        $this->assertSame( array( 11 ), $result['deleted'] );
        $this->assertSame( 10, $result['target']['id'] );
    }

    /**
     * Bulk delete reports a tag that does not exist instead of failing the batch.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_bulk_delete_reports_each_tag() {
        $result = $this->call( '/tags/bulk-delete', 'POST', array( 'ids' => array( 10, 20, 11 ) ) );

        $this->assertSame( array( 10, 11 ), $result['deleted'] );
        $this->assertSame( array( 20 ), array_column( $result['failed'], 'id' ), 'A category ID is never deleted as a tag.' );
    }

    /**
     * The admin page announces the controller.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_admin_page_announces_the_controller() {
        $admin = new Licensio_KB_Tags_Admin( new Licensio_KB_Assets() );

        $this->assertSame( array( 'Other', 'Licensio_KB_Tags_REST_Controller' ), $admin->register_rest_controller( array( 'Other' ) ) );
        $this->assertSame( array( 'Licensio_KB_Tags_REST_Controller' ), $admin->register_rest_controller( null ) );
    }
}
