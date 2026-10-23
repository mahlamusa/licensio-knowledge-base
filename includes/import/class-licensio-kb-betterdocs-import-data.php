<?php
/**
 * Moves BetterDocs' reactions, views and searches into the plugin's tables.
 *
 * BetterDocs keeps daily totals; `Licensio_KB_BetterDocs_Import_Map` expands
 * each total back into one row per event, and this class reads the totals in
 * batches, inserts the expanded rows, and logs what it did.
 *
 * Only finished days are read. BetterDocs keeps adding to today's row until
 * midnight UTC, so importing it would freeze a partial count and the next run
 * — which starts after the last row it read — would never see the rest. Today
 * is left for the next run, and the summary says so.
 *
 * Idempotency is the resume cursor, exactly as in the Heroic import: the
 * target tables have no column naming the source row, so the cursor is the
 * only record of what was read.
 *
 * A row the database refuses is logged as failed, with the database's
 * error, and the cursor moves past it: retrying a half-written expanded day
 * would duplicate the part that was written, so failures are for the
 * operator to read in the log rather than for the next run to repeat.
 *
 * A single day can expand into thousands of rows, so expanded rows are written
 * with multi-row INSERTs, and a batch stops early once it has written about
 * EXPANSION_BUDGET rows, so one busy article cannot push a request past its
 * time limit.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Imports `betterdocs_analytics` and `betterdocs_search_log`.
 *
 * @since 1.0.0
 */
class Licensio_KB_BetterDocs_Import_Data {

    /**
     * Roughly how many expanded rows one batch may write before it stops.
     *
     * @since 1.0.0
     * @var int
     */
    const EXPANSION_BUDGET = 5000;

    /**
     * How many rows one INSERT statement carries.
     *
     * @since 1.0.0
     * @var int
     */
    const INSERT_CHUNK = 500;

    /**
     * The batched reader over the BetterDocs data.
     *
     * @since 1.0.0
     * @var Licensio_KB_Import_Source
     */
    private $source;

    /**
     * The resume point.
     *
     * @since 1.0.0
     * @var Licensio_KB_Import_State
     */
    private $state;

    /**
     * Translates a BetterDocs article ID into a target article ID.
     *
     * @since 1.0.0
     * @var Licensio_KB_Import_Content
     */
    private $content;

    /**
     * Where counts are recorded.
     *
     * @since 1.0.0
     * @var Licensio_KB_Import_Report
     */
    private $report;

    /**
     * Where batches and failed rows are recorded.
     *
     * @since 1.0.0
     * @var Licensio_KB_Import_Log
     */
    private $log;

    /**
     * Whether this run may write.
     *
     * @since 1.0.0
     * @var bool
     */
    private $dry_run = false;

    /**
     * BetterDocs article IDs already looked up, and what they resolved to.
     *
     * @since 1.0.0
     * @var array<int, int>
     */
    private $resolved = array();

    /**
     * Constructor.
     *
     * @since 1.0.0
     *
     * @param Licensio_KB_Import_Source   $source  The batched reader.
     * @param Licensio_KB_Import_State    $state   The resume point.
     * @param Licensio_KB_Import_Content  $content The article ID translator.
     * @param Licensio_KB_Import_Report   $report  The run report.
     * @param bool                        $dry_run Whether to suppress every write.
     * @param Licensio_KB_Import_Log|null $log     The import log; none when null.
     */
    public function __construct(
        Licensio_KB_Import_Source $source,
        Licensio_KB_Import_State $state,
        Licensio_KB_Import_Content $content,
        Licensio_KB_Import_Report $report,
        $dry_run = false,
        $log = null
    ) {
        $this->source  = $source;
        $this->state   = $state;
        $this->content = $content;
        $this->report  = $report;
        $this->dry_run = (bool) $dry_run;
        $this->log     = $log instanceof Licensio_KB_Import_Log ? $log : Licensio_KB_Import_Log::disabled();
    }

    /**
     * Import one batch of daily reactions into `licensio_feedback`.
     *
     * @since 1.0.0
     *
     * @param int $batch How many source rows to read.
     *
     * @return int How many source rows this batch processed. Zero means the phase is done.
     */
    public function migrate_feedback_batch( $batch ) {
        return $this->analytics_batch( 'feedback', $batch );
    }

