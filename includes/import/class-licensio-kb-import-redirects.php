<?php
/**
 * The URL map an import leaves behind, and the redirects it feeds.
 *
 * A knowledge base is linked to from tickets, emails, release notes and search
 * engines. Moving one without redirects breaks every one of those links, so an
 * import produces two things:
 *
 * - `{source}-url-map-{random}.csv`, the audit record: one row per moved URL,
 *   with the IDs on both sides and a note when the slug had to change. It is
 *   written as the import goes, so an interrupted run still leaves a usable map
 *   of what it got through, and it is downloadable from the wizard. Its rows
 *   name draft and private articles, so it must not be fetchable from the
 *   uploads URL: the file name carries a random part, kept in the
 *   `licensio_import_url_map_files` option, and the directory carries a deny
 *   `index.php` and `.htaccess`. The random name is the real protection, since
 *   a server that ignores `.htaccess` (nginx) would otherwise serve it; the
 *   wizard downloads it through a capability and nonce checked request.
 * - The redirect map itself, old path to new URL, stored in the
 *   `licensio_import_redirects` option and served by
 *   `Licensio_KB_Import_Redirect_Server` on requests that would otherwise 404.
 *   Nothing has to be copied anywhere by hand.
 *
 * Old URLs come from the source, which builds them from its own stored settings
 * rather than its functions, since those are gone once the plugin is
 * deactivated. New URLs come from `get_permalink()` and `get_term_link()`
 * rather than string concatenation, so they follow whatever the permalink
 * settings actually are — notably the `hierarchical_category_urls` toggle,
 * which turns a flat category URL into a nested one.
 *
 * A dry run records nothing: no file and no option.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds an import's old-to-new URL map.
 *
 * @since 1.0.0
 */
class Licensio_KB_Import_Redirects {

    /**
     * Suffix of the name a downloaded audit file is offered under, after the source id.
     *
     * @since 1.0.0
     * @var string
     */
    const MAP_FILE_SUFFIX = '-url-map.csv';

    /**
     * The option the served redirect map is kept in, path to URL, every source merged.
     *
     * @since 1.0.0
     * @var string
     */
    const OPTION = 'licensio_import_redirects';

    /**
     * The option the audit file names are kept in, source id to file name.
     *
     * @since 1.0.0
     * @var string
     */
    const FILES_OPTION = 'licensio_import_url_map_files';

    /**
     * Contents of the deny files written into the output directory, by file name.
     *
     * @since 1.0.0
     * @var array<string, string>
     */
    const DENY_FILES = array(
        'index.php' => "<?php\n// Silence is golden.\n",
        '.htaccess' => "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tDeny from all\n</IfModule>\n",
    );

    /**
     * Column headings of the audit file.
     *
     * @since 1.0.0
     * @var string[]
     */
    const COLUMNS = array( 'type', 'source_id', 'target_id', 'old_url', 'new_url', 'note' );

    /**
     * The source driver id the map belongs to.
     *
     * @since 1.0.0
     * @var string
     */
    private $source = '';

    /**
     * Where the audit file is written.
     *
     * @since 1.0.0
     * @var string
     */
    private $directory = '';

    /**
     * Whether this run may write.
     *
     * @since 1.0.0
     * @var bool
     */
    private $dry_run = false;

    /**
     * The redirect entries recorded by this request, path to new URL.
     *
     * @since 1.0.0
     * @var array<string, string>
     */
    private $map = array();

    /**
     * Whether the header row has been considered this request.
     *
     * @since 1.0.0
     * @var bool
     */
    private $header_written = false;

    /**
     * The directory every import's artefacts are written to.
     *
     * @since 1.0.0
     *
     * @return string The absolute directory path, without a trailing slash.
     */
    public static function upload_directory() {
        $uploads = wp_upload_dir();

        return rtrim( $uploads['basedir'], '/\\' ) . '/licensio-kb-import';
    }

    /**
     * Constructor.
     *
     * @since 1.0.0
     *
     * @param string $source    The source driver id.
     * @param string $directory Where to write the audit file; defaults to the uploads directory.
     * @param bool   $dry_run   Whether to suppress every write.
     */
    public function __construct( $source, $directory = '', $dry_run = false ) {
        $this->source    = sanitize_key( (string) $source );
        $this->directory = self::resolve_directory( $directory );
        $this->dry_run   = (bool) $dry_run;
    }

