<?php
/**
 * The core term functions, run as SQL against the scratch database.
 *
 * No WordPress is loaded in the live database suite, so the term API the
 * categories and tags controllers and Licensio_KB_Term_Operations call does
 * not exist.
 * This trait supplies the few functions they use, through Brain Monkey, as
 * plain SQL against the core term tables `install_content_schemas()` creates.
 * Each one does what core does to the rows that matter here:
 *
 * - `wp_delete_term()` removes the term's assignments and meta and moves its
 *   children up to its own parent, as core does.
 * - Every assignment change recounts the term from the published articles
 *   assigned to it, as core's `_update_post_term_count()` does.
 * - `wp_insert_term()` refuses a name a sibling already has (`term_exists`)
 *   and makes the slug unique; `wp_update_term()` makes an empty slug from
 *   the name and refuses a slug a sibling has (`duplicate_term_slug`).
 * - Names are stored with `&`, `<` and `>` encoded, and a bare `&` in a
 *   description as `&amp;`, as core's term filters do.
 * - `wp_insert_term()`, `wp_update_term()` and `update_term_meta()` unslash
 *   the name, description and meta value before storing them, as core does,
 *   so a caller that forgets to slash loses its backslashes here too.
 * - `wp_defer_term_counting()` reports counting as never deferred, so every
 *   assignment change recounts at once.
 *
 * `live_snapshot()` reads every term row and assignment, so a test can show
 * that a failed write left the data exactly as it was.
 *
 * Use it with Licensio_KB_Content_Fixtures in a subclass of
 * Licensio_KB_Analytics_DB_Test_Case and call `stub_category_term_api()` from `setUp()`.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

/*
 * This suite connects to a real database on purpose. The WordPress Database API
 * is therefore not available or appropriate here.
 */
// phpcs:disable WordPress.DB.RestrictedClasses, WordPress.DB.RestrictedFunctions, WordPress.DB.DirectDatabaseQuery

use Brain\Monkey\Functions;

/**
 * Backs the core term API with SQL.
 *
 * @since 1.0.0
 */
trait Licensio_KB_Live_Term_API {

    /**
     * Stub every core term function the categories and tags routes call.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function stub_category_term_api() {
        Functions\when( 'absint' )->alias(
            function ( $value ) {
                return abs( (int) $value );
            }
        );
        Functions\when( 'is_wp_error' )->alias(
            function ( $thing ) {
                return $thing instanceof WP_Error;
            }
        );
        Functions\when( 'rest_ensure_response' )->returnArg();
        Functions\when( 'wp_get_attachment_image_url' )->justReturn( false );
        Functions\when( 'wp_get_attachment_url' )->justReturn( false );
        Functions\when( '_prime_post_caches' )->justReturn( null );
        Functions\when( '_prime_term_caches' )->justReturn( null );
        Functions\when( 'wp_defer_term_counting' )->justReturn( false );

        Functions\when( 'get_terms' )->alias( array( $this, 'live_get_terms' ) );
        Functions\when( 'get_term' )->alias( array( $this, 'live_get_term' ) );
        Functions\when( 'get_term_by' )->alias( array( $this, 'live_get_term_by' ) );
        Functions\when( 'wp_insert_term' )->alias( array( $this, 'live_insert_term' ) );
        Functions\when( 'wp_update_term' )->alias( array( $this, 'live_update_term' ) );
        Functions\when( 'wp_delete_term' )->alias( array( $this, 'live_delete_term' ) );
        Functions\when( 'get_objects_in_term' )->alias( array( $this, 'live_objects_in_term' ) );
        Functions\when( 'wp_update_term_count' )->alias( array( $this, 'live_update_term_count' ) );
        Functions\when( 'clean_object_term_cache' )->justReturn( null );
        Functions\when( 'get_term_meta' )->alias( array( $this, 'live_get_term_meta' ) );
        Functions\when( 'update_term_meta' )->alias( array( $this, 'live_update_term_meta' ) );
    }

    /**
     * Run a query and fail the test if the server refuses it.
     *
     * @since 1.0.0
     *
     * @param string $sql The query.
     *
     * @return mysqli_result|bool The result.
     */
    private function live_query( $sql ) {
        $result = $this->db->query( $sql );

        $this->assertNotFalse( $result, $this->db->error . ' in ' . $sql );

        return $result;
    }

