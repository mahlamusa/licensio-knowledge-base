<?php
/**
 * Unit tests for behaviour when the vendored database library is missing.
 *
 * The plugin ships `libraries/`, so this only happens on a broken install — a
 * partial checkout, or a `composer install --no-dev` that skipped Strauss. It
 * matters more than its rarity suggests: the schema install runs on
 * `admin_init`, so an uncaught RuntimeException there locks the administrator
 * out of the Plugins screen, which is the exact screen they would need to
 * deactivate the plugin and recover.
 *
 * These run in separate processes because they depend on the vendored library
 * NOT having been loaded, and once loaded it stays loaded for the process.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

use Brain\Monkey\Functions;

/**
 * Tests the degraded path through the installer and the upgrade routine.
 *
 * @since 1.0.0
 */
class Lisensio_KB_DB_Degradation_Test extends Lisensio_KB_Test_Case {

    /**
     * Stub the surface the installer touches.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        $GLOBALS['lisensio_kb_delta_calls'] = array();
        $GLOBALS['wpdb']                    = new Lisensio_KB_Test_WPDB(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

        Functions\when( 'wp_clear_scheduled_hook' )->justReturn( 1 );
    }

    /**
     * Clean the globals the test set.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function tearDown(): void {
        unset( $GLOBALS['wpdb'], $GLOBALS['lisensio_kb_delta_calls'] );

        parent::tearDown();
    }

    /**
     * Mark the database gateway booted without loading the vendored library.
     *
     * Lisensio_KB_DB::init() returns early once booted, so the Strauss autoload
     * is never required and is_available() answers false — the same state a
     * broken install is in. Reflection is used rather than a production seam so
     * that nothing exists purely for the test.
     *
     * @since 1.0.0
     *
     * @param bool $booted Whether to mark the gateway as already booted.
     *
     * @return void
     * @throws ReflectionException When the property cannot be reached.
     */
    private function set_db_booted( $booted ) {
        $property = new ReflectionProperty( 'Lisensio_KB_DB', 'booted' );

        $property->setAccessible( true );
        $property->setValue( null, $booted );
    }

    /**
     * install() reports failure instead of throwing.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_install_returns_false_when_the_library_is_missing() {
        $this->set_db_booted( true );

        $this->assertFalse( Lisensio_KB_DB::is_available(), 'The library must be absent for this test to mean anything.' );

        $this->assertFalse( Lisensio_KB_DB_Installer::install() );
        $this->assertSame( array(), $GLOBALS['lisensio_kb_delta_calls'], 'No DDL may be attempted.' );
        $this->assertArrayNotHasKey( 'lisensio_db_version', $this->options );
    }

    /**
     * The upgrade routine records no version when the install could not run.
     *
     * Writing the version marker anyway would mark the site as up to date and
     * permanently skip the repair, because maybe_upgrade() early-returns once
     * the recorded version matches the code.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_no_version_is_recorded_when_the_install_could_not_run() {
        $this->set_db_booted( true );

        Lisensio_KB_Lifecycle::maybe_upgrade();

        $this->assertArrayNotHasKey( 'lisensio_version', $this->options );
        $this->assertArrayNotHasKey( 'lisensio_db_version', $this->options );
    }

    /**
     * A broken install repairs itself on the next admin request.
     *
     * This is the whole point of not recording the version: the first pass
     * fails and changes nothing, and once the library is back the next pass
     * installs and records normally.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_install_is_retried_on_the_next_load() {
        $this->set_db_booted( true );

        Lisensio_KB_Lifecycle::maybe_upgrade();

        $this->assertArrayNotHasKey( 'lisensio_version', $this->options, 'The first pass must record nothing.' );

        // The library is back: allow init() to load it on the next attempt.
        $this->set_db_booted( false );

        Lisensio_KB_Lifecycle::maybe_upgrade();

        $this->assertTrue( Lisensio_KB_DB::is_available() );
        $this->assertSame( LISENSIO_KB_VERSION, $this->options['lisensio_version'] );
        $this->assertSame( LISENSIO_KB_DB_VERSION, $this->options['lisensio_db_version'] );
        $this->assertCount( 4, $GLOBALS['lisensio_kb_delta_calls'], 'All four tables must be created on the repair pass.' );
    }

    /**
     * A healthy install reports success.
     *
     * The positive control: without it every assertion above would still pass
     * if install() simply always returned false.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_install_returns_true_on_a_healthy_install() {
        $this->assertTrue( Lisensio_KB_DB_Installer::install() );
        $this->assertSame( LISENSIO_KB_DB_VERSION, $this->options['lisensio_db_version'] );
    }
}
