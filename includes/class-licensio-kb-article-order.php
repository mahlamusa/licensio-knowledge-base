<?php
/**
 * Per-category article order.
 *
 * An article's position in a category is stored as post meta
 * `licensio_order_{term_id}`, an integer where lower comes first. Each
 * category has its own key, so an article filed in three categories can sit
 * at a different place in each. The keys are written only by the order REST
 * route, a category merge and the importers, and are not registered for core
 * REST.
 *
 * This class owns the storage rules and the query support:
 *
 * - `orderby => licensio_order` on a post query limited to exactly one
 *   `licensio_category` sorts by that category's positions. Articles with a
 *   position come first, lowest first; articles without one follow, by title.
 *   The query gets a named `meta_query` (`relation OR`, `EXISTS` and
 *   `NOT EXISTS`) so unordered articles are not dropped, and the ORDER BY is
 *   written in `posts_clauses`. Core cannot express "missing last" through
 *   `orderby` alone: ordering by either clause sorts a missing position as
 *   NULL, which MySQL puts first, and the `EXISTS` clause's join is not
 *   limited to the key, so it reads an unrelated meta value for an unordered
 *   article.
 * - Any other query asking for `licensio_order` falls back to `menu_order`,
 *   then title.
 * - The main query of a category archive on the front end uses the order
 *   unless something else already chose an `orderby`.
 * - A category merge appends each source category's order to the target's.
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
     * The name of the meta query clause matching ordered articles.
     *
     * @since 1.0.0
     * @var string
     */
    const CLAUSE_ORDERED = 'licensio_order_ordered';

    /**
     * The name of the meta query clause matching unordered articles.
     *
     * @since 1.0.0
     * @var string
     */
    const CLAUSE_UNORDERED = 'licensio_order_unordered';

    /**
     * The query variable holding the category a query is ordered by.
     *
     * @since 1.0.0
     * @var string
     */
    const QUERY_VAR = 'licensio_order_term';

    /**
     * The gap between two saved positions.
     *
     * @since 1.0.0
     * @var int
     */
    const STEP = 10;

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
     * Translate `orderby => licensio_order` into query variables.
     *
     * Hooked to `pre_get_posts`. A front-end category archive's main query
     * with no `orderby` of its own is switched to the per-category order
     * first.
     *
     * For a query limited to exactly one category, this adds the named meta
     * query, records the category in `licensio_order_term`, and turns
     * `suppress_filters` off: the ORDER BY is written in `posts_clauses`, which
     * `get_posts()` would otherwise skip. The order is always ascending; the
     * `order` variable does not reverse it. Any other query falls back to
     * `menu_order`, then title.
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

        if ( $this->is_category_archive( $query ) && '' === (string) $query->get( 'orderby' ) ) {
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

        $key   = self::meta_key( $term_id );
        $group = array(
            'relation'             => 'OR',
            self::CLAUSE_ORDERED   => array(
                'key'     => $key,
                'compare' => 'EXISTS',
                'type'    => 'NUMERIC',
            ),
            self::CLAUSE_UNORDERED => array(
                'key'     => $key,
                'compare' => 'NOT EXISTS',
            ),
        );

        $existing = $query->get( 'meta_query' );

        $query->set( 'meta_query', is_array( $existing ) && ! empty( $existing ) ? array( $existing, $group ) : $group );
        $query->set(
            'orderby',
            array(
                self::CLAUSE_ORDERED => 'ASC',
                'title'              => 'ASC',
            )
        );
        $query->set( 'order', 'ASC' );
        $query->set( self::QUERY_VAR, $term_id );
        $query->set( 'suppress_filters', false );
    }

    /**
     * Write the ORDER BY for a query prepared by `prepare_query()`.
     *
     * Hooked to `posts_clauses`. The `NOT EXISTS` clause is a LEFT JOIN on
     * the category's own key, so its `meta_value` is the position, or NULL for
     * an unordered article.
     *
     * @since 1.0.0
     *
     * @param array<string, string> $clauses The query clauses.
     * @param WP_Query              $query   The query.
     *
     * @return array<string, string> The clauses, with the ORDER BY replaced when the query is ordered per category.
     */
    public function order_clauses( $clauses, $query ) {
        if ( ! is_array( $clauses ) || ! $query instanceof WP_Query || (int) $query->get( self::QUERY_VAR ) <= 0 ) {
            return $clauses;
        }

        $meta_query = isset( $query->meta_query ) ? $query->meta_query : null;

        if ( ! $meta_query instanceof WP_Meta_Query ) {
            return $clauses;
        }

        $named = $meta_query->get_clauses();

        if ( empty( $named[ self::CLAUSE_UNORDERED ]['alias'] ) ) {
            return $clauses;
        }

        $clauses['orderby'] = self::orderby_sql( (string) $named[ self::CLAUSE_UNORDERED ]['alias'] );

        return $clauses;
    }

    /**
     * The ORDER BY expression for the per-category order.
     *
     * Ordered articles first, by position; then unordered ones; each group by
     * title, then ID, so pages never shuffle.
     *
     * @since 1.0.0
     *
     * @param string $alias The alias of the meta table joined on the category's key.
     *
     * @return string The ORDER BY expression, without the keyword.
     */
    public static function orderby_sql( $alias ) {
        global $wpdb;

        $alias = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $alias );
        $posts = $wpdb->posts;

        return "{$alias}.meta_value IS NULL ASC, CAST({$alias}.meta_value AS SIGNED) ASC, {$posts}.post_title ASC, {$posts}.ID ASC";
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
     * @return bool True for the main query of a `licensio_category` archive outside the admin.
     */
    private function is_category_archive( $query ) {
        // On the main query the global conditional reads this same query.
        return ! is_admin()
            && $query->is_main_query()
            && is_tax( Licensio_KB_Post_Types::TAXONOMY_CATEGORY );
    }

    /**
     * The positions stored for one category.
     *
     * One query for the IDs and one for their meta, however many articles
     * the category holds. Trashed articles are left out.
     *
     * @since 1.0.0
     *
     * @param int $term_id The category term ID.
     *
     * @return array<int, int> Article ID to position, lowest first.
     */
    public static function positions( $term_id ) {
        $key = self::meta_key( $term_id );
        $ids = get_posts(
            array(
                'post_type'              => Licensio_KB_Post_Types::POST_TYPE,
                'post_status'            => 'any',
                'posts_per_page'         => -1,
                'fields'                 => 'ids',
                'meta_key'               => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                'orderby'                => array(
                    'meta_value_num' => 'ASC',
                    'ID'             => 'ASC',
                ),
                'no_found_rows'          => true,
                'update_post_term_cache' => false,
            )
        );

        $ids = array_map( 'intval', is_array( $ids ) ? $ids : array() );

        if ( empty( $ids ) ) {
            return array();
        }

        update_meta_cache( 'post', $ids );

        $positions = array();

        foreach ( $ids as $id ) {
            $positions[ $id ] = (int) get_post_meta( $id, $key, true );
        }

        return $positions;
    }

    /**
     * Save a category's order.
     *
     * The articles get 10, 20, 30… in the order given. Articles of the
     * category that are not listed keep whatever position they had.
     *
     * @since 1.0.0
     *
     * @param int   $term_id The category term ID.
     * @param int[] $ids     The article IDs, first first.
     *
     * @return int[] The article IDs as saved.
     */
    public static function save( $term_id, array $ids ) {
        $key   = self::meta_key( $term_id );
        $saved = array();

        foreach ( array_values( $ids ) as $index => $id ) {
            update_post_meta( (int) $id, $key, ( $index + 1 ) * self::STEP );

            $saved[] = (int) $id;
        }

        return $saved;
    }

    /**
     * Append each merged category's order to the target's.
     *
     * Hooked to `licensio_kb_terms_merged`, which fires after the assignments
     * moved and before the sources are deleted, so the source keys are still
     * there. Each source's ordered articles follow the target's last position,
     * one source after another in the order given, keeping their relative
     * order. An article that already has a position in the target keeps it.
     * The source keys are cleaned up by the delete that follows.
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

        $target  = absint( $target );
        $current = self::positions( $target );
        $next    = empty( $current ) ? 0 : max( $current );
        $key     = self::meta_key( $target );

        foreach ( (array) $sources as $source ) {
            $source = absint( $source );

            if ( $source <= 0 || $source === $target ) {
                continue;
            }

            foreach ( array_keys( self::positions( $source ) ) as $article_id ) {
                if ( isset( $current[ $article_id ] ) ) {
                    continue;
                }

                $next                  += self::STEP;
                $current[ $article_id ] = $next;

                update_post_meta( $article_id, $key, $next );
            }
        }
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
