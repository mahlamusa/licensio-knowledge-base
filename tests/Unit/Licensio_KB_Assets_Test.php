<?php
/**
 * Unit tests for the build asset loader.
 *
 * Covers Vite manifest parsing including the shared-chunk CSS walk, the HMR
 * gate, the block asset reader and the module tagging filter. This class
 * previously shipped with no tests at all, which is why the shared-chunk CSS
 * bug was invisible.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

use Brain\Monkey\Functions;

/**
 * Tests Licensio_KB_Assets.
 *
 * @since 1.0.0
 */
class Licensio_KB_Assets_Test extends Licensio_KB_Test_Case {

    /**
     * Absolute path of the manifest fixture.
     *
     * @since 1.0.0
     * @var string
     */
    private $manifest_path = '';

    /**
     * Contents of a real manifest that was already there, if any.
     *
     * @since 1.0.0
     * @var string|null
     */
    private $manifest_backup = null;

    /**
     * Scripts passed to wp_enqueue_script(), by handle.
     *
     * @since 1.0.0
     * @var array<string, string>
     */
    private $scripts = array();

    /**
     * Styles passed to wp_enqueue_style(), by handle.
     *
     * @since 1.0.0
     * @var array<string, string>
     */
    private $styles = array();

    /**
     * Write the manifest fixture and stub the enqueue surface.
     *
     * The fixture lives under `dist/`, which is both gitignored and excluded
     * from PHPCS, so an aborted run cannot leave a linted or committable file
     * behind.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        $this->scripts = array();
        $this->styles  = array();

        $this->manifest_path = LICENSIO_KB_DIST_PATH . '.vite/manifest.json';

        if ( ! is_dir( dirname( $this->manifest_path ) ) ) {
            mkdir( dirname( $this->manifest_path ), 0777, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
        }

        $this->manifest_backup = is_readable( $this->manifest_path )
            ? (string) file_get_contents( $this->manifest_path ) // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
            : null;

        file_put_contents( $this->manifest_path, (string) wp_json_encode( $this->manifest_fixture() ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

        Functions\when( 'wp_enqueue_script' )->alias(
            function ( $handle, $src = '' ) {
                $this->scripts[ $handle ] = (string) $src;
            }
        );
        Functions\when( 'wp_register_script' )->alias(
            function ( $handle, $src = '' ) {
                $this->scripts[ $handle ] = (string) $src;

                return true;
            }
        );
        Functions\when( 'wp_enqueue_style' )->alias(
            function ( $handle, $src = '' ) {
                $this->styles[ $handle ] = (string) $src;
            }
        );
        Functions\when( 'wp_get_environment_type' )->justReturn( 'local' );
    }

    /**
     * Restore whatever manifest was there before.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function tearDown(): void {
        if ( null === $this->manifest_backup ) {
            if ( file_exists( $this->manifest_path ) ) {
                unlink( $this->manifest_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.WP.AlternativeFunctions.file_system_operations_unlink
            }
        } else {
            file_put_contents( $this->manifest_path, $this->manifest_backup ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        }

        parent::tearDown();
    }

    /**
     * A manifest shaped like a real Vite build with a shared chunk.
     *
     * `apps/analytics` and `apps/settings` both import `_shared.js`, which is
     * exactly what happens the moment two admin apps use the same Vue or Ant
     * Design component. The shared chunk carries its own CSS.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed> The manifest.
     */
    private function manifest_fixture() {
        return array(
            'apps/analytics/index.ts' => array(
                'file'    => 'apps/analytics/index.abc123.js',
                'isEntry' => true,
                'imports' => array( '_shared.def456.js' ),
                'css'     => array( 'assets/analytics.111111.css' ),
            ),
            'apps/settings/index.ts'  => array(
                'file'    => 'apps/settings/index.ghi789.js',
                'isEntry' => true,
                'imports' => array( '_shared.def456.js' ),
            ),
            '_shared.def456.js'       => array(
                'file'    => 'chunks/shared.def456.js',
                'imports' => array( '_vendor.jkl012.js' ),
                'css'     => array( 'assets/shared.222222.css' ),
            ),
            '_vendor.jkl012.js'       => array(
                'file' => 'chunks/vendor.jkl012.js',
                'css'  => array( 'assets/vendor.333333.css' ),
            ),
        );
    }

