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
     * The values bound by the last prepare() call.
     *
     * @since 1.0.0
     * @var array<int, mixed>
     */
    private $bound = array();

    /**
     * The positions statements answered, in order.
     *
     * @since 1.0.0
     * @var string[]
     */
    public $position_reads = array();

    /**
     * Build the double.
     *
     * @since 1.0.0
     *
     * @param callable $meta Returns the meta store: post ID to meta key to value.
     */
    public function __construct( callable $meta ) {
        $this->meta = $meta;
    }

    /**
     * Interpolate a statement and remember the values bound to it.
     *
     * @since 1.0.0
     *
     * @param string $query   The statement.
     * @param mixed  ...$args The values.
     *
     * @return string The interpolated statement.
     */
    public function prepare( $query, ...$args ) {
        $this->bound = $args;

        return parent::prepare( $query, ...$args );
    }

    /**
     * The rows of a positions statement, sorted as the server sorts them.
     *
     * The statement binds the post meta table, the posts table, the meta
     * key, the post type and the excluded status, in that order. Any other
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

        $key  = (string) $this->bound[2];
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
}
