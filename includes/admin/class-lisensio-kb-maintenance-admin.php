<?php
/**
 * Data Maintenance tab on the Tools screen.
 *
 * The server half of the Data Maintenance tab: two jobs an administrator runs
 * once in a while — recounting every article's usefulness score from the raw
 * votes, and deleting analytics rows past the retention window now rather than
 * waiting for the next scheduled sweep.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Serves the Data Maintenance tab.
 *
 * @since 1.0.0
 */
class Lisensio_KB_Maintenance_Admin extends Lisensio_KB_Tools_Ajax {

    /**
     * The analytics retention job.
     *
     * @since 1.0.0
     * @var Lisensio_KB_Analytics_Retention
     */
    private $retention;

    /**
     * Constructor.
     *
     * @since 1.0.0
     *
     * @param Lisensio_KB_Analytics_Retention $retention The analytics retention job.
     */
    public function __construct( Lisensio_KB_Analytics_Retention $retention ) {
        $this->retention = $retention;
    }

    /**
     * Register the WordPress hooks.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function init_hooks() {
        add_action( 'wp_ajax_lisensio_tools_maintenance_status', array( $this, 'ajax_status' ) );
        add_action( 'wp_ajax_lisensio_tools_recount_scores', array( $this, 'ajax_recount_scores' ) );
        add_action( 'wp_ajax_lisensio_tools_prune_analytics', array( $this, 'ajax_prune_analytics' ) );

        add_filter( 'lisensio_kb_tools_app_data', array( $this, 'app_data' ) );
    }

    /**
     * The bootstrap data the Data Maintenance tab reads.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $data The Tools app configuration.
     *
     * @return array<string, mixed> The configuration with the tab's endpoint added.
     */
    public function app_data( $data ) {
        $data['maintenance'] = $this->ajax_config();

        return $data;
    }

    /**
     * Row counts, the retention window, and the cutoff it implies.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed> The status.
     */
    private function status() {
        return array(
            'articles'      => $this->count_articles(),
            'feedbackRows'  => (int) Lisensio_KB_DB::table( Lisensio_KB_Feedback::TABLE )->count(),
            'viewRows'      => (int) Lisensio_KB_DB::table( Lisensio_KB_Analytics::TABLE_ARTICLE_VIEWS )->count(),
            'searchRows'    => (int) Lisensio_KB_DB::table( Lisensio_KB_Search_Log::TABLE )->count(),
            'retentionDays' => $this->retention->retention_days(),
            'cutoff'        => $this->retention->cutoff(),
        );
    }

    /**
     * Serve the maintenance status.
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
     * Recompute every article's usefulness score from the raw votes.
     *
     * The score is a cached COUNT, so the cache and the table can drift — a
     * migrated vote, a hand-edited row, a bug since fixed. This walks every
     * article through the component that owns the score and lets it rewrite
     * the cache, exactly as the importer does at the end of a migration.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function ajax_recount_scores() {
        $this->verify_ajax();

        $query = new WP_Query(
            array(
                'post_type'      => Lisensio_KB_Post_Types::POST_TYPE,
                'post_status'    => 'any',
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'no_found_rows'  => true,
            )
        );

        $feedback = new Lisensio_KB_Feedback();

        foreach ( $query->posts as $article_id ) {
            $feedback->recompute_score( (int) $article_id );
        }

        wp_send_json_success(
            array(
                'articles' => count( $query->posts ),
                'status'   => $this->status(),
            )
        );
    }

    /**
     * Run one retention sweep: delete expired analytics rows now.
     *
     * A sweep is bounded by the retention job's own time budget, so a very
     * large backlog may need more than one sweep. The response says whether
     * another sweep would still find work, and the tab loops until it would
     * not.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function ajax_prune_analytics() {
        $this->verify_ajax();

        $deleted = $this->retention->run();

        // A sweep that deleted nothing found no expired rows; one that deleted
        // rows may simply have spent its time budget.
        $complete = array_sum( array_map( 'intval', $deleted ) ) === 0 || $this->retention_exhausted();

        wp_send_json_success(
            array(
                'deleted'  => $deleted,
                'complete' => $complete,
                'status'   => $this->status(),
            )
        );
    }

    /**
     * Whether any expired row survived the last sweep's time budget.
     *
     * @since 1.0.0
     *
     * @return bool True when nothing expired remains.
     */
    private function retention_exhausted() {
        $cutoff = $this->retention->cutoff();

        foreach ( Lisensio_KB_Analytics_Retention::tables() as $table ) {
            $remaining = Lisensio_KB_DB::table( $table )
                ->where( 'created_at', $cutoff, '<' )
                ->count();

            if ( $remaining > 0 ) {
                return false;
            }
        }

        return true;
    }

    /**
     * How many articles the recount will walk.
     *
     * The same `post_status => 'any'` population the recount queries, so the
     * status card names the exact set the button acts on.
     *
     * @since 1.0.0
     *
     * @return int The article count.
     */
    private function count_articles() {
        $query = new WP_Query(
            array(
                'post_type'      => Lisensio_KB_Post_Types::POST_TYPE,
                'post_status'    => 'any',
                'posts_per_page' => 1,
                'fields'         => 'ids',
            )
        );

        return (int) $query->found_posts;
    }
}
