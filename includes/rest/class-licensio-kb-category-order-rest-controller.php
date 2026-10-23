<?php
/**
 * Per-category article order REST routes.
 *
 * Registers the two routes the per-category article order is read and saved
 * through, with their full argument schema and permission check:
 *
 * - `GET /licensio-kb/v1/categories/{id}/articles` lists the articles filed
 *   directly in the category, in their per-category order. Articles filed
 *   only in a sub-category are not listed, although the archive and the
 *   category cards show them after the ordered ones, by title. Query: `page`
 *   (default 1) and `per_page` (1 to 200, default 200). Answers
 *   `{ items: [ { id, title, status, order, edit_url } ], total, pages }`,
 *   where `order` is the article's position or null when it has none.
 * - `PUT /licensio-kb/v1/categories/{id}/articles/order` saves a new order.
 *   Body: `{ ids: int[] }`, first comes first, at most 1000 IDs. Answers
 *   `{ ids: int[] }`, the listed IDs as saved. The listed articles get
 *   positions 10, 20, 30… in the order given. Every other article that
 *   already has a position in the category is renumbered after them,
 *   keeping its previous relative order. Articles without a position stay
 *   without one. So saving the first page of a long list moves those
 *   articles to the top and keeps the rest in their order below them.
 *
 * Both answer 404 `licensio_kb_invalid_term` for an ID that is not a
 * knowledge base category. The save answers 400 `licensio_kb_not_in_category`
 * when an ID is not an article filed directly in the category, and 403
 * `licensio_kb_cannot_edit` when the user may not edit one of the articles.
 * Nothing is written unless every ID passes. The TypeScript types in
 * `apps/components/types/category-order.ts` describe the same shapes.
 *
 * Consumed by the categories app and the per-category article order.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Serves the per-category article order routes.
 *
 * @since 1.0.0
 */
class Licensio_KB_Category_Order_REST_Controller extends Licensio_KB_REST_Controller {

    /**
     * The route base.
     *
     * @since 1.0.0
     * @var string
     */
    protected $rest_base = 'categories';

    /**
     * The most articles one page of the list returns.
     *
     * @since 1.0.0
     * @var int
     */
    const MAX_PER_PAGE = 200;

    /**
     * The most article IDs one save accepts.
     *
     * @since 1.0.0
     * @var int
     */
    const MAX_IDS = 1000;

    /**
     * Register the order routes.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function register_routes() {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id>[\d]+)/articles',
            array(
                array(
                    'methods'             => 'GET',
                    'callback'            => array( $this, 'get_items' ),
                    'permission_callback' => array( $this, 'manage_permissions_check' ),
                    'args'                => $this->get_items_args(),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id>[\d]+)/articles/order',
            array(
                array(
                    'methods'             => 'PUT',
                    'callback'            => array( $this, 'update_order' ),
                    'permission_callback' => array( $this, 'manage_permissions_check' ),
                    'args'                => $this->update_order_args(),
                ),
            )
        );
    }

    /**
     * The category ID path argument both routes share.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed> The argument schema.
     */
    private function id_arg() {
        return array(
            'required'          => true,
            'type'              => 'integer',
            'minimum'           => 1,
            'sanitize_callback' => 'absint',
            'description'       => __( 'The category whose articles are ordered.', 'licensio-knowledge-base' ),
        );
    }

    /**
     * The accepted arguments for the article list.
     *
     * @since 1.0.0
     *
     * @return array<string, array<string, mixed>> The argument schema.
     */
    public function get_items_args() {
        return array(
            'id'       => $this->id_arg(),
            'page'     => array(
                'type'              => 'integer',
                'minimum'           => 1,
                'default'           => 1,
                'sanitize_callback' => 'absint',
                'description'       => __( 'The page of articles to return.', 'licensio-knowledge-base' ),
            ),
            'per_page' => array(
                'type'              => 'integer',
                'minimum'           => 1,
                'maximum'           => self::MAX_PER_PAGE,
                'default'           => self::MAX_PER_PAGE,
                'sanitize_callback' => 'absint',
                'description'       => __( 'How many articles a page holds.', 'licensio-knowledge-base' ),
            ),
        );
    }

    /**
     * The accepted arguments for saving an order.
     *
     * @since 1.0.0
     *
     * @return array<string, array<string, mixed>> The argument schema.
     */
    public function update_order_args() {
        return array(
            'id'  => $this->id_arg(),
            'ids' => array(
                'required'    => true,
                'type'        => 'array',
                'uniqueItems' => true,
                'maxItems'    => self::MAX_IDS,
                'items'       => array(
                    'type'    => 'integer',
                    'minimum' => 1,
                ),
                'description' => __( 'The article IDs in their new order, first first.', 'licensio-knowledge-base' ),
            ),
        );
    }

