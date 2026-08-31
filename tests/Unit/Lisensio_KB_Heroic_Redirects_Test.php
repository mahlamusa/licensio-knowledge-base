<?php
/**
 * The URL map and the generated redirect drop-in.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

require_once dirname( dirname( __DIR__ ) ) . '/tools/migrate-heroic/class-lisensio-kb-heroic-schema.php';
require_once dirname( dirname( __DIR__ ) ) . '/tools/migrate-heroic/class-lisensio-kb-heroic-redirects.php';

/**
 * What the migrator writes so the old links keep working.
 *
 * @since 1.0.0
 */
class Lisensio_KB_Heroic_Redirects_Test extends Lisensio_KB_Test_Case {

    /**
     * Scratch directory for the artefacts.
     *
     * @since 1.0.0
     * @var string
     */
    private $directory = '';

    /**
     * New URLs, keyed by target ID, that the stubs will report.
     *
     * @since 1.0.0
     * @var array<int, string>
     */
    private $permalinks = array();

    /**
     * Stub the WordPress URL helpers and take a scratch directory.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        $this->directory = sys_get_temp_dir() . '/lisensio-kb-redirects-' . uniqid( '', false );

        Brain\Monkey\Functions\when( 'home_url' )->alias(
            function ( $path = '' ) {
                return 'https://example.test' . (string) $path;
            }
        );

        Brain\Monkey\Functions\when( 'untrailingslashit' )->alias(
            function ( $value ) {
                return rtrim( (string) $value, '/\\' );
            }
        );

        Brain\Monkey\Functions\when( 'wp_parse_url' )->alias(
            function ( $url, $component = -1 ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Core's wp_parse_url() delegates to exactly this.
                return -1 === $component ? parse_url( (string) $url ) : parse_url( (string) $url, $component );
            }
        );

        Brain\Monkey\Functions\when( 'wp_mkdir_p' )->alias(
            function ( $dir ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- A scratch directory in the system temp dir; WP_Filesystem is not booted in a unit test.
                return is_dir( $dir ) || mkdir( $dir, 0777, true );
            }
        );

        Brain\Monkey\Functions\when( 'is_wp_error' )->alias(
            function ( $thing ) {
                return $thing instanceof WP_Error;
            }
        );

        Brain\Monkey\Functions\when( 'get_permalink' )->alias(
            function ( $id ) {
                return isset( $this->permalinks[ (int) $id ] ) ? $this->permalinks[ (int) $id ] : '';
            }
        );

        Brain\Monkey\Functions\when( 'get_term_link' )->alias(
            function ( $id ) {
                return isset( $this->permalinks[ (int) $id ] ) ? $this->permalinks[ (int) $id ] : new WP_Error( 'invalid_term', 'no term' );
            }
        );
    }

    /**
     * Remove the scratch directory.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function tearDown(): void {
        if ( '' !== $this->directory && is_dir( $this->directory ) ) {
            foreach ( (array) glob( $this->directory . '/*' ) as $file ) {
                unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Scratch files this test created.
            }

            rmdir( $this->directory ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- The scratch directory this test created.
        }

        parent::tearDown();
    }

    /**
     * A migrator with Heroic's default slugs and a scratch output directory.
     *
     * @since 1.0.0
     *
     * @param bool $dry_run Whether the instance may write.
     *
     * @return Lisensio_KB_Heroic_Redirects The instance under test.
     */
    private function redirects( $dry_run = false ) {
        $this->options[ Lisensio_KB_Heroic_Schema::OPTION_SETTINGS ] = array();

        return new Lisensio_KB_Heroic_Redirects( $this->directory, $dry_run );
    }

