<?php
/**
 * Unit tests for the category term meta.
 *
 * Covers registration, the sanitize callbacks on every key, the authorisation
 * callback and the `licensio_order` term-query orderby.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions -- The tests build the Base64 icons they check.

use Brain\Monkey\Functions;

/**
 * Tests Licensio_KB_Term_Meta.
 *
 * @since 1.0.0
 */
class Licensio_KB_Term_Meta_Test extends Licensio_KB_Test_Case {

    /**
     * Term meta registered during the test, by meta key.
     *
     * @since 1.0.0
     * @var array<string, array{taxonomy: string, args: array<string, mixed>}>
     */
    private $registered = array();

    /**
     * The in-memory term meta table, keyed `term_id|meta_key`.
     *
     * @since 1.0.0
     * @var array<string, mixed>
     */
    private $meta = array();

    /**
     * Post IDs the fake get_post_type() treats as attachments.
     *
     * @since 1.0.0
     * @var int[]
     */
    private $attachments = array( 42 );

    /**
     * Stub the registration and meta surface.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        $this->registered = array();
        $this->meta       = array();

        Functions\when( 'absint' )->alias(
            function ( $value ) {
                return abs( (int) $value );
            }
        );

        Functions\when( 'register_term_meta' )->alias(
            function ( $taxonomy, $key, $args ) {
                $this->registered[ $key ] = array(
                    'taxonomy' => $taxonomy,
                    'args'     => $args,
                );

                return true;
            }
        );

        Functions\when( 'get_post_type' )->alias(
            function ( $id ) {
                return in_array( (int) $id, $this->attachments, true ) ? 'attachment' : 'post';
            }
        );

        Functions\when( 'get_term_meta' )->alias(
            function ( $term_id, $key, $single = false ) {
                unset( $single );
                $index = $term_id . '|' . $key;

                return array_key_exists( $index, $this->meta ) ? $this->meta[ $index ] : '';
            }
        );
    }

    /**
     * All five keys are registered against the category taxonomy.
     *
     * @since 1.0.0 Added the inline SVG icon key.
     *
     * @return void
     */
    public function test_every_key_is_registered_against_the_category_taxonomy() {
        $term_meta = new Licensio_KB_Term_Meta();
        $term_meta->register_meta();

        $expected = array( 'licensio_icon', 'licensio_icon_id', 'licensio_icon_svg', 'licensio_icon_data', 'licensio_image_id', 'licensio_order' );

        $this->assertSame( $expected, array_keys( $this->registered ) );

        foreach ( $this->registered as $key => $registration ) {
            $this->assertSame( 'licensio_category', $registration['taxonomy'], $key );
            $this->assertTrue( $registration['args']['single'], $key );
            $this->assertTrue( $registration['args']['show_in_rest'], $key );
        }
    }

