<?php
/**
 * Unit tests for the block template layer.
 *
 * Covers Site Editor registration, the theme/database precedence rule,
 * front-end template resolution, the article search hierarchy, the frontend
 * hook contract under a block theme and the shipped template files.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;

/**
 * Tests Licensio_KB_Block_Templates.
 *
 * @since 1.0.0
 */
class Licensio_KB_Block_Templates_Test extends Licensio_KB_Test_Case {

    /**
     * The stylesheet name every test pretends is active.
     *
     * @since 1.0.0
     * @var string
     */
    const THEME = 'twentytwentyfour';

    /**
     * The layer under test.
     *
     * @since 1.0.0
     * @var Licensio_KB_Block_Templates
     */
    private $block_templates;

    /**
     * A block template file the plugin ships but does not declare.
     *
     * Proves the declaration list, not the filesystem, decides what the Site
     * Editor can address.
     *
     * @since 1.0.0
     * @var string
     */
    private $undeclared_template = '';

    /**
     * A throwaway theme root used by the override tests.
     *
     * @since 1.0.0
     * @var string
     */
    private $theme_dir = '';

    /**
     * Boot the layer and the WordPress functions it reaches for.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        Functions\when( 'wp_is_block_theme' )->justReturn( true );
        Functions\when( 'get_stylesheet' )->justReturn( self::THEME );
        Functions\when( 'untrailingslashit' )->alias(
            function ( $value ) {
                return rtrim( (string) $value, '/\\' );
            }
        );
        Functions\when( 'locate_template' )->justReturn( '' );
        Functions\when( 'parse_blocks' )->justReturn( array() );
        Functions\when( 'get_query_var' )->justReturn( '' );
        Functions\when( 'is_singular' )->justReturn( false );
        Functions\when( 'get_queried_object_id' )->justReturn( 0 );
        Functions\when( 'get_post' )->alias(
            function ( $post = null ) {
                return $post;
            }
        );

        $this->theme_dir = sys_get_temp_dir() . '/licensio-kb-block-theme-' . uniqid();

        mkdir( $this->theme_dir . '/parts', 0777, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
        mkdir( $this->theme_dir . '/templates', 0777, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir

        $this->without_theme_overrides();

        $this->undeclared_template = LICENSIO_KB_ABSPATH . 'block-templates/licensio-kb-test-fixture.html';

        file_put_contents( $this->undeclared_template, '<!-- wp:paragraph --><p>undeclared</p><!-- /wp:paragraph -->' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

        $this->block_templates = new Licensio_KB_Block_Templates();
    }

    /**
     * Remove the fixtures.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function tearDown(): void {
        if ( '' !== $this->undeclared_template && file_exists( $this->undeclared_template ) ) {
            unlink( $this->undeclared_template ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.WP.AlternativeFunctions.file_system_operations_unlink
        }

        foreach ( array( '/parts', '/templates' ) as $sub_directory ) {
            $directory = $this->theme_dir . $sub_directory;

            if ( ! is_dir( $directory ) ) {
                continue;
            }

            foreach ( (array) glob( $directory . '/*' ) as $file ) {
                unlink( (string) $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.WP.AlternativeFunctions.file_system_operations_unlink
            }

            rmdir( $directory ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
        }

        if ( is_dir( $this->theme_dir ) ) {
            rmdir( $this->theme_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
        }

        parent::tearDown();
    }

    /**
     * Point the theme lookups at a directory that ships nothing.
     *
     * @since 1.0.0
     *
     * @return void
     */
    private function without_theme_overrides() {
        Functions\when( 'get_stylesheet_directory' )->justReturn( LICENSIO_KB_ABSPATH . 'tests/no-such-theme' );
        Functions\when( 'get_template_directory' )->justReturn( LICENSIO_KB_ABSPATH . 'tests/no-such-theme' );
    }

    /**
     * Point the theme lookups at the throwaway theme.
     *
     * @since 1.0.0
     *
     * @return void
     */
    private function with_theme_overrides() {
        $directory = $this->theme_dir;

        Functions\when( 'get_stylesheet_directory' )->justReturn( $directory );
        Functions\when( 'get_template_directory' )->justReturn( $directory );
    }

