<?php
/**
 * Unit tests for search logging.
 *
 * The decisions taken before a row is written: whether to write one at all,
 * what the term aggregates to, and what the source column may contain. That a
 * row lands in the table with the right columns is asserted in the integration
 * suite, against the real schema.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

/**
 * Tests search log normalisation and the collection switches.
 *
 * @since 1.0.0
 */
class Licensio_KB_Search_Log_Test extends Licensio_KB_Test_Case {

    /**
     * Stub the visitor surface.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        $_SERVER['REMOTE_ADDR']     = '198.51.100.4';
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Licensio Test Runner)';

        Functions\when( 'current_time' )->justReturn( '2026-01-01 00:00:00' );
    }

    /**
     * Terms that differ only in case or spacing aggregate as one.
     *
     * The whole value of the search log is counting how often people asked the
     * same question, and `Install Licence` and `install  licence` are the same
     * question.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_terms_normalize_for_aggregation() {
        $this->assertSame( 'install licence', Licensio_KB_Search_Log::normalize( 'Install  Licence' ) );
        $this->assertSame( 'install licence', Licensio_KB_Search_Log::normalize( "  INSTALL\tlicence " ) );
    }

    /**
     * Only the three known sources are stored.
     *
     * The column has a fixed vocabulary that the analytics reports group by. A
     * caller inventing a fourth would grow a row in every report.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_only_known_sources_are_stored() {
        $this->assertSame( 'site', Licensio_KB_Search_Log::sanitize_source( 'site' ) );
        $this->assertSame( 'live', Licensio_KB_Search_Log::sanitize_source( 'LIVE' ) );
        $this->assertSame( 'ticket', Licensio_KB_Search_Log::sanitize_source( ' ticket ' ) );

        $this->assertSame( 'site', Licensio_KB_Search_Log::sanitize_source( 'wat' ) );
        $this->assertSame( 'site', Licensio_KB_Search_Log::sanitize_source( array( 'live' ) ) );
        $this->assertSame( 'site', Licensio_KB_Search_Log::sanitize_source( null ) );
    }

    /**
     * The specification's three sources are the ones on offer.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_source_vocabulary_matches_the_schema() {
        $this->assertSame( array( 'site', 'live', 'ticket' ), Licensio_KB_Search_Log::SOURCES );
    }

    /**
     * An empty term is never logged.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_empty_term_is_not_logged() {
        $this->assertFalse( $this->log()->record( '', 0, 'live' ) );
    }

    /**
     * Turning analytics off stops the write.
     *
     * The site owner's switch has to reach the database layer, not merely the
     * dashboard, or the promise that collection can be disabled entirely is
     * false.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_disabling_analytics_stops_the_write() {
        $this->options['licensio_settings'] = array( 'enable_analytics' => false );

        $this->assertFalse( $this->log()->record( 'licence', 3, 'live' ) );
    }

    /**
     * The logging filter can suppress an individual search.
     *
     * Driven through `expectApplied()`, because Brain Monkey does not run
     * callbacks added with `add_filter()`: a test that registered one would pass
     * even if the production code never consulted the filter.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_logging_filter_can_suppress_a_search() {
        $this->options['licensio_settings'] = array( 'enable_analytics' => true );

        Filters\expectApplied( 'licensio_log_search' )
            ->once()
            ->with( true, 'licence', 'live' )
            ->andReturn( false );

        $this->assertFalse( $this->log()->record( 'licence', 3, 'live' ) );
    }

    /**
     * The filter sees the setting's value, not a hard-coded true.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_filter_receives_the_setting_value() {
        $this->options['licensio_settings'] = array( 'enable_analytics' => false );

        Filters\expectApplied( 'licensio_log_search' )
            ->once()
            ->with( false, 'licence', 'site' )
            ->andReturn( false );

        $this->log()->record( 'licence', 0, 'site' );
    }

    /**
     * A log writer wired to the in-memory settings store.
     *
     * @since 1.0.0
     *
     * @return Licensio_KB_Search_Log The writer.
     */
    private function log() {
        return new Licensio_KB_Search_Log( new Licensio_KB_Visitor(), new Licensio_KB_Settings() );
    }
}
