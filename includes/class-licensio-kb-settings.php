<?php
        /**
         * Settings store.
         *
         * Typed get/set over a single autoloaded option, backed by a defaults
         * registry. Scaffold infrastructure only — this class ships no admin UI and
         * registers no admin screens. The settings app (#10) renders it, and #4, #5,
         * #6, #8 and #9 read from it.
         *
         * Consumed by: #4, #5, #6, #8, #9, #10.
         *
         * @package Licensio_KB
         * @since   1.0.0
         */

        defined( 'ABSPATH' ) || exit;

        /**
         * Reads and writes the plugin settings option.
         *
         * @since 1.0.0
         */
class Licensio_KB_Settings {

    /**
     * Name of the option the settings are stored in.
     *
     * @since 1.0.0
     * @var string
     */
    const OPTION_NAME = 'licensio_settings';

    /**
     * Option set when a stored value requires the rewrite rules to be rebuilt.
     *
     * Written by update() and consumed by Licensio_KB_Post_Types on `init`.
     * Autoloaded off: it is read once per request and is normally absent.
     *
     * @since 1.0.0
     * @var string
     */
    const FLUSH_OPTION = 'licensio_flush_rewrite_rules';

    /**
     * Accepted values for the `layout_width` setting.
     *
     * @since 1.0.0
     * @var string[]
     */
    const LAYOUT_WIDTHS = array( 'contained', 'wide', 'full' );

    /**
     * Accepted values for the `card_style` setting.
     *
     * @since 1.0.0
     * @var string[]
     */
    const CARD_STYLES = array( 'shadow', 'border', 'flat' );

    /**
     * Accepted values for the `header_style` setting.
     *
     * @since 1.0.0
     * @var string[]
     */
    const HEADER_STYLES = array( 'solid', 'gradient', 'image' );

    /**
     * Accepted values for every sidebar position setting.
     *
     * The archive, the article and the category page each choose a side
     * independently, but they choose from the same three answers, so the
     * allowlist and its sanitizer are written once and shared.
     *
     * @since 1.0.0
     * @var string[]
     */
    const SIDEBAR_POSITIONS = array( 'left', 'right', 'none' );

    /**
     * Runtime cache of the resolved settings.
     *
     * @since 1.0.0
     * @var array<string, mixed>|null
     */
    private $cache = null;

