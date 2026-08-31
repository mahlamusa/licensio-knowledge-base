<?php
/**
 * Unit tests for the category card template.
 *
 * Covers the icon precedence — inline SVG, then an uploaded attachment, then a
 * dashicon name — and the one place in the plugin that prints stored markup
 * without escaping it.
 *
 * The sanitizer itself is not under test here; it has its own file, asserted
 * against its own return value. What is under test is that the template reads
 * the icon through the accessor that sanitizes, so a row written straight into
 * the table by an importer is as safe as one written by the term form.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

use Brain\Monkey\Functions;

/**
 * Tests templates/category-card.php.
 *
 * @since 1.0.0
 */
class Lisensio_KB_Category_Card_Test extends Lisensio_KB_Test_Case {

    /**
     * The in-memory term meta table, keyed `term_id|meta_key`.
     *
     * @since 1.0.0
     * @var array<string, mixed>
     */
    private $meta = array();

    /**
     * Every term meta key the card read, in order.
     *
     * @since 1.0.0
     * @var string[]
     */
    private $reads = array();

    /**
     * Stub the WordPress surface the card touches.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        $this->meta  = array();
        $this->reads = array();

        Functions\when( 'absint' )->alias(
            function ( $value ) {
                return abs( (int) $value );
            }
        );

        Functions\when( 'get_post_type' )->justReturn( 'attachment' );
        Functions\when( 'get_term_link' )->justReturn( 'https://example.test/docs/category/getting-started/' );
        Functions\when( 'get_terms' )->justReturn( array() );
        Functions\when( 'number_format_i18n' )->returnArg();
        Functions\when( 'wp_kses_post' )->returnArg();
        Functions\when( 'wp_get_attachment_image' )->justReturn( '<img class="lisensio-kb-card__icon-image" src="https://example.test/icon.png" alt="" />' );

        Functions\when( 'get_term' )->alias(
            function ( $term ) {
                return $term instanceof WP_Term ? $term : null;
            }
        );

        Functions\when( 'get_term_meta' )->alias(
            function ( $term_id, $key, $single = false ) {
                unset( $single );

                $this->reads[] = $key;
                $index         = $term_id . '|' . $key;

                return array_key_exists( $index, $this->meta ) ? $this->meta[ $index ] : '';
            }
        );
    }

    /**
     * Render the card for a category with the icon turned on.
     *
     * The template is included directly rather than located through the theme
     * lookup: the lookup is a separate concern with its own tests, and going
     * through it would put a second layer between the assertion and the file
     * the assertion is about.
     *
     * @since 1.0.0
     *
     * @return string The rendered markup.
     */
    private function render_card() {
        $args = array(
            'term'          => new WP_Term(
                array(
                    'term_id'     => 9,
                    'name'        => 'Getting started',
                    'taxonomy'    => 'lisensio_category',
                    'count'       => 3,
                    'description' => '',
                )
            ),
            'show_icon'     => true,
            'show_children' => false,
        );

        ob_start();

        include LISENSIO_KB_ABSPATH . 'templates/category-card.php';

        return (string) ob_get_clean();
    }

