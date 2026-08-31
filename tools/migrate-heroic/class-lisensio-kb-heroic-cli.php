<?php
/**
 * The `wp lisensio-kb migrate-heroic` command.
 *
 * Drives the phases in dependency order — terms, articles, feedback, views,
 * searches — reporting after each batch, resuming from wherever the last run
 * stopped, and refusing to invent work it has already done.
 *
 * The command is registered by `tools/migrate-heroic/migrate-heroic.php`, which
 * the operator requires explicitly. It is deliberately not autoloaded and not
 * wired into `Lisensio_KB::boot()`: this is a one-time tool that ships in
 * neither build artifact and is deleted once the migration is signed off.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * One-time migration from Heroic Knowledge Base.
 *
 * @since 1.0.0
 */
class Lisensio_KB_Heroic_CLI {

    /**
     * How many source rows a batch reads when the operator does not say.
     *
     * @since 1.0.0
     * @var int
     */
    const DEFAULT_BATCH = 200;

    /**
     * Register the command with WP-CLI.
     *
     * @since 1.0.0
     *
     * @return bool True when the command was registered.
     */
    public static function register() {
        if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( 'WP_CLI' ) ) {
            return false;
        }

        WP_CLI::add_command( 'lisensio-kb migrate-heroic', array( __CLASS__, 'run' ) );

