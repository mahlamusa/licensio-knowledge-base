<?php
/**
 * A settings store double for the integration suite.
 *
 * Licensio_KB_Settings sanitises on read, so a test driving the real store can
 * never hand the code under test a value it would have to correct itself. This
 * double reports the raw value, which is what isolates the layer being tested.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

/**
 * A settings store that answers whatever the test hands it.
 *
 * @since 1.0.0
 */
class Licensio_KB_Integration_Settings extends Licensio_KB_Settings {

    /**
     * The values this double reports.
     *
     * @since 1.0.0
     * @var array<string, mixed>
     */
    private $values;

    /**
     * Build the double.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $values Setting name to raw value.
     */
    public function __construct( array $values = array() ) {
        $this->values = $values;
    }

    /**
     * Report a raw value.
     *
     * @since 1.0.0
     *
     * @param string $key      The setting name.
     * @param mixed  $fallback Returned when the test did not set the key.
     *
     * @return mixed The raw value.
     */
    public function get( $key, $fallback = null ) {
        return array_key_exists( $key, $this->values ) ? $this->values[ $key ] : $fallback;
    }
}
