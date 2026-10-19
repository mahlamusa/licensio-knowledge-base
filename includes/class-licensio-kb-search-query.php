<?php
/**
 * Search query facade.
 *
 * Sanitises the visitor's term, chooses a driver, and hands the ranked article
 * IDs back to the caller. Drivers return IDs and nothing else; hydration goes
 * through WP_Query with `post__in` so permalinks, thumbnails, term caches and
 * every `the_post` consumer behave exactly as they do anywhere else in
 * WordPress.
 *
 * FULLTEXT boolean operators are stripped here, before a term reaches either
 * driver. That is not defence against SQL injection — every value is bound
 * through a placeholder — but against a visitor steering the ranking or
 * emptying the result set with `-`, and against a malformed boolean expression
 * making MySQL raise a syntax error on a public endpoint.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Runs a knowledge base search and hydrates the result.
 *
 * @since 1.0.0
 */
class Licensio_KB_Search_Query {

    /**
     * The longest term accepted, matching the `term` column in the search log.
     *
     * @since 1.0.0
     * @var int
     */
    const MAX_TERM_LENGTH = 191;

    /**
     * The most tokens a single query is broken into.
     *
     * @since 1.0.0
     * @var int
     */
    const MAX_TOKENS = 10;

    /**
     * The shortest token MySQL will index by default.
     *
     * InnoDB's `innodb_ft_min_token_size` defaults to 3. A shorter token used as
     * a required `+` term would make the whole boolean query match nothing, so
     * short tokens are left out of the FULLTEXT expression. They still count
     * towards relevance, because the ranking is computed with LIKE on both
     * drivers.
     *
     * @since 1.0.0
     * @var int
     */
    const MIN_FULLTEXT_TOKEN_LENGTH = 3;

    /**
     * The FULLTEXT boolean-mode operators removed from visitor input.
     *
     * @since 1.0.0
     * @var string[]
     */
    const BOOLEAN_OPERATORS = array( '+', '-', '>', '<', '(', ')', '~', '*', '"', '@' );

    /**
     * A driver pinned by set_driver(), overriding the automatic choice.
     *
     * @since 1.0.0
     * @var Licensio_KB_Search_Driver|null
     */
    private $forced_driver = null;

    /**
     * Run a search.
     *
     * @since 1.0.0
     *
     * @param string               $raw_term The visitor's raw search term.
     * @param array<string, mixed> $args     Optional `limit` and `offset`.
     *
     * @return array{term: string, tokens: string[], ids: int[], total: int, driver: string} The result.
     */
    public function search( $raw_term, array $args = array() ) {
        $term   = self::sanitize_term( $raw_term );
        $tokens = self::tokenize( $term );

        $result = array(
            'term'   => $term,
            'tokens' => $tokens,
            'ids'    => array(),
            'total'  => 0,
            'driver' => 'none',
        );

        if ( empty( $tokens ) ) {
            return $result;
        }

        $driver           = $this->driver( $tokens );
        $found            = $driver->search( $tokens, $args );
        $result['ids']    = $found['ids'];
        $result['total']  = $found['total'];
        $result['driver'] = $driver->name();

        /**
         * Filter a completed knowledge base search result.
         *
         * @since 1.0.0
         *
         * @param array{term: string, tokens: string[], ids: int[], total: int, driver: string} $result The result.
         * @param string                                                                        $term   The sanitized term.
         * @param array<string, mixed>                                                          $args   The search arguments.
         */
        return apply_filters( 'licensio_search_results', $result, $term, $args );
    }

