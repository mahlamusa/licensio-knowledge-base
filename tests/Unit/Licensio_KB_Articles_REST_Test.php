<?php
/**
 * The Articles app's REST routes.
 *
 * The SQL the list runs is proved against a real database in the
 * integration suite; these tests cover the route schemas, how the filters
 * become a WP_Query, and the quick edit and bulk argument checks.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

use Brain\Monkey\Functions;

/**
 * Drives Licensio_KB_Articles_REST_Controller.
 *
 * @since 1.0.0
 */
class Licensio_KB_Articles_REST_Test extends Licensio_KB_Test_Case {

    /**
     * Every route registered during the test, keyed by route path.
     *
     * @since 1.0.0
     * @var array<string, mixed>
     */
    private $routes = array();

    /**
     * The controller under test.
     *
     * @since 1.0.0
     * @var Licensio_KB_Articles_REST_Controller
     */
    private $controller;

    /**
     * Capabilities the current user lacks.
     *
     * @since 1.0.0
     * @var string[]
     */
    private $denied = array();

    /**
     * Term IDs that exist, by taxonomy.
     *
     * @since 1.0.0
     * @var array<string, int[]>
     */
    private $terms = array();

    /**
     * Register the routes with the core functions they touch stubbed.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        $this->routes = array();
        $this->denied = array();
        $this->terms  = array(
            'licensio_category' => array( 3, 4 ),
            'licensio_tag'      => array( 7 ),
        );

        Functions\when( 'register_rest_route' )->alias(
            function ( $rest_namespace, $route, $args ) {
                unset( $rest_namespace );

                $this->routes[ $route ] = $args;

                return true;
            }
        );
        Functions\when( 'rest_ensure_response' )->returnArg();
        Functions\when( 'is_wp_error' )->alias(
            function ( $thing ) {
                return $thing instanceof WP_Error;
            }
        );
        Functions\when( 'current_user_can' )->alias(
            function ( $cap ) {
                return ! in_array( $cap, $this->denied, true );
            }
        );
        Functions\when( 'rest_authorization_required_code' )->justReturn( 403 );
        Functions\when( 'get_post_type_object' )->justReturn(
            (object) array(
                'cap' => (object) array(
                    'publish_posts'     => 'publish_posts',
                    'edit_others_posts' => 'edit_others_posts',
                    'edit_posts'        => 'edit_posts',
                ),
            )
        );
        Functions\when( 'get_taxonomy' )->alias(
            function ( $taxonomy ) {
                return (object) array(
                    'cap' => (object) array(
                        'assign_terms' => 'assign_' . $taxonomy,
                        'edit_terms'   => 'edit_' . $taxonomy,
                    ),
                );
            }
        );
        Functions\when( 'term_exists' )->alias(
            function ( $term, $taxonomy ) {
                return isset( $this->terms[ $taxonomy ] ) && in_array( (int) $term, $this->terms[ $taxonomy ], true ) ? array( 'term_id' => (int) $term ) : null;
            }
        );
        Functions\when( 'get_userdata' )->alias(
            function ( $id ) {
                $user               = new WP_User( (int) $id );
                $user->display_name = 'User ' . (int) $id;

                return (int) $id > 0 && (int) $id < 50 ? $user : false;
            }
        );
        Functions\when( 'user_can' )->alias(
            function ( $user ) {
                return 1 === $user->ID || 2 === $user->ID;
            }
        );
        Functions\when( 'rest_parse_date' )->alias(
            function ( $date ) {
                return preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/', (string) $date ) ? 1 : false;
            }
        );
        Functions\when( 'rest_get_date_with_gmt' )->alias(
            function ( $date ) {
                $local = str_replace( 'T', ' ', (string) $date );

                return array( $local, $local );
            }
        );
        Functions\when( 'wp_check_post_lock' )->justReturn( false );

        $this->controller = new Licensio_KB_Articles_REST_Controller( new Licensio_KB_Article_Metrics( 50, 5 ), new Licensio_KB_Article_Bulk() );
        $this->controller->register_routes();
    }

    /**
     * An article double.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $fields Fields to override.
     *
     * @return WP_Post The article.
     */
    private function post( array $fields = array() ) {
        return new WP_Post(
            array_merge(
                array(
                    'ID'          => 12,
                    'post_type'   => 'licensio_article',
                    'post_status' => 'draft',
                    'post_author' => '1',
                ),
                $fields
            )
        );
    }

