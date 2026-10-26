<?php
/**
 * Per-category article order.
 *
 * An article's position in a category is stored as post meta
 * `licensio_order_{term_id}`, an integer where lower comes first. Each
 * category has its own key, so an article filed in three categories can sit
 * at a different place in each. The keys are written only by the order REST
 * route, a category merge, the importers and the Heroic migration tool, and
 * are not registered for core REST.
 *
 * This class owns the storage rules and the query support:
 *
 * - `orderby => licensio_order` on a post query limited to exactly one
 *   `licensio_category` sorts by that category's positions. Articles with a
 *   position come first, lowest first; articles without one follow. Ties,
 *   and the articles without a position, are ordered by `menu_order` (the
 *   Order an editor can set on the article), then title, then ID.
 *   `posts_clauses` adds one LEFT JOIN on the post meta table, limited to the
 *   category's key in its ON clause, and writes the ORDER BY. Nothing is
 *   filtered out, and no GROUP BY is needed: every writer uses
 *   `update_post_meta()`, so an article holds at most one row per key. Core
 *   cannot express "missing last" through `orderby` alone, since a missing
 *   position sorts as NULL, which MySQL puts first.
 * - `order => DESC` reverses the order, the `menu_order`, title and ID
 *   tie-breaks included; articles without a position still come last. Note
 *   that `get_posts()` defaults `order` to DESC, so a `get_posts()` caller
 *   that wants the curated order passes `ASC`.
 * - Any other query asking for `licensio_order` falls back to `menu_order`,
 *   then title.
 * - The main query of a category archive on the front end uses the order
 *   unless something else already chose an `orderby`. Searches and feeds
 *   keep their own order.
 * - A category merge appends each source category's order to the target's.
 * - The keys are protected meta, so they never show in the Custom Fields
 *   panel, where an author could otherwise move their own article.
 * - Deleting a category deletes its order keys.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stores, queries and maintains the per-category article order.
 *
 * @since 1.0.0
 */
class Licensio_KB_Article_Order {

    /**
     * The `orderby` value that sorts by the per-category order.
     *
     * @since 1.0.0
     * @var string
     */
    const ORDERBY = 'licensio_order';

    /**
     * The meta key prefix; the category term ID follows it.
     *
     * @since 1.0.0
     * @var string
     */
    const META_PREFIX = 'licensio_order_';

    /**
     * The alias of the post meta table joined on the category's key.
     *
     * Not one of core's `mt1`, `mt2`… meta query aliases, so it never collides.
     *
     * @since 1.0.0
     * @var string
     */
    const ALIAS = 'licensio_order_meta';

    /**
     * The query variable holding the category a query is ordered by.
     *
     * @since 1.0.0
     * @var string
     */
    const QUERY_VAR = 'licensio_order_term';

    /**
     * The query variable recording that the order is reversed.
     *
     * Read in `pre_get_posts`, because by `posts_clauses` core has turned an
     * empty `order` into DESC.
     *
     * @since 1.0.0
     * @var string
     */
    const DESC_VAR = 'licensio_order_desc';

    /**
     * The gap between two saved positions.
     *
     * @since 1.0.0
     * @var int
     */
    const STEP = 10;

    /**
     * How many positions one INSERT writes when a merge appends an order.
     *
     * @since 1.0.0
     * @var int
     */
    const INSERT_BATCH = 500;

    /**
     * Register the WordPress hooks.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function init_hooks() {
        add_action( 'pre_get_posts', array( $this, 'prepare_query' ) );
        add_filter( 'posts_clauses', array( $this, 'order_clauses' ), 10, 2 );
        add_filter( 'is_protected_meta', array( $this, 'protect_meta' ), 10, 3 );
        add_action( 'licensio_kb_terms_merged', array( $this, 'append_merged_order' ), 10, 3 );
        add_action( 'licensio_kb_term_removed', array( $this, 'delete_term_order' ), 10, 2 );
    }

    /**
     * The meta key holding positions in one category.
     *
     * @since 1.0.0
     *
     * @param int $term_id The category term ID.
     *
     * @return string The meta key.
     */
    public static function meta_key( $term_id ) {
        return self::META_PREFIX . absint( $term_id );
    }

    /**
     * Hide the order keys from the Custom Fields panel.
     *
     * Hooked to `is_protected_meta`. Positions are a manager's decision, saved
     * through the order route; without this an author could give their own
     * article any position through the Custom Fields panel. Protection only
     * affects that panel and core REST, so the plugin's own writes still work.
     *
     * @since 1.0.0
     *
     * @param bool   $is_protected Whether the key is protected.
     * @param string $meta_key     The meta key.
     * @param string $meta_type    The object type.
     *
     * @return bool True for a post's order key, otherwise unchanged.
     */
    public function protect_meta( $is_protected, $meta_key, $meta_type = '' ) {
        if ( 'post' === $meta_type && 0 === strpos( (string) $meta_key, self::META_PREFIX ) ) {
            return true;
        }

        return $is_protected;
    }

