<?php
/**
 * Presentation schema for the settings registry.
 *
 * The registry in Lisensio_KB_Settings is the single source of truth for what
 * a setting is called, what it defaults to and how it is validated. It says
 * nothing about how a setting should be *shown*, because nothing outside the
 * settings screen needs to know. This class supplies exactly that missing
 * layer: a label, a description, a control type and a group for every key the
 * registry holds, so the Vue app renders itself from the server rather than
 * carrying a second, drifting copy of the field list in TypeScript.
 *
 * A key the registry gains from a third party — the registry is extensible
 * through `lisensio_settings_registry` — still renders. It falls into General
 * with a control inferred from its declared type, so an extension is visible
 * and editable without touching this file.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Describes the settings registry for the settings screen.
 *
 * @since 1.0.0
 */
class Lisensio_KB_Settings_Schema {

    /**
     * The group a setting with no declared group falls into.
     *
     * @since 1.0.0
     * @var string
     */
    const GROUP_GENERAL = 'general';

    /**
     * The settings store the registry is read from.
     *
     * @since 1.0.0
     * @var Lisensio_KB_Settings
     */
    private $settings;

    /**
     * Build the schema.
     *
     * @since 1.0.0
     *
     * @param Lisensio_KB_Settings|null $settings The settings store. Constructed when omitted.
     */
    public function __construct( $settings = null ) {
        $this->settings = ( $settings instanceof Lisensio_KB_Settings ) ? $settings : new Lisensio_KB_Settings();
    }

    /**
     * The categories the sidebar is divided into, in display order.
     *
     * A category is presentation only: it heads a block of groups in the
     * navigation and names the section above the page title. Groups carry the
     * settings; categories carry nothing but an order and a label.
     *
     * @since 1.0.0
     *
     * @return array<string, string> Category id to label.
     */
    public function categories() {
        /**
         * Filter the settings screen categories.
         *
         * @since 1.0.0
         *
         * @param array<string, string> $categories Category id to label, in display order.
         */
        return (array) apply_filters(
            'lisensio_settings_categories',
            array(
                'core'       => __( 'Core', 'lisensio-knowledge-base' ),
                'content'    => __( 'Content', 'lisensio-knowledge-base' ),
                'engagement' => __( 'Engagement', 'lisensio-knowledge-base' ),
                'data'       => __( 'Data', 'lisensio-knowledge-base' ),
            )
        );
    }

    /**
     * The groups the settings screen is divided into, in display order.
     *
     * @since 1.0.0
     *
     * @return array<string, array<string, string>> Group id to label and description.
     */
    public function groups() {
        return array(
            self::GROUP_GENERAL => array(
                'label'       => __( 'General', 'lisensio-knowledge-base' ),
                'description' => __( 'How the knowledge base archive behaves.', 'lisensio-knowledge-base' ),
                'category'    => 'core',
                'icon'        => 'admin-settings',
            ),
            'slugs'             => array(
                'label'       => __( 'Slugs and permalinks', 'lisensio-knowledge-base' ),
                'description' => __( 'The URL bases for articles, categories and tags. Changing one rebuilds the rewrite rules automatically.', 'lisensio-knowledge-base' ),
                'category'    => 'content',
                'icon'        => 'admin-links',
            ),
            'appearance'        => array(
                'label'       => __( 'Appearance', 'lisensio-knowledge-base' ),
                'description' => __( 'Every value here is published as a CSS custom property on the knowledge base root, so a theme can override it without fighting a rule.', 'lisensio-knowledge-base' ),
                'category'    => 'core',
                'icon'        => 'art',
            ),
            'search'            => array(
                'label'       => __( 'Search', 'lisensio-knowledge-base' ),
                'description' => __( 'How many results the search endpoint and the type-ahead return.', 'lisensio-knowledge-base' ),
                'category'    => 'content',
                'icon'        => 'search',
            ),
            'feedback'          => array(
                'label'       => __( 'Feedback', 'lisensio-knowledge-base' ),
                'description' => __( 'Article voting and the comment box that follows a negative vote.', 'lisensio-knowledge-base' ),
                'category'    => 'engagement',
                'icon'        => 'thumbs-up',
            ),
            'privacy'           => array(
                'label'       => __( 'Privacy', 'lisensio-knowledge-base' ),
                'description' => __( 'What is collected, how long it is kept, and what happens to it when the plugin is deleted.', 'lisensio-knowledge-base' ),
                'category'    => 'data',
                'icon'        => 'privacy',
            ),
        );
    }

