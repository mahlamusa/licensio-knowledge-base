<?php
/**
 * The privacy exporter and eraser, against a real database server.
 *
 * The question these answer is the one a wordpress.org reviewer asks: does a
 * personal-data request actually return that person's rows, and does erasure
 * actually remove them — without touching anybody else's. Neither half can be
 * answered honestly by a double, because a double that reports "removed" is
 * indistinguishable from one that removed everything.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

// phpcs:disable WordPress.DB.RestrictedClasses, WordPress.DB.RestrictedFunctions, WordPress.DB.DirectDatabaseQuery

require_once __DIR__ . '/Licensio_KB_Analytics_DB_Test_Case.php';

/**
 * Drives Licensio_KB_Analytics_Privacy at a live server.
 *
 * @since 1.0.0
 */
class Licensio_KB_Analytics_Privacy_Integration_Test extends Licensio_KB_Analytics_DB_Test_Case {

    /**
     * The address the request is made for.
     *
     * @since 1.0.0
     * @var string
     */
    const SUBJECT_EMAIL = 'kim@example.test';

    /**
     * The user ID behind that address.
     *
     * @since 1.0.0
     * @var int
     */
    const SUBJECT_USER_ID = 7;

    /**
     * The visitor hash that user's rows were recorded under.
     *
     * @since 1.0.0
     * @var string
     */
    const SUBJECT_HASH = 'aaaa1111';

    /**
     * A second person's visitor hash, which must never be touched.
     *
     * @since 1.0.0
     * @var string
     */
    const BYSTANDER_HASH = 'bbbb2222';

    /**
     * Seed rows for the subject and for an unrelated bystander.
     *
     * @since 1.0.0
     *
     * @return void
     */
    private function seed_people() {
        $when = '2026-08-10 09:00:00';

        $this->seed(
            'licensio_feedback',
            array(
                // The subject, signed in: this is the only link from an email
                // address to a visitor hash that exists anywhere in the plugin.
                array(
                    'article_id'   => 11,
                    'vote'         => 1,
                    'comment'      => 'Clear enough.',
                    'visitor_hash' => self::SUBJECT_HASH,
                    'user_id'      => self::SUBJECT_USER_ID,
                    'status'       => 'new',
                    'created_at'   => $when,
                ),
                // The subject again, signed out but under the same hash.
                array(
                    'article_id'   => 12,
                    'vote'         => -1,
                    'comment'      => 'Missing the licence key step.',
                    'visitor_hash' => self::SUBJECT_HASH,
                    'user_id'      => 0,
                    'status'       => 'new',
                    'created_at'   => $when,
                ),
                // Somebody else entirely.
                array(
                    'article_id'   => 13,
                    'vote'         => 1,
                    'comment'      => 'Not the subject.',
                    'visitor_hash' => self::BYSTANDER_HASH,
                    'user_id'      => 0,
                    'status'       => 'new',
                    'created_at'   => $when,
                ),
            )
        );

        $this->seed(
            'licensio_search_log',
            array(
                array(
                    'term'            => 'licence key',
                    'normalized_term' => 'licence key',
                    'results_count'   => 2,
                    'visitor_hash'    => self::SUBJECT_HASH,
                    'source'          => 'site',
                    'created_at'      => $when,
                ),
                array(
                    'term'            => 'renewal',
                    'normalized_term' => 'renewal',
                    'results_count'   => 0,
                    'visitor_hash'    => self::SUBJECT_HASH,
                    'source'          => 'live',
                    'created_at'      => $when,
                ),
                array(
                    'term'            => 'somebody else',
                    'normalized_term' => 'somebody else',
                    'results_count'   => 1,
                    'visitor_hash'    => self::BYSTANDER_HASH,
                    'source'          => 'site',
                    'created_at'      => $when,
                ),
            )
        );

        $this->seed(
            'licensio_article_views',
            array(
                array(
                    'article_id'   => 11,
                    'visitor_hash' => self::SUBJECT_HASH,
                    'created_at'   => $when,
                ),
                array(
                    'article_id'   => 13,
                    'visitor_hash' => self::BYSTANDER_HASH,
                    'created_at'   => $when,
                ),
                array(
                    'article_id'   => 14,
                    'visitor_hash' => self::BYSTANDER_HASH,
                    'created_at'   => $when,
                ),
            )
        );
    }

