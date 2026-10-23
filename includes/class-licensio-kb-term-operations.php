<?php
/**
 * Shared term operations for the categories and tags apps.
 *
 * Moving article assignments from one term to another and merging terms are
 * needed by category delete with reassignment, category merge, tag merge and
 * tag conversion. They live here once, so both REST controllers apply the same
 * rules and fire the same hooks.
 *
 * Two hooks form the contract the per-category article order relies on:
 *
 * - `licensio_kb_terms_merged` ( int[] $sources, int $target, string $taxonomy )
 *   fires after the assignments have moved and before the sources are deleted,
 *   so a listener can still read data keyed on the source terms.
 * - `licensio_kb_term_removed` ( int $term_id, string $taxonomy ) fires whenever
 *   a `licensio_category` term is deleted, by this class or by anything else.
 *
 * Consumed by the categories app, the tags app and the per-category article
 * order.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Moves term assignments and merges terms in the plugin taxonomies.
 *
 * @since 1.0.0
 */
class Licensio_KB_Term_Operations {

    /**
     * Register the WordPress hooks.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function init_hooks() {
        add_action( 'delete_term', array( $this, 'announce_removal' ), 10, 3 );
    }

    /**
     * Fire `licensio_kb_term_removed` when a category is deleted.
     *
     * Hooked to core's `delete_term`, so the hook fires for a delete from the
     * Categories app, a merge, the classic screens, WP-CLI or another plugin.
     * Only `licensio_category` terms are announced.
     *
     * @since 1.0.0
     *
     * @param int    $term_id  The deleted term ID.
     * @param int    $tt_id    The deleted term taxonomy ID.
     * @param string $taxonomy The taxonomy the term belonged to.
     *
     * @return void
     */
    public function announce_removal( $term_id, $tt_id, $taxonomy ) {
        unset( $tt_id );

        if ( Licensio_KB_Post_Types::TAXONOMY_CATEGORY !== $taxonomy ) {
            return;
        }

        /**
         * Fires after a knowledge base category is deleted.
         *
         * @since 1.0.0
         *
         * @param int    $term_id  The deleted term ID.
         * @param string $taxonomy The taxonomy, `licensio_category`.
         */
        do_action( 'licensio_kb_term_removed', (int) $term_id, (string) $taxonomy );
    }

    /**
     * Move every object assigned to one term onto another.
     *
     * Each object gains the target term and loses the source term, through
     * `wp_set_object_terms()` and `wp_remove_object_terms()`, so term counts
     * and caches are kept up to date by core. An object that already has the
     * target term simply loses the source term. The source term itself is
     * left in place.
     *
     * No capability is checked here. Callers must check the current user's
     * capability first, for example through the REST controller's permission
     * callback.
     *
     * @since 1.0.0
     *
     * @param int    $from     The term to move assignments off.
     * @param int    $to       The term to move assignments onto.
     * @param string $taxonomy The taxonomy both terms belong to.
     *
     * @return int|WP_Error The number of objects moved, or an error when an input is invalid or a write fails.
     */
    public function move_assignments( $from, $to, $taxonomy ) {
        $from = absint( $from );
        $to   = absint( $to );

        $valid = $this->validate( array( $from ), $to, $taxonomy );

        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        $object_ids = get_objects_in_term( $from, $taxonomy );

        if ( is_wp_error( $object_ids ) ) {
            return $object_ids;
        }

        $moved = 0;

        foreach ( (array) $object_ids as $object_id ) {
            $object_id = (int) $object_id;

            $added = wp_set_object_terms( $object_id, array( $to ), $taxonomy, true );

            if ( is_wp_error( $added ) ) {
                return $added;
            }

            $removed = wp_remove_object_terms( $object_id, array( $from ), $taxonomy );

            if ( is_wp_error( $removed ) ) {
                return $removed;
            }

            ++$moved;
        }

        return $moved;
    }

