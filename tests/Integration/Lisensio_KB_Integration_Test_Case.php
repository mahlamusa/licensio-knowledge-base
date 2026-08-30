<?php
/**
 * Base case for tests that need a live database server.
 *
 * The unit suite runs against doubles, which is why a FULLTEXT capability probe
 * that MySQL rejects outright still passed a fully green suite: the double
 * answered success for every statement. These tests exist to exercise the
 * statements a real server actually receives.
 *
 * Every test here skips when no database is configured or reachable, so the
 * default `vendor/bin/phpunit` run stays fast and dependency-free.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

/*
 * This suite connects to a real database on purpose: it exists precisely because
 * the unit suite's doubles cannot answer how a real server responds to real DDL.
 * The WordPress Database API is therefore not available or appropriate here.
 */
// phpcs:disable WordPress.DB.RestrictedClasses, WordPress.DB.RestrictedFunctions, WordPress.DB.DirectDatabaseQuery


/**
 * Connects to a live MySQL or MariaDB server, or skips.
 *
 * @since 1.0.0
 */
abstract class Lisensio_KB_Integration_Test_Case extends PHPUnit\Framework\TestCase {

    /**
     * Whether Brain Monkey is currently active for this test.
     *
     * @since 1.0.0
     * @var bool
     */
    private $monkey_active = false;

    /**
     * The live connection, or null when the suite is skipping.
     *
     * @since 1.0.0
     * @var mysqli|null
     */
    protected $db = null;

    /**
     * The scratch database created for the test.
     *
     * @since 1.0.0
     * @var string
     */
    protected $database = '';

    /**
     * Connect, or skip with the reason.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        $host = (string) getenv( 'LISENSIO_KB_TEST_DB_HOST' );

        if ( '' === $host ) {
            $this->markTestSkipped( 'Set LISENSIO_KB_TEST_DB_HOST to run the live database suite.' );
        }

        $user   = (string) getenv( 'LISENSIO_KB_TEST_DB_USER' );
        $pass   = (string) getenv( 'LISENSIO_KB_TEST_DB_PASS' );
        $port   = (int) getenv( 'LISENSIO_KB_TEST_DB_PORT' );
        $socket = (string) getenv( 'LISENSIO_KB_TEST_DB_SOCKET' );

        mysqli_report( MYSQLI_REPORT_OFF );

        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.DB.RestrictedClasses.mysql__mysqli -- A live connection is the entire point of this suite.
        $db = @new mysqli( $host, $user, $pass, '', $port > 0 ? $port : 3306, '' !== $socket ? $socket : null );

        if ( $db->connect_errno ) {
            $this->markTestSkipped( 'Database unreachable: ' . $db->connect_error );
        }

        $this->db       = $db;
        $this->database = 'lisensio_kb_it_' . substr( md5( uniqid( '', true ) ), 0, 12 );

        $db->query( 'CREATE DATABASE ' . $this->database );
        $db->select_db( $this->database );

        $this->apply_wordpress_sql_mode();

        /*
         * The installer calls apply_filters() and do_action(). Brain Monkey
         * supplies them per-test and tears them down again, which matters: an
         * earlier revision defined global pass-through shims in this file, and
         * because PHPUnit loads every suite's classes in one process those
         * globals leaked into the unit run and broke its filter assertions.
         */
        Brain\Monkey\setUp();
        $this->monkey_active = true;

        Brain\Monkey\Functions\when( 'apply_filters' )->returnArg( 2 );
        Brain\Monkey\Functions\when( 'do_action' )->justReturn( null );
    }

    /**
     * Drop the scratch database and disconnect.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function tearDown(): void {
        if ( $this->monkey_active ) {
            Brain\Monkey\tearDown();
            $this->monkey_active = false;
        }

        if ( $this->db instanceof mysqli ) {
            if ( '' !== $this->database ) {
                $this->db->query( 'DROP DATABASE IF EXISTS ' . $this->database );
            }

            $this->db->close();
            $this->db = null;
        }

        parent::tearDown();
    }

    /**
     * Strip the sql_modes WordPress considers incompatible.
     *
     * `wpdb::set_sql_mode()` removes these on every connection, so a test that
     * did not would reject schemas WordPress accepts in production — notably
     * the `'0000-00-00 00:00:00'` datetime defaults used throughout core.
     *
     * @since 1.0.0
     *
     * @return void
     */
    private function apply_wordpress_sql_mode() {
        $incompatible = array(
            'NO_ZERO_DATE',
            'NO_ZERO_IN_DATE',
            'ONLY_FULL_GROUP_BY',
            'STRICT_TRANS_TABLES',
            'STRICT_ALL_TABLES',
            'TRADITIONAL',
            'ANSI',
        );

        $result = $this->db->query( 'SELECT @@SESSION.sql_mode AS mode' );

        if ( ! $result ) {
            return;
        }

        $current = (string) $result->fetch_object()->mode;
        $keep    = array_diff( array_filter( explode( ',', $current ) ), $incompatible );

        $this->db->query( "SET SESSION sql_mode = '" . implode( ',', $keep ) . "'" );
    }

    /**
     * The server version string.
     *
     * @since 1.0.0
     *
     * @return string The reported server version.
     */
    protected function server_version() {
        return (string) $this->db->query( 'SELECT VERSION() AS v' )->fetch_object()->v;
    }
}
