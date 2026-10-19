<?php
/**
 * Class autoloader.
 *
 * Maps the `Licensio_KB_*` class names onto the WordPress class-file
 * convention (`class-licensio-kb-*.php`) inside `includes/` and its
 * sub-directories. Scaffold infrastructure — every sub-issue that adds a
 * class under `includes/` is autoloaded by this file with no registration
 * step.
 *
 * Consumed by: #3, #4, #5, #6, #7, #8, #9, #10, #11, #12.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Autoloads plugin classes from the includes directory.
 *
 * @since 1.0.0
 */
class Licensio_KB_Autoloader {

    /**
     * Directories searched for class files, relative to `includes/`.
     *
     * An empty string means `includes/` itself. Sub-issues may append to this
     * list through the `licensio_autoload_paths` filter.
     *
     * @since 1.0.0
     * @var string[]
     */
    private static $paths = array( '', 'abstracts/', 'admin/', 'rest/', 'import/' );

    /**
     * Register the autoloader with SPL.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public static function register() {
        spl_autoload_register( array( __CLASS__, 'autoload' ) );
    }

    /**
     * Resolve and load a plugin class.
     *
     * @since 1.0.0
     *
     * @param string $class_name Fully resolved class name being loaded.
     *
     * @return void
     */
    public static function autoload( $class_name ) {
        if ( 0 !== strpos( $class_name, 'Licensio_KB' ) || false !== strpos( $class_name, '\\' ) ) {
            return;
        }

        /*
         * class_exists() fires every registered autoloader with whatever string
         * it was given, and Licensio_KB_REST_API::register_controllers() feeds it
         * a filter-supplied value. Anything that is not a bare class name cannot
         * name a file here, so it is rejected before it becomes a path.
         */
        if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $class_name ) ) {
            return;
        }

        $file = 'class-' . str_replace( '_', '-', strtolower( $class_name ) ) . '.php';

        /**
         * Filter the directories searched by the plugin autoloader.
         *
         * Paths are relative to the plugin `includes/` directory and must end
         * with a trailing slash. An empty string means `includes/` itself.
         *
         * @since 1.0.0
         *
         * @param string[] $paths      Relative directory paths.
         * @param string   $class_name The class being autoloaded.
         */
        $paths = apply_filters( 'licensio_autoload_paths', self::$paths, $class_name );

        foreach ( (array) $paths as $path ) {
            $candidate = LICENSIO_KB_ABSPATH . 'includes/' . $path . $file;

            if ( is_readable( $candidate ) ) {
                require_once $candidate;

                return;
            }
        }
    }
}
