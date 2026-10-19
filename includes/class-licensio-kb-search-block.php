<?php
/**
 * The search block and its shortcode.
 *
 * `licensio-kb/search` and `[licensio_kb_search]` are the same feature reached
 * two ways, so they share one render callback and emit byte-identical markup.
 * A page builder that cannot host a block gets exactly what the block editor
 * renders, which is the whole point of pairing them.
 *
 * The markup is resolved through `licensio_kb_get_template()` first, so the
 * classic and block template layers can override the form without touching
 * this class. The built-in markup below is the fallback, and it is what ships
 * until a template set exists.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers and renders the knowledge base search block and shortcode.
 *
 * @since 1.0.0
 */
class Licensio_KB_Search_Block {

    /**
     * The block name.
     *
     * @since 1.0.0
     * @var string
     */
    const BLOCK = 'licensio-kb/search';

    /**
     * The shortcode tag.
     *
     * @since 1.0.0
     * @var string
     */
    const SHORTCODE = 'licensio_kb_search';

    /**
     * The script handle for the type-ahead.
     *
     * @since 1.0.0
     * @var string
     */
    const SCRIPT_HANDLE = 'licensio-kb-search';

    /**
     * The stylesheet handle for the search form.
     *
     * @since 1.0.0
     * @var string
     */
    const STYLE_HANDLE = 'licensio-kb-search';

    /**
     * How many search forms have been rendered on this page.
     *
     * Used to give every instance a unique element ID, which the ARIA combobox
     * pattern requires: `aria-controls` and `aria-activedescendant` are ID
     * references, and two forms sharing one ID would point a screen reader at
     * the wrong listbox.
     *
     * @since 1.0.0
     * @var int
     */
    private $instances = 0;

    /**
     * Whether the runtime configuration has already been printed.
     *
     * @since 1.0.0
     * @var bool
     */
    private $configured = false;

