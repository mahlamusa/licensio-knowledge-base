<?php
/**
 * Minimal WP_Screen double.
 *
 * The term field screens decide whether to load the media library by asking
 * `get_current_screen()` which taxonomy is being edited. Without the class the
 * `instanceof` guard can only ever be tested from its failing side, which would
 * leave the branch that actually enqueues the picker uncovered.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

if ( ! class_exists( 'WP_Screen' ) ) {
    /**
     * Stand-in for the WordPress screen object.
     *
     * @since 1.0.0
     */
    class WP_Screen {

        /**
         * The screen ID.
         *
         * @since 1.0.0
         * @var string
         */
        public $id = '';

        /**
         * The taxonomy the screen is editing, if any.
         *
         * @since 1.0.0
         * @var string
         */
        public $taxonomy = '';

        /**
         * The post type the screen is editing, if any.
         *
         * @since 1.0.0
         * @var string
         */
        public $post_type = '';

        /**
         * Constructor.
         *
         * @since 1.0.0
         *
         * @param string $taxonomy The taxonomy the screen is editing.
         * @param string $id       The screen ID.
         */
        public function __construct( $taxonomy = '', $id = '' ) {
            $this->taxonomy = (string) $taxonomy;
            $this->id       = (string) $id;
        }
    }
}
