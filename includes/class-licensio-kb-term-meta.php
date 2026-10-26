<?php
/**
 * Category presentation metadata.
 *
 * Registers the `licensio_category` term meta the specification calls for —
 * icon, image and manual order — with a sanitize callback on every key and an
 * authorisation callback tied to `manage_licensio_kb`. Registration is not
 * admin-only: `show_in_rest` and the sanitize callbacks have to be in place on
 * the front end too, or the REST representation of a category loses its meta
 * and a programmatic `update_term_meta()` stores unsanitized values.
 *
 * Four of those keys are icons, and only one of them can be drawn. The
 * Categories app keeps only the icon source the user picked and clears the
 * others on save, but an importer or the classic term form can still leave
 * more than one set, so their precedence is fixed and documented rather than
 * left to whichever template happens to read first:
 *
 * 1. `licensio_icon_svg`  — inline SVG.
 * 2. `licensio_icon_data` — an inline Base64 raster image (a `data:` URL).
 * 3. `licensio_icon_id`   — an uploaded attachment.
 * 4. `licensio_icon`      — a dashicon name.
 *
 * Most specific first, in the sense of how completely the stored value
 * describes the icon. Inline SVG is the whole drawing, needs no second request
 * to render, scales to any size and inherits its colour from the card through
 * `currentColor`; an attachment is a reference to a file whose contents this
 * plugin has not inspected; a dashicon name is a reference to one of a fixed
 * set of glyphs somebody else drew. A field is only ever ignored in favour of
 * one that says more, never one that says less, and each form field says on
 * screen what it takes precedence over.
 *
 * There is deliberately no colour field beside the SVG. Heroic carried a
 * `meta_svg_color`, but it stored a copy of a colour its importer had already
 * baked into the markup, so it described the icon rather than controlling it.
 * The card sets `color` in CSS, so an icon authored with `currentColor` follows
 * the knowledge base brand colour with nothing stored per category at all — and
 * an icon that wants a fixed colour can simply say so in its own `fill` and
 * `stroke` attributes, which the sanitizer keeps.
 *
 * The term description is deliberately not duplicated here. Core already gives
 * every term a description, edits it on both term forms and exposes it in
 * REST; a second description meta would be two fields that mean the same thing
 * and disagree.
 *
 * Consumed by: #5, #6, #10.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers and reads the knowledge base category term meta.
 *
 * @since 1.0.0
 */
class Licensio_KB_Term_Meta {

    /**
     * Meta key holding a dashicon name, e.g. `dashicons-book-alt`.
     *
     * @since 1.0.0
     * @var string
     */
    const META_ICON = 'licensio_icon';

    /**
     * Meta key holding the attachment ID of an uploaded icon.
     *
     * Separate from META_ICON rather than one overloaded field: the two are
     * rendered by completely different markup — a `<span class="dashicons …">`
     * against an `<img>` — and a single field would have to be parsed to work
     * out which. An uploaded icon takes precedence over a dashicon.
     *
     * @since 1.0.0
     * @var string
     */
    const META_ICON_ID = 'licensio_icon_id';

    /**
     * Meta key holding an inline SVG icon.
     *
     * A third icon field rather than an overload of META_ICON_ID, for the same
     * reason META_ICON_ID is separate from META_ICON: the markup is printed
     * into the page, not linked from it, and the two are not interchangeable.
     * An uploaded SVG is a file the browser fetches and sandboxes; inline SVG
     * is part of the document, which is what lets it inherit `currentColor` —
     * and what makes it the one icon field whose stored value has to be
     * sanitized as markup. See Licensio_KB_SVG_Sanitizer.
     *
     * This is the key Heroic's `meta_svg` migrates into. Heroic echoed that
     * value with `stripslashes()` and no escaping at all
     * (php/ht-knowledge-base-category-meta.php:147), which is a stored XSS in
     * their plugin; the field exists here so the icons can cross without the
     * vulnerability crossing with them.
     *
     * @since 1.0.0
     * @var string
     */
    const META_ICON_SVG = 'licensio_icon_svg';

    /**
     * Meta key holding an inline Base64 raster icon, as a `data:` URL.
     *
     * Separate from META_ICON_SVG because it is printed as the `src` of an
     * `<img>`, not as markup. Only PNG, JPEG, GIF and WebP are kept, and only
     * when the decoded bytes really are an image of that type; a Base64 SVG
     * is decoded into META_ICON_SVG instead, where the SVG sanitiser sees it.
     *
     * @since 1.0.0
     * @var string
     */
    const META_ICON_DATA = 'licensio_icon_data';

    /**
     * The largest inline Base64 icon kept, in decoded bytes.
     *
     * The URL is printed on every card that shows the category, so it is kept
     * to the size of an icon rather than a photograph.
     *
     * @since 1.0.0
     * @var int
     */
    const ICON_DATA_MAX_BYTES = 65536;

