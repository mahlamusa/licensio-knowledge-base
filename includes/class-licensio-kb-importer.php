<?php
/**
 * Import Framework.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registry for source importers.
 *
 * The built-in drivers are registered here; any other source is added through
 * the `licensio_import_drivers` filter with a subclass of
 * `Licensio_KB_Import_Driver`, and the wizard, the uninstaller and the
 * duplicates tool pick it up from this registry with no other change.
 *
 * @since 1.0.0
 */
class Licensio_KB_Importer {

    /**
     * Registered import drivers.
     *
     * @since 1.0.0
     * @var array<string, string> Driver id to class name.
     */
    private $drivers = array();

    /**
     * Constructor.
     *
     * @since 1.0.0
     */
    public function __construct() {
        foreach ( self::driver_classes() as $id => $driver_class ) {
            $this->register_driver( $id, $driver_class );
        }
    }

    /**
     * Every registered driver class, built-in and filtered.
     *
     * @since 1.0.0
     *
     * @return array<string, string> Driver id to class name.
     */
    public static function driver_classes() {
        $drivers = array(
            'heroic'     => 'Licensio_KB_Heroic_Import_Driver',
            'betterdocs' => 'Licensio_KB_BetterDocs_Import_Driver',
        );

        /**
         * Filters the import drivers the wizard offers.
         *
         * @since 1.0.0
         *
         * @param array<string, string> $drivers Driver id to the name of a `Licensio_KB_Import_Driver` subclass.
         */
        $filtered = apply_filters( 'licensio_import_drivers', $drivers );

        $out = array();

        foreach ( is_array( $filtered ) ? $filtered : $drivers as $id => $driver_class ) {
            $id = sanitize_key( (string) $id );

            if ( '' !== $id && is_string( $driver_class ) && '' !== $driver_class ) {
                $out[ $id ] = $driver_class;
            }
        }

        return $out;
    }

    /**
     * The provenance post meta key of every registered source.
     *
     * An article carrying any of them belongs to an import; no other import
     * may adopt it.
     *
     * @since 1.0.0
     *
     * @return string[] Meta keys.
     */
    public static function provenance_post_keys() {
        $keys = array();

        foreach ( array_keys( self::driver_classes() ) as $id ) {
            $keys[] = Licensio_KB_Import_Source::provenance_post_key_for( $id );
        }

        return $keys;
    }

    /**
     * Register a driver.
     *
     * @since 1.0.0
     *
     * @param string $id           The driver id.
     * @param string $driver_class The driver class name.
     *
     * @return void
     */
    public function register_driver( $id, $driver_class ) {
        $this->drivers[ (string) $id ] = (string) $driver_class;
    }

    /**
     * Every registered driver id.
     *
     * @since 1.0.0
     *
     * @return string[] Driver ids.
     */
    public function ids() {
        return array_keys( $this->drivers );
    }

    /**
     * Get a driver by id.
     *
     * @since 1.0.0
     *
     * @param string $id The driver id.
     *
     * @return Licensio_KB_Import_Driver|null The driver, or null when unknown.
     */
    public function get_driver( $id ) {
        if ( ! isset( $this->drivers[ $id ] ) || ! class_exists( $this->drivers[ $id ] ) ) {
            return null;
        }

        $driver = new $this->drivers[ $id ]();

        if ( $driver instanceof Licensio_KB_Import_Driver ) {
            return $driver;
        }

        return null;
    }

    /**
     * Every registered driver.
     *
     * @since 1.0.0
     *
     * @return array<string, Licensio_KB_Import_Driver> Driver id to driver.
     */
    public function get_drivers() {
        $drivers = array();

        foreach ( array_keys( $this->drivers ) as $id ) {
            $driver = $this->get_driver( $id );

            if ( $driver ) {
                $drivers[ $id ] = $driver;
            }
        }

        return $drivers;
    }

    /**
     * Get drivers whose source data is present.
     *
     * @since 1.0.0
     *
     * @return array<string, Licensio_KB_Import_Driver> Driver id to driver.
     */
    public function get_detected_drivers() {
        $detected = array();

        foreach ( $this->get_drivers() as $id => $driver ) {
            if ( $driver->detect() ) {
                $detected[ $id ] = $driver;
            }
        }

        return $detected;
    }

    /**
     * The post types every source keeps its articles in.
     *
     * @since 1.0.0
     *
     * @return string[] Post types.
     */
    public function source_post_types() {
        $types = array();

        foreach ( $this->get_drivers() as $driver ) {
            $types[] = $driver->source()->post_type();
        }

        return array_values( array_unique( $types ) );
    }

    /**
     * Every option the importers keep, for the uninstaller.
     *
     * @since 1.0.0
     *
     * @return string[] Option names.
     */
    public function option_names() {
        $names = array( Licensio_KB_Import_Redirects::OPTION );

        foreach ( $this->get_drivers() as $driver ) {
            $names = array_merge( $names, $driver->option_names() );
        }

        return array_values( array_unique( $names ) );
    }
}
