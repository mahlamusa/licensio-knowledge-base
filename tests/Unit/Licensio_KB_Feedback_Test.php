<?php
/**
 * Unit tests for the feedback store.
 *
 * The arithmetic, the input sanitisation and the exact shape of the row that
 * is written. The rules that need a real server to prove — one vote per
 * visitor, and the cached score agreeing with a recount — are proved in
 * tests/Integration/Licensio_KB_Feedback_Integration_Test.php against a live
 * database, because a $wpdb double can only answer what it was told to.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

use Brain\Monkey\Functions;

/**
 * A $wpdb that records writes and reports an empty table.
 *
 * It answers no reads, on purpose: nothing in this file asserts a read result,
 * so the double cannot certify behaviour it does not implement. Its whole job
 * is to capture the array `record_vote()` hands to `$wpdb->insert()`.
 *
 * @since 1.0.0
 */
class Licensio_KB_Feedback_Recording_WPDB {

    /**
     * The table prefix.
     *
     * @since 1.0.0
     * @var string
     */
    public $prefix = 'wptests_';

    /**
     * The users table name, read by the query builder's prefixer.
     *
     * @since 1.0.0
     * @var string
     */
    public $users = 'wptests_users';

    /**
     * The user meta table name, read by the query builder's prefixer.
     *
     * @since 1.0.0
     * @var string
     */
    public $usermeta = 'wptests_usermeta';

    /**
     * The last error, always empty.
     *
     * @since 1.0.0
     * @var string
     */
    public $last_error = '';

    /**
     * The last statement seen.
     *
     * @since 1.0.0
     * @var string
     */
    public $last_query = '';

    /**
     * Every insert the code under test attempted.
     *
     * @since 1.0.0
     * @var array<int, array<string, mixed>>
     */
    public $inserts = array();

    /**
     * Record an insert.
     *
     * @since 1.0.0
     *
     * @param string               $table  The table written to.
     * @param array<string, mixed> $data   The row.
     * @param array<int, string>   $format The column formats.
     *
     * @return int Rows affected.
     */
    public function insert( $table, $data, $format = null ) {
        $this->inserts[] = array(
            'table'  => $table,
            'data'   => $data,
            'format' => $format,
        );

        return 1;
    }

    /**
     * Report an empty table.
     *
     * @since 1.0.0
     *
     * @param string $query The statement.
     *
     * @return null Always null: the table is empty.
     */
    public function get_row( $query = '' ) {
        $this->last_query = (string) $query;

        return null;
    }

    /**
     * Report an empty result set.
     *
     * @since 1.0.0
     *
     * @param string $query The statement.
     *
     * @return array<int, object> Always empty.
     */
    public function get_results( $query = '' ) {
        $this->last_query = (string) $query;

        return array();
    }

    /**
     * Record a statement.
     *
     * @since 1.0.0
     *
     * @param string $query The statement.
     *
     * @return int Rows affected.
     */
    public function query( $query ) {
        $this->last_query = (string) $query;

        return 1;
    }

    /**
     * Interpolate a statement well enough to inspect it.
     *
     * @since 1.0.0
     *
     * @param string $query   The statement.
     * @param mixed  ...$args The values.
     *
     * @return string The interpolated statement.
     */
    public function prepare( $query, ...$args ) {
        foreach ( $args as $arg ) {
            $query = preg_replace( '/%[0-9]*[sdfF]/', (string) $arg, (string) $query, 1 );
        }

        return (string) $query;
    }

    /**
     * Stop printing errors.
     *
     * @since 1.0.0
     *
     * @return bool Whether errors were being shown.
     */
    public function hide_errors() {
        return false;
    }

    /**
     * Start printing errors.
     *
     * @since 1.0.0
     *
     * @return bool Whether errors were hidden.
     */
    public function show_errors() {
        return true;
    }

    /**
     * Toggle error suppression.
     *
     * @since 1.0.0
     *
     * @param bool $suppress Whether to suppress.
     *
     * @return bool The previous setting.
     */
    public function suppress_errors( $suppress = true ) {
        unset( $suppress );

        return false;
    }
}

/**
 * Tests Licensio_KB_Feedback.
 *
 * @since 1.0.0
 */
class Licensio_KB_Feedback_Test extends Licensio_KB_Test_Case {

    /**
     * The recording $wpdb.
     *
     * @since 1.0.0
     * @var Licensio_KB_Feedback_Recording_WPDB
     */
    private $wpdb;

