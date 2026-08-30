<?php
/**
 * Unit tests for the permalink settings.
 *
 * Covers the hierarchical category URL toggle, the article base, and the
 * "flush only when the stored value actually changes" contract both rely on.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

/**
 * Tests Lisensio_KB_Permalinks.
 *
 * @since 1.0.0
 */
class Lisensio_KB_Permalinks_Test extends Lisensio_KB_Test_Case {

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
     * Build a settings store whose registry actually includes the new key.
     *
     * Brain Monkey does not run `add_filter` callbacks on `apply_filters`, so
     * simply calling init_hooks() would leave the registry without the key and
     * every assertion below would pass against a setting that does not exist.
     * The registry filter is driven directly instead.
     *
     * @since 1.0.0
     *
     * @return array{0: Lisensio_KB_Settings, 1: Lisensio_KB_Permalinks} The settings store and the permalinks component.
     */
    private function build() {
        $settings   = new Lisensio_KB_Settings();
        $permalinks = new Lisensio_KB_Permalinks( $settings );

        Filters\expectApplied( 'lisensio_settings_registry' )
            ->zeroOrMoreTimes()
            ->andReturnUsing(
                function ( $registry ) use ( $permalinks ) {
                    return $permalinks->register_settings( $registry );
                }
            );

        return array( $settings, $permalinks );
    }

