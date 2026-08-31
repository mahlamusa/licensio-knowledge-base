<?php
/**
 * Search and view capture.
 *
 * The privacy promise is the product here, so most of these tests exist to
 * prove a negative: that nothing resembling an IP address reaches a row, and
 * that turning collection off really does stop every write rather than merely
 * hiding the reports.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

/**
 * A settings store that answers whatever a test hands it.
 *
 * Lisensio_KB_Settings sanitises on read, which is the two-layer trap: with the
 * real store in place, a test for the analytics gate would pass whether or not
 * Lisensio_KB_Analytics checks anything, because the setting can only ever
 * arrive already correct. This double reports the raw value so the layer under
 * test is the only thing deciding.
 *
 * @since 1.0.0
 */
class Lisensio_KB_Analytics_Test_Settings extends Lisensio_KB_Settings {

    /**
     * The values this double reports.
     *
     * @since 1.0.0
     * @var array<string, mixed>
     */
    private $values;

    /**
     * Build the double.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $values Setting name to raw value.
     */
    public function __construct( array $values = array() ) {
        $this->values = $values;
    }

    /**
     * Report a raw value, with no sanitization at all.
     *
     * @since 1.0.0
     *
     * @param string $key      The setting name.
     * @param mixed  $fallback Returned when the test did not set the key.
     *
     * @return mixed The raw value.
     */
    public function get( $key, $fallback = null ) {
        return array_key_exists( $key, $this->values ) ? $this->values[ $key ] : $fallback;
    }
}

/**
 * Drives Lisensio_KB_Analytics.
 *
 * @since 1.0.0
 */
class Lisensio_KB_Analytics_Test extends Lisensio_KB_Test_Case {

    /**
     * The IP the fake request arrives from.
     *
     * @since 1.0.0
     * @var string
     */
    const REQUEST_IP = '203.0.113.47';

    /**
     * The user agent the fake request arrives with.
     *
     * @since 1.0.0
     * @var string
     */
    const REQUEST_AGENT = 'Mozilla/5.0 (Lisensio Test)';

    /**
     * The recording $wpdb double.
     *
     * @since 1.0.0
     * @var Lisensio_KB_Test_WPDB
     */
    private $wpdb;

    /**
     * Boot the request environment.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        $_SERVER['REMOTE_ADDR']     = self::REQUEST_IP;
        $_SERVER['HTTP_USER_AGENT'] = self::REQUEST_AGENT;

        $this->wpdb      = new Lisensio_KB_Test_WPDB();
        $GLOBALS['wpdb'] = $this->wpdb; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

        Functions\when( 'current_time' )->alias(
            function ( $format ) {
                return 'mysql' === $format ? '2026-08-30 12:00:00' : time();
            }
        );

        Functions\when( 'number_format_i18n' )->alias(
            function ( $number ) {
                return (string) $number;
            }
        );

        Functions\when( 'is_search' )->justReturn( false );
        Functions\when( 'get_search_query' )->justReturn( '' );
    }

    /**
     * Clear the fake request.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function tearDown(): void {
        unset( $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT'], $GLOBALS['wpdb'] );

        parent::tearDown();
    }

    /**
     * Build the class under test.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $settings Raw setting values.
     *
     * @return Lisensio_KB_Analytics The recorder.
     */
    private function analytics( array $settings = array() ) {
        return new Lisensio_KB_Analytics(
            new Lisensio_KB_Analytics_Test_Settings( $settings + array( 'enable_analytics' => true ) ),
            new Lisensio_KB_Visitor()
        );
    }

