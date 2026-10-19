<?php
/**
 * Moves Heroic's votes and analytics into the plugin's tables.
 *
 * This is the half of the migration where the privacy promise is at stake.
 * Every source row here carries a `user_ip`; not one target row may. The rule is
 * enforced structurally rather than by care: this class never builds a target
 * row itself. It hands the source row to `Licensio_KB_Heroic_Import_Map`, which
 * names every target column explicitly, and inserts what comes back. There is
 * no code path from a source column to a target column that does not pass
 * through a named mapping.
 *
 * Writes go through `Licensio_KB_DB::table()`, the same gateway the owning
 * components use, so the rows are the rows those components would have written.
 *
 * Idempotency is the resume cursor, and only the cursor. Unlike articles and
 * terms, these three tables have no column in which to record the Heroic row an
 * entry came from — adding one would mean altering a schema owned by another
 * component to serve the importer. So a phase re-run starts after the last key
 * it wrote and inserts nothing twice; running the import again after it
 * finished keeps these cursors (see `Licensio_KB_Import_State::restart()`) and
 * imports only rows recorded since.
 *
 * A row the database refuses is logged as failed, with the database's
 * error, and the cursor moves past it: retrying a half-written expanded day
 * would duplicate the part that was written, so failures are for the
 * operator to read in the log rather than for the next run to repeat.
 *
 * The log gets one row per batch, and one per vote or view that points at an
 * article which no longer exists or could not be written, with the reason.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Migrates `hkb_voting`, `hkb_visits` and `hkb_analytics_search_atomic`.
 *
 * @since 1.0.0
 */
class Licensio_KB_Heroic_Import_Data {

    /**
     * The batched reader over the Heroic data.
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
     * Translates a Heroic article ID into a target article ID.
     *
     * @since 1.0.0
     * @var Licensio_KB_Import_Content
     */
    private $content;

    /**
     * Where counts and skipped rows are recorded.
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
     * Seconds to subtract from a source datetime to reach UTC.
     *
     * @since 1.0.0
     * @var int
     */
    private $offset = 0;

    /**
     * Target article IDs that received a vote in this run.
     *
     * @since 1.0.0
     * @var array<int, bool>
     */
    private $voted = array();

    /**
     * Heroic article IDs already looked up, and what they resolved to.
     *
     * Analytics rows are dominated by a small number of popular articles, so the
     * same ID is resolved thousands of times. Caching turns a per-row query into
     * a per-article one.
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
     * @param int                         $offset  Seconds to subtract to reach UTC.
     * @param Licensio_KB_Import_Log|null $log     The import log; none when null.
     */
    public function __construct(
        Licensio_KB_Import_Source $source,
        Licensio_KB_Import_State $state,
        Licensio_KB_Import_Content $content,
        Licensio_KB_Import_Report $report,
        $dry_run = false,
        $offset = 0,
        $log = null
    ) {
        $this->source  = $source;
        $this->state   = $state;
        $this->content = $content;
        $this->report  = $report;
        $this->dry_run = (bool) $dry_run;
        $this->offset  = (int) $offset;
        $this->log     = $log instanceof Licensio_KB_Import_Log ? $log : Licensio_KB_Import_Log::disabled();
    }

    /**
     * Migrate one batch of votes into `licensio_feedback`.
     *
     * @since 1.0.0
     *
     * @param int $batch How many source rows to read.
     *
     * @return int How many source rows this batch read. Zero means the phase is done.
     */
    public function migrate_feedback_batch( $batch ) {
        $rows  = $this->read( 'feedback', Licensio_KB_Heroic_Import_Schema::TABLE_VOTING, Licensio_KB_Heroic_Import_Schema::KEY_VOTING, $batch );
        $tally = $this->tally();
        $from  = $this->state->cursor( 'feedback' );

        foreach ( $rows as $row ) {
            $vote_id    = isset( $row[ Licensio_KB_Heroic_Import_Schema::KEY_VOTING ] ) ? (int) $row[ Licensio_KB_Heroic_Import_Schema::KEY_VOTING ] : 0;
            $post_id    = isset( $row['post_id'] ) ? (int) $row['post_id'] : 0;
            $article_id = $this->resolve_article( $post_id );

            if ( $article_id <= 0 ) {
                $this->report->count( 'feedback_orphaned' );
                ++$tally['orphaned'];

                $this->log->entry( 'feedback', 'feedback', $vote_id, 0, '', Licensio_KB_Import_Log::STATUS_ORPHANED, sprintf( 'Vote %1$d is for Heroic article %2$d, which no longer exists; not imported.', $vote_id, $post_id ) );
            } else {
                $target = Licensio_KB_Heroic_Import_Map::feedback_row( $row, $article_id, $this->state->salt(), $this->offset );

                if ( $this->insert( Licensio_KB_Feedback::TABLE, $target ) ) {
                    $this->voted[ $article_id ] = true;
                    $this->report->count( 'feedback_migrated' );
                    ++$tally['migrated'];
                } else {
                    $this->fail( 'feedback', 'feedback', $vote_id, $article_id, sprintf( 'Vote %d could not be written', $vote_id ) );
                    ++$tally['failed'];
                }
            }

            $this->state->set_cursor( 'feedback', $vote_id );
        }

        $this->log_batch( 'feedback', $rows, $from, $tally );

        return count( $rows );
    }

