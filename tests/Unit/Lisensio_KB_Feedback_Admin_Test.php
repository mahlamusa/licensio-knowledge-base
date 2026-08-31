<?php
/**
 * Unit tests for the feedback inbox screen.
 *
 * The two things this class owes the rest of the plugin: a menu entry gated on
 * the management capability, and a real value in the usefulness column that
 * Lisensio_KB_Admin_Columns leaves as an em dash.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

use Brain\Monkey\Functions;

/**
 * Tests Lisensio_KB_Feedback_Admin.
 *
 * @since 1.0.0
 */
class Lisensio_KB_Feedback_Admin_Test extends Lisensio_KB_Test_Case {

    /**
     * The screen under test.
     *
     * @since 1.0.0
     * @var Lisensio_KB_Feedback_Admin
     */
    private $admin;

    /**
     * The post meta store backing the cached score.
     *
     * @since 1.0.0
     * @var array<string, mixed>
     */
    private $meta = array();

    /**
     * The submenu pages that were registered.
     *
     * @since 1.0.0
     * @var array<int, array<int, mixed>>
     */
    private $submenus = array();

    /**
     * Stub the WordPress surface the screen touches.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        $this->meta     = array();
        $this->submenus = array();

        Functions\when( 'admin_url' )->alias(
            function ( $path = '' ) {
                return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' );
            }
        );

        Functions\when( 'get_post_meta' )->alias(
            function ( $post_id, $key, $single = false ) {
                unset( $single );

                $index = (int) $post_id . '|' . (string) $key;

                return array_key_exists( $index, $this->meta ) ? $this->meta[ $index ] : '';
            }
        );
        Functions\when( '_n' )->alias(
            function ( $single, $plural, $number ) {
                return 1 === (int) $number ? $single : $plural;
            }
        );
        Functions\when( 'add_submenu_page' )->alias(
            function ( ...$args ) {
                $this->submenus[] = $args;

                return 'lisensio_article_page_lisensio-kb-feedback';
            }
        );
        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\when( 'wp_create_nonce' )->justReturn( 'a-nonce' );
        Functions\when( 'rest_url' )->alias(
            function ( $path = '' ) {
                return 'https://example.test/wp-json/' . ltrim( (string) $path, '/' );
            }
        );

        $this->admin = new Lisensio_KB_Feedback_Admin();
    }

    /**
     * Store a cached usefulness figure for an article.
     *
     * @since 1.0.0
     *
     * @param int    $post_id The article.
     * @param string $key     The meta key.
     * @param mixed  $value   The value.
     *
     * @return void
     */
    private function set_meta( $post_id, $key, $value ) {
        $this->meta[ (int) $post_id . '|' . (string) $key ] = $value;
    }

    /**
     * The screen registers its hooks, including the column filter.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_screen_registers_its_hooks() {
        $this->admin->init_hooks();

        $this->assertSame( 10, has_action( 'admin_menu', array( $this->admin, 'register_page' ) ) );
        $this->assertSame( 10, has_action( 'admin_enqueue_scripts', array( $this->admin, 'enqueue_app' ) ) );
        $this->assertSame(
            10,
            has_filter( 'lisensio_article_usefulness_column', array( $this->admin, 'usefulness_column' ) )
        );
    }

    /**
     * The inbox hangs off the Knowledge Base menu and needs the capability.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_inbox_is_gated_on_the_management_capability() {
        $this->admin->register_page();

        $this->assertCount( 1, $this->submenus );

        $page = $this->submenus[0];

        $this->assertSame( 'edit.php?post_type=' . Lisensio_KB_Post_Types::POST_TYPE, $page[0] );
        $this->assertSame( Lisensio_KB_Capabilities::CAPABILITY, $page[3] );
        $this->assertSame( Lisensio_KB_Feedback_Admin::PAGE_SLUG, $page[4] );
        $this->assertSame( 'lisensio_article_page_lisensio-kb-feedback', $this->admin->hook_suffix() );
    }

    /**
     * An article nobody has voted on keeps the column's em dash.
     *
     * The filter returns the value it was given, which is what makes
     * Lisensio_KB_Admin_Columns fall back to its placeholder.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_unvoted_article_keeps_the_column_placeholder() {
        $this->assertSame( '', $this->admin->usefulness_column( '', 42 ) );
    }

    /**
     * An article with votes shows its percentage and its vote count.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_voted_article_shows_its_usefulness() {
        $this->set_meta( 42, Lisensio_KB_Feedback::META_SCORE, 83 );
        $this->set_meta( 42, Lisensio_KB_Feedback::META_UP, 10 );
        $this->set_meta( 42, Lisensio_KB_Feedback::META_DOWN, 2 );

        $this->assertSame( '83% (12 votes)', $this->admin->usefulness_column( '', 42 ) );
    }

    /**
     * A single vote reads as a single vote.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_single_vote_is_singular() {
        $this->set_meta( 7, Lisensio_KB_Feedback::META_SCORE, 100 );
        $this->set_meta( 7, Lisensio_KB_Feedback::META_UP, 1 );
        $this->set_meta( 7, Lisensio_KB_Feedback::META_DOWN, 0 );

        $this->assertSame( '100% (1 vote)', $this->admin->usefulness_column( '', 7 ) );
    }

    /**
     * Zero per cent is a real score, not a missing one.
     *
     * A guard against reading the cached score as a boolean, which would make
     * an article every reader disliked look like an article nobody rated.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_zero_per_cent_is_reported() {
        $this->set_meta( 9, Lisensio_KB_Feedback::META_SCORE, 0 );
        $this->set_meta( 9, Lisensio_KB_Feedback::META_UP, 0 );
        $this->set_meta( 9, Lisensio_KB_Feedback::META_DOWN, 4 );

        $this->assertSame( '0% (4 votes)', $this->admin->usefulness_column( '', 9 ) );
    }

    /**
     * The application is handed the endpoint, a nonce and the status list.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_application_is_handed_its_configuration() {
        $data = $this->admin->app_data();

        $this->assertSame( 'https://example.test/wp-json/lisensio-kb/v1/feedback', $data['root'] );
        $this->assertSame( 'a-nonce', $data['nonce'] );
        $this->assertSame( array( 'new', 'reviewed', 'actioned' ), $data['statuses'] );
    }

    /**
     * The inbox application is not enqueued on any other screen.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_application_is_not_enqueued_on_another_screen() {
        Functions\expect( 'wp_localize_script' )->never();

        $this->admin->register_page();
        $this->admin->enqueue_app( 'edit.php' );

        // The real assertion is the never() expectation, verified on teardown.
        $this->assertNotSame( 'edit.php', $this->admin->hook_suffix() );
    }
}