    /**
     * A view is written with the visitor hash and nothing else identifying.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_article_view_is_recorded_under_the_visitor_hash() {
        $analytics = $this->analytics();
        $visitor   = new Lisensio_KB_Visitor();

        $this->assertTrue( $analytics->record_article_view( 42 ) );

        $rows = $this->wpdb->inserted_rows( 'lisensio_article_views' );

        $this->assertCount( 1, $rows );
        $this->assertSame( 42, $rows[0]['article_id'] );
        $this->assertSame( $visitor->get_hash(), $rows[0]['visitor_hash'] );
        $this->assertSame( '2026-08-30 12:00:00', $rows[0]['created_at'] );
    }

    /**
     * No column of any written row contains the request IP address.
     *
     * The whole differentiator in one assertion. It walks every value of every
     * row the code tried to insert, so a column added later is covered without
     * anybody remembering to extend this test.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_no_written_row_contains_an_ip_address() {
        $analytics = $this->analytics();

        $analytics->record_article_view( 42 );
        $analytics->record_search_event( 'reset my password', 3 );

        $this->assertNotEmpty( $this->wpdb->inserts, 'Nothing was written, so nothing was proven.' );

        foreach ( $this->wpdb->inserts as $insert ) {
            foreach ( $insert['data'] as $column => $value ) {
                $this->assertStringNotContainsString(
                    self::REQUEST_IP,
                    (string) $value,
                    sprintf( 'Column %1$s of %2$s carries the request IP.', $column, $insert['table'] )
                );

                $this->assertStringNotContainsString(
                    self::REQUEST_AGENT,
                    (string) $value,
                    sprintf( 'Column %1$s of %2$s carries the raw user agent.', $column, $insert['table'] )
                );
            }

            $this->assertSame(
                array(),
                array_intersect(
                    array_keys( $insert['data'] ),
                    array( 'ip', 'user_ip', 'ip_address', 'remote_addr', 'visitor_ip' )
                ),
                'A row was written with an IP column.'
            );
        }
    }

    /**
     * No table schema declares a column that could hold an IP address.
     *
     * The installer owns the schema, but the promise is this issue's, and a
     * later column added there would silently break it.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_no_table_schema_declares_an_ip_column() {
        $schemas = Lisensio_KB_DB_Installer::get_schemas( 'wp_', '' );

        $this->assertNotEmpty( $schemas );

        foreach ( $schemas as $table => $sql ) {
            $this->assertSame(
                0,
                preg_match( '/\b(ip|ips|user_ip|ip_address|remote_addr|visitor_ip|ipv4|ipv6)\b/i', $sql ),
                sprintf( 'Table %s declares an IP column.', $table )
            );
        }
    }

    /**
     * A search is written with the term, its normalised form and the count.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_search_is_recorded_with_its_normalized_term() {
        $analytics = $this->analytics();

        $this->assertTrue( $analytics->record_search_event( '  Reset   MY Password ', 0, 'live' ) );

        $rows = $this->wpdb->inserted_rows( 'lisensio_search_log' );

        $this->assertCount( 1, $rows );
        $this->assertSame( 'Reset   MY Password', $rows[0]['term'] );
        $this->assertSame( 'reset my password', $rows[0]['normalized_term'] );
        $this->assertSame( 0, $rows[0]['results_count'] );
        $this->assertSame( 'live', $rows[0]['source'] );
    }

    /**
     * An unknown source falls back to `site` rather than being stored.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_unknown_search_source_is_replaced() {
        $analytics = $this->analytics();

        $analytics->record_search_event( 'install', 2, 'x; DROP TABLE' );

        $rows = $this->wpdb->inserted_rows( 'lisensio_search_log' );

        $this->assertSame( 'site', $rows[0]['source'] );
    }

    /**
     * An empty term is not recorded at all.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_empty_search_term_writes_nothing() {
        $analytics = $this->analytics();

        $this->assertFalse( $analytics->record_search_event( '   ', 0 ) );
        $this->assertSame( array(), $this->wpdb->inserts );
    }

    /**
     * Turning collection off stops every write immediately.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_disabling_collection_stops_every_write() {
        $analytics = $this->analytics( array( 'enable_analytics' => false ) );

        $this->assertFalse( $analytics->record_article_view( 42 ) );
        $this->assertFalse( $analytics->record_search_event( 'reset my password', 3 ) );

        $this->assertSame( array(), $this->wpdb->inserts, 'A row was written while collection was disabled.' );
    }

    /**
     * The `lisensio_analytics_enabled` filter can stop collection on its own.
     *
     * Brain Monkey does not run `add_filter` callbacks on `apply_filters`, so
     * the return value is staged rather than hooked.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_enabled_filter_can_stop_collection() {
        Filters\expectApplied( 'lisensio_analytics_enabled' )->once()->andReturn( false );

        $analytics = $this->analytics( array( 'enable_analytics' => true ) );

        $this->assertFalse( $analytics->record_article_view( 42 ) );
        $this->assertSame( array(), $this->wpdb->inserts );
    }

    /**
     * The same article is counted once per request, however often it renders.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_same_article_is_counted_once_per_request() {
        $analytics = $this->analytics();

        $this->assertTrue( $analytics->record_article_view( 42 ) );
        $this->assertFalse( $analytics->record_article_view( 42 ) );
        $this->assertTrue( $analytics->record_article_view( 43 ) );

        $this->assertCount( 2, $this->wpdb->inserted_rows( 'lisensio_article_views' ) );
    }

    /**
     * A view fired for something that is not an article writes nothing.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_view_without_an_article_writes_nothing() {
        $analytics = $this->analytics();

        $this->assertFalse( $analytics->record_article_view( null ) );
        $this->assertFalse( $analytics->record_article_view( 0 ) );
        $this->assertSame( array(), $this->wpdb->inserts );
    }

    /**
     * A rotated salt produces a different hash for the same visitor.
     *
     * Recorded through the analytics writer rather than against the hash
     * helper directly, so what is proven is that the stored identifier really
     * does change with the salt.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_salt_rotation_changes_the_recorded_hash() {
        $analytics = $this->analytics();
        $visitor   = new Lisensio_KB_Visitor();

        $analytics->record_article_view( 42 );

        $visitor->rotate_salt();

        $analytics->record_article_view( 43 );

        $rows = $this->wpdb->inserted_rows( 'lisensio_article_views' );

        $this->assertCount( 2, $rows );
        $this->assertNotSame(
            $rows[0]['visitor_hash'],
            $rows[1]['visitor_hash'],
            'The recorded hash survived a salt rotation, so the salt is not doing any work.'
        );
    }

    /**
     * Normalisation folds case and collapses whitespace.
     *
     * @since 1.0.0
     *
     * @dataProvider provide_terms
     *
     * @param string $raw      The term as typed.
     * @param string $expected The normalised form.
     *
     * @return void
     */
    public function test_normalize_term_folds_case_and_whitespace( $raw, $expected ) {
        $this->assertSame( $expected, Lisensio_KB_Analytics::normalize_term( $raw ) );
    }