    /**
     * Merge source terms into a target term.
     *
     * In order: every source's assignments move to the target, then
     * `licensio_kb_terms_merged` fires, then the sources are deleted. The hook
     * fires before the deletes on purpose: a listener that copies data keyed
     * on a source term (the per-category article order) must run before the
     * delete hooks clean that data up.
     *
     * Child terms are not re-parented here. Core's `wp_delete_term()` moves a
     * deleted term's children to that term's own parent; a caller that wants
     * them under the target re-parents them before calling this.
     *
     * Every input is checked before anything is written. The target is
     * ignored if it also appears among the sources.
     *
     * No capability is checked and the sources list is not capped here.
     * Callers must check the current user's capability first and limit how
     * many sources one request may send (for example with `maxItems` in the
     * REST argument schema), since every source is deleted.
     *
     * @since 1.0.0
     *
     * @param int[]  $sources  The terms to merge away.
     * @param int    $target   The term that remains.
     * @param string $taxonomy The taxonomy all the terms belong to.
     *
     * @return array{moved: int, deleted: int[]}|WP_Error How many assignments moved and which sources were deleted, or an error.
     */
    public function merge_terms( array $sources, $target, $taxonomy ) {
        $target  = absint( $target );
        $sources = array_values( array_unique( array_filter( array_map( 'absint', $sources ) ) ) );
        $sources = array_values( array_diff( $sources, array( $target ) ) );

        if ( empty( $sources ) ) {
            return new WP_Error(
                'licensio_kb_no_sources',
                __( 'Choose at least one term to merge.', 'licensio-knowledge-base' ),
                array( 'status' => 400 )
            );
        }

        $valid = $this->validate( $sources, $target, $taxonomy );

        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        $moved = 0;

        foreach ( $sources as $source ) {
            $count = $this->move_assignments( $source, $target, $taxonomy );

            if ( is_wp_error( $count ) ) {
                return $count;
            }

            $moved += $count;
        }

        /**
         * Fires after terms are merged, before the source terms are deleted.
         *
         * @since 1.0.0
         *
         * @param int[]  $sources  The merged term IDs, still present when this fires.
         * @param int    $target   The term the sources were merged into.
         * @param string $taxonomy The taxonomy.
         */
        do_action( 'licensio_kb_terms_merged', $sources, $target, $taxonomy );

        $deleted = array();

        foreach ( $sources as $source ) {
            if ( true === wp_delete_term( $source, $taxonomy ) ) {
                $deleted[] = $source;
            }
        }

        return array(
            'moved'   => $moved,
            'deleted' => $deleted,
        );
    }

    /**
     * Check a taxonomy and the terms an operation names.
     *
     * @since 1.0.0
     *
     * @param int[]  $sources  The source term IDs.
     * @param int    $target   The target term ID.
     * @param string $taxonomy The taxonomy.
     *
     * @return true|WP_Error True when every input is usable, an error naming the first problem otherwise.
     */
    private function validate( array $sources, $target, $taxonomy ) {
        $taxonomies = array( Licensio_KB_Post_Types::TAXONOMY_CATEGORY, Licensio_KB_Post_Types::TAXONOMY_TAG );

        if ( ! in_array( $taxonomy, $taxonomies, true ) ) {
            return new WP_Error(
                'licensio_kb_invalid_taxonomy',
                __( 'That taxonomy is not a knowledge base taxonomy.', 'licensio-knowledge-base' ),
                array( 'status' => 400 )
            );
        }

        if ( in_array( $target, $sources, true ) ) {
            return new WP_Error(
                'licensio_kb_same_term',
                __( 'A term cannot be moved onto itself.', 'licensio-knowledge-base' ),
                array( 'status' => 400 )
            );
        }

        foreach ( array_merge( $sources, array( $target ) ) as $term_id ) {
            if ( ! get_term( $term_id, $taxonomy ) instanceof WP_Term ) {
                return new WP_Error(
                    'licensio_kb_invalid_term',
                    __( 'One of the chosen terms does not exist.', 'licensio-knowledge-base' ),
                    array( 'status' => 404 )
                );
            }
        }

        return true;
    }
}
