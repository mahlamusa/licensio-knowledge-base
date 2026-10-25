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
     * Whether term counting is deferred, as core's switch holds it.
     *
     * @since 1.0.0
     * @var bool
     */
    private $deferring = false;

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
        $this->deferring   = false;

        Functions\when( 'wp_defer_term_counting' )->alias(
            function ( $defer = null ) {
                if ( is_bool( $defer ) ) {
                    $this->deferring = $defer;
                    $this->events[]  = $defer ? 'defer' : 'count';
                }

                return $this->deferring;
            }
        );

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

                return isset( $this->assignments[ (int) $term_id ] ) ? new WP_Term(
                    array(
                        'term_id'          => (int) $term_id,
                        'term_taxonomy_id' => self::tt_id_of( $term_id ),
                    )
                ) : null;
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
     * Drop the database double so it cannot leak into another suite.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function tearDown(): void {
        unset( $GLOBALS['wpdb'] );

        parent::tearDown();
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
        $result = ( new Licensio_KB_Term_Operations() )->move_assignments( $from, $to, $taxonomy );

        $this->assertInstanceOf( 'WP_Error', $result );
        $this->assertSame( $code, $result->get_error_code() );
        $this->assertSame( array(), $this->events, 'Nothing is written.' );
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
                'defer',
                'assign 1,2 to 30',
                'unassign 1,2 from 10',
                'assign 3 to 30',
                'unassign 2,3 from 11',
                'count',
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
        Functions\expect( 'wp_delete_term' )->never();
        Actions\expectDone( 'licensio_kb_terms_merged' )->never();

        $operations = new Licensio_KB_Term_Operations();

        $this->assertSame( 'licensio_kb_no_sources', $operations->merge_terms( array( 20 ), 20, 'licensio_tag' )->get_error_code() );
        $this->assertSame( 'licensio_kb_no_sources', $operations->merge_terms( array( 0 ), 20, 'licensio_tag' )->get_error_code() );
        $this->assertSame( 'licensio_kb_invalid_term', $operations->merge_terms( array( 10, 99 ), 20, 'licensio_tag' )->get_error_code() );
        $this->assertSame( 'licensio_kb_invalid_taxonomy', $operations->merge_terms( array( 10 ), 20, 'post_tag' )->get_error_code() );
        $this->assertSame( array(), $this->events, 'Nothing is written.' );
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

    /**
     * Counting is deferred while assignments move and recounted once after.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_counting_is_deferred_while_assignments_move() {
        ( new Licensio_KB_Term_Operations() )->move_assignments( 10, 11, 'licensio_category' );

        $this->assertSame(
            array( 'defer', 'assign 1 to 11', 'unassign 1,2 from 10', 'count' ),
            $this->events
        );
        $this->assertFalse( $this->deferring );
    }

    /**
     * A caller that already deferred counting keeps it deferred.
     *
     * The tag conversion loop defers counting itself and moves one tag at a
     * time; turning the switch off after the first tag would recount on every
     * later article.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_counting_deferred_by_the_caller_stays_deferred() {
        $this->deferring = true;

        ( new Licensio_KB_Term_Operations() )->move_assignments( 10, 11, 'licensio_category' );

        $this->assertSame(
            array( 'assign 1 to 11', 'unassign 1,2 from 10' ),
            $this->events,
            'Counting is neither deferred nor turned back on.'
        );
        $this->assertTrue( $this->deferring );
    }

    /**
     * Counting is restored when the work throws.
     *
     * A hook on an assignment write can throw. Counting must not stay
     * deferred for the rest of the request when that happens.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_counting_is_restored_when_a_move_throws() {
        $this->refuse_write = function () {
            throw new RuntimeException( 'A hook failed.' );
        };

        try {
            ( new Licensio_KB_Term_Operations() )->move_assignments( 10, 11, 'licensio_category' );
            $this->fail( 'The exception should reach the caller.' );
        } catch ( RuntimeException $exception ) {
            $this->assertSame( 'A hook failed.', $exception->getMessage() );
        }

        $this->assertSame( array( 'defer', 'count' ), $this->events );
        $this->assertFalse( $this->deferring );
    }

    /**
     * A move that fails part way puts back the objects already moved.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_failed_move_puts_back_what_it_moved() {
        $before = $this->assignments;

        $this->fail_removal_of( 2 );

        $record = array( 'stale' );
        $result = ( new Licensio_KB_Term_Operations() )->move_assignments( 10, 20, 'licensio_category', $record );

        $this->assertInstanceOf( 'WP_Error', $result );
        $this->assertSame( array(), $record, 'Nothing is left for the caller to undo.' );
        $this->assertEqualsCanonicalizing( $before[10], $this->assignments[10] );
        $this->assertEqualsCanonicalizing( $before[20], $this->assignments[20] );
    }

    /**
     * The record of a move lets the caller put everything back later.
     *
     * An object that had the target before the move keeps it.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_undo_puts_a_recorded_move_back() {
        $before     = $this->assignments;
        $operations = new Licensio_KB_Term_Operations();
        $record     = array();

        $this->assertSame( 2, $operations->move_assignments( 10, 11, 'licensio_category', $record ) );
        $this->assertCount( 1, $record, 'One entry per source term.' );
        $this->assertSame( array( 1 ), $record[0]['added'], 'Object 2 already had the target.' );
        $this->assertSame( array( 1, 2 ), $record[0]['removed'] );

        $operations->undo( $record );

        $this->assertEqualsCanonicalizing( $before[10], $this->assignments[10] );
        $this->assertEqualsCanonicalizing( $before[11], $this->assignments[11], 'Object 2 had the target already and keeps it.' );
    }

    /**
     * A merge whose second source fails puts the first source's moves back.
     *
     * Nothing is deleted and the merged hook does not fire.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_failed_merge_changes_nothing() {
        $before = $this->assignments;

        Functions\expect( 'wp_delete_term' )->never();
        Actions\expectDone( 'licensio_kb_terms_merged' )->never();

        $this->fail_removal_of( 3 );

        $result = ( new Licensio_KB_Term_Operations() )->merge_terms( array( 10, 11 ), 20, 'licensio_tag' );

        $this->assertInstanceOf( 'WP_Error', $result );

        foreach ( $before as $term_id => $objects ) {
            $this->assertEqualsCanonicalizing( $objects, $this->assignments[ $term_id ], 'Term ' . $term_id . ' is as it was.' );
        }
    }

    /**
     * A big term moves in batches, and a failed later batch puts back the earlier ones.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_big_move_is_batched_and_a_late_failure_puts_everything_back() {
        $size                  = Licensio_KB_Term_Operations::BATCH_SIZE;
        $this->assignments[10] = range( 100, 99 + ( 2 * $size ) + 1 );
        $before                = $this->assignments;
        $last                  = 99 + ( 2 * $size ) + 1;

        $this->refuse_write = function ( $action, $term_id, $object_ids ) use ( $last ) {
            return 'remove' === $action && 10 === $term_id && in_array( $last, $object_ids, true );
        };

        $result = ( new Licensio_KB_Term_Operations() )->move_assignments( 10, 30, 'licensio_category' );

        $this->assertInstanceOf( 'WP_Error', $result );
        $this->assertSame( 'licensio_kb_assignments_not_saved', $result->get_error_code() );
        $this->assertSame( 3, count( preg_grep( '/^assign .* to 30$/', $this->events ) ), 'The target is written in three batches.' );
        $this->assertSame( 2, count( preg_grep( '/^unassign .* from 10$/', $this->events ) ), 'Two source batches went through before the third failed.' );
        $this->assertEqualsCanonicalizing( $before[10], $this->assignments[10] );
        $this->assertSame( array(), $this->assignments[30] );
    }

    /**
     * After a move the caches, counts and listeners hear about every changed object.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_move_refreshes_caches_counts_and_listeners() {
        $cleaned = array();
        $counted = array();

        Functions\when( 'clean_object_term_cache' )->alias(
            function ( $object_ids, $object_type ) use ( &$cleaned ) {
                $cleaned[] = array( $object_ids, $object_type );
            }
        );
        Functions\when( 'wp_update_term_count' )->alias(
            function ( $tt_ids, $taxonomy ) use ( &$counted ) {
                $counted[] = array( $tt_ids, $taxonomy );

                return true;
            }
        );
        Actions\expectDone( 'licensio_kb_term_assignments_changed' )->once()->with( array( 1, 2 ), 'licensio_category' );

        ( new Licensio_KB_Term_Operations() )->move_assignments( 10, 11, 'licensio_category' );

        $this->assertSame( array( array( array( 1, 2 ), 'licensio_article' ) ), $cleaned );
        $this->assertSame( array( array( array( 1010, 1011 ), 'licensio_category' ) ), $counted, 'Counts are updated by term taxonomy ID.' );
    }

    /**
     * Moving across taxonomies gives every object of a tag the category and takes the tag off.
     *
     * The tag is taken off here so that deleting it afterwards has no
     * objects left to rewrite one at a time.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_assignments_are_moved_across_taxonomies() {
        $record = array();
        $moved  = ( new Licensio_KB_Term_Operations() )->move_across_taxonomies( 10, 'licensio_tag', 30, 'licensio_category', $record );

        $this->assertSame( 2, $moved );
        $this->assertSame( array( 1, 2 ), $this->assignments[30] );
        $this->assertSame( array(), $this->assignments[10], 'The source term is taken off every object.' );
        $this->assertSame( array( array( 1, 2 ) ), array_column( $record, 'removed' ), 'The removal is recorded for undo.' );
    }

    /**
     * A move across taxonomies names both, and each must be a knowledge base one.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_bad_move_across_taxonomies_is_refused() {
        $operations = new Licensio_KB_Term_Operations();

        $this->assertSame( 'licensio_kb_invalid_taxonomy', $operations->move_across_taxonomies( 10, 'post_tag', 30, 'licensio_category' )->get_error_code() );
        $this->assertSame( 'licensio_kb_invalid_term', $operations->move_across_taxonomies( 10, 'licensio_tag', 99, 'licensio_category' )->get_error_code() );
        $this->assertSame( array(), $this->events, 'Nothing is written.' );
    }

    /**
     * An edited slug is refused only when another term of the taxonomy has it.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_slug_another_term_uses_is_a_conflict() {
        Functions\when( 'get_term_by' )->alias(
            function ( $field, $value ) {
                unset( $field );

                return 'billing' === $value ? new WP_Term( array( 'term_id' => 10 ) ) : false;
            }
        );

        $operations = new Licensio_KB_Term_Operations();

        $this->assertTrue( $operations->check_slug( 11, 'invoices', 'licensio_tag' ), 'A free slug.' );
        $this->assertTrue( $operations->check_slug( 10, 'billing', 'licensio_tag' ), 'The term\'s own slug.' );
        $this->assertTrue( $operations->check_slug( 11, '', 'licensio_tag' ), 'An empty slug is made from the name.' );

        $taken = $operations->check_slug( 11, 'billing', 'licensio_tag' );

        $this->assertInstanceOf( 'WP_Error', $taken );
        $this->assertSame( 'licensio_kb_slug_taken', $taken->get_error_code() );
        $this->assertSame( 409, $taken->get_error_data()['status'] );
        $this->assertStringContainsString( 'Another tag already uses the slug “billing”', $taken->get_error_message() );
        $this->assertSame( array( 'slug' => $taken->get_error_message() ), $taken->get_error_data()['params'] );
        $this->assertStringContainsString( 'Another category', $operations->check_slug( 11, 'billing', 'licensio_category' )->get_error_message() );
    }

    /**
     * Make taking a source term off one object fail.
     *
     * The batch holding that object fails; taking the target off it again,
     * as an undo does, still works.
     *
     * @since 1.0.0
     *
     * @param int $failing_object The object whose removal fails.
     *
     * @return void
     */
    private function fail_removal_of( $failing_object ) {
        $this->refuse_write = function ( $action, $term_id, $object_ids ) use ( $failing_object ) {
            return 'remove' === $action && 20 !== $term_id && in_array( $failing_object, $object_ids, true );
        };
    }
}
