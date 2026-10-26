<?php
/**
 * Category term meta form fields.
 *
 * Renders the icon, image and manual order fields on the `licensio_category`
 * add and edit forms, and saves them. The registration, sanitisation and read
 * accessors for those keys live in Licensio_KB_Term_Meta; this class is only
 * the admin surface over them.
 *
 * Three of the fields are icons and only one of them is drawn. The precedence
 * is inline SVG, then the uploaded icon, then the dashicon name — set out in
 * full on Licensio_KB_Term_Meta. Silent precedence is a support ticket waiting
 * to happen, so each field's description says on screen what it replaces, and
 * the fields are rendered in precedence order so the one that wins is the one
 * read first.
 *
 * Saving keeps one icon source, as the Categories app does: the icon that
 * wins is stored and every other icon field is cleared, including the inline
 * Base64 image the app can set and this form cannot. When the form posts no
 * icon at all, the stored icons are left alone, so saving the order of a
 * category with a Base64 icon does not lose it; the edit form shows that icon
 * read-only, with a note that setting another one here replaces it.
 *
 * @package Licensio_KB
 * @since   1.0.0 Added the inline SVG icon field.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Adds the knowledge base category fields to the term forms.
 *
 * @since 1.0.0
 */
class Licensio_KB_Term_Fields {

    /**
     * Name of the nonce field posted with the term forms.
     *
     * @since 1.0.0
     * @var string
     */
    const NONCE_FIELD = 'licensio_term_meta_nonce';

    /**
     * Nonce action for the term forms.
     *
     * @since 1.0.0
     * @var string
     */
    const NONCE_ACTION = 'licensio_save_term_meta';

    /**
     * Handle the media picker script is registered under.
     *
     * @since 1.0.0
     * @var string
     */
    const SCRIPT_HANDLE = 'licensio-kb-term-fields';

    /**
     * Register the WordPress hooks.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function init_hooks() {
        $taxonomy = Licensio_KB_Post_Types::TAXONOMY_CATEGORY;

        add_action( $taxonomy . '_add_form_fields', array( $this, 'render_add_fields' ) );
        add_action( $taxonomy . '_edit_form_fields', array( $this, 'render_edit_fields' ) );
        add_action( 'created_' . $taxonomy, array( $this, 'save_fields' ) );
        add_action( 'edited_' . $taxonomy, array( $this, 'save_fields' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
    }

    /**
     * Load the media library and the picker script on the category screens.
     *
     * @since 1.0.0
     *
     * @param string $hook_suffix The current admin page.
     *
     * @return void
     */
    public function enqueue_assets( $hook_suffix ) {
        if ( ! in_array( $hook_suffix, array( 'edit-tags.php', 'term.php' ), true ) ) {
            return;
        }

        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

        if ( ! $screen instanceof WP_Screen || Licensio_KB_Post_Types::TAXONOMY_CATEGORY !== $screen->taxonomy ) {
            return;
        }

        wp_enqueue_media();

        wp_enqueue_script(
            self::SCRIPT_HANDLE,
            LICENSIO_KB_ASSETS_URL . 'js/licensio-kb-term-fields.js',
            array( 'jquery' ),
            LICENSIO_KB_VERSION,
            true
        );

        wp_localize_script(
            self::SCRIPT_HANDLE,
            'licensioKbTermFields',
            array(
                'title'  => __( 'Select an image', 'licensio-knowledge-base' ),
                'button' => __( 'Use this image', 'licensio-knowledge-base' ),
            )
        );
    }

    /**
     * Render the fields on the "add new category" form.
     *
     * @since 1.0.0 Added the inline SVG icon field.
     *
     * @param string $taxonomy The taxonomy the form belongs to.
     *
     * @return void
     */
    public function render_add_fields( $taxonomy ) {
        unset( $taxonomy );

        wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

        $this->render_field_wrapper( 'icon_svg', '', 'div' );
        $this->render_field_wrapper( 'icon_id', 0, 'div' );
        $this->render_field_wrapper( 'icon', '', 'div' );
        $this->render_field_wrapper( 'image_id', 0, 'div' );
        $this->render_field_wrapper( 'order', 0, 'div' );
    }

