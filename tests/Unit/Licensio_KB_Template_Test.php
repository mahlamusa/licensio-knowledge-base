<?php
/**
 * Unit tests for the template resolver.
 *
 * Covers the classic resolution order, the block-theme routing decision and
 * the fallback from the block layer to the classic layer.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

/**
 * Tests licensio_kb_get_template() and its helpers.
 *
 * @since 1.0.0
 */
class Licensio_KB_Template_Test extends Licensio_KB_Test_Case {

    /**
     * Absolute path of the classic template written for these tests.
     *
     * @since 1.0.0
     * @var string
     */
    private $classic_template = '';

    /**
     * Absolute path of the block template written for these tests.
     *
     * @since 1.0.0
     * @var string
     */
    private $block_template = '';

    /**
     * A throwaway theme root used for the override tests.
     *
     * Lives in the system temp directory rather than in the source tree, so an
     * aborted run cannot leave a file where PHPCS would lint it or git would
     * offer to commit it.
     *
     * @since 1.0.0
     * @var string
     */
    private $theme_dir = '';

    /**
     * Write the plugin-side template fixtures.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        Functions\when( 'untrailingslashit' )->alias(
            function ( $value ) {
                return rtrim( (string) $value, '/\\' );
            }
        );

        Functions\when( 'locate_template' )->justReturn( '' );
        Functions\when( 'do_blocks' )->returnArg();
        Functions\when( 'wp_is_block_theme' )->justReturn( false );

        // No theme override by default; the override tests point these at a real directory.
        Functions\when( 'get_stylesheet_directory' )->justReturn( LICENSIO_KB_ABSPATH . 'tests/no-such-theme' );
        Functions\when( 'get_template_directory' )->justReturn( LICENSIO_KB_ABSPATH . 'tests/no-such-theme' );

        $this->classic_template = LICENSIO_KB_ABSPATH . 'templates/licensio-kb-test-fixture.php';
        $this->block_template   = LICENSIO_KB_ABSPATH . 'block-templates/licensio-kb-test-fixture.html';

        file_put_contents( $this->classic_template, '<?php echo "classic:" . $args["label"]; ' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        file_put_contents( $this->block_template, '<!-- wp:paragraph --><p>block</p><!-- /wp:paragraph -->' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

        $this->theme_dir = sys_get_temp_dir() . '/licensio-kb-theme-' . uniqid();

        mkdir( $this->theme_dir . '/templates', 0777, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
        file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            $this->theme_dir . '/templates/licensio-kb-test-fixture.html',
            '<!-- wp:paragraph --><p>theme block</p><!-- /wp:paragraph -->'
        );
    }

    /**
     * Remove the template fixtures.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function tearDown(): void {
        $theme_template = $this->theme_dir . '/templates/licensio-kb-test-fixture.html';

        foreach ( array( $this->classic_template, $this->block_template, $theme_template ) as $file ) {
            if ( '' !== $file && file_exists( $file ) ) {
                unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.WP.AlternativeFunctions.file_system_operations_unlink
            }
        }

        if ( '' !== $this->theme_dir && is_dir( $this->theme_dir . '/templates' ) ) {
            rmdir( $this->theme_dir . '/templates' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
            rmdir( $this->theme_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
        }

        parent::tearDown();
    }

    /**
     * Themes override templates from a `licensio-kb` directory.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_theme_override_directory_is_licensio_kb() {
        $this->assertSame( 'licensio-kb', licensio_kb_template_path() );
    }

    /**
     * A theme override wins over the plugin's own template.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_theme_template_takes_precedence_over_the_plugin() {
        $theme_file = LICENSIO_KB_ABSPATH . 'tests/theme-override.php';

        Functions\when( 'locate_template' )->alias(
            function ( $files ) use ( $theme_file ) {
                $this->assertSame( array( 'licensio-kb/licensio-kb-test-fixture.php' ), $files );

                return $theme_file;
            }
        );

        $this->assertSame( $theme_file, licensio_kb_locate_template( 'licensio-kb-test-fixture' ) );
    }

    /**
     * With no theme override the plugin's own template is used.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_plugin_template_is_the_fallback() {
        $this->assertSame( $this->classic_template, licensio_kb_locate_template( 'licensio-kb-test-fixture' ) );
    }

    /**
     * An unknown template resolves to nothing rather than erroring.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_unknown_template_resolves_to_an_empty_string() {
        $this->assertSame( '', licensio_kb_locate_template( 'no-such-template' ) );
        $this->assertSame( '', licensio_kb_get_template( 'no-such-template', array(), false ) );
    }

    /**
     * Under a classic theme the PHP template is rendered with its arguments.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_classic_theme_renders_the_php_template() {
        Functions\when( 'wp_is_block_theme' )->justReturn( false );

        $markup = licensio_kb_get_template( 'licensio-kb-test-fixture', array( 'label' => 'ok' ), false );

        $this->assertSame( 'classic:ok', $markup );
    }

    /**
     * Under a block theme the block template is used instead.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_block_theme_renders_the_block_template() {
        Functions\when( 'wp_is_block_theme' )->justReturn( true );

        $markup = licensio_kb_get_template( 'licensio-kb-test-fixture', array( 'label' => 'ok' ), false );

        $this->assertStringContainsString( '<p>block</p>', $markup );
        $this->assertStringNotContainsString( 'classic:', $markup );
    }

    /**
     * A block theme falls back to the classic template when no block template exists.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_block_theme_falls_back_to_the_classic_template() {
        Functions\when( 'wp_is_block_theme' )->justReturn( true );

        unlink( $this->block_template ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.WP.AlternativeFunctions.file_system_operations_unlink

        $markup = licensio_kb_get_template( 'licensio-kb-test-fixture', array( 'label' => 'ok' ), false );

        $this->assertSame( 'classic:ok', $markup );
    }

    /**
     * The routing decision follows wp_is_block_theme().
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_block_layer_follows_the_active_theme() {
        Functions\when( 'wp_is_block_theme' )->justReturn( true );

        $this->assertTrue( licensio_kb_use_block_templates() );

        Functions\when( 'wp_is_block_theme' )->justReturn( false );

        $this->assertFalse( licensio_kb_use_block_templates() );
    }

    /**
     * A filter can force the classic layer under a block theme.
     *
     * The named behaviour is a filter overriding a block theme, which the
     * previous version of this test never exercised: it only toggled
     * wp_is_block_theme() and watched the result follow, while Brain Monkey's
     * default apply_filters() returned its first argument unchanged.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_filter_can_force_the_classic_layer_under_a_block_theme() {
        Functions\when( 'wp_is_block_theme' )->justReturn( true );

        Filters\expectApplied( 'licensio_use_block_templates' )
            ->once()
            ->with( true )
            ->andReturn( false );

        $this->assertFalse( licensio_kb_use_block_templates() );
    }

    /**
     * A block theme's own templates/ directory overrides the plugin's.
     *
     * WordPress resolves theme overrides itself for full-page templates but not
     * for the partials #6 will resolve through this function, so without this
     * step #6 was being promised a theme override that did not exist.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_theme_block_template_overrides_the_plugin() {
        $theme_dir  = $this->theme_dir;
        $theme_file = $theme_dir . '/templates/licensio-kb-test-fixture.html';

        Functions\when( 'get_stylesheet_directory' )->justReturn( $theme_dir );
        Functions\when( 'get_template_directory' )->justReturn( $theme_dir );

        $this->assertSame( $theme_file, licensio_kb_locate_block_template( 'licensio-kb-test-fixture' ) );
    }

    /**
     * Without a theme override the plugin's own block template is used.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_plugin_block_template_is_the_fallback() {
        Functions\when( 'get_stylesheet_directory' )->justReturn( LICENSIO_KB_ABSPATH . 'tests/no-such-theme' );
        Functions\when( 'get_template_directory' )->justReturn( LICENSIO_KB_ABSPATH . 'tests/no-such-theme' );

        $this->assertSame( $this->block_template, licensio_kb_locate_block_template( 'licensio-kb-test-fixture' ) );
    }

    /**
     * A traversing template name resolves to nothing in both resolvers.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_traversing_template_name_is_refused() {
        foreach ( array( '../../../../wp-config', '/etc/passwd', 'foo/../../bar', "evil\0" ) as $name ) {
            $this->assertSame( '', licensio_kb_locate_template( $name ) );
            $this->assertSame( '', licensio_kb_locate_block_template( $name ) );
        }
    }
}