    /**
     * Every registered key carries a sanitize callback and an auth callback.
     *
     * Term meta is writable over REST. A key registered with `show_in_rest` and
     * no sanitize callback stores whatever it is sent, and one with no auth
     * callback falls back to core's `is_protected_meta()` default, which for an
     * unprotected key is "anybody who can edit the term".
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_every_key_is_sanitized_and_authorised() {
        $term_meta = new Licensio_KB_Term_Meta();
        $term_meta->register_meta();

        foreach ( $this->registered as $key => $registration ) {
            $this->assertArrayHasKey( 'sanitize_callback', $registration['args'], $key );
            $this->assertTrue( is_callable( $registration['args']['sanitize_callback'] ), $key );

            $this->assertArrayHasKey( 'auth_callback', $registration['args'], $key );
            $this->assertSame(
                array( 'Licensio_KB_Term_Meta', 'auth_callback' ),
                $registration['args']['auth_callback'],
                $key
            );
        }
    }

    /**
     * The SVG key is registered with the SVG sanitizer wrapper, not something weaker.
     *
     * The generic "every key has a sanitize callback" assertion above is
     * satisfied by any callable, including one that returns its argument. This
     * key is the only one whose stored value is printed into a page as markup,
     * so which callable it got is asserted by name — a REST write reaching
     * `wp_kses_post()`, or nothing at all, would leave that assertion green.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_svg_key_is_registered_with_the_svg_sanitizer() {
        $term_meta = new Licensio_KB_Term_Meta();
        $term_meta->register_meta();

        $this->assertSame(
            array( 'Licensio_KB_Term_Meta', 'sanitize_icon_svg' ),
            $this->registered['licensio_icon_svg']['args']['sanitize_callback']
        );
        $this->assertSame(
            array( 'Licensio_KB_Term_Meta', 'sanitize_icon_data' ),
            $this->registered['licensio_icon_data']['args']['sanitize_callback']
        );
    }

    /**
     * A Base64 SVG is decoded and goes through the SVG sanitizer.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_base64_svg_is_decoded_and_sanitized() {
        $svg = '<svg viewBox="0 0 24 24" onload="alert(1)"><script>alert(2)</script><path d="M4 4h16"/></svg>';

        $this->assertSame(
            '<svg viewBox="0 0 24 24"><path d="M4 4h16"/></svg>',
            Licensio_KB_Term_Meta::sanitize_icon_svg( 'data:image/svg+xml;base64,' . base64_encode( $svg ) )
        );
        $this->assertSame(
            '<svg viewBox="0 0 24 24"><path d="M4 4h16"/></svg>',
            Licensio_KB_Term_Meta::sanitize_icon_svg( $svg ),
            'Markup is sanitized as before.'
        );
        $this->assertSame( '', Licensio_KB_Term_Meta::sanitize_icon_svg( 'data:image/svg+xml;base64,' . base64_encode( '<script>alert(1)</script>' ) ) );
        $this->assertSame( '', Licensio_KB_Term_Meta::sanitize_icon_svg( 'data:image/svg+xml;base64,not base64!' ) );
        $this->assertSame( '', Licensio_KB_Term_Meta::sanitize_icon_svg( array( $svg ) ) );
    }

    /**
     * An oversized Base64 SVG is refused before it is decoded.
     *
     * The padding is whitespace inside the Base64, which decoding strips,
     * so the decoded SVG is small and valid: only a cap on the encoded text
     * refuses it.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_oversized_base64_svg_is_refused_before_decoding() {
        $encoded = base64_encode( '<svg viewBox="0 0 24 24"><path d="M4 4h16"/></svg>' );
        $wrapped = 'data:image/svg+xml;base64,' . chunk_split( $encoded, 76, "\r\n" );
        $padded  = 'data:image/svg+xml;base64,' . substr( $encoded, 0, 4 ) . str_repeat( "\n", 200000 ) . substr( $encoded, 4 );

        $this->assertSame( '<svg viewBox="0 0 24 24"><path d="M4 4h16"/></svg>', Licensio_KB_Term_Meta::sanitize_icon_svg( $wrapped ), 'Wrapped Base64 within the cap is decoded.' );
        $this->assertSame( '', Licensio_KB_Term_Meta::sanitize_icon_svg( $padded ) );
    }

    /**
     * Only a real PNG, JPEG, GIF or WebP within the size cap is kept as a Base64 icon.
     *
     * @since 1.0.0
     *
     * @dataProvider provide_icon_data
     *
     * @param mixed  $input    The raw value.
     * @param string $expected The stored value.
     *
     * @return void
     */
    public function test_a_base64_icon_is_reduced_to_an_allowed_image( $input, $expected ) {
        $this->assertSame( $expected, Licensio_KB_Term_Meta::sanitize_icon_data( $input ) );
    }