    /**
     * The presentation metadata for the keys this plugin registers itself.
     *
     * Only the parts that cannot be derived from the registry live here. The
     * type, the default and the bounds are read from the registry entry, so a
     * change there is picked up without editing this map.
     *
     * @since 1.0.0
     *
     * @return array<string, array<string, mixed>> Setting key to presentation metadata.
     */
    public function field_map() {
        return array(
            'articles_per_page'          => array(
                'group'       => self::GROUP_GENERAL,
                'label'       => __( 'Articles per page', 'lisensio-knowledge-base' ),
                'description' => __( 'How many articles a knowledge base archive lists before paging.', 'lisensio-knowledge-base' ),
                'control'     => 'number',
            ),
            'article_slug'               => array(
                'group'       => 'slugs',
                'label'       => __( 'Article base', 'lisensio-knowledge-base' ),
                'description' => __( 'The URL segment articles live under, for example "docs".', 'lisensio-knowledge-base' ),
                'control'     => 'text',
            ),
            'category_slug'              => array(
                'group'       => 'slugs',
                'label'       => __( 'Category base', 'lisensio-knowledge-base' ),
                'description' => __( 'The URL segment category archives live under.', 'lisensio-knowledge-base' ),
                'control'     => 'text',
            ),
            'tag_slug'                   => array(
                'group'       => 'slugs',
                'label'       => __( 'Tag base', 'lisensio-knowledge-base' ),
                'description' => __( 'The URL segment tag archives live under.', 'lisensio-knowledge-base' ),
                'control'     => 'text',
            ),
            'hierarchical_category_urls' => array(
                'group'       => 'slugs',
                'label'       => __( 'Nested category URLs', 'lisensio-knowledge-base' ),
                'description' => __( 'On, a child category is addressed as parent/child. Off, URLs stay short but every category slug must be unique across the whole taxonomy.', 'lisensio-knowledge-base' ),
                'control'     => 'toggle',
            ),
            'brand_color'                => array(
                'group'       => 'appearance',
                'label'       => __( 'Brand colour', 'lisensio-knowledge-base' ),
                'description' => __( 'Links, buttons and accents derive from this one colour. A readable text colour is chosen for you.', 'lisensio-knowledge-base' ),
                'control'     => 'color',
                'preview'     => array(
                    'kind'       => 'color',
                    'properties' => array(
                        'value'    => '--lisensio-kb-brand',
                        'rgb'      => '--lisensio-kb-brand-rgb',
                        'contrast' => '--lisensio-kb-brand-contrast',
                    ),
                ),
            ),
            'layout_width'               => array(
                'group'       => 'appearance',
                'label'       => __( 'Layout width', 'lisensio-knowledge-base' ),
                'description' => __( 'How wide the knowledge base content column is allowed to grow.', 'lisensio-knowledge-base' ),
                'control'     => 'select',
                'choices'     => array(
                    'contained' => __( 'Contained', 'lisensio-knowledge-base' ),
                    'wide'      => __( 'Wide', 'lisensio-knowledge-base' ),
                    'full'      => __( 'Full width', 'lisensio-knowledge-base' ),
                ),
                'preview'     => array(
                    'kind'   => 'map',
                    'values' => array(
                        'contained' => array( '--lisensio-kb-max-width' => '72rem' ),
                        'wide'      => array( '--lisensio-kb-max-width' => '90rem' ),
                        'full'      => array( '--lisensio-kb-max-width' => '100%' ),
                    ),
                ),
            ),
            'card_style'                 => array(
                'group'       => 'appearance',
                'label'       => __( 'Card style', 'lisensio-knowledge-base' ),
                'description' => __( 'How article and category cards are separated from the page behind them.', 'lisensio-knowledge-base' ),
                'control'     => 'select',
                'choices'     => array(
                    'shadow' => __( 'Raised', 'lisensio-knowledge-base' ),
                    'border' => __( 'Outlined', 'lisensio-knowledge-base' ),
                    'flat'   => __( 'Flat', 'lisensio-knowledge-base' ),
                ),
                'preview'     => array(
                    'kind'   => 'map',
                    'values' => array(
                        'shadow' => array(
                            '--lisensio-kb-card-shadow' => '0 1px 2px rgba(15,23,42,0.06), 0 8px 24px rgba(15,23,42,0.06)',
                            '--lisensio-kb-card-border' => '1px solid transparent',
                            '--lisensio-kb-card-bg'     => 'var(--lisensio-kb-surface)',
                        ),
                        'border' => array(
                            '--lisensio-kb-card-shadow' => 'none',
                            '--lisensio-kb-card-border' => '1px solid var(--lisensio-kb-line)',
                            '--lisensio-kb-card-bg'     => 'var(--lisensio-kb-surface)',
                        ),
                        'flat'   => array(
                            '--lisensio-kb-card-shadow' => 'none',
                            '--lisensio-kb-card-border' => '1px solid transparent',
                            '--lisensio-kb-card-bg'     => 'transparent',
                        ),
                    ),
                ),
            ),
            'search_results_limit'       => array(
                'group'       => 'search',
                'label'       => __( 'Results per search', 'lisensio-knowledge-base' ),
                'description' => __( 'How many articles the search endpoint returns per page, and how many the type-ahead offers.', 'lisensio-knowledge-base' ),
                'control'     => 'number',
            ),
            'enable_feedback'            => array(
                'group'       => 'feedback',
                'label'       => __( 'Article voting', 'lisensio-knowledge-base' ),
                'description' => __( 'Show the "was this helpful?" prompt at the foot of every article, with a comment box after a negative vote.', 'lisensio-knowledge-base' ),
                'control'     => 'toggle',
            ),
            'enable_analytics'           => array(
                'group'       => 'privacy',
                'label'       => __( 'Collect analytics', 'lisensio-knowledge-base' ),
                'description' => __( 'Record searches and article views. No IP address is ever stored; a visitor is a salted hash that rotates daily.', 'lisensio-knowledge-base' ),
                'control'     => 'toggle',
            ),
            'analytics_retention_days'   => array(
                'group'       => 'privacy',
                'label'       => __( 'Keep analytics for', 'lisensio-knowledge-base' ),
                'description' => __( 'How many days of search and view history the daily purge leaves in place.', 'lisensio-knowledge-base' ),
                'control'     => 'number',
                'unit'        => __( 'days', 'lisensio-knowledge-base' ),
            ),
            'delete_data_on_uninstall'   => array(
                'group'       => 'privacy',
                'label'       => __( 'Delete everything on uninstall', 'lisensio-knowledge-base' ),
                'description' => __( 'On, deleting the plugin drops its tables, options and articles. Off, the data survives so a reinstall picks up where it left off.', 'lisensio-knowledge-base' ),
                'control'     => 'toggle',
            ),
        );
    }

