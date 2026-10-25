<?php
/**
 * Shared term operations.
 *
 * The term store here is an in-memory map of term ID to the objects assigned
 * to it, driven through the same core functions the class calls, so each test
 * can assert on where assignments ended up rather than on which functions
 * were called.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;

/**
 * Drives Licensio_KB_Term_Operations.
 *
 * @since 1.0.0
 */
class Licensio_KB_Term_Operations_Test extends Licensio_KB_Test_Case {

    use Licensio_KB_Term_Assignments;

    /**
     * Term IDs deleted during the test, in order.
     *
     * @since 1.0.0
     * @var int[]
     */
    private $deleted = array();

    /**
     * Back the core term functions with the in-memory store.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        $this->assignments = array(
            10 => array( 1, 2 ),
            11 => array( 2, 3 ),
            20 => array( 4 ),
            30 => array(),
        );
        $this->deleted     = array();
        $this->events      = array();

        Functions\when( 'absint' )->alias(
            function ( $value ) {
                return abs( (int) $value );
            }
        );

        Functions\when( 'is_wp_error' )->alias(
            function ( $thing ) {
                return $thing instanceof WP_Error;
            }
        );

        Functions\when( 'get_term' )->alias(
            function ( $term_id, $taxonomy ) {
                unset( $taxonomy );

                return isset( $this->assignments[ (int) $term_id ] ) ? new WP_Term( array( 'term_id' => (int) $term_id ) ) : null;
            }
        );

        $this->stub_term_assignments();

        Functions\when( 'wp_delete_term' )->alias(
            function ( $term_id ) {
                $this->events[]  = 'delete ' . $term_id;
                $this->deleted[] = $term_id;
                unset( $this->assignments[ $term_id ] );

                return true;
            }
        );
    }

    /**
     * Assignments move to the target and leave the source empty.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_assignments_move_to_the_target() {
        $moved = ( new Licensio_KB_Term_Operations() )->move_assignments( 10, 11, 'licensio_category' );

        $this->assertSame( 2, $moved );
        $this->assertSame( array(), $this->assignments[10] );
        $this->assertSame( array( 2, 3, 1 ), $this->assignments[11], 'An object already on the target is not added twice.' );
        $this->assertSame( array(), $this->deleted, 'Moving assignments never deletes the source.' );
    }

    /**
     * Bad input writes nothing and says why.
     *
     * @since 1.0.0
     *
     * @param mixed  $from     The source term.
     * @param mixed  $to       The target term.
     * @param string $taxonomy The taxonomy.
     * @param string $code     The expected error code.
     *
     * @dataProvider provide_bad_moves
     *
     * @return void
     */
    public function test_a_bad_move_is_refused( $from, $to, $taxonomy, $code ) {
        Functions\expect( 'wp_set_object_terms' )->never();

        $result = ( new Licensio_KB_Term_Operations() )->move_assignments( $from, $to, $taxonomy );

        $this->assertInstanceOf( 'WP_Error', $result );
        $this->assertSame( $code, $result->get_error_code() );
    }

    /**
     * Moves that must be refused.
     *
     * @since 1.0.0
     *
     * @return array<string, array{0: mixed, 1: mixed, 2: string, 3: string}> The cases.
     */
    public function provide_bad_moves() {
        return array(
            'core taxonomy'    => array( 10, 11, 'category', 'licensio_kb_invalid_taxonomy' ),
            'onto itself'      => array( 10, 10, 'licensio_category', 'licensio_kb_same_term' ),
            'missing source'   => array( 99, 11, 'licensio_category', 'licensio_kb_invalid_term' ),
            'missing target'   => array( 10, 99, 'licensio_tag', 'licensio_kb_invalid_term' ),
            'no target at all' => array( 10, 0, 'licensio_tag', 'licensio_kb_invalid_term' ),
        );
    }

    /**
     * A merge moves every source's assignments, then deletes the sources.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_merge_moves_assignments_and_deletes_the_sources() {
        $result = ( new Licensio_KB_Term_Operations() )->merge_terms( array( 10, 11, 11, 30 ), 20, 'licensio_tag' );

        $this->assertSame(
            array(
                'moved'   => 4,
                'deleted' => array( 10, 11, 30 ),
            ),
            $result
        );
        $this->assertSame( array( 4, 1, 2, 3 ), $this->assignments[20] );
        $this->assertSame( array( 20 ), array_keys( $this->assignments ) );
    }

    /**
     * The merged hook fires once, before any source is deleted.
     *
     * The per-category article order copies each source's order to the target
     * on this hook. If the sources were deleted first, the delete hook would
     * already have removed the order it is meant to copy.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_merged_hook_fires_before_the_sources_are_deleted() {
        Actions\expectDone( 'licensio_kb_terms_merged' )
            ->once()
            ->with( array( 10, 11 ), 30, 'licensio_category' )
            ->whenHappen(
                function () {
                    $this->events[] = 'merged';
                }
            );

        ( new Licensio_KB_Term_Operations() )->merge_terms( array( 10, 11 ), 30, 'licensio_category' );

        $this->assertSame(
            array(
                'assign 1 to 30',
                'unassign 1 from 10',
                'assign 2 to 30',
                'unassign 2 from 10',
                'assign 2 to 30',
                'unassign 2 from 11',
                'assign 3 to 30',
                'unassign 3 from 11',
                'merged',
                'delete 10',
                'delete 11',
            ),
            $this->events
        );
    }

    /**
     * The target is never merged into itself.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_target_is_dropped_from_the_sources() {
        $result = ( new Licensio_KB_Term_Operations() )->merge_terms( array( 10, 11 ), 11, 'licensio_tag' );

        $this->assertSame( array( 10 ), $result['deleted'] );
        $this->assertArrayHasKey( 11, $this->assignments );
    }

    /**
     * A merge with a bad input changes nothing at all.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_bad_merge_writes_nothing() {
        Functions\expect( 'wp_set_object_terms' )->never();
        Functions\expect( 'wp_delete_term' )->never();
        Actions\expectDone( 'licensio_kb_terms_merged' )->never();

        $operations = new Licensio_KB_Term_Operations();

        $this->assertSame( 'licensio_kb_no_sources', $operations->merge_terms( array( 20 ), 20, 'licensio_tag' )->get_error_code() );
        $this->assertSame( 'licensio_kb_no_sources', $operations->merge_terms( array( 0 ), 20, 'licensio_tag' )->get_error_code() );
        $this->assertSame( 'licensio_kb_invalid_term', $operations->merge_terms( array( 10, 99 ), 20, 'licensio_tag' )->get_error_code() );
        $this->assertSame( 'licensio_kb_invalid_taxonomy', $operations->merge_terms( array( 10 ), 20, 'post_tag' )->get_error_code() );
    }

    /**
     * The removal hook is attached to core's term deletion.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_removal_hook_is_attached_to_term_deletion() {
        $operations = new Licensio_KB_Term_Operations();

        $operations->init_hooks();

        $this->assertSame( 10, has_action( 'delete_term', array( $operations, 'announce_removal' ) ) );
    }

    /**
     * Deleting a category announces it; other taxonomies stay quiet.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_deleted_category_is_announced() {
        Actions\expectDone( 'licensio_kb_term_removed' )->once()->with( 42, 'licensio_category' );

        $operations = new Licensio_KB_Term_Operations();

        $operations->announce_removal( 42, 7, 'licensio_category' );
        $operations->announce_removal( 43, 8, 'licensio_tag' );
        $operations->announce_removal( 44, 9, 'category' );
    }
}