    /**
     * Migrate one batch of visits into `licensio_article_views`.
     *
     * @since 1.0.0
     *
     * @param int $batch How many source rows to read.
     *
     * @return int How many source rows this batch read. Zero means the phase is done.
     */
    public function migrate_views_batch( $batch ) {
        $rows  = $this->read( 'views', Licensio_KB_Heroic_Import_Schema::TABLE_VISITS, Licensio_KB_Heroic_Import_Schema::KEY_VISITS, $batch );
        $tally = $this->tally();
        $from  = $this->state->cursor( 'views' );

        foreach ( $rows as $row ) {
            $visit_id = isset( $row[ Licensio_KB_Heroic_Import_Schema::KEY_VISITS ] ) ? (int) $row[ Licensio_KB_Heroic_Import_Schema::KEY_VISITS ] : 0;

            if ( ! Licensio_KB_Heroic_Import_Map::is_article_visit( $row ) ) {
                $this->report->count( 'views_not_articles' );
                ++$tally['skipped'];
            } else {
                $object_id  = isset( $row['object_id'] ) ? (int) $row['object_id'] : 0;
                $article_id = $this->resolve_article( $object_id );

                if ( $article_id <= 0 ) {
                    $this->report->count( 'views_orphaned' );
                    ++$tally['orphaned'];

                    $this->log->entry( 'views', 'view', $visit_id, 0, '', Licensio_KB_Import_Log::STATUS_ORPHANED, sprintf( 'View %1$d is of Heroic article %2$d, which no longer exists; not imported.', $visit_id, $object_id ) );
                } else {
                    $target = Licensio_KB_Heroic_Import_Map::article_view_row( $row, $article_id, $this->state->salt(), $this->offset );

                    if ( $this->insert( Licensio_KB_Analytics::TABLE_ARTICLE_VIEWS, $target ) ) {
                        $this->report->count( 'views_migrated' );
                        ++$tally['migrated'];
                    } else {
                        $this->fail( 'views', 'view', $visit_id, $article_id, sprintf( 'View %d could not be written', $visit_id ) );
                        ++$tally['failed'];
                    }
                }
            }

            $this->state->set_cursor( 'views', $visit_id );
        }

        $this->log_batch( 'views', $rows, $from, $tally );

        return count( $rows );
    }

    /**
     * Migrate one batch of searches into `licensio_search_log`.
     *
     * @since 1.0.0
     *
     * @param int $batch How many source rows to read.
     *
     * @return int How many source rows this batch read. Zero means the phase is done.
     */
    public function migrate_searches_batch( $batch ) {
        $rows  = $this->read( 'searches', Licensio_KB_Heroic_Import_Schema::TABLE_SEARCHES, Licensio_KB_Heroic_Import_Schema::KEY_SEARCHES, $batch );
        $tally = $this->tally();
        $from  = $this->state->cursor( 'searches' );

        foreach ( $rows as $row ) {
            $search_id = isset( $row[ Licensio_KB_Heroic_Import_Schema::KEY_SEARCHES ] ) ? (int) $row[ Licensio_KB_Heroic_Import_Schema::KEY_SEARCHES ] : 0;
            $target    = Licensio_KB_Heroic_Import_Map::search_log_row( $row, $this->state->salt(), $this->offset );

            if ( '' === $target['term'] ) {
                $this->report->count( 'searches_empty' );
                ++$tally['skipped'];
            } elseif ( $this->insert( Licensio_KB_Search_Log::TABLE, $target ) ) {
                $this->report->count( 'searches_migrated' );
                ++$tally['migrated'];
            } else {
                $this->fail( 'searches', 'search', $search_id, 0, sprintf( 'Search %d could not be written', $search_id ) );
                ++$tally['failed'];
            }

            $this->state->set_cursor( 'searches', $search_id );
        }

        $this->log_batch( 'searches', $rows, $from, $tally );

        return count( $rows );
    }