    /**
     * The full schema the settings screen renders itself from.
     *
     * @since 1.0.0
     *
     * @return array<int, array<string, mixed>> The groups, each carrying its ordered fields.
     */
    public function schema() {
        $groups   = $this->groups();
        $map      = $this->field_map();
        $registry = $this->settings->registry();

        $fields = array_fill_keys( array_keys( $groups ), array() );

        foreach ( $registry as $key => $definition ) {
            $meta  = isset( $map[ $key ] ) ? $map[ $key ] : array();
            $group = isset( $meta['group'] ) ? (string) $meta['group'] : self::GROUP_GENERAL;

            /*
             * A key whose declared group is not one this screen draws would
             * otherwise be silently dropped, which is the one outcome a
             * settings screen must never produce: a stored value nobody can
             * see or change. It falls into General instead.
             */
            if ( ! isset( $fields[ $group ] ) ) {
                $group = self::GROUP_GENERAL;
            }

            $fields[ $group ][] = $this->describe( (string) $key, (array) $definition, $meta, $group );
        }

        $schema = array();

        foreach ( $groups as $id => $group ) {
            $schema[] = array(
                'id'          => (string) $id,
                'label'       => $group['label'],
                'description' => $group['description'],
                'category'    => isset( $group['category'] ) ? (string) $group['category'] : 'core',
                'icon'        => isset( $group['icon'] ) ? (string) $group['icon'] : 'admin-settings',
                'count'       => count( $fields[ $id ] ),
                'fields'      => $fields[ $id ],
            );
        }

        /**
         * Filter the settings screen schema.
         *
         * Every entry is rendered by the settings app as-is. A field named here
         * that the settings registry does not hold cannot be saved, because the
         * REST controller validates against the registry and not against this.
         *
         * @since 1.0.0
         *
         * @param array<int, array<string, mixed>> $schema   The groups and their fields.
         * @param array<string, mixed>             $registry The settings registry.
         */
        return (array) apply_filters( 'lisensio_settings_schema', $schema, $registry );
    }

