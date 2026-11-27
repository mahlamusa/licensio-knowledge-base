<?php
/**
 * The extension columns of the Articles app.
 *
 * @package Licensio_KB
 * @since   1.1.0
 */

use Brain\Monkey\Functions;

/**
 * Drives Licensio_KB_Article_Extension_Columns.
 *
 * @since 1.1.0
 */
class Licensio_KB_Article_Extension_Columns_Test extends Licensio_KB_Test_Case {

    /**
     * The lookup under test.
     *
     * @since 1.1.0
     * @var Licensio_KB_Article_Extension_Columns
     */
    private $columns;

    /**
     * Declare Articles columns, one of them for a capability the user lacks.
     *
     * @since 1.1.0
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        Functions\when( 'current_user_can' )->alias(
            function ( $capability ) {
                return 'secret' !== $capability;
            }
        );

        $extensions = new Licensio_KB_Extensions();
        $columns    = array(
            'acme/score'  => array(
                'sortable'        => true,
                'default_visible' => true,
            ),
            'acme/note'   => array(),
            'acme/secret' => array(
                'sortable'        => true,
                'default_visible' => true,
                'capability'      => 'secret',
            ),
        );

        foreach ( $columns as $key => $args ) {
            $extensions->add_column(
                'articles',
                $key,
                array_merge(
                    array(
                        'capability' => 'edit_posts',
                        'label'      => $key,
                    ),
                    $args
                )
            );
        }

        $extensions->add_column(
            'tags',
            'acme/terms',
            array(
                'label'           => 'Terms',
                'sortable'        => true,
                'default_visible' => true,
            )
        );

        $this->columns = new Licensio_KB_Article_Extension_Columns( $extensions );
    }

    /**
     * The sort and column checks accept only what the current user may see.
     *
     * Both are `validate_callback`s, which run once the request is
     * authenticated, so a column gated by a capability never reaches the
     * accepted values, and so never a 400 message, for a user who lacks it.
     *
     * @since 1.1.0
     *
     * @return void
     */
    public function test_the_request_checks_accept_only_what_the_user_may_see() {
        $schemas = array();

        Functions\when( 'rest_validate_value_from_schema' )->alias(
            function ( $value, $schema, $param ) use ( &$schemas ) {
                $schemas[ $param ] = $schema;

                return true;
            }
        );

        $request = new WP_REST_Request();

        $this->assertTrue( $this->columns->validate_orderby( 'acme/score', $request, 'orderby' ) );
        $this->assertTrue( $this->columns->validate_columns( array( 'author', 'acme/note' ), $request, 'columns' ) );

        $this->assertSame(
            array(
                'type' => 'string',
                'enum' => array_merge( Licensio_KB_Articles_REST_Controller::ORDERBY, array( 'acme/score' ) ),
            ),
            $schemas['orderby'],
            'The core sorts and the sortable columns the user may see; never acme/secret.'
        );
        $this->assertSame(
            array(
                'type'  => 'array',
                'items' => array(
                    'type' => 'string',
                    'enum' => array_merge( Licensio_KB_Articles_REST_Controller::COLUMNS, array( 'acme/score', 'acme/note' ) ),
                ),
            ),
            $schemas['columns'],
            'The core columns and the extension columns the user may see; never acme/secret.'
        );
    }

    /**
     * A refusal from the schema check is handed back as it is.
     *
     * @since 1.1.0
     *
     * @return void
     */
    public function test_a_refused_value_returns_the_schema_error() {
        $error = new WP_Error( 'rest_not_in_enum', 'orderby is not one of date, modified.' );

        Functions\when( 'rest_validate_value_from_schema' )->justReturn( $error );

        $this->assertSame( $error, $this->columns->validate_orderby( 'acme/secret', new WP_REST_Request(), 'orderby' ) );
        $this->assertSame( $error, $this->columns->validate_columns( array( 'acme/secret' ), new WP_REST_Request(), 'columns' ) );
    }

    /**
     * The request-time answers hold only the columns the current user may see.
     *
     * @since 1.1.0
     *
     * @return void
     */
    public function test_the_request_answers_hold_what_the_user_may_see() {
        $this->assertSame( array( 'acme/score', 'acme/note' ), $this->columns->allowed_keys() );
        $this->assertSame( array( 'acme/score' ), $this->columns->default_keys() );
        $this->assertSame( array( 'acme/score' ), $this->columns->sortable_keys() );
        $this->assertTrue( $this->columns->can_sort_by( 'acme/score' ) );
        $this->assertFalse( $this->columns->can_sort_by( 'acme/note' ), 'Not sortable.' );
        $this->assertFalse( $this->columns->can_sort_by( 'acme/secret' ), 'Not the user’s to see.' );
        $this->assertFalse( $this->columns->can_sort_by( 'date' ) );
    }

    /**
     * The visible keys are the allowed extension columns among the shown ones, in the order shown.
     *
     * @since 1.1.0
     *
     * @return void
     */
    public function test_the_visible_keys_are_the_shown_extension_columns() {
        $this->assertSame(
            array( 'acme/note', 'acme/score' ),
            $this->columns->visible_keys( array( 'author', 'acme/note', 'acme/secret', 'acme/gone', 'acme/score' ) )
        );
        $this->assertSame( array(), $this->columns->visible_keys( array( 'author', 'date' ) ) );
    }

    /**
     * The new default keys are the default columns the user may see and was not offered.
     *
     * @since 1.1.0
     *
     * @return void
     */
    public function test_the_new_default_keys_skip_the_known_columns() {
        $this->assertSame( array( 'acme/score' ), $this->columns->new_default_keys( array() ) );
        $this->assertSame( array( 'acme/score' ), $this->columns->new_default_keys( array( 'acme/note', 'acme/gone' ) ) );
        $this->assertSame( array(), $this->columns->new_default_keys( array( 'acme/score' ) ) );
    }
}
