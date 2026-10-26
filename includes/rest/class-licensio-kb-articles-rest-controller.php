<?php
/**
 * The Articles app's REST routes.
 *
 * Everything the Articles app reads and writes, in the plugin's own
 * `licensio-kb/v1` namespace:
 *
 * - `GET articles`: one page of the list, with status, search, category
 *   (including its children), tag, author, date range, Uncategorised, No tags
 *   and Needs attention filters, sorting by date, modified, title, views or
 *   rating, and the views and rating over a 7, 30 or 90 day window. Answers
 *   `{ items, total, pages, counts }`.
 * - `GET articles/ids`: every ID matching the same filters, capped at 10,000,
 *   for "select all matching".
 * - `PATCH articles/{id}`: quick edit. 409 when another user holds the lock.
 * - `POST articles/bulk` and `POST articles/revert`: batched bulk actions and
 *   their undo, through Licensio_KB_Article_Bulk.
 * - `GET authors`: the users who can edit articles.
 * - `GET` and `PUT preferences`: the current user's columns and page size.
 *
 * The list runs through WP_Query, so core's status, search, taxonomy and date
 * handling apply. The views and rating joins come from
 * Licensio_KB_Article_Metrics through `posts_clauses`, and one page's metrics
 * and attachment counts are read in one grouped query each. Term, meta and
 * user caches are primed, so a page costs the same number of queries
 * whatever its size.
 *
 * Term names and titles are returned as plain text: core stores `&` as
 * `&amp;`, and the app renders text, never HTML.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Serves the Articles app.
 *
 * @since 1.0.0
 */
class Licensio_KB_Articles_REST_Controller extends Licensio_KB_REST_Controller {

    /**
     * The route base.
     *
     * @since 1.0.0
     * @var string
     */
    protected $rest_base = 'articles';

    /**
     * The most rows one list page returns.
     *
     * @since 1.0.0
     * @var int
     */
    const MAX_PER_PAGE = 100;

    /**
     * The most IDs `GET articles/ids` returns.
     *
     * @since 1.0.0
     * @var int
     */
    const IDS_CAP = 10000;

    /**
     * The user meta key holding the list preferences.
     *
     * @since 1.0.0
     * @var string
     */
    const PREFERENCES_META = 'licensio_kb_article_list_prefs';

    /**
     * The optional columns, in table order.
     *
     * @since 1.0.0
     * @var string[]
     */
    const COLUMNS = array( 'author', 'categories', 'tags', 'attachments', 'rating', 'views', 'reading_time', 'date', 'modified' );

    /**
     * The columns shown until a user picks their own.
     *
     * @since 1.0.0
     * @var string[]
     */
    const DEFAULT_COLUMNS = array( 'author', 'categories', 'tags', 'attachments', 'rating', 'views', 'reading_time', 'date' );

    /**
     * The page sizes a user may pick.
     *
     * @since 1.0.0
     * @var int[]
     */
    const PER_PAGE_OPTIONS = array( 20, 50, 100 );

    /**
     * The statuses "All" covers, as in core: everything but the trash.
     *
     * @since 1.0.0
     * @var string[]
     */
    const LIST_STATUSES = array( 'publish', 'future', 'draft', 'pending', 'private' );

    /**
     * The sort keys.
     *
     * @since 1.0.0
     * @var string[]
     */
    const ORDERBY = array( 'date', 'modified', 'title', 'views', 'rating' );

    /**
     * The views and rating SQL.
     *
     * @since 1.0.0
     * @var Licensio_KB_Article_Metrics
     */
    private $metrics;

    /**
     * The bulk action runner.
     *
     * @since 1.0.0
     * @var Licensio_KB_Article_Bulk
     */
    private $bulk;

    /**
     * Build the controller.
     *
     * @since 1.0.0
     *
     * @param Licensio_KB_Article_Metrics|null $metrics Optional metrics helper; defaults to one built from the plugin settings.
     * @param Licensio_KB_Article_Bulk|null    $bulk    Optional bulk runner.
     */
    public function __construct( $metrics = null, $bulk = null ) {
        $this->metrics = $metrics instanceof Licensio_KB_Article_Metrics ? $metrics : Licensio_KB_Article_Metrics::from_settings( licensio_kb()->settings() );
        $this->bulk    = $bulk instanceof Licensio_KB_Article_Bulk ? $bulk : new Licensio_KB_Article_Bulk();
    }

