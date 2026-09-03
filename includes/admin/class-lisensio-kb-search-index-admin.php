<?php
/**
 * Search Index tab on the Tools screen.
 *
 * The server half of the Search Index tab: index status, and a rebuild driven
 * through the search index's own chunked rebuild machinery — the same path the
 * plugin's cron uses — so a rebuild from the admin respects the same request
 * budget and finishes itself even if the browser walks away mid-way.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Serves the Search Index tab.
 *
 * @since 1.0.0
 */
class Lisensio_KB_Search_Index_Admin extends Lisensio_KB_Tools_Ajax {

    /**
     * Register the WordPress hooks.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function init_hooks() {
        add_action( 'wp_ajax_lisensio_tools_index_status', array( $this, 'ajax_status' ) );
        add_action( 'wp_ajax_lisensio_tools_index_start', array( $this, 'ajax_start' ) );
        add_action( 'wp_ajax_lisensio_tools_index_chunk', array( $this, 'ajax_chunk' ) );

        add_filter( 'lisensio_kb_tools_app_data', array( $this, 'app_data' ) );
    }

    /**
     * The bootstrap data the Search Index tab reads.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $data The Tools app configuration.
     *
     * @return array<string, mixed> The configuration with the tab's endpoint added.
     */
    public function app_data( $data ) {
        $data['searchIndex'] = $this->ajax_config();

        return $data;
    }

    /**
     * The index status: how many articles are indexed, how many exist, and
     * whether a rebuild is part-way through.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed> The status.
     */
    private function status() {
        $index = new Lisensio_KB_Search_Index();

        return array(
            'indexed'    => $index->count(),
            'articles'   => $this->count_articles(),
            'rebuilding' => (bool) get_option( Lisensio_KB_Search_Index::STATE_OPTION ),
        );
    }

    /**
     * Serve the index status.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function ajax_status() {
        $this->verify_ajax();

        wp_send_json_success( $this->status() );
    }

    /**
     * Empty the index and begin a rebuild.
     *
     * Truncating first is what makes this a true rebuild rather than a
     * re-upsert: rows for articles that no longer exist go with everything
     * else, and the fresh index contains exactly the articles that do.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function ajax_start() {
        $this->verify_ajax();

        $index = new Lisensio_KB_Search_Index();

        $index->truncate();
        $index->start_rebuild();

        wp_send_json_success( $this->status() );
    }

    /**
     * Run one chunk of the rebuild.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function ajax_chunk() {
        $this->verify_ajax();

        $index  = new Lisensio_KB_Search_Index();
        $chunk  = $index->run_rebuild_chunk();
        $status = $this->status();

        wp_send_json_success(
            array(
                'chunk'    => $chunk,
                'finished' => $chunk < Lisensio_KB_Search_Index::CHUNK_SIZE,
                'status'   => $status,
            )
        );
    }

    /**
     * How many articles the index could hold.
     *
     * The same eligibility rule `Lisensio_KB_Search_Index::is_indexable()`
     * applies — published and not password-protected — so the status card
     * counts exactly the population a rebuild writes.
     *
     * @since 1.0.0
     *
     * @return int The article count.
     */
    private function count_articles() {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts}
                 WHERE post_type = %s AND post_status = %s AND post_password = ''",
                Lisensio_KB_Post_Types::POST_TYPE,
                'publish'
            )
        );
    }
}
