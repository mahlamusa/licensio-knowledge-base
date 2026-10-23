<?php
/**
 * Chunked, read-only access to another knowledge base plugin's data.
 *
 * Every source this plugin imports from stores its articles as posts and its
 * categories and tags as terms, so the reads over `wp_posts`, `wp_terms` and
 * their meta tables live here once. A concrete source names its post type and
 * taxonomies, and supplies the parts that really differ: how its category meta
 * and article order map onto this plugin's, which post meta it knows about, and
 * where its old URLs lived.
 *
 * Every read is keyed and bounded: rows come back ordered by primary key,
 * starting after a caller-supplied high-water mark, at most a batch at a time.
 * That is what makes an import resumable, and what keeps a knowledge base with
 * a million analytics rows inside a sane memory budget.
 *
 * The reads deliberately do not go through the source plugin's own code. An
 * import is often run with that plugin already deactivated, so its functions,
 * post types and taxonomies may not be registered; everything here talks to
 * the tables directly. Nothing here writes: the source's data is read-only.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads one source plugin's content in batches.
 *
 * @since 1.0.0
 */
abstract class Licensio_KB_Import_Source {

    /**
     * How many rows a batch holds when the caller does not say.
     *
     * @since 1.0.0
     * @var int
     */
    const DEFAULT_BATCH = 200;

    /**
     * Post meta WordPress itself keeps, which no import needs to carry.
     *
     * @since 1.0.0
     * @var string[]
     */
    const CORE_POST_META = array( '_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date', '_encloseme', '_pingme', '_wp_trash_meta_status', '_wp_trash_meta_time', '_wp_desired_post_slug', '_wp_page_template' );

    /**
     * The source driver id, e.g. `heroic`.
     *
     * @since 1.0.0
     *
     * @return string The id.
     */
    abstract public function id();

    /**
     * The source's name as the operator knows it.
     *
     * @since 1.0.0
     *
     * @return string The label, e.g. `Heroic Knowledge Base`.
     */
    abstract public function label();

    /**
     * The post type the source keeps its articles in.
     *
     * @since 1.0.0
     *
     * @return string The post type.
     */
    abstract public function post_type();

    /**
     * The source's category taxonomy.
     *
     * @since 1.0.0
     *
     * @return string The taxonomy.
     */
    abstract public function category_taxonomy();

    /**
     * The source's tag taxonomy.
     *
     * @since 1.0.0
     *
     * @return string The taxonomy.
     */
    abstract public function tag_taxonomy();

    /**
     * Map a category's meta onto this plugin's term meta.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $meta The source term meta, keyed by the source's key.
     *
     * @return array{meta: array<string, mixed>, unmapped: string[]} The target meta, and the
     *                                                                 source keys that had a value
     *                                                                 but no home.
     */
    abstract public function map_category_meta( array $meta );

    /**
     * The menu order an article should get.
     *
     * @since 1.0.0
     *
     * @param int $post_id The source post ID.
     *
     * @return array{order: int, ambiguous: bool, source_term_id: int} The kept position, whether
     *                                                                 the source disagreed with
     *                                                                 itself, and the category it
     *                                                                 was taken from.
     */
    abstract public function article_order( $post_id );

    /**
     * What becomes of one source post meta key, for the log.
     *
     * @since 1.0.0
     *
     * @param string $key The meta key.
     *
     * @return array{action: string, target: string} `migrated`, `derived`, `skipped` or
     *                                                `not_migrated`, and where it went or why not.
     */
    abstract public function classify_post_meta( $key );

    /**
     * What becomes of one source category meta key, for the log.
     *
     * Only asked about keys `map_category_meta()` did not already report as
     * unmapped.
     *
     * @since 1.0.0
     *
     * @param string $key The term meta key.
     *
     * @return array{action: string, target: string} `migrated`, `skipped` or `not_migrated`,
     *                                                and where it went or why not.
     */
    abstract public function classify_term_meta( $key );

    /**
     * Where a source article lived before the import.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $row The source post row.
     *
     * @return string The absolute URL, or '' when it cannot be known.
     */
    abstract public function old_article_url( array $row );

    /**
     * Where a source term's archive lived before the import.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $row The source term row, with its `taxonomy`.
     *
     * @return string The absolute URL, or '' when it cannot be known.
     */
    abstract public function old_term_url( array $row );

    /**
     * Map the source's stored settings onto this plugin's.
     *
     * @since 1.0.0
     *
     * @return array{settings: array<string, mixed>, excluded: array<string, string>, unmapped: string[]} Target
     *         setting to value, excluded source key to reason, and the unmapped source keys.
     */
    abstract public function map_settings();

    /**
     * The post meta key recording which source article a target article came from.
     *
     * @since 1.0.0
     *
     * @return string The meta key.
     */
    public function provenance_post_key() {
        return self::provenance_post_key_for( $this->id() );
    }