    /**
     * Base64 icon values and what is kept of them.
     *
     * @since 1.0.0
     *
     * @return array<string, array{0: mixed, 1: string}> Input and expected output.
     */
    public static function provide_icon_data() {
        $png  = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
        $gif  = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';
        $webp = 'UklGRhoAAABXRUJQVlA4TA0AAAAvAAAAEAcQERGIiP4HAA==';
        $jpeg = '/9j/wAARCAABAAEDASIAAhEBAxEBAP/Z';
        $big  = base64_encode( base64_decode( $png ) . str_repeat( "\0", Licensio_KB_Term_Meta::ICON_DATA_MAX_BYTES ) );

        return array(
            'a png'                   => array( 'data:image/png;base64,' . $png, 'data:image/png;base64,' . $png ),
            'a gif'                   => array( 'data:image/gif;base64,' . $gif, 'data:image/gif;base64,' . $gif ),
            'a webp'                  => array( 'data:image/webp;base64,' . $webp, 'data:image/webp;base64,' . $webp ),
            'a jpeg'                  => array( 'data:image/jpeg;base64,' . $jpeg, 'data:image/jpeg;base64,' . $jpeg ),
            'a jpeg labelled as jpg'  => array( 'data:image/jpg;base64,' . $jpeg, 'data:image/jpeg;base64,' . $jpeg ),
            'a png labelled as jpg'   => array( 'data:image/jpg;base64,' . $png, 'data:image/png;base64,' . $png ),
            'wrapped base64'          => array( " data:image/png;base64,\n" . chunk_split( $png, 20, "\n" ), 'data:image/png;base64,' . $png ),
            'a png labelled as a gif' => array( 'data:image/gif;base64,' . $png, 'data:image/png;base64,' . $png ),
            'an svg'                  => array( 'data:image/svg+xml;base64,' . base64_encode( '<svg/>' ), '' ),
            'html labelled as a png'  => array( 'data:image/png;base64,' . base64_encode( '<script>alert(1)</script>' ), '' ),
            'not base64'              => array( 'data:image/png;base64,***', '' ),
            'not base64 encoded'      => array( 'data:image/png,' . $png, '' ),
            'a url'                   => array( 'https://example.com/icon.png', '' ),
            'javascript'              => array( 'javascript:alert(1)', '' ),
            'too large'               => array( 'data:image/png;base64,' . $big, '' ),
            'empty'                   => array( '', '' ),
            'an array'                => array( array( 'data:image/png;base64,' . $png ), '' ),
        );
    }

    /**
     * Writing term meta is gated on the management capability.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_auth_callback_requires_the_management_capability() {
        Functions\when( 'current_user_can' )->alias(
            function ( $capability ) {
                return 'manage_licensio_kb' === $capability;
            }
        );

        $this->assertTrue( Licensio_KB_Term_Meta::auth_callback() );

        Functions\when( 'current_user_can' )->justReturn( false );

        $this->assertFalse( Licensio_KB_Term_Meta::auth_callback() );
    }

    /**
     * A dashicon name survives; anything else does not.
     *
     * The value is printed into a `class` attribute, so the interesting cases
     * are the ones that would break out of it.
     *
     * @since 1.0.0
     *
     * @dataProvider icon_provider
     *
     * @param mixed  $input    The raw value.
     * @param string $expected The sanitized value.
     *
     * @return void
     */
    public function test_the_icon_is_reduced_to_a_dashicon_name( $input, $expected ) {
        $this->assertSame( $expected, Licensio_KB_Term_Meta::sanitize_icon( $input ) );
    }

    /**
     * Icon values and what sanitising them must produce.
     *
     * @since 1.0.0
     *
     * @return array<string, array{0: mixed, 1: string}> Test cases.
     */
    public function icon_provider() {
        return array(
            'a dashicon'              => array( 'dashicons-book-alt', 'dashicons-book-alt' ),
            'padded and upper cased'  => array( '  DASHICONS-Book-Alt ', 'dashicons-book-alt' ),
            'a single segment'        => array( 'dashicons-book', 'dashicons-book' ),
            'digits in a segment'     => array( 'dashicons-editor-h1', 'dashicons-editor-h1' ),
            'no dashicons prefix'     => array( 'book-alt', '' ),
            'an attribute break-out'  => array( 'dashicons-book" onload="alert(1)', '' ),
            'markup'                  => array( '<script>alert(1)</script>', '' ),
            'a space separated class' => array( 'dashicons-book evil', '' ),
            'a trailing dash'         => array( 'dashicons-book-', '' ),
            'the bare prefix'         => array( 'dashicons-', '' ),
            'an empty string'         => array( '', '' ),
            'an array'                => array( array( 'dashicons-book' ), '' ),
            'null'                    => array( null, '' ),
        );
    }

