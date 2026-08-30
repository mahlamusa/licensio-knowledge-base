<?php
/**
 * Category term meta form fields.
 *
 * Renders the icon, image and manual order fields on the `lisensio_category`
 * add and edit forms, and saves them. The registration, sanitisation and read
 * accessors for those keys live in Lisensio_KB_Term_Meta; this class is only
 * the admin surface over them.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Adds the knowledge base category fields to the term forms.
 *
 * @since 1.0.0
 */
class Lisensio_KB_Term_Fields {

    /**
     * Name of the nonce field posted with the term forms.
     *
     * @since 1.0.0
     * @var string
     */
    const NONCE_FIELD = 'lisensio_term_meta_nonce';

    /**
     * Nonce action for the term forms.
     *
     * @since 1.0.0
     * @var string
     */
    const NONCE_ACTION = 'lisensio_save_term_meta';

    /**
     * Handle the media picker script is registered under.
     *
     * @since 1.0.0
     * @var string
     */
    const SCRIPT_HANDLE = 'lisensio-kb-term-fields';

    /**
     * Register the WordPress hooks.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function init_hooks() {
        $taxonomy = Lisensio_KB_Post_Types::TAXONOMY_CATEGORY;

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

        if ( ! $screen instanceof WP_Screen || Lisensio_KB_Post_Types::TAXONOMY_CATEGORY !== $screen->taxonomy ) {
            return;
        }

        wp_enqueue_media();

        wp_enqueue_script(
            self::SCRIPT_HANDLE,
            LISENSIO_KB_ASSETS_URL . 'js/lisensio-kb-term-fields.js',
            array( 'jquery' ),
            LISENSIO_KB_VERSION,
            true
        );

        wp_localize_script(
            self::SCRIPT_HANDLE,
            'lisensioKbTermFields',
            array(
                'title'  => __( 'Select an image', 'lisensio-knowledge-base' ),
                'button' => __( 'Use this image', 'lisensio-knowledge-base' ),
            )
        );
    }

    /**
     * Render the fields on the "add new category" form.
     *
     * @since 1.0.0
     *
     * @param string $taxonomy The taxonomy the form belongs to.
     *
     * @return void
     */
    public function render_add_fields( $taxonomy ) {
        unset( $taxonomy );

        wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

        $this->render_field_wrapper( 'icon', '', 'div' );
        $this->render_field_wrapper( 'icon_id', 0, 'div' );
        $this->render_field_wrapper( 'image_id', 0, 'div' );
        $this->render_field_wrapper( 'order', 0, 'div' );
    }

