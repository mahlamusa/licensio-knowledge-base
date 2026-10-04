<?php
/**
 * The import log: what happened to every item an import touched.
 *
 * The report holds counters for the summary; this holds the detail behind
 * them. Every category, tag and article gets one row naming its title, its
 * source and target ids, the categories it was filed under, every source meta
 * key and what became of it, and a status. Anything that failed says why in
 * plain words. The analytics phases would drown the log if every vote and
 * view had a row, so they write one row per batch plus one per record that
 * failed or pointed at an article that no longer exists.
 *
 * Dry runs are logged too, flagged, because the dry run is when an operator
 * most needs to see what would fail. Rows are kept until the operator clears
 * them and are dropped with the other tables on uninstall.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Writes and reads the import log table.
 *
 * @since 1.0.0
 */
class Lisensio_KB_Import_Log {

    /**
     * The unprefixed table name.
     *
     * @since 1.0.0
     * @var string
     */
    const TABLE = 'lisensio_import_log';

    /**
     * How many characters of a meta value the log keeps.
     *
     * Enough to see a bad mapping; not so much that a serialised blob is copied
     * wholesale into the log.
     *
     * @since 1.0.0
     * @var int
     */
    const VALUE_MAX_LENGTH = 200;

    /**
     * The item was created in the target.
     *
     * @since 1.0.0
     * @var string
     */
    const STATUS_CREATED = 'created';

    /**
     * An existing, unclaimed target item was adopted and updated.
     *
     * @since 1.0.0
     * @var string
     */
    const STATUS_ADOPTED = 'adopted';

    /**
     * A source category was merged into an existing target category.
     *
     * @since 1.0.0
     * @var string
     */
    const STATUS_MERGED = 'merged';

    /**
     * The item was imported by an earlier run and left as it is.
     *
     * @since 1.0.0
     * @var string
     */
    const STATUS_SKIPPED = 'skipped';

    /**
     * A setting was changed to the source's value.
     *
     * @since 1.0.0
     * @var string
     */
    const STATUS_UPDATED = 'updated';

    /**
     * The item could not be imported.
     *
     * @since 1.0.0
     * @var string
     */
    const STATUS_FAILED = 'failed';

    /**
     * An analytics record names an article that is not in the source any more.
     *
     * @since 1.0.0
     * @var string
     */
    const STATUS_ORPHANED = 'orphaned';

    /**
     * The item was imported, but something about it was lossy.
     *
     * @since 1.0.0
     * @var string
     */
    const STATUS_WARNING = 'warning';

    /**
     * A progress or summary row.
     *
     * @since 1.0.0
     * @var string
     */
    const STATUS_INFO = 'info';

    /**
     * The run every entry belongs to.
     *
     * @since 1.0.0
     * @var string
     */
    private $run_id = '';

    /**
     * The source driver id.
     *
     * @since 1.0.0
     * @var string
     */
    private $source = '';

    /**
     * Whether the run is a dry run.
     *
     * @since 1.0.0
     * @var bool
     */
    private $dry_run = false;

    /**
     * Whether entries are written at all.
     *
     * False for the unit tests that drive the importers without a database.
     *
     * @since 1.0.0
     * @var bool
     */
    private $enabled = true;

    /**
     * Constructor.
     *
     * @since 1.0.0
     *
     * @param string $run_id  The run every entry belongs to.
     * @param string $source  The source driver id.
     * @param bool   $dry_run Whether the run is a dry run.
     * @param bool   $enabled Whether entries are written at all.
     */
    public function __construct( $run_id, $source, $dry_run = false, $enabled = true ) {
        $this->run_id  = (string) $run_id;
        $this->source  = (string) $source;
        $this->dry_run = (bool) $dry_run;
        $this->enabled = (bool) $enabled;
    }

    /**
     * A log that writes nothing.
     *
     * @since 1.0.0
     *
     * @return Lisensio_KB_Import_Log The disabled log.
     */
    public static function disabled() {
        return new self( '', '', false, false );
    }

    /**
     * The run every entry belongs to.
     *
     * @since 1.0.0
     *
     * @return string The run id.
     */
    public function run_id() {
        return $this->run_id;
    }

