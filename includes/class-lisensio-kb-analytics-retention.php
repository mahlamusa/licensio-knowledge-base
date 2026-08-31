<?php
/**
 * Analytics retention.
 *
 * Owns the `lisensio_analytics_cleanup` cron hook and the chunked delete behind
 * it. The retention window is the second half of the plugin's privacy posture:
 * the visitor salt rotates daily so old rows cannot be re-identified, and this
 * job makes sure old rows stop existing at all.
 *
 * The hook belongs to this issue rather than to the scaffold because it has
 * exactly one consumer. The salt-rotation hook is the scaffold's; this one is
 * not.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Deletes analytics rows older than the retention window.
 *
 * @since 1.0.0
 */
class Lisensio_KB_Analytics_Retention {

    /**
     * The daily cleanup cron hook.
     *
     * @since 1.0.0
     * @var string
     */
    const CRON_HOOK = 'lisensio_analytics_cleanup';

    /**
     * Rows deleted per statement.
     *
     * A single unbounded `DELETE ... WHERE created_at < ?` over a table with
     * months of rows holds one transaction and one set of locks for as long as
     * it takes, which on a busy site is how a cron job becomes an outage. Small
     * bounded statements let other writers through between chunks.
     *
     * @since 1.0.0
     * @var int
     */
    const CHUNK_SIZE = 500;

    /**
     * Seconds the whole job may spend deleting before it stops for this run.
     *
     * Whatever is left is deleted on the next run. Retention is a window, not a
     * deadline measured in seconds, so finishing late is fine and running past
     * the request's own limit is not.
     *
     * @since 1.0.0
     * @var int
     */
    const TIME_BUDGET = 20;

    /**
     * The retention period used when the setting cannot supply one.
     *
     * @since 1.0.0
     * @var int
     */
    const DEFAULT_RETENTION_DAYS = 90;

    /**
     * The settings store.
     *
     * @since 1.0.0
     * @var Lisensio_KB_Settings
     */
    private $settings;

    /**
     * Build the retention job.
     *
     * @since 1.0.0
     *
     * @param Lisensio_KB_Settings $settings The settings store.
     */
    public function __construct( Lisensio_KB_Settings $settings ) {
        $this->settings = $settings;
    }

    /**
     * Register the WordPress hooks.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function init_hooks() {
        add_action( self::CRON_HOOK, array( $this, 'handle_scheduled_cleanup' ) );
        add_action( 'init', array( __CLASS__, 'schedule' ) );
        add_action( 'lisensio_deactivated', array( __CLASS__, 'unschedule' ) );
    }

    /**
     * The unprefixed names of the tables this job prunes.
     *
     * The search index is not one of them: it mirrors published content rather
     * than recording visitor behaviour, so it has no retention window.
     *
     * @since 1.0.0
     *
     * @return string[] The unprefixed table names.
     */
    public static function tables() {
        return array(
            Lisensio_KB_Analytics::TABLE_SEARCH_LOG,
            Lisensio_KB_Analytics::TABLE_ARTICLE_VIEWS,
            Lisensio_KB_Analytics::TABLE_FEEDBACK,
        );
    }

    /**
     * Ensure the daily cleanup event is scheduled.
     *
     * Static, and registered from the plugin bootstrap as well as from `init`,
     * because Lisensio_KB::boot() never runs during an activation request:
     * WordPress includes the plugin file after `plugins_loaded` has already
     * fired, then fires the activation hook. The `init` registration is the
     * repair path for a site that lost the event some other way.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public static function schedule() {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
        }
    }

    /**
     * Remove the daily cleanup event.
     *
     * Clears every occurrence rather than the next one, so a duplicate left by
     * a half-failed activation does not survive deactivation.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public static function unschedule() {
        wp_clear_scheduled_hook( self::CRON_HOOK );
    }

    /**
     * The configured retention period, in days.
     *
     * A stored `0` returns the default rather than one day. People type 0
     * meaning "keep forever", and turning that into a one-day window would
     * silently destroy the history it was meant to protect. Unbounded retention
     * is deliberately not offered — a bounded window is part of the privacy
     * posture — but the failure mode of a misunderstood 0 must be "keep the
     * default", never "delete almost everything".
     *
     * @since 1.0.0
     *
     * @return int The retention period in days, always at least 1.
     */
    public function retention_days() {
        /**
         * Filter the analytics retention period, in days.
         *
         * Receives the configured value as stored, including a zero, so a
         * filter can tell "not configured" from "configured as 90". A returned
         * value of zero or less is ignored and the default is used, for the
         * same reason the setting treats it that way.
         *
         * @since 1.0.0
         *
         * @param int $days The configured retention period in days.
         */
        $days = (int) apply_filters(
            'lisensio_analytics_retention_days',
            (int) $this->settings->get( 'analytics_retention_days', self::DEFAULT_RETENTION_DAYS )
        );

        /*
         * One guard, deliberately. An earlier revision also normalised the
         * value before handing it to the filter, which meant no single change
         * to either check could be observed: whichever one was broken, the
         * other still returned 90 and the test stayed green. Two layers
         * enforcing one rule is two places for the rule to rot unnoticed.
         */
        return $days > 0 ? $days : self::DEFAULT_RETENTION_DAYS;
    }

