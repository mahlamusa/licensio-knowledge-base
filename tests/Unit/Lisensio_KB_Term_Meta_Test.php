<?php
/**
 * Unit tests for the category term meta.
 *
 * Covers registration, the sanitize callbacks on every key, the authorisation
 * callback and the `lisensio_order` term-query orderby.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

use Brain\Monkey\Functions;

/**
 * Tests Lisensio_KB_Term_Meta.
 *
 * @since 1.0.0
 */
class Lisensio_KB_Term_Meta_Test extends Lisensio_KB_Test_Case {

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
     * All four keys are registered against the category taxonomy.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_every_key_is_registered_against_the_category_taxonomy() {
        $term_meta = new Lisensio_KB_Term_Meta();
        $term_meta->register_meta();

        $expected = array( 'lisensio_icon', 'lisensio_icon_id', 'lisensio_image_id', 'lisensio_order' );

        $this->assertSame( $expected, array_keys( $this->registered ) );

        foreach ( $this->registered as $key => $registration ) {
            $this->assertSame( 'lisensio_category', $registration['taxonomy'], $key );
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
        $term_meta = new Lisensio_KB_Term_Meta();
        $term_meta->register_meta();

        foreach ( $this->registered as $key => $registration ) {
            $this->assertArrayHasKey( 'sanitize_callback', $registration['args'], $key );
            $this->assertTrue( is_callable( $registration['args']['sanitize_callback'] ), $key );

            $this->assertArrayHasKey( 'auth_callback', $registration['args'], $key );
            $this->assertSame(
                array( 'Lisensio_KB_Term_Meta', 'auth_callback' ),
                $registration['args']['auth_callback'],
                $key
            );
        }
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
                return 'manage_lisensio_kb' === $capability;
            }
        );

        $this->assertTrue( Lisensio_KB_Term_Meta::auth_callback() );

        Functions\when( 'current_user_can' )->justReturn( false );

        $this->assertFalse( Lisensio_KB_Term_Meta::auth_callback() );
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
        $this->assertSame( $expected, Lisensio_KB_Term_Meta::sanitize_icon( $input ) );
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
        $this->assertSame( 42, Lisensio_KB_Term_Meta::sanitize_attachment_id( 42 ) );
        $this->assertSame( 42, Lisensio_KB_Term_Meta::sanitize_attachment_id( '42' ) );
        $this->assertSame( 0, Lisensio_KB_Term_Meta::sanitize_attachment_id( 0 ) );
        $this->assertSame( 0, Lisensio_KB_Term_Meta::sanitize_attachment_id( '' ) );
        $this->assertSame( 0, Lisensio_KB_Term_Meta::sanitize_attachment_id( 'nope' ) );
        $this->assertSame( 0, Lisensio_KB_Term_Meta::sanitize_attachment_id( array( 42 ) ) );
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
        $this->assertSame( 0, Lisensio_KB_Term_Meta::sanitize_attachment_id( 7 ) );
    }

    /**
     * The manual order is a non-negative integer.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_order_is_a_non_negative_integer() {
        $this->assertSame( 5, Lisensio_KB_Term_Meta::sanitize_order( '5' ) );
        $this->assertSame( 5, Lisensio_KB_Term_Meta::sanitize_order( 5.9 ) );
        $this->assertSame( 0, Lisensio_KB_Term_Meta::sanitize_order( -3 ) );
        $this->assertSame( 0, Lisensio_KB_Term_Meta::sanitize_order( 'abc' ) );
        $this->assertSame( 0, Lisensio_KB_Term_Meta::sanitize_order( '' ) );
        $this->assertSame( 0, Lisensio_KB_Term_Meta::sanitize_order( array( 3 ) ) );
    }

    /**
     * Reading a stored value runs it back through sanitisation.
     *
     * Values reach term meta by other routes than these forms — an importer,
     * WP-CLI, a hand-edited row — so a consumer must not have to trust what is
     * in the table.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_reads_are_sanitized_too() {
        $this->meta['9|lisensio_icon']  = 'dashicons-book" onload="alert(1)';
        $this->meta['9|lisensio_order'] = '-4';

        $this->assertSame( '', Lisensio_KB_Term_Meta::get_icon( 9 ) );
        $this->assertSame( 0, Lisensio_KB_Term_Meta::get_order( 9 ) );

        $this->meta['9|lisensio_icon']  = 'dashicons-sos';
        $this->meta['9|lisensio_order'] = '3';

        $this->assertSame( 'dashicons-sos', Lisensio_KB_Term_Meta::get_icon( 9 ) );
        $this->assertSame( 3, Lisensio_KB_Term_Meta::get_order( 9 ) );
    }

    /**
     * `orderby => lisensio_order` becomes a meta query on the order key.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_manual_order_is_exposed_as_an_orderby() {
        $term_meta = new Lisensio_KB_Term_Meta();

        $args = $term_meta->filter_terms_args(
            array( 'orderby' => 'lisensio_order' ),
            array( 'lisensio_category' )
        );

        $this->assertSame( 'lisensio_order_clause', $args['orderby'] );
        $this->assertArrayHasKey( 'lisensio_order_clause', $args['meta_query'] );
        $this->assertSame( 'lisensio_order', $args['meta_query']['lisensio_order_clause'][0]['key'] );
        $this->assertSame( 'NUMERIC', $args['meta_query']['lisensio_order_clause'][0]['type'] );
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
        $term_meta = new Lisensio_KB_Term_Meta();

        $args = $term_meta->filter_terms_args(
            array( 'orderby' => 'lisensio_order' ),
            array( 'lisensio_category' )
        );

        $clause = $args['meta_query']['lisensio_order_clause'];

        $this->assertSame( 'OR', $clause['relation'] );
        $this->assertSame( 'NOT EXISTS', $clause[1]['compare'] );
        $this->assertSame( 'lisensio_order', $clause[1]['key'] );
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
        $term_meta = new Lisensio_KB_Term_Meta();

        $untouched = array( 'orderby' => 'name' );
        $this->assertSame( $untouched, $term_meta->filter_terms_args( $untouched, array( 'lisensio_category' ) ) );

        $other_taxonomy = array( 'orderby' => 'lisensio_order' );
        $this->assertSame( $other_taxonomy, $term_meta->filter_terms_args( $other_taxonomy, array( 'category' ) ) );

        $no_orderby = array( 'hide_empty' => false );
        $this->assertSame( $no_orderby, $term_meta->filter_terms_args( $no_orderby, array( 'lisensio_category' ) ) );
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
        $term_meta = new Lisensio_KB_Term_Meta();
        $term_meta->init_hooks();

        $priority = has_action( 'init', array( $term_meta, 'register_meta' ) );

        $this->assertNotFalse( $priority );
        $this->assertGreaterThan( 5, $priority, 'Taxonomies register on init at priority 5.' );
        $this->assertNotFalse( has_filter( 'get_terms_args', array( $term_meta, 'filter_terms_args' ) ) );
    }
}