    /**
     * Hydrate ranked article IDs into posts, keeping the ranking.
     *
     * @since 1.0.0
     *
     * @param int[] $ids The ranked article IDs.
     *
     * @return WP_Post[] The published articles, in the order the driver returned them.
     */
    public function hydrate( array $ids ) {
        $ids = array_values( array_filter( array_map( 'intval', $ids ) ) );

        if ( empty( $ids ) ) {
            return array();
        }

        $query = new WP_Query(
            array(
                'post_type'           => Licensio_KB_Post_Types::POST_TYPE,
                'post_status'         => 'publish',
                'post__in'            => $ids,
                'orderby'             => 'post__in',
                'posts_per_page'      => count( $ids ),
                'no_found_rows'       => true,
                'ignore_sticky_posts' => true,
                'suppress_filters'    => false,
            )
        );

        return $query->posts;
    }

    /**
     * The driver that will answer this query.
     *
     * @since 1.0.0
     *
     * @param string[] $tokens The sanitized query tokens. An empty array asks
     *                         only which driver the site would use.
     *
     * @return Licensio_KB_Search_Driver The chosen driver.
     */
    public function driver( array $tokens = array() ) {
        if ( $this->forced_driver instanceof Licensio_KB_Search_Driver ) {
            return $this->forced_driver;
        }

        /*
         * A query whose every token is below the server's minimum indexed word
         * length cannot be answered by FULLTEXT at all — every token would be
         * dropped and the boolean expression would be empty — so it falls back
         * for that query rather than returning nothing.
         */
        $unindexable = ! empty( $tokens ) && '' === self::boolean_query( $tokens );

        if ( ! self::supports_fulltext() || $unindexable ) {
            return new Licensio_KB_Search_Like_Driver();
        }

        return new Licensio_KB_Search_Fulltext_Driver();
    }

    /**
     * Pin a driver, overriding the automatic choice.
     *
     * Exists so a test can drive the LIKE fallback against a server that does
     * have a FULLTEXT index. A fallback that is only ever exercised where the
     * fast path is unavailable is a fallback nobody has run.
     *
     * @since 1.0.0
     *
     * @param Licensio_KB_Search_Driver|null $driver The driver to use, or null to choose again.
     *
     * @return void
     */
    public function set_driver( $driver ) {
        $this->forced_driver = $driver instanceof Licensio_KB_Search_Driver ? $driver : null;
    }

    /**
     * Whether this site can answer searches with the FULLTEXT index.
     *
     * Reads the installer's recorded probe, then re-applies the installer's own
     * `licensio_supports_fulltext` filter so a site can force the LIKE fallback
     * at runtime without reinstalling the schema. The installer applies it when
     * it writes the option; applying it again here is what makes the switch take
     * effect on an install where the index really does exist.
     *
     * @since 1.0.0
     *
     * @return bool True when the FULLTEXT driver should be used.
     */
    public static function supports_fulltext() {
        /** This filter is documented in includes/class-licensio-kb-db-installer.php */
        return (bool) apply_filters( 'licensio_supports_fulltext', Licensio_KB_DB_Installer::supports_fulltext() );
    }

    /**
     * Reduce a raw search term to something safe to query and to log.
     *
     * Removes every FULLTEXT boolean-mode operator, then strips markup, then
     * collapses whitespace and truncates to the column width.
     *
     * The operators are removed BEFORE the markup, because `<` and `>` are both
     * boolean operators and tag delimiters. Stripping tags first made
     * `a < b > c` lose everything between the two characters — `strip_tags()`
     * saw a tag and removed it — so a visitor searching for a comparison lost
     * half their query to a sanitiser.
     *
     * @since 1.0.0
     *
     * @param mixed $raw_term The visitor's raw input.
     *
     * @return string The sanitized term.
     */
    public static function sanitize_term( $raw_term ) {
        $term = is_scalar( $raw_term ) ? (string) $raw_term : '';
        $term = str_replace( self::BOOLEAN_OPERATORS, ' ', $term );
        $term = wp_strip_all_tags( $term );
        $term = preg_replace( '/\s+/u', ' ', $term );
        $term = trim( (string) $term );

        if ( function_exists( 'mb_substr' ) ) {
            return mb_substr( $term, 0, self::MAX_TERM_LENGTH );
        }

        return substr( $term, 0, self::MAX_TERM_LENGTH );
    }

