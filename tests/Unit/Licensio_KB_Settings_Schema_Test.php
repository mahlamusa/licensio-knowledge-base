<?php
/**
 * The settings screen schema.
 *
 * Two things are worth proving here. The first is that no registry key can be
 * lost on the way to the screen, because a stored setting nobody can see or
 * change is the worst failure a settings screen has. The second is that the
 * appearance preview really does describe what the frontend will do: the
 * name/value pairs the screen previews are compared against the ones
 * Licensio_KB_Templates actually publishes, so the two cannot drift.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

/**
 * Drives Licensio_KB_Settings_Schema.
 *
 * @since 1.0.0
 */
class Licensio_KB_Settings_Schema_Test extends Licensio_KB_Test_Case {

    /**
     * The schema built from the plugin's own registry.
     *
     * @since 1.0.0
     *
     * @return array<int, array<string, mixed>> The groups.
     */
    private function schema() {
        return ( new Licensio_KB_Settings_Schema( new Licensio_KB_Settings() ) )->schema();
    }

    /**
     * Every field in the schema, keyed by setting key.
     *
     * @since 1.0.0
     *
     * @param array<int, array<string, mixed>>|null $schema The schema, or null to build the plugin's own.
     *
     * @return array<string, array<string, mixed>> The fields.
     */
    private function fields( $schema = null ) {
        $fields = array();

        foreach ( ( null === $schema ? $this->schema() : $schema ) as $group ) {
            foreach ( $group['fields'] as $field ) {
                $fields[ $field['key'] ] = $field;
            }
        }

        return $fields;
    }

