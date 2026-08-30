<?php
/**
 * Strauss wrapper.
 *
 * Re-prefixes the Composer dependencies into `libraries/`. Runs as a
 * post-install and post-update hook, so it has to tolerate a `--no-dev`
 * install, where Strauss itself is absent: the already-prefixed `libraries/`
 * directory is committed, so skipping is correct in that case.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

$lisensio_kb_strauss = dirname( __DIR__ ) . '/vendor/bin/strauss';

if ( ! file_exists( $lisensio_kb_strauss ) ) {
    echo "Strauss is not installed (--no-dev); keeping the committed libraries/ directory.\n";
    exit( 0 );
}

$lisensio_kb_status = 0;

passthru( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $lisensio_kb_strauss ), $lisensio_kb_status );

exit( $lisensio_kb_status );
