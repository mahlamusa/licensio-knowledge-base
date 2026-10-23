<?php
/**
 * The core term functions the term write routes call, over the live tables.
 *
 * No WordPress is loaded in the live database suite, so `get_term()`,
 * `wp_set_object_terms()`, `wp_delete_term()` and the rest do not exist. This
 * trait stands each one in with Brain Monkey as a few plain SQL statements on
 * the scratch `terms`, `term_taxonomy` and `term_relationships` tables, so a
 * route's real code runs and its effect is read back from real rows. Term
 * counts follow core: only published articles are counted.
 *
 * The shims cover only what the term routes use: lookups by ID and slug,
 * appending and removing assignments, inserting with a slug, parent and
 * description, and deleting a term with its assignments.
 *
 * Use it with Licensio_KB_Content_Fixtures in a subclass of
 * Licensio_KB_Analytics_DB_Test_Case, after `install_content_schemas()`:
 *
 *     $this->install_content_schemas();
 *     $this->stub_term_api();
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

/*
 * This suite connects to a real database on purpose. The WordPress Database API
 * is therefore not available or appropriate here.
 */
// phpcs:disable WordPress.DB.RestrictedClasses, WordPress.DB.RestrictedFunctions, WordPress.DB.DirectDatabaseQuery

/**
 * Stands in the core term API over the scratch database.
 *
 * @since 1.0.0
 */
trait Licensio_KB_Term_API_Shims {

    /**
     * Stub the core term functions with SQL against the scratch tables.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function stub_term_api() {
        Brain\Monkey\Functions\when( 'get_term' )->alias(
            function ( $term_id, $taxonomy ) {
                return $this->shim_find_term( 't.term_id = ' . (int) $term_id, $taxonomy );
            }
        );

        Brain\Monkey\Functions\when( 'get_term_by' )->alias(
            function ( $field, $value, $taxonomy ) {
                $this->assertSame( 'slug', $field, 'Only slug lookups are shimmed.' );

                $term = $this->shim_find_term( "t.slug = '" . $this->db->real_escape_string( (string) $value ) . "'", $taxonomy );

                return null === $term ? false : $term;
            }
        );

        Brain\Monkey\Functions\when( 'get_objects_in_term' )->alias(
            function ( $term_id, $taxonomy ) {
                $tt_id = $this->shim_tt_id( (int) $term_id, $taxonomy );

                return $this->shim_column( 'SELECT object_id FROM ' . $this->table( 'term_relationships' ) . ' WHERE term_taxonomy_id = ' . $tt_id . ' ORDER BY object_id' );
            }
        );

        Brain\Monkey\Functions\when( 'wp_set_object_terms' )->alias(
            function ( $object_id, $terms, $taxonomy, $append = false ) {
                $this->assertTrue( $append, 'Only appending assignments is shimmed.' );

                $tt_ids = array();

                foreach ( (array) $terms as $term_id ) {
                    $tt_id = $this->shim_tt_id( (int) $term_id, $taxonomy );

                    $this->shim_query( 'INSERT IGNORE INTO ' . $this->table( 'term_relationships' ) . ' (object_id, term_taxonomy_id) VALUES (' . (int) $object_id . ', ' . $tt_id . ')' );
                    $this->shim_recount( $tt_id );

                    $tt_ids[] = $tt_id;
                }

                return $tt_ids;
            }
        );

        Brain\Monkey\Functions\when( 'wp_remove_object_terms' )->alias(
            function ( $object_id, $terms, $taxonomy ) {
                foreach ( (array) $terms as $term_id ) {
                    $tt_id = $this->shim_tt_id( (int) $term_id, $taxonomy );

                    $this->shim_query( 'DELETE FROM ' . $this->table( 'term_relationships' ) . ' WHERE object_id = ' . (int) $object_id . ' AND term_taxonomy_id = ' . $tt_id );
                    $this->shim_recount( $tt_id );
                }

                return true;
            }
        );

        Brain\Monkey\Functions\when( 'wp_insert_term' )->alias(
            function ( $name, $taxonomy, $args = array() ) {
                $slug = isset( $args['slug'] ) ? (string) $args['slug'] : trim( (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $name ) ), '-' );

                $this->shim_query(
                    sprintf(
                        "INSERT INTO %s (name, slug) VALUES ('%s', '%s')",
                        $this->table( 'terms' ),
                        $this->db->real_escape_string( (string) $name ),
                        $this->db->real_escape_string( $slug )
                    )
                );

                $term_id = (int) $this->db->insert_id;

                $this->shim_query(
                    sprintf(
                        "INSERT INTO %s (term_id, taxonomy, description, parent) VALUES (%d, '%s', '%s', %d)",
                        $this->table( 'term_taxonomy' ),
                        $term_id,
                        $this->db->real_escape_string( (string) $taxonomy ),
                        $this->db->real_escape_string( isset( $args['description'] ) ? (string) $args['description'] : '' ),
                        isset( $args['parent'] ) ? (int) $args['parent'] : 0
                    )
                );

                return array(
                    'term_id'          => $term_id,
                    'term_taxonomy_id' => (int) $this->db->insert_id,
                );
            }
        );

        Brain\Monkey\Functions\when( 'wp_delete_term' )->alias(
            function ( $term_id, $taxonomy ) {
                $tt_id = $this->shim_tt_id( (int) $term_id, $taxonomy );

                $this->shim_query( 'DELETE FROM ' . $this->table( 'term_relationships' ) . ' WHERE term_taxonomy_id = ' . $tt_id );
                $this->shim_query( 'DELETE FROM ' . $this->table( 'term_taxonomy' ) . ' WHERE term_taxonomy_id = ' . $tt_id );
                $this->shim_query( 'DELETE FROM ' . $this->table( 'terms' ) . ' WHERE term_id = ' . (int) $term_id );

                return true;
            }
        );
    }

    /**
     * One term matching a condition in a taxonomy.
     *
     * @since 1.0.0
     *
     * @param string $where    A condition on the `t` terms alias, already escaped.
     * @param string $taxonomy The taxonomy.
     *
     * @return WP_Term|null The term, or null when there is none.
     */
    private function shim_find_term( $where, $taxonomy ) {
        $result = $this->shim_query(
            'SELECT t.term_id, t.name, t.slug, tt.taxonomy, tt.description, tt.parent, tt.count FROM ' . $this->table( 'terms' ) . ' t JOIN '
            . $this->table( 'term_taxonomy' ) . " tt ON tt.term_id = t.term_id WHERE {$where} AND tt.taxonomy = '"
            . $this->db->real_escape_string( (string) $taxonomy ) . "' LIMIT 1"
        );

        $row = $result->fetch_assoc();

        if ( null === $row ) {
            return null;
        }

        foreach ( array( 'term_id', 'parent', 'count' ) as $key ) {
            $row[ $key ] = (int) $row[ $key ];
        }

        return new WP_Term( $row );
    }