    /**
     * Record a moved article.
     *
     * @since 1.0.0
     *
     * @param int    $source_id  The source post ID.
     * @param int    $article_id The target article ID.
     * @param string $old_url    Where the source article lived.
     * @param string $slug       The source slug.
     *
     * @return array{old: string, new: string} The old and new URL.
     */
    public function add_article( $source_id, $article_id, $old_url, $slug ) {
        $new = (string) get_permalink( (int) $article_id );

        $this->record( 'article', (int) $source_id, (int) $article_id, (string) $old_url, $new, (string) $slug );

        return array(
            'old' => (string) $old_url,
            'new' => $new,
        );
    }

    /**
     * Record a moved category or tag archive.
     *
     * @since 1.0.0
     *
     * @param string $type      `category`, `tag` or `knowledge_base`.
     * @param int    $source_id The source term ID.
     * @param int    $term_id   The target term ID.
     * @param string $taxonomy  The target taxonomy.
     * @param string $old_url   Where the source archive lived.
     * @param string $slug      The source slug.
     *
     * @return array{old: string, new: string} The old and new URL.
     */
    public function add_term( $type, $source_id, $term_id, $taxonomy, $old_url, $slug ) {
        $link = get_term_link( (int) $term_id, (string) $taxonomy );
        $new  = is_wp_error( $link ) ? '' : (string) $link;

        $this->record( (string) $type, (int) $source_id, (int) $term_id, (string) $old_url, $new, (string) $slug );

        return array(
            'old' => (string) $old_url,
            'new' => $new,
        );
    }

    /**
     * How many redirects this request recorded.
     *
     * @since 1.0.0
     *
     * @return int The number of map entries.
     */
    public function count() {
        return count( $this->map );
    }

    /**
     * The redirects this request recorded.
     *
     * @since 1.0.0
     *
     * @return array<string, string> Old path to new URL.
     */
    public function map() {
        return $this->map;
    }

    /**
     * The audit file's path.
     *
     * A real run gives the source its random file name the first time it is
     * asked for, and every later request of the run reads the same name back.
     * A dry run never names a file.
     *
     * @since 1.0.0
     *
     * @return string The absolute file path, or an empty string for a dry run with no file yet.
     */
    public function map_path() {
        if ( ! $this->dry_run && '' === self::map_file_name( $this->source ) ) {
            $files                  = self::map_file_names();
            $files[ $this->source ] = $this->source . '-url-map-' . wp_generate_password( 20, false, false ) . '.csv';

            update_option( self::FILES_OPTION, $files, false );
        }

        return self::map_path_for( $this->source, $this->directory );
    }

    /**
     * A source's audit file path.
     *
     * Read only: it never names a file, so it is safe on a download request.
     *
     * @since 1.0.0
     *
     * @param string $source    The source driver id.
     * @param string $directory The directory; defaults to the uploads directory.
     *
     * @return string The absolute file path, or an empty string when the source has no file yet.
     */
    public static function map_path_for( $source, $directory = '' ) {
        $name = self::map_file_name( $source );

        if ( '' === $name ) {
            return '';
        }

        $directory = self::resolve_directory( $directory );

        return $directory . '/' . $name;
    }

    /**
     * The file name a download of a source's audit file is offered under.
     *
     * Leaves the random part out, so it never reaches the download.
     *
     * @since 1.0.0
     *
     * @param string $source The source driver id.
     *
     * @return string The file name.
     */
    public static function download_file_name( $source ) {
        return sanitize_key( (string) $source ) . self::MAP_FILE_SUFFIX;
    }