    /**
     * Every row a query returns.
     *
     * @since 1.0.0
     *
     * @param string $sql The query.
     *
     * @return array<int, array<string, string>> The rows.
     */
    protected function live_rows( $sql ) {
        $result = $this->live_query( $sql );

        return $result instanceof mysqli_result ? $result->fetch_all( MYSQLI_ASSOC ) : array();
    }

    /**
     * Every term, term taxonomy row and assignment, for before and after checks.
     *
     * @since 1.0.0
     *
     * @return array<string, array<int, array<string, string>>> The rows, keyed by table, in a stable order.
     */
    protected function live_snapshot() {
        return array(
            'terms'         => $this->live_rows( 'SELECT term_id, name, slug FROM ' . $this->table( 'terms' ) . ' ORDER BY term_id' ),
            'term_taxonomy' => $this->live_rows( 'SELECT term_taxonomy_id, term_id, taxonomy, description, parent, count FROM ' . $this->table( 'term_taxonomy' ) . ' ORDER BY term_taxonomy_id' ),
            'relationships' => $this->live_rows( 'SELECT object_id, term_taxonomy_id FROM ' . $this->table( 'term_relationships' ) . ' ORDER BY object_id, term_taxonomy_id' ),
        );
    }

    /**
     * A string quoted for SQL.
     *
     * @since 1.0.0
     *
     * @param string $value The value.
     *
     * @return string The quoted value.
     */
    private function live_quote( $value ) {
        return "'" . $this->db->real_escape_string( (string) $value ) . "'";
    }

    /**
     * The term taxonomy row of a term.
     *
     * @since 1.0.0
     *
     * @param int    $term_id  The term ID.
     * @param string $taxonomy The taxonomy.
     *
     * @return array<string, string>|null The joined term and term taxonomy row.
     */
    private function live_term_row( $term_id, $taxonomy ) {
        $rows = $this->live_rows(
            'SELECT t.term_id, t.name, t.slug, tt.term_taxonomy_id, tt.taxonomy, tt.description, tt.parent, tt.count FROM '
            . $this->table( 'terms' ) . ' t JOIN ' . $this->table( 'term_taxonomy' ) . ' tt ON tt.term_id = t.term_id WHERE t.term_id = '
            . (int) $term_id . ' AND tt.taxonomy = ' . $this->live_quote( $taxonomy )
        );

        return empty( $rows ) ? null : $rows[0];
    }

    /**
     * A term row as a WP_Term.
     *
     * @since 1.0.0
     *
     * @param array<string, string> $row The joined row.
     *
     * @return WP_Term The term.
     */
    private function live_term_object( array $row ) {
        return new WP_Term(
            array(
                'term_id'          => (int) $row['term_id'],
                'name'             => $row['name'],
                'slug'             => $row['slug'],
                'term_taxonomy_id' => (int) $row['term_taxonomy_id'],
                'taxonomy'         => $row['taxonomy'],
                'description'      => $row['description'],
                'parent'           => (int) $row['parent'],
                'count'            => (int) $row['count'],
            )
        );
    }

    /**
     * Stand-in for `get_terms()`: the terms of one taxonomy.
     *
     * Supports the default `fields` (WP_Term objects) and `id=>parent`, and
     * the `parent`, `name` (as stored) and `number` filters.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $args The query arguments.
     *
     * @return array<int|string, mixed> The terms.
     */
    public function live_get_terms( $args ) {
        $where = 'tt.taxonomy = ' . $this->live_quote( (string) $args['taxonomy'] );

        if ( isset( $args['parent'] ) ) {
            $where .= ' AND tt.parent = ' . (int) $args['parent'];
        }

        if ( isset( $args['name'] ) ) {
            $where .= ' AND t.name = ' . $this->live_quote( (string) $args['name'] );
        }

        $limit = empty( $args['number'] ) ? '' : ' LIMIT ' . (int) $args['number'];
        $rows  = $this->live_rows(
            'SELECT t.term_id, t.name, t.slug, tt.term_taxonomy_id, tt.taxonomy, tt.description, tt.parent, tt.count FROM '
            . $this->table( 'terms' ) . ' t JOIN ' . $this->table( 'term_taxonomy' ) . ' tt ON tt.term_id = t.term_id WHERE '
            . $where . ' ORDER BY t.name ASC' . $limit
        );

        $terms = array();

        foreach ( $rows as $row ) {
            if ( isset( $args['fields'] ) && 'id=>parent' === $args['fields'] ) {
                $terms[ (int) $row['term_id'] ] = (int) $row['parent'];
                continue;
            }

            $terms[] = $this->live_term_object( $row );
        }

        return $terms;
    }

