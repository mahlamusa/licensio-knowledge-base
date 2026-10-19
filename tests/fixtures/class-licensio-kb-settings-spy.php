<?php
/**
 * A deliberately hostile settings store.
 *
 * Licensio_KB_Settings and Licensio_KB_REST_Settings_Controller both sanitise,
 * which means a test driven through the real store can pass with the
 * controller's validation removed: the store clamps the out-of-range number,
 * substitutes the default for the unusable colour and drops the unknown key, so
 * the option ends up correct however wrong the request was.
 *
 * This double removes the second layer. It records what the controller asked it
 * to write, verbatim, and writes it back unaltered, so an assertion about the
 * stored value is an assertion about the controller alone.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

/**
 * Records settings writes without sanitising them.
 *
 * @since 1.0.0
 */
class Licensio_KB_Settings_Spy extends Licensio_KB_Settings {

    /**
     * The registry this store reports.
     *
     * @since 1.0.0
     * @var array<string, array<string, mixed>>
     */
    private $spy_registry;

    /**
     * The current values, as written.
     *
     * @since 1.0.0
     * @var array<string, mixed>
     */
    private $values = array();

    /**
     * Every payload update() was called with, in order.
     *
     * @since 1.0.0
     * @var array<int, array<string, mixed>>
     */
    public $updates = array();

    /**
     * How many times delete() was called.
     *
     * @since 1.0.0
     * @var int
     */
    public $deletes = 0;

    /**
     * Build the double.
     *
     * @since 1.0.0
     *
     * @param array<string, array<string, mixed>> $registry The registry to report.
     * @param array<string, mixed>                $values   The starting values.
     */
    public function __construct( array $registry, array $values = array() ) {
        $this->spy_registry = $registry;
        $this->values       = $values;
    }

    /**
     * The registry this store reports.
     *
     * @since 1.0.0
     *
     * @return array<string, array<string, mixed>> The registry.
     */
    public function registry() {
        return $this->spy_registry;
    }

    /**
     * The registered defaults.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed> Setting key to default value.
     */
    public function defaults() {
        $defaults = array();

        foreach ( $this->spy_registry as $key => $definition ) {
            $defaults[ $key ] = isset( $definition['default'] ) ? $definition['default'] : null;
        }

        return $defaults;
    }

    /**
     * The current values, with no sanitisation on the way out.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed> The current values.
     */
    public function all() {
        return array_merge( $this->defaults(), $this->values );
    }

    /**
     * Record and apply a write, with no sanitisation on the way in.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $values The values to write.
     *
     * @return bool Always true.
     */
    public function update( array $values ) {
        $this->updates[] = $values;
        $this->values    = array_merge( $this->values, $values );

        return true;
    }

    /**
     * Forget every stored value.
     *
     * @since 1.0.0
     *
     * @return bool Always true.
     */
    public function delete() {
        ++$this->deletes;

        $this->values = array();

        return true;
    }
}
