<?php
/**
 * Minimal WP_User_Query double.
 *
 * Records the arguments of the last query so a test can assert what the
 * code asked for, and answers with no users.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

if ( ! class_exists( 'WP_User_Query' ) ) {
    /**
     * Stand-in for a WordPress user query.
     *
     * @since 1.0.0
     */
    class WP_User_Query {

        /**
         * The arguments of the last query built.
         *
         * @since 1.0.0
         * @var array<string, mixed>
         */
        public static $last_args = array();

        /**
         * Record the arguments.
         *
         * @since 1.0.0
         *
         * @param array<string, mixed> $args The query arguments.
         */
        public function __construct( $args = array() ) {
            self::$last_args = (array) $args;
        }

        /**
         * The users found: none.
         *
         * @since 1.0.0
         *
         * @return array<int, object> The users.
         */
        public function get_results() {
            return array();
        }

        /**
         * The total found: none.
         *
         * @since 1.0.0
         *
         * @return int The total.
         */
        public function get_total() {
            return 0;
        }
    }
}
