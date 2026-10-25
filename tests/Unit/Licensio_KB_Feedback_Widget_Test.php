<?php
/**
 * Unit tests for the frontend voting widget.
 *
 * The widget's contract is that it attaches to the shared article footer hook,
 * appears only where it should, and renders markup a logged-out visitor and a
 * screen reader can both use. The template is rendered for real here rather
 * than asserted about in the abstract.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

use Brain\Monkey\Functions;

/**
 * Tests Licensio_KB_Feedback_Widget and templates/feedback-form.php.
 *
 * @since 1.0.0
 */
class Licensio_KB_Feedback_Widget_Test extends Licensio_KB_Test_Case {

    /**
     * The settings store the widget reads its gate from.
     *
     * @since 1.0.0
     * @var Licensio_KB_Settings
     */
    private $settings;

    /**
     * The widget under test.
     *
     * @since 1.0.0
     * @var Licensio_KB_Feedback_Widget
     */
    private $widget;

    /**
     * The post meta store backing the cached score.
     *
     * @since 1.0.0
     * @var array<string, mixed>
     */
    private $meta = array();

    /**
     * Stub the WordPress surface the widget and template touch.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        $this->meta = array();

        Functions\when( 'untrailingslashit' )->alias(
            function ( $value ) {
                return rtrim( (string) $value, '/\\' );
            }
        );
        Functions\when( 'wp_is_block_theme' )->justReturn( false );
        Functions\when( 'locate_template' )->justReturn( '' );
        Functions\when( 'do_blocks' )->returnArg();
        Functions\when( 'is_singular' )->justReturn( true );
        Functions\when( 'post_password_required' )->justReturn( false );
        Functions\when( 'rest_url' )->alias(
            function ( $path = '' ) {
                return 'https://example.test/wp-json/' . ltrim( (string) $path, '/' );
            }
        );
        Functions\when( 'wp_create_nonce' )->justReturn( 'a-nonce' );
        Functions\when( 'get_post_meta' )->alias(
            function ( $post_id, $key, $single = false ) {
                unset( $single );

                $index = (int) $post_id . '|' . (string) $key;

                return array_key_exists( $index, $this->meta ) ? $this->meta[ $index ] : '';
            }
        );
        Functions\when( 'get_post' )->alias(
            function ( $post ) {
                return $post;
            }
        );
        Functions\when( '_n' )->alias(
            function ( $single, $plural, $number ) {
                return 1 === (int) $number ? $single : $plural;
            }
        );

        $this->settings = new Licensio_KB_Settings();
        $this->widget   = new Licensio_KB_Feedback_Widget( new Licensio_KB_Feedback( $this->settings ) );
    }

    /**
     * Store a cached usefulness figure for an article.
     *
     * @since 1.0.0
     *
     * @param int    $post_id The article.
     * @param string $key     The meta key.
     * @param mixed  $value   The value.
     *
     * @return void
     */
    private function set_meta( $post_id, $key, $value ) {
        $this->meta[ (int) $post_id . '|' . (string) $key ] = $value;
    }

    /**
     * Render the widget for an article and return the markup.
     *
     * @since 1.0.0
     *
     * @param int $post_id The article.
     *
     * @return string The rendered markup.
     */
    private function render( $post_id ) {
        $post = new WP_Post(
            array(
                'ID'        => $post_id,
                'post_type' => Licensio_KB_Post_Types::POST_TYPE,
            )
        );

        ob_start();

        $this->widget->render( $post );

        return (string) ob_get_clean();
    }

    /**
     * The widget attaches to the shared article footer hook.
     *
     * Attaching here rather than to a template-specific hook is what makes the
     * widget work identically under a classic theme and a block theme.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_widget_attaches_to_the_article_footer_hook() {
        $this->widget->init_hooks();

        $this->assertSame( 10, has_action( 'licensio_article_footer', array( $this->widget, 'render' ) ) );
        $this->assertSame( 10, has_action( 'wp_enqueue_scripts', array( $this->widget, 'enqueue_assets' ) ) );
    }

    /**
     * The rendered markup carries the two votes and the article it belongs to.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_rendered_markup_offers_both_votes() {
        $markup = $this->render( 42 );

        $this->assertStringContainsString( 'data-licensio-kb-feedback', $markup );
        $this->assertStringContainsString( 'data-article-id="42"', $markup );
        $this->assertStringContainsString( 'data-licensio-kb-vote="1"', $markup );
        $this->assertStringContainsString( 'data-licensio-kb-vote="-1"', $markup );
    }

    /**
     * The comment field ships hidden, ready for the script to reveal.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_comment_field_ships_hidden() {
        $markup = $this->render( 42 );

        $this->assertStringContainsString( 'data-licensio-kb-feedback-comment', $markup );
        $this->assertStringContainsString( 'data-licensio-kb-feedback-textarea', $markup );
        $this->assertMatchesRegularExpression( '/data-licensio-kb-feedback-comment\s+hidden/', $markup );
    }

    /**
     * The outcome of a vote has somewhere to be announced.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_markup_carries_a_polite_live_region() {
        $markup = $this->render( 42 );

        $this->assertStringContainsString( 'role="status"', $markup );
        $this->assertStringContainsString( 'aria-live="polite"', $markup );
    }

    /**
     * An article with votes shows its score; one without shows nothing.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_summary_appears_only_once_there_are_votes() {
        $this->assertStringNotContainsString( 'licensio-kb-feedback__summary', $this->render( 42 ) );

        $this->set_meta( 42, Licensio_KB_Feedback::META_SCORE, 83 );
        $this->set_meta( 42, Licensio_KB_Feedback::META_UP, 10 );
        $this->set_meta( 42, Licensio_KB_Feedback::META_DOWN, 2 );

        $markup = $this->render( 42 );

        $this->assertStringContainsString( 'licensio-kb-feedback__summary', $markup );
        $this->assertStringContainsString( '83% of 12 readers found this helpful.', $markup );
    }

    /**
     * Nothing renders when feedback is turned off.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_nothing_renders_when_feedback_is_disabled() {
        $this->settings->set( 'enable_feedback', false );

        $this->assertSame( '', $this->render( 42 ) );
        $this->assertFalse( $this->widget->should_render() );
    }

    /**
     * Nothing renders for a post that is not a knowledge base article.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_nothing_renders_for_a_foreign_post_type() {
        ob_start();

        $this->widget->render(
            new WP_Post(
                array(
                    'ID'        => 9,
                    'post_type' => 'post',
                )
            )
        );

        $this->assertSame( '', (string) ob_get_clean() );
    }

    /**
     * A protected article the visitor has not unlocked gets no widget.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_nothing_renders_on_a_locked_protected_article() {
        Functions\when( 'post_password_required' )->justReturn( true );

        $this->assertSame( '', $this->render( 42 ) );
        $this->assertFalse( $this->widget->should_render() );
    }

    /**
     * The widget is off any view that is not a single article.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_widget_is_absent_from_other_views() {
        Functions\when( 'is_singular' )->justReturn( false );

        $this->assertFalse( $this->widget->should_render() );
    }

    /**
     * The script is handed the endpoint and a nonce.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_script_is_handed_the_endpoint_and_a_nonce() {
        $data = $this->widget->script_data();

        $this->assertSame( 'https://example.test/wp-json/licensio-kb/v1/feedback', $data['endpoint'] );
        $this->assertSame( 'a-nonce', $data['nonce'] );
        $this->assertArrayHasKey( 'alreadyDone', $data['i18n'] );
    }
}
