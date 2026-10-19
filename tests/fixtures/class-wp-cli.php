<?php
/**
 * Minimal WP_CLI double.
 *
 * `wp licensio-kb reindex` is a shipped entry point, so it needs a test that
 * runs it. WP-CLI is not a Composer dependency of this plugin and its class is
 * absent from both the unit process and static analysis, which without this
 * double leaves the command as the one piece of the search feature that nothing
 * ever executes.
 *
 * Everything the command prints is recorded rather than written out, so a test
 * can assert on the operator-facing output as well as on the side effects.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

if ( ! class_exists( 'WP_CLI' ) ) {
    /**
     * Stand-in for the WP-CLI facade.
     *
     * @since 1.0.0
     */
    class WP_CLI {

        /**
         * Hooks registered through add_hook().
         *
         * @since 1.0.0
         * @var array<int, array<string, mixed>>
         */
        public static $hooks = array();


        /**
         * Commands registered through add_command().
         *
         * @since 1.0.0
         * @var array<int, array{name: string, handler: mixed}>
         */
        public static $commands = array();

        /**
         * Lines written through log().
         *
         * @since 1.0.0
         * @var string[]
         */
        public static $logs = array();

        /**
         * Lines written through success().
         *
         * @since 1.0.0
         * @var string[]
         */
        public static $successes = array();

        /**
         * Lines written through warning().
         *
         * @since 1.0.0
         * @var string[]
         */
        public static $warnings = array();

        /**
         * Forget everything recorded so far.
         *
         * @since 1.0.0
         *
         * @return void
         */
        public static function reset() {
            self::$commands  = array();
            self::$logs      = array();
            self::$successes = array();
            self::$warnings  = array();
        }

        /**
         * Register a command.
         *
         * @since 1.0.0
         *
         * @param string $name    The command name.
         * @param mixed  $handler The command handler.
         * @param array  $args    Registration arguments.
         *
         * @return bool Always true.
         */
        public static function add_command( $name, $handler, $args = array() ) {
            unset( $args );

            self::$commands[] = array(
                'name'    => (string) $name,
                'handler' => $handler,
            );

            return true;
        }

        /**
         * Record an informational line.
         *
         * @since 1.0.0
         *
         * @param string $message The message.
         *
         * @return void
         */
        public static function log( $message ) {
            self::$logs[] = (string) $message;
        }

        /**
         * Record a success line.
         *
         * @since 1.0.0
         *
         * @param string $message The message.
         *
         * @return void
         */
        public static function success( $message ) {
            self::$successes[] = (string) $message;
        }

        /**
         * Record a warning line.
         *
         * @since 1.0.0
         *
         * @param string $message The message.
         *
         * @return void
         */
        public static function warning( $message ) {
            self::$warnings[] = (string) $message;
        }

        /**
         * Fail the command.
         *
         * @since 1.0.0
         *
         * @param string $message The message.
         *
         * @return void
         * @throws RuntimeException Always, standing in for WP-CLI halting the process.
         */
        public static function error( $message ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A test double; the message never reaches a browser.
            throw new RuntimeException( (string) $message );
        }

        /**
         * Record a WP-CLI hook registration.
         *
         * @since 1.0.0
         *
         * @param string   $name     The hook name.
         * @param callable $callback The callback.
         *
         * @return void
         */
        public static function add_hook( $name, $callback ) {
            self::$hooks[] = array(
                'name'     => (string) $name,
                'callback' => $callback,
            );
        }
    }
}