    /**
     * The term meta key recording which source term a target term came from.
     *
     * @since 1.0.0
     *
     * @return string The meta key.
     */
    public function provenance_term_key() {
        return '_licensio_' . sanitize_key( $this->id() ) . '_term_id';
    }

    /**
     * The provenance post meta key for any source id.
     *
     * @since 1.0.0
     *
     * @param string $source The source driver id.
     *
     * @return string The meta key.
     */
    public static function provenance_post_key_for( $source ) {
        return '_licensio_' . sanitize_key( (string) $source ) . '_post_id';
    }

    /**
     * The source taxonomies paired with the target taxonomy each one fills.
     *
     * @since 1.0.0
     *
     * @return array<string, string> Source taxonomy to target taxonomy, categories first.
     */
    public function taxonomy_pairs() {
        return array(
            $this->category_taxonomy() => Licensio_KB_Post_Types::TAXONOMY_CATEGORY,
            $this->tag_taxonomy()      => Licensio_KB_Post_Types::TAXONOMY_TAG,
        );
    }

    /**
     * The terms the import reads for one source taxonomy, parents first.
     *
     * A source with more structure than a category tree — BetterDocs' multiple
     * knowledge bases — folds it in here, so the content importer only ever
     * sees one tree per target taxonomy.
     *
     * @since 1.0.0
     *
     * @param string $taxonomy The source taxonomy.
     *
     * @return array<int, array<string, mixed>> The term rows.
     */
    public function import_terms( $taxonomy ) {
        return $this->terms( $taxonomy );
    }

    /**
     * How many source articles exist, in any status but auto-draft.
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
                $this->post_type(),
                'auto-draft'
            )
        );
    }

    /**
     * The next batch of source articles, after a given ID.
     *
     * Auto-drafts are skipped: they are editor scratch rows, never content.
     *
     * @since 1.0.0
     *
     * @param int $after The ID of the last article already processed.
     * @param int $limit The batch size.
     *
     * @return array<int, array<string, mixed>> The post rows, in ID order.
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
                $this->post_type(),
                'auto-draft',
                (int) $after,
                max( 1, (int) $limit )
            ),
            ARRAY_A
        );

        return is_array( $rows ) ? $rows : array();
    }

    /**
     * Every term in a source taxonomy, parents before their children.
     *
     * @since 1.0.0
     *
     * @param string $taxonomy The source taxonomy.
     *
     * @return array<int, array<string, mixed>> The term rows, each with its `taxonomy`.
     */
    public function terms( $taxonomy ) {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT t.term_id, t.name, t.slug, tt.taxonomy, tt.description, tt.parent, tt.count
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
     * The term IDs a source post is filed under in one taxonomy.
     *
     * @since 1.0.0
     *
     * @param int    $post_id  The source post ID.
     * @param string $taxonomy The source taxonomy.
     *
     * @return int[] The term IDs.
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
     * The names of a set of source terms, for the log.
     *
     * @since 1.0.0
     *
     * @param int[] $term_ids The term IDs.
     *
     * @return array<int, string> Term ID to name.
     */
    public function term_names( array $term_ids ) {
        global $wpdb;

        $term_ids = array_values( array_filter( array_map( 'intval', $term_ids ) ) );

        if ( empty( $term_ids ) ) {
            return array();
        }

        $placeholders = implode( ',', array_fill( 0, count( $term_ids ), '%d' ) );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- The placeholders are generated, one %d per ID.
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT term_id, name FROM {$wpdb->terms} WHERE term_id IN ({$placeholders})", $term_ids ), ARRAY_A );

        $names = array();

        foreach ( (array) $rows as $row ) {
            $names[ (int) $row['term_id'] ] = (string) $row['name'];
        }

        return $names;
    }

    /**
     * One source term's meta, first value per key.
     *
     * @since 1.0.0
     *
     * @param int $term_id The source term ID.
     *
     * @return array<string, string> Meta key to value.
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

        return $this->first_values( $rows );
    }

    /**
     * One source post's meta, first value per key.
     *
     * @since 1.0.0
     *
     * @param int $post_id The source post ID.
     *
     * @return array<string, string> Meta key to value.
     */
    public function post_meta( $post_id ) {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d ORDER BY meta_id ASC",
                (int) $post_id
            ),
            ARRAY_A
        );

