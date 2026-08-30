<?php
/**
 * Unit tests for the search block and shortcode.
 *
 * The markup is where the ARIA combobox contract lives. `aria-expanded`,
 * `aria-controls` and `role="option"` are acceptance criteria for this issue,
 * and the script can only maintain them if the server emitted the right
 * skeleton with matching IDs in the first place.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

/**
 * Tests the rendered search form, its attributes and its configuration.
 *
 * @since 1.0.0
 */
class Lisensio_KB_Search_Block_Test extends Lisensio_KB_Test_Case {

    /**
     * The block renderer under test.
     *
     * @since 1.0.0
     * @var Lisensio_KB_Search_Block
     */
    private $block;

    /**
     * Scripts enqueued during the test.
     *
     * @since 1.0.0
     * @var string[]
     */
    private $enqueued = array();

    /**
     * Inline scripts added during the test.
     *
     * @since 1.0.0
     * @var string[]
     */
    private $inline = array();

    /**
     * Stub the rendering surface.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        $this->block    = new Lisensio_KB_Search_Block();
        $this->enqueued = array();
        $this->inline   = array();

        Functions\when( 'esc_attr' )->alias(
            function ( $value ) {
                return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
            }
        );
        Functions\when( 'esc_html' )->alias(
            function ( $value ) {
                return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
            }
        );
        Functions\when( 'esc_url' )->alias(
            function ( $value ) {
                return str_replace( array( '"', "'", '<', '>' ), '', (string) $value );
            }
        );
        Functions\when( 'sanitize_html_class' )->alias(
            function ( $value ) {
                return (string) preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $value );
            }
        );
        Functions\when( 'wp_parse_args' )->alias(
            function ( $args, $defaults = array() ) {
                return array_merge( (array) $defaults, (array) $args );
            }
        );
        Functions\when( 'shortcode_atts' )->alias(
            function ( $pairs, $atts ) {
                return array_merge( (array) $pairs, array_intersect_key( (array) $atts, (array) $pairs ) );
            }
        );
        Functions\when( 'is_search' )->justReturn( false );
        Functions\when( 'get_search_query' )->justReturn( '' );
        Functions\when( 'get_post_type_archive_link' )->justReturn( 'https://example.test/docs/' );
        Functions\when( 'home_url' )->justReturn( 'https://example.test/' );
        Functions\when( 'rest_url' )->alias(
            function ( $path ) {
                return 'https://example.test/wp-json/' . ltrim( (string) $path, '/' );
            }
        );
        Functions\when( 'locate_template' )->justReturn( '' );
        Functions\when( 'wp_is_block_theme' )->justReturn( false );

        Functions\when( 'wp_enqueue_style' )->alias(
            function ( $handle ) {
                $this->enqueued[] = $handle;
            }
        );
        Functions\when( 'wp_enqueue_script' )->alias(
            function ( $handle ) {
                $this->enqueued[] = $handle;
            }
        );
        Functions\when( 'wp_add_inline_script' )->alias(
            function ( $handle, $script ) {
                unset( $handle );

                $this->inline[] = $script;

                return true;
            }
        );
    }

    /**
     * The input implements the ARIA 1.2 combobox pattern.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_input_is_an_aria_combobox() {
        $markup = $this->block->render();

        $this->assertStringContainsString( 'role="combobox"', $markup );
        $this->assertStringContainsString( 'aria-expanded="false"', $markup );
        $this->assertStringContainsString( 'aria-autocomplete="list"', $markup );
        $this->assertStringContainsString( 'aria-haspopup="listbox"', $markup );
    }

    /**
     * The listbox exists, is hidden, and is the element `aria-controls` names.
     *
     * A mismatch here is invisible on screen and total for a screen reader: the
     * combobox would announce a popup that, as far as accessibility APIs are
     * concerned, is not there.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_aria_controls_names_the_listbox() {
        $markup  = $this->block->render();
        $matches = array();

        preg_match( '/aria-controls="([^"]+)"/', $markup, $matches );

        $this->assertNotEmpty( $matches, 'The combobox has no aria-controls attribute.' );

        $listbox = $matches[1];

        $this->assertStringContainsString( 'id="' . $listbox . '" role="listbox"', $markup );

        /*
         * Matched on the listbox element itself. Asserting that the markup
         * contains "hidden" anywhere passes on the hidden post_type input, so a
         * listbox that rendered open on page load would go unnoticed.
         */
        $this->assertMatchesRegularExpression(
            '/<ul[^>]*id="' . preg_quote( $listbox, '/' ) . '"[^>]*\shidden/',
            $markup,
            'The listbox must start hidden.'
        );
    }

    /**
     * The label points at the input it labels.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_label_is_bound_to_the_input() {
        $markup  = $this->block->render();
        $matches = array();

        preg_match( '/<label[^>]*for="([^"]+)"/', $markup, $matches );

        $this->assertNotEmpty( $matches );
        $this->assertStringContainsString( 'id="' . $matches[1] . '"', $markup );
    }

    /**
     * A live region carries the result count for assistive technology.
     *
     * A listbox appearing is not announced on its own, so without this a screen
     * reader user gets no signal that anything happened when they typed.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_live_region_is_present() {
        $markup = $this->block->render();

        $this->assertStringContainsString( 'role="status"', $markup );
        $this->assertStringContainsString( 'aria-live="polite"', $markup );
    }

    /**
     * Two forms on one page never share an element ID.
     *
     * `aria-controls` and `aria-activedescendant` are ID references. Two forms
     * with the same IDs would point the second combobox at the first listbox.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_each_instance_gets_its_own_ids() {
        $first  = $this->block->render();
        $second = $this->block->render();

        $ids = array();

        preg_match( '/aria-controls="([^"]+)"/', $first, $ids );
        $first_listbox = $ids[1];

        preg_match( '/aria-controls="([^"]+)"/', $second, $ids );
        $second_listbox = $ids[1];

        $this->assertNotSame( $first_listbox, $second_listbox );
    }

    /**
     * The form submits to the full results page, scoped to the article type.
     *
     * Pressing Enter with nothing selected has to land somewhere useful.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_form_submits_to_the_results_page() {
        $markup = $this->block->render();

        $this->assertStringContainsString( 'action="https://example.test/docs/"', $markup );
        $this->assertStringContainsString( 'name="s"', $markup );
        $this->assertStringContainsString( 'name="post_type" value="lisensio_article"', $markup );
    }

    /**
     * The block and the shortcode produce the same markup.
     *
     * They exist so a page builder gets what the block editor gets. Rendering
     * them from one callback is the mechanism; this is the assertion that the
     * mechanism holds.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_block_and_the_shortcode_render_identically() {
        $attributes = array(
            'placeholder' => 'Find an answer',
            'label'       => 'Search help',
            'button'      => 'Go',
        );

        $block     = $this->normalize_ids( $this->block->render( $attributes ) );
        $shortcode = $this->normalize_ids( $this->block->render_shortcode( $attributes ) );

        $this->assertSame( $block, $shortcode );
        $this->assertStringContainsString( 'placeholder="Find an answer"', $block );
        $this->assertStringContainsString( '>Go</button>', $block );
    }

    /**
     * An attribute left empty in the editor falls back to the default.
     *
     * A block attribute the editor never touched arrives as an empty string
     * rather than as a missing key, so a naive merge renders a form with no
     * placeholder and an unlabelled button.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_empty_attributes_fall_back_to_the_defaults() {
        $markup = $this->block->render(
            array(
                'placeholder' => '',
                'label'       => '',
                'button'      => '',
            )
        );

        $defaults = Lisensio_KB_Search_Block::defaults();

        $this->assertStringContainsString( 'placeholder="' . htmlspecialchars( $defaults['placeholder'], ENT_QUOTES, 'UTF-8' ) . '"', $markup );
        $this->assertStringContainsString( '>' . htmlspecialchars( $defaults['button'], ENT_QUOTES, 'UTF-8' ) . '</button>', $markup );
    }

    /**
     * Attribute values are escaped.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_attribute_values_are_escaped() {
        $markup = $this->block->render( array( 'placeholder' => '" onfocus="alert(1)' ) );

        $this->assertStringNotContainsString( 'onfocus="alert(1)"', $markup );
        $this->assertStringContainsString( '&quot;', $markup );
    }

    /**
     * Rendering enqueues the type-ahead and its stylesheet.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_rendering_enqueues_the_type_ahead() {
        $this->block->render();

        $this->assertContains( Lisensio_KB_Search_Block::SCRIPT_HANDLE, $this->enqueued );
        $this->assertContains( Lisensio_KB_Search_Block::STYLE_HANDLE, $this->enqueued );
    }

    /**
     * The runtime configuration is printed once, however many forms there are.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_configuration_is_printed_once() {
        $this->block->render();
        $this->block->render();
        $this->block->render();

        $this->assertCount( 1, $this->inline );
        $this->assertStringContainsString( 'window.lisensioKB.search', $this->inline[0] );

        $marker = 'window.lisensioKB.search = ';
        $json   = substr( $this->inline[0], strpos( $this->inline[0], $marker ) + strlen( $marker ) );
        $json   = rtrim( $json, ';' );

        $decoded = json_decode( $json, true );

        $this->assertIsArray( $decoded, 'The printed configuration is not valid JSON: ' . $json );
        $this->assertSame( 'https://example.test/wp-json/lisensio-kb/v1/search', $decoded['endpoint'] );
    }

    /**
     * The configuration carries the debounce the specification asks for.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_configuration_carries_the_debounce_and_endpoint() {
        $config = Lisensio_KB_Search_Block::config();

        $this->assertSame( 250, $config['debounce'] );
        $this->assertSame( 'live', $config['source'] );
        $this->assertSame( 'https://example.test/wp-json/lisensio-kb/v1/search', $config['endpoint'] );
        $this->assertArrayHasKey( 'noResults', $config['i18n'] );
    }

    /**
     * The configuration is filterable.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_configuration_is_filterable() {
        Filters\expectApplied( 'lisensio_search_frontend_config' )->once();

        Lisensio_KB_Search_Block::config();
    }

    /**
     * A theme template overrides the built-in markup entirely.
     *
     * The resolver is the scaffold's; this asserts the block actually routes
     * through it rather than printing its own markup unconditionally.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_template_override_replaces_the_markup() {
        Filters\expectApplied( 'lisensio_get_template' )
            ->once()
            ->andReturn( '<p>from the theme</p>' );

        $this->assertSame( '<p>from the theme</p>', $this->block->render() );
    }

    /**
     * The block and the shortcode are both registered.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_registration_hooks_are_added() {
        Actions\expectAdded( 'init' )->twice();

        $this->block->init_hooks();
    }

    /**
     * Replace the per-instance IDs so two renders can be compared.
     *
     * @since 1.0.0
     *
     * @param string $markup The rendered markup.
     *
     * @return string The markup with instance IDs normalised.
     */
    private function normalize_ids( $markup ) {
        return (string) preg_replace( '/lisensio-kb-search-\d+/', 'lisensio-kb-search-N', $markup );
    }
}
