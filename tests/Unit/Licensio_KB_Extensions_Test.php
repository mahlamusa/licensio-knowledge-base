<?php
/**
 * The extension registry of the content manager apps.
 *
 * @package Licensio_KB
 * @since   1.1.0
 */

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;

/**
 * Drives Licensio_KB_Extensions and Licensio_KB_Extension_Config.
 *
 * @since 1.1.0
 */
class Licensio_KB_Extensions_Test extends Licensio_KB_Test_Case {

    /**
     * The registry under test.
     *
     * @since 1.1.0
     * @var Licensio_KB_Extensions
     */
    private $extensions;

    /**
     * The capabilities the current user holds.
     *
     * @since 1.1.0
     * @var string[]
     */
    private $caps = array();

    /**
     * The messages of the declarations that were refused.
     *
     * @since 1.1.0
     * @var string[]
     */
    private $refused = array();

    /**
     * Build a registry with the capability checks stubbed.
     *
     * @since 1.1.0
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        $this->caps    = array( 'edit_licensio_articles', 'manage_licensio_kb' );
        $this->refused = array();

        Functions\when( 'current_user_can' )->alias(
            function ( $capability ) {
                return in_array( $capability, $this->caps, true );
            }
        );
        Functions\when( 'get_post_type_object' )->justReturn(
            (object) array(
                'cap' => (object) Licensio_KB_Capabilities::post_type_capabilities(),
            )
        );
        Functions\when( '_doing_it_wrong' )->alias(
            function ( $method, $message ) {
                unset( $method );

                $this->refused[] = $message;
            }
        );

        $this->extensions = new Licensio_KB_Extensions();
    }

    /**
     * The registry is filled on admin_init and on rest_api_init.
     *
     * @since 1.1.0
     *
     * @return void
     */
    public function test_the_registry_is_filled_on_admin_and_rest_requests() {
        $this->extensions->init_hooks();

        $this->assertSame( 5, has_action( 'admin_init', array( $this->extensions, 'register' ) ), 'Filled before other admin_init callbacks at the default priority.' );
        $this->assertSame( 5, has_action( 'rest_api_init', array( $this->extensions, 'register' ) ), 'Filled before the REST controllers register their routes.' );
        $this->assertLessThan( 10, Licensio_KB_Extensions::PRIORITY );
    }

    /**
     * An admin page that also builds the REST server asks for extensions once.
     *
     * @since 1.1.0
     *
     * @return void
     */
    public function test_the_register_action_fires_once_per_request() {
        Actions\expectDone( 'licensio_kb_register_extensions' )->once()->with( $this->extensions );

        $this->extensions->register();
        $this->extensions->register();
    }

    /**
     * A full declaration of each kind is kept as given, in order.
     *
     * @since 1.1.0
     *
     * @return void
     */
    public function test_each_kind_of_item_is_kept() {
        $this->assertTrue(
            $this->extensions->add_column(
                'articles',
                'acme/score',
                array(
                    'label'           => 'Score',
                    'group'           => 'Acme',
                    'default_visible' => 1,
                    'sortable'        => true,
                    'position'        => 'tags',
                    'capability'      => 'manage_licensio_kb',
                )
            )
        );
        $this->assertTrue( $this->extensions->add_column( 'articles', 'acme/notes', array( 'label' => 'Notes' ) ) );
        $this->assertTrue(
            $this->extensions->add_filter(
                'articles',
                'acme/seo',
                array(
                    'label'     => 'SEO',
                    'options'   => array(
                        'Missing_Meta' => 'Missing meta',
                        'low-score'    => 'Low score',
                    ),
                    'query_arg' => 'seo_filter',
                )
            )
        );
        $this->assertTrue( $this->extensions->add_bulk_action( 'articles', 'acme/check', array( 'label' => 'Check' ) ) );
        $this->assertTrue( $this->extensions->add_row_action( 'categories', 'acme/check', array( 'label' => 'Check' ) ) );
        $this->assertTrue( $this->extensions->add_row_action( 'tags', 'acme/edit', array( 'label' => 'Edit' ) ) );

        $this->assertSame(
            array(
                'acme/score' => array(
                    'key'             => 'acme/score',
                    'label'           => 'Score',
                    'capability'      => 'manage_licensio_kb',
                    'group'           => 'Acme',
                    'default_visible' => true,
                    'sortable'        => true,
                    'position'        => 'tags',
                ),
                'acme/notes' => array(
                    'key'             => 'acme/notes',
                    'label'           => 'Notes',
                    'capability'      => 'edit_licensio_articles',
                    'group'           => '',
                    'default_visible' => false,
                    'sortable'        => false,
                    'position'        => '',
                ),
            ),
            $this->extensions->items( 'articles', 'columns' )
        );
        $this->assertSame(
            array(
                'missing_meta' => 'Missing meta',
                'low-score'    => 'Low score',
            ),
            $this->extensions->items( 'articles', 'filters' )['acme/seo']['options']
        );
        $this->assertSame( 'seo_filter', $this->extensions->items( 'articles', 'filters' )['acme/seo']['query_arg'] );
        $this->assertSame( 'edit_licensio_articles', $this->extensions->items( 'articles', 'bulk_actions' )['acme/check']['capability'] );
        $this->assertSame( 'manage_licensio_kb', $this->extensions->items( 'categories', 'row_actions' )['acme/check']['capability'], 'The Categories app needs the management capability.' );
        $this->assertSame( 'manage_licensio_kb', $this->extensions->items( 'tags', 'row_actions' )['acme/edit']['capability'], 'The Tags app needs the management capability.' );
        $this->assertSame( array(), $this->extensions->items( 'tags', 'columns' ) );
        $this->assertSame( array(), $this->refused );
    }

