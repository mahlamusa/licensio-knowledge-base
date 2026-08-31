<?php
/**
 * Analytics aggregation queries.
 *
 * Every read the reporting dashboard performs. All of them are grouped
 * aggregates over one table, filtered on a `created_at` range so they ride the
 * covering indexes the installer declares — `results_created` on the search log
 * and `article_created` on the article views.
 *
 * No query in this class selects a visitor hash into a report. The hash exists
 * to count distinct people, never to identify one, so it appears only inside
 * `COUNT(DISTINCT ...)`.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads the aggregate reports out of the analytics tables.
 *
 * @since 1.0.0
 */
class Lisensio_KB_Analytics_Repository {

    /**
     * The largest number of rows any report will return.
     *
     * @since 1.0.0
     * @var int
     */
    const MAX_LIMIT = 100;

    /**
     * The default number of rows a top-N report returns.
     *
     * @since 1.0.0
     * @var int
     */
    const DEFAULT_LIMIT = 20;

    /**
     * The search log table, without the WordPress prefix.
     *
     * @since 1.0.0
     * @var string
     */
    const TABLE_SEARCH_LOG = Lisensio_KB_Analytics::TABLE_SEARCH_LOG;

    /**
     * The article views table, without the WordPress prefix.
     *
     * @since 1.0.0
     * @var string
     */
    const TABLE_ARTICLE_VIEWS = Lisensio_KB_Analytics::TABLE_ARTICLE_VIEWS;

    /**
     * The feedback table, without the WordPress prefix.
     *
     * @since 1.0.0
     * @var string
     */
    const TABLE_FEEDBACK = Lisensio_KB_Analytics::TABLE_FEEDBACK;

    /**
     * Headline totals for a date range.
     *
     * @since 1.0.0
     *
     * @param string $start Range start, `Y-m-d H:i:s`, inclusive.
     * @param string $end   Range end, `Y-m-d H:i:s`, inclusive.
     *
     * @return array<string, int|float> The totals, with `effectiveness` as a percentage.
     */
    public static function overview( $start, $end ) {
        $searches = self::row(
            self::TABLE_SEARCH_LOG,
            'SELECT COUNT(*) AS total,'
                . ' COUNT(DISTINCT normalized_term) AS unique_terms,'
                . ' COUNT(DISTINCT visitor_hash) AS visitors,'
                . ' SUM(CASE WHEN results_count = 0 THEN 1 ELSE 0 END) AS zero_results',
            $start,
            $end
        );

        $views = self::row(
            self::TABLE_ARTICLE_VIEWS,
            'SELECT COUNT(*) AS total,'
                . ' COUNT(DISTINCT article_id) AS articles,'
                . ' COUNT(DISTINCT visitor_hash) AS visitors',
            $start,
            $end
        );

        $feedback = self::row(
            self::TABLE_FEEDBACK,
            'SELECT COUNT(*) AS total,'
                . ' SUM(CASE WHEN vote > 0 THEN 1 ELSE 0 END) AS positive,'
                . ' SUM(CASE WHEN vote < 0 THEN 1 ELSE 0 END) AS negative',
            $start,
            $end
        );

        $total_searches = self::int_field( $searches, 'total' );
        $zero_results   = self::int_field( $searches, 'zero_results' );

        return array(
            'total_searches'       => $total_searches,
            'unique_terms'         => self::int_field( $searches, 'unique_terms' ),
            'search_visitors'      => self::int_field( $searches, 'visitors' ),
            'zero_result_searches' => $zero_results,
            'effectiveness'        => self::effectiveness_percentage( $total_searches, $zero_results ),
            'total_views'          => self::int_field( $views, 'total' ),
            'viewed_articles'      => self::int_field( $views, 'articles' ),
            'view_visitors'        => self::int_field( $views, 'visitors' ),
            'total_feedback'       => self::int_field( $feedback, 'total' ),
            'positive_feedback'    => self::int_field( $feedback, 'positive' ),
            'negative_feedback'    => self::int_field( $feedback, 'negative' ),
        );
    }

    /**
     * The share of searches that returned at least one article, as a percentage.
     *
     * Rounded to one decimal place. A range with no searches at all scores 0.0
     * rather than 100.0: "every search succeeded" is a claim about searches that
     * happened, and none did.
     *
     * @since 1.0.0
     *
     * @param int $total_searches How many searches were performed.
     * @param int $zero_results   How many of them returned nothing.
     *
     * @return float The effectiveness percentage, 0.0 to 100.0.
     */
    public static function effectiveness_percentage( $total_searches, $zero_results ) {
        $total_searches = max( 0, (int) $total_searches );
        $zero_results   = min( max( 0, (int) $zero_results ), $total_searches );

        if ( 0 === $total_searches ) {
            return 0.0;
        }

        return round( ( ( $total_searches - $zero_results ) / $total_searches ) * 100, 1 );
    }

