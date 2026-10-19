<?php
/**
 * The migrator's WP-CLI surface.
 *
 * The command name is the whole interface. A typo in it makes the tool
 * unreachable while every other test in the suite stays green, which is exactly
 * the sort of thing nobody notices until migration day.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

require_once dirname( dirname( __DIR__ ) ) . '/tools/migrate-heroic/class-licensio-kb-heroic-schema.php';
require_once dirname( dirname( __DIR__ ) ) . '/tools/migrate-heroic/class-licensio-kb-heroic-map.php';
require_once dirname( dirname( __DIR__ ) ) . '/tools/migrate-heroic/class-licensio-kb-heroic-state.php';
require_once dirname( dirname( __DIR__ ) ) . '/tools/migrate-heroic/class-licensio-kb-heroic-report.php';
require_once dirname( dirname( __DIR__ ) ) . '/tools/migrate-heroic/class-licensio-kb-heroic-source.php';
require_once dirname( dirname( __DIR__ ) ) . '/tools/migrate-heroic/class-licensio-kb-heroic-redirects.php';
require_once dirname( dirname( __DIR__ ) ) . '/tools/migrate-heroic/class-licensio-kb-heroic-content-migrator.php';
require_once dirname( dirname( __DIR__ ) ) . '/tools/migrate-heroic/class-licensio-kb-heroic-data-migrator.php';
require_once dirname( dirname( __DIR__ ) ) . '/tools/migrate-heroic/class-licensio-kb-heroic-cli.php';

/**
 * A `$wpdb` stand-in for an installation with no Heroic data at all.
 *
 * Every count is zero and every batch is empty, so each phase reaches its end
 * on its first read. That is exactly the shape needed to drive the command's
 * orchestration loop: if a phase never terminates, or the phases run out of
 * order, or a report line raises, this reports it rather than needing a live
 * Heroic site to notice.
 *
 * @since 1.0.0
 */
class Licensio_KB_Heroic_Empty_WPDB {

    /**
     * The posts table name.
     *
     * @since 1.0.0
     * @var string
     */
    public $posts = 'wp_posts';

    /**
     * The post meta table name.
     *
     * @since 1.0.0
     * @var string
     */
    public $postmeta = 'wp_postmeta';

    /**
     * The terms table name.
     *
     * @since 1.0.0
     * @var string
     */
    public $terms = 'wp_terms';

    /**
     * The term taxonomy table name.
     *
     * @since 1.0.0
     * @var string
     */
    public $term_taxonomy = 'wp_term_taxonomy';

    /**
     * The term relationships table name.
     *
     * @since 1.0.0
     * @var string
     */
    public $term_relationships = 'wp_term_relationships';

    /**
     * The term meta table name.
     *
     * @since 1.0.0
     * @var string
     */
    public $termmeta = 'wp_termmeta';

    /**
     * The WordPress table prefix.
     *
     * @since 1.0.0
     * @var string
     */
    public $prefix = 'wp_';

    /**
     * Every statement the command ran.
     *
     * @since 1.0.0
     * @var string[]
     */
    public $queries = array();

    /**
     * Report zero for every count and no table for every existence probe.
     *
     * @since 1.0.0
     *
     * @param string $query The statement.
     *
     * @return int Always 0.
     */
    public function get_var( $query ) {
        $this->queries[] = $query;

        return 0;
    }

    /**
     * Report no rows.
     *
     * @since 1.0.0
     *
     * @param string $query  The statement.
     * @param mixed  $output Ignored.
     *
     * @return array<int, mixed> Always empty.
     */
    public function get_results( $query = '', $output = null ) {
        unset( $output );

        $this->queries[] = $query;

        return array();
    }

    /**
     * Report no column values.
     *
     * @since 1.0.0
     *
     * @param string $query The statement.
     *
     * @return array<int, mixed> Always empty.
     */
    public function get_col( $query = '' ) {
        $this->queries[] = $query;

        return array();
    }

    /**
     * Interpolate a statement.
     *
     * @since 1.0.0
     *
     * @param string $query   The statement.
     * @param mixed  ...$args The values to bind.
     *
     * @return string The prepared statement.
     */
    public function prepare( $query, ...$args ) {
        if ( 1 === count( $args ) && is_array( $args[0] ) ) {
            $args = $args[0];
        }

        $position = 0;

        return (string) preg_replace_callback(
            '/%[sdf]/',
            function () use ( &$position, $args ) {
                $value = array_key_exists( $position, $args ) ? $args[ $position ] : '';
                ++$position;

                return "'" . $value . "'";
            },
            (string) $query
        );
    }

    /**
     * Escape a string for a LIKE comparison.
     *
     * @since 1.0.0
     *
     * @param string $text The text.
     *
     * @return string The escaped text.
     */
    public function esc_like( $text ) {
        return addcslashes( (string) $text, '_%\\' );
    }
}

/**
 * How the migrator presents itself to WP-CLI.
 *
 * @since 1.0.0
 */
class Licensio_KB_Heroic_CLI_Test extends Licensio_KB_Test_Case {

