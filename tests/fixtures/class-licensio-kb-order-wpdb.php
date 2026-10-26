<?php
/**
 * $wpdb double that answers the per-category positions query.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

/**
 * Answers `Licensio_KB_Article_Order::positions()` from an in-memory meta store.
 *
 * @since 1.0.0
 */
class Licensio_KB_Order_WPDB extends Licensio_KB_Test_WPDB {

    /**
     * Returns the meta store: post ID to meta key to value.
     *
     * @since 1.0.0
     * @var callable
     */
    private $meta;

    /**
     * Stores one inserted meta row: post ID, meta key, value.
     *
     * @since 1.0.0
     * @var callable|null
     */
    private $write;

    /**
     * The positions statements answered, in order.
     *
     * @since 1.0.0
     * @var string[]
     */
    public $position_reads = array();

    /**
     * The statements asking whether a category has positions, in order.
     *
     * @since 1.0.0
     * @var string[]
     */
    public $presence_reads = array();

    /**
     * The post meta INSERT that fails, counted from 1; 0 for none.
     *
     * @since 1.0.0
     * @var int
     */
    public $failing_insert = 0;

    /**
     * The post meta INSERTs run so far.
     *
     * @since 1.0.0
     * @var int
     */
    private $meta_inserts = 0;

    /**
     * Build the double.
     *
     * @since 1.0.0
     *
     * @param callable      $meta  Returns the meta store: post ID to meta key to value.
     * @param callable|null $write Stores one inserted meta row: post ID, meta key, value.
     */
    public function __construct( callable $meta, ?callable $write = null ) {
        $this->meta  = $meta;
        $this->write = $write;
    }

    /**
     * Run a statement, storing the rows of a post meta INSERT.
     *
     * The INSERT counted by `$failing_insert` stores nothing and fails, as a
     * statement the server rejects would.
     *
     * @since 1.0.0
     *
     * @param string $query The statement.
     *
     * @return int|false The rows inserted, false for the failing INSERT, or what the parent double answers.
     */
    public function query( $query ) {
        if ( null === $this->write || 0 !== strpos( (string) $query, 'INSERT INTO `' . $this->postmeta . '`' ) ) {
            return parent::query( $query );
        }

        $this->queries[] = (string) $query;

        ++$this->meta_inserts;

        if ( $this->meta_inserts === $this->failing_insert ) {
            return false;
        }

        preg_match_all( "/\\( (\\d+), '([^']*)', '([^']*)' \\)/", (string) $query, $rows, PREG_SET_ORDER );

        foreach ( $rows as $row ) {
            call_user_func( $this->write, (int) $row[1], $row[2], $row[3] );
        }

        return count( $rows );
    }

    /**
     * Interpolate a statement.
     *
     * Takes the values as arguments or as one array, as `wpdb::prepare()` does.
     *
     * @since 1.0.0
     *
     * @param string $query   The statement.
     * @param mixed  ...$args The values, or one array of them.
     *
     * @return string The interpolated statement.
     */
    public function prepare( $query, ...$args ) {
        if ( 1 === count( $args ) && is_array( $args[0] ) ) {
            $args = $args[0];
        }

        return parent::prepare( $query, ...$args );
    }

    /**
     * The rows of a positions statement, sorted as the server sorts them.
     *
     * The meta key is read from the interpolated statement. Any other
     * statement is answered by the parent double.
     *
     * @since 1.0.0
     *
     * @param string $query  The statement.
     * @param mixed  $output The output format.
     *
     * @return array<int, mixed> The rows.
     */
    public function get_results( $query = '', $output = null ) {
        if ( false === strpos( (string) $query, 'pm.meta_value' ) ) {
            return parent::get_results( $query, $output );
        }

        $this->position_reads[] = (string) $query;

        preg_match( "/pm\\.meta_key = '([^']*)'/", (string) $query, $matches );

        $key  = $matches[1];
        $rows = array();

        foreach ( call_user_func( $this->meta ) as $id => $values ) {
            if ( isset( $values[ $key ] ) ) {
                $rows[] = array( (string) $id, (string) $values[ $key ] );
            }
        }

        usort(
            $rows,
            function ( $a, $b ) {
                return array( (int) $a[1], (int) $a[0] ) <=> array( (int) $b[1], (int) $b[0] );
            }
        );

        return $rows;
    }

    /**
     * Answer whether any article holds a meta key.
     *
     * The meta key is read from the interpolated statement.
     *
     * @since 1.0.0
     *
     * @param string $query The statement.
     *
     * @return string|null '1' when an article holds the key, otherwise null.
     */
    public function get_var( $query = '' ) {
        $this->presence_reads[] = (string) $query;

        preg_match( "/meta_key = '([^']*)'/", (string) $query, $matches );

        foreach ( call_user_func( $this->meta ) as $values ) {
            if ( isset( $values[ $matches[1] ] ) ) {
                return '1';
            }
        }

        return null;
    }
}
