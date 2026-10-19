<?php
/**
 * Where BetterDocs keeps its data.
 *
 * Every name here was read from the BetterDocs 4.9.3 source (the free plugin
 * on wordpress.org), with the file and line it came from, because an import is
 * run with BetterDocs often deactivated and none of its code can be called.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * BetterDocs post types, taxonomies, meta keys, tables and settings.
 *
 * @since 1.0.0
 */
class Licensio_KB_BetterDocs_Import_Schema {

    /**
     * The article post type. includes/Core/PostType.php:17.
     *
     * @since 1.0.0
     * @var string
     */
    const POST_TYPE = 'docs';

    /**
     * The hierarchical category taxonomy. includes/Core/PostType.php:19.
     *
     * @since 1.0.0
     * @var string
     */
    const TAXONOMY_CATEGORY = 'doc_category';

    /**
     * The tag taxonomy. includes/Core/PostType.php:20.
     *
     * @since 1.0.0
     * @var string
     */
    const TAXONOMY_TAG = 'doc_tag';

    /**
     * The multiple-knowledge-base taxonomy, registered by BetterDocs Pro only.
     *
     * includes/Abilities/ProState.php:226.
     *
     * @since 1.0.0
     * @var string
     */
    const TAXONOMY_KNOWLEDGE_BASE = 'knowledge_base';

    /**
     * The glossary taxonomy, which has no equivalent here. includes/Core/PostType.php:23.
     *
     * @since 1.0.0
     * @var string
     */
    const TAXONOMY_GLOSSARY = 'glossaries';

    /**
     * Category manual order. includes/Core/PostType.php:406.
     *
     * @since 1.0.0
     * @var string
     */
    const META_CATEGORY_ORDER = 'doc_category_order';

    /**
     * A knowledge base's manual order (BetterDocs Pro).
     *
     * @since 1.0.0
     * @var string
     */
    const META_KB_ORDER = 'kb_order';

    /**
     * Category icon attachment ID. includes/Core/PostType.php:604.
     *
     * @since 1.0.0
     * @var string
     */
    const META_CATEGORY_IMAGE = 'doc_category_image-id';

    /**
     * The knowledge bases a category belongs to: a serialised array of KB slugs.
     *
     * includes/Core/PostType.php:711.
     *
     * @since 1.0.0
     * @var string
     */
    const META_CATEGORY_KNOWLEDGE_BASE = 'doc_category_knowledge_base';

    /**
     * A category's article order: comma-separated post IDs. includes/Core/PostType.php:1088,1224.
     *
     * @since 1.0.0
     * @var string
     */
    const META_DOCS_ORDER = '_docs_order';

    /**
     * The per-post view counter BetterDocs keeps beside its analytics table.
     *
     * @since 1.0.0
     * @var string
     */
    const META_VIEWS = '_betterdocs_meta_views';

    /**
     * Daily per-article totals. includes/Core/Install.php:184.
     *
     * @since 1.0.0
     * @var string
     */
    const TABLE_ANALYTICS = 'betterdocs_analytics';

    /**
     * Search keywords. includes/Core/Install.php:162.
     *
     * @since 1.0.0
     * @var string
     */
    const TABLE_SEARCH_KEYWORD = 'betterdocs_search_keyword';

    /**
     * Daily per-keyword search totals. includes/Core/Install.php:171.
     *
     * @since 1.0.0
     * @var string
     */
    const TABLE_SEARCH_LOG = 'betterdocs_search_log';

    /**
     * The primary key of both daily tables.
     *
     * @since 1.0.0
     * @var string
     */
    const KEY_ID = 'id';

    /**
     * The settings option. includes/Core/Settings.php.
     *
     * @since 1.0.0
     * @var string
     */
    const OPTION_SETTINGS = 'betterdocs_settings';

    /**
     * Default article base; `permalink_structure` defaults to `docs/`. includes/Core/Settings.php:237,241.
     *
     * @since 1.0.0
     * @var string
     */
    const DEFAULT_DOCS_SLUG = 'docs';

    /**
     * Default category base. includes/Core/Settings.php:239.
     *
     * @since 1.0.0
     * @var string
     */
    const DEFAULT_CATEGORY_SLUG = 'docs-category';

    /**
     * Default tag base. includes/Core/Settings.php:240.
     *
     * @since 1.0.0
     * @var string
     */
    const DEFAULT_TAG_SLUG = 'docs-tag';

    /**
     * BetterDocs' stored settings.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed> The settings, empty when none are stored.
     */
    public static function settings() {
        $settings = get_option( self::OPTION_SETTINGS, array() );

        return is_array( $settings ) ? $settings : array();
    }

    /**
     * One setting, trimmed of slashes, or a fallback.
     *
     * @since 1.0.0
     *
     * @param string $key      The setting key.
     * @param string $fallback The value when the setting is empty.
     *
     * @return string The value.
     */
    public static function slug_setting( $key, $fallback ) {
        $settings = self::settings();
        $value    = isset( $settings[ $key ] ) && is_scalar( $settings[ $key ] ) ? trim( (string) $settings[ $key ], " \t\n\r\0\x0B/" ) : '';

        return '' === $value ? $fallback : $value;
    }

    /**
     * Whether BetterDocs builds category URLs from the whole parent chain.
     *
     * @since 1.0.0
     *
     * @return bool True when `enable_category_hierarchy_slugs` is on.
     */
    public static function hierarchical_slugs() {
        $settings = self::settings();

        return ! empty( $settings['enable_category_hierarchy_slugs'] );
    }
}