    /**
     * List a category's articles in their per-category order.
     *
     * Every status except trash (and auto-draft), filed directly in the
     * category; articles of sub-categories are not listed. Ordered articles
     * come first, then unordered ones by title.
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request The request.
     *
     * @return WP_REST_Response|WP_Error The page of articles, or an error for an unknown category.
     */
    public function get_items( $request ) {
        $term_id = $this->category_id( $request );

        if ( is_wp_error( $term_id ) ) {
            return $term_id;
        }

        $per_page = min( self::MAX_PER_PAGE, max( 1, (int) $request->get_param( 'per_page' ) ) );
        $page     = max( 1, (int) $request->get_param( 'page' ) );

        $query = $this->query(
            array(
                'post_type'              => Licensio_KB_Post_Types::POST_TYPE,
                'post_status'            => 'any',
                'posts_per_page'         => $per_page,
                'paged'                  => $page,
                'orderby'                => Licensio_KB_Article_Order::ORDERBY,
                'order'                  => 'ASC',
                'ignore_sticky_posts'    => true,
                'update_post_term_cache' => false,
                // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
                'tax_query'              => $this->category_clause( $term_id ),
            )
        );

        $key   = Licensio_KB_Article_Order::meta_key( $term_id );
        $items = array();

        foreach ( $query->posts as $post ) {
            if ( ! $post instanceof WP_Post ) {
                continue;
            }

            $order = get_post_meta( (int) $post->ID, $key, true );
            $link  = get_edit_post_link( (int) $post->ID, 'raw' );

            $items[] = array(
                'id'       => (int) $post->ID,
                'title'    => html_entity_decode( (string) $post->post_title, ENT_QUOTES, 'UTF-8' ),
                'status'   => (string) $post->post_status,
                'order'    => is_numeric( $order ) ? (int) $order : null,
                'edit_url' => is_string( $link ) ? $link : '',
            );
        }

        $total = (int) $query->found_posts;

        return rest_ensure_response(
            array(
                'items' => $items,
                'total' => $total,
                'pages' => (int) ceil( $total / $per_page ),
            )
        );
    }

    /**
     * Save a category's article order.
     *
     * Every ID must be an article filed directly in the category, in any
     * status except trash, that the current user may edit. The articles are
     * written as 10, 20, 30… in the order given. Every other article with a
     * position in the category, trashed ones included, is renumbered after
     * them in its previous relative order; articles without a position stay
     * without one.
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request The request.
     *
     * @return WP_REST_Response|WP_Error The order as saved, or an error naming the first problem.
     */
    public function update_order( $request ) {
        $term_id = $this->category_id( $request );

        if ( is_wp_error( $term_id ) ) {
            return $term_id;
        }

        $ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $request->get_param( 'ids' ) ) ) ) );

        if ( ! empty( $ids ) ) {
            $found = get_posts(
                array(
                    'post_type'              => Licensio_KB_Post_Types::POST_TYPE,
                    'post_status'            => 'any',
                    'post__in'               => $ids,
                    'posts_per_page'         => count( $ids ),
                    'fields'                 => 'ids',
                    'no_found_rows'          => true,
                    'update_post_term_cache' => false,
                    'update_post_meta_cache' => false,
                    // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
                    'tax_query'              => $this->category_clause( $term_id ),
                )
            );

            $strays = array_values( array_diff( $ids, array_map( 'intval', is_array( $found ) ? $found : array() ) ) );

            if ( ! empty( $strays ) ) {
                return new WP_Error(
                    'licensio_kb_not_in_category',
                    __( 'Some of these articles are not in this category.', 'licensio-knowledge-base' ),
                    array(
                        'status' => 400,
                        'ids'    => $strays,
                    )
                );
            }

            foreach ( $ids as $id ) {
                if ( ! current_user_can( 'edit_post', $id ) ) {
                    return new WP_Error(
                        'licensio_kb_cannot_edit',
                        __( 'You are not allowed to reorder one of these articles.', 'licensio-knowledge-base' ),
                        array(
                            'status' => rest_authorization_required_code(),
                            'ids'    => array( $id ),
                        )
                    );
                }
            }
        }

        return rest_ensure_response( array( 'ids' => Licensio_KB_Article_Order::save( $term_id, $ids ) ) );
    }

    /**
     * The category a request names, checked.
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request The request.
     *
     * @return int|WP_Error The category term ID, or a 404 error when it is not a knowledge base category.
     */
    private function category_id( $request ) {
        $term_id = absint( $request->get_param( 'id' ) );
        $term    = $term_id > 0 ? get_term( $term_id, Licensio_KB_Post_Types::TAXONOMY_CATEGORY ) : null;

        if ( ! $term instanceof WP_Term ) {
            return new WP_Error(
                'licensio_kb_invalid_term',
                __( 'That category does not exist.', 'licensio-knowledge-base' ),
                array( 'status' => 404 )
            );
        }

        return (int) $term->term_id;
    }

    /**
     * The tax query limiting a query to one category, without its children.
     *
     * @since 1.0.0
     *
     * @param int $term_id The category term ID.
     *
     * @return array<int, array<string, mixed>> The tax query.
     */
    private function category_clause( $term_id ) {
        return array(
            array(
                'taxonomy'         => Licensio_KB_Post_Types::TAXONOMY_CATEGORY,
                'field'            => 'term_id',
                'terms'            => (int) $term_id,
                'include_children' => false,
            ),
        );
    }

    /**
     * Run a post query.
     *
     * A seam for the unit tests, which have no database to query.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $args The query arguments.
     *
     * @return WP_Query The query, already run.
     */
    protected function query( array $args ) {
        return new WP_Query( $args );
    }
}
