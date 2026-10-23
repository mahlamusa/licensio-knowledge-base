<?php
/**
 * Bulk article actions and their undo.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

/**
 * Drives Licensio_KB_Article_Bulk.
 *
 * @since 1.0.0
 */
class Licensio_KB_Article_Bulk_Test extends Licensio_KB_Test_Case {

    /**
     * The articles get_post() knows, by ID.
     *
     * @since 1.0.0
     * @var array<int, WP_Post>
     */
    private $posts = array();

    /**
     * Capabilities the current user lacks.
     *
     * @since 1.0.0
     * @var string[]
     */
    private $denied = array();

    /**
     * Article IDs another user is editing.
     *
     * @since 1.0.0
     * @var int[]
     */
    private $locked = array();

    /**
     * Every core write, in order, as `function:id`.
     *
     * @since 1.0.0
     * @var string[]
     */
    private $core_writes = array();

    /**
     * Back the core functions with in-memory state.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        $this->posts       = array();
        $this->denied      = array();
        $this->locked      = array();
        $this->core_writes = array();

        Functions\when( 'get_post' )->alias(
            function ( $id ) {
                return isset( $this->posts[ (int) $id ] ) ? $this->posts[ (int) $id ] : null;
            }
        );
        Functions\when( 'current_user_can' )->alias(
            function ( $cap ) {
                return ! in_array( $cap, $this->denied, true );
            }
        );
        Functions\when( 'wp_check_post_lock' )->alias(
            function ( $id ) {
                return in_array( (int) $id, $this->locked, true ) ? 9 : false;
            }
        );
        Functions\when( 'get_post_type_object' )->justReturn(
            (object) array(
                'cap' => (object) array(
                    'publish_posts'     => 'publish_posts',
                    'edit_others_posts' => 'edit_others_posts',
                    'edit_posts'        => 'edit_posts',
                ),
            )
        );
        Functions\when( 'get_taxonomy' )->alias(
            function ( $taxonomy ) {
                return (object) array(
                    'cap' => (object) array(
                        'assign_terms' => 'assign_' . $taxonomy,
                        'edit_terms'   => 'edit_' . $taxonomy,
                    ),
                );
            }
        );
        Functions\when( 'is_wp_error' )->alias(
            function ( $thing ) {
                return $thing instanceof WP_Error;
            }
        );
        Functions\when( 'wp_slash' )->returnArg();
        Functions\when( 'get_current_user_id' )->justReturn( 3 );
        Functions\when( 'wp_hash' )->alias(
            function ( $data ) {
                return md5( 'salt|' . $data );
            }
        );

        foreach ( array( 'wp_trash_post', 'wp_untrash_post', 'wp_delete_post' ) as $function ) {
            Functions\when( $function )->alias(
                function ( $id ) use ( $function ) {
                    $this->core_writes[] = $function . ':' . $id;

                    return $this->posts[ (int) $id ];
                }
            );
        }

        Functions\when( 'wp_update_post' )->alias(
            function ( $postarr ) {
                $fields = $postarr;
                unset( $fields['ID'] );
                $this->core_writes[] = 'wp_update_post:' . $postarr['ID'] . ':' . wp_json_encode( $fields );

                return $postarr['ID'];
            }
        );
        Functions\when( 'wp_set_object_terms' )->alias(
            function ( $id, $terms, $taxonomy, $append ) {
                $this->core_writes[] = 'wp_set_object_terms:' . $id . ':' . $taxonomy . ':' . wp_json_encode( $terms ) . ':' . ( $append ? 'append' : 'replace' );

                return $terms;
            }
        );
        Functions\when( 'wp_remove_object_terms' )->alias(
            function ( $id, $terms, $taxonomy ) {
                $this->core_writes[] = 'wp_remove_object_terms:' . $id . ':' . $taxonomy . ':' . wp_json_encode( $terms );

                return true;
            }
        );
        Functions\when( 'wp_get_object_terms' )->justReturn( array( '4', '6' ) );
        Functions\when( 'term_exists' )->alias(
            function ( $term, $taxonomy ) {
                return (int) $term < 100 && in_array( $taxonomy, array( 'licensio_category', 'licensio_tag' ), true ) ? array( 'term_id' => (int) $term ) : null;
            }
        );
    }

    /**
     * Add an article.
     *
     * @since 1.0.0
     *
     * @param int    $id     The ID.
     * @param string $status The status.
     *
     * @return void
     */
    private function article( $id, $status = 'publish' ) {
        $this->posts[ $id ] = new WP_Post(
            array(
                'ID'          => $id,
                'post_type'   => 'licensio_article',
                'post_status' => $status,
            )
        );
    }

