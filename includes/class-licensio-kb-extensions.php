<?php
/**
 * The extension registry of the content manager apps.
 *
 * Other plugins add columns, filters, bulk actions and row actions to the
 * Articles, Categories and Tags apps by declaring them here, from a callback
 * on `licensio_kb_register_extensions`. PHP declares what exists, its label
 * and who may see it; the plugin's JavaScript supplies the behaviour through
 * `window.licensioKb.extensions.register()`.
 *
 * Every key is namespaced, `{namespace}/{name}` in lowercase letters, digits,
 * dashes and underscores, so two plugins never collide with each other or
 * with the apps' own columns. Every item carries a capability; when none is
 * given it is the capability the app's own page needs.
 *
 * One registry per request, owned by the main plugin class. Consumers detect
 * the API through the `LICENSIO_KB_EXTENSIONS_API` constant.
 *
 * The same API version covers `Licensio_KB_FAQ_Block::entries()`, which hands
 * another plugin the questions and answers a FAQ block renders.
 *
 * @package Licensio_KB
 * @since   1.1.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Holds the extensions declared for the content manager apps.
 *
 * @since 1.1.0
 */
class Licensio_KB_Extensions {

    /**
     * The Articles app screen.
     *
     * @since 1.1.0
     * @var string
     */
    const SCREEN_ARTICLES = 'articles';

    /**
     * The Categories app screen.
     *
     * @since 1.1.0
     * @var string
     */
    const SCREEN_CATEGORIES = 'categories';

    /**
     * The Tags app screen.
     *
     * @since 1.1.0
     * @var string
     */
    const SCREEN_TAGS = 'tags';

    /**
     * Every screen an extension can be added to.
     *
     * @since 1.1.0
     * @var string[]
     */
    const SCREENS = array( self::SCREEN_ARTICLES, self::SCREEN_CATEGORIES, self::SCREEN_TAGS );

    /**
     * Every kind of extension item.
     *
     * @since 1.1.0
     * @var string[]
     */
    const TYPES = array( 'columns', 'filters', 'bulk_actions', 'row_actions' );

    /**
     * What a namespaced key looks like: `{namespace}/{name}`.
     *
     * @since 1.1.0
     * @var string
     */
    const KEY_PATTERN = '/^[a-z0-9_-]+\/[a-z0-9_-]+$/D';

    /**
     * The `admin_init` and `rest_api_init` priority the registry is filled at.
     *
     * Before `Licensio_KB_REST_API::register_controllers()` (priority 10), and
     * before other plugins' `admin_init` callbacks at the default priority.
     *
     * @since 1.1.0
     * @var int
     */
    const PRIORITY = 5;

    /**
     * The declared items, by screen, then type, then key.
     *
     * @since 1.1.0
     * @var array<string, array<string, array<string, array<string, mixed>>>>
     */
    private $items = array();

    /**
     * Whether the register action has fired on this request.
     *
     * @since 1.1.0
     * @var bool
     */
    private $registered = false;

    /**
     * Register the WordPress hooks.
     *
     * The registry is filled on `admin_init` for the app pages and on
     * `rest_api_init` for the REST routes, whichever comes first, both at
     * `PRIORITY`. On `rest_api_init` that is before the plugin's REST
     * controllers register their routes, so their schemas can read it.
     *
     * @since 1.1.0
     *
     * @return void
     */
    public function init_hooks() {
        add_action( 'admin_init', array( $this, 'register' ), self::PRIORITY );
        add_action( 'rest_api_init', array( $this, 'register' ), self::PRIORITY );
    }

    /**
     * Ask other plugins to declare their extensions, once per request.
     *
     * An admin page can build the REST server after `admin_init` (core does
     * so to preload REST data), which fires `rest_api_init` too. The action
     * still fires only once, so nothing is declared twice.
     *
     * @since 1.1.0
     *
     * @return void
     */
    public function register() {
        if ( $this->registered ) {
            return;
        }

        $this->registered = true;

        /**
         * Declare columns, filters, bulk actions and row actions for the
         * Articles, Categories and Tags apps.
         *
         * Fires once per request, on `admin_init` or `rest_api_init` at
         * priority 5, whichever comes first, and never on other front-end
         * requests. Add the callback when your plugin loads, or on
         * `plugins_loaded` or `init`; never from inside an `admin_init` or
         * `rest_api_init` callback, which may run after the action fired, in
         * which case the declarations are silently missed. Check
         * `defined( 'LICENSIO_KB_EXTENSIONS_API' )` first.
         *
         *     add_action( 'licensio_kb_register_extensions', function ( $extensions ) {
         *         $extensions->add_column( 'articles', 'acme/score', array( 'label' => 'Score' ) );
         *     } );
         *
         * Each item's behaviour is registered in JS from a script enqueued on
         * `licensio_kb_enqueue_app_extensions`.
         *
         * @since 1.1.0
         *
         * @param Licensio_KB_Extensions $extensions The registry.
         */
        do_action( 'licensio_kb_register_extensions', $this );
    }

