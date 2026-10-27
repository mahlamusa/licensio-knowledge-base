<?php
/**
 * Shared term operations for the categories and tags apps.
 *
 * Moving article assignments from one term to another and merging terms are
 * needed by category delete with reassignment, category merge, tag merge and
 * tag conversion. Taking a term off all its articles before the term is
 * deleted is needed by category delete without reassignment and tag delete.
 * They live here once, so both REST controllers apply the same rules and fire
 * the same hooks.
 *
 * Every move is all or nothing and is recorded, so a caller whose later step
 * fails can put the articles back with `undo()`. The relationship rows move
 * in batches of SQL statements rather than through one core call per article,
 * so a term with thousands of articles moves well within a request's time
 * limit; caches and counts are refreshed after each move. Term counting is
 * deferred while articles move. `check_slug()` holds the rule both apps
 * follow when a term is edited to a slug another term already uses.
 *
 * Writing the rows directly would skip core's per-object relationship hooks,
 * so core's after-write hooks are fired for each object as its rows are
 * written: `added_term_relationship` ( $object_id, $tt_id, $taxonomy ) and
 * `deleted_term_relationships` ( $object_id, array( $tt_id ), $taxonomy ).
 * `set_object_terms` is not fired, since no object's whole term list is set.
 * `licensio_kb_term_assignments_changed` ( int[] $object_ids, string
 * $taxonomy ) also fires, once per taxonomy for every move, merge, removal or undo, so
 * the search index can refresh the articles that changed.
 *
 * A move rewrites articles the user may not be able to open, so the REST
 * routes ask `check_articles()` first: every article filed under the source
 * terms must be one the user may edit and nobody else has open in the editor,
 * or nothing is moved and the error names the articles.
 *
 * Two hooks form the contract the per-category article order relies on:
 *
 * - `licensio_kb_terms_merged` ( int[] $sources, int $target, string $taxonomy )
 *   fires after the assignments have moved and before the sources are deleted,
 *   so a listener can still read data keyed on the source terms.
 * - `licensio_kb_term_removed` ( int $term_id, string $taxonomy ) fires whenever
 *   a `licensio_category` term is deleted, by this class or by anything else.
 *
 * Consumed by the categories app, the tags app, the duplicates tool's term
 * merge and the per-category article order.
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
     * How many relationship rows one statement writes.
     *
     * Keeps each statement well under the server's packet limit while a term
     * with thousands of articles still moves in a handful of statements.
     *
     * @since 1.0.0
     * @var int
     */
    const BATCH_SIZE = 500;

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
     * Each object gains the target term and loses the source term. The
     * relationship rows are written in batches, then the objects' term caches
     * are cleaned and both terms are recounted, once each. An object that
     * already has the target term simply loses the source term. The source
     * term itself is left in place.
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

        return $this->move_all( array( $from ), $taxonomy, $to, $taxonomy, $record );
    }

    /**
     * Move every object assigned to a term onto a term of another taxonomy.
     *
     * Used to turn a tag into a category: each article with the tag gains
     * the category and loses the tag, in batches as in `move_assignments()`.
     * Taking the tag off here, rather than leaving it for the tag's delete,
     * matters at scale: core's `wp_delete_term()` rewrites the terms of every
     * object still assigned, one object at a time.
     *
     * All or nothing, as `move_assignments()`: if one object fails, the
     * objects already moved are put back, and on success `$record` receives
     * what was moved, for `undo()`.
     *
     * No capability is checked here. Callers must check the current user's
     * capability first.
     *
     * @since 1.0.0
     *
     * @param int        $from          The term whose objects move.
     * @param string     $from_taxonomy The source term's taxonomy.
     * @param int        $to            The term the objects gain.
     * @param string     $to_taxonomy   The target term's taxonomy.
     * @param array|null $record        Optional. Receives the moves made, for `undo()`; empty when nothing moved.
     *
     * @return int|WP_Error The number of objects moved, or an error when an input is invalid or a write fails.
     */
    public function move_across_taxonomies( $from, $from_taxonomy, $to, $to_taxonomy, &$record = null ) {
        $record = array();
        $from   = absint( $from );
        $to     = absint( $to );

        $valid = $this->validate( array( $from ), $from_taxonomy, $to, $to_taxonomy );

        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        return $this->move_all( array( $from ), $from_taxonomy, $to, $to_taxonomy, $record );
    }

    /**
     * Take a term off every object assigned to it.
     *
     * For deleting a term whose objects are not moved elsewhere: a category
     * deleted with its articles left without it, or a deleted tag. Core's
     * `wp_delete_term()` would rewrite the terms of every assigned object one
     * object at a time; here the relationship rows are deleted in batches, as
     * in `move_assignments()`, so the delete that follows finds the term
     * empty. The term itself is left in place.
     *
     * All or nothing: if a batch fails, the objects already handled get the
     * term back and the error is returned. On success `$record` receives what
     * was removed, so a caller whose own later step fails (the term's delete)
     * can put the objects back with `undo()`.
     *
     * No capability is checked here. Callers must check the current user's
     * capability first, and `check_articles()` for the objects.
     *
     * @since 1.0.0
     *
     * @param int        $term_id  The term to take off its objects.
     * @param string     $taxonomy The term's taxonomy.
     * @param array|null $record   Optional. Receives the removal made, for `undo()`; empty when nothing was removed.
     *
     * @return int|WP_Error The number of objects that lost the term, or an error when an input is invalid or a write fails.
     */
    public function remove_assignments( $term_id, $taxonomy, &$record = null ) {
        $record  = array();
        $term_id = absint( $term_id );

        $valid = $this->validate( array( $term_id ), $taxonomy, null, $taxonomy );

        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        return $this->move_all( array( $term_id ), $taxonomy, 0, $taxonomy, $record );
    }

    /**
     * Put back the assignments a move or merge recorded.
     *
     * Works through the record newest first: the objects that lost the source
     * term get it back, and the objects that gained the target term lose it
     * again; an object that had the target before the move keeps it. A
     * removal, which has no target, only gives the term back. Best
     * effort: a write that fails here is skipped, since the caller is already
     * reporting the error that made it undo. Caches and counts are refreshed
     * for every object in the record.
     *
     * @since 1.0.0
     *
     * @param array<int, array<string, mixed>> $record The record `move_assignments()`, `move_across_taxonomies()` or `remove_assignments()` filled.
     *
     * @return void
     */
    public function undo( array $record ) {
        $this->with_deferred_counting(
            function () use ( $record ) {
                foreach ( array_reverse( $record ) as $entry ) {
                    $restored = array();
                    $dropped  = array();

                    $this->write_relationships( 'add', $entry['removed'], $entry['from_tt'], $entry['from_taxonomy'], $restored );
                    $this->write_relationships( 'remove', $entry['added'], $entry['to_tt'], $entry['to_taxonomy'], $dropped );
                }

                $this->refresh( $record );

                return null;
            }
        );
    }

    /**
     * Move the objects of several checked terms onto one term.
     *
     * The inputs must already have passed `validate()`. Term counting is
     * deferred throughout, the undo included, so a failure recounts once. If
     * a write fails, every object already handled is put back, `$record` is
     * emptied and the error is returned. On success the objects' caches and
     * the terms' counts are refreshed.
     *
     * @since 1.0.0
     *
     * @param int[]  $sources         The terms to take objects from.
     * @param string $source_taxonomy The sources' taxonomy.
     * @param int    $to              The term the objects gain, or 0 for none.
     * @param string $target_taxonomy The target's taxonomy.
     * @param array  $record          Receives one entry per source term handled.
     *
     * @return int|WP_Error The number of objects handled, or the first error.
     */
    private function move_all( array $sources, $source_taxonomy, $to, $target_taxonomy, &$record ) {
        $record = array();

        return $this->with_deferred_counting(
            function () use ( $sources, $source_taxonomy, $to, $target_taxonomy, &$record ) {
                $moved = 0;

                foreach ( $sources as $from ) {
                    $count = $this->move_objects( $from, $source_taxonomy, $to, $target_taxonomy, $record );

                    if ( is_wp_error( $count ) ) {
                        $this->undo( $record );
                        $record = array();

                        return $count;
                    }

                    $moved += $count;
                }

                $this->refresh( $record );

                return $moved;
            }
        );
    }

    /**
     * Move every object from one checked term to another.
     *
     * The target relationships are written first, then the source ones are
     * removed, in batches. The record entry is added before anything is
     * written and grows with each batch that succeeds, so the objects of a
     * batch already written are put back too when a later batch fails.
     * With no target the objects only lose the source term, and the entry
     * records 0 as the target.
     *
     * @since 1.0.0
     * @since 1.0.0 Takes 0 as the target, for a removal.
     *
     * @param int    $from            The term to take objects from.
     * @param string $source_taxonomy The source's taxonomy.
     * @param int    $to              The term the objects gain, or 0 for none.
     * @param string $target_taxonomy The target's taxonomy.
     * @param array  $record          Receives one entry for this source term.
     *
     * @return int|WP_Error The number of objects handled, or an error when a read or write fails.
     */
    private function move_objects( $from, $source_taxonomy, $to, $target_taxonomy, &$record ) {
        $object_ids = get_objects_in_term( $from, $source_taxonomy );

        if ( is_wp_error( $object_ids ) ) {
            return $object_ids;
        }

        $on_target = $to ? get_objects_in_term( $to, $target_taxonomy ) : array();

        if ( is_wp_error( $on_target ) ) {
            return $on_target;
        }

        $object_ids = array_values( array_unique( array_map( 'intval', (array) $object_ids ) ) );

        if ( array() === $object_ids ) {
            return 0;
        }

        $from_term = get_term( $from, $source_taxonomy );
        $to_term   = $to ? get_term( $to, $target_taxonomy ) : null;

        if ( ! $from_term instanceof WP_Term || ( $to && ! $to_term instanceof WP_Term ) ) {
            return new WP_Error(
                'licensio_kb_invalid_term',
                __( 'One of the chosen terms does not exist.', 'licensio-knowledge-base' ),
                array( 'status' => 404 )
            );
        }

        $index            = count( $record );
        $record[ $index ] = array(
            'from'          => (int) $from,
            'from_tt'       => (int) $from_term->term_taxonomy_id,
            'from_taxonomy' => (string) $source_taxonomy,
            'to'            => (int) $to,
            'to_tt'         => $to_term instanceof WP_Term ? (int) $to_term->term_taxonomy_id : 0,
            'to_taxonomy'   => (string) $target_taxonomy,
            'added'         => array(),
            'removed'       => array(),
        );

        $missing = $to ? array_values( array_diff( $object_ids, array_map( 'intval', (array) $on_target ) ) ) : array();
        $written = $this->write_relationships( 'add', $missing, $record[ $index ]['to_tt'], (string) $target_taxonomy, $record[ $index ]['added'] );

        if ( is_wp_error( $written ) ) {
            return $written;
        }

        $written = $this->write_relationships( 'remove', $object_ids, $record[ $index ]['from_tt'], (string) $source_taxonomy, $record[ $index ]['removed'] );

        if ( is_wp_error( $written ) ) {
            return $written;
        }

        return count( $object_ids );
    }

    /**
     * Add or remove one term's relationship rows for many objects.
     *
     * One statement per batch of `BATCH_SIZE` objects, prepared with the
     * object and term taxonomy IDs as integers. Stops at the first statement
     * that fails; `$done` holds the objects of the batches already written.
     * After each batch, core's `added_term_relationship` or
     * `deleted_term_relationships` fires for each of its objects, as core's
     * own term writes do.
     *
     * @since 1.0.0 Fires core's relationship hooks.
     *
     * @param string $action     `add` to insert the rows, `remove` to delete them.
     * @param int[]  $object_ids The objects.
     * @param int    $tt_id      The term taxonomy ID.
     * @param string $taxonomy   The term's taxonomy, for the hooks.
     * @param int[]  $done       Receives the objects whose rows were written.
     *
     * @return true|WP_Error True when every batch was written, or an error.
     */
    private function write_relationships( $action, array $object_ids, $tt_id, $taxonomy, &$done ) {
        global $wpdb;

        $done = array();

        foreach ( array_chunk( array_map( 'intval', $object_ids ), self::BATCH_SIZE ) as $batch ) {
            if ( 'add' === $action ) {
                $values = array();

                foreach ( $batch as $object_id ) {
                    $values[] = $object_id;
                    $values[] = (int) $tt_id;
                }

                $rows = implode( ', ', array_fill( 0, count( $batch ), '( %d, %d )' ) );

                // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $rows holds only %d placeholders, one pair per object.
                $sql = $wpdb->prepare(
                    "INSERT IGNORE INTO %i ( object_id, term_taxonomy_id ) VALUES {$rows}",
                    array_merge( array( $wpdb->term_relationships ), $values )
                );
                // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            } else {
                $sql = $wpdb->prepare(
                    'DELETE FROM %i WHERE term_taxonomy_id = %d AND object_id IN ( ' . implode( ', ', array_fill( 0, count( $batch ), '%d' ) ) . ' )',
                    array_merge( array( $wpdb->term_relationships, (int) $tt_id ), $batch )
                );
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
            if ( false === $wpdb->query( $sql ) ) {
                return new WP_Error(
                    'licensio_kb_assignments_not_saved',
                    __( 'The article assignments could not be saved.', 'licensio-knowledge-base' ),
                    array( 'status' => 500 )
                );
            }

            foreach ( $batch as $object_id ) {
                if ( 'add' === $action ) {
                    /** This action is documented in wp-includes/taxonomy.php */
                    do_action( 'added_term_relationship', $object_id, (int) $tt_id, $taxonomy ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's own hook, fired as core's term writes fire it.
                } else {
                    /** This action is documented in wp-includes/taxonomy.php */
                    do_action( 'deleted_term_relationships', $object_id, array( (int) $tt_id ), $taxonomy ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's own hook, fired as core's term writes fire it.
                }
            }

            $done = array_merge( $done, $batch );
        }

        return true;
    }

    /**
     * Refresh caches, counts and listeners after recorded writes.
     *
     * Cleans the term caches of every object in the record, recounts each
     * term once (queued while counting is deferred) and fires
     * `licensio_kb_term_assignments_changed` once per taxonomy.
     *
     * @since 1.0.0
     * @since 1.0.0 Leaves out the zero a removal records in place of a target term.
     *
     * @param array<int, array<string, mixed>> $record The record of the writes.
     *
     * @return void
     */
    private function refresh( array $record ) {
        $objects = array();
        $terms   = array();

        foreach ( $record as $entry ) {
            $objects[ $entry['from_taxonomy'] ] = array_merge( isset( $objects[ $entry['from_taxonomy'] ] ) ? $objects[ $entry['from_taxonomy'] ] : array(), $entry['removed'] );
            $objects[ $entry['to_taxonomy'] ]   = array_merge( isset( $objects[ $entry['to_taxonomy'] ] ) ? $objects[ $entry['to_taxonomy'] ] : array(), $entry['added'] );

            $terms[ $entry['from_taxonomy'] ][] = (int) $entry['from_tt'];
            $terms[ $entry['to_taxonomy'] ][]   = (int) $entry['to_tt'];
        }

        foreach ( $objects as $taxonomy => $object_ids ) {
            $object_ids = array_values( array_unique( $object_ids ) );

            // A removal records no target term, so its zero is left out.
            wp_update_term_count( array_values( array_unique( array_filter( $terms[ $taxonomy ] ) ) ), $taxonomy );

            if ( array() === $object_ids ) {
                continue;
            }

            clean_object_term_cache( $object_ids, Licensio_KB_Post_Types::POST_TYPE );

            /**
             * Fires after term assignments were written directly to the database.
             *
             * Moves, copies and merges write the relationship rows in batches
             * instead of through `wp_set_object_terms()`, so core's per-object
             * hooks do not fire. This one does, once per taxonomy.
             *
             * @since 1.0.0
             *
             * @param int[]  $object_ids The objects that gained or lost a term.
             * @param string $taxonomy   The taxonomy whose assignments changed.
             */
            do_action( 'licensio_kb_term_assignments_changed', $object_ids, (string) $taxonomy );
        }
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
     * Check that the current user may rewrite every article filed under some terms.
     *
     * Each article must be one the user may edit (`edit_post`), and nobody
     * else may have it open in the editor, whose next save would quietly put
     * the old terms back. A user who may edit every article, published and
     * private, others' included, skips the per-article capability check;
     * the edit locks are read in one query per batch either way.
     *
     * @since 1.0.0
     *
     * @param int[]  $term_ids The terms whose articles would move.
     * @param string $taxonomy Their taxonomy.
     *
     * @return true|WP_Error True when every article may be moved; 403 `licensio_kb_articles_forbidden` or 409 `licensio_kb_articles_locked` naming the articles otherwise.
     */
    public function check_articles( array $term_ids, $taxonomy ) {
        $object_ids = array();

        foreach ( array_filter( array_map( 'absint', $term_ids ) ) as $term_id ) {
            $found = get_objects_in_term( $term_id, $taxonomy );

            if ( is_wp_error( $found ) ) {
                return $found;
            }

            $object_ids = array_merge( $object_ids, array_map( 'intval', (array) $found ) );
        }

        $object_ids = array_values( array_unique( $object_ids ) );

        if ( array() === $object_ids ) {
            return true;
        }

        $forbidden = array();
        $edits_all = current_user_can( Licensio_KB_Article_Bulk::type_cap( 'edit_others_posts' ) )
            && current_user_can( Licensio_KB_Article_Bulk::type_cap( 'edit_published_posts' ) )
            && current_user_can( Licensio_KB_Article_Bulk::type_cap( 'edit_private_posts' ) );

        if ( ! $edits_all ) {
            foreach ( array_chunk( $object_ids, self::BATCH_SIZE ) as $batch ) {
                _prime_post_caches( $batch, false, false );

                foreach ( $batch as $object_id ) {
                    if ( ! current_user_can( 'edit_post', $object_id ) ) {
                        $forbidden[] = $object_id;
                    }
                }
            }
        }

        if ( ! empty( $forbidden ) ) {
            return new WP_Error(
                'licensio_kb_articles_forbidden',
                __( 'You are not allowed to edit some of the articles this would move.', 'licensio-knowledge-base' ),
                array(
                    'status' => rest_authorization_required_code(),
                    'ids'    => $forbidden,
                )
            );
        }

        $locked = $this->locked_objects( $object_ids );

        if ( ! empty( $locked ) ) {
            return new WP_Error(
                'licensio_kb_articles_locked',
                _n(
                    'Someone is editing an article this would move. Try again when they have finished.',
                    'Someone is editing articles this would move. Try again when they have finished.',
                    count( $locked ),
                    'licensio-knowledge-base'
                ),
                array(
                    'status' => 409,
                    'ids'    => $locked,
                )
            );
        }

        return true;
    }

    /**
     * The objects another user holds the edit lock on.
     *
     * Reads `_edit_lock` the way `wp_check_post_lock()` does, but for a batch
     * of objects in one query rather than loading every object's meta.
     *
     * @since 1.0.0
     *
     * @param int[] $object_ids The objects.
     *
     * @return int[] The locked objects.
     */
    private function locked_objects( array $object_ids ) {
        global $wpdb;

        /** This filter is documented in wp-admin/includes/ajax-actions.php */
        $window = (int) apply_filters( 'wp_check_post_lock_window', 150 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's own filter, read as wp_check_post_lock() reads it.
        $user   = get_current_user_id();
        $locked = array();

        foreach ( array_chunk( $object_ids, self::BATCH_SIZE ) as $batch ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One read per batch instead of loading every article's meta.
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT post_id, meta_value FROM %i WHERE meta_key = %s AND post_id IN ( ' . implode( ', ', array_fill( 0, count( $batch ), '%d' ) ) . ' )',
                    array_merge( array( $wpdb->postmeta, '_edit_lock' ), $batch )
                )
            );

            foreach ( (array) $rows as $row ) {
                $parts = explode( ':', (string) $row->meta_value );
                $time  = (int) $parts[0];
                $owner = isset( $parts[1] ) ? (int) $parts[1] : 0;

                if ( $owner > 0 && $owner !== $user && $time > time() - $window ) {
                    $locked[] = (int) $row->post_id;
                }
            }
        }

        return array_values( array_unique( $locked ) );
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
        $moved  = $this->move_all( $sources, $taxonomy, $target, $taxonomy, $record );

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
     * @since 1.0.0 Takes null as the target, for a removal.
     *
     * @param int[]    $sources         The source term IDs.
     * @param string   $source_taxonomy The sources' taxonomy.
     * @param int|null $target          The target term ID, or null for none.
     * @param string   $target_taxonomy The target's taxonomy.
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

        $terms = null === $target ? array() : array( array( $target, $target_taxonomy ) );

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
