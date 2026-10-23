<?php
/**
 * Run Strauss to prefix the vendored libraries into libraries/.
 *
 * After a successful run, adds a direct-access guard to the generated
 * autoloaders, which Strauss rewrites without one on every run. Plugin Check
 * reports a missing guard as an error.
 *
 * It also adds file-level phpcs:disable annotations to the stellarwp/db files
 * Plugin Check reports errors in. The library is a query builder: it runs the
 * SQL its callers have already prepared, interpolates only an integer depth
 * counter into SAVEPOINT names, and puts operator names in exception messages
 * that are never printed to a page. Strauss restores the upstream files on
 * every run, so the annotations are re-applied here, and a run fails when a
 * listed file disappears or no longer declares a namespace.
 *
 * @since 1.0.0 Added the direct-access guard to the generated autoloaders.
 * @since 1.0.0 Added the Plugin Check annotations to the stellarwp/db library.
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

    $licensio_kb_annotations = array(
        'DB.php'                                 => array(
            'sniffs' => 'WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.Security.EscapeOutput.ExceptionNotEscaped',
            'reason' => 'Runs SQL its callers prepared; SAVEPOINT names use an integer counter; exceptions are never printed.',
        ),
        'QueryBuilder/Clauses/Having.php'        => null,
        'QueryBuilder/Clauses/Join.php'          => null,
        'QueryBuilder/Clauses/JoinCondition.php' => null,
        'QueryBuilder/Clauses/OrderBy.php'       => null,
        'QueryBuilder/Clauses/Where.php'         => null,
    );

    foreach ( $licensio_kb_annotations as $licensio_kb_file => $licensio_kb_annotation ) {
        if ( null === $licensio_kb_annotation ) {
            $licensio_kb_annotation = array(
                'sniffs' => 'WordPress.Security.EscapeOutput.ExceptionNotEscaped',
                'reason' => 'Exception messages name SQL operators and are never printed to a page.',
            );
        }

        $licensio_kb_path = dirname( __DIR__ ) . '/libraries/stellarwp/db/src/DB/' . $licensio_kb_file;
        $licensio_kb_code = is_readable( $licensio_kb_path ) ? (string) file_get_contents( $licensio_kb_path ) : '';

        if ( false !== strpos( $licensio_kb_code, '// phpcs:disable' ) ) {
            continue;
        }

        $licensio_kb_line = '// phpcs:disable ' . $licensio_kb_annotation['sniffs'] . ' -- ' . $licensio_kb_annotation['reason'];
        $licensio_kb_code = preg_replace( '/^namespace /m', $licensio_kb_line . "\n\nnamespace ", $licensio_kb_code, 1, $licensio_kb_count );

        if ( 1 !== $licensio_kb_count ) {
            echo 'Could not annotate libraries/stellarwp/db/src/DB/' . $licensio_kb_file . "; check the Plugin Check annotations in bin/run-strauss.php.\n";
            exit( 1 );
        }

        file_put_contents( $licensio_kb_path, $licensio_kb_code );
    }
}

exit( $licensio_kb_status );
