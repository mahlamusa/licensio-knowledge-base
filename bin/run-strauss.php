<?php
/**
 * Run Strauss to prefix the vendored libraries into libraries/.
 *
 * After a successful run, adds a direct-access guard to the generated
 * autoloaders, which Strauss rewrites without one on every run. Plugin Check
 * reports a missing guard as an error.
 *
 * @since 1.0.0 Added the direct-access guard to the generated autoloaders.
 *
 * @package Lisensio_KB
 */

// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions, WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput -- A Composer script; it runs outside WordPress.

$lisensio_kb_strauss = dirname( __DIR__ ) . '/vendor/bin/strauss';

if ( ! file_exists( $lisensio_kb_strauss ) ) {
    echo "Strauss is not installed (--no-dev); keeping the committed libraries/ directory.\n";
    exit( 0 );
}

$lisensio_kb_status = 0;

passthru( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $lisensio_kb_strauss ), $lisensio_kb_status );

if ( 0 === $lisensio_kb_status ) {
    foreach ( array( 'autoload.php', 'autoload-classmap.php' ) as $lisensio_kb_file ) {
        $lisensio_kb_path = dirname( __DIR__ ) . '/libraries/' . $lisensio_kb_file;

        if ( ! is_readable( $lisensio_kb_path ) ) {
            continue;
        }

        $lisensio_kb_code = (string) file_get_contents( $lisensio_kb_path );

        if ( false === strpos( $lisensio_kb_code, "defined( 'ABSPATH' )" ) ) {
            $lisensio_kb_code = preg_replace( '/^<\?php\R+/', "<?php\n\ndefined( 'ABSPATH' ) || exit;\n\n", $lisensio_kb_code, 1 );
            file_put_contents( $lisensio_kb_path, $lisensio_kb_code );
        }
    }
}

exit( $lisensio_kb_status );
