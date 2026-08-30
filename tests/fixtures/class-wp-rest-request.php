<?php
/**
 * Minimal WP_REST_Request double.
 *
 * Brain Monkey supplies WordPress functions but no classes. The permission
 * callbacks read a header off the request and the route stubs read the route.
 *
 * @package Lisensio_KB
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
         * Constructor.
         *
         * @since 1.0.0
         *
         * @param array<string, string> $headers Request headers.
         * @param string                $route   The resolved route.
         */
        public function __construct( $headers = array(), $route = '' ) {
            $this->headers = (array) $headers;
            $this->route   = (string) $route;
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
