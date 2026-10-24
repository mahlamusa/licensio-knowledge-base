<?php
/**
 * Minimal WP_Tax_Query double.
 *
 * The per-category article order reads a query's parsed tax query to find
 * the one category it is limited to, and guards on `instanceof`, so the unit
 * suite needs the class with its public `$queries`.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

if ( ! class_exists( 'WP_Tax_Query', false ) ) {
    /**
     * Stand-in for a parsed tax query.
     *
     * @since 1.0.0
     */
    class WP_Tax_Query {

        /**
         * The parsed clauses.
         *
         * @since 1.0.0
         * @var array<int|string, mixed>
         */
        public $queries = array();

        /**
         * Constructor.
         *
         * @since 1.0.0
         *
         * @param array<int|string, mixed> $queries The parsed clauses.
         */
        public function __construct( array $queries = array() ) {
            $this->queries = $queries;
        }
    }
}
