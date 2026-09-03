<?php
/**
 * Shared AJAX guard for the Tools tabs.
 *
 * Every tool endpoint answers the same two questions before it does anything:
 * may this user manage the knowledge base, and did the request carry the nonce
 * the screen was rendered with. The contract lives here so the tabs cannot
 * drift on it.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * The AJAX guard shared by the Tools tabs.
 *
 * @since 1.0.0
 */
abstract class Lisensio_KB_Tools_Ajax {

    /**
     * The nonce action shared by the tool endpoints.
     *
     * @since 1.0.0
     * @var string
     */
    const NONCE_ACTION = 'lisensio_kb_tools';

    /**
     * Check permissions and nonce.
     *
     * Sends the JSON error and exits when either check fails.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function verify_ajax() {
        if ( ! current_user_can( Lisensio_KB_Capabilities::CAPABILITY ) ) {
            wp_send_json_error( 'Forbidden', 403 );
        }

        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), self::NONCE_ACTION ) ) {
            wp_send_json_error( 'Invalid nonce', 403 );
        }
    }

    /**
     * The AJAX endpoint configuration the tab reads.
     *
     * @since 1.0.0
     *
     * @return array{ajaxUrl: string, nonce: string} The endpoint URL and nonce.
     */
    protected function ajax_config() {
        return array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
        );
    }
}
