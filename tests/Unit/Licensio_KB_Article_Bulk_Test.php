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
     * The transients set, by name.
     *
     * @since 1.0.0
     * @var array<string, mixed>
     */
    private $transients = array();

    /**
     * How many tokens have been generated.
     *
     * @since 1.0.0
     * @var int
     */
    private $tokens = 0;

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
        $this->transients  = array();
        $this->tokens      = 0;

        Functions\when( 'wp_generate_password' )->alias(
            function () {
                return str_pad( 'token' . ( ++$this->tokens ), Licensio_KB_Article_Bulk::TOKEN_LENGTH, 'x' );
            }
        );
        Functions\when( 'set_transient' )->alias(
            function ( $name, $value, $expiration ) {
                $this->assertSame( Licensio_KB_Article_Bulk::SNAPSHOT_TTL, $expiration );
                $this->transients[ $name ] = $value;

                return true;
            }
        );
        Functions\when( 'get_transient' )->alias(
            function ( $name ) {
                return isset( $this->transients[ $name ] ) ? $this->transients[ $name ] : false;
            }
        );
        Functions\when( 'delete_transient' )->alias(
            function ( $name ) {
                if ( ! isset( $this->transients[ $name ] ) ) {
                    return false;
                }

                unset( $this->transients[ $name ] );

                return true;
            }
        );

        Functions\when( '_prime_post_caches' )->justReturn( null );
        Functions\when( 'wp_defer_term_counting' )->justReturn( false );
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
     * @param int    $id       The ID.
     * @param string $status   The status.
     * @param string $password Optional. The post password.
     *
     * @return void
     */
    private function article( $id, $status = 'publish', $password = '' ) {
        $this->posts[ $id ] = new WP_Post(
            array(
                'ID'            => $id,
                'post_type'     => 'licensio_article',
                'post_status'   => $status,
                'post_password' => $password,
            )
        );
    }

    /**
     * The snapshot a token keeps on the server.
     *
     * @since 1.0.0
     *
     * @param string $token The token.
     *
     * @return array<int|string, mixed>|null Article ID to snapshot, or null when none is kept.
     */
    private function stored( $token ) {
        $name = Licensio_KB_Article_Bulk::SNAPSHOT_PREFIX . $token;

        return isset( $this->transients[ $name ] ) ? $this->transients[ $name ]['previous'] : null;
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
     * Making articles private clears their password; other statuses keep it.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_private_status_clears_the_password() {
        $this->article( 1, 'publish' );

        $bulk = new Licensio_KB_Article_Bulk();

        $bulk->run( array( 1 ), 'status', array( 'status' => 'private' ) );
        $bulk->run( array( 1 ), 'status', array( 'status' => 'draft' ) );

        $this->assertSame(
            array(
                'wp_update_post:1:{"post_status":"private","post_password":""}',
                'wp_update_post:1:{"post_status":"draft"}',
            ),
            $this->core_writes
        );
    }

    /**
     * Undoable actions keep what each article was on the server and answer a token; the rest keep nothing.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_snapshots_are_kept_for_undoable_actions_only() {
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

        $this->assertSame( array( 'done', 'skipped', 'failed', 'token' ), array_keys( $status ), 'The snapshot itself never goes to the browser.' );
        $this->assertSame( Licensio_KB_Article_Bulk::TOKEN_LENGTH, strlen( $status['token'] ) );
        $this->assertNotSame( $status['token'], $terms['token'] );
        $this->assertSame( array( 1 => array( 'status' => 'pending' ) ), $this->stored( $status['token'] ) );
        $this->assertSame( array( 1 => array( 'terms' => array( 'licensio_tag' => array( 4, 6 ) ) ) ), $this->stored( $terms['token'] ) );
        $this->assertSame( 3, $this->transients[ Licensio_KB_Article_Bulk::SNAPSHOT_PREFIX . $status['token'] ]['user'], 'The snapshot belongs to the user who made the change.' );
        $this->assertSame( '', $author['token'] );
        $this->assertCount( 2, $this->transients );
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
        $this->assertSame( array( 2 ), array_keys( $this->stored( $result['token'] ) ) );
    }

    /**
     * An undo token works once: a replay, or a second request racing the first, is refused and writes nothing.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_undo_token_works_once() {
        $this->article( 1, 'publish' );

        $bulk   = new Licensio_KB_Article_Bulk();
        $result = $bulk->run( array( 1 ), 'status', array( 'status' => 'draft' ) );

        $this->article( 1, 'draft' );
        $this->core_writes = array();

        $first = $bulk->revert( $result['token'] );

        $this->assertSame( array( 1 ), $first['done'] );
        $this->assertNull( $this->stored( $result['token'] ), 'The snapshot is gone once used.' );

        $replay = $bulk->revert( $result['token'] );

        $this->assertInstanceOf( 'WP_Error', $replay );
        $this->assertSame( 'licensio_kb_snapshot_expired', $replay->get_error_code() );
        $this->assertCount( 1, $this->core_writes, 'A replay writes nothing.' );
    }

    /**
     * A malformed or unknown token is refused without reading anything.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_revert_refuses_an_unknown_token() {
        $bulk = new Licensio_KB_Article_Bulk();

        foreach ( array( '', 'short', str_repeat( 'a', 31 ) . '!', str_repeat( 'b', Licensio_KB_Article_Bulk::TOKEN_LENGTH ) ) as $token ) {
            $result = $bulk->revert( $token );

            $this->assertInstanceOf( 'WP_Error', $result, $token );
            $this->assertSame( 'licensio_kb_snapshot_expired', $result->get_error_code(), $token );
        }

        $this->assertSame( array(), $this->core_writes );
    }

    /**
     * Revert refuses terms that no longer exist in the taxonomy they are listed under.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_revert_refuses_terms_that_no_longer_exist() {
        $this->article( 1, 'publish' );

        $token  = Licensio_KB_Article_Bulk::store_snapshot( array( 1 => array( 'terms' => array( 'licensio_tag' => array( 4, 150 ) ) ) ) );
        $result = ( new Licensio_KB_Article_Bulk() )->revert( $token );

        $this->assertInstanceOf( 'WP_Error', $result );
        $this->assertSame( 'rest_invalid_param', $result->get_error_code() );
        $this->assertSame( array(), $this->core_writes );
    }

    /**
     * Revert refuses a snapshot that expired or that someone else took, and leaves the other user's in place.
     *
     * The 15 minute limit is the transient's lifetime, checked in setUp.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_revert_refuses_expired_or_foreign_snapshots() {
        $this->article( 1, 'draft' );

        $bulk  = new Licensio_KB_Article_Bulk();
        $token = Licensio_KB_Article_Bulk::store_snapshot( array( 1 => array( 'status' => 'publish' ) ) );

        Functions\when( 'get_current_user_id' )->justReturn( 4 );

        $result = $bulk->revert( $token );

        $this->assertSame( 'licensio_kb_snapshot_invalid', $result->get_error_code() );
        $this->assertNotNull( $this->stored( $token ), 'Another user cannot use up the owner\'s undo.' );

        Functions\when( 'get_current_user_id' )->justReturn( 3 );

        // The transient expired.
        $this->transients = array();

        $this->assertSame( 'licensio_kb_snapshot_expired', $bulk->revert( $token )->get_error_code() );
        $this->assertSame( array(), $this->core_writes );
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

        $this->denied = array( 'publish_posts' );

        $previous = array(
            1 => array( 'status' => 'publish' ),
            2 => array( 'status' => 'draft' ),
            3 => array( 'terms' => array( 'licensio_tag' => array( 4 ) ) ),
            5 => array( 'status' => 'draft' ),
        );
        $result   = $bulk->revert( Licensio_KB_Article_Bulk::store_snapshot( $previous ) );

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

        $previous = array(
            1 => array( 'status' => 'publish' ),
            2 => array( 'status' => 'trash' ),
            3 => array( 'status' => 'pending' ),
        );
        $result   = ( new Licensio_KB_Article_Bulk() )->revert( Licensio_KB_Article_Bulk::store_snapshot( $previous ) );

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

    /**
     * Making a protected article private keeps its password in the snapshot,
     * and Undo writes the status and the password back in one write.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_undoing_make_private_restores_the_password() {
        $this->article( 1, 'publish', 'open sesame' );
        $this->article( 2, 'publish' );

        $bulk   = new Licensio_KB_Article_Bulk();
        $result = $bulk->run( array( 1, 2 ), 'status', array( 'status' => 'private' ) );

        $this->assertSame(
            array(
                1 => array(
                    'status'   => 'publish',
                    'password' => 'open sesame',
                ),
                2 => array( 'status' => 'publish' ),
            ),
            $this->stored( $result['token'] )
        );

        $this->article( 1, 'private' );
        $this->article( 2, 'private' );
        $this->core_writes = array();

        $reverted = $bulk->revert( $result['token'] );

        $this->assertSame( array( 1, 2 ), $reverted['done'] );
        $this->assertSame(
            array(
                'wp_update_post:1:{"post_status":"publish","post_password":"open sesame"}',
                'wp_update_post:2:{"post_status":"publish"}',
            ),
            $this->core_writes
        );
    }

    /**
     * Only a change to private records the password; other statuses keep it
     * on the article, so it stays out of their snapshot.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_other_statuses_do_not_record_the_password() {
        $this->article( 1, 'publish', 'open sesame' );

        $result = ( new Licensio_KB_Article_Bulk() )->run( array( 1 ), 'status', array( 'status' => 'draft' ) );

        $this->assertSame( array( 1 => array( 'status' => 'publish' ) ), $this->stored( $result['token'] ) );
    }

    /**
     * The password a snapshot holds never leaves the server.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_password_never_leaves_the_server() {
        $this->article( 1, 'publish', 'open sesame' );

        $result = ( new Licensio_KB_Article_Bulk() )->run( array( 1 ), 'status', array( 'status' => 'private' ) );

        $this->assertStringNotContainsString( 'open sesame', (string) wp_json_encode( $result ) );
        $this->assertSame( 'open sesame', $this->stored( $result['token'] )[1]['password'] );
    }

    /**
     * A user who may not edit the article cannot put its password back.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_revert_of_a_password_needs_edit_post() {
        $this->article( 1, 'private' );

        $this->denied = array( 'edit_post' );

        $previous = array(
            1 => array(
                'status'   => 'publish',
                'password' => 'open sesame',
            ),
        );
        $result   = ( new Licensio_KB_Article_Bulk() )->revert( Licensio_KB_Article_Bulk::store_snapshot( $previous ) );

        $this->assertSame( array(), $result['done'] );
        $this->assertSame( array( 1 => 'forbidden' ), array_column( $result['skipped'], 'reason', 'id' ) );
        $this->assertSame( array(), $this->core_writes );
    }

    /**
     * When the article has been trashed since, Undo writes the password while
     * it is still in the trash and only then restores it, so it is never
     * public without it. An article already back in its status still gets its
     * password.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_revert_writes_the_password_before_leaving_the_trash_or_alone() {
        $this->article( 1, 'trash' );
        $this->article( 2, 'publish' );

        $previous = array(
            1 => array(
                'status'   => 'publish',
                'password' => 'one',
            ),
            2 => array(
                'status'   => 'publish',
                'password' => 'two',
            ),
        );
        $result   = ( new Licensio_KB_Article_Bulk() )->revert( Licensio_KB_Article_Bulk::store_snapshot( $previous ) );

        $this->assertSame( array( 1, 2 ), $result['done'] );
        $this->assertSame(
            array(
                'wp_update_post:1:{"post_password":"one"}',
                'wp_untrash_post:1',
                'wp_update_post:2:{"post_password":"two"}',
            ),
            $this->core_writes
        );
    }
}
