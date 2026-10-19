<?php
/**
 * Unit tests for the redirect server.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

/**
 * Tests Licensio_KB_Import_Redirect_Server.
 *
 * @since 1.0.0
 */
class Licensio_KB_Import_Redirect_Server_Test extends Licensio_KB_Test_Case {

    /**
     * Stub the URL helpers the path normaliser uses.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

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
    }

    /**
     * A moved path resolves to its new URL.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_moved_path_resolves_to_its_new_url() {
        $map = array( '/knowledge-base/install' => 'https://example.test/docs/install/' );

        $this->assertSame(
            'https://example.test/docs/install/',
            Licensio_KB_Import_Redirect_Server::target_for( '/knowledge-base/install', $map )
        );
    }

    /**
     * The query string of the old link is carried over.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_query_string_is_carried_over() {
        $map = array( '/knowledge-base/install' => 'https://example.test/docs/install/' );

        $this->assertSame(
            'https://example.test/docs/install/?utm_source=mail',
            Licensio_KB_Import_Redirect_Server::target_for( '/knowledge-base/install/?utm_source=mail', $map )
        );
    }

    /**
     * A query string is appended with an ampersand when the target has one.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_query_string_joins_an_existing_one() {
        $map = array( '/old' => 'https://example.test/?p=5' );

        $this->assertSame(
            'https://example.test/?p=5&a=1',
            Licensio_KB_Import_Redirect_Server::target_for( '/old?a=1', $map )
        );
    }

    /**
     * A trailing slash on the request does not matter.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_trailing_slash_is_normalised() {
        $map = array( '/knowledge-base/install' => 'https://example.test/docs/install/' );

        $this->assertSame(
            'https://example.test/docs/install/',
            Licensio_KB_Import_Redirect_Server::target_for( '/knowledge-base/install/', $map )
        );
    }

    /**
     * A path nothing moved returns an empty string.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_unknown_path_returns_an_empty_string() {
        $map = array( '/knowledge-base/install' => 'https://example.test/docs/install/' );

        $this->assertSame( '', Licensio_KB_Import_Redirect_Server::target_for( '/somewhere-else/', $map ) );
        $this->assertSame( '', Licensio_KB_Import_Redirect_Server::target_for( '', $map ) );
        $this->assertSame( '', Licensio_KB_Import_Redirect_Server::target_for( '/knowledge-base/install', array() ) );
    }

    /**
     * A browser's uppercase percent-escapes match a slug WordPress stored in lowercase.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_percent_escapes_match_in_any_case() {
        $map = array( Licensio_KB_Import_Redirects::normalize_path( 'http://example.test/knowledge-base/%d0%b0%d0%b1/' ) => 'http://example.test/docs/ab/' );

        $this->assertSame( 'http://example.test/docs/ab/', Licensio_KB_Import_Redirect_Server::target_for( '/knowledge-base/%D0%B0%D0%B1/', $map ) );
    }
}
