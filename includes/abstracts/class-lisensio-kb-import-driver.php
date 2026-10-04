<?php
/**
 * An import source, as the wizard drives it.
 *
 * The run itself is the same for every source and lives here: a run is a
 * sequence of phases walked one AJAX batch at a time, `settings`, `terms` and
 * `articles` first and then the source's own analytics phases. This class decides when a
 * run starts, resumes or restarts, keeps a dry run's state apart from the real
 * import's, writes the run's start, end and any crash to the import log, and
 * hands back the summary.
 *
 * A source supplies its reader (`source()`), its analytics phases and how to
 * run one batch of each, and the counts the wizard previews. Adding a source is
 * a subclass registered through the `lisensio_import_drivers` filter; nothing
 * in the framework changes.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Defines and runs one import source.
 *
 * @since 1.0.0
 */
abstract class Lisensio_KB_Import_Driver {

    /**
     * The phases every source runs first.
     *
     * `settings` copies the source's settings when the operator asked for it;
     * `terms` and `articles` carry provenance. A new run walks all three again
     * from the start without creating anything twice.
     *
     * @since 1.0.0
     * @var string[]
     */
    const CONTENT_PHASES = array( 'settings', 'terms', 'articles' );

    /**
     * The source's reader.
     *
     * @since 1.0.0
     *
     * @return Lisensio_KB_Import_Source The reader.
     */
    abstract public function source();

    /**
     * The analytics phases, in the order they run, after the content phases.
     *
     * @since 1.0.0
     *
     * @return string[] The phase names.
     */
    abstract public function data_phases();

    /**
     * Run one batch of one analytics phase.
     *
     * @since 1.0.0
     *
     * @param string                     $phase   One of data_phases().
     * @param int                        $batch   How many source rows to read.
     * @param Lisensio_KB_Import_Content $content The content importer, for article ID translation.
     * @param Lisensio_KB_Import_State   $state   The resume point.
     * @param Lisensio_KB_Import_Report  $report  The run report.
     * @param Lisensio_KB_Import_Log     $log     The import log.
     * @param bool                       $dry_run Whether to suppress every write.
     *
     * @return int How many source rows the batch read. Zero closes the phase.
     */
    abstract protected function run_data_batch( $phase, $batch, Lisensio_KB_Import_Content $content, Lisensio_KB_Import_State $state, Lisensio_KB_Import_Report $report, Lisensio_KB_Import_Log $log, $dry_run );

    /**
     * How many rows each analytics table holds, for the preview.
     *
     * @since 1.0.0
     *
     * @return array<string, int> Count key to count.
     */
    abstract protected function data_counts();

    /**
     * The labels of the counts `counts()` returns.
     *
     * @since 1.0.0
     *
     * @return array<string, string> Count key to label.
     */
    abstract public function count_labels();

    /**
     * The source driver id.
     *
     * @since 1.0.0
     *
     * @return string The id.
     */
    public function id() {
        return $this->source()->id();
    }

    /**
     * The source's name as the operator knows it.
     *
     * @since 1.0.0
     *
     * @return string The label.
     */
    public function label() {
        return $this->source()->label();
    }

    /**
     * Every phase, in order.
     *
     * @since 1.0.0
     *
     * @return string[] The phase names.
     */
    public function phases() {
        return array_merge( self::CONTENT_PHASES, $this->data_phases() );
    }

    /**
     * Whether this source has anything to import.
     *
     * Detection reads the data, not whether the plugin is active, so a site
     * that deactivated the source plugin can still import from it.
     *
     * @since 1.0.0
     *
     * @return bool True if the source is detected.
     */
    public function detect() {
        $counts = $this->counts();

        return $counts['articles'] > 0 || $counts['categories'] > 0 || $counts['tags'] > 0;
    }

    /**
     * How many items of each type this source has.
     *
     * @since 1.0.0
     *
     * @return array<string, int> Count key to count; always has articles, categories and tags.
     */
    public function counts() {
        $source = $this->source();

        return array_merge(
            array(
                'articles'   => $source->count_articles(),
                'categories' => count( $source->import_terms( $source->category_taxonomy() ) ),
                'tags'       => count( $source->import_terms( $source->tag_taxonomy() ) ),
            ),
            $this->data_counts()
        );
    }