    /**
     * Register the routes.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function register_routes() {
        $manage = array( $this, 'manage_permissions_check' );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            array(
                array(
                    'methods'             => 'GET',
                    'callback'            => array( $this, 'get_items' ),
                    'permission_callback' => $manage,
                    'args'                => $this->get_list_args(),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/ids',
            array(
                array(
                    'methods'             => 'GET',
                    'callback'            => array( $this, 'get_ids' ),
                    'permission_callback' => $manage,
                    'args'                => $this->get_filter_args(),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id>[\d]+)',
            array(
                array(
                    'methods'             => 'PATCH',
                    'callback'            => array( $this, 'update_item' ),
                    'permission_callback' => array( $this, 'update_item_permissions_check' ),
                    'args'                => $this->get_update_args(),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/bulk',
            array(
                array(
                    'methods'             => 'POST',
                    'callback'            => array( $this, 'bulk_items' ),
                    'permission_callback' => $manage,
                    'args'                => $this->get_bulk_args(),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/revert',
            array(
                array(
                    'methods'             => 'POST',
                    'callback'            => array( $this, 'revert_items' ),
                    'permission_callback' => $manage,
                    'args'                => $this->get_revert_args(),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/authors',
            array(
                array(
                    'methods'             => 'GET',
                    'callback'            => array( $this, 'get_authors' ),
                    'permission_callback' => $manage,
                    'args'                => $this->get_authors_args(),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/preferences',
            array(
                array(
                    'methods'             => 'GET',
                    'callback'            => array( $this, 'get_preferences' ),
                    'permission_callback' => $manage,
                ),
                array(
                    'methods'             => 'PUT',
                    'callback'            => array( $this, 'update_preferences' ),
                    'permission_callback' => $manage,
                    'args'                => $this->get_preferences_args(),
                ),
            )
        );
    }

    /**
     * The filters `GET articles` and `GET articles/ids` share.
     *
     * @since 1.0.0
     *
     * @return array<string, array<string, mixed>> The argument schema.
     */
    public function get_filter_args() {
        $term = array(
            'type'              => 'integer',
            'minimum'           => 0,
            'default'           => 0,
            'sanitize_callback' => 'absint',
        );
        $flag = array(
            'type'    => 'boolean',
            'default' => false,
        );
        $date = array(
            'type'              => 'string',
            'default'           => '',
            'validate_callback' => array( __CLASS__, 'validate_day' ),
        );

        return array(
            'status'          => array(
                'type'        => 'string',
                'enum'        => array_merge( array( 'all' ), self::LIST_STATUSES, array( 'trash' ) ),
                'default'     => 'all',
                'description' => __( 'One status, or all but the trash.', 'licensio-knowledge-base' ),
            ),
            'search'          => array(
                'type'              => 'string',
                'default'           => '',
                'sanitize_callback' => 'sanitize_text_field',
                'description'       => __( 'Limit the articles to titles containing this text.', 'licensio-knowledge-base' ),
            ),
            'category'        => array_merge( $term, array( 'description' => __( 'A category ID; its children are included.', 'licensio-knowledge-base' ) ) ),
            'tag'             => array_merge( $term, array( 'description' => __( 'A tag ID.', 'licensio-knowledge-base' ) ) ),
            'author'          => array_merge( $term, array( 'description' => __( 'An author user ID.', 'licensio-knowledge-base' ) ) ),
            'after'           => array_merge( $date, array( 'description' => __( 'Published on or after this day, YYYY-MM-DD.', 'licensio-knowledge-base' ) ) ),
            'before'          => array_merge( $date, array( 'description' => __( 'Published on or before this day, YYYY-MM-DD.', 'licensio-knowledge-base' ) ) ),
            'uncategorised'   => array_merge( $flag, array( 'description' => __( 'Only articles with no category.', 'licensio-knowledge-base' ) ) ),
            'untagged'        => array_merge( $flag, array( 'description' => __( 'Only articles with no tag.', 'licensio-knowledge-base' ) ) ),
            'needs_attention' => array_merge( $flag, array( 'description' => __( 'Only articles whose helpful share in the window is below the low rating threshold.', 'licensio-knowledge-base' ) ) ),
            'window'          => array(
                'type'        => 'integer',
                'enum'        => Licensio_KB_Article_Metrics::WINDOWS,
                'default'     => Licensio_KB_Article_Metrics::DEFAULT_WINDOW,
                'description' => __( 'The days the views and rating cover.', 'licensio-knowledge-base' ),
            ),
        );
    }

    /**
     * The arguments of `GET articles`: the filters plus sort and paging.
     *
     * @since 1.0.0
     *
     * @return array<string, array<string, mixed>> The argument schema.
     */
    public function get_list_args() {
        return array_merge(
            $this->get_filter_args(),
            array(
                'orderby'  => array(
                    'type'    => 'string',
                    'enum'    => self::ORDERBY,
                    'default' => 'date',
                ),
                'order'    => array(
                    'type'    => 'string',
                    'enum'    => array( 'asc', 'desc' ),
                    'default' => 'desc',
                ),
                'page'     => array(
                    'type'    => 'integer',
                    'minimum' => 1,
                    'default' => 1,
                ),
                'per_page' => array(
                    'type'    => 'integer',
                    'minimum' => 1,
                    'maximum' => self::MAX_PER_PAGE,
                    'default' => 20,
                ),
            )
        );
    }

    /**
     * The arguments of `PATCH articles/{id}`. Only the fields sent change.
     *
     * @since 1.0.0
     *
     * @return array<string, array<string, mixed>> The argument schema.
     */
    public function get_update_args() {
        return array(
            'id'             => array(
                'type'     => 'integer',
                'minimum'  => 1,
                'required' => true,
            ),
            // Not sanitised here: wp_update_post() applies core's title filters,
            // which strip markup only for users without unfiltered_html.
            'title'          => array(
                'type' => 'string',
            ),
            // One argument: the REST server passes the request and key too,
            // which sanitize_title() would take as its fallback and context.
            'slug'           => array(
                'type'              => 'string',
                'sanitize_callback' => array( $this, 'sanitize_slug' ),
            ),
            'status'         => array(
                'type' => 'string',
                'enum' => self::LIST_STATUSES,
            ),
            'date'           => array(
                'type'              => 'string',
                'validate_callback' => array( __CLASS__, 'validate_datetime' ),
            ),
            'password'       => array(
                'type'      => 'string',
                'maxLength' => 255,
            ),
            'categories'     => array(
                'type'  => 'array',
                'items' => array(
                    'type'    => 'integer',
                    'minimum' => 1,
                ),
            ),
            'tags'           => array(
                'type'              => 'array',
                'validate_callback' => array( __CLASS__, 'validate_tag_values' ),
            ),
            'author'         => array(
                'type'    => 'integer',
                'minimum' => 1,
            ),
            'comment_status' => array(
                'type' => 'string',
                'enum' => array( 'open', 'closed' ),
            ),
            'window'         => array(
                'type'    => 'integer',
                'enum'    => Licensio_KB_Article_Metrics::WINDOWS,
                'default' => Licensio_KB_Article_Metrics::DEFAULT_WINDOW,
            ),
        );
    }

    /**
     * The arguments of `POST articles/bulk`.
     *
     * @since 1.0.0
     *
     * @return array<string, array<string, mixed>> The argument schema.
     */
    public function get_bulk_args() {
        return array(
            'ids'    => array(
                'type'     => 'array',
                'required' => true,
                'minItems' => 1,
                'maxItems' => Licensio_KB_Article_Bulk::MAX_IDS,
                'items'    => array(
                    'type'    => 'integer',
                    'minimum' => 1,
                ),
            ),
            'action' => array(
                'type'     => 'string',
                'required' => true,
                'enum'     => Licensio_KB_Article_Bulk::ACTIONS,
            ),
            'args'   => array(
                'type'    => 'object',
                'default' => array(),
            ),
        );
    }

    /**
     * The arguments of `POST articles/revert`.
     *
     * @since 1.0.0
     *
     * @return array<string, array<string, mixed>> The argument schema.
     */
    public function get_revert_args() {
        return array(
            'previous'    => array(
                'type'     => 'object',
                'required' => true,
            ),
            'snapshot_at' => array(
                'type'     => 'integer',
                'required' => true,
            ),
            'token'       => array(
                'type'     => 'string',
                'required' => true,
            ),
        );
    }