        return true;
    }

    /**
     * Migrate a Heroic Knowledge Base into this plugin.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Report exactly what a real run would do and write nothing at all — no
     * posts, no terms, no rows, no files, and no resume state.
     *
     * [--batch-size=<n>]
     * : How many source rows to process per batch. Default 200.
     *
     * [--phase=<phase>]
     * : Run one phase only: terms, articles, feedback, views or searches.
     *
     * [--reset]
     * : Forget the resume point and start over. Content is unaffected because it
     * carries its own provenance, but the three analytics tables have nowhere to
     * record which Heroic row they came from, so resetting WILL re-import and
     * therefore duplicate votes, views and searches. Use it only after emptying
     * those tables.
     *
     * [--output-dir=<path>]
     * : Where to write the URL map and the redirect drop-in.
     * Default wp-content/lisensio-heroic-migration.
     *
     * [--source-timezone-offset=<seconds>]
     * : Seconds to subtract from Heroic datetimes to reach UTC. Heroic is not
     * consistent — votes are written in UTC, visits and searches in the database
     * server's timezone — so check before trusting the default of 0.
     *
     * ## EXAMPLES
     *
     *     wp lisensio-kb migrate-heroic --dry-run
     *     wp lisensio-kb migrate-heroic --batch-size=500
     *     wp lisensio-kb migrate-heroic --phase=searches
     *
     * @since 1.0.0
     *
     * @param string[]                   $args       Positional arguments. Unused.
     * @param array<string, string|bool> $assoc_args Associative arguments.
     *
     * @return void
     */
    public static function run( $args, $assoc_args ) {
        unset( $args );

        $assoc_args = is_array( $assoc_args ) ? $assoc_args : array();

        $phase = isset( $assoc_args['phase'] ) ? (string) $assoc_args['phase'] : '';

        /*
         * Validated before anything else is read, so a typo in the phase name
         * costs nothing. WP_CLI::error() halts the process in production but
         * only throws under the test double, hence the explicit return.
         */
        if ( '' !== $phase && ! in_array( $phase, Lisensio_KB_Heroic_State::PHASES, true ) ) {
            WP_CLI::error( sprintf( 'Unknown phase "%1$s". Known phases: %2$s.', $phase, implode( ', ', Lisensio_KB_Heroic_State::PHASES ) ) );

            return;
        }

        $dry_run = ! empty( $assoc_args['dry-run'] );
        $batch   = isset( $assoc_args['batch-size'] ) ? max( 1, (int) $assoc_args['batch-size'] ) : self::DEFAULT_BATCH;
        $offset  = isset( $assoc_args['source-timezone-offset'] ) ? (int) $assoc_args['source-timezone-offset'] : 0;
        $output  = isset( $assoc_args['output-dir'] )
            ? (string) $assoc_args['output-dir']
            : WP_CONTENT_DIR . '/lisensio-heroic-migration';

        $state = new Lisensio_KB_Heroic_State( ! $dry_run );

        if ( ! empty( $assoc_args['reset'] ) ) {
            $state->reset();

            WP_CLI::warning( 'Resume point cleared. Analytics rows already imported will be imported again.' );
        }

        $source    = new Lisensio_KB_Heroic_Source();
        $report    = new Lisensio_KB_Heroic_Report();
        $redirects = new Lisensio_KB_Heroic_Redirects( $output, $dry_run );
        $content   = new Lisensio_KB_Heroic_Content_Migrator( $source, $state, $redirects, $report, $dry_run );
        $data      = new Lisensio_KB_Heroic_Data_Migrator( $source, $state, $content, $report, $dry_run, $offset );

        self::report_source( $source );

        if ( $dry_run ) {
            WP_CLI::log( 'Dry run: nothing will be written.' );
        }

        $started = microtime( true );

        foreach ( Lisensio_KB_Heroic_State::PHASES as $name ) {
            if ( '' !== $phase && $phase !== $name ) {
                continue;
            }

            if ( $state->is_complete( $name ) ) {
                WP_CLI::log( sprintf( 'Phase %s is already complete; skipping.', $name ) );

                continue;
            }

            self::run_phase( $name, $batch, $content, $data );
        }

        if ( '' === $phase || 'feedback' === $phase ) {
            $recomputed = $data->recompute_scores();

            if ( $recomputed > 0 ) {
                WP_CLI::log( sprintf( 'Recomputed usefulness for %d articles.', $recomputed ) );
            }
        }

        self::write_artifacts( $redirects, $dry_run );
        self::report_totals( $report, $redirects, $dry_run );

        if ( '' === $phase && ! $dry_run && self::all_phases_complete( $state ) ) {
            $state->finish();

            WP_CLI::log( 'Migration salt destroyed. Migrated visitor hashes are now irreversible.' );
        }

        WP_CLI::success(
            sprintf(
                '%1$s in %2$s seconds. Reindex the search index next: wp lisensio-kb reindex --flush',
                $dry_run ? 'Dry run finished' : 'Migration run finished',
                number_format_i18n( microtime( true ) - $started, 2 )
            )
        );
    }

    /**
     * Drive one phase to the end of its source.
     *
     * @since 1.0.0
     *
     * @param string                              $name    The phase name.
     * @param int                                 $batch   How many rows per batch.
     * @param Lisensio_KB_Heroic_Content_Migrator $content The content migrator.
     * @param Lisensio_KB_Heroic_Data_Migrator    $data    The analytics migrator.
     *
     * @return void
     */
    private static function run_phase( $name, $batch, $content, $data ) {
        WP_CLI::log( sprintf( 'Phase: %s', $name ) );

        if ( 'terms' === $name ) {
            $content->migrate_terms(
                function ( $done ) {
                    if ( 0 === $done % 25 ) {
                        WP_CLI::log( sprintf( '  %d terms…', (int) $done ) );
                    }
                }
            );

            return;
        }

        $total = 0;

        do {
            switch ( $name ) {
                case 'articles':
                    $read = $content->migrate_articles_batch( $batch );
                    break;
                case 'feedback':
                    $read = $data->migrate_feedback_batch( $batch );
                    break;
                case 'views':
                    $read = $data->migrate_views_batch( $batch );
                    break;
                default:
                    $read = $data->migrate_searches_batch( $batch );
                    break;
            }

            $total += $read;

            if ( $read > 0 ) {
                WP_CLI::log( sprintf( '  %d rows…', $total ) );
            }
        } while ( $read > 0 );
    }

    /**
     * Report what is on the source side before touching anything.
     *
     * @since 1.0.0
     *
     * @param Lisensio_KB_Heroic_Source $source The batched reader.
     *
     * @return void
     */
    private static function report_source( Lisensio_KB_Heroic_Source $source ) {
        WP_CLI::log( 'Heroic Knowledge Base source:' );
        WP_CLI::log( sprintf( '  %-28s %d', 'ht_kb articles', $source->count_articles() ) );
        WP_CLI::log( sprintf( '  %-28s %d', 'ht_kb_category terms', count( $source->terms( Lisensio_KB_Heroic_Schema::TAXONOMY_CATEGORY ) ) ) );
        WP_CLI::log( sprintf( '  %-28s %d', 'ht_kb_tag terms', count( $source->terms( Lisensio_KB_Heroic_Schema::TAXONOMY_TAG ) ) ) );
        WP_CLI::log( sprintf( '  %-28s %d', Lisensio_KB_Heroic_Schema::TABLE_VOTING, $source->count_table( Lisensio_KB_Heroic_Schema::TABLE_VOTING ) ) );
        WP_CLI::log( sprintf( '  %-28s %d', Lisensio_KB_Heroic_Schema::TABLE_VISITS, $source->count_table( Lisensio_KB_Heroic_Schema::TABLE_VISITS ) ) );
        WP_CLI::log( sprintf( '  %-28s %d', Lisensio_KB_Heroic_Schema::TABLE_SEARCHES, $source->count_table( Lisensio_KB_Heroic_Schema::TABLE_SEARCHES ) ) );

        $exits = $source->count_table( Lisensio_KB_Heroic_Schema::TABLE_EXITS );

        WP_CLI::log( sprintf( '  %-28s %d (not migrated: no target table)', Lisensio_KB_Heroic_Schema::TABLE_EXITS, $exits ) );
    }

    /**
     * Write the URL map and the redirect drop-in.
     *
     * @since 1.0.0
     *
     * @param Lisensio_KB_Heroic_Redirects $redirects The URL map.
     * @param bool                         $dry_run   Whether this run may write.
     *
     * @return void
     */
    private static function write_artifacts( Lisensio_KB_Heroic_Redirects $redirects, $dry_run ) {
        if ( $dry_run ) {
            WP_CLI::log( sprintf( 'Would write %d redirects to %s', $redirects->count(), $redirects->redirect_path() ) );

            return;
        }

        if ( $redirects->write_redirect_plugin() ) {
            WP_CLI::log( sprintf( 'URL map written to %s', $redirects->map_path() ) );
            WP_CLI::log( sprintf( 'Redirect drop-in written to %s', $redirects->redirect_path() ) );
            WP_CLI::log( 'Copy the drop-in into wp-content/mu-plugins/ to serve the 301s.' );
        }
    }

    /**
     * Print the counters and every lossy mapping.
     *
     * @since 1.0.0
     *
     * @param Lisensio_KB_Heroic_Report    $report    The run report.
     * @param Lisensio_KB_Heroic_Redirects $redirects The URL map.
     * @param bool                         $dry_run   Whether this run may write.
     *
     * @return void
     */
    private static function report_totals( Lisensio_KB_Heroic_Report $report, Lisensio_KB_Heroic_Redirects $redirects, $dry_run ) {
        WP_CLI::log( $dry_run ? 'Would migrate:' : 'Migrated:' );

        foreach ( $report->counts() as $name => $value ) {
            WP_CLI::log( sprintf( '  %-28s %d', $name, $value ) );
        }

        WP_CLI::log( sprintf( '  %-28s %d', 'redirects', $redirects->count() ) );

        foreach ( $report->note_categories() as $category ) {
            $notes = $report->notes( $category );

            WP_CLI::warning( sprintf( '%1$s: %2$d', $category, count( $notes ) ) );

            foreach ( array_slice( $notes, 0, 20 ) as $note ) {
                WP_CLI::log( '  ' . $note );
            }

            if ( count( $notes ) > 20 ) {
                WP_CLI::log( sprintf( '  … and %d more', count( $notes ) - 20 ) );
            }
        }
    }

    /**
     * Whether every phase has run to the end of its source.
     *
     * @since 1.0.0
     *
     * @param Lisensio_KB_Heroic_State $state The resume point.
     *
     * @return bool True when the migration is finished.
     */
    private static function all_phases_complete( Lisensio_KB_Heroic_State $state ) {
        foreach ( Lisensio_KB_Heroic_State::PHASES as $phase ) {
            if ( ! $state->is_complete( $phase ) ) {
                return false;
            }
        }

        return true;
    }
}