    /**
     * Every route exists with the right method, permission and limits.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_routes_are_registered_with_their_limits() {
        $this->assertSame(
            array( '/articles', '/articles/ids', '/articles/(?P<id>[\d]+)', '/articles/bulk', '/articles/revert', '/authors', '/preferences' ),
            array_keys( $this->routes )
        );

        $list = $this->routes['/articles'][0];

        $this->assertSame( 'GET', $list['methods'] );
        $this->assertSame( 'edit_articles_permissions_check', $list['permission_callback'][1] );
        $this->assertSame( 'authors_permissions_check', $this->routes['/authors'][0]['permission_callback'][1] );
        $this->assertSame( 100, $list['args']['per_page']['maximum'] );
        $this->assertSame( array( 7, 30, 90 ), $list['args']['window']['enum'] );
        $this->assertSame( 30, $list['args']['window']['default'] );
        $this->assertSame( array( 'date', 'modified', 'title', 'views', 'rating' ), $list['args']['orderby']['enum'] );
        $this->assertSame( array( 'all', 'publish', 'future', 'draft', 'pending', 'private', 'trash' ), $list['args']['status']['enum'] );

        $this->assertArrayNotHasKey( 'page', $this->routes['/articles/ids'][0]['args'] );
        $this->assertSame( 'update_item_permissions_check', $this->routes['/articles/(?P<id>[\d]+)'][0]['permission_callback'][1] );
        $this->assertSame( 'PATCH', $this->routes['/articles/(?P<id>[\d]+)'][0]['methods'] );

        $bulk = $this->routes['/articles/bulk'][0]['args'];

        $this->assertSame( 100, $bulk['ids']['maxItems'] );
        $this->assertSame( 1, $bulk['ids']['minItems'] );
        $this->assertSame( array( 'trash', 'restore', 'delete', 'status', 'terms', 'author', 'comments' ), $bulk['action']['enum'] );

        $this->assertTrue( $this->routes['/articles/revert'][0]['args']['token']['required'] );
        $this->assertSame( array( 20, 50, 100 ), $this->routes['/preferences'][1]['args']['per_page']['enum'] );
        $this->assertSame( 'PUT', $this->routes['/preferences'][1]['methods'] );
    }

    /**
     * Day and datetime arguments are validated strictly.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_dates_are_validated() {
        $this->assertTrue( Licensio_KB_Articles_REST_Controller::validate_day( '' ) );
        $this->assertTrue( Licensio_KB_Articles_REST_Controller::validate_day( '2026-02-28' ) );

        foreach ( array( '2026-02-30', '2026-2-3', '28/02/2026', '2026-02-28 10:00', array() ) as $bad ) {
            $this->assertInstanceOf( 'WP_Error', Licensio_KB_Articles_REST_Controller::validate_day( $bad ) );
        }

        $this->assertTrue( Licensio_KB_Articles_REST_Controller::validate_datetime( '2026-02-28T10:30:00' ) );
        $this->assertInstanceOf( 'WP_Error', Licensio_KB_Articles_REST_Controller::validate_datetime( 'tomorrow' ) );
    }

    /**
     * Tags may be IDs or new names, nothing else.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_tag_values_are_ids_or_new_names() {
        $this->assertTrue( Licensio_KB_Articles_REST_Controller::validate_tag_values( array( 4, '5', array( 'name' => 'New' ) ) ) );

        foreach ( array( 'x', array( 0 ), array( -2 ), array( array( 'name' => ' ' ) ), array( array( 'name' => '<b></b>' ) ), array( array( 'slug' => 'a' ) ), array( 1.5 ) ) as $bad ) {
            $this->assertInstanceOf( 'WP_Error', Licensio_KB_Articles_REST_Controller::validate_tag_values( $bad ) );
        }
    }

    /**
     * With no filters the list covers every status but the trash, newest first.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_default_query() {
        $args = $this->controller->query_args(
            array(
                'status'   => 'all',
                'orderby'  => 'date',
                'order'    => 'desc',
                'page'     => 2,
                'per_page' => 50,
                'window'   => 30,
            )
        );

        $this->assertSame( 'licensio_article', $args['post_type'] );
        $this->assertSame( array( 'publish', 'future', 'draft', 'pending', 'private' ), $args['post_status'] );
        $this->assertSame( 'readable', $args['perm'] );
        $this->assertSame( 50, $args['posts_per_page'] );
        $this->assertSame( 2, $args['paged'] );
        $this->assertSame(
            array(
                'date' => 'DESC',
                'ID'   => 'DESC',
            ),
            $args['orderby']
        );
        $this->assertFalse( $args['suppress_filters'] );
        $this->assertArrayNotHasKey( 'tax_query', $args );
        $this->assertArrayNotHasKey( 'date_query', $args );
        $this->assertArrayNotHasKey( 's', $args );
        $this->assertArrayNotHasKey( Licensio_KB_Article_Metrics::QUERY_VAR, $args, 'No metrics join is needed to sort by date.' );
    }

    /**
     * Every filter maps onto the query.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_every_filter_maps_onto_the_query() {
        $args = $this->controller->query_args(
            array(
                'status'          => 'trash',
                'search'          => ' invoice ',
                'category'        => 3,
                'tag'             => 7,
                'author'          => 2,
                'after'           => '2026-01-05',
                'before'          => '2026-02-28',
                'uncategorised'   => true,
                'untagged'        => true,
                'needs_attention' => true,
                'window'          => 7,
                'orderby'         => 'title',
                'order'           => 'asc',
            )
        );

        $this->assertSame( array( 'trash' ), $args['post_status'] );
        $this->assertSame( 'invoice', $args['s'] );
        $this->assertSame( array( 'post_title' ), $args['search_columns'], 'Search covers titles only.' );
        $this->assertSame( 2, $args['author'] );
        $this->assertSame(
            array(
                'relation' => 'AND',
                array(
                    'taxonomy'         => 'licensio_category',
                    'field'            => 'term_id',
                    'terms'            => array( 3 ),
                    'include_children' => true,
                ),
                array(
                    'taxonomy'         => 'licensio_tag',
                    'field'            => 'term_id',
                    'terms'            => array( 7 ),
                    'include_children' => false,
                ),
                array(
                    'taxonomy' => 'licensio_category',
                    'operator' => 'NOT EXISTS',
                ),
                array(
                    'taxonomy' => 'licensio_tag',
                    'operator' => 'NOT EXISTS',
                ),
            ),
            $args['tax_query']
        );
        $this->assertSame(
            array(
                array(
                    'after'     => array(
                        'year'  => 2026,
                        'month' => 1,
                        'day'   => 5,
                    ),
                    'before'    => array(
                        'year'  => 2026,
                        'month' => 2,
                        'day'   => 28,
                    ),
                    'column'    => 'post_date',
                    'inclusive' => true,
                ),
            ),
            $args['date_query']
        );
        $this->assertSame(
            array(
                'window'          => 7,
                'orderby'         => 'title',
                'order'           => 'ASC',
                'needs_attention' => true,
            ),
            $args[ Licensio_KB_Article_Metrics::QUERY_VAR ]
        );
        $this->assertSame(
            array(
                'title' => 'ASC',
                'ID'    => 'ASC',
            ),
            $args['orderby']
        );
    }

    /**
     * Each filter on its own maps onto the query, and a date range may be open on one side.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_each_filter_maps_onto_the_query_on_its_own() {
        $single = array(
            'category'      => array(
                'taxonomy'         => 'licensio_category',
                'field'            => 'term_id',
                'terms'            => array( 3 ),
                'include_children' => true,
            ),
            'tag'           => array(
                'taxonomy'         => 'licensio_tag',
                'field'            => 'term_id',
                'terms'            => array( 3 ),
                'include_children' => false,
            ),
            'uncategorised' => array(
                'taxonomy' => 'licensio_category',
                'operator' => 'NOT EXISTS',
            ),
            'untagged'      => array(
                'taxonomy' => 'licensio_tag',
                'operator' => 'NOT EXISTS',
            ),
        );

        foreach ( $single as $filter => $clause ) {
            $args = $this->controller->query_args( array( $filter => in_array( $filter, array( 'category', 'tag' ), true ) ? 3 : true ) );

            $this->assertSame(
                array(
                    'relation' => 'AND',
                    $clause,
                ),
                $args['tax_query'],
                $filter
            );
            $this->assertArrayNotHasKey( 'date_query', $args, $filter );
            $this->assertArrayNotHasKey( 'author', $args, $filter );
        }

        $after = $this->controller->query_args( array( 'after' => '2026-01-05' ) );
        $this->assertSame(
            array(
                'after'     => array(
                    'year'  => 2026,
                    'month' => 1,
                    'day'   => 5,
                ),
                'column'    => 'post_date',
                'inclusive' => true,
            ),
            $after['date_query'][0]
        );

        $before = $this->controller->query_args( array( 'before' => '2026-02-28' ) );
        $this->assertArrayNotHasKey( 'after', $before['date_query'][0] );
        $this->assertSame( 28, $before['date_query'][0]['before']['day'] );

        $this->assertArrayNotHasKey( 'date_query', $this->controller->query_args( array( 'after' => '2026-13-40' ) ), 'An impossible day is ignored.' );
        $this->assertSame( 5, $this->controller->query_args( array( 'author' => 5 ) )['author'] );
        $this->assertArrayNotHasKey( 'tax_query', $this->controller->query_args( array( 'author' => 5 ) ) );

        $attention = $this->controller->query_args( array( 'needs_attention' => true ) );
        $this->assertTrue( $attention[ Licensio_KB_Article_Metrics::QUERY_VAR ]['needs_attention'] );
        $this->assertArrayNotHasKey( 'tax_query', $attention );
    }

    /**
     * Sorting by views or rating hands the order to the metrics join.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_metric_sorts_use_the_metrics_join() {
        foreach ( array( 'views', 'rating' ) as $orderby ) {
            $args = $this->controller->query_args(
                array(
                    'orderby' => $orderby,
                    'order'   => 'desc',
                    'window'  => 90,
                )
            );

            $this->assertSame( $orderby, $args[ Licensio_KB_Article_Metrics::QUERY_VAR ]['orderby'] );
            $this->assertSame( 90, $args[ Licensio_KB_Article_Metrics::QUERY_VAR ]['window'] );
        }
    }

    /**
     * The IDs query returns every match, capped, as IDs.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_ids_query_is_capped_and_unpaged() {
        $args = $this->controller->query_args(
            array(
                'needs_attention' => true,
                'orderby'         => 'views',
            ),
            true
        );

        $this->assertSame( 'ids', $args['fields'] );
        $this->assertSame( 10000, $args['posts_per_page'] );
        $this->assertSame( 1, $args['paged'] );
        $this->assertSame( '', $args[ Licensio_KB_Article_Metrics::QUERY_VAR ]['orderby'] );
        $this->assertTrue( $args[ Licensio_KB_Article_Metrics::QUERY_VAR ]['needs_attention'] );
    }

    /**
     * A page past the end of a filtered list is empty but still reports the real total.
     *
     * WP_Query skips counting when the page is empty, so the matches are
     * counted again from the first page, with the same filters.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_filtered_page_past_the_end_still_reports_the_total() {
        Functions\when( 'wp_count_posts' )->justReturn( (object) array( 'draft' => '3' ) );

        $empty              = new WP_Query();
        $count              = new WP_Query();
        $count->found_posts = 3;

        $controller = new Licensio_KB_Articles_REST_Controller_Double( $empty, $count );
        $result     = $controller->get_items(
            new WP_REST_Request(
                array(),
                '/licensio-kb/v1/articles',
                array(
                    'status'   => 'draft',
                    'category' => 3,
                    'page'     => 99,
                    'per_page' => 2,
                )
            )
        );

        $this->assertCount( 2, $controller->calls );
        $this->assertSame( 99, $controller->calls[0]['paged'] );
        $this->assertSame(
            array_merge(
                $controller->calls[0],
                array(
                    'fields'         => 'ids',
                    'posts_per_page' => 1,
                    'paged'          => 1,
                )
            ),
            $controller->calls[1],
            'The recount keeps every filter of the page query.'
        );
        $this->assertSame( array( 'draft' ), $controller->calls[1]['post_status'] );
        $this->assertSame( array( 3 ), $controller->calls[1]['tax_query'][0]['terms'] );
        $this->assertSame( array(), $result['items'] );
        $this->assertSame( 3, $result['total'] );
        $this->assertSame( 2, $result['pages'] );
    }

    /**
     * A page inside the list takes its total from the page query alone.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_empty_first_page_is_not_counted_again() {
        Functions\when( 'wp_count_posts' )->justReturn( (object) array() );

        $controller = new Licensio_KB_Articles_REST_Controller_Double( new WP_Query() );
        $result     = $controller->get_items(
            new WP_REST_Request( array(), '/licensio-kb/v1/articles', array( 'status' => 'draft' ) )
        );

        $this->assertCount( 1, $controller->calls );
        $this->assertSame( 0, $result['total'] );
        $this->assertSame( 0, $result['pages'] );
    }

    /**
     * The status counts come from wp_count_posts(), "All" without the trash.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_status_counts_match_core() {
        Functions\when( 'wp_count_posts' )->justReturn(
            (object) array(
                'publish'    => '5',
                'draft'      => '2',
                'trash'      => '4',
                'private'    => '1',
                'auto-draft' => '9',
            )
        );

        $this->assertSame(
            array(
                'all'     => 8,
                'publish' => 5,
                'future'  => 0,
                'draft'   => 2,
                'pending' => 0,
                'private' => 1,
                'trash'   => 4,
            ),
            Licensio_KB_Articles_REST_Controller::status_counts()
        );
    }

    /**
     * Preferences keep only allow-listed columns and page sizes, and say whether
     * the user chose their columns.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_preferences_are_allow_listed() {
        $this->assertSame(
            array(
                'columns'        => array( 'author', 'views', 'modified' ),
                'per_page'       => 50,
                'columns_chosen' => true,
                'medium_columns' => array( 'rating', 'views' ),
            ),
            Licensio_KB_Articles_REST_Controller::sanitize_preferences(
                array(
                    'columns'        => array( 'modified', 'views', 'post_content', 'author' ),
                    'per_page'       => 50,
                    'columns_chosen' => '1',
                    'medium_columns' => array( 'views', 'post_content', 'rating' ),
                )
            )
        );

        $defaults = Licensio_KB_Articles_REST_Controller::sanitize_preferences( 'junk' );

        $this->assertSame( Licensio_KB_Articles_REST_Controller::DEFAULT_COLUMNS, $defaults['columns'] );
        $this->assertSame( 20, $defaults['per_page'] );
        $this->assertFalse( $defaults['columns_chosen'] );
        $this->assertSame( array(), $defaults['medium_columns'] );
        $this->assertSame( 20, Licensio_KB_Articles_REST_Controller::sanitize_preferences( array( 'per_page' => 25 ) )['per_page'] );

        $this->assertTrue(
            Licensio_KB_Articles_REST_Controller::sanitize_preferences( array( 'columns' => array( 'author', 'views' ) ) )['columns_chosen'],
            'Columns saved before the key existed were the user’s choice.'
        );
        $this->assertFalse(
            Licensio_KB_Articles_REST_Controller::sanitize_preferences(
                array(
                    'columns'        => array( 'author' ),
                    'columns_chosen' => false,
                )
            )['columns_chosen']
        );
    }

    /**
     * Quick edit categories and tags, and bulk terms, are capped at 100.
     *
     * Without a cap one request could create any number of new tags.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_term_lists_are_capped() {
        $args = $this->routes['/articles/(?P<id>[\d]+)'][0]['args'];

        $this->assertSame( 100, $args['categories']['maxItems'] );
        $this->assertSame( 100, $args['tags']['maxItems'] );

        $names = array_fill( 0, 100, array( 'name' => 'New' ) );

        $this->assertTrue( Licensio_KB_Articles_REST_Controller::validate_tag_values( $names ) );

        $names[] = array( 'name' => 'One too many' );

        $this->assertInstanceOf( 'WP_Error', Licensio_KB_Articles_REST_Controller::validate_tag_values( $names ) );

        $this->terms['licensio_category'] = range( 1, 101 );

        $bulk = function ( $count ) {
            return $this->controller->bulk_action_args(
                'terms',
                array(
                    'taxonomy' => 'licensio_category',
                    'mode'     => 'add',
                    'term_ids' => range( 1, $count ),
                )
            );
        };

        $this->assertSame( range( 1, 100 ), $bulk( 100 )['term_ids'] );

        $result = $bulk( 101 );

        $this->assertInstanceOf( 'WP_Error', $result );
        $this->assertSame( 400, $result->get_error_data()['status'] );
    }

    /**
     * Each bulk action's arguments are checked before anything runs.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_bulk_arguments_are_checked() {
        $invalid = array(
            array( 'status', array( 'status' => 'trash' ) ),
            array( 'status', array() ),
            array(
                'terms',
                array(
                    'taxonomy' => 'category',
                    'mode'     => 'add',
                    'term_ids' => array( 3 ),
                ),
            ),
            array(
                'terms',
                array(
                    'taxonomy' => 'licensio_category',
                    'mode'     => 'merge',
                    'term_ids' => array( 3 ),
                ),
            ),
            array(
                'terms',
                array(
                    'taxonomy' => 'licensio_category',
                    'mode'     => 'add',
                    'term_ids' => array(),
                ),
            ),
            array(
                'terms',
                array(
                    'taxonomy' => 'licensio_category',
                    'mode'     => 'add',
                    'term_ids' => array( 7 ),
                ),
            ),
            array( 'author', array( 'author' => 3 ) ),
            array( 'author', array( 'author' => 99 ) ),
            array( 'comments', array( 'comment_status' => 'maybe' ) ),
        );

        foreach ( $invalid as $case ) {
            $result = $this->controller->bulk_action_args( $case[0], $case[1] );

            $this->assertInstanceOf( 'WP_Error', $result, $case[0] . ' ' . wp_json_encode( $case[1] ) );
            $this->assertSame( 400, $result->get_error_data()['status'] );
        }

        $this->assertSame(
            array(
                'taxonomy' => 'licensio_category',
                'mode'     => 'replace',
                'term_ids' => array(),
            ),
            $this->controller->bulk_action_args(
                'terms',
                array(
                    'taxonomy' => 'licensio_category',
                    'mode'     => 'replace',
                    'term_ids' => array(),
                )
            ),
            'Replacing with nothing clears the terms.'
        );
        $this->assertSame( array( 'author' => 2 ), $this->controller->bulk_action_args( 'author', array( 'author' => '2' ) ) );
        $this->assertSame( array(), $this->controller->bulk_action_args( 'trash', array( 'ignored' => 1 ) ) );
    }

    /**
     * A bulk request with more than 100 IDs is refused.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_bulk_request_over_the_cap_is_refused() {
        $result = $this->controller->bulk_items(
            new WP_REST_Request(
                array(),
                '/licensio-kb/v1/articles/bulk',
                array(
                    'ids'    => range( 1, 101 ),
                    'action' => 'trash',
                    'args'   => array(),
                )
            )
        );

        $this->assertInstanceOf( 'WP_Error', $result );
        $this->assertSame( array( 'ids' ), array_keys( $result->get_error_data()['params'] ) );
    }

    /**
     * A quick edit request.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $params The request parameters.
     *
     * @return WP_REST_Request The request.
     */
    private function patch( array $params ) {
        return new WP_REST_Request( array(), '/licensio-kb/v1/articles/12', array_merge( array( 'id' => 12 ), $params ) );
    }