    /**
     * Render the fields on the "edit category" form.
     *
     * @since 1.0.0 Added the inline SVG icon field.
     * @since 1.0.0 Shows an inline Base64 icon read-only.
     *
     * @param WP_Term|object $term The term being edited.
     *
     * @return void
     */
    public function render_edit_fields( $term ) {
        $term_id = isset( $term->term_id ) ? (int) $term->term_id : 0;

        wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

        $this->render_icon_data_row( Licensio_KB_Term_Meta::get_icon_data( $term_id ) );
        $this->render_field_wrapper( 'icon_svg', Licensio_KB_Term_Meta::get_icon_svg( $term_id ), 'tr' );
        $this->render_field_wrapper( 'icon_id', Licensio_KB_Term_Meta::get_icon_id( $term_id ), 'tr' );
        $this->render_field_wrapper( 'icon', Licensio_KB_Term_Meta::get_icon( $term_id ), 'tr' );
        $this->render_field_wrapper( 'image_id', Licensio_KB_Term_Meta::get_image_id( $term_id ), 'tr' );
        $this->render_field_wrapper( 'order', Licensio_KB_Term_Meta::get_order( $term_id ), 'tr' );
    }

    /**
     * Show a stored inline Base64 icon, read-only, on the edit form.
     *
     * This form has no control for a Base64 icon; the Categories app sets it.
     * Showing it says which icon the category uses, and that setting another
     * icon here replaces it. The source is printed with `esc_attr()` because
     * `esc_url()` drops `data:` URLs; Licensio_KB_Term_Meta::get_icon_data()
     * rebuilds the value from bytes that decoded as an allowed image.
     *
     * @since 1.0.0
     *
     * @param string $data The checked `data:` URL, or an empty string.
     *
     * @return void
     */
    private function render_icon_data_row( $data ) {
        if ( '' === $data ) {
            return;
        }

        printf(
            '<tr class="form-field"><th scope="row">%1$s</th><td><img class="licensio-kb-media-preview" src="%2$s" alt="" width="48" height="48" /><p class="description">%3$s</p></td></tr>',
            esc_html__( 'Base64 image icon', 'licensio-knowledge-base' ),
            esc_attr( $data ),
            esc_html__( 'Set in the Categories screen, which can change it. Setting an icon below replaces it.', 'licensio-knowledge-base' )
        );
    }

    /**
     * Render one field inside the wrapper the current form expects.
     *
     * The add form stacks `div.form-field` blocks and the edit form is a table
     * of `tr.form-field` rows. Only the wrapper differs, so the field markup
     * itself is written once.
     *
     * @since 1.0.0
     *
     * @param string     $field   One of `icon_svg`, `icon`, `icon_id`, `image_id` or `order`.
     * @param string|int $value   The current value.
     * @param string     $wrapper Either `div` for the add form or `tr` for the edit form.
     *
     * @return void
     */
    private function render_field_wrapper( $field, $value, $wrapper ) {
        $labels = array(
            'icon_svg' => __( 'Inline SVG icon', 'licensio-knowledge-base' ),
            'icon'     => __( 'Icon', 'licensio-knowledge-base' ),
            'icon_id'  => __( 'Uploaded icon', 'licensio-knowledge-base' ),
            'image_id' => __( 'Image', 'licensio-knowledge-base' ),
            'order'    => __( 'Order', 'licensio-knowledge-base' ),
        );

        $label = isset( $labels[ $field ] ) ? $labels[ $field ] : $field;
        $name  = $this->field_name( $field );

        if ( 'tr' === $wrapper ) {
            echo '<tr class="form-field">';
            printf( '<th scope="row"><label for="%1$s">%2$s</label></th><td>', esc_attr( $name ), esc_html( $label ) );
        } else {
            echo '<div class="form-field">';
            printf( '<label for="%1$s">%2$s</label>', esc_attr( $name ), esc_html( $label ) );
        }

        $this->render_field( $field, $value );

        echo 'tr' === $wrapper ? '</td></tr>' : '</div>';
    }

    /**
     * The form input name for a field.
     *
     * @since 1.0.0
     *
     * @param string $field One of `icon_svg`, `icon`, `icon_id`, `image_id` or `order`.
     *
     * @return string The input name.
     */
    private function field_name( $field ) {
        return 'licensio_' . $field;
    }