    /**
     * A key declared again replaces the earlier item.
     *
     * @since 1.1.0
     *
     * @return void
     */
    public function test_a_key_declared_again_replaces_the_first() {
        $this->extensions->add_row_action( 'articles', 'acme/edit', array( 'label' => 'Edit' ) );
        $this->extensions->add_row_action( 'articles', 'acme/edit', array( 'label' => 'Edit SEO' ) );

        $this->assertSame( array( 'acme/edit' ), array_keys( $this->extensions->items( 'articles', 'row_actions' ) ) );
        $this->assertSame( 'Edit SEO', $this->extensions->items( 'articles', 'row_actions' )['acme/edit']['label'] );
    }

    /**
     * Labels, column groups and filter option labels reach the apps as the plain text declared.
     *
     * Tags and line breaks are removed and entities decoded, so a less-than
     * sign, an ampersand or a percent sign shows exactly as written.
     *
     * @since 1.1.0
     *
     * @return void
     */
    public function test_labels_are_kept_as_plain_text() {
        // Core's wp_strip_all_tags(), which the base case stubs without the line break removal.
        Functions\when( 'wp_strip_all_tags' )->alias(
            function ( $text, $remove_breaks = false ) {
                $text = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $text );
                $text = strip_tags( $text ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags

                return trim( $remove_breaks ? preg_replace( '/[\r\n\t ]+/', ' ', $text ) : $text );
            }
        );

        $this->extensions->add_column(
            'articles',
            'acme/words',
            array(
                'label' => 'Words < 300',
                'group' => " <b>Score</b> < 50\n&amp; up ",
            )
        );
        $this->extensions->add_filter(
            'articles',
            'acme/ctr',
            array(
                'label'   => 'CTR %ab',
                'options' => array(
                    'low'  => 'CTR < 1% & falling',
                    'high' => '&quot;Top&quot; &lt;script&gt;',
                ),
            )
        );
        $this->extensions->add_row_action( 'tags', 'acme/edit', array( 'label' => 'Edit <script>alert(1)</script>SEO' ) );

        $declared = array(
            'Words <300'  => 'Words <300',
            'a <b> c'     => 'a c',
            'x < y > z'   => 'x < y > z',
            'Under 5 <'   => 'Under 5 <',
            '<5 min'      => '<5 min',
            '<b></b>Tips' => 'Tips',
        );

        foreach ( array_keys( $declared ) as $index => $label ) {
            $this->extensions->add_bulk_action( 'articles', 'acme/action-' . $index, array( 'label' => $label ) );
        }

        $this->assertSame(
            array_values( $declared ),
            array_column( $this->extensions->items( 'articles', 'bulk_actions' ), 'label' ),
            'A less-than sign that opens no tag is kept.'
        );

        $column = $this->extensions->items( 'articles', 'columns' )['acme/words'];
        $filter = $this->extensions->items( 'articles', 'filters' )['acme/ctr'];

        $this->assertSame( 'Words < 300', $column['label'] );
        $this->assertSame( 'Score < 50 & up', $column['group'], 'Tags and line breaks go, entities are decoded.' );
        $this->assertSame( 'CTR %ab', $filter['label'], 'Percent sequences are kept.' );
        $this->assertSame(
            array(
                'low'  => 'CTR < 1% & falling',
                'high' => '"Top" <script>',
            ),
            $filter['options'],
            'Option labels are decoded too; the apps show them as text.'
        );
        $this->assertSame( 'Edit SEO', $this->extensions->items( 'tags', 'row_actions' )['acme/edit']['label'], 'Script contents are removed.' );

        $config = Licensio_KB_Extension_Config::for_screen( $this->extensions, 'articles' );

        $this->assertSame( 'Words < 300', $config['columns'][0]['label'], 'The app config carries the label unchanged.' );
        $this->assertSame( 'CTR < 1% & falling', $config['filters'][0]['options'][0]['label'] );
    }

