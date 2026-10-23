<?php
/**
 * Importing a real BetterDocs database into the real target schema.
 *
 * Everything here runs against a live server. BetterDocs' analytics tables and
 * the core tables are created on a scratch database, seeded with daily totals,
 * and imported by the importer's real code through the plugin's real gateway.
 *
 * Not covered, because the harness has no WordPress: the content phases (terms
 * and articles are seeded as the content phase would leave them; only the
 * per-category order an article import writes is driven, with the core write
 * functions backed by SQL), the score
 * recompute that closes the feedback phase, `run_batch()` and the redirect
 * map, and the driver's closing notes.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

// phpcs:disable WordPress.DB.RestrictedClasses, WordPress.DB.RestrictedFunctions, WordPress.DB.DirectDatabaseQuery

require_once __DIR__ . '/Licensio_KB_BetterDocs_Import_DB_Test_Case.php';
require_once __DIR__ . '/trait-licensio-kb-article-import-stubs.php';

/**
 * What actually lands in the target tables.
 *
 * @since 1.0.0
 */
class Licensio_KB_BetterDocs_Import_Integration_Test extends Licensio_KB_BetterDocs_Import_DB_Test_Case {

    use Licensio_KB_Article_Import_Stubs;

    /**
     * Seed a small BetterDocs site.
     *
     * Two BetterDocs articles (11 and 12) were imported as 900 and 901; article
     * 77 was not. The analytics rows are ordered the way BetterDocs writes them:
     * a day that is still open is the newest row.
     *
     * Expected once imported:
     * - reactions: 2 on article 900 (helpful) and 2 on 901 (not helpful), one
     *   orphaned reaction on 77.
     * - views: 5 on 900, 3 on 901, 4 orphaned on 77.
     * - searches: 3 for the first keyword, 4 for the second; two rows are
     *   skipped, one with an empty keyword and one whose keyword is gone.
     * - today's three rows are left for the next run.
     *
     * @since 1.0.0
     *
     * @return void
     */
    private function seed_betterdocs_site() {
        $this->seed_source_article( 11, 'install-a-licence' );
        $this->seed_source_article( 12, 'renew-a-licence' );
        $this->seed_imported_article( 900, 11, 'install-a-licence' );
        $this->seed_imported_article( 901, 12, 'renew-a-licence' );

        $this->seed(
            Licensio_KB_BetterDocs_Import_Schema::TABLE_ANALYTICS,
            array(
                array(
                    'id'           => 1,
                    'post_id'      => 11,
                    'impressions'  => 5,
                    'unique_visit' => 2,
                    'happy'        => 1,
                    'normal'       => 1,
                    'sad'          => 0,
                    'created_at'   => '2025-01-02',
                ),
                array(
                    'id'           => 2,
                    'post_id'      => 12,
                    'impressions'  => 3,
                    'unique_visit' => 3,
                    'happy'        => 0,
                    'normal'       => 0,
                    'sad'          => 2,
                    'created_at'   => '2025-01-03',
                ),
                array(
                    'id'           => 3,
                    'post_id'      => 77,
                    'impressions'  => 4,
                    'unique_visit' => 1,
                    'happy'        => 1,
                    'normal'       => 0,
                    'sad'          => 0,
                    'created_at'   => '2025-01-04',
                ),
                array(
                    'id'           => 4,
                    'post_id'      => 11,
                    'impressions'  => 0,
                    'unique_visit' => 0,
                    'happy'        => 0,
                    'normal'       => 0,
                    'sad'          => 0,
                    'created_at'   => '2025-01-05',
                ),
                array(
                    'id'           => 5,
                    'post_id'      => 11,
                    'impressions'  => 10,
                    'unique_visit' => 5,
                    'happy'        => 3,
                    'normal'       => 0,
                    'sad'          => 0,
                    'created_at'   => gmdate( 'Y-m-d' ),
                ),
            )
        );

        $this->seed(
            Licensio_KB_BetterDocs_Import_Schema::TABLE_SEARCH_KEYWORD,
            array(
                array(
                    'id'      => 1,
                    'keyword' => 'Reset  Password',
                ),
                array(
                    'id'      => 2,
                    'keyword' => 'licence key',
                ),
                array(
                    'id'      => 3,
                    'keyword' => '',
                ),
            )
        );

        $this->seed(
            Licensio_KB_BetterDocs_Import_Schema::TABLE_SEARCH_LOG,
            array(
                array(
                    'id'              => 1,
                    'keyword_id'      => 1,
                    'count'           => 2,
                    'not_found_count' => 1,
                    'created_at'      => '2025-01-02',
                ),
                array(
                    'id'              => 2,
                    'keyword_id'      => 2,
                    'count'           => 0,
                    'not_found_count' => 4,
                    'created_at'      => '2025-01-02',
                ),
                array(
                    'id'              => 3,
                    'keyword_id'      => 3,
                    'count'           => 2,
                    'not_found_count' => 0,
                    'created_at'      => '2025-01-03',
                ),
                array(
                    'id'              => 4,
                    'keyword_id'      => 99,
                    'count'           => 1,
                    'not_found_count' => 0,
                    'created_at'      => '2025-01-03',
                ),
                array(
                    'id'              => 5,
                    'keyword_id'      => 1,
                    'count'           => 1,
                    'not_found_count' => 0,
                    'created_at'      => gmdate( 'Y-m-d' ),
                ),
            )
        );
    }