    /**
     * The source's category tree, for the wizard's mapping step.
     *
     * @since 1.0.0
     *
     * @return array<int, array<string, mixed>> Rows of id, name, parent, count and type, parents first.
     */
    public function category_tree() {
        $source = $this->source();
        $tree   = array();

        foreach ( $source->import_terms( $source->category_taxonomy() ) as $row ) {
            $tree[] = array(
                'id'     => (int) $row['term_id'],
                'name'   => isset( $row['name'] ) ? (string) $row['name'] : '',
                'parent' => isset( $row['parent'] ) ? (int) $row['parent'] : 0,
                'count'  => isset( $row['count'] ) ? (int) $row['count'] : 0,
                'type'   => ! empty( $row['item_type'] ) ? (string) $row['item_type'] : 'category',
            );
        }

        return $tree;
    }

    /**
     * Where the wizard stands with this source.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed> in_progress, dry_in_progress, phase and finished_at.
     */
    public function status() {
        $state = $this->state( false );
        $dry   = $this->state( true );

        return array(
            'in_progress'     => $state->is_in_progress(),
            'dry_in_progress' => $dry->is_in_progress(),
            'phase'           => (string) $state->current_phase(),
            'finished_at'     => $state->finished_at(),
        );
    }

    /**
     * Process one batch of the import.
     *
     * The first batch of a run starts it: the report and the audit file are
     * cleared, the operator's category mapping is stored, and a run id is
     * issued. A later batch carries on from the stored cursors, which is also
     * how an interrupted run resumes. A real run asked for after a finished one
     * starts a new run over the same cursors: content is walked again (and
     * skipped where already imported), analytics continue from where they
     * stopped.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $options dry_run, batch_size, category_map (source term ID to target term ID)
     *                                      and import_settings.
     *
     * @return array{phase: string, done: int, total: int, finished: bool, run_id: string, summary?: array<string, mixed>} Progress, and the summary once finished.
     * @throws Throwable When the batch fails; the failure is logged first.
     */
    public function run_batch( array $options ) {
        $dry_run = ! empty( $options['dry_run'] );
        $batch   = isset( $options['batch_size'] ) ? max( 1, (int) $options['batch_size'] ) : 50;
        $state   = $this->state( $dry_run );
        $report  = new Lisensio_KB_Import_Report( $this->id(), $dry_run );

        if ( $state->is_finished() ) {
            $state->restart( self::CONTENT_PHASES );
        }

        $redirects = new Lisensio_KB_Import_Redirects( $this->id(), '', $dry_run );
        $starting  = ! $state->has_run();

        if ( $starting ) {
            $report->reset();
            $redirects->start();
            $state->set_category_map( isset( $options['category_map'] ) && is_array( $options['category_map'] ) ? $options['category_map'] : array() );
            $state->set_import_settings( ! empty( $options['import_settings'] ) );

            if ( $dry_run ) {
                $state->seed_cursors( $this->state( false ), $this->data_phases() );
            }
        }

        $log = new Lisensio_KB_Import_Log( $state->run_id(), $this->id(), $dry_run );

        if ( $starting ) {
            $log->entry(
                'run',
                'run',
                0,
                0,
                $this->label(),
                Lisensio_KB_Import_Log::STATUS_INFO,
                $dry_run ? 'Dry run started: nothing is written except this log.' : 'Import started.',
                array(
                    'counts'          => $this->counts(),
                    'category_map'    => $state->category_map(),
                    'import_settings' => $state->import_settings(),
                    'batch_size'      => $batch,
                )
            );
        }

        $content = new Lisensio_KB_Import_Content( $this->source(), $state, $redirects, $report, $dry_run, $log, Lisensio_KB_Importer::provenance_post_keys() );
        $phase   = (string) $state->current_phase();

        try {
            $processed = $this->run_phase( $phase, $batch, $content, $state, $report, $log, $redirects, $dry_run );
        } catch ( Throwable $e ) {
            $report->save();

            $log->entry(
                $phase,
                'run',
                0,
                0,
                $this->label(),
                Lisensio_KB_Import_Log::STATUS_FAILED,
                sprintf( 'The %1$s phase stopped with an error: %2$s (%3$s line %4$d). Run the import again to resume from the last saved point.', $phase, $e->getMessage(), basename( $e->getFile() ), $e->getLine() )
            );

            throw $e;
        }

        $report->save();

        $response = array(
            'phase'    => null === $state->current_phase() ? 'complete' : (string) $state->current_phase(),
            'done'     => $processed,
            'total'    => $batch,
            'finished' => $state->is_complete(),
            'run_id'   => $state->run_id(),
        );

        if ( $response['finished'] ) {
            $response['summary'] = $this->finish_run( $state, $report, $log, $dry_run );
        }

        return $response;
    }

