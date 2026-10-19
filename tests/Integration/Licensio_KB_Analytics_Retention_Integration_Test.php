<?php
/**
 * The retention sweep, against a real database server.
 *
 * A double cannot answer whether a bounded `DELETE ... LIMIT` removed the rows
 * it was supposed to and left the others alone: it can only report the number
 * it was told to report. These tests seed real rows on both sides of the cutoff
 * and count what survives.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

// phpcs:disable WordPress.DB.RestrictedClasses, WordPress.DB.RestrictedFunctions, WordPress.DB.DirectDatabaseQuery

require_once __DIR__ . '/Licensio_KB_Analytics_DB_Test_Case.php';

/**
 * Drives Licensio_KB_Analytics_Retention at a live server.
 *
 * @since 1.0.0
 */
class Licensio_KB_Analytics_Retention_Integration_Test extends Licensio_KB_Analytics_DB_Test_Case {

    /**
     * Seed search log rows on both sides of a 30-day cutoff.
     *
     * Deliberately more than two chunks' worth of expired rows, so a sweep that
     * stopped after one statement would leave rows behind and be caught.
     *
     * @since 1.0.0
     *
     * @param int $expired  How many expired rows to write.
     * @param int $retained How many in-window rows to write.
     *
     * @return void
     */
    private function seed_search_log( $expired, $retained ) {
        $values = array();

        for ( $index = 0; $index < $expired; $index++ ) {
            $values[] = sprintf( "('old-%d', 'old-%d', 1, 'h', 'site', '%s')", $index, $index, $this->days_ago( 200 ) );
        }

        for ( $index = 0; $index < $retained; $index++ ) {
            $values[] = sprintf( "('new-%d', 'new-%d', 1, 'h', 'site', '%s')", $index, $index, $this->days_ago( 1 ) );
        }

        $this->assertTrue(
            (bool) $this->db->query(
                'INSERT INTO ' . $this->table( 'licensio_search_log' )
                . ' (term, normalized_term, results_count, visitor_hash, source, created_at) VALUES '
                . implode( ',', $values )
            ),
            $this->db->error
        );
    }

    /**
     * Build the job with a fixed retention window.
     *
     * @since 1.0.0
     *
     * @param mixed $days The raw stored retention value.
     *
     * @return Licensio_KB_Analytics_Retention The job.
     */
    private function retention( $days ) {
        return new Licensio_KB_Analytics_Retention(
            $this->settings( array( 'analytics_retention_days' => $days ) )
        );
    }

