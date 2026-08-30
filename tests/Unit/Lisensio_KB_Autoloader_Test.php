<?php
/**
 * Unit tests for the class autoloader.
 *
 * Covers the prefix guard, the Strauss-prefixed namespace decline, the
 * class-name allowlist and the WordPress filename transform.
 *
 * The observable behaviour is the `lisensio_autoload_paths` filter: it is
 * applied only once a name has passed every guard and is about to be turned
 * into a path, so "the filter never ran" is exactly "the name never became a
 * filesystem path".
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

use Brain\Monkey\Filters;

/**
 * Tests Lisensio_KB_Autoloader.
 *
 * @since 1.0.0
 */
class Lisensio_KB_Autoloader_Test extends Lisensio_KB_Test_Case {

    /**
     * A class name that is not a bare identifier never becomes a path.
     *
     * class_exists() fires every registered autoloader with whatever string it
     * is given, and Lisensio_KB_REST_API::register_controllers() feeds it a
     * filter-supplied value.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_class_name_that_is_not_an_identifier_never_becomes_a_path() {
        $applied = $this->count_path_lookups();

        $names = array(
            'Lisensio_KB/../../../../wp-config',
            'Lisensio_KB_Foo/../Bar',
            'Lisensio_KB' . "\0" . 'Foo',
            'Lisensio_KB Foo',
            'Lisensio_KB-Foo',
            'Lisensio_KB.Foo',
        );

        foreach ( $names as $name ) {
            Lisensio_KB_Autoloader::autoload( $name );
        }

        $this->assertSame( 0, $applied->count, 'No rejected name may reach the path lookup.' );
    }

    /**
     * A foreign class name is left to the rest of the SPL chain.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_foreign_class_name_is_declined() {
        $applied = $this->count_path_lookups();

        Lisensio_KB_Autoloader::autoload( 'WooCommerce' );
        Lisensio_KB_Autoloader::autoload( 'WP_Query' );

        $this->assertSame( 0, $applied->count );
    }

    /**
     * The Strauss-prefixed vendor namespace is declined.
     *
     * Those names pass the `Lisensio_KB` prefix test but must not be mangled
     * into a `class-lisensio-kb-...` filename.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_prefixed_vendor_namespace_is_declined() {
        $applied = $this->count_path_lookups();

        Lisensio_KB_Autoloader::autoload( 'Lisensio_KB\StellarWP\DB\DB' );

        $this->assertSame( 0, $applied->count );
    }

    /**
     * A legitimate class name is resolved, proving the guard is not blanket.
     *
     * Without this the tests above would still pass if the autoloader simply
     * refused everything.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_legitimate_class_name_is_resolved() {
        $applied = $this->count_path_lookups();

        Lisensio_KB_Autoloader::autoload( 'Lisensio_KB_Settings' );

        $this->assertSame( 1, $applied->count, 'A valid class name must reach the path lookup.' );
        $this->assertTrue( class_exists( 'Lisensio_KB_Settings', false ) );
    }

    /**
     * Count how many times the autoloader reaches its path lookup.
     *
     * The `lisensio_autoload_paths` filter is applied only once a name has
     * passed every guard, so this counts exactly the names that became a
     * filesystem path.
     *
     * @since 1.0.0
     *
     * @return object A counter whose `count` property holds the tally.
     */
    private function count_path_lookups() {
        $counter = new stdClass();

        $counter->count = 0;

        /*
         * Brain Monkey does NOT invoke callbacks registered with add_filter()
         * when the code under test calls apply_filters(); expectApplied() is the
         * only way to observe a filter. Counting through add_filter() would have
         * reported zero for every case and passed vacuously.
         */
        Filters\expectApplied( 'lisensio_autoload_paths' )
            ->zeroOrMoreTimes()
            ->andReturnUsing(
                static function ( $paths ) use ( $counter ) {
                    $counter->count++;

                    return $paths;
                }
            );

        return $counter;
    }

    /**
     * Every plugin class maps to a file that exists.
     *
     * Pins the class-name to filename transform against the awkward cases —
     * acronyms and multi-word suffixes.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_every_plugin_class_resolves_to_a_real_file() {
        $classes = array(
            'Lisensio_KB'                       => 'class-lisensio-kb.php',
            'Lisensio_KB_DB'                    => 'class-lisensio-kb-db.php',
            'Lisensio_KB_DB_Installer'          => 'class-lisensio-kb-db-installer.php',
            'Lisensio_KB_REST_API'              => 'class-lisensio-kb-rest-api.php',
            'Lisensio_KB_REST_Stubs_Controller' => 'class-lisensio-kb-rest-stubs-controller.php',
            'Lisensio_KB_Uninstaller'           => 'class-lisensio-kb-uninstaller.php',
        );

        foreach ( $classes as $class_name => $expected_file ) {
            $this->assertSame(
                $expected_file,
                'class-' . str_replace( '_', '-', strtolower( $class_name ) ) . '.php'
            );
            $this->assertFileExists( LISENSIO_KB_ABSPATH . 'includes/' . $expected_file );
        }
    }
}
