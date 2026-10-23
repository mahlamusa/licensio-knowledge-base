<?php
/**
 * Per-category article order REST routes.
 *
 * Registers the two routes the per-category article order is read and saved
 * through, with their full argument schema and permission check:
 *
 * - `GET /licensio-kb/v1/categories/{id}/articles` lists the category's
 *   articles in their per-category order. Query: `page` (default 1) and
 *   `per_page` (1 to 200, default 200). Answers
 *   `{ items: [ { id, title, status, order, edit_url } ], total, pages }`,
 *   where `order` is the article's position or null when it has none.
 * - `PUT /licensio-kb/v1/categories/{id}/articles/order` saves a new order.
 *   Body: `{ ids: int[] }`, first comes first. Answers `{ ids: int[] }`, the
 *   order as saved.
 *
 * Both currently answer `501 licensio_kb_not_implemented`. The per-category
 * article order work replaces the two callbacks and keeps the routes, the
 * argument schema and the response shapes above unchanged; the TypeScript
 * types in `apps/components/types/category-order.ts` describe the same shapes.
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
     * @since 1.0.0
     *
     * @param WP_REST_Request $request The request.
     *
     * @return WP_Error A 501 error until the per-category article order is built.
     */
    public function get_items( $request ) {
        return $this->not_built( $request );
    }

    /**
     * Save a category's article order.
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request The request.
     *
     * @return WP_Error A 501 error until the per-category article order is built.
     */
    public function update_order( $request ) {
        return $this->not_built( $request );
    }

    /**
     * The answer from a route whose handler is not built yet.
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request The request.
     *
     * @return WP_Error A 501 error naming the route.
     */
    private function not_built( $request ) {
        return new WP_Error(
            'licensio_kb_not_implemented',
            sprintf(
                /* translators: %s: REST route path */
                __( 'The %s endpoint is registered but not yet implemented.', 'licensio-knowledge-base' ),
                $request->get_route()
            ),
            array( 'status' => 501 )
        );
    }
}
