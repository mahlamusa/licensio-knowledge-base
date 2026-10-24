<?php
/**
 * SQL-backed WordPress writes for driving an article import.
 *
 * The importers' article phase reads the source with its production SQL, then
 * writes through core functions this suite has no WordPress for. This trait
 * backs those functions with plain SQL on the scratch tables, so a test can
 * run `migrate_articles_batch()` and read back the rows it really wrote.
 *
 * Used by the Heroic and BetterDocs import tests, whose test cases supply
 * `table()`, `seed()` and the mysqli connection.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

/*
 * This suite connects to a real database on purpose. The WordPress Database API
 * is therefore not available or appropriate here.
 */
// phpcs:disable WordPress.DB.RestrictedClasses, WordPress.DB.RestrictedFunctions, WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery

/**
 * Runs an article import against the scratch tables.
 *
 * @since 1.0.0
 */
trait Licensio_KB_Article_Import_Stubs {

    /**
     * The fully qualified name of one of the scratch tables.
     *
     * @since 1.0.0
     *
     * @param string $table The unprefixed table name.
     *
     * @return string The prefixed table name.
     */
    abstract protected function table( $table );

    /**
     * Insert rows straight into a scratch table.
     *
     * @since 1.0.0
     *
     * @param string                           $table The unprefixed table name.
     * @param array<int, array<string, mixed>> $rows  The rows to insert.
     *
     * @return void
     */
    abstract protected function seed( $table, array $rows );