    /**
     * Only the ID of a real attachment is stored.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_only_an_attachment_id_is_stored() {
        $this->assertSame( 42, Licensio_KB_Term_Meta::sanitize_attachment_id( 42 ) );
        $this->assertSame( 42, Licensio_KB_Term_Meta::sanitize_attachment_id( '42' ) );
        $this->assertSame( 0, Licensio_KB_Term_Meta::sanitize_attachment_id( 0 ) );
        $this->assertSame( 0, Licensio_KB_Term_Meta::sanitize_attachment_id( '' ) );
        $this->assertSame( 0, Licensio_KB_Term_Meta::sanitize_attachment_id( 'nope' ) );
        $this->assertSame( 0, Licensio_KB_Term_Meta::sanitize_attachment_id( array( 42 ) ) );
    }

    /**
     * The ID of a post that is not an attachment is rejected.
     *
     * The field is a hidden input. Storing an arbitrary post ID would hand a
     * private post's ID to wp_get_attachment_image() on the front end.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_post_id_that_is_not_an_attachment_is_rejected() {
        $this->assertSame( 0, Licensio_KB_Term_Meta::sanitize_attachment_id( 7 ) );
    }

    /**
     * The manual order is a non-negative integer.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_order_is_a_non_negative_integer() {
        $this->assertSame( 5, Licensio_KB_Term_Meta::sanitize_order( '5' ) );
        $this->assertSame( 5, Licensio_KB_Term_Meta::sanitize_order( 5.9 ) );
        $this->assertSame( 0, Licensio_KB_Term_Meta::sanitize_order( -3 ) );
        $this->assertSame( 0, Licensio_KB_Term_Meta::sanitize_order( 'abc' ) );
        $this->assertSame( 0, Licensio_KB_Term_Meta::sanitize_order( '' ) );
        $this->assertSame( 0, Licensio_KB_Term_Meta::sanitize_order( array( 3 ) ) );
    }

    /**
     * Reading a stored value runs it back through sanitisation.
     *
     * Values reach term meta by other routes than these forms — an importer,
     * WP-CLI, a hand-edited row — so a consumer must not have to trust what is
     * in the table.
     *
     * @since 1.0.0 Added the inline SVG and Base64 icon keys.
     *
     * @return void
     */
    public function test_reads_are_sanitized_too() {
        $this->meta['9|licensio_icon']      = 'dashicons-book" onload="alert(1)';
        $this->meta['9|licensio_order']     = '-4';
        $this->meta['9|licensio_icon_svg']  = '<svg viewBox="0 0 24 24" onload="alert(1)"><script>alert(2)</script><path d="M4 4h16"/></svg>';
        $this->meta['9|licensio_icon_data'] = 'data:image/png;base64,' . base64_encode( '"><script>alert(1)</script>' );

        $this->assertSame( '', Licensio_KB_Term_Meta::get_icon_data( 9 ), 'A Base64 icon that is not an image is not answered.' );
        $this->assertSame( '', Licensio_KB_Term_Meta::get_icon( 9 ) );
        $this->assertSame( 0, Licensio_KB_Term_Meta::get_order( 9 ) );
        $this->assertSame(
            '<svg viewBox="0 0 24 24"><path d="M4 4h16"/></svg>',
            Licensio_KB_Term_Meta::get_icon_svg( 9 ),
            'A row written outside the form must be sanitized on the way out.'
        );

        $this->meta['9|licensio_icon']     = 'dashicons-sos';
        $this->meta['9|licensio_order']    = '3';
        $this->meta['9|licensio_icon_svg'] = '<svg viewBox="0 0 24 24"><path d="M4 4h16"/></svg>';

        $this->assertSame( 'dashicons-sos', Licensio_KB_Term_Meta::get_icon( 9 ) );
        $this->assertSame( 3, Licensio_KB_Term_Meta::get_order( 9 ) );
        $this->assertSame(
            '<svg viewBox="0 0 24 24"><path d="M4 4h16"/></svg>',
            Licensio_KB_Term_Meta::get_icon_svg( 9 ),
            'A legitimate icon must survive the read.'
        );
    }