    /**
     * Add a column to an app's list.
     *
     * @since 1.1.0
     *
     * @param string               $screen `articles`, `categories` or `tags`.
     * @param string               $key    The namespaced key, `{namespace}/{name}`.
     * @param array<string, mixed> $args {
     *     The column.
     *
     *     @type string $label           Required. The column heading.
     *     @type string $group           The column picker group. Default none.
     *     @type bool   $default_visible Whether the column shows before the user chooses columns. Default false.
     *     @type bool   $sortable        Whether the list can sort by it. Default false.
     *     @type string $position        The column key it follows. Default empty, the end.
     *     @type string $capability      Who sees it. Default the app page's capability.
     * }
     *
     * @return bool True when the column was added.
     */
    public function add_column( $screen, $key, array $args ) {
        $item = $this->base_item( $screen, $key, $args, __METHOD__ );

        if ( null === $item ) {
            return false;
        }

        $item['group']           = isset( $args['group'] ) ? sanitize_text_field( (string) $args['group'] ) : '';
        $item['default_visible'] = ! empty( $args['default_visible'] );
        $item['sortable']        = ! empty( $args['sortable'] );
        $item['position']        = isset( $args['position'] ) ? self::position( (string) $args['position'] ) : '';

        return $this->store( $screen, 'columns', $key, $item );
    }

    /**
     * Add a filter dropdown to an app's filter bar.
     *
     * @since 1.1.0
     *
     * @param string               $screen `articles`, `categories` or `tags`.
     * @param string               $key    The namespaced key, `{namespace}/{name}`.
     * @param array<string, mixed> $args {
     *     The filter.
     *
     *     @type string                $label      Required. The dropdown's label.
     *     @type array<string, string> $options    Required. Option value to label. Values are sanitised as keys.
     *     @type string                $query_arg  The query argument the core screen uses for it, so the
     *                                             redirect to the app can carry it. Default none.
     *     @type string                $capability Who sees it. Default the app page's capability.
     * }
     *
     * @return bool True when the filter was added.
     */
    public function add_filter( $screen, $key, array $args ) {
        $item = $this->base_item( $screen, $key, $args, __METHOD__ );

        if ( null === $item ) {
            return false;
        }

        $options = array();

        foreach ( isset( $args['options'] ) && is_array( $args['options'] ) ? $args['options'] : array() as $value => $label ) {
            $value = sanitize_key( (string) $value );

            if ( '' !== $value && is_scalar( $label ) ) {
                $options[ $value ] = sanitize_text_field( (string) $label );
            }
        }

        if ( empty( $options ) ) {
            self::doing_it_wrong( __METHOD__, __( 'An extension filter needs at least one option.', 'licensio-knowledge-base' ) );

            return false;
        }

        $item['options']   = $options;
        $item['query_arg'] = isset( $args['query_arg'] ) ? sanitize_key( (string) $args['query_arg'] ) : '';

        return $this->store( $screen, 'filters', $key, $item );
    }

    /**
     * Add an action to an app's bulk action bar.
     *
     * @since 1.1.0
     *
     * @param string               $screen `articles`, `categories` or `tags`.
     * @param string               $key    The namespaced key, `{namespace}/{name}`.
     * @param array<string, mixed> $args {
     *     The bulk action.
     *
     *     @type string $label      Required. The action's label.
     *     @type string $capability Who sees it. Default the app page's capability.
     * }
     *
     * @return bool True when the bulk action was added.
     */
    public function add_bulk_action( $screen, $key, array $args ) {
        $item = $this->base_item( $screen, $key, $args, __METHOD__ );

        return null === $item ? false : $this->store( $screen, 'bulk_actions', $key, $item );
    }

    /**
     * Add an action to the menu of each row in an app's list.
     *
     * @since 1.1.0
     *
     * @param string               $screen `articles`, `categories` or `tags`.
     * @param string               $key    The namespaced key, `{namespace}/{name}`.
     * @param array<string, mixed> $args {
     *     The row action.
     *
     *     @type string $label      Required. The action's label.
     *     @type string $capability Who sees it. Default the app page's capability.
     * }
     *
     * @return bool True when the row action was added.
     */
    public function add_row_action( $screen, $key, array $args ) {
        $item = $this->base_item( $screen, $key, $args, __METHOD__ );

        return null === $item ? false : $this->store( $screen, 'row_actions', $key, $item );
    }

