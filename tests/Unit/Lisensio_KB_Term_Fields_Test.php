<?php
/**
 * Unit tests for the category term meta form fields.
 *
 * Covers the two gates on the save handler, that only posted fields are
 * written, and that what is written has been sanitized.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

use Brain\Monkey\Functions;

/**
 * Tests Lisensio_KB_Term_Fields.
 *
 * @since 1.0.0
 */
class Lisensio_KB_Term_Fields_Test extends Lisensio_KB_Test_Case {

    /**
     * Term meta written during the test, keyed `term_id|meta_key`.
     *
     * @since 1.0.0
     * @var array<string, mixed>
     */
    private $written = array();

    /**
     * Nonce values the fake wp_verify_nonce() accepts.
     *
     * @since 1.0.0
     * @var string
     */
    private $valid_nonce = 'good-nonce';

    /**
     * Stub the save surface and post a valid request by default.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        $this->written = array();

        Functions\when( 'absint' )->alias(
            function ( $value ) {
                return abs( (int) $value );
            }
        );

        Functions\when( 'wp_kses_post' )->returnArg();

        Functions\when( 'current_user_can' )->alias(
            function ( $capability ) {
                return 'manage_lisensio_kb' === $capability;
            }
        );

        Functions\when( 'wp_verify_nonce' )->alias(
            function ( $nonce, $action ) {
                return ( $this->valid_nonce === $nonce && 'lisensio_save_term_meta' === $action ) ? 1 : false;
            }
        );

        Functions\when( 'get_post_type' )->alias(
            function ( $id ) {
                return 42 === (int) $id ? 'attachment' : 'post';
            }
        );

        Functions\when( 'update_term_meta' )->alias(
            function ( $term_id, $key, $value ) {
                $this->written[ $term_id . '|' . $key ] = $value;

                return true;
            }
        );

        $_POST = array(
            'lisensio_term_meta_nonce' => $this->valid_nonce,
        );
    }

    /**
     * Leave no request state behind.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function tearDown(): void {
        $_POST = array();

        parent::tearDown();
    }

    /**
     * A valid submission stores every posted field.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_valid_submission_is_stored() {
        $_POST['lisensio_icon']     = 'dashicons-book-alt';
        $_POST['lisensio_icon_id']  = '42';
        $_POST['lisensio_image_id'] = '42';
        $_POST['lisensio_order']    = '7';

        $fields = new Lisensio_KB_Term_Fields();
        $fields->save_fields( 3 );

        $this->assertSame(
            array(
                '3|lisensio_icon'     => 'dashicons-book-alt',
                '3|lisensio_icon_id'  => 42,
                '3|lisensio_image_id' => 42,
                '3|lisensio_order'    => 7,
            ),
            $this->written
        );
    }

    /**
     * What is stored has been through the sanitizer, not just the form.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_posted_values_are_sanitized_before_they_are_stored() {
        $_POST['lisensio_icon']     = 'dashicons-book" onload="alert(1)';
        $_POST['lisensio_image_id'] = '7';
        $_POST['lisensio_order']    = '-9';

        $fields = new Lisensio_KB_Term_Fields();
        $fields->save_fields( 3 );

        $this->assertSame( '', $this->written['3|lisensio_icon'] );
        $this->assertSame( 0, $this->written['3|lisensio_image_id'], 'Post 7 is not an attachment.' );
        $this->assertSame( 0, $this->written['3|lisensio_order'] );
    }

    /**
     * Nothing is written without a valid nonce.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_bad_nonce_writes_nothing() {
        $_POST['lisensio_term_meta_nonce'] = 'forged';
        $_POST['lisensio_order']           = '7';

        $fields = new Lisensio_KB_Term_Fields();
        $fields->save_fields( 3 );

        $this->assertSame( array(), $this->written );
    }

    /**
     * Nothing is written when the nonce field is absent altogether.
     *
     * This is the case a term created by `wp_insert_term()` or by an importer
     * hits: `created_lisensio_category` fires with no request behind it.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_request_with_no_nonce_writes_nothing() {
        unset( $_POST['lisensio_term_meta_nonce'] );
        $_POST['lisensio_order'] = '7';

        $fields = new Lisensio_KB_Term_Fields();
        $fields->save_fields( 3 );

        $this->assertSame( array(), $this->written );
    }

    /**
     * Nothing is written for a user without the management capability.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_user_without_the_capability_writes_nothing() {
        Functions\when( 'current_user_can' )->justReturn( false );

        $_POST['lisensio_order'] = '7';

        $fields = new Lisensio_KB_Term_Fields();
        $fields->save_fields( 3 );

        $this->assertSame( array(), $this->written );
    }

    /**
     * A field that was not posted is left alone.
     *
     * The edit form posts all four, but a quick-edit of the term name — or any
     * other code path that reaches `edited_lisensio_category` — posts none of
     * them, and writing sanitized defaults there would erase an icon and an
     * image nobody asked to change.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_absent_fields_are_not_overwritten() {
        $_POST['lisensio_order'] = '2';

        $fields = new Lisensio_KB_Term_Fields();
        $fields->save_fields( 3 );

        $this->assertSame( array( '3|lisensio_order' => 2 ), $this->written );
    }

    /**
     * The handler is attached to both term lifecycle actions and both forms.
     *
     * Hooking only `edited_` would silently drop everything typed into the add
     * form, which is the screen most categories are created on.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_both_forms_and_both_save_actions_are_hooked() {
        $fields = new Lisensio_KB_Term_Fields();
        $fields->init_hooks();

        $this->assertNotFalse( has_action( 'lisensio_category_add_form_fields', array( $fields, 'render_add_fields' ) ) );
        $this->assertNotFalse( has_action( 'lisensio_category_edit_form_fields', array( $fields, 'render_edit_fields' ) ) );
        $this->assertNotFalse( has_action( 'created_lisensio_category', array( $fields, 'save_fields' ) ) );
        $this->assertNotFalse( has_action( 'edited_lisensio_category', array( $fields, 'save_fields' ) ) );
    }

    /**
     * Record whether the media picker assets were enqueued for a screen.
     *
     * @since 1.0.0
     *
     * @param string         $hook_suffix The admin page to simulate.
     * @param WP_Screen|null $screen     The screen get_current_screen() returns.
     *
     * @return array{media: bool, scripts: string[]} What was enqueued.
     */
    private function enqueue_for( $hook_suffix, $screen ) {
        $enqueued = array(
            'media'   => false,
            'scripts' => array(),
        );

        Functions\when( 'get_current_screen' )->justReturn( $screen );
        Functions\when( 'wp_localize_script' )->justReturn( true );

        Functions\when( 'wp_enqueue_media' )->alias(
            function () use ( &$enqueued ) {
                $enqueued['media'] = true;
            }
        );

        Functions\when( 'wp_enqueue_script' )->alias(
            function ( $handle ) use ( &$enqueued ) {
                $enqueued['scripts'][] = $handle;
            }
        );

        $fields = new Lisensio_KB_Term_Fields();
        $fields->enqueue_assets( $hook_suffix );

        return $enqueued;
    }

