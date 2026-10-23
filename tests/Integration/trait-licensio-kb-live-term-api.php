<?php
/**
 * The core term functions, run as SQL against the scratch database.
 *
 * No WordPress is loaded in the live database suite, so the term API the
 * categories controller and Licensio_KB_Term_Operations call does not exist.
 * This trait supplies the few functions they use, through Brain Monkey, as
 * plain SQL against the core term tables `install_content_schemas()` creates.
 * Each one does what core does to the rows that matter here:
 *
 * - `wp_delete_term()` removes the term's assignments and meta and moves its
 *   children up to its own parent, as core does.
 * - Every assignment change recounts the term from the published articles
 *   assigned to it, as core's `_update_post_term_count()` does.
 *
 * Use it with Licensio_KB_Content_Fixtures in a subclass of
 * Licensio_KB_Analytics_DB_Test_Case and call `stub_term_api()` from `setUp()`.
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
     * Stub every core term function the categories routes call.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function stub_term_api() {
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

        Functions\when( 'get_terms' )->alias( array( $this, 'live_get_terms' ) );
        Functions\when( 'get_term' )->alias( array( $this, 'live_get_term' ) );
        Functions\when( 'wp_insert_term' )->alias( array( $this, 'live_insert_term' ) );
        Functions\when( 'wp_update_term' )->alias( array( $this, 'live_update_term' ) );
        Functions\when( 'wp_delete_term' )->alias( array( $this, 'live_delete_term' ) );
        Functions\when( 'get_objects_in_term' )->alias( array( $this, 'live_objects_in_term' ) );
        Functions\when( 'wp_set_object_terms' )->alias( array( $this, 'live_set_object_terms' ) );
        Functions\when( 'wp_remove_object_terms' )->alias( array( $this, 'live_remove_object_terms' ) );
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
     * Stand-in for `get_terms()`: every term of one taxonomy.
     *
     * Supports the default `fields` (WP_Term objects) and `id=>parent`.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $args The query arguments.
     *
     * @return array<int|string, mixed> The terms.
     */
    public function live_get_terms( $args ) {
        $rows = $this->live_rows(
            'SELECT t.term_id, t.name, t.slug, tt.term_taxonomy_id, tt.taxonomy, tt.description, tt.parent, tt.count FROM '
            . $this->table( 'terms' ) . ' t JOIN ' . $this->table( 'term_taxonomy' ) . ' tt ON tt.term_id = t.term_id WHERE tt.taxonomy = '
            . $this->live_quote( (string) $args['taxonomy'] ) . ' ORDER BY t.name ASC'
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
     * Stand-in for `wp_insert_term()`.
     *
     * @since 1.0.0
     *
     * @param string               $name     The term name.
     * @param string               $taxonomy The taxonomy.
     * @param array<string, mixed> $args     `slug`, `parent` and `description`.
     *
     * @return array{term_id: int, term_taxonomy_id: int} The new IDs.
     */
    public function live_insert_term( $name, $taxonomy, $args = array() ) {
        $slug = isset( $args['slug'] ) && '' !== $args['slug'] ? (string) $args['slug'] : trim( (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $name ) ), '-' );

        $this->live_query( 'INSERT INTO ' . $this->table( 'terms' ) . ' (name, slug) VALUES (' . $this->live_quote( $name ) . ', ' . $this->live_quote( $slug ) . ')' );

        $term_id = (int) $this->db->insert_id;

        $this->live_query(
            'INSERT INTO ' . $this->table( 'term_taxonomy' ) . ' (term_id, taxonomy, description, parent) VALUES ('
            . $term_id . ', ' . $this->live_quote( $taxonomy ) . ', ' . $this->live_quote( isset( $args['description'] ) ? (string) $args['description'] : '' ) . ', '
            . ( isset( $args['parent'] ) ? (int) $args['parent'] : 0 ) . ')'
        );

        return array(
            'term_id'          => $term_id,
            'term_taxonomy_id' => (int) $this->db->insert_id,
        );
    }

    /**
     * Stand-in for `wp_update_term()`.
     *
     * @since 1.0.0
     *
     * @param int                  $term_id  The term ID.
     * @param string               $taxonomy The taxonomy.
     * @param array<string, mixed> $args     Any of `name`, `slug`, `parent` and `description`.
     *
     * @return array{term_id: int, term_taxonomy_id: int}|WP_Error The IDs, or an error for a missing term.
     */
    public function live_update_term( $term_id, $taxonomy, $args = array() ) {
        $row = $this->live_term_row( (int) $term_id, (string) $taxonomy );

        if ( null === $row ) {
            return new WP_Error( 'invalid_term', 'Empty Term.' );
        }

        foreach ( array( 'name', 'slug' ) as $field ) {
            if ( isset( $args[ $field ] ) ) {
                $this->live_query( 'UPDATE ' . $this->table( 'terms' ) . " SET {$field} = " . $this->live_quote( $args[ $field ] ) . ' WHERE term_id = ' . (int) $term_id );
            }
        }

        if ( isset( $args['description'] ) ) {
            $this->live_query( 'UPDATE ' . $this->table( 'term_taxonomy' ) . ' SET description = ' . $this->live_quote( $args['description'] ) . ' WHERE term_taxonomy_id = ' . (int) $row['term_taxonomy_id'] );
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
     * Stand-in for `wp_set_object_terms()` in append mode.
     *
     * @since 1.0.0
     *
     * @param int    $object_id The object ID.
     * @param int[]  $terms     The term IDs to add.
     * @param string $taxonomy  The taxonomy.
     * @param bool   $append    Must be true; replacing is not supported here.
     *
     * @return int[] The term taxonomy IDs added.
     */
    public function live_set_object_terms( $object_id, $terms, $taxonomy, $append = false ) {
        $this->assertTrue( $append, 'Only appending is supported by this stand-in.' );

        $added = array();

        foreach ( (array) $terms as $term_id ) {
            $row = $this->live_term_row( (int) $term_id, (string) $taxonomy );

            $this->assertNotNull( $row, 'No term ' . (int) $term_id . ' to assign.' );

            $this->live_query( 'INSERT IGNORE INTO ' . $this->table( 'term_relationships' ) . ' (object_id, term_taxonomy_id) VALUES (' . (int) $object_id . ', ' . (int) $row['term_taxonomy_id'] . ')' );
            $this->live_recount( (int) $row['term_taxonomy_id'] );

            $added[] = (int) $row['term_taxonomy_id'];
        }

        return $added;
    }

    /**
     * Stand-in for `wp_remove_object_terms()`.
     *
     * @since 1.0.0
     *
     * @param int    $object_id The object ID.
     * @param int[]  $terms     The term IDs to remove.
     * @param string $taxonomy  The taxonomy.
     *
     * @return bool True.
     */
    public function live_remove_object_terms( $object_id, $terms, $taxonomy ) {
        foreach ( (array) $terms as $term_id ) {
            $row = $this->live_term_row( (int) $term_id, (string) $taxonomy );

            if ( null === $row ) {
                continue;
            }

            $this->live_query( 'DELETE FROM ' . $this->table( 'term_relationships' ) . ' WHERE object_id = ' . (int) $object_id . ' AND term_taxonomy_id = ' . (int) $row['term_taxonomy_id'] );
            $this->live_recount( (int) $row['term_taxonomy_id'] );
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
        $this->live_query( 'INSERT INTO ' . $this->table( 'termmeta' ) . ' (term_id, meta_key, meta_value) VALUES (' . (int) $term_id . ', ' . $this->live_quote( $key ) . ', ' . $this->live_quote( (string) $value ) . ')' );

        return true;
    }
}
