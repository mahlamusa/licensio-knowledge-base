<?php
/**
 * Unit tests for the category term meta form fields.
 *
 * Covers the two gates on the save handler, that only posted fields are
 * written, and that what is written has been sanitized.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

use Brain\Monkey\Functions;

/**
 * Tests Licensio_KB_Term_Fields.
 *
 * @since 1.0.0
 */
class Licensio_KB_Term_Fields_Test extends Licensio_KB_Test_Case {

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
                return 'manage_licensio_kb' === $capability;
            }
        );

        Functions\when( 'wp_verify_nonce' )->alias(
            function ( $nonce, $action ) {
                return ( $this->valid_nonce === $nonce && 'licensio_save_term_meta' === $action ) ? 1 : false;
            }
        );

        Functions\when( 'get_post_type' )->alias(
            function ( $id ) {
                return 42 === (int) $id ? 'attachment' : 'post';
            }
        );

        // Core's update_term_meta() unslashes the value before storing it.
        Functions\when( 'update_term_meta' )->alias(
            function ( $term_id, $key, $value ) {
                $this->written[ $term_id . '|' . $key ] = self::core_unslash( $value );

                return true;
            }
        );

        $_POST = array(
            'licensio_term_meta_nonce' => $this->valid_nonce,
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
     * A valid submission stores every posted field, keeping one icon.
     *
     * The SVG wins by precedence, so the uploaded icon, the Dashicons name and
     * the inline Base64 image are cleared rather than left hidden behind it.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_valid_submission_is_stored() {
        $_POST['licensio_icon']     = 'dashicons-book-alt';
        $_POST['licensio_icon_svg'] = '<svg viewBox="0 0 24 24"><path d="M4 4h16"/></svg>';
        $_POST['licensio_icon_id']  = '42';
        $_POST['licensio_image_id'] = '42';
        $_POST['licensio_order']    = '7';

        $fields = new Licensio_KB_Term_Fields();
        $fields->save_fields( 3 );

        $this->assertSame(
            array(
                '3|licensio_icon'      => '',
                '3|licensio_icon_svg'  => '<svg viewBox="0 0 24 24"><path d="M4 4h16"/></svg>',
                '3|licensio_icon_id'   => 0,
                '3|licensio_image_id'  => 42,
                '3|licensio_order'     => 7,
                '3|licensio_icon_data' => '',
            ),
            $this->written
        );
    }

    /**
     * A Dashicons name saved on its own clears every other icon source.
     *
     * The form has no Base64 control, so a Base64 icon set in the Categories
     * app would otherwise keep winning over the icon picked here.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_one_icon_source_is_kept_and_the_others_cleared() {
        $_POST['licensio_icon']     = 'dashicons-lock';
        $_POST['licensio_icon_svg'] = '';
        $_POST['licensio_icon_id']  = '0';

        $fields = new Licensio_KB_Term_Fields();
        $fields->save_fields( 3 );

        $this->assertSame(
            array(
                '3|licensio_icon'      => 'dashicons-lock',
                '3|licensio_icon_svg'  => '',
                '3|licensio_icon_id'   => 0,
                '3|licensio_icon_data' => '',
            ),
            $this->written
        );

        $this->written             = array();
        $_POST['licensio_icon_id'] = '42';

        $fields->save_fields( 3 );

        $this->assertSame(
            array(
                '3|licensio_icon'      => '',
                '3|licensio_icon_svg'  => '',
                '3|licensio_icon_id'   => 42,
                '3|licensio_icon_data' => '',
            ),
            $this->written,
            'An uploaded icon beats the Dashicons name.'
        );
    }

    /**
     * A save that names no icon leaves the stored icons, Base64 included, alone.
     *
     * The edit form posts its empty icon fields with every save, so changing
     * only the order of a category whose icon was set in the app must not
     * clear that icon.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_save_with_no_icon_keeps_the_base64_icon() {
        $_POST['licensio_icon']     = '';
        $_POST['licensio_icon_svg'] = '';
        $_POST['licensio_icon_id']  = '0';
        $_POST['licensio_order']    = '4';

        $fields = new Licensio_KB_Term_Fields();
        $fields->save_fields( 3 );

        $this->assertArrayNotHasKey( '3|licensio_icon_data', $this->written );
        $this->assertSame( 4, $this->written['3|licensio_order'] );
    }

    /**
     * The edit form shows a Base64 icon read-only and says it is replaced.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_edit_form_shows_a_base64_icon_read_only() {
        $png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

        Functions\when( 'wp_nonce_field' )->justReturn( '' );
        Functions\when( 'get_term_meta' )->alias(
            function ( $term_id, $key ) use ( $png ) {
                unset( $term_id );

                return 'licensio_icon_data' === $key ? $png : '';
            }
        );

        $fields = new Licensio_KB_Term_Fields();

        ob_start();
        $fields->render_edit_fields( (object) array( 'term_id' => 3 ) );
        $markup = (string) ob_get_clean();

        $this->assertStringContainsString( 'src="' . $png . '"', $markup );
        $this->assertStringContainsString( 'Setting an icon below replaces it.', $markup );
        $this->assertStringNotContainsString( 'name="licensio_icon_data"', $markup, 'Read-only: nothing posts it.' );
    }

    /**
     * A posted SVG is reduced by the allowlist before it is stored.
     *
     * The stored value is what the card prints unescaped, so the payload has to
     * die at the boundary and not merely on the way back out. Asserted on the
     * exact stored string: "does not contain script" would also pass if the
     * handler had thrown the whole icon away.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_posted_svg_is_sanitized_before_it_is_stored() {
        $_POST['licensio_icon_svg'] = '<svg viewBox="0 0 24 24" onload="alert(1)"><script>alert(2)</script><use href="javascript:alert(3)"/><path d="M4 4h16"/></svg>';

        $fields = new Licensio_KB_Term_Fields();
        $fields->save_fields( 3 );

        $this->assertSame(
            '<svg viewBox="0 0 24 24"><use/><path d="M4 4h16"/></svg>',
            $this->written['3|licensio_icon_svg']
        );
    }

    /**
     * The slashes WordPress adds to the request are removed, not stored.
     *
     * `$_POST` reaches a save handler magic-quoted, so a quoted attribute
     * arrives as `viewBox=\"…\"`. Storing that gives a value that parses as
     * nothing, which is how Heroic ended up calling `stripslashes()` at the
     * point of output — the exact place where undoing an escape is a
     * vulnerability rather than a fix.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_request_slashes_are_removed_before_sanitisation() {
        $_POST['licensio_icon_svg'] = '<svg viewBox=\"0 0 24 24\"><path d=\"M4 4h16\"/></svg>';

        $fields = new Licensio_KB_Term_Fields();
        $fields->save_fields( 3 );

        $this->assertSame(
            '<svg viewBox="0 0 24 24"><path d="M4 4h16"/></svg>',
            $this->written['3|licensio_icon_svg']
        );
    }

    /**
     * A backslash the user typed into the SVG is stored, not lost.
     *
     * The request is unslashed once, and `update_term_meta()` unslashes again,
     * so the value has to be slashed for that second pass.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_backslash_in_the_svg_survives_the_save() {
        $_POST['licensio_icon_svg'] = wp_slash( '<svg viewBox="0 0 24 24"><title>C:\\Temp</title></svg>' );

        $fields = new Licensio_KB_Term_Fields();
        $fields->save_fields( 3 );

        $this->assertSame(
            '<svg viewBox="0 0 24 24"><title>C:\\Temp</title></svg>',
            $this->written['3|licensio_icon_svg']
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
        $_POST['licensio_icon']     = 'dashicons-book" onload="alert(1)';
        $_POST['licensio_image_id'] = '7';
        $_POST['licensio_order']    = '-9';

        $fields = new Licensio_KB_Term_Fields();
        $fields->save_fields( 3 );

        $this->assertSame( '', $this->written['3|licensio_icon'] );
        $this->assertSame( 0, $this->written['3|licensio_image_id'], 'Post 7 is not an attachment.' );
        $this->assertSame( 0, $this->written['3|licensio_order'] );
    }

    /**
     * Nothing is written without a valid nonce.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_bad_nonce_writes_nothing() {
        $_POST['licensio_term_meta_nonce'] = 'forged';
        $_POST['licensio_order']           = '7';

        $fields = new Licensio_KB_Term_Fields();
        $fields->save_fields( 3 );

        $this->assertSame( array(), $this->written );
    }

    /**
     * Nothing is written when the nonce field is absent altogether.
     *
     * This is the case a term created by `wp_insert_term()` or by an importer
     * hits: `created_licensio_category` fires with no request behind it.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_request_with_no_nonce_writes_nothing() {
        unset( $_POST['licensio_term_meta_nonce'] );
        $_POST['licensio_order'] = '7';

        $fields = new Licensio_KB_Term_Fields();
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

        $_POST['licensio_order'] = '7';

        $fields = new Licensio_KB_Term_Fields();
        $fields->save_fields( 3 );

        $this->assertSame( array(), $this->written );
    }

    /**
     * A field that was not posted is left alone.
     *
     * The edit form posts all four, but a quick-edit of the term name — or any
     * other code path that reaches `edited_licensio_category` — posts none of
     * them, and writing sanitized defaults there would erase an icon and an
     * image nobody asked to change.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_absent_fields_are_not_overwritten() {
        $_POST['licensio_order'] = '2';

        $fields = new Licensio_KB_Term_Fields();
        $fields->save_fields( 3 );

        $this->assertSame( array( '3|licensio_order' => 2 ), $this->written );
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
        $fields = new Licensio_KB_Term_Fields();
        $fields->init_hooks();

        $this->assertNotFalse( has_action( 'licensio_category_add_form_fields', array( $fields, 'render_add_fields' ) ) );
        $this->assertNotFalse( has_action( 'licensio_category_edit_form_fields', array( $fields, 'render_edit_fields' ) ) );
        $this->assertNotFalse( has_action( 'created_licensio_category', array( $fields, 'save_fields' ) ) );
        $this->assertNotFalse( has_action( 'edited_licensio_category', array( $fields, 'save_fields' ) ) );
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

        $fields = new Licensio_KB_Term_Fields();
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
        $enqueued = $this->enqueue_for( 'edit-tags.php', new WP_Screen( 'licensio_category' ) );

        $this->assertTrue( $enqueued['media'] );
        $this->assertSame( array( 'licensio-kb-term-fields' ), $enqueued['scripts'] );

        $enqueued = $this->enqueue_for( 'term.php', new WP_Screen( 'licensio_category' ) );

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
        $enqueued = $this->enqueue_for( 'index.php', new WP_Screen( 'licensio_category' ) );

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

        $fields = new Licensio_KB_Term_Fields();

        ob_start();
        $fields->render_add_fields( 'licensio_category' );
        $markup = (string) ob_get_clean();

        $this->assertStringContainsString( 'name="licensio_term_meta_nonce"', $markup );
        $this->assertStringContainsString( 'name="licensio_icon"', $markup );
        $this->assertStringContainsString( 'name="licensio_icon_svg"', $markup );
        $this->assertStringContainsString( 'name="licensio_icon_id"', $markup );
        $this->assertStringContainsString( 'name="licensio_image_id"', $markup );
        $this->assertStringContainsString( 'name="licensio_order"', $markup );
    }

    /**
     * The icon fields are rendered in the order they take precedence.
     *
     * Nothing breaks if they are not, which is why it is asserted: the only
     * thing telling an author that pasting an SVG overrides the icon they
     * uploaded is reading the two fields in that order, with the descriptions
     * that say so.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_icon_fields_are_rendered_in_precedence_order() {
        Functions\when( 'wp_nonce_field' )->justReturn( '' );

        $fields = new Licensio_KB_Term_Fields();

        ob_start();
        $fields->render_add_fields( 'licensio_category' );
        $markup = (string) ob_get_clean();

        $svg  = strpos( $markup, 'name="licensio_icon_svg"' );
        $file = strpos( $markup, 'name="licensio_icon_id"' );
        $name = strpos( $markup, 'name="licensio_icon"' );

        $this->assertIsInt( $svg );
        $this->assertIsInt( $file );
        $this->assertIsInt( $name );
        $this->assertLessThan( $file, $svg, 'The SVG field wins, so it is read first.' );
        $this->assertLessThan( $name, $file, 'The uploaded icon beats the dashicon name.' );
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
                    'licensio_icon'     => 'dashicons-sos',
                    'licensio_icon_id'  => 42,
                    'licensio_image_id' => 0,
                    'licensio_order'    => 6,
                );

                return isset( $stored[ $key ] ) ? $stored[ $key ] : '';
            }
        );

        $fields = new Licensio_KB_Term_Fields();

        ob_start();
        $fields->render_edit_fields( (object) array( 'term_id' => 3 ) );
        $markup = (string) ob_get_clean();

        $this->assertStringContainsString( 'value="dashicons-sos"', $markup );
        $this->assertStringContainsString( 'value="42"', $markup );
        $this->assertStringContainsString( 'value="6"', $markup );
        $this->assertStringContainsString( '<img src="icon.svg"', $markup );
    }

    /**
     * The SVG field shows the markup in the textarea and the icon in a preview.
     *
     * Two renderings of the same value, and they have to differ: the textarea
     * is for reading and editing the source, and is escaped; the preview is the
     * drawing, and is not. Both halves are asserted, because escaping the
     * preview and escaping neither are equally wrong and a test that only
     * looked for `<svg` would pass on either.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_svg_field_shows_the_source_escaped_and_the_icon_drawn() {
        Functions\when( 'wp_nonce_field' )->justReturn( '' );
        Functions\when( 'esc_textarea' )->alias(
            function ( $value ) {
                return htmlspecialchars( (string) $value, ENT_QUOTES );
            }
        );
        Functions\when( 'get_term_meta' )->alias(
            function ( $term_id, $key ) {
                unset( $term_id );

                return 'licensio_icon_svg' === $key
                    ? '<svg viewBox="0 0 24 24"><path d="M4 4h16"/></svg>'
                    : '';
            }
        );

        $fields = new Licensio_KB_Term_Fields();

        ob_start();
        $fields->render_edit_fields( (object) array( 'term_id' => 3 ) );
        $markup = (string) ob_get_clean();

        $this->assertStringContainsString(
            '<textarea id="licensio_icon_svg" name="licensio_icon_svg" rows="6" class="large-text code" placeholder="Paste SVG markup here">&lt;svg viewBox=&quot;0 0 24 24&quot;&gt;&lt;path d=&quot;M4 4h16&quot;/&gt;&lt;/svg&gt;</textarea>',
            $markup,
            'The textarea must show the source, escaped.'
        );

        $this->assertStringContainsString(
            '<div class="licensio-kb-svg-preview" style="max-width:80px;"><svg viewBox="0 0 24 24"><path d="M4 4h16"/></svg></div>',
            $markup,
            'The preview must show the icon, not its source.'
        );
    }

    /**
     * An empty SVG field renders the textarea and no preview.
     *
     * An empty preview box on every category that has never had an icon is the
     * kind of thing that gets "fixed" by loosening something else.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_empty_svg_field_renders_no_preview() {
        Functions\when( 'wp_nonce_field' )->justReturn( '' );

        $fields = new Licensio_KB_Term_Fields();

        ob_start();
        $fields->render_add_fields( 'licensio_category' );
        $markup = (string) ob_get_clean();

        $this->assertStringContainsString( 'name="licensio_icon_svg"', $markup );
        $this->assertStringNotContainsString( 'licensio-kb-svg-preview', $markup );
    }
}
