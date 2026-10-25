<?php
/**
 * Presentation schema for the settings registry.
 *
 * The registry in Licensio_KB_Settings is the single source of truth for what
 * a setting is called, what it defaults to and how it is validated. It says
 * nothing about how a setting should be *shown*, because nothing outside the
 * settings screen needs to know. This class supplies exactly that missing
 * layer: a label, a description, a control type and a group for every key the
 * registry holds, so the Vue app renders itself from the server rather than
 * carrying a second, drifting copy of the field list in TypeScript.
 *
 * A key the registry gains from a third party — the registry is extensible
 * through `licensio_settings_registry` — still renders. It falls into General
 * with a control inferred from its declared type, so an extension is visible
 * and editable without touching this file.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Describes the settings registry for the settings screen.
 *
 * @since 1.0.0
 */
class Licensio_KB_Settings_Schema {

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
     * @var Licensio_KB_Settings
     */
    private $settings;

    /**
     * Build the schema.
     *
     * @since 1.0.0
     *
     * @param Licensio_KB_Settings|null $settings The settings store. Constructed when omitted.
     */
    public function __construct( $settings = null ) {
        $this->settings = ( $settings instanceof Licensio_KB_Settings ) ? $settings : new Licensio_KB_Settings();
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
            'licensio_settings_categories',
            array(
                'core'       => __( 'Core', 'licensio-knowledge-base' ),
                'content'    => __( 'Content', 'licensio-knowledge-base' ),
                'engagement' => __( 'Engagement', 'licensio-knowledge-base' ),
                'data'       => __( 'Data', 'licensio-knowledge-base' ),
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
                'label'       => __( 'General', 'licensio-knowledge-base' ),
                'description' => __( 'How the knowledge base archive behaves.', 'licensio-knowledge-base' ),
                'category'    => 'core',
                'icon'        => 'admin-settings',
            ),
            'slugs'             => array(
                'label'       => __( 'Slugs and permalinks', 'licensio-knowledge-base' ),
                'description' => __( 'The URL bases for articles, categories and tags. Changing one rebuilds the rewrite rules automatically.', 'licensio-knowledge-base' ),
                'category'    => 'content',
                'icon'        => 'admin-links',
            ),
            'appearance'        => array(
                'label'       => __( 'Appearance', 'licensio-knowledge-base' ),
                'description' => __( 'Every value here is published as a CSS custom property on the knowledge base root, so a theme can override it without fighting a rule.', 'licensio-knowledge-base' ),
                'category'    => 'core',
                'icon'        => 'art',
            ),
            'sidebar'           => array(
                'label'       => __( 'Sidebar', 'licensio-knowledge-base' ),
                'description' => __( 'Which side the sidebar sits on, chosen separately for the archive, an article and a category, whether it follows the reader down a long article, whether it opens with a table of contents, and where the sidebar Contact Support button leads.', 'licensio-knowledge-base' ),
                'category'    => 'core',
                'icon'        => 'align-pull-right',
            ),
            'search'            => array(
                'label'       => __( 'Search', 'licensio-knowledge-base' ),
                'description' => __( 'How many results the search endpoint and the type-ahead return.', 'licensio-knowledge-base' ),
                'category'    => 'content',
                'icon'        => 'search',
            ),
            'feedback'          => array(
                'label'       => __( 'Feedback', 'licensio-knowledge-base' ),
                'description' => __( 'Article voting and the comment box that follows a negative vote.', 'licensio-knowledge-base' ),
                'category'    => 'engagement',
                'icon'        => 'thumbs-up',
            ),
            'privacy'           => array(
                'label'       => __( 'Privacy', 'licensio-knowledge-base' ),
                'description' => __( 'What is collected, how long it is kept, and what happens to it when the plugin is deleted.', 'licensio-knowledge-base' ),
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
                'label'       => __( 'Articles per page', 'licensio-knowledge-base' ),
                'description' => __( 'How many articles a knowledge base archive lists before paging.', 'licensio-knowledge-base' ),
                'control'     => 'number',
            ),
            'show_subcategories'         => array(
                'group'       => self::GROUP_GENERAL,
                'label'       => __( 'Show subcategories', 'licensio-knowledge-base' ),
                'description' => __( 'On, a category card lists the categories nested inside it. Off, it lists only articles.', 'licensio-knowledge-base' ),
                'control'     => 'toggle',
            ),
            'show_article_counts'        => array(
                'group'       => self::GROUP_GENERAL,
                'label'       => __( 'Show article counts', 'licensio-knowledge-base' ),
                'description' => __( 'Print the number of articles beside every category name.', 'licensio-knowledge-base' ),
                'control'     => 'toggle',
            ),
            'classic_screens'            => array(
                'group'       => self::GROUP_GENERAL,
                'label'       => __( 'Classic screens', 'licensio-knowledge-base' ),
                'description' => __( 'Use the classic WordPress screens for articles, categories and tags.', 'licensio-knowledge-base' ),
                'control'     => 'toggle',
            ),
            'article_slug'               => array(
                'group'       => 'slugs',
                'label'       => __( 'Article base', 'licensio-knowledge-base' ),
                'description' => __( 'The URL segment articles live under, for example "docs".', 'licensio-knowledge-base' ),
                'control'     => 'text',
            ),
            'category_slug'              => array(
                'group'       => 'slugs',
                'label'       => __( 'Category base', 'licensio-knowledge-base' ),
                'description' => __( 'The URL segment category archives live under.', 'licensio-knowledge-base' ),
                'control'     => 'text',
            ),
            'tag_slug'                   => array(
                'group'       => 'slugs',
                'label'       => __( 'Tag base', 'licensio-knowledge-base' ),
                'description' => __( 'The URL segment tag archives live under.', 'licensio-knowledge-base' ),
                'control'     => 'text',
            ),
            'hierarchical_category_urls' => array(
                'group'       => 'slugs',
                'label'       => __( 'Nested category URLs', 'licensio-knowledge-base' ),
                'description' => __( 'On, a child category is addressed as parent/child. Off, URLs stay short but every category slug must be unique across the whole taxonomy.', 'licensio-knowledge-base' ),
                'control'     => 'toggle',
            ),
            'brand_color'                => array(
                'group'       => 'appearance',
                'label'       => __( 'Brand colour', 'licensio-knowledge-base' ),
                'description' => __( 'Links, buttons and accents derive from this one colour. A readable text colour is chosen for you.', 'licensio-knowledge-base' ),
                'control'     => 'color',
                'preview'     => array(
                    'kind'       => 'color',
                    'properties' => array(
                        'value'    => '--licensio-kb-brand',
                        'rgb'      => '--licensio-kb-brand-rgb',
                        'contrast' => '--licensio-kb-brand-contrast',
                    ),
                ),
            ),
            'layout_width'               => array(
                'group'       => 'appearance',
                'label'       => __( 'Layout width', 'licensio-knowledge-base' ),
                'description' => __( 'How wide the knowledge base content column is allowed to grow.', 'licensio-knowledge-base' ),
                'control'     => 'select',
                'choices'     => array(
                    'contained' => __( 'Contained', 'licensio-knowledge-base' ),
                    'wide'      => __( 'Wide', 'licensio-knowledge-base' ),
                    'full'      => __( 'Full width', 'licensio-knowledge-base' ),
                ),
                'preview'     => array(
                    'kind'   => 'map',
                    'values' => array(
                        'contained' => array( '--licensio-kb-max-width' => '72rem' ),
                        'wide'      => array( '--licensio-kb-max-width' => '90rem' ),
                        'full'      => array( '--licensio-kb-max-width' => '100%' ),
                    ),
                ),
            ),
            'card_style'                 => array(
                'group'       => 'appearance',
                'label'       => __( 'Card style', 'licensio-knowledge-base' ),
                'description' => __( 'How article and category cards are separated from the page behind them.', 'licensio-knowledge-base' ),
                'control'     => 'select',
                'choices'     => array(
                    'shadow' => __( 'Raised', 'licensio-knowledge-base' ),
                    'border' => __( 'Outlined', 'licensio-knowledge-base' ),
                    'flat'   => __( 'Flat', 'licensio-knowledge-base' ),
                ),
                'preview'     => array(
                    'kind'   => 'map',
                    'values' => array(
                        'shadow' => array(
                            '--licensio-kb-card-shadow' => '0 4px 14px rgba(16,24,40,0.06)',
                            '--licensio-kb-card-border' => '1px solid transparent',
                            '--licensio-kb-card-bg'     => 'var(--licensio-kb-surface)',
                        ),
                        'border' => array(
                            '--licensio-kb-card-shadow' => 'none',
                            '--licensio-kb-card-border' => '1px solid var(--licensio-kb-line)',
                            '--licensio-kb-card-bg'     => 'var(--licensio-kb-surface)',
                        ),
                        'flat'   => array(
                            '--licensio-kb-card-shadow' => 'none',
                            '--licensio-kb-card-border' => '1px solid transparent',
                            '--licensio-kb-card-bg'     => 'transparent',
                        ),
                    ),
                ),
            ),
            'header_style'               => array(
                'group'       => 'appearance',
                'label'       => __( 'Archive header style', 'licensio-knowledge-base' ),
                'description' => __( 'How the band behind the archive heading and search box is filled.', 'licensio-knowledge-base' ),
                'control'     => 'select',
                'choices'     => array(
                    'solid'    => __( 'Solid colour', 'licensio-knowledge-base' ),
                    'gradient' => __( 'Gradient', 'licensio-knowledge-base' ),
                    'image'    => __( 'Image', 'licensio-knowledge-base' ),
                ),
            ),
            'header_image_id'            => array(
                'group'       => 'appearance',
                'label'       => __( 'Archive header image', 'licensio-knowledge-base' ),
                'description' => __( 'The media library ID of the image behind the archive header. Used only when the header style is Image; 0 for none.', 'licensio-knowledge-base' ),
                'control'     => 'number',
            ),
            'archive_header_text'        => array(
                'group'       => 'appearance',
                'label'       => __( 'Archive heading', 'licensio-knowledge-base' ),
                'description' => __( 'The heading printed above the category grid on the knowledge base archive.', 'licensio-knowledge-base' ),
                'control'     => 'text',
            ),
            'search_placeholder'         => array(
                'group'       => 'appearance',
                'label'       => __( 'Search box placeholder', 'licensio-knowledge-base' ),
                'description' => __( 'The prompt shown inside the search box before anything is typed.', 'licensio-knowledge-base' ),
                'control'     => 'text',
            ),
            'archive_columns'            => array(
                'group'       => 'appearance',
                'label'       => __( 'Archive columns', 'licensio-knowledge-base' ),
                'description' => __( 'How many category cards sit side by side on a wide screen. Zero follows the sidebar: two columns beside one, three without it.', 'licensio-knowledge-base' ),
                'control'     => 'number',
            ),
            'sticky_sidebar'             => array(
                'group'       => 'sidebar',
                'label'       => __( 'Sticky sidebar', 'licensio-knowledge-base' ),
                'description' => __( 'On, the article sidebar follows the reader down the page instead of scrolling away.', 'licensio-knowledge-base' ),
                'control'     => 'toggle',
            ),
            'archive_sidebar_position'   => array(
                'group'       => 'sidebar',
                'label'       => __( 'Archive sidebar', 'licensio-knowledge-base' ),
                'description' => __( 'Which side the sidebar sits on when the knowledge base archive is shown.', 'licensio-knowledge-base' ),
                'control'     => 'select',
                'choices'     => self::sidebar_choices(),
            ),
            'article_sidebar_position'   => array(
                'group'       => 'sidebar',
                'label'       => __( 'Article sidebar', 'licensio-knowledge-base' ),
                'description' => __( 'Which side the sidebar sits on when a single article is shown.', 'licensio-knowledge-base' ),
                'control'     => 'select',
                'choices'     => self::sidebar_choices(),
            ),
            'category_sidebar_position'  => array(
                'group'       => 'sidebar',
                'label'       => __( 'Category sidebar', 'licensio-knowledge-base' ),
                'description' => __( 'Which side the sidebar sits on when a category archive is shown. Set it to No sidebar and the category grid widens to three columns.', 'licensio-knowledge-base' ),
                'control'     => 'select',
                'choices'     => self::sidebar_choices(),
            ),
            'support_url'                => array(
                'group'       => 'sidebar',
                'label'       => __( 'Support URL', 'licensio-knowledge-base' ),
                'description' => __( 'Where the Contact Support button in the sidebar leads, such as a contact page. Empty hides the Need Support card.', 'licensio-knowledge-base' ),
                'control'     => 'text',
            ),

            /*
             * Grouped with the sidebar rather than with the content settings.
             * The table of contents is one of the sidebar's three sections and
             * has no existence outside it — setting the article sidebar to "No
             * sidebar" already turns it off — so someone looking for the switch
             * looks here, beside the setting that overrides it.
             */
            'show_table_of_contents'     => array(
                'group'       => 'sidebar',
                'label'       => __( 'Table of contents', 'licensio-knowledge-base' ),
                'description' => __( 'List an article\'s headings at the top of its sidebar, each one a link to that section. Articles with fewer than two headings never show it.', 'licensio-knowledge-base' ),
                'control'     => 'toggle',
            ),
            'search_results_limit'       => array(
                'group'       => 'search',
                'label'       => __( 'Results per search', 'licensio-knowledge-base' ),
                'description' => __( 'How many articles the search endpoint returns per page, and how many the type-ahead offers.', 'licensio-knowledge-base' ),
                'control'     => 'number',
            ),
            'enable_feedback'            => array(
                'group'       => 'feedback',
                'label'       => __( 'Article voting', 'licensio-knowledge-base' ),
                'description' => __( 'Show the "was this helpful?" prompt at the foot of every article, with a comment box after a negative vote.', 'licensio-knowledge-base' ),
                'control'     => 'toggle',
            ),
            'low_rating_threshold'       => array(
                'group'       => 'feedback',
                'label'       => __( 'Low rating threshold', 'licensio-knowledge-base' ),
                'description' => __( 'An article needs attention when its helpful share in the chosen window falls below this percentage.', 'licensio-knowledge-base' ),
                'control'     => 'number',
                'unit'        => '%',
            ),
            'low_rating_min_votes'       => array(
                'group'       => 'feedback',
                'label'       => __( 'Minimum votes', 'licensio-knowledge-base' ),
                'description' => __( 'How many votes an article needs in the chosen window before it can be flagged as needing attention.', 'licensio-knowledge-base' ),
                'control'     => 'number',
                'unit'        => __( 'votes', 'licensio-knowledge-base' ),
            ),
            'enable_analytics'           => array(
                'group'       => 'privacy',
                'label'       => __( 'Collect analytics', 'licensio-knowledge-base' ),
                'description' => __( 'Record searches and article views. No IP address is ever stored; a visitor is a salted hash that rotates daily.', 'licensio-knowledge-base' ),
                'control'     => 'toggle',
            ),
            'analytics_retention_days'   => array(
                'group'       => 'privacy',
                'label'       => __( 'Keep analytics for', 'licensio-knowledge-base' ),
                'description' => __( 'How many days of search and view history the daily purge leaves in place.', 'licensio-knowledge-base' ),
                'control'     => 'number',
                'unit'        => __( 'days', 'licensio-knowledge-base' ),
            ),
            'delete_data_on_uninstall'   => array(
                'group'       => 'privacy',
                'label'       => __( 'Delete everything on uninstall', 'licensio-knowledge-base' ),
                'description' => __( 'On, deleting the plugin drops its tables (feedback and votes, the search index, search and view history, and the import log) and deletes its settings (including the permalink slugs), the import redirects and the import URL map files. Off, all of that survives so a reinstall picks up where it left off. Articles, categories and tags are never deleted.', 'licensio-knowledge-base' ),
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
        return (array) apply_filters( 'licensio_settings_schema', $schema, $registry );
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
     * The labelled choices offered by every sidebar position setting.
     *
     * Built from Licensio_KB_Settings::SIDEBAR_POSITIONS rather than repeated
     * three times, so the select can never offer a value the sanitizer would
     * refuse, nor omit one it would accept.
     *
     * @since 1.0.0
     *
     * @return array<string, string> Sidebar position value to label.
     */
    private static function sidebar_choices() {
        $labels = array(
            'left'  => __( 'Left', 'licensio-knowledge-base' ),
            'right' => __( 'Right', 'licensio-knowledge-base' ),
            'none'  => __( 'No sidebar', 'licensio-knowledge-base' ),
        );

        $choices = array();

        foreach ( Licensio_KB_Settings::SIDEBAR_POSITIONS as $position ) {
            $choices[ $position ] = isset( $labels[ $position ] ) ? $labels[ $position ] : $position;
        }

        return $choices;
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
