<?php
/**
 * The aggregation queries, against a real database server.
 *
 * A `$wpdb` double can be told to return whatever a test expects, which makes a
 * `GROUP BY` test with a double a test of the double. These run the plugin's own
 * SQL at MySQL and MariaDB and check the numbers that come back.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

// phpcs:disable WordPress.DB.RestrictedClasses, WordPress.DB.RestrictedFunctions, WordPress.DB.DirectDatabaseQuery

require_once __DIR__ . '/Licensio_KB_Analytics_DB_Test_Case.php';

/**
 * Drives Licensio_KB_Analytics_Repository at a live server.
 *
 * @since 1.0.0
 */
class Licensio_KB_Analytics_Aggregation_Test extends Licensio_KB_Analytics_DB_Test_Case {

    /**
     * The start of the range every test reports over.
     *
     * @since 1.0.0
     * @var string
     */
    const RANGE_START = '2026-08-01 00:00:00';

    /**
     * The end of the range every test reports over.
     *
     * @since 1.0.0
     * @var string
     */
    const RANGE_END = '2026-08-30 23:59:59';

    /**
     * Seed ten searches, three of which returned nothing.
     *
     * Two further zero-result searches sit outside the range, so a query that
     * forgot its date bounds would compute 58.3% instead of 70%.
     *
     * @since 1.0.0
     *
     * @return void
     */
    private function seed_searches() {
        $rows = array();

        // Seven searches that found something.
        foreach ( array( 'alpha', 'alpha', 'zulu', 'zulu', 'zulu', 'zulu', 'zulu' ) as $index => $term ) {
            $rows[] = array(
                'term'            => $term,
                'normalized_term' => $term,
                'results_count'   => 4,
                'visitor_hash'    => 'hash-' . ( $index % 3 ),
                'source'          => 'site',
                'created_at'      => '2026-08-10 09:00:00',
            );
        }

        // Three that found nothing.
        foreach ( array( 'mike', 'mike', 'mike' ) as $term ) {
            $rows[] = array(
                'term'            => $term,
                'normalized_term' => $term,
                'results_count'   => 0,
                'visitor_hash'    => 'hash-1',
                'source'          => 'site',
                'created_at'      => '2026-08-11 09:00:00',
            );
        }

        // Outside the range entirely.
        $rows[] = array(
            'term'            => 'ancient',
            'normalized_term' => 'ancient',
            'results_count'   => 0,
            'visitor_hash'    => 'hash-9',
            'source'          => 'site',
            'created_at'      => '2026-07-01 09:00:00',
        );
        $rows[] = array(
            'term'            => 'future',
            'normalized_term' => 'future',
            'results_count'   => 0,
            'visitor_hash'    => 'hash-9',
            'source'          => 'site',
            'created_at'      => '2026-09-15 09:00:00',
        );

        $this->seed( 'licensio_search_log', $rows );
    }

