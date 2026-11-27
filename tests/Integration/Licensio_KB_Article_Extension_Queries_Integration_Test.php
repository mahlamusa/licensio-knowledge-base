<?php
/**
 * The query count of an Articles page with an extension column, against a real database server.
 *
 * An extension column's data comes from `licensio_kb_article_extension_data_batch`,
 * which gets the whole page at once, so a consumer can read every row in one
 * query. These tests prove the page then costs the same number of queries
 * whether it lists 5 articles or 20: `GET articles` runs on real rows through
 * the controller's own `get_items()` and `prepare_items()`, and every query
 * the plugin and the consumer send goes to the live adapter, which records it.
 *
 * No WordPress is loaded in this suite. The page query is answered by the
 * test translator of Licensio_KB_Articles_REST_Live_Controller, and the core
 * helpers `prepare_items()` reads from caches WP_Query has already primed
 * (users, terms, post meta for the edit lock, permalinks) are stubbed to send
 * no query, as they send none per row in WordPress either.
 *
 * A consumer that queries once per row is measured too, so the test is shown
 * to tell a growing count from a fixed one.
 *
 * @package Licensio_KB
 * @since   1.1.0
 */

/*
 * This suite connects to a real database on purpose. The WordPress Database API
 * is therefore not available or appropriate here.
 */
// phpcs:disable WordPress.DB.RestrictedClasses, WordPress.DB.RestrictedFunctions, WordPress.DB.DirectDatabaseQuery

require_once __DIR__ . '/Licensio_KB_Analytics_DB_Test_Case.php';
require_once __DIR__ . '/trait-licensio-kb-content-fixtures.php';
require_once __DIR__ . '/class-licensio-kb-articles-rest-live-controller.php';

use Brain\Monkey\Functions;

/**
 * Counts the queries of an Articles page with a batch-filled extension column.
 *
 * @since 1.1.0
 */
class Licensio_KB_Article_Extension_Queries_Integration_Test extends Licensio_KB_Analytics_DB_Test_Case {

    use Licensio_KB_Content_Fixtures;

    /**
     * The meta key the example consumer stores its score under.
     *
     * @since 1.1.0
     * @var string
     */
    const META_KEY = 'acme_score';

    /**
     * The extension column the consumer fills.
     *
     * @since 1.1.0
     * @var string
     */
    const COLUMN = 'acme/score';

    /**
     * Whether the consumer reads every row in one batch query, or one query per row.
     *
     * @since 1.1.0
     * @var bool
     */
    private $batched = true;

    /**
     * The extension column data of the last page, keyed by article ID.
     *
     * @since 1.1.0
     * @var array<int, mixed>
     */
    private $cells = array();

    /**
     * Add the content tables, declare the column and stub the cached core helpers.
     *
     * @since 1.1.0
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        $this->install_content_schemas();

        Functions\when( 'rest_ensure_response' )->returnArg();
        Functions\when( 'get_post_type_object' )->justReturn(
            (object) array(
                'cap' => (object) Licensio_KB_Capabilities::post_type_capabilities(),
            )
        );
        Functions\when( 'get_current_user_id' )->justReturn( 1 );
        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\when( 'get_user_meta' )->justReturn( array( 'columns' => array( 'author', self::COLUMN ) ) );
        Functions\when( 'wp_count_posts' )->justReturn( (object) array( 'publish' => '0' ) );
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
        Functions\when( 'wp_check_post_lock' )->justReturn( false );
        Functions\when( 'get_userdata' )->justReturn( (object) array( 'display_name' => 'Author' ) );
        Functions\when( 'get_the_terms' )->justReturn( false );
        Functions\when( 'get_post' )->justReturn( null );
        Functions\when( 'get_edit_post_link' )->justReturn( '' );
        Functions\when( 'get_permalink' )->justReturn( '' );
        Functions\when( 'get_preview_post_link' )->justReturn( '' );
        Functions\when( 'apply_filters' )->alias( array( $this, 'consumer' ) );
    }

    /**
     * The example consumer, standing in for `apply_filters()`.
     *
     * Fills the column from post meta: from one `IN (...)` query on the batch
     * hook when batched, otherwise from one query per row on the row hook.
     * Every other hook returns its value unchanged.
     *
     * @since 1.1.0
     *
     * @param string $hook  The hook name.
     * @param mixed  $value The value filtered.
     * @param mixed  ...$args The other arguments: the posts or post, then the shown keys.
     *
     * @return mixed The filtered value.
     */
    public function consumer( $hook, $value, ...$args ) {
        if ( 'licensio_kb_article_extension_data_batch' === $hook && $this->batched ) {
            $ids  = array_map( 'intval', wp_list_pluck( $args[0], 'ID' ) );
            $rows = $this->adapter->get_results(
                "SELECT post_id, meta_value FROM {$this->adapter->postmeta} WHERE meta_key = " . $this->adapter->quote( self::META_KEY )
                . ' AND post_id IN (' . implode( ', ', $ids ) . ')',
                ARRAY_A
            );

            foreach ( $rows as $row ) {
                $value[ (int) $row['post_id'] ][ self::COLUMN ] = array( 'text' => (string) $row['meta_value'] );
            }

            return $value;
        }

        if ( 'licensio_kb_article_extension_data' === $hook && ! $this->batched ) {
            $score = $this->adapter->get_var(
                "SELECT meta_value FROM {$this->adapter->postmeta} WHERE meta_key = " . $this->adapter->quote( self::META_KEY )
                . ' AND post_id = ' . (int) $args[0]->ID
            );

            $value[ self::COLUMN ] = array( 'text' => (string) $score );
        }

        return $value;
    }

