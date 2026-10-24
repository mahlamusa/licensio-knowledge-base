<?php
/**
 * Windowed views and ratings for the article list.
 *
 * The Articles app shows, sorts and filters articles by how many views and
 * which votes they had in the last 7, 30 or 90 days. Those figures live in the
 * plugin's own `licensio_article_views` and `licensio_feedback` tables, which
 * WP_Query knows nothing about, so this class supplies the SQL:
 *
 * - `clauses()` adds derived-table LEFT JOINs to the article list query
 *   through `posts_clauses`, for sorting by views or rating and for the Needs
 *   attention filter. Each derived table is grouped by article, so it joins at
 *   most one row per post and never multiplies the result.
 * - `for_articles()` reads the figures for one page of IDs, in one grouped
 *   query per table.
 * - `attachment_counts()` counts the child attachments of one page of IDs in
 *   one grouped query.
 *
 * Views are written in site time (`current_time( 'mysql' )`) and votes in UTC
 * (`current_time( 'mysql', true )`), so each table's window starts at the
 * matching clock.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds the article list's metrics SQL.
 *
 * @since 1.0.0
 */
class Licensio_KB_Article_Metrics {

    /**
     * The windows the list offers, in days.
     *
     * @since 1.0.0
     * @var int[]
     */
    const WINDOWS = array( 7, 30, 90 );

    /**
     * The default window, in days.
     *
     * @since 1.0.0
     * @var int
     */
    const DEFAULT_WINDOW = 30;

    /**
     * The query var that asks `clauses()` to add the metrics joins.
     *
     * Its value is the spec array `clauses()` reads. A query without it is
     * left alone, so the filter is safe to leave attached.
     *
     * @since 1.0.0
     * @var string
     */
    const QUERY_VAR = 'licensio_kb_metrics';

    /**
     * The helpful share below which an article needs attention, in percent.
     *
     * @since 1.0.0
     * @var int
     */
    private $threshold;

    /**
     * The votes an article needs before it can need attention.
     *
     * @since 1.0.0
     * @var int
     */
    private $min_votes;

    /**
     * Build the helper.
     *
     * @since 1.0.0
     *
     * @param int $threshold The low rating threshold, 1 to 99 percent.
     * @param int $min_votes The minimum votes, at least 1.
     */
    public function __construct( $threshold = 50, $min_votes = 5 ) {
        $this->threshold = min( 99, max( 1, (int) $threshold ) );
        $this->min_votes = max( 1, (int) $min_votes );
    }

    /**
     * Build the helper from the plugin settings.
     *
     * @since 1.0.0
     *
     * @param Licensio_KB_Settings $settings The settings store.
     *
     * @return Licensio_KB_Article_Metrics The helper.
     */
    public static function from_settings( Licensio_KB_Settings $settings ) {
        return new self( (int) $settings->get( 'low_rating_threshold', 50 ), (int) $settings->get( 'low_rating_min_votes', 5 ) );
    }

    /**
     * The low rating threshold in use.
     *
     * @since 1.0.0
     *
     * @return int The threshold, in percent.
     */
    public function threshold() {
        return $this->threshold;
    }

    /**
     * The minimum votes in use.
     *
     * @since 1.0.0
     *
     * @return int The minimum number of votes.
     */
    public function min_votes() {
        return $this->min_votes;
    }

    /**
     * A window in days, held to the offered values.
     *
     * @since 1.0.0
     *
     * @param mixed $window The requested window.
     *
     * @return int 7, 30 or 90.
     */
    public static function normalize_window( $window ) {
        $window = (int) $window;

        return in_array( $window, self::WINDOWS, true ) ? $window : self::DEFAULT_WINDOW;
    }

    /**
     * When a window starts, on the clock a table is written with.
     *
     * @since 1.0.0
     *
     * @param int  $window The window, in days.
     * @param bool $gmt    True for a table written in UTC, false for site time.
     *
     * @return string A `Y-m-d H:i:s` timestamp.
     */
    public static function window_start( $window, $gmt ) {
        $now = strtotime( (string) current_time( 'mysql', $gmt ) . ' UTC' );

        return gmdate( 'Y-m-d H:i:s', (int) $now - ( self::normalize_window( $window ) * DAY_IN_SECONDS ) );
    }

