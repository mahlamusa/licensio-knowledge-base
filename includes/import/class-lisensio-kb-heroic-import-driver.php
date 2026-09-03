<?php
/**
 * Heroic Knowledge Base import driver.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Orchestrates the import from Heroic Knowledge Base.
 *
 * The wizard calls `run_batch()` once per AJAX request; the state option carries
 * the run from one request to the next, and each phase knows how to advance
 * itself. The driver only dispatches to the right phase, triggers the work that
 * belongs at a phase boundary, and closes the run when the last phase is done.
 *
 * @since 1.0.0
 */
class Lisensio_KB_Heroic_Import_Driver extends Lisensio_KB_Import_Driver {

    /**
     * The batched reader over the Heroic data.
     *
     * @since 1.0.0
     * @var Lisensio_KB_Heroic_Import_Source
     */
    private $source;

    /**
     * The resume point carried between requests.
     *
     * @since 1.0.0
     * @var Lisensio_KB_Heroic_Import_State
     */
    private $state;

    /**
     * The URL map writer.
     *
     * @since 1.0.0
     * @var Lisensio_KB_Heroic_Import_Redirects
     */
    private $redirects;

    /**
     * The run report.
     *
     * @since 1.0.0
     * @var Lisensio_KB_Heroic_Import_Report
     */
    private $report;

    /**
     * Constructor.
     *
     * @since 1.0.0
     */
    public function __construct() {
        $this->source    = new Lisensio_KB_Heroic_Import_Source();
        $this->state     = new Lisensio_KB_Heroic_Import_State();
        $this->redirects = new Lisensio_KB_Heroic_Import_Redirects();
        $this->report    = new Lisensio_KB_Heroic_Import_Report();
    }

    /**
     * Whether Heroic KB data is present.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public function detect() {
        $articles = $this->source->count_articles();
        $cats     = count( $this->source->terms( Lisensio_KB_Heroic_Import_Schema::TAXONOMY_CATEGORY ) );
        $tags     = count( $this->source->terms( Lisensio_KB_Heroic_Import_Schema::TAXONOMY_TAG ) );

        return ( $articles > 0 || $cats > 0 || $tags > 0 );
    }

    /**
     * Total items to migrate for preview.
     *
     * @since 1.0.0
     *
     * @return array<string, int>
     */
    public function counts() {
        return array(
            'articles'   => $this->source->count_articles(),
            'categories' => count( $this->source->terms( Lisensio_KB_Heroic_Import_Schema::TAXONOMY_CATEGORY ) ),
            'tags'       => count( $this->source->terms( Lisensio_KB_Heroic_Import_Schema::TAXONOMY_TAG ) ),
        );
    }

    /**
     * Run a batch of the migration.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $options Options from the wizard: `dry_run` and `batch_size`.
     *
     * @return array{phase: string, done: int, total: int, finished: bool}
     */
    public function run_batch( array $options ) {
        $dry_run = ! empty( $options['dry_run'] );
        $batch   = isset( $options['batch_size'] ) ? max( 1, (int) $options['batch_size'] ) : 50;

        if ( $this->state->is_complete() ) {
            return array(
                'phase'    => 'complete',
                'done'     => 0,
                'total'    => 0,
                'finished' => true,
            );
        }

        /*
         * A state with no marks on it is the start of a run, so the previous
         * run's report must not bleed into this one. The wizard resets before
         * starting a run too; this is the backstop for direct API callers.
         */
        if ( ! $this->state->is_in_progress() ) {
            $this->report->reset();
        }

        /*
         * The redirects writer checks its own dry-run flag, so it has to carry
         * this batch's flag — otherwise a dry run would append real rows to the
         * audit file.
         */
        $redirects = new Lisensio_KB_Heroic_Import_Redirects( '', $dry_run );

        $content = new Lisensio_KB_Heroic_Import_Content( $this->source, $this->state, $redirects, $this->report, $dry_run );
        $data    = new Lisensio_KB_Heroic_Import_Data( $this->source, $this->state, $content, $this->report, $dry_run, 0 );

        $processed = 0;
        $phase     = $this->state->current_phase();

        switch ( $phase ) {
            case 'terms':
                // Both Heroic taxonomies are migrated in one request; terms are few.
                $content->migrate_terms();
                $processed = 1;
                break;

            case 'articles':
                $processed = $content->migrate_articles_batch( $batch );

                if ( 0 === $processed ) {
                    // Every article now exists, so the URL map is complete: write the drop-in.
                    $redirects->flush();
                }
                break;

            case 'feedback':
                $processed = $data->migrate_feedback_batch( $batch );

                if ( 0 === $processed ) {
                    // The score is a COUNT over the whole table, so recompute once at the end.
                    $data->recompute_all_scores();
                }
                break;

            case 'views':
                $processed = $data->migrate_views_batch( $batch );
                break;

            case 'searches':
                $processed = $data->migrate_searches_batch( $batch );
                break;
        }

        $this->report->save();

        if ( $this->state->is_complete() ) {
            /*
             * Exits are outbound-click analytics: the target model has no home
             * for them, so they are counted and reported rather than silently
             * left behind — the schema documents this table as reported, and
             * the report is where that promise is kept.
             */
            $exits = $this->source->count_table( Lisensio_KB_Heroic_Import_Schema::TABLE_EXITS );

            if ( $exits > 0 ) {
                $this->report->count( 'exits_not_migrated', $exits );
                $this->report->note(
                    'exits',
                    sprintf(
                        /* translators: %d: number of rows. */
                        _n(
                            '%d outbound-click row in the Heroic exits table has no equivalent here and was not migrated.',
                            '%d outbound-click rows in the Heroic exits table have no equivalent here and were not migrated.',
                            $exits,
                            'lisensio-knowledge-base'
                        ),
                        $exits
                    )
                );
            }

            if ( ! $dry_run ) {
                // Closes the run and destroys the salt; the cursors stay so a re-run imports nothing.
                $this->state->finish();

                $this->report->note(
                    'redirects',
                    sprintf(
                        /* translators: %s: absolute path to the generated redirect drop-in. */
                        __( 'Redirect drop-in written to %s — move it to wp-content/mu-plugins/ to serve the 301s.', 'lisensio-knowledge-base' ),
                        $this->redirects->redirect_path()
                    )
                );
            }

            $this->report->save();
        }

        // Totals are per batch: a full count would need an expensive query per
        // request, and the UI signals progress by phase, not by percentage.
        $current = $this->state->current_phase();

        return array(
            'phase'    => null === $current ? 'complete' : $current,
            'done'     => $processed,
            'total'    => $batch,
            'finished' => $this->state->is_complete(),
        );
    }

    /**
     * Whether migration is done.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public function is_complete() {
        return $this->state->is_complete();
    }

    /**
     * Reset the migration.
     *
     * Clears the resume cursors and the report. Content already migrated is
     * left alone — it carries provenance meta and a fresh run skips it.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function reset() {
        $this->state->reset();
        $this->report->reset();
    }

    /**
     * Final summary of the migration.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function summary() {
        $summary = $this->report->get_all();

        // A dry run writes no artefacts, so the download links only appear once the files exist.
        if ( file_exists( $this->redirects->redirect_path() ) ) {
            $summary['redirects_url'] = $this->redirects->download_url( 'plugin' );
        }

        if ( file_exists( $this->redirects->map_path() ) ) {
            $summary['url_map_url'] = $this->redirects->download_url( 'map' );
        }

        return $summary;
    }
}