    /**
     * Write one entry.
     *
     * @since 1.0.0
     *
     * @param string               $phase     The import phase, e.g. `articles`.
     * @param string               $item_type What the entry is about, e.g. `article`, `category`, `batch`.
     * @param int                  $source_id The item's id in the source, 0 when there is none.
     * @param int                  $target_id The item's id in the target, 0 when there is none.
     * @param string               $title     The item's title or name.
     * @param string               $status    One of the STATUS_ constants.
     * @param string               $message   A plain-language explanation; required for failures.
     * @param array<string, mixed> $details   Structured detail, stored as JSON.
     *
     * @return bool True when the entry was written.
     */
    public function entry( $phase, $item_type, $source_id, $target_id, $title, $status, $message = '', array $details = array() ) {
        if ( ! $this->enabled ) {
            return false;
        }

        $json = empty( $details ) ? '' : wp_json_encode( $details );

        return (bool) Lisensio_KB_DB::table( self::TABLE )->insert(
            array(
                'run_id'     => $this->run_id,
                'source'     => $this->source,
                'dry_run'    => $this->dry_run ? 1 : 0,
                'phase'      => (string) $phase,
                'item_type'  => (string) $item_type,
                'source_id'  => max( 0, (int) $source_id ),
                'target_id'  => max( 0, (int) $target_id ),
                'title'      => self::truncate( (string) $title, 255 ),
                'status'     => (string) $status,
                'message'    => (string) $message,
                'details'    => is_string( $json ) ? $json : '',
                'created_at' => current_time( 'mysql', true ),
            ),
            array( '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
        );
    }

    /**
     * Describe one source meta row for the log.
     *
     * @since 1.0.0
     *
     * @param string $key    The meta key.
     * @param mixed  $value  The raw meta value.
     * @param string $action What became of it: migrated, derived, skipped or not_migrated.
     * @param string $target Where it went, or why it did not.
     *
     * @return array<string, string> The meta entry.
     */
    public static function meta_entry( $key, $value, $action, $target = '' ) {
        return array(
            'key'    => (string) $key,
            'value'  => self::truncate( is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value ), self::VALUE_MAX_LENGTH ),
            'action' => (string) $action,
            'target' => (string) $target,
        );
    }

    /**
     * Every run in the log, newest first.
     *
     * @since 1.0.0
     *
     * @return array<int, array<string, mixed>> One row per run: run_id, source, dry_run, started_at, entries, failed.
     */
    public static function runs() {
        global $wpdb;

        $table = Lisensio_KB_DB::table_name( self::TABLE );

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Our own table name; the query builder has no conditional aggregates.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT run_id, source, MAX(dry_run) AS dry_run, MIN(created_at) AS started_at, COUNT(*) AS entries,
                        SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) AS failed
                 FROM {$table}
                 GROUP BY run_id, source
                 ORDER BY MIN(id) DESC",
                self::STATUS_FAILED
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        $runs = array();

        foreach ( (array) $rows as $row ) {
            $runs[] = array(
                'run_id'     => (string) $row['run_id'],
                'source'     => (string) $row['source'],
                'dry_run'    => (bool) (int) $row['dry_run'],
                'started_at' => (string) $row['started_at'],
                'entries'    => (int) $row['entries'],
                'failed'     => (int) $row['failed'],
            );
        }

