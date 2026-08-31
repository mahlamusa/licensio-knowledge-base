<?php
/**
 * PHP comment stripper for the free/pro boundary check.
 *
 * Prints a PHP file with every comment and doc block blanked out, keeping the
 * original line count so the caller can report real line numbers.
 *
 * The boundary check has to tell a WooCommerce or SLSWC *symbol* apart from the
 * word appearing in prose. Both exist in the shipped tree: the plugin header
 * says the plugin "requires neither SLSWC nor WooCommerce", and the vendored
 * StellarWP DB notes that WooCommerce sets SQL_BIG_SELECTS too. A regex that
 * tries to skip comments on its own gets those wrong in one direction or the
 * other, so the stripping is done with PHP's own tokeniser instead, which is
 * exact by construction.
 *
 * Inline HTML is deliberately kept: it is shipped output, not commentary.
 *
 * Usage: php bin/php-code-only.php <file>
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

/**
 * Blank out a token's text while preserving its newlines.
 *
 * @since 1.0.0
 *
 * @param string $text The token text.
 *
 * @return string The newlines the token spanned, and nothing else.
 */
function lisensio_kb_blank_token( $text ) {
    return str_repeat( "\n", substr_count( $text, "\n" ) );
}

if ( $argc < 2 ) {
    fwrite( STDERR, "Usage: php bin/php-code-only.php <file>\n" );
    exit( 2 );
}

$lisensio_kb_file = $argv[1];

if ( ! is_readable( $lisensio_kb_file ) ) {
    fwrite( STDERR, sprintf( "Cannot read %s\n", $lisensio_kb_file ) );
    exit( 2 );
}

$lisensio_kb_source = file_get_contents( $lisensio_kb_file );

if ( false === $lisensio_kb_source ) {
    fwrite( STDERR, sprintf( "Cannot read %s\n", $lisensio_kb_file ) );
    exit( 2 );
}

$lisensio_kb_tokens = token_get_all( $lisensio_kb_source );
$lisensio_kb_output = '';

foreach ( $lisensio_kb_tokens as $lisensio_kb_token ) {
    if ( is_string( $lisensio_kb_token ) ) {
        $lisensio_kb_output .= $lisensio_kb_token;
        continue;
    }

    if ( T_COMMENT === $lisensio_kb_token[0] || T_DOC_COMMENT === $lisensio_kb_token[0] ) {
        $lisensio_kb_output .= lisensio_kb_blank_token( $lisensio_kb_token[1] );
        continue;
    }

    $lisensio_kb_output .= $lisensio_kb_token[1];
}

echo $lisensio_kb_output;
