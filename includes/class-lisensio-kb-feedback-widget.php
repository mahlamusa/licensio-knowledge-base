<?php
/**
 * Frontend voting widget.
 *
 * Attaches the "was this helpful?" control to `lisensio_article_footer`, the
 * hook both the classic template layer (#5) and the block template layer (#6)
 * fire, so the widget is written once and appears under either theme
 * paradigm.
 *
 * The markup is rendered server side by `templates/feedback-form.php` and is
 * overridable by a theme like any other knowledge base template.
 * `assets/js/feedback.js` enhances it: it reveals the comment field on a
 * negative vote, posts to the REST endpoint and announces the outcome in a
 * live region. Nothing here depends on the visitor being logged in.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders the voting UI and enqueues its script.
 *
 * @since 1.0.0
 */
class Lisensio_KB_Feedback_Widget {

    /**
     * The script handle the voting behaviour is registered under.
     *
     * @since 1.0.0
     * @var string
     */
    const SCRIPT_HANDLE = 'lisensio-kb-feedback';

    /**
     * The stylesheet handle the widget's styles are registered under.
     *
     * @since 1.0.0
     * @var string
     */
    const STYLE_HANDLE = 'lisensio-kb-feedback';

    /**
     * The feedback store.
     *
     * @since 1.0.0
     * @var Lisensio_KB_Feedback
     */
    private $feedback;

    /**
     * Constructor.
     *
     * The `enable_feedback` setting is read through the feedback component
     * rather than from a settings store of its own, so the widget and the REST
     * endpoint can never disagree about whether voting is on.
     *
     * @since 1.0.0
     *
     * @param Lisensio_KB_Feedback $feedback The feedback store.
     */
    public function __construct( Lisensio_KB_Feedback $feedback ) {
        $this->feedback = $feedback;
    }

    /**
     * Register the WordPress hooks.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function init_hooks() {
        add_action( 'lisensio_article_footer', array( $this, 'render' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
    }

    /**
     * Register and conditionally enqueue the widget's script and styles.
     *
     * Registered unconditionally so a consumer can depend on the handle;
     * enqueued only on a single article, so a site pays nothing for the widget
     * on any other view.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function enqueue_assets() {
        wp_register_style(
            self::STYLE_HANDLE,
            LISENSIO_KB_ASSETS_URL . 'css/feedback.css',
            array(),
            LISENSIO_KB_VERSION
        );

        wp_register_script(
            self::SCRIPT_HANDLE,
            LISENSIO_KB_ASSETS_URL . 'js/feedback.js',
            array( Lisensio_KB_Assets::FRONTEND_NAMESPACE_HANDLE ),
            LISENSIO_KB_VERSION,
            true
        );

        if ( ! $this->should_render() ) {
            return;
        }

        wp_enqueue_style( self::STYLE_HANDLE );
        wp_enqueue_script( self::SCRIPT_HANDLE );

        wp_localize_script( self::SCRIPT_HANDLE, 'lisensioKBFeedbackData', $this->script_data() );
    }

    /**
     * The configuration the frontend script needs.
     *
     * The nonce is a `wp_rest` nonce, which is what the REST permission
     * callback verifies. For a logged-out visitor it is not a per-visitor
     * secret — the endpoint's real protections are the per-visitor rate limit
     * and the server-side one-vote-per-visitor rule.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed> The localised configuration.
     */
    public function script_data() {
        return array(
            'endpoint' => Lisensio_KB_REST_API::url( 'feedback' ),
            'nonce'    => wp_create_nonce( 'wp_rest' ),
            'i18n'     => array(
                'thanks'      => __( 'Thanks for your feedback.', 'lisensio-knowledge-base' ),
                'thanksSorry' => __( 'Thanks — your feedback helps us improve this article.', 'lisensio-knowledge-base' ),
                'sending'     => __( 'Sending your feedback…', 'lisensio-knowledge-base' ),
                'error'       => __( 'Your feedback could not be saved. Please try again.', 'lisensio-knowledge-base' ),
                'alreadyDone' => __( 'You have already given feedback on this article.', 'lisensio-knowledge-base' ),
            ),
        );
    }

    /**
     * Whether the widget belongs on this request.
     *
     * @since 1.0.0
     *
     * @return bool True on a single article when feedback is enabled.
     */
    public function should_render() {
        if ( ! $this->feedback->is_enabled() ) {
            return false;
        }

        return is_singular( Lisensio_KB_Post_Types::POST_TYPE );
    }

    /**
     * Render the voting widget.
     *
     * @since 1.0.0
     *
     * @param WP_Post|null $post The article being rendered.
     *
     * @return void
     */
    public function render( $post = null ) {
        if ( ! $this->feedback->is_enabled() ) {
            return;
        }

        $post = get_post( $post );

        if ( ! $post instanceof WP_Post || Lisensio_KB_Post_Types::POST_TYPE !== $post->post_type ) {
            return;
        }

        lisensio_kb_get_template(
            'feedback-form',
            array(
                'article_id' => (int) $post->ID,
                'score'      => Lisensio_KB_Feedback::get_score( (int) $post->ID ),
            )
        );
    }
}
