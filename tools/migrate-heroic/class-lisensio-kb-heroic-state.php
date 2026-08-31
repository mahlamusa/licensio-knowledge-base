<?php
/**
 * Migration progress, so an interrupted run can be resumed.
 *
 * A migration of a live knowledge base moves hundreds of thousands of analytics
 * rows, and something will eventually kill the process. The state is a
 * high-water mark per phase: the primary key of the last source row that was
 * successfully written. A resumed run asks for rows after that key, so it
 * neither redoes work nor skips it, and a re-run of a finished phase inserts
 * nothing.
 *
 * That high-water mark is also what makes the three table phases idempotent:
 * `lisensio_search_log`, `lisensio_article_views` and `lisensio_feedback` have
 * no column to record which Heroic row they came from, so the cursor is the
 * only record of it. The two content phases do not depend on this — they carry
 * provenance in post and term meta and would be idempotent with no state at all.
 * `--reset` therefore re-imports analytics but not content, and says so.
 *
 * The salt lives here too. Migrated visitor hashes must be stable across a
 * resumed run — otherwise the same visitor would count as several — so the salt
 * has to survive between processes. It is generated on first use and deleted by
 * `finish()`, because a stored salt plus a stored hash is a recoverable IP
 * address and the whole point of the exercise is that there is not one.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the migrator's resume point.
 *
 * @since 1.0.0
 */
class Lisensio_KB_Heroic_State {

    /**
     * The option the state is kept in.
     *
     * @since 1.0.0
     * @var string
     */
    const OPTION_NAME = 'lisensio_heroic_migration_state';

    /**
     * The phases, in the order they must run.
     *
     * Terms before articles, because an article's categories have to exist
     * before it can be filed under them. Articles before every data phase,
     * because a vote or a view names an article.
     *
     * @since 1.0.0
     * @var string[]
     */
    const PHASES = array( 'terms', 'articles', 'feedback', 'views', 'searches' );

    /**
     * Whether writes reach the option.
     *
     * False under `--dry-run`, which is what makes the dry run leave nothing
     * behind: the cursors move in memory so the reported counts are the counts a
     * real run would produce, and none of it is persisted.
     *
     * @since 1.0.0
     * @var bool
     */
    private $persist = true;

    /**
     * The in-memory copy of the state.
     *
     * @since 1.0.0
     * @var array<string, mixed>
     */
    private $state = array();

    /**
     * Constructor.
     *
     * @since 1.0.0
     *
     * @param bool $persist Whether to write the state to the option.
     */
    public function __construct( $persist = true ) {
        $this->persist = (bool) $persist;

        $stored = get_option( self::OPTION_NAME, array() );

        $this->state = is_array( $stored ) ? $stored : array();
    }

    /**
     * The whole state.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed> The stored state.
     */
    public function all() {
        return $this->state;
    }

    /**
     * The primary key of the last source row a phase wrote.
     *
     * @since 1.0.0
     *
     * @param string $phase One of self::PHASES.
     *
     * @return int The high-water mark, 0 when the phase has not started.
     */
    public function cursor( $phase ) {
        $phase = (string) $phase;

        return isset( $this->state[ $phase ]['cursor'] ) ? (int) $this->state[ $phase ]['cursor'] : 0;
    }

    /**
     * Record how far a phase has got.
     *
     * Never moves backwards. A caller that processed an out-of-order batch —
     * or a resumed run whose first batch came back empty — must not be able to
     * lower the mark and cause the next run to re-import rows that are already
     * in the target.
     *
     * @since 1.0.0
     *
     * @param string $phase One of self::PHASES.
     * @param int    $id    The primary key of the last row written.
     *
     * @return void
     */
    public function set_cursor( $phase, $id ) {
        $phase = (string) $phase;
        $id    = (int) $id;

        if ( $id <= $this->cursor( $phase ) ) {
            return;
        }

        $this->state[ $phase ]['cursor'] = $id;

        $this->save();
    }

    /**
     * Whether a phase has run to the end of its source.
     *
     * @since 1.0.0
     *
     * @param string $phase One of self::PHASES.
     *
     * @return bool True when the phase is finished.
     */
    public function is_complete( $phase ) {
        $phase = (string) $phase;

        return ! empty( $this->state[ $phase ]['complete'] );
    }

    /**
     * Mark a phase finished.
     *
     * @since 1.0.0
     *
     * @param string $phase One of self::PHASES.
     *
     * @return void
     */
    public function complete( $phase ) {
        $this->state[ (string) $phase ]['complete'] = true;

        $this->save();
    }

    /**
     * The salt every migrated visitor hash is keyed with.
     *
     * Generated on first use and reused for the rest of the migration, so a
     * visitor who searched twice gets one identity across both rows and across a
     * resumed run.
     *
     * @since 1.0.0
     *
     * @return string A 64-character random string.
     */
    public function salt() {
        if ( empty( $this->state['salt'] ) ) {
            $this->state['salt'] = $this->generate_salt();

            $this->save();
        }

        return (string) $this->state['salt'];
    }

    /**
     * Whether a migration is part-way through.
     *
     * @since 1.0.0
     *
     * @return bool True when any phase has started but the migration has not finished.
     */
    public function is_in_progress() {
        if ( ! empty( $this->state['finished_at'] ) ) {
            return false;
        }

        foreach ( self::PHASES as $phase ) {
            if ( $this->cursor( $phase ) > 0 || $this->is_complete( $phase ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Forget everything and start the next run from scratch.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function reset() {
        $this->state = array();

        if ( $this->persist ) {
            delete_option( self::OPTION_NAME );
        }
    }

    /**
     * Close the migration and destroy the salt.
     *
     * The cursors are kept, so a later accidental re-run still imports nothing.
     * The salt is not: holding it alongside the hashes it produced would make
     * every migrated hash reversible by brute force over the address space,
     * which would undo in one option what the whole mapping layer is for.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function finish() {
        unset( $this->state['salt'] );

        $this->state['finished_at'] = time();

        $this->save();
    }

    /**
     * Persist the state, unless this is a dry run.
     *
     * @since 1.0.0
     *
     * @return void
     */
    private function save() {
        if ( ! $this->persist ) {
            return;
        }

        update_option( self::OPTION_NAME, $this->state, false );
    }

    /**
     * Generate a salt.
     *
     * @since 1.0.0
     *
     * @return string A 64-character random string.
     */
    private function generate_salt() {
        try {
            return bin2hex( random_bytes( 32 ) );
        } catch ( Exception $e ) {
            return wp_generate_password( 64, true, true );
        }
    }
}