    /**
     * Add the metrics joins, filter and sort to an article list query.
     *
     * Hooked to `posts_clauses`. Acts only on a query carrying the
     * `licensio_kb_metrics` query var, whose value is a spec array:
     *
     * - `window`: 7, 30 or 90.
     * - `orderby`: `views` or `rating` to sort by that figure; anything else
     *   leaves the query's own order.
     * - `order`: `ASC` or `DESC`.
     * - `needs_attention`: true to keep only articles whose helpful share is
     *   below the threshold with at least the minimum votes.
     *
     * Articles with no votes sort after every rated one in either direction,
     * and ties fall back to the post ID so paging is stable.
     *
     * @since 1.0.0
     *
     * @param array<string, string> $clauses The query clauses.
     * @param WP_Query|mixed        $query   The query being built.
     *
     * @return array<string, string> The clauses, with the metrics parts added.
     */
    public function filter_clauses( $clauses, $query ) {
        if ( ! is_object( $query ) || ! method_exists( $query, 'get' ) ) {
            return $clauses;
        }

        $spec = $query->get( self::QUERY_VAR );

        if ( ! is_array( $spec ) ) {
            return $clauses;
        }

        return $this->clauses( (array) $clauses, $spec );
    }

    /**
     * Add the metrics joins, filter and sort to a set of query clauses.
     *
     * See `filter_clauses()` for the spec.
     *
     * @since 1.0.0
     *
     * @param array<string, string> $clauses The query clauses: `join`, `where` and `orderby` are read and written.
     * @param array<string, mixed>  $spec    The metrics spec.
     *
     * @return array<string, string> The clauses, with the metrics parts added.
     */
    public function clauses( array $clauses, array $spec ) {
        global $wpdb;

        $window          = self::normalize_window( isset( $spec['window'] ) ? $spec['window'] : self::DEFAULT_WINDOW );
        $orderby         = isset( $spec['orderby'] ) ? (string) $spec['orderby'] : '';
        $order           = isset( $spec['order'] ) && 'ASC' === strtoupper( (string) $spec['order'] ) ? 'ASC' : 'DESC';
        $needs_attention = ! empty( $spec['needs_attention'] );

        foreach ( array( 'join', 'where', 'orderby' ) as $key ) {
            $clauses[ $key ] = isset( $clauses[ $key ] ) ? (string) $clauses[ $key ] : '';
        }

        if ( 'views' === $orderby ) {
            $clauses['join']   .= ' ' . $this->views_join( $window );
            $clauses['orderby'] = "COALESCE( lkb_views.views, 0 ) {$order}, {$wpdb->posts}.ID {$order}";
        }

        if ( 'rating' === $orderby || $needs_attention ) {
            $clauses['join'] .= ' ' . $this->ratings_join( $window );
        }

        if ( 'rating' === $orderby ) {
            $clauses['orderby'] = "( lkb_ratings.votes IS NULL ) ASC, ( lkb_ratings.up / lkb_ratings.votes ) {$order}, lkb_ratings.votes DESC, {$wpdb->posts}.ID {$order}";
        }

        if ( $needs_attention ) {
            $clauses['where'] .= $wpdb->prepare(
                ' AND lkb_ratings.votes >= %d AND lkb_ratings.up * 100 < %d * lkb_ratings.votes',
                $this->min_votes,
                $this->threshold
            );
        }

        return $clauses;
    }

    /**
     * The derived-table join of views in a window.
     *
     * @since 1.0.0
     *
     * @param int $window The window, in days.
     *
     * @return string The LEFT JOIN, aliased `lkb_views`.
     */
    private function views_join( $window ) {
        global $wpdb;

        return $wpdb->prepare(
            'LEFT JOIN ( SELECT article_id, COUNT(*) AS views FROM %i WHERE created_at >= %s GROUP BY article_id ) AS lkb_views ON lkb_views.article_id = %i.ID',
            $wpdb->prefix . Licensio_KB_Analytics::TABLE_ARTICLE_VIEWS,
            self::window_start( $window, false ),
            $wpdb->posts
        );
    }

    /**
     * The derived-table join of votes in a window.
     *
     * @since 1.0.0
     *
     * @param int $window The window, in days.
     *
     * @return string The LEFT JOIN, aliased `lkb_ratings`, with `up` and `votes` columns.
     */
    private function ratings_join( $window ) {
        global $wpdb;

        return $wpdb->prepare(
            'LEFT JOIN ( SELECT article_id, SUM( vote = %d ) AS up, COUNT(*) AS votes FROM %i WHERE created_at >= %s AND vote IN ( %d, %d ) GROUP BY article_id ) AS lkb_ratings ON lkb_ratings.article_id = %i.ID',
            Licensio_KB_Feedback::VOTE_UP,
            $wpdb->prefix . Licensio_KB_Feedback::TABLE,
            self::window_start( $window, true ),
            Licensio_KB_Feedback::VOTE_UP,
            Licensio_KB_Feedback::VOTE_DOWN,
            $wpdb->posts
        );
    }

