<?php
/**
 * The URL map and the served redirect map an import leaves behind.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */


/**
 * What an import records so the old links keep working.
 *
 * @since 1.0.0
 */
class Licensio_KB_Heroic_Import_Redirects_Test extends Licensio_KB_Test_Case {

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

        $this->directory = sys_get_temp_dir() . '/licensio-kb-redirects-' . uniqid( '', false );

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
            foreach ( array_merge( (array) glob( $this->directory . '/*' ), (array) glob( $this->directory . '/.htaccess' ) ) as $file ) {
                unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Scratch files this test created.
            }

            rmdir( $this->directory ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- The scratch directory this test created.
        }

        parent::tearDown();
    }

    /**
     * A redirects recorder for Heroic with a scratch output directory.
     *
     * @since 1.0.0
     *
     * @param bool $dry_run Whether the instance may write.
     *
     * @return Licensio_KB_Import_Redirects The instance under test.
     */
    private function redirects( $dry_run = false ) {
        return new Licensio_KB_Import_Redirects( 'heroic', $this->directory, $dry_run );
    }

    /**
     * A source reading Heroic's default slugs.
     *
     * @since 1.0.0
     *
     * @param array<string, string> $settings Heroic's stored settings.
     *
     * @return Licensio_KB_Heroic_Import_Source The source.
     */
    private function source( array $settings = array() ) {
        $this->options[ Licensio_KB_Heroic_Import_Schema::OPTION_SETTINGS ] = $settings;

        return new Licensio_KB_Heroic_Import_Source();
    }

    /**
     * Record an article the way the importer does.
     *
     * @since 1.0.0
     *
     * @param Licensio_KB_Import_Redirects $redirects The recorder.
     * @param int                          $source_id The source post ID.
     * @param int                          $target_id The target article ID.
     * @param string                       $slug      The source slug.
     *
     * @return array{old: string, new: string} The old and new URL.
     */
    private function add_article( $redirects, $source_id, $target_id, $slug ) {
        $source = $this->source();

        return $redirects->add_article(
            $source_id,
            $target_id,
            $source->old_article_url( array( 'post_name' => $slug ) ),
            $slug
        );
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
        $urls      = $this->add_article( $redirects, 12, 900, 'install-a-licence' );

        $this->assertSame(
            array( '/knowledge-base/install-a-licence' => 'https://example.test/docs/install-a-licence/' ),
            $redirects->map()
        );
        $this->assertSame( 'https://example.test/knowledge-base/install-a-licence/', $urls['old'] );
        $this->assertSame( 'https://example.test/docs/install-a-licence/', $urls['new'] );
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
            'category',
            8,
            55,
            Licensio_KB_Post_Types::TAXONOMY_CATEGORY,
            $this->source()->old_term_url(
                array(
                    'slug'     => 'renewals',
                    'taxonomy' => Licensio_KB_Heroic_Import_Schema::TAXONOMY_CATEGORY,
                )
            ),
            'renewals'
        );

        $this->assertSame(
            array( '/article-categories/renewals' => 'https://example.test/docs-category/licences/renewals/' ),
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
            'tag',
            9,
            60,
            Licensio_KB_Post_Types::TAXONOMY_TAG,
            $this->source()->old_term_url(
                array(
                    'slug'     => 'billing',
                    'taxonomy' => Licensio_KB_Heroic_Import_Schema::TAXONOMY_TAG,
                )
            ),
            'billing'
        );

        $this->assertArrayHasKey( '/article-tags/billing', $redirects->map() );
    }

    /**
     * Heroic's configured slugs are used, not the defaults.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_old_url_uses_heroics_configured_slug() {
        $source = $this->source(
            array( Licensio_KB_Heroic_Import_Schema::SETTING_ARTICLE_SLUG => 'help' )
        );

        $this->assertSame(
            'https://example.test/help/install/',
            $source->old_article_url( array( 'post_name' => 'install' ) )
        );
    }

    /**
     * A row with no slug has no old URL.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_row_without_a_slug_has_no_old_url() {
        $source = $this->source();

        $this->assertSame( '', $source->old_article_url( array( 'post_name' => '' ) ) );
        $this->assertSame( '', $source->old_term_url( array( 'slug' => '' ) ) );
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
        $this->add_article( $redirects, 12, 900, 'install' );

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
            'category',
            8,
            999,
            Licensio_KB_Post_Types::TAXONOMY_CATEGORY,
            'https://example.test/article-categories/gone/',
            'gone'
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
        $this->add_article( $redirects, 12, 900, 'install' );
        $this->add_article( $redirects, 13, 901, 'renew' );

        $lines = array_values( array_filter( explode( "\n", $this->read( $redirects->map_path() ) ) ) );

        $this->assertCount( 3, $lines, 'One header and one row per URL.' );
        $this->assertSame( '"type","source_id","target_id","old_url","new_url","note"', $lines[0] );
        $this->assertStringContainsString( '"article","12","900","https://example.test/knowledge-base/install/"', $lines[1] );
        $this->assertStringContainsString( '"article","13","901"', $lines[2] );
    }

    /**
     * The audit file is named after the source plus a random part.
     *
     * The rows name draft and private articles, so the name must not be one
     * anybody can guess from the uploads URL.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_audit_file_is_named_after_the_source_with_a_random_part() {
        $redirects = $this->redirects();

        $this->assertMatchesRegularExpression( '#^' . preg_quote( $this->directory, '#' ) . '/heroic-url-map-[A-Za-z0-9]{20}\.csv$#', $redirects->map_path() );
        $this->assertSame( $redirects->map_path(), Licensio_KB_Import_Redirects::map_path_for( 'heroic', $this->directory ) );
        $this->assertSame(
            array( 'heroic' => basename( $redirects->map_path() ) ),
            $this->options[ Licensio_KB_Import_Redirects::FILES_OPTION ],
            'The random name must be kept so every later request finds the same file.'
        );
    }

    /**
     * Every request of a run resolves the same audit file.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_every_request_resolves_the_same_audit_file() {
        $first = $this->redirects()->map_path();

        Brain\Monkey\Functions\when( 'wp_generate_password' )->justReturn( 'bbbbbbbbbbbbbbbbbbbb' );

        $this->assertSame( $first, $this->redirects()->map_path() );
    }

    /**
     * Reading a path never names a file, so a download cannot create one.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_source_without_a_file_has_no_path() {
        $this->assertSame( '', Licensio_KB_Import_Redirects::map_path_for( 'heroic', $this->directory ) );
        $this->assertSame( '', $this->redirects( true )->map_path(), 'A dry run must not name a file.' );
        $this->assertArrayNotHasKey( Licensio_KB_Import_Redirects::FILES_OPTION, $this->options );
    }

    /**
     * The download is offered under a plain name, without the random part.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_download_name_leaves_the_random_part_out() {
        $this->assertSame( 'heroic-url-map.csv', Licensio_KB_Import_Redirects::download_file_name( 'heroic' ) );
    }

    /**
     * The output directory carries a deny index.php and .htaccess.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_output_directory_denies_direct_access() {
        $this->permalinks[900] = 'https://example.test/docs/install/';

        $this->add_article( $this->redirects(), 12, 900, 'install' );

        $this->assertStringContainsString( 'Silence is golden', $this->read( $this->directory . '/index.php' ) );
        $this->assertStringContainsString( 'Require all denied', $this->read( $this->directory . '/.htaccess' ) );
        $this->assertStringContainsString( 'Deny from all', $this->read( $this->directory . '/.htaccess' ) );
    }

    /**
     * Deleting the output directory removes every file in it and the directory.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_delete_directory_removes_the_files_and_the_directory() {
        Brain\Monkey\Functions\when( 'wp_delete_file' )->alias(
            function ( $file ) {
                unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Scratch file this test created.
            }
        );

        $this->permalinks[900] = 'https://example.test/docs/install/';

        $redirects = $this->redirects();
        $this->add_article( $redirects, 12, 900, 'install' );

        $this->assertFileExists( $redirects->map_path() );

        Licensio_KB_Import_Redirects::delete_directory( $this->directory );

        $this->assertDirectoryDoesNotExist( $this->directory );
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
        $this->add_article( $redirects, 12, 900, 'install' );

        $this->assertStringContainsString( 'slug changed', $this->read( $redirects->map_path() ) );
    }

    /**
     * Starting a real run deletes the previous run's audit file.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_start_deletes_the_previous_audit_file() {
        Brain\Monkey\Functions\when( 'wp_delete_file' )->alias(
            function ( $file ) {
                unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Scratch file this test created.
            }
        );

        $this->permalinks[900] = 'https://example.test/docs/install/';

        $redirects = $this->redirects();
        $this->add_article( $redirects, 12, 900, 'install' );

        $this->assertFileExists( $redirects->map_path() );

        $this->redirects()->start();

        $this->assertFileDoesNotExist( $redirects->map_path() );
    }

    /**
     * Starting a dry run leaves the previous audit file alone.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_dry_run_start_keeps_the_previous_audit_file() {
        $this->permalinks[900] = 'https://example.test/docs/install/';

        $redirects = $this->redirects();
        $this->add_article( $redirects, 12, 900, 'install' );

        $this->redirects( true )->start();

        $this->assertFileExists( $redirects->map_path() );
    }

    /**
     * A quote in a slug cannot corrupt the audit file.
     *
     * The audit file is read back to build the served map, so a field that
     * broke out of its quotes would shift every column after it.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_quote_in_a_url_survives_the_round_trip() {
        $this->permalinks[900] = 'https://example.test/docs/o-brien/';

        $redirects = $this->redirects();
        $redirects->add_article( 12, 900, 'https://example.test/knowledge-base/"quoted"/', 'quoted' );
        $redirects->flush();

        $this->assertSame(
            array( '/knowledge-base/"quoted"' => 'https://example.test/docs/o-brien/' ),
            Licensio_KB_Import_Redirects::served_map()
        );
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
     * A dry run writes no file and no option.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_dry_run_writes_neither_the_file_nor_the_option() {
        $this->permalinks[900] = 'https://example.test/docs/install/';

        $redirects = $this->redirects( true );
        $this->add_article( $redirects, 12, 900, 'install' );

        $this->assertSame( 0, $redirects->flush() );
        $this->assertFileDoesNotExist( $redirects->map_path() );
        $this->assertDirectoryDoesNotExist( $this->directory );
        $this->assertArrayNotHasKey( Licensio_KB_Import_Redirects::OPTION, $this->options );
        $this->assertSame( array(), $this->writes );
    }

    /**
     * An object with no old URL contributes nothing.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_object_without_an_old_url_is_not_mapped() {
        $redirects = $this->redirects();
        $redirects->add_article( 12, 900, '', '' );

        $this->assertSame( 0, $redirects->count() );
        $this->assertFileDoesNotExist( $redirects->map_path() );
    }

    /**
     * A redirects recorder with no directory writes into uploads after its own.
     *
     * The wizard constructs the recorder without a directory, so the default has
     * to land the artefacts in the one place the download action looks.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_default_directory_is_the_uploads_one() {
        Brain\Monkey\Functions\when( 'wp_upload_dir' )->justReturn(
            array(
                'basedir' => sys_get_temp_dir() . '/licensio-kb-default-uploads',
                'baseurl' => 'https://example.test/wp-content/uploads',
            )
        );

        $redirects = new Licensio_KB_Import_Redirects( 'heroic' );

        $this->assertSame(
            Licensio_KB_Import_Redirects::upload_directory(),
            dirname( $redirects->map_path() ),
            'A default construction must use upload_directory().'
        );
    }

    /**
     * Flushing merges the audit file into the served map, not this request's memory.
     *
     * The wizard spans many requests and each starts with an empty in-memory
     * map; the audit file is the only record of the whole run.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_flush_merges_the_audit_file_into_the_served_map() {
        $this->permalinks[900] = 'https://example.test/docs/moved/';

        $first = $this->redirects();
        $this->add_article( $first, 12, 900, 'moved' );

        // A second instance, as the next wizard request would construct it.
        $second = $this->redirects();

        $this->assertSame( 0, $second->count(), 'A fresh instance must start empty.' );
        $this->assertSame( 1, $second->flush() );
        $this->assertSame(
            array( '/knowledge-base/moved' => 'https://example.test/docs/moved/' ),
            Licensio_KB_Import_Redirects::served_map(),
            'The served map must carry every row the run recorded, including those this fresh instance never saw.'
        );
    }

    /**
     * Flushing keeps the redirects another source already published.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_flush_keeps_redirects_from_other_sources() {
        $this->options[ Licensio_KB_Import_Redirects::OPTION ] = array( '/docs/old' => 'https://example.test/new/' );

        $this->permalinks[900] = 'https://example.test/docs/moved/';

        $redirects = $this->redirects();
        $this->add_article( $redirects, 12, 900, 'moved' );

        $this->assertSame( 2, $redirects->flush() );
        $this->assertArrayHasKey( '/docs/old', Licensio_KB_Import_Redirects::served_map() );
    }

    /**
     * Rows that were not redirects are left out of the served map.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_flush_skips_unchanged_and_unresolved_rows() {
        $this->permalinks[900] = 'https://example.test/knowledge-base/same/';

        $redirects = $this->redirects();
        $this->add_article( $redirects, 12, 900, 'same' );
        $redirects->add_term( 'category', 8, 999, Licensio_KB_Post_Types::TAXONOMY_CATEGORY, 'https://example.test/article-categories/gone/', 'gone' );

        $this->assertSame( 0, $this->redirects()->flush() );
    }

    /**
     * Paths are keyed without a trailing slash or query string.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_normalize_path_drops_the_trailing_slash_and_query() {
        $this->assertSame( '/a/b', Licensio_KB_Import_Redirects::normalize_path( 'https://example.test/a/b/?x=1' ) );
        $this->assertSame( '/a/b', Licensio_KB_Import_Redirects::normalize_path( '/a/b' ) );
        $this->assertSame( '', Licensio_KB_Import_Redirects::normalize_path( '' ) );
    }

    /**
     * The served map is empty before any import has published.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_served_map_defaults_to_empty() {
        $this->assertSame( array(), Licensio_KB_Import_Redirects::served_map() );
    }
}
