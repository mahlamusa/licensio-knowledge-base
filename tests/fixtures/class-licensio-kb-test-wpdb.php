<?php
/**
 * Minimal $wpdb double.
 *
 * Brain Monkey supplies WordPress functions but no classes. This records the
 * statements the installer asks it to run so a test can assert on them.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

/**
 * Stand-in for $wpdb, recording the statements it is asked to run.
 *
 * @since 1.0.0
 */
class Licensio_KB_Test_WPDB {

    /**
     * The table prefix.
     *
     * @since 1.0.0
     * @var string
     */
    public $prefix = 'wptests_';

    /**
     * The users table name.
     *
     * StellarWP's TablePrefix treats users and usermeta as shared tables and
     * reads them off $wpdb directly, so the double must carry them.
     *
     * @since 1.0.0
     * @var string
     */
    public $users = 'wptests_users';

    /**
     * The user meta table name.
     *
     * @since 1.0.0
     * @var string
     */
    public $usermeta = 'wptests_usermeta';

    /**
     * The last SQL error, always empty in the double.
     *
     * @since 1.0.0
     * @var string
     */
    public $last_error = '';

    /**
     * The last query run.
     *
     * @since 1.0.0
     * @var string
     */
    public $last_query = '';

    /**
     * Every statement passed to query().
     *
     * @since 1.0.0
     * @var string[]
     */
    public $queries = array();

    /**
     * What query() returns; set to false to simulate a database that
     * rejects the FULLTEXT probe.
     *
     * @since 1.0.0
     * @var int|false
     */
    public $query_result = 1;

    /**
     * Whether the simulated server honours a requested FULLTEXT key.
     *
     * Set to false to model a server that accepts the CREATE TABLE but does
     * not create the index.
     *
     * @since 1.0.0
     * @var bool
     */
    public $server_supports_fulltext = true;

    /**
     * Every row passed to insert(), in order.
     *
     * Recorded rather than stored so a test can assert on exactly what the code
     * under test tried to write — which is how "no IP reaches any table" is
     * provable rather than assumed.
     *
     * @since 1.0.0
     * @var array<int, array{table: string, data: array<string, mixed>, format: mixed}>
     */
    public $inserts = array();

    /**
     * What insert() returns.
     *
     * @since 1.0.0
     * @var int|false
     */
    public $insert_result = 1;

    /**
     * The ID of the last inserted row.
     *
     * @since 1.0.0
     * @var int
     */
    public $insert_id = 0;

    /**
     * Record a row instead of writing it.
     *
     * @since 1.0.0
     *
     * @param string               $table  The table name.
     * @param array<string, mixed> $data   Column to value.
     * @param mixed                $format The value formats.
     *
     * @return int|false The configured result.
     */
    public function insert( $table, $data, $format = null ) {
        $this->inserts[] = array(
            'table'  => (string) $table,
            'data'   => (array) $data,
            'format' => $format,
        );

        ++$this->insert_id;

        return $this->insert_result;
    }

    /**
     * The rows recorded for one table.
     *
     * @since 1.0.0
     *
     * @param string $table The table name, with or without the prefix.
     *
     * @return array<int, array<string, mixed>> The recorded row data.
     */
    public function inserted_rows( $table ) {
        $rows = array();

        foreach ( $this->inserts as $insert ) {
            if ( false !== strpos( $insert['table'], $table ) ) {
                $rows[] = $insert['data'];
            }
        }

        return $rows;
    }

    /**
     * The charset and collation clause.
     *
     * @since 1.0.0
     *
     * @return string The clause used in CREATE TABLE statements.
     */
    public function get_charset_collate() {
        return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    }

    /**
     * Record a statement instead of running it.
     *
     * @since 1.0.0
     *
     * @param string $query The SQL statement.
     *
     * @return int|false The configured result.
     */
    public function query( $query ) {
        $this->queries[]  = $query;
        $this->last_query = $query;

        // A server without FULLTEXT support rejects the ALTER, as a real one would.
        if ( ! $this->server_supports_fulltext && false !== stripos( (string) $query, 'ADD FULLTEXT' ) ) {
            return false;
        }

        return $this->query_result;
    }

    /**
     * Answer SHOW INDEX from what was actually requested of the server.
     *
     * The result is derived rather than canned: the index exists only if an
     * `ADD FULLTEXT` was really issued AND the simulated server honours it.
     * A double that returns success unconditionally would certify whatever the
     * code does, which is how a probe that can never succeed on MySQL 8 passed
     * a green suite.
     *
     * @since 1.0.0
     *
     * @param string $query  The SQL statement.
     * @param mixed  $output Ignored; present for signature compatibility.
     *
     * @return array<int, object> Matching index rows.
     */
    public function get_results( $query = '', $output = null ) {
        unset( $output );

        $this->last_query = $query;

        if ( false === stripos( (string) $query, 'SHOW INDEX' ) ) {
            return array();
        }

        // Rows a real server would always report for this table.
        $rows = array(
            (object) array(
                'Key_name'   => 'PRIMARY',
                'Index_type' => 'BTREE',
            ),
            (object) array(
                'Key_name'   => 'updated_at',
                'Index_type' => 'BTREE',
            ),
        );

        $attempted = false;

        foreach ( $this->queries as $issued ) {
            if ( false !== stripos( (string) $issued, 'ADD FULLTEXT' ) ) {
                $attempted = true;
                break;
            }
        }

        if ( $attempted && $this->server_supports_fulltext ) {
            $rows[] = (object) array(
                'Key_name'   => 'ft_all',
                'Index_type' => 'FULLTEXT',
            );
        }

        return $rows;
    }

    /**
     * Toggle error suppression.
     *
     * @since 1.0.0
     *
     * @param bool $suppress Whether to suppress errors.
     *
     * @return bool The previous setting.
     */
    public function suppress_errors( $suppress = true ) {
        unset( $suppress );

        return false;
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
     * @return bool Whether errors were previously hidden.
     */
    public function show_errors() {
        return true;
    }

    /**
     * Interpolate a statement.
     *
     * @since 1.0.0
     *
     * @param string $query The statement.
     * @param mixed  ...$args The values.
     *
     * @return string The interpolated statement.
     */
    public function prepare( $query, ...$args ) {
        return vsprintf( str_replace( array( '%s', '%d' ), array( "'%s'", '%d' ), $query ), $args );
    }
}
