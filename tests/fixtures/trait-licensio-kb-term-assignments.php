<?php
/**
 * An in-memory store of which objects carry which terms.
 *
 * Moving objects between terms (a merge, a delete with reassignment, moving
 * articles to another category) runs through the same relationship writes
 * wherever it happens. Each suite that drives those moves needs them backed by
 * a store it can inspect afterwards, so the store lives here once.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

use Brain\Monkey\Functions;

/**
 * Backs get_objects_in_term() and the batched relationship writes with an array.
 *
 * A term's term taxonomy ID is its term ID plus 1000 here (see `tt_id_of()`),
 * so code that writes a term ID where a term taxonomy ID belongs fails.
 *
 * @since 1.0.0
 */
trait Licensio_KB_Term_Assignments {

    /**
     * Term ID to the object IDs assigned to it.
     *
     * @since 1.0.0
     * @var array<int, int[]>
     */
    protected $assignments = array();

    /**
     * Every change, in the order it happened.
     *
     * @since 1.0.0
     * @var string[]
     */
    protected $events = array();

    /**
     * Decides whether a write fails: called with the action, term ID and object IDs.
     *
     * @since 1.0.0
     * @var callable|null
     */
    protected $refuse_write = null;

    /**
     * The term taxonomy ID the store gives a term.
     *
     * @since 1.0.0
     *
     * @param int $term_id The term ID.
     *
     * @return int The term taxonomy ID.
     */
    protected static function tt_id_of( $term_id ) {
        return (int) $term_id + 1000;
    }

    /**
     * Route the assignment reads and writes to the store.
     *
     * Each write is logged as `assign 1,2 to 30` or `unassign 1,2 from 10`.
     * Caches and counts are stubbed out; the store has neither. A suite using
     * this unsets `$GLOBALS['wpdb']` in its `tearDown()`.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function stub_term_assignments() {
        $this->refuse_write = null;

        Functions\when( 'get_objects_in_term' )->alias(
            function ( $term_id ) {
                return array_map( 'strval', $this->assignments[ (int) $term_id ] );
            }
        );
        Functions\when( 'clean_object_term_cache' )->justReturn( null );
        Functions\when( 'wp_update_term_count' )->justReturn( true );

        $GLOBALS['wpdb'] = new Licensio_KB_Relationships_WPDB( // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The suite's database double.
            function ( $action, $tt_id, array $object_ids ) {
                $term_id = (int) $tt_id - 1000;

                $this->assertArrayHasKey( $term_id, $this->assignments, 'No term with term taxonomy ID ' . $tt_id . '.' );

                if ( null !== $this->refuse_write && call_user_func( $this->refuse_write, $action, $term_id, $object_ids ) ) {
                    return false;
                }

                if ( 'add' === $action ) {
                    $this->events[] = 'assign ' . implode( ',', $object_ids ) . ' to ' . $term_id;

                    foreach ( $object_ids as $object_id ) {
                        if ( ! in_array( $object_id, $this->assignments[ $term_id ], true ) ) {
                            $this->assignments[ $term_id ][] = $object_id;
                        }
                    }
                } else {
                    $this->events[]                = 'unassign ' . implode( ',', $object_ids ) . ' from ' . $term_id;
                    $this->assignments[ $term_id ] = array_values( array_diff( $this->assignments[ $term_id ], $object_ids ) );
                }

                return count( $object_ids );
            }
        );
    }
}
