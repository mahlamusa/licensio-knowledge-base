<?php
/**
 * REST API registrar.
 *
 * Owns the `lisensio-kb/v1` namespace and the list of controllers registered
 * against it. A sub-issue adds its controller by filtering
 * `lisensio_rest_controllers` rather than hooking `rest_api_init` itself, so
 * every route in the plugin is registered from one place in one order.
 *
 * Consumed by: #4, #8, #9, #10.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the knowledge base REST controllers.
 *
 * @since 1.0.0
 */
class Lisensio_KB_REST_API {

    /**
     * The REST namespace every controller registers against.
     *
     * Derived from `LISENSIO_KB_REST_NAMESPACE`, which is the one place the
     * namespace is spelled out. It was previously written out three times — the
     * constant, this class member and the controller property — so a v2 bump
     * that changed one of them would have split the API surface across two
     * namespaces with nothing to catch it.
     *
     * @since 1.0.0
     * @var string
     */
    const NAMESPACE_V1 = LISENSIO_KB_REST_NAMESPACE;

    /**
     * Register the WordPress hooks.
     *
     * Controllers register at priority 10; the not-implemented stubs at 100,
     * so a real implementation always takes the route ahead of its stub.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function init_hooks() {
        add_action( 'rest_api_init', array( $this, 'register_controllers' ), 10 );
        add_action( 'rest_api_init', array( $this, 'register_stubs' ), 100 );
    }

    /**
     * Instantiate and register every controller.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function register_controllers() {
        /**
         * Filter the REST controller class names registered by the plugin.
         *
         * Each entry must name a class extending Lisensio_KB_REST_Controller.
         *
         * @since 1.0.0
         *
         * @param string[] $controllers Controller class names.
         */
        $controllers = apply_filters( 'lisensio_rest_controllers', array() );

        foreach ( (array) $controllers as $class_name ) {
            if ( ! is_string( $class_name ) || ! class_exists( $class_name ) ) {
                continue;
            }

            /*
             * Checked before instantiating, not after. Constructing first ran the
             * constructor of any class the filter named, whatever it was, and
             * only then asked whether it was one of ours.
             */
            if ( ! is_subclass_of( $class_name, 'Lisensio_KB_REST_Controller' ) ) {
                continue;
            }

            $controller = new $class_name();

            $controller->register_routes();
        }
    }

    /**
     * Register the reserved routes that have no implementation yet.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function register_stubs() {
        $stubs = new Lisensio_KB_REST_Stubs_Controller();
        $stubs->register_routes();
    }

    /**
     * The plugin's REST namespace.
     *
     * @since 1.0.0
     *
     * @return string The versioned namespace.
     */
    public static function get_namespace() {
        return self::NAMESPACE_V1;
    }

    /**
     * The base URL for the plugin's REST namespace.
     *
     * @since 1.0.0
     *
     * @param string $route Optional route path appended to the namespace.
     *
     * @return string The absolute REST URL.
     */
    public static function url( $route = '' ) {
        return rest_url( self::NAMESPACE_V1 . '/' . ltrim( $route, '/' ) );
    }
}