    /**
     * Every action, with its arguments, the state its article starts in,
     * and the capability that forbids it.
     *
     * @since 1.0.0
     *
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: string, 3: string}> The cases.
     */
    public function actions() {
        return array(
            'trash'          => array( 'trash', array(), 'publish', 'delete_post' ),
            'restore'        => array( 'restore', array(), 'trash', 'delete_post' ),
            'delete'         => array( 'delete', array(), 'trash', 'delete_post' ),
            'status draft'   => array( 'status', array( 'status' => 'draft' ), 'publish', 'edit_post' ),
            'status publish' => array( 'status', array( 'status' => 'publish' ), 'draft', 'publish_posts' ),
            'terms'          => array(
                'terms',
                array(
                    'taxonomy' => 'licensio_tag',
                    'mode'     => 'add',
                    'term_ids' => array( 5 ),
                ),
                'publish',
                'assign_licensio_tag',
            ),
            'author'         => array( 'author', array( 'author' => 2 ), 'publish', 'edit_others_posts' ),
            'comments'       => array( 'comments', array( 'comment_status' => 'closed' ), 'publish', 'edit_post' ),
        );
    }

    /**
     * The matrix: each action × permission × lock.
     *
     * A permitted, free article is changed; a forbidden one is skipped as
     * forbidden whether or not it is locked; a permitted, locked one is
     * skipped as locked. Nothing skipped is written.
     *
     * @since 1.0.0
     *
     * @dataProvider actions
     *
     * @param string               $action    The action.
     * @param array<string, mixed> $args      Its arguments.
     * @param string               $status    The article's starting status.
     * @param string               $forbidder The capability that forbids it.
     *
     * @return void
     */
    public function test_the_action_permission_lock_matrix( $action, array $args, $status, $forbidder ) {
        $this->article( 10, $status );
        $this->article( 11, $status );
        $this->article( 12, $status );
        $this->locked = array( 11 );

        $bulk   = new Licensio_KB_Article_Bulk();
        $result = $bulk->run( array( 10, 11 ), $action, $args );

        $this->assertSame( array( 10 ), $result['done'] );
        $this->assertSame(
            array(
                array(
                    'id'     => 11,
                    'reason' => 'locked',
                ),
            ),
            $result['skipped']
        );
        $this->assertSame( array(), $result['failed'] );

        $written = count( $this->core_writes );

        $this->denied = array( $forbidder );
        $result       = $bulk->run( array( 11, 12 ), $action, $args );

        $this->assertSame( array(), $result['done'] );
        $this->assertSame( array( 'forbidden', 'forbidden' ), array_column( $result['skipped'], 'reason' ), 'Forbidden wins over locked.' );
        $this->assertCount( $written, $this->core_writes, 'A skipped article is never written.' );
    }

