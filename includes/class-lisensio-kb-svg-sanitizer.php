<?php
/**
 * Inline SVG sanitizer.
 *
 * Reduces arbitrary markup to the small, closed subset of SVG a category icon
 * actually needs. The subset is expressed as an allowlist of element names and,
 * per element, of attribute names: anything not named in ELEMENTS is removed
 * with its whole subtree, and anything not named in GLOBAL_ATTRIBUTES or in
 * that element's ELEMENT_ATTRIBUTES entry is removed from the element.
 *
 * That direction matters. A blocklist has to enumerate every dangerous name,
 * and the list of ways to run script inside SVG is long and still growing —
 * `<script>`, `<foreignObject>` with an HTML `<img onerror>`, `<animate
 * attributeName="href" to="javascript:…">`, `<set>`, `<handler>`, every `on*`
 * attribute, `href="javascript:…"`, `style` with a `url()` payload. None of
 * those appear below, and none of them need to: an allowlist rejects the
 * vectors nobody has published yet for the same reason it rejects the ones
 * that have been. `wp_kses_post()` is not usable here either — its allowlist is
 * the one for post content, which permits `<a href>` and no SVG geometry at
 * all, so it would strip a legitimate icon to nothing while still passing
 * markup this field has no business storing.
 *
 * The output of `sanitize()` is safe to print into a page without escaping,
 * and it has to be printed that way: escaping it would turn an icon into
 * visible angle brackets. That is the whole contract of this class, and the one
 * place it is relied upon is documented at the point of use.
 *
 * Consumed by: #30.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reduces inline SVG markup to an allowlisted subset.
 *
 * @since 1.0.0
 */
class Lisensio_KB_SVG_Sanitizer {

    /**
     * The SVG namespace, the only one a stored icon may declare.
     *
     * @since 1.0.0
     * @var string
     */
    const NAMESPACE_SVG = 'http://www.w3.org/2000/svg';

    /**
     * Longest input the sanitizer will parse, in bytes.
     *
     * A category icon is a couple of kilobytes. The cap bounds what an XML
     * parser is asked to chew on when the value arrives from somewhere other
     * than the form — an importer, the REST API, a hand-edited row.
     *
     * @since 1.0.0
     * @var int
     */
    const MAX_LENGTH = 65536;

    /**
     * Element names that may appear in a stored icon.
     *
     * Case sensitive, because SVG element names are: `clipPath` is an element
     * and `clippath` is not one. The comparison is exact, so a capitalised
     * `<SCRIPT>` fails to match for the same reason `<script>` does.
     *
     * Deliberately absent, beyond `script` and `foreignObject`: `image` and
     * `pattern`, which can fetch an external URL; `filter` and its primitives,
     * which are a large surface for no icon-shaped benefit; `a`, which is a
     * link inside a card that is already a link; `style`, which would reopen
     * everything the attribute allowlist closes; and every animation element,
     * because `<animate attributeName="href">` rewrites an attribute after this
     * code has finished inspecting it.
     *
     * @since 1.0.0
     * @var string[]
     */
    const ELEMENTS = array(
        'svg',
        'g',
        'defs',
        'symbol',
        'use',
        'title',
        'desc',
        'path',
        'rect',
        'circle',
        'ellipse',
        'line',
        'polyline',
        'polygon',
        'text',
        'tspan',
        'clipPath',
        'mask',
        'linearGradient',
        'radialGradient',
        'stop',
    );

    /**
     * Attribute names allowed on every allowed element.
     *
     * Presentation and identity only. No `style`, and therefore no CSS parser
     * between a stored value and a browser; no `on*`, which is what makes the
     * requirement to "strip every event handler" a consequence of the list
     * rather than a rule that has to be maintained beside it.
     *
     * @since 1.0.0
     * @var string[]
     */
    const GLOBAL_ATTRIBUTES = array(
        'id',
        'class',
        'fill',
        'fill-opacity',
        'fill-rule',
        'stroke',
        'stroke-width',
        'stroke-linecap',
        'stroke-linejoin',
        'stroke-dasharray',
        'stroke-dashoffset',
        'stroke-opacity',
        'stroke-miterlimit',
        'opacity',
        'color',
        'transform',
        'display',
        'visibility',
        'clip-path',
        'clip-rule',
        'mask',
        'vector-effect',
        'shape-rendering',
        'paint-order',
    );

