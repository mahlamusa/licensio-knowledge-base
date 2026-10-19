<?php
/**
 * Minimal WP_Role double.
 *
 * Brain Monkey supplies WordPress functions but no classes, and the capability
 * model asks `get_role()` for a real role object and then writes to it. A stub
 * that only recorded calls would not answer the question the tests exist to
 * ask — whether a role ends up holding the capability — so this double stores
 * capabilities the way core does and answers has_cap() from that store.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

if ( ! class_exists( 'WP_Role' ) ) {
    /**
     * Stand-in for the WordPress role object.
     *
     * @since 1.0.0
     */
    class WP_Role {

        /**
         * The role name.
         *
         * @since 1.0.0
         * @var string
         */
        public $name;

        /**
         * Capability name to whether it is granted.
         *
         * @since 1.0.0
         * @var array<string, bool>
         */
        public $capabilities = array();

        /**
         * How many times add_cap() was called.
         *
         * Core's add_cap() rewrites the autoloaded `wp_user_roles` option on
         * every call, so "already granted" has to mean "not called again", not
         * merely "ends up with the same capabilities".
         *
         * @since 1.0.0
         * @var int
         */
        public $add_cap_calls = 0;

        /**
         * Constructor.
         *
         * @since 1.0.0
         *
         * @param string              $role         The role name.
         * @param array<string, bool> $capabilities Capabilities the role starts with.
         */
        public function __construct( $role = '', $capabilities = array() ) {
            $this->name         = (string) $role;
            $this->capabilities = (array) $capabilities;
        }

        /**
         * Grant a capability.
         *
         * @since 1.0.0
         *
         * @param string $cap   The capability name.
         * @param bool   $grant Whether the capability is granted.
         *
         * @return void
         */
        public function add_cap( $cap, $grant = true ) {
            ++$this->add_cap_calls;

            $this->capabilities[ $cap ] = (bool) $grant;
        }

        /**
         * Revoke a capability.
         *
         * @since 1.0.0
         *
         * @param string $cap The capability name.
         *
         * @return void
         */
        public function remove_cap( $cap ) {
            unset( $this->capabilities[ $cap ] );
        }

        /**
         * Whether the role holds a capability.
         *
         * @since 1.0.0
         *
         * @param string $cap The capability name.
         *
         * @return bool True when the capability is granted.
         */
        public function has_cap( $cap ) {
            return ! empty( $this->capabilities[ $cap ] );
        }
    }
}
