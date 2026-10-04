<?php
/**
 * Import progress, so an interrupted run can be resumed.
 *
 * The wizard runs an import one AJAX batch at a time, so the state has to
 * survive between requests: it is kept in an option per source and reloaded on
 * every batch. It is a high-water mark per phase — the primary key of the last
 * source row that was successfully written. A resumed run asks for rows after
 * that key, so it neither redoes work nor skips it, and a re-run of a finished
 * phase inserts nothing.
 *
 * That high-water mark is also what makes the analytics phases idempotent:
 * `lisensio_search_log`, `lisensio_article_views` and `lisensio_feedback` have
 * no column to record which source row they came from, so the cursor is the
 * only record of it. `restart()` therefore rewinds the content phases — which
 * carry provenance in post and term meta and are idempotent with no state at
 * all — and keeps the analytics cursors, so running an import again picks up
 * only analytics recorded since the last run.
 *
 * A dry run keeps its own state under a separate option, so walking the source
 * to verify it can never move the real import's cursors. The driver deletes the
 * dry-run state when the dry run finishes.
 *
 * The salt lives here too. Imported visitor hashes must be stable across a
 * resumed run — otherwise the same visitor would count as several — so the salt
 * has to survive between requests. It is generated on first use and deleted by
 * `finish()`, because a stored salt plus a stored hash is a recoverable IP
 * address and the whole point of the exercise is that there is not one.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes an importer's resume point.
 *
 * @since 1.0.0
 */
class Lisensio_KB_Import_State {

    /**
     * The prefix of the option a real import's state is kept in.
     *
     * @since 1.0.0
     * @var string
     */
    const OPTION_PREFIX = 'lisensio_import_state_';

    /**
     * The prefix of the option a dry run's state is kept in.
     *
     * @since 1.0.0
     * @var string
     */
    const DRY_RUN_OPTION_PREFIX = 'lisensio_import_dryrun_state_';

    /**
     * The source this state belongs to.
     *
     * @since 1.0.0
     * @var string
     */
    private $source = '';

    /**
     * The phases, in the order they must run.
     *
     * @since 1.0.0
     * @var string[]
     */
    private $phases = array();

    /**
     * Whether writes reach the option.
     *
     * False when the caller wants the state in memory only, which is what the
     * unit tests do.
     *
     * @since 1.0.0
     * @var bool
     */
    private $persist = true;

    /**
     * Whether this is a dry run's state.
     *
     * @since 1.0.0
     * @var bool
     */
    private $dry_run = false;

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
     * @param string   $source  The source driver id, e.g. `heroic`.
     * @param string[] $phases  The phases, in the order they must run.
     * @param bool     $persist Whether to write the state to the option.
     * @param bool     $dry_run Whether this is a dry run's state.
     */
    public function __construct( $source, array $phases, $persist = true, $dry_run = false ) {
        $this->source  = (string) $source;
        $this->phases  = array_values( array_map( 'strval', $phases ) );
        $this->persist = (bool) $persist;
        $this->dry_run = (bool) $dry_run;

        $stored = $this->persist ? get_option( $this->option_name(), array() ) : array();

        $this->state = is_array( $stored ) ? $stored : array();
    }

    /**
     * The option a source's state is kept in.
     *
     * @since 1.0.0
     *
     * @param string $source  The source driver id.
     * @param bool   $dry_run Whether to name the dry run's option.
     *
     * @return string The option name.
     */
    public static function option_for( $source, $dry_run = false ) {
        return ( $dry_run ? self::DRY_RUN_OPTION_PREFIX : self::OPTION_PREFIX ) . sanitize_key( (string) $source );
    }

    /**
     * The option this state is kept in.
     *
     * @since 1.0.0
     *
     * @return string The option name.
     */
    public function option_name() {
        return self::option_for( $this->source, $this->dry_run );
    }