    /**
     * Import the seeded site and hand back the report.
     *
     * @since 1.0.0
     *
     * @param bool                        $dry_run Whether the run may write.
     * @param Licensio_KB_Import_Log|null $log     The import log to write, if any.
     *
     * @return Licensio_KB_Import_Report The run report.
     */
    private function import( $dry_run = false, $log = null ) {
        $report   = $this->new_report( $dry_run );
        $importer = $this->data_importer( $this->new_state( $dry_run ), $report, $dry_run, $log );

        $this->run_all_data_phases( $importer );

        return $report;
    }

    /**
     * The seeding really put daily totals into the source tables.
     *
     * Without this, every count below could pass because nothing was there.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_source_tables_really_hold_the_seeded_totals() {
        $this->seed_betterdocs_site();

        $this->assertSame( 5, $this->count_rows( Licensio_KB_BetterDocs_Import_Schema::TABLE_ANALYTICS ) );
        $this->assertSame( 3, $this->count_rows( Licensio_KB_BetterDocs_Import_Schema::TABLE_SEARCH_KEYWORD ) );
        $this->assertSame( 5, $this->count_rows( Licensio_KB_BetterDocs_Import_Schema::TABLE_SEARCH_LOG ) );
        $this->assertSame( 0, $this->count_rows( Licensio_KB_Feedback::TABLE ) );
    }

    /**
     * A dry run writes nothing to any data table, nor to the import log.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_dry_run_writes_nothing_to_the_licensio_tables() {
        $this->seed_betterdocs_site();
        $this->import( true );

        $this->assertSame(
            array(
                Licensio_KB_Feedback::TABLE                => 0,
                Licensio_KB_Analytics::TABLE_ARTICLE_VIEWS => 0,
                Licensio_KB_Search_Log::TABLE              => 0,
            ),
            $this->data_table_counts()
        );
        $this->assertSame( 0, $this->count_rows( Licensio_KB_Import_Log::TABLE ) );
    }

    /**
     * A dry run counts what a real run would write.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_dry_run_reports_the_counts_a_real_run_would_write() {
        $this->seed_betterdocs_site();

        $dry = $this->import( true );

        $this->assertSame( 4, $dry->get( 'feedback_migrated' ) );
        $this->assertSame( 8, $dry->get( 'views_migrated' ) );
        $this->assertSame( 7, $dry->get( 'searches_migrated' ) );
    }

    /**
     * A dry run keeps its own state and never moves the real import's cursors.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_dry_run_leaves_the_real_import_state_alone() {
        $this->seed_betterdocs_site();
        $this->import( true );

        $this->assertArrayNotHasKey( Licensio_KB_Import_State::option_for( 'betterdocs', false ), $this->options );
        $this->assertSame( 0, $this->new_state( false )->cursor( 'views' ) );
        $this->assertSame( 4, $this->new_state( true )->cursor( 'views' ) );
    }

    /**
     * A dry run counts a row whose article exists in BetterDocs as importable.
     *
     * No target article exists before the real import, so the dry run has to
     * fall back to the BetterDocs article; the real run has nothing to attach
     * the same rows to and orphans them.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_dry_run_counts_rows_for_not_yet_imported_articles_as_importable() {
        $this->seed_betterdocs_site();
        $this->db->query( 'DELETE FROM ' . $this->table( 'posts' ) . " WHERE post_type = '" . Licensio_KB_Post_Types::POST_TYPE . "'" );
        $this->db->query( 'DELETE FROM ' . $this->table( 'postmeta' ) );

        $dry = $this->import( true );

        $this->assertSame( 8, $dry->get( 'views_migrated' ), 'Articles 11 and 12 exist in BetterDocs.' );
        $this->assertSame( 4, $dry->get( 'views_orphaned' ), 'Article 77 does not.' );

        $real = $this->import( false );

        $this->assertSame( 0, $real->get( 'views_migrated' ) );
        $this->assertSame( 12, $real->get( 'views_orphaned' ) );
        $this->assertSame( 0, $this->count_rows( Licensio_KB_Analytics::TABLE_ARTICLE_VIEWS ) );
    }

    /**
     * A real import writes one row per expanded event.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_full_import_writes_the_expanded_rows() {
        $this->seed_betterdocs_site();

        $report = $this->import();

        $this->assertSame( 4, $this->count_rows( Licensio_KB_Feedback::TABLE ) );
        $this->assertSame( 8, $this->count_rows( Licensio_KB_Analytics::TABLE_ARTICLE_VIEWS ) );
        $this->assertSame( 7, $this->count_rows( Licensio_KB_Search_Log::TABLE ) );

        $this->assertSame( 4, $report->get( 'feedback_migrated' ) );
        $this->assertSame( 8, $report->get( 'views_migrated' ) );
        $this->assertSame( 7, $report->get( 'searches_migrated' ) );
        $this->assertSame( 2, $report->get( 'searches_empty' ) );
    }

    /**
     * The expanded totals equal the daily totals they came from.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_expanded_event_totals_equal_the_finished_daily_totals() {
        $this->seed_betterdocs_site();
        $this->import();

        $views = $this->fetch_all( Licensio_KB_Analytics::TABLE_ARTICLE_VIEWS, 'article_id, id' );
        $by    = array_count_values( array_column( $views, 'article_id' ) );

        $this->assertSame( 5, $by[900] );
        $this->assertSame( 3, $by[901] );
        $this->assertArrayNotHasKey( 77, $by, 'Article 77 was never imported.' );

        $votes = array();

        foreach ( $this->fetch_all( Licensio_KB_Feedback::TABLE ) as $row ) {
            $votes[ $row['article_id'] ][ (int) $row['vote'] ] = ( isset( $votes[ $row['article_id'] ][ (int) $row['vote'] ] ) ? $votes[ $row['article_id'] ][ (int) $row['vote'] ] : 0 ) + 1;
        }

        $this->assertSame( array( Licensio_KB_Feedback::VOTE_UP => 2 ), $votes[900], 'Happy plus normal.' );
        $this->assertSame( array( Licensio_KB_Feedback::VOTE_DOWN => 2 ), $votes[901], 'Sad.' );
    }

    /**
     * Impressions are shared among the unique visitors BetterDocs counted.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_views_are_shared_among_the_unique_visitors() {
        $this->seed_betterdocs_site();
        $this->import();

        $rows = $this->fetch_all( Licensio_KB_Analytics::TABLE_ARTICLE_VIEWS );
        $by   = array();

        foreach ( $rows as $row ) {
            $by[ $row['article_id'] ][ $row['visitor_hash'] ] = true;
        }

        $this->assertCount( 2, $by[900], 'Five impressions from two unique visitors.' );
        $this->assertCount( 3, $by[901], 'Three impressions from three unique visitors.' );
        $this->assertSame( '2025-01-02 12:00:00', $rows[0]['created_at'] );
    }

    /**
     * Searches are written found-first, with the term and its normalised form.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_searches_are_expanded_with_their_results_count() {
        $this->seed_betterdocs_site();
        $this->import();

        $rows = $this->fetch_all( Licensio_KB_Search_Log::TABLE, 'id' );

        $this->assertSame( array( 1, 1, 0, 0, 0, 0, 0 ), array_map( 'intval', array_column( $rows, 'results_count' ) ) );
        $this->assertSame( 'Reset  Password', $rows[0]['term'] );
        $this->assertSame( 'reset password', $rows[0]['normalized_term'] );
        $this->assertSame( 'licence key', $rows[3]['term'] );
        $this->assertSame( 'site', $rows[0]['source'] );
    }

    /**
     * Today's rows are left for the next run.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_todays_rows_are_not_imported() {
        $this->seed_betterdocs_site();
        $this->import();

        $today = gmdate( 'Y-m-d' );

        foreach ( array( Licensio_KB_Feedback::TABLE, Licensio_KB_Analytics::TABLE_ARTICLE_VIEWS, Licensio_KB_Search_Log::TABLE ) as $table ) {
            foreach ( $this->fetch_all( $table ) as $row ) {
                $this->assertStringStartsNotWith( $today, $row['created_at'], sprintf( 'A row from today reached %s.', $table ) );
            }
        }

        $this->assertSame( 4, $this->new_state()->cursor( 'views' ), 'The cursor stops before the open day.' );
    }

    /**
     * Once the open day has finished, the next run picks it up and nothing else.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_finished_day_is_picked_up_by_the_next_run() {
        $this->seed_betterdocs_site();
        $this->import();

        $this->db->query( 'UPDATE ' . $this->table( 'betterdocs_analytics' ) . " SET created_at = '2025-01-06' WHERE id = 5" );
        $this->db->query( 'UPDATE ' . $this->table( 'betterdocs_search_log' ) . " SET created_at = '2025-01-06' WHERE id = 5" );

        $this->import();

        $this->assertSame( 7, $this->count_rows( Licensio_KB_Feedback::TABLE ), 'Four plus the three reactions of the finished day.' );
        $this->assertSame( 18, $this->count_rows( Licensio_KB_Analytics::TABLE_ARTICLE_VIEWS ), 'Eight plus the ten views of the finished day.' );
        $this->assertSame( 8, $this->count_rows( Licensio_KB_Search_Log::TABLE ) );
    }

    /**
     * A reaction or view for an article that was not imported is logged, not written.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_orphaned_rows_are_logged_and_counted() {
        $this->seed_betterdocs_site();

        $log    = new Licensio_KB_Import_Log( 'betterdocs-orphans', 'betterdocs' );
        $report = $this->import( false, $log );

        $this->assertSame( 1, $report->get( 'feedback_orphaned' ), 'One reaction on article 77.' );
        $this->assertSame( 4, $report->get( 'views_orphaned' ), 'Four views of article 77.' );

        $orphans = Licensio_KB_Import_Log::entries( 'betterdocs-orphans', Licensio_KB_Import_Log::STATUS_ORPHANED );

        $this->assertSame( 2, $orphans['total'] );

        $item_types = array_column( $orphans['items'], 'item_type' );

        sort( $item_types );

        $this->assertSame( array( 'feedback', 'view' ), $item_types );

        foreach ( $orphans['items'] as $item ) {
            $this->assertSame( 3, $item['source_id'], 'The daily row that named article 77.' );
            $this->assertSame( 0, $item['target_id'] );
            $this->assertStringContainsString( 'BetterDocs article 77', $item['message'] );
        }
    }

    /**
     * Running the import again changes nothing.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_second_run_is_idempotent() {
        $this->seed_betterdocs_site();
        $this->import();

        $before = $this->data_table_counts();
        $report = $this->import();

        $this->assertSame( $before, $this->data_table_counts() );
        $this->assertSame( 0, $report->get( 'views_migrated' ) );
        $this->assertSame( 0, $report->get( 'feedback_migrated' ) );
    }

    /**
     * A new run after a finished one, as the driver opens it, still adds nothing.
     *
     * The driver rewinds the content phases and keeps the analytics cursors.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_restarted_run_keeps_the_analytics_cursors() {
        $this->seed_betterdocs_site();
        $this->import();

        $state = $this->new_state();

        $state->finish();
        $state->restart( Licensio_KB_Import_Driver::CONTENT_PHASES );

        $before = $this->data_table_counts();

        $this->assertSame( 4, $this->new_state()->cursor( 'views' ) );

        $this->import();

        $this->assertSame( $before, $this->data_table_counts() );
    }

    /**
     * A run stopped part-way carries on from where it stopped.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_interrupted_run_resumes_without_repeating_or_skipping() {
        $this->seed_betterdocs_site();

        $first = $this->new_state();

        $this->assertSame( 1, $this->data_importer( $first, $this->new_report() )->migrate_views_batch( 1 ) );
        $this->assertSame( 5, $this->count_rows( Licensio_KB_Analytics::TABLE_ARTICLE_VIEWS ) );
        $this->assertSame( 1, $this->new_state()->cursor( 'views' ) );
        $this->assertSame( 0, $this->new_state()->cursor( 'feedback' ), 'Each phase has its own cursor.' );

        $salt = $first->salt();

        // The process ends here. A new request reloads everything from the options.
        $resumed = $this->new_state();

        $this->assertSame( $salt, $resumed->salt(), 'Visitor identities must be stable across a resume.' );

        $this->run_all_data_phases( $this->data_importer( $resumed, $this->new_report() ), 1 );

        $this->assertSame( 8, $this->count_rows( Licensio_KB_Analytics::TABLE_ARTICLE_VIEWS ) );
        $this->assertSame( 4, $this->count_rows( Licensio_KB_Feedback::TABLE ) );
        $this->assertSame( 7, $this->count_rows( Licensio_KB_Search_Log::TABLE ) );
    }

    /**
     * One busy day stops its batch early so a request cannot run away.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_batch_stops_once_it_has_written_the_expansion_budget() {
        $this->seed_source_article( 11, 'busy' );
        $this->seed_imported_article( 900, 11, 'busy' );
        $this->seed_source_article( 12, 'quiet' );
        $this->seed_imported_article( 901, 12, 'quiet' );

        $this->seed(
            Licensio_KB_BetterDocs_Import_Schema::TABLE_ANALYTICS,
            array(
                array(
                    'id'           => 1,
                    'post_id'      => 11,
                    'impressions'  => 6000,
                    'unique_visit' => 10,
                    'created_at'   => '2025-01-02',
                ),
                array(
                    'id'           => 2,
                    'post_id'      => 12,
                    'impressions'  => 3,
                    'unique_visit' => 3,
                    'created_at'   => '2025-01-03',
                ),
            )
        );

        $importer = $this->data_importer( $this->new_state(), $this->new_report() );

        $this->assertSame( 1, $importer->migrate_views_batch( 10 ), 'The busy day alone exceeds the budget.' );
        $this->assertSame( 6000, $this->count_rows( Licensio_KB_Analytics::TABLE_ARTICLE_VIEWS ) );

        $inserts = array_filter(
            $this->adapter->queries,
            function ( $query ) {
                return 0 === strpos( $query, 'INSERT INTO `' . self::PREFIX . 'licensio_article_views`' );
            }
        );

        $this->assertCount( 12, $inserts, 'Six thousand rows go out 500 to a statement.' );

        $this->assertSame( 1, $importer->migrate_views_batch( 10 ) );
        $this->assertSame( 0, $importer->migrate_views_batch( 10 ) );
        $this->assertSame( 6003, $this->count_rows( Licensio_KB_Analytics::TABLE_ARTICLE_VIEWS ) );
    }

    /**
     * No stored value holds the identity an imported hash was derived from.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_no_target_value_holds_a_clear_text_identity() {
        $this->seed_betterdocs_site();
        $this->import();

        foreach ( array( Licensio_KB_Feedback::TABLE, Licensio_KB_Analytics::TABLE_ARTICLE_VIEWS, Licensio_KB_Search_Log::TABLE ) as $table ) {
            $rows = $this->fetch_all( $table );

            $this->assertNotEmpty( $rows );

            foreach ( $rows as $row ) {
                $this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $row['visitor_hash'] );
                $this->assertSame( 0, (int) ( $row['user_id'] ?? 0 ), 'BetterDocs recorded no user.' );

                foreach ( $row as $column => $value ) {
                    if ( 'visitor_hash' === $column ) {
                        continue;
                    }

                    foreach ( array( 'view:', 'reaction:', 'search:', 'betterdocs:' ) as $needle ) {
                        $this->assertStringNotContainsString( $needle, (string) $value );
                    }
                }
            }
        }
    }

    /**
     * Every batch leaves an info row, and a run's rows are listed together.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_import_writes_a_log_row_per_batch_and_per_orphan() {
        $this->seed_betterdocs_site();

        $log = new Licensio_KB_Import_Log( 'betterdocs-run', 'betterdocs', false );

        $this->import( false, $log );

        $runs = Licensio_KB_Import_Log::runs();

        $this->assertCount( 1, $runs );
        $this->assertSame( 'betterdocs-run', $runs[0]['run_id'] );
        $this->assertSame( 'betterdocs', $runs[0]['source'] );
        $this->assertFalse( $runs[0]['dry_run'] );
        $this->assertSame( 5, $runs[0]['entries'], 'Three batches and two orphaned records.' );
        $this->assertSame( 0, $runs[0]['failed'] );

        $batches = Licensio_KB_Import_Log::entries( 'betterdocs-run', Licensio_KB_Import_Log::STATUS_INFO );

        $this->assertSame( 3, $batches['total'] );
        $this->assertSame( array( 'feedback', 'views', 'searches' ), array_column( $batches['items'], 'phase' ) );
        $this->assertSame( 'Feedback daily rows 1 to 4', $batches['items'][0]['title'] );
        $this->assertSame( 'Read 4 daily totals, imported 4 events, skipped 1 empty days, orphaned 1, failed 0.', $batches['items'][0]['message'] );
        $this->assertSame( 4, $batches['items'][0]['details']['read'] );
        $this->assertSame( 8, $batches['items'][1]['details']['migrated'] );
        $this->assertSame( 7, $batches['items'][2]['details']['migrated'] );
    }

    /**
     * A dry run's log rows are flagged and are the only thing it writes.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_dry_run_logs_its_rows_flagged_as_a_dry_run() {
        $this->seed_betterdocs_site();

        $this->import( true, new Licensio_KB_Import_Log( 'betterdocs-dry', 'betterdocs', true ) );

        $runs = Licensio_KB_Import_Log::runs();

        $this->assertCount( 1, $runs );
        $this->assertTrue( $runs[0]['dry_run'] );
        $this->assertSame( 5, $runs[0]['entries'] );
        $this->assertSame( 0, $this->count_rows( Licensio_KB_Feedback::TABLE ) );
        $this->assertSame( 0, $this->count_rows( Licensio_KB_Analytics::TABLE_ARTICLE_VIEWS ) );
        $this->assertSame( 0, $this->count_rows( Licensio_KB_Search_Log::TABLE ) );
    }

    /**
     * A write the database refuses is counted and logged as a failure, with the reason.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_refused_write_is_logged_as_a_failure() {
        $this->seed_betterdocs_site();
        $this->db->query( 'DROP TABLE ' . $this->table( Licensio_KB_Feedback::TABLE ) );
        $this->adapter->suppress_errors( true );

        $log    = new Licensio_KB_Import_Log( 'betterdocs-failing', 'betterdocs', false );
        $report = $this->new_report();

        $this->data_importer( $this->new_state(), $report, false, $log )->migrate_feedback_batch( 100 );

        $this->assertSame( 2, $report->get( 'feedback_failed' ) );
        $this->assertSame( 0, $report->get( 'feedback_migrated' ) );

        $failures = Licensio_KB_Import_Log::entries( 'betterdocs-failing', Licensio_KB_Import_Log::STATUS_FAILED );

        $this->assertSame( 2, $failures['total'] );
        $this->assertStringContainsString( 'could not be written', $failures['items'][0]['message'] );
        $this->assertStringContainsString( 'licensio_feedback', $failures['items'][0]['message'], 'The database error names the missing table.' );

        $runs = Licensio_KB_Import_Log::runs();

        $this->assertSame( 2, $runs[0]['failed'] );

        $warnings = Licensio_KB_Import_Log::entries( 'betterdocs-failing', Licensio_KB_Import_Log::STATUS_WARNING );

        $this->assertSame( 1, $warnings['total'], 'The batch row is a warning when any write failed.' );
    }

    /**
     * The driver's preview counts the events the daily totals add up to.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_driver_previews_event_counts_from_the_daily_totals() {
        $this->seed_betterdocs_site();

        $driver = new Licensio_KB_BetterDocs_Import_Driver();
        $counts = $driver->counts();

        $this->assertSame( 2, $counts['articles'], 'Imported target articles are not source articles.' );
        $this->assertSame( 8, $counts['reactions'] );
        $this->assertSame( 22, $counts['views'] );
        $this->assertSame( 11, $counts['searches'] );
        $this->assertTrue( $driver->detect() );
    }

    /**
     * Without BetterDocs' tables there is nothing to preview and no fatal error.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_driver_copes_with_missing_analytics_tables() {
        $this->db->query( 'DROP TABLE ' . $this->table( 'betterdocs_analytics' ) );
        $this->db->query( 'DROP TABLE ' . $this->table( 'betterdocs_search_log' ) );
        $this->db->query( 'DROP TABLE ' . $this->table( 'betterdocs_search_keyword' ) );

        $driver = new Licensio_KB_BetterDocs_Import_Driver();
        $counts = $driver->counts();

        $this->assertSame( 0, $counts['reactions'] );
        $this->assertSame( 0, $counts['views'] );
        $this->assertSame( 0, $counts['searches'] );
        $this->assertFalse( $driver->detect() );

        $importer = $this->data_importer( $this->new_state(), $this->new_report() );

        $this->assertSame( 0, $importer->migrate_views_batch( 10 ) );
        $this->assertSame( 0, $importer->migrate_searches_batch( 10 ) );
    }

    /**
     * The category tree the wizard offers has knowledge bases at its roots.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_category_tree_folds_knowledge_bases_in_from_the_database() {
        $this->seed_term( 100, 'knowledge_base', 'Faq' );
        $this->seed_term( 1, 'doc_category', 'Billing', 0, array( 'doc_category_knowledge_base' => serialize( array( 'faq' ) ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
        $this->seed_term( 2, 'doc_category', 'Setup', 1 );
        $this->seed_term( 3, 'doc_category', 'Loose' );
        $this->seed_term( 40, 'doc_tag', 'Urgent' );

        $driver = new Licensio_KB_BetterDocs_Import_Driver();
        $tree   = $driver->category_tree();

        $this->assertSame( array( 100, 1, 2, 3 ), array_column( $tree, 'id' ) );
        $this->assertSame( array( 0, 100, 1, 0 ), array_column( $tree, 'parent' ) );
        $this->assertSame( array( 'knowledge_base', 'category', 'category', 'category' ), array_column( $tree, 'type' ) );
        $this->assertSame( 4, $driver->counts()['categories'] );
        $this->assertSame( 1, $driver->counts()['tags'] );
    }

    /**
     * The reader finds an article's order, categories and old URL in the real tables.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_reader_resolves_article_order_and_old_url_from_the_database() {
        $this->options['betterdocs_settings'] = array( 'permalink_structure' => '%doc_category%' );

        $this->seed_source_article( 11, 'install-a-licence' );
        $this->seed_term( 1, 'doc_category', 'Billing', 0, array( '_docs_order' => '5,11,6' ) );
        $this->file_under( 11, 1 );

        $source = new Licensio_KB_BetterDocs_Import_Source();

        $this->assertSame( array( 1 ), $source->post_terms( 11, 'doc_category' ) );
        $this->assertSame(
            array(
                'order'     => 1,
                'positions' => array( 1 => 1 ),
            ),
            $source->article_order( 11 )
        );
        $this->assertSame(
            'https://example.test/docs/billing/install-a-licence/',
            $source->old_article_url(
                array(
                    'ID'        => 11,
                    'post_name' => 'install-a-licence',
                )
            )
        );
    }

    /**
     * The shared reads skip auto-drafts, page by key and ignore other post types.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_shared_reads_page_by_key_and_skip_auto_drafts() {
        $this->seed_source_article( 11, 'one' );
        $this->seed_source_article( 12, 'two' );
        $this->seed_source_article( 13, 'three' );
        $this->seed_imported_article( 900, 11, 'one' );
        $this->db->query( 'UPDATE ' . $this->table( 'posts' ) . " SET post_status = 'auto-draft' WHERE ID = 13" );

        $source = new Licensio_KB_BetterDocs_Import_Source();

        $this->assertSame( 2, $source->count_articles() );
        $this->assertSame( array( '11', '12' ), array_column( $source->articles( 0, 10 ), 'ID' ) );
        $this->assertSame( array( '12' ), array_column( $source->articles( 11, 10 ), 'ID' ) );
        $this->assertSame( array( '11' ), array_column( $source->articles( 0, 1 ), 'ID' ) );
        $this->assertTrue( $source->article_exists( 12 ) );
        $this->assertFalse( $source->article_exists( 900 ), 'A target article is not a BetterDocs article.' );
        $this->assertTrue( Licensio_KB_Import_Source::table_exists( 'betterdocs_analytics' ) );
        $this->assertFalse( Licensio_KB_Import_Source::table_exists( 'betterdocs_analytics_feedback' ) );
    }

    /**
     * The reader returns the article's place in every category list that names it.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_reader_returns_every_category_position() {
        $this->seed_source_article( 11, 'install-a-licence' );
        $this->seed_term( 1, 'doc_category', 'Billing', 0, array( '_docs_order' => '5,11,6' ) );
        $this->seed_term( 2, 'doc_category', 'Install', 0, array( '_docs_order' => '11' ) );
        $this->seed_term( 3, 'doc_category', 'Unlisted' );
        $this->file_under( 11, 1 );
        $this->file_under( 11, 2 );
        $this->file_under( 11, 3 );

        $order = ( new Licensio_KB_BetterDocs_Import_Source() )->article_order( 11 );

        $this->assertSame(
            array(
                1 => 1,
                2 => 0,
            ),
            $order['positions'],
            'A category whose list does not name the article has no position.'
        );
        $this->assertSame( 1, $order['order'] );
    }

    /**
     * The article import writes each article's place in every imported category.
     *
     * Category 1 is imported as 501. Categories 2 and 3 are both imported as
     * 502, so their orders are merged: 2's positions as they are, then 3's
     * after them. Article 14 is listed in both and keeps its place from 2.
     * Category 4 was not imported, so its position has nowhere to go.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_article_import_writes_the_per_category_order() {
        $this->stub_article_writes();

        foreach ( array( 11, 12, 13, 14 ) as $post_id ) {
            $this->seed_source_article( $post_id, 'doc-' . $post_id );
        }

        $this->seed_term( 1, 'doc_category', 'Billing', 0, array( '_docs_order' => '12,11' ) );
        $this->seed_term( 2, 'doc_category', 'Install', 0, array( '_docs_order' => '11,14' ) );
        $this->seed_term( 3, 'doc_category', 'Extra', 0, array( '_docs_order' => '13,14' ) );
        $this->seed_term( 4, 'doc_category', 'Unmapped', 0, array( '_docs_order' => '11' ) );

        foreach ( array(
            11 => array( 1, 2, 4 ),
            12 => array( 1 ),
            13 => array( 3 ),
            14 => array( 2, 3 ),
        ) as $post_id => $terms ) {
            foreach ( $terms as $term_id ) {
                $this->file_under( $post_id, $term_id );
            }
        }

        $source = new Licensio_KB_BetterDocs_Import_Source();

        $this->seed_target_category( 501, $source->provenance_term_key(), array( 1 ) );
        $this->seed_target_category( 502, $source->provenance_term_key(), array( 2, 3 ) );

        $report  = $this->new_report();
        $content = new Licensio_KB_Import_Content(
            $source,
            $this->new_state(),
            new Licensio_KB_Import_Redirects( 'betterdocs', sys_get_temp_dir() . '/licensio-kb-betterdocs-unused', true ),
            $report
        );

        while ( $content->migrate_articles_batch( 2 ) > 0 ) {
            continue;
        }

        $this->assertSame(
            array(
                'Doc 11' => array(
                    'licensio_order_501' => 1,
                    'licensio_order_502' => 0,
                ),
                'Doc 12' => array( 'licensio_order_501' => 0 ),
                'Doc 13' => array( 'licensio_order_502' => 2 ),
                'Doc 14' => array( 'licensio_order_502' => 1 ),
            ),
            $this->written_positions()
        );
        $this->assertSame( 0, $report->get( 'articles_order_collapsed' ) );
        $this->assertSame( array(), $report->notes( 'article_order' ) );
    }
}