    /**
     * The effectiveness percentage matches a hand computation.
     *
     * Ten searches in range, three of which returned nothing:
     * (10 - 3) / 10 * 100 = 70.0.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_effectiveness_percentage_matches_a_hand_computation() {
        $this->seed_searches();

        $overview = Licensio_KB_Analytics_Repository::overview( self::RANGE_START, self::RANGE_END );

        $this->assertSame( 10, $overview['total_searches'] );
        $this->assertSame( 3, $overview['zero_result_searches'] );
        $this->assertSame( 70.0, $overview['effectiveness'] );
    }

    /**
     * Rows outside the range are not counted.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_rows_outside_the_range_are_not_counted() {
        $this->seed_searches();

        $this->assertSame( 12, $this->count_rows( 'licensio_search_log' ) );

        $overview = Licensio_KB_Analytics_Repository::overview( self::RANGE_START, self::RANGE_END );

        $this->assertSame( 10, $overview['total_searches'] );
        $this->assertSame( 3, $overview['unique_terms'] );
    }

    /**
     * Unique visitors are counted by distinct hash, never listed.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_unique_visitors_are_counted_by_distinct_hash() {
        $this->seed_searches();

        $overview = Licensio_KB_Analytics_Repository::overview( self::RANGE_START, self::RANGE_END );

        // hash-0, hash-1 and hash-2 appear in range; hash-9 only outside it.
        $this->assertSame( 3, $overview['search_visitors'] );
    }

    /**
     * No report row carries a visitor hash out of the database.
     *
     * The hash exists to count people, not to identify one. A report that
     * leaked it would hand an administrator a per-visitor activity trail the
     * plugin promises not to build.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_no_report_row_carries_a_visitor_hash() {
        $this->seed_searches();
        $this->seed(
            'licensio_article_views',
            array(
                array(
                    'article_id'   => 7,
                    'visitor_hash' => 'hash-secret',
                    'created_at'   => '2026-08-12 10:00:00',
                ),
            )
        );

        $reports = array(
            'top_searches'        => Licensio_KB_Analytics_Repository::top_searches( self::RANGE_START, self::RANGE_END ),
            'zero_results'        => Licensio_KB_Analytics_Repository::zero_result_searches( self::RANGE_START, self::RANGE_END ),
            'top_articles'        => Licensio_KB_Analytics_Repository::top_articles( self::RANGE_START, self::RANGE_END ),
            'daily_search_totals' => Licensio_KB_Analytics_Repository::daily_totals( 'licensio_search_log', self::RANGE_START, self::RANGE_END ),
        );

        foreach ( $reports as $name => $rows ) {
            $this->assertNotEmpty( $rows, sprintf( 'Report %s returned nothing, so nothing was proven.', $name ) );

            foreach ( $rows as $row ) {
                $this->assertStringNotContainsString(
                    'hash-',
                    wp_json_encode( $row ),
                    sprintf( 'Report %s carries a visitor hash.', $name )
                );
            }
        }
    }

    /**
     * Top searches are ranked by frequency, not by insertion or alphabet.
     *
     * The counts are chosen so the expected order — zulu, mike, alpha — is
     * neither the order the terms were inserted in nor their alphabetical
     * order in either direction. A ranking test whose expected order happens to
     * match its input order passes without the ORDER BY doing anything.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_top_searches_are_ranked_by_frequency() {
        $this->seed_searches();

        $rows = Licensio_KB_Analytics_Repository::top_searches( self::RANGE_START, self::RANGE_END );

        $this->assertSame(
            array( 'zulu', 'mike', 'alpha' ),
            array_column( $rows, 'normalized_term' )
        );
        $this->assertSame( 5, $rows[0]['searches'] );
        $this->assertSame( 3, $rows[1]['searches'] );
        $this->assertSame( 2, $rows[2]['searches'] );
    }

    /**
     * Top searches report how often a term came back empty.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_top_searches_report_their_empty_result_counts() {
        $this->seed_searches();

        $rows = Licensio_KB_Analytics_Repository::top_searches( self::RANGE_START, self::RANGE_END );
        $by   = array_column( $rows, null, 'normalized_term' );

        $this->assertSame( 3, $by['mike']['zero_results'] );
        $this->assertSame( 0, $by['zulu']['zero_results'] );
        $this->assertSame( 4.0, $by['zulu']['average_results'] );
    }

    /**
     * The zero-result report lists only searches that found nothing.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_zero_result_report_lists_only_empty_searches() {
        $this->seed_searches();

        $rows = Licensio_KB_Analytics_Repository::zero_result_searches( self::RANGE_START, self::RANGE_END );

        $this->assertSame( array( 'mike' ), array_column( $rows, 'normalized_term' ) );
        $this->assertSame( 3, $rows[0]['searches'] );
    }

    /**
     * Case and spacing variants of one question are one row.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_variants_of_one_term_group_together() {
        $this->seed(
            'licensio_search_log',
            array(
                array(
                    'term'            => 'Reset Password',
                    'normalized_term' => Licensio_KB_Analytics::normalize_term( 'Reset Password' ),
                    'results_count'   => 0,
                    'visitor_hash'    => 'a',
                    'source'          => 'site',
                    'created_at'      => '2026-08-10 09:00:00',
                ),
                array(
                    'term'            => 'reset   password',
                    'normalized_term' => Licensio_KB_Analytics::normalize_term( 'reset   password' ),
                    'results_count'   => 0,
                    'visitor_hash'    => 'b',
                    'source'          => 'site',
                    'created_at'      => '2026-08-11 09:00:00',
                ),
            )
        );

        $rows = Licensio_KB_Analytics_Repository::zero_result_searches( self::RANGE_START, self::RANGE_END );

        $this->assertCount( 1, $rows );
        $this->assertSame( 'reset password', $rows[0]['normalized_term'] );
        $this->assertSame( 2, $rows[0]['searches'] );
    }

    /**
     * A range with no searches at all scores zero, not a hundred.
     *
     * "Every search succeeded" is a claim about searches that happened, and
     * none did. Reporting 100% would put a green number on an empty dashboard.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_empty_range_scores_zero_effectiveness() {
        $overview = Licensio_KB_Analytics_Repository::overview( self::RANGE_START, self::RANGE_END );

        $this->assertSame( 0, $overview['total_searches'] );
        $this->assertSame( 0.0, $overview['effectiveness'] );
    }

    /**
     * Articles are ranked by views, not by ID or insertion order.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_articles_are_ranked_by_views() {
        $this->seed_views();

        $rows = Licensio_KB_Analytics_Repository::top_articles( self::RANGE_START, self::RANGE_END );

        $this->assertSame( array( 208, 305, 101 ), array_column( $rows, 'article_id' ) );
        $this->assertSame( 5, $rows[0]['views'] );
        $this->assertSame( 2, $rows[0]['visitors'] );
    }

    /**
     * Seed views for three articles, in an order that proves the sort.
     *
     * Insertion order is 101, 305, 208; ascending IDs are 101, 208, 305;
     * descending are 305, 208, 101. The expected ranking, 208, 305, 101,
     * matches none of them.
     *
     * @since 1.0.0
     *
     * @return void
     */
    private function seed_views() {
        $rows = array();

        // Article 101: three views, three distinct visitors.
        foreach ( array( 'v1', 'v2', 'v3' ) as $hash ) {
            $rows[] = array(
                'article_id'   => 101,
                'visitor_hash' => $hash,
                'created_at'   => '2026-08-05 10:00:00',
            );
        }

        // Article 305: four views.
        foreach ( array( 'v1', 'v1', 'v2', 'v3' ) as $hash ) {
            $rows[] = array(
                'article_id'   => 305,
                'visitor_hash' => $hash,
                'created_at'   => '2026-08-06 10:00:00',
            );
        }

        // Article 208: five views, two distinct visitors.
        foreach ( array( 'v1', 'v1', 'v1', 'v2', 'v2' ) as $hash ) {
            $rows[] = array(
                'article_id'   => 208,
                'visitor_hash' => $hash,
                'created_at'   => '2026-08-07 10:00:00',
            );
        }

        // One view outside the range.
        $rows[] = array(
            'article_id'   => 101,
            'visitor_hash' => 'v9',
            'created_at'   => '2026-06-01 10:00:00',
        );

        $this->seed( 'licensio_article_views', $rows );
    }

