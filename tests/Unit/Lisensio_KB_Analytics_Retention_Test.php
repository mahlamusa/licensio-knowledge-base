<?php
/**
 * Analytics retention policy.
 *
 * The chunked delete itself is proven against a real database in
 * `tests/Integration`; no double can honestly answer whether a bounded DELETE
 * removes the rows it should. What is proven here is the policy in front of it:
 * how long rows are kept, what a misunderstood `0` means, and that the cron
 * hook this issue owns is actually wired up.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

/**
 * A settings store that answers a raw value without sanitising it.
 *
 * Lisensio_KB_Settings already turns a stored `0` into the 90-day default, so
 * with the real store in place a test of the retention job's own fallback would
 * pass whether or not the job checks anything: two layers, one assertion, and
 * no way to tell which one did the work. This double removes the first layer.
 *
 * @since 1.0.0
 */
class Lisensio_KB_Retention_Test_Settings extends Lisensio_KB_Settings {

    /**
     * The raw retention value this double reports.
     *
     * @since 1.0.0
     * @var mixed
     */
    private $retention;

    /**
     * Build the double.
     *
     * @since 1.0.0
     *
     * @param mixed $retention The raw value to report for `analytics_retention_days`.
     */
    public function __construct( $retention ) {
        $this->retention = $retention;
    }

    /**
     * Report the raw value.
     *
     * @since 1.0.0
     *
     * @param string $key      The setting name.
     * @param mixed  $fallback Returned for every other key.
     *
     * @return mixed The raw value.
     */
    public function get( $key, $fallback = null ) {
        return 'analytics_retention_days' === $key ? $this->retention : $fallback;
    }
}

/**
 * Drives Lisensio_KB_Analytics_Retention.
 *
 * @since 1.0.0
 */
class Lisensio_KB_Analytics_Retention_Test extends Lisensio_KB_Test_Case {

    /**
     * Boot the environment.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        Functions\when( 'current_time' )->alias(
            function ( $format ) {
                return 'mysql' === $format ? '2026-08-30 12:00:00' : 1788134400;
            }
        );
    }

    /**
     * Build the job with a raw retention value.
     *
     * @since 1.0.0
     *
     * @param mixed $retention The raw stored value.
     *
     * @return Lisensio_KB_Analytics_Retention The job.
     */
    private function retention( $retention ) {
        return new Lisensio_KB_Analytics_Retention( new Lisensio_KB_Retention_Test_Settings( $retention ) );
    }

    /**
     * A stored zero keeps the default window rather than collapsing to one day.
     *
     * People type 0 meaning "keep forever". Clamping that up to the registry's
     * one-day minimum would delete almost every row on the next sweep, which is
     * data loss wearing the costume of validation.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_zero_retention_falls_back_to_the_default() {
        $this->assertSame(
            Lisensio_KB_Analytics_Retention::DEFAULT_RETENTION_DAYS,
            $this->retention( 0 )->retention_days()
        );
    }

    /**
     * The default is 90 days, as the specification requires.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_default_retention_is_ninety_days() {
        $this->assertSame( 90, Lisensio_KB_Analytics_Retention::DEFAULT_RETENTION_DAYS );
    }

    /**
     * Nonsense values fall back rather than being used.
     *
     * @since 1.0.0
     *
     * @dataProvider provide_unusable_retentions
     *
     * @param mixed $stored The raw stored value.
     *
     * @return void
     */
    public function test_an_unusable_retention_falls_back_to_the_default( $stored ) {
        $this->assertSame( 90, $this->retention( $stored )->retention_days() );
    }

    /**
     * Values that cannot be a retention window.
     *
     * @since 1.0.0
     *
     * @return array<int, array{0: mixed}> The cases.
     */
    public function provide_unusable_retentions() {
        return array(
            array( 0 ),
            array( -1 ),
            array( '0' ),
            array( '' ),
            array( 'forever' ),
        );
    }