    /**
     * Create the term tables the article phase reads, when the test case has none.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function install_term_tables() {
        $prefix  = $this->table( '' );
        $schemas = array(
            "CREATE TABLE IF NOT EXISTS {$prefix}terms (
                term_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                name varchar(200) NOT NULL DEFAULT '',
                slug varchar(200) NOT NULL DEFAULT '',
                term_group bigint(10) NOT NULL DEFAULT 0,
                PRIMARY KEY  (term_id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;",
            "CREATE TABLE IF NOT EXISTS {$prefix}term_taxonomy (
                term_taxonomy_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                term_id bigint(20) unsigned NOT NULL DEFAULT 0,
                taxonomy varchar(32) NOT NULL DEFAULT '',
                description longtext NOT NULL,
                parent bigint(20) unsigned NOT NULL DEFAULT 0,
                count bigint(20) NOT NULL DEFAULT 0,
                PRIMARY KEY  (term_taxonomy_id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;",
            "CREATE TABLE IF NOT EXISTS {$prefix}term_relationships (
                object_id bigint(20) unsigned NOT NULL DEFAULT 0,
                term_taxonomy_id bigint(20) unsigned NOT NULL DEFAULT 0,
                term_order int(11) NOT NULL DEFAULT 0,
                PRIMARY KEY  (object_id, term_taxonomy_id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;",
            "CREATE TABLE IF NOT EXISTS {$prefix}termmeta (
                meta_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                term_id bigint(20) unsigned NOT NULL DEFAULT 0,
                meta_key varchar(255) DEFAULT NULL,
                meta_value longtext,
                PRIMARY KEY  (meta_id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;",
        );

        foreach ( $schemas as $sql ) {
            $this->assertTrue( (bool) $this->db->query( $sql ), $this->db->error );
        }
    }

    /**
     * Back the core write functions the article phase calls with SQL.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function stub_article_writes() {
        Brain\Monkey\Functions\when( 'is_wp_error' )->alias(
            function ( $thing ) {
                return $thing instanceof WP_Error;
            }
        );

        Brain\Monkey\Functions\when( 'wp_insert_post' )->alias(
            function ( $post ) {
                $this->seed(
                    'posts',
                    array(
                        array(
                            'post_title'  => (string) $post['post_title'],
                            'post_name'   => (string) $post['post_name'],
                            'post_type'   => (string) $post['post_type'],
                            'post_status' => (string) $post['post_status'],
                            'menu_order'  => (int) $post['menu_order'],
                        ),
                    )
                );

                return (int) $this->db->insert_id;
            }
        );

        Brain\Monkey\Functions\when( 'update_post_meta' )->alias(
            function ( $post_id, $key, $value ) {
                $this->db->query( 'DELETE FROM ' . $this->table( 'postmeta' ) . ' WHERE post_id = ' . (int) $post_id . " AND meta_key = '" . $this->db->real_escape_string( (string) $key ) . "'" );
                $this->seed(
                    'postmeta',
                    array(
                        array(
                            'post_id'    => (int) $post_id,
                            'meta_key'   => (string) $key,
                            'meta_value' => (string) $value,
                        ),
                    )
                );

                return true;
            }
        );

        Brain\Monkey\Functions\when( 'get_post_meta' )->alias(
            function ( $post_id, $key ) {
                $result = $this->db->query( 'SELECT meta_value FROM ' . $this->table( 'postmeta' ) . ' WHERE post_id = ' . (int) $post_id . " AND meta_key = '" . $this->db->real_escape_string( (string) $key ) . "' LIMIT 1" );
                $row    = $result instanceof mysqli_result ? $result->fetch_row() : null;

                return is_array( $row ) ? (string) $row[0] : '';
            }
        );

        Brain\Monkey\Functions\when( 'get_term_meta' )->alias(
            function ( $term_id, $key ) {
                $result = $this->db->query( 'SELECT meta_value FROM ' . $this->table( 'termmeta' ) . ' WHERE term_id = ' . (int) $term_id . " AND meta_key = '" . $this->db->real_escape_string( (string) $key ) . "' ORDER BY meta_id ASC" );

                return $result instanceof mysqli_result ? array_column( $result->fetch_all( MYSQLI_NUM ), 0 ) : array();
            }
        );

        Brain\Monkey\Functions\when( 'wp_set_object_terms' )->returnArg( 2 );

        Brain\Monkey\Functions\when( 'get_post_field' )->alias(
            function ( $field, $post_id ) {
                $result = $this->db->query( 'SELECT post_name FROM ' . $this->table( 'posts' ) . ' WHERE ID = ' . (int) $post_id );
                $row    = $result instanceof mysqli_result ? $result->fetch_row() : null;

                return 'post_name' === $field && is_array( $row ) ? (string) $row[0] : '';
            }
        );

        Brain\Monkey\Functions\when( 'remove_accents' )->returnArg();
        Brain\Monkey\Functions\when( 'get_permalink' )->justReturn( '' );
        Brain\Monkey\Functions\when( 'wp_parse_url' )->justReturn( '' );
    }

    /**
     * Create a target category already imported from the given source categories.
     *
     * This is the state the terms phase leaves: a `licensio_category` carrying
     * one provenance row per source category, in import order.
     *
     * @since 1.0.0
     *
     * @param int    $term_id    The target term ID.
     * @param string $key        The source's provenance term meta key.
     * @param int[]  $source_ids The source category term IDs, in import order.
     *
     * @return void
     */
    protected function seed_target_category( $term_id, $key, array $source_ids ) {
        $this->seed(
            'term_taxonomy',
            array(
                array(
                    'term_taxonomy_id' => (int) $term_id,
                    'term_id'          => (int) $term_id,
                    'taxonomy'         => Licensio_KB_Post_Types::TAXONOMY_CATEGORY,
                    'description'      => '',
                ),
            )
        );

        foreach ( $source_ids as $source_id ) {
            $this->seed(
                'termmeta',
                array(
                    array(
                        'term_id'    => (int) $term_id,
                        'meta_key'   => (string) $key,
                        'meta_value' => (string) (int) $source_id,
                    ),
                )
            );
        }
    }

    /**
     * Every `licensio_order_*` value written, by imported article title.
     *
     * @since 1.0.0
     *
     * @return array<string, array<string, int>> Article title to order key to position.
     */
    protected function written_positions() {
        $sql = 'SELECT p.post_title, m.meta_key, m.meta_value FROM ' . $this->table( 'postmeta' ) . ' m
            INNER JOIN ' . $this->table( 'posts' ) . " p ON p.ID = m.post_id
            WHERE p.post_type = 'licensio_article' AND m.meta_key LIKE 'licensio\\_order\\_%'
            ORDER BY p.post_title ASC, m.meta_key ASC";

        $positions = array();

        foreach ( $this->db->query( $sql )->fetch_all( MYSQLI_NUM ) as $row ) {
            $positions[ (string) $row[0] ][ (string) $row[1] ] = (int) $row[2];
        }

        return $positions;
    }
}