    /**
     * Import one batch of daily impressions into `licensio_article_views`.
     *
     * @since 1.0.0
     *
     * @param int $batch How many source rows to read.
     *
     * @return int How many source rows this batch processed. Zero means the phase is done.
     */
    public function migrate_views_batch( $batch ) {
        return $this->analytics_batch( 'views', $batch );
    }

    /**
     * Import one batch of daily keyword totals into `licensio_search_log`.
     *
     * @since 1.0.0
     *
     * @param int $batch How many source rows to read.
     *
     * @return int How many source rows this batch processed. Zero means the phase is done.
     */
    public function migrate_searches_batch( $batch ) {
        $from = $this->state->cursor( 'searches' );
        $rows = $this->read( 'searches', Licensio_KB_BetterDocs_Import_Schema::TABLE_SEARCH_LOG, $batch );

        if ( empty( $rows ) ) {
            return 0;
        }

        $keywords = $this->keywords( wp_list_pluck( $rows, 'keyword_id' ) );
        $tally    = $this->tally();
        $written  = 0;
        $done     = 0;

        foreach ( $rows as $row ) {
            $id             = (int) $row['id'];
            $keyword_id     = isset( $row['keyword_id'] ) ? (int) $row['keyword_id'] : 0;
            $row['keyword'] = isset( $keywords[ $keyword_id ] ) ? $keywords[ $keyword_id ] : '';
            $expanded       = Licensio_KB_BetterDocs_Import_Map::search_rows( $row, $this->state->salt() );

            if ( empty( $expanded ) ) {
                $this->report->count( 'searches_empty' );
                ++$tally['skipped'];
            } elseif ( $this->insert( Licensio_KB_Search_Log::TABLE, $expanded ) ) {
                $this->report->count( 'searches_migrated', count( $expanded ) );
                $tally['migrated'] += count( $expanded );
                $written           += count( $expanded );
            } else {
                $this->fail( 'searches', 'search', $id, 0, sprintf( 'The %1$d searches for "%2$s" on %3$s could not be written', count( $expanded ), $row['keyword'], $row['created_at'] ) );
                ++$tally['failed'];
            }

            $this->state->set_cursor( 'searches', $id );
            ++$done;

            if ( $written >= self::EXPANSION_BUDGET ) {
                break;
            }
        }

        $this->log_batch( 'searches', $done, $from, $tally );

        return $done;
    }

    /**
     * Recompute the usefulness score of every imported article.
     *
     * Once, when the feedback phase runs dry, from every vote in the table.
     *
     * @since 1.0.0
     *
     * @return int How many articles were recomputed.
     */
    public function recompute_all_scores() {
        if ( $this->dry_run ) {
            return 0;
        }

        $query = new WP_Query(
            array(
                'post_type'      => Licensio_KB_Post_Types::POST_TYPE,
                'post_status'    => 'any',
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'no_found_rows'  => true,
                // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Runs once per import, on the imported set only.
                'meta_key'       => $this->source->provenance_post_key(),
                'meta_compare'   => 'EXISTS',
            )
        );

        $feedback = new Licensio_KB_Feedback();

        foreach ( $query->posts as $article_id ) {
            $feedback->recompute_score( (int) $article_id );
        }

        $done = count( $query->posts );

        $this->report->count( 'scores_recomputed', $done );

        return $done;
    }

