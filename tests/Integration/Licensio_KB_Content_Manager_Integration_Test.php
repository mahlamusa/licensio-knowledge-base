<?php
/**
 * The core screen redirects against a live database.
 *
 * The article list redirect turns the `licensio_category` and `licensio_tag`
 * slugs core puts in its filter links into the term IDs the Articles app
 * reads. These tests resolve those slugs against real term rows, through
 * Licensio_KB_Live_Term_API, and drive the escape hatch through the settings
 * store and the filter both ways.
 *
 * The `load-*` hooks themselves need a running WordPress, so the redirect
 * response is covered by the browser pass, not here.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

/*
 * This suite connects to a real database on purpose. The WordPress Database API
 * is therefore not available or appropriate here.
 */
// phpcs:disable WordPress.DB.RestrictedClasses, WordPress.DB.RestrictedFunctions, WordPress.DB.DirectDatabaseQuery

require_once __DIR__ . '/Licensio_KB_Analytics_DB_Test_Case.php';
require_once __DIR__ . '/trait-licensio-kb-content-fixtures.php';
require_once __DIR__ . '/trait-licensio-kb-live-term-api.php';

use Brain\Monkey\Functions;

/**
 * Exercises Licensio_KB_Content_Manager against a live server.
 *
 * @since 1.0.0
 */
class Licensio_KB_Content_Manager_Integration_Test extends Licensio_KB_Analytics_DB_Test_Case {

    use Licensio_KB_Content_Fixtures;
    use Licensio_KB_Live_Term_API;

    /**
     * The Articles app URL the redirects start from.
     *
     * @since 1.0.0
     * @var string
     */
    const ARTICLES_URL = 'https://example.test/wp-admin/admin.php?page=licensio-kb-articles';

    /**
     * Add the core tables, stand in the term API and the URL helpers.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        $this->install_content_schemas();
        $this->stub_category_term_api();

        Functions\when( 'admin_url' )->alias(
            function ( $path = '' ) {
                return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' );
            }
        );
        Functions\when( 'add_query_arg' )->alias(
            function ( $args, $url ) {
                foreach ( $args as $key => $value ) {
                    $url .= '&' . $key . '=' . $value;
                }

                return $url;
            }
        );
        Functions\when( 'sanitize_key' )->alias(
            function ( $key ) {
                return (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
            }
        );
        Functions\when( 'sanitize_title' )->alias(
            function ( $title ) {
                return trim( (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $title ) ), '-' );
            }
        );
    }

    /**
     * A content manager reading the given classic screens value.
     *
     * @since 1.0.0
     *
     * @param bool $classic Whether the classic screens are on.
     *
     * @return Licensio_KB_Content_Manager The content manager.
     */
    private function manager( $classic = false ) {
        return new Licensio_KB_Content_Manager( new Licensio_KB_Integration_Settings( array( 'classic_screens' => $classic ) ) );
    }

    /**
     * Category and tag slugs become the IDs of the terms in those taxonomies.
     *
     * A tag and a category share the slug `billing`, so a lookup that ignored
     * the taxonomy would carry the wrong ID.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_term_slugs_become_the_ids_of_the_right_terms() {
        $tag      = $this->make_term( 'Billing', Licensio_KB_Post_Types::TAXONOMY_TAG );
        $category = $this->make_term( 'Billing', Licensio_KB_Post_Types::TAXONOMY_CATEGORY );
        $how_to   = $this->make_term( 'How To', Licensio_KB_Post_Types::TAXONOMY_TAG );

        $this->assertNotSame( $tag, $category );

        $this->assertSame(
            self::ARTICLES_URL . '&status=draft&search=invoice&category=' . $category . '&tag=' . $how_to,
            $this->manager()->redirect_url(
                'edit.php',
                array(
                    'post_type'         => 'licensio_article',
                    'post_status'       => 'draft',
                    's'                 => 'invoice',
                    'licensio_category' => 'billing',
                    'licensio_tag'      => 'how-to',
                )
            )
        );

        $this->assertSame(
            self::ARTICLES_URL . '&tag=' . $tag,
            $this->manager()->redirect_url(
                'edit.php',
                array(
                    'post_type'    => 'licensio_article',
                    'licensio_tag' => 'billing',
                )
            )
        );
    }

    /**
     * An all-digit slug is a slug, and a slug with no term is dropped.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_numeric_and_unknown_slugs() {
        $year = $this->make_term( '2024', Licensio_KB_Post_Types::TAXONOMY_CATEGORY );

        $this->assertSame(
            self::ARTICLES_URL . '&category=' . $year,
            $this->manager()->redirect_url(
                'edit.php',
                array(
                    'post_type'         => 'licensio_article',
                    'licensio_category' => '2024',
                )
            )
        );

        $this->assertSame(
            self::ARTICLES_URL,
            $this->manager()->redirect_url(
                'edit.php',
                array(
                    'post_type'         => 'licensio_article',
                    'licensio_category' => (string) $year,
                    'licensio_tag'      => 'no-such-tag',
                )
            ),
            'A term ID is not a slug, and an unknown slug is not carried.'
        );
    }

    /**
     * The classic screens setting turns the redirects and menu removal off.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_classic_screens_setting_turns_the_content_manager_off() {
        Functions\expect( 'remove_submenu_page' )->never();
        Functions\expect( 'wp_safe_redirect' )->never();

        $manager = $this->manager( true );

        $this->assertFalse( $manager->enabled() );

        $manager->remove_core_menu_items();
        $manager->maybe_redirect();
    }

    /**
     * The filter turns the content manager off, and on again over the setting.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_filter_turns_the_content_manager_off_and_on() {
        $answer = false;

        Functions\when( 'apply_filters' )->alias(
            function ( $hook, $value ) use ( &$answer ) {
                return 'licensio_kb_use_content_manager' === $hook ? $answer : $value;
            }
        );

        $this->assertFalse( $this->manager()->enabled(), 'The filter turns it off.' );

        $answer = true;

        $this->assertTrue( $this->manager()->enabled(), 'The filter leaves it on.' );
        $this->assertFalse( $this->manager( true )->enabled(), 'The setting wins before the filter runs.' );
    }
}
