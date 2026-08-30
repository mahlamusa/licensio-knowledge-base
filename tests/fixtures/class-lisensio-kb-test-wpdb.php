<?php
/**
 * Minimal $wpdb double.
 *
 * Brain Monkey supplies WordPress functions but no classes. This records the
 * statements the installer asks it to run so a test can assert on them.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

/**
 * Stand-in for $wpdb, recording the statements it is asked to run.
 *
 * @since 1.0.0
 */
class Lisensio_KB_Test_WPDB {

    /**
     * The table prefix.
     *
     * @since 1.0.0
     * @var string
     */
    public $prefix = 'wptests_';

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
