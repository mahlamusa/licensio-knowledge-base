<?php
/**
 * Shared admin menu registration.
 *
 * This is scaffold infrastructure with two consumers — the analytics dashboard
 * and the settings screen — so the tests are about the contract those two rely
 * on rather than about either of them.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

/**
 * Drives Licensio_KB_Admin_Menu.
 *
 * @since 1.0.0
 */
class Licensio_KB_Admin_Menu_Test extends Licensio_KB_Test_Case {

    /**
     * Reset the recorded hook suffixes between tests.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        Licensio_KB_Admin_Menu::reset();

        Functions\when( 'admin_url' )->alias(
            function ( $path = '' ) {
                return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' );
            }
        );
    }

    /**
     * A valid page keeps its own values and gets the documented defaults.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_page_is_normalised_with_the_documented_defaults() {
        Filters\expectApplied( 'licensio_admin_menu_pages' )->once()->andReturn(
            array(
                array(
                    'menu_slug'  => 'licensio-kb-analytics',
                    'menu_title' => 'Analytics',
                    'callback'   => '__return_true',
                ),
            )
        );

        $pages = ( new Licensio_KB_Admin_Menu() )->pages();

        $this->assertCount( 1, $pages );
        $this->assertSame( 'licensio-kb-analytics', $pages[0]['menu_slug'] );
        $this->assertSame( 'Analytics', $pages[0]['menu_title'] );
        $this->assertSame( 'Analytics', $pages[0]['page_title'] );
        $this->assertSame( 'manage_licensio_kb', $pages[0]['capability'] );
        $this->assertSame( 10, $pages[0]['position'] );
    }

    /**
     * The capability default is the knowledge base management capability.
     *
     * An admin screen that quietly defaulted to something weaker would put the
     * analytics reports — what every visitor searched for and read — in front
     * of any subscriber who could load wp-admin.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_capability_default_is_the_management_capability() {
        Filters\expectApplied( 'licensio_admin_menu_pages' )->once()->andReturn(
            array(
                array(
                    'menu_slug' => 'a-page',
                    'callback'  => '__return_true',
                ),
            )
        );

        $pages = ( new Licensio_KB_Admin_Menu() )->pages();

        $this->assertSame( Licensio_KB_Capabilities::CAPABILITY, $pages[0]['capability'] );
    }

    /**
     * Unusable definitions are dropped rather than registered.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_unusable_definitions_are_dropped() {
        Filters\expectApplied( 'licensio_admin_menu_pages' )->once()->andReturn(
            array(
                'not-an-array',
                array( 'callback' => '__return_true' ),
                array( 'menu_slug' => 'no-callback' ),
                array(
                    'menu_slug' => 'Bad Slug',
                    'callback'  => '__return_true',
                ),
                array(
                    'menu_slug' => 'edit.php?post_type=x',
                    'callback'  => '__return_true',
                ),
                array(
                    'menu_slug' => 'good-page',
                    'callback'  => 'no_such_function_exists_here',
                ),
                array(
                    'menu_slug' => 'good-page',
                    'callback'  => '__return_true',
                ),
            )
        );

        $pages = ( new Licensio_KB_Admin_Menu() )->pages();

        $this->assertCount( 1, $pages );
        $this->assertSame( 'good-page', $pages[0]['menu_slug'] );
    }

    /**
     * Pages are ordered by position, and ties keep registration order.
     *
     * The positions are chosen so the expected order is neither the order they
     * were registered in nor the alphabetical order of their slugs: a test
     * whose expected order happens to equal its input order proves nothing
     * about sorting.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_pages_are_ordered_by_position_with_stable_ties() {
        Filters\expectApplied( 'licensio_admin_menu_pages' )->once()->andReturn(
            array(
                array(
                    'menu_slug' => 'delta',
                    'callback'  => '__return_true',
                    'position'  => 40,
                ),
                array(
                    'menu_slug' => 'alpha',
                    'callback'  => '__return_true',
                    'position'  => 20,
                ),
                array(
                    'menu_slug' => 'charlie',
                    'callback'  => '__return_true',
                    'position'  => 20,
                ),
                array(
                    'menu_slug' => 'bravo',
                    'callback'  => '__return_true',
                    'position'  => 5,
                ),
            )
        );

        $pages = ( new Licensio_KB_Admin_Menu() )->pages();

        $this->assertSame(
            array( 'bravo', 'alpha', 'charlie', 'delta' ),
            array_column( $pages, 'menu_slug' )
        );
    }

    /**
     * Every page is added under the Knowledge Base menu, not as a new top level.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_pages_are_added_under_the_knowledge_base_menu() {
        Filters\expectApplied( 'licensio_admin_menu_pages' )->once()->andReturn(
            array(
                array(
                    'menu_slug'  => 'licensio-kb-analytics',
                    'menu_title' => 'Analytics',
                    'page_title' => 'Knowledge Base Analytics',
                    'callback'   => '__return_true',
                ),
            )
        );

        $added = array();

        Functions\when( 'add_submenu_page' )->alias(
            function ( $parent_slug, $page_title, $menu_title, $capability, $slug ) use ( &$added ) {
                $added[] = compact( 'parent_slug', 'page_title', 'menu_title', 'capability', 'slug' );

                return 'knowledge-base_page_' . $slug;
            }
        );

        ( new Licensio_KB_Admin_Menu() )->register_pages();

        $this->assertCount( 1, $added );
        $this->assertSame( 'edit.php?post_type=licensio_article', $added[0]['parent_slug'] );
        $this->assertSame( 'Knowledge Base Analytics', $added[0]['page_title'] );
        $this->assertSame( 'manage_licensio_kb', $added[0]['capability'] );
    }

    /**
     * The hook suffix is recorded so a consumer can gate its own enqueue on it.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_hook_suffix_is_recorded_for_the_consumer() {
        Filters\expectApplied( 'licensio_admin_menu_pages' )->once()->andReturn(
            array(
                array(
                    'menu_slug' => 'licensio-kb-analytics',
                    'callback'  => '__return_true',
                ),
            )
        );

        Functions\when( 'add_submenu_page' )->justReturn( 'knowledge-base_page_licensio-kb-analytics' );

        $this->assertSame( '', Licensio_KB_Admin_Menu::hook_suffix( 'licensio-kb-analytics' ) );

        ( new Licensio_KB_Admin_Menu() )->register_pages();

        $this->assertSame(
            'knowledge-base_page_licensio-kb-analytics',
            Licensio_KB_Admin_Menu::hook_suffix( 'licensio-kb-analytics' )
        );
        $this->assertSame( '', Licensio_KB_Admin_Menu::hook_suffix( 'never-registered' ) );
    }

    /**
     * A page a user cannot see records no hook suffix.
     *
     * `add_submenu_page()` returns false when the current user lacks the
     * capability, and treating that as a hook suffix would enqueue the app on
     * whatever screen happened to be loading.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_refused_page_records_no_hook_suffix() {
        Filters\expectApplied( 'licensio_admin_menu_pages' )->once()->andReturn(
            array(
                array(
                    'menu_slug' => 'licensio-kb-analytics',
                    'callback'  => '__return_true',
                ),
            )
        );

        Functions\when( 'add_submenu_page' )->justReturn( false );

        ( new Licensio_KB_Admin_Menu() )->register_pages();

        $this->assertSame( '', Licensio_KB_Admin_Menu::hook_suffix( 'licensio-kb-analytics' ) );
    }

    /**
     * The page URL points at the shared parent.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_page_url_uses_the_shared_parent() {
        $this->assertSame(
            'https://example.test/wp-admin/edit.php?post_type=licensio_article&page=licensio-kb-analytics',
            Licensio_KB_Admin_Menu::page_url( 'licensio-kb-analytics' )
        );
    }

    /**
     * The menu registers on `admin_menu`, after the post type's own menu.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_menu_registers_after_the_post_type_menu() {
        $menu = new Licensio_KB_Admin_Menu();

        $menu->init_hooks();

        $this->assertSame(
            20,
            has_action( 'admin_menu', array( $menu, 'register_pages' ) )
        );
    }
}
