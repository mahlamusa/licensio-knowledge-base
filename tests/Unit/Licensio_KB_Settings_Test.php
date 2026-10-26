<?php
/**
 * Unit tests for the settings store.
 *
 * Covers the defaults registry, type casting on read and write, rejection of
 * unregistered keys and the extension filter sub-issues use to add settings.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

/**
 * Tests Licensio_KB_Settings.
 *
 * @since 1.0.0
 */
class Licensio_KB_Settings_Test extends Licensio_KB_Test_Case {

    /**
     * With nothing stored, every setting comes back at its default.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_defaults_are_returned_when_nothing_is_stored() {
        $settings = new Licensio_KB_Settings();

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
        $settings = new Licensio_KB_Settings();

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
        $settings = new Licensio_KB_Settings();

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
        $settings = new Licensio_KB_Settings();

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
        $settings = new Licensio_KB_Settings();

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
     * A preview resolves values exactly as update() would store them, and saves nothing.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_preview_sanitizes_without_saving() {
        $settings = new Licensio_KB_Settings();
        $settings->set( 'articles_per_page', 20 );

        $preview = $settings->preview(
            array(
                'articles_per_page' => '20',
                'brand_color'       => '#FF0000',
                'not_a_setting'     => 'x',
            )
        );

        $this->assertSame( 20, $preview['articles_per_page'], 'A numeric string resolves to the same int, so it is not a change.' );
        $this->assertSame( $settings->sanitize_brand_color( '#FF0000' ), $preview['brand_color'] );
        $this->assertArrayNotHasKey( 'not_a_setting', $preview );
        $this->assertSame( array( 'articles_per_page' => 20 ), $this->options[ Licensio_KB_Settings::OPTION_NAME ] );
    }

    /**
     * An unregistered key is neither stored nor readable.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_unregistered_keys_are_rejected() {
        $settings = new Licensio_KB_Settings();

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
        $settings = new Licensio_KB_Settings();
        $settings->set( 'article_slug', 'kb' );

        $this->assertSame(
            array( 'article_slug' => 'kb' ),
            $this->options[ Licensio_KB_Settings::OPTION_NAME ]
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
        $settings = new Licensio_KB_Settings();
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
        $this->options[ Licensio_KB_Settings::OPTION_NAME ] = 'corrupt';

        $settings = new Licensio_KB_Settings();

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
        Filters\expectApplied( 'licensio_settings_registry' )
            ->andReturnUsing(
                function ( $registry ) {
                    $registry['deflection_enabled'] = array(
                        'type'    => 'bool',
                        'default' => false,
                    );

                    return $registry;
                }
            );

        $settings = new Licensio_KB_Settings();

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
        $settings = new Licensio_KB_Settings();

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
        $settings = new Licensio_KB_Settings();

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
        $settings = new Licensio_KB_Settings();

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
        $settings = new Licensio_KB_Settings();

        foreach ( array( 'red;}body{background:url(//evil/x)', '</style><script>alert(1)</script>', 'red', '#gggggg', '' ) as $payload ) {
            $settings->set( 'brand_color', $payload );

            $this->assertSame( '', $settings->get( 'brand_color' ), 'A non-colour must fall back to the empty default.' );
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
        $this->assertSame( '', Licensio_KB_Settings::sanitize_brand_color( '' ), 'An empty colour means use the theme, so it is accepted.' );
        $this->assertSame( '', ( new Licensio_KB_Settings() )->defaults()['brand_color'], 'The brand colour defaults to the theme.' );

        $settings = new Licensio_KB_Settings();

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
        $settings = new Licensio_KB_Settings();

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
        $settings = new Licensio_KB_Settings();

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
     * The low rating rule defaults to under 50 per cent with at least 5 votes.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_low_rating_settings_have_their_defaults() {
        $settings = new Licensio_KB_Settings();

        $this->assertSame( 50, $settings->get( 'low_rating_threshold' ) );
        $this->assertSame( 5, $settings->get( 'low_rating_min_votes' ) );
    }

    /**
     * The low rating threshold is held between 1 and 99 per cent.
     *
     * At 0 no article could ever be low rated, and at 100 every article
     * short of a perfect score would be.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_low_rating_threshold_is_clamped() {
        $settings = new Licensio_KB_Settings();

        $settings->set( 'low_rating_threshold', 0 );
        $this->assertSame( 1, $settings->get( 'low_rating_threshold' ) );

        $settings->set( 'low_rating_threshold', 100 );
        $this->assertSame( 99, $settings->get( 'low_rating_threshold' ) );

        $settings->set( 'low_rating_threshold', '75' );
        $this->assertSame( 75, $settings->get( 'low_rating_threshold' ) );
    }

    /**
     * The minimum vote count for the low rating rule is at least 1.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_low_rating_minimum_votes_is_at_least_one() {
        $settings = new Licensio_KB_Settings();

        $settings->set( 'low_rating_min_votes', 0 );
        $this->assertSame( 1, $settings->get( 'low_rating_min_votes' ) );

        $settings->set( 'low_rating_min_votes', -5 );
        $this->assertSame( 1, $settings->get( 'low_rating_min_votes' ) );

        $settings->set( 'low_rating_min_votes', '12' );
        $this->assertSame( 12, $settings->get( 'low_rating_min_votes' ) );
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
        $settings = new Licensio_KB_Settings();

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
        $settings = new Licensio_KB_Settings();

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
        $this->options[ Licensio_KB_Settings::OPTION_NAME ] = array(
            'article_slug'             => '../../evil',
            'brand_color'              => '</style><script>',
            'analytics_retention_days' => 0,
            'layout_width'             => 'enormous',
        );

        $settings = new Licensio_KB_Settings();

        $this->assertSame( 'evil', $settings->get( 'article_slug' ) );
        $this->assertSame( '', $settings->get( 'brand_color' ) );
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
        $settings = new Licensio_KB_Settings();

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
        $settings = new Licensio_KB_Settings();

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
        $settings = new Licensio_KB_Settings();

        $this->assertFalse( Licensio_KB_Settings::needs_rewrite_flush() );

        $settings->set( 'article_slug', 'handbook' );

        $this->assertTrue( Licensio_KB_Settings::needs_rewrite_flush() );
    }

    /**
     * A setting that does not affect rewrites does not request a flush.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_non_rewrite_setting_does_not_request_a_flush() {
        $settings = new Licensio_KB_Settings();

        $settings->set( 'brand_color', '#ff0000' );
        $settings->set( 'articles_per_page', 5 );

        $this->assertFalse( Licensio_KB_Settings::needs_rewrite_flush() );
    }

    /**
     * Re-saving a slug that did not actually change requests no flush.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_resaving_an_unchanged_slug_requests_no_flush() {
        $settings = new Licensio_KB_Settings();

        $settings->set( 'article_slug', 'docs' );

        $this->assertFalse( Licensio_KB_Settings::needs_rewrite_flush() );
    }

    /**
     * Data is preserved on uninstall unless the owner opts in.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_delete_data_on_uninstall_defaults_to_false() {
        $settings = new Licensio_KB_Settings();

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
        Filters\expectApplied( 'licensio_settings_registry' )
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

        $settings = new Licensio_KB_Settings();

        $settings->set( 'deflection_mode', 'on' );
        $this->assertSame( 'on', $settings->get( 'deflection_mode' ) );

        $settings->set( 'deflection_mode', 'nonsense' );
        $this->assertSame( 'off', $settings->get( 'deflection_mode' ) );
    }

    /**
     * The presentation settings default to the values the design already expects.
     *
     * These eleven were read off a running site rather than invented, so a
     * change to any one of them is a change to what an existing knowledge base
     * looks like the moment it upgrades. They are asserted literally for that
     * reason: the point of the test is that these exact values are the ones a
     * fresh install starts from.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_presentation_settings_default_to_the_live_values() {
        $defaults = ( new Licensio_KB_Settings() )->defaults();

        $this->assertSame( 'solid', $defaults['header_style'] );
        $this->assertSame( 0, $defaults['header_image_id'] );
        $this->assertSame( 'Help Topics', $defaults['archive_header_text'] );
        $this->assertSame( 'Search the Knowledge Base...', $defaults['search_placeholder'] );
        $this->assertSame( 0, $defaults['archive_columns'] );
        $this->assertSame( '', $defaults['support_url'] );
        $this->assertFalse( $defaults['show_subcategories'] );
        $this->assertTrue( $defaults['show_article_counts'] );
        $this->assertFalse( $defaults['classic_screens'] );
        $this->assertTrue( $defaults['sticky_sidebar'] );
        $this->assertSame( 'right', $defaults['archive_sidebar_position'] );
        $this->assertSame( 'right', $defaults['article_sidebar_position'] );
        $this->assertSame( 'right', $defaults['category_sidebar_position'] );
    }

    /**
     * The header style is restricted to the three treatments the theme renders.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_header_style_is_allowlisted() {
        $settings = new Licensio_KB_Settings();

        foreach ( array( 'solid', 'gradient', 'image' ) as $style ) {
            $settings->set( 'header_style', $style );
            $this->assertSame( $style, $settings->get( 'header_style' ) );
        }

        foreach ( array( 'parallax', 'SOLID', '"><script>', '' ) as $payload ) {
            $settings->set( 'header_style', $payload );
            $this->assertSame(
                'solid',
                $settings->get( 'header_style' ),
                'An unknown header style must fall back to the default.'
            );
        }
    }

    /**
     * All three sidebar positions accept the same three answers and no others.
     *
     * Asserted key by key rather than once, because they are three independent
     * settings: a sanitiser wired to only one of them would still satisfy a test
     * that checked a single key.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_every_sidebar_position_takes_the_same_allowlist() {
        $settings = new Licensio_KB_Settings();

        $keys = array(
            'archive_sidebar_position',
            'article_sidebar_position',
            'category_sidebar_position',
        );

        foreach ( $keys as $key ) {
            foreach ( array( 'left', 'right', 'none' ) as $position ) {
                $settings->set( $key, $position );
                $this->assertSame( $position, $settings->get( $key ), $key . ' must accept ' . $position . '.' );
            }

            foreach ( array( 'top', 'both', 'Right', 'none;display:none', '' ) as $payload ) {
                $settings->set( $key, $payload );
                $this->assertSame(
                    'right',
                    $settings->get( $key ),
                    $key . ' must refuse an unknown position and fall back to the default.'
                );
            }
        }
    }

    /**
     * One sidebar position does not drag the other two along with it.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_sidebar_positions_are_independent_of_one_another() {
        $settings = new Licensio_KB_Settings();

        $settings->update(
            array(
                'archive_sidebar_position'  => 'left',
                'article_sidebar_position'  => 'none',
                'category_sidebar_position' => 'right',
            )
        );

        $this->assertSame( 'left', $settings->get( 'archive_sidebar_position' ) );
        $this->assertSame( 'none', $settings->get( 'article_sidebar_position' ) );
        $this->assertSame( 'right', $settings->get( 'category_sidebar_position' ) );
    }

    /**
     * The archive column count is held between zero and four.
     *
     * Zero is not a column count but the automatic pair: two columns beside a
     * sidebar, three without one. It is stored rather than resolved so the
     * sidebar can change without the setting silently moving.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_archive_column_count_is_clamped() {
        $settings = new Licensio_KB_Settings();

        $settings->set( 'archive_columns', 9 );
        $this->assertSame( 4, $settings->get( 'archive_columns' ) );

        $settings->set( 'archive_columns', 0 );
        $this->assertSame( 0, $settings->get( 'archive_columns' ) );

        $settings->set( 'archive_columns', -7 );
        $this->assertSame( 0, $settings->get( 'archive_columns' ) );

        $settings->set( 'archive_columns', '2' );
        $this->assertSame( 2, $settings->get( 'archive_columns' ) );
    }

    /**
     * A column count that is not a number at all falls back to the default.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_non_numeric_column_count_falls_back_to_the_default() {
        $settings = new Licensio_KB_Settings();

        foreach ( array( 'three', 'col-md-4', '' ) as $payload ) {
            $settings->set( 'archive_columns', $payload );
            $this->assertSame( 0, $settings->get( 'archive_columns' ) );
        }
    }

    /**
     * The column count is stored as a number, not as a class name.
     *
     * Heroic stored `kb-columns-3`, which put a presentation decision in the
     * database. The written value is inspected in the option itself rather than
     * through get(), so a read-side cast cannot make a stored class name look
     * like a number.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_column_count_is_stored_as_a_bare_number() {
        $settings = new Licensio_KB_Settings();

        $settings->set( 'archive_columns', 4 );

        $this->assertSame(
            array( 'archive_columns' => 4 ),
            $this->options[ Licensio_KB_Settings::OPTION_NAME ]
        );
    }

    /**
     * The header text and the search placeholder are stripped of markup.
     *
     * Both are echoed into the archive header, one as a heading and one as an
     * input placeholder attribute, so markup reaching the store is stored XSS.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_display_text_is_stripped_of_markup() {
        $settings = new Licensio_KB_Settings();

        foreach ( array( 'archive_header_text', 'search_placeholder' ) as $key ) {
            $settings->set( $key, '<script>alert(1)</script>Help Topics' );

            $stored = $this->options[ Licensio_KB_Settings::OPTION_NAME ][ $key ];

            $this->assertStringNotContainsString( '<', $stored, $key . ' must not store markup.' );
            $this->assertStringNotContainsString( '>', $stored, $key . ' must not store markup.' );
        }
    }

    /**
     * Display text can be cleared, because an empty heading is a real choice.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_display_text_can_be_emptied() {
        $settings = new Licensio_KB_Settings();

        $settings->set( 'archive_header_text', '' );
        $this->assertSame( '', $settings->get( 'archive_header_text' ) );

        $settings->set( 'search_placeholder', 'Search the docs' );
        $this->assertSame( 'Search the docs', $settings->get( 'search_placeholder' ) );
    }

    /**
     * Display text that is not text at all falls back to the default on read.
     *
     * Driven through the stored option rather than through set(), so only the
     * read-side sanitiser is exercised: a value shaped like this can only arrive
     * by a route that skipped the write path.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_display_text_that_is_not_text_falls_back_to_the_default() {
        $this->options[ Licensio_KB_Settings::OPTION_NAME ] = array(
            'archive_header_text' => array( 'Help Topics' ),
            'search_placeholder'  => null,
        );

        $settings = new Licensio_KB_Settings();

        $this->assertSame( 'Help Topics', $settings->get( 'archive_header_text' ) );
        $this->assertSame( 'Search the Knowledge Base...', $settings->get( 'search_placeholder' ) );
    }

    /**
     * A header image ID is kept only when it names a real attachment.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_header_image_must_be_an_attachment() {
        $this->stub_attachments( array( 42 ) );

        $settings = new Licensio_KB_Settings();

        $settings->set( 'header_image_id', 42 );
        $this->assertSame( 42, $settings->get( 'header_image_id' ) );

        $settings->set( 'header_image_id', 7 );
        $this->assertSame(
            0,
            $settings->get( 'header_image_id' ),
            'A post ID that is not an attachment must be refused.'
        );
    }

    /**
     * Zero clears the header image rather than being refused as an ID.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_zero_header_image_clears_the_image() {
        $this->stub_attachments( array( 42 ) );

        $settings = new Licensio_KB_Settings();

        $settings->set( 'header_image_id', 42 );
        $settings->set( 'header_image_id', 0 );

        $this->assertSame( 0, $settings->get( 'header_image_id' ) );
    }

    /**
     * A stored ID whose attachment has since been deleted stops being used.
     *
     * Driven through the stored option, so only the read-side sanitiser runs:
     * the ID was a valid attachment when it was written and is not one now.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_header_image_that_no_longer_exists_is_dropped_on_read() {
        $this->stub_attachments( array() );

        $this->options[ Licensio_KB_Settings::OPTION_NAME ] = array( 'header_image_id' => 42 );

        $settings = new Licensio_KB_Settings();

        $this->assertSame( 0, $settings->get( 'header_image_id' ) );
    }

    /**
     * The two display toggles behave as booleans.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_display_toggles_are_booleans() {
        $settings = new Licensio_KB_Settings();

        $settings->set( 'show_subcategories', 'true' );
        $settings->set( 'show_article_counts', 'false' );
        $settings->set( 'sticky_sidebar', 0 );

        $this->assertTrue( $settings->get( 'show_subcategories' ) );
        $this->assertFalse( $settings->get( 'show_article_counts' ) );
        $this->assertFalse( $settings->get( 'sticky_sidebar' ) );
    }

    /**
     * None of the new settings rebuilds the rewrite rules.
     *
     * They change what the archive looks like, not what it is addressed as, so
     * a save must not pay for a rewrite flush.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_presentation_setting_does_not_request_a_rewrite_flush() {
        $this->stub_attachments( array( 42 ) );

        $settings = new Licensio_KB_Settings();

        $settings->update(
            array(
                'header_style'             => 'gradient',
                'header_image_id'          => 42,
                'archive_header_text'      => 'Documentation',
                'search_placeholder'       => 'Search',
                'archive_columns'          => 2,
                'show_subcategories'       => true,
                'show_article_counts'      => false,
                'sticky_sidebar'           => false,
                'archive_sidebar_position' => 'left',
            )
        );

        $this->assertFalse( Licensio_KB_Settings::needs_rewrite_flush() );
    }

    /**
     * Teach the fake WordPress which post IDs are attachments.
     *
     * @since 1.0.0
     *
     * @param int[] $attachments The post IDs that are attachments.
     *
     * @return void
     */
    private function stub_attachments( array $attachments ) {
        Functions\when( 'absint' )->alias(
            function ( $value ) {
                return abs( (int) $value );
            }
        );

        Functions\when( 'get_post_type' )->alias(
            function ( $id ) use ( $attachments ) {
                return in_array( (int) $id, $attachments, true ) ? 'attachment' : 'page';
            }
        );
    }
}
