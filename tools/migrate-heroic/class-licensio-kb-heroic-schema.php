<?php
/**
 * Everything this tool knows about Heroic Knowledge Base.
 *
 * One place for the source system's names, magic numbers and settings, so no
 * other file in the migrator hard-codes a Heroic string. Every constant here
 * was read out of the installed Heroic Knowledge Base 3.10.4 source rather than
 * out of the audit; the citation is on the constant.
 *
 * Nothing in this class writes. It is the read-only description of what the
 * migrator is migrating away from.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Names, defaults and vocabularies of the Heroic Knowledge Base data model.
 *
 * @since 1.0.0
 */
class Licensio_KB_Heroic_Schema {

    /**
     * Heroic's article post type.
     *
     * ht-knowledge-base.php:477 — `register_post_type( 'ht_kb', $args )`, with
     * `hierarchical => false` and `has_archive` filtered from false.
     *
     * @since 1.0.0
     * @var string
     */
    const POST_TYPE = 'ht_kb';

    /**
     * Heroic's category taxonomy.
     *
     * ht-knowledge-base.php:543. Terms are hierarchical, but the rewrite is
     * flat: `'hierarchical' => apply_filters( 'ht_kb_category_rewrite_hierachical', false )`.
     *
     * @since 1.0.0
     * @var string
     */
    const TAXONOMY_CATEGORY = 'ht_kb_category';

    /**
     * Heroic's tag taxonomy.
     *
     * ht-knowledge-base.php:618. Flat.
     *
     * @since 1.0.0
     * @var string
     */
    const TAXONOMY_TAG = 'ht_kb_tag';

    /**
     * The single option holding every Heroic setting.
     *
     * @since 1.0.0
     * @var string
     */
    const OPTION_SETTINGS = 'ht_knowledge_base_settings';

    /**
     * Heroic's voting table, unprefixed.
     *
     * voting/php/ht-voting-database.php:13.
     *
     * @since 1.0.0
     * @var string
     */
    const TABLE_VOTING = 'hkb_voting';

    /**
     * Heroic's article view table, unprefixed.
     *
     * php/ht-knowledge-base-view-count.php:19.
     *
     * @since 1.0.0
     * @var string
     */
    const TABLE_VISITS = 'hkb_visits';

    /**
     * Heroic's outbound-click table, unprefixed.
     *
     * exits/php/ht-kb-exits-database.php:13. There is no target table for this
     * data, so the migrator counts it and reports it rather than moving it.
     *
     * @since 1.0.0
     * @var string
     */
    const TABLE_EXITS = 'hkb_exits';

    /**
     * Heroic's per-search analytics table, unprefixed.
     *
     * php/ht-knowledge-base-analytics-core.php:266.
     *
     * @since 1.0.0
     * @var string
     */
    const TABLE_SEARCHES = 'hkb_analytics_search_atomic';

    /**
     * The column every Heroic table carries and no target table may receive.
     *
     * Named here so a reader can find the one thing this migrator is most
     * careful about without reading the mapping layer.
     *
     * @since 1.0.0
     * @var string
     */
    const COLUMN_IP = 'user_ip';

    /**
     * Primary key of the voting table.
     *
     * @since 1.0.0
     * @var string
     */
    const KEY_VOTING = 'vote_id';

    /**
     * Primary key of the visits table.
     *
     * @since 1.0.0
     * @var string
     */
    const KEY_VISITS = 'visit_id';

    /**
     * Primary key of the exits table.
     *
     * @since 1.0.0
     * @var string
     */
    const KEY_EXITS = 'exit_id';

    /**
     * Primary key of the search analytics table.
     *
     * @since 1.0.0
     * @var string
     */
    const KEY_SEARCHES = 'id';

    /**
     * Term meta key holding a category's manual order.
     *
     * ht-knowledge-base.php:2989 and php/ht-knowledge-base-category-ordering.php:314.
     * Note the key is unprefixed, which is why the migrator reads it explicitly
     * rather than sweeping every term meta key.
     *
     * @since 1.0.0
     * @var string
     */
    const META_TERM_ORDER = 'term_order';

