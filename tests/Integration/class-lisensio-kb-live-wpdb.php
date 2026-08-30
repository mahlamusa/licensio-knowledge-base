<?php
/**
 * A minimal mysqli-backed stand-in for $wpdb.
 *
 * Just enough surface for the installer and the search stack to run their real
 * code against a real server. Without this the integration suite would only
 * prove things about MySQL; with it, the plugin's own `detect_fulltext_index()`,
 * `add_fulltext_index()`, search drivers and index writer are the code under
 * test.
 *
 * Two behaviours are deliberate and load-bearing.
 *
 * `prepare()` reproduces the one piece of `wpdb::prepare()` semantics the
 * vendored query builder depends on: a `%s` placeholder carrying a width or
 * format prefix (`%1s`, `%2s`) interpolates UNQUOTED, because the builder uses
 * exactly that form for table names, column names and operators, while a plain
 * `%s` is escaped and quoted as a value. A double that quoted both would
 * produce `SELECT * FROM 'lkb_lisensio_search_index'` and every query in the
 * suite would fail for a reason that has nothing to do with the code under
 * test.
 *
 * A failed statement raises rather than returning false, unless errors have
 * been suppressed the way the installer suppresses them around its optional
 * `ADD FULLTEXT`. A driver that generates malformed SQL must fail its test
 * loudly; returning an empty result set instead would let a broken query read
 * as "no matches", which is precisely the shape of bug this suite exists for.
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
 * Routes the $wpdb calls the plugin makes at a live connection.
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
     * Whether errors are currently suppressed.
     *
     * @since 1.0.0
     * @var bool
     */
    private $suppressed = false;

    /**
     * The last error reported by the server.
     *
     * @since 1.0.0
     * @var string
     */
    public $last_error = '';

    /**
     * The last statement sent to the server.
     *
     * @since 1.0.0
     * @var string
     */
    public $last_query = '';

    /**
     * Every statement sent to the server, in order.
     *
     * @since 1.0.0
     * @var string[]
     */
    public $queries = array();

    /**
     * The auto-increment value produced by the last insert.
     *
     * @since 1.0.0
     * @var int
     */
    public $insert_id = 0;

    /**
     * Table prefix.
     *
     * @since 1.0.0
     * @var string
     */
    public $prefix = '';

    /**
     * The users table name.
     *
     * StellarWP's TablePrefix treats users and usermeta as shared tables and
     * reads them off $wpdb directly, so the adapter must carry them.
     *
     * @since 1.0.0
     * @var string
     */
    public $users = '';

    /**
     * The user meta table name.
     *
     * @since 1.0.0
     * @var string
     */
    public $usermeta = '';

    /**
     * Build the adapter.
     *
     * @since 1.0.0
     *
     * @param mysqli $db     A live connection.
     * @param string $prefix The table prefix in use.
     */
    public function __construct( $db, $prefix ) {
        $this->db       = $db;
        $this->prefix   = $prefix;
        $this->users    = $prefix . 'users';
        $this->usermeta = $prefix . 'usermeta';
    }

    /**
     * Run a statement.
     *
     * @since 1.0.0
     *
     * @param string $query The SQL statement.
     *
     * @return int|false Rows affected, or false on failure.
     * @throws RuntimeException When the statement fails and errors are not suppressed.
     */
    public function query( $query ) {
        $result = $this->run( $query );

        if ( false === $result ) {
            return false;
        }

        return $result instanceof mysqli_result ? $result->num_rows : $this->db->affected_rows;
    }

    /**
     * Run a statement and return its rows.
     *
     * @since 1.0.0
     *
     * @param string $query  The SQL statement.
     * @param mixed  $output Ignored; rows are always objects.
     *
     * @return array<int, object> The result rows.
     * @throws RuntimeException When the statement fails and errors are not suppressed.
     */
    public function get_results( $query = '', $output = null ) {
        unset( $output );

        $result = $this->run( $query );

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
     * Run a statement and return its first row.
     *
     * @since 1.0.0
     *
     * @param string $query  The SQL statement.
     * @param mixed  $output Ignored; the row is always an object.
     *
     * @return object|null The first row, or null when there is none.
     * @throws RuntimeException When the statement fails and errors are not suppressed.
     */
    public function get_row( $query = '', $output = null ) {
        unset( $output );

        $rows = $this->get_results( $query );

        return empty( $rows ) ? null : $rows[0];
    }

    /**
     * Run a statement and return the first column of every row.
     *
     * @since 1.0.0
     *
     * @param string $query The SQL statement.
     * @param int    $index Which column to read.
     *
     * @return array<int, mixed> The column values.
     * @throws RuntimeException When the statement fails and errors are not suppressed.
     */
    public function get_col( $query = '', $index = 0 ) {
        $values = array();

        foreach ( $this->get_results( $query ) as $row ) {
            $columns  = array_values( (array) $row );
            $values[] = isset( $columns[ $index ] ) ? $columns[ $index ] : null;
        }

        return $values;
    }

    /**
     * Run a statement and return a single value.
     *
     * @since 1.0.0
     *
     * @param string $query The SQL statement.
     * @param int    $x     Which column to read.
     * @param int    $y     Which row to read.
     *
     * @return string|null The value, or null when there is none.
     * @throws RuntimeException When the statement fails and errors are not suppressed.
     */
    public function get_var( $query = '', $x = 0, $y = 0 ) {
        $rows = $this->get_results( $query );

        if ( ! isset( $rows[ $y ] ) ) {
            return null;
        }

        $columns = array_values( (array) $rows[ $y ] );

        return isset( $columns[ $x ] ) ? $columns[ $x ] : null;
    }

    /**
     * Insert a row.
     *
     * @since 1.0.0
     *
     * @param string               $table  The table name.
     * @param array<string, mixed> $data   Column to value.
     * @param mixed                $format Ignored; values are escaped by type.
     *
     * @return int|false Rows inserted, or false on failure.
     * @throws RuntimeException When the statement fails and errors are not suppressed.
     */
    public function insert( $table, $data, $format = null ) {
        unset( $format );

        $columns = array();
        $values  = array();

        foreach ( (array) $data as $column => $value ) {
            $columns[] = '`' . str_replace( '`', '', (string) $column ) . '`';
            $values[]  = $this->literal( $value );
        }

        $sql = 'INSERT INTO `' . $table . '` (' . implode( ', ', $columns ) . ') VALUES (' . implode( ', ', $values ) . ')';

        $result = $this->run( $sql );

        if ( false === $result ) {
            return false;
        }

        $this->insert_id = (int) $this->db->insert_id;

        return $this->db->affected_rows;
    }

    /**
     * Update rows.
     *
     * @since 1.0.0
     *
     * @param string               $table        The table name.
     * @param array<string, mixed> $data         Column to new value.
     * @param array<string, mixed> $where        Column to matched value.
     * @param mixed                $format       Ignored.
     * @param mixed                $where_format Ignored.
     *
     * @return int|false Rows updated, or false on failure.
     * @throws RuntimeException When the statement fails and errors are not suppressed.
     */
    public function update( $table, $data, $where, $format = null, $where_format = null ) {
        unset( $format, $where_format );

        $sets = array();

        foreach ( (array) $data as $column => $value ) {
            $sets[] = '`' . str_replace( '`', '', (string) $column ) . '` = ' . $this->literal( $value );
        }

        $conditions = array();

        foreach ( (array) $where as $column => $value ) {
            $conditions[] = '`' . str_replace( '`', '', (string) $column ) . '` = ' . $this->literal( $value );
        }

        $sql = 'UPDATE `' . $table . '` SET ' . implode( ', ', $sets );

        if ( ! empty( $conditions ) ) {
            $sql .= ' WHERE ' . implode( ' AND ', $conditions );
        }

        $result = $this->run( $sql );

        return false === $result ? false : $this->db->affected_rows;
    }

    /**
     * Interpolate a statement.
     *
     * Reproduces the two `wpdb::prepare()` behaviours the vendored query builder
     * relies on: `%s` is escaped and quoted as a value, while a `%s` carrying a
     * width or format prefix such as `%1s` is interpolated raw, which is how the
     * builder writes table names, column names and operators.
     *
     * @since 1.0.0
     *
     * @param string $query   The SQL statement.
     * @param mixed  ...$args Values to bind, or one array of them.
     *
     * @return string The prepared statement.
     */
    public function prepare( $query, ...$args ) {
        if ( 1 === count( $args ) && is_array( $args[0] ) ) {
            $args = $args[0];
        }

        $args     = array_values( $args );
        $position = 0;
        $adapter  = $this;

        return (string) preg_replace_callback(
            '/%([-+0-9]*)([sdfF])/',
            function ( $matches ) use ( &$position, $args, $adapter ) {
                $value = array_key_exists( $position, $args ) ? $args[ $position ] : '';
                ++$position;

                if ( 'd' === $matches[2] ) {
                    return (string) (int) $value;
                }

                if ( 'f' === $matches[2] || 'F' === $matches[2] ) {
                    return (string) (float) $value;
                }

                // A format prefix marks an identifier or operator, not a value.
                if ( '' !== $matches[1] ) {
                    return (string) $value;
                }

                return $adapter->quote( $value );
            },
            (string) $query
        );
    }

    /**
     * Escape a string for a LIKE comparison.
     *
     * @since 1.0.0
     *
     * @param string $text The text.
     *
     * @return string The escaped text.
     */
    public function esc_like( $text ) {
        return addcslashes( (string) $text, '_%\\' );
    }

    /**
     * Quote and escape a value as a SQL string literal.
     *
     * @since 1.0.0
     *
     * @param mixed $value The value.
     *
     * @return string The quoted literal.
     */
    public function quote( $value ) {
        return "'" . $this->db->real_escape_string( (string) $value ) . "'";
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
     * Toggle error suppression.
     *
     * @since 1.0.0
     *
     * @param bool $suppress Whether to suppress.
     *
     * @return bool The previous setting.
     */
    public function suppress_errors( $suppress = true ) {
        $previous         = $this->suppressed;
        $this->suppressed = (bool) $suppress;

        return $previous;
    }

    /**
     * Stop printing errors. The adapter never prints, so this is a no-op.
     *
     * @since 1.0.0
     *
     * @return bool Always false.
     */
    public function hide_errors() {
        return false;
    }

    /**
     * Start printing errors. The adapter never prints, so this is a no-op.
     *
     * @since 1.0.0
     *
     * @return bool Always true.
     */
    public function show_errors() {
        return true;
    }

    /**
     * Send a statement to the server.
     *
     * @since 1.0.0
     *
     * @param string $query The SQL statement.
     *
     * @return mysqli_result|bool The server's answer.
     * @throws RuntimeException When the statement fails and errors are not suppressed.
     */
    private function run( $query ) {
        $query = (string) $query;

        $this->last_query = $query;
        $this->queries[]  = $query;

        $result = $this->db->query( $query );

        $this->last_error = $this->db->error;

        if ( false === $result && ! $this->suppressed ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A test adapter; this reaches a PHPUnit failure message, never a browser.
            throw new RuntimeException( 'Query failed: ' . $this->db->error . ' -- ' . $query );
        }

        return $result;
    }

    /**
     * Render a PHP value as a SQL literal.
     *
     * @since 1.0.0
     *
     * @param mixed $value The value.
     *
     * @return string The literal.
     */
    private function literal( $value ) {
        if ( null === $value ) {
            return 'NULL';
        }

        if ( is_int( $value ) || is_float( $value ) ) {
            return (string) $value;
        }

        if ( is_bool( $value ) ) {
            return $value ? '1' : '0';
        }

        return $this->quote( $value );
    }
}