    /**
     * Translate `orderby => licensio_order` into query variables.
     *
     * Hooked to `pre_get_posts`. A front-end category archive's main query
     * with no `orderby` of its own is switched to the per-category order
     * first; a search or a feed on the archive is not.
     *
     * For a query limited to exactly one category, this records the category
     * in `licensio_order_term` and leaves `orderby` as `licensio_order`, which
     * is how `order_clauses()` knows nothing changed it since. An `order` of
     * DESC is recorded in `licensio_order_desc`; anything else, including no
     * `order` at all, is ascending. It also turns
     * `suppress_filters` off. That is strictly required: the JOIN and ORDER BY
     * are written in `posts_clauses`, and `get_posts()` sets
     * `suppress_filters`, which skips every `posts_*` filter. As a result, the
     * other plugins' `posts_*` filters run on these queries too. Any other
     * query falls back to `menu_order`, then title.
     *
     * @since 1.0.0
     *
     * @param WP_Query $query The query being prepared.
     *
     * @return void
     */
    public function prepare_query( $query ) {
        if ( ! $query instanceof WP_Query ) {
            return;
        }

        if ( $this->is_category_archive( $query ) && empty( $query->get( 'orderby' ) ) ) {
            $query->set( 'orderby', self::ORDERBY );
        }

        if ( self::ORDERBY !== $query->get( 'orderby' ) ) {
            return;
        }

        $term_id = self::single_category( $query );

        if ( $term_id <= 0 ) {
            $query->set(
                'orderby',
                array(
                    'menu_order' => 'ASC',
                    'title'      => 'ASC',
                )
            );

            return;
        }

        $query->set( self::QUERY_VAR, $term_id );
        $query->set( self::DESC_VAR, 'DESC' === strtoupper( (string) $query->get( 'order' ) ) );
        $query->set( 'suppress_filters', false );
    }

    /**
     * Write the JOIN and ORDER BY for a query prepared by `prepare_query()`.
     *
     * Hooked to `posts_clauses`. Does nothing unless `orderby` is still
     * `licensio_order`, so a later `pre_get_posts` callback that chose another
     * order, such as the search ranking by `post__in`, wins.
     *
     * @since 1.0.0
     *
     * @param array<string, string> $clauses The query clauses.
     * @param WP_Query              $query   The query.
     *
     * @return array<string, string> The clauses, with the JOIN added and the ORDER BY replaced when the query is ordered per category.
     */
    public function order_clauses( $clauses, $query ) {
        if ( ! is_array( $clauses ) || ! $query instanceof WP_Query ) {
            return $clauses;
        }

        $term_id = (int) $query->get( self::QUERY_VAR );

        if ( $term_id <= 0 || self::ORDERBY !== $query->get( 'orderby' ) ) {
            return $clauses;
        }

        $join = isset( $clauses['join'] ) ? (string) $clauses['join'] : '';

        $clauses['join']    = $join . ' ' . self::join_sql( $term_id );
        $clauses['orderby'] = self::orderby_sql( $query->get( self::DESC_VAR ) ? 'DESC' : 'ASC' );

        return $clauses;
    }

    /**
     * The LEFT JOIN on one category's key.
     *
     * The key is in the ON clause, so every article keeps exactly one row:
     * its position, or NULL when it has none.
     *
     * @since 1.0.0
     *
     * @param int $term_id The category term ID.
     *
     * @return string The JOIN clause.
     */
    public static function join_sql( $term_id ) {
        global $wpdb;

        return $wpdb->prepare(
            'LEFT JOIN %i AS %i ON ( %i.ID = %i.post_id AND %i.meta_key = %s )',
            $wpdb->postmeta,
            self::ALIAS,
            $wpdb->posts,
            self::ALIAS,
            self::ALIAS,
            self::meta_key( $term_id )
        );
    }

    /**
     * The ORDER BY expression for the per-category order.
     *
     * Ordered articles first, by position; then unordered ones; each group by
     * `menu_order`, then title, then ID, so an Order set on the article still
     * counts and pages never shuffle. Descending reverses the positions and
     * every tie-break, `menu_order` included, so the whole list reads in
     * reverse; unordered articles still come last.
     *
     * @since 1.0.0
     *
     * @param string $direction `ASC` or `DESC`.
     *
     * @return string The ORDER BY expression, without the keyword.
     */
    public static function orderby_sql( $direction = 'ASC' ) {
        global $wpdb;

        $direction = 'DESC' === $direction ? 'DESC' : 'ASC';

        return $wpdb->prepare(
            "%i.meta_value IS NULL ASC, CAST(%i.meta_value AS SIGNED) {$direction}, %i.menu_order {$direction}, %i.post_title {$direction}, %i.ID {$direction}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The direction is one of two literals.
            self::ALIAS,
            self::ALIAS,
            $wpdb->posts,
            $wpdb->posts,
            $wpdb->posts
        );
    }