    /**
     * Render the control and help text for one field.
     *
     * @since 1.0.0 Added the inline SVG icon field.
     *
     * @param string     $field One of `icon_svg`, `icon`, `icon_id`, `image_id` or `order`.
     * @param string|int $value The current value.
     *
     * @return void
     */
    private function render_field( $field, $value ) {
        $name = $this->field_name( $field );

        switch ( $field ) {
            case 'icon_svg':
                $this->render_svg_field( $name, (string) $value );
                printf(
                    '<p class="description">%s</p>',
                    esc_html__( 'Paste an SVG icon. Everything outside a small allowlist of shape elements and presentation attributes is removed on save, so the preview shows what was actually kept. Saving one replaces the uploaded icon and the Dashicons name. An icon drawn with currentColor follows the knowledge base colour.', 'licensio-knowledge-base' )
                );
                break;

            case 'icon':
                printf(
                    '<input type="text" id="%1$s" name="%1$s" value="%2$s" class="regular-text" placeholder="dashicons-book-alt" />',
                    esc_attr( $name ),
                    esc_attr( (string) $value )
                );
                printf(
                    '<p class="description">%s</p>',
                    esc_html__( 'A Dashicons name, for example dashicons-book-alt. Kept only when no SVG icon or uploaded icon is set.', 'licensio-knowledge-base' )
                );
                break;

            case 'icon_id':
            case 'image_id':
                $this->render_media_field( $name, (int) $value );

                if ( 'icon_id' === $field ) {
                    printf(
                        '<p class="description">%s</p>',
                        esc_html__( 'An uploaded icon, for example an SVG file. Replaces the Dashicons name on save, and is itself replaced when an SVG icon is pasted above.', 'licensio-knowledge-base' )
                    );
                } else {
                    printf(
                        '<p class="description">%s</p>',
                        esc_html__( 'Shown on the category card and at the top of the category page.', 'licensio-knowledge-base' )
                    );
                }
                break;

            case 'order':
            default:
                printf(
                    '<input type="number" id="%1$s" name="%1$s" value="%2$d" min="0" step="1" class="small-text" />',
                    esc_attr( $name ),
                    (int) $value
                );
                printf(
                    '<p class="description">%s</p>',
                    esc_html__( 'Lower numbers appear first. Categories with the same order fall back to their name.', 'licensio-knowledge-base' )
                );
                break;
        }
    }

    /**
     * Render the inline SVG textarea and its preview.
     *
     * Two renderings of one value, escaped differently on purpose. The textarea
     * shows the markup, so it is escaped with `esc_textarea()` and the author
     * reads angle brackets. The preview shows the icon, so it is printed
     * unescaped and the browser draws it.
     *
     * Printing it unescaped is safe because of where the value came from, not
     * because of anything this method does: every route into the meta key runs
     * Licensio_KB_SVG_Sanitizer::sanitize(), and Licensio_KB_Term_Meta runs it
     * again on the way back out, so `$value` here is markup an allowlist of
     * element and attribute names has already reduced — no `script`, no
     * `foreignObject`, no `on*` handler, no `href` that is not a fragment. See
     * that class for the list. Escaping it instead would print the source of
     * the icon into the page where the icon should be, which is the failure
     * this comment exists to stop a reviewer from "fixing".
     *
     * @since 1.0.0
     *
     * @param string $name  The input name.
     * @param string $value The sanitized SVG markup.
     *
     * @return void
     */
    private function render_svg_field( $name, $value ) {
        printf(
            '<textarea id="%1$s" name="%1$s" rows="6" class="large-text code" placeholder="%2$s">%3$s</textarea>',
            esc_attr( $name ),
            esc_attr__( 'Paste SVG markup here', 'licensio-knowledge-base' ),
            esc_textarea( $value )
        );

        if ( '' === $value ) {
            return;
        }

        echo '<div class="licensio-kb-svg-preview" style="max-width:80px;">';
        echo $value; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Allowlist-sanitized SVG; see the docblock above.
        echo '</div>';
    }

    /**
     * Render a media picker: a hidden ID, a preview and the two buttons.
     *
     * @since 1.0.0
     *
     * @param string $name          The input name.
     * @param int    $attachment_id The currently selected attachment, or 0.
     *
     * @return void
     */
    private function render_media_field( $name, $attachment_id ) {
        $preview = '';

        if ( $attachment_id > 0 ) {
            $preview = wp_get_attachment_image( $attachment_id, 'thumbnail', false, array( 'style' => 'max-width:80px;height:auto;' ) );
        }

        /*
         * The remove button is hidden with wp-admin's own `hidden` class rather
         * than the `hidden` attribute: `.button-link` is an author-stylesheet
         * rule and would override the user-agent rule behind the attribute,
         * leaving a Remove button on a field with nothing to remove.
         */
        printf(
            '<div class="licensio-kb-media-field" data-licensio-media-field="1">
                <input type="hidden" id="%1$s" name="%1$s" value="%2$d" class="licensio-kb-media-id" />
                <div class="licensio-kb-media-preview">%3$s</div>
                <button type="button" class="button licensio-kb-media-select">%4$s</button>
                <button type="button" class="button-link licensio-kb-media-remove%6$s">%5$s</button>
            </div>',
            esc_attr( $name ),
            (int) $attachment_id,
            wp_kses_post( (string) $preview ),
            esc_html__( 'Select', 'licensio-knowledge-base' ),
            esc_html__( 'Remove', 'licensio-knowledge-base' ),
            $attachment_id > 0 ? '' : ' hidden'
        );
    }

