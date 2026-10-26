<?php
/**
 * A $wpdb double for the batched term relationship writes.
 *
 * Licensio_KB_Term_Operations writes relationship rows with two statement
 * shapes: a multi-row `INSERT IGNORE` and a `DELETE ... IN ( ... )`. This
 * double reads those two shapes back into an action, a term taxonomy ID and
 * the object IDs, and hands them to a callback, so a suite can apply them to
 * its own store and make any one of them fail. Any other statement fails the
 * test. It also answers the edit lock read Licensio_KB_Term_Operations makes
 * before a move, from `$edit_locks`.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

/**
 * Stand-in for $wpdb that understands the relationship writes.
 *
 * @since 1.0.0
 */
class Licensio_KB_Relationships_WPDB {

    /**
     * The term relationships table name.
     *
     * @since 1.0.0
     * @var string
     */
    public $term_relationships = 'wptests_term_relationships';

    /**
     * The post meta table name.
     *
     * @since 1.0.0
     * @var string
     */
    public $postmeta = 'wptests_postmeta';

    /**
     * The `_edit_lock` value of each locked post, by post ID.
     *
     * @since 1.0.0
     * @var array<int, string>
     */
    public $edit_locks = array();

    /**
     * Applies one write and returns the rows affected, or false to fail it.
     *
     * @since 1.0.0
     * @var callable
     */
    private $handler;

    /**
     * Set the callback that applies the writes.
     *
     * @since 1.0.0
     *
     * @param callable $handler Called with `add` or `remove`, the term taxonomy ID and the object IDs.
     */
    public function __construct( callable $handler ) {
        $this->handler = $handler;
    }

    /**
     * Interpolate a statement, as `wpdb::prepare()` does.
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

        return vsprintf( str_replace( array( '%s', '%d', '%i' ), array( "'%s'", '%d', '`%s`' ), $query ), $args );
    }

    /**
     * Read a relationship write back and apply it through the callback.
     *
     * @since 1.0.0
     *
     * @param string $query The statement.
     *
     * @return int|false The rows affected, or false when the callback fails the write.
     * @throws UnexpectedValueException When the statement is not a relationship write.
     */
    public function query( $query ) {
        if ( preg_match( '/^INSERT IGNORE INTO `[^`]+` \( object_id, term_taxonomy_id \) VALUES (.+)$/s', $query, $matches ) ) {
            preg_match_all( '/\( (\d+), (\d+) \)/', $matches[1], $pairs );

            return call_user_func( $this->handler, 'add', (int) $pairs[2][0], array_map( 'intval', $pairs[1] ) );
        }

        if ( preg_match( '/^DELETE FROM `[^`]+` WHERE term_taxonomy_id = (\d+) AND object_id IN \( ([\d, ]+) \)$/', $query, $matches ) ) {
            return call_user_func( $this->handler, 'remove', (int) $matches[1], array_map( 'intval', explode( ', ', $matches[2] ) ) );
        }

        throw new UnexpectedValueException( 'Unexpected statement: ' . $query ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A test failure message, never output to a page.
    }

    /**
     * Answer the edit lock read with the locks of the posts it names.
     *
     * @since 1.0.0
     *
     * @param string $query The statement.
     *
     * @return array<int, object> The `post_id` and `meta_value` rows.
     * @throws UnexpectedValueException When the statement is not the edit lock read.
     */
    public function get_results( $query ) {
        if ( ! preg_match( "/^SELECT post_id, meta_value FROM `[^`]+` WHERE meta_key = '_edit_lock' AND post_id IN \\( ([\\d, ]+) \\)$/", $query, $matches ) ) {
            throw new UnexpectedValueException( 'Unexpected statement: ' . $query ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A test failure message, never output to a page.
        }

        $rows = array();

        foreach ( array_map( 'intval', explode( ', ', $matches[1] ) ) as $post_id ) {
            if ( isset( $this->edit_locks[ $post_id ] ) ) {
                $rows[] = (object) array(
                    'post_id'    => (string) $post_id,
                    'meta_value' => $this->edit_locks[ $post_id ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- A result row, not a query.
                );
            }
        }

        return $rows;
    }
}