    /**
     * Describe one setting.
     *
     * @since 1.0.0
     *
     * @param string               $key        The setting key.
     * @param array<string, mixed> $definition The registry entry.
     * @param array<string, mixed> $meta       The presentation metadata, possibly empty.
     * @param string               $group      The group the field was placed in.
     *
     * @return array<string, mixed> The field description.
     */
    private function describe( $key, array $definition, array $meta, $group ) {
        $type = isset( $definition['type'] ) ? (string) $definition['type'] : 'string';

        $field = array(
            'key'         => $key,
            'group'       => $group,
            'type'        => $type,
            'control'     => isset( $meta['control'] ) ? (string) $meta['control'] : self::control_for_type( $type ),
            'label'       => isset( $meta['label'] ) ? (string) $meta['label'] : self::label_for_key( $key ),
            'description' => isset( $meta['description'] ) ? (string) $meta['description'] : '',
            'default'     => isset( $definition['default'] ) ? $definition['default'] : null,
            'min'         => isset( $definition['min'] ) ? (int) $definition['min'] : null,
            'max'         => isset( $definition['max'] ) ? (int) $definition['max'] : null,
            'unit'        => isset( $meta['unit'] ) ? (string) $meta['unit'] : '',
            'choices'     => array(),
            'preview'     => isset( $meta['preview'] ) ? $meta['preview'] : null,
        );

        if ( isset( $meta['choices'] ) && is_array( $meta['choices'] ) ) {
            foreach ( $meta['choices'] as $value => $label ) {
                $field['choices'][] = array(
                    'value' => (string) $value,
                    'label' => (string) $label,
                );
            }
        }

        return $field;
    }

    /**
     * The control an unmapped setting is edited with.
     *
     * @since 1.0.0
     *
     * @param string $type The registry type.
     *
     * @return string The control name the settings app understands.
     */
    public static function control_for_type( $type ) {
        switch ( $type ) {
            case 'bool':
                return 'toggle';
            case 'int':
            case 'float':
                return 'number';
            default:
                return 'text';
        }
    }

    /**
     * A readable label for an unmapped setting key.
     *
     * @since 1.0.0
     *
     * @param string $key The setting key.
     *
     * @return string The label.
     */
    public static function label_for_key( $key ) {
        return ucfirst( str_replace( '_', ' ', (string) $key ) );
    }
}