    /**
     * A real Heroic icon reaches the page as markup, not as text.
     *
     * The whole point of the field: escaped output would put `&lt;svg` on the
     * card where the drawing should be.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_stored_icon_reaches_the_page_as_markup() {
        $icon = trim( (string) file_get_contents( LISENSIO_KB_ABSPATH . 'tests/fixtures/heroic-kb-icon-getting-started.svg' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

        $this->meta['9|lisensio_icon_svg'] = $icon;

        $markup = $this->render_card();

        $this->assertStringContainsString( 'lisensio-kb-card__icon--svg', $markup );
        $this->assertStringContainsString( '<svg', $markup );
        $this->assertStringContainsString( 'viewBox="0 0 230 230"', $markup );
        $this->assertStringContainsString( '<circle class="hkbiconsvg-stroke" cx="148.82" cy="80.45" r="21.79"/>', $markup );
        $this->assertStringNotContainsString( '&lt;svg', $markup );
    }

    /**
     * Markup stored outside the form does not reach the page.
     *
     * `update_term_meta()` is not the only way a row appears — an importer, a
     * WP-CLI call, a hand edit. The card prints its icon unescaped, so the
     * accessor has to sanitize on read as well as on write, and this is the
     * assertion that says so.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_markup_written_straight_into_the_table_is_sanitized_on_read() {
        $this->meta['9|lisensio_icon_svg'] = '<svg viewBox="0 0 24 24" onload="alert(1)"><script>alert(2)</script><path d="M4 4h16"/></svg>';

        $markup = $this->render_card();

        $this->assertStringContainsString( '<svg viewBox="0 0 24 24"><path d="M4 4h16"/></svg>', $markup );
        $this->assertStringNotContainsString( 'onload', $markup );
        $this->assertStringNotContainsString( 'alert', $markup );
    }

    /**
     * The inline SVG wins over an uploaded icon and over a dashicon name.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_inline_svg_takes_precedence() {
        $this->meta['9|lisensio_icon_svg'] = '<svg viewBox="0 0 24 24"><path d="M4 4h16"/></svg>';
        $this->meta['9|lisensio_icon_id']  = 42;
        $this->meta['9|lisensio_icon']     = 'dashicons-sos';

        $markup = $this->render_card();

        $this->assertStringContainsString( '<path d="M4 4h16"/>', $markup );
        $this->assertStringNotContainsString( 'lisensio-kb-card__icon-image', $markup );
        $this->assertStringNotContainsString( 'dashicons-sos', $markup );
    }

    /**
     * The card does not read the icon fields the winning one has already beaten.
     *
     * The branch chain below decides which icon is drawn, so a card that read
     * all three and then drew one would look identical — which is why this is
     * asserted on the reads rather than on the markup. It is three extra term
     * meta reads per card, and a category grid is a page of cards.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_beaten_icon_fields_are_never_read() {
        $this->meta['9|lisensio_icon_svg'] = '<svg viewBox="0 0 24 24"><path d="M4 4h16"/></svg>';
        $this->meta['9|lisensio_icon_id']  = 42;
        $this->meta['9|lisensio_icon']     = 'dashicons-sos';

        $this->render_card();

        $this->assertContains( 'lisensio_icon_svg', $this->reads );
        $this->assertNotContains( 'lisensio_icon_id', $this->reads );
        $this->assertNotContains( 'lisensio_icon', $this->reads );
    }

    /**
     * With no SVG stored, the uploaded icon still wins over the dashicon name.
     *
     * The new field must not have quietly reordered the two that were there
     * before it.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_uploaded_icon_still_beats_the_dashicon_name() {
        $this->meta['9|lisensio_icon_id'] = 42;
        $this->meta['9|lisensio_icon']    = 'dashicons-sos';

        $markup = $this->render_card();

        $this->assertStringContainsString( 'lisensio-kb-card__icon-image', $markup );
        $this->assertStringNotContainsString( 'dashicons-sos', $markup );
    }

    /**
     * An icon that fails sanitisation falls through to the next field.
     *
     * Rejecting the SVG must not leave the card with no icon at all when the
     * category also has a perfectly good dashicon name.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_rejected_svg_falls_through_to_the_dashicon_name() {
        $this->meta['9|lisensio_icon_svg'] = '<script>alert(1)</script>';
        $this->meta['9|lisensio_icon']     = 'dashicons-sos';

        $markup = $this->render_card();

        $this->assertStringContainsString( 'dashicons dashicons-sos', $markup );
        $this->assertStringNotContainsString( 'alert', $markup );
    }

    /**
     * No icon is drawn at all when the card was not asked for one.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_no_icon_is_read_when_the_card_does_not_show_one() {
        $this->meta['9|lisensio_icon_svg'] = '<svg viewBox="0 0 24 24"><path d="M4 4h16"/></svg>';

        $args = array(
            'term'          => new WP_Term(
                array(
                    'term_id'     => 9,
                    'name'        => 'Getting started',
                    'taxonomy'    => 'lisensio_category',
                    'count'       => 3,
                    'description' => '',
                )
            ),
            'show_children' => false,
        );

        ob_start();

        include LISENSIO_KB_ABSPATH . 'templates/category-card.php';

        $markup = (string) ob_get_clean();

        $this->assertStringNotContainsString( '<svg', $markup );
        $this->assertStringContainsString( 'Getting started', $markup );
    }
}
