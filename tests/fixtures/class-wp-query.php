<?php
/**
 * Minimal WP_Query double.
 *
 * The search component hooks `pre_get_posts` and re-points the main query at
 * the ranked IDs from the index. That callback takes a WP_Query and guards on
 * `instanceof`, so the unit suite needs the class to exist before it can prove
 * the guard works.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

if ( ! class_exists( 'WP_Query' ) ) {
    /**
     * Stand-in for a WordPress query.
     *
     * @since 1.0.0
     */
    class WP_Query {

        /**
         * The query variables.
         *
         * @since 1.0.0
         * @var array<string, mixed>
         */
        public $query_vars = array();

        /**
         * The posts the query found.
         *
         * @since 1.0.0
         * @var array<int, object>
         */
        public $posts = array();

        /**
         * How many posts matched, ignoring paging.
         *
         * @since 1.0.0
         * @var int
         */
        public $found_posts = 0;

        /**
         * Constructor.
         *
         * @since 1.0.0
         *
         * @param array<string, mixed> $query The query variables.
         */
        public function __construct( $query = array() ) {
            $this->query_vars = (array) $query;
        }

        /**
         * Read a query variable.
         *
         * @since 1.0.0
         *
         * @param string $name     The variable name.
         * @param mixed  $fallback The value to return when it is unset.
         *
         * @return mixed The value.
         */
        public function get( $name, $fallback = null ) {
            return array_key_exists( $name, $this->query_vars ) ? $this->query_vars[ $name ] : $fallback;
        }

        /**
         * Set a query variable.
         *
         * @since 1.0.0
         *
         * @param string $name  The variable name.
         * @param mixed  $value The value.
         *
         * @return void
         */
        public function set( $name, $value ) {
            $this->query_vars[ $name ] = $value;
        }

        /**
         * Whether this is the main query.
         *
         * @since 1.0.0
         *
         * @return bool Always false in the double.
         */
        public function is_main_query() {
            return false;
        }

        /**
         * Whether this is a search.
         *
         * @since 1.0.0
         *
         * @return bool Always false in the double.
         */
        public function is_search() {
            return false;
        }
    }
}
