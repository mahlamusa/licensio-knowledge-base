<?php
/**
 * Moves Heroic's votes and analytics into the plugin's tables.
 *
 * This is the half of the migration where the privacy promise is at stake.
 * Every source row here carries a `user_ip`; not one target row may. The rule is
 * enforced structurally rather than by care: this class never builds a target
 * row itself. It hands the source row to `Lisensio_KB_Heroic_Map`, which names
 * every target column explicitly, and inserts what comes back. There is no code
 * path from a source column to a target column that does not pass through a
 * named mapping.
 *
 * Writes go through `Lisensio_KB_DB::table()`, the same gateway the owning
 * components use, so the rows are the rows those components would have written.
 *
 * Idempotency is the resume cursor, and only the cursor. Unlike articles and
 * terms, these three tables have no column in which to record the Heroic row an
 * entry came from — adding one would mean altering a schema owned by another
 * component to serve a disposable tool. So a phase re-run without `--reset`
 * starts after the last key it wrote and inserts nothing twice, and `--reset`
 * genuinely will duplicate analytics. That trade is documented on the flag.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Migrates `hkb_voting`, `hkb_visits` and `hkb_analytics_search_atomic`.
 *
 * @since 1.0.0
 */
class Lisensio_KB_Heroic_Data_Migrator {

    /**
     * The batched reader over the Heroic data.
     *
     * @since 1.0.0
     * @var Lisensio_KB_Heroic_Source
     */
    private $source;

    /**
     * The resume point.
     *
     * @since 1.0.0
     * @var Lisensio_KB_Heroic_State
     */
    private $state;

    /**
     * Translates a Heroic article ID into a target article ID.
     *
     * @since 1.0.0
     * @var Lisensio_KB_Heroic_Content_Migrator
     */
    private $content;

    /**
     * Where counts and skipped rows are recorded.
     *
     * @since 1.0.0
     * @var Lisensio_KB_Heroic_Report
     */
    private $report;

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
     * @param Lisensio_KB_Heroic_Source           $source  The batched reader.
     * @param Lisensio_KB_Heroic_State            $state   The resume point.
     * @param Lisensio_KB_Heroic_Content_Migrator $content The article ID translator.
     * @param Lisensio_KB_Heroic_Report           $report  The run report.
     * @param bool                                $dry_run Whether to suppress every write.
     * @param int                                 $offset  Seconds to subtract to reach UTC.
     */
    public function __construct(
        Lisensio_KB_Heroic_Source $source,
        Lisensio_KB_Heroic_State $state,
        Lisensio_KB_Heroic_Content_Migrator $content,
        Lisensio_KB_Heroic_Report $report,
        $dry_run = false,
        $offset = 0
    ) {
        $this->source  = $source;
        $this->state   = $state;
        $this->content = $content;
        $this->report  = $report;
        $this->dry_run = (bool) $dry_run;
        $this->offset  = (int) $offset;
    }

    /**
     * Migrate one batch of votes into `lisensio_feedback`.
     *
     * @since 1.0.0
     *
     * @param int $batch How many source rows to read.
     *
     * @return int How many source rows this batch read. Zero means the phase is done.
     */
    public function migrate_feedback_batch( $batch ) {
        $rows = $this->read( 'feedback', Lisensio_KB_Heroic_Schema::TABLE_VOTING, Lisensio_KB_Heroic_Schema::KEY_VOTING, $batch );

        foreach ( $rows as $row ) {
            $article_id = $this->resolve_article( isset( $row['post_id'] ) ? (int) $row['post_id'] : 0 );

            if ( $article_id <= 0 ) {
                $this->report->count( 'feedback_orphaned' );
            } else {
                $target = Lisensio_KB_Heroic_Map::feedback_row( $row, $article_id, $this->state->salt(), $this->offset );

                if ( $this->insert( Lisensio_KB_Feedback::TABLE, $target ) ) {
                    $this->voted[ $article_id ] = true;

                    $this->report->count( 'feedback_migrated' );
                }
            }

            $this->state->set_cursor( 'feedback', isset( $row[ Lisensio_KB_Heroic_Schema::KEY_VOTING ] ) ? (int) $row[ Lisensio_KB_Heroic_Schema::KEY_VOTING ] : 0 );
        }

        return count( $rows );
    }

    /**
     * Migrate one batch of visits into `lisensio_article_views`.
     *
     * @since 1.0.0
     *
     * @param int $batch How many source rows to read.
     *
     * @return int How many source rows this batch read. Zero means the phase is done.
     */
    public function migrate_views_batch( $batch ) {
        $rows = $this->read( 'views', Lisensio_KB_Heroic_Schema::TABLE_VISITS, Lisensio_KB_Heroic_Schema::KEY_VISITS, $batch );

        foreach ( $rows as $row ) {
            if ( ! Lisensio_KB_Heroic_Map::is_article_visit( $row ) ) {
                $this->report->count( 'views_not_articles' );
            } else {
                $article_id = $this->resolve_article( isset( $row['object_id'] ) ? (int) $row['object_id'] : 0 );

                if ( $article_id <= 0 ) {
                    $this->report->count( 'views_orphaned' );
                } else {
                    $target = Lisensio_KB_Heroic_Map::article_view_row( $row, $article_id, $this->state->salt(), $this->offset );

                    if ( $this->insert( Lisensio_KB_Analytics::TABLE_ARTICLE_VIEWS, $target ) ) {
                        $this->report->count( 'views_migrated' );
                    }
                }
            }

            $this->state->set_cursor( 'views', isset( $row[ Lisensio_KB_Heroic_Schema::KEY_VISITS ] ) ? (int) $row[ Lisensio_KB_Heroic_Schema::KEY_VISITS ] : 0 );
        }

        return count( $rows );
    }

    /**
     * Migrate one batch of searches into `lisensio_search_log`.
     *
     * @since 1.0.0
     *
     * @param int $batch How many source rows to read.
     *
     * @return int How many source rows this batch read. Zero means the phase is done.
     */
    public function migrate_searches_batch( $batch ) {
        $rows = $this->read( 'searches', Lisensio_KB_Heroic_Schema::TABLE_SEARCHES, Lisensio_KB_Heroic_Schema::KEY_SEARCHES, $batch );

        foreach ( $rows as $row ) {
            $target = Lisensio_KB_Heroic_Map::search_log_row( $row, $this->state->salt(), $this->offset );

            if ( '' === $target['term'] ) {
                $this->report->count( 'searches_empty' );
            } elseif ( $this->insert( Lisensio_KB_Search_Log::TABLE, $target ) ) {
                $this->report->count( 'searches_migrated' );
            }

            $this->state->set_cursor( 'searches', isset( $row[ Lisensio_KB_Heroic_Schema::KEY_SEARCHES ] ) ? (int) $row[ Lisensio_KB_Heroic_Schema::KEY_SEARCHES ] : 0 );
        }

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
     * @param Lisensio_KB_Feedback|null $feedback The component that owns the score.
     *
     * @return int How many articles were recomputed.
     */
    public function recompute_scores( $feedback = null ) {
        if ( $this->dry_run ) {
            return 0;
        }

        $feedback = $feedback instanceof Lisensio_KB_Feedback ? $feedback : new Lisensio_KB_Feedback();
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

        return (bool) Lisensio_KB_DB::table( $table )->insert( $row );
    }

    /**
     * Translate a Heroic article ID into a target article ID.
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
        }

        return (int) $this->resolved[ $heroic_id ];
    }
}
