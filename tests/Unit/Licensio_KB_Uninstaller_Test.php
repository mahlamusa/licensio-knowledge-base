<?php
/**
 * Unit tests for plugin teardown.
 *
 * Covers the data-retention decision, what is kept and what is always
 * removed, and the multisite loop. Teardown used to
 * drop tables unconditionally and touch only the current blog, so a "clean"
 * delete destroyed visitor-written feedback on one site and left orphaned
 * tables, options and a live cron event on every other site of a network.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

use Brain\Monkey\Functions;

/**
 * A $wpdb whose table drops fail, as a broken install behaves.
 *
 * Only the drops throw: they go through the vendored query builder, which is
 * what goes missing. Plain statements such as the transient delete do not.
 *
 * @since 1.0.0
 */
class Licensio_KB_Throwing_WPDB extends Licensio_KB_Test_WPDB {

    /**
     * Refuse every table drop.
     *
     * @since 1.0.0
     *
     * @param string $query The SQL statement.
     *
     * @return int|false The parent result for anything but a table drop.
     * @throws RuntimeException On a table drop.
     */
    public function query( $query ) {
        if ( false !== stripos( (string) $query, 'DROP TABLE' ) ) {
            throw new RuntimeException( 'The Licensio Knowledge Base database library is unavailable.' );
        }

        return parent::query( $query );
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
     * The cron hooks cleared, by blog ID.
     *
     * @since 1.0.0
     * @var array<int, string[]>
     */
    private $cron_hooks_cleared = array();

    /**
     * The delete_metadata() calls made, as argument lists.
     *
     * @since 1.0.0
     * @var array<int, array<int, mixed>>
     */
    private $meta_deleted = array();

    /**
     * The wp_set_option_autoload_values() calls made, as option-to-autoload maps.
     *
     * @since 1.0.0
     * @var array<int, array<string, bool>>
     */
    private $autoload_calls = array();

    /**
     * Scratch uploads directory the URL map directory lives in.
     *
     * @since 1.0.0
     * @var string
     */
    private $uploads = '';

    /**
     * Per-blog role stores, keyed by blog ID then role name.
     *
     * Roles are stored per site, so each blog gets its own administrator and
     * editor, both holding the management capability, and a subscriber that
     * was granted it by hand.
     *
     * @since 1.0.0
     * @var array<int, array<string, WP_Role>>
     */
    private $roles = array();

    /**
     * Stub the multisite and cron surface.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        $this->blogs              = array();
        $this->current_blog       = 0;
        $this->cron_cleared       = array();
        $this->cron_hooks_cleared = array();
        $this->meta_deleted       = array();

        $this->autoload_calls = array();
        $this->roles          = array();
        $this->uploads        = sys_get_temp_dir() . '/licensio-kb-uninstall-' . uniqid( '', false );

        $GLOBALS['wpdb'] = new Licensio_KB_Test_WPDB(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

        Functions\when( 'switch_to_blog' )->alias(
            function ( $blog_id ) {
                $this->blogs[ $this->current_blog ] = $this->options;
                $this->current_blog                 = (int) $blog_id;
                $this->options                      = isset( $this->blogs[ $blog_id ] ) ? $this->blogs[ $blog_id ] : array();

                $GLOBALS['wpdb']->prefix  = 1 === (int) $blog_id ? 'wptests_' : 'wptests_' . (int) $blog_id . '_';
                $GLOBALS['wpdb']->options = $GLOBALS['wpdb']->prefix . 'options';

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
                $this->cron_hooks_cleared[ $this->current_blog ][] = $hook;

                if ( Licensio_KB_Visitor::CRON_HOOK === $hook ) {
                    $this->cron_cleared[] = $this->current_blog;
                }

                return 1;
            }
        );

        Functions\when( 'delete_metadata' )->alias(
            function ( ...$args ) {
                $this->meta_deleted[] = $args;

                return true;
            }
        );

        Functions\when( 'wp_set_option_autoload_values' )->alias(
            function ( $options ) {
                $this->autoload_calls[] = $options;

                return array_fill_keys( array_keys( $options ), true );
            }
        );

        Functions\when( 'get_role' )->alias(
            function ( $name ) {
                $roles = $this->site_roles( $this->current_blog );

                return isset( $roles[ $name ] ) ? $roles[ $name ] : null;
            }
        );

        Functions\when( 'wp_roles' )->alias(
            function () {
                return (object) array( 'role_objects' => $this->site_roles( $this->current_blog ) );
            }
        );

        Functions\when( 'wp_upload_dir' )->alias(
            function () {
                return array( 'basedir' => $this->uploads );
            }
        );

        Functions\when( 'wp_delete_file' )->alias(
            function ( $file ) {
                unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Scratch file this test created.
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

        $directory = $this->uploads . '/licensio-kb-import';

        foreach ( array( $directory . '/heroic-url-map.csv', $directory . '/.htaccess' ) as $file ) {
            if ( file_exists( $file ) ) {
                unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Scratch file this test created.
            }
        }

        foreach ( array( $directory, $this->uploads ) as $dir ) {
            if ( is_dir( $dir ) ) {
                rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- The scratch directory this test created.
            }
        }

        parent::tearDown();
    }

    /**
     * The roles of one site, created on first use.
     *
     * @since 1.0.0
     *
     * @param int $blog_id The blog ID.
     *
     * @return array<string, WP_Role> Role name to role.
     */
    private function site_roles( $blog_id ) {
        if ( ! isset( $this->roles[ $blog_id ] ) ) {
            $this->roles[ $blog_id ] = array(
                'administrator' => new WP_Role(
                    'administrator',
                    array(
                        'manage_options'     => true,
                        'manage_licensio_kb' => true,
                    )
                ),
                'editor'        => new WP_Role(
                    'editor',
                    array(
                        'edit_others_posts'  => true,
                        'manage_licensio_kb' => true,
                    )
                ),
                'subscriber'    => new WP_Role(
                    'subscriber',
                    array(
                        'read'               => true,
                        'manage_licensio_kb' => true,
                    )
                ),
            );
        }

        return $this->roles[ $blog_id ];
    }

    /**
     * The DELETE statements issued against an options table.
     *
     * @since 1.0.0
     *
     * @return string[] The statements.
     */
    private function options_deletes() {
        return array_values(
            array_filter(
                $GLOBALS['wpdb']->queries,
                static function ( $query ) {
                    return 0 === stripos( (string) $query, 'DELETE FROM' ) && false !== strpos( (string) $query, 'options`' );
                }
            )
        );
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
            'licensio_settings'                        => array( 'delete_data_on_uninstall' => $delete_data ),
            'licensio_visitor_salt'                    => array( 'current' => 'a-salt' ),
            'licensio_version'                         => '1.0.0',
            'licensio_db_version'                      => '1.0.0',
            'licensio_unrelated_key'                   => 'left alone',
            Licensio_KB_Search_Index::BUILT_OPTION     => '1.0.0',
            Licensio_KB_Lifecycle::UPGRADE_LOCK_OPTION => 1700000000,
            Licensio_KB_Import_State::option_for( 'heroic' ) => array( 'phase' => 'articles' ),
            Licensio_KB_Import_Report::option_for( 'heroic' ) => array( 'counts' => array() ),
            Licensio_KB_Import_Redirects::OPTION       => array( '/old' => '/new' ),
            Licensio_KB_Import_Redirects::FILES_OPTION => array( 'heroic' => 'heroic-url-map.csv' ),
        );
    }

