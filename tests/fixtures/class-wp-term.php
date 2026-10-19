<?php
/**
 * Minimal WP_Term double.
 *
 * The category listings and the breadcrumb builder both type-check the terms
 * they are handed, so the class has to exist for them to be testable without a
 * WordPress installation.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

if ( ! class_exists( 'WP_Term' ) ) {
    /**
     * Stand-in for the WordPress term object.
     *
     * @since 1.0.0
     */
    class WP_Term {

        /**
         * The term ID.
         *
         * @since 1.0.0
         * @var int
         */
        public $term_id = 0;

        /**
         * The term name.
         *
         * @since 1.0.0
         * @var string
         */
        public $name = '';

        /**
         * The term slug.
         *
         * @since 1.0.0
         * @var string
         */
        public $slug = '';

        /**
         * The taxonomy the term belongs to.
         *
         * @since 1.0.0
         * @var string
         */
        public $taxonomy = '';

        /**
         * The term description.
         *
         * @since 1.0.0
         * @var string
         */
        public $description = '';

        /**
         * The parent term ID.
         *
         * @since 1.0.0
         * @var int
         */
        public $parent = 0;

        /**
         * How many objects are assigned directly to the term.
         *
         * @since 1.0.0
         * @var int
         */
        public $count = 0;

        /**
         * Constructor.
         *
         * @since 1.0.0
         *
         * @param array<string, mixed> $properties Property name to value.
         */
        public function __construct( array $properties = array() ) {
            foreach ( $properties as $name => $value ) {
                $this->{$name} = $value;
            }
        }
    }
}
