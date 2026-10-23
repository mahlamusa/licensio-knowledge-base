<?php
/**
 * Minimal WP_User double.
 *
 * Brain Monkey supplies WordPress functions but no classes. The privacy
 * exporter resolves an email address to a user and reads its ID.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

if ( ! class_exists( 'WP_User' ) ) {
    /**
     * Stand-in for a WordPress user.
     *
     * @since 1.0.0
     */
    class WP_User {

        /**
         * The user ID.
         *
         * @since 1.0.0
         * @var int
         */
        public $ID = 0;

        /**
         * The user email address.
         *
         * @since 1.0.0
         * @var string
         */
        public $user_email = '';

        /**
         * The user's display name; an empty string unless a test sets it.
         *
         * Never null in practice. The type admits null only so code that
         * guards the read with isset(), as code reading a real user may,
         * still passes static analysis.
         *
         * @since 1.0.0
         * @var string|null
         */
        public $display_name = '';

        /**
         * Constructor.
         *
         * @since 1.0.0
         *
         * @param int    $id    The user ID.
         * @param string $email The user email address.
         */
        public function __construct( $id = 0, $email = '' ) {
            $this->ID         = (int) $id;
            $this->user_email = (string) $email;
        }
    }
}