    /**
     * Stand-in for `get_term()`.
     *
     * @since 1.0.0
     *
     * @param int    $term_id  The term ID.
     * @param string $taxonomy The taxonomy.
     *
     * @return WP_Term|null The term, or null when there is none.
     */
    public function live_get_term( $term_id, $taxonomy ) {
        $row = $this->live_term_row( (int) $term_id, (string) $taxonomy );

        return null === $row ? null : $this->live_term_object( $row );
    }

    /**
     * Stand-in for `get_term_by()`, by slug only.
     *
     * @since 1.0.0
     *
     * @param string $field    The field to match; must be `slug`.
     * @param string $value    The slug.
     * @param string $taxonomy The taxonomy.
     *
     * @return WP_Term|false The term, or false when there is none.
     */
    public function live_get_term_by( $field, $value, $taxonomy ) {
        $this->assertSame( 'slug', $field, 'Only slug lookups are supported by this stand-in.' );

        $owner = $this->live_slug_owner( (string) $value, (string) $taxonomy );

        return null === $owner ? false : $this->live_get_term( (int) $owner['term_id'], (string) $taxonomy );
    }

    /**
     * A slug made from a name, the way the fixtures make them.
     *
     * @since 1.0.0
     *
     * @param string $name The term name.
     *
     * @return string The slug.
     */
    private function live_slug_from( $name ) {
        return trim( (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( html_entity_decode( (string) $name, ENT_QUOTES, 'UTF-8' ) ) ), '-' );
    }

    /**
     * The term that has a slug in a taxonomy.
     *
     * @since 1.0.0
     *
     * @param string $slug     The slug.
     * @param string $taxonomy The taxonomy.
     *
     * @return array<string, string>|null The term row, or null when the slug is free.
     */
    private function live_slug_owner( $slug, $taxonomy ) {
        $rows = $this->live_rows(
            'SELECT t.term_id, tt.parent FROM ' . $this->table( 'terms' ) . ' t JOIN ' . $this->table( 'term_taxonomy' ) . ' tt ON tt.term_id = t.term_id WHERE t.slug = '
            . $this->live_quote( $slug ) . ' AND tt.taxonomy = ' . $this->live_quote( $taxonomy ) . ' LIMIT 1'
        );

        return empty( $rows ) ? null : $rows[0];
    }

    /**
     * A free slug, as wp_unique_term_slug() picks one.
     *
     * Under a parent the parent's slug is tried first, then a number.
     *
     * @since 1.0.0
     *
     * @param string $slug      The wanted slug.
     * @param string $taxonomy  The taxonomy.
     * @param int    $parent_id The parent term ID, 0 at the top level.
     *
     * @return string The slug, made unique in the taxonomy.
     */
    private function live_unique_slug( $slug, $taxonomy, $parent_id ) {
        if ( null === $this->live_slug_owner( $slug, $taxonomy ) ) {
            return $slug;
        }

        if ( $parent_id > 0 ) {
            $parent = $this->live_term_row( $parent_id, $taxonomy );

            if ( null !== $parent && null === $this->live_slug_owner( $slug . '-' . $parent['slug'], $taxonomy ) ) {
                return $slug . '-' . $parent['slug'];
            }
        }

        $number = 2;

        while ( null !== $this->live_slug_owner( $slug . '-' . $number, $taxonomy ) ) {
            ++$number;
        }

        return $slug . '-' . $number;
    }

    /**
     * A term name as core stores it: `&`, `<` and `>` encoded, quotes left alone.
     *
     * @since 1.0.0
     *
     * @param string $name The name.
     *
     * @return string The stored name.
     */
    private function live_store_name( $name ) {
        return htmlspecialchars( (string) $name, ENT_NOQUOTES, 'UTF-8', false );
    }

