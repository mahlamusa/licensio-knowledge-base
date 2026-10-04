<?php
/**
 * Minimal WP_Post double.
 *
 * Brain Monkey supplies WordPress functions but no classes, and the classic
 * templates type-check what `get_post()` hands them, so the class has to exist
 * for a template to be rendered under test.
 *
 * The search index reads the same object to decide what to store and whether to
 * store anything at all, and guards both decisions on `instanceof WP_Post` —
 * `transition_post_status` fires for every post on the site, so that guard is
 * load-bearing rather than decorative.
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
         * The post password.
         *
         * @since 1.0.0
         * @var string
         */
        public $post_password = '';

        /**
         * The post excerpt.
         *
         * @since 1.0.0
         * @var string
         */
        public $post_excerpt = '';

        /**
         * The post content.
         *
         * @since 1.0.0
         * @var string
         */
        public $post_content = '';

        /**
         * The post author's user ID, as core stores it: a numeric string.
         *
         * @since 1.0.0
         * @var string
         */
        public $post_author = '0';

        /**
         * The local publish date.
         *
         * @since 1.0.0
         * @var string
         */
        public $post_date = '0000-00-00 00:00:00';

        /**
         * The UTC publish date.
         *
         * @since 1.0.0
         * @var string
         */
        public $post_date_gmt = '0000-00-00 00:00:00';

        /**
         * Whether comments are open.
         *
         * @since 1.0.0
         * @var string
         */
        public $comment_status = 'closed';

        /**
         * Whether pings are open.
         *
         * @since 1.0.0
         * @var string
         */
        public $ping_status = 'closed';

        /**
         * The menu order.
         *
         * @since 1.0.0
         * @var int
         */
        public $menu_order = 0;

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
