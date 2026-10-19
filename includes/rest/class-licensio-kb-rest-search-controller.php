<?php
/**
 * The search REST endpoint.
 *
 * `GET /wp-json/licensio-kb/v1/search` — public, rate limited per visitor
 * hash, and the only interface the type-ahead has to the search stack.
 *
 * The endpoint is public because documentation is meant to be findable, which
 * makes the rate limit the control that matters here: without one, an
 * unauthenticated caller could flood `licensio_search_log` with a term of their
 * choosing and poison every "top searches" and "documentation gap" report the
 * analytics dashboard produces. The limit is keyed on the privacy-safe visitor
 * hash, never on an IP address.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Serves knowledge base search results over REST.
 *
 * @since 1.0.0
 */
class Licensio_KB_REST_Search_Controller extends Licensio_KB_REST_Controller {

    /**
     * The route base, below the plugin namespace.
     *
     * @since 1.0.0
     * @var string
     */
    const REST_BASE = 'search';

    /**
     * The rate-limit bucket this endpoint counts against.
     *
     * @since 1.0.0
     * @var string
     */
    const RATE_BUCKET = 'search';

    /**
     * Searches permitted per visitor per window.
     *
     * Generous, because a 250 ms-debounced type-ahead legitimately issues one
     * request per word a visitor types.
     *
     * @since 1.0.0
     * @var int
     */
    const RATE_LIMIT = 60;

    /**
     * The rate-limit window, in seconds.
     *
     * @since 1.0.0
     * @var int
     */
    const RATE_WINDOW = 60;

    /**
     * The search runner.
     *
     * @since 1.0.0
     * @var Licensio_KB_Search_Query
     */
    private $query;

    /**
     * The search log writer.
     *
     * @since 1.0.0
     * @var Licensio_KB_Search_Log
     */
    private $log;

    /**
     * Constructor.
     *
     * @since 1.0.0
     *
     * @param Licensio_KB_Search_Query|null $query The search runner.
     * @param Licensio_KB_Search_Log|null   $log   The search log writer.
     */
    public function __construct( $query = null, $log = null ) {
        $this->rest_base = self::REST_BASE;
        $this->query     = $query instanceof Licensio_KB_Search_Query ? $query : new Licensio_KB_Search_Query();
        $this->log       = $log instanceof Licensio_KB_Search_Log ? $log : new Licensio_KB_Search_Log();
    }

    /**
     * Register the route.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function register_routes() {
        register_rest_route(
            $this->namespace,
            '/' . self::REST_BASE,
            array(
                array(
                    'methods'             => 'GET',
                    'callback'            => array( $this, 'get_items' ),
                    'permission_callback' => array( $this, 'search_permissions_check' ),
                    'args'                => $this->get_collection_params(),
                ),
                'schema' => array( $this, 'get_public_item_schema' ),
            )
        );
    }

    /**
     * The accepted query parameters.
     *
     * @since 1.0.0
     *
     * @return array<string, array<string, mixed>> The parameter definitions.
     */
    public function get_collection_params() {
        return array(
            'term'     => array(
                'description'       => __( 'The text to search for.', 'licensio-knowledge-base' ),
                'type'              => 'string',
                'required'          => true,
                'sanitize_callback' => array( 'Licensio_KB_Search_Query', 'sanitize_term' ),
            ),
            'page'     => array(
                'description' => __( 'Which page of results to return.', 'licensio-knowledge-base' ),
                'type'        => 'integer',
                'default'     => 1,
                'minimum'     => 1,
            ),
            'per_page' => array(
                'description' => __( 'How many results to return per page.', 'licensio-knowledge-base' ),
                'type'        => 'integer',
                'default'     => $this->default_per_page(),
                'minimum'     => 1,
                'maximum'     => 50,
            ),
            'source'   => array(
                'description' => __( 'Where the search was made from.', 'licensio-knowledge-base' ),
                'type'        => 'string',
                'default'     => Licensio_KB_Search_Log::DEFAULT_SOURCE,
                'enum'        => Licensio_KB_Search_Log::SOURCES,
            ),
        );
    }

    /**
     * The response schema for one search result.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed> The schema.
     */
    public function get_item_schema() {
        if ( ! empty( $this->schema ) ) {
            return $this->add_additional_fields_schema( $this->schema );
        }

        $this->schema = array(
            '$schema'    => 'http://json-schema.org/draft-04/schema#',
            'title'      => 'licensio-kb-search-result',
            'type'       => 'object',
            'properties' => array(
                'id'       => array(
                    'description' => __( 'The article ID.', 'licensio-knowledge-base' ),
                    'type'        => 'integer',
                    'context'     => array( 'view' ),
                ),
                'title'    => array(
                    'description' => __( 'The article title.', 'licensio-knowledge-base' ),
                    'type'        => 'string',
                    'context'     => array( 'view' ),
                ),
                'excerpt'  => array(
                    'description' => __( 'A short plain-text summary of the article.', 'licensio-knowledge-base' ),
                    'type'        => 'string',
                    'context'     => array( 'view' ),
                ),
                'url'      => array(
                    'description' => __( 'The article permalink.', 'licensio-knowledge-base' ),
                    'type'        => 'string',
                    'format'      => 'uri',
                    'context'     => array( 'view' ),
                ),
                'category' => array(
                    'description' => __( 'The article primary category.', 'licensio-knowledge-base' ),
                    'type'        => 'object',
                    'context'     => array( 'view' ),
                ),
                'snippet'  => array(
                    'description' => __( 'An extract around the match, with the matched words wrapped in mark tags.', 'licensio-knowledge-base' ),
                    'type'        => 'string',
                    'context'     => array( 'view' ),
                ),
            ),
        );

        return $this->add_additional_fields_schema( $this->schema );
    }