    /**
     * The one category a query is limited to.
     *
     * Read from the parsed tax query. The query qualifies when it has exactly
     * one `licensio_category` clause, that clause names exactly one term with
     * the `IN` operator, and no clause is joined by `OR`.
     *
     * @since 1.0.0
     *
     * @param WP_Query $query The query.
     *
     * @return int The category term ID, or 0 when the query is not limited to exactly one category.
     */
    public static function single_category( $query ) {
        $tax_query = isset( $query->tax_query ) ? $query->tax_query : null;

        if ( ! $tax_query instanceof WP_Tax_Query || ! is_array( $tax_query->queries ) ) {
            return 0;
        }

        $queries = $tax_query->queries;

        if ( isset( $queries['relation'] ) && 'OR' === strtoupper( (string) $queries['relation'] ) && count( $queries ) > 2 ) {
            return 0;
        }

        unset( $queries['relation'] );

        $matches = array();

        foreach ( $queries as $clause ) {
            if ( ! is_array( $clause ) || ! isset( $clause['taxonomy'] ) ) {
                return 0;
            }

            if ( Licensio_KB_Post_Types::TAXONOMY_CATEGORY === $clause['taxonomy'] ) {
                $matches[] = $clause;
            }
        }

        if ( 1 !== count( $matches ) ) {
            return 0;
        }

        $clause   = $matches[0];
        $terms    = isset( $clause['terms'] ) ? array_values( (array) $clause['terms'] ) : array();
        $operator = isset( $clause['operator'] ) ? strtoupper( (string) $clause['operator'] ) : 'IN';

        if ( 'IN' !== $operator || 1 !== count( $terms ) ) {
            return 0;
        }

        return self::resolve_term( $terms[0], isset( $clause['field'] ) ? (string) $clause['field'] : 'term_id' );
    }

    /**
     * The term ID a tax query clause names.
     *
     * @since 1.0.0
     *
     * @param mixed  $value The term value from the clause.
     * @param string $field The clause's field: `term_id`, `slug`, `name` or `term_taxonomy_id`.
     *
     * @return int The category term ID, or 0 when it does not resolve.
     */
    private static function resolve_term( $value, $field ) {
        if ( 'term_id' === $field || 'id' === $field ) {
            return absint( $value );
        }

        if ( ! in_array( $field, array( 'slug', 'name', 'term_taxonomy_id' ), true ) ) {
            return 0;
        }

        $term = get_term_by( $field, $value, Licensio_KB_Post_Types::TAXONOMY_CATEGORY );

        return $term instanceof WP_Term ? (int) $term->term_id : 0;
    }

    /**
     * Whether a query is the main query of a front-end category archive.
     *
     * @since 1.0.0
     *
     * @param WP_Query $query The query.
     *
     * @return bool True for the main query of a `licensio_category` archive outside the admin, unless it is a search or a feed.
     */
    private function is_category_archive( $query ) {
        // On the main query the global conditional reads this same query.
        return ! is_admin()
            && $query->is_main_query()
            && ! $query->is_search()
            && ! $query->is_feed()
            && is_tax( Licensio_KB_Post_Types::TAXONOMY_CATEGORY );
    }