    /**
     * The settings registry: default, type and validation for every known key.
     *
     * Sub-issues extend this through the `licensio_settings_registry` filter
     * rather than by editing this method, so the scaffold stays owned here.
     *
     * Each entry supports:
     *
     * - `type`           — `string`, `int`, `float`, `bool` or `array`.
     * - `default`        — the value used when nothing valid is stored.
     * - `sanitize`       — optional callable applied before the type cast.
     *                      Returning null rejects the value and the default is
     *                      stored instead, so a rejected write never persists an
     *                      empty string.
     * - `min` / `max`    — optional bounds, clamped after casting a numeric type.
     * - `flush_rewrites` — set when changing the value invalidates the rewrite
     *                      rules, so update() can request a flush.
     *
     * @since 1.0.0
     *
     * @return array<string, array<string, mixed>> The registry keyed by setting name.
     */
    public function registry() {
        $registry = array(
            'article_slug'              => array(
                'type'           => 'string',
                'default'        => 'docs',
                'sanitize'       => array( 'Licensio_KB_Settings', 'sanitize_slug' ),
                'flush_rewrites' => true,
            ),
            'category_slug'             => array(
                'type'           => 'string',
                'default'        => 'docs-category',
                'sanitize'       => array( 'Licensio_KB_Settings', 'sanitize_slug' ),
                'flush_rewrites' => true,
            ),
            'tag_slug'                  => array(
                'type'           => 'string',
                'default'        => 'docs-tag',
                'sanitize'       => array( 'Licensio_KB_Settings', 'sanitize_slug' ),
                'flush_rewrites' => true,
            ),
            'articles_per_page'         => array(
                'type'    => 'int',
                'default' => 20,
                'min'     => 1,
                'max'     => 500,
            ),
            'search_results_limit'      => array(
                'type'    => 'int',
                'default' => 10,
                'min'     => 1,
                'max'     => 100,
            ),
            'brand_color'               => array(
                'type'     => 'string',
                'default'  => '#3c569f',
                'sanitize' => array( 'Licensio_KB_Settings', 'sanitize_brand_color' ),
            ),
            'layout_width'              => array(
                'type'     => 'string',
                'default'  => 'contained',
                'sanitize' => array( 'Licensio_KB_Settings', 'sanitize_layout_width' ),
            ),
            'card_style'                => array(
                'type'     => 'string',
                'default'  => 'shadow',
                'sanitize' => array( 'Licensio_KB_Settings', 'sanitize_card_style' ),
            ),
            'header_style'              => array(
                'type'     => 'string',
                'default'  => 'solid',
                'sanitize' => array( 'Licensio_KB_Settings', 'sanitize_header_style' ),
            ),
            'header_image_id'           => array(
                'type'     => 'int',
                'default'  => 0,
                'min'      => 0,
                'sanitize' => array( 'Licensio_KB_Settings', 'sanitize_attachment_id' ),
            ),
            'archive_header_text'       => array(
                'type'     => 'string',
                'default'  => 'Help Topics',
                'sanitize' => array( 'Licensio_KB_Settings', 'sanitize_display_text' ),
            ),
            'search_placeholder'        => array(
                'type'     => 'string',
                'default'  => 'Search the Knowledge Base...',
                'sanitize' => array( 'Licensio_KB_Settings', 'sanitize_display_text' ),
            ),
            'archive_columns'           => array(
                'type'     => 'int',
                'default'  => 0,
                'min'      => 0,
                'max'      => 4,
                'sanitize' => array( 'Licensio_KB_Settings', 'sanitize_archive_columns' ),
            ),
            'show_subcategories'        => array(
                'type'    => 'bool',
                'default' => false,
            ),
            'show_article_counts'       => array(
                'type'    => 'bool',
                'default' => true,
            ),
            'classic_screens'           => array(
                'type'    => 'bool',
                'default' => false,
            ),
            'sticky_sidebar'            => array(
                'type'    => 'bool',
                'default' => true,
            ),
            'archive_sidebar_position'  => array(
                'type'     => 'string',
                'default'  => 'right',
                'sanitize' => array( 'Licensio_KB_Settings', 'sanitize_sidebar_position' ),
            ),
            'article_sidebar_position'  => array(
                'type'     => 'string',
                'default'  => 'right',
                'sanitize' => array( 'Licensio_KB_Settings', 'sanitize_sidebar_position' ),
            ),
            'category_sidebar_position' => array(
                'type'     => 'string',
                'default'  => 'right',
                'sanitize' => array( 'Licensio_KB_Settings', 'sanitize_sidebar_position' ),
            ),
            'support_url'               => array(
                'type'     => 'string',
                'default'  => '',
                'sanitize' => array( 'Licensio_KB_Settings', 'sanitize_url' ),
            ),
            'show_table_of_contents'    => array(
                'type'    => 'bool',
                'default' => true,
            ),
            'enable_feedback'           => array(
                'type'    => 'bool',
                'default' => true,
            ),
            'low_rating_threshold'      => array(
                'type'    => 'int',
                'default' => 50,
                'min'     => 1,
                'max'     => 99,
            ),
            'low_rating_min_votes'      => array(
                'type'    => 'int',
                'default' => 5,
                'min'     => 1,
                'max'     => 100000,
            ),
            'enable_analytics'          => array(
                'type'    => 'bool',
                'default' => true,
            ),
            'analytics_retention_days'  => array(
                'type'     => 'int',
                'default'  => 90,
                'min'      => 1,
                'max'      => 3650,
                'sanitize' => array( 'Licensio_KB_Settings', 'sanitize_retention_days' ),
            ),
            'delete_data_on_uninstall'  => array(
                'type'    => 'bool',
                'default' => false,
            ),
        );

        /**
         * Filter the knowledge base settings registry.
         *
         * Each entry is an array with a `type` (`string`, `int`, `float`,
         * `bool` or `array`) and a `default` value, plus the optional
         * `sanitize`, `min`, `max` and `flush_rewrites` keys documented on
         * Licensio_KB_Settings::registry().
         *
         * @since 1.0.0
         *
         * @param array<string, array<string, mixed>> $registry The settings registry.
         */
        return apply_filters( 'licensio_settings_registry', $registry );
    }

