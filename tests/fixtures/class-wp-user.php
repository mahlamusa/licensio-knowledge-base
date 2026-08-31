<?php
/**
 * Minimal WP_User double.
 *
 * Brain Monkey supplies WordPress functions but no classes. The privacy
 * exporter resolves an email address to a user and reads its ID.
 *
 * @package Lisensio_KB
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
