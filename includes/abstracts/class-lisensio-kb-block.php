<?php
/**
 * Shared behaviour for the knowledge base blocks.
 *
 * Every knowledge base block is dynamic: the editor preview, the frontend and
 * the shortcode all reach the same PHP. The base class registers both entry
 * points against a single callable — `render()` — so there is no second
 * implementation of the markup that could drift from the one that ships.
 *
 * Two details make the two entry points interchangeable.
 *
 * First, WordPress lower-cases shortcode attribute names in
 * `shortcode_parse_atts()`, so `[lisensio_kb_articles showExcerpt="false"]`
 * arrives as `showexcerpt`. The block editor sends the camelCase names declared
 * in `block.json`. `normalize()` matches case-insensitively against the
 * declared defaults, so one attribute set serves both.
 *
 * Second, a shortcode hands every value over as a string while the editor hands
 * over real integers and booleans. `normalize()` casts each value to the type of
 * its declared default before the subclass sanitises it, so `"2"` and `2` reach
 * the markup as the same value.
 *
 * `get_block_wrapper_attributes()` is deliberately not used: it reads the block
 * currently being rendered, which does not exist on the shortcode path, so it
 * would make the two paths emit different markup. The wrapper is built by
 * `wrapper()` instead and the blocks opt out of the wrapper-driven supports.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Base class for a knowledge base block and its shortcode.
 *
 * @since 1.0.0
 */
abstract class Lisensio_KB_Block {

    /**
     * The block name, e.g. `lisensio-kb/article-list`.
     *
     * @since 1.0.0
     *
     * @return string The block name.
     */
    abstract public static function block_name();

    /**
     * The shortcode tag, e.g. `lisensio_kb_articles`.
     *
     * @since 1.0.0
     *
     * @return string The shortcode tag.
     */
    abstract public static function shortcode_tag();

    /**
     * The `blocks/` sub-directory holding the block's `block.json`.
     *
     * @since 1.0.0
     *
     * @return string The directory name.
     */
    abstract public static function directory();

    /**
     * The attributes the block accepts, and their default values.
     *
     * The declared type of each default drives the casting done by
     * `normalize()`, so a default must never be null.
     *
     * @since 1.0.0
     *
     * @return array<string, bool|int|string> Attribute name to default value.
     */
    abstract public static function defaults();

    /**
     * The block's inner markup, without the wrapper.
     *
     * @since 1.0.0
     *
     * @param array<string, bool|int|string> $attributes The normalised attributes.
     *
     * @return string The markup, or an empty string when there is nothing to show.
     */
    abstract public function content( array $attributes );

    /**
     * The BEM modifier used on the block wrapper.
     *
     * @since 1.0.0
     *
     * @return string The modifier, without the `--` prefix.
     */
    abstract public static function modifier();