    /**
     * A term description as core stores it: a bare `&` encoded by kses.
     *
     * @since 1.0.0
     *
     * @param string $description The description.
     *
     * @return string The stored description.
     */
    private function live_store_description( $description ) {
        return (string) preg_replace( '/&(?![a-zA-Z][a-zA-Z0-9]*;|#[0-9]+;|#x[0-9a-fA-F]+;)/', '&amp;', (string) $description );
    }

    /**
     * Stand-in for wp_insert_term().
     *
     * Refuses a name a sibling already has, and makes the slug unique.
     *
     * @since 1.0.0
     *
     * @param string               $name     The term name.
     * @param string               $taxonomy The taxonomy.
     * @param array<string, mixed> $args     Any of `slug`, `description` and `parent`.
     *
     * @return array{term_id: int, term_taxonomy_id: int}|WP_Error The new IDs, or `term_exists`.
     */
    public function live_insert_term( $name, $taxonomy, $args = array() ) {
        $name      = $this->live_store_name( stripslashes( (string) $name ) );
        $parent_id = isset( $args['parent'] ) ? (int) $args['parent'] : 0;
        $sibling   = $this->live_rows(
            'SELECT t.term_id FROM ' . $this->table( 'terms' ) . ' t JOIN ' . $this->table( 'term_taxonomy' ) . ' tt ON tt.term_id = t.term_id WHERE t.name = '
            . $this->live_quote( $name ) . ' AND tt.taxonomy = ' . $this->live_quote( $taxonomy ) . ' AND tt.parent = ' . $parent_id . ' LIMIT 1'
        );

        if ( ! empty( $sibling ) ) {
            return new WP_Error( 'term_exists', 'A term with the name provided already exists with this parent.', (int) $sibling[0]['term_id'] );
        }

        $slug = isset( $args['slug'] ) && '' !== $args['slug'] ? (string) $args['slug'] : $this->live_slug_from( $name );
        $slug = $this->live_unique_slug( $slug, (string) $taxonomy, $parent_id );

        $this->live_query( 'INSERT INTO ' . $this->table( 'terms' ) . ' (name, slug) VALUES (' . $this->live_quote( $name ) . ', ' . $this->live_quote( $slug ) . ')' );

        $term_id = (int) $this->db->insert_id;

        $this->live_query(
            'INSERT INTO ' . $this->table( 'term_taxonomy' ) . ' (term_id, taxonomy, description, parent) VALUES ('
            . $term_id . ', ' . $this->live_quote( $taxonomy ) . ', ' . $this->live_quote( $this->live_store_description( isset( $args['description'] ) ? stripslashes( (string) $args['description'] ) : '' ) ) . ', '
            . $parent_id . ')'
        );

        return array(
            'term_id'          => $term_id,
            'term_taxonomy_id' => (int) $this->db->insert_id,
        );
    }

