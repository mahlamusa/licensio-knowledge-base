<?php
/**
 * The FULLTEXT search driver.
 *
 * Uses `MATCH ... AGAINST` in boolean mode against the `ft_all` index the
 * installer puts on `licensio_search_index`, so MySQL selects the candidate
 * rows from the index rather than scanning the table. The final token is
 * prefix-expanded (`term*`) so a type-ahead matches while the visitor is still
 * typing the word.
 *
 * Ranking note. MySQL will only accept a `MATCH` whose column list exactly
 * matches an existing FULLTEXT index, and the schema owns exactly one such
 * index — `ft_all (title, content, terms)`. There is therefore no way to ask
 * the server for a per-column relevance without adding per-column indexes,
 * which is the installer's schema to change, not this driver's. The per-field
 * weighting is instead computed in SQL from the same arithmetic
 * Licensio_KB_Search_Driver::score() applies in PHP, over the candidate rows
 * FULLTEXT has already narrowed to. The FULLTEXT relevance is kept as the
 * tie-breaker, so the index still does the selecting and the ordering within a
 * weight band, and the LIKE fallback produces the identical ordering.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Queries the search index through MySQL's FULLTEXT index.
 *
 * @since 1.0.0
 */
class Licensio_KB_Search_Fulltext_Driver extends Licensio_KB_Search_Driver {

    /**
     * The driver's short name.
     *
     * @since 1.0.0
     *
     * @return string Always `fulltext`.
     */
    public function name() {
        return 'fulltext';
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
        $boolean = Licensio_KB_Search_Query::boolean_query( $tokens );

        if ( '' === $boolean ) {
            return self::no_results();
        }

        $paging = self::paging( $args );
        $match  = 'MATCH(title, content, terms) AGAINST (%s IN BOOLEAN MODE)';

        list( $score_sql, $score_values ) = $this->score_expression( $tokens );

        $select_values   = $score_values;
        $select_values[] = $boolean;

        $builder = Licensio_KB_DB::table( self::TABLE );
        $builder->selectRaw(
            'SELECT article_id, ( ' . $score_sql . ' ) AS field_score, ' . $match . ' AS ft_score',
            ...$select_values
        );

        /*
         * The `WHERE` keyword is part of the raw string on purpose. The query
         * builder omits its own keyword when the first WHERE clause is raw SQL,
         * on the assumption that the caller wrote it.
         */
        $builder->whereRaw( 'WHERE ' . $match, $boolean );
        $builder->orderBy( 'field_score', 'DESC' );
        $builder->orderBy( 'ft_score', 'DESC' );
        $builder->orderBy( 'updated_at', 'DESC' );
        $builder->limit( $paging['limit'] );
        $builder->offset( $paging['offset'] );

        $rows = $builder->getAll();
        $ids  = array();

        foreach ( (array) $rows as $row ) {
            if ( isset( $row->article_id ) ) {
                $ids[] = (int) $row->article_id;
            }
        }

        return array(
            'ids'   => $ids,
            'total' => $this->count( $boolean ),
        );
    }

    /**
     * How many indexed articles match the boolean query.
     *
     * Runs against the same FULLTEXT predicate as the page query, so the total
     * is exact rather than an estimate.
     *
     * @since 1.0.0
     *
     * @param string $boolean The boolean-mode query string.
     *
     * @return int The number of matching rows.
     */
    private function count( $boolean ) {
        $builder = Licensio_KB_DB::table( self::TABLE );
        $builder->whereRaw( 'WHERE MATCH(title, content, terms) AGAINST (%s IN BOOLEAN MODE)', $boolean );

        return (int) $builder->count();
    }

    /**
     * The SQL expression that reproduces score() inside the database.
     *
     * Each `field LIKE %s` yields 1 or 0 in MySQL, so summing them multiplied by
     * the field weight is exactly the arithmetic score() performs in PHP. The
     * comparison is case-insensitive on both sides: MySQL because the column
     * collation is `_ci`, PHP because score() folds case explicitly.
     *
     * @since 1.0.0
     *
     * @param string[] $tokens The sanitized query tokens.
     *
     * @return array{0: string, 1: string[]} The expression and the LIKE patterns to bind to it.
     */
    private function score_expression( array $tokens ) {
        $weights     = self::weights();
        $expressions = array();
        $values      = array();

        /*
         * The column list is fixed here rather than taken from the weight keys.
         * Those keys travel through the `licensio_search_weights` filter and land
         * in raw SQL where no placeholder can protect them, and an unrecognised
         * key mapped to a default column would quietly double one field's weight.
         */
        foreach ( array( 'title', 'terms', 'content' ) as $column ) {
            foreach ( $tokens as $token ) {
                $expressions[] = '( ' . $column . ' LIKE %s ) * ' . (int) $weights[ $column ];
                $values[]      = self::like_pattern( $token );
            }
        }

        if ( empty( $expressions ) ) {
            return array( '0', array() );
        }

        return array( implode( ' + ', $expressions ), $values );
    }
}