    /**
     * Delete the output directory and everything in it.
     *
     * Used by the uninstaller when the site owner asked for everything to be
     * deleted. Only files are removed; a directory that still holds anything
     * else afterwards is left in place.
     *
     * @since 1.0.0
     *
     * @param string $directory The directory; defaults to the uploads directory.
     *
     * @return void
     */
    public static function delete_directory( $directory = '' ) {
        $directory = self::resolve_directory( $directory );

        if ( ! is_dir( $directory ) ) {
            return;
        }

        $entries = scandir( $directory );

        foreach ( is_array( $entries ) ? $entries : array() as $entry ) {
            if ( is_file( $directory . '/' . $entry ) ) {
                wp_delete_file( $directory . '/' . $entry );
            }
        }

        $left = scandir( $directory );

        if ( is_array( $left ) && 2 >= count( $left ) ) {
            rmdir( $directory ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- The plugin's own, now empty, output directory; the files in it were deleted with wp_delete_file().
        }
    }

    /**
     * Start a fresh audit file for a new real run.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function start() {
        if ( $this->dry_run || ! file_exists( $this->map_path() ) ) {
            return;
        }

        wp_delete_file( $this->map_path() );
    }

    /**
     * Merge every redirect in the audit file into the served map.
     *
     * Runs once the articles phase finishes. Reading the file back, rather than
     * only this request's entries, is what lets a run spread over many requests
     * — or interrupted and resumed — still produce the complete map.
     *
     * @since 1.0.0
     *
     * @return int How many redirects the served map holds afterwards.
     */
    public function flush() {
        if ( $this->dry_run ) {
            return 0;
        }

        foreach ( $this->rows_from_map_file() as $row ) {
            $old_url = isset( $row[3] ) ? (string) $row[3] : '';
            $new_url = isset( $row[4] ) ? (string) $row[4] : '';
            $path    = self::normalize_path( $old_url );

            if ( '' !== $path && '' !== $new_url && $old_url !== $new_url ) {
                $this->map[ $path ] = $new_url;
            }
        }

        $served = self::served_map();

        foreach ( $this->map as $path => $url ) {
            $served[ $path ] = $url;
        }

        update_option( self::OPTION, $served, false );

        return count( $served );
    }

    /**
     * Every redirect the plugin serves, path to new URL.
     *
     * @since 1.0.0
     *
     * @return array<string, string> The served map.
     */
    public static function served_map() {
        $map = get_option( self::OPTION, array() );

        return is_array( $map ) ? $map : array();
    }

    /**
     * The path a redirect is keyed by: no trailing slash, no query, and
     * percent-escapes in lowercase.
     *
     * @since 1.0.0
     *
     * @param string $url A URL or a path.
     *
     * @return string The normalised path, or ''.
     */
    public static function normalize_path( $url ) {
        $path = wp_parse_url( (string) $url, PHP_URL_PATH );

        if ( ! is_string( $path ) || '' === $path ) {
            return '';
        }

        /*
         * WordPress stores a non-ASCII slug percent-encoded in lowercase hex,
         * while browsers send uppercase. Lowercasing every escape makes the
         * two spellings of the same path one key.
         */
        $path = (string) preg_replace_callback(
            '/%[0-9A-Fa-f]{2}/',
            static function ( $escape ) {
                return strtolower( $escape[0] );
            },
            $path
        );

        return untrailingslashit( $path );
    }

    /**
     * The wizard's download link for a source's audit file.
     *
     * @since 1.0.0
     *
     * @param string $source The source driver id.
     *
     * @return string The nonced admin-ajax URL.
     */
    public static function download_url( $source ) {
        return wp_nonce_url(
            admin_url( 'admin-ajax.php?action=licensio_import_download_redirects&source=' . rawurlencode( sanitize_key( (string) $source ) ) ),
            Licensio_KB_Import_Admin::NONCE_ACTION
        );
    }

    /**
     * Read the audit file back as rows.
     *
     * @since 1.0.0
     *
     * @return array<int, array<int, string|null>> The data rows, header excluded.
     */
    private function rows_from_map_file() {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $contents = file_exists( $this->map_path() ) ? file_get_contents( $this->map_path() ) : false;

        if ( ! is_string( $contents ) || '' === $contents ) {
            return array();
        }

        $rows = array();

        foreach ( explode( "\n", trim( $contents ) ) as $line ) {
            if ( '' === $line ) {
                continue;
            }

            $row = str_getcsv( $line );

            // Skip the header row.
            if ( isset( $row[0] ) && 'type' === $row[0] ) {
                continue;
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Record one moved URL.
     *
     * @since 1.0.0
     *
     * @param string $type      `article`, `category`, `tag` or `knowledge_base`.
     * @param int    $source_id The source ID.
     * @param int    $target_id The target ID.
     * @param string $old_url   The source URL.
     * @param string $new_url   The target URL.
     * @param string $slug      The source slug, for the slug-change note.
     *
     * @return void
     */
    private function record( $type, $source_id, $target_id, $old_url, $new_url, $slug ) {
        if ( '' === $old_url ) {
            return;
        }

        $note = '';

        if ( '' === $new_url ) {
            $note = 'no new URL could be resolved';
        } elseif ( $old_url === $new_url ) {
            $note = 'URL unchanged';
        } elseif ( '' !== $slug && false === strpos( $new_url, '/' . $slug . '/' ) ) {
            $note = 'slug changed';
        }

        if ( '' !== $new_url && $old_url !== $new_url ) {
            $path = self::normalize_path( $old_url );

            if ( '' !== $path ) {
                $this->map[ $path ] = $new_url;
            }
        }

        $this->append_row( array( $type, (string) $source_id, (string) $target_id, $old_url, $new_url, $note ) );
    }

    /**
     * Append one row to the audit file.
     *
     * @since 1.0.0
     *
     * @param array<int, string> $values The fields.
     *
     * @return void
     */
    private function append_row( array $values ) {
        if ( $this->dry_run ) {
            return;
        }

        $this->ensure_directory();

        $line = '';

        if ( ! $this->header_written ) {
            $this->header_written = true;

            if ( ! file_exists( $this->map_path() ) ) {
                $line .= $this->csv_line( self::COLUMNS );
            }
        }

        $line .= $this->csv_line( $values );

        $this->put( $this->map_path(), $line, true );
    }

    /**
     * One CSV line, every field quoted.
     *
     * @since 1.0.0
     *
     * @param array<int, string> $values The fields.
     *
     * @return string The line, newline-terminated.
     */
    private function csv_line( array $values ) {
        $fields = array();

        foreach ( $values as $value ) {
            $fields[] = '"' . str_replace( '"', '""', (string) $value ) . '"';
        }

        return implode( ',', $fields ) . "\n";
    }

    /**
     * Create the output directory if it does not exist, with its deny files.
     *
     * The deny files are added to a directory that lacks them too, so a
     * directory made before they existed is covered as well.
     *
     * @since 1.0.0
     *
     * @return void
     */
    private function ensure_directory() {
        if ( '' === $this->directory ) {
            return;
        }

        if ( ! is_dir( $this->directory ) ) {
            wp_mkdir_p( $this->directory );
        }

        foreach ( self::DENY_FILES as $name => $contents ) {
            if ( ! file_exists( $this->directory . '/' . $name ) ) {
                $this->put( $this->directory . '/' . $name, $contents );
            }
        }
    }

    /**
     * The given directory without a trailing slash, or the uploads directory.
     *
     * @since 1.0.0
     *
     * @param string $directory The directory; empty for the uploads directory.
     *
     * @return string The absolute directory path, without a trailing slash.
     */
    private static function resolve_directory( $directory ) {
        return '' === (string) $directory ? self::upload_directory() : rtrim( (string) $directory, '/\\' );
    }

    /**
     * Every source's audit file name.
     *
     * @since 1.0.0
     *
     * @return array<string, string> Source id to file name.
     */
    private static function map_file_names() {
        $files = get_option( self::FILES_OPTION, array() );

        return is_array( $files ) ? $files : array();
    }

    /**
     * One source's audit file name.
     *
     * @since 1.0.0
     *
     * @param string $source The source driver id.
     *
     * @return string The file name, or an empty string when the source has none yet.
     */
    private static function map_file_name( $source ) {
        $files  = self::map_file_names();
        $source = sanitize_key( (string) $source );

        return isset( $files[ $source ] ) ? basename( (string) $files[ $source ] ) : '';
    }

    /**
     * Write a file.
     *
     * @since 1.0.0
     *
     * @param string $path     The file path.
     * @param string $contents The contents.
     * @param bool   $append   Whether to append rather than overwrite.
     *
     * @return int|false Bytes written, or false on failure.
     */
    private function put( $path, $contents, $append = false ) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        return file_put_contents( $path, $contents, $append ? FILE_APPEND : 0 );
    }
}
