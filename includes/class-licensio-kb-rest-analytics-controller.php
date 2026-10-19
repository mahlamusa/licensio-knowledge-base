<?php
/**
 * Analytics REST controller.
 *
 * Serves the four read-only reports the dashboard renders. Every route is
 * gated on the shared management permission callback, because the reports
 * describe what a site's visitors searched for and read, which is not public
 * information even in aggregate.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers and answers `/analytics/{overview|searches|articles|feedback}`.
 *
 * @since 1.0.0
 */
class Licensio_KB_REST_Analytics_Controller extends Licensio_KB_REST_Controller {

    /**
     * The report names this controller serves.
     *
     * Also used to release the matching scaffold stub routes, so the two lists
     * cannot drift apart.
     *
     * @since 1.0.0
     * @var string[]
     */
    const REPORTS = array( 'overview', 'searches', 'articles', 'feedback' );

    /**
     * The default reporting window, in days.
     *
     * @since 1.0.0
     * @var int
     */
    const DEFAULT_DAYS = 30;

    /**
     * The longest reporting window a request may ask for, in days.
     *
     * @since 1.0.0
     * @var int
     */
    const MAX_DAYS = 3650;

    /**
     * Register the four report routes.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function register_routes() {
        foreach ( self::REPORTS as $report ) {
            register_rest_route(
                $this->namespace,
                '/analytics/' . $report,
                array(
                    array(
                        'methods'             => 'GET',
                        'callback'            => array( $this, 'get_' . $report ),
                        'permission_callback' => array( $this, 'manage_permissions_check' ),
                        'args'                => $this->range_args(),
                    ),
                )
            );
        }
    }

    /**
     * The query arguments every report accepts.
     *
     * @since 1.0.0
     *
     * @return array<string, array<string, mixed>> The argument schema.
     */
    public function range_args() {
        return array(
            'start' => array(
                'description'       => __( 'Range start date, as YYYY-MM-DD.', 'licensio-knowledge-base' ),
                'type'              => 'string',
                'required'          => false,
                'validate_callback' => array( __CLASS__, 'is_valid_date' ),
            ),
            'end'   => array(
                'description'       => __( 'Range end date, as YYYY-MM-DD.', 'licensio-knowledge-base' ),
                'type'              => 'string',
                'required'          => false,
                'validate_callback' => array( __CLASS__, 'is_valid_date' ),
            ),
            'days'  => array(
                'description' => __( 'Length of the reporting window in days, counted back from today. Ignored when start and end are given.', 'licensio-knowledge-base' ),
                'type'        => 'integer',
                'required'    => false,
                'default'     => self::DEFAULT_DAYS,
                'minimum'     => 1,
                'maximum'     => self::MAX_DAYS,
            ),
            'limit' => array(
                'description' => __( 'How many rows each ranked list returns.', 'licensio-knowledge-base' ),
                'type'        => 'integer',
                'required'    => false,
                'default'     => Licensio_KB_Analytics_Repository::DEFAULT_LIMIT,
                'minimum'     => 1,
                'maximum'     => Licensio_KB_Analytics_Repository::MAX_LIMIT,
            ),
        );
    }