    /**
     * The image types an inline Base64 icon may have.
     *
     * @since 1.0.0
     * @var string[]
     */
    const ICON_DATA_TYPES = array( 'image/png', 'image/jpeg', 'image/gif', 'image/webp' );

    /**
     * Meta key holding the attachment ID of the category image.
     *
     * @since 1.0.0
     * @var string
     */
    const META_IMAGE_ID = 'licensio_image_id';

    /**
     * Meta key holding the manual sort order.
     *
     * @since 1.0.0
     * @var string
     */
    const META_ORDER = 'licensio_order';

    /**
     * The `orderby` value that sorts terms by their manual order.
     *
     * @since 1.0.0
     * @var string
     */
    const ORDERBY = 'licensio_order';

    /**
     * Name of the meta query clause used to sort by the manual order.
     *
     * @since 1.0.0
     * @var string
     */
    const ORDER_CLAUSE = 'licensio_order_clause';

    /**
     * Register the WordPress hooks.
     *
     * Meta is registered on `init` at priority 7 — after the taxonomies are
     * registered at 5, because `register_term_meta()` on an unregistered
     * taxonomy still records the meta but nothing wires it into the REST
     * response for that taxonomy.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function init_hooks() {
        add_action( 'init', array( $this, 'register_meta' ), 7 );
        add_filter( 'get_terms_args', array( $this, 'filter_terms_args' ), 10, 2 );
    }

    /**
     * The term meta this plugin registers, keyed by meta key.
     *
     * @since 1.0.0
     *
     * @return array<string, array<string, mixed>> Meta key to its `register_term_meta()` arguments.
     */
    public function definitions() {
        return array(
            self::META_ICON      => array(
                'type'              => 'string',
                'description'       => __( 'Dashicon name used as the category icon.', 'licensio-knowledge-base' ),
                'single'            => true,
                'default'           => '',
                'show_in_rest'      => true,
                'sanitize_callback' => array( __CLASS__, 'sanitize_icon' ),
                'auth_callback'     => array( __CLASS__, 'auth_callback' ),
            ),
            self::META_ICON_ID   => array(
                'type'              => 'integer',
                'description'       => __( 'Attachment ID of an uploaded category icon.', 'licensio-knowledge-base' ),
                'single'            => true,
                'default'           => 0,
                'show_in_rest'      => true,
                'sanitize_callback' => array( __CLASS__, 'sanitize_attachment_id' ),
                'auth_callback'     => array( __CLASS__, 'auth_callback' ),
            ),
            self::META_ICON_SVG  => array(
                'type'              => 'string',
                'description'       => __( 'Inline SVG markup used as the category icon.', 'licensio-knowledge-base' ),
                'single'            => true,
                'default'           => '',
                'show_in_rest'      => true,
                'sanitize_callback' => array( __CLASS__, 'sanitize_icon_svg' ),
                'auth_callback'     => array( __CLASS__, 'auth_callback' ),
            ),
            self::META_ICON_DATA => array(
                'type'              => 'string',
                'description'       => __( 'Inline Base64 image used as the category icon.', 'licensio-knowledge-base' ),
                'single'            => true,
                'default'           => '',
                'show_in_rest'      => true,
                'sanitize_callback' => array( __CLASS__, 'sanitize_icon_data' ),
                'auth_callback'     => array( __CLASS__, 'auth_callback' ),
            ),
            self::META_IMAGE_ID  => array(
                'type'              => 'integer',
                'description'       => __( 'Attachment ID of the category image.', 'licensio-knowledge-base' ),
                'single'            => true,
                'default'           => 0,
                'show_in_rest'      => true,
                'sanitize_callback' => array( __CLASS__, 'sanitize_attachment_id' ),
                'auth_callback'     => array( __CLASS__, 'auth_callback' ),
            ),
            self::META_ORDER     => array(
                'type'              => 'integer',
                'description'       => __( 'Manual sort order of the category.', 'licensio-knowledge-base' ),
                'single'            => true,
                'default'           => 0,
                'show_in_rest'      => true,
                'sanitize_callback' => array( __CLASS__, 'sanitize_order' ),
                'auth_callback'     => array( __CLASS__, 'auth_callback' ),
            ),
        );
    }

    /**
     * Register the category term meta with WordPress.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function register_meta() {
        foreach ( $this->definitions() as $key => $args ) {
            register_term_meta( Licensio_KB_Post_Types::TAXONOMY_CATEGORY, $key, $args );
        }
    }

    /**
     * Authorisation callback for every knowledge base term meta key.
     *
     * Term meta is writable through the REST API, so without this any
     * authenticated user who can edit the term could also set the icon and the
     * image. It is deliberately the same capability that gates the term itself.
     *
     * @since 1.0.0
     *
     * @return bool True when the current user may write knowledge base term meta.
     */
    public static function auth_callback() {
        return Licensio_KB_Capabilities::current_user_can_manage();
    }

