<?php
/**
 * Unit tests for plugin teardown.
 *
 * Covers the data-retention decision and the multisite loop. Teardown used to
 * drop tables unconditionally and touch only the current blog, so a "clean"
 * delete destroyed visitor-written feedback on one site and left orphaned
 * tables, options and a live cron event on every other site of a network.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

use Brain\Monkey\Functions;

/**
 * A $wpdb whose every statement fails, as a broken install behaves.
 *
 * @since 1.0.0
 */
class Licensio_KB_Throwing_WPDB extends Licensio_KB_Test_WPDB {

    /**
     * Refuse every statement.
     *
     * @since 1.0.0
     *
     * @param string $query The SQL statement.
     *
     * @throws RuntimeException Always.
     */
    public function query( $query ) {
        unset( $query );

        throw new RuntimeException( 'The Licensio Knowledge Base database library is unavailable.' );
    }
}

/**
 * Tests Licensio_KB_Uninstaller.
 *
 * @since 1.0.0
 */
class Licensio_KB_Uninstaller_Test extends Licensio_KB_Test_Case {

    /**
     * Per-blog option stores, keyed by blog ID.
     *
     * @since 1.0.0
     * @var array<int, array<string, mixed>>
     */
    private $blogs = array();

    /**
     * The blog currently switched to.
     *
     * Starts at 0, a sentinel for "the site the uninstall was invoked on", so
     * that switching to blog 1 does not overwrite blog 1's seeded option store
     * with the empty one the test started with.
     *
     * @since 1.0.0
     * @var int
     */
    private $current_blog = 0;

    /**
     * Blog IDs that had their cron event cleared.
     *
     * @since 1.0.0
     * @var int[]
     */
    private $cron_cleared = array();

    /**
     * The delete_metadata() calls made, as argument lists.
     *
     * @since 1.0.0
     * @var array<int, array<int, mixed>>
     */
    private $meta_deleted = array();

    /**
     * Stub the multisite and cron surface.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        $this->blogs        = array();
        $this->current_blog = 0;
        $this->cron_cleared = array();
        $this->meta_deleted = array();

        $GLOBALS['wpdb'] = new Licensio_KB_Test_WPDB(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

        Functions\when( 'switch_to_blog' )->alias(
            function ( $blog_id ) {
                $this->blogs[ $this->current_blog ] = $this->options;
                $this->current_blog                 = (int) $blog_id;
                $this->options                      = isset( $this->blogs[ $blog_id ] ) ? $this->blogs[ $blog_id ] : array();

                $GLOBALS['wpdb']->prefix = 1 === (int) $blog_id ? 'wptests_' : 'wptests_' . (int) $blog_id . '_';

                return true;
            }
        );

        Functions\when( 'restore_current_blog' )->alias(
            function () {
                $this->blogs[ $this->current_blog ] = $this->options;

                return true;
            }
        );

        Functions\when( 'wp_clear_scheduled_hook' )->alias(
            function ( $hook ) {
                unset( $hook );

                $this->cron_cleared[] = $this->current_blog;

                return 1;
            }
        );

        Functions\when( 'delete_metadata' )->alias(
            function ( ...$args ) {
                $this->meta_deleted[] = $args;

                return true;
            }
        );
    }

    /**
     * Clean the globals the test set.
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
     * Seed a site with plugin options as if it had been running.
     *
     * @since 1.0.0
     *
     * @param bool $delete_data Value of the delete_data_on_uninstall setting.
     *
     * @return void
     */
    private function seed_site( $delete_data = false ) {
        $this->options = array(
            'licensio_settings'                  => array( 'delete_data_on_uninstall' => $delete_data ),
            'licensio_visitor_salt'              => array( 'current' => 'a-salt' ),
            'licensio_version'                   => '1.0.0',
            'licensio_db_version'                => '1.0.0',
            'licensio_unrelated_key'             => 'left alone',
            Licensio_KB_Import_State::option_for( 'heroic' ) => array( 'phase' => 'articles' ),
            Licensio_KB_Import_Report::option_for( 'heroic' ) => array( 'counts' => array() ),
            Licensio_KB_Import_Redirects::OPTION => array( '/old' => '/new' ),
        );
    }

