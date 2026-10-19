<?php
/**
 * Minimal WP_REST_Request double.
 *
 * Brain Monkey supplies WordPress functions but no classes. The permission
 * callbacks read a header off the request, the route stubs read the route, and
 * the feedback controller reads request parameters.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

if ( ! class_exists( 'WP_REST_Request' ) ) {
    /**
     * Stand-in for a REST request.
     *
     * @since 1.0.0
     */
    class WP_REST_Request {

        /**
         * Request headers, keyed by name.
         *
         * @since 1.0.0
         * @var array<string, string>
         */
        private $headers = array();

        /**
         * The route the request resolved to.
         *
         * @since 1.0.0
         * @var string
         */
        private $route = '';

        /**
         * Request parameters, keyed by name.
         *
         * @since 1.0.0
         * @var array<string, mixed>
         */
        private $params = array();

        /**
         * Constructor.
         *
         * @since 1.0.0
         *
         * @param array<string, string> $headers Request headers.
         * @param string                $route   The resolved route.
         * @param array<string, mixed>  $params  Request parameters.
         */
        public function __construct( $headers = array(), $route = '', $params = array() ) {
            $this->headers = (array) $headers;
            $this->route   = (string) $route;
            $this->params  = (array) $params;
        }

        /**
         * Read one request parameter.
         *
         * @since 1.0.0
         *
         * @param string $key The parameter name.
         *
         * @return mixed The parameter value, or null when it was not sent.
         */
        public function get_param( $key ) {
            return array_key_exists( $key, $this->params ) ? $this->params[ $key ] : null;
        }

        /**
         * Set one request parameter.
         *
         * @since 1.0.0
         *
         * @param string $key   The parameter name.
         * @param mixed  $value The parameter value.
         *
         * @return void
         */
        public function set_param( $key, $value ) {
            $this->params[ $key ] = $value;
        }

        /**
         * Every request parameter.
         *
         * @since 1.0.0
         *
         * @return array<string, mixed> The parameters.
         */
        public function get_params() {
            return $this->params;
        }

        /**
         * Read a request header.
         *
         * @since 1.0.0
         *
         * @param string $name The header name.
         *
         * @return string|null The header value, or null when absent.
         */
        public function get_header( $name ) {
            return isset( $this->headers[ $name ] ) ? $this->headers[ $name ] : null;
        }

        /**
         * The resolved route.
         *
         * @since 1.0.0
         *
         * @return string The route path.
         */
        public function get_route() {
            return $this->route;
        }
    }
}
