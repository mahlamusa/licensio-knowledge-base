<?php
/**
 * Settings REST controller.
 *
 * Reads, writes and resets the plugin settings for a caller holding
 * `manage_lisensio_kb`.
 *
 * Two properties of the write route are the reason it exists at all rather
 * than the screen posting straight into Lisensio_KB_Settings::update():
 *
 * - **Unknown keys are refused.** The store skips a key it does not recognise,
 *   which is right for a store — a caller cannot corrupt the option — but wrong
 *   for an API, where a typo would return 200 and quietly change nothing.
 * - **A save is all or nothing.** The store sanitises each value on its own and
 *   falls back to the default when one is unusable, so a payload with one bad
 *   field would half-apply. Every field is validated here before a single one is
 *   written, and the first failure aborts the whole save.
 *
 * The registry is still the only definition of what a setting is. Nothing in
 * this class knows the name of a setting; it validates whatever the registry
 * declares, so a key added through `lisensio_settings_registry` is validated
 * against its own declared type and bounds with no change here.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers and answers `/settings`.
 *
 * @since 1.0.0
 */
class Lisensio_KB_REST_Settings_Controller extends Lisensio_KB_REST_Controller {

    /**
     * The route this controller owns.
     *
     * @since 1.0.0
     * @var string
     */
    const ROUTE = '/settings';

    /**
     * The settings store.
     *
     * @since 1.0.0
     * @var Lisensio_KB_Settings
     */
    private $settings;

    /**
     * The presentation schema the settings screen renders itself from.
     *
     * Named `screen_schema` rather than `schema` because WP_REST_Controller
     * already declares a protected `$schema` holding a JSON Schema array, and
     * shadowing it with an object of a different type would be a real
     * incompatibility, not a naming preference.
     *
     * @since 1.0.0
     * @var Lisensio_KB_Settings_Schema
     */
    private $screen_schema;

    /**
     * Build the controller.
     *
     * Both collaborators default to a fresh instance so
     * Lisensio_KB_REST_API::register_controllers(), which instantiates a
     * controller by class name with no arguments, still works. A test injects
     * its own to isolate this layer from the store's sanitisers.
     *
     * @since 1.0.0
     *
     * @param Lisensio_KB_Settings|null        $settings The settings store.
     * @param Lisensio_KB_Settings_Schema|null $schema   The presentation schema.
     */
    public function __construct( $settings = null, $schema = null ) {
        $this->settings      = ( $settings instanceof Lisensio_KB_Settings ) ? $settings : new Lisensio_KB_Settings();
        $this->screen_schema = ( $schema instanceof Lisensio_KB_Settings_Schema ) ? $schema : new Lisensio_KB_Settings_Schema( $this->settings );
    }

    /**
     * Register the settings route.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function register_routes() {
        register_rest_route(
            $this->namespace,
            self::ROUTE,
            array(
                array(
                    'methods'             => 'GET',
                    'callback'            => array( $this, 'get_item' ),
                    'permission_callback' => array( $this, 'manage_permissions_check' ),
                    'args'                => array(),
                ),
                array(
                    'methods'             => 'POST',
                    'callback'            => array( $this, 'update_item' ),
                    'permission_callback' => array( $this, 'manage_permissions_check' ),
                    'args'                => $this->update_args(),
                ),
                array(
                    'methods'             => 'DELETE',
                    'callback'            => array( $this, 'delete_item' ),
                    'permission_callback' => array( $this, 'manage_permissions_check' ),
                    'args'                => array(),
                ),
            )
        );
    }

    /**
     * The arguments the write route accepts.
     *
     * The values arrive nested under one `settings` object rather than as
     * top-level parameters. WordPress merges query, body and URL parameters and
     * adds its own — `_locale`, `_envelope`, `context` — so a flat body would
     * make this route reject core's own parameters as unknown settings.
     *
     * @since 1.0.0
     *
     * @return array<string, array<string, mixed>> The argument schema.
     */
    public function update_args() {
        return array(
            'settings' => array(
                'description' => __( 'The settings to write, keyed by setting name.', 'lisensio-knowledge-base' ),
                'type'        => 'object',
                'required'    => true,
            ),
        );
    }

    /**
     * Answer the current settings.
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request The request.
     *
     * @return WP_REST_Response The settings, the registry defaults and the screen schema.
     */
    public function get_item( $request ) {
        unset( $request );

        return rest_ensure_response( $this->payload() );
    }