    /**
     * Whether the last real run finished.
     *
     * @since 1.0.0
     *
     * @return bool True when finished.
     */
    public function is_complete() {
        return $this->state( false )->is_finished();
    }

    /**
     * Forget a run's state and report.
     *
     * @since 1.0.0
     *
     * @param bool $dry_run Whether to forget the dry run's rather than the real import's.
     *
     * @return void
     */
    public function reset( $dry_run = false ) {
        $this->state( (bool) $dry_run )->reset();

        $report = new Lisensio_KB_Import_Report( $this->id(), (bool) $dry_run );
        $report->reset();
    }

    /**
     * Summary report of the last real run.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed> counts, notes and the audit file download URL when there is one.
     */
    public function summary() {
        $report  = new Lisensio_KB_Import_Report( $this->id(), false );
        $summary = $report->get_all();

        if ( file_exists( Lisensio_KB_Import_Redirects::map_path_for( $this->id() ) ) ) {
            $summary['url_map_url'] = Lisensio_KB_Import_Redirects::download_url( $this->id() );
        }

        return $summary;
    }

    /**
     * The options this source keeps, for the uninstaller.
     *
     * @since 1.0.0
     *
     * @return string[] Option names.
     */
    public function option_names() {
        return array(
            Lisensio_KB_Import_State::option_for( $this->id(), false ),
            Lisensio_KB_Import_State::option_for( $this->id(), true ),
            Lisensio_KB_Import_Report::option_for( $this->id(), false ),
            Lisensio_KB_Import_Report::option_for( $this->id(), true ),
        );
    }

    /**
     * Anything the source wants said in the summary once a run has finished.
     *
     * @since 1.0.0
     *
     * @param Lisensio_KB_Import_Report $report The run report.
     * @param Lisensio_KB_Import_Log    $log    The import log.
     *
     * @return void
     */
    protected function finish_notes( Lisensio_KB_Import_Report $report, Lisensio_KB_Import_Log $log ) {
        unset( $report, $log );
    }

    /**
     * Run one batch of whichever phase is current.
     *
     * @since 1.0.0
     *
     * @param string                       $phase     The current phase.
     * @param int                          $batch     How many source rows to read.
     * @param Lisensio_KB_Import_Content   $content   The content importer.
     * @param Lisensio_KB_Import_State     $state     The resume point.
     * @param Lisensio_KB_Import_Report    $report    The run report.
     * @param Lisensio_KB_Import_Log       $log       The import log.
     * @param Lisensio_KB_Import_Redirects $redirects The URL map.
     * @param bool                         $dry_run   Whether to suppress every write.
     *
     * @return int How many items the batch processed.
     */
    private function run_phase( $phase, $batch, Lisensio_KB_Import_Content $content, Lisensio_KB_Import_State $state, Lisensio_KB_Import_Report $report, Lisensio_KB_Import_Log $log, Lisensio_KB_Import_Redirects $redirects, $dry_run ) {
        if ( '' === $phase ) {
            return 0;
        }

        if ( 'settings' === $phase ) {
            $this->import_settings( $state, $report, $log, $dry_run );

            return 1;
        }

        if ( 'terms' === $phase ) {
            $content->migrate_terms();

            return 1;
        }

        if ( 'articles' === $phase ) {
            $processed = $content->migrate_articles_batch( $batch );

            if ( 0 === $processed ) {
                $served = $redirects->flush();

                if ( ! $dry_run ) {
                    $log->entry( 'articles', 'run', 0, 0, $this->label(), Lisensio_KB_Import_Log::STATUS_INFO, sprintf( 'Redirect map updated: %d old URLs now redirect.', $served ) );
                }
            }

            return $processed;
        }

        return $this->run_data_batch( $phase, $batch, $content, $state, $report, $log, $dry_run );
    }