    /**
     * By default the data tables survive an uninstall.
     *
     * The feedback table holds comments and votes written by visitors, which a
     * site owner may treat as support history. A plugin delete is not reliably a
     * decision to destroy it, and the destruction is not reversible.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_data_tables_are_preserved_by_default() {
        $this->seed_site( false );

        Licensio_KB_Uninstaller::uninstall_site();

        $dropped = array_filter(
            $GLOBALS['wpdb']->queries,
            static function ( $query ) {
                return false !== stripos( $query, 'DROP TABLE' );
            }
        );

        $this->assertSame( array(), $dropped, 'No table may be dropped unless the site owner opted in.' );
    }

    /**
     * The setting defaults to false on a site that never set it.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_retention_setting_defaults_to_preserving_data() {
        $settings = new Licensio_KB_Settings();

        $this->assertFalse( $settings->get( 'delete_data_on_uninstall' ) );
        $this->assertFalse( Licensio_KB_Uninstaller::should_delete_data() );
    }

    /**
     * Opting in drops every table.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_opting_in_drops_every_table() {
        $this->seed_site( true );

        Licensio_KB_Uninstaller::uninstall_site();

        $issued = implode( ' ', $GLOBALS['wpdb']->queries );

        foreach ( Licensio_KB_DB_Installer::get_table_names() as $table ) {
            $this->assertStringContainsString( 'wptests_' . $table, $issued );
        }
    }

    /**
     * Plugin options and the cron event go regardless of the data setting.
     *
     * An autoloaded option and a scheduled hook that outlive the plugin owning
     * them are residue on every request, forever.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_options_and_cron_are_always_removed() {
        $this->seed_site( false );

        Licensio_KB_Uninstaller::uninstall_site();

        foreach ( array_merge( Licensio_KB_Uninstaller::OPTIONS, Licensio_KB_Uninstaller::import_options() ) as $option ) {
            $this->assertArrayNotHasKey( $option, $this->options, $option . ' must not survive uninstall.' );
        }

        $this->assertNotEmpty( $this->cron_cleared, 'The salt rotation event must be cleared.' );
        $this->assertArrayHasKey( 'licensio_unrelated_key', $this->options, 'Other plugins options must be left alone.' );
    }

    /**
     * The import state, report and redirect map are removed with the plugin.
     *
     * The options come from the import registry rather than a hard-coded list,
     * so a source added through the drivers filter is cleaned up as well.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_import_options_are_removed() {
        $this->seed_site( false );

        $options = Licensio_KB_Uninstaller::import_options();

        $this->assertContains( Licensio_KB_Import_State::option_for( 'heroic' ), $options );
        $this->assertContains( Licensio_KB_Import_Report::option_for( 'heroic' ), $options );
        $this->assertContains( Licensio_KB_Import_Redirects::OPTION, $options );

        Licensio_KB_Uninstaller::uninstall_site();

        foreach ( $options as $option ) {
            $this->assertArrayNotHasKey( $option, $this->options, $option . ' must not survive uninstall.' );
        }
    }

    /**
     * On multisite every site is torn down, not just the current one.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_every_site_of_a_network_is_torn_down() {
        Functions\when( 'is_multisite' )->justReturn( true );
        Functions\when( 'get_sites' )->justReturn( array( 1, 2, 3 ) );

        foreach ( array( 1, 2, 3 ) as $blog_id ) {
            $this->blogs[ $blog_id ] = array(
                'licensio_settings'     => array( 'delete_data_on_uninstall' => true ),
                'licensio_version'      => '1.0.0',
                'licensio_visitor_salt' => array( 'current' => 'a-salt' ),
            );
        }

        Licensio_KB_Uninstaller::run();

        $this->assertSame( array( 1, 2, 3 ), $this->cron_cleared, 'Every site must have its cron event cleared.' );

        foreach ( array( 1, 2, 3 ) as $blog_id ) {
            $this->assertArrayNotHasKey(
                'licensio_version',
                $this->blogs[ $blog_id ],
                sprintf( 'Site %d kept its options after a network uninstall.', $blog_id )
            );
        }
    }

    /**
     * Each site's own tables are dropped, under that site's table prefix.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_each_site_drops_its_own_prefixed_tables() {
        Functions\when( 'is_multisite' )->justReturn( true );
        Functions\when( 'get_sites' )->justReturn( array( 1, 2 ) );

        foreach ( array( 1, 2 ) as $blog_id ) {
            $this->blogs[ $blog_id ] = array(
                'licensio_settings' => array( 'delete_data_on_uninstall' => true ),
            );
        }

        Licensio_KB_Uninstaller::run();

        $issued = implode( ' ', $GLOBALS['wpdb']->queries );

        $this->assertStringContainsString( 'wptests_licensio_feedback', $issued );
        $this->assertStringContainsString( 'wptests_2_licensio_feedback', $issued );
    }

    /**
     * A site that opted out keeps its data even when a sibling opted in.
     *
     * The setting is per-site, so the decision has to be read per site rather
     * than once for the whole network.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_retention_decision_is_made_per_site() {
        Functions\when( 'is_multisite' )->justReturn( true );
        Functions\when( 'get_sites' )->justReturn( array( 1, 2 ) );

        $this->blogs[1] = array( 'licensio_settings' => array( 'delete_data_on_uninstall' => false ) );
        $this->blogs[2] = array( 'licensio_settings' => array( 'delete_data_on_uninstall' => true ) );

        Licensio_KB_Uninstaller::run();

        $issued = implode( ' ', $GLOBALS['wpdb']->queries );

        $this->assertStringNotContainsString( ' wptests_licensio_feedback', ' ' . $issued );
        $this->assertStringContainsString( 'wptests_2_licensio_feedback', $issued );
    }

    /**
     * A missing database library does not abort the option and cron cleanup.
     *
     * The teardown used to throw straight out of the installer, skipping every
     * option deletion and the cron clear that followed it — leaving the tables
     * AND the options AND a cron event firing for a plugin that no longer
     * existed.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_failing_table_drop_still_cleans_options_and_cron() {
        $this->seed_site( true );

        // A $wpdb whose every statement throws, as a broken install behaves.
        $GLOBALS['wpdb'] = new Licensio_KB_Throwing_WPDB(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

        Licensio_KB_Uninstaller::uninstall_site();

        foreach ( array_merge( Licensio_KB_Uninstaller::OPTIONS, Licensio_KB_Uninstaller::import_options() ) as $option ) {
            $this->assertArrayNotHasKey( $option, $this->options, $option . ' must not survive uninstall.' );
        }

        $this->assertNotEmpty( $this->cron_cleared, 'The cron event must still be cleared.' );
    }

    /**
     * The article list preferences are deleted for every user.
     *
     * They are admin screen state, not visitor content, so they go whatever
     * the data retention setting says. The fifth argument deletes the key
     * from every user rather than from user 0.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_article_list_preferences_are_deleted_for_every_user() {
        $this->seed_site( false );

        Licensio_KB_Uninstaller::uninstall_site();

        $this->assertContains( 'licensio_kb_article_list_prefs', Licensio_KB_Uninstaller::USER_META );
        $this->assertSame(
            array( array( 'user', 0, 'licensio_kb_article_list_prefs', '', true ) ),
            $this->meta_deleted
        );
    }

    /**
     * On multisite the shared user meta is still deleted.
     *
     * Users belong to the network, so the first site's pass removes the key
     * and the later passes find nothing. What matters is that it is never
     * skipped.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_article_list_preferences_are_deleted_on_a_network() {
        Functions\when( 'is_multisite' )->justReturn( true );
        Functions\when( 'get_sites' )->justReturn( array( 1, 2 ) );

        Licensio_KB_Uninstaller::run();

        $this->assertNotEmpty( $this->meta_deleted );

        foreach ( $this->meta_deleted as $call ) {
            $this->assertSame( array( 'user', 0, 'licensio_kb_article_list_prefs', '', true ), $call );
        }
    }
}