    /**
     * Register the WordPress hooks.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function init_hooks() {
        add_action( 'init', array( $this, 'register_assets' ), 5 );
        add_action( 'init', array( $this, 'register' ), 20 );
    }

    /**
     * Register the block's scripts and styles.
     *
     * Registered on `init` rather than `wp_enqueue_scripts` because
     * `register_block_type()` resolves the handles named in `block.json` at
     * registration time, and because the editor needs them too.
     *
     * Only the hand-written frontend assets are registered here. The editor
     * bundle is built by wp-scripts and declared in `block.json` as
     * `file:./index.js`, which WordPress registers itself from the built
     * metadata.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function register_assets() {
        wp_register_style(
            self::STYLE_HANDLE,
            LICENSIO_KB_ASSETS_URL . 'css/search.css',
            array(),
            LICENSIO_KB_VERSION
        );

        wp_register_script(
            self::SCRIPT_HANDLE,
            LICENSIO_KB_ASSETS_URL . 'js/search.js',
            array( Licensio_KB_Assets::FRONTEND_NAMESPACE_HANDLE ),
            LICENSIO_KB_VERSION,
            true
        );
    }

    /**
     * Register the block and the shortcode.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function register() {
        add_shortcode( self::SHORTCODE, array( $this, 'render_shortcode' ) );

        $metadata = self::metadata_path();

        if ( '' === $metadata || ! function_exists( 'register_block_type' ) ) {
            return;
        }

        register_block_type(
            $metadata,
            array(
                'render_callback' => array( $this, 'render' ),
            )
        );
    }

    /**
     * The directory `block.json` is registered from.
     *
     * The built copy in `dist/` is preferred, because that is the one whose
     * `file:./index.js` reference points at a compiled bundle. The source
     * directory is only used when the plugin is running from a checkout that has
     * never been built, where registering the block is still worth doing: the
     * server-rendered form works, and only the editor bundle is missing.
     *
     * @since 1.0.0
     *
     * @return string The absolute directory path, or an empty string when there is none.
     */
    public static function metadata_path() {
        $candidates = array(
            LICENSIO_KB_DIST_PATH . 'blocks/search',
            LICENSIO_KB_ABSPATH . 'blocks/search',
        );

        foreach ( $candidates as $candidate ) {
            if ( is_readable( $candidate . '/block.json' ) ) {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * The attributes a search form accepts, and their defaults.
     *
     * One definition serves both entry points, so the block and the shortcode
     * cannot drift apart on what they support.
     *
     * @since 1.0.0
     *
     * @return array<string, string> Attribute name to default value.
     */
    public static function defaults() {
        return array(
            'placeholder' => __( 'Search the knowledge base…', 'licensio-knowledge-base' ),
            'label'       => __( 'Search the knowledge base', 'licensio-knowledge-base' ),
            'button'      => __( 'Search', 'licensio-knowledge-base' ),
        );
    }

    /**
     * Render the shortcode.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed>|string $atts The shortcode attributes.
     *
     * @return string The rendered search form.
     */
    public function render_shortcode( $atts ) {
        $atts = shortcode_atts( self::defaults(), is_array( $atts ) ? $atts : array(), self::SHORTCODE );

        return $this->render( $atts );
    }

    /**
     * Render the search form.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $attributes The block or shortcode attributes.
     *
     * @return string The rendered search form.
     */
    public function render( $attributes = array() ) {
        /*
         * A block attribute the editor has not been given a value for arrives as
         * an empty string, not as a missing key, so wp_parse_args() alone would
         * keep it and render a form with no placeholder and no button label. The
         * empty values are dropped first so the registered defaults win.
         */
        $supplied = array();

        foreach ( (array) $attributes as $key => $value ) {
            if ( is_scalar( $value ) && '' !== (string) $value ) {
                $supplied[ $key ] = $value;
            }
        }

        $attributes = wp_parse_args( $supplied, self::defaults() );

        ++$this->instances;

        $args = array(
            'id'          => 'licensio-kb-search-' . $this->instances,
            'placeholder' => (string) $attributes['placeholder'],
            'label'       => (string) $attributes['label'],
            'button'      => (string) $attributes['button'],
            'action'      => self::results_url(),
            'value'       => is_search() ? (string) get_search_query() : '',
        );

        $this->enqueue();

        $template = licensio_kb_get_template( 'search-form', $args, false );

        return '' !== $template ? $template : self::markup( $args );
    }

    /**
     * Enqueue the assets a rendered search form needs.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function enqueue() {
        if ( ! function_exists( 'wp_enqueue_script' ) ) {
            return;
        }

        wp_enqueue_style( self::STYLE_HANDLE );
        wp_enqueue_script( self::SCRIPT_HANDLE );

        if ( $this->configured ) {
            return;
        }

        $this->configured = true;

        wp_add_inline_script(
            self::SCRIPT_HANDLE,
            'window.licensioKB = window.licensioKB || {}; window.licensioKB.search = '
                . wp_json_encode( self::config() ) . ';',
            'before'
        );
    }

    /**
     * The configuration the type-ahead reads at runtime.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed> The frontend configuration.
     */
    public static function config() {
        $config = array(
            'endpoint' => Licensio_KB_REST_API::url( 'search' ),
            'minChars' => 2,
            'debounce' => 250,
            'perPage'  => 8,
            'source'   => 'live',
            'i18n'     => array(
                'noResults' => __( 'No articles found. Press Enter to search the whole knowledge base.', 'licensio-knowledge-base' ),
                'error'     => __( 'Search is unavailable right now. Press Enter to try the full results page.', 'licensio-knowledge-base' ),
                /* translators: %d: number of matching articles */
                'results'   => __( '%d articles found. Use the up and down arrow keys to review them.', 'licensio-knowledge-base' ),
                'oneResult' => __( '1 article found. Use the up and down arrow keys to review it.', 'licensio-knowledge-base' ),
            ),
        );

        /**
         * Filter the configuration passed to the search type-ahead.
         *
         * @since 1.0.0
         *
         * @param array<string, mixed> $config The frontend configuration.
         */
        return apply_filters( 'licensio_search_frontend_config', $config );
    }

    /**
     * Where the form submits when nothing in the listbox is selected.
     *
     * @since 1.0.0
     *
     * @return string The full results page URL.
     */
    public static function results_url() {
        $archive = get_post_type_archive_link( Licensio_KB_Post_Types::POST_TYPE );
        $url     = is_string( $archive ) && '' !== $archive ? $archive : home_url( '/' );

        /**
         * Filter the URL the search form submits to.
         *
         * @since 1.0.0
         *
         * @param string $url The full results page URL.
         */
        return (string) apply_filters( 'licensio_search_results_url', $url );
    }

    /**
     * The built-in search form markup.
     *
     * Implements the ARIA 1.2 combobox pattern: the input carries the
     * `combobox` role together with `aria-expanded`, `aria-controls` and
     * `aria-autocomplete`, and the popup is a sibling `listbox`. The script adds
     * `aria-activedescendant` as the visitor arrows through the options. The
     * status paragraph is what announces the result count, because a listbox
     * appearing is not itself announced.
     *
     * @since 1.0.0
     *
     * @param array<string, string> $args The resolved form arguments.
     *
     * @return string The markup.
     */
    public static function markup( array $args ) {
        $id      = sanitize_html_class( $args['id'] );
        $listbox = $id . '-listbox';

        $markup = sprintf(
            '<div class="licensio-kb licensio-kb-search" data-licensio-kb-search>
    <form class="licensio-kb-search__form" role="search" method="get" action="%1$s">
        <label class="licensio-kb-search__label screen-reader-text" for="%2$s">%3$s</label>
        <div class="licensio-kb-search__field">
            <input
                type="search"
                class="licensio-kb-search__input"
                id="%2$s"
                name="s"
                value="%4$s"
                placeholder="%5$s"
                autocomplete="off"
                spellcheck="false"
                role="combobox"
                aria-expanded="false"
                aria-controls="%6$s"
                aria-autocomplete="list"
                aria-haspopup="listbox"
            />
            <button type="submit" class="licensio-kb-search__submit">%7$s</button>
            <ul class="licensio-kb-search__results" id="%6$s" role="listbox" aria-label="%3$s" hidden></ul>
        </div>
        <input type="hidden" name="post_type" value="%8$s" />
        <p class="licensio-kb-search__status screen-reader-text" role="status" aria-live="polite"></p>
    </form>
</div>',
            esc_url( $args['action'] ),
            esc_attr( $id ),
            esc_attr( $args['label'] ),
            esc_attr( $args['value'] ),
            esc_attr( $args['placeholder'] ),
            esc_attr( $listbox ),
            esc_html( $args['button'] ),
            esc_attr( Licensio_KB_Post_Types::POST_TYPE )
        );

        /**
         * Filter the rendered search form markup.
         *
         * @since 1.0.0
         *
         * @param string                $markup The markup.
         * @param array<string, string> $args   The resolved form arguments.
         */
        return (string) apply_filters( 'licensio_search_form_markup', $markup, $args );
    }
}
