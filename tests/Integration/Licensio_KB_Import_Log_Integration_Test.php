<?php
/**
 * The import log against a real table.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

// phpcs:disable WordPress.DB.RestrictedClasses, WordPress.DB.RestrictedFunctions, WordPress.DB.DirectDatabaseQuery

require_once __DIR__ . '/Licensio_KB_BetterDocs_Import_DB_Test_Case.php';

/**
 * Writing, listing, paging, exporting and clearing log entries.
 *
 * @since 1.0.0
 */
class Licensio_KB_Import_Log_Integration_Test extends Licensio_KB_BetterDocs_Import_DB_Test_Case {

    /**
     * Write several entries for two runs.
     *
     * Run `run-a` is a dry run with three entries, one of them failed; run `run-b`
     * is a real run with one entry. `run-a` is written first.
     *
     * @since 1.0.0
     *
     * @return void
     */
    private function seed_two_runs() {
        $dry  = new Licensio_KB_Import_Log( 'run-a', 'betterdocs', true );
        $real = new Licensio_KB_Import_Log( 'run-b', 'heroic', false );

        $dry->entry( 'terms', 'category', 4, 40, 'Billing', Licensio_KB_Import_Log::STATUS_CREATED, '' );
        $dry->entry( 'articles', 'article', 11, 0, 'Install', Licensio_KB_Import_Log::STATUS_FAILED, 'The article could not be created.' );
        $dry->entry( 'articles', 'article', 12, 120, 'Renew', Licensio_KB_Import_Log::STATUS_CREATED, '' );
        $real->entry( 'run', 'run', 0, 0, 'Heroic', Licensio_KB_Import_Log::STATUS_INFO, 'Import started.' );
    }

