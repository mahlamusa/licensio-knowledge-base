<?php
/**
 * What the migration did, and what it could not do.
 *
 * A counter bag with one rule behind it: nothing is allowed to be lossy in
 * silence. Every mapping this tool cannot make faithfully increments a named
 * counter and, where the detail matters, records a line the operator can act
 * on. A run that discards a category icon or files an article under a term
 * that was not migrated says so on the way out, with numbers.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Accumulates counts and notes for one migration run.
 *
 * @since 1.0.0
 */
class Licensio_KB_Heroic_Report {

    /**
     * Counters, keyed by name.
     *
     * @since 1.0.0
     * @var array<string, int>
     */
    private $counts = array();

    /**
     * Notes worth showing the operator, keyed by category.
     *
     * @since 1.0.0
     * @var array<string, array<int, string>>
     */
    private $notes = array();

    /**
     * Add to a counter.
     *
     * @since 1.0.0
     *
     * @param string $name The counter name.
     * @param int    $by   How much to add.
     *
     * @return void
     */
    public function count( $name, $by = 1 ) {
        $name = (string) $name;

        $this->counts[ $name ] = $this->get( $name ) + (int) $by;
    }

    /**
     * Read a counter.
     *
     * @since 1.0.0
     *
     * @param string $name The counter name.
     *
     * @return int The count, 0 when the counter was never touched.
     */
    public function get( $name ) {
        $name = (string) $name;

        return isset( $this->counts[ $name ] ) ? (int) $this->counts[ $name ] : 0;
    }

    /**
     * Every counter.
     *
     * @since 1.0.0
     *
     * @return array<string, int> Counter name to value.
     */
    public function counts() {
        return $this->counts;
    }

    /**
     * Record a note the operator should read.
     *
     * @since 1.0.0
     *
     * @param string $category Which group the note belongs to.
     * @param string $note     The note.
     *
     * @return void
     */
    public function note( $category, $note ) {
        $this->notes[ (string) $category ][] = (string) $note;
    }

    /**
     * The notes in one category.
     *
     * @since 1.0.0
     *
     * @param string $category The group.
     *
     * @return string[] The notes, in the order they were recorded.
     */
    public function notes( $category ) {
        $category = (string) $category;

        return isset( $this->notes[ $category ] ) ? $this->notes[ $category ] : array();
    }

    /**
     * Every note category that has at least one note.
     *
     * @since 1.0.0
     *
     * @return string[] The category names.
     */
    public function note_categories() {
        return array_keys( $this->notes );
    }
}