    /**
     * The timestamp before which rows are expired.
     *
     * @since 1.0.0
     *
     * @param int|null $now Unix timestamp to measure from. Defaults to the current site time.
     *
     * @return string A `Y-m-d H:i:s` timestamp in site time.
     */
    public function cutoff( $now = null ) {
        $now = ( null === $now ) ? (int) current_time( 'timestamp' ) : (int) $now; // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- The cutoff is compared against columns written with current_time( 'mysql' ).

        return gmdate( 'Y-m-d H:i:s', $now - ( $this->retention_days() * DAY_IN_SECONDS ) );
    }

    /**
     * Cron handler: run the sweep and discard the report.
     *
     * run() returns what it deleted, which is what makes it testable; an action
     * callback must return nothing.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function handle_scheduled_cleanup() {
        $this->run();
    }

    /**
     * Delete every expired row, in bounded chunks.
     *
     * @since 1.0.0
     *
     * @return array<string, int> Unprefixed table name to the number of rows deleted.
     */
    public function run() {
        $cutoff  = $this->cutoff();
        $deleted = array();
        $started = microtime( true );

        foreach ( self::tables() as $table ) {
            $deleted[ $table ] = $this->purge_table( $table, $cutoff, $started );
        }

        /**
         * Fires after a retention sweep has finished.
         *
         * @since 1.0.0
         *
         * @param array<string, int> $deleted Unprefixed table name to rows deleted.
         * @param string             $cutoff  The cutoff timestamp rows were compared against.
         */
        do_action( 'lisensio_analytics_cleanup_complete', $deleted, $cutoff );

        return $deleted;
    }

    /**
     * Delete expired rows from one table.
     *
     * Stops when a chunk comes back short — which means the last expired row is
     * gone — or when the time budget is spent.
     *
     * @since 1.0.0
     *
     * @param string $table   The unprefixed table name.
     * @param string $cutoff  Rows created before this timestamp are expired.
     * @param float  $started The `microtime( true )` the sweep began at.
     *
     * @return int How many rows were deleted.
     */
    private function purge_table( $table, $cutoff, $started ) {
        $deleted = 0;

        while ( ( microtime( true ) - $started ) < self::TIME_BUDGET ) {
            $rows = $this->delete_chunk( $table, $cutoff );

            if ( $rows <= 0 ) {
                break;
            }

            $deleted += $rows;

            if ( $rows < self::CHUNK_SIZE ) {
                break;
            }
        }

        return $deleted;
    }

    /**
     * Delete one chunk of expired rows.
     *
     * @since 1.0.0
     *
     * @param string $table  The unprefixed table name.
     * @param string $cutoff Rows created before this timestamp are expired.
     *
     * @return int How many rows were deleted, or 0 when the statement failed.
     */
    private function delete_chunk( $table, $cutoff ) {
        try {
            $builder = self::builder( $table );

            $rows = $builder
                ->where( 'created_at', $cutoff, '<' )
                ->limit( self::CHUNK_SIZE )
                ->delete();
        } catch ( Exception $e ) {
            unset( $e );

            return 0;
        }

        return is_numeric( $rows ) ? max( 0, (int) $rows ) : 0;
    }

    /**
     * A query builder for one of the plugin tables.
     *
     * Wrapped so the fluent calls that follow are typed as `mixed` rather than
     * as the gateway's declared `object`, which static analysis cannot call
     * methods on.
     *
     * @since 1.0.0
     *
     * @param string $table The unprefixed table name.
     *
     * @return mixed The StellarWP query builder.
     */
    private static function builder( $table ) {
        return Lisensio_KB_DB::table( $table );
    }
}
