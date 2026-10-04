<?php
/**
 * Heroic Knowledge Base import driver.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Imports from Heroic Knowledge Base.
 *
 * The run itself is `Lisensio_KB_Import_Driver`'s. This names Heroic's reader,
 * its three analytics phases and how to run a batch of each, and adds the one
 * thing Heroic has that nothing here can hold: its outbound-click table.
 *
 * @since 1.0.0
 */
class Lisensio_KB_Heroic_Import_Driver extends Lisensio_KB_Import_Driver {

    /**
     * The analytics phases, in order.
     *
     * @since 1.0.0
     * @var string[]
     */
    const DATA_PHASES = array( 'feedback', 'views', 'searches' );

    /**
     * The batched reader over the Heroic data.
     *
     * @since 1.0.0
     * @var Lisensio_KB_Heroic_Import_Source
     */
    private $source;

    /**
     * Constructor.
     *
     * @since 1.0.0
     *
     * @param Lisensio_KB_Heroic_Import_Source|null $source The reader; a live one when null.
     */
    public function __construct( $source = null ) {
        $this->source = $source instanceof Lisensio_KB_Heroic_Import_Source ? $source : new Lisensio_KB_Heroic_Import_Source();
    }

    /**
     * The Heroic reader.
     *
     * @since 1.0.0
     *
     * @return Lisensio_KB_Heroic_Import_Source The reader.
     */
    public function source() {
        return $this->source;
    }

    /**
     * Heroic's analytics phases.
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
            'articles'   => __( 'Articles', 'lisensio-knowledge-base' ),
            'categories' => __( 'Categories', 'lisensio-knowledge-base' ),
            'tags'       => __( 'Tags', 'lisensio-knowledge-base' ),
            'votes'      => __( 'Votes', 'lisensio-knowledge-base' ),
            'views'      => __( 'Views', 'lisensio-knowledge-base' ),
            'searches'   => __( 'Searches', 'lisensio-knowledge-base' ),
        );
    }

    /**
     * How many rows each Heroic analytics table holds.
     *
     * @since 1.0.0
     *
     * @return array<string, int> Count key to count.
     */
    protected function data_counts() {
        return array(
            'votes'    => $this->source->count_table( Lisensio_KB_Heroic_Import_Schema::TABLE_VOTING ),
            'views'    => $this->source->count_table( Lisensio_KB_Heroic_Import_Schema::TABLE_VISITS ),
            'searches' => $this->source->count_table( Lisensio_KB_Heroic_Import_Schema::TABLE_SEARCHES ),
        );
    }

    /**
     * Run one batch of one Heroic analytics phase.
     *
     * The usefulness scores are recomputed once, when the feedback phase runs
     * dry, from every imported vote rather than per batch.
     *
     * @since 1.0.0
     *
     * @param string                     $phase   One of DATA_PHASES.
     * @param int                        $batch   How many source rows to read.
     * @param Lisensio_KB_Import_Content $content The content importer.
     * @param Lisensio_KB_Import_State   $state   The resume point.
     * @param Lisensio_KB_Import_Report  $report  The run report.
     * @param Lisensio_KB_Import_Log     $log     The import log.
     * @param bool                       $dry_run Whether to suppress every write.
     *
     * @return int How many source rows the batch read.
     */
    protected function run_data_batch( $phase, $batch, Lisensio_KB_Import_Content $content, Lisensio_KB_Import_State $state, Lisensio_KB_Import_Report $report, Lisensio_KB_Import_Log $log, $dry_run ) {
        $data = new Lisensio_KB_Heroic_Import_Data( $this->source, $state, $content, $report, $dry_run, 0, $log );

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
     * Report the Heroic data that has no home here.
     *
     * @since 1.0.0
     *
     * @param Lisensio_KB_Import_Report $report The run report.
     * @param Lisensio_KB_Import_Log    $log    The import log.
     *
     * @return void
     */
    protected function finish_notes( Lisensio_KB_Import_Report $report, Lisensio_KB_Import_Log $log ) {
        $exits = $this->source->count_table( Lisensio_KB_Heroic_Import_Schema::TABLE_EXITS );

        if ( $exits <= 0 ) {
            return;
        }

        $report->count( 'exits_not_migrated', $exits );

        $note = sprintf(
            /* translators: %d: number of outbound-click rows. */
            _n(
                '%d outbound-click row in the Heroic exits table has no equivalent here and was not migrated.',
                '%d outbound-click rows in the Heroic exits table have no equivalent here and were not migrated.',
                $exits,
                'lisensio-knowledge-base'
            ),
            $exits
        );

        $report->note( 'exits', $note );

        $log->entry( 'run', 'run', 0, 0, $this->label(), Lisensio_KB_Import_Log::STATUS_WARNING, $note );
    }
}
