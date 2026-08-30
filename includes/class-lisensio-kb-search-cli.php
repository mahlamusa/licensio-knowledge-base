<?php
/**
 * The `wp lisensio-kb` WP-CLI command.
 *
 * Cron rebuilds the index in chunks so no single request runs long. WP-CLI has
 * no request budget to respect, so `reindex` drives the same chunking loop to
 * completion in one process — the operator's escape hatch when cron is disabled
 * or when an import has left the index behind the content.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Knowledge base maintenance commands.
 *
 * @since 1.0.0
 */
class Lisensio_KB_Search_CLI {

    /**
     * The index this command rebuilds.
     *
     * @since 1.0.0
     * @var Lisensio_KB_Search_Index|null
     */
    private $index = null;

    /**
     * Constructor.
     *
     * @since 1.0.0
     *
     * @param Lisensio_KB_Search_Index|null $index The search index.
     */
    public function __construct( $index = null ) {
        $this->index = $index instanceof Lisensio_KB_Search_Index ? $index : null;
    }

    /**
     * Register the command with WP-CLI.
     *
     * @since 1.0.0
     *
     * @param Lisensio_KB_Search_Index|null $index The search index to rebuild.
     *
     * @return bool True when the command was registered.
     */
    public static function register( $index = null ) {
        if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( 'WP_CLI' ) ) {
            return false;
        }

        WP_CLI::add_command( 'lisensio-kb', new self( $index ) );

        return true;
    }

    /**
     * Rebuild the knowledge base search index.
     *
     * ## OPTIONS
     *
     * [--flush]
     * : Empty the index before rebuilding. Use after changing what is indexed,
     * so rows for articles that are no longer indexable are removed.
     *
     * ## EXAMPLES
     *
     *     wp lisensio-kb reindex
     *     wp lisensio-kb reindex --flush
     *
     * @since 1.0.0
     *
     * @param string[]                   $args       Positional arguments. Unused.
     * @param array<string, string|bool> $assoc_args Associative arguments. WP-CLI
     *                                               passes a bare flag as boolean true.
     *
     * @return void
     */
    public function reindex( $args, $assoc_args ) {
        unset( $args );

        $index = $this->index();

        if ( ! empty( $assoc_args['flush'] ) ) {
            $index->truncate();

            WP_CLI::log( __( 'Search index emptied.', 'lisensio-knowledge-base' ) );
        }

        $started = microtime( true );

        $processed = $index->rebuild_all(
            function ( $total ) {
                WP_CLI::log(
                    sprintf(
                        /* translators: %d: number of articles processed so far */
                        __( 'Processed %d articles…', 'lisensio-knowledge-base' ),
                        (int) $total
                    )
                );
            }
        );

        WP_CLI::success(
            sprintf(
                /* translators: 1: number of articles processed, 2: number of indexed rows, 3: elapsed seconds */
                __( 'Reindexed %1$d articles into %2$d index rows in %3$s seconds.', 'lisensio-knowledge-base' ),
                (int) $processed,
                $index->count(),
                number_format_i18n( microtime( true ) - $started, 2 )
            )
        );
    }

    /**
     * The index instance to operate on.
     *
     * @since 1.0.0
     *
     * @return Lisensio_KB_Search_Index The search index.
     */
    private function index() {
        if ( ! $this->index instanceof Lisensio_KB_Search_Index ) {
            $this->index = new Lisensio_KB_Search_Index();
        }

        return $this->index;
    }
}