        return $this->first_values( $rows );
    }

    /**
     * Whether a source article with this ID exists.
     *
     * @since 1.0.0
     *
     * @param int $post_id The source post ID.
     *
     * @return bool True when the article exists.
     */
    public function article_exists( $post_id ) {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return (bool) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE ID = %d AND post_type = %s",
                (int) $post_id,
                $this->post_type()
            )
        );
    }

    /**
     * The attachments uploaded to a source article.
     *
     * @since 1.0.0
     *
     * @param int $post_id The source post ID.
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
     * A table name with the site prefix.
     *
     * @since 1.0.0
     *
     * @param string $table The unprefixed table name.
     *
     * @return string The prefixed table name.
     */
    public static function table( $table ) {
        global $wpdb;

        $prefix = isset( $wpdb ) ? (string) $wpdb->prefix : '';

        return $prefix . (string) $table;
    }

    /**
     * Whether a source table exists.
     *
     * The source plugin may never have created its analytics tables, or may
     * have dropped them on uninstall; an import then has nothing to read rather
     * than a fatal SQL error.
     *
     * @since 1.0.0
     *
     * @param string $table The unprefixed table name.
     *
     * @return bool True when the table exists.
     */
    public static function table_exists( $table ) {
        global $wpdb;

        if ( ! isset( $wpdb ) ) {
            return false;
        }

        $name = self::table( $table );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) );

        return (string) $found === $name;
    }

    /**
     * How many rows a source table holds.
     *
     * @since 1.0.0
     *
     * @param string $table The unprefixed table name; a class constant, never input.
     *
     * @return int The row count, 0 when the table does not exist.
     */
    public function count_table( $table ) {
        global $wpdb;

        if ( ! self::table_exists( $table ) ) {
            return 0;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', self::table( $table ) ) );
    }

    /**
     * The next batch of rows from a source table, after a given key.
     *
     * @since 1.0.0
     *
     * @param string $table          The unprefixed table name; a class constant, never input.
     * @param string $key            The primary key column; a class constant, never input.
     * @param int    $after          The key of the last row already processed.
     * @param int    $limit          The batch size.
     * @param string $created_before Only rows whose created_at is before this UTC date-time, or '' for all rows.
     *
     * @return array<int, array<string, mixed>> The rows, in key order.
     */
    public function rows( $table, $key, $after = 0, $limit = self::DEFAULT_BATCH, $created_before = '' ) {
        global $wpdb;

        if ( ! self::table_exists( $table ) ) {
            return array();
        }

        $name  = self::table( $table );
        $limit = max( 1, (int) $limit );

        if ( '' === (string) $created_before ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $rows = $wpdb->get_results(
                $wpdb->prepare( 'SELECT * FROM %i WHERE %i > %d ORDER BY %i ASC LIMIT %d', $name, $key, (int) $after, $key, $limit ),
                ARRAY_A
            );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $rows = $wpdb->get_results(
                $wpdb->prepare( 'SELECT * FROM %i WHERE %i > %d AND created_at < %s ORDER BY %i ASC LIMIT %d', $name, $key, (int) $after, (string) $created_before, $key, $limit ),
                ARRAY_A
            );
        }

        return is_array( $rows ) ? $rows : array();
    }

    /**
     * Reduce meta rows to the first value per key.
     *
     * @since 1.0.0
     *
     * @param mixed $rows The meta_key / meta_value rows.
     *
     * @return array<string, string> Meta key to value.
     */
    protected function first_values( $rows ) {
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
     * Order term rows so every parent precedes its children.
     *
     * @since 1.0.0
     *
     * @param array<int, array<string, mixed>> $rows The term rows, in any order.
     *
     * @return array<int, array<string, mixed>> The same rows, parents first.
     */
    protected function sort_parents_first( array $rows ) {
        $by_parent = array();
        $known     = array();

        foreach ( $rows as $row ) {
            $known[ isset( $row['term_id'] ) ? (int) $row['term_id'] : 0 ] = true;
        }

        foreach ( $rows as $row ) {
            $parent = isset( $row['parent'] ) ? (int) $row['parent'] : 0;

            /*
             * A parent outside the set — deleted, or in another taxonomy — would
             * strand the child and everything under it. Treat the child as a
             * root instead, so it is still imported.
             */
            if ( $parent > 0 && empty( $known[ $parent ] ) ) {
                $parent = 0;
            }

            $by_parent[ $parent ][] = $row;
        }

        return $this->flatten_tree( $by_parent, 0 );
    }

    /**
     * Walk a parent-indexed term map depth first.
     *
     * @since 1.0.0
     *
     * @param array<int, array<int, array<string, mixed>>> $by_parent Rows grouped by parent ID.
     * @param int                                          $parent_id The parent to start under.
     *
     * @return array<int, array<string, mixed>> The rows below that parent, parents first.
     */
    private function flatten_tree( array $by_parent, $parent_id ) {
        $out = array();

        if ( empty( $by_parent[ $parent_id ] ) ) {
            return $out;
        }

        foreach ( $by_parent[ $parent_id ] as $row ) {
            $out[] = $row;

            $id = isset( $row['term_id'] ) ? (int) $row['term_id'] : 0;

            if ( $id > 0 && $id !== (int) $parent_id ) {
                $out = array_merge( $out, $this->flatten_tree( $by_parent, $id ) );
            }
        }

        return $out;
    }
}
