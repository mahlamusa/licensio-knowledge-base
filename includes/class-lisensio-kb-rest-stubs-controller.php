<?php
/**
 * Reserved REST routes.
 *
 * Registers every route in the `lisensio-kb/v1` namespace as a stub that
 * answers `501 Not Implemented`, so the API surface is discoverable and the
 * permission model is fixed before any consumer writes a handler.
 *
 * A sub-issue that implements a route removes it from this controller by
 * filtering `lisensio_rest_stub_routes` with its own route path, then
 * registers its real controller. Stubs are registered late (priority 100) so
 * a consumer that forgets the filter still wins the route.
 *
 * Consumed by: #4 (`/search`, `/articles`, `/categories`), #8 (`/feedback`,
 * `/feedback/{id}`), #9 (`/views`, `/analytics/*`), #10 (`/settings`).
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the reserved routes as not-implemented stubs.
 *
 * @since 1.0.0
 */
class Lisensio_KB_REST_Stubs_Controller extends Lisensio_KB_REST_Controller {

    /**
     * The reserved route table.
     *
     * Each entry maps a route path to its methods and the permission callback
     * name on this class.
     *
     * @since 1.0.0
     *
     * @return array<string, array{methods: string, permission: string}> The reserved routes.
     */
    public function reserved_routes() {
        $routes = array(
            '/search'                 => array(
                'methods'    => 'GET',
                'permission' => 'public_permissions_check',
            ),
            '/articles'               => array(
                'methods'    => 'GET',
                'permission' => 'public_permissions_check',
            ),
            '/categories'             => array(
                'methods'    => 'GET',
                'permission' => 'public_permissions_check',
            ),
            '/feedback'               => array(
                'methods'    => 'POST',
                'permission' => 'public_write_permissions_check',
            ),
            '/views'                  => array(
                'methods'    => 'POST',
                'permission' => 'public_write_permissions_check',
            ),
            '/feedback/(?P<id>[\d]+)' => array(
                'methods'    => 'PATCH',
                'permission' => 'manage_permissions_check',
            ),
            '/analytics/overview'     => array(
                'methods'    => 'GET',
                'permission' => 'manage_permissions_check',
            ),
            '/analytics/searches'     => array(
                'methods'    => 'GET',
                'permission' => 'manage_permissions_check',
            ),
            '/analytics/articles'     => array(
                'methods'    => 'GET',
                'permission' => 'manage_permissions_check',
            ),
            '/analytics/feedback'     => array(
                'methods'    => 'GET',
                'permission' => 'manage_permissions_check',
            ),
            '/settings'               => array(
                'methods'    => 'GET, POST',
                'permission' => 'manage_permissions_check',
            ),
        );

        /**
         * Filter the routes registered as not-implemented stubs.
         *
         * Remove your route from this array in the sub-issue that implements
         * it, so your controller owns the route outright.
         *
         * @since 1.0.0
         *
         * @param array<string, array{methods: string, permission: string}> $routes The reserved routes.
         */
        return apply_filters( 'lisensio_rest_stub_routes', $routes );
    }

    /**
     * Register the reserved routes.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function register_routes() {
        foreach ( $this->reserved_routes() as $route => $definition ) {
            register_rest_route(
                $this->namespace,
                $route,
                array(
                    array(
                        'methods'             => $definition['methods'],
                        'callback'            => array( $this, 'handle_stub' ),
                        'permission_callback' => array( $this, $definition['permission'] ),
                        'args'                => array(),
                    ),
                )
            );
        }
    }

    /**
     * Answer a reserved route that has no implementation yet.
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request The request.
     *
     * @return WP_Error A 501 error naming the route.
     */
    public function handle_stub( $request ) {
        return $this->not_implemented( $request->get_route() );
    }
}
