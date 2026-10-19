<?php
/**
 * Unit tests for the database schema.
 *
 * The schema is generated as plain strings, so the whole contract can be
 * asserted without a database. The most important assertion here is the
 * privacy one: no table may carry an IP column.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

/**
 * Tests Licensio_KB_DB_Installer.
 *
 * @since 1.0.0
 */
class Licensio_KB_DB_Installer_Test extends Licensio_KB_Test_Case {

    /**
     * All five specified tables are defined.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_all_five_tables_are_defined() {
        $expected = array(
            'licensio_search_index',
            'licensio_search_log',
            'licensio_article_views',
            'licensio_feedback',
            'licensio_import_log',
        );

        $this->assertSame( $expected, Licensio_KB_DB_Installer::get_table_names() );
        $this->assertSame( $expected, array_keys( Licensio_KB_DB_Installer::get_schemas() ) );
    }

    /**
     * No table schema contains an IP column.
     *
     * Visitor identity is the rotating salted hash; the raw address is never
     * stored. This test is the guard on that promise.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_no_table_has_an_ip_column() {
        foreach ( Licensio_KB_DB_Installer::get_schemas( 'wp_', '' ) as $table => $schema ) {
            foreach ( $this->columns_of( $schema ) as $column ) {
                $this->assertDoesNotMatchRegularExpression(
                    '/(^|_)(ip|ipv4|ipv6|ip_address|user_ip|remote_addr)(_|$)/',
                    $column,
                    sprintf( 'Table %1$s must not define the column %2$s.', $table, $column )
                );
            }

            $this->assertStringNotContainsStringIgnoringCase( 'user_ip', $schema );
            $this->assertStringNotContainsStringIgnoringCase( 'ip_address', $schema );
        }
    }

    /**
     * Every table stores visitor identity as a 64-character hash column.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_visitor_tables_store_a_sha256_hash() {
        $schemas = Licensio_KB_DB_Installer::get_schemas( 'wp_', '' );

        foreach ( array( 'licensio_search_log', 'licensio_article_views', 'licensio_feedback' ) as $table ) {
            $this->assertStringContainsString( 'visitor_hash char(64)', $schemas[ $table ] );
        }
    }

    /**
     * The specified columns exist on each table.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_specified_columns_exist() {
        $schemas = Licensio_KB_DB_Installer::get_schemas( 'wp_', '' );

        $expected = array(
            'licensio_search_index'  => array( 'article_id', 'title', 'excerpt', 'content', 'terms', 'updated_at' ),
            'licensio_search_log'    => array( 'term', 'normalized_term', 'results_count', 'visitor_hash', 'source', 'created_at' ),
            'licensio_article_views' => array( 'id', 'article_id', 'visitor_hash', 'created_at' ),
            'licensio_feedback'      => array( 'id', 'article_id', 'vote', 'comment', 'visitor_hash', 'user_id', 'status', 'created_at' ),
        );

        foreach ( $expected as $table => $columns ) {
            $found = $this->columns_of( $schemas[ $table ] );

            foreach ( $columns as $column ) {
                $this->assertContains( $column, $found, sprintf( 'Table %1$s is missing column %2$s.', $table, $column ) );
            }
        }
    }

    /**
     * No schema statement ever carries a FULLTEXT key.
     *
     * The index is added out of band by a suppressed ALTER, because a server
     * that rejects the key inside CREATE TABLE rejects the whole statement and
     * the site ends up with no search index table at all.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_no_schema_statement_carries_a_fulltext_key() {
        $schemas = Licensio_KB_DB_Installer::get_schemas( 'wp_', '' );

        foreach ( $schemas as $name => $sql ) {
            $this->assertStringNotContainsStringIgnoringCase( 'FULLTEXT', $sql, sprintf( 'Schema %s must not declare a FULLTEXT key.', $name ) );
        }
    }

    /**
     * The table prefix and charset clause are applied to every statement.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_prefix_and_charset_are_applied() {
        $schemas = Licensio_KB_DB_Installer::get_schemas( 'xyz_', 'DEFAULT CHARACTER SET utf8mb4' );

        foreach ( $schemas as $table => $schema ) {
            $this->assertStringContainsString( 'CREATE TABLE xyz_' . $table . ' (', $schema );
            $this->assertStringContainsString( 'DEFAULT CHARACTER SET utf8mb4', $schema );
        }
    }

    /**
     * The plugin table prefix is applied exactly once.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_table_names_are_prefixed_once() {
        $this->assertSame( 'licensio_feedback', Licensio_KB_DB::unprefixed_table_name( 'feedback' ) );
        $this->assertSame( 'licensio_feedback', Licensio_KB_DB::unprefixed_table_name( 'licensio_feedback' ) );
    }

    /**
     * Extract the column names declared by a CREATE TABLE statement.
     *
     * @since 1.0.0
     *
     * @param string $schema The CREATE TABLE statement.
     *
     * @return string[] The declared column names.
     */
    private function columns_of( $schema ) {
        $columns = array();

        foreach ( explode( "\n", $schema ) as $line ) {
            $line = trim( $line );

            if ( '' === $line || 0 === strpos( $line, 'CREATE TABLE' ) || 0 === strpos( $line, ')' ) ) {
                continue;
            }

            if ( preg_match( '/^(PRIMARY KEY|KEY|UNIQUE KEY|FULLTEXT KEY|INDEX)\b/i', $line ) ) {
                continue;
            }

            if ( preg_match( '/^([a-z0-9_]+)\s/i', $line, $matches ) ) {
                $columns[] = $matches[1];
            }
        }

        return $columns;
    }
}
