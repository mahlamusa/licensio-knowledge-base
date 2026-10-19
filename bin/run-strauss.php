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
 * @package Licensio_KB
 */

// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions, WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput -- A Composer script; it runs outside WordPress.

$licensio_kb_strauss = dirname( __DIR__ ) . '/vendor/bin/strauss';

if ( ! file_exists( $licensio_kb_strauss ) ) {
    echo "Strauss is not installed (--no-dev); keeping the committed libraries/ directory.\n";
    exit( 0 );
}

$licensio_kb_status = 0;

passthru( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $licensio_kb_strauss ), $licensio_kb_status );

if ( 0 === $licensio_kb_status ) {
    foreach ( array( 'autoload.php', 'autoload-classmap.php' ) as $licensio_kb_file ) {
        $licensio_kb_path = dirname( __DIR__ ) . '/libraries/' . $licensio_kb_file;

        if ( ! is_readable( $licensio_kb_path ) ) {
            continue;
        }

        $licensio_kb_code = (string) file_get_contents( $licensio_kb_path );

        if ( false === strpos( $licensio_kb_code, "defined( 'ABSPATH' )" ) ) {
            $licensio_kb_code = preg_replace( '/^<\?php\R+/', "<?php\n\ndefined( 'ABSPATH' ) || exit;\n\n", $licensio_kb_code, 1 );
            file_put_contents( $licensio_kb_path, $licensio_kb_code );
        }
    }
}

exit( $licensio_kb_status );