    /**
     * The media picker loads on the knowledge base category screens.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_media_picker_loads_on_the_category_screens() {
        $enqueued = $this->enqueue_for( 'edit-tags.php', new WP_Screen( 'lisensio_category' ) );

        $this->assertTrue( $enqueued['media'] );
        $this->assertSame( array( 'lisensio-kb-term-fields' ), $enqueued['scripts'] );

        $enqueued = $this->enqueue_for( 'term.php', new WP_Screen( 'lisensio_category' ) );

        $this->assertTrue( $enqueued['media'] );
    }

    /**
     * The media library is not loaded on unrelated admin screens.
     *
     * `wp_enqueue_media()` prints a large amount of markup and script into the
     * footer, so it must not follow the plugin onto every admin page — nor onto
     * another plugin's taxonomy screens.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_media_library_is_not_loaded_on_other_screens() {
        $enqueued = $this->enqueue_for( 'index.php', new WP_Screen( 'lisensio_category' ) );

        $this->assertFalse( $enqueued['media'] );
        $this->assertSame( array(), $enqueued['scripts'] );

        $enqueued = $this->enqueue_for( 'edit-tags.php', new WP_Screen( 'post_tag' ) );

        $this->assertFalse( $enqueued['media'] );

        $enqueued = $this->enqueue_for( 'edit-tags.php', null );

        $this->assertFalse( $enqueued['media'] );
    }

    /**
     * The add form renders a nonce and every field.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_add_form_renders_a_nonce_and_every_field() {
        Functions\when( 'wp_nonce_field' )->alias(
            function ( $action, $name ) {
                printf( '<input type="hidden" name="%s" value="%s" />', esc_attr( $name ), esc_attr( $action ) );
            }
        );

        $fields = new Lisensio_KB_Term_Fields();

        ob_start();
        $fields->render_add_fields( 'lisensio_category' );
        $markup = (string) ob_get_clean();

        $this->assertStringContainsString( 'name="lisensio_term_meta_nonce"', $markup );
        $this->assertStringContainsString( 'name="lisensio_icon"', $markup );
        $this->assertStringContainsString( 'name="lisensio_icon_id"', $markup );
        $this->assertStringContainsString( 'name="lisensio_image_id"', $markup );
        $this->assertStringContainsString( 'name="lisensio_order"', $markup );
    }

    /**
     * The edit form renders the stored values back into the fields.
     *
     * The acceptance criterion is that an icon and an image round-trip through
     * save and reload, so the reload half is asserted here.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_edit_form_renders_the_stored_values() {
        Functions\when( 'wp_nonce_field' )->justReturn( '' );
        Functions\when( 'wp_get_attachment_image' )->justReturn( '<img src="icon.svg" alt="" />' );
        Functions\when( 'get_term_meta' )->alias(
            function ( $term_id, $key ) {
                unset( $term_id );

                $stored = array(
                    'lisensio_icon'     => 'dashicons-sos',
                    'lisensio_icon_id'  => 42,
                    'lisensio_image_id' => 0,
                    'lisensio_order'    => 6,
                );

                return isset( $stored[ $key ] ) ? $stored[ $key ] : '';
            }
        );

        $fields = new Lisensio_KB_Term_Fields();

        ob_start();
        $fields->render_edit_fields( (object) array( 'term_id' => 3 ) );
        $markup = (string) ob_get_clean();

        $this->assertStringContainsString( 'value="dashicons-sos"', $markup );
        $this->assertStringContainsString( 'value="42"', $markup );
        $this->assertStringContainsString( 'value="6"', $markup );
        $this->assertStringContainsString( '<img src="icon.svg"', $markup );
    }
}
