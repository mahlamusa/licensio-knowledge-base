<?php
/**
 * Minimal WP_Post double.
 *
 * Brain Monkey supplies WordPress functions but no classes, and the classic
 * templates type-check what `get_post()` hands them, so the class has to exist
 * for a template to be rendered under test.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

if ( ! class_exists( 'WP_Post' ) ) {
    /**
     * Stand-in for the WordPress post object.
     *
     * @since 1.0.0
     */
    class WP_Post {

        /**
         * The post ID.
         *
         * @since 1.0.0
         * @var int
         */
        public $ID = 0;

        /**
         * The post title.
         *
         * @since 1.0.0
         * @var string
         */
        public $post_title = '';

        /**
         * The post type.
         *
         * @since 1.0.0
         * @var string
         */
        public $post_type = 'post';

        /**
         * The post status.
         *
         * @since 1.0.0
         * @var string
         */
        public $post_status = 'publish';

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