    /**
     * Write a settings payload.
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request The request.
     *
     * @return WP_REST_Response|WP_Error The stored settings, or the per-field errors.
     */
    public function update_item( $request ) {
        $submitted = $request->get_param( 'settings' );

        if ( ! is_array( $submitted ) ) {
            return new WP_Error(
                'lisensio_invalid_settings',
                __( 'The settings must be sent as an object keyed by setting name.', 'lisensio-knowledge-base' ),
                array( 'status' => 400 )
            );
        }

        if ( empty( $submitted ) ) {
            return new WP_Error(
                'lisensio_no_settings',
                __( 'No settings were submitted.', 'lisensio-knowledge-base' ),
                array( 'status' => 400 )
            );
        }

        $validated = $this->validate( $submitted );

        if ( is_wp_error( $validated ) ) {
            return $validated;
        }

        $this->settings->update( $validated );

        return rest_ensure_response( $this->payload() );
    }

    /**
     * Reset every setting to its registered default.
     *
     * The stored option is deleted rather than overwritten with a computed
     * defaults array, so the result is exactly what the registry declares even
     * for a key this plugin has never heard of.
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request The request.
     *
     * @return WP_REST_Response The restored settings.
     */
    public function delete_item( $request ) {
        unset( $request );

        $before = $this->settings->all();

        $this->settings->delete();

        $after = $this->settings->all();

        /*
         * Deleting the option bypasses Lisensio_KB_Settings::update(), which is
         * what normally raises the rewrite flag. Without this, resetting a
         * customised article base would leave the old rewrite rules in place and
         * every knowledge base URL 404ing until somebody saved permalinks.
         */
        foreach ( $this->settings->registry() as $key => $definition ) {
            if ( empty( $definition['flush_rewrites'] ) ) {
                continue;
            }

            $was = array_key_exists( $key, $before ) ? $before[ $key ] : null;
            $now = array_key_exists( $key, $after ) ? $after[ $key ] : null;

            if ( $was !== $now ) {
                Lisensio_KB_Settings::request_rewrite_flush();

                break;
            }
        }

        return rest_ensure_response( $this->payload() );
    }

    /**
     * The body every route answers with.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed> The settings, the defaults and the schema.
     */
    private function payload() {
        return array(
            'settings' => $this->settings->all(),
            'defaults' => $this->settings->defaults(),
            'schema'   => $this->screen_schema->schema(),
        );
    }

    /**
     * Validate a submitted payload field by field.
     *
     * Nothing is written from here. The caller gets either a complete, clean
     * payload or an error naming every field that failed, which is what makes a
     * partial save impossible.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $submitted The submitted settings.
     *
     * @return array<string, mixed>|WP_Error The validated values, or the per-field errors.
     */
    private function validate( array $submitted ) {
        $registry = $this->settings->registry();
        $unknown  = array_diff( array_keys( $submitted ), array_keys( $registry ) );

        if ( ! empty( $unknown ) ) {
            $params = array();

            foreach ( $unknown as $key ) {
                $params[ (string) $key ] = __( 'This is not a knowledge base setting.', 'lisensio-knowledge-base' );
            }

            return new WP_Error(
                'lisensio_unknown_setting',
                sprintf(
                    /* translators: %s: comma-separated list of unrecognised setting names. */
                    __( 'These are not knowledge base settings: %s.', 'lisensio-knowledge-base' ),
                    implode( ', ', array_map( 'strval', $unknown ) )
                ),
                array(
                    'status' => 400,
                    'params' => $params,
                )
            );
        }

        $clean  = array();
        $errors = array();

        foreach ( $submitted as $key => $value ) {
            $result = self::validate_value( $value, (array) $registry[ $key ] );

            if ( is_wp_error( $result ) ) {
                $errors[ (string) $key ] = $result->get_error_message();

                continue;
            }

            $clean[ $key ] = $result;
        }

        if ( ! empty( $errors ) ) {
            return new WP_Error(
                'lisensio_invalid_setting',
                __( 'One or more settings are invalid, so nothing was saved.', 'lisensio-knowledge-base' ),
                array(
                    'status' => 400,
                    'params' => $errors,
                )
            );
        }

        return $clean;
    }

    /**
     * Validate one value against its registry entry.
     *
     * Deliberately stricter than the store. The store coerces: an out-of-range
     * number is clamped and an unusable value falls back to the default, both of
     * which are the right behaviour when *reading* an option somebody edited by
     * hand, and the wrong behaviour when answering a request, where silently
     * storing something other than what was sent is indistinguishable from a
     * bug.
     *
     * @since 1.0.0
     *
     * @param mixed                $value      The submitted value.
     * @param array<string, mixed> $definition The registry entry.
     *
     * @return mixed|WP_Error The validated value, or an error describing the failure.
     */
    public static function validate_value( $value, array $definition ) {
        $type = isset( $definition['type'] ) ? (string) $definition['type'] : 'string';

        switch ( $type ) {
            case 'bool':
                return self::validate_bool( $value );
            case 'int':
                return self::validate_int( $value, $definition );
            case 'string':
                return self::validate_string( $value, $definition );
            default:
                /*
                 * A type this class has no opinion on — a registry extension
                 * declaring `array` or `float`. It is handed to the registry's
                 * own sanitiser if it has one, and otherwise passed through to
                 * the store, which casts it.
                 */
                return self::apply_sanitizer( $value, $definition );
        }
    }

