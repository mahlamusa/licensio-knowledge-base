<?php
/**
 * The URL map, and the 301s that make it real.
 *
 * A knowledge base is linked to from tickets, emails, release notes and search
 * engines. Moving one without redirects breaks every one of those links, so the
 * migration produces two artefacts:
 *
 * - `heroic-url-map.csv`, the audit record: one row per moved URL, with the IDs
 *   on both sides and a note when the slug had to change. It is written as the
 *   migration goes, so an interrupted run still leaves a usable map of what it
 *   got through.
 * - `licensio-heroic-redirects.php`, a self-contained mu-plugin that serves the
 *   301s. It is generated rather than registered here on purpose: this tool is
 *   deleted once the migration is signed off, and the redirects have to outlive
 *   it by years. A drop-in file with the map inlined depends on nothing.
 *
 * Old URLs are built from Heroic's stored settings rather than from Heroic's
 * functions, which are gone once the plugin is deactivated. New URLs come from
 * `get_permalink()` and `get_term_link()` rather than string concatenation, so
 * they follow whatever the permalink settings actually are — notably the
 * `hierarchical_category_urls` toggle, which turns a flat Heroic category URL
 * into a nested one and therefore changes every child category's address.
 *
 * The Heroic knowledge base home is not in the map. It was a normal WordPress
 * page — Heroic registers `ht_kb` with `has_archive` false and keeps the page ID
 * in `kb-archive-page-id` — so its permalink is untouched by the migration and
 * there is nothing to redirect.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds the old-to-new URL map and the redirect drop-in.
 *
 * @since 1.0.0
 */
class Licensio_KB_Heroic_Redirects {

    /**
     * Name of the audit file.
     *
     * @since 1.0.0
     * @var string
     */
    const MAP_FILE = 'heroic-url-map.csv';

    /**
     * Name of the generated redirect drop-in.
     *
     * @since 1.0.0
     * @var string
     */
    const REDIRECT_FILE = 'licensio-heroic-redirects.php';

    /**
     * Columns of the audit file.
     *
     * @since 1.0.0
     * @var string[]
     */
    const COLUMNS = array( 'type', 'source_id', 'target_id', 'old_url', 'new_url', 'note' );

    /**
     * Directory the artefacts are written to.
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
     * Old path to new URL, for the drop-in.
     *
     * @since 1.0.0
     * @var array<string, string>
     */
    private $map = array();

    /**
     * Whether the audit file's header row has been written.
     *
     * @since 1.0.0
     * @var bool
     */
    private $header_written = false;

    /**
     * Constructor.
     *
     * @since 1.0.0
     *
     * @param string $directory Where to write the artefacts.
     * @param bool   $dry_run   Whether to suppress every write.
     */
    public function __construct( $directory, $dry_run = false ) {
        $this->directory = rtrim( (string) $directory, '/\\' );
        $this->dry_run   = (bool) $dry_run;
    }

    /**
     * Record the move of one article.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $row        The Heroic post row.
     * @param int                  $article_id The target article ID.
     *
     * @return void
     */
    public function add_article( array $row, $article_id ) {
        $source_id = isset( $row['ID'] ) ? (int) $row['ID'] : 0;
        $slug      = isset( $row['post_name'] ) ? (string) $row['post_name'] : '';

        if ( '' === $slug ) {
            return;
        }

        $old = $this->old_url( Licensio_KB_Heroic_Schema::article_slug(), $slug );
        $new = (string) get_permalink( (int) $article_id );

        $this->record( 'article', $source_id, (int) $article_id, $old, $new, $slug );
    }

    /**
     * Record the move of one term.
     *
     * @since 1.0.0
     *
     * @param string               $from     The Heroic taxonomy.
     * @param array<string, mixed> $row      The Heroic term row.
     * @param string               $to       The target taxonomy.
     * @param int                  $term_id  The target term ID.
     *
     * @return void
     */
    public function add_term( $from, array $row, $to, $term_id ) {
        $source_id = isset( $row['term_id'] ) ? (int) $row['term_id'] : 0;
        $slug      = isset( $row['slug'] ) ? (string) $row['slug'] : '';

        if ( '' === $slug ) {
            return;
        }

        $base = Licensio_KB_Heroic_Schema::TAXONOMY_CATEGORY === $from
            ? Licensio_KB_Heroic_Schema::category_slug()
            : Licensio_KB_Heroic_Schema::tag_slug();

        $old  = $this->old_url( $base, $slug );
        $link = get_term_link( (int) $term_id, (string) $to );
        $new  = is_wp_error( $link ) ? '' : (string) $link;

        $this->record( Licensio_KB_Heroic_Schema::TAXONOMY_CATEGORY === $from ? 'category' : 'tag', $source_id, (int) $term_id, $old, $new, $slug );
    }

