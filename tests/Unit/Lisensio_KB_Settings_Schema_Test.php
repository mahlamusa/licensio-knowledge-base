<?php
/**
 * The settings screen schema.
 *
 * Two things are worth proving here. The first is that no registry key can be
 * lost on the way to the screen, because a stored setting nobody can see or
 * change is the worst failure a settings screen has. The second is that the
 * appearance preview really does describe what the frontend will do: the
 * name/value pairs the screen previews are compared against the ones
 * Lisensio_KB_Templates actually publishes, so the two cannot drift.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

/**
 * Drives Lisensio_KB_Settings_Schema.
 *
 * @since 1.0.0
 */
class Lisensio_KB_Settings_Schema_Test extends Lisensio_KB_Test_Case {

    /**
     * The schema built from the plugin's own registry.
     *
     * @since 1.0.0
     *
     * @return array<int, array<string, mixed>> The groups.
     */
    private function schema() {
        return ( new Lisensio_KB_Settings_Schema( new Lisensio_KB_Settings() ) )->schema();
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
    public function test_the_screen_has_the_six_groups() {
        $ids = array();

        foreach ( $this->schema() as $group ) {
            $ids[] = $group['id'];
        }

        $this->assertSame(
            array( 'general', 'slugs', 'appearance', 'search', 'feedback', 'privacy' ),
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
        $registry = ( new Lisensio_KB_Settings() )->registry();

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

        $schema = ( new Lisensio_KB_Settings_Schema( new Lisensio_KB_Settings_Spy( $registry ) ) )->schema();
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

        $schema = new class( new Lisensio_KB_Settings_Spy( $registry ) ) extends Lisensio_KB_Settings_Schema {

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
        $registry = ( new Lisensio_KB_Settings() )->registry();

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

        foreach ( array_keys( Lisensio_KB_Templates::LAYOUT_WIDTHS ) as $layout ) {
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

        foreach ( Lisensio_KB_Settings::CARD_STYLES as $style ) {
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
        $this->options[ Lisensio_KB_Settings::OPTION_NAME ] = array( $key => $value );

        return ( new Lisensio_KB_Templates( new Lisensio_KB_Settings() ) )->custom_properties();
    }
}