    /**
     * The slugs of a template list.
     *
     * @since 1.0.0
     *
     * @param WP_Block_Template[]|mixed $templates The templates.
     *
     * @return string[] The slugs, in order.
     */
    private function slugs( $templates ) {
        $slugs = array();

        foreach ( (array) $templates as $template ) {
            if ( $template instanceof WP_Block_Template ) {
                $slugs[] = $template->slug;
            }
        }

        return $slugs;
    }

    /**
     * The template with a given slug, or null.
     *
     * @since 1.0.0
     *
     * @param WP_Block_Template[]|mixed $templates The templates.
     * @param string                    $slug      The slug to find.
     *
     * @return WP_Block_Template|null The template.
     */
    private function find( $templates, $slug ) {
        foreach ( (array) $templates as $template ) {
            if ( $template instanceof WP_Block_Template && $slug === $template->slug ) {
                return $template;
            }
        }

        return null;
    }

    /**
     * Every filter the block layer depends on is registered.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_init_hooks_registers_the_block_template_filters() {
        $this->block_templates->init_hooks();

        $this->assertNotFalse( has_filter( 'get_block_templates', array( $this->block_templates, 'add_block_templates' ) ) );
        $this->assertNotFalse( has_filter( 'get_block_file_template', array( $this->block_templates, 'add_block_file_template' ) ) );
        $this->assertNotFalse( has_filter( 'search_template_hierarchy', array( $this->block_templates, 'search_template_hierarchy' ) ) );
        $this->assertNotFalse( has_filter( 'render_block', array( $this->block_templates, 'render_article_hooks' ) ) );
        $this->assertNotFalse( has_filter( 'render_block', array( $this->block_templates, 'scope_search_form' ) ) );
    }

    /**
     * All five templates reach the Site Editor list.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_every_shipped_template_is_offered_to_the_site_editor() {
        $result = $this->block_templates->add_block_templates( array(), array(), 'wp_template' );

        $this->assertSame(
            array(
                'single-licensio_article',
                'archive-licensio_article',
                'taxonomy-licensio_category',
                'taxonomy-licensio_tag',
                'search-licensio_article',
            ),
            $this->slugs( $result )
        );

        $single = $this->find( $result, 'single-licensio_article' );

        $this->assertInstanceOf( 'WP_Block_Template', $single );
        $this->assertSame( self::THEME . '//single-licensio_article', $single->id );
        $this->assertSame( self::THEME, $single->theme );
        $this->assertSame( 'wp_template', $single->type );
        $this->assertSame( 'plugin', $single->source );
        $this->assertTrue( $single->has_theme_file );
        $this->assertFalse( $single->is_custom );
        $this->assertSame( 'Single Article', $single->title );
        $this->assertStringContainsString( 'wp:post-content', $single->content );
    }

    /**
     * The header template part reaches the Site Editor list.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_header_part_is_offered_to_the_site_editor() {
        $result = $this->block_templates->add_block_templates( array(), array(), 'wp_template_part' );

        $this->assertSame( array( 'licensio-kb-header' ), $this->slugs( $result ) );

        $part = $this->find( $result, 'licensio-kb-header' );

        $this->assertInstanceOf( 'WP_Block_Template', $part );
        $this->assertSame( 'wp_template_part', $part->type );
        $this->assertSame( 'header', $part->area );
        $this->assertStringContainsString( 'licensio-kb__masthead', $part->content );
    }

    /**
     * A theme's own template is never displaced by the plugin's copy.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_theme_template_is_not_displaced_by_the_plugin() {
        $theme_template          = new WP_Block_Template();
        $theme_template->slug    = 'single-licensio_article';
        $theme_template->type    = 'wp_template';
        $theme_template->source  = 'theme';
        $theme_template->content = 'THEME COPY';

        $result = $this->block_templates->add_block_templates( array( $theme_template ), array(), 'wp_template' );

        $this->assertSame( 1, count( array_keys( $this->slugs( $result ), 'single-licensio_article', true ) ) );

        $single = $this->find( $result, 'single-licensio_article' );

        $this->assertInstanceOf( 'WP_Block_Template', $single );
        $this->assertSame( 'THEME COPY', $single->content );
    }

    /**
     * A saved customisation is neither duplicated nor left unresettable.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_saved_customisation_wins_and_becomes_resettable() {
        $saved                 = new WP_Block_Template();
        $saved->slug           = 'archive-licensio_article';
        $saved->type           = 'wp_template';
        $saved->source         = 'custom';
        $saved->origin         = null;
        $saved->has_theme_file = false;
        $saved->content        = 'USER COPY';

        $result = $this->block_templates->add_block_templates( array( $saved ), array(), 'wp_template' );

        $this->assertSame( 1, count( array_keys( $this->slugs( $result ), 'archive-licensio_article', true ) ) );

        $archive = $this->find( $result, 'archive-licensio_article' );

        $this->assertInstanceOf( 'WP_Block_Template', $archive );
        $this->assertSame( 'USER COPY', $archive->content );
        $this->assertTrue( $archive->has_theme_file );
        $this->assertSame( 'plugin', $archive->origin );
    }

    /**
     * A customisation of a template this plugin does not ship is left alone.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_unrelated_customisation_is_left_alone() {
        $saved                 = new WP_Block_Template();
        $saved->slug           = 'single-post';
        $saved->type           = 'wp_template';
        $saved->source         = 'custom';
        $saved->has_theme_file = false;

        $result = $this->block_templates->add_block_templates( array( $saved ), array(), 'wp_template' );

        $unrelated = $this->find( $result, 'single-post' );

        $this->assertInstanceOf( 'WP_Block_Template', $unrelated );
        $this->assertFalse( $unrelated->has_theme_file );
        $this->assertNull( $unrelated->origin );
    }

    /**
     * Under a classic theme the block layer adds nothing.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_nothing_is_offered_under_a_classic_theme() {
        Functions\when( 'wp_is_block_theme' )->justReturn( false );

        $this->assertSame( array(), $this->block_templates->add_block_templates( array(), array(), 'wp_template' ) );
    }

    /**
     * A lookup for the templates a post may use is left alone.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_post_type_lookup_is_left_alone() {
        $result = $this->block_templates->add_block_templates( array(), array( 'post_type' => 'page' ), 'wp_template' );

        $this->assertSame( array(), $result );
    }

    /**
     * A slug-restricted lookup only gets the slugs it asked for.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_slug_restricted_lookup_is_honoured() {
        $result = $this->block_templates->add_block_templates(
            array(),
            array( 'slug__in' => array( 'taxonomy-licensio_tag' ) ),
            'wp_template'
        );

        $this->assertSame( array( 'taxonomy-licensio_tag' ), $this->slugs( $result ) );
    }

    /**
     * A part lookup for another area gets nothing.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_part_lookup_for_another_area_is_empty() {
        $result = $this->block_templates->add_block_templates( array(), array( 'area' => 'footer' ), 'wp_template_part' );

        $this->assertSame( array(), $this->slugs( $result ) );

        $result = $this->block_templates->add_block_templates( array(), array( 'area' => 'header' ), 'wp_template_part' );

        $this->assertSame( array( 'licensio-kb-header' ), $this->slugs( $result ) );
    }

    /**
     * The front end resolves the plugin's copy when nothing else answers.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_plugin_copy_answers_a_front_end_lookup() {
        $template = $this->block_templates->add_block_file_template(
            null,
            self::THEME . '//taxonomy-licensio_category',
            'wp_template'
        );

        $this->assertInstanceOf( 'WP_Block_Template', $template );
        $this->assertSame( 'taxonomy-licensio_category', $template->slug );
        $this->assertStringContainsString( '[licensio_kb_view view="category"]', $template->content );
    }

    /**
     * A template core already resolved is returned untouched.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_resolved_template_is_never_replaced() {
        $resolved          = new WP_Block_Template();
        $resolved->slug    = 'single-licensio_article';
        $resolved->content = 'THEME COPY';

        $template = $this->block_templates->add_block_file_template(
            $resolved,
            self::THEME . '//single-licensio_article',
            'wp_template'
        );

        $this->assertSame( $resolved, $template );
    }

    /**
     * A lookup addressed to another theme is declined.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_lookup_for_another_theme_is_declined() {
        $this->assertNull(
            $this->block_templates->add_block_file_template( null, 'some-other-theme//single-licensio_article', 'wp_template' )
        );
    }

    /**
     * A file the plugin ships but does not declare is not addressable.
     *
     * The declaration list is the gate. Resolving on the filesystem alone would
     * make anything dropped into `block-templates/` an editable Site Editor
     * template.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_undeclared_file_is_not_addressable() {
        $this->assertFileExists( $this->undeclared_template );

        $this->assertNull(
            $this->block_templates->add_block_file_template( null, self::THEME . '//licensio-kb-test-fixture', 'wp_template' )
        );

        $this->assertNotContains( 'licensio-kb-test-fixture', $this->slugs( $this->block_templates->add_block_templates( array(), array(), 'wp_template' ) ) );
    }

    /**
     * A traversing slug is declined.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_traversing_slug_is_declined() {
        foreach ( array( '../../../wp-config', '/etc/passwd', "evil\0" ) as $slug ) {
            $this->assertNull(
                $this->block_templates->add_block_file_template( null, self::THEME . '//' . $slug, 'wp_template' )
            );
        }
    }

    /**
     * A `wp_template` slug is not served as a template part, or the reverse.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_template_type_is_respected() {
        $this->assertNull(
            $this->block_templates->add_block_file_template( null, self::THEME . '//single-licensio_article', 'wp_template_part' )
        );

        $this->assertNull(
            $this->block_templates->add_block_file_template( null, self::THEME . '//licensio-kb-header', 'wp_template' )
        );
    }

    /**
     * A theme's own template part overrides the plugin's.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_theme_part_overrides_the_plugin_part() {
        file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            $this->theme_dir . '/parts/licensio-kb-header.html',
            '<!-- wp:paragraph --><p>theme header</p><!-- /wp:paragraph -->'
        );

        $this->with_theme_overrides();

        $part = $this->block_templates->build_template( 'licensio-kb-header', 'wp_template_part' );

        $this->assertInstanceOf( 'WP_Block_Template', $part );
        $this->assertStringContainsString( 'theme header', $part->content );
        $this->assertStringNotContainsString( 'licensio-kb__masthead', $part->content );
    }

    /**
     * A parsed block tree with a template part nested inside a group.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $part_attributes The template part block's attributes.
     *
     * @return array<int, array<string, mixed>> The block tree.
     */
    private function tree_with_a_nested_template_part( array $part_attributes ) {
        return array(
            array(
                'blockName'   => 'core/group',
                'attrs'       => array(),
                'innerBlocks' => array(
                    array(
                        'blockName'   => 'core/template-part',
                        'attrs'       => $part_attributes,
                        'innerBlocks' => array(),
                    ),
                ),
            ),
        );
    }

