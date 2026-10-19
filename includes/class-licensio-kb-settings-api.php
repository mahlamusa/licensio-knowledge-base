<?php
/**
 * The settings REST surface.
 *
 * Announces Licensio_KB_REST_Settings_Controller to the scaffold's REST
 * registrar and releases `/settings` from the not-implemented stubs, in the
 * same shape as the search, feedback and analytics modules.
 *
 * It exists apart from Licensio_KB_Settings_Admin because the REST route has
 * to be registered on every request, admin or not, while the screen only
 * exists in wp-admin.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Hooks the settings REST controller into the plugin's REST registrar.
 *
 * @since 1.0.0
 */
class Licensio_KB_Settings_API {

    /**
     * Register the WordPress hooks.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function init_hooks() {
        add_filter( 'licensio_rest_controllers', array( $this, 'register_rest_controller' ) );
        add_filter( 'licensio_rest_stub_routes', array( $this, 'release_stub_route' ) );
    }

    /**
     * Add the settings controller to the registrar's list.
     *
     * @since 1.0.0
     *
     * @param string[]|mixed $controllers The registered controller class names.
     *
     * @return string[] The list, with the settings controller added.
     */
    public function register_rest_controller( $controllers ) {
        $controllers   = is_array( $controllers ) ? $controllers : array();
        $controllers[] = 'Licensio_KB_REST_Settings_Controller';

        return $controllers;
    }

    /**
     * Release `/settings` from the scaffold's not-implemented stubs.
     *
     * @since 1.0.0
     *
     * @param array<string, array<string, string>>|mixed $routes The reserved routes.
     *
     * @return array<string, array<string, string>>|mixed The routes, without the settings stub.
     */
    public function release_stub_route( $routes ) {
        if ( ! is_array( $routes ) ) {
            return $routes;
        }

        unset( $routes[ Licensio_KB_REST_Settings_Controller::ROUTE ] );

        return $routes;
    }
}