    /**
     * Stand-in for wp_update_term().
     *
     * An empty slug is made from the name. A slug another term has is made
     * unique when the slug was empty or the parents differ, and refused as
     * `duplicate_term_slug` otherwise, as core does.
     *
     * @since 1.0.0
     *
     * @param int                  $term_id  The term ID.
     * @param string               $taxonomy The taxonomy.
     * @param array<string, mixed> $args     Any of `name`, `slug`, `description` and `parent`.
     *
     * @return array{term_id: int, term_taxonomy_id: int}|WP_Error The IDs, or an error.
     */
    public function live_update_term( $term_id, $taxonomy, $args = array() ) {
        $row = $this->live_term_row( (int) $term_id, (string) $taxonomy );

        if ( null === $row ) {
            return new WP_Error( 'invalid_term', 'Empty Term.' );
        }

        $name      = isset( $args['name'] ) ? $this->live_store_name( stripslashes( (string) $args['name'] ) ) : (string) $row['name'];
        $parent_id = isset( $args['parent'] ) ? (int) $args['parent'] : (int) $row['parent'];
        $empty     = isset( $args['slug'] ) && '' === (string) $args['slug'];
        $slug      = $empty ? $this->live_slug_from( $name ) : ( isset( $args['slug'] ) ? (string) $args['slug'] : (string) $row['slug'] );
        $owner     = $this->live_slug_owner( $slug, (string) $taxonomy );

        if ( null !== $owner && (int) $owner['term_id'] !== (int) $term_id ) {
            if ( ! $empty && (int) $owner['parent'] === $parent_id ) {
                return new WP_Error( 'duplicate_term_slug', 'The slug is already in use by another term.' );
            }

            $slug = $this->live_unique_slug( $slug, (string) $taxonomy, $parent_id );
        }

        $this->live_query( 'UPDATE ' . $this->table( 'terms' ) . ' SET name = ' . $this->live_quote( $name ) . ', slug = ' . $this->live_quote( $slug ) . ' WHERE term_id = ' . (int) $term_id );

        if ( isset( $args['description'] ) ) {
            $this->live_query( 'UPDATE ' . $this->table( 'term_taxonomy' ) . ' SET description = ' . $this->live_quote( $this->live_store_description( stripslashes( (string) $args['description'] ) ) ) . ' WHERE term_taxonomy_id = ' . (int) $row['term_taxonomy_id'] );
        }

        if ( isset( $args['parent'] ) ) {
            $this->live_query( 'UPDATE ' . $this->table( 'term_taxonomy' ) . ' SET parent = ' . (int) $args['parent'] . ' WHERE term_taxonomy_id = ' . (int) $row['term_taxonomy_id'] );
        }

        return array(
            'term_id'          => (int) $term_id,
            'term_taxonomy_id' => (int) $row['term_taxonomy_id'],
        );
    }

    /**
     * Stand-in for `wp_delete_term()`.
     *
     * Children move up to the deleted term's parent, as in core.
     *
     * @since 1.0.0
     *
     * @param int    $term_id  The term ID.
     * @param string $taxonomy The taxonomy.
     *
     * @return bool True when the term was deleted.
     */
    public function live_delete_term( $term_id, $taxonomy ) {
        $row = $this->live_term_row( (int) $term_id, (string) $taxonomy );

        if ( null === $row ) {
            return false;
        }

        $tt_id = (int) $row['term_taxonomy_id'];

        $this->live_query( 'UPDATE ' . $this->table( 'term_taxonomy' ) . ' SET parent = ' . (int) $row['parent'] . ' WHERE parent = ' . (int) $term_id . ' AND taxonomy = ' . $this->live_quote( $taxonomy ) );
        $this->live_query( 'DELETE FROM ' . $this->table( 'term_relationships' ) . ' WHERE term_taxonomy_id = ' . $tt_id );
        $this->live_query( 'DELETE FROM ' . $this->table( 'termmeta' ) . ' WHERE term_id = ' . (int) $term_id );
        $this->live_query( 'DELETE FROM ' . $this->table( 'term_taxonomy' ) . ' WHERE term_taxonomy_id = ' . $tt_id );
        $this->live_query( 'DELETE FROM ' . $this->table( 'terms' ) . ' WHERE term_id = ' . (int) $term_id );

        return true;
    }

    /**
     * Stand-in for `get_objects_in_term()`.
     *
     * @since 1.0.0
     *
     * @param int    $term_id  The term ID.
     * @param string $taxonomy The taxonomy.
     *
     * @return string[] The object IDs, as core returns them.
     */
    public function live_objects_in_term( $term_id, $taxonomy ) {
        $row = $this->live_term_row( (int) $term_id, (string) $taxonomy );

        if ( null === $row ) {
            return array();
        }

        $rows = $this->live_rows( 'SELECT object_id FROM ' . $this->table( 'term_relationships' ) . ' WHERE term_taxonomy_id = ' . (int) $row['term_taxonomy_id'] . ' ORDER BY object_id' );

        return array_column( $rows, 'object_id' );
    }

