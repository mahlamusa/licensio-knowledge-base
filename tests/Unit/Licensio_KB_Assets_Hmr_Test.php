<?php
/**
 * Unit tests for the HMR gate.
 *
 * Separate from the main assets tests because `HMR_DEV` is a constant: it
 * cannot be defined and undefined within one process, so these run isolated.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

/**
 * Tests the conditions under which Licensio_KB_Assets uses the Vite dev server.
 *
 * @since 1.0.0
 */
class Licensio_KB_Assets_Hmr_Test extends Licensio_KB_Test_Case {

    /**
     * A production environment refuses HMR even with the constant set.
     *
     * The socket probe runs on the SERVER but the URL it gates is fetched by the
     * BROWSER, so reachability proves nothing about what the browser will load.
     * With `HMR_DEV` left in a production wp-config.php and anything listening on
     * the dev port server-side, every administrator's browser would be told to
     * execute whatever was listening on that port on their own machine, in the
     * wp-admin origin.
     *
     * Asserting the host filter is never applied proves the environment check
     * short-circuits BEFORE the probe, rather than the probe merely failing.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_production_environment_refuses_hmr() {
        define( 'HMR_DEV', 'licensio-kb' );

        Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );

        Filters\expectApplied( 'licensio_dev_server_host' )->never();

        $assets = new Licensio_KB_Assets();

        $this->assertFalse( $assets->is_hmr_enabled() );
    }

    /**
     * Outside production the probe runs, and runs only once per request.
     *
     * enqueue_app() asks the gate once per app, and the probe opens a socket
     * with a 200ms timeout, so three admin apps on one screen cost up to 600ms
     * of added latency per development request without the memo.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_probe_runs_once_per_request_outside_production() {
        define( 'HMR_DEV', 'licensio-kb' );

        Functions\when( 'wp_get_environment_type' )->justReturn( 'local' );

        // An unroutable port nothing listens on, so the probe fails fast.
        Filters\expectApplied( 'licensio_dev_server_host' )->once()->andReturn( '127.0.0.1' );
        Filters\expectApplied( 'licensio_dev_server_port' )->once()->andReturn( '6399' );

        $assets = new Licensio_KB_Assets();

        $this->assertFalse( $assets->is_hmr_enabled() );
        $this->assertFalse( $assets->is_hmr_enabled() );
        $this->assertFalse( $assets->is_hmr_enabled() );
    }

    /**
     * A constant naming a different plugin does not enable HMR here.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_constant_for_another_plugin_is_ignored() {
        define( 'HMR_DEV', 'some-other-plugin' );

        Functions\when( 'wp_get_environment_type' )->justReturn( 'local' );

        Filters\expectApplied( 'licensio_dev_server_host' )->never();

        $assets = new Licensio_KB_Assets();

        $this->assertFalse( $assets->is_hmr_enabled() );
    }
}
