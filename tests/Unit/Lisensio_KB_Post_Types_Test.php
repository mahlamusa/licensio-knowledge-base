<?php
/**
 * Unit tests for the content model registration.
 *
 * Covers the hook ordering the post type's `taxonomies` argument depends on,
 * the scope boundary against #3, the rewrite slug guards and the rewrite flush
 * that a slug change requests.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;

/**
 * Tests Lisensio_KB_Post_Types.
 *
 * @since 1.0.0
 */
class Lisensio_KB_Post_Types_Test extends Lisensio_KB_Test_Case {

    /**
     * Taxonomies registered during the test, by name.
     *
     * @since 1.0.0
     * @var array<string, array<string, mixed>>
     */
    private $taxonomies = array();

    /**
     * Post types registered during the test, by name.
     *
     * @since 1.0.0
     * @var array<string, array<string, mixed>>
     */
    private $post_types = array();

    /**
     * How many times flush_rewrite_rules() was called.
     *
     * @since 1.0.0
     * @var int
     */
    private $flushes = 0;

    /**
     * Stub the registration surface.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        $this->taxonomies = array();
        $this->post_types = array();
        $this->flushes    = 0;

        Functions\when( 'register_post_type' )->alias(
            function ( $name, $args ) {
                $this->post_types[ $name ] = $args;
            }
        );
        Functions\when( 'register_taxonomy' )->alias(
            function ( $name, $object_type, $args ) {
                unset( $object_type );
                $this->taxonomies[ $name ] = $args;
            }
        );
        Functions\when( 'flush_rewrite_rules' )->alias(
            function () {
                $this->flushes++;
            }
        );
    }

    /**
     * Taxonomies are hooked before the post type that lists them.
     *
     * register_post_type()'s `taxonomies` argument calls
     * register_taxonomy_for_object_type(), which returns false for a taxonomy
     * not yet in $wp_taxonomies. With the post type registered first that
     * argument was a silent no-op that still read as load-bearing.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_taxonomies_are_registered_before_the_post_type() {
        $priorities = array();

        Actions\expectAdded( 'init' )->whenHappen(
            function ( $callback, $priority ) use ( &$priorities ) {
                if ( is_array( $callback ) && is_string( $callback[1] ) ) {
                    $priorities[ $callback[1] ] = $priority;
                }
            }
        );

        $post_types = new Lisensio_KB_Post_Types( new Lisensio_KB_Settings() );
        $post_types->init_hooks();

        $this->assertArrayHasKey( 'register_taxonomies', $priorities );
        $this->assertArrayHasKey( 'register_post_type', $priorities );
        $this->assertLessThan(
            $priorities['register_post_type'],
            $priorities['register_taxonomies'],
            'Taxonomies must register before the post type that declares them.'
        );
    }

    /**
     * The post type still declares both taxonomies.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_post_type_declares_both_taxonomies() {
        $post_types = new Lisensio_KB_Post_Types( new Lisensio_KB_Settings() );
        $post_types->register_post_type();

        $declared = $this->post_types['lisensio_article']['taxonomies'];

        $this->assertContains( 'lisensio_category', $declared );
        $this->assertContains( 'lisensio_tag', $declared );
    }

    /**
     * Neither taxonomy registers an admin column.
     *
     * The scaffold ledger is explicit that admin columns belong to #3, and
     * `show_admin_column` exists solely to add one to the article list table.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_no_taxonomy_registers_an_admin_column() {
        $post_types = new Lisensio_KB_Post_Types( new Lisensio_KB_Settings() );
        $post_types->register_taxonomies();

        foreach ( $this->taxonomies as $name => $args ) {
            $this->assertNotTrue(
                isset( $args['show_admin_column'] ) ? $args['show_admin_column'] : false,
                sprintf( '%s must not register an admin column; that belongs to #3.', $name )
            );
        }
    }

    /**
     * Every rewrite slug is non-empty, including the taxonomy ones.
     *
     * Only the article slug used to be guarded, so an empty stored category or
     * tag slug registered a taxonomy with an empty rewrite slug.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_no_rewrite_slug_can_be_empty() {
        $this->options[ Lisensio_KB_Settings::OPTION_NAME ] = array(
            'article_slug'  => '',
            'category_slug' => '',
            'tag_slug'      => '',
        );

        $post_types = new Lisensio_KB_Post_Types( new Lisensio_KB_Settings() );
        $post_types->register_taxonomies();
        $post_types->register_post_type();

        $this->assertSame( 'docs', $this->post_types['lisensio_article']['rewrite']['slug'] );
        $this->assertSame( 'docs-category', $this->taxonomies['lisensio_category']['rewrite']['slug'] );
        $this->assertSame( 'docs-tag', $this->taxonomies['lisensio_tag']['rewrite']['slug'] );

        $this->assertSame( 'docs', $post_types->article_slug() );
        $this->assertSame( 'docs-category', $post_types->category_slug() );
        $this->assertSame( 'docs-tag', $post_types->tag_slug() );
    }

    /**
     * A pending flush request is honoured once and then cleared.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_pending_rewrite_flush_is_performed_once() {
        $post_types = new Lisensio_KB_Post_Types( new Lisensio_KB_Settings() );

        Lisensio_KB_Settings::request_rewrite_flush();

        $post_types->maybe_flush_rewrite_rules();
        $this->assertSame( 1, $this->flushes );

        $post_types->maybe_flush_rewrite_rules();
        $this->assertSame( 1, $this->flushes, 'The flush request must be consumed, not repeated every request.' );
        $this->assertFalse( Lisensio_KB_Settings::needs_rewrite_flush() );
    }

    /**
     * With no request pending nothing is flushed.
     *
     * Flushing rewrite rules on every request is expensive.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_nothing_is_flushed_without_a_request() {
        $post_types = new Lisensio_KB_Post_Types( new Lisensio_KB_Settings() );

        $post_types->maybe_flush_rewrite_rules();

        $this->assertSame( 0, $this->flushes );
    }

    /**
     * Saving a new slug leads to the rules actually being rebuilt.
     *
     * The end-to-end path a consumer depends on: settings write, then the flush
     * on `init` after the permastructs have been registered.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_saving_a_new_slug_leads_to_a_flush() {
        $settings   = new Lisensio_KB_Settings();
        $post_types = new Lisensio_KB_Post_Types( $settings );

        $settings->set( 'article_slug', 'handbook' );

        $post_types->maybe_flush_rewrite_rules();

        $this->assertSame( 1, $this->flushes );
        $this->assertSame( 'handbook', $post_types->article_slug() );
    }
}
