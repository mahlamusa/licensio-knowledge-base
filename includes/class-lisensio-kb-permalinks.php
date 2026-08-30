<?php
/**
 * Permalink configuration.
 *
 * The article base (`article_slug`, default `docs`) and the two taxonomy bases
 * are already registered settings, and Lisensio_KB_Post_Types reads them when
 * it builds the rewrite arguments. This class adds the remaining permalink
 * decision the specification calls for — whether a category URL repeats the
 * whole term hierarchy — and registers it in the same settings registry so it
 * inherits the same "flush only when the stored value actually changes"
 * behaviour as the bases.
 *
 * Nothing here calls `flush_rewrite_rules()`. A settings write happens long
 * before `init`, when the old permastructs are still the registered ones, so
 * Lisensio_KB_Settings records a flush request that Lisensio_KB_Post_Types
 * honours once on the next `init`.
 *
 * Consumed by: #5, #6, #10.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers and applies the knowledge base permalink settings.
 *
 * @since 1.0.0
 */
class Lisensio_KB_Permalinks {

    /**
     * Setting name for the article base.
     *
     * Registered by the scaffold; named here so consumers have one place to
     * read the key from rather than repeating the string.
     *
     * @since 1.0.0
     * @var string
     */
    const SETTING_ARTICLE_BASE = 'article_slug';

    /**
     * Setting name for the hierarchical category URL toggle.
     *
     * @since 1.0.0
     * @var string
     */
    const SETTING_HIERARCHICAL = 'hierarchical_category_urls';

    /**
     * The article base used when nothing valid is stored.
     *
     * @since 1.0.0
     * @var string
     */
    const DEFAULT_ARTICLE_BASE = 'docs';

    /**
     * Settings store.
     *
     * @since 1.0.0
     * @var Lisensio_KB_Settings
     */
    private $settings;

    /**
     * Constructor.
     *
     * @since 1.0.0
     *
     * @param Lisensio_KB_Settings $settings The settings store.
     */
    public function __construct( Lisensio_KB_Settings $settings ) {
        $this->settings = $settings;
    }

    /**
     * Register the WordPress hooks.
     *
     * Must run before anything reads a setting: Lisensio_KB_Settings caches the
     * resolved values on first read, and a key added to the registry afterwards
     * would be missing from that cache for the rest of the request.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function init_hooks() {
        add_filter( 'lisensio_settings_registry', array( $this, 'register_settings' ) );
        add_filter( 'lisensio_category_taxonomy_args', array( $this, 'filter_category_args' ) );
    }

    /**
     * Add the permalink settings this class owns to the settings registry.
     *
     * `flush_rewrites` is what makes the toggle take effect without anybody
     * visiting the permalinks screen, and — because Lisensio_KB_Settings only
     * sets the flag when the sanitized value differs from the resolved one —
     * what stops a no-op save from rebuilding the rules.
     *
     * @since 1.0.0
     *
     * @param array<string, array<string, mixed>> $registry The settings registry.
     *
     * @return array<string, array<string, mixed>> The registry, with the permalink settings added.
     */
    public function register_settings( $registry ) {
        if ( ! is_array( $registry ) ) {
            return $registry;
        }

        $registry[ self::SETTING_HIERARCHICAL ] = array(
            'type'           => 'bool',
            'default'        => true,
            'flush_rewrites' => true,
        );

        return $registry;
    }

    /**
     * Apply the hierarchical category URL toggle to the taxonomy arguments.
     *
     * With the toggle on, `/docs-category/parent/child/` addresses a child
     * term. With it off, `/docs-category/child/` does, which keeps URLs short
     * but means a term slug has to be unique across the whole taxonomy rather
     * than only among its siblings.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $args The taxonomy registration arguments.
     *
     * @return array<string, mixed> The arguments, with the rewrite toggle applied.
     */
    public function filter_category_args( $args ) {
        if ( ! is_array( $args ) || empty( $args['rewrite'] ) || ! is_array( $args['rewrite'] ) ) {
            return $args;
        }

        $args['rewrite']['hierarchical'] = $this->hierarchical_category_urls();

        return $args;
    }

    /**
     * Whether category URLs repeat the term hierarchy.
     *
     * @since 1.0.0
     *
     * @return bool True when a category URL contains its ancestors.
     */
    public function hierarchical_category_urls() {
        return (bool) $this->settings->get( self::SETTING_HIERARCHICAL, true );
    }

    /**
     * The configured article base.
     *
     * @since 1.0.0
     *
     * @return string The article base, never empty.
     */
    public function article_base() {
        $base = (string) $this->settings->get( self::SETTING_ARTICLE_BASE, self::DEFAULT_ARTICLE_BASE );

        return '' === $base ? self::DEFAULT_ARTICLE_BASE : $base;
    }

    /**
     * Ask for the rewrite rules to be rebuilt on the next `init`.
     *
     * Hooked to `lisensio_activated` from the plugin bootstrap. Activation
     * registers the content model and flushes before this class has had a
     * chance to attach its filters — the plugin's own `plugins_loaded` callback
     * has already been missed by the time the activation request loads the
     * plugin file — so the rules built there can describe the wrong category
     * permastruct. Requesting a flush rebuilds them once on the next request,
     * after `init` has registered the real arguments.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public static function request_flush() {
        Lisensio_KB_Settings::request_rewrite_flush();
    }
}