    /**
     * Register the block and the shortcode.
     *
     * The shortcode is registered unconditionally, because a site running from
     * an unbuilt checkout still has a working server renderer even when the
     * editor bundle is missing.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function register() {
        add_shortcode( static::shortcode_tag(), array( $this, 'render' ) );

        $metadata = static::metadata_path();

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
     * The built copy in `dist/` is preferred because that is the one whose
     * `file:./index.js` reference points at a compiled bundle. The source
     * directory is the fallback for a checkout that has never been built, where
     * registering is still worth doing: the server-rendered markup works and
     * only the editor bundle is missing.
     *
     * @since 1.0.0
     *
     * @return string The absolute directory path, or an empty string when there is none.
     */
    public static function metadata_path() {
        foreach ( static::metadata_candidates() as $candidate ) {
            if ( is_readable( $candidate . '/block.json' ) ) {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * The directories a `block.json` is looked for in, in preference order.
     *
     * Split out from `metadata_path()` so the preference itself can be asserted
     * without depending on whether the checkout under test happens to have been
     * built.
     *
     * @since 1.0.0
     *
     * @return string[] The absolute directory paths, built copy first.
     */
    public static function metadata_candidates() {
        $directory = static::directory();

        if ( ! preg_match( '/^[a-z0-9-]+$/', $directory ) ) {
            return array();
        }

        return array(
            LISENSIO_KB_DIST_PATH . 'blocks/' . $directory,
            LISENSIO_KB_ABSPATH . 'blocks/' . $directory,
        );
    }

    /**
     * Render the block, the shortcode or the editor preview.
     *
     * One callable is registered for both entry points, so the signature has to
     * satisfy both. WordPress calls a render callback with
     * `( $attributes, $content, $block )` and a shortcode callback with
     * `( $atts, $content, $tag )`; only the first argument is read here, and a
     * shortcode with no attributes passes an empty string rather than an array.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed>|string $attributes The block or shortcode attributes.
     * @param string                      $content    Inner content. Unused; the blocks have none.
     * @param mixed                       $block      The WP_Block instance, or the shortcode tag.
     *
     * @return string The rendered markup.
     */
    public function render( $attributes = array(), $content = '', $block = null ) {
        unset( $content, $block );

        $inner = $this->content( static::normalize( $attributes ) );

        if ( '' === $inner ) {
            return '';
        }

        return self::wrapper( static::modifier(), $inner );
    }

    /**
     * Wrap a block's inner markup.
     *
     * The `lisensio-kb` class is what the default stylesheet declares its custom
     * properties on, so a block dropped onto an unrelated page still picks up
     * the knowledge base look.
     *
     * @since 1.0.0
     *
     * @param string $modifier The BEM modifier, without the `--` prefix.
     * @param string $inner    The already-escaped inner markup.
     *
     * @return string The wrapped markup.
     */
    public static function wrapper( $modifier, $inner ) {
        return sprintf(
            '<div class="lisensio-kb lisensio-kb-block lisensio-kb-block--%1$s">%2$s</div>',
            esc_attr( (string) $modifier ),
            $inner
        );
    }

    /**
     * Reduce raw block or shortcode attributes to a sanitised, typed set.
     *
     * Unknown keys are dropped rather than passed through, so neither entry
     * point can smuggle a value into the markup that the block never declared.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed>|string $raw The attributes as supplied.
     *
     * @return array<string, bool|int|string> The normalised attributes.
     */
    public static function normalize( $raw ) {
        $defaults = static::defaults();
        $values   = $defaults;
        $lookup   = array();

        foreach ( array_keys( $defaults ) as $name ) {
            $lookup[ strtolower( $name ) ] = $name;
        }

        foreach ( (array) $raw as $key => $value ) {
            if ( ! is_string( $key ) ) {
                continue;
            }

            $lowercased = strtolower( $key );

            if ( ! isset( $lookup[ $lowercased ] ) ) {
                continue;
            }

            $name    = $lookup[ $lowercased ];
            $default = $defaults[ $name ];

            if ( is_bool( $default ) ) {
                $values[ $name ] = self::to_bool( $value );
                continue;
            }

            if ( ! is_scalar( $value ) ) {
                continue;
            }

            $values[ $name ] = is_int( $default ) ? (int) $value : (string) $value;
        }

        return static::sanitize( $values );
    }

    /**
     * Clamp and whitelist the typed attributes.
     *
     * Overridden by every subclass that has a bounded attribute. The base
     * implementation is the identity, because an attribute set of nothing but
     * free strings needs no clamping.
     *
     * @since 1.0.0
     *
     * @param array<string, bool|int|string> $values The typed attributes.
     *
     * @return array<string, bool|int|string> The sanitised attributes.
     */
    protected static function sanitize( array $values ) {
        return $values;
    }

    /**
     * Read a boolean out of whatever the two entry points supply.
     *
     * The editor sends a real boolean; a shortcode sends a string. `"false"`
     * and `"no"` are truthy to PHP, so a plain cast would turn every attempt to
     * switch something off into switching it on.
     *
     * @since 1.0.0
     *
     * @param mixed $value The raw value.
     *
     * @return bool The boolean it denotes.
     */
    public static function to_bool( $value ) {
        if ( is_bool( $value ) ) {
            return $value;
        }

        if ( is_string( $value ) ) {
            return ! in_array( strtolower( trim( $value ) ), array( '', '0', 'false', 'no', 'off' ), true );
        }

        return (bool) $value;
    }

    /**
     * Keep an integer inside a range.
     *
     * @since 1.0.0
     *
     * @param mixed $value The raw value.
     * @param int   $min   The lowest allowed value.
     * @param int   $max   The highest allowed value.
     *
     * @return int The clamped value.
     */
    protected static function clamp( $value, $min, $max ) {
        return max( (int) $min, min( (int) $max, (int) $value ) );
    }

    /**
     * Keep a string inside a whitelist.
     *
     * @since 1.0.0
     *
     * @param mixed    $value    The raw value.
     * @param string[] $allowed  The permitted values.
     * @param string   $fallback The value used when the raw one is not permitted.
     *
     * @return string The permitted value.
     */
    protected static function enum( $value, array $allowed, $fallback ) {
        $value = strtolower( trim( (string) $value ) );

        return in_array( $value, $allowed, true ) ? $value : (string) $fallback;
    }

    /**
     * The published articles a block should list.
     *
     * Shared by the article list and the FAQ, which differ in how they present
     * articles but not in how they find them.
     *
     * @since 1.0.0
     *
     * @param string $category The category slug to filter by, or an empty string for all.
     * @param string $orderby  The `WP_Query` orderby value.
     * @param string $order    `asc` or `desc`.
     * @param int    $count    How many articles to return.
     *
     * @return WP_Post[] The articles, newest or ordered first.
     */
    protected function query_articles( $category, $orderby, $order, $count ) {
        $args = array(
            'post_type'              => Lisensio_KB_Post_Types::POST_TYPE,
            'post_status'            => 'publish',
            'posts_per_page'         => (int) $count,
            'orderby'                => (string) $orderby,
            'order'                  => 'asc' === $order ? 'ASC' : 'DESC',
            'ignore_sticky_posts'    => true,
            'no_found_rows'          => true,
            'update_post_term_cache' => false,
        );

        if ( '' !== $category ) {
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
            $args['tax_query'] = array(
                array(
                    'taxonomy' => Lisensio_KB_Post_Types::TAXONOMY_CATEGORY,
                    'field'    => 'slug',
                    'terms'    => $category,
                ),
            );
        }

        /**
         * Filter the query a knowledge base block lists articles with.
         *
         * @since 1.0.0
         *
         * @param array<string, mixed> $args       The `get_posts()` arguments.
         * @param string               $block_name The block asking, e.g. `lisensio-kb/article-list`.
         */
        $args = (array) apply_filters( 'lisensio_block_article_query_args', $args, static::block_name() );

        $posts = get_posts( $args );

        return is_array( $posts ) ? $posts : array();
    }
}
