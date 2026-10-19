<?php
/**
 * Article table of contents.
 *
 * Two jobs, and the first one is why this is a class rather than a template.
 * A table of contents is a list of links to `#fragment`s, and article headings
 * carry no `id` at all — nothing in WordPress adds one — so there is nothing
 * for a template to link to. This filters the rendered article, gives every
 * `h2` and `h3` a stable `id` and remembers the headings it found; the sidebar
 * template then renders the list from what was remembered.
 *
 * The content is parsed with `DOMDocument`, not matched with a regular
 * expression. A regex that finds `<h2>` also finds the one an author wrote
 * inside a `<pre>` block, the one quoted inside an HTML comment, and the one
 * mentioned inside an attribute value, and rewriting any of those corrupts the
 * article. A parser knows the difference, and it knows it for the cases nobody
 * thought to write a test for. The cost is a round trip through libxml, which
 * is measured and documented on `process()`.
 *
 * The ids are derived from the heading text, never from its position, so a
 * reader's bookmark to `#overview` still lands on Overview after an editor
 * adds three sections above it.
 *
 * Consumed by: #36.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Assigns heading ids to article content and collects the table of contents.
 *
 * @since 1.0.0
 */
class Licensio_KB_TOC {

    /**
     * Heading levels the table of contents is built from.
     *
     * `h2` is a section and `h3` is a sub-section; `h4` and below are detail
     * inside a section rather than a place a reader navigates to, and listing
     * them turns a table of contents into a second copy of the article.
     *
     * @since 1.0.0
     * @var int[]
     */
    const LEVELS = array( 2, 3 );

    /**
     * How many headings an article needs before a list is worth rendering.
     *
     * A one-item table of contents is a link to the top of the page wearing a
     * heading of its own.
     *
     * @since 1.0.0
     * @var int
     */
    const MIN_HEADINGS = 2;

    /**
     * Longest article the filter will parse, in bytes.
     *
     * Beyond this the content is handed back untouched rather than fed to an
     * XML parser. An article this long has other problems, and a table of
     * contents is not worth an unbounded parse on every page view.
     *
     * @since 1.0.0
     * @var int
     */
    const MAX_LENGTH = 2097152;

    /**
     * The slug used for a heading whose text sanitizes to nothing.
     *
     * @since 1.0.0
     * @var string
     */
    const FALLBACK_SLUG = 'section';

    /**
     * The priority the content filter runs at.
     *
     * After `do_shortcode()` at 11, so a heading a shortcode produced is
     * indexed too, and before `convert_smilies()` at 20.
     *
     * @since 1.0.0
     * @var int
     */
    const PRIORITY = 12;

    /**
     * Headings collected per article during this request.
     *
     * @since 1.0.0
     * @var array<int, array<int, array<string, mixed>>>
     */
    private static $collected = array();

    /**
     * Register the content filter.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function init_hooks() {
        add_filter( 'the_content', array( $this, 'filter_content' ), self::PRIORITY );
    }

    /**
     * Give the article's headings ids and remember them.
     *
     * Returns the content unchanged whenever this is not the one article the
     * request is about, so a related-articles loop, a REST response or another
     * post type is never rewritten.
     *
     * @since 1.0.0
     *
     * @param string $content The rendered post content.
     *
     * @return string The content, with an id on every heading that lacked one.
     */
    public function filter_content( $content ) {
        $content = (string) $content;

        if ( ! self::applies() ) {
            return $content;
        }

        $result = self::process( $content );

        self::$collected[ (int) get_the_ID() ] = $result['headings'];

        return $result['content'];
    }

    /**
     * Whether the current request is the single article the filter is for.
     *
     * Each condition closes one way the filter could reach content it has no
     * business rewriting: an admin screen or a feed, a REST response, a view
     * that is not a single article, a secondary loop nested inside one, and an
     * article rendered while the reader is looking at a different one.
     *
     * @since 1.0.0
     *
     * @return bool True when the content being filtered is the queried article.
     */
    public static function applies() {
        if ( is_admin() || is_feed() ) {
            return false;
        }

        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
            return false;
        }

        if ( ! is_singular( Licensio_KB_Post_Types::POST_TYPE ) || ! in_the_loop() ) {
            return false;
        }

        $post = get_post();

        if ( ! $post instanceof WP_Post || Licensio_KB_Post_Types::POST_TYPE !== $post->post_type ) {
            return false;
        }

        /*
         * The decisive check. `in_the_loop()` reads the global query, so a
         * secondary loop opened inside the main one still reports true; only
         * comparing the post being filtered with the post the URL asked for
         * tells the article apart from the excerpts rendered beside it.
         */
        if ( (int) $post->ID !== (int) get_queried_object_id() ) {
            return false;
        }

