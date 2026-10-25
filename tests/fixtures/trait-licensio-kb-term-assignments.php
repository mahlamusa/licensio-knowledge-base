<?php
/**
 * An in-memory store of which objects carry which terms.
 *
 * Moving objects between terms (a merge, a delete with reassignment, moving
 * articles to another category) runs through the same three core functions
 * wherever it happens. Each suite that drives those moves needs them backed by
 * a store it can inspect afterwards, so the store lives here once.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

use Brain\Monkey\Functions;

/**
 * Backs get_objects_in_term(), wp_set_object_terms() and
 * wp_remove_object_terms() with an array.
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
     * Route the three assignment functions to the store.
     *
     * A move must add to an object's terms rather than replace them, so a call
     * to wp_set_object_terms() without `$append` fails the test.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function stub_term_assignments() {
        Functions\when( 'get_objects_in_term' )->alias(
            function ( $term_id ) {
                return array_map( 'strval', $this->assignments[ (int) $term_id ] );
            }
        );
        Functions\when( 'wp_set_object_terms' )->alias(
            function ( $object_id, $terms, $taxonomy, $append ) {
                unset( $taxonomy );
                $this->assertTrue( $append, 'A move must not drop the other terms of the object.' );

                foreach ( $terms as $term_id ) {
                    $this->events[] = 'assign ' . $object_id . ' to ' . $term_id;

                    if ( ! in_array( $object_id, $this->assignments[ $term_id ], true ) ) {
                        $this->assignments[ $term_id ][] = $object_id;
                    }
                }

                return array();
            }
        );
        Functions\when( 'wp_remove_object_terms' )->alias(
            function ( $object_id, $terms ) {
                foreach ( $terms as $term_id ) {
                    $this->events[]                = 'unassign ' . $object_id . ' from ' . $term_id;
                    $this->assignments[ $term_id ] = array_values( array_diff( $this->assignments[ $term_id ], array( $object_id ) ) );
                }

                return true;
            }
        );
    }
}