    /**
     * An article that kept its slug still gets a redirect, because the base moved.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_article_maps_from_heroics_base_to_the_new_permalink() {
        $this->permalinks[900] = 'https://example.test/docs/install-a-licence/';

        $redirects = $this->redirects();
        $redirects->add_article(
            array(
                'ID'        => 12,
                'post_name' => 'install-a-licence',
            ),
            900
        );

        $this->assertSame(
            array( '/knowledge-base/install-a-licence/' => 'https://example.test/docs/install-a-licence/' ),
            $redirects->map()
        );
    }

    /**
     * A category maps from Heroic's flat URL to whatever the plugin serves now.
     *
     * Heroic's category rewrite is flat even though its terms nest, so a child
     * category's new hierarchical URL bears no resemblance to its old one. The
     * new side comes from `get_term_link()` precisely so this follows the
     * plugin's actual permalink settings rather than an assumption about them.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_child_category_maps_from_a_flat_url_to_a_nested_one() {
        $this->permalinks[55] = 'https://example.test/docs-category/licences/renewals/';

        $redirects = $this->redirects();
        $redirects->add_term(
            Lisensio_KB_Heroic_Schema::TAXONOMY_CATEGORY,
            array(
                'term_id' => 8,
                'slug'    => 'renewals',
            ),
            Lisensio_KB_Post_Types::TAXONOMY_CATEGORY,
            55
        );

        $this->assertSame(
            array( '/article-categories/renewals/' => 'https://example.test/docs-category/licences/renewals/' ),
            $redirects->map()
        );
    }

    /**
     * A tag maps from Heroic's tag base.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_tag_maps_from_heroics_tag_base() {
        $this->permalinks[60] = 'https://example.test/docs-tag/billing/';

        $redirects = $this->redirects();
        $redirects->add_term(
            Lisensio_KB_Heroic_Schema::TAXONOMY_TAG,
            array(
                'term_id' => 9,
                'slug'    => 'billing',
            ),
            Lisensio_KB_Post_Types::TAXONOMY_TAG,
            60
        );

        $this->assertArrayHasKey( '/article-tags/billing/', $redirects->map() );
    }

    /**
     * Heroic's configured slugs are used, not the defaults.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_old_url_uses_heroics_configured_slug() {
        $this->options[ Lisensio_KB_Heroic_Schema::OPTION_SETTINGS ] = array(
            Lisensio_KB_Heroic_Schema::SETTING_ARTICLE_SLUG => 'help',
        );

        $this->permalinks[900] = 'https://example.test/docs/install/';

        $redirects = new Lisensio_KB_Heroic_Redirects( $this->directory );
        $redirects->add_article(
            array(
                'ID'        => 12,
                'post_name' => 'install',
            ),
            900
        );

        $this->assertArrayHasKey( '/help/install/', $redirects->map() );
    }

    /**
     * A URL that did not move gets no redirect.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_unchanged_url_is_not_redirected_to_itself() {
        $this->permalinks[900] = 'https://example.test/knowledge-base/install/';

        $redirects = $this->redirects();
        $redirects->add_article(
            array(
                'ID'        => 12,
                'post_name' => 'install',
            ),
            900
        );

        $this->assertSame( array(), $redirects->map(), 'A redirect to the same URL is a redirect loop.' );
    }

    /**
     * A term whose new URL cannot be resolved is recorded but not redirected.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_unresolvable_new_url_is_recorded_in_the_map_file_only() {
        $redirects = $this->redirects();
        $redirects->add_term(
            Lisensio_KB_Heroic_Schema::TAXONOMY_CATEGORY,
            array(
                'term_id' => 8,
                'slug'    => 'gone',
            ),
            Lisensio_KB_Post_Types::TAXONOMY_CATEGORY,
            999
        );

        $this->assertSame( array(), $redirects->map() );

        $csv = $this->read( $redirects->map_path() );

        $this->assertStringContainsString( 'no new URL could be resolved', $csv );
    }

    /**
     * The audit file gets a header and one row per moved URL.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_audit_file_lists_every_url_with_its_ids() {
        $this->permalinks[900] = 'https://example.test/docs/install/';
        $this->permalinks[901] = 'https://example.test/docs/renew/';

        $redirects = $this->redirects();
        $redirects->add_article(
            array(
                'ID'        => 12,
                'post_name' => 'install',
            ),
            900
        );
        $redirects->add_article(
            array(
                'ID'        => 13,
                'post_name' => 'renew',
            ),
            901
        );

        $lines = array_values( array_filter( explode( "\n", $this->read( $redirects->map_path() ) ) ) );

        $this->assertCount( 3, $lines, 'One header and one row per URL.' );
        $this->assertSame( '"type","source_id","target_id","old_url","new_url","note"', $lines[0] );
        $this->assertStringContainsString( '"article","12","900","https://example.test/knowledge-base/install/"', $lines[1] );
        $this->assertStringContainsString( '"article","13","901"', $lines[2] );
    }

    /**
     * A slug that had to change is flagged in the audit file.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_changed_slug_is_flagged_in_the_audit_file() {
        $this->permalinks[900] = 'https://example.test/docs/install-12/';

        $redirects = $this->redirects();
        $redirects->add_article(
            array(
                'ID'        => 12,
                'post_name' => 'install',
            ),
            900
        );

        $this->assertStringContainsString( 'slug changed', $this->read( $redirects->map_path() ) );
    }

    /**
     * The generated drop-in is valid PHP that redirects the mapped paths.
     *
     * Asserting the file parses is the point: it is generated source that will
     * be loaded as an mu-plugin on every front-end request, and a syntax error
     * in it takes the whole site down.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_generated_drop_in_is_valid_php_and_carries_the_map() {
        $this->permalinks[900] = 'https://example.test/docs/install/';

        $redirects = $this->redirects();
        $redirects->add_article(
            array(
                'ID'        => 12,
                'post_name' => 'install',
            ),
            900
        );

        $this->assertTrue( $redirects->write_redirect_plugin() );

        $path = $redirects->redirect_path();

        $this->assertFileExists( $path );

        $source = $this->read( $path );

        $this->assertStringContainsString( "'/knowledge-base/install' => 'https://example.test/docs/install/'", $source );
        $this->assertStringContainsString( 'wp_safe_redirect( $target, 301 )', $source );
        $this->assertStringContainsString( "add_action( 'template_redirect'", $source );

        $this->assertParses( $path, 'The generated redirect drop-in does not parse' );
    }

    /**
     * A quote in a slug cannot break out of the generated literal.
     *
     * The drop-in is PHP source generated from database values. A slug carrying
     * a single quote would close the string literal and everything after it
     * would be executed on every request.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_quote_in_a_url_cannot_inject_php_into_the_drop_in() {
        $this->permalinks[900] = "https://example.test/docs/o'brien/";

        $redirects = $this->redirects();
        $redirects->add_article(
            array(
                'ID'        => 12,
                'post_name' => "quote'; system('id'); //",
            ),
            900
        );

        $this->assertTrue( $redirects->write_redirect_plugin() );

        $path = $redirects->redirect_path();

        $this->assertParses( $path, 'A quoted slug produced unparseable PHP' );
        $this->assertStringNotContainsString( "system('id')", str_replace( "\\'", '', $this->read( $path ) ) );
    }

    /**
     * Read a generated file.
     *
     * @since 1.0.0
     *
     * @param string $path The file.
     *
     * @return string The contents.
     */
    private function read( $path ) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local scratch file, not a URL.
        return (string) file_get_contents( $path );
    }

    /**
     * Assert that a generated PHP file is syntactically valid.
     *
     * `token_get_all()` with TOKEN_PARSE raises `ParseError` on invalid source,
     * which is the same parser the file would meet at runtime — and unlike
     * shelling out to `php -l` it works on hosts where process execution is
     * disabled.
     *
     * @since 1.0.0
     *
     * @param string $path    The file to parse.
     * @param string $message The failure message.
     *
     * @return void
     */
    private function assertParses( $path, $message ) {
        try {
            token_get_all( $this->read( $path ), TOKEN_PARSE );
        } catch ( ParseError $e ) {
            $this->fail( $message . ': ' . $e->getMessage() );
        }

        $this->addToAssertionCount( 1 );
    }

    /**
     * A dry run writes no file at all.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_dry_run_writes_neither_artefact() {
        $this->permalinks[900] = 'https://example.test/docs/install/';

        $redirects = $this->redirects( true );
        $redirects->add_article(
            array(
                'ID'        => 12,
                'post_name' => 'install',
            ),
            900
        );

        $this->assertFalse( $redirects->write_redirect_plugin() );
        $this->assertFileDoesNotExist( $redirects->map_path() );
        $this->assertFileDoesNotExist( $redirects->redirect_path() );
        $this->assertDirectoryDoesNotExist( $this->directory );
    }

    /**
     * An object with no slug contributes nothing.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_object_without_a_slug_is_not_mapped() {
        $redirects = $this->redirects();
        $redirects->add_article(
            array(
                'ID'        => 12,
                'post_name' => '',
            ),
            900
        );

        $this->assertSame( 0, $redirects->count() );
        $this->assertFileDoesNotExist( $redirects->map_path() );
    }
}