    /**
     * Stub the WordPress surface the store touches.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        $_SERVER['REMOTE_ADDR']     = '198.51.100.4';
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Licensio Test Runner)';

        $this->wpdb             = new Licensio_KB_Feedback_Recording_WPDB();
        $GLOBALS['wpdb']        = $this->wpdb; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
        $GLOBALS['EZSQL_ERROR'] = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

        Functions\when( 'sanitize_textarea_field' )->alias(
            function ( $value ) {
                return is_string( $value ) ? trim( strip_tags( $value ) ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
            }
        );

        Functions\when( 'current_time' )->justReturn( '2026-02-01 10:00:00' );

        Licensio_KB_DB::init();
    }

    /**
     * Drop the globals again.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function tearDown(): void {
        unset( $GLOBALS['wpdb'], $GLOBALS['EZSQL_ERROR'], $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT'] );

        parent::tearDown();
    }

    /**
     * Skip a test that needs the vendored query builder when it is absent.
     *
     * @since 1.0.0
     *
     * @return void
     */
    private function require_query_builder() {
        if ( ! Licensio_KB_DB::is_available() ) {
            $this->markTestSkipped( 'The vendored query builder is unavailable.' );
        }
    }

    /**
     * The percentage is the share of helpful votes, rounded.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_score_is_the_share_of_helpful_votes() {
        $this->assertSame( 100, Licensio_KB_Feedback::calculate_score( 3, 0 ) );
        $this->assertSame( 0, Licensio_KB_Feedback::calculate_score( 0, 3 ) );
        $this->assertSame( 50, Licensio_KB_Feedback::calculate_score( 2, 2 ) );
        $this->assertSame( 67, Licensio_KB_Feedback::calculate_score( 2, 1 ) );
        $this->assertSame( 83, Licensio_KB_Feedback::calculate_score( 10, 2 ) );
    }

    /**
     * An article nobody voted on has no score, not a score of zero.
     *
     * Zero per cent and "no data" are different claims, and the list table and
     * the article footer both have to be able to tell them apart.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_unvoted_article_has_a_null_score() {
        $this->assertNull( Licensio_KB_Feedback::calculate_score( 0, 0 ) );
    }

    /**
     * Only 1 and -1 are votes.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_only_one_and_minus_one_are_votes() {
        $this->assertSame( 1, Licensio_KB_Feedback::normalize_vote( 1 ) );
        $this->assertSame( 1, Licensio_KB_Feedback::normalize_vote( '1' ) );
        $this->assertSame( 1, Licensio_KB_Feedback::normalize_vote( 'up' ) );
        $this->assertSame( -1, Licensio_KB_Feedback::normalize_vote( -1 ) );
        $this->assertSame( -1, Licensio_KB_Feedback::normalize_vote( 'down' ) );

        $this->assertSame( 0, Licensio_KB_Feedback::normalize_vote( 0 ) );
        $this->assertSame( 0, Licensio_KB_Feedback::normalize_vote( 5 ) );
        $this->assertSame( 0, Licensio_KB_Feedback::normalize_vote( -2 ) );
        $this->assertSame( 0, Licensio_KB_Feedback::normalize_vote( 'maybe' ) );
        $this->assertSame( 0, Licensio_KB_Feedback::normalize_vote( array( 1 ) ) );
        $this->assertSame( 0, Licensio_KB_Feedback::normalize_vote( null ) );
    }

    /**
     * A comment carrying markup keeps its words and loses its tags.
     *
     * The stored value is what every consumer renders, so nothing that could be
     * parsed as HTML may survive this call.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_comment_carrying_markup_is_stripped_to_text() {
        $clean = Licensio_KB_Feedback::sanitize_comment( '<script>alert(1)</script>' );

        $this->assertSame( 'alert(1)', $clean );
        $this->assertStringNotContainsString( '<', $clean );

        $clean = Licensio_KB_Feedback::sanitize_comment( 'Broken <b>step 3</b> <img src=x onerror=alert(1)>' );

        $this->assertSame( 'Broken step 3', $clean );
        $this->assertStringNotContainsString( 'onerror', $clean );
        $this->assertStringNotContainsString( '<', $clean );
    }

    /**
     * A comment is capped, and a non-string is not a comment.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_comment_is_capped_and_must_be_a_string() {
        $long = str_repeat( 'a', Licensio_KB_Feedback::COMMENT_MAX_LENGTH + 500 );

        $this->assertSame(
            Licensio_KB_Feedback::COMMENT_MAX_LENGTH,
            strlen( Licensio_KB_Feedback::sanitize_comment( $long ) )
        );

        $this->assertSame( '', Licensio_KB_Feedback::sanitize_comment( array( 'nope' ) ) );
        $this->assertSame( '', Licensio_KB_Feedback::sanitize_comment( null ) );
    }

    /**
     * Only the three known statuses are statuses.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_only_the_three_known_statuses_are_valid() {
        $this->assertTrue( Licensio_KB_Feedback::is_valid_status( 'new' ) );
        $this->assertTrue( Licensio_KB_Feedback::is_valid_status( 'reviewed' ) );
        $this->assertTrue( Licensio_KB_Feedback::is_valid_status( 'actioned' ) );

        $this->assertFalse( Licensio_KB_Feedback::is_valid_status( 'spam' ) );
        $this->assertFalse( Licensio_KB_Feedback::is_valid_status( '' ) );
        $this->assertFalse( Licensio_KB_Feedback::is_valid_status( 1 ) );
    }

    /**
     * The written row carries no IP address, in any column.
     *
     * The privacy guarantee is structural: the schema has no IP column and the
     * row assembled here never contains one. This asserts the row that actually
     * reaches `$wpdb->insert()`, not the schema, so adding an IP anywhere in
     * `record_vote()` fails here even though the write itself would succeed.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_written_row_contains_no_ip_address() {
        $this->require_query_builder();

        $feedback = new Licensio_KB_Feedback( new Licensio_KB_Settings() );
        $visitor  = new Licensio_KB_Visitor();

        $this->assertTrue(
            $feedback->record_vote( 42, Licensio_KB_Feedback::VOTE_DOWN, 'Step 3 is wrong', $visitor->get_hash(), 0 )
        );

        $this->assertCount( 1, $this->wpdb->inserts );

        $row = $this->wpdb->inserts[0]['data'];

        $this->assertSame(
            array( 'article_id', 'vote', 'comment', 'visitor_hash', 'user_id', 'status', 'created_at' ),
            array_keys( $row )
        );

        foreach ( $row as $column => $value ) {
            $this->assertStringNotContainsStringIgnoringCase( 'ip', (string) $column, 'No column may name an IP address.' );
            $this->assertNotSame( '198.51.100.4', (string) $value, 'No value may be the request IP address.' );
        }

        $this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', (string) $row['visitor_hash'] );
    }

    /**
     * The vote is written with the values it was given.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_written_row_carries_the_submitted_values() {
        $this->require_query_builder();

        $feedback = new Licensio_KB_Feedback( new Licensio_KB_Settings() );

        $feedback->record_vote( 7, Licensio_KB_Feedback::VOTE_UP, 'Clear enough', str_repeat( 'a', 64 ), 12 );

        $row = $this->wpdb->inserts[0]['data'];

        $this->assertSame( 7, $row['article_id'] );
        $this->assertSame( 1, $row['vote'] );
        $this->assertSame( 'Clear enough', $row['comment'] );
        $this->assertSame( 12, $row['user_id'] );
        $this->assertSame( 'new', $row['status'] );
        $this->assertSame( '2026-02-01 10:00:00', $row['created_at'] );
        $this->assertStringContainsString( 'wptests_licensio_feedback', (string) $this->wpdb->inserts[0]['table'] );
    }

    /**
     * The stub table gives up the two routes this component implements.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_reserved_feedback_routes_are_released() {
        $feedback = new Licensio_KB_Feedback( new Licensio_KB_Settings() );
        $stubs    = new Licensio_KB_REST_Stubs_Controller();
        $routes   = array();

        foreach ( array_keys( $this->reserved_route_table( $stubs ) ) as $route ) {
            $routes[ $route ] = array(
                'methods'    => 'GET',
                'permission' => 'public_permissions_check',
            );
        }

        $released = $feedback->release_stub_routes( $routes );

        $this->assertArrayNotHasKey( '/feedback', $released );
        $this->assertArrayNotHasKey( '/feedback/(?P<id>[\d]+)', $released );

        // Everything else is left for the issue that owns it.
        $this->assertArrayHasKey( '/views', $released );
        $this->assertArrayHasKey( '/analytics/feedback', $released );
        $this->assertArrayHasKey( '/settings', $released );
    }

    /**
     * The REST registrar is told about the feedback controller.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_feedback_controller_is_announced_to_the_registrar() {
        $feedback = new Licensio_KB_Feedback( new Licensio_KB_Settings() );

        $this->assertSame(
            array( 'Licensio_KB_Search_Controller', 'Licensio_KB_Feedback_REST_Controller' ),
            $feedback->register_rest_controller( array( 'Licensio_KB_Search_Controller' ) )
        );

        $this->assertSame(
            array( 'Licensio_KB_Feedback_REST_Controller' ),
            $feedback->register_rest_controller( array() )
        );
    }

    /**
     * Feedback follows the `enable_feedback` setting.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_feedback_follows_the_enable_feedback_setting() {
        $settings = new Licensio_KB_Settings();
        $feedback = new Licensio_KB_Feedback( $settings );

        $this->assertTrue( $feedback->is_enabled() );

        $settings->set( 'enable_feedback', false );

        $this->assertFalse( $feedback->is_enabled() );
    }

    /**
     * The reserved routes the scaffold declares, unfiltered.
     *
     * @since 1.0.0
     *
     * @param Licensio_KB_REST_Stubs_Controller $stubs The stub controller.
     *
     * @return array<string, array<string, string>> The reserved routes.
     */
    private function reserved_route_table( $stubs ) {
        Brain\Monkey\Functions\when( 'apply_filters' )->returnArg( 2 );

        return $stubs->reserved_routes();
    }
}