    /**
     * How many URLs the map holds.
     *
     * @since 1.0.0
     *
     * @return int The entry count.
     */
    public function count() {
        return count( $this->map );
    }

    /**
     * The old path to new URL map.
     *
     * @since 1.0.0
     *
     * @return array<string, string> Old request path to new absolute URL.
     */
    public function map() {
        return $this->map;
    }

    /**
     * The path of the audit file.
     *
     * @since 1.0.0
     *
     * @return string The absolute path.
     */
    public function map_path() {
        return $this->directory . '/' . self::MAP_FILE;
    }

    /**
     * The path of the generated redirect drop-in.
     *
     * @since 1.0.0
     *
     * @return string The absolute path.
     */
    public function redirect_path() {
        return $this->directory . '/' . self::REDIRECT_FILE;
    }

    /**
     * Write the redirect drop-in from everything recorded so far.
     *
     * @since 1.0.0
     *
     * @return bool True when the file was written.
     */
    public function write_redirect_plugin() {
        if ( $this->dry_run || empty( $this->map ) ) {
            return false;
        }

        $this->ensure_directory();

        return false !== $this->put( $this->redirect_path(), $this->render_redirect_plugin() );
    }

    /**
     * Record one moved URL, appending it to the audit file as it goes.
     *
     * A move whose new URL could not be resolved is still written, with a note.
     * Dropping it would leave the operator with an audit file that quietly
     * disagrees with the number of things that moved.
     *
     * @since 1.0.0
     *
     * @param string $type      `article`, `category` or `tag`.
     * @param int    $source_id The Heroic ID.
     * @param int    $target_id The target ID.
     * @param string $old_url   The old absolute URL.
     * @param string $new_url   The new absolute URL, or an empty string.
     * @param string $slug      The Heroic slug, for the note.
     *
     * @return void
     */
    private function record( $type, $source_id, $target_id, $old_url, $new_url, $slug ) {
        $note = '';

        if ( '' === $new_url ) {
            $note = 'no new URL could be resolved';
        } elseif ( $old_url === $new_url ) {
            $note = 'URL unchanged';
        } elseif ( '' !== $slug && false === strpos( $new_url, '/' . $slug . '/' ) ) {
            $note = 'slug changed';
        }

        if ( '' !== $new_url && $old_url !== $new_url ) {
            $path = $this->path_of( $old_url );

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
     * @param string[] $values The column values, in self::COLUMNS order.
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
     * Render one CSV line.
     *
     * @since 1.0.0
     *
     * @param string[] $values The field values.
     *
     * @return string The line, newline terminated.
     */
    private function csv_line( array $values ) {
        $fields = array();

        foreach ( $values as $value ) {
            $fields[] = '"' . str_replace( '"', '""', (string) $value ) . '"';
        }

        return implode( ',', $fields ) . "\n";
    }

    /**
     * Build the URL Heroic served something at.
     *
     * @since 1.0.0
     *
     * @param string $base The Heroic rewrite base.
     * @param string $slug The object slug.
     *
     * @return string The absolute URL.
     */
    private function old_url( $base, $slug ) {
        return home_url( '/' . trim( (string) $base, '/' ) . '/' . trim( (string) $slug, '/' ) . '/' );
    }

    /**
     * The request path of an absolute URL, leading slash included.
     *
     * @since 1.0.0
     *
     * @param string $url The absolute URL.
     *
     * @return string The path, or an empty string.
     */
    private function path_of( $url ) {
        $path = wp_parse_url( (string) $url, PHP_URL_PATH );

        return is_string( $path ) ? $path : '';
    }

    /**
     * Make sure the output directory exists.
     *
     * @since 1.0.0
     *
     * @return void
     */
    private function ensure_directory() {
        if ( '' !== $this->directory && ! is_dir( $this->directory ) ) {
            wp_mkdir_p( $this->directory );
        }
    }

    /**
     * Write to a file.
     *
     * `file_put_contents()` rather than `WP_Filesystem`: this runs under WP-CLI
     * as the system user that owns the install, there is no credentials prompt
     * to negotiate, and the alternative would drag a whole admin subsystem into
     * a throwaway script.
     *
     * @since 1.0.0
     *
     * @param string $path     The file to write.
     * @param string $contents What to write.
     * @param bool   $append   Whether to append rather than replace.
     *
     * @return int|false Bytes written, or false on failure.
     */
    private function put( $path, $contents, $append = false ) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        return file_put_contents( $path, $contents, $append ? FILE_APPEND : 0 );
    }

    /**
     * Render a value as a single-quoted PHP string literal.
     *
     * Only the backslash and the single quote are special inside a
     * single-quoted PHP string, so escaping exactly those two is sufficient and
     * complete. A URL that contained either would otherwise close the literal
     * and inject arbitrary PHP into a file that runs on every front-end request.
     *
     * @since 1.0.0
     *
     * @param string $value The value.
     *
     * @return string The PHP literal, quotes included.
     */
    private static function php_string( $value ) {
        return "'" . str_replace( array( '\\', "'" ), array( '\\\\', "\\'" ), (string) $value ) . "'";
    }

    /**
     * Render the redirect drop-in.
     *
     * The generated file is deliberately dumb: a literal array and one hook. It
     * has no dependency on this tool, on Heroic, or on anything the plugin does
     * not already guarantee, because it has to keep working long after all three
     * have changed.
     *
     * Matching is on the path only, with and without the trailing slash, so a
     * link that lost its slash somewhere still lands. The query string is
     * carried over rather than dropped, because campaign and anchor parameters
     * on knowledge base links are common and losing them loses the attribution.
     *
     * @since 1.0.0
     *
     * @return string The PHP source of the drop-in.
     */
    private function render_redirect_plugin() {
        $entries = '';

        foreach ( $this->map as $path => $target ) {
            $entries .= sprintf(
                "        %s => %s,\n",
                self::php_string( untrailingslashit( (string) $path ) ),
                self::php_string( (string) $target )
            );
        }

        $generated = gmdate( 'Y-m-d H:i:s' );

        return <<<PHP
<?php
/**
 * Plugin Name: Licensio Knowledge Base — Heroic URL redirects
 * Description: Permanent redirects from the Heroic Knowledge Base URLs to their Licensio equivalents. Generated by tools/migrate-heroic on {$generated} UTC. Drop into wp-content/mu-plugins/.
 * Version: 1.0.0
 *
 * @package Licensio_KB
 */

defined( 'ABSPATH' ) || exit;

/**
 * The old-to-new URL map, keyed by unslashed request path.
 *
 * @since 1.0.0
 *
 * @return array<string, string> Old path to new absolute URL.
 */
function licensio_kb_heroic_redirect_map() {
    return array(
{$entries}    );
}

/**
 * Send a 301 when the request names a URL the knowledge base used to live at.
 *
 * @since 1.0.0
 *
 * @return void
 */
function licensio_kb_heroic_redirect() {
    if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
        return;
    }

    \$requested = isset( \$_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( \$_SERVER['REQUEST_URI'] ) ) : '';

    if ( '' === \$requested ) {
        return;
    }

    \$path = wp_parse_url( \$requested, PHP_URL_PATH );
    \$path = is_string( \$path ) ? untrailingslashit( \$path ) : '';
    \$map  = licensio_kb_heroic_redirect_map();

    if ( '' === \$path || ! isset( \$map[ \$path ] ) ) {
        return;
    }

    \$target = \$map[ \$path ];
    \$query  = wp_parse_url( \$requested, PHP_URL_QUERY );

    if ( is_string( \$query ) && '' !== \$query ) {
        \$target .= ( false === strpos( \$target, '?' ) ? '?' : '&' ) . \$query;
    }

    wp_safe_redirect( \$target, 301 );
    exit;
}

add_action( 'template_redirect', 'licensio_kb_heroic_redirect', 1 );

PHP;
    }
}