        return Licensio_KB_Sidebar::is_toc_enabled();
    }

    /**
     * The headings collected for an article during this request.
     *
     * @since 1.0.0
     *
     * @param int $post_id The article ID.
     *
     * @return array<int, array<string, mixed>> The headings, in document order.
     */
    public static function headings( $post_id ) {
        $post_id = (int) $post_id;

        return isset( self::$collected[ $post_id ] ) ? self::$collected[ $post_id ] : array();
    }

    /**
     * Forget every heading collected so far.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public static function reset() {
        self::$collected = array();
    }

    /**
     * Assign heading ids and collect them.
     *
     * Idempotent: an id this method assigned is an id an author set as far as
     * a second run is concerned, so running twice produces the same document
     * and the same list rather than suffixing every slug again.
     *
     * The round trip through libxml is not quite byte-for-byte. Character
     * references for characters that need none are resolved — `&nbsp;` comes
     * back as U+00A0 and `&#8212;` as an em dash — because the parser stores
     * text decoded and the serializer only re-escapes what HTML requires.
     * `&amp;`, `&lt;` and `&gt;` survive as themselves, void elements keep
     * their HTML spelling (`<br>`, not `<br />`), attribute quoting and
     * comments — WordPress block delimiters included — are preserved, and
     * content with no `h2` or `h3` in it never reaches the parser at all.
     *
     * @since 1.0.0
     *
     * @param string $html The rendered article content.
     *
     * @return array{content: string, headings: array<int, array<string, mixed>>} The content and its headings.
     */
    public static function process( $html ) {
        $html    = (string) $html;
        $nothing = array(
            'content'  => $html,
            'headings' => array(),
        );

        if ( '' === trim( $html ) || strlen( $html ) > self::MAX_LENGTH ) {
            return $nothing;
        }

        /*
         * A document type declaration is how an entity expansion bomb arrives,
         * and article content has never needed one. Refused before the parser
         * sees it rather than filtered afterwards, the way the SVG sanitizer
         * refuses the same construct.
         */
        if ( false !== stripos( $html, '<!DOCTYPE' ) || false !== stripos( $html, '<!ENTITY' ) ) {
            return $nothing;
        }

        /*
         * The fast path, and also a correctness guarantee: an article with no
         * heading tag in it is returned as the identical string rather than as
         * whatever the parser would have made of it.
         */
        if ( ! preg_match( '/<h[' . implode( '', array_map( 'intval', self::LEVELS ) ) . '][\s>]/i', $html ) ) {
            return $nothing;
        }

        if ( ! class_exists( 'DOMDocument' ) || ! class_exists( 'DOMXPath' ) ) {
            return $nothing;
        }

        $document = self::parse( $html );

        if ( ! $document instanceof DOMDocument ) {
            return $nothing;
        }

        $root = $document->documentElement;

        if ( ! $root instanceof DOMElement ) {
            return $nothing;
        }

        $headings = self::assign_ids( $document, $root );

        return array(
            'content'  => self::serialize( $document, $root ),
            'headings' => $headings,
        );
    }

    /**
     * Group a flat heading list into the tree the list is rendered from.
     *
     * An `h3` nests under the `h2` above it. An `h3` that has no `h2` above it
     * stays at the top level rather than being dropped, because an article
     * whose author started at `h3` still deserves a table of contents.
     *
     * @since 1.0.0
     *
     * @param array<int, array<string, mixed>> $headings The flat list, in document order.
     *
     * @return array<int, array<string, mixed>> The nested list.
     */
    public static function tree( array $headings ) {
        $tree   = array();
        $parent = null;

        foreach ( $headings as $heading ) {
            $item = array(
                'id'       => isset( $heading['id'] ) ? (string) $heading['id'] : '',
                'text'     => isset( $heading['text'] ) ? (string) $heading['text'] : '',
                'level'    => isset( $heading['level'] ) ? (int) $heading['level'] : 2,
                'children' => array(),
            );

            if ( '' === $item['id'] || '' === $item['text'] ) {
                continue;
            }

            if ( 2 === $item['level'] ) {
                $tree[] = $item;
                $parent = count( $tree ) - 1;

                continue;
            }

            if ( null === $parent ) {
                $tree[] = $item;

                continue;
            }

            $tree[ $parent ]['children'][] = $item;
        }

        return $tree;
    }

    /**
     * Parse article content into a document.
     *
     * Parsed as HTML, unlike the SVG sanitizer, because article content is
     * HTML: it carries void elements with no closing tag, unquoted attributes
     * and entities an XML parser would reject outright, and rejecting an
     * article because a plugin emitted `<br>` is not an option.
     *
     * The content is wrapped in a container element so libxml has a single
     * root to hang the fragment from, and the encoding is declared so that
     * bytes above ASCII are read as UTF-8 rather than as the Latin-1 libxml
     * defaults to. `LIBXML_HTML_NOIMPLIED` and `LIBXML_HTML_NODEFDTD` stop it
     * inventing `<html>`, `<body>` and a doctype around the fragment.
     *
     * @since 1.0.0
     *
     * @param string $html The rendered article content.
     *
     * @return DOMDocument|null The parsed document, or null when parsing failed.
     */
    private static function parse( $html ) {
        $previous = libxml_use_internal_errors( true );

        $document = new DOMDocument();
        $loaded   = $document->loadHTML(
            '<?xml encoding="utf-8" ?><div>' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
        );

        libxml_clear_errors();
        libxml_use_internal_errors( $previous );

        return $loaded ? $document : null;
    }

    /**
     * Give every eligible heading an id, and return the list of them.
     *
     * The XPath expression is what keeps a heading inside `<pre>` or `<code>`
     * out of both the document and the list: it selects only headings with no
     * such ancestor, so those nodes are never reached rather than reached and
     * then skipped. A heading inside a comment is not selected either, because
     * inside a comment it is text and not a heading at all.
     *
     * @since 1.0.0
     *
     * @param DOMDocument $document The parsed document.
     * @param DOMElement  $root     The container element the fragment hangs from.
     *
     * @return array<int, array<string, mixed>> The headings, in document order.
     */
    private static function assign_ids( DOMDocument $document, DOMElement $root ) {
        $xpath = new DOMXPath( $document );
        $nodes = $xpath->query( self::expression(), $root );

        if ( false === $nodes ) {
            return array();
        }

        $headings = array();
        $used     = array();

        foreach ( $nodes as $node ) {
            if ( ! $node instanceof DOMElement ) {
                continue;
            }

            $text = trim( (string) preg_replace( '/\s+/u', ' ', (string) $node->nodeValue ) );

            if ( '' === $text ) {
                continue;
            }

            $id = trim( (string) $node->getAttribute( 'id' ) );

            if ( '' === $id ) {
                $id = self::unique_slug( $text, $used );

                $node->setAttribute( 'id', $id );
            }

            $used[ $id ] = true;

            $headings[] = array(
                'id'    => $id,
                'text'  => $text,
                'level' => (int) substr( $node->nodeName, 1 ),
            );
        }

        return $headings;
    }

    /**
     * The XPath expression selecting every eligible heading.
     *
     * @since 1.0.0
     *
     * @return string The expression.
     */
    private static function expression() {
        $parts = array();

        foreach ( self::LEVELS as $level ) {
            $parts[] = './/h' . (int) $level . '[not(ancestor::pre) and not(ancestor::code)]';
        }

        return implode( ' | ', $parts );
    }

    /**
     * Turn heading text into an id nothing else in the article is using.
     *
     * The slug comes from the text, so it does not move when a heading is
     * added above. Two headings that genuinely share their text are told apart
     * by a numeric suffix, which is the one case where an insertion can shift
     * an id — and the only alternative is storing the ids, which would make
     * renaming a heading unable to rename its anchor.
     *
     * @since 1.0.0
     *
     * @param string              $text The heading text.
     * @param array<string, bool> $used The ids already taken in this article.
     *
     * @return string The id.
     */
    private static function unique_slug( $text, array $used ) {
        $base = sanitize_title( $text );

        if ( '' === $base ) {
            $base = self::FALLBACK_SLUG;
        }

        $slug  = $base;
        $index = 2;

        while ( isset( $used[ $slug ] ) ) {
            $slug = $base . '-' . $index;
            ++$index;
        }

        return $slug;
    }

    /**
     * Serialize the fragment back out of its container.
     *
     * The container itself is not printed: only its children are, so the
     * wrapper the parser needed leaves no trace in the article.
     *
     * @since 1.0.0
     *
     * @param DOMDocument $document The parsed document.
     * @param DOMElement  $root     The container element.
     *
     * @return string The serialized fragment.
     */
    private static function serialize( DOMDocument $document, DOMElement $root ) {
        $html = '';

        foreach ( $root->childNodes as $child ) {
            $markup = $document->saveHTML( $child );

            if ( is_string( $markup ) ) {
                $html .= $markup;
            }
        }

        return $html;
    }
}