    /**
     * The active theme is stamped onto every template part reference.
     *
     * `core/template-part` refuses to resolve a part whose theme attribute is
     * missing, and a file the plugin ships cannot name the theme in advance.
     * The references are nested inside a group in every shipped template, so
     * the walk has to recurse.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_theme_attribute_is_stamped_onto_template_parts() {
        Functions\when( 'parse_blocks' )->justReturn(
            $this->tree_with_a_nested_template_part( array( 'slug' => 'licensio-kb-header' ) )
        );
        Functions\when( 'serialize_blocks' )->alias(
            function ( $blocks ) {
                return json_encode( $blocks ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
            }
        );

        $template = $this->block_templates->build_template( 'single-licensio_article', 'wp_template' );

        $this->assertInstanceOf( 'WP_Block_Template', $template );
        $this->assertStringContainsString( '"theme":"' . self::THEME . '"', $template->content );
    }

    /**
     * A template part that already names a theme is left alone.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_explicit_theme_attribute_is_not_overwritten() {
        Functions\when( 'parse_blocks' )->justReturn(
            $this->tree_with_a_nested_template_part(
                array(
                    'slug'  => 'licensio-kb-header',
                    'theme' => 'some-other-theme',
                )
            )
        );
        Functions\when( 'serialize_blocks' )->justReturn( 'RESERIALIZED' );

        $template = $this->block_templates->build_template( 'single-licensio_article', 'wp_template' );

        $this->assertInstanceOf( 'WP_Block_Template', $template );
        $this->assertStringContainsString( 'wp:post-content', $template->content );
        $this->assertNotSame( 'RESERIALIZED', $template->content );
    }

    /**
     * A search scoped to articles is answered by the article search template.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_article_search_template_leads_the_hierarchy() {
        Functions\when( 'get_query_var' )->justReturn( 'licensio_article' );

        $hierarchy = $this->block_templates->search_template_hierarchy( array( 'search', 'index' ) );

        $this->assertSame( array( 'search-licensio_article', 'search', 'index' ), $hierarchy );
    }

    /**
     * A site-wide search is left to the theme.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_site_wide_search_hierarchy_is_untouched() {
        $this->assertSame(
            array( 'search', 'index' ),
            $this->block_templates->search_template_hierarchy( array( 'search', 'index' ) )
        );
    }

    /**
     * Under a classic theme the hierarchy is left to the classic layer.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_hierarchy_is_untouched_under_a_classic_theme() {
        Functions\when( 'wp_is_block_theme' )->justReturn( false );
        Functions\when( 'get_query_var' )->justReturn( 'licensio_article' );

        $this->assertSame(
            array( 'search', 'index' ),
            $this->block_templates->search_template_hierarchy( array( 'search', 'index' ) )
        );
    }

    /**
     * Put the request on a single article.
     *
     * @since 1.0.0
     *
     * @return WP_Post The article being viewed.
     */
    private function on_a_single_article() {
        $post     = new WP_Post();
        $post->ID = 42;

        Functions\when( 'is_singular' )->justReturn( true );
        Functions\when( 'get_queried_object_id' )->justReturn( 42 );
        Functions\when( 'get_post' )->justReturn( $post );

        return $post;
    }