    /**
     * Reduce a value to a rewrite-safe slug path.
     *
     * Each `/`-separated segment is passed through `sanitize_title()` and empty
     * segments are dropped, so a nested base such as `help/docs` survives while
     * `../foo`, markup and whitespace do not. Returning null rather than an
     * empty string lets the caller fall back to the registered default instead
     * of registering a post type with an empty rewrite slug.
     *
     * @since 1.0.0
     *
     * @param mixed $value The raw value.
     *
     * @return string|null The sanitized slug path, or null when nothing usable remains.
     */
    public static function sanitize_slug( $value ) {
        $clean = array();

        foreach ( explode( '/', (string) $value ) as $segment ) {
            $segment = sanitize_title( $segment );

            if ( '' !== $segment ) {
                $clean[] = $segment;
            }
        }

        return empty( $clean ) ? null : implode( '/', $clean );
    }

    /**
     * Reduce a value to a valid hex colour.
     *
     * The brand colour is emitted into a CSS custom property by #5 and #6, so a
     * value such as `red;}body{background:url(//evil/x)` or `</style><script>`
     * would become stored XSS in every consumer that forgot to escape. Core's
     * `sanitize_hex_color()` already returns null for anything that is not
     * `#rgb` or `#rrggbb`.
     *
     * @since 1.0.0
     *
     * @param mixed $value The raw value.
     *
     * @return string|null The hex colour, or null when the value is not one.
     */
    public static function sanitize_brand_color( $value ) {
        $color = sanitize_hex_color( (string) $value );

        return ( is_string( $color ) && '' !== $color ) ? $color : null;
    }

    /**
     * Reduce a value to a usable analytics retention period, in days.
     *
     * `0` and non-numeric input fall back to the registered default of 90 days
     * rather than being clamped up to the 1-day minimum. People type 0 meaning
     * "keep forever", and clamping that to a single day would silently destroy
     * the history it was meant to preserve — a data-loss bug wearing the costume
     * of validation. Genuinely out-of-range numbers (negatives, and anything
     * above 3650) are clamped by the registry bounds instead, because those are
     * nonsense input rather than a misunderstood intent.
     *
     * Note that 0 is NOT "keep forever". Unbounded retention is deliberately not
     * offered: a bounded window is part of the plugin's privacy posture, and the
     * visitor salt rotates daily precisely so that old rows cannot be
     * re-identified afterwards. A sub-issue that wants explicit "keep forever"
     * semantics should add its own setting rather than overloading 0.
     *
     * @since 1.0.0
     *
     * @param mixed $value The raw value.
     *
     * @return int|null The retention period in days, or null to use the default.
     */
    public static function sanitize_retention_days( $value ) {
        if ( ! is_numeric( $value ) || 0 === (int) $value ) {
            return null;
        }

        return (int) $value;
    }

    /**
     * Restrict the layout width to a known value.
     *
     * @since 1.0.0
     *
     * @param mixed $value The raw value.
     *
     * @return string|null The layout width, or null when it is not a known one.
     */
    public static function sanitize_layout_width( $value ) {
        return in_array( (string) $value, self::LAYOUT_WIDTHS, true ) ? (string) $value : null;
    }

    /**
     * Restrict the card style to a known value.
     *
     * @since 1.0.0
     *
     * @param mixed $value The raw value.
     *
     * @return string|null The card style, or null when it is not a known one.
     */
    public static function sanitize_card_style( $value ) {
        return in_array( (string) $value, self::CARD_STYLES, true ) ? (string) $value : null;
    }

    /**
     * Restrict the archive header style to a known value.
     *
     * The value names a header treatment the theme renders, so an unknown one
     * is not a harmless typo: it either selects nothing at all or, if a theme
     * interpolates it into a class name, injects an attacker-chosen token into
     * markup. The allowlist closes both.
     *
     * @since 1.0.0
     *
     * @param mixed $value The raw value.
     *
     * @return string|null The header style, or null when it is not a known one.
     */
    public static function sanitize_header_style( $value ) {
        return in_array( (string) $value, self::HEADER_STYLES, true ) ? (string) $value : null;
    }