    /**
     * Resolve the subject's address to a user, and nothing else.
     *
     * @since 1.0.0
     *
     * @return void
     */
    private function stub_user_lookup() {
        Brain\Monkey\Functions\when( 'get_user_by' )->alias(
            function ( $field, $value ) {
                if ( 'email' !== $field || self::SUBJECT_EMAIL !== $value ) {
                    return false;
                }

                return new WP_User( self::SUBJECT_USER_ID, self::SUBJECT_EMAIL );
            }
        );
    }

    /**
     * The subject's user ID resolves to the hash their rows carry.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_user_resolves_to_the_visitor_hash_their_rows_carry() {
        $this->seed_people();
        $this->stub_user_lookup();

        $identity = ( new Licensio_KB_Analytics_Privacy() )->identity( self::SUBJECT_EMAIL );

        $this->assertSame( self::SUBJECT_USER_ID, $identity['user_id'] );
        $this->assertSame( array( self::SUBJECT_HASH ), $identity['hashes'] );
        $this->assertTrue( $identity['resolvable'] );
    }

    /**
     * The exporter returns every row belonging to the subject.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_exporter_returns_the_subjects_rows() {
        $this->seed_people();
        $this->stub_user_lookup();

        $response = ( new Licensio_KB_Analytics_Privacy() )->export( self::SUBJECT_EMAIL );

        $this->assertTrue( $response['done'] );

        $groups = array_count_values( array_column( $response['data'], 'group_id' ) );

        $this->assertSame(
            array(
                'licensio-kb-searches'      => 2,
                'licensio-kb-article-views' => 1,
                'licensio-kb-feedback'      => 2,
            ),
            $groups
        );
    }

    /**
     * The exported rows carry the subject's own values.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_exported_rows_carry_the_subjects_values() {
        $this->seed_people();
        $this->stub_user_lookup();

        $response = ( new Licensio_KB_Analytics_Privacy() )->export( self::SUBJECT_EMAIL );
        $values   = array();

        foreach ( $response['data'] as $item ) {
            foreach ( $item['data'] as $field ) {
                $values[] = (string) $field['value'];
            }
        }

        $this->assertContains( 'licence key', $values );
        $this->assertContains( 'renewal', $values );
        $this->assertContains( 'Missing the licence key step.', $values );
        $this->assertContains( 'Article 11', $values );
    }

    /**
     * The exporter never returns somebody else's rows.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_exporter_returns_nobody_elses_rows() {
        $this->seed_people();
        $this->stub_user_lookup();

        $response = ( new Licensio_KB_Analytics_Privacy() )->export( self::SUBJECT_EMAIL );
        $encoded  = wp_json_encode( $response['data'] );

        $this->assertStringNotContainsString( self::BYSTANDER_HASH, $encoded );
        $this->assertStringNotContainsString( 'somebody else', $encoded );
        $this->assertStringNotContainsString( 'Not the subject.', $encoded );
    }

    /**
     * An address that matches nobody exports nothing.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_unknown_address_exports_nothing() {
        $this->seed_people();
        $this->stub_user_lookup();

        $response = ( new Licensio_KB_Analytics_Privacy() )->export( 'stranger@example.test' );

        $this->assertSame( array(), $response['data'] );
        $this->assertTrue( $response['done'] );
    }

    /**
     * The eraser removes the subject's rows from all three tables.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_eraser_removes_the_subjects_rows() {
        $this->seed_people();
        $this->stub_user_lookup();

        $response = ( new Licensio_KB_Analytics_Privacy() )->erase( self::SUBJECT_EMAIL );

        $this->assertTrue( $response['items_removed'] );
        $this->assertTrue( $response['done'] );

        $this->assertSame(
            0,
            $this->count_rows( 'licensio_search_log', "visitor_hash = '" . self::SUBJECT_HASH . "'" )
        );
        $this->assertSame(
            0,
            $this->count_rows( 'licensio_article_views', "visitor_hash = '" . self::SUBJECT_HASH . "'" )
        );
        $this->assertSame(
            0,
            $this->count_rows( 'licensio_feedback', "visitor_hash = '" . self::SUBJECT_HASH . "'" )
        );
        $this->assertSame(
            0,
            $this->count_rows( 'licensio_feedback', 'user_id = ' . self::SUBJECT_USER_ID )
        );
    }

    /**
     * The eraser leaves everybody else's rows exactly where they were.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_eraser_leaves_everybody_else_alone() {
        $this->seed_people();
        $this->stub_user_lookup();

        ( new Licensio_KB_Analytics_Privacy() )->erase( self::SUBJECT_EMAIL );

        $this->assertSame( 1, $this->count_rows( 'licensio_search_log' ) );
        $this->assertSame( 2, $this->count_rows( 'licensio_article_views' ) );
        $this->assertSame( 1, $this->count_rows( 'licensio_feedback' ) );

        $this->assertSame(
            1,
            $this->count_rows( 'licensio_search_log', "visitor_hash = '" . self::BYSTANDER_HASH . "'" )
        );
        $this->assertSame(
            2,
            $this->count_rows( 'licensio_article_views', "visitor_hash = '" . self::BYSTANDER_HASH . "'" )
        );
    }

    /**
     * An address that matches nobody erases nothing at all.
     *
     * The failure this guards against is not "one row too few". A handler that
     * ran its DELETE with no criteria would erase every search and every view
     * on the site and report success while doing it.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_unknown_address_erases_nothing() {
        $this->seed_people();
        $this->stub_user_lookup();

        $response = ( new Licensio_KB_Analytics_Privacy() )->erase( 'stranger@example.test' );

        $this->assertFalse( $response['items_removed'] );
        $this->assertSame( 3, $this->count_rows( 'licensio_search_log' ) );
        $this->assertSame( 3, $this->count_rows( 'licensio_article_views' ) );
        $this->assertSame( 3, $this->count_rows( 'licensio_feedback' ) );
    }

    /**
     * A user with no feedback resolves to no hash, and nothing is erased.
     *
     * Somebody who only ever searched cannot be tied to an email address at
     * all, which is a consequence of storing no IP. The handler must leave the
     * whole table alone rather than fall back to matching everything.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_user_with_no_feedback_erases_nothing() {
        $this->seed_people();

        // Delete the only rows that link this user to a hash.
        $this->db->query( 'DELETE FROM ' . $this->table( 'licensio_feedback' ) );

        $this->stub_user_lookup();

        $response = ( new Licensio_KB_Analytics_Privacy() )->erase( self::SUBJECT_EMAIL );

        $this->assertFalse( $response['items_removed'] );
        $this->assertSame(
            3,
            $this->count_rows( 'licensio_search_log' ),
            'The search log was erased with no criteria at all.'
        );
        $this->assertSame(
            3,
            $this->count_rows( 'licensio_article_views' ),
            'The view log was erased with no criteria at all.'
        );
    }

    /**
     * An unresolvable request never reaches the database.
     *
     * The per-table criteria check is what keeps an unmatched request from
     * erasing everything; this asserts the early-out in front of it, which is
     * the difference between three pointless queries per request and none.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_unresolvable_request_issues_no_query() {
        $this->seed_people();
        $this->stub_user_lookup();

        $before = count( $this->adapter->queries );

        ( new Licensio_KB_Analytics_Privacy() )->erase( 'stranger@example.test' );

        $this->assertSame(
            array(),
            array_slice( $this->adapter->queries, $before ),
            'An address matching nobody still went to the database.'
        );
    }

    /**
     * A hash supplied by the site is honoured.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_site_supplied_hash_is_honoured() {
        $this->seed_people();

        Brain\Monkey\Functions\when( 'get_user_by' )->justReturn( false );

        Brain\Monkey\Functions\when( 'apply_filters' )->alias(
            function ( $hook, $value ) {
                return 'licensio_privacy_visitor_hashes' === $hook
                    ? array( Licensio_KB_Analytics_Privacy_Integration_Test::BYSTANDER_HASH )
                    : $value;
            }
        );

        $response = ( new Licensio_KB_Analytics_Privacy() )->erase( 'anyone@example.test' );

        $this->assertTrue( $response['items_removed'] );
        $this->assertSame(
            0,
            $this->count_rows( 'licensio_article_views', "visitor_hash = '" . self::BYSTANDER_HASH . "'" )
        );
        $this->assertSame(
            1,
            $this->count_rows( 'licensio_article_views', "visitor_hash = '" . self::SUBJECT_HASH . "'" )
        );
    }
}