    /**
     * All three hooks fire around the article body, in order.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_hook_contract_fires_around_the_article_body() {
        $post = $this->on_a_single_article();

        Actions\expectDone( 'licensio_before_article' )->once()->with( $post )->whenHappen(
            function () {
                echo 'BEFORE';
            }
        );
        Actions\expectDone( 'licensio_after_article' )->once()->with( $post )->whenHappen(
            function () {
                echo 'AFTER';
            }
        );
        Actions\expectDone( 'licensio_article_footer' )->once()->with( $post )->whenHappen(
            function () {
                echo 'FOOTER';
            }
        );

        $markup = $this->block_templates->render_article_hooks( 'BODY', array( 'blockName' => 'core/post-content' ) );

        $this->assertSame(
            'BEFOREBODYAFTER<footer class="licensio-kb-article__footer">FOOTER</footer>',
            $markup
        );
    }

    /**
     * Nothing is wrapped around the body when no footer callback printed.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_empty_footer_adds_no_wrapper() {
        $this->on_a_single_article();

        $markup = $this->block_templates->render_article_hooks( 'BODY', array( 'blockName' => 'core/post-content' ) );

        $this->assertSame( 'BODY', $markup );
    }

    /**
     * The contract does not fire on any other block.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_contract_does_not_fire_on_another_block() {
        $this->on_a_single_article();

        Actions\expectDone( 'licensio_before_article' )->never();
        Actions\expectDone( 'licensio_after_article' )->never();
        Actions\expectDone( 'licensio_article_footer' )->never();

        $this->assertSame(
            'BODY',
            $this->block_templates->render_article_hooks( 'BODY', array( 'blockName' => 'core/paragraph' ) )
        );
    }

    /**
     * The contract does not fire twice under a classic theme.
     *
     * The classic templates already call the three hook functions themselves,
     * so a block layer that did not stand down would render every feedback
     * form and analytics beacon twice.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_contract_does_not_fire_under_a_classic_theme() {
        $this->on_a_single_article();

        Functions\when( 'wp_is_block_theme' )->justReturn( false );

        Actions\expectDone( 'licensio_before_article' )->never();
        Actions\expectDone( 'licensio_after_article' )->never();
        Actions\expectDone( 'licensio_article_footer' )->never();

        $this->assertSame(
            'BODY',
            $this->block_templates->render_article_hooks( 'BODY', array( 'blockName' => 'core/post-content' ) )
        );
    }

    /**
     * The contract does not fire for every card in a listing.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_contract_does_not_fire_in_a_listing() {
        $post     = new WP_Post();
        $post->ID = 7;

        Functions\when( 'is_singular' )->justReturn( true );
        Functions\when( 'get_queried_object_id' )->justReturn( 42 );
        Functions\when( 'get_post' )->justReturn( $post );

        Actions\expectDone( 'licensio_before_article' )->never();

        $this->assertSame(
            'BODY',
            $this->block_templates->render_article_hooks( 'BODY', array( 'blockName' => 'core/post-content' ) )
        );
    }

    /**
     * The contract does not fire outside a single article.
     *
     * Everything else about the request is left looking exactly like an article
     * view — there is a current post and it is the queried object — so
     * `is_singular()` is the only thing standing between an archive and a
     * feedback form.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_contract_does_not_fire_outside_a_single_article() {
        $this->on_a_single_article();

        Functions\when( 'is_singular' )->justReturn( false );

        Actions\expectDone( 'licensio_before_article' )->never();

        $this->assertSame(
            'BODY',
            $this->block_templates->render_article_hooks( 'BODY', array( 'blockName' => 'core/post-content' ) )
        );
    }

    /**
     * The knowledge base search form is scoped to the article post type.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_knowledge_base_search_form_is_scoped_to_articles() {
        $markup = $this->block_templates->scope_search_form(
            '<form role="search"><input type="search" name="s" /><button>Go</button></form>',
            array(
                'blockName' => 'core/search',
                'attrs'     => array( 'className' => 'is-something licensio-kb__search' ),
            )
        );

        $this->assertSame(
            '<form role="search"><input type="search" name="s" /><button>Go</button><input type="hidden" name="post_type" value="licensio_article" /></form>',
            $markup
        );
    }

    /**
     * A search form that is not the knowledge base's is left alone.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_unrelated_search_form_is_left_alone() {
        $form = '<form role="search"><input type="search" name="s" /></form>';

        $this->assertSame(
            $form,
            $this->block_templates->scope_search_form(
                $form,
                array(
                    'blockName' => 'core/search',
                    'attrs'     => array( 'className' => 'wp-block-search' ),
                )
            )
        );

        $this->assertSame(
            $form,
            $this->block_templates->scope_search_form( $form, array( 'blockName' => 'core/search' ) )
        );

        $this->assertSame(
            $form,
            $this->block_templates->scope_search_form(
                $form,
                array(
                    'blockName' => 'core/paragraph',
                    'attrs'     => array( 'className' => 'licensio-kb__search' ),
                )
            )
        );
    }

    /**
     * The redesigned views render the shared body through the view shortcode.
     *
     * The archive and the category template no longer drive their own Query
     * Loop: they render `[licensio_kb_view]`, which includes the same body
     * template the classic layer includes, so a block theme and a classic
     * theme serve the same markup.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_redesigned_views_render_the_shared_body_shortcode() {
        $views = array(
            'archive-licensio_article'   => 'archive',
            'taxonomy-licensio_category' => 'category',
        );

        foreach ( $views as $slug => $view ) {
            $template = $this->block_templates->build_template( $slug, 'wp_template' );

            $this->assertInstanceOf( 'WP_Block_Template', $template, $slug );
            $this->assertStringContainsString( '[licensio_kb_view view="' . $view . '"]', $template->content, $slug );
            $this->assertStringNotContainsString( '<!-- wp:query ', $template->content, $slug );
        }
    }

    /**
     * The remaining listing templates drive a Query Loop over the article post type.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_listing_templates_drive_a_query_loop_over_articles() {
        $listings = array(
            'taxonomy-licensio_tag',
            'search-licensio_article',
        );

        foreach ( $listings as $slug ) {
            $template = $this->block_templates->build_template( $slug, 'wp_template' );

            $this->assertInstanceOf( 'WP_Block_Template', $template, $slug );
            $this->assertStringContainsString( '<!-- wp:query ', $template->content, $slug );
            $this->assertStringContainsString( '"postType":"licensio_article"', $template->content, $slug );
            $this->assertStringContainsString( '<!-- wp:post-template -->', $template->content, $slug );
        }
    }

    /**
     * The templates that still open with the hero pull in the shared header part.
     *
     * The redesigned archive and category views dropped the part along with
     * the hero band it carried; single, tag and search still open with it.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_every_template_pulls_in_the_shared_header_part() {
        $with_part = array(
            'single-' . Licensio_KB_Post_Types::POST_TYPE,
            'taxonomy-' . Licensio_KB_Post_Types::TAXONOMY_TAG,
            'search-' . Licensio_KB_Post_Types::POST_TYPE,
        );

        foreach ( $with_part as $slug ) {
            $template = $this->block_templates->build_template( $slug, 'wp_template' );

            $this->assertInstanceOf( 'WP_Block_Template', $template, $slug );
            $this->assertStringContainsString( '"slug":"licensio-kb-header"', $template->content, $slug );
        }
    }
}
