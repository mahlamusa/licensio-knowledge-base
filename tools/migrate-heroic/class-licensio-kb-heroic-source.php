<?php
/**
 * Chunked reads out of the Heroic Knowledge Base data.
 *
 * Every read is keyed and bounded: rows come back ordered by primary key,
 * starting after a caller-supplied high-water mark, at most a batch at a time.
 * That is what makes the migration resumable and what keeps a knowledge base
 * with a million analytics rows inside a sane memory budget — an offset-based
 * pager would also have re-read the same rows every batch.
 *
 * The reads deliberately do not go through Heroic's own code. A migration is
 * normally run with Heroic already deactivated, so its functions and constants
 * do not exist; everything here talks to the tables and options directly.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads Heroic content and analytics in batches.
 *
 * @since 1.0.0
 */
class Licensio_KB_Heroic_Source {

    /**
     * How many rows a batch holds when the caller does not say.
     *
     * @since 1.0.0
     * @var int
     */
    const DEFAULT_BATCH = 200;

    /**
     * How many `ht_kb` posts exist, in any status but auto-draft.
     *
     * @since 1.0.0
     *
     * @return int The article count.
     */
    public function count_articles() {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status != %s",
                Licensio_KB_Heroic_Schema::POST_TYPE,
                'auto-draft'
            )
        );
    }

    /**
     * A batch of `ht_kb` posts, ordered by ID.
     *
     * Revisions and auto-drafts are excluded. A revision is not content the
     * target needs — `wp_insert_post()` on the new article starts its own
     * revision history — and an auto-draft is an editor artefact that was never
     * an article.
     *
     * @since 1.0.0
     *
     * @param int $after The ID to start after.
     * @param int $limit How many rows to return.
     *
     * @return array<int, array<string, mixed>> The post rows, oldest ID first.
     */
    public function articles( $after = 0, $limit = self::DEFAULT_BATCH ) {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->posts}
                 WHERE post_type = %s AND post_status != %s AND ID > %d
                 ORDER BY ID ASC
                 LIMIT %d",
                Licensio_KB_Heroic_Schema::POST_TYPE,
                'auto-draft',
                (int) $after,
                max( 1, (int) $limit )
            ),
            ARRAY_A
        );

        return is_array( $rows ) ? $rows : array();
    }

    /**
     * Every term in one of Heroic's taxonomies, parents before children.
     *
     * Terms are few even on a large knowledge base, and a child cannot be
     * created before its parent, so they are read in one go and topologically
     * ordered rather than paged. A term whose parent is missing — which a
     * half-deleted taxonomy can produce — is treated as a root so it is
     * migrated rather than silently dropped.
     *
     * @since 1.0.0
     *
     * @param string $taxonomy The Heroic taxonomy name.
     *
     * @return array<int, array<string, mixed>> Term rows: term_id, name, slug, description, parent, count.
     */
    public function terms( $taxonomy ) {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT t.term_id, t.name, t.slug, tt.description, tt.parent, tt.count
                 FROM {$wpdb->terms} AS t
                 INNER JOIN {$wpdb->term_taxonomy} AS tt ON tt.term_id = t.term_id
                 WHERE tt.taxonomy = %s
                 ORDER BY t.term_id ASC",
                (string) $taxonomy
            ),
            ARRAY_A
        );

        return $this->sort_parents_first( is_array( $rows ) ? $rows : array() );
    }

    /**
     * The Heroic term IDs attached to one Heroic post, in one taxonomy.
     *
     * @since 1.0.0
     *
     * @param int    $post_id  The Heroic post ID.
     * @param string $taxonomy The Heroic taxonomy name.
     *
     * @return int[] The term IDs, ascending.
     */
    public function post_terms( $post_id, $taxonomy ) {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT tt.term_id
                 FROM {$wpdb->term_relationships} AS tr
                 INNER JOIN {$wpdb->term_taxonomy} AS tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                 WHERE tr.object_id = %d AND tt.taxonomy = %s
                 ORDER BY tt.term_id ASC",
                (int) $post_id,
                (string) $taxonomy
            )
        );

        return array_map( 'intval', is_array( $ids ) ? $ids : array() );
    }

    /**
     * Every term meta value on one Heroic term.
     *
     * Read wholesale rather than key by key: Heroic's keys are unprefixed
     * (`term_order`, `meta_image`, `meta_svg`), so a caller that wants to report
     * what it could not map has to see everything that is there.
     *
     * @since 1.0.0
     *
     * @param int $term_id The Heroic term ID.
     *
     * @return array<string, string> Meta key to its first value.
     */
    public function term_meta( $term_id ) {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT meta_key, meta_value FROM {$wpdb->termmeta} WHERE term_id = %d ORDER BY meta_id ASC",
                (int) $term_id
            ),
            ARRAY_A
        );

        $meta = array();

        foreach ( (array) $rows as $row ) {
            $key = isset( $row['meta_key'] ) ? (string) $row['meta_key'] : '';

            if ( '' === $key || array_key_exists( $key, $meta ) ) {
                continue;
            }

            $meta[ $key ] = isset( $row['meta_value'] ) ? (string) $row['meta_value'] : '';
        }

        return $meta;
    }

    /**
     * The per-category positions Heroic recorded for one article.
     *
     * @since 1.0.0
     *
     * @param int $post_id The Heroic post ID.
     *
     * @return array<int, string> Heroic term ID to the stored position.
     */
    public function article_orders( $post_id ) {
        global $wpdb;

        $prefix = Licensio_KB_Heroic_Schema::META_ARTICLE_ORDER_PREFIX;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT meta_key, meta_value FROM {$wpdb->postmeta}
                 WHERE post_id = %d AND meta_key LIKE %s",
                (int) $post_id,
                $wpdb->esc_like( $prefix ) . '%'
            ),
            ARRAY_A
        );

        $orders = array();

        foreach ( (array) $rows as $row ) {
            $key     = isset( $row['meta_key'] ) ? (string) $row['meta_key'] : '';
            $term_id = (int) substr( $key, strlen( $prefix ) );

            if ( $term_id > 0 ) {
                $orders[ $term_id ] = isset( $row['meta_value'] ) ? (string) $row['meta_value'] : '';
            }
        }

        return $orders;
    }

    /**
     * The attachment IDs whose parent is one Heroic article.
     *
     * @since 1.0.0
     *
     * @param int $post_id The Heroic post ID.
     *
     * @return int[] The attachment IDs.
     */
    public function attachments( $post_id ) {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = %s ORDER BY ID ASC",
                (int) $post_id,
                'attachment'
            )
        );

        return array_map( 'intval', is_array( $ids ) ? $ids : array() );
    }

    /**
     * How many rows one of Heroic's tables holds.
     *
     * Returns 0 for a table this installation never created, which is what lets
     * the dry run report a module that was never switched on rather than fail.
     *
     * @since 1.0.0
     *
     * @param string $table One of the Licensio_KB_Heroic_Schema TABLE_* constants.
     *
     * @return int The row count.
     */
    public function count_table( $table ) {
        global $wpdb;

        if ( ! Licensio_KB_Heroic_Schema::table_exists( $table ) ) {
            return 0;
        }

        $name = Licensio_KB_Heroic_Schema::table( $table );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$name}`" );
    }

    /**
     * A batch of rows from one of Heroic's tables.
     *
     * @since 1.0.0
     *
     * @param string $table One of the Licensio_KB_Heroic_Schema TABLE_* constants.
     * @param string $key   The table's primary key column.
     * @param int    $after The key value to start after.
     * @param int    $limit How many rows to return.
     *
     * @return array<int, array<string, mixed>> The rows, lowest key first.
     */
    public function rows( $table, $key, $after = 0, $limit = self::DEFAULT_BATCH ) {
        global $wpdb;

        if ( ! Licensio_KB_Heroic_Schema::table_exists( $table ) ) {
            return array();
        }

        /*
         * The table and key are not user input: both come from
         * Licensio_KB_Heroic_Schema constants, and the key is additionally
         * reduced to word characters. They are interpolated because an
         * identifier cannot be a bound parameter. The two values that ARE
         * caller-supplied, the cursor and the limit, are bound.
         */
        $name   = Licensio_KB_Heroic_Schema::table( $table );
        $column = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $key );

        $sql = $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifiers cannot be bound; both are class constants, see above.
            "SELECT * FROM `{$name}` WHERE `{$column}` > %d ORDER BY `{$column}` ASC LIMIT %d",
            (int) $after,
            max( 1, (int) $limit )
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $sql, ARRAY_A );

        return is_array( $rows ) ? $rows : array();
    }

    /**
     * Order term rows so a parent always precedes its children.
     *
     * @since 1.0.0
     *
     * @param array<int, array<string, mixed>> $rows The term rows, in any order.
     *
     * @return array<int, array<string, mixed>> The same rows, parents first.
     */
    private function sort_parents_first( array $rows ) {
        $by_parent = array();
        $known     = array();

        foreach ( $rows as $row ) {
            $known[ isset( $row['term_id'] ) ? (int) $row['term_id'] : 0 ] = true;
        }

        foreach ( $rows as $row ) {
            $parent = isset( $row['parent'] ) ? (int) $row['parent'] : 0;

            /*
             * A term whose parent is not in this taxonomy — the residue of a
             * half-deleted tree — is treated as a root. Filing it under a parent
             * that will never appear would leave it stranded in the queue and
             * silently unmigrated.
             */
            if ( $parent > 0 && empty( $known[ $parent ] ) ) {
                $parent = 0;
            }

            $by_parent[ $parent ][] = $row;
        }

        return $this->flatten_tree( $by_parent, 0 );
    }

    /**
     * Walk a parent-indexed tree into a flat parents-first list.
     *
     * @since 1.0.0
     *
     * @param array<int, array<int, array<string, mixed>>> $by_parent Rows grouped by parent term ID.
     * @param int                                          $parent_id The parent to walk from.
     *
     * @return array<int, array<string, mixed>> The flattened rows.
     */
    private function flatten_tree( array $by_parent, $parent_id ) {
        $out = array();

        if ( empty( $by_parent[ $parent_id ] ) ) {
            return $out;
        }

        foreach ( $by_parent[ $parent_id ] as $row ) {
            $out[] = $row;
            $id    = isset( $row['term_id'] ) ? (int) $row['term_id'] : 0;

            if ( $id > 0 && $id !== (int) $parent_id ) {
                $out = array_merge( $out, $this->flatten_tree( $by_parent, $id ) );
            }
        }

        return $out;
    }
}
