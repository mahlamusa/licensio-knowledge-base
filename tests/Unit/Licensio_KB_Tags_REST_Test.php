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
     * Per-term capabilities refused, as `cap:id`, the way a map_meta_cap filter would.
     *
     * @since 1.0.0
     * @var string[]
     */
    private $denied_terms = array();

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
     * Whether every article assignment write fails.
     *
     * @since 1.0.0
     * @var bool
     */
    private $refuse_write = false;

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

        Functions\when( 'get_post_type_object' )->justReturn(
            (object) array(
                'cap' => (object) Licensio_KB_Capabilities::post_type_capabilities(),
            )
        );
        Functions\when( 'get_current_user_id' )->justReturn( 1 );
        Functions\when( '_prime_post_caches' )->justReturn( null );

        $this->routes       = array();
        $this->calls        = array();
        $this->denied_terms = array();
        $this->caps         = array( 'manage_licensio_kb', 'manage_tags', 'edit_tags', 'delete_tags', 'assign_tags', 'edit_categories', 'assign_categories', 'edit_others_licensio_articles', 'edit_published_licensio_articles', 'edit_private_licensio_articles' );
        $this->terms        = array(
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
        Functions\when( 'wp_defer_term_counting' )->justReturn( false );
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
            function ( $cap, $id = 0 ) {
                if ( in_array( $cap, array( 'edit_term', 'delete_term' ), true ) ) {
                    return ! in_array( $cap . ':' . (int) $id, $this->denied_terms, true );
                }

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
        Functions\when( 'get_terms' )->justReturn( array() );
        Functions\when( 'get_objects_in_term' )->alias(
            function ( $term_id, $taxonomy ) {
                unset( $term_id );

                return 'licensio_tag' === $taxonomy ? array( '100', '101' ) : array();
            }
        );
        Functions\when( 'clean_object_term_cache' )->justReturn( null );
        Functions\when( 'wp_update_term_count' )->justReturn( true );

        $this->refuse_write = false;
        $GLOBALS['wpdb']    = new Licensio_KB_Relationships_WPDB( // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The suite's database double.
            function ( $action, $tt_id, array $object_ids ) {
                if ( $this->refuse_write ) {
                    return false;
                }

                $this->calls[] = array( $action, $tt_id, $object_ids );

                return count( $object_ids );
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
                'term_id'          => $id,
                'term_taxonomy_id' => $id,
                'name'             => $name,
                'slug'             => '' === $slug ? strtolower( $name ) : $slug,
                'taxonomy'         => $taxonomy,
                'description'      => $description,
                'parent'           => $parent_id,
                'count'            => 2,
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
        $this->assertSame( 'sanitize_slug', $this->routes['/tags/(?P<id>[\d]+)'][0]['args']['slug']['sanitize_callback'][1], 'Core sanitize_slug(), not sanitize_title(), which would take the request as its fallback for an empty slug.' );
        $this->assertSame( 'wp_kses_post', $this->routes['/tags'][1]['args']['description']['sanitize_callback'], 'A new description keeps the HTML core allows.' );
        $this->assertSame( 'wp_kses_post', $this->routes['/tags/(?P<id>[\d]+)'][0]['args']['description']['sanitize_callback'], 'An edited description keeps its links and formatting.' );

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
            array( '/tags/merge', 'POST', 'assign_tags' ),
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
     * A tag the user may not edit or delete, by its per-term capability, cannot be changed.
     *
     * Each write route checks `edit_term` or `delete_term` on every tag it
     * touches, and convert checks `edit_term` on the parent category, as the
     * Categories routes do. Tags and parents that do not exist are left to
     * the handler, which reports them.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_per_term_capabilities_are_checked() {
        $patch   = array( '/tags/(?P<id>[\d]+)', 'PATCH' );
        $delete  = array( '/tags/bulk-delete', 'POST' );
        $merge   = array( '/tags/merge', 'POST' );
        $convert = array( '/tags/convert', 'POST' );
        $cases   = array(
            array( 'edit_term:10', $patch, array( 'id' => 10 ) ),
            array( 'delete_term:10', $delete, array( 'ids' => array( 11, 10 ) ) ),
            array(
                'delete_term:10',
                $merge,
                array(
                    'sources' => array( 10 ),
                    'target'  => 11,
                ),
            ),
            array(
                'edit_term:10',
                $merge,
                array(
                    'sources' => array( 11 ),
                    'target'  => 10,
                ),
            ),
            array( 'delete_term:10', $convert, array( 'ids' => array( 11, 10 ) ) ),
            array(
                'edit_term:20',
                $convert,
                array(
                    'ids'    => array( 11 ),
                    'parent' => 20,
                ),
            ),
        );

        foreach ( $cases as $case ) {
            list( $denied, $route, $params ) = $case;

            $this->denied_terms = array();
            $this->assertTrue( $this->call( $route[0], $route[1], $params, 'permission_callback' ), $route[0] . ' allowed' );

            $this->denied_terms = array( $denied );
            $refused            = $this->call( $route[0], $route[1], $params, 'permission_callback' );

            $this->assertInstanceOf( WP_Error::class, $refused, $route[0] . ' without ' . $denied );
            $this->assertSame( 'licensio_forbidden', $refused->get_error_code() );
            $this->assertSame( 403, $refused->get_error_data()['status'] );
        }

        $this->denied_terms = array( 'edit_term:11', 'delete_term:10' );

        $this->assertTrue( $this->call( $patch[0], $patch[1], array( 'id' => 10 ), 'permission_callback' ), 'Only the tag denied is refused.' );
        $this->assertTrue( $this->call( $delete[0], $delete[1], array( 'ids' => array( 99 ) ), 'permission_callback' ), 'A missing tag is left to the handler.' );
        $this->assertTrue(
            $this->call(
                $convert[0],
                $convert[1],
                array(
                    'ids'    => array( 11 ),
                    'parent' => 0,
                ),
                'permission_callback'
            ),
            'The top level has no parent to check.'
        );
        $this->assertTrue(
            $this->call(
                $convert[0],
                $convert[1],
                array(
                    'ids'    => array( 11 ),
                    'parent' => 99,
                ),
                'permission_callback'
            ),
            'A missing parent is left to the handler.'
        );
    }

    /**
     * An edit to a slug another tag uses is a 409; the current slug is left alone.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_edit_to_a_taken_slug_is_a_conflict() {
        $updates = array();

        Functions\expect( 'wp_unique_term_slug' )->never();
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

        $this->assertInstanceOf( WP_Error::class, $edited );
        $this->assertSame( 'licensio_kb_slug_taken', $edited->get_error_code() );
        $this->assertSame( 409, $edited->get_error_data()['status'] );
        $this->assertSame( 'Another tag already uses the slug “invoices”. Choose a different slug.', $edited->get_error_message() );
        $this->assertSame( array(), $updates, 'Nothing is written.' );

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
     * A slug sent empty is handed to core empty, so core regenerates it from the name.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_emptied_slug_is_regenerated_from_the_name() {
        $updates = array();

        Functions\expect( 'wp_unique_term_slug' )->never();
        Functions\when( 'wp_update_term' )->alias(
            function ( $id, $taxonomy, $args ) use ( &$updates ) {
                $updates[] = $args;

                $this->terms[ $taxonomy ][ $id ]->slug = 'billing-2';

                return array( 'term_id' => $id );
            }
        );

        $edited = $this->call(
            '/tags/(?P<id>[\d]+)',
            'PATCH',
            array(
                'id'   => 10,
                'slug' => '',
            )
        );

        $this->assertSame( array( array( 'slug' => '' ) ), $updates );
        $this->assertSame( 'billing-2', $edited['slug'], 'The answer carries the slug core saved.' );
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
     * An escaped description round-trips unchanged when only the name is edited.
     *
     * Decoding it and sending it back would let kses turn `&lt;b&gt;` into a
     * real tag, so it is answered as stored and a name edit leaves it out.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_escaped_description_round_trips_through_a_name_edit() {
        $updates = array();

        $this->terms['licensio_tag'][10]->description = 'Use &lt;b&gt; for bold';

        Functions\when( 'wp_update_term' )->alias(
            function ( $id, $taxonomy, $args ) use ( &$updates ) {
                $updates[] = $args;

                $this->terms[ $taxonomy ][ $id ]->name = $args['name'];

                return array( 'term_id' => $id );
            }
        );

        $edited = $this->call(
            '/tags/(?P<id>[\d]+)',
            'PATCH',
            array(
                'id'   => 10,
                'name' => 'Billing and money',
            )
        );

        $this->assertSame( array( array( 'name' => 'Billing and money' ) ), $updates, 'The description is not sent back.' );
        $this->assertSame( 'Use &lt;b&gt; for bold', $edited['description'] );
        $this->assertSame( 'Use &lt;b&gt; for bold', $this->terms['licensio_tag'][10]->description );
    }

    /**
     * Names are answered as plain text and descriptions as stored.
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
                'description' => 'Questions &amp; answers',
                'count'       => 2,
            ),
            $response->get_data()
        );
    }

    /**
     * Converting reuses the category that already has the tag's slug.
     *
     * The category stays where it is, every article the tag had moves to it,
     * and the tag is deleted after the assignments are moved.
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
                array( 'add', 21, array( 100, 101 ) ),
                array( 'remove', 10, array( 100, 101 ) ),
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
        $this->assertSame( array( 'add', 30, array( 100, 101 ) ), $this->calls[1] );
        $this->assertSame( array( 'remove', 11, array( 100, 101 ) ), $this->calls[2] );
        $this->assertSame( array( 'delete', 11, 'licensio_tag' ), $this->calls[3] );
        $this->assertSame( 20, $result['converted'][0]['category']['parent'] );
    }

    /**
     * Converting reuses a category of the same name under the parent.
     *
     * Core lets a second category of the same name in under one parent when
     * the slug differs, so the convert looks for it by its stored name first.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_convert_reuses_a_same_name_category_under_the_parent() {
        $sibling = $this->term( 22, 'Q&amp;A', 'licensio_category', '', 20, 'q-a-help' );
        $queries = array();

        $this->terms['licensio_tag'][12]      = $this->term( 12, 'Q&amp;A', 'licensio_tag', '', 0, 'q-a' );
        $this->terms['licensio_category'][22] = $sibling;

        Functions\when( 'get_terms' )->alias(
            function ( $args ) use ( &$queries, $sibling ) {
                $queries[] = $args;

                return ( 20 === $args['parent'] && 'Q&amp;A' === $args['name'] ) ? array( $sibling ) : array();
            }
        );

        $result = $this->call(
            '/tags/convert',
            'POST',
            array(
                'ids'    => array( 12 ),
                'parent' => 20,
            )
        );

        $this->assertSame( 'licensio_category', $queries[0]['taxonomy'] );
        $this->assertFalse( $queries[0]['hide_empty'] );
        $this->assertTrue( $result['converted'][0]['reused'] );
        $this->assertSame( 22, $result['converted'][0]['category']['id'] );
        $this->assertSame( 'Q&A', $result['converted'][0]['category']['name'] );
        $this->assertSame(
            array(
                array( 'add', 22, array( 100, 101 ) ),
                array( 'remove', 12, array( 100, 101 ) ),
                array( 'delete', 12, 'licensio_tag' ),
            ),
            $this->calls,
            'No category is inserted.'
        );
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
     * A tag that cannot be deleted is left as it was.
     *
     * Its articles get the tag back and lose the category again, and the
     * category the convert created for it is deleted.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_convert_puts_back_a_tag_that_cannot_be_deleted() {
        Functions\when( 'get_objects_in_term' )->alias(
            function ( $term_id ) {
                return 30 === (int) $term_id ? array() : array( '100', '101' );
            }
        );
        Functions\when( 'wp_delete_term' )->alias(
            function ( $id, $taxonomy ) {
                $this->calls[] = array( 'delete', (int) $id, $taxonomy );

                return 'licensio_category' === $taxonomy;
            }
        );

        $result = $this->call( '/tags/convert', 'POST', array( 'ids' => array( 11 ) ) );

        $this->assertSame( array(), $result['converted'] );
        $this->assertSame( array( 11 ), array_column( $result['failed'], 'id' ) );
        $this->assertSame(
            array(
                array( 'add', 30, array( 100, 101 ) ),
                array( 'remove', 11, array( 100, 101 ) ),
                array( 'delete', 11, 'licensio_tag' ),
                array( 'add', 11, array( 100, 101 ) ),
                array( 'remove', 30, array( 100, 101 ) ),
                array( 'delete', 30, 'licensio_category' ),
            ),
            array_slice( $this->calls, 1 )
        );
    }

    /**
     * A tag whose assignment fails is kept and reported.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_convert_keeps_a_tag_whose_assignment_fails() {
        $this->refuse_write = true;
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
     * Merge and convert move nothing when an article of the tag is refused.
     *
     * The routes ask `check_articles()` first; a tag with an article the
     * user may not edit, or that someone else has open in the editor, is
     * left as it was.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_merge_and_convert_check_every_article_first() {
        $operations = Mockery::mock( Licensio_KB_Term_Operations::class );
        $operations->shouldReceive( 'check_articles' )
            ->with( array( 11 ), 'licensio_tag' )
            ->andReturn( new WP_Error( 'licensio_kb_articles_locked', 'Article 100 is being edited.', array( 'status' => 409 ) ) );
        $operations->shouldReceive( 'check_articles' )
            ->with( array( 10 ), 'licensio_tag' )
            ->andReturn( new WP_Error( 'licensio_kb_articles_forbidden', 'You may not edit article 100.', array( 'status' => 403 ) ) );
        $operations->shouldNotReceive( 'merge_terms', 'move_across_taxonomies' );

        $this->routes = array();
        ( new Licensio_KB_Tags_REST_Controller( $operations ) )->register_routes();

        $merged = $this->call(
            '/tags/merge',
            'POST',
            array(
                'sources' => array( 11 ),
                'target'  => 10,
            )
        );

        $this->assertInstanceOf( WP_Error::class, $merged );
        $this->assertSame( 'licensio_kb_articles_locked', $merged->get_error_code() );

        $converted = $this->call(
            '/tags/convert',
            'POST',
            array(
                'ids'    => array( 10 ),
                'parent' => 20,
            )
        );

        $this->assertSame( array(), $converted['converted'] );
        $this->assertSame( array( 10 ), array_column( $converted['failed'], 'id' ) );
        $this->assertSame( array(), $this->calls, 'Nothing was created, moved or deleted.' );
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
