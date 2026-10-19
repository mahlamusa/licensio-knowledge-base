<?php
/**
 * Result snippet building.
 *
 * Produces the short highlighted extract shown under each search result: a
 * window of text around the first matching token, with every matching token
 * wrapped in `<mark>`.
 *
 * Escaping order matters and is the whole point of this class. The text is
 * escaped FIRST and the `<mark>` tags are inserted afterwards, so the only
 * markup that can ever reach the browser is the two tags this file writes. A
 * snippet built the other way round — highlight, then escape — would either
 * escape its own tags into visible text or, if the escaping were skipped to
 * avoid that, hand an article author stored XSS on a public endpoint.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds highlighted search result snippets.
 *
 * @since 1.0.0
 */
class Licensio_KB_Search_Snippet {

    /**
     * How many characters of context a snippet holds by default.
     *
     * @since 1.0.0
     * @var int
     */
    const LENGTH = 200;

    /**
     * The ellipsis placed where the snippet was cut.
     *
     * @since 1.0.0
     * @var string
     */
    const ELLIPSIS = '&hellip;';

    /**
     * Build a highlighted snippet.
     *
     * @since 1.0.0
     *
     * @param string   $text   The plain text to extract from.
     * @param string[] $tokens The query tokens to highlight.
     * @param int      $length How many characters of context to include.
     *
     * @return string Escaped HTML containing only `<mark>` tags.
     */
    public static function build( $text, array $tokens, $length = self::LENGTH ) {
        $text   = trim( (string) preg_replace( '/\s+/u', ' ', (string) $text ) );
        $length = max( 40, (int) $length );

        if ( '' === $text ) {
            return '';
        }

        $window = self::window( $text, $tokens, $length );

        return self::highlight( esc_html( $window['text'] ), $tokens, $window['prefixed'], $window['suffixed'] );
    }

    /**
     * Cut a window of text around the first matching token.
     *
     * @since 1.0.0
     *
     * @param string   $text   The normalised plain text.
     * @param string[] $tokens The query tokens.
     * @param int      $length The window length in characters.
     *
     * @return array{text: string, prefixed: bool, suffixed: bool} The window and whether it was cut on each side.
     */
    private static function window( $text, array $tokens, $length ) {
        $total = self::strlen( $text );

        if ( $total <= $length ) {
            return array(
                'text'     => $text,
                'prefixed' => false,
                'suffixed' => false,
            );
        }

        $position = self::first_match( $text, $tokens );
        $start    = max( 0, $position - (int) floor( $length / 3 ) );

        /*
         * Step back to the nearest space so the window never opens or closes in
         * the middle of a word, which reads as a typo rather than as a cut.
         */
        if ( $start > 0 ) {
            $space = self::strpos( $text, ' ', $start );
            $start = false === $space ? $start : $space + 1;
        }

        $window = self::substr( $text, $start, $length );
        $end    = $start + self::strlen( $window );

        if ( $end < $total ) {
            $last = self::strrpos( $window, ' ' );

            if ( false !== $last && $last > 0 ) {
                $window = self::substr( $window, 0, $last );
                $end    = $start + $last;
            }
        }

        return array(
            'text'     => trim( $window ),
            'prefixed' => $start > 0,
            'suffixed' => $end < $total,
        );
    }

    /**
     * Where the first query token appears in the text.
     *
     * @since 1.0.0
     *
     * @param string   $text   The normalised plain text.
     * @param string[] $tokens The query tokens.
     *
     * @return int The character offset, or zero when no token appears.
     */
    private static function first_match( $text, array $tokens ) {
        $first = null;

        foreach ( $tokens as $token ) {
            $token = (string) $token;

            if ( '' === $token ) {
                continue;
            }

            $position = function_exists( 'mb_stripos' ) ? mb_stripos( $text, $token ) : stripos( $text, $token );

            if ( false !== $position && ( null === $first || $position < $first ) ) {
                $first = (int) $position;
            }
        }

        return null === $first ? 0 : $first;
    }

    /**
     * Wrap every token occurrence in `<mark>`.
     *
     * Operates on ALREADY ESCAPED text. The tokens are escaped the same way
     * before being built into the pattern, so a token containing `&` matches the
     * `&amp;` the escaping produced rather than failing to match at all.
     *
     * @since 1.0.0
     *
     * @param string   $escaped  The escaped window text.
     * @param string[] $tokens   The query tokens.
     * @param bool     $prefixed Whether the window was cut at the start.
     * @param bool     $suffixed Whether the window was cut at the end.
     *
     * @return string The highlighted snippet.
     */
    private static function highlight( $escaped, array $tokens, $prefixed, $suffixed ) {
        $patterns = array();

        foreach ( $tokens as $token ) {
            $token = esc_html( (string) $token );

            if ( '' === $token ) {
                continue;
            }

            $patterns[] = preg_quote( $token, '/' );
        }

        if ( ! empty( $patterns ) ) {
            $marked = preg_replace(
                '/(' . implode( '|', $patterns ) . ')/iu',
                '<mark>$1</mark>',
                $escaped
            );

            /*
             * preg_replace() returns null when the subject is not valid UTF-8,
             * which the `u` modifier requires. Falling back to the unhighlighted
             * text keeps the endpoint answering rather than returning an empty
             * snippet for an article with one bad byte in it.
             */
            $escaped = null === $marked ? $escaped : $marked;
        }

        return ( $prefixed ? self::ELLIPSIS . ' ' : '' ) . $escaped . ( $suffixed ? ' ' . self::ELLIPSIS : '' );
    }

    /**
     * Multibyte-aware strlen.
     *
     * @since 1.0.0
     *
     * @param string $text The text.
     *
     * @return int The character count.
     */
    private static function strlen( $text ) {
        return function_exists( 'mb_strlen' ) ? (int) mb_strlen( $text ) : strlen( $text );
    }

    /**
     * Multibyte-aware substr.
     *
     * @since 1.0.0
     *
     * @param string   $text   The text.
     * @param int      $start  The start offset.
     * @param int|null $length The length, or null for the remainder.
     *
     * @return string The substring.
     */
    private static function substr( $text, $start, $length = null ) {
        if ( function_exists( 'mb_substr' ) ) {
            return (string) mb_substr( $text, $start, $length );
        }

        return null === $length ? substr( $text, $start ) : substr( $text, $start, $length );
    }

    /**
     * Multibyte-aware strpos.
     *
     * @since 1.0.0
     *
     * @param string $text   The text.
     * @param string $needle The needle.
     * @param int    $offset Where to start looking.
     *
     * @return int|false The offset, or false when not found.
     */
    private static function strpos( $text, $needle, $offset = 0 ) {
        return function_exists( 'mb_strpos' ) ? mb_strpos( $text, $needle, $offset ) : strpos( $text, $needle, $offset );
    }

    /**
     * Multibyte-aware strrpos.
     *
     * @since 1.0.0
     *
     * @param string $text   The text.
     * @param string $needle The needle.
     *
     * @return int|false The offset, or false when not found.
     */
    private static function strrpos( $text, $needle ) {
        return function_exists( 'mb_strrpos' ) ? mb_strrpos( $text, $needle ) : strrpos( $text, $needle );
    }
}