    /**
     * Restrict a sidebar position to a known value.
     *
     * Shared by `archive_sidebar_position`, `article_sidebar_position` and
     * `category_sidebar_position`: three independent settings answering the
     * same question, so they are validated against the same allowlist rather
     * than against three copies of it that could drift apart.
     *
     * @since 1.0.0
     *
     * @param mixed $value The raw value.
     *
     * @return string|null The sidebar position, or null when it is not a known one.
     */
    public static function sanitize_sidebar_position( $value ) {
        return in_array( (string) $value, self::SIDEBAR_POSITIONS, true ) ? (string) $value : null;
    }

    /**
     * Reduce a value to plain display text.
     *
     * Shared by `archive_header_text` and `search_placeholder`. Both are echoed
     * into the archive header — one as a heading, one as an input placeholder
     * attribute — so markup is stripped here rather than trusted to every
     * template that renders them. An empty result is kept rather than rejected:
     * clearing the heading is a legitimate choice, and silently restoring
     * "How can we help?" over it would be a setting that refuses to be unset.
     * Only a value that is not text at all falls back to the default.
     *
     * @since 1.0.0
     *
     * @param mixed $value The raw value.
     *
     * @return string|null The plain text, or null when the value is not text.
     */
    public static function sanitize_display_text( $value ) {
        if ( ! is_scalar( $value ) ) {
            return null;
        }

        return sanitize_text_field( (string) $value );
    }

    /**
     * Reduce a value to a URL the support card may link to.
     *
     * An empty string is valid and means the card is not shown; anything
     * that cannot be resolved to a URL at all is rejected so a typo can
     * never become a link to the current page.
     *
     * @since 1.0.0
     *
     * @param mixed $value The candidate URL.
     *
     * @return string|null The cleaned URL, an empty string, or null when the value is not a URL.
     */
    public static function sanitize_url( $value ) {
        if ( ! is_scalar( $value ) ) {
            return null;
        }

        $value = trim( (string) $value );

        if ( '' === $value ) {
            return '';
        }

        $url = esc_url_raw( $value );

        return '' === $url ? null : $url;
    }

    /**
     * Reduce a value to a knowledge base archive column count.
     *
     * Only the number is stored. Heroic stored a CSS class name instead, which
     * put a presentation decision in the database and left the theme unable to
     * lay the grid out any other way; the mapping from 3 to whatever grid the
     * theme uses belongs in the theme. Non-numeric input falls back to the
     * default; a number outside 1-4 is clamped by the registry bounds.
     *
     * @since 1.0.0
     *
     * @param mixed $value The raw value.
     *
     * @return int|null The column count, or null when the value is not a number.
     */
    public static function sanitize_archive_columns( $value ) {
        if ( ! is_numeric( $value ) ) {
            return null;
        }

        return (int) $value;
    }

    /**
     * Reduce a value to the ID of an attachment that actually exists.
     *
     * An integer is not proof of an attachment. A post ID that belongs to a
     * page, a draft or nothing at all would be handed to `wp_get_attachment_image()`
     * on every archive render, so the post type is checked here instead. Zero is
     * accepted as it stands, because zero is how the header image is cleared;
     * any other ID that is not an attachment is refused, and the registry
     * substitutes the default.
     *
     * @since 1.0.0
     *
     * @param mixed $value The raw value.
     *
     * @return int|null The attachment ID, 0 for no image, or null when the ID is not an attachment.
     */
    public static function sanitize_attachment_id( $value ) {
        $id = is_scalar( $value ) ? absint( $value ) : 0;

        if ( 0 === $id ) {
            return 0;
        }

        return ( 'attachment' === get_post_type( $id ) ) ? $id : null;
    }

    /**
     * Record that the rewrite rules need rebuilding.
     *
     * Deliberately a flag rather than an inline `flush_rewrite_rules()` call:
     * settings are written from a REST handler, long before the post type and
     * taxonomies have been registered on `init`, so flushing at write time would
     * rebuild the rules from the old permastructs.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public static function request_rewrite_flush() {
        update_option( self::FLUSH_OPTION, 1, false );
    }

    /**
     * Whether a rewrite flush has been requested and not yet performed.
     *
     * @since 1.0.0
     *
     * @return bool True when the rules need rebuilding.
     */
    public static function needs_rewrite_flush() {
        return (bool) get_option( self::FLUSH_OPTION, false );
    }