    /**
     * A locked article answers 409 with the editor's name, and nothing is written.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_quick_edit_of_a_locked_article_is_a_409() {
        Functions\when( 'get_post' )->justReturn( $this->post() );
        Functions\when( 'wp_check_post_lock' )->justReturn( 8 );
        Functions\expect( 'wp_update_post' )->never();

        $result = $this->controller->update_item( $this->patch( array( 'title' => 'New' ) ) );

        $this->assertInstanceOf( 'WP_Error', $result );
        $this->assertSame( 'licensio_kb_article_locked', $result->get_error_code() );
        $this->assertSame( 409, $result->get_error_data()['status'] );
        $this->assertSame(
            array(
                'user_id' => 8,
                'name'    => 'User 8',
            ),
            $result->get_error_data()['lock']
        );
    }

    /**
     * A trashed article must be restored first, and an unknown ID is a 404.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_quick_edit_refuses_trashed_and_unknown_articles() {
        Functions\when( 'get_post' )->justReturn( $this->post( array( 'post_status' => 'trash' ) ) );

        $this->assertSame( 'licensio_kb_article_trashed', $this->controller->update_item( $this->patch( array() ) )->get_error_code() );

        Functions\when( 'get_post' )->justReturn( $this->post( array( 'post_type' => 'post' ) ) );

        $this->assertSame( 404, $this->controller->update_item( $this->patch( array() ) )->get_error_data()['status'] );
    }

    /**
     * Field errors come back together, keyed by field.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_quick_edit_field_errors_are_keyed_by_field() {
        $result = $this->controller->collect_changes(
            $this->patch(
                array(
                    'status'     => 'private',
                    'password'   => 'secret',
                    'author'     => 3,
                    'categories' => array( 3, 9 ),
                    'tags'       => array( 7 ),
                )
            ),
            $this->post()
        );

        $this->assertInstanceOf( 'WP_Error', $result );
        $this->assertSame( 'rest_invalid_param', $result->get_error_code() );
        $this->assertSame( array( 'password', 'author', 'categories' ), array_keys( $result->get_error_data()['params'] ) );
    }

    /**
     * A valid quick edit becomes post fields and term lists.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_valid_quick_edit_becomes_post_fields() {
        $result = $this->controller->collect_changes(
            $this->patch(
                array(
                    'title'          => 'Renamed',
                    'slug'           => 'renamed',
                    'status'         => 'publish',
                    'date'           => '2026-03-01T09:00:00',
                    'password'       => '',
                    'author'         => 2,
                    'comment_status' => 'closed',
                    'categories'     => array( 3 ),
                    'tags'           => array( 7, array( 'name' => 'Fresh' ) ),
                )
            ),
            $this->post()
        );

        $this->assertSame(
            array(
                'ID'             => 12,
                'post_title'     => 'Renamed',
                'post_name'      => 'renamed',
                'post_status'    => 'publish',
                'post_date'      => '2026-03-01 09:00:00',
                'post_date_gmt'  => '2026-03-01 09:00:00',
                'edit_date'      => true,
                'post_password'  => '',
                'post_author'    => 2,
                'comment_status' => 'closed',
            ),
            $result[0]
        );
        $this->assertSame(
            array(
                'licensio_category' => array( 3 ),
                'licensio_tag'      => array( 7, array( 'name' => 'Fresh' ) ),
            ),
            $result[1]
        );
    }

    /**
     * The slug sanitizer takes one argument, as the REST server calls it with three.
     *
     * Core's sanitize_title() reads a second argument as the value to return
     * for an empty result and a third as the context, and strips accents only
     * in the `save` context. The double below behaves the same way, so a
     * sanitizer that forwards the server's request and key would answer the
     * request for an empty slug and keep the accents percent-encoded. An empty
     * slug reaches core as an empty post name, which core rebuilds from the
     * title.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_slug_is_sanitized_with_one_argument() {
        Functions\when( 'sanitize_title' )->alias(
            function ( $title, $fallback_title = '', $context = 'save' ) {
                $title = (string) $title;

                if ( 'save' === $context ) {
                    $title = strtr(
                        $title,
                        array(
                            "\u{e9}" => 'e',
                            "\u{dc}" => 'U',
                        )
                    );
                } else {
                    $title = (string) preg_replace_callback(
                        '/[\x80-\xff]/',
                        function ( $byte ) {
                            return '%' . strtolower( bin2hex( $byte[0] ) );
                        },
                        $title
                    );
                }

                $title = trim( (string) preg_replace( '/[^a-z0-9%]+/', '-', strtolower( $title ) ), '-' );

                return '' === $title ? $fallback_title : $title;
            }
        );

        $sanitize = $this->routes['/articles/(?P<id>[\d]+)'][0]['args']['slug']['sanitize_callback'];
        $request  = $this->patch( array() );

        foreach ( array( '', '   ', '!!!' ) as $empty ) {
            $this->assertSame( '', call_user_func( $sanitize, $empty, $request, 'slug' ) );
        }

        $this->assertSame( 'cafe-uber', call_user_func( $sanitize, "Caf\u{e9} \u{dc}ber", $request, 'slug' ) );

        $result = $this->controller->collect_changes( $this->patch( array( 'slug' => '' ) ), $this->post() );

        $this->assertSame(
            array(
                'ID'        => 12,
                'post_name' => '',
            ),
            $result[0]
        );
    }

    /**
     * The title reaches core as typed, so core's own title filters decide.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_title_is_passed_to_core_unsanitised() {
        $this->assertArrayNotHasKey( 'sanitize_callback', $this->controller->get_update_args()['title'] );

        $result = $this->controller->collect_changes( $this->patch( array( 'title' => 'Use a < b in <code>WP_Query</code>' ) ), $this->post() );

        $this->assertSame( 'Use a < b in <code>WP_Query</code>', $result[0]['post_title'] );
    }

    /**
     * Quick edit edits the stored title, so an unchanged title saves the same bytes.
     *
     * A title stored with entities by a user without `unfiltered_html` is
     * shown decoded, as text, but Quick edit gets it as stored. Sending that
     * back writes it unchanged, so the entities never become markup.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_quick_edit_round_trips_the_stored_title() {
        $stored  = 'Guide &lt;img src=x onerror=alert(1)&gt; &amp; tips';
        $post    = $this->post( array( 'post_title' => $stored ) );
        $written = array();
        $metrics = new class( 50, 5 ) extends Licensio_KB_Article_Metrics {

            /**
             * No figures for any article.
             *
             * @since 1.0.0
             *
             * @param int[] $ids    The article IDs.
             * @param int   $window The window, in days.
             *
             * @return array<int, array{views: int, up: int, down: int, share: int|null}> None.
             */
            public function for_articles( array $ids, $window ) {
                return array();
            }

