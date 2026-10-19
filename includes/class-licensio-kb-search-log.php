<?php
/**
 * Search logging.
 *
 * Writes one row to `licensio_search_log` per search, so the analytics issue
 * can answer what visitors looked for and what they failed to find. The
 * visitor is identified by the rotating salted hash from Licensio_KB_Visitor,
 * never by an IP address.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Records searches against the search log table.
 *
 * @since 1.0.0
 */
class Licensio_KB_Search_Log {

    /**
     * The table this class writes to.
     *
     * @since 1.0.0
     * @var string
     */
    const TABLE = 'licensio_search_log';

    /**
     * Where a logged search came from.
     *
     * `site` is the full results page, `live` is the type-ahead, and `ticket`
     * is reserved for the pro deflection surface.
     *
     * @since 1.0.0
     * @var string[]
     */
    const SOURCES = array( 'site', 'live', 'ticket' );

    /**
     * The default source when none was supplied.
     *
     * @since 1.0.0
     * @var string
     */
    const DEFAULT_SOURCE = 'site';

    /**
     * The visitor identity helper.
     *
     * @since 1.0.0
     * @var Licensio_KB_Visitor
     */
    private $visitor;

    /**
     * The settings store.
     *
     * @since 1.0.0
     * @var Licensio_KB_Settings
     */
    private $settings;

    /**
     * Constructor.
     *
     * @since 1.0.0
     *
     * @param Licensio_KB_Visitor|null  $visitor  The visitor identity helper.
     * @param Licensio_KB_Settings|null $settings The settings store.
     */
    public function __construct( $visitor = null, $settings = null ) {
        $this->visitor  = $visitor instanceof Licensio_KB_Visitor ? $visitor : new Licensio_KB_Visitor();
        $this->settings = $settings instanceof Licensio_KB_Settings ? $settings : new Licensio_KB_Settings();
    }

    /**
     * Record one search.
     *
     * @since 1.0.0
     *
     * @param string $term          The sanitized search term.
     * @param int    $results_count How many articles matched.
     * @param string $source        Where the search came from.
     *
     * @return bool True when a row was written.
     */
    public function record( $term, $results_count, $source = self::DEFAULT_SOURCE ) {
        $term   = (string) $term;
        $source = self::sanitize_source( $source );

        if ( '' === $term ) {
            return false;
        }

        if ( ! $this->is_enabled( $term, $source ) ) {
            return false;
        }

        $row = array(
            'term'            => $term,
            'normalized_term' => self::normalize( $term ),
            'results_count'   => max( 0, (int) $results_count ),
            'visitor_hash'    => (string) $this->visitor->get_hash(),
            'source'          => $source,
            'created_at'      => (string) current_time( 'mysql', true ),
        );

        Licensio_KB_DB::table( self::TABLE )->insert( $row );

        /**
         * Fires after a search has been written to the search log.
         *
         * @since 1.0.0
         *
         * @param array<string, mixed> $row The row that was written.
         */
        do_action( 'licensio_search_logged', $row );

        return true;
    }

    /**
     * Whether this search should be logged at all.
     *
     * @since 1.0.0
     *
     * @param string $term   The sanitized search term.
     * @param string $source Where the search came from.
     *
     * @return bool True when the row should be written.
     */
    private function is_enabled( $term, $source ) {
        $enabled = (bool) $this->settings->get( 'enable_analytics', true );

        /**
         * Filter whether a search is written to the search log.
         *
         * Returning false suppresses the row. Analytics collection is already
         * governed by the `enable_analytics` setting; this filter is for a site
         * that wants finer control, such as excluding logged-in editors.
         *
         * @since 1.0.0
         *
         * @param bool   $enabled Whether to log this search.
         * @param string $term    The sanitized search term.
         * @param string $source  Where the search came from.
         */
        return (bool) apply_filters( 'licensio_log_search', $enabled, $term, $source );
    }

    /**
     * Reduce a term to its comparable form.
     *
     * Lower-cased and whitespace-collapsed, so `Install  Licence` and
     * `install licence` aggregate as one term in the analytics reports.
     *
     * @since 1.0.0
     *
     * @param string $term The sanitized search term.
     *
     * @return string The normalized term.
     */
    public static function normalize( $term ) {
        $term = (string) preg_replace( '/\s+/u', ' ', (string) $term );
        $term = trim( $term );

        if ( function_exists( 'mb_strtolower' ) ) {
            return mb_strtolower( $term, 'UTF-8' );
        }

        return strtolower( $term );
    }

    /**
     * Reduce a source to one of the known values.
     *
     * The column is a short varchar with a fixed vocabulary, so an unknown
     * value is coerced rather than stored: an analytics report that groups by
     * source must not grow a column because a caller invented one.
     *
     * @since 1.0.0
     *
     * @param mixed $source The requested source.
     *
     * @return string A known source.
     */
    public static function sanitize_source( $source ) {
        $source = is_scalar( $source ) ? strtolower( trim( (string) $source ) ) : '';

        return in_array( $source, self::SOURCES, true ) ? $source : self::DEFAULT_SOURCE;
    }
}