    /**
     * Term meta key holding a category's image.
     *
     * The value is an attachment ID: php/ht-knowledge-base-category-meta.php:104
     * passes it straight to `wp_get_attachment_image_src()`.
     *
     * @since 1.0.0
     * @var string
     */
    const META_TERM_IMAGE = 'meta_image';

    /**
     * Term meta key holding a category's icon.
     *
     * The value is raw inline SVG markup, not an attachment and not an icon
     * name: php/ht-knowledge-base-category-meta.php:150 echoes it into the page.
     *
     * @since 1.0.0
     * @var string
     */
    const META_TERM_SVG = 'meta_svg';

    /**
     * Term meta key holding the colour applied to the SVG icon.
     *
     * @since 1.0.0
     * @var string
     */
    const META_TERM_SVG_COLOR = 'meta_svg_color';

    /**
     * Prefix of the post meta key holding an article's order inside one category.
     *
     * ht-knowledge-base.php:2723 — `update_post_meta( $post_id, '_ht_article_order_' . $term_id, $order )`.
     * Heroic orders an article once per category it belongs to; the target model
     * has one `menu_order` per article, which is why the migrator has to choose.
     *
     * @since 1.0.0
     * @var string
     */
    const META_ARTICLE_ORDER_PREFIX = '_ht_article_order_';

    /**
     * Post meta key holding Heroic's cached view count.
     *
     * php/ht-knowledge-base-view-count.php:15.
     *
     * @since 1.0.0
     * @var string
     */
    const META_VIEW_COUNT = '_ht_kb_post_views_count';

    /**
     * Post meta key holding Heroic's cached usefulness figure.
     *
     * ht-knowledge-base.php:45.
     *
     * @since 1.0.0
     * @var string
     */
    const META_USEFULNESS = '_ht_kb_usefulness';

    /**
     * The `object_type` a visit row carries when it is an article view.
     *
     * php/ht-knowledge-base-utility-functions.php:87. The other values are
     * `ht_kb_archive`, `ht_kb_category` and `ht_kb_tag`, none of which is an
     * article view and none of which the target table can hold.
     *
     * @since 1.0.0
     * @var string
     */
    const OBJECT_TYPE_ARTICLE = 'ht_kb_article';

    /**
     * The `source` value a live (type-ahead) search row carries.
     *
     * php/ht-knowledge-base-analytics-core.php:13 — `HKB_ANALYTICS_SOURCE_LIVE_SEARCH`.
     * The column is `INT(4) NULL`, not a string.
     *
     * @since 1.0.0
     * @var int
     */
    const SOURCE_LIVE_SEARCH = 1;

    /**
     * The `source` value a full-page search row carries.
     *
     * php/ht-knowledge-base-analytics-core.php:14 — `HKB_ANALYTICS_SOURCE_FULL_SEARCH`.
     *
     * @since 1.0.0
     * @var int
     */
    const SOURCE_FULL_SEARCH = 2;

    /**
     * The `magnitude` an up vote is stored as.
     *
     * voting/php/ht-vote-class.php:55 — `HT_Vote_Up` calls `parent::__construct( 10 )`.
     * A down vote is 0 (`HT_Vote_Down`, line 62) and a neutral vote is 5
     * (`HT_Vote_Neutral`, line 69), so the column is emphatically not 1/-1.
     *
     * @since 1.0.0
     * @var int
     */
    const MAGNITUDE_UP = 10;

    /**
     * The `magnitude` a down vote is stored as.
     *
     * @since 1.0.0
     * @var int
     */
    const MAGNITUDE_DOWN = 0;

    /**
     * Heroic's default article rewrite slug.
     *
     * php/ht-knowledge-base-settings-helpers.php:604.
     *
     * @since 1.0.0
     * @var string
     */
    const DEFAULT_ARTICLE_SLUG = 'knowledge-base';