    /**
     * The phases, in order.
     *
     * @since 1.0.0
     *
     * @return string[] The phase names.
     */
    public function phases() {
        return $this->phases;
    }

    /**
     * Whether this is a dry run's state.
     *
     * @since 1.0.0
     *
     * @return bool True for a dry run.
     */
    public function is_dry_run() {
        return $this->dry_run;
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
     * @param string $phase One of the phases.
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
     * @param string $phase One of the phases.
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
     * Whether a phase — or the whole import — has run to the end.
     *
     * @since 1.0.0
     *
     * @phpstan-impure
     *
     * @param string|null $phase One of the phases, or null to ask about the
     *                           whole import.
     *
     * @return bool True when the phase (or every phase) is finished.
     */
    public function is_complete( $phase = null ) {
        if ( null === $phase ) {
            foreach ( $this->phases as $name ) {
                if ( ! $this->is_complete( $name ) ) {
                    return false;
                }
            }

            return true;
        }

        $phase = (string) $phase;

        return ! empty( $this->state[ $phase ]['complete'] );
    }

    /**
     * Mark a phase finished.
     *
     * @since 1.0.0
     *
     * @param string $phase One of the phases.
     *
     * @return void
     */
    public function complete( $phase ) {
        $this->state[ (string) $phase ]['complete'] = true;

        $this->save();
    }

    /**
     * The phase the import is currently in.
     *
     * @since 1.0.0
     *
     * @return string|null One of the phases, or null when every phase is done.
     */
    public function current_phase() {
        foreach ( $this->phases as $phase ) {
            if ( ! $this->is_complete( $phase ) ) {
                return $phase;
            }
        }

        return null;
    }

    /**
     * The salt every imported visitor hash is keyed with.
     *
     * Generated on first use and reused for the rest of the run, so a visitor
     * who searched twice gets one identity across both rows and across a
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
     * The id of the run in progress, generated when the run starts.
     *
     * Every log entry the run writes carries it, so the log can show one run
     * at a time.
     *
     * @since 1.0.0
     *
     * @return string The run id, e.g. `heroic-20261004-101500-1a2b3c`.
     */
    public function run_id() {
        if ( empty( $this->state['run_id'] ) ) {
            $this->state['run_id']     = $this->generate_run_id();
            $this->state['started_at'] = time();

            $this->save();
        }

        return (string) $this->state['run_id'];
    }

    /**
     * Whether the current run has been started and given an id.
     *
     * @since 1.0.0
     *
     * @return bool True once `run_id()` has issued an id for this run.
     */
    public function has_run() {
        return ! empty( $this->state['run_id'] );
    }

    /**
     * The operator's category mapping for this run.
     *
     * @since 1.0.0
     *
     * @return array<int, int> Source category term id to target term id; 0 means create.
     */
    public function category_map() {
        $map = isset( $this->state['category_map'] ) && is_array( $this->state['category_map'] ) ? $this->state['category_map'] : array();
        $out = array();

        foreach ( $map as $source_id => $target_id ) {
            if ( (int) $source_id > 0 && (int) $target_id > 0 ) {
                $out[ (int) $source_id ] = (int) $target_id;
            }
        }

        return $out;
    }

    /**
     * Store the operator's category mapping for this run.
     *
     * @since 1.0.0
     *
     * @param array<int|string, int|string> $map Source category term id to target term id; 0 means create.
     *
     * @return void
     */
    public function set_category_map( array $map ) {
        $clean = array();

        foreach ( $map as $source_id => $target_id ) {
            if ( (int) $source_id > 0 && (int) $target_id > 0 ) {
                $clean[ (int) $source_id ] = (int) $target_id;
            }
        }

        $this->state['category_map'] = $clean;

        $this->save();
    }

    /**
     * Whether the operator asked this run to copy the source's settings.
     *
     * @since 1.0.0
     *
     * @return bool True when settings are to be imported.
     */
    public function import_settings() {
        return ! empty( $this->state['import_settings'] );
    }

    /**
     * Record whether this run copies the source's settings.
     *
     * @since 1.0.0
     *
     * @param bool $import Whether to import settings.
     *
     * @return void
     */
    public function set_import_settings( $import ) {
        $this->state['import_settings'] = (bool) $import;

        $this->save();
    }

    /**
     * Whether an import is part-way through.
     *
     * @since 1.0.0
     *
     * @return bool True when the run has started but has not finished.
     */
    public function is_in_progress() {
        if ( $this->is_finished() ) {
            return false;
        }

        if ( ! empty( $this->state['run_id'] ) ) {
            return true;
        }

        foreach ( $this->phases as $phase ) {
            if ( $this->cursor( $phase ) > 0 || $this->is_complete( $phase ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the last run was closed by `finish()`.
     *
     * @since 1.0.0
     *
     * @return bool True when the last run finished.
     */
    public function is_finished() {
        return ! empty( $this->state['finished_at'] );
    }

    /**
     * When the last run finished.
     *
     * @since 1.0.0
     *
     * @return int A Unix timestamp, 0 when no run has finished.
     */
    public function finished_at() {
        return isset( $this->state['finished_at'] ) ? (int) $this->state['finished_at'] : 0;
    }

    /**
     * Open a new run after a finished one.
     *
     * The phases named in `$rewind` start again from the beginning; every other
     * phase keeps its cursor, so a phase with no provenance of its own imports
     * only the rows added since the last run instead of everything twice.
     *
     * @since 1.0.0
     *
     * @param string[] $rewind The phases to start again from the beginning.
     *
     * @return void
     */
    public function restart( array $rewind ) {
        foreach ( $this->phases as $phase ) {
            if ( ! isset( $this->state[ $phase ] ) || ! is_array( $this->state[ $phase ] ) ) {
                continue;
            }

            unset( $this->state[ $phase ]['complete'] );

            if ( in_array( $phase, $rewind, true ) ) {
                unset( $this->state[ $phase ]['cursor'] );
            }
        }

        unset( $this->state['finished_at'], $this->state['salt'], $this->state['run_id'], $this->state['started_at'], $this->state['category_map'], $this->state['import_settings'] );

        $this->save();
    }

    /**
     * Start some phases from another state's cursors.
     *
     * A dry run uses this to begin its analytics phases where the real import
     * stopped, so it previews what the next real run would add rather than
     * everything the source has ever recorded.
     *
     * @since 1.0.0
     *
     * @param Lisensio_KB_Import_State $from   The state to copy from.
     * @param string[]                 $phases The phases whose cursors to copy.
     *
     * @return void
     */
    public function seed_cursors( Lisensio_KB_Import_State $from, array $phases ) {
        foreach ( $phases as $phase ) {
            $this->set_cursor( $phase, $from->cursor( $phase ) );
        }
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
            delete_option( $this->option_name() );
        }
    }

    /**
     * Close the run and destroy the salt.
     *
     * The cursors are kept, so a later run imports only what is new. The salt
     * is not: holding it alongside the hashes it produced would make every
     * imported hash reversible by brute force over the address space, which
     * would undo in one option what the whole mapping layer is for.
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
     * Persist the state, unless it is held in memory only.
     *
     * @since 1.0.0
     *
     * @return void
     */
    private function save() {
        if ( ! $this->persist ) {
            return;
        }

        update_option( $this->option_name(), $this->state, false );
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

    /**
     * Generate a run id.
     *
     * @since 1.0.0
     *
     * @return string The source, the UTC time and a random suffix.
     */
    private function generate_run_id() {
        try {
            $suffix = bin2hex( random_bytes( 3 ) );
        } catch ( Exception $e ) {
            $suffix = strtolower( wp_generate_password( 6, false, false ) );
        }

        return sanitize_key( $this->source ) . '-' . gmdate( 'Ymd-His' ) . '-' . $suffix;
    }
}