    /**
     * Import one batch of `betterdocs_analytics` for the feedback or views phase.
     *
     * @since 1.0.0
     *
     * @param string $phase `feedback` or `views`.
     * @param int    $batch How many source rows to read.
     *
     * @return int How many source rows this batch processed.
     */
    private function analytics_batch( $phase, $batch ) {
        $from = $this->state->cursor( $phase );
        $rows = $this->read( $phase, Licensio_KB_BetterDocs_Import_Schema::TABLE_ANALYTICS, $batch );

        if ( empty( $rows ) ) {
            return 0;
        }

        $views   = 'views' === $phase;
        $table   = $views ? Licensio_KB_Analytics::TABLE_ARTICLE_VIEWS : Licensio_KB_Feedback::TABLE;
        $tally   = $this->tally();
        $written = 0;
        $done    = 0;

        foreach ( $rows as $row ) {
            $id      = (int) $row['id'];
            $post_id = isset( $row['post_id'] ) ? (int) $row['post_id'] : 0;
            $total   = $views
                ? ( isset( $row['impressions'] ) ? (int) $row['impressions'] : 0 )
                : ( isset( $row['happy'] ) ? (int) $row['happy'] : 0 ) + ( isset( $row['normal'] ) ? (int) $row['normal'] : 0 ) + ( isset( $row['sad'] ) ? (int) $row['sad'] : 0 );

            if ( $total <= 0 ) {
                ++$tally['skipped'];
            } else {
                $article_id = $this->resolve_article( $post_id );

                if ( $article_id <= 0 ) {
                    $this->report->count( $phase . '_orphaned', $total );
                    ++$tally['orphaned'];

                    $this->log->entry( $phase, $views ? 'view' : 'feedback', $id, 0, '', Licensio_KB_Import_Log::STATUS_ORPHANED, sprintf( '%1$d %2$s on %3$s are for BetterDocs article %4$d, which no longer exists; not imported.', $total, $views ? 'views' : 'reactions', (string) $row['created_at'], $post_id ) );
                } else {
                    $expanded = $views
                        ? Licensio_KB_BetterDocs_Import_Map::view_rows( $row, $article_id, $this->state->salt() )
                        : Licensio_KB_BetterDocs_Import_Map::feedback_rows( $row, $article_id, $this->state->salt() );

                    if ( $this->insert( $table, $expanded ) ) {
                        $this->report->count( $phase . '_migrated', count( $expanded ) );
                        $tally['migrated'] += count( $expanded );
                        $written           += count( $expanded );
                    } else {
                        $this->fail( $phase, $views ? 'view' : 'feedback', $id, $article_id, sprintf( 'The %1$d %2$s of article %3$d on %4$s could not be written', count( $expanded ), $views ? 'views' : 'reactions', $article_id, (string) $row['created_at'] ) );
                        ++$tally['failed'];
                    }
                }
            }

            $this->state->set_cursor( $phase, $id );
            ++$done;

            if ( $written >= self::EXPANSION_BUDGET ) {
                break;
            }
        }

        $this->log_batch( $phase, $done, $from, $tally );

        return $done;
    }

    /**
     * Read the next batch of finished days, closing the phase when it runs dry.
     *
     * @since 1.0.0
     *
     * @param string $phase The phase.
     * @param string $table The source table.
     * @param int    $batch The batch size.
     *
     * @return array<int, array<string, mixed>> The rows.
     */
    private function read( $phase, $table, $batch ) {
        $rows = $this->source->rows( $table, Licensio_KB_BetterDocs_Import_Schema::KEY_ID, $this->state->cursor( $phase ), $batch, gmdate( 'Y-m-d' ) );

        if ( empty( $rows ) ) {
            $this->state->complete( $phase );
        }

        return $rows;
    }

    /**
     * The keyword text of a set of keyword IDs.
     *
     * @since 1.0.0
     *
     * @param array<int, mixed> $ids The keyword IDs.
     *
     * @return array<int, string> Keyword ID to keyword.
     */
    private function keywords( array $ids ) {
        global $wpdb;

        $ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );

        if ( empty( $ids ) || ! Licensio_KB_Import_Source::table_exists( Licensio_KB_BetterDocs_Import_Schema::TABLE_SEARCH_KEYWORD ) ) {
            return array();
        }