    /**
     * Reset the WP-CLI double and point the migrator at an empty installation.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        WP_CLI::reset();

        $GLOBALS['wpdb'] = new Licensio_KB_Heroic_Empty_WPDB(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

        Brain\Monkey\Functions\when( 'number_format_i18n' )->alias(
            function ( $number, $decimals = 0 ) {
                return number_format( (float) $number, (int) $decimals );
            }
        );
    }

    /**
     * Release the database double.
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
     * The command registers under the name the documentation gives.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_it_registers_the_documented_command_name() {
        $this->assertTrue( Licensio_KB_Heroic_CLI::register() );

        $this->assertSame( 'licensio-kb migrate-heroic', WP_CLI::$commands[0]['name'] );
    }

    /**
     * It does not take the plugin's own `licensio-kb` namespace for itself.
     *
     * Registering the bare namespace would displace `wp licensio-kb reindex`,
     * which the migration itself tells the operator to run afterwards.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_it_does_not_displace_the_plugins_own_command() {
        Licensio_KB_Heroic_CLI::register();

        $this->assertNotSame( 'licensio-kb', WP_CLI::$commands[0]['name'] );
    }

    /**
     * An unknown phase is refused before any work starts.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_unknown_phase_is_refused() {
        $this->expectException( 'RuntimeException' );
        $this->expectExceptionMessage( 'Unknown phase "analytics"' );

        Licensio_KB_Heroic_CLI::run( array(), array( 'phase' => 'analytics' ) );
    }

    /**
     * Every phase the state knows about is accepted by the flag.
     *
     * A phase added to the state but forgotten here would be silently
     * unreachable from the command line.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_every_known_phase_is_accepted() {
        foreach ( Licensio_KB_Heroic_State::PHASES as $phase ) {
            WP_CLI::reset();

            Licensio_KB_Heroic_CLI::run(
                array(),
                array(
                    'phase'      => $phase,
                    'dry-run'    => true,
                    'output-dir' => sys_get_temp_dir(),
                )
            );

            $this->assertContains(
                'Phase: ' . $phase,
                WP_CLI::$logs,
                sprintf( 'Phase %s is in the state machine but never ran from the command line.', $phase )
            );
        }
    }

    /**
     * A full dry run drives every phase, in order, and terminates.
     *
     * The orchestration loop is the one part of the migrator that no other test
     * touches: each phase reads batches until a batch comes back empty, so a
     * phase that fails to close its cursor hangs the command rather than failing
     * it. Against an installation with no Heroic data every phase should end on
     * its first read, which makes a hang or a misordering visible here.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_full_dry_run_drives_every_phase_in_order_and_stops() {
        Licensio_KB_Heroic_CLI::run(
            array(),
            array(
                'dry-run'    => true,
                'output-dir' => sys_get_temp_dir(),
            )
        );

        $phases = array();

        foreach ( WP_CLI::$logs as $line ) {
            if ( 0 === strpos( $line, 'Phase: ' ) ) {
                $phases[] = substr( $line, strlen( 'Phase: ' ) );
            }
        }

        $this->assertSame( Licensio_KB_Heroic_State::PHASES, $phases, 'The phases did not run in dependency order.' );
        $this->assertNotEmpty( WP_CLI::$successes, 'The command did not reach its success line.' );
    }

    /**
     * The dry run reports the source counts and says it is writing nothing.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_dry_run_announces_itself_and_the_source_counts() {
        Licensio_KB_Heroic_CLI::run(
            array(),
            array(
                'dry-run'    => true,
                'output-dir' => sys_get_temp_dir(),
            )
        );

        $output = implode( "\n", WP_CLI::$logs );

        $this->assertStringContainsString( 'Dry run: nothing will be written.', $output );
        $this->assertStringContainsString( 'Heroic Knowledge Base source:', $output );
        $this->assertStringContainsString( 'ht_kb articles', $output );
        $this->assertStringContainsString( Licensio_KB_Heroic_Schema::TABLE_EXITS, $output );
        $this->assertStringContainsString( 'not migrated: no target table', $output );
        $this->assertStringContainsString( 'Would migrate:', $output );
    }

    /**
     * A dry run persists no resume state.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_dry_run_leaves_no_resume_state_behind() {
        Licensio_KB_Heroic_CLI::run(
            array(),
            array(
                'dry-run'    => true,
                'output-dir' => sys_get_temp_dir(),
            )
        );

        $this->assertSame( array(), $this->writes, 'A dry run wrote to the options table.' );
    }

    /**
     * A phase an earlier run finished is skipped, and the operator is told.
     *
     * A resumed run that silently re-entered every finished phase would look
     * identical to one that was actually redoing the work, which is the one
     * thing an operator watching a long migration needs to be able to tell apart.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_phase_an_earlier_run_finished_is_skipped() {
        $state = new Licensio_KB_Heroic_State();

        $state->complete( 'terms' );
        $state->complete( 'articles' );

        Licensio_KB_Heroic_CLI::run( array(), array( 'output-dir' => sys_get_temp_dir() ) );

        $output = implode( "\n", WP_CLI::$logs );

        $this->assertStringContainsString( 'Phase terms is already complete; skipping.', $output );
        $this->assertStringContainsString( 'Phase articles is already complete; skipping.', $output );
        $this->assertStringNotContainsString( 'Phase: terms', $output, 'A finished phase was entered again.' );
        $this->assertStringContainsString( 'Phase: searches', $output, 'An unfinished phase was skipped too.' );
    }

    /**
     * Resetting the resume point warns that analytics will be re-imported.
     *
     * The flag is safe for content and destructive for analytics, and an
     * operator who is not told that will run it and double every vote.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_reset_warns_that_analytics_will_be_imported_again() {
        Licensio_KB_Heroic_CLI::run(
            array(),
            array(
                'dry-run'    => true,
                'reset'      => true,
                'output-dir' => sys_get_temp_dir(),
            )
        );

        $this->assertStringContainsString( 'imported again', implode( "\n", WP_CLI::$warnings ) );
    }
}