    /**
     * Break a sanitized term into query tokens.
     *
     * Tokens are letters, digits and underscores only. Nothing that could act as
     * a LIKE wildcard or a boolean operator survives tokenising, which is what
     * lets both drivers embed the tokens in a pattern without further escaping
     * beyond the placeholder binding they already use.
     *
     * Tokens are lower-cased before they are deduplicated. Both matching paths
     * are already case-insensitive — MySQL through its `_ci` collation, PHP
     * through `mb_stripos()` — so `Licence licence` is one token's worth of
     * work, and folding it makes the token cap count distinct words rather than
     * distinct spellings.
     *
     * @since 1.0.0
     *
     * @param string $term The sanitized term.
     *
     * @return string[] The query tokens, at most MAX_TOKENS of them.
     */
    public static function tokenize( $term ) {
        $matches = array();

        preg_match_all( '/[\p{L}\p{N}_]+/u', (string) $term, $matches );

        $tokens = array_values( array_unique( array_map( array( __CLASS__, 'fold' ), $matches[0] ) ) );

        return array_slice( $tokens, 0, self::MAX_TOKENS );
    }

    /**
     * Build the boolean-mode expression for a set of tokens.
     *
     * Every usable token is required (`+token`) and the last usable one is
     * prefix-expanded (`+token*`) so a type-ahead matches a word the visitor has
     * not finished typing. Tokens too short for the server to have indexed are
     * left out rather than required, because requiring one guarantees zero rows.
     *
     * @since 1.0.0
     *
     * @param string[] $tokens The sanitized query tokens.
     *
     * @return string The boolean-mode expression, or an empty string when no token qualifies.
     */
    public static function boolean_query( array $tokens ) {
        /**
         * Filter the shortest token included in the FULLTEXT boolean query.
         *
         * Raise it to 4 on a MyISAM install, whose `ft_min_word_len` defaults
         * to 4 rather than InnoDB's 3.
         *
         * @since 1.0.0
         *
         * @param int $length The minimum token length.
         */
        $minimum = (int) apply_filters( 'licensio_search_min_token_length', self::MIN_FULLTEXT_TOKEN_LENGTH );
        $minimum = max( 1, $minimum );

        $usable = array();

        foreach ( $tokens as $token ) {
            /*
             * Re-checked here rather than trusted from tokenize(). This is the
             * one string in the plugin that MySQL parses as an expression rather
             * than binding as a value, so a caller that reached a driver with its
             * own token list — the drivers are public — must not be able to put
             * `-` or `*` into it. Sanitising the term is the first line; this is
             * the one that holds whoever skipped it.
             */
            if ( ! preg_match( '/^[\p{L}\p{N}_]+$/u', (string) $token ) ) {
                continue;
            }

            if ( self::token_length( $token ) >= $minimum ) {
                $usable[] = $token;
            }
        }

        if ( empty( $usable ) ) {
            return '';
        }

        $last  = count( $usable ) - 1;
        $parts = array();

        foreach ( $usable as $index => $token ) {
            $parts[] = '+' . $token . ( $index === $last ? '*' : '' );
        }

        return implode( ' ', $parts );
    }

    /**
     * Lower-case a token.
     *
     * @since 1.0.0
     *
     * @param string $token The token.
     *
     * @return string The lower-cased token.
     */
    private static function fold( $token ) {
        if ( function_exists( 'mb_strtolower' ) ) {
            return (string) mb_strtolower( (string) $token, 'UTF-8' );
        }

        return strtolower( (string) $token );
    }

    /**
     * The length of a token in characters.
     *
     * @since 1.0.0
     *
     * @param string $token The token.
     *
     * @return int The character count.
     */
    private static function token_length( $token ) {
        if ( function_exists( 'mb_strlen' ) ) {
            return (int) mb_strlen( (string) $token );
        }

        return strlen( (string) $token );
    }
}