    /**
     * Clear the pending rewrite flush request.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public static function clear_rewrite_flush() {
        delete_option( self::FLUSH_OPTION );
    }

    /**
     * The default value for every registered setting.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed> Setting name to default value.
     */
    public function defaults() {
        $defaults = array();

        foreach ( $this->registry() as $key => $definition ) {
            $defaults[ $key ] = isset( $definition['default'] ) ? $definition['default'] : null;
        }

        return $defaults;
    }

    /**
     * All settings, stored values merged over the defaults and validated.
     *
     * Validation runs on read as well as on write so a value that reached the
     * option by some other route — a hand-edited row, a database import, an
     * older release — still cannot hand a consumer a malformed slug or an
     * unescapable colour.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed> The resolved settings.
     */
    public function all() {
        if ( is_array( $this->cache ) ) {
            return $this->cache;
        }

        $stored = get_option( self::OPTION_NAME, array() );

        if ( ! is_array( $stored ) ) {
            $stored = array();
        }

        $this->cache = $this->resolve( $stored );

        return $this->cache;
    }

    /**
     * Whether a setting is registered.
     *
     * @since 1.0.0
     *
     * @param string $key The setting name.
     *
     * @return bool True when the key exists in the registry.
     */
    public function has( $key ) {
        return array_key_exists( $key, $this->registry() );
    }

    /**
     * Get a single setting, cast to its registered type.
     *
     * @since 1.0.0
     *
     * @param string $key      The setting name.
     * @param mixed  $fallback Value returned when the key is not registered.
     *
     * @return mixed The setting value, or the fallback for an unknown key.
     */
    public function get( $key, $fallback = null ) {
        $settings = $this->all();

        return array_key_exists( $key, $settings ) ? $settings[ $key ] : $fallback;
    }

    /**
     * Write a single setting.
     *
     * Unknown keys are rejected so a typo cannot silently create a setting.
     *
     * @since 1.0.0
     *
     * @param string $key   The setting name.
     * @param mixed  $value The new value; sanitized and cast to the registered type.
     *
     * @return bool True when the setting holds the requested value afterwards.
     */
    public function set( $key, $value ) {
        return $this->update( array( $key => $value ) );
    }

    /**
     * Write several settings at once.
     *
     * Returns true when every recognised key holds its requested value once the
     * call returns, which includes the case where it already did. Core's
     * `update_option()` returns false for a no-op write, and reporting that as a
     * failure would make the settings screen (#10) show an error for the most
     * common interaction there is: saving a form nobody changed.
     *
     * Returns false only when no key in $values is registered, so a caller that
     * mistypes every key still hears about it.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $values Setting name to new value.
     *
     * @return bool True when the recognised settings hold their requested values.
     */
    public function update( array $values ) {
        $registry = $this->registry();
        $resolved = $this->all();
        $stored   = get_option( self::OPTION_NAME, array() );

        if ( ! is_array( $stored ) ) {
            $stored = array();
        }

        $original = $stored;
        $known    = false;
        $flush    = false;

        foreach ( $values as $key => $value ) {
            if ( ! array_key_exists( $key, $registry ) ) {
                continue;
            }

            $known = true;
            $clean = $this->sanitize_setting( $value, $registry[ $key ] );

            if ( ! empty( $registry[ $key ]['flush_rewrites'] )
                && array_key_exists( $key, $resolved )
                && $clean !== $resolved[ $key ] ) {
                $flush = true;
            }

            $stored[ $key ] = $clean;
        }

        if ( ! $known ) {
            return false;
        }

        if ( $stored === $original ) {
            return true;
        }

        $this->cache = null;

        if ( $flush ) {
            self::request_rewrite_flush();
        }

        return (bool) update_option( self::OPTION_NAME, $stored );
    }

    /**
     * Delete the stored settings, returning every value to its default.
     *
     * @since 1.0.0
     *
     * @return bool True when the option was deleted.
     */
    public function delete() {
        $this->cache = null;

        return (bool) delete_option( self::OPTION_NAME );
    }