    /**
     * Reduce a value to a dashicon name.
     *
     * The stored value is printed straight into a `class` attribute by the
     * template layer, so anything that is not literally `dashicons-` followed
     * by lowercase alphanumeric segments is rejected rather than escaped —
     * there is no legitimate icon name that this loses.
     *
     * @since 1.0.0
     *
     * @param mixed $value The raw value.
     *
     * @return string The dashicon name, or an empty string when the value is not one.
     */
    public static function sanitize_icon( $value ) {
        if ( ! is_scalar( $value ) ) {
            return '';
        }

        $icon = strtolower( trim( (string) $value ) );

        return preg_match( '/^dashicons-[a-z0-9]+(?:-[a-z0-9]+)*$/', $icon ) ? $icon : '';
    }

    /**
     * Reduce a value to sanitised inline SVG markup.
     *
     * Takes SVG markup, or a Base64 SVG `data:` URL, which is decoded first.
     * Either way the markup goes through Licensio_KB_SVG_Sanitizer, so a
     * Base64 SVG is never a way around it.
     *
     * @since 1.0.0
     *
     * @param mixed $value The raw markup or `data:image/svg+xml;base64,` URL.
     *
     * @return string The sanitised SVG, or an empty string when there is nothing safe to keep.
     */
    public static function sanitize_icon_svg( $value ) {
        if ( ! is_scalar( $value ) ) {
            return '';
        }

        $value = trim( (string) $value );

        // Text that is not Base64 decodes to an empty string, and the sanitiser applies the size cap.
        if ( 1 === preg_match( '#^data:image/svg\+xml;base64,#i', $value ) ) {
            $value = self::decode_base64( substr( $value, strpos( $value, ',' ) + 1 ) );
        }

        return Licensio_KB_SVG_Sanitizer::sanitize( $value );
    }