    /**
     * An entry round-trips with its details decoded.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_entry_round_trips_with_decoded_details() {
        $log = new Licensio_KB_Import_Log( 'run-a', 'betterdocs', true );

        $this->assertTrue(
            $log->entry(
                'articles',
                'article',
                11,
                900,
                'Install a licence',
                Licensio_KB_Import_Log::STATUS_CREATED,
                'Created.',
                array(
                    'categories' => array(
                        array(
                            'name'      => 'Billing',
                            'source_id' => 4,
                            'target_id' => 40,
                            'status'    => 'created',
                        ),
                    ),
                )
            )
        );

        $result = Licensio_KB_Import_Log::entries( 'run-a' );
        $item   = $result['items'][0];

        $this->assertSame( 1, $result['total'] );
        $this->assertSame( 'run-a', $item['run_id'] );
        $this->assertSame( 'betterdocs', $item['source'] );
        $this->assertTrue( $item['dry_run'] );
        $this->assertSame( 'articles', $item['phase'] );
        $this->assertSame( 'article', $item['item_type'] );
        $this->assertSame( 11, $item['source_id'] );
        $this->assertSame( 900, $item['target_id'] );
        $this->assertSame( 'Install a licence', $item['title'] );
        $this->assertSame( 'created', $item['status'] );
        $this->assertSame( 'Created.', $item['message'] );
        $this->assertSame( 'Billing', $item['details']['categories'][0]['name'] );
        $this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $item['created_at'] );
    }

    /**
     * An entry without details stores an empty array, and ids never go negative.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_entry_without_details_has_empty_details_and_ids_are_clamped() {
        $log = new Licensio_KB_Import_Log( 'run-a', 'betterdocs' );

        $log->entry( 'run', 'run', -5, -9, '', Licensio_KB_Import_Log::STATUS_INFO );

        $item = Licensio_KB_Import_Log::entries( 'run-a' )['items'][0];

        $this->assertSame( array(), $item['details'] );
        $this->assertSame( 0, $item['source_id'] );
        $this->assertSame( 0, $item['target_id'] );
        $this->assertFalse( $item['dry_run'] );
    }

    /**
     * A title longer than the column is cut with an ellipsis.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_long_title_is_cut_to_the_column_width() {
        $log = new Licensio_KB_Import_Log( 'run-a', 'betterdocs' );

        $log->entry( 'articles', 'article', 1, 1, str_repeat( 'é', 400 ), Licensio_KB_Import_Log::STATUS_CREATED );

        $title = Licensio_KB_Import_Log::entries( 'run-a' )['items'][0]['title'];

        $this->assertSame( 255, mb_strlen( $title ) );
        $this->assertSame( str_repeat( 'é', 254 ) . '…', $title );
    }

    /**
     * Runs are listed newest first with their entry and failure counts.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_runs_lists_each_run_newest_first_with_counts() {
        $this->seed_two_runs();

        $runs = Licensio_KB_Import_Log::runs();

        $this->assertSame( array( 'run-b', 'run-a' ), array_column( $runs, 'run_id' ) );

        $this->assertSame( 'heroic', $runs[0]['source'] );
        $this->assertFalse( $runs[0]['dry_run'] );
        $this->assertSame( 1, $runs[0]['entries'] );
        $this->assertSame( 0, $runs[0]['failed'] );

        $this->assertSame( 'betterdocs', $runs[1]['source'] );
        $this->assertTrue( $runs[1]['dry_run'] );
        $this->assertSame( 3, $runs[1]['entries'] );
        $this->assertSame( 1, $runs[1]['failed'] );
        $this->assertNotSame( '', $runs[1]['started_at'] );
    }

    /**
     * An empty log has no runs.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_runs_is_empty_for_an_empty_log() {
        $this->assertSame( array(), Licensio_KB_Import_Log::runs() );
    }

    /**
     * A run's entries come back in write order, filtered by status on request.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_entries_are_in_write_order_and_filter_by_status() {
        $this->seed_two_runs();

        $all = Licensio_KB_Import_Log::entries( 'run-a' );

        $this->assertSame( 3, $all['total'] );
        $this->assertSame( array( 'Billing', 'Install', 'Renew' ), array_column( $all['items'], 'title' ) );

        $failed = Licensio_KB_Import_Log::entries( 'run-a', Licensio_KB_Import_Log::STATUS_FAILED );

        $this->assertSame( 1, $failed['total'] );
        $this->assertSame( 'Install', $failed['items'][0]['title'] );

        $this->assertSame( 0, Licensio_KB_Import_Log::entries( 'run-a', Licensio_KB_Import_Log::STATUS_ORPHANED )['total'] );
        $this->assertSame( 0, Licensio_KB_Import_Log::entries( 'no-such-run' )['total'] );
    }

    /**
     * Pages hold the page size, and the total is the whole filtered count.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_entries_paginate() {
        $this->seed_two_runs();

        $first  = Licensio_KB_Import_Log::entries( 'run-a', '', 1, 2 );
        $second = Licensio_KB_Import_Log::entries( 'run-a', '', 2, 2 );

        $this->assertSame( 3, $first['total'] );
        $this->assertSame( array( 'Billing', 'Install' ), array_column( $first['items'], 'title' ) );
        $this->assertSame( 3, $second['total'] );
        $this->assertSame( array( 'Renew' ), array_column( $second['items'], 'title' ) );
    }

    /**
     * Deleting one run leaves the others.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_clearing_one_run_leaves_the_other_runs() {
        $this->seed_two_runs();

        Licensio_KB_Import_Log::clear( 'run-a' );

        $this->assertSame( array( 'run-b' ), array_column( Licensio_KB_Import_Log::runs(), 'run_id' ) );
        $this->assertSame( 0, Licensio_KB_Import_Log::entries( 'run-a' )['total'] );
        $this->assertSame( 1, Licensio_KB_Import_Log::entries( 'run-b' )['total'] );
    }

    /**
     * Clearing with no run empties the whole log.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_clearing_everything_empties_the_log() {
        $this->seed_two_runs();

        Licensio_KB_Import_Log::clear();

        $this->assertSame( array(), Licensio_KB_Import_Log::runs() );
        $this->assertSame( 0, $this->count_rows( Licensio_KB_Import_Log::TABLE ) );
    }

    /**
     * A disabled log leaves the table untouched.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_disabled_log_leaves_the_table_empty() {
        Licensio_KB_Import_Log::disabled()->entry( 'run', 'run', 0, 0, '', Licensio_KB_Import_Log::STATUS_INFO, 'Ignored.' );

        $this->assertSame( 0, $this->count_rows( Licensio_KB_Import_Log::TABLE ) );
    }

    /**
     * The CSV export has a header, flattens the details, and neutralises formulas.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_csv_export_flattens_details_and_neutralises_formulas() {
        $log = new Licensio_KB_Import_Log( 'run-a', 'betterdocs', true );

        $log->entry(
            'articles',
            'article',
            11,
            900,
            '=HYPERLINK("http://example.test")',
            Licensio_KB_Import_Log::STATUS_WARNING,
            '-2+3',
            array(
                'categories' => array(
                    array(
                        'name'   => 'Billing',
                        'status' => 'created',
                    ),
                ),
                'tags'       => array(
                    array(
                        'name'   => 'Urgent',
                        'status' => 'merged',
                    ),
                ),
                'meta'       => array(
                    array(
                        'key'    => '_thumbnail_id',
                        'action' => 'migrated',
                    ),
                ),
            )
        );

        $lines = explode( "\n", trim( Licensio_KB_Import_Log::to_csv( 'run-a' ) ) );

        $this->assertCount( 2, $lines );
        $this->assertSame( '"id","created_at","dry_run","phase","item_type","source_id","target_id","title","status","message","categories","meta"', $lines[0] );
        $this->assertStringContainsString( ',"yes","articles","article","11","900",', $lines[1] );
        $this->assertStringContainsString( '"\'=HYPERLINK(""http://example.test"")"', $lines[1], 'A leading equals sign is neutralised and inner quotes are doubled.' );
        $this->assertStringContainsString( '"\'-2+3"', $lines[1], 'A leading minus sign is neutralised.' );
        $this->assertStringContainsString( '"Billing (created); Urgent (merged)","_thumbnail_id: migrated"', $lines[1] );
    }

    /**
     * Exporting a run that does not exist gives only the header.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_csv_export_of_an_unknown_run_is_only_the_header() {
        $this->assertCount( 1, explode( "\n", trim( Licensio_KB_Import_Log::to_csv( 'no-such-run' ) ) ) );
    }
}