    /**
     * Make the relationship delete of one term fail for one object.
     *
     * Swaps `$wpdb` for an adapter that refuses the batched `DELETE` taking
     * the term off a batch holding the object, as a failing server would;
     * every other statement runs as usual.
     *
     * @since 1.0.0
     *
     * @param int    $term_id   The term whose relationship delete fails.
     * @param string $taxonomy  The term's taxonomy.
     * @param int    $object_id The object whose batch fails.
     *
     * @return void
     */
    protected function live_fail_removal( $term_id, $taxonomy, $object_id ) {
        $tt_id   = (int) $this->live_term_row( (int) $term_id, (string) $taxonomy )['term_taxonomy_id'];
        $pattern = '/^DELETE FROM \S+ WHERE term_taxonomy_id = ' . $tt_id . ' AND object_id IN \(.*\b' . (int) $object_id . '\b/';

        $GLOBALS['wpdb'] = new class( $this->db, self::PREFIX, $pattern ) extends Licensio_KB_Live_WPDB { // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The suite's database adapter.

            /**
             * The statement to refuse.
             *
             * @since 1.0.0
             * @var string
             */
            private $refused;

            /**
             * Build the adapter.
             *
             * @since 1.0.0
             *
             * @param mysqli $db      A live connection.
             * @param string $prefix  The table prefix in use.
             * @param string $refused A pattern matching the statement to refuse.
             */
            public function __construct( $db, $prefix, $refused ) {
                parent::__construct( $db, $prefix );

                $this->refused = $refused;
            }

            /**
             * Run a statement, unless it is the refused one.
             *
             * @since 1.0.0
             *
             * @param string $query The SQL statement.
             *
             * @return int|false Rows affected, or false for the refused statement.
             */
            public function query( $query ) {
                return preg_match( $this->refused, (string) $query ) ? false : parent::query( $query );
            }
        };
    }

    /**
     * Stand-in for `wp_update_term_count()`.
     *
     * @since 1.0.0
     *
     * @param int[]|int $tt_ids   The term taxonomy IDs to recount.
     * @param string    $taxonomy The taxonomy.
     *
     * @return bool True.
     */
    public function live_update_term_count( $tt_ids, $taxonomy ) {
        unset( $taxonomy );

        foreach ( (array) $tt_ids as $tt_id ) {
            $this->live_recount( (int) $tt_id );
        }

        return true;
    }

    /**
     * Recount a term from the published articles assigned to it.
     *
     * @since 1.0.0
     *
     * @param int $tt_id The term taxonomy ID.
     *
     * @return void
     */
    private function live_recount( $tt_id ) {
        $this->live_query(
            'UPDATE ' . $this->table( 'term_taxonomy' ) . ' SET count = (SELECT COUNT(*) FROM ' . $this->table( 'term_relationships' ) . ' r JOIN '
            . $this->table( 'posts' ) . " p ON p.ID = r.object_id WHERE r.term_taxonomy_id = {$tt_id} AND p.post_status = 'publish') WHERE term_taxonomy_id = {$tt_id}"
        );
    }

    /**
     * Stand-in for `get_term_meta()` with `$single` true.
     *
     * @since 1.0.0
     *
     * @param int    $term_id The term ID.
     * @param string $key     The meta key.
     *
     * @return string The value, or an empty string when there is none.
     */
    public function live_get_term_meta( $term_id, $key ) {
        $rows = $this->live_rows( 'SELECT meta_value FROM ' . $this->table( 'termmeta' ) . ' WHERE term_id = ' . (int) $term_id . ' AND meta_key = ' . $this->live_quote( $key ) . ' LIMIT 1' );

        return empty( $rows ) ? '' : (string) $rows[0]['meta_value'];
    }

    /**
     * Stand-in for `update_term_meta()`.
     *
     * @since 1.0.0
     *
     * @param int    $term_id The term ID.
     * @param string $key     The meta key.
     * @param mixed  $value   The value.
     *
     * @return bool True.
     */
    public function live_update_term_meta( $term_id, $key, $value ) {
        $this->live_query( 'DELETE FROM ' . $this->table( 'termmeta' ) . ' WHERE term_id = ' . (int) $term_id . ' AND meta_key = ' . $this->live_quote( $key ) );
        $this->live_query( 'INSERT INTO ' . $this->table( 'termmeta' ) . ' (term_id, meta_key, meta_value) VALUES (' . (int) $term_id . ', ' . $this->live_quote( $key ) . ', ' . $this->live_quote( stripslashes( (string) $value ) ) . ')' );

        return true;
    }
}