    /**
     * Save the posted fields onto the term.
     *
     * Only keys actually present in the request are written. A term created or
     * edited by something other than these forms — `wp_insert_term()`, a REST
     * write, an importer — posts none of them, and writing defaults for the
     * absent ones would erase metadata that call had no intention of touching.
     *
     * One icon source is kept: when the posted icons name one, the one that
     * wins by precedence is stored and every other icon field is cleared,
     * the inline Base64 image included. When they name none, the stored
     * icons are left as they are.
     *
     * @since 1.0.0 Added the inline SVG icon field. Slashes the values so backslashes survive.
     * @since 1.0.0 Keeps one icon source and clears the others.
     *
     * @param int $term_id The term that was created or edited.
     *
     * @return void
     */
    public function save_fields( $term_id ) {
        if ( ! Licensio_KB_Capabilities::current_user_can_manage() ) {
            return;
        }

        $nonce = isset( $_POST[ self::NONCE_FIELD ] )
            ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) )
            : '';

        if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
            return;
        }

        $fields = array(
            'licensio_icon'     => array( Licensio_KB_Term_Meta::META_ICON, array( 'Licensio_KB_Term_Meta', 'sanitize_icon' ) ),
            'licensio_icon_svg' => array( Licensio_KB_Term_Meta::META_ICON_SVG, array( 'Licensio_KB_Term_Meta', 'sanitize_icon_svg' ) ),
            'licensio_icon_id'  => array( Licensio_KB_Term_Meta::META_ICON_ID, array( 'Licensio_KB_Term_Meta', 'sanitize_attachment_id' ) ),
            'licensio_image_id' => array( Licensio_KB_Term_Meta::META_IMAGE_ID, array( 'Licensio_KB_Term_Meta', 'sanitize_attachment_id' ) ),
            'licensio_order'    => array( Licensio_KB_Term_Meta::META_ORDER, array( 'Licensio_KB_Term_Meta', 'sanitize_order' ) ),
        );

        // Sanitised values by meta key.
        $values = array();

        foreach ( $fields as $input => $field ) {
            if ( ! isset( $_POST[ $input ] ) ) {
                continue;
            }

            $raw                 = wp_unslash( $_POST[ $input ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by the callback below.
            $values[ $field[0] ] = call_user_func( $field[1], $raw );
        }

        $winner = self::winning_icon( $values );

        if ( '' !== $winner ) {
            $cleared = array(
                Licensio_KB_Term_Meta::META_ICON      => '',
                Licensio_KB_Term_Meta::META_ICON_SVG  => '',
                Licensio_KB_Term_Meta::META_ICON_ID   => 0,
                Licensio_KB_Term_Meta::META_ICON_DATA => '',
            );

            unset( $cleared[ $winner ] );
            $values = array_merge( $values, $cleared );
        }

        foreach ( $values as $meta_key => $value ) {
            // update_term_meta() unslashes the value, and it was unslashed above already.
            update_term_meta( (int) $term_id, $meta_key, wp_slash( $value ) );
        }
    }

    /**
     * The posted icon field that wins, by the front end's precedence.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $values The sanitised posted values, by meta key.
     *
     * @return string The meta key of the inline SVG, uploaded icon or Dashicon, or an empty string when no icon was posted.
     */
    private static function winning_icon( array $values ) {
        foreach ( array( Licensio_KB_Term_Meta::META_ICON_SVG, Licensio_KB_Term_Meta::META_ICON_ID, Licensio_KB_Term_Meta::META_ICON ) as $meta_key ) {
            if ( ! empty( $values[ $meta_key ] ) ) {
                return $meta_key;
            }
        }

        return '';
    }
}
