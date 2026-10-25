<?php
/**
 * Shared term operations for the categories and tags apps.
 *
 * Moving article assignments from one term to another and merging terms are
 * needed by category delete with reassignment, category merge, tag merge and
 * tag conversion. They live here once, so both REST controllers apply the same
 * rules and fire the same hooks.
 *
 * Every move is all or nothing and is recorded, so a caller whose later step
 * fails can put the articles back with `undo()`. Term counting is deferred
 * while articles move. `check_slug()` holds the rule both apps follow when a
 * term is edited to a slug another term already uses.
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
     * `wp_set_object_terms()` and `wp_remove_object_terms()`, so caches are
     * kept up to date by core. Term counting is deferred while the objects
     * move, so each term is recounted once rather than once per object. An
     * object that already has the target term simply loses the source term.
     * The source term itself is left in place.
     *
     * All or nothing: if one object cannot be moved, the objects already
     * moved are put back and the error is returned. On success `$record`
     * receives what was moved, so a caller whose own later step fails can put
     * the objects back with `undo()`.
     *
     * No capability is checked here. Callers must check the current user's
     * capability first, for example through the REST controller's permission
     * callback.
     *
     * @since 1.0.0
     *
     * @param int        $from     The term to move assignments off.
     * @param int        $to       The term to move assignments onto.
     * @param string     $taxonomy The taxonomy both terms belong to.
     * @param array|null $record   Optional. Receives the moves made, for `undo()`; empty when nothing moved.
     *
     * @return int|WP_Error The number of objects moved, or an error when an input is invalid or a write fails.
     */
    public function move_assignments( $from, $to, $taxonomy, &$record = null ) {
        $record = array();
        $from   = absint( $from );
        $to     = absint( $to );

        $valid = $this->validate( array( $from ), $taxonomy, $to, $taxonomy );

        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        return $this->move_all( array( $from ), $taxonomy, $to, $taxonomy, true, $record );
    }

    /**
     * Give every object assigned to a term a term of another taxonomy too.
     *
     * Used to turn a tag into a category: each article with the tag gains
     * the category. The source assignments are left alone; deleting the
     * source term afterwards removes them. Term counting is deferred as in
     * `move_assignments()`.
     *
     * All or nothing, as `move_assignments()`: if one object fails, the ones
     * already given the target lose it again, and on success `$record`
     * receives what was added, for `undo()`.
     *
     * No capability is checked here. Callers must check the current user's
     * capability first.
     *
     * @since 1.0.0
     *
     * @param int        $from          The term whose objects are copied.
     * @param string     $from_taxonomy The source term's taxonomy.
     * @param int        $to            The term the objects gain.
     * @param string     $to_taxonomy   The target term's taxonomy.
     * @param array|null $record        Optional. Receives the assignments added, for `undo()`; empty when nothing was added.
     *
     * @return int|WP_Error The number of objects the target was given, or an error when an input is invalid or a write fails.
     */
    public function copy_assignments( $from, $from_taxonomy, $to, $to_taxonomy, &$record = null ) {
        $record = array();
        $from   = absint( $from );
        $to     = absint( $to );

        $valid = $this->validate( array( $from ), $from_taxonomy, $to, $to_taxonomy );

        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        return $this->move_all( array( $from ), $from_taxonomy, $to, $to_taxonomy, false, $record );
    }

    /**
     * Put back the assignments a move, copy or merge recorded.
     *
     * Works through the record newest first: an object gets its source term
     * back if it lost it, and loses the target term unless it had the target
     * before the move. Best effort: a write that fails here is skipped, since
     * the caller is already reporting the error that made it undo.
     *
     * @since 1.0.0
     *
     * @param array<int, array<string, mixed>> $record The record `move_assignments()` or `copy_assignments()` filled.
     *
     * @return void
     */
    public function undo( array $record ) {
        $this->with_deferred_counting(
            function () use ( $record ) {
                foreach ( array_reverse( $record ) as $entry ) {
                    if ( $entry['removed'] ) {
                        wp_set_object_terms( $entry['object'], array( $entry['from'] ), $entry['from_taxonomy'], true );
                    }

                    if ( ! $entry['had_target'] ) {
                        wp_remove_object_terms( $entry['object'], array( $entry['to'] ), $entry['to_taxonomy'] );
                    }
                }

                return null;
            }
        );
    }

    /**
     * Move or copy the objects of several checked terms onto one term.
     *
     * The inputs must already have passed `validate()`. Term counting is
     * deferred throughout, the undo included, so a failure recounts once. If
     * a write fails, every object already handled is put back, `$record` is
     * emptied and the error is returned.
     *
     * @since 1.0.0
     *
     * @param int[]  $sources         The terms to take objects from.
     * @param string $source_taxonomy The sources' taxonomy.
     * @param int    $to              The term the objects gain.
     * @param string $target_taxonomy The target's taxonomy.
     * @param bool   $remove          True to take the source term off each object, false to leave it.
     * @param array  $record          Receives one entry per object handled.
     *
     * @return int|WP_Error The number of objects handled, or the first error.
     */
    private function move_all( array $sources, $source_taxonomy, $to, $target_taxonomy, $remove, &$record ) {
        $record = array();

        return $this->with_deferred_counting(
            function () use ( $sources, $source_taxonomy, $to, $target_taxonomy, $remove, &$record ) {
                $moved = 0;

                foreach ( $sources as $from ) {
                    $count = $this->move_objects( $from, $source_taxonomy, $to, $target_taxonomy, $remove, $record );

                    if ( is_wp_error( $count ) ) {
                        $this->undo( $record );
                        $record = array();

                        return $count;
                    }

                    $moved += $count;
                }

                return $moved;
            }
        );
    }

    /**
     * Move or copy every object from one checked term to another.
     *
     * Each object is recorded as soon as it gains the target, before the
     * source is removed, so an object whose removal fails is put back too.
     *
     * @since 1.0.0
     *
     * @param int    $from            The term to take objects from.
     * @param string $source_taxonomy The source's taxonomy.
     * @param int    $to              The term the objects gain.
     * @param string $target_taxonomy The target's taxonomy.
     * @param bool   $remove          True to take the source term off each object.
     * @param array  $record          Receives one entry per object handled.
     *
     * @return int|WP_Error The number of objects handled, or an error when a write fails.
     */
    private function move_objects( $from, $source_taxonomy, $to, $target_taxonomy, $remove, &$record ) {
        $object_ids = get_objects_in_term( $from, $source_taxonomy );

        if ( is_wp_error( $object_ids ) ) {
            return $object_ids;
        }

        $on_target = get_objects_in_term( $to, $target_taxonomy );

        if ( is_wp_error( $on_target ) ) {
            return $on_target;
        }

        $had_target = array_flip( array_map( 'intval', (array) $on_target ) );
        $moved      = 0;

        foreach ( (array) $object_ids as $object_id ) {
            $object_id = (int) $object_id;

            $added = wp_set_object_terms( $object_id, array( $to ), $target_taxonomy, true );

            if ( is_wp_error( $added ) ) {
                return $added;
            }

            $index            = count( $record );
            $record[ $index ] = array(
                'object'        => $object_id,
                'from'          => (int) $from,
                'from_taxonomy' => (string) $source_taxonomy,
                'to'            => (int) $to,
                'to_taxonomy'   => (string) $target_taxonomy,
                'had_target'    => isset( $had_target[ $object_id ] ),
                'removed'       => false,
            );

            if ( $remove ) {
                $removed = wp_remove_object_terms( $object_id, array( $from ), $source_taxonomy );

                if ( is_wp_error( $removed ) ) {
                    return $removed;
                }

                $record[ $index ]['removed'] = true;
            }

            ++$moved;
        }

        return $moved;
    }

    /**
     * Run a callback with term counting deferred.
     *
     * Core's deferral is one switch, not a counter, so a caller that already
     * deferred counting (the tag conversion loop does) keeps it deferred: the
     * switch is only turned off again, which recounts, if this method turned
     * it on. It is turned off in a `finally` block, so counting comes back
     * even when the callback (or a hook it triggers) throws.
     *
     * @since 1.0.0
     *
     * @param callable $callback The work to run.
     *
     * @return mixed What the callback returned.
     * @throws Throwable Whatever the callback throws, after counting is restored.
     */
    private function with_deferred_counting( $callback ) {
        $was_deferred = wp_defer_term_counting();

        if ( ! $was_deferred ) {
            wp_defer_term_counting( true );
        }

        try {
            return $callback();
        } finally {
            if ( ! $was_deferred ) {
                wp_defer_term_counting( false );
            }
        }
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
     * Every input is checked before anything is written, and the moves are
     * all or nothing: if one fails, every assignment already moved is put
     * back, the hook does not fire, nothing is deleted and the error is
     * returned. Once the hook has fired the merge is not undone: a source
     * that cannot be deleted is left out of `deleted`, empty.
     *
     * Child terms are not re-parented here. Core's `wp_delete_term()` moves a
     * deleted term's children to that term's own parent; a caller that wants
     * them under the target re-parents them before calling this.
     *
     * The target is ignored if it also appears among the sources.
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
        $sources = array_values( array_diff( array_unique( array_filter( array_map( 'absint', $sources ) ) ), array( $target ) ) );

        if ( empty( $sources ) ) {
            return new WP_Error(
                'licensio_kb_no_sources',
                __( 'Choose at least one term to merge.', 'licensio-knowledge-base' ),
                array( 'status' => 400 )
            );
        }

        $valid = $this->validate( $sources, $taxonomy, $target, $taxonomy );

        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        $record = array();
        $moved  = $this->move_all( $sources, $taxonomy, $target, $taxonomy, true, $record );

        if ( is_wp_error( $moved ) ) {
            return $moved;
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
     * Refuse a slug that another term of the taxonomy already uses.
     *
     * For editing a term. Core makes a taken slug unique when a term is added,
     * but refuses one on an edit, except when the edit also changes the
     * parent, where it quietly picks another. Both apps check here first, so
     * an edited slug is either saved as typed or refused with a clear
     * message, never changed behind the user's back. An empty slug, which
     * core makes from the name, and the term's own slug are always allowed.
     *
     * @since 1.0.0
     *
     * @param int    $term_id  The term being edited.
     * @param string $slug     The slug it would get, already sanitised.
     * @param string $taxonomy The taxonomy.
     *
     * @return true|WP_Error True when the slug is free, a 409 error naming it otherwise.
     */
    public function check_slug( $term_id, $slug, $taxonomy ) {
        $slug = (string) $slug;

        if ( '' === $slug ) {
            return true;
        }

        $owner = get_term_by( 'slug', $slug, $taxonomy );

        if ( ! $owner instanceof WP_Term || (int) $owner->term_id === (int) $term_id ) {
            return true;
        }

        $message = Licensio_KB_Post_Types::TAXONOMY_TAG === $taxonomy
            /* translators: %s: the slug that is taken. */
            ? __( 'Another tag already uses the slug “%s”. Choose a different slug.', 'licensio-knowledge-base' )
            /* translators: %s: the slug that is taken. */
            : __( 'Another category already uses the slug “%s”. Choose a different slug.', 'licensio-knowledge-base' );
        $message = sprintf( $message, $slug );

        return new WP_Error(
            'licensio_kb_slug_taken',
            $message,
            array(
                'status' => 409,
                'params' => array( 'slug' => $message ),
            )
        );
    }

    /**
     * Check the taxonomies and the terms an operation names.
     *
     * @since 1.0.0
     *
     * @param int[]  $sources         The source term IDs.
     * @param string $source_taxonomy The sources' taxonomy.
     * @param int    $target          The target term ID.
     * @param string $target_taxonomy The target's taxonomy.
     *
     * @return true|WP_Error True when every input is usable, an error naming the first problem otherwise.
     */
    private function validate( array $sources, $source_taxonomy, $target, $target_taxonomy ) {
        $taxonomies = array( Licensio_KB_Post_Types::TAXONOMY_CATEGORY, Licensio_KB_Post_Types::TAXONOMY_TAG );

        if ( ! in_array( $source_taxonomy, $taxonomies, true ) || ! in_array( $target_taxonomy, $taxonomies, true ) ) {
            return new WP_Error(
                'licensio_kb_invalid_taxonomy',
                __( 'That taxonomy is not a knowledge base taxonomy.', 'licensio-knowledge-base' ),
                array( 'status' => 400 )
            );
        }

        if ( $source_taxonomy === $target_taxonomy && in_array( $target, $sources, true ) ) {
            return new WP_Error(
                'licensio_kb_same_term',
                __( 'A term cannot be moved onto itself.', 'licensio-knowledge-base' ),
                array( 'status' => 400 )
            );
        }

        $terms = array( array( $target, $target_taxonomy ) );

        foreach ( $sources as $source ) {
            $terms[] = array( $source, $source_taxonomy );
        }

        foreach ( $terms as $term ) {
            if ( ! get_term( $term[0], $term[1] ) instanceof WP_Term ) {
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
