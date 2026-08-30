<?php
/**
 * The FULLTEXT search index, against a real database server.
 *
 * This suite exists because of a specific defect. The installer originally
 * decided whether FULLTEXT was available by creating a throwaway
 * `CREATE TEMPORARY TABLE ... FULLTEXT ... ENGINE=InnoDB`. MySQL rejects that
 * combination outright with errno 1796 while accepting the same key on the
 * permanent table the plugin actually needs, so every MySQL 8 host recorded
 * FULLTEXT as unavailable and silently fell back to LIKE search — the plugin's
 * headline feature, disabled everywhere.
 *
 * It passed a fully green unit suite, because the `$wpdb` double returned
 * success for every statement. No double can catch this class of bug: it is a
 * question about how a real server responds to real DDL.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

/*
 * This suite connects to a real database on purpose: it exists precisely because
 * the unit suite's doubles cannot answer how a real server responds to real DDL.
 * The WordPress Database API is therefore not available or appropriate here.
 */
// phpcs:disable WordPress.DB.RestrictedClasses, WordPress.DB.RestrictedFunctions, WordPress.DB.DirectDatabaseQuery


require_once __DIR__ . '/Lisensio_KB_Integration_Test_Case.php';
require_once __DIR__ . '/class-lisensio-kb-live-wpdb.php';

/**
 * Drives the installer's real schema output at a live server.
 *
 * @since 1.0.0
 */
class Lisensio_KB_Fulltext_Integration_Test extends Lisensio_KB_Integration_Test_Case {

    /**
     * The table prefix used for the scratch schema.
     *
     * @since 1.0.0
     * @var string
     */
    const PREFIX = 'lkbit_';

    /**
     * The schemas the plugin would run, created on the live server.
     *
     * @since 1.0.0
     *
     * @return void
     */
    private function install_schemas() {
        $GLOBALS['wpdb'] = new Lisensio_KB_Live_WPDB( $this->db, self::PREFIX );

        $schemas = Lisensio_KB_DB_Installer::get_schemas( self::PREFIX, 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci' );

        foreach ( $schemas as $name => $sql ) {
            $this->assertTrue(
                (bool) $this->db->query( $sql ),
                sprintf( 'Schema %1$s failed on %2$s: %3$s', $name, $this->server_version(), $this->db->error )
            );
        }
    }

    /**
     * Every table the plugin owns is creatable on a real server.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_every_schema_creates_on_a_real_server() {
        $this->install_schemas();

        foreach ( Lisensio_KB_DB_Installer::get_table_names() as $table ) {
            $result = $this->db->query( "SHOW TABLES LIKE '" . self::PREFIX . $table . "'" );

            $this->assertSame( 1, $result->num_rows, sprintf( 'Table %s was not created.', $table ) );
        }
    }

    /**
     * No CREATE TABLE carries the FULLTEXT key.
     *
     * A server that rejects the key inside CREATE TABLE rejects the entire
     * statement, leaving no search index table at all — worse than no index.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_no_schema_declares_the_fulltext_key() {
        foreach ( Lisensio_KB_DB_Installer::get_schemas( self::PREFIX, '' ) as $name => $sql ) {
            $this->assertStringNotContainsStringIgnoringCase( 'FULLTEXT', $sql, sprintf( 'Schema %s must not declare FULLTEXT.', $name ) );
        }
    }

    /**
     * The out-of-band ALTER succeeds and is read back as a real FULLTEXT index.
     *
     * This is the assertion the original bug would have failed.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_fulltext_index_is_added_and_detected() {
        $this->install_schemas();

        $table = self::PREFIX . 'lisensio_search_index';

        // The plugin's own out-of-band ALTER, not a hand-written approximation.
        $add = new ReflectionMethod( 'Lisensio_KB_DB_Installer', 'add_fulltext_index' );
        $add->setAccessible( true );
        $add->invoke( null );

        // The plugin's own detection, reading back from the live server.
        $this->assertTrue(
            Lisensio_KB_DB_Installer::detect_fulltext_index(),
            sprintf( 'detect_fulltext_index() returned false on %s.', $this->server_version() )
        );

        $rows = $this->db->query( "SHOW INDEX FROM {$table}" );
        $seen = array();

        while ( $row = $rows->fetch_object() ) {
            // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- MySQL's own column names.
            $seen[] = $row->Key_name . '/' . $row->Index_type;
        }

        $this->assertContains( 'ft_all/FULLTEXT', $seen, 'Indexes present: ' . implode( ', ', $seen ) );
    }

    /**
     * Matching on Index_type catches a server that downgrades the index.
     *
     * A key named ft_all that the server built as BTREE must not read as
     * success, which is why detection matches on Index_type rather than
     * Key_name.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_btree_key_named_ft_all_is_not_mistaken_for_fulltext() {
        $this->install_schemas();

        // Put a BTREE key called ft_all on the search index table itself, so the
        // plugin's own detection is the thing being fooled — or not.
        $index = self::PREFIX . 'lisensio_search_index';

        $this->assertTrue( (bool) $this->db->query( "ALTER TABLE {$index} ADD KEY ft_all (article_id)" ), $this->db->error );

        $this->assertFalse(
            Lisensio_KB_DB_Installer::detect_fulltext_index(),
            'A BTREE key named ft_all must not be detected as FULLTEXT.'
        );
    }

    /**
     * The index is usable, not merely present.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_index_actually_matches_a_search() {
        $this->install_schemas();

        $table = self::PREFIX . 'lisensio_search_index';

        $this->db->query( "ALTER TABLE {$table} ADD FULLTEXT KEY ft_all (title, content, terms)" );
        $this->db->query(
            "INSERT INTO {$table} (article_id, title, excerpt, content, terms, updated_at)
             VALUES (1, 'Installing Lisensio', 'x', 'How to install the licence server', 'setup install', '2026-01-01 00:00:00')"
        );

        $result = $this->db->query(
            "SELECT article_id FROM {$table}
             WHERE MATCH(title, content, terms) AGAINST ('install' IN BOOLEAN MODE)"
        );

        $this->assertNotFalse( $result, 'MATCH ... AGAINST failed: ' . $this->db->error );
        $this->assertSame( 1, $result->num_rows, 'The FULLTEXT index returned no match for a term present in the row.' );
    }
}