    /**
     * The most frequent search terms in a range.
     *
     * Grouped on `normalized_term` so casing and spacing variants of one
     * question are one row. `term` carries a representative raw spelling so the
     * report shows what somebody actually typed.
     *
     * @since 1.0.0
     *
     * @param string $start Range start, `Y-m-d H:i:s`, inclusive.
     * @param string $end   Range end, `Y-m-d H:i:s`, inclusive.
     * @param int    $limit How many rows to return.
     *
     * @return array<int, array<string, mixed>> The rows, most searched first.
     */
    public static function top_searches( $start, $end, $limit = self::DEFAULT_LIMIT ) {
        $rows = self::rows(
            self::TABLE_SEARCH_LOG,
            'SELECT normalized_term, MAX(term) AS term, COUNT(*) AS searches,'
                . ' SUM(CASE WHEN results_count = 0 THEN 1 ELSE 0 END) AS zero_results,'
                . ' AVG(results_count) AS average_results',
            $start,
            $end,
            'normalized_term',
            'searches',
            $limit
        );

        $out = array();

        foreach ( $rows as $row ) {
            $out[] = array(
                'term'            => (string) self::field( $row, 'term' ),
                'normalized_term' => (string) self::field( $row, 'normalized_term' ),
                'searches'        => self::int_field( $row, 'searches' ),
                'zero_results'    => self::int_field( $row, 'zero_results' ),
                'average_results' => round( (float) self::field( $row, 'average_results' ), 1 ),
            );
        }

        return $out;
    }

    /**
     * The most frequent searches that returned nothing.
     *
     * This is the documentation-gap list: every row is a question somebody
     * asked that the knowledge base could not answer.
     *
     * @since 1.0.0
     *
     * @param string $start Range start, `Y-m-d H:i:s`, inclusive.
     * @param string $end   Range end, `Y-m-d H:i:s`, inclusive.
     * @param int    $limit How many rows to return.
     *
     * @return array<int, array<string, mixed>> The rows, most searched first.
     */
    public static function zero_result_searches( $start, $end, $limit = self::DEFAULT_LIMIT ) {
        $rows = self::rows(
            self::TABLE_SEARCH_LOG,
            'SELECT normalized_term, MAX(term) AS term, COUNT(*) AS searches',
            $start,
            $end,
            'normalized_term',
            'searches',
            $limit,
            array( 'results_count', 0, '=' )
        );

        $out = array();

        foreach ( $rows as $row ) {
            $out[] = array(
                'term'            => (string) self::field( $row, 'term' ),
                'normalized_term' => (string) self::field( $row, 'normalized_term' ),
                'searches'        => self::int_field( $row, 'searches' ),
            );
        }

        return $out;
    }

    /**
     * The most viewed articles in a range.
     *
     * @since 1.0.0
     *
     * @param string $start Range start, `Y-m-d H:i:s`, inclusive.
     * @param string $end   Range end, `Y-m-d H:i:s`, inclusive.
     * @param int    $limit How many rows to return.
     *
     * @return array<int, array<string, int>> Rows of `article_id`, `views` and `visitors`.
     */
    public static function top_articles( $start, $end, $limit = self::DEFAULT_LIMIT ) {
        $rows = self::rows(
            self::TABLE_ARTICLE_VIEWS,
            'SELECT article_id, COUNT(*) AS views, COUNT(DISTINCT visitor_hash) AS visitors',
            $start,
            $end,
            'article_id',
            'views',
            $limit
        );

        $out = array();

        foreach ( $rows as $row ) {
            $out[] = array(
                'article_id' => self::int_field( $row, 'article_id' ),
                'views'      => self::int_field( $row, 'views' ),
                'visitors'   => self::int_field( $row, 'visitors' ),
            );
        }

        return $out;
    }