    /**
     * Expired rows go and in-window rows stay.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_sweep_removes_only_expired_rows() {
        $this->seed_search_log( 1200, 40 );

        $deleted = $this->retention( 30 )->run();

        $this->assertSame( 1200, $deleted['licensio_search_log'] );
        $this->assertSame( 40, $this->count_rows( 'licensio_search_log' ) );
        $this->assertSame(
            0,
            $this->count_rows( 'licensio_search_log', "term LIKE 'old-%'" ),
            'An expired row survived the sweep.'
        );
        $this->assertSame(
            40,
            $this->count_rows( 'licensio_search_log', "term LIKE 'new-%'" ),
            'An in-window row was deleted.'
        );
    }

    /**
     * A row exactly on the cutoff is kept.
     *
     * The comparison is strictly less than the cutoff, so the boundary row
     * belongs to the window rather than to history.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_row_on_the_cutoff_is_kept() {
        $retention = $this->retention( 30 );
        $cutoff    = $retention->cutoff();

        $this->seed(
            'licensio_search_log',
            array(
                array(
                    'term'            => 'on-the-cutoff',
                    'normalized_term' => 'on-the-cutoff',
                    'results_count'   => 1,
                    'visitor_hash'    => 'h',
                    'source'          => 'site',
                    'created_at'      => $cutoff,
                ),
                array(
                    'term'            => 'one-second-earlier',
                    'normalized_term' => 'one-second-earlier',
                    'results_count'   => 1,
                    'visitor_hash'    => 'h',
                    'source'          => 'site',
                    'created_at'      => gmdate( 'Y-m-d H:i:s', strtotime( $cutoff . ' UTC' ) - 1 ),
                ),
            )
        );

        $retention->run();

        $this->assertSame( 1, $this->count_rows( 'licensio_search_log' ) );
        $this->assertSame( 1, $this->count_rows( 'licensio_search_log', "term = 'on-the-cutoff'" ) );
    }

    /**
     * The delete really is chunked, and every statement is bounded.
     *
     * Row counts alone cannot tell one unbounded `DELETE` from a series of
     * bounded ones, and the difference is the whole point: an unbounded delete
     * over months of rows holds its locks for as long as it takes.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_delete_is_issued_in_bounded_chunks() {
        $this->seed_search_log( 1200, 0 );

        $before = count( $this->adapter->queries );

        $this->retention( 30 )->run();

        $deletes = array_values(
            array_filter(
                array_slice( $this->adapter->queries, $before ),
                function ( $sql ) {
                    return 0 === stripos( ltrim( $sql ), 'DELETE' )
                        && false !== stripos( $sql, 'licensio_search_log' );
                }
            )
        );

        $this->assertGreaterThan( 1, count( $deletes ), 'The sweep issued a single unbounded DELETE.' );

        foreach ( $deletes as $sql ) {
            $this->assertStringContainsString(
                'LIMIT ' . Licensio_KB_Analytics_Retention::CHUNK_SIZE,
                $sql,
                'A DELETE went out without a chunk bound: ' . $sql
            );
        }
    }

    /**
     * All three visitor tables are swept, and the search index is not.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_every_visitor_table_is_swept_and_the_index_is_not() {
        $expired = $this->days_ago( 200 );

        $this->seed(
            'licensio_search_log',
            array(
                array(
                    'term'            => 'old',
                    'normalized_term' => 'old',
                    'results_count'   => 0,
                    'visitor_hash'    => 'h',
                    'source'          => 'site',
                    'created_at'      => $expired,
                ),
            )
        );
        $this->seed(
            'licensio_article_views',
            array(
                array(
                    'article_id'   => 7,
                    'visitor_hash' => 'h',
                    'created_at'   => $expired,
                ),
            )
        );
        $this->seed(
            'licensio_feedback',
            array(
                array(
                    'article_id'   => 7,
                    'vote'         => 1,
                    'comment'      => '',
                    'visitor_hash' => 'h',
                    'user_id'      => 0,
                    'status'       => 'new',
                    'created_at'   => $expired,
                ),
            )
        );
        $this->seed(
            'licensio_search_index',
            array(
                array(
                    'article_id' => 7,
                    'title'      => 'Old but published',
                    'excerpt'    => '',
                    'content'    => 'Still the current content of a live article.',
                    'terms'      => '',
                    'updated_at' => $expired,
                ),
            )
        );

        $deleted = $this->retention( 30 )->run();

        $this->assertSame( 1, $deleted['licensio_search_log'] );
        $this->assertSame( 1, $deleted['licensio_article_views'] );
        $this->assertSame( 1, $deleted['licensio_feedback'] );

        $this->assertSame( 0, $this->count_rows( 'licensio_search_log' ) );
        $this->assertSame( 0, $this->count_rows( 'licensio_article_views' ) );
        $this->assertSame( 0, $this->count_rows( 'licensio_feedback' ) );
        $this->assertSame(
            1,
            $this->count_rows( 'licensio_search_index' ),
            'The search index mirrors published content and must not be expired.'
        );
    }

    /**
     * A stored zero keeps the 90-day window rather than deleting almost everything.
     *
     * Asserted against real rows: a row 45 days old is inside the default
     * window and outside a one-day one, so a clamp to 1 would delete it.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_zero_retention_does_not_delete_recent_history() {
        $this->seed(
            'licensio_search_log',
            array(
                array(
                    'term'            => 'forty-five-days-old',
                    'normalized_term' => 'forty-five-days-old',
                    'results_count'   => 1,
                    'visitor_hash'    => 'h',
                    'source'          => 'site',
                    'created_at'      => $this->days_ago( 45 ),
                ),
                array(
                    'term'            => 'a-hundred-days-old',
                    'normalized_term' => 'a-hundred-days-old',
                    'results_count'   => 1,
                    'visitor_hash'    => 'h',
                    'source'          => 'site',
                    'created_at'      => $this->days_ago( 100 ),
                ),
            )
        );

        $this->retention( 0 )->run();

        $this->assertSame(
            1,
            $this->count_rows( 'licensio_search_log', "term = 'forty-five-days-old'" ),
            'A stored 0 was treated as a one-day window and destroyed recent history.'
        );
        $this->assertSame(
            0,
            $this->count_rows( 'licensio_search_log', "term = 'a-hundred-days-old'" ),
            'Retention is bounded; a row past the default window must still go.'
        );
    }

    /**
     * A sweep with nothing to do deletes nothing and issues no delete.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_sweep_with_nothing_expired_deletes_nothing() {
        $this->seed_search_log( 0, 25 );

        $deleted = $this->retention( 30 )->run();

        $this->assertSame( 0, $deleted['licensio_search_log'] );
        $this->assertSame( 25, $this->count_rows( 'licensio_search_log' ) );
    }
}
