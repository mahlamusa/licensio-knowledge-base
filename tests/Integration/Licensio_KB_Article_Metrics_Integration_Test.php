<?php
/**
 * The article list's metrics SQL, against a real database server.
 *
 * Sorting by views or rating and the Needs attention filter are derived-table
 * joins the Articles list adds to WP_Query through `posts_clauses`. Whether
 * they put the right rows in the right order for a 7, 30 or 90 day window is
 * a question only a server can answer, so the clauses are run here exactly as
 * Licensio_KB_Article_Metrics builds them, inside the same SELECT shape
 * WP_Query wraps them in. The per-page figures and attachment counts are run
 * the same way, with the number of queries they cost counted.
 *
 * The filters that WP_Query itself turns into SQL (status, search, taxonomy,
 * author and dates) are covered by the unit tests of the arguments the
 * controller hands it; this suite has no WordPress to run WP_Query.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

/*
 * This suite connects to a real database on purpose. The WordPress Database API
 * is therefore not available or appropriate here.
 */
// phpcs:disable WordPress.DB.RestrictedClasses, WordPress.DB.RestrictedFunctions, WordPress.DB.DirectDatabaseQuery

require_once __DIR__ . '/Licensio_KB_Analytics_DB_Test_Case.php';
require_once __DIR__ . '/trait-licensio-kb-content-fixtures.php';

/**
 * Runs Licensio_KB_Article_Metrics against a live server.
 *
 * @since 1.0.0
 */
class Licensio_KB_Article_Metrics_Integration_Test extends Licensio_KB_Analytics_DB_Test_Case {

    use Licensio_KB_Content_Fixtures;

    /**
     * Add the core post and term tables.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        $this->install_content_schemas();
    }

    /**
     * Run an article list query with the metrics clauses, as WP_Query would.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $spec      The metrics spec.
     * @param int                  $threshold The low rating threshold.
     * @param int                  $min_votes The minimum votes.
     *
     * @return int[] The article IDs, in order.
     */
    private function list_ids( array $spec, $threshold = 50, $min_votes = 5 ) {
        $posts   = $this->table( 'posts' );
        $metrics = new Licensio_KB_Article_Metrics( $threshold, $min_votes );
        $clauses = $metrics->clauses(
            array(
                'join'    => '',
                'where'   => " AND {$posts}.post_type = 'licensio_article' AND {$posts}.post_status = 'publish'",
                'orderby' => "{$posts}.post_date DESC, {$posts}.ID DESC",
            ),
            $spec
        );

        $sql = "SELECT SQL_CALC_FOUND_ROWS {$posts}.ID FROM {$posts} {$clauses['join']} WHERE 1=1 {$clauses['where']} ORDER BY {$clauses['orderby']} LIMIT 0, 50";

        return array_map( 'intval', $this->adapter->get_col( $sql ) );
    }