    /**
     * Unknown screens, keys without a namespace and items without a label are refused.
     *
     * @since 1.1.0
     *
     * @return void
     */
    public function test_invalid_declarations_are_refused() {
        $this->assertFalse( $this->extensions->add_column( 'posts', 'acme/score', array( 'label' => 'Score' ) ) );

        foreach ( array( 'score', 'Acme/Score', 'acme/score/extra', 'acme/', '/score', 'acme score/x', 'author', "acme/score\n" ) as $key ) {
            $this->assertFalse( $this->extensions->add_column( 'articles', $key, array( 'label' => 'Score' ) ), $key );
        }

        $this->assertFalse( $this->extensions->add_column( 'articles', 'acme/score', array() ) );
        $this->assertFalse( $this->extensions->add_bulk_action( 'articles', 'acme/check', array( 'label' => '<b></b>' ) ) );
        $this->assertFalse( $this->extensions->add_filter( 'articles', 'acme/seo', array( 'label' => 'SEO' ) ), 'A filter needs options.' );
        $this->assertFalse(
            $this->extensions->add_row_action(
                'articles',
                'acme/edit',
                array(
                    'label'      => 'Edit',
                    'capability' => array( 'manage_options' ),
                )
            ),
            'A capability that is not a string is refused, not replaced by the default.'
        );
        $this->assertFalse(
            $this->extensions->add_filter(
                'articles',
                'acme/seo',
                array(
                    'label'   => 'SEO',
                    'options' => array( '!!' => 'Nothing left after sanitising' ),
                )
            )
        );

        $this->assertCount( 14, $this->refused, 'Each refusal is reported.' );

        foreach ( Licensio_KB_Extensions::SCREENS as $screen ) {
            foreach ( Licensio_KB_Extensions::TYPES as $type ) {
                $this->assertSame( array(), $this->extensions->items( $screen, $type ) );
            }
        }
    }

    /**
     * The Categories and Tags apps show no filters or bulk actions, so both are refused there.
     *
     * @since 1.1.0
     *
     * @return void
     */
    public function test_filters_and_bulk_actions_are_refused_on_the_term_screens() {
        $filter = array(
            'label'   => 'SEO',
            'options' => array( 'missing' => 'Missing meta' ),
        );

        foreach ( array( 'categories', 'tags' ) as $screen ) {
            $this->assertFalse( $this->extensions->add_filter( $screen, 'acme/seo', $filter ), $screen );
            $this->assertFalse( $this->extensions->add_bulk_action( $screen, 'acme/check', array( 'label' => 'Check' ) ), $screen );

            $this->assertSame( array(), $this->extensions->items( $screen, 'filters' ), $screen );
            $this->assertSame( array(), $this->extensions->items( $screen, 'bulk_actions' ), $screen );

            $config = Licensio_KB_Extension_Config::for_screen( $this->extensions, $screen );

            $this->assertSame( array(), $config['filters'], $screen );
            $this->assertSame( array(), $config['bulkActions'], $screen );
        }

        $this->assertSame(
            array(
                'Extension filters can only be added to the articles screen. The Categories and Tags apps show none.',
                'Extension bulk actions can only be added to the articles screen. The Categories and Tags apps show none.',
                'Extension filters can only be added to the articles screen. The Categories and Tags apps show none.',
                'Extension bulk actions can only be added to the articles screen. The Categories and Tags apps show none.',
            ),
            $this->refused,
            'Each refusal names the screen that supports it.'
        );

        $this->assertTrue( $this->extensions->add_filter( 'articles', 'acme/seo', $filter ), 'The Articles app still takes filters.' );
        $this->assertTrue( $this->extensions->add_bulk_action( 'articles', 'acme/check', array( 'label' => 'Check' ) ), 'The Articles app still takes bulk actions.' );
        $this->assertFalse( $this->extensions->add_filter( 'posts', 'acme/seo', $filter ) );
        $this->assertStringStartsWith( 'Unknown screen', end( $this->refused ), 'An unknown screen keeps its own notice.' );
    }