    /**
     * Seed the URL map directory with a map file and a deny file.
     *
     * @since 1.0.0
     *
     * @return string The directory.
     */
    private function seed_url_map_directory() {
        $directory = $this->uploads . '/licensio-kb-import';

        mkdir( $directory, 0777, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- A scratch directory in the system temp dir; WP_Filesystem is not booted in a unit test.
        file_put_contents( $directory . '/heroic-url-map.csv', "\"type\"\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Scratch file.
        file_put_contents( $directory . '/.htaccess', "Deny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Scratch file.

        return $directory;
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
     * The plugin state options and the cron event go regardless of the data setting.
     *
     * A scheduled hook or a version marker that outlives the plugin owning it
     * is residue. The search index marker in particular must go: left behind
     * after the tables were dropped, it told a reinstall of the same version
     * that the new, empty index was already built.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_state_options_and_cron_are_always_removed() {
        $this->seed_site( false );

        Licensio_KB_Uninstaller::uninstall_site();

        $this->assertContains( Licensio_KB_Lifecycle::UPGRADE_LOCK_OPTION, Licensio_KB_Uninstaller::OPTIONS, 'A lock left by an interrupted upgrade must not outlive the plugin.' );

        foreach ( Licensio_KB_Uninstaller::OPTIONS as $option ) {
            $this->assertArrayNotHasKey( $option, $this->options, $option . ' must not survive uninstall.' );
        }

        $this->assertNotEmpty( $this->cron_cleared, 'The salt rotation event must be cleared.' );
        $this->assertSame( Licensio_KB_Uninstaller::CRON_HOOKS, $this->cron_hooks_cleared[0], 'Every plugin cron hook must be cleared.' );
        $this->assertContains( Licensio_KB_Search_Index::CRON_HOOK, Licensio_KB_Uninstaller::CRON_HOOKS );
        $this->assertContains( Licensio_KB_Analytics_Retention::CRON_HOOK, Licensio_KB_Uninstaller::CRON_HOOKS );
        $this->assertArrayHasKey( 'licensio_unrelated_key', $this->options, 'Other plugins options must be left alone.' );
    }

    /**
     * The data options are the settings plus every importer option.
     *
     * The import options come from the import registry rather than a
     * hard-coded list, so a source added through the drivers filter is covered
     * as well.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_data_options_are_the_settings_and_the_import_options() {
        $options = Licensio_KB_Uninstaller::data_options();

        $this->assertContains( Licensio_KB_Settings::OPTION_NAME, $options );
        $this->assertContains( Licensio_KB_Import_State::option_for( 'heroic' ), $options );
        $this->assertContains( Licensio_KB_Import_Report::option_for( 'heroic' ), $options );
        $this->assertContains( Licensio_KB_Import_Redirects::OPTION, $options );
        $this->assertContains( Licensio_KB_Import_Redirects::FILES_OPTION, $options );
        $this->assertSame( array(), array_intersect( $options, Licensio_KB_Uninstaller::OPTIONS ), 'An option cannot be both kept and always removed.' );
    }

    /**
     * By default the settings, slugs and redirect map survive, and stop autoloading.
     *
     * The settings hold the permalink slugs and the import options hold the
     * redirects for old links; deleting them on a delete and reinstall changes
     * every permalink and turns every old link into a 404.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_data_options_are_kept_by_default_and_stop_autoloading() {
        $this->seed_site( false );
        $this->options['licensio_settings']['article_slug'] = 'help';

        Licensio_KB_Uninstaller::uninstall_site();

        foreach ( array( 'licensio_settings', Licensio_KB_Import_State::option_for( 'heroic' ), Licensio_KB_Import_Report::option_for( 'heroic' ), Licensio_KB_Import_Redirects::OPTION, Licensio_KB_Import_Redirects::FILES_OPTION ) as $option ) {
            $this->assertArrayHasKey( $option, $this->options, $option . ' must survive an uninstall that keeps data.' );
        }

        $this->assertSame( 'help', $this->options['licensio_settings']['article_slug'] );
        $this->assertSame( array( '/old' => '/new' ), $this->options[ Licensio_KB_Import_Redirects::OPTION ] );
        $this->assertSame( array( array_fill_keys( Licensio_KB_Uninstaller::data_options(), false ) ), $this->autoload_calls );
    }

    /**
     * By default the URL map directory survives, so the kept file names still resolve.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_url_map_directory_is_kept_by_default() {
        $this->seed_site( false );
        $directory = $this->seed_url_map_directory();

        Licensio_KB_Uninstaller::uninstall_site();

        $this->assertFileExists( $directory . '/heroic-url-map.csv' );
    }

    /**
     * Opting in deletes the data options and the URL map directory.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_opting_in_deletes_the_data_options_and_the_url_map_directory() {
        $this->seed_site( true );
        $directory = $this->seed_url_map_directory();

        Licensio_KB_Uninstaller::uninstall_site();

        foreach ( array_merge( Licensio_KB_Uninstaller::OPTIONS, Licensio_KB_Uninstaller::data_options() ) as $option ) {
            $this->assertArrayNotHasKey( $option, $this->options, $option . ' must not survive Delete everything.' );
        }

        $this->assertDirectoryDoesNotExist( $directory );
        $this->assertSame( array(), $this->autoload_calls, 'Deleted options need no autoload change.' );
        $this->assertArrayHasKey( 'licensio_unrelated_key', $this->options, 'Other plugins options must be left alone.' );
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

        foreach ( array_merge( Licensio_KB_Uninstaller::OPTIONS, Licensio_KB_Uninstaller::data_options() ) as $option ) {
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

    /**
     * The knowledge base capabilities are removed whatever the data setting says.
     *
     * Capabilities are plugin state, not site content: a role left holding
     * the management capability after the plugin is gone holds a permission
     * nothing checks.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_capabilities_are_removed_in_both_modes() {
        foreach ( array( false, true ) as $delete_data ) {
            $this->roles = array();
            $this->seed_site( $delete_data );

            Licensio_KB_Uninstaller::uninstall_site();

            $roles = $this->site_roles( 0 );

            $this->assertFalse( $roles['administrator']->has_cap( 'manage_licensio_kb' ) );
            $this->assertFalse( $roles['editor']->has_cap( 'manage_licensio_kb' ) );
            $this->assertTrue( $roles['administrator']->has_cap( 'manage_options' ), 'Core capabilities must be left alone.' );
            $this->assertTrue( $roles['editor']->has_cap( 'edit_others_posts' ), 'Core capabilities must be left alone.' );
            $this->assertFalse( $roles['subscriber']->has_cap( 'manage_licensio_kb' ), 'A copy granted by hand must be removed too.' );
            $this->assertTrue( $roles['subscriber']->has_cap( 'read' ), 'Core capabilities must be left alone.' );
        }
    }

    /**
     * On multisite every site's roles lose the capabilities.
     *
     * Roles are stored per site, so removing them only on the current site
     * left every other site's administrators and editors holding them. The
     * subscriber holds a copy granted by hand, which every site must lose too.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_capabilities_are_removed_on_every_site_of_a_network() {
        Functions\when( 'is_multisite' )->justReturn( true );
        Functions\when( 'get_sites' )->justReturn( array( 1, 2 ) );

        $this->blogs[1] = array( 'licensio_settings' => array( 'delete_data_on_uninstall' => false ) );
        $this->blogs[2] = array( 'licensio_settings' => array( 'delete_data_on_uninstall' => true ) );

        Licensio_KB_Uninstaller::run();

        foreach ( array( 1, 2 ) as $blog_id ) {
            foreach ( $this->site_roles( $blog_id ) as $name => $role ) {
                $this->assertFalse(
                    $role->has_cap( 'manage_licensio_kb' ),
                    sprintf( 'The %1$s role of site %2$d kept the capability.', $name, $blog_id )
                );
            }
        }
    }

    /**
     * The category hierarchy cache is deleted with the data, and stops autoloading otherwise.
     *
     * Core keeps an autoloaded children map per hierarchical taxonomy. Once the
     * plugin is gone nothing reads it, but it is loaded on every request. Tags
     * are not hierarchical, so core never writes a children map for them and
     * there is nothing to delete.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_category_hierarchy_cache_follows_the_data_setting() {
        $this->assertContains( 'licensio_category_children', Licensio_KB_Uninstaller::data_options() );
        $this->assertNotContains( 'licensio_tag_children', Licensio_KB_Uninstaller::data_options(), 'Tags are not hierarchical, so core keeps no children map for them.' );

        $this->seed_site( false );
        $this->options['licensio_category_children'] = array( 4 => array( 7 ) );

        Licensio_KB_Uninstaller::uninstall_site();

        $this->assertArrayHasKey( 'licensio_category_children', $this->options, 'Kept with the data.' );
        $this->assertFalse( $this->autoload_calls[0]['licensio_category_children'] );

        $this->seed_site( true );
        $this->options['licensio_category_children'] = array( 4 => array( 7 ) );

        Licensio_KB_Uninstaller::uninstall_site();

        $this->assertArrayNotHasKey( 'licensio_category_children', $this->options, 'The cache must not survive Delete everything.' );
    }

    /**
     * The rate-limit transients are deleted only with the data.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_rate_limit_transients_are_deleted_only_with_the_data() {
        $this->seed_site( false );

        Licensio_KB_Uninstaller::uninstall_site();

        $this->assertSame( array(), $this->options_deletes(), 'Keeping the data issues no transient delete.' );

        $this->seed_site( true );

        Licensio_KB_Uninstaller::uninstall_site();

        $deletes = $this->options_deletes();

        $this->assertCount( 1, $deletes );
        $this->assertStringContainsString( '`wptests_options`', $deletes[0] );
        $this->assertStringContainsString( "'\\_transient\\_licensio\\_rl\\_%'", $deletes[0], 'The underscores must be escaped so they match only themselves.' );
        $this->assertStringContainsString( "'\\_transient\\_timeout\\_licensio\\_rl\\_%'", $deletes[0] );
    }

    /**
     * On multisite each site's own options table is cleared of its counters.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_rate_limit_transients_are_deleted_from_each_sites_options_table() {
        Functions\when( 'is_multisite' )->justReturn( true );
        Functions\when( 'get_sites' )->justReturn( array( 1, 2 ) );

        foreach ( array( 1, 2 ) as $blog_id ) {
            $this->blogs[ $blog_id ] = array( 'licensio_settings' => array( 'delete_data_on_uninstall' => true ) );
        }

        Licensio_KB_Uninstaller::run();

        $issued = implode( ' ', $this->options_deletes() );

        $this->assertStringContainsString( '`wptests_options`', $issued );
        $this->assertStringContainsString( '`wptests_2_options`', $issued );
    }
}
