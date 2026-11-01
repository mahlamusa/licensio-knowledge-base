<?php
/**
 * Unit tests for behaviour when the vendored database library is missing.
 *
 * The plugin ships `libraries/`, so this only happens on a broken install — a
 * partial checkout, or a `composer install --no-dev` that skipped Strauss. It
 * matters more than its rarity suggests: the schema install runs on
 * activation, so an uncaught RuntimeException there blocks the activation
 * with a fatal error instead of finishing without the tables.
 *
 * These run in separate processes because they depend on the vendored library
 * NOT having been loaded, and once loaded it stays loaded for the process.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

use Brain\Monkey\Functions;

/**
 * Tests the degraded path through the installer and the upgrade routine.
 *
 * @since 1.0.0
 */
class Licensio_KB_DB_Degradation_Test extends Licensio_KB_Test_Case {

    /**
     * Stub the surface the installer touches.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        $GLOBALS['licensio_kb_delta_calls'] = array();
        $GLOBALS['wpdb']                    = new Licensio_KB_Test_WPDB(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

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
        unset( $GLOBALS['wpdb'], $GLOBALS['licensio_kb_delta_calls'] );

        parent::tearDown();
    }

    /**
     * Mark the database gateway booted without loading the vendored library.
     *
     * Licensio_KB_DB::init() returns early once booted, so the Strauss autoload
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
        $property = new ReflectionProperty( 'Licensio_KB_DB', 'booted' );

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

        $this->assertFalse( Licensio_KB_DB::is_available(), 'The library must be absent for this test to mean anything.' );

        $this->assertFalse( Licensio_KB_DB_Installer::install() );
        $this->assertSame( array(), $GLOBALS['licensio_kb_delta_calls'], 'No DDL may be attempted.' );
        $this->assertArrayNotHasKey( 'licensio_db_version', $this->options );
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
        $this->assertTrue( Licensio_KB_DB_Installer::install() );
        $this->assertArrayNotHasKey( 'licensio_db_version', $this->options );
    }
}
