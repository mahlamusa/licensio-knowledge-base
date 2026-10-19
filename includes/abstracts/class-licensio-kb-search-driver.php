<?php
/**
 * Search driver contract.
 *
 * One interface, two implementations: a FULLTEXT driver that lets MySQL select
 * and pre-rank candidates through the `ft_all` index, and a LIKE driver for
 * hosts where that index could not be built. Both answer the same question —
 * "which article IDs match these tokens, best first" — and both rank with the
 * SAME weighting, so a site that falls back to LIKE gets the same ordering, not
 * merely some ordering.
 *
 * The weighting is title x3, tags x2, content x1 over the number of query
 * tokens each field contains. It is expressed once here, in PHP, and mirrored
 * in SQL by the FULLTEXT driver from the same weight values, so the two cannot
 * drift apart without a test noticing.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shared behaviour for the knowledge base search drivers.
 *
 * @since 1.0.0
 */
abstract class Licensio_KB_Search_Driver {

    /**
     * The table every driver reads from.
     *
     * @since 1.0.0
     * @var string
     */
    const TABLE = 'licensio_search_index';

    /**
     * Relevance weight applied to a token found in the article title.
     *
     * @since 1.0.0
     * @var int
     */
    const WEIGHT_TITLE = 3;

    /**
     * Relevance weight applied to a token found in the article's terms.
     *
     * @since 1.0.0
     * @var int
     */
    const WEIGHT_TERMS = 2;

    /**
     * Relevance weight applied to a token found in the article body.
     *
     * @since 1.0.0
     * @var int
     */
    const WEIGHT_CONTENT = 1;

    /**
     * The driver's short name, used in logs and in the REST response.
     *
     * @since 1.0.0
     *
     * @return string Either `fulltext` or `like`.
     */
    abstract public function name();

    /**
     * Find the article IDs matching a set of query tokens, best first.
     *
     * @since 1.0.0
     *
     * @param string[]             $tokens The sanitized query tokens.
     * @param array<string, mixed> $args   Optional `limit` and `offset`.
     *
     * @return array{ids: int[], total: int} The ranked IDs for this page and the total match count.
     */
    abstract public function search( array $tokens, array $args = array() );

    /**
     * The relevance weight of each indexed field.
     *
     * Read through one filter by both drivers so a site that re-weights search
     * re-weights both paths at once.
     *
     * @since 1.0.0
     *
     * @return array{title: int, terms: int, content: int} The per-field weights.
     */
    public static function weights() {
        $weights = array(
            'title'   => self::WEIGHT_TITLE,
            'terms'   => self::WEIGHT_TERMS,
            'content' => self::WEIGHT_CONTENT,
        );

        /**
         * Filter the relevance weight of each indexed search field.
         *
         * Applies to both the FULLTEXT and the LIKE driver.
         *
         * @since 1.0.0
         *
         * @param array{title: int, terms: int, content: int} $weights The per-field weights.
         */
        $filtered = apply_filters( 'licensio_search_weights', $weights );

        foreach ( array_keys( $weights ) as $field ) {
            if ( isset( $filtered[ $field ] ) && is_numeric( $filtered[ $field ] ) ) {
                $weights[ $field ] = (int) $filtered[ $field ];
            }
        }

        return $weights;
    }

    /**
     * Score one indexed row against the query tokens.
     *
     * The reference implementation of the ranking. The LIKE driver calls it
     * directly; the FULLTEXT driver expresses the identical arithmetic in SQL so
     * MySQL can order without shipping every row back to PHP.
     *
     * @since 1.0.0
     *
     * @param string[] $tokens  The sanitized query tokens.
     * @param string   $title   The indexed title.
     * @param string   $terms   The indexed term names.
     * @param string   $content The indexed body text.
     *
     * @return float The relevance score. Zero when no token matched any field.
     */
    public static function score( array $tokens, $title, $terms, $content ) {
        $weights = self::weights();
        $score   = 0.0;

        foreach ( $tokens as $token ) {
            if ( self::contains( $title, $token ) ) {
                $score += $weights['title'];
            }

            if ( self::contains( $terms, $token ) ) {
                $score += $weights['terms'];
            }

            if ( self::contains( $content, $token ) ) {
                $score += $weights['content'];
            }
        }

        return $score;
    }

    /**
     * Whether a haystack contains a token, case-insensitively.
     *
     * Uses `mb_stripos()` where the mbstring extension is available so that
     * case folding is correct outside ASCII — otherwise a German or Greek query
     * would rank differently in PHP than it does in MySQL, whose `utf8mb4`
     * collations are case-insensitive across the whole repertoire.
     *
     * @since 1.0.0
     *
     * @param string $haystack The text to search.
     * @param string $needle   The token to look for.
     *
     * @return bool True when the token appears in the text.
     */
    protected static function contains( $haystack, $needle ) {
        $haystack = (string) $haystack;
        $needle   = (string) $needle;

        if ( '' === $needle || '' === $haystack ) {
            return false;
        }

        if ( function_exists( 'mb_stripos' ) ) {
            return false !== mb_stripos( $haystack, $needle );
        }

        return false !== stripos( $haystack, $needle );
    }

    /**
     * Escape the LIKE wildcards in a token.
     *
     * `$wpdb->esc_like()` in everything but name. It is reimplemented rather
     * than called because feature code in this plugin never touches `$wpdb`
     * directly, and the database gateway exposes the query builder rather than
     * the connection.
     *
     * @since 1.0.0
     *
     * @param string $token The token to escape.
     *
     * @return string The token with `%`, `_` and `\` escaped.
     */
    protected static function esc_like( $token ) {
        return addcslashes( (string) $token, '_%\\' );
    }

    /**
     * The LIKE pattern that matches a token anywhere in a field.
     *
     * @since 1.0.0
     *
     * @param string $token The token to match.
     *
     * @return string The `%token%` pattern, with wildcards inside the token escaped.
     */
    protected static function like_pattern( $token ) {
        return '%' . self::esc_like( $token ) . '%';
    }

    /**
     * Normalise the `limit` and `offset` arguments.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $args The raw arguments.
     *
     * @return array{limit: int, offset: int} The clamped paging arguments.
     */
    protected static function paging( array $args ) {
        $limit  = isset( $args['limit'] ) ? (int) $args['limit'] : 10;
        $offset = isset( $args['offset'] ) ? (int) $args['offset'] : 0;

        return array(
            'limit'  => max( 1, min( 100, $limit ) ),
            'offset' => max( 0, $offset ),
        );
    }

    /**
     * An empty result set.
     *
     * @since 1.0.0
     *
     * @return array{ids: int[], total: int} No matches.
     */
    protected static function no_results() {
        return array(
            'ids'   => array(),
            'total' => 0,
        );
    }
}
