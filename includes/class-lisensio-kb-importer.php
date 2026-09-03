<?php
/**
 * Import Framework.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registry for source importers.
 *
 * @since 1.0.0
 */
class Lisensio_KB_Importer {

    /**
     * Registered import drivers.
     *
     * @since 1.0.0
     * @var array<string, string> Key is driver ID, value is FQCN.
     */
    private $drivers = array();

    /**
     * Constructor.
     *
     * @since 1.0.0
     */
    public function __construct() {
        $this->register_driver( 'heroic', 'Lisensio_KB_Heroic_Import_Driver' );
    }

    /**
     * Register an import driver.
     *
     * @since 1.0.0
     *
     * @param string $id           The driver identifier.
     * @param string $driver_class The class name implementing Lisensio_KB_Import_Driver.
     *
     * @return void
     */
    public function register_driver( $id, $driver_class ) {
        $this->drivers[ (string) $id ] = (string) $driver_class;
    }

    /**
     * Get a specific driver instance.
     *
     * @since 1.0.0
     *
     * @param string $id The driver identifier.
     *
     * @return Lisensio_KB_Import_Driver|null The driver, or null if not registered or invalid.
     */
    public function get_driver( $id ) {
        if ( ! isset( $this->drivers[ $id ] ) || ! class_exists( $this->drivers[ $id ] ) ) {
            return null;
        }

        $driver = new $this->drivers[ $id ]();

        if ( $driver instanceof Lisensio_KB_Import_Driver ) {
            return $driver;
        }

        return null;
    }

    /**
     * Get all detected drivers.
     *
     * @since 1.0.0
     *
     * @return array<string, Lisensio_KB_Import_Driver> Detected drivers indexed by ID.
     */
    public function get_detected_drivers() {
        $detected = array();

        foreach ( $this->drivers as $id => $class ) {
            $driver = $this->get_driver( $id );
            if ( $driver && $driver->detect() ) {
                $detected[ $id ] = $driver;
            }
        }

        return $detected;
    }
}