    /**
     * The arguments of `GET authors`.
     *
     * @since 1.0.0
     *
     * @return array<string, array<string, mixed>> The argument schema.
     */
    public function get_authors_args() {
        return array(
            'search'   => array(
                'type'              => 'string',
                'default'           => '',
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'include'  => array(
                'type'     => 'array',
                'default'  => array(),
                'maxItems' => self::MAX_PER_PAGE,
                'items'    => array(
                    'type'    => 'integer',
                    'minimum' => 1,
                ),
            ),
            'page'     => array(
                'type'    => 'integer',
                'minimum' => 1,
                'default' => 1,
            ),
            'per_page' => array(
                'type'    => 'integer',
                'minimum' => 1,
                'maximum' => self::MAX_PER_PAGE,
                'default' => 20,
            ),
        );
    }

    /**
     * The arguments of `PUT preferences`.
     *
     * @since 1.0.0
     *
     * @return array<string, array<string, mixed>> The argument schema.
     */
    public function get_preferences_args() {
        return array(
            'columns'  => array(
                'type'     => 'array',
                'required' => true,
                'items'    => array(
                    'type' => 'string',
                    'enum' => self::COLUMNS,
                ),
            ),
            'per_page' => array(
                'type'     => 'integer',
                'required' => true,
                'enum'     => self::PER_PAGE_OPTIONS,
            ),
        );
    }

    /**
     * Validate a `YYYY-MM-DD` day, or an empty string for none.
     *
     * @since 1.0.0
     *
     * @param mixed $value The value.
     *
     * @return true|WP_Error True when valid.
     */
    public static function validate_day( $value ) {
        if ( '' === $value || null === $value ) {
            return true;
        }

        if ( Licensio_KB_REST_Analytics_Controller::is_valid_date( $value ) ) {
            return true;
        }

        return new WP_Error( 'rest_invalid_param', __( 'Use a date in the form YYYY-MM-DD.', 'licensio-knowledge-base' ), array( 'status' => 400 ) );
    }

    /**
     * Validate a publish date core can parse.
     *
     * @since 1.0.0
     *
     * @param mixed $value The value.
     *
     * @return true|WP_Error True when valid.
     */
    public static function validate_datetime( $value ) {
        if ( is_string( $value ) && false !== rest_parse_date( $value ) ) {
            return true;
        }

        return new WP_Error( 'rest_invalid_param', __( 'Use a date and time in the form YYYY-MM-DDTHH:MM:SS.', 'licensio-knowledge-base' ), array( 'status' => 400 ) );
    }

    /**
     * Validate a tag list: term IDs, or `{ name }` for a tag to create.
     *
     * A new name is checked as it will be saved, so a name that is only
     * markup is refused here rather than failing when the tag is created.
     *
     * @since 1.0.0
     *
     * @param mixed $value The value.
     *
     * @return true|WP_Error True when valid.
     */
    public static function validate_tag_values( $value ) {
        if ( ! is_array( $value ) ) {
            return new WP_Error( 'rest_invalid_param', __( 'Tags must be a list.', 'licensio-knowledge-base' ), array( 'status' => 400 ) );
        }

        foreach ( $value as $tag ) {
            $is_id   = ( is_int( $tag ) && $tag > 0 ) || ( is_string( $tag ) && ctype_digit( $tag ) && (int) $tag > 0 );
            $is_name = is_array( $tag ) && isset( $tag['name'] ) && is_string( $tag['name'] ) && '' !== sanitize_text_field( $tag['name'] );

            if ( ! $is_id && ! $is_name ) {
                return new WP_Error( 'rest_invalid_param', __( 'Each tag must be a tag ID or a new tag name.', 'licensio-knowledge-base' ), array( 'status' => 400 ) );
            }
        }

        return true;
    }

    /**
     * The WP_Query arguments for a set of list filters.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $params  The request parameters, validated against the schema.
     * @param bool                 $ids_only True for `GET articles/ids`: every match, as IDs, unsorted by metrics.
     *
     * @return array<string, mixed> The query arguments.
     */
    public function query_args( array $params, $ids_only = false ) {
        $status  = isset( $params['status'] ) ? (string) $params['status'] : 'all';
        $order   = isset( $params['order'] ) && 'asc' === $params['order'] ? 'ASC' : 'DESC';
        $orderby = isset( $params['orderby'] ) && in_array( $params['orderby'], self::ORDERBY, true ) ? (string) $params['orderby'] : 'date';

        $args = array(
            'post_type'           => Licensio_KB_Post_Types::POST_TYPE,
            'post_status'         => 'all' === $status ? self::LIST_STATUSES : array( $status ),
            'perm'                => 'readable',
            'ignore_sticky_posts' => true,
            'suppress_filters'    => false,
        );

        $search = isset( $params['search'] ) ? trim( (string) $params['search'] ) : '';

        if ( '' !== $search ) {
            $args['s']              = $search;
            $args['search_columns'] = array( 'post_title' );
        }

        if ( ! empty( $params['author'] ) ) {
            $args['author'] = (int) $params['author'];
        }

        $tax_query = $this->tax_query( $params );

        if ( ! empty( $tax_query ) ) {
            $args['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- The category, tag and missing-taxonomy filters are taxonomy queries by definition.
        }

        $date_query = self::date_query( $params );

        if ( ! empty( $date_query ) ) {
            $args['date_query'] = $date_query;
        }

        $metrics = array(
            'window'          => Licensio_KB_Article_Metrics::normalize_window( isset( $params['window'] ) ? $params['window'] : 0 ),
            'orderby'         => $ids_only ? '' : $orderby,
            'order'           => $order,
            'needs_attention' => ! empty( $params['needs_attention'] ),
        );

        if ( $metrics['needs_attention'] || in_array( $metrics['orderby'], array( 'views', 'rating' ), true ) ) {
            $args[ Licensio_KB_Article_Metrics::QUERY_VAR ] = $metrics;
        }

        if ( $ids_only ) {
            return array_merge(
                $args,
                array(
                    'fields'                 => 'ids',
                    'posts_per_page'         => self::IDS_CAP,
                    'paged'                  => 1,
                    'orderby'                => array( 'ID' => 'DESC' ),
                    'update_post_term_cache' => false,
                    'update_post_meta_cache' => false,
                )
            );
        }

        $fields = array(
            'date'     => 'date',
            'modified' => 'modified',
            'title'    => 'title',
        );

        return array_merge(
            $args,
            array(
                'posts_per_page'         => min( self::MAX_PER_PAGE, max( 1, isset( $params['per_page'] ) ? (int) $params['per_page'] : 20 ) ),
                'paged'                  => max( 1, isset( $params['page'] ) ? (int) $params['page'] : 1 ),

                // Views and rating replace this order through posts_clauses.
                'orderby'                => isset( $fields[ $orderby ] ) ? array(
                    $fields[ $orderby ] => $order,
                    'ID'                => $order,
                ) : array( 'ID' => $order ),
                'update_post_term_cache' => true,
                'update_post_meta_cache' => true,
            )
        );
    }

    /**
     * The taxonomy part of the list query.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $params The request parameters.
     *
     * @return array<int|string, mixed> The tax_query, or an empty array for none.
     */
    private function tax_query( array $params ) {
        $clauses = array();

        if ( ! empty( $params['category'] ) ) {
            $clauses[] = array(
                'taxonomy'         => Licensio_KB_Post_Types::TAXONOMY_CATEGORY,
                'field'            => 'term_id',
                'terms'            => array( (int) $params['category'] ),
                'include_children' => true,
            );
        }

        if ( ! empty( $params['tag'] ) ) {
            $clauses[] = array(
                'taxonomy'         => Licensio_KB_Post_Types::TAXONOMY_TAG,
                'field'            => 'term_id',
                'terms'            => array( (int) $params['tag'] ),
                'include_children' => false,
            );
        }

        if ( ! empty( $params['uncategorised'] ) ) {
            $clauses[] = array(
                'taxonomy' => Licensio_KB_Post_Types::TAXONOMY_CATEGORY,
                'operator' => 'NOT EXISTS',
            );
        }

        if ( ! empty( $params['untagged'] ) ) {
            $clauses[] = array(
                'taxonomy' => Licensio_KB_Post_Types::TAXONOMY_TAG,
                'operator' => 'NOT EXISTS',
            );
        }

        if ( empty( $clauses ) ) {
            return array();
        }

        return array_merge( array( 'relation' => 'AND' ), $clauses );
    }

    /**
     * The date part of the list query: inclusive whole days.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $params The request parameters.
     *
     * @return array<int, array<string, mixed>> The date_query, or an empty array for none.
     */
    private static function date_query( array $params ) {
        $clause = array();

        foreach ( array( 'after', 'before' ) as $bound ) {
            $day = isset( $params[ $bound ] ) ? (string) $params[ $bound ] : '';

            if ( ! Licensio_KB_REST_Analytics_Controller::is_valid_date( $day ) ) {
                continue;
            }

            list( $year, $month, $date ) = array_map( 'intval', explode( '-', $day ) );

            $clause[ $bound ] = array(
                'year'  => $year,
                'month' => $month,
                'day'   => $date,
            );
        }

        if ( empty( $clause ) ) {
            return array();
        }

        $clause['column']    = 'post_date';
        $clause['inclusive'] = true;

        return array( $clause );
    }

    /**
     * Run an article query with the metrics joins attached.
     *
     * Protected so a test can stand in for WP_Query, which needs a loaded
     * WordPress.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $args The query arguments.
     *
     * @return WP_Query The finished query.
     */
    protected function run_query( array $args ) {
        $filter = array( $this->metrics, 'filter_clauses' );

        add_filter( 'posts_clauses', $filter, 10, 2 );
        $query = new WP_Query( $args );
        remove_filter( 'posts_clauses', $filter, 10 );

        return $query;
    }

    /**
     * One page of the article list.
     *
     * WP_Query leaves `found_posts` at 0 when the page asked for is past the
     * end, so the matches are then counted again from the first page. The
     * total and page count stay right, and the app can step back to the
     * last page.
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request The request.
     *
     * @return WP_REST_Response|WP_Error The page.
     */
    public function get_items( $request ) {
        $params = $request->get_params();
        $args   = $this->query_args( $params );
        $query  = $this->run_query( $args );
        $posts  = array_values(
            array_filter(
                (array) $query->posts,
                function ( $post ) {
                    return $post instanceof WP_Post;
                }
            )
        );
        $window = Licensio_KB_Article_Metrics::normalize_window( isset( $params['window'] ) ? $params['window'] : 0 );
        $total  = (int) $query->found_posts;

        if ( ! $posts && $args['paged'] > 1 ) {
            $total = (int) $this->run_query(
                array_merge(
                    $args,
                    array(
                        'fields'         => 'ids',
                        'posts_per_page' => 1,
                        'paged'          => 1,
                    )
                )
            )->found_posts;
        }

        return rest_ensure_response(
            array(
                'items'  => $this->prepare_items( $posts, $window ),
                'total'  => $total,
                'pages'  => (int) ceil( $total / (int) $args['posts_per_page'] ),
                'counts' => self::status_counts(),
            )
        );
    }

    /**
     * Every ID matching the filters, for "select all matching".
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request The request.
     *
     * @return WP_REST_Response|WP_Error The IDs, the full match count, and whether the IDs were cut off.
     */
    public function get_ids( $request ) {
        $query = $this->run_query( $this->query_args( $request->get_params(), true ) );
        $ids   = array_map( 'intval', (array) $query->posts );
        $total = (int) $query->found_posts;

        return rest_ensure_response(
            array(
                'ids'       => $ids,
                'total'     => $total,
                'truncated' => $total > count( $ids ),
            )
        );
    }

    /**
     * The status counts the pills show.
     *
     * Read from `wp_count_posts()`, which core caches, so they match core's
     * own list. "All" adds up every status but the trash, as core does.
     *
     * @since 1.0.0
     *
     * @return array<string, int> Status to count, plus `all`.
     */
    public static function status_counts() {
        $counted = (array) wp_count_posts( Licensio_KB_Post_Types::POST_TYPE, 'readable' );
        $counts  = array( 'all' => 0 );

        foreach ( array_merge( self::LIST_STATUSES, array( 'trash' ) ) as $status ) {
            $counts[ $status ] = isset( $counted[ $status ] ) ? (int) $counted[ $status ] : 0;

            if ( 'trash' !== $status ) {
                $counts['all'] += $counts[ $status ];
            }
        }

        return $counts;
    }

    /**
     * Turn a page of posts into list items.
     *
     * The caches every item reads from are filled first, so the cost is a
     * fixed number of queries: the metrics (two), the attachment counts
     * (one) and the users (one).
     *
     * @since 1.0.0
     *
     * @param WP_Post[] $posts  The posts, with their term and meta caches primed.
     * @param int       $window The metrics window, in days.
     *
     * @return array<int, array<string, mixed>> The items.
     */
    public function prepare_items( array $posts, $window ) {
        if ( empty( $posts ) ) {
            return array();
        }

        $ids         = array_map( 'intval', wp_list_pluck( $posts, 'ID' ) );
        $figures     = $this->metrics->for_articles( $ids, $window );
        $attachments = $this->metrics->attachment_counts( $ids );
        $locks       = array();

        foreach ( $ids as $id ) {
            $locks[ $id ] = Licensio_KB_Article_Bulk::lock_holder( $id );
        }

        $users = array_merge( array_map( 'intval', wp_list_pluck( $posts, 'post_author' ) ), array_values( $locks ) );

        cache_users( array_values( array_unique( array_filter( $users ) ) ) );

        $items = array();

        foreach ( $posts as $post ) {
            $id = (int) $post->ID;

            $items[] = $this->prepare_article(
                $post,
                isset( $figures[ $id ] ) ? $figures[ $id ] : array(
                    'views' => 0,
                    'up'    => 0,
                    'down'  => 0,
                    'share' => null,
                ),
                isset( $attachments[ $id ] ) ? $attachments[ $id ] : 0,
                $locks[ $id ]
            );
        }

        return $items;
    }

    /**
     * One list item.
     *
     * @since 1.0.0
     *
     * @param WP_Post              $post        The article.
     * @param array<string, mixed> $figures     Its views and votes in the window.
     * @param int                  $attachments Its attachment count.
     * @param int                  $lock_user   The user holding its lock, or 0.
     *
     * @return array<string, mixed> The item.
     */
    private function prepare_article( $post, array $figures, $attachments, $lock_user ) {
        $id     = (int) $post->ID;
        $author = get_userdata( (int) $post->post_author );
        $holder = $lock_user > 0 ? get_userdata( $lock_user ) : false;

        return array(
            'id'              => $id,
            'title'           => self::plain_text( $post->post_title ),
            'slug'            => (string) $post->post_name,
            'status'          => (string) $post->post_status,
            'date'            => self::iso_local( $post->post_date ),
            'modified'        => self::iso_local( $post->post_modified ),
            'author'          => array(
                'id'   => (int) $post->post_author,
                'name' => $author ? self::plain_text( $author->display_name ) : '',
            ),
            'categories'      => self::term_refs( $post, Licensio_KB_Post_Types::TAXONOMY_CATEGORY ),
            'tags'            => self::term_refs( $post, Licensio_KB_Post_Types::TAXONOMY_TAG ),
            'attachments'     => (int) $attachments,
            'reading_time'    => Licensio_KB_Admin_Columns::reading_time( $id ),
            'views'           => (int) $figures['views'],
            'rating'          => array(
                'up'    => (int) $figures['up'],
                'down'  => (int) $figures['down'],
                'share' => null === $figures['share'] ? null : (int) $figures['share'],
            ),
            'needs_attention' => $this->metrics->needs_attention( $figures ),
            'comment_status'  => (string) $post->comment_status,
            'has_password'    => '' !== (string) $post->post_password,
            'lock'            => $lock_user > 0 ? array(
                'user_id' => $lock_user,
                'name'    => $holder ? self::plain_text( $holder->display_name ) : '',
            ) : null,
            'edit_url'        => (string) get_edit_post_link( $id, 'raw' ),
            'view_url'        => (string) ( 'publish' === $post->post_status ? get_permalink( $post ) : get_preview_post_link( $post ) ),
        );
    }

    /**
     * An article's terms in one taxonomy, from the primed cache.
     *
     * @since 1.0.0
     *
     * @param WP_Post $post     The article.
     * @param string  $taxonomy The taxonomy.
     *
     * @return array<int, array{id: int, name: string}> The terms, names as plain text.
     */
    private static function term_refs( $post, $taxonomy ) {
        $terms = get_the_terms( $post, $taxonomy );

        if ( ! is_array( $terms ) ) {
            return array();
        }

        $refs = array();

        foreach ( $terms as $term ) {
            if ( $term instanceof WP_Term ) {
                $refs[] = array(
                    'id'   => (int) $term->term_id,
                    'name' => self::plain_text( $term->name ),
                );
            }
        }

        return $refs;
    }

    /**
     * Quick edit one article.
     *
     * Every field is checked before anything is written; field errors come
     * back together as a 400 with `data.params`, so the app can show each one
     * beside its field. New tags are created before the article is written,
     * so a tag that cannot be created leaves the article untouched. A slug
     * that collides is made unique by core.
     *
     * The title is deliberately not run through sanitize_text_field().
     * wp_update_post() applies core's title filters, which run kses on the
     * title for users without `unfiltered_html`, the same treatment the block
     * editor gives it. sanitize_text_field() would corrupt legitimate titles
     * on top of that: it stores a lone `<` as the `&lt;` entity and deletes
     * any `%` followed by two hex digits, as core's _sanitize_text_fields()
     * does for every value.
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request The request.
     *
     * @return WP_REST_Response|WP_Error The updated item.
     */
    public function update_item( $request ) {
        $id   = (int) $request->get_param( 'id' );
        $post = get_post( $id );

        if ( ! $post instanceof WP_Post || Licensio_KB_Post_Types::POST_TYPE !== $post->post_type ) {
            return self::not_found();
        }

        // Leaving the trash goes through Restore, so core's untrash hooks run.
        if ( 'trash' === $post->post_status ) {
            return new WP_Error( 'licensio_kb_article_trashed', __( 'Restore this article before editing it.', 'licensio-knowledge-base' ), array( 'status' => 400 ) );
        }

        $locked = $this->lock_error( $id );

        if ( is_wp_error( $locked ) ) {
            return $locked;
        }

        $changes = $this->collect_changes( $request, $post );

        if ( is_wp_error( $changes ) ) {
            return $changes;
        }

        list( $postarr, $terms ) = $changes;

        $term_ids = array();

        foreach ( $terms as $taxonomy => $term_values ) {
            $resolved = $this->resolve_terms( $taxonomy, $term_values );

            if ( is_wp_error( $resolved ) ) {
                return $resolved;
            }

            $term_ids[ $taxonomy ] = $resolved;
        }

        if ( count( $postarr ) > 1 ) {
            // wp_update_post() expects slashed data, as core's own callers send it.
            $updated = wp_update_post( wp_slash( $postarr ), true );

            if ( is_wp_error( $updated ) ) {
                return $updated;
            }
        }

        foreach ( $term_ids as $taxonomy => $ids ) {
            $set = wp_set_object_terms( $id, $ids, $taxonomy, false );

            if ( is_wp_error( $set ) ) {
                return $set;
            }
        }

        clean_post_cache( $id );
        update_object_term_cache( array( $id ), Licensio_KB_Post_Types::POST_TYPE );

        $items = $this->prepare_items( array( get_post( $id ) ), Licensio_KB_Article_Metrics::normalize_window( $request->get_param( 'window' ) ) );

        return rest_ensure_response( $items[0] );
    }

    /**
     * The 409 for an article another user is editing.
     *
     * @since 1.0.0
     *
     * @param int $id The article ID.
     *
     * @return WP_Error|null The error, or null when the article is free.
     */
    private function lock_error( $id ) {
        $holder = Licensio_KB_Article_Bulk::lock_holder( $id );

        if ( $holder < 1 ) {
            return null;
        }

        $user = get_userdata( $holder );
        $name = $user ? self::plain_text( $user->display_name ) : '';

        return new WP_Error(
            'licensio_kb_article_locked',
            /* translators: %s: the name of the user editing the article. */
            sprintf( __( '%s is editing this article.', 'licensio-knowledge-base' ), '' !== $name ? $name : __( 'Someone else', 'licensio-knowledge-base' ) ),
            array(
                'status' => 409,
                'lock'   => array(
                    'user_id' => $holder,
                    'name'    => $name,
                ),
            )
        );
    }

    /**
     * Check a quick edit and turn it into post fields and term changes.
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request The request.
     * @param WP_Post         $post    The article.
     *
     * @return array{0: array<string, mixed>, 1: array<string, array<int, mixed>>}|WP_Error The post fields (with `ID`) and the term lists by taxonomy, or the errors.
     */
    public function collect_changes( $request, $post ) {
        $id      = (int) $post->ID;
        $postarr = array( 'ID' => $id );
        $terms   = array();
        $errors  = array();

        if ( null !== $request->get_param( 'title' ) ) {
            $postarr['post_title'] = (string) $request->get_param( 'title' );
        }

        if ( null !== $request->get_param( 'slug' ) ) {
            $postarr['post_name'] = (string) $request->get_param( 'slug' );
        }

        $status = $request->get_param( 'status' );

        if ( null !== $status ) {
            $status = (string) $status;

            if ( $status !== $post->post_status && in_array( $status, array( 'publish', 'future', 'private' ), true ) && ! current_user_can( Licensio_KB_Article_Bulk::type_cap( 'publish_posts' ) ) ) {
                return self::forbidden( __( 'You are not allowed to publish this article.', 'licensio-knowledge-base' ) );
            }

            $postarr['post_status'] = $status;
        }

        $date = $request->get_param( 'date' );

        if ( null !== $date ) {
            $dates = rest_get_date_with_gmt( (string) $date );

            if ( empty( $dates ) ) {
                $errors['date'] = __( 'The date is not valid.', 'licensio-knowledge-base' );
            } else {
                $postarr['post_date']     = $dates[0];
                $postarr['post_date_gmt'] = $dates[1];
                $postarr['edit_date']     = true;
            }
        }

        $password = $request->get_param( 'password' );

        if ( null !== $password ) {
            $postarr['post_password'] = (string) $password;
        }

        $final_status   = isset( $postarr['post_status'] ) ? $postarr['post_status'] : $post->post_status;
        $final_password = isset( $postarr['post_password'] ) ? $postarr['post_password'] : (string) $post->post_password;

        if ( 'private' === $final_status && '' !== $final_password ) {
            $errors['password'] = __( 'A private article cannot also have a password.', 'licensio-knowledge-base' );
        }

        $author = $request->get_param( 'author' );

        if ( null !== $author && (int) $author !== (int) $post->post_author ) {
            if ( ! current_user_can( Licensio_KB_Article_Bulk::type_cap( 'edit_others_posts' ) ) ) {
                return self::forbidden( __( 'You are not allowed to change the author of this article.', 'licensio-knowledge-base' ) );
            }

            if ( ! self::is_author( (int) $author ) ) {
                $errors['author'] = __( 'That user cannot write articles.', 'licensio-knowledge-base' );
            } else {
                $postarr['post_author'] = (int) $author;
            }
        }

        if ( null !== $request->get_param( 'comment_status' ) ) {
            $postarr['comment_status'] = (string) $request->get_param( 'comment_status' );
        }

        $fields = array(
            'categories' => Licensio_KB_Post_Types::TAXONOMY_CATEGORY,
            'tags'       => Licensio_KB_Post_Types::TAXONOMY_TAG,
        );

        foreach ( $fields as $param => $taxonomy ) {
            $values = $request->get_param( $param );

            if ( null === $values ) {
                continue;
            }

            if ( ! current_user_can( Licensio_KB_Article_Bulk::taxonomy_cap( $taxonomy, 'assign_terms' ) ) ) {
                return self::forbidden( __( 'You are not allowed to assign these terms.', 'licensio-knowledge-base' ) );
            }

            $error = $this->check_terms( $taxonomy, (array) $values );

            if ( '' !== $error ) {
                $errors[ $param ] = $error;
                continue;
            }

            $terms[ $taxonomy ] = (array) $values;
        }

        if ( ! empty( $errors ) ) {
            return new WP_Error(
                'rest_invalid_param',
                /* translators: %s: the names of the fields with errors. */
                sprintf( __( 'Invalid parameter(s): %s', 'licensio-knowledge-base' ), implode( ', ', array_keys( $errors ) ) ),
                array(
                    'status' => 400,
                    'params' => $errors,
                )
            );
        }

        return array( $postarr, $terms );
    }

    /**
     * Check a term list: existing IDs in the right taxonomy, and new names
     * only where the user may create terms.
     *
     * @since 1.0.0
     *
     * @param string       $taxonomy The taxonomy.
     * @param array<mixed> $values   Term IDs, or `{ name }` for terms to create.
     *
     * @return string An error message, or an empty string when the list is valid.
     */
    private function check_terms( $taxonomy, array $values ) {
        foreach ( $values as $value ) {
            if ( is_array( $value ) ) {
                if ( Licensio_KB_Post_Types::TAXONOMY_TAG !== $taxonomy ) {
                    return __( 'Categories cannot be created here.', 'licensio-knowledge-base' );
                }

                if ( ! current_user_can( Licensio_KB_Article_Bulk::taxonomy_cap( $taxonomy, 'edit_terms' ) ) ) {
                    return __( 'You are not allowed to create tags.', 'licensio-knowledge-base' );
                }

                continue;
            }

            if ( ! term_exists( (int) $value, $taxonomy ) ) {
                return Licensio_KB_Post_Types::TAXONOMY_TAG === $taxonomy
                    ? __( 'One of the tags does not exist.', 'licensio-knowledge-base' )
                    : __( 'One of the categories does not exist.', 'licensio-knowledge-base' );
            }
        }

        return '';
    }

    /**
     * Turn a checked term list into IDs, creating the new tags.
     *
     * A new name that matches an existing tag uses that tag.
     *
     * @since 1.0.0 Slashes the new name so backslashes survive.
     *
     * @param string       $taxonomy The taxonomy.
     * @param array<mixed> $values   Term IDs, or `{ name }` for terms to create.
     *
     * @return int[]|WP_Error The term IDs.
     */
    private function resolve_terms( $taxonomy, array $values ) {
        $ids = array();

        foreach ( $values as $value ) {
            if ( ! is_array( $value ) ) {
                $ids[] = (int) $value;
                continue;
            }

            // term_exists() and wp_insert_term() unslash the name; a REST value arrives unslashed.
            $name     = wp_slash( sanitize_text_field( (string) $value['name'] ) );
            $existing = term_exists( $name, $taxonomy );

            if ( is_array( $existing ) ) {
                $ids[] = (int) $existing['term_id'];
                continue;
            }

            $created = wp_insert_term( $name, $taxonomy );

            if ( is_wp_error( $created ) ) {
                return $created;
            }

            $ids[] = (int) $created['term_id'];
        }

        return array_values( array_unique( $ids ) );
    }

    /**
     * Whether a user may write articles.
     *
     * @since 1.0.0
     *
     * @param int $user_id The user ID.
     *
     * @return bool True when the user exists and can edit articles.
     */
    private static function is_author( $user_id ) {
        $user = get_userdata( $user_id );

        return $user instanceof WP_User && user_can( $user, Licensio_KB_Article_Bulk::type_cap( 'edit_posts' ) );
    }

    /**
     * Permission for quick edit: manage the knowledge base and edit this article.
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request The request.
     *
     * @return true|WP_Error True when allowed.
     */
    public function update_item_permissions_check( $request ) {
        $allowed = $this->manage_permissions_check( $request );

        if ( true !== $allowed ) {
            return $allowed;
        }

        if ( ! current_user_can( 'edit_post', (int) $request->get_param( 'id' ) ) ) {
            return self::forbidden( __( 'You are not allowed to edit this article.', 'licensio-knowledge-base' ) );
        }

        return true;
    }

    /**
     * Run a bulk action over a batch of articles.
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request The request.
     *
     * @return WP_REST_Response|WP_Error What happened to each ID.
     */
    public function bulk_items( $request ) {
        $action = (string) $request->get_param( 'action' );
        $args   = $this->bulk_action_args( $action, (array) $request->get_param( 'args' ) );

        if ( is_wp_error( $args ) ) {
            return $args;
        }

        $ids = Licensio_KB_Article_Bulk::clean_ids( (array) $request->get_param( 'ids' ) );

        if ( empty( $ids ) || count( $ids ) > Licensio_KB_Article_Bulk::MAX_IDS ) {
            return Licensio_KB_Article_Bulk::invalid( 'ids', __( 'Send between 1 and 100 article IDs.', 'licensio-knowledge-base' ) );
        }

        $result = $this->bulk->run( $ids, $action, $args );

        // An object, even when empty, so the app always reads a map.
        $result['previous'] = (object) $result['previous'];

        return rest_ensure_response( $result );
    }

    /**
     * Check and clean the arguments of one bulk action.
     *
     * @since 1.0.0
     *
     * @param string               $action The action.
     * @param array<string, mixed> $args   The raw arguments.
     *
     * @return array<string, mixed>|WP_Error The cleaned arguments, or a 400 naming the bad one.
     */
    public function bulk_action_args( $action, array $args ) {
        switch ( $action ) {
            case 'status':
                $status = isset( $args['status'] ) ? (string) $args['status'] : '';

                if ( ! in_array( $status, Licensio_KB_Article_Bulk::BULK_STATUSES, true ) ) {
                    return Licensio_KB_Article_Bulk::invalid( 'args', __( 'Choose a status to set.', 'licensio-knowledge-base' ) );
                }

                return array( 'status' => $status );

            case 'terms':
                $taxonomy = isset( $args['taxonomy'] ) ? (string) $args['taxonomy'] : '';
                $mode     = isset( $args['mode'] ) ? (string) $args['mode'] : '';
                $term_ids = isset( $args['term_ids'] ) && is_array( $args['term_ids'] ) ? $args['term_ids'] : null;

                if ( ! in_array( $taxonomy, Licensio_KB_Article_Bulk::taxonomies(), true ) || ! in_array( $mode, Licensio_KB_Article_Bulk::TERM_MODES, true ) || null === $term_ids ) {
                    return Licensio_KB_Article_Bulk::invalid( 'args', __( 'Choose a taxonomy, a mode and the terms.', 'licensio-knowledge-base' ) );
                }

                $term_ids = Licensio_KB_Article_Bulk::clean_ids( $term_ids );

                if ( empty( $term_ids ) && 'replace' !== $mode ) {
                    return Licensio_KB_Article_Bulk::invalid( 'args', __( 'Choose at least one term.', 'licensio-knowledge-base' ) );
                }

                foreach ( $term_ids as $term_id ) {
                    if ( ! term_exists( $term_id, $taxonomy ) ) {
                        return Licensio_KB_Article_Bulk::invalid( 'args', __( 'One of the terms does not exist.', 'licensio-knowledge-base' ) );
                    }
                }

                return array(
                    'taxonomy' => $taxonomy,
                    'mode'     => $mode,
                    'term_ids' => $term_ids,
                );

            case 'author':
                $author = isset( $args['author'] ) ? (int) $args['author'] : 0;

                if ( $author < 1 || ! self::is_author( $author ) ) {
                    return Licensio_KB_Article_Bulk::invalid( 'args', __( 'Choose a user who can write articles.', 'licensio-knowledge-base' ) );
                }

                return array( 'author' => $author );

            case 'comments':
                $comment_status = isset( $args['comment_status'] ) ? (string) $args['comment_status'] : '';

                if ( ! in_array( $comment_status, array( 'open', 'closed' ), true ) ) {
                    return Licensio_KB_Article_Bulk::invalid( 'args', __( 'Choose open or closed.', 'licensio-knowledge-base' ) );
                }

                return array( 'comment_status' => $comment_status );
        }

        return array();
    }

    /**
     * Undo a bulk action from its snapshot.
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request The request.
     *
     * @return WP_REST_Response|WP_Error What happened to each ID, or why the snapshot was refused.
     */
    public function revert_items( $request ) {
        $result = $this->bulk->revert(
            (array) $request->get_param( 'previous' ),
            (int) $request->get_param( 'snapshot_at' ),
            (string) $request->get_param( 'token' )
        );

        return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
    }

    /**
     * The users who can write articles.
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request The request.
     *
     * @return WP_REST_Response|WP_Error The users and their total.
     */
    public function get_authors( $request ) {
        $search  = trim( (string) $request->get_param( 'search' ) );
        $include = Licensio_KB_Article_Bulk::clean_ids( (array) $request->get_param( 'include' ) );
        $args    = array(
            'capability'  => Licensio_KB_Article_Bulk::type_cap( 'edit_posts' ),
            'fields'      => array( 'ID', 'display_name' ),
            'orderby'     => 'display_name',
            'order'       => 'ASC',
            'number'      => max( 1, min( self::MAX_PER_PAGE, (int) $request->get_param( 'per_page' ) ) ),
            'paged'       => max( 1, (int) $request->get_param( 'page' ) ),
            'count_total' => true,
        );

        if ( '' !== $search ) {
            $args['search']         = '*' . $search . '*';
            $args['search_columns'] = array( 'user_login', 'user_nicename', 'display_name' );
        }

        if ( ! empty( $include ) ) {
            $args['include'] = $include;
        }

        $query = new WP_User_Query( $args );
        $items = array();

        foreach ( (array) $query->get_results() as $user ) {
            $items[] = array(
                'id'   => (int) $user->ID,
                'name' => self::plain_text( $user->display_name ),
            );
        }

        return rest_ensure_response(
            array(
                'items' => $items,
                'total' => (int) $query->get_total(),
            )
        );
    }

    /**
     * The current user's list preferences.
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request The request.
     *
     * @return WP_REST_Response The preferences.
     */
    public function get_preferences( $request ) {
        unset( $request );

        return rest_ensure_response( self::preferences( get_current_user_id() ) );
    }

    /**
     * Save the current user's list preferences.
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request The request.
     *
     * @return WP_REST_Response The saved preferences.
     */
    public function update_preferences( $request ) {
        $preferences = self::sanitize_preferences(
            array(
                'columns'  => $request->get_param( 'columns' ),
                'per_page' => $request->get_param( 'per_page' ),
            )
        );

        update_user_meta( get_current_user_id(), self::PREFERENCES_META, $preferences );

        return rest_ensure_response( $preferences );
    }

    /**
     * A user's list preferences, with the defaults filled in.
     *
     * @since 1.0.0
     *
     * @param int $user_id The user ID.
     *
     * @return array{columns: string[], per_page: int} The preferences.
     */
    public static function preferences( $user_id ) {
        return self::sanitize_preferences( get_user_meta( (int) $user_id, self::PREFERENCES_META, true ) );
    }

    /**
     * Hold preferences to the allow-listed columns and page sizes.
     *
     * @since 1.0.0
     *
     * @param mixed $value The stored or submitted preferences.
     *
     * @return array{columns: string[], per_page: int} The clean preferences.
     */
    public static function sanitize_preferences( $value ) {
        $value    = is_array( $value ) ? $value : array();
        $columns  = isset( $value['columns'] ) && is_array( $value['columns'] ) ? $value['columns'] : self::DEFAULT_COLUMNS;
        $per_page = isset( $value['per_page'] ) ? (int) $value['per_page'] : 0;

        return array(
            // Kept in table order, whatever order they were sent in.
            'columns'  => array_values( array_intersect( self::COLUMNS, array_map( 'strval', $columns ) ) ),
            'per_page' => in_array( $per_page, self::PER_PAGE_OPTIONS, true ) ? $per_page : self::PER_PAGE_OPTIONS[0],
        );
    }

    /**
     * Text stored with HTML entities, as plain text.
     *
     * Core saves term names, and titles for users without `unfiltered_html`,
     * with `&` as `&amp;`. The app renders text, never HTML.
     *
     * @since 1.0.0
     *
     * @param string $text The stored text.
     *
     * @return string The text with HTML entities decoded.
     */
    private static function plain_text( $text ) {
        return html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' );
    }

    /**
     * A MySQL datetime in site time as `YYYY-MM-DDTHH:MM:SS`.
     *
     * @since 1.0.0
     *
     * @param string $datetime The `Y-m-d H:i:s` value.
     *
     * @return string The ISO-like value.
     */
    private static function iso_local( $datetime ) {
        return str_replace( ' ', 'T', (string) $datetime );
    }

    /**
     * A 404 for an ID that is not an article.
     *
     * @since 1.0.0
     *
     * @return WP_Error The error.
     */
    private static function not_found() {
        return new WP_Error( 'licensio_kb_article_not_found', __( 'No article was found with that ID.', 'licensio-knowledge-base' ), array( 'status' => 404 ) );
    }

    /**
     * A 403 with a message.
     *
     * @since 1.0.0
     *
     * @param string $message What the user may not do.
     *
     * @return WP_Error The error.
     */
    private static function forbidden( $message ) {
        return new WP_Error( 'licensio_forbidden', $message, array( 'status' => rest_authorization_required_code() ) );
    }
}
