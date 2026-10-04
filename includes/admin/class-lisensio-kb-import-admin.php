<?php
/**
 * Import wizard endpoints.
 *
 * The wizard itself is the Migration tab of the Tools screen (see
 * `Lisensio_KB_Tools_Admin`); this class is the server half — the AJAX
 * endpoints the tab drives, the URL map and log downloads — plus the bootstrap
 * data the tab needs from this side of the mount.
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
     * How many log entries one page holds.
     *
     * @since 1.0.0
     * @var int
     */
    const LOG_PAGE_SIZE = 50;

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
        add_action( 'wp_ajax_lisensio_import_detect', array( $this, 'ajax_detect' ) );
        add_action( 'wp_ajax_lisensio_import_categories', array( $this, 'ajax_categories' ) );
        add_action( 'wp_ajax_lisensio_import_run', array( $this, 'ajax_run_batch' ) );
        add_action( 'wp_ajax_lisensio_import_reset', array( $this, 'ajax_reset' ) );
        add_action( 'wp_ajax_lisensio_import_log_runs', array( $this, 'ajax_log_runs' ) );
        add_action( 'wp_ajax_lisensio_import_log_entries', array( $this, 'ajax_log_entries' ) );
        add_action( 'wp_ajax_lisensio_import_log_clear', array( $this, 'ajax_log_clear' ) );
        add_action( 'wp_ajax_lisensio_import_download_redirects', array( $this, 'download_redirects' ) );
        add_action( 'wp_ajax_lisensio_import_download_log', array( $this, 'download_log' ) );

        add_filter( 'lisensio_kb_tools_app_data', array( $this, 'app_data' ) );
    }

    /**
     * The bootstrap data the Migration tab reads.
     *
     * `available` says whether any import source is detected on the site, or
     * whether there is a log to read, so the Tools screen can hide the tab
     * entirely when there is nothing to migrate and nothing to review.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $data The Tools app configuration.
     *
     * @return array<string, mixed> The configuration with the wizard endpoints added.
     */
    public function app_data( $data ) {
        $available = count( $this->importer->get_detected_drivers() ) > 0;

        if ( ! $available ) {
            try {
                $available = (int) Lisensio_KB_DB::table( Lisensio_KB_Import_Log::TABLE )->count() > 0;
            } catch ( Throwable $e ) {
                unset( $e );
            }
        }

        $data['wizard'] = array(
            'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
            'nonce'     => wp_create_nonce( self::NONCE_ACTION ),
            'available' => $available,
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
            wp_send_json_error( __( 'You are not allowed to run imports.', 'lisensio-knowledge-base' ), 403 );
        }

        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), self::NONCE_ACTION ) ) {
            wp_send_json_error( __( 'Your session has expired. Reload the page and try again.', 'lisensio-knowledge-base' ), 403 );
        }
    }

    /**
     * The driver a request names, or a JSON error.
     *
     * @since 1.0.0
     *
     * @return Lisensio_KB_Import_Driver The driver.
     */
    private function requested_driver() {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in verify_ajax().
        $source = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : '';
        $driver = $this->importer->get_driver( $source );

        if ( ! $driver ) {
            wp_send_json_error( __( 'Unknown import source.', 'lisensio-knowledge-base' ), 400 );
        }

        return $driver;
    }

    /**
     * Detect available sources, their item counts and where each run stands.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function ajax_detect() {
        $this->verify_ajax();

        $detected = array();

        foreach ( $this->importer->get_detected_drivers() as $id => $driver ) {
            $labels = $driver->count_labels();
            $counts = array();

            foreach ( $driver->counts() as $key => $count ) {
                $counts[] = array(
                    'key'   => $key,
                    'label' => isset( $labels[ $key ] ) ? $labels[ $key ] : $key,
                    'count' => (int) $count,
                );
            }

            $detected[] = array(
                'id'     => $id,
                'label'  => $driver->label(),
                'counts' => $counts,
                'phases' => $driver->phases(),
                'status' => $driver->status(),
            );
        }

        wp_send_json_success( array( 'drivers' => $detected ) );
    }

    /**
     * The source's categories and the existing categories they can be merged into.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function ajax_categories() {
        $this->verify_ajax();

        $driver  = $this->requested_driver();
        $targets = array();
        $terms   = get_terms(
            array(
                'taxonomy'   => Lisensio_KB_Post_Types::TAXONOMY_CATEGORY,
                'hide_empty' => false,
                'orderby'    => 'name',
            )
        );

        foreach ( is_array( $terms ) ? $terms : array() as $term ) {
            if ( $term instanceof WP_Term ) {
                $targets[] = array(
                    'id'     => (int) $term->term_id,
                    'name'   => (string) $term->name,
                    'parent' => (int) $term->parent,
                );
            }
        }

        wp_send_json_success(
            array(
                'source'  => $driver->category_tree(),
                'targets' => $targets,
            )
        );
    }

    /**
     * Run an import batch.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function ajax_run_batch() {
        $this->verify_ajax();

        $driver = $this->requested_driver();

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified in verify_ajax().
        $dry_run    = isset( $_POST['dry_run'] ) && 'true' === sanitize_text_field( wp_unslash( $_POST['dry_run'] ) );
        $batch_size = isset( $_POST['batch_size'] ) ? absint( wp_unslash( $_POST['batch_size'] ) ) : 50;
        $raw_map    = isset( $_POST['category_map'] ) ? sanitize_text_field( wp_unslash( $_POST['category_map'] ) ) : '';
        $settings   = isset( $_POST['import_settings'] ) && 'true' === sanitize_text_field( wp_unslash( $_POST['import_settings'] ) );
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        $decoded = '' === $raw_map ? array() : json_decode( $raw_map, true );
        $map     = array();

        foreach ( is_array( $decoded ) ? $decoded : array() as $source_id => $target_id ) {
            if ( absint( $source_id ) > 0 && absint( $target_id ) > 0 ) {
                $map[ absint( $source_id ) ] = absint( $target_id );
            }
        }

        try {
            $progress = $driver->run_batch(
                array(
                    'dry_run'         => $dry_run,
                    'batch_size'      => min( 500, max( 1, $batch_size ) ),
                    'category_map'    => $map,
                    'import_settings' => $settings,
                )
            );
        } catch ( Throwable $e ) {
            wp_send_json_error(
                sprintf(
                    /* translators: %s: the error message. */
                    __( 'The import stopped with an error: %s. The failure is in the migration log; running the import again resumes from the last saved point.', 'lisensio-knowledge-base' ),
                    $e->getMessage()
                ),
                500
            );
        }

        $response = array( 'progress' => $progress );

        if ( isset( $progress['summary'] ) ) {
            $response['summary'] = $progress['summary'];
            unset( $response['progress']['summary'] );
        }

        wp_send_json_success( $response );
    }

    /**
     * Forget a run's state, so the next batch starts a fresh run.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function ajax_reset() {
        $this->verify_ajax();

        $driver = $this->requested_driver();

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in verify_ajax().
        $dry_run = isset( $_POST['dry_run'] ) && 'true' === sanitize_text_field( wp_unslash( $_POST['dry_run'] ) );

        $driver->reset( $dry_run );

        wp_send_json_success();
    }

    /**
     * Every run in the import log.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function ajax_log_runs() {
        $this->verify_ajax();

        $runs = Lisensio_KB_Import_Log::runs();

        foreach ( $runs as $index => $run ) {
            $runs[ $index ]['download_url'] = self::log_download_url( $run['run_id'] );
        }

        wp_send_json_success( array( 'runs' => $runs ) );
    }

    /**
     * One page of a run's log entries.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function ajax_log_entries() {
        $this->verify_ajax();

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified in verify_ajax().
        $run_id = isset( $_POST['run_id'] ) ? sanitize_text_field( wp_unslash( $_POST['run_id'] ) ) : '';
        $status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';
        $page   = isset( $_POST['page'] ) ? max( 1, absint( wp_unslash( $_POST['page'] ) ) ) : 1;
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        wp_send_json_success( Lisensio_KB_Import_Log::entries( $run_id, $status, $page, self::LOG_PAGE_SIZE ) );
    }

    /**
     * Clear the log, or one run of it.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function ajax_log_clear() {
        $this->verify_ajax();

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in verify_ajax().
        $run_id = isset( $_POST['run_id'] ) ? sanitize_text_field( wp_unslash( $_POST['run_id'] ) ) : '';

        Lisensio_KB_Import_Log::clear( $run_id );

        wp_send_json_success();
    }

    /**
     * Download a source's URL map.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function download_redirects() {
        $this->verify_download();

        $source = isset( $_GET['source'] ) ? sanitize_key( wp_unslash( $_GET['source'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified in verify_download().

        if ( ! in_array( $source, $this->importer->ids(), true ) ) {
            wp_die( esc_html__( 'Unknown import source.', 'lisensio-knowledge-base' ), '', array( 'response' => 400 ) );
        }

        $path = Lisensio_KB_Import_Redirects::map_path_for( $source );

        if ( ! file_exists( $path ) ) {
            wp_die( esc_html__( 'There is no URL map yet. Run the import first.', 'lisensio-knowledge-base' ), '', array( 'response' => 404 ) );
        }

        $this->send_csv_headers( basename( $path ) );

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streaming a download; WP_Filesystem has no streaming API.
        readfile( $path );
        exit;
    }

    /**
     * Download one run's log as CSV.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function download_log() {
        $this->verify_download();

        $run_id = isset( $_GET['run_id'] ) ? sanitize_text_field( wp_unslash( $_GET['run_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified in verify_download().

        if ( '' === $run_id ) {
            wp_die( esc_html__( 'No run was named.', 'lisensio-knowledge-base' ), '', array( 'response' => 400 ) );
        }

        $this->send_csv_headers( 'lisensio-import-log-' . sanitize_file_name( $run_id ) . '.csv' );

        echo Lisensio_KB_Import_Log::to_csv( $run_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A CSV attachment, not HTML; every field is quoted and formula-guarded.
        exit;
    }

    /**
     * The download link for one run's log.
     *
     * @since 1.0.0
     *
     * @param string $run_id The run.
     *
     * @return string The nonced admin-ajax URL.
     */
    public static function log_download_url( $run_id ) {
        return wp_nonce_url(
            admin_url( 'admin-ajax.php?action=lisensio_import_download_log&run_id=' . rawurlencode( (string) $run_id ) ),
            self::NONCE_ACTION
        );
    }

    /**
     * Check permissions and nonce for a GET download.
     *
     * @since 1.0.0
     *
     * @return void
     */
    private function verify_download() {
        if ( ! current_user_can( Lisensio_KB_Capabilities::CAPABILITY ) ) {
            wp_die( esc_html__( 'You are not allowed to download import files.', 'lisensio-knowledge-base' ), '', array( 'response' => 403 ) );
        }

        $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

        if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
            wp_die( esc_html__( 'The download link has expired. Reload the page and try again.', 'lisensio-knowledge-base' ), '', array( 'response' => 403 ) );
        }
    }

    /**
     * Send the headers of a CSV attachment.
     *
     * @since 1.0.0
     *
     * @param string $filename The file name the browser saves.
     *
     * @return void
     */
    private function send_csv_headers( $filename ) {
        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
    }
}