    /**
     * Articles ranked by the share of positive feedback they received.
     *
     * Ties break on the number of votes and then on the article ID, so the
     * order is deterministic rather than whatever the storage engine returns.
     *
     * @since 1.0.0
     *
     * @param string $start     Range start, `Y-m-d H:i:s`, inclusive.
     * @param string $end       Range end, `Y-m-d H:i:s`, inclusive.
     * @param int    $limit     How many rows to return.
     * @param string $direction `DESC` for the most useful, `ASC` for the least useful.
     *
     * @return array<int, array<string, int|float>> Rows of `article_id`, `positive`, `negative`, `votes` and `usefulness`.
     */
    public static function articles_by_usefulness( $start, $end, $limit = self::DEFAULT_LIMIT, $direction = 'DESC' ) {
        $direction = ( 'ASC' === strtoupper( (string) $direction ) ) ? 'ASC' : 'DESC';

        $rows = self::rows(
            self::TABLE_FEEDBACK,
            'SELECT article_id, COUNT(*) AS votes,'
                . ' SUM(CASE WHEN vote > 0 THEN 1 ELSE 0 END) AS positive,'
                . ' SUM(CASE WHEN vote < 0 THEN 1 ELSE 0 END) AS negative,'
                . ' ROUND( ( SUM(CASE WHEN vote > 0 THEN 1 ELSE 0 END) / COUNT(*) ) * 100, 1 ) AS usefulness',
            $start,
            $end,
            'article_id',
            'usefulness',
            $limit,
            null,
            $direction
        );

        $out = array();

        foreach ( $rows as $row ) {
            $out[] = array(
                'article_id' => self::int_field( $row, 'article_id' ),
                'votes'      => self::int_field( $row, 'votes' ),
                'positive'   => self::int_field( $row, 'positive' ),
                'negative'   => self::int_field( $row, 'negative' ),
                'usefulness' => round( (float) self::field( $row, 'usefulness' ), 1 ),
            );
        }

        return $out;
    }

    /**
     * Feedback totals by vote and by triage status.
     *
     * @since 1.0.0
     *
     * @param string $start Range start, `Y-m-d H:i:s`, inclusive.
     * @param string $end   Range end, `Y-m-d H:i:s`, inclusive.
     *
     * @return array{totals: array<string, int|float>, by_status: array<string, int>} The feedback summary.
     */
    public static function feedback_summary( $start, $end ) {
        $totals = self::row(
            self::TABLE_FEEDBACK,
            'SELECT COUNT(*) AS total,'
                . ' SUM(CASE WHEN vote > 0 THEN 1 ELSE 0 END) AS positive,'
                . ' SUM(CASE WHEN vote < 0 THEN 1 ELSE 0 END) AS negative,'
                . ' COUNT(DISTINCT article_id) AS articles',
            $start,
            $end
        );

        $total    = self::int_field( $totals, 'total' );
        $positive = self::int_field( $totals, 'positive' );

        $status_rows = self::rows(
            self::TABLE_FEEDBACK,
            'SELECT status, COUNT(*) AS total',
            $start,
            $end,
            'status',
            'total',
            self::MAX_LIMIT
        );

        $by_status = array();

        foreach ( $status_rows as $row ) {
            $by_status[ (string) self::field( $row, 'status' ) ] = self::int_field( $row, 'total' );
        }

        return array(
            'totals'    => array(
                'total'      => $total,
                'positive'   => $positive,
                'negative'   => self::int_field( $totals, 'negative' ),
                'articles'   => self::int_field( $totals, 'articles' ),
                'usefulness' => 0 === $total ? 0.0 : round( ( $positive / $total ) * 100, 1 ),
            ),
            'by_status' => $by_status,
        );
    }

    /**
     * Daily row counts for a table, for the dashboard's trend chart.
     *
     * @since 1.0.0
     *
     * @param string $table The unprefixed table name.
     * @param string $start Range start, `Y-m-d H:i:s`, inclusive.
     * @param string $end   Range end, `Y-m-d H:i:s`, inclusive.
     *
     * @return array<int, array{date: string, total: int}> One entry per day that has rows, oldest first.
     */
    public static function daily_totals( $table, $start, $end ) {
        $rows = self::query(
            $table,
            function ( $builder ) {
                return $builder
                    ->selectRaw( 'SELECT DATE(created_at) AS bucket, COUNT(*) AS total' )
                    ->groupBy( 'DATE(created_at)' )
                    ->orderBy( 'bucket', 'ASC' );
            },
            $start,
            $end
        );

        $out = array();

        foreach ( $rows as $row ) {
            $out[] = array(
                'date'  => (string) self::field( $row, 'bucket' ),
                'total' => self::int_field( $row, 'total' ),
            );
        }

        return $out;
    }

    /**
     * Lifetime view counts for a set of articles.
     *
     * Deliberately not date-bounded: this backs the article list table's Views
     * column, which reports how many times an article has ever been read.
     *
     * @since 1.0.0
     *
     * @param int[] $article_ids The articles to count.
     *
     * @return array<int, int> Article ID to view count. Articles with no views map to 0.
     */
    public static function view_counts( array $article_ids ) {
        $ids = array();

        foreach ( $article_ids as $id ) {
            $id = (int) $id;

            if ( $id > 0 ) {
                $ids[ $id ] = 0;
            }
        }

        if ( empty( $ids ) ) {
            return array();
        }

        try {
            $rows = (array) Lisensio_KB_DB::table( self::TABLE_ARTICLE_VIEWS )
                ->selectRaw( 'SELECT article_id, COUNT(*) AS views' )
                ->whereIn( 'article_id', array_keys( $ids ) )
                ->groupBy( 'article_id' )
                ->getAll( ARRAY_A );
        } catch ( Exception $e ) {
            unset( $e );

            return $ids;
        }

        foreach ( $rows as $row ) {
            $article_id = self::int_field( $row, 'article_id' );

            if ( isset( $ids[ $article_id ] ) ) {
                $ids[ $article_id ] = self::int_field( $row, 'views' );
            }
        }

        return $ids;
    }

