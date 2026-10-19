<?php
/**
 * The search component.
 *
 * Wires the search feature into WordPress: index maintenance, the REST route,
 * the block and shortcode, the WP-CLI command, and the ranking and logging of
 * the full results page. Attached from `licensio_loaded`, which is the hook the
 * scaffold's container fires once every shared component exists.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Boots and holds the knowledge base search feature.
 *
 * @since 1.0.0
 */
class Licensio_KB_Search {

    /**
     * How many ranked IDs are handed to the full results page query.
     *
     * The results page paginates through WP_Query, which needs the whole ranked
     * set up front to count and page it. The cap keeps that array bounded; a
     * visitor who has paged past 200 results is not reading them.
     *
     * @since 1.0.0
     * @var int
     */
    const RESULTS_PAGE_CAP = 200;

    /**
     * The single instance of the component.
     *
     * @since 1.0.0
     * @var Licensio_KB_Search|null
     */
    private static $instance = null;

    /**
     * The index maintainer.
     *
     * @since 1.0.0
     * @var Licensio_KB_Search_Index|null
     */
    private $index = null;

    /**
     * The query runner.
     *
     * @since 1.0.0
     * @var Licensio_KB_Search_Query|null
     */
    private $query = null;

    /**
     * The search log writer.
     *
     * @since 1.0.0
     * @var Licensio_KB_Search_Log|null
     */
    private $log = null;

    /**
     * The block and shortcode renderer.
     *
     * @since 1.0.0
     * @var Licensio_KB_Search_Block|null
     */
    private $block = null;

    /**
     * Whether the current main query is a knowledge base search we ranked.
     *
     * @since 1.0.0
     * @var bool
     */
    private $ranked_main_query = false;

    /**
     * The term the current main query searched for.
     *
     * @since 1.0.0
     * @var string
     */
    private $main_query_term = '';

    /**
     * Return the single instance of the component.
     *
     * @since 1.0.0
     *
     * @return Licensio_KB_Search The component.
     */
    public static function instance() {
        if ( ! self::$instance instanceof self ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Register the WordPress hooks.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function init_hooks() {
        $this->index()->init_hooks();
        $this->block()->init_hooks();

        add_filter( 'licensio_rest_controllers', array( $this, 'register_rest_controller' ) );
        add_filter( 'licensio_rest_stub_routes', array( $this, 'release_stub_route' ) );

        add_action( 'pre_get_posts', array( $this, 'rank_main_query' ) );
        add_action( 'wp', array( $this, 'log_main_query' ) );

        add_action( 'init', array( $this, 'register_cli' ), 30 );
    }

    /**
     * Add the search controller to the plugin's REST registrar.
     *
     * @since 1.0.0
     *
     * @param string[] $controllers The registered controller class names.
     *
     * @return string[] The controller class names, with the search controller added.
     */
    public function register_rest_controller( $controllers ) {
        $controllers   = is_array( $controllers ) ? $controllers : array();
        $controllers[] = 'Licensio_KB_REST_Search_Controller';

        return $controllers;
    }

    /**
     * Take `/search` off the scaffold's not-implemented stub list.
     *
     * @since 1.0.0
     *
     * @param array<string, array<string, string>> $routes The reserved stub routes.
     *
     * @return array<string, array<string, string>> The stub routes, without `/search`.
     */
    public function release_stub_route( $routes ) {
        $routes = is_array( $routes ) ? $routes : array();

        unset( $routes['/search'] );

        return $routes;
    }

    /**
     * Register the WP-CLI command.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function register_cli() {
        Licensio_KB_Search_CLI::register( $this->index() );
    }

    /**
     * Rank the full results page with the search index.
     *
     * Pressing Enter without choosing a suggestion lands here. Without this the
     * page would fall back to core's `LIKE` search over `post_title` and
     * `post_content`, which cannot see term names at all and orders by date —
     * so the type-ahead and the page it submits to would disagree about which
     * article is the best answer.
     *
     * @since 1.0.0
     *
     * @param WP_Query $query The query about to run.
     *
     * @return void
     */
    public function rank_main_query( $query ) {
        if ( ! $query instanceof WP_Query || is_admin() || ! $query->is_main_query() || ! $query->is_search() ) {
            return;
        }

        if ( ! $this->is_knowledge_base_query( $query ) ) {
            return;
        }

        $term = Licensio_KB_Search_Query::sanitize_term( $query->get( 's' ) );

        if ( '' === $term ) {
            return;
        }

        $found = $this->query()->search( $term, array( 'limit' => self::RESULTS_PAGE_CAP ) );

        /*
         * An empty match list must still narrow the query. Leaving `post__in`
         * unset would let core's own LIKE search answer instead, so a term the
         * index found nothing for would show results on the page and none in the
         * type-ahead.
         */
        $query->set( 'post__in', empty( $found['ids'] ) ? array( 0 ) : $found['ids'] );
        $query->set( 'orderby', 'post__in' );
        $query->set( 'post_type', Licensio_KB_Post_Types::POST_TYPE );

        $this->ranked_main_query = true;
        $this->main_query_term   = $found['term'];
    }

    /**
     * Log a full results page search once the query has run.
     *
     * Logged here rather than in `pre_get_posts` because the row records how
     * many articles were found, and that is not known until the query has run.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function log_main_query() {
        if ( ! $this->ranked_main_query ) {
            return;
        }

        $this->ranked_main_query = false;

        $found = 0;

        if ( isset( $GLOBALS['wp_query'] ) && $GLOBALS['wp_query'] instanceof WP_Query ) {
            $found = (int) $GLOBALS['wp_query']->found_posts;
        }

        $this->log()->record( $this->main_query_term, $found, 'site' );
    }

    /**
     * Whether a search query is searching the knowledge base.
     *
     * @since 1.0.0
     *
     * @param WP_Query $query The query.
     *
     * @return bool True when the query is scoped to the article post type.
     */
    private function is_knowledge_base_query( $query ) {
        $post_type = $query->get( 'post_type' );

        if ( is_array( $post_type ) ) {
            return in_array( Licensio_KB_Post_Types::POST_TYPE, $post_type, true );
        }

        return Licensio_KB_Post_Types::POST_TYPE === $post_type;
    }

    /**
     * The index maintainer.
     *
     * @since 1.0.0
     *
     * @return Licensio_KB_Search_Index The index.
     */
    public function index() {
        if ( ! $this->index instanceof Licensio_KB_Search_Index ) {
            $this->index = new Licensio_KB_Search_Index();
        }

        return $this->index;
    }

    /**
     * The query runner.
     *
     * @since 1.0.0
     *
     * @return Licensio_KB_Search_Query The query runner.
     */
    public function query() {
        if ( ! $this->query instanceof Licensio_KB_Search_Query ) {
            $this->query = new Licensio_KB_Search_Query();
        }

        return $this->query;
    }

    /**
     * The search log writer.
     *
     * @since 1.0.0
     *
     * @return Licensio_KB_Search_Log The log writer.
     */
    public function log() {
        if ( ! $this->log instanceof Licensio_KB_Search_Log ) {
            $this->log = new Licensio_KB_Search_Log();
        }

        return $this->log;
    }

    /**
     * The block and shortcode renderer.
     *
     * @since 1.0.0
     *
     * @return Licensio_KB_Search_Block The renderer.
     */
    public function block() {
        if ( ! $this->block instanceof Licensio_KB_Search_Block ) {
            $this->block = new Licensio_KB_Search_Block();
        }

        return $this->block;
    }
}
