<?php
/**
 * Unit tests for the result snippet builder.
 *
 * The snippet is the one field of the search response that reaches the browser
 * as HTML rather than as text — the type-ahead assigns it with `innerHTML` so
 * the highlight renders. That makes the escaping here the boundary between an
 * article author and stored XSS on a public endpoint, so `esc_html()` is stubbed
 * with a REAL implementation for these tests. Brain Monkey's escape stubs return
 * their input unchanged, which would make every assertion below pass whether or
 * not anything was escaped at all.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

use Brain\Monkey\Functions;

/**
 * Tests snippet extraction, highlighting and escaping.
 *
 * @since 1.0.0
 */
class Lisensio_KB_Search_Snippet_Test extends Lisensio_KB_Test_Case {

    /**
     * Replace the pass-through escape stub with a real one.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        Functions\when( 'esc_html' )->alias(
            function ( $value ) {
                return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
            }
        );
    }

    /**
     * The matched word is wrapped in a mark element.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_match_is_highlighted() {
        $snippet = Lisensio_KB_Search_Snippet::build( 'Read the licence terms.', array( 'licence' ) );

        $this->assertStringContainsString( '<mark>licence</mark>', $snippet );
    }

    /**
     * Highlighting is case-insensitive and keeps the original casing.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_highlighting_preserves_the_original_case() {
        $snippet = Lisensio_KB_Search_Snippet::build( 'The Licence file.', array( 'licence' ) );

        $this->assertStringContainsString( '<mark>Licence</mark>', $snippet );
    }

    /**
     * Every query token is highlighted, not only the first.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_every_token_is_highlighted() {
        $snippet = Lisensio_KB_Search_Snippet::build( 'The licence server is running.', array( 'licence', 'server' ) );

        $this->assertStringContainsString( '<mark>licence</mark>', $snippet );
        $this->assertStringContainsString( '<mark>server</mark>', $snippet );
    }

    /**
     * Markup in the source text is escaped, and only mark tags survive.
     *
     * This is the assertion that matters. The snippet is written into the page
     * with `innerHTML`, so a script tag that reached it unescaped would run.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_source_markup_is_escaped() {
        $snippet = Lisensio_KB_Search_Snippet::build(
            'Before <script>alert("licence")</script> after.',
            array( 'licence' )
        );

        $this->assertStringNotContainsString( '<script>', $snippet );
        $this->assertStringContainsString( '&lt;script&gt;', $snippet );
        $this->assertStringContainsString( '<mark>licence</mark>', $snippet );

        // The only tags in the output are the highlight tags this class adds.
        $this->assertSame(
            array(),
            array_values(
                array_diff(
                    $this->tags_in( $snippet ),
                    array( 'mark', '/mark' )
                )
            )
        );
    }

    /**
     * An image with an event handler cannot smuggle itself through.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_event_handler_attribute_is_escaped() {
        $snippet = Lisensio_KB_Search_Snippet::build(
            '<img src=x onerror=alert(1)> licence',
            array( 'licence' )
        );

        $this->assertStringNotContainsString( '<img', $snippet );
        $this->assertStringContainsString( '&lt;img', $snippet );
    }

    /**
     * A token that becomes an entity when escaped still highlights.
     *
     * The tokens are escaped the same way the text is before the pattern is
     * built. Without that, a token containing `&` would be searched for in text
     * where it had already become `&amp;`, and would silently never match.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_token_containing_an_entity_character_highlights() {
        $snippet = Lisensio_KB_Search_Snippet::build( 'Terms & conditions apply.', array( '&' ) );

        $this->assertStringContainsString( '<mark>&amp;</mark>', $snippet );
    }

    /**
     * A long body is cut to a window around the first match.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_long_body_is_windowed_around_the_match() {
        $text = str_repeat( 'padding words here. ', 40 ) . 'the licence clause. ' . str_repeat( 'trailing words. ', 40 );

        $snippet = Lisensio_KB_Search_Snippet::build( $text, array( 'licence' ), 120 );

        $this->assertStringContainsString( '<mark>licence</mark>', $snippet );
        $this->assertStringContainsString( '&hellip;', $snippet );
        $this->assertLessThan( strlen( $text ), strlen( $snippet ) );
    }

    /**
     * Short text is returned whole, with no ellipsis.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_short_text_is_not_truncated() {
        $snippet = Lisensio_KB_Search_Snippet::build( 'A licence.', array( 'licence' ) );

        $this->assertStringNotContainsString( '&hellip;', $snippet );
        $this->assertSame( 'A <mark>licence</mark>.', $snippet );
    }

    /**
     * Text with no match still produces a readable opening extract.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_text_without_a_match_still_returns_an_extract() {
        $snippet = Lisensio_KB_Search_Snippet::build( 'Nothing relevant in here at all.', array( 'licence' ) );

        $this->assertSame( 'Nothing relevant in here at all.', $snippet );
        $this->assertStringNotContainsString( '<mark>', $snippet );
    }

    /**
     * Empty text produces an empty snippet rather than an ellipsis.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_empty_text_produces_an_empty_snippet() {
        $this->assertSame( '', Lisensio_KB_Search_Snippet::build( '   ', array( 'licence' ) ) );
    }

    /**
     * The tag names appearing in a fragment of HTML.
     *
     * @since 1.0.0
     *
     * @param string $html The fragment.
     *
     * @return string[] The tag names, including closing tags with their slash.
     */
    private function tags_in( $html ) {
        $matches = array();

        preg_match_all( '#<(/?[a-zA-Z][a-zA-Z0-9]*)#', $html, $matches );

        return $matches[1];
    }
}