    /**
     * Reduce a value to an inline Base64 raster icon.
     *
     * Kept only when it is a `data:` URL of one of ICON_DATA_TYPES whose
     * Base64 decodes strictly, whose bytes are no larger than
     * ICON_DATA_MAX_BYTES and really are an image of an allowed type. The URL
     * is rebuilt from the decoded bytes and the type they turned out to be,
     * so nothing but the image itself survives.
     *
     * @since 1.0.0
     *
     * @param mixed $value The raw `data:` URL.
     *
     * @return string The `data:` URL, or an empty string when the value is not an allowed image.
     */
    public static function sanitize_icon_data( $value ) {
        if ( ! is_scalar( $value ) ) {
            return '';
        }

        $value = trim( (string) $value );

        if ( 1 !== preg_match( '#^data:image/(png|jpeg|gif|webp);base64,#i', $value ) ) {
            return '';
        }

        // A loose cap on the encoded text first, twice what the largest icon needs to leave room for line breaks, so a huge value is never decoded.
        if ( strlen( $value ) > 2 * ( 64 + (int) ceil( self::ICON_DATA_MAX_BYTES / 3 ) * 4 ) ) {
            return '';
        }

        $bytes = self::decode_base64( substr( $value, strpos( $value, ',' ) + 1 ) );

        if ( '' === $bytes || strlen( $bytes ) > self::ICON_DATA_MAX_BYTES ) {
            return '';
        }

        $size = @getimagesizefromstring( $bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Bytes that are not an image raise a notice; that is the answer, not an error.
        $mime = is_array( $size ) ? $size['mime'] : '';

        if ( ! in_array( $mime, self::ICON_DATA_TYPES, true ) ) {
            return '';
        }

        return 'data:' . $mime . ';base64,' . base64_encode( $bytes ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Rebuilding a data: URL for an image.
    }

    /**
     * Decode strict Base64, ignoring the whitespace a pasted value wraps with.
     *
     * @since 1.0.0
     *
     * @param string $encoded The Base64 text.
     *
     * @return string The bytes, or an empty string when the text is not Base64.
     */
    private static function decode_base64( $encoded ) {
        $encoded = (string) preg_replace( '/\s+/', '', $encoded );
        $bytes   = base64_decode( $encoded, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding an image the user pasted.

        return is_string( $bytes ) ? $bytes : '';
    }

    /**
     * Reduce a value to the ID of a real attachment.
     *
     * `absint()` alone is not enough. The field is a hidden input on a form
     * anybody with the management capability can edit, and an arbitrary post ID
     * stored here would later be handed to `wp_get_attachment_image()`, which
     * would either render nothing or leak the existence of a private post.
     *
     * @since 1.0.0
     *
     * @param mixed $value The raw value.
     *
     * @return int The attachment ID, or 0 when the value does not name one.
     */
    public static function sanitize_attachment_id( $value ) {
        $id = is_scalar( $value ) ? absint( $value ) : 0;

        if ( 0 === $id ) {
            return 0;
        }

        return ( 'attachment' === get_post_type( $id ) ) ? $id : 0;
    }

    /**
     * Reduce a value to a manual sort order.
     *
     * @since 1.0.0
     *
     * @param mixed $value The raw value.
     *
     * @return int The sort order, never negative.
     */
    public static function sanitize_order( $value ) {
        if ( ! is_scalar( $value ) || ! is_numeric( $value ) ) {
            return 0;
        }

        return max( 0, (int) $value );
    }

    /**
     * Support `orderby => 'licensio_order'` in term queries.
     *
     * The manual order is the point of the field, and a consumer should not
     * have to know it is stored in term meta to sort by it. The clause is a
     * two-branch `OR` — the order exists, or it does not — because a plain
     * `meta_key` inner-joins term meta and would silently drop every category
     * nobody has ordered yet. Both branches sort together: MySQL orders a NULL
     * from the missing branch alongside the zero a newly-ordered term carries.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $args       The term query arguments.
     * @param string[]|string      $taxonomies The taxonomies being queried.
     *
     * @return array<string, mixed> The arguments, with the manual order translated to a meta query.
     */
    public function filter_terms_args( $args, $taxonomies ) {
        if ( ! is_array( $args ) || ! isset( $args['orderby'] ) || self::ORDERBY !== $args['orderby'] ) {
            return $args;
        }

        if ( ! in_array( Licensio_KB_Post_Types::TAXONOMY_CATEGORY, (array) $taxonomies, true ) ) {
            return $args;
        }

        $meta_query = isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ? $args['meta_query'] : array();

        $meta_query[ self::ORDER_CLAUSE ] = array(
            'relation' => 'OR',
            array(
                'key'     => self::META_ORDER,
                'type'    => 'NUMERIC',
                'compare' => 'EXISTS',
            ),
            array(
                'key'     => self::META_ORDER,
                'compare' => 'NOT EXISTS',
            ),
        );

        $args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
        $args['orderby']    = self::ORDER_CLAUSE;

        return $args;
    }

    /**
     * The dashicon name stored on a category.
     *
     * @since 1.0.0
     *
     * @param int $term_id The category term ID.
     *
     * @return string The dashicon name, or an empty string when none is set.
     */
    public static function get_icon( $term_id ) {
        return self::sanitize_icon( get_term_meta( (int) $term_id, self::META_ICON, true ) );
    }

    /**
     * The attachment ID of a category's uploaded icon.
     *
     * @since 1.0.0
     *
     * @param int $term_id The category term ID.
     *
     * @return int The attachment ID, or 0 when none is set.
     */
    public static function get_icon_id( $term_id ) {
        return absint( get_term_meta( (int) $term_id, self::META_ICON_ID, true ) );
    }

    /**
     * The inline SVG icon stored on a category.
     *
     * Re-sanitized on the way out, like the dashicon name is. A value that was
     * stored before an element left the allowlist, or that never went through
     * `update_term_meta()` at all — an importer writing straight to the table,
     * a hand-edited row — is sanitized by the time a caller sees it, so the
     * renderer's freedom not to escape rests on this function and not on the
     * history of the row.
     *
     * @since 1.0.0
     *
     * @param int $term_id The category term ID.
     *
     * @return string The sanitized SVG markup, or an empty string when none is set.
     */
    public static function get_icon_svg( $term_id ) {
        return self::sanitize_icon_svg( get_term_meta( (int) $term_id, self::META_ICON_SVG, true ) );
    }

    /**
     * The inline Base64 raster icon stored on a category.
     *
     * Re-checked on the way out, like the SVG is, so a template may print it
     * as an `<img>` source whatever wrote the row.
     *
     * @since 1.0.0
     *
     * @param int $term_id The category term ID.
     *
     * @return string The `data:` URL, or an empty string when none is set.
     */
    public static function get_icon_data( $term_id ) {
        return self::sanitize_icon_data( get_term_meta( (int) $term_id, self::META_ICON_DATA, true ) );
    }

    /**
     * The attachment ID of a category's image.
     *
     * @since 1.0.0
     *
     * @param int $term_id The category term ID.
     *
     * @return int The attachment ID, or 0 when none is set.
     */
    public static function get_image_id( $term_id ) {
        return absint( get_term_meta( (int) $term_id, self::META_IMAGE_ID, true ) );
    }

    /**
     * The manual sort order of a category.
     *
     * @since 1.0.0
     *
     * @param int $term_id The category term ID.
     *
     * @return int The sort order, 0 when none is set.
     */
    public static function get_order( $term_id ) {
        return self::sanitize_order( get_term_meta( (int) $term_id, self::META_ORDER, true ) );
    }
}