    /**
     * Sorting by views counts only the views inside the window.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_sorting_by_views_follows_the_window() {
        $recent = $this->make_article( array( 'title' => 'Recent' ) );
        $old    = $this->make_article( array( 'title' => 'Old' ) );
        $none   = $this->make_article( array( 'title' => 'Unread' ) );

        $this->add_views( $recent, 5, 3 );
        $this->add_views( $old, 3, 3 );
        $this->add_views( $old, 20, 60 );

        $this->assertSame(
            array( $recent, $old, $none ),
            $this->list_ids(
                array(
                    'orderby' => 'views',
                    'window'  => 7,
                )
            )
        );
        $this->assertSame(
            array( $recent, $old, $none ),
            $this->list_ids(
                array(
                    'orderby' => 'views',
                    'window'  => 30,
                )
            )
        );
        $this->assertSame(
            array( $old, $recent, $none ),
            $this->list_ids(
                array(
                    'orderby' => 'views',
                    'window'  => 90,
                )
            )
        );
        $this->assertSame(
            array( $none, $recent, $old ),
            $this->list_ids(
                array(
                    'orderby' => 'views',
                    'order'   => 'ASC',
                    'window'  => 90,
                )
            )
        );
    }

    /**
     * Sorting by rating uses the helpful share in the window; unrated articles come last.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_sorting_by_rating_follows_the_window() {
        $loved   = $this->make_article( array( 'title' => 'Loved' ) );
        $mixed   = $this->make_article( array( 'title' => 'Mixed' ) );
        $unrated = $this->make_article( array( 'title' => 'Unrated' ) );
        $turned  = $this->make_article( array( 'title' => 'Turned' ) );

        $this->add_votes( $loved, 9, 1, 2 );
        $this->add_votes( $mixed, 1, 1, 2 );
        $this->add_votes( $turned, 0, 4, 2 );
        $this->add_votes( $turned, 40, 0, 50 );

        $this->assertSame(
            array( $loved, $mixed, $turned, $unrated ),
            $this->list_ids(
                array(
                    'orderby' => 'rating',
                    'window'  => 30,
                )
            )
        );
        $this->assertSame(
            array( $turned, $mixed, $loved, $unrated ),
            $this->list_ids(
                array(
                    'orderby' => 'rating',
                    'order'   => 'ASC',
                    'window'  => 30,
                )
            ),
            'Unrated articles stay last in either direction.'
        );
        $this->assertSame(
            $turned,
            $this->list_ids(
                array(
                    'orderby' => 'rating',
                    'window'  => 90,
                )
            )[0],
            'Over 90 days the old praise counts.'
        );
    }

    /**
     * Needs attention: below the threshold, with at least the minimum votes, in the window.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_needs_attention_applies_the_threshold_and_minimum() {
        $poor     = $this->make_article( array( 'title' => 'Poor' ) );
        $few      = $this->make_article( array( 'title' => 'Few votes' ) );
        $even     = $this->make_article( array( 'title' => 'Even' ) );
        $old_poor = $this->make_article( array( 'title' => 'Poor long ago' ) );
        $quiet    = $this->make_article( array( 'title' => 'No votes' ) );
        $draft    = $this->make_article(
            array(
                'title'  => 'Poor draft',
                'status' => 'draft',
            )
        );

        $this->add_votes( $poor, 1, 5, 2 );
        $this->add_votes( $few, 0, 4, 2 );
        $this->add_votes( $even, 3, 3, 2 );
        $this->add_votes( $old_poor, 0, 9, 45 );
        $this->add_votes( $draft, 0, 9, 2 );

        $this->assertSame( array( $poor ), $this->list_ids( array( 'needs_attention' => true ) ) );
        $this->assertSame(
            array( $old_poor, $poor ),
            $this->list_ids(
                array(
                    'needs_attention' => true,
                    'window'          => 90,
                )
            )
        );
        $this->assertSame( array( $few, $poor ), $this->list_ids( array( 'needs_attention' => true ), 50, 4 ), 'A lower minimum lets four votes count.' );
        $this->assertSame( array( $even, $poor ), $this->list_ids( array( 'needs_attention' => true ), 60, 5 ), 'A higher threshold catches an even split.' );
        $this->assertNotContains( $quiet, $this->list_ids( array( 'needs_attention' => true ), 99, 1 ) );
    }

    /**
     * Needs attention combines with a metric sort.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_needs_attention_combines_with_a_views_sort() {
        $a = $this->make_article();
        $b = $this->make_article();

        $this->add_votes( $a, 0, 6, 1 );
        $this->add_votes( $b, 1, 6, 1 );
        $this->add_views( $a, 1, 1 );
        $this->add_views( $b, 4, 1 );

        $this->assertSame(
            array( $b, $a ),
            $this->list_ids(
                array(
                    'needs_attention' => true,
                    'orderby'         => 'views',
                )
            )
        );
    }

    /**
     * One page's figures are windowed and cost two queries whatever its size.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_page_figures_are_windowed_and_grouped() {
        $ids = array();

        for ( $i = 0; $i < 40; $i++ ) {
            $ids[] = $this->make_article();
        }

        $this->add_views( $ids[0], 3, 2 );
        $this->add_views( $ids[0], 6, 40 );
        $this->add_votes( $ids[1], 3, 1, 5 );
        $this->add_votes( $ids[1], 0, 7, 20 );

        $metrics = new Licensio_KB_Article_Metrics();

        $this->adapter->queries = array();
        $week                   = $metrics->for_articles( $ids, 7 );
        $this->assertCount( 2, $this->adapter->queries );

        $this->assertSame(
            array(
                'views' => 3,
                'up'    => 0,
                'down'  => 0,
                'share' => null,
            ),
            $week[ $ids[0] ]
        );
        $this->assertSame(
            array(
                'views' => 0,
                'up'    => 3,
                'down'  => 1,
                'share' => 75,
            ),
            $week[ $ids[1] ]
        );
        $this->assertCount( 40, $week );

        $quarter = $metrics->for_articles( $ids, 90 );

        $this->assertSame( 9, $quarter[ $ids[0] ]['views'] );
        $this->assertSame( 27, $quarter[ $ids[1] ]['share'] );
    }

    /**
     * Attachment counts come from one grouped query.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_attachment_counts_come_from_one_query() {
        $with    = $this->make_article();
        $without = $this->make_article();
        $rows    = array();

        for ( $i = 0; $i < 3; $i++ ) {
            $rows[] = array(
                'post_content'          => '',
                'post_title'            => 'File ' . $i,
                'post_excerpt'          => '',
                'to_ping'               => '',
                'pinged'                => '',
                'post_content_filtered' => '',
                'post_status'           => 'inherit',
                'post_type'             => 'attachment',
                'post_parent'           => $with,
            );
        }

        $this->seed( 'posts', $rows );

        $this->adapter->queries = array();

        $this->assertSame(
            array(
                $with    => 3,
                $without => 0,
            ),
            ( new Licensio_KB_Article_Metrics() )->attachment_counts( array( $with, $without ) )
        );
        $this->assertCount( 1, $this->adapter->queries );
    }

    /**
     * A list page costs the same number of metric queries for 5 rows as for 40.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_list_page_costs_a_fixed_number_of_queries() {
        $ids = array();

        for ( $i = 0; $i < 40; $i++ ) {
            $ids[] = $this->make_article();
            $this->add_views( $ids[ $i ], 1, 1 );
        }

        $metrics = new Licensio_KB_Article_Metrics();
        $cost    = function ( array $page ) use ( $metrics ) {
            $this->adapter->queries = array();
            $metrics->for_articles( $page, 30 );
            $metrics->attachment_counts( $page );

            return count( $this->adapter->queries );
        };

        $this->assertSame( 3, $cost( array_slice( $ids, 0, 5 ) ) );
        $this->assertSame( 3, $cost( $ids ) );
        $this->assertSame( 0, $cost( array() ), 'An empty page asks nothing.' );
    }
}
