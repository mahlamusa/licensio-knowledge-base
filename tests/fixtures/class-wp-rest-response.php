<?php
/**
 * Minimal WP_REST_Response double.
 *
 * The search controller returns a response carrying the paging headers, so the
 * unit suite needs somewhere for those headers to land. Brain Monkey supplies
 * WordPress functions but no classes.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

if ( ! class_exists( 'WP_REST_Response' ) ) {
    /**
     * Stand-in for a REST response.
     *
     * @since 1.0.0
     */
    class WP_REST_Response {

        /**
         * The response body.
         *
         * @since 1.0.0
         * @var mixed
         */
        private $data;

        /**
         * The HTTP status code.
         *
         * @since 1.0.0
         * @var int
         */
        private $status;

        /**
         * The response headers.
         *
         * @since 1.0.0
         * @var array<string, string>
         */
        private $headers = array();

        /**
         * The response links, by relation.
         *
         * @since 1.0.0
         * @var array<string, array<int, array<string, string>>>
         */
        private $links = array();

        /**
         * Constructor.
         *
         * @since 1.0.0
         *
         * @param mixed                 $data    The response body.
         * @param int                   $status  The HTTP status code.
         * @param array<string, string> $headers The response headers.
         */
        public function __construct( $data = null, $status = 200, $headers = array() ) {
            $this->data    = $data;
            $this->status  = (int) $status;
            $this->headers = (array) $headers;
        }

        /**
         * Set a response header.
         *
         * @since 1.0.0
         *
         * @param string $key     The header name.
         * @param string $value   The header value.
         * @param bool   $replace Whether to replace an existing header.
         *
         * @return void
         */
        public function header( $key, $value, $replace = true ) {
            if ( $replace || ! isset( $this->headers[ $key ] ) ) {
                $this->headers[ $key ] = $value;
            }
        }

        /**
         * The response headers.
         *
         * @since 1.0.0
         *
         * @return array<string, string> The headers.
         */
        public function get_headers() {
            return $this->headers;
        }

        /**
         * The response body.
         *
         * @since 1.0.0
         *
         * @return mixed The body.
         */
        public function get_data() {
            return $this->data;
        }

        /**
         * The HTTP status code.
         *
         * @since 1.0.0
         *
         * @return int The status code.
         */
        public function get_status() {
            return $this->status;
        }

        /**
         * Add a link.
         *
         * @since 1.0.0
         *
         * @param string $rel  The link relation.
         * @param string $href The link target.
         *
         * @return void
         */
        public function add_link( $rel, $href ) {
            $this->links[ $rel ][] = array( 'href' => $href );
        }

        /**
         * Remove every link with a relation.
         *
         * @since 1.0.0
         *
         * @param string $rel The link relation.
         *
         * @return void
         */
        public function remove_link( $rel ) {
            unset( $this->links[ $rel ] );
        }

        /**
         * The links, by relation.
         *
         * @since 1.0.0
         *
         * @return array<string, array<int, array<string, string>>> The links.
         */
        public function get_links() {
            return $this->links;
        }
    }
}