            /**
             * No attachments for any article.
             *
             * @since 1.0.0
             *
             * @param int[] $ids The article IDs.
             *
             * @return array<int, int> None.
             */
            public function attachment_counts( array $ids ) {
                return array();
            }
        };

        Functions\when( 'get_post' )->justReturn( $post );
        Functions\when( 'wp_slash' )->returnArg();
        Functions\when( 'wp_update_post' )->alias(
            function ( $postarr ) use ( &$written, $post ) {
                $written[]        = $postarr;
                $post->post_title = $postarr['post_title'];

                return $postarr['ID'];
            }
        );
        Functions\when( 'clean_post_cache' )->justReturn( null );
        Functions\when( 'update_object_term_cache' )->justReturn( null );
        Functions\when( 'wp_list_pluck' )->alias(
            function ( $items, $field ) {
                return array_map(
                    function ( $item ) use ( $field ) {
                        return $item->$field;
                    },
                    $items
                );
            }
        );
        Functions\when( 'cache_users' )->justReturn( null );
        Functions\when( 'get_the_terms' )->justReturn( false );
        Functions\when( 'get_edit_post_link' )->justReturn( '' );
        Functions\when( 'get_preview_post_link' )->justReturn( '' );
        Functions\when( 'strip_shortcodes' )->returnArg();

        $controller = new Licensio_KB_Articles_REST_Controller( $metrics, new Licensio_KB_Article_Bulk() );
        $row        = $controller->prepare_items( array( $post ), 30 )[0];

        $this->assertSame( 'Guide <img src=x onerror=alert(1)> & tips', $row['title'], 'Shown as text.' );
        $this->assertSame( $stored, $row['title_raw'], 'Edited as stored.' );

        $saved = $controller->update_item( $this->patch( array( 'title' => $row['title_raw'] ) ) );

        $this->assertSame( $stored, $written[0]['post_title'], 'The same bytes are written back.' );
        $this->assertSame( $stored, $saved['title_raw'] );
        $this->assertSame( $row['title'], $saved['title'] );
    }

    /**
     * A tag that cannot be created stops the quick edit before the article is written.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_failed_new_tag_writes_nothing() {
        Functions\when( 'get_post' )->justReturn( $this->post() );
        Functions\when( 'wp_slash' )->returnArg();
        Functions\when( 'wp_insert_term' )->justReturn( new WP_Error( 'term_exists', 'No.' ) );
        Functions\expect( 'wp_update_post' )->never();
        Functions\expect( 'wp_set_object_terms' )->never();

        $result = $this->controller->update_item(
            $this->patch(
                array(
                    'title' => 'Renamed',
                    'tags'  => array( array( 'name' => 'Fresh' ) ),
                )
            )
        );

        $this->assertInstanceOf( 'WP_Error', $result );
        $this->assertSame( 'term_exists', $result->get_error_code() );
    }

    /**
     * A new tag typed in the quick edit keeps its backslashes.
     *
     * The REST value arrives unslashed, and both `term_exists()` and
     * `wp_insert_term()` unslash the name, so `Paths C:\Temp` would otherwise be
     * looked up and created as `Paths C:Temp`.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_new_tag_keeps_every_backslash() {
        $looked_up = array();
        $created   = array();

        Functions\when( 'get_post' )->justReturn( $this->post() );
        Functions\when( 'term_exists' )->alias(
            function ( $term ) use ( &$looked_up ) {
                $looked_up[] = self::core_unslash( $term );

                return null;
            }
        );
        Functions\when( 'wp_insert_term' )->alias(
            function ( $name ) use ( &$created ) {
                $created[] = self::core_unslash( $name );

                return array( 'term_id' => 40 );
            }
        );
        Functions\when( 'wp_set_object_terms' )->justReturn( new WP_Error( 'stop', 'Stop here.' ) );

        $this->controller->update_item( $this->patch( array( 'tags' => array( array( 'name' => 'Paths C:\Temp' ) ) ) ) );

        $this->assertSame( array( 'Paths C:\Temp' ), $looked_up );
        $this->assertSame( array( 'Paths C:\Temp' ), $created );
    }

    /**
     * Publishing, changing the author and creating tags each need their capability.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_quick_edit_checks_the_extra_capabilities() {
        $this->denied = array( 'publish_posts' );
        $this->assertSame( 403, $this->controller->collect_changes( $this->patch( array( 'status' => 'publish' ) ), $this->post() )->get_error_data()['status'] );
        $this->assertIsArray( $this->controller->collect_changes( $this->patch( array( 'status' => 'pending' ) ), $this->post() ) );

        $this->denied = array( 'edit_others_posts' );
        $this->assertSame( 403, $this->controller->collect_changes( $this->patch( array( 'author' => 2 ) ), $this->post() )->get_error_data()['status'] );
        $this->assertIsArray( $this->controller->collect_changes( $this->patch( array( 'author' => 1 ) ), $this->post() ), 'Sending the same author is not a change.' );

        $this->denied = array( 'edit_licensio_tag' );
        $result       = $this->controller->collect_changes( $this->patch( array( 'tags' => array( array( 'name' => 'New' ) ) ) ), $this->post() );
        $this->assertSame( array( 'tags' ), array_keys( $result->get_error_data()['params'] ) );

        $this->denied = array( 'assign_licensio_category' );
        $this->assertSame( 403, $this->controller->collect_changes( $this->patch( array( 'categories' => array( 3 ) ) ), $this->post() )->get_error_data()['status'] );
    }

    /**
     * Quick edit needs edit_post on the article itself.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_quick_edit_permission_needs_edit_post() {
        $this->assertTrue( $this->controller->update_item_permissions_check( $this->patch( array() ) ) );

        $this->denied = array( 'edit_post' );
        $this->assertInstanceOf( 'WP_Error', $this->controller->update_item_permissions_check( $this->patch( array() ) ) );

        // Authors and contributors do not manage the knowledge base, but still edit their own articles.
        $this->denied = array( 'manage_licensio_kb', 'manage_options' );
        $this->assertTrue( $this->controller->update_item_permissions_check( $this->patch( array() ) ) );

        $this->denied = array( 'edit_posts' );
        $this->assertInstanceOf( 'WP_Error', $this->controller->update_item_permissions_check( $this->patch( array() ) ) );
    }

    /**
     * The routes need the article capability, not the management one.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_article_writers_may_use_the_routes() {
        $request = new WP_REST_Request( array(), '/licensio-kb/v1/articles', array() );

        $this->denied = array( 'manage_licensio_kb', 'manage_options' );
        $this->assertTrue( $this->controller->edit_articles_permissions_check( $request ) );

        $this->denied = array( 'edit_posts' );
        $this->assertInstanceOf( 'WP_Error', $this->controller->edit_articles_permissions_check( $request ) );
    }

    /**
     * The author search matches display names and nicenames, never login names.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_author_search_never_matches_login_names() {
        $this->controller->get_authors(
            new WP_REST_Request(
                array(),
                '/licensio-kb/v1/authors',
                array(
                    'search'   => 'adm',
                    'include'  => array(),
                    'page'     => 1,
                    'per_page' => 20,
                )
            )
        );

        $this->assertSame( '*adm*', WP_User_Query::$last_args['search'] );
        $this->assertSame( array( 'user_nicename', 'display_name' ), WP_User_Query::$last_args['search_columns'] );
    }

    /**
     * Only users who may edit others' articles may list the authors.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_author_list_needs_edit_others() {
        $request = new WP_REST_Request( array(), '/licensio-kb/v1/authors', array() );

        $this->assertTrue( $this->controller->authors_permissions_check( $request ) );

        $this->denied = array( 'edit_others_posts' );
        $this->assertInstanceOf( 'WP_Error', $this->controller->authors_permissions_check( $request ) );
    }

    /**
     * A user who may not edit others' articles only lists their own.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_writer_without_edit_others_only_lists_their_own_articles() {
        Functions\when( 'get_current_user_id' )->justReturn( 7 );

        $this->assertArrayNotHasKey( 'author', $this->controller->query_args( array() ) );
        $this->assertSame( 5, $this->controller->query_args( array( 'author' => 5 ) )['author'] );

        $this->denied = array( 'edit_others_posts' );

        $this->assertSame( 7, $this->controller->query_args( array() )['author'] );
        $this->assertSame( 7, $this->controller->query_args( array( 'author' => 5 ) )['author'], 'Naming another author must not widen the list.' );
        $this->assertSame( 7, $this->controller->query_args( array( 'author' => 5 ), true )['author'], 'Select all matching is limited too.' );
    }

    /**
     * The status pills count only the writer's own articles.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_status_counts_are_the_writers_own() {
        $previous = isset( $GLOBALS['wpdb'] ) ? $GLOBALS['wpdb'] : null;

        // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- A double for the grouped count query.
        $GLOBALS['wpdb'] = new class() {

            /**
             * The posts table name.
             *
             * @since 1.0.0
             * @var string
             */
            public $posts = 'wp_posts';

            /**
             * The last query run.
             *
             * @since 1.0.0
             * @var string
             */
            public $last = '';

            /**
             * Fill the placeholders, like wpdb::prepare().
             *
             * @since 1.0.0
             *
             * @param string $query   The SQL with placeholders.
             * @param mixed  ...$args The values.
             *
             * @return string The SQL.
             */
            public function prepare( $query, ...$args ) {
                return vsprintf( str_replace( array( '%i', '%s', '%d' ), array( '%s', "'%s'", '%d' ), $query ), $args );
            }

            /**
             * Answer the grouped count.
             *
             * @since 1.0.0
             *
             * @param string $query The SQL.
             *
             * @return array<int, object> The rows.
             */
            public function get_results( $query ) {
                $this->last = $query;

                return array(
                    (object) array(
                        'post_status' => 'draft',
                        'num'         => '2',
                    ),
                    (object) array(
                        'post_status' => 'pending',
                        'num'         => '1',
                    ),
                    (object) array(
                        'post_status' => 'trash',
                        'num'         => '3',
                    ),
                );
            }
        };

        Functions\when( 'get_current_user_id' )->justReturn( 7 );
        Functions\expect( 'wp_count_posts' )->never();

        $this->denied = array( 'edit_others_posts' );

        try {
            $counts = Licensio_KB_Articles_REST_Controller::status_counts();
            $query  = $GLOBALS['wpdb']->last;
        } finally {
            $GLOBALS['wpdb'] = $previous; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Puts the original back.
        }

        $this->assertStringContainsString( 'post_author = 7', $query );
        $this->assertSame(
            array(
                'all'     => 3,
                'publish' => 0,
                'future'  => 0,
                'draft'   => 2,
                'pending' => 1,
                'private' => 0,
                'trash'   => 3,
            ),
            $counts
        );
    }

    /**
     * The metrics filter leaves any query without the metrics query var alone.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_metrics_filter_only_touches_its_own_query() {
        $metrics = new Licensio_KB_Article_Metrics();
        $clauses = array(
            'join'    => '',
            'where'   => ' AND 1=1',
            'orderby' => 'post_date DESC',
        );

        $this->assertSame( $clauses, $metrics->filter_clauses( $clauses, new WP_Query( array( 'post_type' => 'post' ) ) ) );
        $this->assertSame( $clauses, $metrics->filter_clauses( $clauses, null ) );
        $this->assertSame( 30, Licensio_KB_Article_Metrics::normalize_window( 14 ) );
        $this->assertSame( 7, Licensio_KB_Article_Metrics::normalize_window( '7' ) );
    }

    /**
     * The low rating rule: below the threshold, with at least the minimum votes.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_low_rating_rule() {
        $metrics = new Licensio_KB_Article_Metrics( 50, 5 );

        $this->assertTrue(
            $metrics->needs_attention(
                array(
                    'up'   => 2,
                    'down' => 3,
                )
            )
        );
        $this->assertFalse(
            $metrics->needs_attention(
                array(
                    'up'   => 1,
                    'down' => 3,
                )
            ),
            'Four votes is under the minimum.'
        );
        $this->assertFalse(
            $metrics->needs_attention(
                array(
                    'up'   => 5,
                    'down' => 5,
                )
            ),
            'Exactly the threshold is not below it.'
        );
        $this->assertSame( 99, ( new Licensio_KB_Article_Metrics( 150, 0 ) )->threshold() );
        $this->assertSame( 1, ( new Licensio_KB_Article_Metrics( 150, 0 ) )->min_votes() );
    }
}

/**
 * The articles controller with its post query replaced.
 *
 * @since 1.0.0
 */
class Licensio_KB_Articles_REST_Controller_Double extends Licensio_KB_Articles_REST_Controller {

    /**
     * The queries the controller gets back, in turn; the last one repeats.
     *
     * @since 1.0.0
     * @var WP_Query[]
     */
    private $results;

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
        parent::__construct( new Licensio_KB_Article_Metrics( 50, 5 ), new Licensio_KB_Article_Bulk() );

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
    protected function run_query( array $args ) {
        $this->calls[] = $args;

        return $this->results[ min( count( $this->calls ), count( $this->results ) ) - 1 ];
    }
}