    /**
     * `orderby => licensio_order` becomes a meta query on the order key.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_manual_order_is_exposed_as_an_orderby() {
        $term_meta = new Licensio_KB_Term_Meta();

        $args = $term_meta->filter_terms_args(
            array( 'orderby' => 'licensio_order' ),
            array( 'licensio_category' )
        );

        $this->assertSame( 'licensio_order_clause', $args['orderby'] );
        $this->assertArrayHasKey( 'licensio_order_clause', $args['meta_query'] );
        $this->assertSame( 'licensio_order', $args['meta_query']['licensio_order_clause'][0]['key'] );
        $this->assertSame( 'NUMERIC', $args['meta_query']['licensio_order_clause'][0]['type'] );
    }

    /**
     * A category nobody has ordered is still returned.
     *
     * A plain `meta_key` inner-joins term meta, so every category without a
     * stored order would silently vanish from the result — the whole knowledge
     * base, on a site that has never touched the field. The `NOT EXISTS` branch
     * is what stops that, so it is asserted rather than assumed.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_categories_without_a_stored_order_are_not_dropped() {
        $term_meta = new Licensio_KB_Term_Meta();

        $args = $term_meta->filter_terms_args(
            array( 'orderby' => 'licensio_order' ),
            array( 'licensio_category' )
        );

        $clause = $args['meta_query']['licensio_order_clause'];

        $this->assertSame( 'OR', $clause['relation'] );
        $this->assertSame( 'NOT EXISTS', $clause[1]['compare'] );
        $this->assertSame( 'licensio_order', $clause[1]['key'] );
        $this->assertArrayNotHasKey( 'meta_key', $args, 'An inner-joining meta_key would drop unordered terms.' );
    }

    /**
     * Any other term query is handed back untouched.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_other_term_queries_are_left_alone() {
        $term_meta = new Licensio_KB_Term_Meta();

        $untouched = array( 'orderby' => 'name' );
        $this->assertSame( $untouched, $term_meta->filter_terms_args( $untouched, array( 'licensio_category' ) ) );

        $other_taxonomy = array( 'orderby' => 'licensio_order' );
        $this->assertSame( $other_taxonomy, $term_meta->filter_terms_args( $other_taxonomy, array( 'category' ) ) );

        $no_orderby = array( 'hide_empty' => false );
        $this->assertSame( $no_orderby, $term_meta->filter_terms_args( $no_orderby, array( 'licensio_category' ) ) );
    }

    /**
     * Meta registration is hooked after the taxonomies are registered.
     *
     * `register_term_meta()` on a taxonomy that does not exist yet records the
     * meta but never reaches the REST controller for that taxonomy, so the
     * ordering matters and is not merely tidy.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_meta_registration_runs_after_taxonomy_registration() {
        $term_meta = new Licensio_KB_Term_Meta();
        $term_meta->init_hooks();

        $priority = has_action( 'init', array( $term_meta, 'register_meta' ) );

        $this->assertNotFalse( $priority );
        $this->assertGreaterThan( 5, $priority, 'Taxonomies register on init at priority 5.' );
        $this->assertNotFalse( has_filter( 'get_terms_args', array( $term_meta, 'filter_terms_args' ) ) );
    }

    /**
     * Stub a category tree for the placement hooks: term ID to parent.
     *
     * @since 1.0.0
     *
     * @param array<int, int> $parents Term ID to parent ID.
     *
     * @return void
     */
    private function stub_tree( array &$parents ) {
        Functions\when( 'get_term' )->alias(
            function ( $term_id ) use ( &$parents ) {
                return isset( $parents[ (int) $term_id ] ) ? new WP_Term(
                    array(
                        'term_id' => (int) $term_id,
                        'parent'  => $parents[ (int) $term_id ],
                    )
                ) : null;
            }
        );
        Functions\when( 'get_terms' )->alias(
            function ( $args ) use ( &$parents ) {
                $ids = array();

                foreach ( $parents as $id => $parent ) {
                    if ( $parent === $args['parent'] ) {
                        $ids[] = $id;
                    }
                }

                return $ids;
            }
        );
        Functions\when( 'update_term_meta' )->alias(
            function ( $term_id, $key, $value ) {
                $this->meta[ $term_id . '|' . $key ] = $value;

                return true;
            }
        );
    }