    /**
     * Every declared item of one type on a screen, whoever may see it.
     *
     * @since 1.1.0
     *
     * @param string $screen `articles`, `categories` or `tags`.
     * @param string $type   `columns`, `filters`, `bulk_actions` or `row_actions`.
     *
     * @return array<string, array<string, mixed>> Key to item, in the order they were added.
     */
    public function items( $screen, $type ) {
        return isset( $this->items[ $screen ][ $type ] ) ? $this->items[ $screen ][ $type ] : array();
    }

    /**
     * The declared items of one type on a screen that the current user may see.
     *
     * For use while a request is handled (localising an app, a REST callback),
     * once the current user is known. During `rest_api_init` the REST nonce
     * has not been checked yet, so route schemas read `items()` instead.
     *
     * @since 1.1.0
     *
     * @param string $screen `articles`, `categories` or `tags`.
     * @param string $type   `columns`, `filters`, `bulk_actions` or `row_actions`.
     *
     * @return array<string, array<string, mixed>> Key to item, in the order they were added.
     */
    public function allowed( $screen, $type ) {
        return array_filter(
            $this->items( $screen, $type ),
            function ( $item ) {
                return current_user_can( $item['capability'] );
            }
        );
    }

    /**
     * The checked label, key and capability every item has.
     *
     * @since 1.1.0
     *
     * @param string               $screen The screen.
     * @param string               $key    The namespaced key.
     * @param array<string, mixed> $args   The item as declared.
     * @param string               $method The public method called, for the notice.
     *
     * @return array<string, mixed>|null The item, or null when the screen, key, label or capability is invalid.
     */
    private function base_item( $screen, $key, array $args, $method ) {
        if ( ! in_array( $screen, self::SCREENS, true ) ) {
            /* translators: %s: the screen name given. */
            self::doing_it_wrong( $method, sprintf( __( 'Unknown screen "%s". Use articles, categories or tags.', 'licensio-knowledge-base' ), is_scalar( $screen ) ? (string) $screen : '' ) );

            return null;
        }

        if ( ! is_string( $key ) || ! preg_match( self::KEY_PATTERN, $key ) ) {
            /* translators: %s: the key given. */
            self::doing_it_wrong( $method, sprintf( __( 'Invalid key "%s". Use namespace/name in lowercase letters, digits, dashes and underscores.', 'licensio-knowledge-base' ), is_scalar( $key ) ? (string) $key : '' ) );

            return null;
        }

        $label = isset( $args['label'] ) && is_scalar( $args['label'] ) ? sanitize_text_field( (string) $args['label'] ) : '';

        if ( '' === $label ) {
            self::doing_it_wrong( $method, __( 'An extension item needs a label.', 'licensio-knowledge-base' ) );

            return null;
        }

        if ( isset( $args['capability'] ) && ! is_string( $args['capability'] ) ) {
            self::doing_it_wrong( $method, __( 'An extension item capability must be a string.', 'licensio-knowledge-base' ) );

            return null;
        }

        $capability = isset( $args['capability'] ) ? trim( $args['capability'] ) : '';

        return array(
            'key'        => $key,
            'label'      => $label,
            'capability' => '' !== $capability ? $capability : self::default_capability( $screen ),
        );
    }

    /**
     * Keep an item. A key declared again replaces the earlier item.
     *
     * @since 1.1.0
     *
     * @param string               $screen The screen.
     * @param string               $type   The item type.
     * @param string               $key    The namespaced key.
     * @param array<string, mixed> $item   The checked item.
     *
     * @return bool Always true.
     */
    private function store( $screen, $type, $key, array $item ) {
        $this->items[ $screen ][ $type ][ $key ] = $item;

        return true;
    }

    /**
     * The capability an item gets when it names none: the app page's own.
     *
     * @since 1.1.0
     *
     * @param string $screen The screen.
     *
     * @return string The capability.
     */
    private static function default_capability( $screen ) {
        return self::SCREEN_ARTICLES === $screen ? Licensio_KB_Articles_Admin::capability() : Licensio_KB_Capabilities::CAPABILITY;
    }

    /**
     * A column position: a core column key or another extension's key.
     *
     * @since 1.1.0
     *
     * @param string $position The position given.
     *
     * @return string The position, or an empty string for the end.
     */
    private static function position( $position ) {
        return preg_match( '/^[a-z0-9_-]+(\/[a-z0-9_-]+)?$/D', $position ) ? $position : '';
    }

    /**
     * Report a declaration that was refused.
     *
     * @since 1.1.0
     *
     * @param string $method  The public method called.
     * @param string $message Why it was refused.
     *
     * @return void
     */
    private static function doing_it_wrong( $method, $message ) {
        _doing_it_wrong( esc_html( $method ), esc_html( $message ), '1.1.0' );
    }
}