    /**
     * Whether a value is a `YYYY-MM-DD` date that actually exists.
     *
     * `2026-02-31` matches the pattern and is not a date, so the parts are
     * checked as well as the shape.
     *
     * @since 1.0.0
     *
     * @param mixed $value The submitted value.
     *
     * @return bool True when the value is a real calendar date.
     */
    public static function is_valid_date( $value ) {
        if ( ! is_string( $value ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
            return false;
        }

        list( $year, $month, $day ) = array_map( 'intval', explode( '-', $value ) );

        return checkdate( $month, $day, $year );
    }

    /**
     * The headline report.
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request The request.
     *
     * @return WP_REST_Response The overview payload.
     */
    public function get_overview( $request ) {
        $range = $this->resolve_range( $request );

        return rest_ensure_response(
            array(
                'range'          => $this->range_payload( $range ),
                'totals'         => Licensio_KB_Analytics_Repository::overview( $range['start'], $range['end'] ),
                'search_trend'   => Licensio_KB_Analytics_Repository::daily_totals( Licensio_KB_Analytics::TABLE_SEARCH_LOG, $range['start'], $range['end'] ),
                'view_trend'     => Licensio_KB_Analytics_Repository::daily_totals( Licensio_KB_Analytics::TABLE_ARTICLE_VIEWS, $range['start'], $range['end'] ),
                'collecting'     => $this->is_collecting(),
                'retention_days' => $this->retention_days(),
            )
        );
    }

    /**
     * The search report.
     *
     * Every zero-result row carries the URL of a pre-filled new-article screen,
     * which is the whole point of the report: a documentation gap should be one
     * click away from becoming an article.
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request The request.
     *
     * @return WP_REST_Response The searches payload.
     */
    public function get_searches( $request ) {
        $range = $this->resolve_range( $request );
        $limit = (int) $request->get_param( 'limit' );

        $zero_results = Licensio_KB_Analytics_Repository::zero_result_searches( $range['start'], $range['end'], $limit );

        foreach ( $zero_results as $index => $row ) {
            $zero_results[ $index ]['create_article_url'] = self::create_article_url( $row['term'] );
        }

        $totals = Licensio_KB_Analytics_Repository::overview( $range['start'], $range['end'] );

        return rest_ensure_response(
            array(
                'range'         => $this->range_payload( $range ),
                'top'           => Licensio_KB_Analytics_Repository::top_searches( $range['start'], $range['end'], $limit ),
                'zero_results'  => $zero_results,
                'effectiveness' => $totals['effectiveness'],
                'total'         => $totals['total_searches'],
            )
        );
    }

    /**
     * The article report.
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request The request.
     *
     * @return WP_REST_Response The articles payload.
     */
    public function get_articles( $request ) {
        $range = $this->resolve_range( $request );
        $limit = (int) $request->get_param( 'limit' );

        return rest_ensure_response(
            array(
                'range'        => $this->range_payload( $range ),
                'most_viewed'  => $this->decorate( Licensio_KB_Analytics_Repository::top_articles( $range['start'], $range['end'], $limit ) ),
                'most_useful'  => $this->decorate( Licensio_KB_Analytics_Repository::articles_by_usefulness( $range['start'], $range['end'], $limit, 'DESC' ) ),
                'least_useful' => $this->decorate( Licensio_KB_Analytics_Repository::articles_by_usefulness( $range['start'], $range['end'], $limit, 'ASC' ) ),
            )
        );
    }

    /**
     * The feedback report.
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request The request.
     *
     * @return WP_REST_Response The feedback payload.
     */
    public function get_feedback( $request ) {
        $range   = $this->resolve_range( $request );
        $summary = Licensio_KB_Analytics_Repository::feedback_summary( $range['start'], $range['end'] );

        return rest_ensure_response(
            array(
                'range'     => $this->range_payload( $range ),
                'totals'    => $summary['totals'],
                'by_status' => $summary['by_status'],
                'trend'     => Licensio_KB_Analytics_Repository::daily_totals( Licensio_KB_Analytics::TABLE_FEEDBACK, $range['start'], $range['end'] ),
            )
        );
    }

    /**
     * The admin URL of a new article pre-filled with a search term.
     *
     * @since 1.0.0
     *
     * @param string $term The search term the article should answer.
     *
     * @return string The absolute admin URL.
     */
    public static function create_article_url( $term ) {
        return add_query_arg(
            array(
                'post_type' => Licensio_KB_Post_Types::POST_TYPE,
                Licensio_KB_Analytics_Admin::PREFILL_QUERY_ARG => rawurlencode( (string) $term ),
            ),
            admin_url( 'post-new.php' )
        );
    }

    /**
     * Resolve the requested reporting window.
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request The request.
     *
     * @return array{start: string, end: string, start_date: string, end_date: string} The resolved range.
     */
    private function resolve_range( $request ) {
        $start = (string) $request->get_param( 'start' );
        $end   = (string) $request->get_param( 'end' );

        if ( '' === $end ) {
            $end = current_time( 'Y-m-d' );
        }

        if ( '' === $start ) {
            $days  = max( 1, (int) $request->get_param( 'days' ) );
            $start = gmdate( 'Y-m-d', (int) strtotime( $end . ' 00:00:00' ) - ( ( $days - 1 ) * DAY_IN_SECONDS ) );
        }

        // A range submitted backwards is answered as the range it describes.
        if ( $start > $end ) {
            $swap  = $start;
            $start = $end;
            $end   = $swap;
        }

        return array(
            'start_date' => $start,
            'end_date'   => $end,
            'start'      => $start . ' 00:00:00',
            'end'        => $end . ' 23:59:59',
        );
    }

    /**
     * The range as it is reported back to the client.
     *
     * @since 1.0.0
     *
     * @param array{start: string, end: string, start_date: string, end_date: string} $range The resolved range.
     *
     * @return array{start: string, end: string} The range dates.
     */
    private function range_payload( array $range ) {
        return array(
            'start' => $range['start_date'],
            'end'   => $range['end_date'],
        );
    }

    /**
     * Add the article title and links to a set of report rows.
     *
     * Done in PHP rather than with a JOIN: the analytics tables are written on
     * every page view and every search, and joining them to `wp_posts` on read
     * is how a reporting query starts costing more than the thing it measures.
     *
     * @since 1.0.0
     *
     * @param array<int, array<string, mixed>> $rows Report rows carrying an `article_id`.
     *
     * @return array<int, array<string, mixed>> The rows, with `title`, `permalink` and `edit_link` added.
     */
    private function decorate( array $rows ) {
        foreach ( $rows as $index => $row ) {
            $article_id = isset( $row['article_id'] ) ? (int) $row['article_id'] : 0;
            $title      = $article_id > 0 ? get_the_title( $article_id ) : '';
            $permalink  = $article_id > 0 ? get_permalink( $article_id ) : '';
            $edit_link  = $article_id > 0 ? get_edit_post_link( $article_id, 'raw' ) : '';

            $rows[ $index ]['title'] = ( is_string( $title ) && '' !== $title )
                ? $title
                /* translators: %d: article ID. */
                : sprintf( __( 'Article #%d', 'licensio-knowledge-base' ), $article_id );

            $rows[ $index ]['permalink'] = is_string( $permalink ) ? $permalink : '';
            $rows[ $index ]['edit_link'] = is_string( $edit_link ) ? $edit_link : '';
        }

        return $rows;
    }

    /**
     * Whether collection is currently switched on.
     *
     * Reported to the dashboard so an empty report can say why it is empty.
     *
     * @since 1.0.0
     *
     * @return bool True when rows are being recorded.
     */
    private function is_collecting() {
        $settings  = new Licensio_KB_Settings();
        $analytics = new Licensio_KB_Analytics( $settings, new Licensio_KB_Visitor() );

        return $analytics->is_enabled();
    }

    /**
     * The configured retention period, in days.
     *
     * @since 1.0.0
     *
     * @return int The retention period.
     */
    private function retention_days() {
        $retention = new Licensio_KB_Analytics_Retention( new Licensio_KB_Settings() );

        return $retention->retention_days();
    }
}
