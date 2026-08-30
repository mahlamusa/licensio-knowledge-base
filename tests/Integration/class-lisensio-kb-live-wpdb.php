<?php
/**
 * A minimal mysqli-backed stand-in for $wpdb.
 *
 * Just enough surface for the installer to run its real code against a real
 * server. Without this the integration suite would only prove things about
 * MySQL; with it, the plugin's own `detect_fulltext_index()` and
 * `add_fulltext_index()` are the code under test.
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
 * Routes the handful of $wpdb calls the installer makes at a live connection.
 *
 * @since 1.0.0
 */
class Lisensio_KB_Live_WPDB {

    /**
     * The live connection.
     *
     * @since 1.0.0
     * @var mysqli
     */
    private $db;

    /**
     * The last error reported by the server.
     *
     * @since 1.0.0
     * @var string
     */
    public $last_error = '';

    /**
     * Table prefix.
     *
     * @since 1.0.0
     * @var string
     */
    public $prefix = '';

    /**
     * Build the adapter.
     *
     * @since 1.0.0
     *
     * @param mysqli $db     A live connection.
     * @param string $prefix The table prefix in use.
     */
    public function __construct( $db, $prefix ) {
        $this->db     = $db;
        $this->prefix = $prefix;
    }

    /**
     * Run a statement.
     *
     * @since 1.0.0
     *
     * @param string $query The SQL statement.
     *
     * @return int|false Rows affected, or false on failure.
     */
    public function query( $query ) {
        $result = $this->db->query( $query );

        $this->last_error = $this->db->error;

        return false === $result ? false : 1;
    }

    /**
     * Run a statement and return its rows as objects.
     *
     * @since 1.0.0
     *
     * @param string $query The SQL statement.
     *
     * @return array<int, object> The result rows.
     */
    public function get_results( $query ) {
        $result = $this->db->query( $query );

        $this->last_error = $this->db->error;

        if ( ! $result instanceof mysqli_result ) {
            return array();
        }

        $rows = array();

        while ( $row = $result->fetch_object() ) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Interpolate a statement. Only %s is used by the installer.
     *
     * @since 1.0.0
     *
     * @param string $query The SQL statement.
     * @param mixed  ...$args Values to bind.
     *
     * @return string The prepared statement.
     */
    public function prepare( $query, ...$args ) {
        foreach ( $args as $arg ) {
            $query = preg_replace( '/%s/', "'" . $this->db->real_escape_string( (string) $arg ) . "'", $query, 1 );
        }

        return $query;
    }

    /**
     * Toggle error suppression. The adapter never prints, so this is a no-op.
     *
     * @since 1.0.0
     *
     * @param bool $suppress Whether to suppress.
     *
     * @return bool The previous setting, always false.
     */
    public function suppress_errors( $suppress = true ) {
        unset( $suppress );

        return false;
    }
}