    /**
     * Permission callback for the search route.
     *
     * Public, then rate limited. The order matters: the budget is spent by
     * anyone who reaches the endpoint, which is what makes it a control over an
     * anonymous caller rather than over a session.
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request The request.
     *
     * @return true|WP_Error True when the caller may search, a 429 WP_Error when they are over budget.
     */
    public function search_permissions_check( $request ) {
        $public = $this->public_permissions_check( $request );

        if ( is_wp_error( $public ) ) {
            return $public;
        }

        /**
         * Filter how many searches a visitor may run per window.
         *
         * @since 1.0.0
         *
         * @param int $limit The request budget.
         */
        $limit = (int) apply_filters( 'licensio_search_rate_limit', self::RATE_LIMIT );

        return $this->check_rate_limit( self::RATE_BUCKET, $limit, self::RATE_WINDOW );
    }

    /**
     * Run a search and return the results.
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request The request.
     *
     * @return WP_REST_Response The results, with paging headers.
     */
    public function get_items( $request ) {
        $term     = (string) $request->get_param( 'term' );
        $page     = max( 1, (int) $request->get_param( 'page' ) );
        $per_page = max( 1, (int) $request->get_param( 'per_page' ) );
        $source   = Licensio_KB_Search_Log::sanitize_source( $request->get_param( 'source' ) );

        $found = $this->query->search(
            $term,
            array(
                'limit'  => $per_page,
                'offset' => ( $page - 1 ) * $per_page,
            )
        );

        $items = array();

        foreach ( $this->query->hydrate( $found['ids'] ) as $post ) {
            $items[] = $this->prepare_result( $post, $found['tokens'] );
        }

        /*
         * Only the first page is logged. A visitor paging through one set of
         * results has run one search, and counting each page separately would
         * inflate every "top searches" figure by however deep people browse.
         */
        if ( 1 === $page ) {
            $this->log->record( $found['term'], (int) $found['total'], $source );
        }

        $response = new WP_REST_Response( $items );

        $response->header( 'X-WP-Total', (string) (int) $found['total'] );
        $response->header( 'X-WP-TotalPages', (string) (int) ceil( $found['total'] / $per_page ) );
        $response->header( 'X-Licensio-Search-Driver', (string) $found['driver'] );

        return $response;
    }

    /**
     * Shape one article into a search result.
     *
     * @since 1.0.0
     *
     * @param WP_Post  $post   The matched article.
     * @param string[] $tokens The query tokens.
     *
     * @return array<string, mixed> The result.
     */
    public function prepare_result( $post, array $tokens ) {
        $excerpt = Licensio_KB_Search_Index::plain_text(
            '' !== (string) $post->post_excerpt ? $post->post_excerpt : $post->post_content
        );

        return array(
            'id'       => (int) $post->ID,
            'title'    => wp_strip_all_tags( get_the_title( $post ) ),
            'excerpt'  => self::trim_excerpt( $excerpt ),
            'url'      => (string) get_permalink( $post ),
            'category' => $this->primary_category( $post ),
            'snippet'  => Licensio_KB_Search_Snippet::build( $excerpt, $tokens ),
        );
    }

    /**
     * The category shown against a result.
     *
     * The first assigned term wins, which for a hierarchical taxonomy listed in
     * name order is stable enough for a result row and costs one query.
     *
     * @since 1.0.0
     *
     * @param WP_Post $post The article.
     *
     * @return array{id: int, name: string, url: string} The category, or empty values when the article has none.
     */
    private function primary_category( $post ) {
        $empty = array(
            'id'   => 0,
            'name' => '',
            'url'  => '',
        );

        $terms = get_the_terms( $post, Licensio_KB_Post_Types::TAXONOMY_CATEGORY );

        if ( is_wp_error( $terms ) || empty( $terms ) ) {
            return $empty;
        }

        $term = reset( $terms );
        $link = get_term_link( $term );

        return array(
            'id'   => (int) $term->term_id,
            'name' => (string) $term->name,
            'url'  => is_wp_error( $link ) ? '' : (string) $link,
        );
    }

    /**
     * Cut a plain-text excerpt to a readable length.
     *
     * @since 1.0.0
     *
     * @param string $excerpt The plain-text excerpt.
     *
     * @return string The trimmed excerpt.
     */
    private static function trim_excerpt( $excerpt ) {
        $excerpt = (string) $excerpt;

        if ( function_exists( 'mb_strlen' ) && mb_strlen( $excerpt ) > 200 ) {
            return trim( mb_substr( $excerpt, 0, 200 ) ) . '…';
        }

        if ( ! function_exists( 'mb_strlen' ) && strlen( $excerpt ) > 200 ) {
            return trim( substr( $excerpt, 0, 200 ) ) . '…';
        }

        return $excerpt;
    }

    /**
     * The configured result count for a page of search results.
     *
     * @since 1.0.0
     *
     * @return int The default `per_page`.
     */
    private function default_per_page() {
        $settings = new Licensio_KB_Settings();

        return max( 1, min( 50, (int) $settings->get( 'search_results_limit', 10 ) ) );
    }
}