        return $runs;
    }

    /**
     * One page of a run's entries.
     *
     * @since 1.0.0
     *
     * @param string $run_id   The run.
     * @param string $status   Only this status, or '' for all.
     * @param int    $page     1-based page.
     * @param int    $per_page Entries per page; 0 returns every entry.
     *
     * @return array{items: array<int, array<string, mixed>>, total: int} The entries and the total that match.
     */
    public static function entries( $run_id, $status = '', $page = 1, $per_page = 50 ) {
        $count = Lisensio_KB_DB::table( self::TABLE )->where( 'run_id', (string) $run_id );
        $query = Lisensio_KB_DB::table( self::TABLE )->where( 'run_id', (string) $run_id );

        if ( '' !== (string) $status ) {
            $count->where( 'status', (string) $status );
            $query->where( 'status', (string) $status );
        }

        $query->orderBy( 'id', 'ASC' );

        if ( (int) $per_page > 0 ) {
            $query->limit( (int) $per_page )->offset( ( max( 1, (int) $page ) - 1 ) * (int) $per_page );
        }

        $items = array();

        foreach ( (array) $query->getAll( ARRAY_A ) as $row ) {
            $items[] = self::format( (array) $row );
        }

        return array(
            'items' => $items,
            'total' => (int) $count->count(),
        );
    }

    /**
     * Delete every entry, or one run's.
     *
     * @since 1.0.0
     *
     * @param string $run_id The run to delete, or '' for the whole log.
     *
     * @return void
     */
    public static function clear( $run_id = '' ) {
        if ( '' === (string) $run_id ) {
            Lisensio_KB_DB::query( 'TRUNCATE TABLE ' . Lisensio_KB_DB::table_name( self::TABLE ) );

            return;
        }

        Lisensio_KB_DB::table( self::TABLE )->where( 'run_id', (string) $run_id )->delete();
    }

    /**
     * A run's entries as CSV lines, header first.
     *
     * Details are flattened into the categories and meta columns so the file
     * reads in a spreadsheet without a JSON viewer.
     *
     * @since 1.0.0
     *
     * @param string $run_id The run.
     *
     * @return string The CSV document.
     */
    public static function to_csv( $run_id ) {
        $lines = array( self::csv_line( array( 'id', 'created_at', 'dry_run', 'phase', 'item_type', 'source_id', 'target_id', 'title', 'status', 'message', 'categories', 'meta' ) ) );

        $entries = self::entries( $run_id, '', 1, 0 );

        foreach ( $entries['items'] as $item ) {
            $details    = $item['details'];
            $categories = array();
            $meta       = array();

            foreach ( array( 'categories', 'tags' ) as $group ) {
                foreach ( isset( $details[ $group ] ) && is_array( $details[ $group ] ) ? $details[ $group ] : array() as $term ) {
                    $categories[] = ( isset( $term['name'] ) ? $term['name'] : '' ) . ' (' . ( isset( $term['status'] ) ? $term['status'] : '' ) . ')';
                }
            }

            foreach ( isset( $details['meta'] ) && is_array( $details['meta'] ) ? $details['meta'] : array() as $row ) {
                $meta[] = ( isset( $row['key'] ) ? $row['key'] : '' ) . ': ' . ( isset( $row['action'] ) ? $row['action'] : '' );
            }

            $lines[] = self::csv_line(
                array(
                    $item['id'],
                    $item['created_at'],
                    $item['dry_run'] ? 'yes' : 'no',
                    $item['phase'],
                    $item['item_type'],
                    $item['source_id'],
                    $item['target_id'],
                    $item['title'],
                    $item['status'],
                    $item['message'],
                    implode( '; ', $categories ),
                    implode( '; ', $meta ),
                )
            );
        }

        return implode( '', $lines );
    }

    /**
     * One table row as the shape the wizard reads.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $row The raw row.
     *
     * @return array<string, mixed> The entry, with details decoded.
     */
    private static function format( array $row ) {
        $details = isset( $row['details'] ) && '' !== (string) $row['details'] ? json_decode( (string) $row['details'], true ) : array();

        return array(
            'id'         => (int) $row['id'],
            'run_id'     => (string) $row['run_id'],
            'source'     => (string) $row['source'],
            'dry_run'    => (bool) (int) $row['dry_run'],
            'phase'      => (string) $row['phase'],
            'item_type'  => (string) $row['item_type'],
            'source_id'  => (int) $row['source_id'],
            'target_id'  => (int) $row['target_id'],
            'title'      => (string) $row['title'],
            'status'     => (string) $row['status'],
            'message'    => (string) $row['message'],
            'details'    => is_array( $details ) ? $details : array(),
            'created_at' => (string) $row['created_at'],
        );
    }

    /**
     * One CSV line, every field quoted.
     *
     * @since 1.0.0
     *
     * @param array<int, mixed> $values The fields.
     *
     * @return string The line, newline-terminated.
     */
    private static function csv_line( array $values ) {
        $fields = array();

        foreach ( $values as $value ) {
            $value = (string) $value;

            // A leading formula character would be executed by a spreadsheet.
            if ( '' !== $value && false !== strpos( '=+-@', $value[0] ) ) {
                $value = "'" . $value;
            }

            $fields[] = '"' . str_replace( '"', '""', $value ) . '"';
        }

        return implode( ',', $fields ) . "\n";
    }

    /**
     * Cut a string to a length, multibyte-safe.
     *
     * @since 1.0.0
     *
     * @param string $value  The string.
     * @param int    $length The maximum length.
     *
     * @return string The string, cut and marked with an ellipsis when it was longer.
     */
    private static function truncate( $value, $length ) {
        if ( function_exists( 'mb_strlen' ) && mb_strlen( $value ) > $length ) {
            return mb_substr( $value, 0, $length - 1 ) . '…';
        }

        if ( ! function_exists( 'mb_strlen' ) && strlen( $value ) > $length ) {
            return substr( $value, 0, $length - 1 ) . '…';
        }

        return $value;
    }
}