    /**
     * Run a grouped top-N aggregate over one table.
     *
     * @since 1.0.0
     *
     * @param string                                     $table      The unprefixed table name.
     * @param string                                     $select     The full SELECT clause, keyword included.
     * @param string                                     $start      Range start, `Y-m-d H:i:s`, inclusive.
     * @param string                                     $end        Range end, `Y-m-d H:i:s`, inclusive.
     * @param string                                     $group_by   The column to group on.
     * @param string                                     $order_by   The select alias to order on.
     * @param int                                        $limit      How many rows to return.
     * @param array{0: string, 1: mixed, 2: string}|null $condition Optional extra WHERE triple.
     * @param string                                     $direction  `DESC` or `ASC`.
     *
     * @return array<int, array<string, mixed>> The result rows.
     */
    private static function rows( $table, $select, $start, $end, $group_by, $order_by, $limit, $condition = null, $direction = 'DESC' ) {
        $limit     = max( 1, min( self::MAX_LIMIT, (int) $limit ) );
        $direction = ( 'ASC' === strtoupper( (string) $direction ) ) ? 'ASC' : 'DESC';

        return self::query(
            $table,
            function ( $builder ) use ( $select, $group_by, $order_by, $limit, $condition, $direction ) {
                $builder->selectRaw( $select );

                if ( is_array( $condition ) ) {
                    $builder->where( $condition[0], $condition[1], $condition[2] );
                }

                return $builder
                    ->groupBy( $group_by )
                    ->orderBy( $order_by, $direction )
                    ->orderBy( $group_by, 'ASC' )
                    ->limit( $limit );
            },
            $start,
            $end
        );
    }

    /**
     * Run a single-row aggregate over one table.
     *
     * @since 1.0.0
     *
     * @param string $table  The unprefixed table name.
     * @param string $select The full SELECT clause, keyword included.
     * @param string $start  Range start, `Y-m-d H:i:s`, inclusive.
     * @param string $end    Range end, `Y-m-d H:i:s`, inclusive.
     *
     * @return array<string, mixed> The single result row, or an empty array.
     */
    private static function row( $table, $select, $start, $end ) {
        $rows = self::query(
            $table,
            function ( $builder ) use ( $select ) {
                return $builder->selectRaw( $select );
            },
            $start,
            $end
        );

        return isset( $rows[0] ) && is_array( $rows[0] ) ? $rows[0] : array();
    }

    /**
     * Build and run a date-bounded query through the database gateway.
     *
     * A missing database library returns an empty result set rather than
     * throwing: the dashboard is a report, and a broken install should show an
     * empty report inside a working admin rather than a white screen.
     *
     * @since 1.0.0
     *
     * @param string   $table   The unprefixed table name.
     * @param callable $shape   Receives the builder and returns it, shaped.
     * @param string   $start   Range start, `Y-m-d H:i:s`, inclusive.
     * @param string   $end     Range end, `Y-m-d H:i:s`, inclusive.
     *
     * @return array<int, array<string, mixed>> The result rows.
     */
    private static function query( $table, callable $shape, $start, $end ) {
        try {
            $builder = Lisensio_KB_DB::table( $table )
                ->where( 'created_at', (string) $start, '>=' )
                ->where( 'created_at', (string) $end, '<=' );

            return (array) call_user_func( $shape, $builder )->getAll( ARRAY_A );
        } catch ( Exception $e ) {
            unset( $e );

            return array();
        }
    }

    /**
     * Read a column out of a result row, whether it is an array or an object.
     *
     * @since 1.0.0
     *
     * @param mixed  $row    The result row.
     * @param string $column The column name.
     *
     * @return mixed The value, or null when the column is absent.
     */
    private static function field( $row, $column ) {
        if ( is_array( $row ) && array_key_exists( $column, $row ) ) {
            return $row[ $column ];
        }

        if ( is_object( $row ) && isset( $row->{$column} ) ) {
            return $row->{$column};
        }

        return null;
    }

    /**
     * Read a column out of a result row as an integer.
     *
     * @since 1.0.0
     *
     * @param mixed  $row    The result row.
     * @param string $column The column name.
     *
     * @return int The value, or 0 when the column is absent or null.
     */
    private static function int_field( $row, $column ) {
        return (int) self::field( $row, $column );
    }
}