    /**
     * Seed feedback for three articles at three different usefulness scores.
     *
     * Insertion order is 101, 305, 208. Ascending IDs are 101, 208, 305 and
     * descending are 305, 208, 101. The expected most-useful ranking is
     * 208, 101, 305 and the least-useful is 305, 101, 208, so neither matches
     * the insertion order nor either ID ordering.
     *
     * @since 1.0.0
     *
     * @return void
     */
    private function seed_feedback() {
        $votes = array(
            101 => array( 1, 1, 1, -1 ),
            305 => array( 1, -1, -1, -1 ),
            208 => array( 1, 1, 1, 1 ),
        );

        $rows = array();

        foreach ( $votes as $article_id => $article_votes ) {
            foreach ( $article_votes as $index => $vote ) {
                $rows[] = array(
                    'article_id'   => $article_id,
                    'vote'         => $vote,
                    'comment'      => '',
                    'visitor_hash' => 'f' . $index,
                    'user_id'      => 0,
                    'status'       => 0 === $index ? 'reviewed' : 'new',
                    'created_at'   => '2026-08-08 10:00:00',
                );
            }
        }

        $this->seed( 'licensio_feedback', $rows );
    }

    /**
     * The most useful articles come back best first.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_most_useful_articles_are_ranked_best_first() {
        $this->seed_feedback();

        $rows = Licensio_KB_Analytics_Repository::articles_by_usefulness( self::RANGE_START, self::RANGE_END, 20, 'DESC' );

        $this->assertSame( array( 208, 101, 305 ), array_column( $rows, 'article_id' ) );
        $this->assertSame( 100.0, $rows[0]['usefulness'] );
        $this->assertSame( 75.0, $rows[1]['usefulness'] );
        $this->assertSame( 25.0, $rows[2]['usefulness'] );
    }

    /**
     * The least useful articles come back worst first.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_least_useful_articles_are_ranked_worst_first() {
        $this->seed_feedback();

        $rows = Licensio_KB_Analytics_Repository::articles_by_usefulness( self::RANGE_START, self::RANGE_END, 20, 'ASC' );

        $this->assertSame( array( 305, 101, 208 ), array_column( $rows, 'article_id' ) );
        $this->assertSame( 1, $rows[0]['positive'] );
        $this->assertSame( 3, $rows[0]['negative'] );
        $this->assertSame( 4, $rows[0]['votes'] );
    }

    /**
     * A limit really limits, and does not silently return everything.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_limit_is_applied() {
        $this->seed_feedback();

        $rows = Licensio_KB_Analytics_Repository::articles_by_usefulness( self::RANGE_START, self::RANGE_END, 2, 'DESC' );

        $this->assertCount( 2, $rows );
        $this->assertSame( array( 208, 101 ), array_column( $rows, 'article_id' ) );
    }

    /**
     * The feedback summary totals by vote and by triage status.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_feedback_summary_totals_by_vote_and_status() {
        $this->seed_feedback();

        $summary = Licensio_KB_Analytics_Repository::feedback_summary( self::RANGE_START, self::RANGE_END );

        $this->assertSame( 12, $summary['totals']['total'] );
        $this->assertSame( 8, $summary['totals']['positive'] );
        $this->assertSame( 4, $summary['totals']['negative'] );
        $this->assertSame( 3, $summary['totals']['articles'] );
        $this->assertSame( 66.7, $summary['totals']['usefulness'] );

        $this->assertSame(
            array(
                'new'      => 9,
                'reviewed' => 3,
            ),
            $summary['by_status']
        );
    }

    /**
     * Daily totals come back one row per day, oldest first.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_daily_totals_are_bucketed_by_day_oldest_first() {
        $this->seed_views();

        $trend = Licensio_KB_Analytics_Repository::daily_totals( 'licensio_article_views', self::RANGE_START, self::RANGE_END );

        $this->assertSame(
            array(
                array(
                    'date'  => '2026-08-05',
                    'total' => 3,
                ),
                array(
                    'date'  => '2026-08-06',
                    'total' => 4,
                ),
                array(
                    'date'  => '2026-08-07',
                    'total' => 5,
                ),
            ),
            $trend
        );
    }

    /**
     * View counts for the list table column are lifetime, not range-bounded.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_view_counts_are_lifetime_totals() {
        $this->seed_views();

        $counts = Licensio_KB_Analytics_Repository::view_counts( array( 101, 208, 999 ) );

        // Article 101 has three views in range and one outside it.
        $this->assertSame( 4, $counts[101] );
        $this->assertSame( 5, $counts[208] );
        $this->assertSame( 0, $counts[999], 'An article with no views must report 0, not be missing.' );
        $this->assertArrayNotHasKey( 305, $counts, 'Only the requested articles are counted.' );
    }

    /**
     * The column query asks only for the articles on screen.
     *
     * The PHP that reads the result already ignores rows it did not ask for,
     * so the row counts are right either way; what an unbounded query costs is
     * a GROUP BY over every view ever recorded, on every load of the article
     * list table. Only the statement itself can show the difference.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_column_query_is_bounded_to_the_requested_articles() {
        $this->seed_views();

        $before = count( $this->adapter->queries );

        Licensio_KB_Analytics_Repository::view_counts( array( 101 ) );

        $issued = array_values( array_slice( $this->adapter->queries, $before ) );

        $this->assertCount( 1, $issued, 'The column should cost exactly one query.' );
        $this->assertStringContainsString(
            "article_id IN ('101')",
            $issued[0],
            'The column query grouped the whole table instead of the articles on screen: ' . $issued[0]
        );
    }

    /**
     * Asking for no articles runs no query at all.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_counting_no_articles_runs_no_query() {
        $before = count( $this->adapter->queries );

        $this->assertSame( array(), Licensio_KB_Analytics_Repository::view_counts( array() ) );
        $this->assertCount( $before, $this->adapter->queries );
    }
}