        $table = Licensio_KB_Import_Source::table( Licensio_KB_BetterDocs_Import_Schema::TABLE_SEARCH_KEYWORD );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, keyword FROM %i WHERE id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')',
                array_merge( array( $table ), $ids )
            ),
            ARRAY_A
        );

        $out = array();

        foreach ( (array) $rows as $row ) {
            $out[ (int) $row['id'] ] = (string) $row['keyword'];
        }

        return $out;
    }

    /**
     * Insert one day's expanded rows in multi-row statements, all or nothing,
     * unless this is a dry run.
     *
     * @since 1.0.0
     *
     * @param string                           $table The unprefixed target table.
     * @param array<int, array<string, mixed>> $rows  The rows; all with the same columns.
     *
     * @return bool True when every row was written.
     */
    private function insert( $table, array $rows ) {
        global $wpdb;

        if ( $this->dry_run || empty( $rows ) ) {
            return true;
        }

        $columns = array_keys( $rows[0] );
        $name    = Licensio_KB_DB::table_name( $table );
        $formats = array();

        foreach ( $rows[0] as $value ) {
            $formats[] = is_int( $value ) ? '%d' : '%s';
        }

        $tuple = '(' . implode( ', ', $formats ) . ')';

        /*
         * One day is written all or nothing. Without the transaction a chunk
         * failing after earlier chunks committed would leave part of the day
         * in the table while the log says none of it was written, and a
         * request that ran out of time mid-day would leave rows behind that a
         * resumed run, starting before that day, would write again.
         *
         * One gap remains: if the connection drops mid-day, wpdb reconnects
         * and retries in autocommit mode, so the chunks before the drop are
         * lost while the rest commit. That needs a server failure during an
         * import and is not detected here.
         */
        $wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

        foreach ( array_chunk( $rows, self::INSERT_CHUNK ) as $chunk ) {
            $values = array();

            foreach ( $chunk as $row ) {
                foreach ( $columns as $column ) {
                    $values[] = $row[ $column ];
                }
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $tuple holds only the literal placeholders %d and %s.
            $result = $wpdb->query(
                // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The one array holds every replacement.
                $wpdb->prepare(
                    'INSERT INTO %i (' . implode( ', ', array_fill( 0, count( $columns ), '%i' ) ) . ') VALUES ' . implode( ', ', array_fill( 0, count( $chunk ), $tuple ) ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $tuple is one generated %d or %s per column.
                    array_merge( array( $name ), $columns, $values )
                )
            );

            if ( false === $result ) {
                $error = (string) $wpdb->last_error;

                $wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

                // Keep the failing statement's error for the log, not the rollback's.
                $wpdb->last_error = $error;

                return false;
            }
        }

        $wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

        return true;
    }

    /**
     * Translate a BetterDocs article ID into a target article ID.
     *
     * In a dry run no target article exists yet, so a row whose BetterDocs
     * article exists counts as importable, as in the Heroic import.
     *
     * @since 1.0.0
     *
     * @param int $post_id The BetterDocs post ID.
     *
     * @return int The target article ID, or 0 when the article was not imported.
     */
    private function resolve_article( $post_id ) {
        $post_id = (int) $post_id;

        if ( $post_id <= 0 ) {
            return 0;
        }

        if ( ! array_key_exists( $post_id, $this->resolved ) ) {
            $this->resolved[ $post_id ] = $this->content->find_article( $post_id );

            if ( $this->dry_run && $this->resolved[ $post_id ] <= 0 && $this->source->article_exists( $post_id ) ) {
                $this->resolved[ $post_id ] = $post_id;
            }
        }

        return (int) $this->resolved[ $post_id ];
    }

    /**
     * A fresh per-batch tally for the log.
     *
     * @since 1.0.0
     *
     * @return array<string, int> Zeroed counters.
     */
    private function tally() {
        return array(
            'migrated' => 0,
            'skipped'  => 0,
            'orphaned' => 0,
            'failed'   => 0,
        );
    }

    /**
     * Log one batch's outcome.
     *
     * @since 1.0.0
     *
     * @param string             $phase The phase.
     * @param int                $read  How many daily rows the batch processed.
     * @param int                $from  The cursor before the batch.
     * @param array<string, int> $tally The batch's counters; `migrated` counts expanded rows.
     *
     * @return void
     */
    private function log_batch( $phase, $read, $from, array $tally ) {
        $this->log->entry(
            $phase,
            'batch',
            0,
            0,
            sprintf( '%1$s daily rows %2$d to %3$d', ucfirst( $phase ), $from + 1, $this->state->cursor( $phase ) ),
            $tally['failed'] > 0 ? Licensio_KB_Import_Log::STATUS_WARNING : Licensio_KB_Import_Log::STATUS_INFO,
            sprintf( 'Read %1$d daily totals, imported %2$d events, skipped %3$d empty days, orphaned %4$d, failed %5$d.', $read, $tally['migrated'], $tally['skipped'], $tally['orphaned'], $tally['failed'] ),
            array_merge( array( 'read' => $read ), $tally )
        );
    }

    /**
     * Count and log rows the database refused.
     *
     * @since 1.0.0
     *
     * @param string $phase      The phase.
     * @param string $item_type  The log item type.
     * @param int    $source_id  The source row key.
     * @param int    $article_id The target article ID, or 0.
     * @param string $what       What could not be written.
     *
     * @return void
     */
    private function fail( $phase, $item_type, $source_id, $article_id, $what ) {
        global $wpdb;

        $error = isset( $wpdb ) && '' !== (string) $wpdb->last_error ? (string) $wpdb->last_error : 'the database returned no error message';

        $this->report->count( $phase . '_failed' );

        $this->log->entry( $phase, $item_type, $source_id, $article_id, '', Licensio_KB_Import_Log::STATUS_FAILED, $what . ': ' . $error . '.' );
    }
}
