<?php
/**
 * The notice that explains a missing admin app build.
 *
 * Covers when the notice shows (a manager, a knowledge base screen, a missing
 * app entry and no dev server) and every condition that keeps it away,
 * including a complete build, which is what a release install has.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

use Brain\Monkey\Functions;

/**
 * Drives Licensio_KB_Build_Notice.
 *
 * @since 1.0.0
 */
class Licensio_KB_Build_Notice_Test extends Licensio_KB_Test_Case {

    /**
     * Stub the capability check as a manager.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        Functions\when( 'current_user_can' )->justReturn( true );
    }

    /**
     * An asset loader whose manifest holds the given app entries.
     *
     * @since 1.0.0
     *
     * @param string[] $built The app entries the manifest has a file for.
     * @param bool     $hmr   Whether the dev server serves the apps.
     *
     * @return Licensio_KB_Assets The asset loader.
     */
    private function assets( array $built, $hmr = false ) {
        return new class( $built, $hmr ) extends Licensio_KB_Assets {

            /**
             * The app entries the manifest has a file for.
             *
             * @since 1.0.0
             * @var string[]
             */
            private $built;

            /**
             * Whether the dev server serves the apps.
             *
             * @since 1.0.0
             * @var bool
             */
            private $hmr;

            /**
             * How many times the dev server was asked about.
             *
             * @since 1.0.0
             * @var int
             */
            public $hmr_probes = 0;

            /**
             * Build the double.
             *
             * @since 1.0.0
             *
             * @param string[] $built The app entries the manifest has a file for.
             * @param bool     $hmr   Whether the dev server serves the apps.
             */
            public function __construct( array $built, $hmr ) {
                $this->built = $built;
                $this->hmr   = $hmr;
            }

            /**
             * The manifest chunk of an entry.
             *
             * @since 1.0.0
             *
             * @param string $entry The entry.
             *
             * @return array<string, mixed> The chunk, or an empty array when it was not built.
             */
            public function manifest_entry( $entry ) {
                return in_array( $entry, $this->built, true ) ? array( 'file' => 'apps/x.js' ) : array();
            }

            /**
             * Whether the dev server serves the apps.
             *
             * @since 1.0.0
             *
             * @return bool The configured answer.
             */
            public function is_hmr_enabled() {
                ++$this->hmr_probes;

                return $this->hmr;
            }
        };
    }

    /**
     * A screen double.
     *
     * @since 1.0.0
     *
     * @param string $id        The screen ID.
     * @param string $base      The screen base.
     * @param string $post_type The post type.
     * @param string $taxonomy  The taxonomy.
     *
     * @return WP_Screen The screen.
     */
    private function screen( $id, $base = '', $post_type = '', $taxonomy = '' ) {
        $screen            = new WP_Screen( $taxonomy, $id );
        $screen->base      = $base;
        $screen->post_type = $post_type;

        return $screen;
    }

    /**
     * Render the notice for a screen and capture the output.
     *
     * @since 1.0.0
     *
     * @param Licensio_KB_Assets $assets The asset loader.
     * @param WP_Screen|null     $screen The current screen.
     *
     * @return string The printed HTML.
     */
    private function render( Licensio_KB_Assets $assets, $screen ) {
        Functions\when( 'get_current_screen' )->justReturn( $screen );

        ob_start();
        ( new Licensio_KB_Build_Notice( $assets ) )->maybe_render();

        return (string) ob_get_clean();
    }

    /**
     * The settings app page, where a missing build leaves the screen loading.
     *
     * @since 1.0.0
     *
     * @return WP_Screen The screen.
     */
    private function settings_page() {
        return $this->screen( 'licensio_article_page_licensio-kb-settings' );
    }

