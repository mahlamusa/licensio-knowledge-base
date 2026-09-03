<?php
/**
 * Duplicates tab on the Tools screen.
 *
 * The server half of the Duplicates tab: a read-only scan and the fix that
 * acts on what the scan currently finds. The detection and merge rules
 * themselves live in `Lisensio_KB_Duplicates`.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Serves the Duplicates tab.
 *
 * @since 1.0.0
 */
class Lisensio_KB_Duplicates_Admin extends Lisensio_KB_Tools_Ajax {

    /**
     * Register the WordPress hooks.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function init_hooks() {
        add_action( 'wp_ajax_lisensio_tools_duplicates_scan', array( $this, 'ajax_scan' ) );
        add_action( 'wp_ajax_lisensio_tools_duplicates_fix', array( $this, 'ajax_fix' ) );

        add_filter( 'lisensio_kb_tools_app_data', array( $this, 'app_data' ) );
    }

    /**
     * The bootstrap data the Duplicates tab reads.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $data The Tools app configuration.
     *
     * @return array<string, mixed> The configuration with the tab's endpoint added.
     */
    public function app_data( $data ) {
        $data['duplicates'] = $this->ajax_config();

        return $data;
    }

    /**
     * Scan for duplicate posts and terms. Writes nothing.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function ajax_scan() {
        $this->verify_ajax();

        wp_send_json_success( ( new Lisensio_KB_Duplicates() )->scan() );
    }

    /**
     * Fix every fixable group the scanner currently finds.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function ajax_fix() {
        $this->verify_ajax();

        wp_send_json_success( ( new Lisensio_KB_Duplicates() )->fix() );
    }
}