    /**
     * Validate a boolean setting.
     *
     * @since 1.0.0
     *
     * @param mixed $value The submitted value.
     *
     * @return bool|WP_Error The boolean, or an error.
     */
    private static function validate_bool( $value ) {
        if ( is_bool( $value ) ) {
            return $value;
        }

        $truthy = array( 1, '1', 'true' );
        $falsy  = array( 0, '0', 'false' );

        if ( in_array( $value, $truthy, true ) ) {
            return true;
        }

        if ( in_array( $value, $falsy, true ) ) {
            return false;
        }

        return new WP_Error(
            'lisensio_invalid_setting',
            __( 'This setting must be true or false.', 'lisensio-knowledge-base' )
        );
    }

    /**
     * Validate a whole-number setting against its bounds.
     *
     * @since 1.0.0
     *
     * @param mixed                $value      The submitted value.
     * @param array<string, mixed> $definition The registry entry.
     *
     * @return int|WP_Error The number, or an error.
     */
    private static function validate_int( $value, array $definition ) {
        $is_integer = is_int( $value );
        $is_digits  = is_string( $value ) && (bool) preg_match( '/^-?\d+$/', $value );

        if ( ! $is_integer && ! $is_digits ) {
            return new WP_Error(
                'lisensio_invalid_setting',
                __( 'This setting must be a whole number.', 'lisensio-knowledge-base' )
            );
        }

        $number = (int) $value;

        $min = isset( $definition['min'] ) ? (int) $definition['min'] : null;
        $max = isset( $definition['max'] ) ? (int) $definition['max'] : null;

        if ( ( null !== $min && $number < $min ) || ( null !== $max && $number > $max ) ) {
            if ( null !== $min && null !== $max ) {
                return new WP_Error(
                    'lisensio_invalid_setting',
                    sprintf(
                        /* translators: 1: lowest accepted number, 2: highest accepted number. */
                        __( 'This setting must be between %1$d and %2$d.', 'lisensio-knowledge-base' ),
                        $min,
                        $max
                    )
                );
            }

            if ( null !== $min ) {
                return new WP_Error(
                    'lisensio_invalid_setting',
                    sprintf(
                        /* translators: %d: lowest accepted number. */
                        __( 'This setting must be %d or more.', 'lisensio-knowledge-base' ),
                        $min
                    )
                );
            }

            return new WP_Error(
                'lisensio_invalid_setting',
                sprintf(
                    /* translators: %d: highest accepted number. */
                    __( 'This setting must be %d or less.', 'lisensio-knowledge-base' ),
                    $max
                )
            );
        }

        $sanitized = self::apply_sanitizer( $number, $definition );

        return is_wp_error( $sanitized ) ? $sanitized : (int) $sanitized;
    }

    /**
     * Validate a text setting.
     *
     * @since 1.0.0
     *
     * @param mixed                $value      The submitted value.
     * @param array<string, mixed> $definition The registry entry.
     *
     * @return string|WP_Error The text, or an error.
     */
    private static function validate_string( $value, array $definition ) {
        if ( ! is_string( $value ) ) {
            return new WP_Error(
                'lisensio_invalid_setting',
                __( 'This setting must be text.', 'lisensio-knowledge-base' )
            );
        }

        $sanitized = self::apply_sanitizer( $value, $definition );

        return is_wp_error( $sanitized ) ? $sanitized : (string) $sanitized;
    }

    /**
     * Run the registry's own sanitiser and treat a rejection as an error.
     *
     * The registry sanitisers signal "unusable" by returning null, which the
     * store reads as "use the default". Here it means the request was wrong, so
     * it becomes a per-field message instead.
     *
     * @since 1.0.0
     *
     * @param mixed                $value      The submitted value.
     * @param array<string, mixed> $definition The registry entry.
     *
     * @return mixed|WP_Error The sanitised value, or an error.
     */
    private static function apply_sanitizer( $value, array $definition ) {
        $sanitize = isset( $definition['sanitize'] ) ? $definition['sanitize'] : null;

        if ( null === $sanitize || ! is_callable( $sanitize ) ) {
            return $value;
        }

        $sanitized = call_user_func( $sanitize, $value );

        if ( null === $sanitized ) {
            return new WP_Error(
                'lisensio_invalid_setting',
                __( 'This value is not accepted for this setting.', 'lisensio-knowledge-base' )
            );
        }

        return $sanitized;
    }
}
