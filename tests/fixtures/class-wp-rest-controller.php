<?php
/**
 * Minimal WP_REST_Controller double.
 *
 * Brain Monkey supplies WordPress functions but no classes.
 * Lisensio_KB_REST_Controller extends WP_REST_Controller, so without this the
 * plugin's whole REST layer cannot even be loaded in the unit process — which
 * is why it shipped with no tests.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

if ( ! class_exists( 'WP_REST_Controller' ) ) {
    /**
     * Stand-in for the WordPress REST controller base class.
     *
     * @since 1.0.0
     */
    class WP_REST_Controller {

        /**
         * The REST namespace.
         *
         * @since 1.0.0
         * @var string
         */
        protected $namespace = '';

        /**
         * The route base.
         *
         * @since 1.0.0
         * @var string
         */
        protected $rest_base = '';

        /**
         * Register the controller's routes.
         *
         * @since 1.0.0
         *
         * @return void
         */
        public function register_routes() {
        }

        /**
         * The namespace the controller registers against.
         *
         * @since 1.0.0
         *
         * @return string The namespace.
         */
        public function get_namespace() {
            return $this->namespace;
        }
    }
}