    /**
     * The positions stored for one category.
     *
     * One query, however many articles the category holds, and no post meta
     * cache priming: priming would load every meta row of every positioned
     * article, which runs out of memory on a category with thousands of
     * articles. Trashed articles are included, so a merge or a save keeps
     * their place for when they are restored; auto-drafts are not.
     *
     * @since 1.0.0
     *
     * @param int $term_id The category term ID.
     *
     * @return array<int, int> Article ID to position, lowest first.
     */
    public static function positions( $term_id ) {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT pm.post_id, pm.meta_value FROM %i pm INNER JOIN %i p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND p.post_type = %s AND p.post_status <> %s ORDER BY CAST(pm.meta_value AS SIGNED) ASC, pm.post_id ASC',
                $wpdb->postmeta,
                $wpdb->posts,
                self::meta_key( $term_id ),
                Licensio_KB_Post_Types::POST_TYPE,
                'auto-draft'
            ),
            ARRAY_N
        );

        $positions = array();

        foreach ( is_array( $rows ) ? $rows : array() as $row ) {
            $id = (int) $row[0];

            // Every writer keeps one row per key; should a second exist, the first wins.
            if ( ! isset( $positions[ $id ] ) ) {
                $positions[ $id ] = (int) $row[1];
            }
        }

        return $positions;
    }

    /**
     * Save a category's order.
     *
     * The listed articles get 10, 20, 30… in the order given. Every other
     * article that already has a position in the category, trashed ones
     * included, is renumbered after them, keeping its previous relative
     * order, so the positions stay dense and a partial list never interleaves
     * with the rest. Articles without a position stay without one.
     *
     * @since 1.0.0
     *
     * @param int   $term_id The category term ID.
     * @param int[] $ids     The article IDs, first first.
     *
     * @return int[] The listed article IDs as saved.
     */
    public static function save( $term_id, array $ids ) {
        $key      = self::meta_key( $term_id );
        $ids      = array_values( array_map( 'intval', $ids ) );
        $previous = self::positions( $term_id );
        $listed   = array_flip( $ids );
        $position = 0;

        foreach ( $ids as $id ) {
            $position += self::STEP;

            if ( ! isset( $previous[ $id ] ) || $previous[ $id ] !== $position ) {
                update_post_meta( $id, $key, $position );
            }
        }

        foreach ( $previous as $id => $old ) {
            if ( isset( $listed[ $id ] ) ) {
                continue;
            }

            $position += self::STEP;

            if ( $old !== $position ) {
                update_post_meta( $id, $key, $position );
            }
        }

        return $ids;
    }

    /**
     * Append each merged category's order to the target's.
     *
     * Hooked to `licensio_kb_terms_merged`, which fires after the assignments
     * moved and before the sources are deleted, so the source keys are still
     * there. Each source's ordered articles follow the target's last position,
     * one source after another in the order given, keeping their relative
     * order. Trashed articles are carried too, so they keep a place if they
     * are restored. An article that already has a position in the target
     * keeps it. The source keys are cleaned up by the delete that follows.
     *
     * The new positions are written in batched INSERTs, since none of those
     * articles holds the target key yet: one `update_post_meta()` per
     * article took seconds on a category with thousands of articles.
     *
     * @since 1.0.0
     *
     * @param int[]  $sources  The merged category term IDs.
     * @param int    $target   The category they were merged into.
     * @param string $taxonomy The taxonomy.
     *
     * @return void
     */
    public function append_merged_order( $sources, $target, $taxonomy ) {
        if ( Licensio_KB_Post_Types::TAXONOMY_CATEGORY !== $taxonomy ) {
            return;
        }

        $target   = absint( $target );
        $current  = self::positions( $target );
        $next     = empty( $current ) ? 0 : max( $current );
        $key      = self::meta_key( $target );
        $appended = array();

        foreach ( (array) $sources as $source ) {
            $source = absint( $source );

            if ( $source <= 0 || $source === $target ) {
                continue;
            }

            foreach ( array_keys( self::positions( $source ) ) as $article_id ) {
                if ( isset( $current[ $article_id ] ) ) {
                    continue;
                }

                $next                   += self::STEP;
                $current[ $article_id ]  = $next;
                $appended[ $article_id ] = $next;
            }
        }

        self::insert_positions( $key, $appended );
    }

    /**
     * Insert order keys for articles that hold none yet.
     *
     * Writes `INSERT_BATCH` rows per statement, then drops those articles'
     * post meta cache and bumps the posts cache version, as
     * `update_post_meta()` would.
     *
     * @since 1.0.0
     *
     * @param string          $key       The order meta key.
     * @param array<int, int> $positions Article ID to position.
     *
     * @return void
     */
    private static function insert_positions( $key, array $positions ) {
        global $wpdb;

        if ( empty( $positions ) ) {
            return;
        }

        foreach ( array_chunk( $positions, self::INSERT_BATCH, true ) as $batch ) {
            $values = array( $wpdb->postmeta );

            foreach ( $batch as $article_id => $position ) {
                $values[] = (int) $article_id;
                $values[] = $key;
                $values[] = (string) $position;
            }

            $rows = implode( ', ', array_fill( 0, count( $batch ), '( %d, %s, %s )' ) );

            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $rows holds only placeholders, one triple per article.
            $sql = $wpdb->prepare( "INSERT INTO %i ( post_id, meta_key, meta_value ) VALUES {$rows}", $values );
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
            $wpdb->query( $sql );
        }

        wp_cache_delete_multiple( array_keys( $positions ), 'post_meta' );
        wp_cache_set_posts_last_changed();
    }

    /**
     * Delete a removed category's order keys from every article.
     *
     * Hooked to `licensio_kb_term_removed`.
     *
     * @since 1.0.0
     *
     * @param int    $term_id  The deleted category term ID.
     * @param string $taxonomy The taxonomy.
     *
     * @return void
     */
    public function delete_term_order( $term_id, $taxonomy ) {
        if ( Licensio_KB_Post_Types::TAXONOMY_CATEGORY !== $taxonomy || absint( $term_id ) <= 0 ) {
            return;
        }

        delete_metadata( 'post', 0, self::meta_key( $term_id ), '', true );
    }
}