    /**
     * Copy the source's settings that have an equivalent here, if asked to.
     *
     * Only settings with a direct equivalent are changed, through
     * `Lisensio_KB_Settings::update()`, so every value is sanitised by this
     * plugin's own schema. URL settings are never changed. Each setting gets a
     * log row saying what it was and what it became; settings with no
     * equivalent are named in one row, by key only, because the source options
     * hold licence and API keys.
     *
     * @since 1.0.0
     *
     * @param Lisensio_KB_Import_State  $state   The resume point.
     * @param Lisensio_KB_Import_Report $report  The run report.
     * @param Lisensio_KB_Import_Log    $log     The import log.
     * @param bool                      $dry_run Whether to suppress every write.
     *
     * @return void
     */
    private function import_settings( Lisensio_KB_Import_State $state, Lisensio_KB_Import_Report $report, Lisensio_KB_Import_Log $log, $dry_run ) {
        if ( ! $state->import_settings() ) {
            $log->entry( 'settings', 'setting', 0, 0, $this->label(), Lisensio_KB_Import_Log::STATUS_INFO, 'Settings were not imported: the option was not selected.' );
            $state->complete( 'settings' );

            return;
        }

        $mapped   = $this->source()->map_settings();
        $settings = new Lisensio_KB_Settings();
        $before   = $settings->all();

        if ( ! $dry_run && ! empty( $mapped['settings'] ) ) {
            $settings->update( $mapped['settings'] );
        }

        $after = $dry_run ? array_merge( $before, $mapped['settings'] ) : ( new Lisensio_KB_Settings() )->all();

        foreach ( array_keys( $mapped['settings'] ) as $key ) {
            $from    = array_key_exists( $key, $before ) ? $before[ $key ] : null;
            $to      = array_key_exists( $key, $after ) ? $after[ $key ] : null;
            $changed = $from !== $to;

            $report->count( $changed ? 'settings_updated' : 'settings_unchanged' );

            $log->entry(
                'settings',
                'setting',
                0,
                0,
                $key,
                $changed ? Lisensio_KB_Import_Log::STATUS_UPDATED : Lisensio_KB_Import_Log::STATUS_SKIPPED,
                $changed ? sprintf( 'Changed from %1$s to %2$s.', wp_json_encode( $from ), wp_json_encode( $to ) ) : 'Already had the same value; left unchanged.',
                array(
                    'from' => $from,
                    'to'   => $to,
                )
            );
        }

        foreach ( $mapped['excluded'] as $key => $reason ) {
            $report->count( 'settings_excluded' );

            $log->entry( 'settings', 'setting', 0, 0, $key, Lisensio_KB_Import_Log::STATUS_SKIPPED, 'Not imported: ' . $reason . '.' );
        }

        if ( ! empty( $mapped['unmapped'] ) ) {
            $report->count( 'settings_unmapped', count( $mapped['unmapped'] ) );

            $note = sprintf( '%1$d %2$s settings have no equivalent here and were not imported: %3$s.', count( $mapped['unmapped'] ), $this->label(), implode( ', ', $mapped['unmapped'] ) );

            $report->note( 'settings', $note );
            $log->entry( 'settings', 'setting', 0, 0, $this->label(), Lisensio_KB_Import_Log::STATUS_INFO, $note );
        }

        $state->complete( 'settings' );
    }

    /**
     * Close a finished run and build its summary.
     *
     * A real run keeps its cursors for the next run and destroys its salt. A
     * dry run's state and report are deleted once the summary is built, so it
     * leaves nothing behind but its log.
     *
     * @since 1.0.0
     *
     * @param Lisensio_KB_Import_State  $state   The resume point.
     * @param Lisensio_KB_Import_Report $report  The run report.
     * @param Lisensio_KB_Import_Log    $log     The import log.
     * @param bool                      $dry_run Whether this is a dry run.
     *
     * @return array<string, mixed> The summary.
     */
    private function finish_run( Lisensio_KB_Import_State $state, Lisensio_KB_Import_Report $report, Lisensio_KB_Import_Log $log, $dry_run ) {
        $this->finish_notes( $report, $log );

        $summary = $report->get_all();

        $summary['run_id']  = $state->run_id();
        $summary['dry_run'] = (bool) $dry_run;

        $log->entry(
            'run',
            'run',
            0,
            0,
            $this->label(),
            Lisensio_KB_Import_Log::STATUS_INFO,
            $dry_run ? 'Dry run finished.' : 'Import finished.',
            array( 'counts' => $summary['counts'] )
        );

        if ( $dry_run ) {
            $state->reset();
            $report->reset();

            return $summary;
        }

        $report->save();
        $state->finish();

        if ( file_exists( Lisensio_KB_Import_Redirects::map_path_for( $this->id() ) ) ) {
            $summary['url_map_url'] = Lisensio_KB_Import_Redirects::download_url( $this->id() );
        }

        return $summary;
    }

    /**
     * The state of a real or a dry run.
     *
     * @since 1.0.0
     *
     * @param bool $dry_run Whether to load the dry run's state.
     *
     * @return Lisensio_KB_Import_State The state.
     */
    protected function state( $dry_run ) {
        return new Lisensio_KB_Import_State( $this->id(), $this->phases(), true, (bool) $dry_run );
    }
}