    /**
     * The toggle is a registered setting that invalidates the rewrite rules.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_toggle_is_registered_as_a_flushing_setting() {
        $settings   = new Lisensio_KB_Settings();
        $permalinks = new Lisensio_KB_Permalinks( $settings );

        $registry = $permalinks->register_settings( array() );

        $this->assertSame(
            array(
                'type'           => 'bool',
                'default'        => true,
                'flush_rewrites' => true,
            ),
            $registry['hierarchical_category_urls']
        );
    }

    /**
     * Registering the setting does not disturb the rest of the registry.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_existing_registry_survives() {
        list( $settings, $permalinks ) = $this->build();

        $registry = $settings->registry();

        $this->assertArrayHasKey( 'article_slug', $registry );
        $this->assertArrayHasKey( 'hierarchical_category_urls', $registry );
        $this->assertSame( 'docs', $registry['article_slug']['default'] );
    }

    /**
     * Category URLs are hierarchical out of the box.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_category_urls_are_hierarchical_by_default() {
        list( $settings, $permalinks ) = $this->build();

        unset( $settings );

        $args = $permalinks->filter_category_args( array( 'rewrite' => array( 'slug' => 'docs-category' ) ) );

        $this->assertTrue( $args['rewrite']['hierarchical'] );
        $this->assertTrue( $permalinks->hierarchical_category_urls() );
    }

    /**
     * Turning the toggle off flattens the category rewrite.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_turning_the_toggle_off_flattens_the_category_rewrite() {
        list( $settings, $permalinks ) = $this->build();

        $settings->set( 'hierarchical_category_urls', false );

        $args = $permalinks->filter_category_args( array( 'rewrite' => array( 'slug' => 'docs-category' ) ) );

        $this->assertFalse( $args['rewrite']['hierarchical'] );
        $this->assertFalse( $permalinks->hierarchical_category_urls() );
        $this->assertSame( 'docs-category', $args['rewrite']['slug'], 'The rewrite slug must survive the filter.' );
    }

    /**
     * The toggle reaches the registered taxonomy, not just the filter.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_toggle_reaches_the_registered_taxonomy() {
        list( $settings, $permalinks ) = $this->build();

        $settings->set( 'hierarchical_category_urls', false );

        Filters\expectApplied( 'lisensio_category_taxonomy_args' )
            ->once()
            ->andReturnUsing(
                function ( $args ) use ( $permalinks ) {
                    return $permalinks->filter_category_args( $args );
                }
            );

        $post_types = new Lisensio_KB_Post_Types( $settings );
        $post_types->register_taxonomies();

        $this->assertFalse( $this->taxonomies['lisensio_category']['rewrite']['hierarchical'] );
    }

    /**
     * Arguments with no rewrite block are handed back untouched.
     *
     * A site can switch the taxonomy's rewrite off entirely; writing into a
     * rewrite array that is not there would turn `false` into an array and
     * quietly switch it back on.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_arguments_without_a_rewrite_block_are_untouched() {
        list( $settings, $permalinks ) = $this->build();

        unset( $settings );

        $args = array(
            'rewrite'      => false,
            'hierarchical' => true,
        );

        $this->assertSame( $args, $permalinks->filter_category_args( $args ) );
    }

    /**
     * Changing the toggle asks for the rewrite rules to be rebuilt.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_changing_the_toggle_requests_a_flush() {
        list( $settings, $permalinks ) = $this->build();

        unset( $permalinks );

        $this->assertFalse( Lisensio_KB_Settings::needs_rewrite_flush() );

        $settings->set( 'hierarchical_category_urls', false );

        $this->assertTrue( Lisensio_KB_Settings::needs_rewrite_flush() );
    }

    /**
     * Saving the toggle unchanged does not rebuild anything.
     *
     * Rebuilding the rewrite rules is expensive, and the settings screen saves
     * every field on every submission, so a no-op save is the common case.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_saving_the_toggle_unchanged_requests_no_flush() {
        list( $settings, $permalinks ) = $this->build();

        unset( $permalinks );

        $settings->set( 'hierarchical_category_urls', true );

        $this->assertFalse(
            Lisensio_KB_Settings::needs_rewrite_flush(),
            'Saving the value it already had must not invalidate the rewrite rules.'
        );
    }

    /**
     * Changing the toggle twice back to its original value settles correctly.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_second_identical_save_requests_no_further_flush() {
        list( $settings, $permalinks ) = $this->build();

        unset( $permalinks );

        $settings->set( 'hierarchical_category_urls', false );

        Lisensio_KB_Settings::clear_rewrite_flush();

        $settings->set( 'hierarchical_category_urls', false );

        $this->assertFalse( Lisensio_KB_Settings::needs_rewrite_flush() );
    }

    /**
     * Changing the article base rebuilds the permalinks with no manual flush.
     *
     * The whole path the acceptance criterion describes: write the setting,
     * then let `init` rebuild the rules once and register the new base.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_changing_the_article_base_rebuilds_the_permalinks() {
        list( $settings, $permalinks ) = $this->build();

        $post_types = new Lisensio_KB_Post_Types( $settings );

        $this->assertSame( 'docs', $permalinks->article_base() );

        $settings->set( 'article_slug', 'handbook' );

        $post_types->maybe_flush_rewrite_rules();
        $post_types->register_post_type();

        $this->assertSame( 1, $this->flushes );
        $this->assertSame( 'handbook', $permalinks->article_base() );
        $this->assertSame( 'handbook', $this->post_types['lisensio_article']['rewrite']['slug'] );
    }

    /**
     * The rules are not rebuilt on a page load that changed nothing.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_rules_are_not_rebuilt_on_every_page_load() {
        list( $settings, $permalinks ) = $this->build();

        unset( $permalinks );

        $post_types = new Lisensio_KB_Post_Types( $settings );

        $settings->set( 'article_slug', 'handbook' );

        $post_types->maybe_flush_rewrite_rules();
        $post_types->maybe_flush_rewrite_rules();
        $post_types->maybe_flush_rewrite_rules();

        $this->assertSame( 1, $this->flushes );
    }

    /**
     * An empty article base falls back to the default rather than to nothing.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_empty_article_base_falls_back_to_the_default() {
        list( $settings, $permalinks ) = $this->build();

        $settings->set( 'article_slug', '' );

        $this->assertSame( 'docs', $permalinks->article_base() );
    }

    /**
     * Activation asks for a rebuild on the next request.
     *
     * Activation registers the content model before this component has been
     * able to attach its filters, so the rules built there can describe the
     * wrong category permastruct.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_activation_requests_a_rebuild() {
        Lisensio_KB_Permalinks::request_flush();

        $this->assertTrue( Lisensio_KB_Settings::needs_rewrite_flush() );
    }

    /**
     * Both filters are registered.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_both_filters_are_registered() {
        $permalinks = new Lisensio_KB_Permalinks( new Lisensio_KB_Settings() );
        $permalinks->init_hooks();

        $this->assertNotFalse( has_filter( 'lisensio_settings_registry', array( $permalinks, 'register_settings' ) ) );
        $this->assertNotFalse( has_filter( 'lisensio_category_taxonomy_args', array( $permalinks, 'filter_category_args' ) ) );
    }
}