    /**
     * The views and votes of some articles in a window.
     *
     * Two queries whatever the number of IDs: one grouped count per table.
     *
     * @since 1.0.0
     *
     * @param int[] $ids    The article IDs.
     * @param int   $window The window, in days.
     *
     * @return array<int, array{views: int, up: int, down: int, share: int|null}> Article ID to its figures, for every ID given.
     */
    public function for_articles( array $ids, $window ) {
        global $wpdb;

        $ids     = Licensio_KB_Article_Bulk::clean_ids( $ids );
        $figures = array();

        foreach ( $ids as $id ) {
            $figures[ $id ] = array(
                'views' => 0,
                'up'    => 0,
                'down'  => 0,
                'share' => null,
            );
        }

        if ( empty( $ids ) ) {
            return $figures;
        }

        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $placeholders is one generated %d per ID; the figures change with every view, so they are not cached.
        $views = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT article_id, COUNT(*) AS views FROM %i WHERE article_id IN ( {$placeholders} ) AND created_at >= %s GROUP BY article_id",
                array_merge( array( $wpdb->prefix . Licensio_KB_Analytics::TABLE_ARTICLE_VIEWS ), $ids, array( self::window_start( $window, false ) ) )
            )
        );

        $votes = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT article_id, SUM( vote = %d ) AS up, SUM( vote = %d ) AS down FROM %i WHERE article_id IN ( {$placeholders} ) AND created_at >= %s GROUP BY article_id",
                array_merge(
                    array( Licensio_KB_Feedback::VOTE_UP, Licensio_KB_Feedback::VOTE_DOWN, $wpdb->prefix . Licensio_KB_Feedback::TABLE ),
                    $ids,
                    array( self::window_start( $window, true ) )
                )
            )
        );
        // phpcs:enable

        foreach ( (array) $views as $row ) {
            $id = (int) $row->article_id;

            if ( isset( $figures[ $id ] ) ) {
                $figures[ $id ]['views'] = (int) $row->views;
            }
        }

        foreach ( (array) $votes as $row ) {
            $id = (int) $row->article_id;

            if ( ! isset( $figures[ $id ] ) ) {
                continue;
            }

            $up   = (int) $row->up;
            $down = (int) $row->down;

            $figures[ $id ]['up']    = $up;
            $figures[ $id ]['down']  = $down;
            $figures[ $id ]['share'] = ( $up + $down ) > 0 ? (int) round( $up * 100 / ( $up + $down ) ) : null;
        }

        return $figures;
    }

    /**
     * Whether figures put an article below the low rating threshold.
     *
     * @since 1.0.0
     *
     * @param array{up: int, down: int} $figures The article's votes in the window.
     *
     * @return bool True when the article needs attention.
     */
    public function needs_attention( array $figures ) {
        $up    = isset( $figures['up'] ) ? (int) $figures['up'] : 0;
        $votes = $up + ( isset( $figures['down'] ) ? (int) $figures['down'] : 0 );

        return $votes >= $this->min_votes && $up * 100 < $this->threshold * $votes;
    }

    /**
     * How many attachments each article has.
     *
     * One grouped query whatever the number of IDs.
     *
     * @since 1.0.0
     *
     * @param int[] $ids The article IDs.
     *
     * @return array<int, int> Article ID to its attachment count, for every ID given.
     */
    public function attachment_counts( array $ids ) {
        global $wpdb;

        $ids    = Licensio_KB_Article_Bulk::clean_ids( $ids );
        $counts = array_fill_keys( $ids, 0 );

        if ( empty( $ids ) ) {
            return $counts;
        }

        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $placeholders is one generated %d per ID.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT post_parent, COUNT(*) AS attachments FROM %i WHERE post_type = 'attachment' AND post_parent IN ( {$placeholders} ) GROUP BY post_parent",
                array_merge( array( $wpdb->posts ), $ids )
            )
        );
        // phpcs:enable

        foreach ( (array) $rows as $row ) {
            $counts[ (int) $row->post_parent ] = (int) $row->attachments;
        }

        return $counts;
    }
}