    /**
     * It hooks into the admin notices.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_it_hooks_the_admin_notices() {
        $notice = new Licensio_KB_Build_Notice( $this->assets( array() ) );
        $notice->init_hooks();

        $this->assertNotFalse( has_action( 'admin_notices', array( $notice, 'maybe_render' ) ) );
    }

    /**
     * A missing build is explained, with both ways to fix it.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_missing_build_shows_the_notice() {
        $html = $this->render( $this->assets( array() ), $this->settings_page() );

        $this->assertStringContainsString( 'notice-error', $html );
        $this->assertStringContainsString( 'built files are missing', $html );
        $this->assertStringContainsString( 'release zip', $html );
        $this->assertStringContainsString( '<code>npm run build</code>', $html );
    }

    /**
     * One missing app is enough to show the notice.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_one_missing_app_shows_the_notice() {
        $built = array_diff( Licensio_KB_Build_Notice::app_entries(), array( Licensio_KB_Settings_Admin::APP_ENTRY ) );

        $this->assertStringContainsString( 'licensio-kb-build-notice', $this->render( $this->assets( $built ), $this->settings_page() ) );
    }

    /**
     * A complete build, as in a release install, shows nothing and never probes the dev server.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_complete_build_shows_nothing() {
        $assets = $this->assets( Licensio_KB_Build_Notice::app_entries() );

        $this->assertSame( '', $this->render( $assets, $this->settings_page() ) );
        $this->assertSame( 0, $assets->hmr_probes );
    }

    /**
     * The dev server serving the apps counts as built.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_dev_server_shows_nothing() {
        $this->assertSame( '', $this->render( $this->assets( array(), true ), $this->settings_page() ) );
    }

    /**
     * Users who cannot manage the knowledge base never see it.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_non_managers_see_nothing() {
        Functions\when( 'current_user_can' )->justReturn( false );

        $this->assertSame( '', $this->render( $this->assets( array() ), $this->settings_page() ) );
    }

    /**
     * Screens outside the knowledge base, and no screen at all, show nothing.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_other_screens_show_nothing() {
        $assets = $this->assets( array() );

        $this->assertSame( '', $this->render( $assets, $this->screen( 'dashboard', 'dashboard' ) ) );
        $this->assertSame( '', $this->render( $assets, $this->screen( 'edit-post', 'edit', 'post' ) ) );
        $this->assertSame( '', $this->render( $assets, $this->screen( 'edit-post_tag', 'edit-tags', 'post', 'post_tag' ) ) );
        $this->assertSame( '', $this->render( $assets, $this->screen( 'settings_page_other-plugin', 'settings_page_other-plugin' ) ) );
        $this->assertSame( '', $this->render( $assets, null ) );
    }

    /**
     * The knowledge base screens the notice is scoped to.
     *
     * @since 1.0.0
     *
     * @return array<string, array<int, WP_Screen>> One screen per case.
     */
    public function kb_screens() {
        return array(
            'plugin page in the menu' => array( $this->screen( 'licensio_article_page_licensio-kb-tools' ) ),
            'hidden app page'         => array( $this->screen( 'admin_page_licensio-kb-articles' ) ),
            'core article list'       => array( $this->screen( 'edit-licensio_article', 'edit', 'licensio_article' ) ),
            'core category screen'    => array( $this->screen( 'edit-licensio_category', 'edit-tags', 'licensio_article', 'licensio_category' ) ),
            'core tag edit screen'    => array( $this->screen( 'edit-licensio_tag', 'term', 'licensio_article', 'licensio_tag' ) ),
        );
    }

    /**
     * It shows on every knowledge base screen.
     *
     * @since 1.0.0
     *
     * @dataProvider kb_screens
     *
     * @param WP_Screen $screen The screen.
     *
     * @return void
     */
    public function test_it_shows_on_knowledge_base_screens( WP_Screen $screen ) {
        $this->assertTrue( Licensio_KB_Build_Notice::is_kb_screen( $screen ) );
        $this->assertStringContainsString( 'licensio-kb-build-notice', $this->render( $this->assets( array() ), $screen ) );
    }

    /**
     * The article editor does not use the admin apps, so it is left out.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_article_editor_is_left_out() {
        $this->assertFalse( Licensio_KB_Build_Notice::is_kb_screen( $this->screen( 'licensio_article', 'post', 'licensio_article' ) ) );
    }

    /**
     * Every admin app is checked, not only the three content manager apps.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_every_admin_app_is_checked() {
        $entries = Licensio_KB_Build_Notice::app_entries();

        $this->assertCount( 7, array_unique( $entries ) );
        $this->assertContains( Licensio_KB_Feedback_Admin::APP_ENTRY, $entries );
        $this->assertContains( Licensio_KB_Analytics_Admin::APP_ENTRY, $entries );
    }
}