    /**
     * Recompute the usefulness score of every article that gained a vote.
     *
     * Deliberately separate from the import loop. The score is derived from a
     * COUNT over the whole table, so recomputing it per inserted row would be
     * quadratic on an article with thousands of votes; once at the end gives the
     * same number for a fraction of the work.
     *
     * @since 1.0.0
     *
     * @param Licensio_KB_Feedback|null $feedback The component that owns the score.
     *
     * @return int How many articles were recomputed.
     */
    public function recompute_scores( $feedback = null ) {
        if ( $this->dry_run ) {
            return 0;
        }

        $feedback = $feedback instanceof Licensio_KB_Feedback ? $feedback : new Licensio_KB_Feedback();
        $done     = 0;

        foreach ( array_keys( $this->voted ) as $article_id ) {
            $feedback->recompute_score( (int) $article_id );

            ++$done;
        }

        $this->report->count( 'scores_recomputed', $done );

        return $done;
    }

    /**
     * The target articles this run recorded votes against.
     *
     * @since 1.0.0
     *
     * @return int[] The target article IDs.
     */
    public function voted_articles() {
        return array_map( 'intval', array_keys( $this->voted ) );
    }

    /**
     * Recompute the usefulness score of every migrated article.
     *
     * The wizard spreads the feedback phase over many requests, so the
     * per-instance `$voted` cache only survives one batch. This walks every
     * article the content phase created — they carry the provenance meta — and
     * recomputes each from the table, which is correct no matter how many
     * batches the votes arrived in.
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
     * Read the next batch for a phase, closing the phase when it runs dry.
     *
     * @since 1.0.0
     *
     * @param string $phase The phase name.
     * @param string $table The Heroic table.
     * @param string $key   The table's primary key.
     * @param int    $batch How many rows to read.
     *
     * @return array<int, array<string, mixed>> The rows.
     */
    private function read( $phase, $table, $key, $batch ) {
        $rows = $this->source->rows( $table, $key, $this->state->cursor( $phase ), $batch );

        if ( empty( $rows ) ) {
            $this->state->complete( $phase );
        }

        return $rows;
    }

    /**
     * Insert a mapped row, unless this is a dry run.
     *
     * @since 1.0.0
     *
     * @param string               $table The unprefixed target table.
     * @param array<string, mixed> $row   The mapped row.
     *
     * @return bool True when the row was counted as migrated.
     */
    private function insert( $table, array $row ) {
        if ( $this->dry_run ) {
            return true;
        }

        return (bool) Licensio_KB_DB::table( $table )->insert( $row );
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
     * @param string                           $phase The phase.
     * @param array<int, array<string, mixed>> $rows  The rows the batch read.
     * @param int                              $from  The cursor before the batch.
     * @param array<string, int>               $tally The batch's counters.
     *
     * @return void
     */
    private function log_batch( $phase, array $rows, $from, array $tally ) {
        if ( empty( $rows ) ) {
            return;
        }

        $this->log->entry(
            $phase,
            'batch',
            0,
            0,
            sprintf( '%1$s rows %2$d to %3$d', ucfirst( $phase ), $from + 1, $this->state->cursor( $phase ) ),
            $tally['failed'] > 0 ? Licensio_KB_Import_Log::STATUS_WARNING : Licensio_KB_Import_Log::STATUS_INFO,
            sprintf( 'Read %1$d, imported %2$d, skipped %3$d, orphaned %4$d, failed %5$d.', count( $rows ), $tally['migrated'], $tally['skipped'], $tally['orphaned'], $tally['failed'] ),
            array_merge( array( 'read' => count( $rows ) ), $tally )
        );
    }

    /**
     * Count and log a row the database refused.
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

    /**
     * Translate a Heroic article ID into a target article ID.
     *
     * In a dry run no target article exists yet, so a vote or view whose Heroic
     * article exists would be migrated and is counted that way: the row's own
     * ID stands in for the target, and the dry run never writes the row it
     * names. Only a source row pointing at an article that does not exist
     * anywhere is orphaned — the same definition a real run uses.
     *
     * @since 1.0.0
     *
     * @param int $heroic_id The Heroic post ID.
     *
     * @return int The target article ID, or 0 when the article was not migrated.
     */
    private function resolve_article( $heroic_id ) {
        $heroic_id = (int) $heroic_id;

        if ( $heroic_id <= 0 ) {
            return 0;
        }

        if ( ! array_key_exists( $heroic_id, $this->resolved ) ) {
            $this->resolved[ $heroic_id ] = $this->content->find_article( $heroic_id );

            if ( $this->dry_run && $this->resolved[ $heroic_id ] <= 0 && $this->source->article_exists( $heroic_id ) ) {
                $this->resolved[ $heroic_id ] = $heroic_id;
            }
        }

        return (int) $this->resolved[ $heroic_id ];
    }
}
