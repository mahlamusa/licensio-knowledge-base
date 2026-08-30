<?php
/**
 * The query-builder gateway.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

/**
 * Guards the one thing the gateway must get right: prefixing.
 *
 * @since 1.0.0
 */
class Lisensio_KB_DB_Gateway_Test extends Lisensio_KB_Test_Case {

    /**
     * Provide the $wpdb the prefixing depends on, and load the library.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        $GLOBALS['wpdb'] = new Lisensio_KB_Test_WPDB(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

        // is_available() only answers truthfully once init() has loaded the library.
        Lisensio_KB_DB::init();
    }

    /**
     * Drop the global again.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function tearDown(): void {
        unset( $GLOBALS['wpdb'] );

        parent::tearDown();
    }

    /**
     * table() must hand the builder an unprefixed name.
     *
     * The builder applies $wpdb->prefix itself. Passing an already-prefixed
     * name produced `wp_wp_lisensio_search_index`, so every read and write in
     * the plugin addressed a table that does not exist. The failure is silent:
     * the query builds without complaint and only fails on execution, which is
     * why a full unit suite and a real-database integration suite both passed
     * over it. The scaffold never called table() at all.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_table_is_prefixed_exactly_once() {
        if ( ! Lisensio_KB_DB::is_available() ) {
            $this->markTestSkipped( 'The vendored query builder is unavailable.' );
        }

        $sql = Lisensio_KB_DB::table( 'lisensio_search_index' )->getSQL();

        $this->assertStringContainsString( 'wptests_lisensio_search_index', $sql );
        $this->assertStringNotContainsString( 'wptests_wptests_', $sql );
        $this->assertSame( 1, substr_count( $sql, 'wptests_' ), 'The table name must carry exactly one prefix.' );
    }

    /**
     * Every table the plugin owns survives the same round trip.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_every_owned_table_is_prefixed_exactly_once() {
        if ( ! Lisensio_KB_DB::is_available() ) {
            $this->markTestSkipped( 'The vendored query builder is unavailable.' );
        }

        foreach ( Lisensio_KB_DB_Installer::get_table_names() as $table ) {
            $sql = Lisensio_KB_DB::table( $table )->getSQL();

            $this->assertStringContainsString( 'wptests_' . $table, $sql, sprintf( 'Table %s is not addressable.', $table ) );
            $this->assertSame( 1, substr_count( $sql, 'wptests_' ), sprintf( 'Table %s carries the wrong number of prefixes.', $table ) );
        }
    }

    /**
     * A bare short name still gains its plugin prefix.
     *
     * table()'s contract accepts `search_log` as well as
     * `lisensio_search_log`, so the fix must drop only the WordPress prefix and
     * keep applying the plugin one. Asserting the absence of a doubled prefix
     * alone would also pass a fix that stripped both, leaving the query pointed
     * at `wptests_search_log` — a different nonexistent table.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_bare_short_name_still_gains_the_plugin_prefix() {
        if ( ! Lisensio_KB_DB::is_available() ) {
            $this->markTestSkipped( 'The vendored query builder is unavailable.' );
        }

        $sql = Lisensio_KB_DB::table( 'search_log' )->getSQL();

        $this->assertStringContainsString( 'wptests_lisensio_search_log', $sql );
        $this->assertStringNotContainsString( 'wptests_wptests_', $sql );
        $this->assertStringNotContainsString( 'wptests_search_log', str_replace( 'wptests_lisensio_search_log', '', $sql ) );
    }

    /**
     * delta() and query() still take a fully qualified name.
     *
     * They bypass the builder, so their contract is the opposite of table()'s.
     * Conflating the two is what caused the original defect.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_table_name_remains_fully_qualified_for_raw_statements() {
        $this->assertSame( 'wptests_lisensio_search_index', Lisensio_KB_DB::table_name( 'lisensio_search_index' ) );
        $this->assertSame( 'lisensio_search_index', Lisensio_KB_DB::unprefixed_table_name( 'lisensio_search_index' ) );
    }
}
