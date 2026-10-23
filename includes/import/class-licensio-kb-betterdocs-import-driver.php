<?php
/**
 * BetterDocs import driver.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Imports from BetterDocs.
 *
 * The run itself is `Licensio_KB_Import_Driver`'s. This names the BetterDocs
 * reader, its three analytics phases and how to run a batch of each, and
 * reports what BetterDocs has that nothing here can hold.
 *
 * @since 1.0.0
 */
class Licensio_KB_BetterDocs_Import_Driver extends Licensio_KB_Import_Driver {

    /**
     * The analytics phases, in order. Feedback and views both read the daily
     * analytics table, each with its own cursor.
     *
     * @since 1.0.0
     * @var string[]
     */
    const DATA_PHASES = array( 'feedback', 'views', 'searches' );

    /**
     * The batched reader over the BetterDocs data.
     *
     * @since 1.0.0
     * @var Licensio_KB_BetterDocs_Import_Source
     */
    private $source;

    /**
     * Constructor.
     *
     * @since 1.0.0
     *
     * @param Licensio_KB_BetterDocs_Import_Source|null $source The reader; a live one when null.
     */
    public function __construct( $source = null ) {
        $this->source = $source instanceof Licensio_KB_BetterDocs_Import_Source ? $source : new Licensio_KB_BetterDocs_Import_Source();
    }

    /**
     * The BetterDocs reader.
     *
     * @since 1.0.0
     *
     * @return Licensio_KB_BetterDocs_Import_Source The reader.
     */
    public function source() {
        return $this->source;
    }

    /**
     * BetterDocs' analytics phases.
     *
     * @since 1.0.0
     *
     * @return string[] The phase names.
     */
    public function data_phases() {
        return self::DATA_PHASES;
    }

    /**
     * The labels of the preview counts.
     *
     * @since 1.0.0
     *
     * @return array<string, string> Count key to label.
     */
    public function count_labels() {
        return array(
            'articles'   => __( 'Articles', 'licensio-knowledge-base' ),
            'categories' => __( 'Categories', 'licensio-knowledge-base' ),
            'tags'       => __( 'Tags', 'licensio-knowledge-base' ),
            'reactions'  => __( 'Reactions', 'licensio-knowledge-base' ),
            'views'      => __( 'Views', 'licensio-knowledge-base' ),
            'searches'   => __( 'Searches', 'licensio-knowledge-base' ),
        );
    }

    /**
     * How many events the BetterDocs daily totals add up to.
     *
     * The preview counts events, not daily rows, because events are what the
     * import writes.
     *
     * @since 1.0.0
     *
     * @return array<string, int> Count key to count.
     */
    protected function data_counts() {
        global $wpdb;

        $counts = array(
            'reactions' => 0,
            'views'     => 0,
            'searches'  => 0,
        );

        if ( Licensio_KB_Import_Source::table_exists( Licensio_KB_BetterDocs_Import_Schema::TABLE_ANALYTICS ) ) {
            $table = Licensio_KB_Import_Source::table( Licensio_KB_BetterDocs_Import_Schema::TABLE_ANALYTICS );

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $row = $wpdb->get_row( $wpdb->prepare( 'SELECT SUM(happy + normal + sad) AS reactions, SUM(impressions) AS views FROM %i', $table ), ARRAY_A );

            $counts['reactions'] = isset( $row['reactions'] ) ? (int) $row['reactions'] : 0;
            $counts['views']     = isset( $row['views'] ) ? (int) $row['views'] : 0;
        }

        if ( Licensio_KB_Import_Source::table_exists( Licensio_KB_BetterDocs_Import_Schema::TABLE_SEARCH_LOG ) ) {
            $table = Licensio_KB_Import_Source::table( Licensio_KB_BetterDocs_Import_Schema::TABLE_SEARCH_LOG );

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $counts['searches'] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT SUM(count + not_found_count) FROM %i', $table ) );
        }

        return $counts;
    }

    /**
     * Run one batch of one BetterDocs analytics phase.
     *
     * @since 1.0.0
     *
     * @param string                     $phase   One of DATA_PHASES.
     * @param int                        $batch   How many source rows to read.
     * @param Licensio_KB_Import_Content $content The content importer.
     * @param Licensio_KB_Import_State   $state   The resume point.
     * @param Licensio_KB_Import_Report  $report  The run report.
     * @param Licensio_KB_Import_Log     $log     The import log.
     * @param bool                       $dry_run Whether to suppress every write.
     *
     * @return int How many source rows the batch read.
     */
    protected function run_data_batch( $phase, $batch, Licensio_KB_Import_Content $content, Licensio_KB_Import_State $state, Licensio_KB_Import_Report $report, Licensio_KB_Import_Log $log, $dry_run ) {
        $data = new Licensio_KB_BetterDocs_Import_Data( $this->source, $state, $content, $report, $dry_run, $log );

        switch ( $phase ) {
            case 'feedback':
                $processed = $data->migrate_feedback_batch( $batch );

                if ( 0 === $processed ) {
                    $data->recompute_all_scores();
                }

                return $processed;

            case 'views':
                return $data->migrate_views_batch( $batch );

            case 'searches':
                return $data->migrate_searches_batch( $batch );
        }

        return 0;
    }

    /**
     * Report what BetterDocs has that was not imported.
     *
     * @since 1.0.0
     *
     * @param Licensio_KB_Import_Report $report The run report.
     * @param Licensio_KB_Import_Log    $log    The import log.
     *
     * @return void
     */
    protected function finish_notes( Licensio_KB_Import_Report $report, Licensio_KB_Import_Log $log ) {
        $notes = array(
            'today' => __( 'Analytics recorded today are still being added to by BetterDocs and were left for the next run.', 'licensio-knowledge-base' ),
        );

        $glossary = count( $this->source->terms( Licensio_KB_BetterDocs_Import_Schema::TAXONOMY_GLOSSARY ) );

        if ( $glossary > 0 ) {
            $notes['glossary'] = sprintf(
                /* translators: %d: number of glossary terms. */
                _n( '%d BetterDocs glossary term has no equivalent here and was not imported.', '%d BetterDocs glossary terms have no equivalent here and were not imported.', $glossary, 'licensio-knowledge-base' ),
                $glossary
            );

            $report->count( 'glossary_not_migrated', $glossary );
        }

        if ( Licensio_KB_Import_Source::table_exists( 'betterdocs_analytics_feedback' ) ) {
            $notes['feedback_comments'] = __( 'BetterDocs Pro feedback messages (betterdocs_analytics_feedback) are not imported; only the reaction counts are.', 'licensio-knowledge-base' );
        }

        foreach ( $notes as $key => $note ) {
            $report->note( $key, $note );

            $log->entry( 'run', 'run', 0, 0, $this->label(), 'today' === $key ? Licensio_KB_Import_Log::STATUS_INFO : Licensio_KB_Import_Log::STATUS_WARNING, $note );
        }
    }
}
