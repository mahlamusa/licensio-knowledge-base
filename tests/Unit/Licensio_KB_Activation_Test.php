<?php
/**
 * Activation smoke tests.
 *
 * Runs the real activation routine against WordPress function doubles and
 * asserts it completes: the content model is registered, all five tables are
 * created, the FULLTEXT probe result is stored, the visitor salt is seeded
 * and the rotation cron is scheduled. Neither SLSWC nor WooCommerce is
 * loaded in this process, which is exactly the environment the plugin has to
 * survive.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

use Brain\Monkey\Functions;

/**
 * Tests Licensio_KB_Lifecycle and the plugin container.
 *
 * @since 1.0.0
 */
class Licensio_KB_Activation_Test extends Licensio_KB_Test_Case {

    /**
     * Post types registered during the test.
     *
     * @since 1.0.0
     * @var array<string, array<string, mixed>>
     */
    private $post_types = array();

    /**
     * Taxonomies registered during the test.
     *
     * @since 1.0.0
     * @var array<string, array<string, mixed>>
     */
    private $taxonomies = array();

    /**
     * Cron events scheduled during the test.
     *
     * @since 1.0.0
     * @var array<string, int>
     */
    private $cron = array();

    /**
     * Whether flush_rewrite_rules() was called.
     *
     * @since 1.0.0
     * @var bool
     */
    private $flushed = false;