    /**
     * A new category goes after its siblings; one given an order keeps it.
     *
     * Siblings without an order count as 0, so a new category also follows
     * them, as the tree sorts unordered siblings first.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_new_category_is_placed_last_among_its_siblings() {
        $parents = array(
            1 => 0,
            2 => 0,
            3 => 1,
            9 => 0,
        );

        $this->stub_tree( $parents );

        $this->meta['1|licensio_order'] = '10';
        $this->meta['2|licensio_order'] = '40';

        $term_meta = new Licensio_KB_Term_Meta();

        $term_meta->place_new_category( 9 );
        $this->assertSame( 50, $this->meta['9|licensio_order'] );

        // The only child of 1, with no ordered siblings.
        $term_meta->place_new_category( 3 );
        $this->assertSame( 10, $this->meta['3|licensio_order'] );

        // An importer or the classic form already chose an order.
        $parents[8]                     = 0;
        $this->meta['8|licensio_order'] = '5';
        $term_meta->place_new_category( 8 );
        $this->assertSame( '5', $this->meta['8|licensio_order'] );
    }

    /**
     * A category moved to another parent goes after its new siblings, unless the same save chose an order.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_moved_category_is_appended_to_its_new_siblings() {
        $parents = array(
            1 => 0,
            2 => 0,
            3 => 1,
            4 => 2,
        );

        $this->stub_tree( $parents );

        // Higher than its new siblings: its own order must not count.
        $this->meta['3|licensio_order'] = '50';
        $this->meta['4|licensio_order'] = '30';

        $term_meta = new Licensio_KB_Term_Meta();

        // 3 moves under 2, next to 4.
        $term_meta->remember_place( 3, 'licensio_category' );
        $parents[3] = 2;
        $term_meta->place_moved_category( 3 );
        $this->assertSame( 40, $this->meta['3|licensio_order'] );

        // A rename leaves the order alone.
        $term_meta->remember_place( 3, 'licensio_category' );
        $term_meta->place_moved_category( 3 );
        $this->assertSame( 40, $this->meta['3|licensio_order'] );

        // A move that also sets an order keeps that order.
        $term_meta->remember_place( 4, 'licensio_category' );
        $parents[4]                     = 1;
        $this->meta['4|licensio_order'] = '5';
        $term_meta->place_moved_category( 4 );
        $this->assertSame( '5', $this->meta['4|licensio_order'] );

        // Another taxonomy is not tracked.
        $term_meta->remember_place( 4, 'licensio_tag' );
        $parents[4] = 0;
        $term_meta->place_moved_category( 4 );
        $this->assertSame( '5', $this->meta['4|licensio_order'] );
    }

    /**
     * The placement hooks run after the classic term form saves its fields.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_placement_hooks_run_after_the_term_form() {
        $term_meta = new Licensio_KB_Term_Meta();

        $term_meta->init_hooks();

        $this->assertSame( 20, has_action( 'created_licensio_category', array( $term_meta, 'place_new_category' ) ) );
        $this->assertSame( 20, has_action( 'edited_licensio_category', array( $term_meta, 'place_moved_category' ) ) );
        $this->assertNotFalse( has_action( 'edit_terms', array( $term_meta, 'remember_place' ) ) );
    }
}