    /**
     * A configured window is used as configured.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_configured_retention_is_honoured() {
        $this->assertSame( 14, $this->retention( 14 )->retention_days() );
    }

    /**
     * The filter cannot talk the job into a zero-day window either.
     *
     * Brain Monkey does not run `add_filter` callbacks on `apply_filters`, so
     * the filtered value is staged rather than hooked.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_filter_cannot_set_a_zero_window() {
        Filters\expectApplied( 'lisensio_analytics_retention_days' )->once()->andReturn( 0 );

        $this->assertSame( 90, $this->retention( 30 )->retention_days() );
    }

    /**
     * The filter can shorten the window.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_filter_can_shorten_the_window() {
        Filters\expectApplied( 'lisensio_analytics_retention_days' )->once()->andReturn( 7 );

        $this->assertSame( 7, $this->retention( 30 )->retention_days() );
    }

    /**
     * The cutoff is exactly the retention window before the given moment.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_cutoff_is_the_window_before_now() {
        $now = strtotime( '2026-08-30 12:00:00 UTC' );

        $this->assertSame(
            gmdate( 'Y-m-d H:i:s', $now - ( 30 * DAY_IN_SECONDS ) ),
            $this->retention( 30 )->cutoff( $now )
        );
    }

    /**
     * A zero-day window would have made the cutoff today; it does not.
     *
     * The same guard as the retention test, asserted where the damage would
     * actually happen — on the timestamp rows are compared against.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_zero_window_does_not_expire_yesterdays_rows() {
        $now    = strtotime( '2026-08-30 12:00:00 UTC' );
        $cutoff = $this->retention( 0 )->cutoff( $now );

        $this->assertLessThan(
            gmdate( 'Y-m-d H:i:s', $now - ( 89 * DAY_IN_SECONDS ) ),
            $cutoff,
            'A stored 0 produced a cutoff inside the default window.'
        );
    }

    /**
     * The cron hook is the one the issue names.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_cron_hook_is_the_documented_one() {
        $this->assertSame( 'lisensio_analytics_cleanup', Lisensio_KB_Analytics_Retention::CRON_HOOK );
    }

    /**
     * The cleanup handler, the scheduler and the teardown are all wired up.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_cron_hooks_are_registered() {
        $retention = $this->retention( 30 );

        $retention->init_hooks();

        $this->assertNotFalse(
            has_action(
                Lisensio_KB_Analytics_Retention::CRON_HOOK,
                array( $retention, 'handle_scheduled_cleanup' )
            )
        );
        $this->assertNotFalse(
            has_action( 'init', array( 'Lisensio_KB_Analytics_Retention', 'schedule' ) )
        );
        $this->assertNotFalse(
            has_action( 'lisensio_deactivated', array( 'Lisensio_KB_Analytics_Retention', 'unschedule' ) )
        );
    }

    /**
     * Scheduling is skipped when the event already exists.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_existing_event_is_not_scheduled_twice() {
        $scheduled = $this->capture_schedule( 1788134400 );

        Lisensio_KB_Analytics_Retention::schedule();

        $this->assertSame( array(), $scheduled->calls, 'A second daily event was scheduled.' );
    }

    /**
     * A missing event is scheduled daily, under the documented hook.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_missing_event_is_scheduled_daily() {
        $scheduled = $this->capture_schedule( false );

        Lisensio_KB_Analytics_Retention::schedule();

        $this->assertCount( 1, $scheduled->calls );
        $this->assertSame( 'daily', $scheduled->calls[0]['recurrence'] );
        $this->assertSame( Lisensio_KB_Analytics_Retention::CRON_HOOK, $scheduled->calls[0]['hook'] );
        $this->assertGreaterThan( time(), $scheduled->calls[0]['timestamp'] );
    }

    /**
     * Unscheduling clears every occurrence, not just the next one.
     *
     * A duplicate left by a half-failed activation survived
     * `wp_unschedule_event()`, which removes one timestamped entry, and kept
     * running for a plugin that had been switched off.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_unscheduling_clears_every_occurrence() {
        $cleared = new ArrayObject( array() );

        Functions\when( 'wp_clear_scheduled_hook' )->alias(
            function ( $hook ) use ( $cleared ) {
                $cleared->append( $hook );

                return 1;
            }
        );

        Lisensio_KB_Analytics_Retention::unschedule();

        $this->assertSame(
            array( Lisensio_KB_Analytics_Retention::CRON_HOOK ),
            $cleared->getArrayCopy()
        );
    }

    /**
     * Record what the scheduler is asked to do.
     *
     * @since 1.0.0
     *
     * @param int|false $existing What `wp_next_scheduled()` reports.
     *
     * @return object A recorder whose `calls` property holds each scheduled event.
     */
    private function capture_schedule( $existing ) {
        $recorder = new class() {

            /**
             * The recorded scheduling calls.
             *
             * @var array<int, array{timestamp: int, recurrence: string, hook: string}>
             */
            public $calls = array();
        };

        Functions\when( 'wp_next_scheduled' )->justReturn( $existing );

        Functions\when( 'wp_schedule_event' )->alias(
            function ( $timestamp, $recurrence, $hook ) use ( $recorder ) {
                $recorder->calls[] = array(
                    'timestamp'  => $timestamp,
                    'recurrence' => $recurrence,
                    'hook'       => $hook,
                );

                return true;
            }
        );

        return $recorder;
    }

    /**
     * The search index is deliberately not pruned.
     *
     * It mirrors published content rather than recording visitor behaviour, so
     * expiring it would silently disable search for older articles.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_only_the_visitor_tables_are_pruned() {
        $this->assertSame(
            array( 'lisensio_search_log', 'lisensio_article_views', 'lisensio_feedback' ),
            Lisensio_KB_Analytics_Retention::tables()
        );
    }
}
