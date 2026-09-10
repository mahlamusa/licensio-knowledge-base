<?php
/**
 * Import wizard endpoints.
 *
 * The wizard itself is the Migration tab of the Tools screen (see
 * `Lisensio_KB_Tools_Admin`); this class is the server half — the AJAX
 * endpoints the tab drives and the redirect-artefact downloads — plus the
 * bootstrap data the tab needs from this side of the mount.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Serves the import wizard.
 *
 * @since 1.0.0
 */
class Lisensio_KB_Import_Admin {

    /**
     * The nonce action shared by every wizard endpoint.
     *
     * @since 1.0.0
     * @var string
     */
    const NONCE_ACTION = 'lisensio_kb_import';

    /**
     * The registry of import drivers.
     *
     * @since 1.0.0
     * @var Lisensio_KB_Importer
     */
    private $importer;

    /**
     * Constructor.
     *
     * @since 1.0.0
     *
     * @param Lisensio_KB_Importer $importer The registry of drivers.
     */
    public function __construct( Lisensio_KB_Importer $importer ) {
        $this->importer = $importer;
    }

    /**
     * Register the WordPress hooks.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function init_hooks() {
        // AJAX endpoints.
        add_action( 'wp_ajax_lisensio_import_detect', array( $this, 'ajax_detect' ) );
        add_action( 'wp_ajax_lisensio_import_run', array( $this, 'ajax_run_batch' ) );
        add_action( 'wp_ajax_lisensio_import_reset', array( $this, 'ajax_reset' ) );
        add_action( 'wp_ajax_lisensio_import_download_redirects', array( $this, 'download_redirects' ) );

        add_filter( 'lisensio_kb_tools_app_data', array( $this, 'app_data' ) );
    }

    /**
     * The bootstrap data the Migration tab reads.
     *
     * `available` says whether any import source is detected on the site, so
     * the Tools screen can hide the tab entirely when there is nothing to
     * migrate. Detection is the same call the wizard itself makes.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $data The Tools app configuration.
     *
     * @return array<string, mixed> The configuration with the wizard endpoints added.
     */
    public function app_data( $data ) {
        $data['wizard'] = array(
            'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
            'nonce'     => wp_create_nonce( self::NONCE_ACTION ),
            'available' => count( $this->importer->get_detected_drivers() ) > 0,
        );

        return $data;
    }

    /**
     * Check permissions and nonce.
     *
     * @since 1.0.0
     *
     * @return void
     */
    private function verify_ajax() {
        if ( ! current_user_can( Lisensio_KB_Capabilities::CAPABILITY ) ) {
            wp_send_json_error( 'Forbidden', 403 );
        }

        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), self::NONCE_ACTION ) ) {
            wp_send_json_error( 'Invalid nonce', 403 );
        }
    }

    /**
     * Detect available sources and their item counts.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function ajax_detect() {
        $this->verify_ajax();

        $detected = array();

        foreach ( $this->importer->get_detected_drivers() as $id => $driver ) {
            $detected[ $id ] = array(
                'id'     => $id,
                'counts' => $driver->counts(),
            );
        }

        wp_send_json_success( array( 'drivers' => array_values( $detected ) ) );
    }

    /**
     * Run a migration batch.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function ajax_run_batch() {
        $this->verify_ajax();

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in verify_ajax().
        $source = isset( $_POST['source'] ) ? sanitize_text_field( wp_unslash( $_POST['source'] ) ) : '';
        $driver = $this->importer->get_driver( $source );

        if ( ! $driver ) {
            wp_send_json_error( 'Invalid source driver.' );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in verify_ajax().
        $dry_run = ! empty( $_POST['dry_run'] ) && 'true' === $_POST['dry_run'];
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in verify_ajax().
        $batch_size = isset( $_POST['batch_size'] ) ? (int) $_POST['batch_size'] : 50;

        $options = array(
            'dry_run'    => $dry_run,
            'batch_size' => $batch_size,
        );

        $progress = $driver->run_batch( $options );

        $response = array(
            'progress' => $progress,
        );

        if ( $progress['finished'] ) {
            $response['summary'] = $driver->summary();
        }

        wp_send_json_success( $response );
    }

    /**
     * Reset the migration state.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function ajax_reset() {
        $this->verify_ajax();

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in verify_ajax().
        $source = isset( $_POST['source'] ) ? sanitize_text_field( wp_unslash( $_POST['source'] ) ) : '';
        $driver = $this->importer->get_driver( $source );

        if ( $driver ) {
            $driver->reset();
        }

        wp_send_json_success();
    }

    /**
     * Download one of the redirect artefacts.
     *
     * The drop-in is a PHP file living in uploads; linking to it directly would
     * execute it, so this action streams it as an attachment instead.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function download_redirects() {
        if ( ! current_user_can( Lisensio_KB_Capabilities::CAPABILITY ) ) {
            wp_die( 'Forbidden', 403 );
        }

        $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

        if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
            wp_die( 'Invalid nonce', 403 );
        }

        $file = isset( $_GET['file'] ) ? sanitize_key( wp_unslash( $_GET['file'] ) ) : 'plugin';

        $filenames = array(
            'plugin' => Lisensio_KB_Heroic_Import_Redirects::REDIRECT_FILE,
            'map'    => Lisensio_KB_Heroic_Import_Redirects::MAP_FILE,
        );

        if ( ! isset( $filenames[ $file ] ) ) {
            wp_die( 'Unknown file requested.' );
        }

        $path = Lisensio_KB_Heroic_Import_Redirects::upload_directory() . '/' . $filenames[ $file ];

        if ( ! file_exists( $path ) ) {
            wp_die( 'File not found. Run the import first.' );
        }

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=' . $filenames[ $file ] );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streaming a download; WP_Filesystem has no streaming API.
        readfile( $path );
        exit;
    }
}
