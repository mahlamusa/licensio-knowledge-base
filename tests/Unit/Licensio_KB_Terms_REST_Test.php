<?php
/**
 * The term search route behind the TermSelect picker.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

use Brain\Monkey\Functions;

/**
 * Drives Licensio_KB_Terms_REST_Controller.
 *
 * @since 1.0.0
 */
class Licensio_KB_Terms_REST_Test extends Licensio_KB_Test_Case {

    /**
     * Every route registered during the test, keyed by route path.
     *
     * @since 1.0.0
     * @var array<string, array<int, array<string, mixed>>>
     */
    private $routes = array();

    /**
     * Every get_terms() query, in order.
     *
     * @since 1.0.0
     * @var array<int, array<string, mixed>>
     */
    private $queries = array();

    /**
     * Capture route registration and back get_terms() with a small tree.
     *
     * The tree is Getting started (1) › Install (2) › Requirements (3), plus
     * a top-level Billing (4).
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        $this->routes  = array();
        $this->queries = array();

        Functions\when( 'register_rest_route' )->alias(
            function ( $rest_namespace, $route, $args ) {
                unset( $rest_namespace );

                $this->routes[ $route ] = $args;

                return true;
            }
        );

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

        $tree = array(
            $this->term( 1, 'Getting started', 0 ),
            $this->term( 2, 'Install', 1 ),
            $this->term( 3, 'Requirements', 2 ),
            $this->term( 4, 'Billing', 0 ),
        );

        Functions\when( 'get_terms' )->alias(
            function ( $args ) use ( $tree ) {
                $this->queries[] = $args;

                if ( ! empty( $args['include'] ) ) {
                    return array_values(
                        array_filter(
                            $tree,
                            function ( $term ) use ( $args ) {
                                return in_array( $term->term_id, $args['include'], true );
                            }
                        )
                    );
                }

                return $tree;
            }
        );

        ( new Licensio_KB_Terms_REST_Controller() )->register_routes();
    }

    /**
     * A term double.
     *
     * @since 1.0.0
     *
     * @param int    $id        The term ID.
     * @param string $name      The term name.
     * @param int    $parent_id The parent term ID.
     *
     * @return WP_Term The term.
     */
    private function term( $id, $name, $parent_id ) {
        return new WP_Term(
            array(
                'term_id' => $id,
                'name'    => $name,
                'slug'    => strtolower( str_replace( ' ', '-', $name ) ),
                'parent'  => $parent_id,
                'count'   => $id * 2,
            )
        );
    }

    /**
     * Run the search route.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $params The request parameters.
     *
     * @return mixed The response.
     */
    private function search( array $params ) {
        $handler = $this->routes['/terms'][0];

        return call_user_func(
            $handler['callback'],
            new WP_REST_Request(
                array(),
                '/licensio-kb/v1/terms',
                array_merge(
                    array(
                        'search'   => '',
                        'include'  => array(),
                        'exclude'  => array(),
                        'per_page' => 20,
                    ),
                    $params
                )
            )
        );
    }

    /**
     * The route takes only the plugin taxonomies and caps the page size.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_route_is_registered_with_its_arguments() {
        $this->assertSame( 'GET', $this->routes['/terms'][0]['methods'] );
        $this->assertSame( 'manage_permissions_check', $this->routes['/terms'][0]['permission_callback'][1] );

        $args = $this->routes['/terms'][0]['args'];

        $this->assertTrue( $args['taxonomy']['required'] );
        $this->assertSame( array( 'licensio_category', 'licensio_tag' ), $args['taxonomy']['enum'] );
        $this->assertSame( 100, $args['per_page']['maximum'] );
        $this->assertSame( 100, $args['include']['maxItems'] );
        $this->assertSame( 'integer', $args['exclude']['items']['type'] );
    }

    /**
     * Categories carry the names of their ancestors, top first.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_categories_carry_their_ancestor_path() {
        $items = $this->search( array( 'taxonomy' => 'licensio_category' ) );

        $this->assertSame(
            array(
                'id'     => 3,
                'name'   => 'Requirements',
                'slug'   => 'requirements',
                'parent' => 2,
                'count'  => 6,
                'path'   => array( 'Getting started', 'Install' ),
            ),
            $items[2]
        );
        $this->assertSame( array(), $items[3]['path'] );
    }

    /**
     * The search query passes the filters through and never hides empty terms.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_query_passes_the_filters_through() {
        $this->search(
            array(
                'taxonomy' => 'licensio_tag',
                'search'   => 'inst',
                'exclude'  => array( '4' ),
                'per_page' => 5,
            )
        );

        $this->assertCount( 1, $this->queries, 'Tags have no hierarchy, so no ancestor query is made.' );
        $this->assertSame( 'licensio_tag', $this->queries[0]['taxonomy'] );
        $this->assertSame( 'inst', $this->queries[0]['search'] );
        $this->assertSame( array( 4 ), $this->queries[0]['exclude'] );
        $this->assertSame( 5, $this->queries[0]['number'] );
        $this->assertFalse( $this->queries[0]['hide_empty'] );
    }

    /**
     * Included IDs come back whatever the page size.
     *
     * The picker uses `include` to label the terms already selected, so all of
     * them must come back even when there are more than a page.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_included_ids_come_back_in_full() {
        $items = $this->search(
            array(
                'taxonomy' => 'licensio_tag',
                'include'  => array( 2, 4 ),
                'per_page' => 1,
            )
        );

        $this->assertSame( 2, $this->queries[0]['number'] );
        $this->assertSame( array( 2, 4 ), array_column( $items, 'id' ) );
        $this->assertSame( array(), $items[0]['path'] );
    }

    /**
     * A query error is returned as is.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_query_error_is_returned() {
        Functions\when( 'get_terms' )->justReturn( new WP_Error( 'invalid_taxonomy', 'Nope' ) );

        $result = $this->search( array( 'taxonomy' => 'licensio_tag' ) );

        $this->assertInstanceOf( 'WP_Error', $result );
    }
}
