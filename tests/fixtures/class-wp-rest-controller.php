<?php
/**
 * Minimal WP_REST_Controller double.
 *
 * Brain Monkey supplies WordPress functions but no classes.
 * Licensio_KB_REST_Controller extends WP_REST_Controller, so without this the
 * plugin's whole REST layer cannot even be loaded in the unit process — which
 * is why it shipped with no tests.
 *
 * @package Licensio_KB
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
         * The memoized item schema.
         *
         * @since 1.0.0
         * @var array<string, mixed>
         */
        protected $schema = array();

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

        /**
         * Merge registered additional fields into a schema.
         *
         * Core walks the additional-field registry here. Nothing in the unit
         * process registers one, so the schema is returned unchanged.
         *
         * @since 1.0.0
         *
         * @param array<string, mixed> $schema The item schema.
         *
         * @return array<string, mixed> The item schema.
         */
        protected function add_additional_fields_schema( $schema ) {
            return $schema;
        }

        /**
         * The item schema, with the read-only fields core adds.
         *
         * @since 1.0.0
         *
         * @return array<string, mixed> The public item schema.
         */
        public function get_public_item_schema() {
            return $this->get_item_schema();
        }

        /**
         * The item schema.
         *
         * @since 1.0.0
         *
         * @return array<string, mixed> The item schema.
         */
        public function get_item_schema() {
            return array();
        }
    }
}
