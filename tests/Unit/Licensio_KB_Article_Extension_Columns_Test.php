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
     * The schema lists hold every declared Articles column, whoever may see it.
     *
     * @since 1.1.0
     *
     * @return void
     */
    public function test_the_schema_lists_hold_every_articles_column() {
        $this->assertSame( array( 'acme/score', 'acme/note', 'acme/secret' ), $this->columns->keys() );
        $this->assertSame( array( 'acme/score', 'acme/secret' ), $this->columns->sortable_keys() );
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
}