    /**
     * Extra attribute names allowed on a particular element.
     *
     * Geometry, in other words: the values that describe the shape rather than
     * its paint. An element with no entry here is limited to GLOBAL_ATTRIBUTES.
     *
     * @since 1.0.0
     * @var array<string, string[]>
     */
    const ELEMENT_ATTRIBUTES = array(
        'svg'            => array( 'xmlns', 'xmlns:xlink', 'version', 'viewBox', 'width', 'height', 'x', 'y', 'preserveAspectRatio', 'overflow', 'role', 'aria-hidden', 'aria-label', 'aria-labelledby', 'focusable' ),
        'symbol'         => array( 'viewBox', 'preserveAspectRatio' ),
        'use'            => array( 'href', 'xlink:href', 'x', 'y', 'width', 'height' ),
        'path'           => array( 'd', 'pathLength' ),
        'rect'           => array( 'x', 'y', 'width', 'height', 'rx', 'ry' ),
        'circle'         => array( 'cx', 'cy', 'r' ),
        'ellipse'        => array( 'cx', 'cy', 'rx', 'ry' ),
        'line'           => array( 'x1', 'y1', 'x2', 'y2' ),
        'polyline'       => array( 'points' ),
        'polygon'        => array( 'points' ),
        'text'           => array( 'x', 'y', 'dx', 'dy', 'text-anchor', 'dominant-baseline', 'font-family', 'font-size', 'font-style', 'font-weight', 'letter-spacing' ),
        'tspan'          => array( 'x', 'y', 'dx', 'dy', 'text-anchor', 'dominant-baseline', 'font-family', 'font-size', 'font-style', 'font-weight', 'letter-spacing' ),
        'clipPath'       => array( 'clipPathUnits' ),
        'mask'           => array( 'maskUnits', 'maskContentUnits', 'x', 'y', 'width', 'height' ),
        'linearGradient' => array( 'x1', 'y1', 'x2', 'y2', 'gradientUnits', 'gradientTransform', 'spreadMethod', 'href', 'xlink:href' ),
        'radialGradient' => array( 'cx', 'cy', 'r', 'fx', 'fy', 'gradientUnits', 'gradientTransform', 'spreadMethod', 'href', 'xlink:href' ),
        'stop'           => array( 'offset', 'stop-color', 'stop-opacity' ),
    );

    /**
     * Attribute names whose value must be a same-document fragment.
     *
     * @since 1.0.0
     * @var string[]
     */
    const REFERENCE_ATTRIBUTES = array( 'href', 'xlink:href' );

    /**
     * Reduce markup to the allowlisted subset of SVG.
     *
     * Returns an empty string rather than a partial result whenever the input
     * is not a single well-formed `<svg>` element, so a caller never has to
     * distinguish "nothing stored" from "something rejected".
     *
     * @since 1.0.0
     *
     * @param mixed $value The raw markup.
     *
     * @return string The sanitized SVG, or an empty string when there is nothing safe to keep.
     */
    public static function sanitize( $value ) {
        if ( ! is_scalar( $value ) ) {
            return '';
        }

        $markup = trim( (string) $value );

        if ( '' === $markup || strlen( $markup ) > self::MAX_LENGTH ) {
            return '';
        }

        /*
         * Refused before the parser sees it, not filtered afterwards. A DTD is
         * how an entity expansion bomb and an external entity both arrive, and
         * an icon has never needed one, so the whole construct is out of scope
         * rather than something to be made safe.
         */
        if ( false !== stripos( $markup, '<!DOCTYPE' ) || false !== stripos( $markup, '<!ENTITY' ) ) {
            return '';
        }

        if ( ! class_exists( 'DOMDocument' ) ) {
            return '';
        }

        $document = self::parse( $markup );

        if ( ! $document instanceof DOMDocument ) {
            return '';
        }

        $root = $document->documentElement;

        if ( ! $root instanceof DOMElement || 'svg' !== $root->nodeName ) {
            return '';
        }

        if ( null !== $root->namespaceURI && self::NAMESPACE_SVG !== $root->namespaceURI ) {
            return '';
        }

        self::clean( $root );

        $sanitized = $document->saveXML( $root );

        return is_string( $sanitized ) ? $sanitized : '';
    }

