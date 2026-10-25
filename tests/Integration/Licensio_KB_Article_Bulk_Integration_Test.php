<?php
/**
 * Bulk status changes and their undo, against a real database server.
 *
 * Making a password-protected article private clears its password. Undo must
 * put the article back published with that same password, never public. The
 * article row lives in a real posts table; get_post() and wp_update_post()
 * read and write it with SQL, and the snapshot goes through JSON on its way
 * back, as it does between the Articles app and the REST routes.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

/*
 * This suite connects to a real database on purpose. The WordPress Database API
 * is therefore not available or appropriate here.
 */
// phpcs:disable WordPress.DB.RestrictedClasses, WordPress.DB.RestrictedFunctions, WordPress.DB.DirectDatabaseQuery

require_once __DIR__ . '/Licensio_KB_Analytics_DB_Test_Case.php';
require_once __DIR__ . '/trait-licensio-kb-content-fixtures.php';

use Brain\Monkey\Functions;

/**
 * Runs Licensio_KB_Article_Bulk against a live posts table.
 *
 * @since 1.0.0
 */
class Licensio_KB_Article_Bulk_Integration_Test extends Licensio_KB_Analytics_DB_Test_Case {

    use Licensio_KB_Content_Fixtures;

    /**
     * Add the core tables and back the core post functions with SQL.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        $this->install_content_schemas();

        Functions\when( '_prime_post_caches' )->justReturn( null );
        Functions\when( 'wp_defer_term_counting' )->justReturn( false );
        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\when( 'wp_check_post_lock' )->justReturn( false );
        Functions\when( 'get_post_type_object' )->justReturn( null );
        Functions\when( 'get_current_user_id' )->justReturn( 1 );
        Functions\when( 'wp_slash' )->returnArg();
        Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
        Functions\when( 'wp_hash' )->alias(
            function ( $data ) {
                return hash_hmac( 'sha256', $data, 'integration-salt' );
            }
        );
        Functions\when( 'is_wp_error' )->alias(
            function ( $thing ) {
                return $thing instanceof WP_Error;
            }
        );
        Functions\when( 'get_post' )->alias( array( $this, 'live_get_post' ) );
        Functions\when( 'wp_update_post' )->alias( array( $this, 'live_update_post' ) );
    }

    /**
     * Read one post row as a WP_Post.
     *
     * @since 1.0.0
     *
     * @param int $id The post ID.
     *
     * @return WP_Post|null The post, or null when there is no such row.
     */
    public function live_get_post( $id ) {
        $row = $this->db->query( 'SELECT ID, post_type, post_status, post_password FROM ' . $this->table( 'posts' ) . ' WHERE ID = ' . (int) $id )->fetch_assoc();

        if ( ! is_array( $row ) ) {
            return null;
        }

        $row['ID'] = (int) $row['ID'];

        return new WP_Post( $row );
    }

    /**
     * Write the status and password of a post row, as wp_update_post() would.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $postarr The post fields, with `ID`.
     *
     * @return int The post ID.
     */
    public function live_update_post( $postarr ) {
        $set = array();

        foreach ( array( 'post_status', 'post_password' ) as $column ) {
            if ( isset( $postarr[ $column ] ) ) {
                $set[] = $column . " = '" . $this->db->real_escape_string( (string) $postarr[ $column ] ) . "'";
            }
        }

        $this->assertTrue( (bool) $this->db->query( 'UPDATE ' . $this->table( 'posts' ) . ' SET ' . implode( ', ', $set ) . ' WHERE ID = ' . (int) $postarr['ID'] ), $this->db->error );

        return (int) $postarr['ID'];
    }

    /**
     * The status and password an article row holds.
     *
     * @since 1.0.0
     *
     * @param int $id The article ID.
     *
     * @return array{0: string, 1: string} The status and the password.
     */
    private function state( $id ) {
        $post = $this->live_get_post( $id );

        return array( $post->post_status, $post->post_password );
    }

    /**
     * Protected, then bulk Make private, then Undo: published with the same
     * password again. An article without a password comes back without one.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_undo_of_make_private_restores_the_password() {
        $protected = $this->make_article( array( 'password' => "it's a s3cret & more" ) );
        $public    = $this->make_article();
        $bulk      = new Licensio_KB_Article_Bulk();

        $result = $bulk->run( array( $protected, $public ), 'status', array( 'status' => 'private' ) );

        $this->assertSame( array( $protected, $public ), $result['done'] );
        $this->assertSame( array( 'private', '' ), $this->state( $protected ) );
        $this->assertSame( array( 'private', '' ), $this->state( $public ) );

        // The app keeps the answer as JSON and sends the snapshot back as it got it.
        $answer   = json_decode( (string) wp_json_encode( array_merge( $result, array( 'previous' => (object) $result['previous'] ) ) ), true );
        $reverted = $bulk->revert( $answer['previous'], $answer['snapshot_at'], $answer['token'] );

        $this->assertSame( array( $protected, $public ), $reverted['done'] );
        $this->assertSame( array( 'publish', "it's a s3cret & more" ), $this->state( $protected ) );
        $this->assertSame( array( 'publish', '' ), $this->state( $public ) );
    }
}