    /**
     * A position that is neither a column key nor an extension key means the end.
     *
     * @since 1.1.0
     *
     * @return void
     */
    public function test_a_bad_position_means_the_end() {
        $this->extensions->add_column(
            'articles',
            'acme/a',
            array(
                'label'    => 'A',
                'position' => 'acme/b',
            )
        );
        $this->extensions->add_column(
            'articles',
            'acme/b',
            array(
                'label'    => 'B',
                'position' => '<script>',
            )
        );
        $this->extensions->add_column(
            'articles',
            'acme/c',
            array(
                'label'    => 'C',
                'position' => "tags\n",
            )
        );

        $columns = $this->extensions->items( 'articles', 'columns' );

        $this->assertSame( 'acme/b', $columns['acme/a']['position'] );
        $this->assertSame( '', $columns['acme/b']['position'] );
        $this->assertSame( '', $columns['acme/c']['position'], 'A trailing newline is not a column key.' );
    }

    /**
     * Only the items the user may see reach the app, without their capabilities.
     *
     * @since 1.1.0
     *
     * @return void
     */
    public function test_the_app_config_holds_what_the_user_may_see() {
        $this->extensions->add_column(
            'articles',
            'acme/score',
            array(
                'label'           => 'Score',
                'group'           => 'Acme',
                'default_visible' => true,
                'sortable'        => true,
                'position'        => 'date',
            )
        );
        $this->extensions->add_column(
            'articles',
            'acme/secret',
            array(
                'label'      => 'Secret',
                'capability' => 'acme_secret',
            )
        );
        $this->extensions->add_filter(
            'articles',
            'acme/seo',
            array(
                'label'     => 'SEO',
                'options'   => array(
                    'missing_meta' => 'Missing meta',
                    '2024'         => 'A numeric key',
                ),
                'query_arg' => 'seo_filter',
            )
        );
        $this->extensions->add_bulk_action(
            'articles',
            'acme/optimize',
            array(
                'label'      => 'Optimize',
                'capability' => 'acme_optimize',
            )
        );
        $this->extensions->add_bulk_action( 'articles', 'acme/check', array( 'label' => 'Check' ) );
        $this->extensions->add_row_action( 'articles', 'acme/edit', array( 'label' => 'Edit' ) );

        $this->assertSame(
            array(
                'columns'     => array(
                    array(
                        'key'            => 'acme/score',
                        'label'          => 'Score',
                        'group'          => 'Acme',
                        'defaultVisible' => true,
                        'sortable'       => true,
                        'position'       => 'date',
                    ),
                ),
                'filters'     => array(
                    array(
                        'key'      => 'acme/seo',
                        'label'    => 'SEO',
                        'queryArg' => 'seo_filter',
                        'options'  => array(
                            array(
                                'value' => 'missing_meta',
                                'label' => 'Missing meta',
                            ),
                            array(
                                'value' => '2024',
                                'label' => 'A numeric key',
                            ),
                        ),
                    ),
                ),
                'bulkActions' => array(
                    array(
                        'key'   => 'acme/check',
                        'label' => 'Check',
                    ),
                ),
                'rowActions'  => array(
                    array(
                        'key'   => 'acme/edit',
                        'label' => 'Edit',
                    ),
                ),
            ),
            Licensio_KB_Extension_Config::for_screen( $this->extensions, 'articles' )
        );
    }

    /**
     * A user without the app page's capability sees no extension by default.
     *
     * @since 1.1.0
     *
     * @return void
     */
    public function test_items_default_to_the_app_pages_capability() {
        $this->extensions->add_row_action( 'articles', 'acme/edit', array( 'label' => 'Edit' ) );
        $this->extensions->add_row_action( 'tags', 'acme/edit', array( 'label' => 'Edit' ) );

        $this->caps = array( 'edit_licensio_articles' );

        $this->assertCount( 1, $this->extensions->allowed( 'articles', 'row_actions' ) );
        $this->assertSame( array(), $this->extensions->allowed( 'tags', 'row_actions' ), 'The Tags app needs the management capability.' );

        $this->caps = array();

        $this->assertSame(
            array(
                'columns'     => array(),
                'filters'     => array(),
                'bulkActions' => array(),
                'rowActions'  => array(),
            ),
            Licensio_KB_Extension_Config::for_screen( $this->extensions, 'articles' )
        );
    }
}