    /**
     * Terms and their normalised forms.
     *
     * @since 1.0.0
     *
     * @return array<int, array{0: string, 1: string}> The cases.
     */
    public function provide_terms() {
        return array(
            array( 'Reset Password', 'reset password' ),
            array( "  reset\t\npassword  ", 'reset password' ),
            array( 'RESET   PASSWORD', 'reset password' ),
            array( 'Licence Key', 'licence key' ),
        );
    }

    /**
     * A term longer than the column is cut to fit rather than truncated by MySQL.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_long_term_is_cut_to_the_column_width() {
        $analytics = $this->analytics();

        $analytics->record_search_event( str_repeat( 'a', 500 ), 1 );

        $rows = $this->wpdb->inserted_rows( 'lisensio_search_log' );

        $this->assertSame( Lisensio_KB_Analytics::TERM_MAX_LENGTH, strlen( $rows[0]['term'] ) );
        $this->assertSame( Lisensio_KB_Analytics::TERM_MAX_LENGTH, strlen( $rows[0]['normalized_term'] ) );
    }

    /**
     * View tracking attaches to the shared after-article hook.
     *
     * One registration has to cover both the classic and the block template
     * layers, which is the whole reason the hook contract exists.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_view_tracking_attaches_to_the_after_article_hook() {
        $analytics = $this->analytics();

        $analytics->init_hooks();

        $this->assertNotFalse(
            has_action( 'lisensio_after_article', array( $analytics, 'handle_article_view' ) )
        );
    }

    /**
     * The article views column is populated through #3's own filter.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_views_column_is_populated_through_the_published_filter() {
        $analytics = $this->analytics();

        $analytics->init_hooks();

        $this->assertNotFalse(
            has_filter( 'lisensio_article_views_column', array( $analytics, 'filter_views_column' ) )
        );
    }

    /**
     * The controller is added to the shared REST registrar.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_rest_controller_is_added_to_the_registrar() {
        $analytics = $this->analytics();

        $this->assertSame(
            array( 'Lisensio_KB_REST_Analytics_Controller' ),
            $analytics->register_rest_controller( array() )
        );
    }

    /**
     * Only the analytics stub routes are released.
     *
     * Releasing another issue's stub would leave its route answering a 404
     * instead of the documented 501.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_only_the_analytics_stub_routes_are_released() {
        $analytics = $this->analytics();

        $routes = array(
            '/search'             => array( 'methods' => 'GET' ),
            '/feedback'           => array( 'methods' => 'POST' ),
            '/analytics/overview' => array( 'methods' => 'GET' ),
            '/analytics/searches' => array( 'methods' => 'GET' ),
            '/analytics/articles' => array( 'methods' => 'GET' ),
            '/analytics/feedback' => array( 'methods' => 'GET' ),
            '/settings'           => array( 'methods' => 'GET, POST' ),
        );

        $this->assertSame(
            array( '/search', '/feedback', '/settings' ),
            array_keys( $analytics->release_stub_routes( $routes ) )
        );
    }
}