    /**
     * Stub the WordPress surface the activation routine touches.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        $this->post_types = array();
        $this->taxonomies = array();
        $this->cron       = array();
        $this->flushed    = false;

        $GLOBALS['licensio_kb_delta_calls'] = array();
        $GLOBALS['wpdb']                    = new Licensio_KB_Test_WPDB(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';

        Functions\when( 'get_bloginfo' )->justReturn( '6.4' );
        Functions\when( 'add_shortcode' )->justReturn( null );
        Functions\when( 'flush_rewrite_rules' )->alias(
            function () {
                $this->flushed = true;
            }
        );
        Functions\when( 'register_post_type' )->alias(
            function ( $name, $args ) {
                $this->post_types[ $name ] = $args;
            }
        );
        Functions\when( 'register_taxonomy' )->alias(
            function ( $name, $object_type, $args ) {
                unset( $object_type );
                $this->taxonomies[ $name ] = $args;
            }
        );
        Functions\when( 'wp_next_scheduled' )->alias(
            function ( $hook ) {
                return isset( $this->cron[ $hook ] ) ? $this->cron[ $hook ] : false;
            }
        );
        Functions\when( 'wp_schedule_event' )->alias(
            function ( $timestamp, $recurrence, $hook ) {
                unset( $recurrence );
                $this->cron[ $hook ] = $timestamp;

                return true;
            }
        );
        Functions\when( 'wp_unschedule_event' )->alias(
            function ( $timestamp, $hook ) {
                unset( $timestamp );
                unset( $this->cron[ $hook ] );

                return true;
            }
        );
        Functions\when( 'wp_clear_scheduled_hook' )->alias(
            function ( $hook ) {
                unset( $this->cron[ $hook ] );

                return 1;
            }
        );
        Functions\when( 'deactivate_plugins' )->justReturn( null );
        Functions\when( 'wp_die' )->alias(
            function () {
                throw new RuntimeException( 'wp_die() was called during activation.' );
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
        unset( $GLOBALS['wpdb'], $GLOBALS['licensio_kb_delta_calls'], $_SERVER['REMOTE_ADDR'] );

        parent::tearDown();
    }

    /**
     * The requirements gate passes on a supported stack.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_requirements_are_met_on_a_supported_stack() {
        $this->assertSame( '', Licensio_KB_Lifecycle::requirements_error() );
    }

    /**
     * The requirements gate reports an unsupported WordPress version.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_requirements_error_names_an_old_wordpress() {
        Functions\when( 'get_bloginfo' )->justReturn( '6.0' );

        $this->assertStringContainsString( '6.4', Licensio_KB_Lifecycle::requirements_error() );
    }

    /**
     * Activation completes without SLSWC or WooCommerce present.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_activation_completes_on_a_bare_wordpress() {
        $this->assertFalse( class_exists( 'WooCommerce' ), 'WooCommerce must not be loaded in this process.' );
        $this->assertFalse( class_exists( 'SLSWC' ), 'SLSWC must not be loaded in this process.' );

        Licensio_KB_Lifecycle::activate();

        $this->assertTrue( $this->flushed, 'Activation must flush the rewrite rules.' );
    }

    /**
     * Activation registers the article post type with the specified defaults.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_activation_registers_the_content_model() {
        Licensio_KB_Lifecycle::activate();

        $this->assertArrayHasKey( 'licensio_article', $this->post_types );
        $this->assertArrayHasKey( 'licensio_category', $this->taxonomies );
        $this->assertArrayHasKey( 'licensio_tag', $this->taxonomies );

        $article = $this->post_types['licensio_article'];

        $this->assertTrue( $article['show_in_rest'] );
        $this->assertSame( 'docs', $article['rewrite']['slug'] );
        $this->assertSame( 'docs', $article['has_archive'] );

        foreach ( array( 'editor', 'excerpt', 'author', 'thumbnail', 'revisions' ) as $support ) {
            $this->assertContains( $support, $article['supports'] );
        }

        $this->assertTrue( $this->taxonomies['licensio_category']['hierarchical'] );
        $this->assertTrue( $this->taxonomies['licensio_category']['show_in_rest'] );
        $this->assertFalse( $this->taxonomies['licensio_tag']['hierarchical'] );
        $this->assertTrue( $this->taxonomies['licensio_tag']['show_in_rest'] );
    }

    /**
     * Activation creates all five tables and records the schema version.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_activation_creates_all_five_tables() {
        Licensio_KB_Lifecycle::activate();

        $statements = $GLOBALS['licensio_kb_delta_calls'];

        $this->assertCount( 5, $statements );

        foreach ( Licensio_KB_DB_Installer::get_table_names() as $table ) {
            $this->assertNotEmpty(
                array_filter(
                    $statements,
                    static function ( $statement ) use ( $table ) {
                        return false !== strpos( $statement, 'CREATE TABLE wptests_' . $table . ' (' );
                    }
                ),
                sprintf( 'Activation must create the %s table.', $table )
            );
        }

        $this->assertArrayNotHasKey( 'licensio_db_version', $this->options, 'No schema version marker is kept.' );
    }

    /**
     * The FULLTEXT key is always requested, and the outcome is read back.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_fulltext_index_is_requested_and_the_outcome_recorded() {
        Licensio_KB_Lifecycle::activate();

        $this->assertStringContainsStringIgnoringCase( 'ADD FULLTEXT KEY ft_all', implode( ' ', $GLOBALS['wpdb']->queries ) );
        $this->assertSame( 'yes', $this->options['licensio_fulltext_supported'] );
        $this->assertTrue( Licensio_KB_DB_Installer::supports_fulltext() );
    }

    /**
     * The CREATE TABLE never carries the FULLTEXT key.
     *
     * A server that rejects the key rejects the entire CREATE TABLE, which would
     * leave no search index table at all — strictly worse than having no index.
     * The key is added out of band by a suppressed ALTER so a failure costs only
     * the index. dbDelta() never drops indexes, so it survives later runs.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_create_table_statement_does_not_carry_the_fulltext_key() {
        Licensio_KB_Lifecycle::activate();

        $deltas = implode( ' ', $GLOBALS['licensio_kb_delta_calls'] );

        $this->assertStringContainsString( 'licensio_search_index', $deltas );
        $this->assertStringNotContainsStringIgnoringCase( 'FULLTEXT', $deltas );
    }

    /**
     * A server that refuses the index still gets its table.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_server_refusing_the_index_still_gets_the_table() {
        $GLOBALS['wpdb']->server_supports_fulltext = false;

        Licensio_KB_Lifecycle::activate();

        $this->assertStringContainsString( 'licensio_search_index', implode( ' ', $GLOBALS['licensio_kb_delta_calls'] ) );
        $this->assertSame( 'no', $this->options['licensio_fulltext_supported'] );
    }

    /**
     * Support is never established with a temporary table.
     *
     * MySQL rejects `FULLTEXT` on a `TEMPORARY` InnoDB table (errno 1796) even
     * where it accepts the same key on the permanent table, so probing that way
     * reported no FULLTEXT support on every MySQL 8 host and silently disabled
     * the search index. Creating temporary tables is a separate privilege that
     * shared hosts withhold too. This pins the approach, not just the outcome.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_no_temporary_table_is_used_to_establish_support() {
        Licensio_KB_Lifecycle::activate();

        $issued = implode( ' ', $GLOBALS['wpdb']->queries ) . ' ' . implode( ' ', $GLOBALS['licensio_kb_delta_calls'] );

        $this->assertStringNotContainsStringIgnoringCase( 'CREATE TEMPORARY TABLE', $issued );
    }

    /**
     * A server that will not create the index is recorded as unsupported.
     *
     * The key is still requested; the plugin records what the server did with
     * it rather than predicting the answer in advance.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_server_that_drops_the_index_is_recorded_as_unsupported() {
        $GLOBALS['wpdb']->server_supports_fulltext = false;

        Licensio_KB_Lifecycle::activate();

        $this->assertStringContainsStringIgnoringCase( 'ADD FULLTEXT KEY ft_all', implode( ' ', $GLOBALS['wpdb']->queries ) );
        $this->assertSame( 'no', $this->options['licensio_fulltext_supported'] );
        $this->assertFalse( Licensio_KB_DB_Installer::supports_fulltext() );
    }

    /**
     * Activation seeds the visitor salt and schedules its daily rotation.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_activation_seeds_the_salt_and_schedules_rotation() {
        Licensio_KB_Lifecycle::activate();

        $this->assertArrayHasKey( Licensio_KB_Visitor::OPTION_NAME, $this->options );
        $this->assertNotEmpty( $this->options[ Licensio_KB_Visitor::OPTION_NAME ]['current'] );
        $this->assertArrayHasKey( Licensio_KB_Visitor::CRON_HOOK, $this->cron );
    }

    /**
     * Deactivation clears the rotation event and leaves the data alone.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_deactivation_clears_the_rotation_event() {
        Licensio_KB_Lifecycle::activate();

        Licensio_KB_Lifecycle::deactivate();

        $this->assertArrayNotHasKey( Licensio_KB_Visitor::CRON_HOOK, $this->cron );
        $this->assertArrayHasKey( Licensio_KB_Visitor::OPTION_NAME, $this->options );
        $this->assertArrayHasKey( 'licensio_fulltext_supported', $this->options );
    }

    /**
     * A network activation installs into every site, not just the activating one.
     *
     * The tables, the visitor salt and — most visibly — the rewrite rules are
     * all per-site, so without the loop every other subsite 404s on `/docs/…`
     * until someone re-saves its permalinks by hand.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_network_activation_installs_into_every_site() {
        $switched = array();

        Functions\when( 'is_multisite' )->justReturn( true );
        Functions\when( 'wp_is_large_network' )->justReturn( false );
        Functions\when( 'get_sites' )->justReturn( array( 1, 2, 3 ) );
        Functions\when( 'switch_to_blog' )->alias(
            function ( $blog_id ) use ( &$switched ) {
                $switched[] = (int) $blog_id;

                return true;
            }
        );
        Functions\when( 'restore_current_blog' )->justReturn( true );

        Licensio_KB_Lifecycle::activate( true );

        $this->assertSame( array( 1, 2, 3 ), $switched, 'Every site of the network must be installed into.' );
        $this->assertTrue( $this->flushed, 'Each site needs its rewrite rules built.' );
    }

    /**
     * A single-site activation does not go near the network path.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_single_site_activation_does_not_switch_blogs() {
        $switched = false;

        Functions\when( 'switch_to_blog' )->alias(
            function () use ( &$switched ) {
                $switched = true;

                return true;
            }
        );

        Licensio_KB_Lifecycle::activate();

        $this->assertFalse( $switched );
        $this->assertArrayNotHasKey( 'licensio_version', $this->options, 'No plugin version marker is kept.' );
    }

    /**
     * A very large network refuses network activation instead of walking its sites.
     *
     * Walking thousands of sites inside one activation request would time out,
     * and a site never installed into would 404 on every knowledge base URL.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_large_network_refuses_network_activation() {
        $switched    = false;
        $deactivated = null;

        Functions\when( 'is_multisite' )->justReturn( true );
        Functions\when( 'wp_is_large_network' )->justReturn( true );
        Functions\when( 'deactivate_plugins' )->alias(
            function ( $plugins, $silent, $network_wide ) use ( &$deactivated ) {
                $deactivated = array( $plugins, $silent, $network_wide );
            }
        );
        Functions\when( 'switch_to_blog' )->alias(
            function () use ( &$switched ) {
                $switched = true;

                return true;
            }
        );
        Functions\when( 'restore_current_blog' )->justReturn( true );

        try {
            Licensio_KB_Lifecycle::activate( true );
            $this->fail( 'A large network must be refused through wp_die().' );
        } catch ( RuntimeException $exception ) {
            $this->assertSame( 'wp_die() was called during activation.', $exception->getMessage() );
        }

        $this->assertSame( array( LICENSIO_KB_PLUGIN_BASENAME, false, true ), $deactivated, 'The plugin must be deactivated network wide.' );
        $this->assertFalse( $switched, 'A large network must not be walked.' );
    }

    /**
     * The container boots and hands every scaffolded component to consumers.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_container_boots_and_exposes_every_component() {
        $plugin = Licensio_KB::instance();

        $this->assertSame( $plugin, Licensio_KB::instance() );
        $this->assertInstanceOf( 'Licensio_KB_Settings', $plugin->settings() );
        $this->assertInstanceOf( 'Licensio_KB_Post_Types', $plugin->post_types() );
        $this->assertInstanceOf( 'Licensio_KB_Assets', $plugin->assets() );
        $this->assertInstanceOf( 'Licensio_KB_Visitor', $plugin->visitor() );
        $this->assertInstanceOf( 'Licensio_KB_REST_API', $plugin->rest_api() );
        $this->assertInstanceOf( 'Licensio_KB_Content_Manager', $plugin->content_manager() );
        $this->assertInstanceOf( 'Licensio_KB_Term_Operations', $plugin->term_operations() );
    }

    /**
     * The vendored query builder is present and prefixed for this plugin.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_vendored_query_builder_is_available() {
        Licensio_KB_DB::init();

        $this->assertTrue( Licensio_KB_DB::is_available() );
        $this->assertSame( 'wptests_licensio_feedback', Licensio_KB_DB::table_name( 'feedback' ) );
    }
}