    /**
     * Heroic's default category rewrite slug.
     *
     * php/ht-knowledge-base-settings-helpers.php:622.
     *
     * @since 1.0.0
     * @var string
     */
    const DEFAULT_CATEGORY_SLUG = 'article-categories';

    /**
     * Heroic's default tag rewrite slug.
     *
     * php/ht-knowledge-base-settings-helpers.php:639.
     *
     * @since 1.0.0
     * @var string
     */
    const DEFAULT_TAG_SLUG = 'article-tags';

    /**
     * Setting key holding the article rewrite slug.
     *
     * @since 1.0.0
     * @var string
     */
    const SETTING_ARTICLE_SLUG = 'kb-article-slug';

    /**
     * Setting key holding the category rewrite slug.
     *
     * @since 1.0.0
     * @var string
     */
    const SETTING_CATEGORY_SLUG = 'kb-category-slug';

    /**
     * Setting key holding the tag rewrite slug.
     *
     * @since 1.0.0
     * @var string
     */
    const SETTING_TAG_SLUG = 'kb-tag-slug';

    /**
     * The fully qualified name of one of Heroic's tables.
     *
     * @since 1.0.0
     *
     * @param string $table One of the TABLE_* constants.
     *
     * @return string The table name including the WordPress prefix.
     */
    public static function table( $table ) {
        global $wpdb;

        $prefix = isset( $wpdb ) ? (string) $wpdb->prefix : '';

        return $prefix . (string) $table;
    }

    /**
     * Whether one of Heroic's tables exists on this installation.
     *
     * Heroic creates each table lazily on activation, and a site that never
     * enabled a module simply has no table. Asking first is what lets the
     * migrator report "0 rows" rather than raise on a missing table.
     *
     * @since 1.0.0
     *
     * @param string $table One of the TABLE_* constants.
     *
     * @return bool True when the table is present.
     */
    public static function table_exists( $table ) {
        global $wpdb;

        if ( ! isset( $wpdb ) ) {
            return false;
        }

        $name = self::table( $table );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) );

        return (string) $found === $name;
    }

    /**
     * Heroic's settings array.
     *
     * Read straight from the option rather than through Heroic's own helpers,
     * because the migration is normally run with Heroic already deactivated and
     * its functions therefore undefined.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed> The stored settings, or an empty array.
     */
    public static function settings() {
        $settings = get_option( self::OPTION_SETTINGS, array() );

        return is_array( $settings ) ? $settings : array();
    }

    /**
     * The article rewrite base Heroic served articles under.
     *
     * @since 1.0.0
     *
     * @return string The slug, without leading or trailing slashes.
     */
    public static function article_slug() {
        return self::slug( self::SETTING_ARTICLE_SLUG, self::DEFAULT_ARTICLE_SLUG );
    }

    /**
     * The category rewrite base Heroic served category archives under.
     *
     * @since 1.0.0
     *
     * @return string The slug, without leading or trailing slashes.
     */
    public static function category_slug() {
        return self::slug( self::SETTING_CATEGORY_SLUG, self::DEFAULT_CATEGORY_SLUG );
    }

    /**
     * The tag rewrite base Heroic served tag archives under.
     *
     * @since 1.0.0
     *
     * @return string The slug, without leading or trailing slashes.
     */
    public static function tag_slug() {
        return self::slug( self::SETTING_TAG_SLUG, self::DEFAULT_TAG_SLUG );
    }

    /**
     * Read one slug setting, falling back to Heroic's own default.
     *
     * @since 1.0.0
     *
     * @param string $key      The Heroic setting key.
     * @param string $fallback The slug Heroic uses when the setting is absent.
     *
     * @return string The slug, without leading or trailing slashes.
     */
    private static function slug( $key, $fallback ) {
        $settings = self::settings();
        $slug     = isset( $settings[ $key ] ) ? trim( (string) $settings[ $key ] ) : '';

        return '' === $slug ? $fallback : trim( $slug, '/' );
    }
}
