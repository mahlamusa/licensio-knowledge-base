<?php
/**
 * The LIKE fallback search driver.
 *
 * Used when the host could not build the `ft_all` FULLTEXT index, or when a
 * site forces the fallback through the `licensio_supports_fulltext` filter.
 * Selects candidate rows with a disjunction of `LIKE` comparisons and then
 * ranks them in PHP with Licensio_KB_Search_Driver::score() — the same
 * arithmetic the FULLTEXT driver expresses in SQL, so the ordering does not
 * change when a site falls back.
 *
 * It is slower than FULLTEXT by construction: a leading-wildcard `LIKE` cannot
 * use an index, so this is a table scan. The candidate set is therefore capped
 * before ranking, and the cap is filterable for sites that would rather spend
 * the time.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Queries the search index with LIKE and ranks the result in PHP.
 *
 * @since 1.0.0
 */
class Licensio_KB_Search_Like_Driver extends Licensio_KB_Search_Driver {

    /**
     * How many candidate rows are ranked before the page is cut.
     *
     * @since 1.0.0
     * @var int
     */
    const CANDIDATE_LIMIT = 500;

    /**
     * The driver's short name.
     *
     * @since 1.0.0
     *
     * @return string Always `like`.
     */
    public function name() {
        return 'like';
    }

    /**
     * Find the matching article IDs, best first.
     *
     * @since 1.0.0
     *
     * @param string[]             $tokens The sanitized query tokens.
     * @param array<string, mixed> $args   Optional `limit` and `offset`.
     *
     * @return array{ids: int[], total: int} The ranked IDs for this page and the total match count.
     */
    public function search( array $tokens, array $args = array() ) {
        if ( empty( $tokens ) ) {
            return self::no_results();
        }

        $paging = self::paging( $args );
        $rows   = $this->candidates( $tokens, $paging );

        if ( empty( $rows ) ) {
            return self::no_results();
        }

        $scored = array();

        foreach ( $rows as $index => $row ) {
            $scored[] = array(
                'id'    => isset( $row->article_id ) ? (int) $row->article_id : 0,
                'score' => self::score(
                    $tokens,
                    isset( $row->title ) ? (string) $row->title : '',
                    isset( $row->terms ) ? (string) $row->terms : '',
                    isset( $row->content ) ? (string) $row->content : ''
                ),

                /*
                 * The row's position in the `updated_at DESC` candidate query,
                 * used to keep the sort total. usort() is not stable before PHP
                 * 8.0, and this plugin supports 7.4, so equal scores would
                 * otherwise come back in an order that varies by PHP version.
                 */
                'order' => $index,
            );
        }

        usort( $scored, array( $this, 'compare' ) );

        $page = array_slice( $scored, $paging['offset'], $paging['limit'] );
        $ids  = array();

        foreach ( $page as $entry ) {
            if ( $entry['id'] > 0 ) {
                $ids[] = $entry['id'];
            }
        }

        return array(
            'ids'   => $ids,
            'total' => $this->count( $tokens ),
        );
    }

    /**
     * Order two scored rows, best first.
     *
     * @since 1.0.0
     *
     * @param array{id: int, score: float, order: int} $a The first row.
     * @param array{id: int, score: float, order: int} $b The second row.
     *
     * @return int A usort() comparison result.
     */
    private function compare( $a, $b ) {
        if ( $a['score'] === $b['score'] ) {
            return $a['order'] <=> $b['order'];
        }

        return $b['score'] <=> $a['score'];
    }

    /**
     * Read the candidate rows a query could match.
     *
     * @since 1.0.0
     *
     * @param string[]                       $tokens The sanitized query tokens.
     * @param array{limit: int, offset: int} $paging The clamped paging arguments.
     *
     * @return array<int, object> The candidate rows.
     */
    private function candidates( array $tokens, array $paging ) {
        list( $where_sql, $values ) = $this->match_expression( $tokens );

        $builder = Licensio_KB_DB::table( self::TABLE );
        $builder->select( 'article_id', 'title', 'terms', 'content' );
        $builder->whereRaw( 'WHERE ( ' . $where_sql . ' )', ...$values );
        $builder->orderBy( 'updated_at', 'DESC' );
        $builder->limit( $this->candidate_limit( $paging ) );

        return array_values( (array) $builder->getAll() );
    }

    /**
     * How many candidate rows to rank.
     *
     * @since 1.0.0
     *
     * @param array{limit: int, offset: int} $paging The clamped paging arguments.
     *
     * @return int The candidate cap, never smaller than the requested page.
     */
    private function candidate_limit( array $paging ) {
        /**
         * Filter how many rows the LIKE driver ranks before cutting the page.
         *
         * Raising this improves ranking accuracy deep into a large result set at
         * the cost of a longer scan.
         *
         * @since 1.0.0
         *
         * @param int $limit The candidate cap.
         */
        $limit = (int) apply_filters( 'licensio_search_like_candidate_limit', self::CANDIDATE_LIMIT );

        return max( $paging['offset'] + $paging['limit'], max( 1, $limit ) );
    }

    /**
     * How many indexed articles match at least one token.
     *
     * @since 1.0.0
     *
     * @param string[] $tokens The sanitized query tokens.
     *
     * @return int The number of matching rows.
     */
    private function count( array $tokens ) {
        list( $where_sql, $values ) = $this->match_expression( $tokens );

        $builder = Licensio_KB_DB::table( self::TABLE );
        $builder->whereRaw( 'WHERE ( ' . $where_sql . ' )', ...$values );

        return (int) $builder->count();
    }

    /**
     * The predicate that selects a row containing every token.
     *
     * Every token must appear in at least one indexed field, which is the same
     * requirement the FULLTEXT driver expresses by prefixing each token with
     * `+`. Selecting rows that match ANY token instead would make the fallback
     * return a visibly different — and much larger — result set for every
     * multi-word query, and would make the two drivers report different totals
     * for the same search.
     *
     * @since 1.0.0
     *
     * @param string[] $tokens The sanitized query tokens.
     *
     * @return array{0: string, 1: string[]} The expression and the LIKE patterns to bind to it.
     */
    private function match_expression( array $tokens ) {
        $groups = array();
        $values = array();

        foreach ( $tokens as $token ) {
            $columns = array();

            foreach ( array( 'title', 'terms', 'content' ) as $column ) {
                $columns[] = $column . ' LIKE %s';
                $values[]  = self::like_pattern( $token );
            }

            $groups[] = '( ' . implode( ' OR ', $columns ) . ' )';
        }

        return array( implode( ' AND ', $groups ), $values );
    }
}