    /**
     * Make articles, each with a score.
     *
     * @since 1.1.0
     *
     * @param int $count How many.
     *
     * @return void
     */
    private function make_scored_articles( $count ) {
        for ( $i = 1; $i <= $count; $i++ ) {
            $id = $this->make_article();

            $this->seed(
                'postmeta',
                array(
                    array(
                        'post_id'    => $id,
                        'meta_key'   => self::META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- A fixture row, not a query.
                        'meta_value' => (string) ( $id * 10 ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- A fixture row, not a query.
                    ),
                )
            );
        }
    }

    /**
     * Load one page of `GET articles` and count the queries it sent.
     *
     * The controller is built with the declared column, and runs the real
     * `prepare_items()` rather than the translator's ID-only one.
     *
     * @since 1.1.0
     *
     * @param int $per_page The page size.
     *
     * @return int The number of queries the page sent.
     */
    private function page_queries( $per_page ) {
        $extensions = new Licensio_KB_Extensions();

        $extensions->add_column(
            Licensio_KB_Extensions::SCREEN_ARTICLES,
            self::COLUMN,
            array( 'label' => 'Score' )
        );

        $controller = new class( $this->adapter, new Licensio_KB_Article_Extension_Columns( $extensions ), new Licensio_KB_Extension_Filters( $extensions, Licensio_KB_Extensions::SCREEN_ARTICLES ) ) extends Licensio_KB_Articles_REST_Live_Controller {

            /**
             * Build the rows the way the plugin does.
             *
             * @since 1.1.0
             *
             * @param WP_Post[] $posts  The posts.
             * @param int       $window The metrics window, in days.
             *
             * @return array<int, array<string, mixed>> The items.
             */
            public function prepare_items( array $posts, $window ) {
                return Licensio_KB_Articles_REST_Controller::prepare_items( $posts, $window );
            }
        };

        $before = count( $this->adapter->queries );
        $page   = $controller->get_items( new WP_REST_Request( array(), '/licensio-kb/v1/articles', array( 'per_page' => $per_page ) ) );
        $sent   = count( $this->adapter->queries ) - $before;

        $this->assertCount( $per_page, $page['items'] );

        $this->cells = array();

        foreach ( $page['items'] as $item ) {
            $this->cells[ $item['id'] ] = (array) $item['extensions'];
        }

        return $sent;
    }

    /**
     * A page of 20 rows costs the same number of queries as a page of 5.
     *
     * @since 1.1.0
     *
     * @return void
     */
    public function test_a_batch_filled_column_costs_the_same_queries_for_5_and_20_rows() {
        $this->make_scored_articles( 20 );

        $five   = $this->page_queries( 5 );
        $twenty = $this->page_queries( 20 );

        $this->assertSame( $five, $twenty, 'The query count does not grow with the rows.' );

        foreach ( $this->cells as $id => $cells ) {
            $this->assertSame( array( self::COLUMN => array( 'text' => (string) ( $id * 10 ) ) ), $cells, 'Every row carries its own score.' );
        }
    }

    /**
     * A consumer that queries per row makes the count grow, so the test above can fail.
     *
     * @since 1.1.0
     *
     * @return void
     */
    public function test_a_per_row_consumer_makes_the_count_grow() {
        $this->make_scored_articles( 20 );
        $this->batched = false;

        $five   = $this->page_queries( 5 );
        $twenty = $this->page_queries( 20 );

        $this->assertSame( 15, $twenty - $five, 'One more query for each extra row.' );
    }
}