    /**
     * The term taxonomy ID of a term in a taxonomy.
     *
     * @since 1.0.0
     *
     * @param int    $term_id  The term ID.
     * @param string $taxonomy The taxonomy.
     *
     * @return int The term taxonomy ID.
     */
    private function shim_tt_id( $term_id, $taxonomy ) {
        $ids = $this->shim_column(
            'SELECT term_taxonomy_id FROM ' . $this->table( 'term_taxonomy' ) . ' WHERE term_id = ' . (int) $term_id
            . " AND taxonomy = '" . $this->db->real_escape_string( (string) $taxonomy ) . "'"
        );

        $this->assertCount( 1, $ids, 'No term ' . (int) $term_id . ' in ' . $taxonomy . '.' );

        return (int) $ids[0];
    }

    /**
     * Recount a term's published articles, as core does.
     *
     * @since 1.0.0
     *
     * @param int $tt_id The term taxonomy ID.
     *
     * @return void
     */
    private function shim_recount( $tt_id ) {
        $this->shim_query(
            'UPDATE ' . $this->table( 'term_taxonomy' ) . ' SET count = ( SELECT COUNT(*) FROM ' . $this->table( 'term_relationships' ) . ' r JOIN '
            . $this->table( 'posts' ) . " p ON p.ID = r.object_id WHERE r.term_taxonomy_id = {$tt_id} AND p.post_status = 'publish' ) WHERE term_taxonomy_id = {$tt_id}"
        );
    }

    /**
     * The first column of every row of a query.
     *
     * @since 1.0.0
     *
     * @param string $sql The query.
     *
     * @return string[] The values.
     */
    private function shim_column( $sql ) {
        $result = $this->shim_query( $sql );
        $values = array();

        while ( $row = $result->fetch_row() ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
            $values[] = (string) $row[0];
        }

        return $values;
    }

    /**
     * Run a statement and fail the test if it fails.
     *
     * @since 1.0.0
     *
     * @param string $sql The statement.
     *
     * @return mixed The mysqli result.
     */
    private function shim_query( $sql ) {
        $result = $this->db->query( $sql );

        $this->assertNotFalse( $result, $this->db->error . ' in ' . $sql );

        return $result;
    }
}
