<?php
/**
 * Unit tests for the category card template.
 *
 * Covers the icon precedence — inline SVG, then an uploaded attachment, then a
 * dashicon name — the one place in the plugin that prints stored markup without
 * escaping it, and the article list the card leads with.
 *
 * The sanitizer itself is not under test here; it has its own file, asserted
 * against its own return value. What is under test is that the template reads
 * the icon through the accessor that sanitizes, so a row written straight into
 * the table by an importer is as safe as one written by the term form.
 *
 * @package Lisensio_KB
 * @since   1.0.0 Added the article list coverage.
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
     * The articles get_posts() answers the card with.
     *
     * @since 1.0.0
     * @var WP_Post[]
     */
    private $articles = array();

    /**
     * The arguments of the last get_posts() call the card made.
     *
     * @since 1.0.0
     * @var array<string, mixed>
     */
    private $query_args = array();

    /**
     * Stub the WordPress surface the card touches.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        $this->meta       = array();
        $this->reads      = array();
        $this->articles   = array();
        $this->query_args = array();

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

        Functions\when( 'get_posts' )->alias(
            function ( $args = array() ) {
                $this->query_args = (array) $args;

                return array_slice(
                    $this->articles,
                    0,
                    isset( $args['posts_per_page'] ) ? (int) $args['posts_per_page'] : count( $this->articles )
                );
            }
        );

        Functions\when( 'get_permalink' )->alias(
            function ( $post ) {
                return $post instanceof WP_Post
                    ? 'https://example.test/docs/article-' . $post->ID . '/'
                    : '';
            }
        );

        Functions\when( 'get_the_title' )->alias(
            function ( $post ) {
                return $post instanceof WP_Post ? $post->post_title : '';
            }
        );

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
     * Build an article the stubbed query can answer with.
     *
     * @since 1.0.0
     *
     * @param int    $id    The post ID.
     * @param string $title The article title.
     *
     * @return WP_Post The article.
     */
    private function article( $id, $title ) {
        return new WP_Post(
            array(
                'ID'         => $id,
                'post_title' => $title,
                'post_type'  => 'lisensio_article',
            )
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
     * @param array<string, mixed> $overrides Template arguments to add or replace.
     *
     * @return string The rendered markup.
     */
    private function render_card( array $overrides = array() ) {
        $args = array_merge(
            array(
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
            ),
            $overrides
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

    /**
     * The card lists the articles in the category, each as a link.
     *
     * This is the whole point of the redesign: a card that names a category and
     * counts it tells a reader nothing they can act on, so the titles and their
     * permalinks are asserted rather than the presence of a list element.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_card_lists_the_articles_in_the_category() {
        $this->articles = array(
            $this->article( 11, 'Quick start: 4-step setup' ),
            $this->article( 12, 'Installing the plugin' ),
        );

        $markup = $this->render_card();

        $this->assertStringContainsString( '<ul class="lisensio-kb-card__articles">', $markup );
        $this->assertStringContainsString(
            '<a class="lisensio-kb-card__article-link" href="https://example.test/docs/article-11/">',
            $markup
        );
        $this->assertStringContainsString( 'Quick start: 4-step setup', $markup );
        $this->assertStringContainsString(
            '<a class="lisensio-kb-card__article-link" href="https://example.test/docs/article-12/">',
            $markup
        );
        $this->assertStringContainsString( 'Installing the plugin', $markup );
    }

    /**
     * An article title reaches the card escaped.
     *
     * The titles are printed inside a link the card builds itself, so the
     * escaping is this template's responsibility and not `get_the_title()`'s.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_article_title_reaches_the_card_escaped() {
        $this->articles = array( $this->article( 11, '<script>alert(1)</script>' ) );

        $markup = $this->render_card();

        $this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;', $markup );
        $this->assertStringNotContainsString( '<script>', $markup );
    }

    /**
     * The list is capped, and the cap is asked of the query rather than of PHP.
     *
     * A card that fetched every article in a category and then printed three of
     * them would look identical, so the assertion is on the query the card made.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_article_list_is_capped_by_the_query() {
        $this->articles = array(
            $this->article( 11, 'One' ),
            $this->article( 12, 'Two' ),
            $this->article( 13, 'Three' ),
            $this->article( 14, 'Four' ),
        );

        $markup = $this->render_card();

        $this->assertSame( 3, $this->query_args['posts_per_page'] );
        $this->assertStringContainsString( 'Three', $markup );
        $this->assertStringNotContainsString( 'Four', $markup );

        $markup = $this->render_card( array( 'article_limit' => 2 ) );

        $this->assertSame( 2, $this->query_args['posts_per_page'] );
        $this->assertStringContainsString( 'Two', $markup );
        $this->assertStringNotContainsString( 'Three', $markup );
    }

    /**
     * A cap of nothing is answered with nothing, and without a query.
     *
     * `posts_per_page` reads a non-positive number as "no limit", so a card
     * asked for no articles would otherwise fetch every article in the
     * category and print the lot.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_zero_article_cap_queries_nothing() {
        $this->articles = array( $this->article( 11, 'One' ) );

        $markup = $this->render_card( array( 'article_limit' => 0 ) );

        $this->assertSame( array(), $this->query_args );
        $this->assertStringNotContainsString( 'lisensio-kb-card__articles', $markup );
    }

    /**
     * The list asks for published articles in this category and its children.
     *
     * A parent category that only groups its children reports a count in the
     * dozens; without `include_children` its card would list nothing at all.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_article_query_is_scoped_to_the_category_and_its_children() {
        $this->articles = array( $this->article( 11, 'One' ) );

        $this->render_card();

        $this->assertSame( 'lisensio_article', $this->query_args['post_type'] );
        $this->assertSame( 'publish', $this->query_args['post_status'] );
        $this->assertSame(
            array(
                'taxonomy'         => 'lisensio_category',
                'field'            => 'term_id',
                'terms'            => 9,
                'include_children' => true,
            ),
            $this->query_args['tax_query'][0]
        );
    }

    /**
     * A card told not to list articles does not query for them at all.
     *
     * A category grid is a page of cards, so a query per card that nothing
     * prints is a page of wasted queries.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_card_that_hides_the_articles_never_queries_for_them() {
        $this->articles = array( $this->article( 11, 'One' ) );

        $markup = $this->render_card( array( 'show_articles' => false ) );

        $this->assertSame( array(), $this->query_args );
        $this->assertStringContainsString( 'Getting started', $markup );
        $this->assertStringNotContainsString( 'lisensio-kb-card__articles', $markup );
    }

    /**
     * A category with no articles gets no empty list element.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_category_with_no_articles_renders_no_list() {
        $markup = $this->render_card();

        $this->assertStringContainsString( 'Getting started', $markup );
        $this->assertStringNotContainsString( '<ul class="lisensio-kb-card__articles">', $markup );
    }

    /**
     * The arrow is drawn by the stylesheet, on the class the card emits.
     *
     * The arrow is generated content, so no assertion on the markup can see it.
     * What can be held to account is that the selector the rule is written for
     * is the class the template actually prints — renaming one without the
     * other is the way this breaks.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_stylesheet_draws_an_arrow_on_the_class_the_card_emits() {
        $this->articles = array( $this->article( 11, 'One' ) );

        $markup = $this->render_card();
        $css    = (string) file_get_contents( LISENSIO_KB_ABSPATH . 'assets/css/lisensio-kb.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

        $this->assertStringContainsString( 'class="lisensio-kb-card__article-link"', $markup );
        $this->assertStringContainsString( '.lisensio-kb .lisensio-kb-card__article-link::before', $css );
        $this->assertStringContainsString( 'content: "\2192";', $css );
    }

    /**
     * The article count survives the redesign, as secondary meta.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_article_count_is_still_rendered() {
        $this->articles = array( $this->article( 11, 'One' ) );

        $markup = $this->render_card();

        $this->assertStringContainsString( '<p class="lisensio-kb-card__meta">', $markup );
        $this->assertStringContainsString( '3 articles', $markup );
    }
    /**
     * The icon sits beside the category name, not above it.
     *
     * A layout contract, so it is asserted structurally: the icon and the title
     * must be siblings inside one heading row. Checking only that both appear
     * on the card would pass just as happily with the icon stacked above the
     * name again, which is the arrangement this replaced.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_icon_shares_a_row_with_the_category_name() {
        $this->meta['9|lisensio_icon_svg'] = '<svg viewBox="0 0 24 24"><path d="M4 4h16"/></svg>';

        $markup = $this->render_card();

        $heading = strpos( $markup, '<div class="lisensio-kb-card__heading">' );
        $icon    = strpos( $markup, 'lisensio-kb-card__icon' );
        $title   = strpos( $markup, 'lisensio-kb-card__title' );
        $close   = strpos( $markup, '</div>', $title );

        $this->assertNotFalse( $heading, 'the card renders no heading row' );
        $this->assertNotFalse( $icon, 'the card renders no icon' );
        $this->assertLessThan( $icon, $heading, 'the icon is outside the heading row' );
        $this->assertLessThan( $title, $icon, 'the icon does not precede the name' );
        $this->assertNotFalse( $close, 'the heading row is never closed' );
    }
}
