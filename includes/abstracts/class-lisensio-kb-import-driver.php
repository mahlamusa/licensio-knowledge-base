<?php
/**
 * Interface for a knowledge base import source.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Defines what a source importer must implement.
 *
 * @since 1.0.0
 */
abstract class Lisensio_KB_Import_Driver {

    /**
     * Whether this source is present on the installation.
     *
     * @since 1.0.0
     *
     * @return bool True if the source is detected.
     */
    abstract public function detect();

    /**
     * How many items of each type this source has.
     *
     * Used for the preview counts before the import begins.
     *
     * @since 1.0.0
     *
     * @return array<string, int> Associative array of type to count.
     */
    abstract public function counts();

    /**
     * Process one batch of the migration.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $options Options from the wizard (e.g. batch_size, offset).
     *
     * @return array{phase: string, done: int, total: int, finished: bool} Progress of the current phase.
     */
    abstract public function run_batch( array $options );

    /**
     * Whether the entire migration is complete.
     *
     * @since 1.0.0
     *
     * @return bool True when finished.
     */
    abstract public function is_complete();

    /**
     * Reset the migration state to start fresh.
     *
     * @since 1.0.0
     *
     * @return void
     */
    abstract public function reset();

    /**
     * Summary report of the migration.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed> Keys for the summary view.
     */
    abstract public function summary();
}
