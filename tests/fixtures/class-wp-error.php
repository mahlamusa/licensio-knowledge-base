<?php
/**
 * Minimal WP_Error double.
 *
 * Brain Monkey supplies WordPress functions but no classes. The vendored
 * query builder constructs a WP_Error on every statement it runs, so the
 * class has to exist for the installer to be testable.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

if ( ! class_exists( 'WP_Error' ) ) {
    /**
     * Stand-in for the WordPress error object.
     *
     * @since 1.0.0
     */
    class WP_Error {

        /**
         * Error codes mapped to their messages.
         *
         * @since 1.0.0
         * @var array<string, string[]>
         */
        public $errors = array();

        /**
         * Error data, keyed by code.
         *
         * @since 1.0.0
         * @var array<string, mixed>
         */
        public $error_data = array();

        /**
         * Constructor.
         *
         * @since 1.0.0
         *
         * @param string $code    The error code.
         * @param string $message The error message.
         * @param mixed  $data    Optional error data.
         */
        public function __construct( $code = '', $message = '', $data = '' ) {
            if ( '' !== $code ) {
                $this->add( $code, $message, $data );
            }
        }

        /**
         * Add an error.
         *
         * @since 1.0.0
         *
         * @param string $code    The error code.
         * @param string $message The error message.
         * @param mixed  $data    Optional error data.
         *
         * @return void
         */
        public function add( $code, $message = '', $data = '' ) {
            $this->errors[ $code ][] = $message;

            if ( '' !== $data ) {
                $this->error_data[ $code ] = $data;
            }
        }

        /**
         * The first error code.
         *
         * @since 1.0.0
         *
         * @return string The code, or an empty string when there is no error.
         */
        public function get_error_code() {
            $codes = array_keys( $this->errors );

            return empty( $codes ) ? '' : (string) $codes[0];
        }

        /**
         * The first error message.
         *
         * @since 1.0.0
         *
         * @param string $code Optional code to read. Defaults to the first.
         *
         * @return string The message, or an empty string when there is none.
         */
        public function get_error_message( $code = '' ) {
            $code = '' === $code ? $this->get_error_code() : $code;

            return isset( $this->errors[ $code ][0] ) ? (string) $this->errors[ $code ][0] : '';
        }

        /**
         * The data attached to an error.
         *
         * The REST layer puts the HTTP status here, so a test that wants to
         * prove a refusal is a 429 rather than a 403 has to be able to read it.
         *
         * @since 1.0.0
         *
         * @param string $code Optional code to read. Defaults to the first.
         *
         * @return mixed The data, or null when there is none.
         */
        public function get_error_data( $code = '' ) {
            $code = '' === $code ? $this->get_error_code() : $code;

            return isset( $this->error_data[ $code ] ) ? $this->error_data[ $code ] : null;
        }
    }
}