    /**
     * Parse markup into a document, or return null when it is not well formed.
     *
     * Parsed as XML rather than HTML on purpose. The HTML parser lower-cases
     * every name, which would turn `viewBox` into `viewbox` and `clipPath` into
     * an unknown element, quietly breaking icons this class is supposed to
     * preserve. `LIBXML_NONET` is belt and braces next to the DTD refusal in
     * the caller.
     *
     * @since 1.0.0
     *
     * @param string $markup The raw markup.
     *
     * @return DOMDocument|null The parsed document, or null when parsing failed.
     */
    private static function parse( $markup ) {
        $previous = libxml_use_internal_errors( true );

        $document = new DOMDocument();
        $loaded   = $document->loadXML( $markup, LIBXML_NONET );

        libxml_clear_errors();
        libxml_use_internal_errors( $previous );

        return $loaded ? $document : null;
    }

    /**
     * Strip everything the allowlist does not name, depth first.
     *
     * Children are collected into a plain array before the walk: `childNodes`
     * is live, and removing from it while iterating it skips the node that
     * shifts into the freed index — which is exactly the node an attacker would
     * put a payload in.
     *
     * @since 1.0.0
     *
     * @param DOMElement $element An element already known to be allowed.
     *
     * @return void
     */
    private static function clean( DOMElement $element ) {
        self::clean_attributes( $element );

        $children = array();

        foreach ( $element->childNodes as $child ) {
            $children[] = $child;
        }

        foreach ( $children as $child ) {
            if ( $child instanceof DOMElement ) {
                if ( in_array( $child->nodeName, self::ELEMENTS, true ) ) {
                    self::clean( $child );
                } else {
                    $element->removeChild( $child );
                }

                continue;
            }

            /*
             * Text is kept — the serializer escapes it, so the worst it can be
             * is visible. Comments, processing instructions and CDATA are not:
             * inside foreign content an HTML parser honours a CDATA section,
             * which makes `<![CDATA[</svg><script>]]>` a way back out of the
             * subtree this walk just cleaned.
             */
            if ( ! $child instanceof DOMText || $child instanceof DOMCdataSection ) {
                $element->removeChild( $child );
            }
        }
    }

    /**
     * Remove every attribute the allowlist does not name for this element.
     *
     * @since 1.0.0
     *
     * @param DOMElement $element The element to clean.
     *
     * @return void
     */
    private static function clean_attributes( DOMElement $element ) {
        $extra   = isset( self::ELEMENT_ATTRIBUTES[ $element->nodeName ] )
            ? self::ELEMENT_ATTRIBUTES[ $element->nodeName ]
            : array();
        $allowed = array_merge( self::GLOBAL_ATTRIBUTES, $extra );

        $attributes = array();

        foreach ( $element->attributes as $attribute ) {
            if ( $attribute instanceof DOMAttr ) {
                $attributes[] = $attribute;
            }
        }

        foreach ( $attributes as $attribute ) {
            $name = $attribute->nodeName;

            if ( ! in_array( $name, $allowed, true ) || ! self::is_allowed_value( $name, $attribute->nodeValue ) ) {
                $element->removeAttributeNode( $attribute );
            }
        }
    }

    /**
     * Whether an allowlisted attribute is carrying an allowlisted value.
     *
     * Two value rules, both of them about references leaving the document.
     * `href` and `xlink:href` must be a bare `#fragment`, which rules out
     * `javascript:`, `data:` and every absolute URL in one comparison instead
     * of a list of schemes to keep up with. Any other value that reaches for
     * `url()` — `fill`, `clip-path`, `mask` — must reach for a fragment too.
     *
     * @since 1.0.0
     *
     * @param string      $name  The attribute name.
     * @param string|null $value The attribute value.
     *
     * @return bool True when the value may be kept.
     */
    private static function is_allowed_value( $name, $value ) {
        $value = null === $value ? '' : trim( (string) $value );

        if ( in_array( $name, self::REFERENCE_ATTRIBUTES, true ) ) {
            return (bool) preg_match( '/^#[A-Za-z0-9_][A-Za-z0-9_.-]*$/', $value );
        }

        if ( false === stripos( $value, 'url(' ) ) {
            return true;
        }

        return (bool) preg_match( '/^url\(\s*([\'"]?)#[A-Za-z0-9_][A-Za-z0-9_.-]*\1\s*\)$/i', $value );
    }
}