    /**
     * Drop the runtime cache so the next read hits the option again.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function flush_cache() {
        $this->cache = null;
    }

    /**
     * All settings as they would resolve after `update()`, without saving.
     *
     * The same sanitisation, defaults and `licensio_settings` filter as
     * `all()`, applied to the stored option with these values merged in, so a
     * caller can preview a change exactly — the importer's dry run uses it to
     * report which settings would really change.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $values Setting key to raw value; unknown keys are ignored.
     *
     * @return array<string, mixed> The resolved settings.
     */
    public function preview( array $values ) {
        $registry = $this->registry();
        $stored   = get_option( self::OPTION_NAME, array() );

        if ( ! is_array( $stored ) ) {
            $stored = array();
        }

        foreach ( $values as $key => $value ) {
            if ( array_key_exists( $key, $registry ) ) {
                $stored[ $key ] = $this->sanitize_setting( $value, $registry[ $key ] );
            }
        }

        return $this->resolve( $stored );
    }

    /**
     * Resolve a stored option into the full, validated, filtered settings.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $stored The raw stored option value.
     *
     * @return array<string, mixed> The resolved settings.
     */
    private function resolve( array $stored ) {
        $resolved = array();

        foreach ( $this->registry() as $key => $definition ) {
            $default = isset( $definition['default'] ) ? $definition['default'] : null;
            $value   = array_key_exists( $key, $stored ) ? $stored[ $key ] : $default;

            $resolved[ $key ] = $this->sanitize_setting( $value, $definition );
        }

        /**
         * Filter the fully resolved knowledge base settings.
         *
         * @since 1.0.0
         *
         * @param array<string, mixed> $resolved The resolved settings.
         * @param array<string, mixed> $stored   The raw stored option value.
         */
        return apply_filters( 'licensio_settings', $resolved, $stored );
    }

    /**
     * Sanitize, cast and bound a raw value against its registry definition.
     *
     * The single transformation every value passes through, on read and on
     * write. A type cast alone is not sanitization: `(string)` happily preserves
     * `</style><script>` and `(int)` happily preserves a retention period of
     * zero days, and both flow on into rewrite rules, inline CSS and the
     * retention job.
     *
     * @since 1.0.0
     *
     * @param mixed                $value      The raw value.
     * @param array<string, mixed> $definition The registry entry for the setting.
     *
     * @return mixed The sanitized, cast and bounded value.
     */
    private function sanitize_setting( $value, array $definition ) {
        $type     = isset( $definition['type'] ) ? $definition['type'] : 'string';
        $default  = isset( $definition['default'] ) ? $definition['default'] : null;
        $sanitize = isset( $definition['sanitize'] ) ? $definition['sanitize'] : null;

        if ( null !== $sanitize && is_callable( $sanitize ) ) {
            $value = call_user_func( $sanitize, $value );

            if ( null === $value ) {
                $value = $default;
            }
        }

        return $this->clamp( $this->cast( $value, $type ), $definition );
    }

    /**
     * Hold a numeric setting inside its registered bounds.
     *
     * @since 1.0.0
     *
     * @param mixed                $value      The cast value.
     * @param array<string, mixed> $definition The registry entry for the setting.
     *
     * @return mixed The value, clamped when the setting is numeric and bounded.
     */
    private function clamp( $value, array $definition ) {
        $type = isset( $definition['type'] ) ? $definition['type'] : 'string';

        if ( 'int' !== $type && 'float' !== $type ) {
            return $value;
        }

        if ( isset( $definition['min'] ) && $value < $definition['min'] ) {
            $value = $definition['min'];
        }

        if ( isset( $definition['max'] ) && $value > $definition['max'] ) {
            $value = $definition['max'];
        }

        return $this->cast( $value, $type );
    }

    /**
     * Cast a raw value to a registered setting type.
     *
     * @since 1.0.0
     *
     * @param mixed  $value The raw value.
     * @param string $type  One of `string`, `int`, `float`, `bool` or `array`.
     *
     * @return mixed The value cast to the requested type.
     */
    private function cast( $value, $type ) {
        switch ( $type ) {
            case 'int':
                return (int) $value;
            case 'float':
                return (float) $value;
            case 'bool':
                $filtered = filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );

                return null === $filtered ? (bool) $value : $filtered;
            case 'array':
                return is_array( $value ) ? $value : array();
            case 'string':
            default:
                return is_scalar( $value ) ? (string) $value : '';
        }
    }
}
