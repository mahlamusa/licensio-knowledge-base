<?php
/**
 * Unit tests for the inline SVG sanitizer.
 *
 * The sanitizer is exercised directly here, never through a rendered page. A
 * page has its own escaping in it, so a payload that reached the browser inert
 * would prove nothing about which layer made it inert — and a mutation to this
 * class would leave a page-level test green. Every assertion below is on the
 * literal return value of `sanitize()`.
 *
 * The assertions are exact rather than "does not contain `<script`", for the
 * same reason: an empty string does not contain `<script` either, so a
 * containment assertion passes just as happily when the sanitizer has thrown
 * the icon away as when it has kept it. Each payload test therefore states the
 * whole expected output, which fails both when too much survives and when too
 * little does.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

/**
 * Tests Licensio_KB_SVG_Sanitizer.
 *
 * @since 1.0.0
 */
class Licensio_KB_SVG_Sanitizer_Test extends Licensio_KB_Test_Case {

    /**
     * A real category icon, copied out of a Heroic Knowledge Base install.
     *
     * Not invented for the test: this is `hkb-icons/kb-icon-getting-started.svg`
     * from ht-knowledge-base, byte for byte, which is what Heroic's importer
     * writes into the `meta_svg` term meta this field has to receive. An icon
     * somebody actually ships exercises `<g>`, `<path>`, `<circle>`, `viewBox`,
     * classes, IDs and the stroke presentation attributes all at once.
     *
     * @since 1.0.0
     *
     * @return string The icon markup.
     */
    private function heroic_icon() {
        $path = LICENSIO_KB_ABSPATH . 'tests/fixtures/heroic-kb-icon-getting-started.svg';

        return trim( (string) file_get_contents( $path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
    }

    /**
     * Drop the SVG namespace declaration from markup.
     *
     * libxml re-emits `xmlns` as the first attribute of the root wherever it
     * appeared in the source, which is the one difference between a preserved
     * icon and its input. Removing it from both sides lets the preservation
     * test compare against the input itself rather than against a recorded copy
     * of the sanitizer's own output, which would agree with the implementation
     * even when the implementation is wrong.
     *
     * @since 1.0.0
     *
     * @param string $markup The markup to normalise.
     *
     * @return string The markup without the SVG namespace declaration.
     */
    private function without_namespace( $markup ) {
        return str_replace( ' xmlns="http://www.w3.org/2000/svg"', '', $markup );
    }

    /**
     * A real Heroic icon crosses the sanitizer unchanged.
     *
     * Compared against the input, not against an expected value derived from
     * the sanitizer: every element, attribute and path command has to still be
     * there, in order, or this fails.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_real_heroic_icon_survives_intact() {
        $icon = $this->heroic_icon();

        $this->assertStringStartsWith( '<svg', $icon, 'The fixture is not an SVG.' );

        $sanitized = Licensio_KB_SVG_Sanitizer::sanitize( $icon );

        $this->assertSame( $this->without_namespace( $icon ), $this->without_namespace( $sanitized ) );
    }

    /**
     * A nested script element is removed and its siblings are not.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_script_element_is_stripped() {
        $payload = '<svg viewBox="0 0 24 24"><script>alert(document.cookie)</script><path d="M4 4h16"/></svg>';

        $this->assertSame(
            '<svg viewBox="0 0 24 24"><path d="M4 4h16"/></svg>',
            Licensio_KB_SVG_Sanitizer::sanitize( $payload )
        );
    }

    /**
     * A script element takes its text content with it.
     *
     * Removing the element but leaving the text would put `alert(1)` on the
     * page as visible content, and — inside SVG, which an HTML parser treats as
     * foreign content — is one nesting mistake away from being live again.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_script_element_takes_its_contents_with_it() {
        $payload = '<svg viewBox="0 0 24 24"><g><script>alert(1)</script></g><path d="M4 4h16"/></svg>';

        $sanitized = Licensio_KB_SVG_Sanitizer::sanitize( $payload );

        $this->assertSame( '<svg viewBox="0 0 24 24"><g/><path d="M4 4h16"/></svg>', $sanitized );
        $this->assertStringNotContainsString( 'alert', $sanitized );
    }

    /**
     * Event handler attributes are removed wherever they appear.
     *
     * `on*` is not enumerated anywhere in the sanitizer. It is stripped because
     * the attribute allowlist does not name it, which is the property this test
     * is really pinning: a handler nobody has thought of yet fails for the same
     * reason `onload` does.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_event_handler_attributes_are_stripped() {
        $payload = '<svg viewBox="0 0 24 24" onload="alert(1)"><path d="M4 4h16" onclick="alert(2)" onmouseover="alert(3)"/><circle cx="1" cy="1" r="1" ONLOAD="alert(4)"/></svg>';

        $this->assertSame(
            '<svg viewBox="0 0 24 24"><path d="M4 4h16"/><circle cx="1" cy="1" r="1"/></svg>',
            Licensio_KB_SVG_Sanitizer::sanitize( $payload )
        );
    }

    /**
     * A javascript: reference is removed and the element it was on survives.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_javascript_href_is_stripped() {
        $payload = '<svg viewBox="0 0 24 24" xmlns:xlink="http://www.w3.org/1999/xlink"><use href="javascript:alert(1)" x="1"/><use xlink:href="javascript:alert(2)" y="2"/></svg>';

        $this->assertSame(
            '<svg xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 24 24"><use x="1"/><use y="2"/></svg>',
            Licensio_KB_SVG_Sanitizer::sanitize( $payload )
        );
    }

    /**
     * Every reference that is not a same-document fragment is removed.
     *
     * @since 1.0.0
     *
     * @dataProvider reference_provider
     *
     * @param string $href Whatever is put in the `href`.
     *
     * @return void
     */
    public function test_only_a_fragment_reference_is_kept( $href ) {
        $payload = '<svg viewBox="0 0 24 24"><use href="' . $href . '" x="1"/></svg>';

        $this->assertSame(
            '<svg viewBox="0 0 24 24"><use x="1"/></svg>',
            Licensio_KB_SVG_Sanitizer::sanitize( $payload )
        );
    }

    /**
     * References that must not survive an `href`.
     *
     * @since 1.0.0
     *
     * @return array<string, array{0: string}> Test cases.
     */
    public function reference_provider() {
        return array(
            'a javascript URL'        => array( 'javascript:alert(1)' ),
            'a cased javascript URL'  => array( 'JaVaScRiPt:alert(1)' ),
            'a padded javascript URL' => array( ' javascript:alert(1)' ),
            'a data URL'              => array( 'data:image/svg+xml,%3Csvg%3E' ),
            'an absolute URL'         => array( 'https://example.test/x.svg#a' ),
            'a protocol relative URL' => array( '//example.test/x.svg' ),
            'a same origin path'      => array( '/wp-content/uploads/x.svg#a' ),
            'a file with a fragment'  => array( 'x.svg#a' ),
        );
    }

    /**
     * A same-document fragment reference is kept.
     *
     * Without this the "strip anything that is not a fragment" tests would also
     * pass on a sanitizer that removed every reference, which is not the rule.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_fragment_reference_is_kept() {
        $payload = '<svg viewBox="0 0 24 24" xmlns:xlink="http://www.w3.org/1999/xlink"><defs><path id="a" d="M4 4h16"/></defs><use href="#a"/><use xlink:href="#a"/></svg>';

        $this->assertSame(
            '<svg xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 24 24"><defs><path id="a" d="M4 4h16"/></defs><use href="#a"/><use xlink:href="#a"/></svg>',
            Licensio_KB_SVG_Sanitizer::sanitize( $payload )
        );
    }

    /**
     * foreignObject is removed with the HTML inside it.
     *
     * It is the way arbitrary HTML — and therefore an `onerror` on an `img` —
     * gets into an SVG document without an element named `script` anywhere.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_foreign_object_is_stripped() {
        $payload = '<svg viewBox="0 0 24 24"><foreignObject width="10" height="10"><img src="x" onerror="alert(1)"/></foreignObject><path d="M4 4h16"/></svg>';

        $sanitized = Licensio_KB_SVG_Sanitizer::sanitize( $payload );

        $this->assertSame( '<svg viewBox="0 0 24 24"><path d="M4 4h16"/></svg>', $sanitized );
        $this->assertStringNotContainsString( 'onerror', $sanitized );
    }

    /**
     * Animation elements are removed.
     *
     * `<animate attributeName="href" to="javascript:…">` rewrites an attribute
     * after the sanitizer has finished looking at it, so no amount of checking
     * attribute values makes an animation element safe to keep.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_animation_elements_are_stripped() {
        $payload = '<svg viewBox="0 0 24 24"><use href="#a"><animate attributeName="href" to="javascript:alert(1)"/><set attributeName="href" to="javascript:alert(2)"/></use></svg>';

        $this->assertSame(
            '<svg viewBox="0 0 24 24"><use href="#a"/></svg>',
            Licensio_KB_SVG_Sanitizer::sanitize( $payload )
        );
    }

    /**
     * A style attribute is removed, and so is a style element.
     *
     * Keeping either would put a CSS parser between a stored value and the
     * browser, and CSS reaches URLs.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_style_is_stripped() {
        $payload = '<svg viewBox="0 0 24 24"><style>*{background:url(https://example.test/x)}</style><path d="M4 4h16" style="background-image:url(https://example.test/x)"/></svg>';

        $sanitized = Licensio_KB_SVG_Sanitizer::sanitize( $payload );

        $this->assertSame( '<svg viewBox="0 0 24 24"><path d="M4 4h16"/></svg>', $sanitized );
        $this->assertStringNotContainsString( 'example.test', $sanitized );
    }

    /**
     * A url() value must point at a fragment too.
     *
     * `fill`, `clip-path` and `mask` are allowlisted names whose values can
     * still reference a document, so the reference rule is applied to them and
     * not only to `href`.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_url_value_must_be_a_fragment() {
        $payload = '<svg viewBox="0 0 24 24"><path d="M4 4h16" fill="url(#gradient)" clip-path="url(https://example.test/x#c)" mask="url(data:text/html,x)"/></svg>';

        $this->assertSame(
            '<svg viewBox="0 0 24 24"><path d="M4 4h16" fill="url(#gradient)"/></svg>',
            Licensio_KB_SVG_Sanitizer::sanitize( $payload )
        );
    }

    /**
     * Elements outside the allowlist are removed with their subtrees.
     *
     * None of these can run script on their own. They are refused because the
     * allowlist does not name them, which is the point — an icon has no use for
     * an element that fetches a URL, and the field's safety does not depend on
     * anyone having worked out in advance which of them is exploitable.
     *
     * @since 1.0.0
     *
     * @dataProvider unlisted_element_provider
     *
     * @param string $element The markup that must not survive.
     *
     * @return void
     */
    public function test_unlisted_elements_are_removed( $element ) {
        $payload = '<svg viewBox="0 0 24 24">' . $element . '<path d="M4 4h16"/></svg>';

        $this->assertSame(
            '<svg viewBox="0 0 24 24"><path d="M4 4h16"/></svg>',
            Licensio_KB_SVG_Sanitizer::sanitize( $payload )
        );
    }

    /**
     * Elements a stored icon may not contain.
     *
     * @since 1.0.0
     *
     * @return array<string, array{0: string}> Test cases.
     */
    public function unlisted_element_provider() {
        return array(
            'an image'             => array( '<image href="https://example.test/x.png" width="10" height="10"/>' ),
            'a pattern'            => array( '<pattern id="p"><image href="https://example.test/x.png"/></pattern>' ),
            'a link'               => array( '<a href="https://example.test/">x</a>' ),
            'a filter'             => array( '<filter id="f"><feImage href="https://example.test/x.png"/></filter>' ),
            'a capitalised script' => array( '<SCRIPT>alert(1)</SCRIPT>' ),
            'a mixed case script'  => array( '<ScRiPt>alert(1)</ScRiPt>' ),
            'a handler element'    => array( '<handler type="text/javascript">alert(1)</handler>' ),
            'an HTML iframe'       => array( '<iframe src="https://example.test/"></iframe>' ),
        );
    }

    /**
     * A CDATA section is removed rather than re-serialised.
     *
     * An HTML parser honours CDATA inside foreign content, so a CDATA section
     * carried through to the page is a way of writing `</svg><script>` that the
     * XML parser never sees as markup at all.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_cdata_is_removed() {
        $payload = '<svg viewBox="0 0 24 24"><title><![CDATA[</svg><script>alert(1)</script>]]></title><path d="M4 4h16"/></svg>';

        $sanitized = Licensio_KB_SVG_Sanitizer::sanitize( $payload );

        $this->assertSame( '<svg viewBox="0 0 24 24"><title/><path d="M4 4h16"/></svg>', $sanitized );
        $this->assertStringNotContainsString( 'CDATA', $sanitized );
    }

    /**
     * A comment is removed.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_comments_are_removed() {
        $payload = '<svg viewBox="0 0 24 24"><!-- --><path d="M4 4h16"/></svg>';

        $this->assertSame(
            '<svg viewBox="0 0 24 24"><path d="M4 4h16"/></svg>',
            Licensio_KB_SVG_Sanitizer::sanitize( $payload )
        );
    }

    /**
     * Anything carrying a DTD is refused whole.
     *
     * A doctype is how both an entity expansion bomb and an external entity
     * arrive, and it is refused before the parser is handed the string rather
     * than filtered afterwards.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_doctype_is_refused() {
        $bomb = '<!DOCTYPE svg [<!ENTITY a "aaaaaaaaaa"><!ENTITY b "&a;&a;&a;&a;&a;">]><svg viewBox="0 0 24 24"><title>&b;</title></svg>';

        $this->assertSame( '', Licensio_KB_SVG_Sanitizer::sanitize( $bomb ) );

        $external = '<!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg viewBox="0 0 24 24"><title>&x;</title></svg>';

        $this->assertSame( '', Licensio_KB_SVG_Sanitizer::sanitize( $external ) );
    }

    /**
     * Anything that is not a single well-formed SVG element yields nothing.
     *
     * An empty string rather than a partial result, so no caller has to tell
     * "nothing stored" apart from "something rejected".
     *
     * @since 1.0.0
     *
     * @dataProvider rejected_provider
     *
     * @param mixed $input The raw value.
     *
     * @return void
     */
    public function test_input_that_is_not_an_svg_yields_nothing( $input ) {
        $this->assertSame( '', Licensio_KB_SVG_Sanitizer::sanitize( $input ) );
    }

    /**
     * Values that must sanitize to an empty string.
     *
     * @since 1.0.0
     *
     * @return array<string, array{0: mixed}> Test cases.
     */
    public function rejected_provider() {
        return array(
            'an empty string'        => array( '' ),
            'whitespace'             => array( "  \n " ),
            'plain text'             => array( 'Getting started' ),
            'HTML'                   => array( '<div onclick="alert(1)">x</div>' ),
            'a bare script'          => array( '<script>alert(1)</script>' ),
            'an unclosed element'    => array( '<svg viewBox="0 0 24 24"><path d="M4 4h16"></svg>' ),
            'markup before the root' => array( '<div></div><svg viewBox="0 0 24 24"/>' ),
            'an array'               => array( array( '<svg/>' ) ),
            'null'                   => array( null ),
            'a boolean'              => array( true ),
        );
    }

    /**
     * Markup longer than the cap is refused without being parsed.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_oversized_markup_is_refused() {
        $long = '<svg viewBox="0 0 24 24"><path d="' . str_repeat( 'M4 4h16', 20000 ) . '"/></svg>';

        $this->assertGreaterThan( Licensio_KB_SVG_Sanitizer::MAX_LENGTH, strlen( $long ) );
        $this->assertSame( '', Licensio_KB_SVG_Sanitizer::sanitize( $long ) );
    }

    /**
     * An icon drawn with currentColor keeps saying so.
     *
     * This is the colour story in place of a colour field: the card sets
     * `color` in CSS and the icon inherits it, which only works if `fill` and
     * `stroke` survive sanitisation with that value intact.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_current_color_survives() {
        $payload = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16" fill="currentColor"/></svg>';

        $this->assertSame( $payload, Licensio_KB_SVG_Sanitizer::sanitize( $payload ) );
    }

    /**
     * The allowlist itself names nothing that can execute.
     *
     * Asserted against the constants rather than against behaviour, because the
     * way this field regresses is not a broken walk over the tree — it is
     * somebody widening the list to make an icon work. `on*` is the one that
     * matters most: nothing in the sanitizer looks for event handlers, so the
     * only thing keeping them out is their absence from here.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_allowlist_names_nothing_executable() {
        foreach ( array( 'script', 'foreignObject', 'style', 'a', 'image', 'iframe', 'animate', 'set', 'handler' ) as $element ) {
            $this->assertNotContains( $element, Licensio_KB_SVG_Sanitizer::ELEMENTS, $element . ' must not be allowlisted.' );
        }

        $attributes = Licensio_KB_SVG_Sanitizer::GLOBAL_ATTRIBUTES;

        foreach ( Licensio_KB_SVG_Sanitizer::ELEMENT_ATTRIBUTES as $element => $names ) {
            $this->assertContains( $element, Licensio_KB_SVG_Sanitizer::ELEMENTS, $element . ' has attributes but is not an allowed element.' );

            $attributes = array_merge( $attributes, $names );
        }

        foreach ( $attributes as $attribute ) {
            $this->assertStringStartsNotWith( 'on', $attribute, $attribute . ' looks like an event handler.' );
            $this->assertNotSame( 'style', $attribute, 'style must not be allowlisted.' );
        }

        $this->assertContains( 'href', Licensio_KB_SVG_Sanitizer::REFERENCE_ATTRIBUTES );
        $this->assertContains( 'xlink:href', Licensio_KB_SVG_Sanitizer::REFERENCE_ATTRIBUTES );
    }
}
