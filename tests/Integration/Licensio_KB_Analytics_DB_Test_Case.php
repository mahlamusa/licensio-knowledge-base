<?php
/**
 * Base case for the analytics tests that need a real database.
 *
 * Aggregation and retention are the two things a double cannot honestly answer.
 * A `$wpdb` stand-in can record that a `GROUP BY` was requested; only a server
 * can say whether the numbers that come back are right, and only a server can
 * say whether a chunked `DELETE ... LIMIT` removed the rows it was supposed to
 * and left the rest alone.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

/*
 * This suite connects to a real database on purpose. The WordPress Database API
 * is therefore not available or appropriate here.
 */
// phpcs:disable WordPress.DB.RestrictedClasses, WordPress.DB.RestrictedFunctions, WordPress.DB.DirectDatabaseQuery

require_once __DIR__ . '/Licensio_KB_Integration_Test_Case.php';
require_once __DIR__ . '/class-licensio-kb-live-wpdb.php';
require_once __DIR__ . '/class-licensio-kb-integration-settings.php';

/**
 * Installs the plugin schema on a scratch database and seeds analytics rows.
 *
 * @since 1.0.0
 */
abstract class Licensio_KB_Analytics_DB_Test_Case extends Licensio_KB_Integration_Test_Case {

    /**
     * The table prefix used for the scratch schema.
     *
     * @since 1.0.0
     * @var string
     */
    const PREFIX = 'lkbit_';

    /**
     * The moment every test treats as "now".
     *
     * @since 1.0.0
     * @var string
     */
    const NOW = '2026-08-30 12:00:00';

    /**
     * The mysqli-backed $wpdb adapter.
     *
     * @since 1.0.0
     * @var Licensio_KB_Live_WPDB
     */
    protected $adapter;

    /**
     * Create the tables and point the plugin's gateway at them.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        $this->adapter   = new Licensio_KB_Live_WPDB( $this->db, self::PREFIX );
        $GLOBALS['wpdb'] = $this->adapter;

        foreach ( Licensio_KB_DB_Installer::get_schemas( self::PREFIX, 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci' ) as $name => $sql ) {
            $this->assertTrue(
                (bool) $this->db->query( $sql ),
                sprintf( 'Schema %1$s failed: %2$s', $name, $this->db->error )
            );
        }

        Brain\Monkey\Functions\when( 'current_time' )->alias(
            function ( $format ) {
                return 'mysql' === $format ? self::NOW : strtotime( self::NOW . ' UTC' );
            }
        );

        Brain\Monkey\Functions\when( 'get_the_title' )->alias(
            function ( $id ) {
                return 'Article ' . (int) $id;
            }
        );

        Brain\Monkey\Functions\stubTranslationFunctions();

        Brain\Monkey\Functions\when( 'sanitize_text_field' )->alias(
            function ( $value ) {
                return is_string( $value ) ? trim( $value ) : '';
            }
        );
    }

    /**
     * Release the adapter.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function tearDown(): void {
        unset( $GLOBALS['wpdb'] );

        parent::tearDown();
    }

    /**
     * The fully qualified name of one of the scratch tables.
     *
     * @since 1.0.0
     *
     * @param string $table The unprefixed table name.
     *
     * @return string The prefixed table name.
     */
    protected function table( $table ) {
        return self::PREFIX . $table;
    }

    /**
     * Insert rows straight into a scratch table.
     *
     * Deliberately bypasses the plugin's own writer: what these tests are about
     * is what the aggregation and retention code does with rows that are known
     * to be there, and driving the writer would make a query failure and a
     * capture failure look the same.
     *
     * @since 1.0.0
     *
     * @param string                           $table   The unprefixed table name.
     * @param array<int, array<string, mixed>> $rows    The rows to insert.
     *
     * @return void
     */
    protected function seed( $table, array $rows ) {
        foreach ( $rows as $row ) {
            $columns = array();
            $values  = array();

            foreach ( $row as $column => $value ) {
                $columns[] = $column;
                $values[]  = is_int( $value ) ? (string) $value : "'" . $this->db->real_escape_string( (string) $value ) . "'";
            }

            $sql = 'INSERT INTO ' . $this->table( $table )
                . ' (' . implode( ', ', $columns ) . ') VALUES (' . implode( ', ', $values ) . ')';

            $this->assertTrue( (bool) $this->db->query( $sql ), $this->db->error );
        }
    }

    /**
     * How many rows a scratch table holds.
     *
     * @since 1.0.0
     *
     * @param string $table The unprefixed table name.
     * @param string $where Optional WHERE clause, without the keyword.
     *
     * @return int The row count.
     */
    protected function count_rows( $table, $where = '' ) {
        $sql = 'SELECT COUNT(*) AS total FROM ' . $this->table( $table );

        if ( '' !== $where ) {
            $sql .= ' WHERE ' . $where;
        }

        return (int) $this->db->query( $sql )->fetch_object()->total;
    }

    /**
     * A `Y-m-d H:i:s` timestamp a number of days before NOW.
     *
     * @since 1.0.0
     *
     * @param int $days How many days back.
     *
     * @return string The timestamp.
     */
    protected function days_ago( $days ) {
        return gmdate( 'Y-m-d H:i:s', strtotime( self::NOW . ' UTC' ) - ( (int) $days * DAY_IN_SECONDS ) );
    }

    /**
     * A settings store reporting one raw value.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $values Setting name to raw value.
     *
     * @return Licensio_KB_Settings The settings double.
     */
    protected function settings( array $values ) {
        return new Licensio_KB_Integration_Settings( $values );
    }
}
