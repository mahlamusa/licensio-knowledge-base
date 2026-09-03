<?php
/**
 * What the migration did, and what it could not do.
 *
 * A counter bag with one rule behind it: nothing is allowed to be lossy in
 * silence. Every mapping this tool cannot make faithfully increments a named
 * counter and, where the detail matters, records a line the operator can act
 * on. A run that discards a category icon or collapses three article positions
 * into one says so on the way out, with numbers.
 *
 * The wizard runs one AJAX batch at a time, so the report is kept in an option
 * and reloaded on every batch: counters accumulate across requests, and the
 * summary at the end covers the whole run rather than the last batch.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Accumulates counts and notes for one migration run.
 *
 * @since 1.0.0
 */
class Lisensio_KB_Heroic_Import_Report {

    /**
     * The option the report is kept in.
     *
     * @since 1.0.0
     * @var string
     */
    const OPTION_NAME = 'lisensio_import_report_heroic';

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
     * Constructor.
     *
     * Reloads the accumulated report of a run in progress.
     *
     * @since 1.0.0
     */
    public function __construct() {
        $stored = get_option( self::OPTION_NAME, array() );

        if ( is_array( $stored ) ) {
            $this->counts = isset( $stored['counts'] ) && is_array( $stored['counts'] ) ? $stored['counts'] : array();
            $this->notes  = isset( $stored['notes'] ) && is_array( $stored['notes'] ) ? $stored['notes'] : array();
        }
    }

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

    /**
     * Persist the accumulated report for the next batch.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function save() {
        update_option(
            self::OPTION_NAME,
            array(
                'counts' => $this->counts,
                'notes'  => $this->notes,
            ),
            false
        );
    }

    /**
     * Forget the report of the previous run.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function reset() {
        $this->counts = array();
        $this->notes  = array();

        delete_option( self::OPTION_NAME );
    }

    /**
     * Everything the summary view needs.
     *
     * @since 1.0.0
     *
     * @return array{counts: array<string, int>, notes: array<string, string[]>} The counters, and the notes by category.
     */
    public function get_all() {
        return array(
            'counts' => $this->counts,
            'notes'  => $this->notes,
        );
    }
}