    /**
     * Render the fields on the "edit category" form.
     *
     * @since 1.0.0
     *
     * @param WP_Term|object $term The term being edited.
     *
     * @return void
     */
    public function render_edit_fields( $term ) {
        $term_id = isset( $term->term_id ) ? (int) $term->term_id : 0;

        wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

        $this->render_field_wrapper( 'icon', Lisensio_KB_Term_Meta::get_icon( $term_id ), 'tr' );
        $this->render_field_wrapper( 'icon_id', Lisensio_KB_Term_Meta::get_icon_id( $term_id ), 'tr' );
        $this->render_field_wrapper( 'image_id', Lisensio_KB_Term_Meta::get_image_id( $term_id ), 'tr' );
        $this->render_field_wrapper( 'order', Lisensio_KB_Term_Meta::get_order( $term_id ), 'tr' );
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
     * @param string     $field   One of `icon`, `icon_id`, `image_id` or `order`.
     * @param string|int $value   The current value.
     * @param string     $wrapper Either `div` for the add form or `tr` for the edit form.
     *
     * @return void
     */
    private function render_field_wrapper( $field, $value, $wrapper ) {
        $labels = array(
            'icon'     => __( 'Icon', 'lisensio-knowledge-base' ),
            'icon_id'  => __( 'Uploaded icon', 'lisensio-knowledge-base' ),
            'image_id' => __( 'Image', 'lisensio-knowledge-base' ),
            'order'    => __( 'Order', 'lisensio-knowledge-base' ),
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
     * @param string $field One of `icon`, `icon_id`, `image_id` or `order`.
     *
     * @return string The input name.
     */
    private function field_name( $field ) {
        return 'lisensio_' . $field;
    }

    /**
     * Render the control and help text for one field.
     *
     * @since 1.0.0
     *
     * @param string     $field One of `icon`, `icon_id`, `image_id` or `order`.
     * @param string|int $value The current value.
     *
     * @return void
     */
    private function render_field( $field, $value ) {
        $name = $this->field_name( $field );

        switch ( $field ) {
            case 'icon':
                printf(
                    '<input type="text" id="%1$s" name="%1$s" value="%2$s" class="regular-text" placeholder="dashicons-book-alt" />',
                    esc_attr( $name ),
                    esc_attr( (string) $value )
                );
                printf(
                    '<p class="description">%s</p>',
                    esc_html__( 'A Dashicons name, for example dashicons-book-alt. Ignored when an icon is uploaded below.', 'lisensio-knowledge-base' )
                );
                break;

            case 'icon_id':
            case 'image_id':
                $this->render_media_field( $name, (int) $value );

                if ( 'icon_id' === $field ) {
                    printf(
                        '<p class="description">%s</p>',
                        esc_html__( 'An uploaded icon, for example an SVG. Takes precedence over the Dashicons name.', 'lisensio-knowledge-base' )
                    );
                } else {
                    printf(
                        '<p class="description">%s</p>',
                        esc_html__( 'Shown on the category card and at the top of the category page.', 'lisensio-knowledge-base' )
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
                    esc_html__( 'Lower numbers appear first. Categories with the same order fall back to their name.', 'lisensio-knowledge-base' )
                );
                break;
        }
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
            '<div class="lisensio-kb-media-field" data-lisensio-media-field="1">
                <input type="hidden" id="%1$s" name="%1$s" value="%2$d" class="lisensio-kb-media-id" />
                <div class="lisensio-kb-media-preview">%3$s</div>
                <button type="button" class="button lisensio-kb-media-select">%4$s</button>
                <button type="button" class="button-link lisensio-kb-media-remove%6$s">%5$s</button>
            </div>',
            esc_attr( $name ),
            (int) $attachment_id,
            wp_kses_post( (string) $preview ),
            esc_html__( 'Select', 'lisensio-knowledge-base' ),
            esc_html__( 'Remove', 'lisensio-knowledge-base' ),
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
     * @since 1.0.0
     *
     * @param int $term_id The term that was created or edited.
     *
     * @return void
     */
    public function save_fields( $term_id ) {
        if ( ! Lisensio_KB_Capabilities::current_user_can_manage() ) {
            return;
        }

        $nonce = isset( $_POST[ self::NONCE_FIELD ] )
            ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) )
            : '';

        if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
            return;
        }

        $fields = array(
            'lisensio_icon'     => array( Lisensio_KB_Term_Meta::META_ICON, array( 'Lisensio_KB_Term_Meta', 'sanitize_icon' ) ),
            'lisensio_icon_id'  => array( Lisensio_KB_Term_Meta::META_ICON_ID, array( 'Lisensio_KB_Term_Meta', 'sanitize_attachment_id' ) ),
            'lisensio_image_id' => array( Lisensio_KB_Term_Meta::META_IMAGE_ID, array( 'Lisensio_KB_Term_Meta', 'sanitize_attachment_id' ) ),
            'lisensio_order'    => array( Lisensio_KB_Term_Meta::META_ORDER, array( 'Lisensio_KB_Term_Meta', 'sanitize_order' ) ),
        );

        foreach ( $fields as $input => $field ) {
            if ( ! isset( $_POST[ $input ] ) ) {
                continue;
            }

            $raw   = wp_unslash( $_POST[ $input ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by the callback below.
            $value = call_user_func( $field[1], $raw );

            update_term_meta( (int) $term_id, $field[0], $value );
        }
    }
}