    /**
     * The CSS of an imported shared chunk is collected, not silently dropped.
     *
     * Vite attaches a shared chunk's CSS to that chunk's own manifest entry, not
     * to the entry importing it. Reading only the entry's `css` array left the
     * shared component styles off the page with no console error, no PHP
     * warning and nothing in the manifest to suggest a problem.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_shared_chunk_css_is_collected_from_imports() {
        $assets = new Licensio_KB_Assets();

        $styles = $assets->entry_styles( 'apps/analytics/index.ts' );

        $this->assertContains( 'assets/analytics.111111.css', $styles, 'The entry own CSS must be enqueued.' );
        $this->assertContains( 'assets/shared.222222.css', $styles, 'The imported shared chunk CSS must be enqueued.' );
    }

    /**
     * The import graph is walked to any depth, not just one level.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_transitively_imported_chunk_css_is_collected() {
        $assets = new Licensio_KB_Assets();

        $this->assertContains( 'assets/vendor.333333.css', $assets->entry_styles( 'apps/analytics/index.ts' ) );
    }

    /**
     * An entry with no CSS of its own still gets its shared chunk's CSS.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_entry_without_own_css_still_gets_its_imports() {
        $assets = new Licensio_KB_Assets();

        $styles = $assets->entry_styles( 'apps/settings/index.ts' );

        $this->assertSame(
            array( 'assets/vendor.333333.css', 'assets/shared.222222.css' ),
            $styles
        );
    }

    /**
     * A stylesheet reached by two paths is enqueued once.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_stylesheets_are_deduplicated() {
        $assets = new Licensio_KB_Assets();

        $styles = $assets->entry_styles( 'apps/analytics/index.ts' );

        $this->assertSame( count( $styles ), count( array_unique( $styles ) ) );
    }

    /**
     * Enqueuing an app enqueues its script and every stylesheet it needs.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_enqueue_app_enqueues_the_script_and_all_styles() {
        $assets = new Licensio_KB_Assets();

        $this->assertTrue( $assets->enqueue_app( 'licensio-analytics', 'apps/analytics/index.ts' ) );

        $this->assertSame(
            LICENSIO_KB_DIST_URL . 'apps/analytics/index.abc123.js',
            $this->scripts['licensio-analytics']
        );
        $this->assertCount( 3, $this->styles );
        $this->assertContains( LICENSIO_KB_DIST_URL . 'assets/shared.222222.css', array_values( $this->styles ) );
    }

    /**
     * The chunks an entry imports are listed to any depth, without the entry.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_entry_chunks_walks_the_import_graph() {
        $assets = new Licensio_KB_Assets();

        $this->assertSame(
            array( 'chunks/shared.def456.js', 'chunks/vendor.jkl012.js' ),
            $assets->entry_chunks( 'apps/analytics/index.ts' )
        );
    }

    /**
     * An app that uses wp-i18n gets the translations of its imported chunks.
     *
     * WordPress only loads the JSON file of the handle's own script, so a
     * string in a shared chunk would stay untranslated without this.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_chunk_translations_are_printed_before_an_i18n_app() {
        $inline     = array();
        $registered = array();

        Functions\when( 'wp_register_script' )->alias(
            function ( $handle, $src = '' ) use ( &$registered ) {
                $registered[ $handle ] = (string) $src;

                return true;
            }
        );
        Functions\when( 'wp_deregister_script' )->justReturn( null );
        Functions\when( 'load_script_textdomain' )->alias(
            function ( $handle ) use ( &$registered ) {
                $is_shared = false !== strpos( $registered[ $handle ], 'chunks/shared.def456.js' );

                return $is_shared ? '{"locale_data":{"messages":{"":{},"Save":["Speichern"]}}}' : false;
            }
        );
        Functions\when( 'wp_add_inline_script' )->alias(
            function ( $handle, $data, $position = 'after' ) use ( &$inline ) {
                $inline[] = array( $handle, $data, $position );

                return true;
            }
        );

        $assets = new Licensio_KB_Assets();

        $this->assertTrue( $assets->enqueue_app( 'licensio-analytics', 'apps/analytics/index.ts', array( 'wp-i18n' ) ) );

        $this->assertCount( 1, $inline, 'Only the chunk with a translation file prints anything.' );
        $this->assertSame( 'licensio-analytics', $inline[0][0] );
        $this->assertSame( 'before', $inline[0][2] );
        $this->assertStringContainsString( '"licensio-knowledge-base"', $inline[0][1] );
        $this->assertStringContainsString( 'Speichern', $inline[0][1] );
        $this->assertStringContainsString( 'wp.i18n.setLocaleData', $inline[0][1] );
    }

    /**
     * An app without wp-i18n does not look for chunk translations.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_chunk_translations_need_the_i18n_dependency() {
        Functions\expect( 'load_script_textdomain' )->never();

        $assets = new Licensio_KB_Assets();

        $this->assertTrue( $assets->enqueue_app( 'licensio-analytics', 'apps/analytics/index.ts' ) );
    }

    /**
     * An unknown entry enqueues nothing and says so.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_unknown_entry_enqueues_nothing() {
        $assets = new Licensio_KB_Assets();

        $this->assertFalse( $assets->enqueue_app( 'licensio-missing', 'apps/nope/index.ts' ) );
        $this->assertSame( array(), $this->scripts );
    }

    /**
     * HMR stays off when the constant is absent, whatever else is true.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_hmr_is_disabled_without_the_constant() {
        $assets = new Licensio_KB_Assets();

        $this->assertFalse( $assets->is_hmr_enabled() );
    }

    /**
     * The shared frontend namespace is registered as a classic script.
     *
     * It must be served from `assets/`, not from the Vite `dist/` output: a Vite
     * entry is an ES module, module scripts execute after every classic script,
     * and the plugin's frontend scripts are classic by design — so a classic
     * script declaring this as a dependency would still run first and find the
     * global undefined.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_frontend_namespace_is_registered_from_assets_not_dist() {
        $assets = new Licensio_KB_Assets();

        $assets->register_frontend_namespace();

        $this->assertSame(
            LICENSIO_KB_ASSETS_URL . 'js/licensio-kb.js',
            $this->scripts[ Licensio_KB_Assets::FRONTEND_NAMESPACE_HANDLE ]
        );
        $this->assertStringNotContainsString( 'dist/', $this->scripts[ Licensio_KB_Assets::FRONTEND_NAMESPACE_HANDLE ] );
    }

    /**
     * The shared frontend namespace source contains no ES module syntax.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_frontend_namespace_is_not_an_es_module() {
        $source = (string) file_get_contents( LICENSIO_KB_ABSPATH . 'assets/js/licensio-kb.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

        $this->assertDoesNotMatchRegularExpression( '/^\s*export\s/m', $source );
        $this->assertDoesNotMatchRegularExpression( '/^\s*import\s/m', $source );
    }

    /**
     * A block asset name that is not a plain slug is refused.
     *
     * The name becomes a path that is include()d.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_block_asset_rejects_a_traversing_name() {
        $assets = new Licensio_KB_Assets();

        foreach ( array( '../../../..', 'foo/../bar', 'Foo', 'foo bar', '' ) as $name ) {
            $asset = $assets->block_asset( $name );

            $this->assertSame( array(), $asset['dependencies'] );
            $this->assertSame( LICENSIO_KB_VERSION, $asset['version'] );
        }
    }

    /**
     * A missing block asset file degrades to empty dependencies.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_block_asset_falls_back_when_the_file_is_missing() {
        $assets = new Licensio_KB_Assets();

        $asset = $assets->block_asset( 'no-such-block' );

        $this->assertSame( array(), $asset['dependencies'] );
        $this->assertSame( LICENSIO_KB_VERSION, $asset['version'] );
    }

    /**
     * Registered Vite handles are tagged as ES modules; others are untouched.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_only_registered_handles_are_tagged_as_modules() {
        $assets = new Licensio_KB_Assets();
        $assets->enqueue_app( 'licensio-analytics', 'apps/analytics/index.ts' );

        $tag = "<script src='https://example.test/app.js'></script>"; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript

        $this->assertStringContainsString( 'type="module"', $assets->add_module_type( $tag, 'licensio-analytics' ) );
        $this->assertSame( $tag, $assets->add_module_type( $tag, 'some-other-plugin' ) );
    }

    /**
     * An inline translation containing ` type=` cannot stop the module type.
     *
     * The filtered markup carries the handle's inline scripts before its own
     * tag. Only the opening tag with a `src` attribute may change, so the
     * translation text comes back exactly as it went in.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_inline_translation_with_type_does_not_block_the_module_type() {
        $assets = new Licensio_KB_Assets();
        $assets->enqueue_app( 'licensio-analytics', 'apps/analytics/index.ts' );

        $inline = '<script id="licensio-analytics-js-before">wp.i18n.setLocaleData( { "Pick a type=": [ "Choose a src= type=" ] } );</script>';
        $tag    = $inline . "\n" . '<script src="https://example.test/app.js" id="licensio-analytics-js"></script>'; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript

        $filtered = $assets->add_module_type( $tag, 'licensio-analytics' );

        $this->assertSame(
            $inline . "\n" . '<script type="module" src="https://example.test/app.js" id="licensio-analytics-js"></script>', // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript
            $filtered
        );
    }

    /**
     * A `text/javascript` type on the source tag becomes `module`.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_text_javascript_type_becomes_module() {
        $assets = new Licensio_KB_Assets();
        $assets->enqueue_app( 'licensio-analytics', 'apps/analytics/index.ts' );

        $tag = "<script type='text/javascript' src='https://example.test/app.js' id='licensio-analytics-js'></script>"; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript

        $this->assertSame(
            "<script type=\"module\" src='https://example.test/app.js' id='licensio-analytics-js'></script>", // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript
            $assets->add_module_type( $tag, 'licensio-analytics' )
        );
    }

    /**
     * dist_url() builds a URL under the build output directory.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_dist_url_joins_the_build_directory() {
        $assets = new Licensio_KB_Assets();

        $this->assertSame( LICENSIO_KB_DIST_URL . 'blocks/search/index.js', $assets->dist_url( '/blocks/search/index.js' ) );
    }
}