    /**
     * The screen is divided into the six groups the issue names.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_screen_has_the_seven_groups() {
        $ids = array();

        foreach ( $this->schema() as $group ) {
            $ids[] = $group['id'];
        }

        $this->assertSame(
            array( 'general', 'slugs', 'appearance', 'sidebar', 'search', 'feedback', 'privacy' ),
            $ids
        );
    }

    /**
     * Every registry key reaches the screen.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_every_registry_key_reaches_the_screen() {
        $registry = ( new Licensio_KB_Settings() )->registry();

        $this->assertSame(
            array(),
            array_diff( array_keys( $registry ), array_keys( $this->fields() ) )
        );
    }

    /**
     * A key the schema has never heard of still gets an editor.
     *
     * The registry is extensible. A key added by another plugin that fell out of
     * the schema would be stored, read and acted on by the plugin while being
     * invisible and uneditable on the only screen that manages it.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_unmapped_registry_key_still_gets_an_editor() {
        $registry = array(
            'somebody_elses_toggle' => array(
                'type'    => 'bool',
                'default' => false,
            ),
        );

        $schema = ( new Licensio_KB_Settings_Schema( new Licensio_KB_Settings_Spy( $registry ) ) )->schema();
        $fields = $this->fields( $schema );

        $this->assertArrayHasKey( 'somebody_elses_toggle', $fields );
        $this->assertSame( 'general', $fields['somebody_elses_toggle']['group'] );
        $this->assertSame( 'toggle', $fields['somebody_elses_toggle']['control'] );
        $this->assertSame( 'Somebody elses toggle', $fields['somebody_elses_toggle']['label'] );
    }

    /**
     * A key claiming a group the screen does not draw falls into General.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_key_claiming_an_unknown_group_falls_into_general() {
        $registry = array(
            'stray' => array(
                'type'    => 'string',
                'default' => '',
            ),
        );

        $schema = new class( new Licensio_KB_Settings_Spy( $registry ) ) extends Licensio_KB_Settings_Schema {

            /**
             * A field map naming a group the screen does not draw.
             *
             * @since 1.0.0
             *
             * @return array<string, array<string, mixed>> The map.
             */
            public function field_map() {
                return array(
                    'stray' => array(
                        'group'   => 'nowhere',
                        'label'   => 'Stray',
                        'control' => 'text',
                    ),
                );
            }
        };

        $fields = $this->fields( $schema->schema() );

        $this->assertArrayHasKey( 'stray', $fields );
        $this->assertSame( 'general', $fields['stray']['group'] );
    }

    /**
     * The bounds a number is edited under come from the registry.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_number_bounds_come_from_the_registry() {
        $fields   = $this->fields();
        $registry = ( new Licensio_KB_Settings() )->registry();

        $this->assertSame( $registry['articles_per_page']['min'], $fields['articles_per_page']['min'] );
        $this->assertSame( $registry['articles_per_page']['max'], $fields['articles_per_page']['max'] );
        $this->assertSame( 'number', $fields['articles_per_page']['control'] );
    }

    /**
     * The layout preview matches the widths the template layer publishes.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_layout_preview_matches_the_template_layer() {
        $preview = $this->fields()['layout_width']['preview'];

        $this->assertSame( 'map', $preview['kind'] );

        foreach ( array_keys( Licensio_KB_Templates::LAYOUT_WIDTHS ) as $layout ) {
            $published = $this->published_properties( 'layout_width', $layout );

            foreach ( $preview['values'][ $layout ] as $property => $value ) {
                $this->assertSame(
                    $published[ $property ],
                    $value,
                    sprintf( 'The %1$s layout preview publishes a different %2$s.', $layout, $property )
                );
            }
        }
    }

    /**
     * The card preview matches the declarations the template layer publishes.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_card_preview_matches_the_template_layer() {
        $preview = $this->fields()['card_style']['preview'];

        foreach ( Licensio_KB_Settings::CARD_STYLES as $style ) {
            $published = $this->published_properties( 'card_style', $style );

            foreach ( $preview['values'][ $style ] as $property => $value ) {
                $this->assertSame(
                    $published[ $property ],
                    $value,
                    sprintf( 'The %1$s card preview publishes a different %2$s.', $style, $property )
                );
            }
        }
    }

    /**
     * The brand colour preview names the properties the template layer sets.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_brand_preview_names_the_published_properties() {
        $preview   = $this->fields()['brand_color']['preview'];
        $published = $this->published_properties( 'brand_color', '#ff0000' );

        $this->assertSame( 'color', $preview['kind'] );

        foreach ( $preview['properties'] as $property ) {
            $this->assertArrayHasKey( $property, $published );
        }

        $this->assertSame( '#ff0000', $published[ $preview['properties']['value'] ] );
        $this->assertSame( '255,0,0', $published[ $preview['properties']['rgb'] ] );
    }

    /**
     * Each presentation setting reaches the group the issue asked for.
     *
     * Asserted per key rather than by counting a group, because a count is
     * satisfied by the right number of the wrong fields.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_presentation_settings_land_in_their_groups() {
        $fields = $this->fields();

        $expected = array(
            'header_style'              => 'appearance',
            'header_image_id'           => 'appearance',
            'archive_header_text'       => 'appearance',
            'search_placeholder'        => 'appearance',
            'archive_columns'           => 'appearance',
            'show_subcategories'        => 'general',
            'show_article_counts'       => 'general',
            'sticky_sidebar'            => 'sidebar',
            'archive_sidebar_position'  => 'sidebar',
            'article_sidebar_position'  => 'sidebar',
            'category_sidebar_position' => 'sidebar',
        );

        foreach ( $expected as $key => $group ) {
            $this->assertArrayHasKey( $key, $fields, $key . ' must reach the settings screen.' );
            $this->assertSame( $group, $fields[ $key ]['group'], $key . ' is in the wrong group.' );
        }
    }

    /**
     * Every presentation setting is written into the field map, not inferred.
     *
     * Checked against field_map() rather than against the rendered field,
     * because an unmapped key still renders: it falls into General with a
     * label derived from its own key, which for a setting that belongs in
     * General anyway is indistinguishable from a mapped one.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_every_presentation_setting_is_mapped() {
        $map = ( new Licensio_KB_Settings_Schema( new Licensio_KB_Settings() ) )->field_map();

        $keys = array(
            'header_style',
            'header_image_id',
            'archive_header_text',
            'search_placeholder',
            'archive_columns',
            'show_subcategories',
            'show_article_counts',
            'sticky_sidebar',
            'archive_sidebar_position',
            'article_sidebar_position',
            'category_sidebar_position',
        );

        foreach ( $keys as $key ) {
            $this->assertArrayHasKey( $key, $map, $key . ' has no presentation metadata.' );

            foreach ( array( 'group', 'label', 'description', 'control' ) as $part ) {
                $this->assertArrayHasKey( $part, $map[ $key ], $key . ' declares no ' . $part . '.' );
                $this->assertNotSame( '', $map[ $key ][ $part ], $key . ' declares an empty ' . $part . '.' );
            }
        }
    }

    /**
     * The controls match what each setting actually is.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_presentation_settings_get_the_right_controls() {
        $fields = $this->fields();

        $this->assertSame( 'select', $fields['header_style']['control'] );
        $this->assertSame( 'number', $fields['header_image_id']['control'] );
        $this->assertSame( 'text', $fields['archive_header_text']['control'] );
        $this->assertSame( 'text', $fields['search_placeholder']['control'] );
        $this->assertSame( 'number', $fields['archive_columns']['control'] );
        $this->assertSame( 'toggle', $fields['show_subcategories']['control'] );
        $this->assertSame( 'toggle', $fields['show_article_counts']['control'] );
        $this->assertSame( 'toggle', $fields['sticky_sidebar']['control'] );
        $this->assertSame( 'select', $fields['archive_sidebar_position']['control'] );
    }

    /**
     * The header style select offers exactly the values the sanitiser accepts.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_header_style_choices_match_the_allowlist() {
        $values = array_column( $this->fields()['header_style']['choices'], 'value' );

        $this->assertSame( Licensio_KB_Settings::HEADER_STYLES, $values );
    }

    /**
     * Every sidebar select offers exactly the values the sanitiser accepts.
     *
     * A select that offered a fourth position would produce a setting the store
     * silently replaced with the default, which reads to the user as a save
     * that did not save.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_every_sidebar_select_matches_the_allowlist() {
        $fields = $this->fields();

        $keys = array(
            'archive_sidebar_position',
            'article_sidebar_position',
            'category_sidebar_position',
        );

        foreach ( $keys as $key ) {
            $choices = $fields[ $key ]['choices'];

            $this->assertSame(
                Licensio_KB_Settings::SIDEBAR_POSITIONS,
                array_column( $choices, 'value' ),
                $key . ' offers something other than the allowlist.'
            );

            foreach ( $choices as $choice ) {
                $this->assertNotSame(
                    $choice['value'],
                    $choice['label'],
                    $key . ' is showing a raw value instead of a label.'
                );
            }
        }
    }

    /**
     * The archive column bounds reach the number control from the registry.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_archive_column_bounds_reach_the_screen() {
        $field = $this->fields()['archive_columns'];

        $this->assertSame( 0, $field['min'] );
        $this->assertSame( 4, $field['max'] );
    }

    /**
     * The sidebar group is announced with a category and an icon, like the rest.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_sidebar_group_is_described_like_every_other_group() {
        $sidebar = null;

        foreach ( $this->schema() as $group ) {
            if ( 'sidebar' === $group['id'] ) {
                $sidebar = $group;
            }
        }

        $this->assertIsArray( $sidebar );
        $this->assertNotSame( '', $sidebar['label'] );
        $this->assertNotSame( '', $sidebar['description'] );
        $this->assertSame( 'core', $sidebar['category'] );
        $this->assertNotSame( 'admin-settings', $sidebar['icon'] );
        $this->assertSame( 6, $sidebar['count'] );
    }

    /**
     * Every group names a category the sidebar actually renders.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_every_group_belongs_to_a_declared_category() {
        $schema     = new Licensio_KB_Settings_Schema( new Licensio_KB_Settings() );
        $categories = array_keys( $schema->categories() );

        foreach ( $schema->schema() as $group ) {
            $this->assertContains(
                $group['category'],
                $categories,
                $group['id'] . ' names a category that does not exist.'
            );
        }
    }

    /**
     * The custom properties the frontend publishes for one stored value.
     *
     * @since 1.0.0
     *
     * @param string $key   The setting to set.
     * @param string $value The value to set it to.
     *
     * @return array<string, string> Custom property name to value.
     */
    private function published_properties( $key, $value ) {
        $this->options[ Licensio_KB_Settings::OPTION_NAME ] = array( $key => $value );

        return ( new Licensio_KB_Templates( new Licensio_KB_Settings() ) )->custom_properties();
    }
}
