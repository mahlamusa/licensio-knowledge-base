<?php
/**
 * Unit tests for the settings store.
 *
 * Covers the defaults registry, type casting on read and write, rejection of
 * unregistered keys and the extension filter sub-issues use to add settings.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

use Brain\Monkey\Filters;

/**
 * Tests Lisensio_KB_Settings.
 *
 * @since 1.0.0
 */
class Lisensio_KB_Settings_Test extends Lisensio_KB_Test_Case {

    /**
     * With nothing stored, every setting comes back at its default.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_defaults_are_returned_when_nothing_is_stored() {
        $settings = new Lisensio_KB_Settings();

        $this->assertSame( 'docs', $settings->get( 'article_slug' ) );
        $this->assertSame( 90, $settings->get( 'analytics_retention_days' ) );
        $this->assertTrue( $settings->get( 'enable_feedback' ) );
        $this->assertSame( $settings->defaults(), $settings->all() );
    }

    /**
     * The article rewrite slug defaults to `docs`, as the specification requires.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_article_slug_default_is_docs() {
        $settings = new Lisensio_KB_Settings();

        $this->assertSame( 'docs', $settings->defaults()['article_slug'] );
    }

    /**
     * A written value is read back, cast to its registered type.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_values_are_cast_to_their_registered_type() {
        $settings = new Lisensio_KB_Settings();

        $settings->set( 'analytics_retention_days', '45' );
        $settings->set( 'enable_analytics', 'false' );
        $settings->set( 'article_slug', 'handbook' );

        $this->assertSame( 45, $settings->get( 'analytics_retention_days' ) );
        $this->assertFalse( $settings->get( 'enable_analytics' ) );
        $this->assertSame( 'handbook', $settings->get( 'article_slug' ) );
    }

    /**
     * Boolean settings accept the usual truthy and falsy representations.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_boolean_casting_handles_string_representations() {
        $settings = new Lisensio_KB_Settings();

        foreach ( array( '1', 'true', 'yes', 'on', 1, true ) as $truthy ) {
            $settings->set( 'enable_feedback', $truthy );
            $this->assertTrue( $settings->get( 'enable_feedback' ) );
        }

        foreach ( array( '0', 'false', 'no', 'off', 0, false, '' ) as $falsy ) {
            $settings->set( 'enable_feedback', $falsy );
            $this->assertFalse( $settings->get( 'enable_feedback' ) );
        }
    }

    /**
     * Several settings can be written in one call.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_update_writes_several_settings_at_once() {
        $settings = new Lisensio_KB_Settings();

        $this->assertTrue(
            $settings->update(
                array(
                    'brand_color'       => '#ff0000',
                    'articles_per_page' => 5,
                )
            )
        );

        $this->assertSame( '#ff0000', $settings->get( 'brand_color' ) );
        $this->assertSame( 5, $settings->get( 'articles_per_page' ) );
    }

    /**
     * An unregistered key is neither stored nor readable.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_unregistered_keys_are_rejected() {
        $settings = new Lisensio_KB_Settings();

        $this->assertFalse( $settings->has( 'not_a_setting' ) );
        $this->assertFalse( $settings->set( 'not_a_setting', 'value' ) );
        $this->assertNull( $settings->get( 'not_a_setting' ) );
        $this->assertSame( 'fallback', $settings->get( 'not_a_setting', 'fallback' ) );
        $this->assertArrayNotHasKey( 'not_a_setting', $settings->all() );
    }

    /**
     * Only the keys that were written end up in the stored option.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_only_written_keys_are_persisted() {
        $settings = new Lisensio_KB_Settings();
        $settings->set( 'article_slug', 'kb' );

        $this->assertSame(
            array( 'article_slug' => 'kb' ),
            $this->options[ Lisensio_KB_Settings::OPTION_NAME ]
        );
    }

    /**
     * Deleting the option returns every setting to its default.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_delete_restores_the_defaults() {
        $settings = new Lisensio_KB_Settings();
        $settings->set( 'article_slug', 'kb' );

        $this->assertSame( 'kb', $settings->get( 'article_slug' ) );

        $settings->delete();

        $this->assertSame( 'docs', $settings->get( 'article_slug' ) );
    }

    /**
     * A corrupt option value falls back to the defaults instead of erroring.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_non_array_option_falls_back_to_defaults() {
        $this->options[ Lisensio_KB_Settings::OPTION_NAME ] = 'corrupt';

        $settings = new Lisensio_KB_Settings();

        $this->assertSame( 'docs', $settings->get( 'article_slug' ) );
    }

    /**
     * Sub-issues extend the registry through the filter.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_registry_filter_adds_new_settings() {
        Filters\expectApplied( 'lisensio_settings_registry' )
            ->andReturnUsing(
                function ( $registry ) {
                    $registry['deflection_enabled'] = array(
                        'type'    => 'bool',
                        'default' => false,
                    );

                    return $registry;
                }
            );

        $settings = new Lisensio_KB_Settings();

        $this->assertTrue( $settings->has( 'deflection_enabled' ) );
        $this->assertFalse( $settings->get( 'deflection_enabled' ) );
    }

    /**
     * A slug is reduced to slug characters before it reaches a permastruct.
     *
     * These three values are read straight into register_post_type() and
     * register_taxonomy() rewrite arguments, where a type cast alone let
     * arbitrary strings through into a permalink structure.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_slugs_are_sanitized_on_write() {
        $settings = new Lisensio_KB_Settings();

        $settings->set( 'article_slug', '../../../wp-config' );
        $this->assertSame( 'wp-config', $settings->get( 'article_slug' ) );

        $settings->set( 'category_slug', 'Docs Category' );
        $this->assertSame( 'docs-category', $settings->get( 'category_slug' ) );

        $settings->set( 'tag_slug', '<script>alert(1)</script>' );
        $this->assertStringNotContainsString( '<', $settings->get( 'tag_slug' ) );
    }

    /**
     * A slug that sanitizes away falls back to its default, never to empty.
     *
     * Registering a taxonomy with an empty rewrite slug produces broken
     * permastructs rather than an error, so an empty value must not be stored.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_empty_slug_falls_back_to_the_default() {
        $settings = new Lisensio_KB_Settings();

        foreach ( array(
            'article_slug'  => 'docs',
            'category_slug' => 'docs-category',
            'tag_slug'      => 'docs-tag',
        ) as $key => $default ) {
            $settings->set( $key, '' );
            $this->assertSame( $default, $settings->get( $key ), $key . ' must fall back to its default.' );

            $settings->set( $key, '///' );
            $this->assertSame( $default, $settings->get( $key ), $key . ' must reject a slug of only separators.' );
        }
    }

    /**
     * A nested slug path is preserved.
     *
     * `help/docs` is a legitimate permastruct base, so the sanitizer cleans each
     * segment rather than flattening the whole value.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_nested_slug_path_is_preserved() {
        $settings = new Lisensio_KB_Settings();

        $settings->set( 'article_slug', 'Help/My Docs' );

        $this->assertSame( 'help/my-docs', $settings->get( 'article_slug' ) );
    }

    /**
     * The brand colour rejects anything that is not a hex colour.
     *
     * The value is emitted into a CSS custom property by #5 and #6, so a value
     * that closes the style block or injects a rule would be stored XSS in every
     * consumer that forgot to escape it.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_brand_color_rejects_css_and_markup_injection() {
        $settings = new Lisensio_KB_Settings();

        foreach ( array( 'red;}body{background:url(//evil/x)', '</style><script>alert(1)</script>', 'red', '#gggggg', '' ) as $payload ) {
            $settings->set( 'brand_color', $payload );

            $this->assertSame( '#2563eb', $settings->get( 'brand_color' ), 'A non-colour must fall back to the default.' );
        }
    }

    /**
     * A real hex colour is accepted, in both lengths.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_brand_color_accepts_valid_hex_values() {
        $settings = new Lisensio_KB_Settings();

        $settings->set( 'brand_color', '#ff0000' );
        $this->assertSame( '#ff0000', $settings->get( 'brand_color' ) );

        $settings->set( 'brand_color', '#abc' );
        $this->assertSame( '#abc', $settings->get( 'brand_color' ) );
    }

    /**
     * Enumerated appearance settings only accept known values.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_enumerated_settings_are_allowlisted() {
        $settings = new Lisensio_KB_Settings();

        $settings->set( 'layout_width', 'wide' );
        $this->assertSame( 'wide', $settings->get( 'layout_width' ) );

        $settings->set( 'layout_width', 'enormous' );
        $this->assertSame( 'contained', $settings->get( 'layout_width' ) );

        $settings->set( 'card_style', 'border' );
        $this->assertSame( 'border', $settings->get( 'card_style' ) );

        $settings->set( 'card_style', '"><script>' );
        $this->assertSame( 'shadow', $settings->get( 'card_style' ) );
    }

    /**
     * Numeric settings are held inside their registered bounds.
     *
     * A retention period of zero or a negative number is read by #9's cleanup
     * job as either "delete everything" or "retain forever".
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_numeric_settings_are_clamped_to_their_bounds() {
        $settings = new Lisensio_KB_Settings();

        $settings->set( 'analytics_retention_days', -30 );
        $this->assertSame( 1, $settings->get( 'analytics_retention_days' ) );

        $settings->set( 'analytics_retention_days', 999999 );
        $this->assertSame( 3650, $settings->get( 'analytics_retention_days' ) );

        $settings->set( 'search_results_limit', 0 );
        $this->assertSame( 1, $settings->get( 'search_results_limit' ) );

        $settings->set( 'articles_per_page', 100000 );
        $this->assertSame( 500, $settings->get( 'articles_per_page' ) );
    }

    /**
     * A retention period of zero falls back to the default, never to one day.
     *
     * People type 0 meaning "keep forever". Clamping that up to the 1-day
     * minimum would silently destroy the analytics history it was meant to
     * preserve — data loss dressed as validation. Falling back to 90 days is
     * wrong in a way the site owner can see and correct; one-day retention is
     * wrong in a way they discover only after the rows are gone.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_zero_retention_period_falls_back_to_the_default() {
        $settings = new Lisensio_KB_Settings();

        $settings->set( 'analytics_retention_days', 0 );
        $this->assertSame( 90, $settings->get( 'analytics_retention_days' ) );

        $settings->set( 'analytics_retention_days', '0' );
        $this->assertSame( 90, $settings->get( 'analytics_retention_days' ) );

        $settings->set( 'analytics_retention_days', 'forever' );
        $this->assertSame( 90, $settings->get( 'analytics_retention_days' ) );
    }

    /**
     * A real retention period is still stored verbatim.
     *
     * The positive control for the rule above: without it, the zero test would
     * pass just as well if every value fell back to the default.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_valid_retention_period_is_stored_unchanged() {
        $settings = new Lisensio_KB_Settings();

        $settings->set( 'analytics_retention_days', 30 );
        $this->assertSame( 30, $settings->get( 'analytics_retention_days' ) );

        $settings->set( 'analytics_retention_days', '365' );
        $this->assertSame( 365, $settings->get( 'analytics_retention_days' ) );

        $settings->set( 'analytics_retention_days', 3650 );
        $this->assertSame( 3650, $settings->get( 'analytics_retention_days' ) );
    }

    /**
     * Validation runs on read too, not only on write.
     *
     * A value that reached the option by another route — a hand-edited row, a
     * database import, an older release — must not be handed to a consumer.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_hand_written_option_value_is_still_validated_on_read() {
        $this->options[ Lisensio_KB_Settings::OPTION_NAME ] = array(
            'article_slug'             => '../../evil',
            'brand_color'              => '</style><script>',
            'analytics_retention_days' => 0,
            'layout_width'             => 'enormous',
        );

        $settings = new Lisensio_KB_Settings();

        $this->assertSame( 'evil', $settings->get( 'article_slug' ) );
        $this->assertSame( '#2563eb', $settings->get( 'brand_color' ) );
        $this->assertSame( 90, $settings->get( 'analytics_retention_days' ) );
        $this->assertSame( 'contained', $settings->get( 'layout_width' ) );
    }

    /**
     * Writing a value that is already stored reports success, not failure.
     *
     * Core's update_option() returns false for a no-op write. Passing that
     * through made #10's settings screen show an error for the single most
     * common interaction there is: saving a form nobody changed.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_writing_an_unchanged_value_reports_success() {
        $settings = new Lisensio_KB_Settings();

        $this->assertTrue( $settings->set( 'brand_color', '#ff0000' ) );
        $this->assertTrue( $settings->set( 'brand_color', '#ff0000' ), 'A no-op save must not report failure.' );
        $this->assertTrue( $settings->update( array( 'brand_color' => '#ff0000' ) ) );
        $this->assertSame( '#ff0000', $settings->get( 'brand_color' ) );
    }

    /**
     * Saving the untouched defaults reports success.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_saving_the_defaults_unchanged_reports_success() {
        $settings = new Lisensio_KB_Settings();

        $this->assertTrue( $settings->update( $settings->defaults() ) );
    }

    /**
     * Changing a slug asks for the rewrite rules to be rebuilt.
     *
     * Without this every article, category and tag URL 404s until permalinks are
     * re-saved by hand, and #10 cannot supply it without editing this class.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_changing_a_slug_requests_a_rewrite_flush() {
        $settings = new Lisensio_KB_Settings();

        $this->assertFalse( Lisensio_KB_Settings::needs_rewrite_flush() );

        $settings->set( 'article_slug', 'handbook' );

        $this->assertTrue( Lisensio_KB_Settings::needs_rewrite_flush() );
    }

    /**
     * A setting that does not affect rewrites does not request a flush.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_non_rewrite_setting_does_not_request_a_flush() {
        $settings = new Lisensio_KB_Settings();

        $settings->set( 'brand_color', '#ff0000' );
        $settings->set( 'articles_per_page', 5 );

        $this->assertFalse( Lisensio_KB_Settings::needs_rewrite_flush() );
    }

    /**
     * Re-saving a slug that did not actually change requests no flush.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_resaving_an_unchanged_slug_requests_no_flush() {
        $settings = new Lisensio_KB_Settings();

        $settings->set( 'article_slug', 'docs' );

        $this->assertFalse( Lisensio_KB_Settings::needs_rewrite_flush() );
    }

    /**
     * Data is preserved on uninstall unless the owner opts in.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_delete_data_on_uninstall_defaults_to_false() {
        $settings = new Lisensio_KB_Settings();

        $this->assertTrue( $settings->has( 'delete_data_on_uninstall' ) );
        $this->assertFalse( $settings->get( 'delete_data_on_uninstall' ) );
    }

    /**
     * A consumer can register its own sanitizer through the registry filter.
     *
     * This is the seam that stops #4, #5, #9 and #10 each editing this class to
     * add validation for their own settings.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_consumer_can_register_its_own_sanitizer() {
        Filters\expectApplied( 'lisensio_settings_registry' )
            ->andReturnUsing(
                function ( $registry ) {
                    $registry['deflection_mode'] = array(
                        'type'     => 'string',
                        'default'  => 'off',
                        'sanitize' => static function ( $value ) {
                            return in_array( $value, array( 'off', 'on' ), true ) ? $value : null;
                        },
                    );

                    return $registry;
                }
            );

        $settings = new Lisensio_KB_Settings();

        $settings->set( 'deflection_mode', 'on' );
        $this->assertSame( 'on', $settings->get( 'deflection_mode' ) );

        $settings->set( 'deflection_mode', 'nonsense' );
        $this->assertSame( 'off', $settings->get( 'deflection_mode' ) );
    }
}