    /**
     * An action only runs where it makes sense, and unknown IDs are reported.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_state_and_missing_articles_are_skipped_with_a_reason() {
        $this->article( 1, 'publish' );
        $this->article( 2, 'trash' );
        $this->posts[3] = new WP_Post(
            array(
                'ID'        => 3,
                'post_type' => 'page',
            )
        );

        $bulk = new Licensio_KB_Article_Bulk();

        $reasons = function ( $action, $args = array() ) use ( $bulk ) {
            $result = $bulk->run( array( 1, 2, 3, 4 ), $action, $args );

            return array_column( $result['skipped'], 'reason', 'id' );
        };

        $this->assertSame(
            array(
                2 => 'in_trash',
                3 => 'not_found',
                4 => 'not_found',
            ),
            $reasons( 'trash' )
        );
        $this->assertSame( 'not_in_trash', $reasons( 'restore' )[1] );
        $this->assertSame( 'not_in_trash', $reasons( 'delete' )[1] );
        $this->assertSame( 'in_trash', $reasons( 'status', array( 'status' => 'draft' ) )[2] );
    }

    /**
     * Each action writes through the matching core function.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_each_action_writes_through_core() {
        $this->article( 1, 'publish' );
        $this->article( 2, 'trash' );

        $bulk = new Licensio_KB_Article_Bulk();

        $bulk->run( array( 1 ), 'trash', array() );
        $bulk->run( array( 2 ), 'restore', array() );
        $bulk->run( array( 2 ), 'delete', array() );
        $bulk->run( array( 1 ), 'status', array( 'status' => 'pending' ) );
        $bulk->run( array( 1 ), 'author', array( 'author' => 7 ) );
        $bulk->run( array( 1 ), 'comments', array( 'comment_status' => 'closed' ) );

        foreach ( array( 'add', 'replace', 'remove' ) as $mode ) {
            $bulk->run(
                array( 1 ),
                'terms',
                array(
                    'taxonomy' => 'licensio_category',
                    'mode'     => $mode,
                    'term_ids' => array( '5', 8 ),
                )
            );
        }

        $this->assertSame(
            array(
                'wp_trash_post:1',
                'wp_untrash_post:2',
                'wp_delete_post:2',
                'wp_update_post:1:{"post_status":"pending"}',
                'wp_update_post:1:{"post_author":7}',
                'wp_update_post:1:{"comment_status":"closed"}',
                'wp_set_object_terms:1:licensio_category:[5,8]:append',
                'wp_set_object_terms:1:licensio_category:[5,8]:replace',
                'wp_remove_object_terms:1:licensio_category:[5,8]',
            ),
            $this->core_writes
        );
    }

    /**
     * Undoable actions return what each article was; the rest return nothing.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_snapshots_are_returned_for_undoable_actions_only() {
        $this->article( 1, 'pending' );

        $bulk = new Licensio_KB_Article_Bulk();

        $status = $bulk->run( array( 1 ), 'status', array( 'status' => 'draft' ) );
        $terms  = $bulk->run(
            array( 1 ),
            'terms',
            array(
                'taxonomy' => 'licensio_tag',
                'mode'     => 'replace',
                'term_ids' => array( 9 ),
            )
        );
        $author = $bulk->run( array( 1 ), 'author', array( 'author' => 2 ) );

        $this->assertSame( array( 1 => array( 'status' => 'pending' ) ), $status['previous'] );
        $this->assertSame( array( 1 => array( 'terms' => array( 'licensio_tag' => array( 4, 6 ) ) ) ), $terms['previous'] );
        $this->assertSame( array(), $author['previous'] );
        $this->assertSame( Licensio_KB_Article_Bulk::snapshot_token( $status['snapshot_at'], $status['previous'] ), $status['token'] );
        $this->assertTrue( Licensio_KB_Article_Bulk::validate_snapshot( $terms['previous'], $terms['snapshot_at'], $terms['token'] ), 'A snapshot is valid with its own token.' );
    }

    /**
     * A failed write is reported with its message, and the run carries on.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_failed_write_is_reported_and_the_run_continues() {
        $this->article( 1 );
        $this->article( 2 );

        Functions\when( 'wp_trash_post' )->alias(
            function ( $id ) {
                return 1 === (int) $id ? false : $this->posts[ (int) $id ];
            }
        );

        $result = ( new Licensio_KB_Article_Bulk() )->run( array( 1, 2 ), 'trash', array() );

        $this->assertSame( array( 2 ), $result['done'] );
        $this->assertSame( 1, $result['failed'][0]['id'] );
        $this->assertNotSame( '', $result['failed'][0]['message'] );
        $this->assertArrayNotHasKey( 1, $result['previous'] );
    }

    /**
     * Revert refuses any field outside the allow-list.
     *
     * Author and comment changes are deliberately not undoable.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_revert_accepts_only_the_allow_listed_fields() {
        $now = time();

        foreach ( array( 'author', 'comment_status', 'post_content', 'post_title' ) as $field ) {
            $previous = array( 1 => array( $field => 'x' ) );
            $result   = Licensio_KB_Article_Bulk::validate_snapshot( $previous, $now, Licensio_KB_Article_Bulk::snapshot_token( $now, $previous ) );

            $this->assertInstanceOf( 'WP_Error', $result, $field . ' must not be revertible.' );
            $this->assertSame( 'rest_invalid_param', $result->get_error_code() );
        }

        $bad = array(
            array( 1 => array( 'status' => 'inherit' ) ),
            array( 1 => array( 'terms' => array( 'category' => array( 1 ) ) ) ),
            array( 1 => array( 'terms' => array( 'licensio_tag' => array( 'news' ) ) ) ),
            array( 0 => array( 'status' => 'draft' ) ),
            array(),
            array_fill( 1, 101, array( 'status' => 'draft' ) ),
        );

        foreach ( $bad as $previous ) {
            $this->assertInstanceOf( 'WP_Error', Licensio_KB_Article_Bulk::validate_snapshot( $previous, $now, Licensio_KB_Article_Bulk::snapshot_token( $now, $previous ) ) );
        }

        $good = array(
            1 => array( 'status' => 'trash' ),
            2 => array( 'terms' => array( 'licensio_category' => array( 3, '4' ) ) ),
        );

        $this->assertTrue( Licensio_KB_Article_Bulk::validate_snapshot( $good, $now, Licensio_KB_Article_Bulk::snapshot_token( $now, $good ) ) );
    }

    /**
     * The token is bound to the snapshot: any change to it is refused.
     *
     * The same snapshot sent back with its keys in another order and its term
     * IDs as strings, as JSON delivers it, still matches.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_revert_refuses_a_tampered_snapshot() {
        $now      = time();
        $previous = array(
            1 => array( 'status' => 'draft' ),
            2 => array( 'terms' => array( 'licensio_tag' => array( 4, 6 ) ) ),
        );
        $token    = Licensio_KB_Article_Bulk::snapshot_token( $now, $previous );

        $same = array(
            '2' => array( 'terms' => array( 'licensio_tag' => array( '6', '4' ) ) ),
            '1' => array( 'status' => 'draft' ),
        );
        $this->assertTrue( Licensio_KB_Article_Bulk::validate_snapshot( $same, $now, $token ) );

        $tampered = array(
            'another status'    => array(
                1 => array( 'status' => 'publish' ),
                2 => array( 'terms' => array( 'licensio_tag' => array( 4, 6 ) ) ),
            ),
            'another term'      => array(
                1 => array( 'status' => 'draft' ),
                2 => array( 'terms' => array( 'licensio_tag' => array( 4, 7 ) ) ),
            ),
            'another taxonomy'  => array(
                1 => array( 'status' => 'draft' ),
                2 => array( 'terms' => array( 'licensio_category' => array( 4, 6 ) ) ),
            ),
            'another article'   => array(
                1 => array( 'status' => 'draft' ),
                3 => array( 'terms' => array( 'licensio_tag' => array( 4, 6 ) ) ),
            ),
            'an extra article'  => array(
                1 => array( 'status' => 'draft' ),
                2 => array( 'terms' => array( 'licensio_tag' => array( 4, 6 ) ) ),
                9 => array( 'status' => 'draft' ),
            ),
            'a dropped article' => array(
                1 => array( 'status' => 'draft' ),
            ),
        );

        foreach ( $tampered as $label => $changed ) {
            $result = Licensio_KB_Article_Bulk::validate_snapshot( $changed, $now, $token );

            $this->assertInstanceOf( 'WP_Error', $result, $label );
            $this->assertSame( 'licensio_kb_snapshot_invalid', $result->get_error_code(), $label );
        }
    }

    /**
     * Revert refuses term IDs that are not terms of the taxonomy they are listed under.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_revert_refuses_terms_from_another_taxonomy() {
        $now      = time();
        $previous = array( 1 => array( 'terms' => array( 'licensio_tag' => array( 4, 150 ) ) ) );
        $result   = Licensio_KB_Article_Bulk::validate_snapshot( $previous, $now, Licensio_KB_Article_Bulk::snapshot_token( $now, $previous ) );

        $this->assertInstanceOf( 'WP_Error', $result );
        $this->assertSame( 'rest_invalid_param', $result->get_error_code() );
    }

    /**
     * Revert refuses a snapshot older than 15 minutes or taken by someone else.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_revert_refuses_old_or_foreign_snapshots() {
        $previous = array( 1 => array( 'status' => 'draft' ) );

        $fresh = time() - 14 * MINUTE_IN_SECONDS;
        $this->assertTrue( Licensio_KB_Article_Bulk::validate_snapshot( $previous, $fresh, Licensio_KB_Article_Bulk::snapshot_token( $fresh, $previous ) ) );

        $old    = time() - 16 * MINUTE_IN_SECONDS;
        $result = Licensio_KB_Article_Bulk::validate_snapshot( $previous, $old, Licensio_KB_Article_Bulk::snapshot_token( $old, $previous ) );
        $this->assertSame( 'licensio_kb_snapshot_expired', $result->get_error_code() );

        // A newer time with the old token: the age cannot be forged.
        $result = Licensio_KB_Article_Bulk::validate_snapshot( $previous, time(), Licensio_KB_Article_Bulk::snapshot_token( $old, $previous ) );
        $this->assertSame( 'licensio_kb_snapshot_invalid', $result->get_error_code() );

        $token = Licensio_KB_Article_Bulk::snapshot_token( $fresh, $previous );
        Functions\when( 'get_current_user_id' )->justReturn( 4 );
        $result = Licensio_KB_Article_Bulk::validate_snapshot( $previous, $fresh, $token );
        $this->assertSame( 'licensio_kb_snapshot_invalid', $result->get_error_code() );
    }

    /**
     * Revert checks every article's permission and lock again.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_revert_rechecks_permission_and_lock() {
        $this->article( 1, 'trash' );
        $this->article( 2, 'trash' );
        $this->article( 3, 'draft' );
        $this->locked = array( 2 );

        $bulk = new Licensio_KB_Article_Bulk();
        $now  = time();

        $this->denied = array( 'publish_posts' );

        $previous = array(
            1 => array( 'status' => 'publish' ),
            2 => array( 'status' => 'draft' ),
            3 => array( 'terms' => array( 'licensio_tag' => array( 4 ) ) ),
            5 => array( 'status' => 'draft' ),
        );
        $result   = $bulk->revert( $previous, $now, Licensio_KB_Article_Bulk::snapshot_token( $now, $previous ) );

        $this->assertSame( array( 3 ), $result['done'] );
        $this->assertSame(
            array(
                1 => 'forbidden',
                2 => 'locked',
                5 => 'not_found',
            ),
            array_column( $result['skipped'], 'reason', 'id' )
        );
        $this->assertSame( array( 'wp_set_object_terms:3:licensio_tag:[4]:replace' ), $this->core_writes );
    }

    /**
     * Undoing a trash puts the article back in its earlier status, not draft.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_revert_untrashes_to_the_snapshot_status() {
        $this->article( 1, 'trash' );
        $this->article( 2, 'publish' );
        $this->article( 3, 'draft' );

        $filter = null;

        Filters\expectAdded( 'wp_untrash_post_status' )->once()->whenHappen(
            function ( $callback ) use ( &$filter ) {
                $filter = $callback;
            }
        );

        $now      = time();
        $previous = array(
            1 => array( 'status' => 'publish' ),
            2 => array( 'status' => 'trash' ),
            3 => array( 'status' => 'pending' ),
        );
        $result   = ( new Licensio_KB_Article_Bulk() )->revert( $previous, $now, Licensio_KB_Article_Bulk::snapshot_token( $now, $previous ) );

        $this->assertSame( array( 1, 2, 3 ), $result['done'] );
        $this->assertSame( 'publish', call_user_func( $filter, 'draft' ) );
        $this->assertSame(
            array(
                'wp_untrash_post:1',
                'wp_trash_post:2',
                'wp_update_post:3:{"post_status":"pending"}',
            ),
            $this->core_writes
        );
    }
}
