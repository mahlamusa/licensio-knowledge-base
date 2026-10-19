<?php
/**
 * Article feedback, against a real database server.
 *
 * Two of this issue's guarantees cannot be honestly proved by a double.
 *
 * "One vote per visitor per article, enforced server-side" is a claim about
 * what a SELECT returns after an INSERT. A $wpdb double answers whatever it was
 * written to answer, so a suite built on one certifies the test author's
 * beliefs about the query rather than the server's behaviour — which is exactly
 * how this project once shipped a FULLTEXT probe that could never succeed on
 * MySQL 8 behind a fully green build.
 *
 * "The usefulness score matches a recomputation from raw rows" is the same kind
 * of claim: it is only meaningful if the rows are real.
 *
 * So both are executed here, through the plugin's own Licensio_KB_DB gateway
 * and the vendored query builder, against MySQL or MariaDB. The suite skips
 * itself when no server is configured.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

/*
 * This suite connects to a real database on purpose: it exists precisely because
 * the unit suite's doubles cannot answer how a real server responds to real SQL.
 * The WordPress Database API is therefore not available or appropriate here.
 */
// phpcs:disable WordPress.DB.RestrictedClasses, WordPress.DB.RestrictedFunctions, WordPress.DB.DirectDatabaseQuery


require_once __DIR__ . '/Licensio_KB_Integration_Test_Case.php';
require_once __DIR__ . '/class-licensio-kb-live-wpdb.php';

/**
 * Drives Licensio_KB_Feedback at a live server.
 *
 * @since 1.0.0
 */
class Licensio_KB_Feedback_Integration_Test extends Licensio_KB_Integration_Test_Case {

    /**
     * The table prefix used for the scratch schema.
     *
     * @since 1.0.0
     * @var string
     */
    const PREFIX = 'lkbfb_';

    /**
     * A visitor hash standing in for one browser.
     *
     * @since 1.0.0
     * @var string
     */
    const HASH_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /**
     * A visitor hash standing in for a different browser.
     *
     * @since 1.0.0
     * @var string
     */
    const HASH_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    /**
     * The store under test.
     *
     * @since 1.0.0
     * @var Licensio_KB_Feedback
     */
    private $feedback;

    /**
     * The in-memory options table.
     *
     * @since 1.0.0
     * @var array<string, mixed>
     */
    private $options = array();

    /**
     * The in-memory post meta table.
     *
     * @since 1.0.0
     * @var array<string, mixed>
     */
    private $meta = array();

    /**
     * Create the feedback table and point the plugin at the live connection.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        $this->options = array();
        $this->meta    = array();

        $GLOBALS['wpdb']        = new Licensio_KB_Live_WPDB( $this->db, self::PREFIX );
        $GLOBALS['EZSQL_ERROR'] = array();

        $schemas = Licensio_KB_DB_Installer::get_schemas(
            self::PREFIX,
            'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
        );

        $this->assertTrue(
            (bool) $this->db->query( $schemas['licensio_feedback'] ),
            'The feedback schema failed on ' . $this->server_version() . ': ' . $this->db->error
        );

        $this->stub_wordpress();

        Licensio_KB_DB::init();

        if ( ! Licensio_KB_DB::is_available() ) {
            $this->markTestSkipped( 'The vendored query builder is unavailable.' );
        }

        $this->feedback = new Licensio_KB_Feedback( new Licensio_KB_Settings() );
    }

    /**
     * Drop the globals again.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function tearDown(): void {
        unset( $GLOBALS['wpdb'], $GLOBALS['EZSQL_ERROR'], $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT'] );

        parent::tearDown();
    }

    /**
     * Supply the handful of WordPress functions the store calls.
     *
     * Options and post meta are backed by real in-memory arrays rather than by
     * canned return values, so a write is genuinely visible to the next read.
     *
     * @since 1.0.0
     *
     * @return void
     */
    private function stub_wordpress() {
        Brain\Monkey\Functions\when( 'current_time' )->justReturn( '2026-02-01 10:00:00' );

        Brain\Monkey\Functions\when( 'get_option' )->alias(
            function ( $name, $fallback = false ) {
                return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $fallback;
            }
        );
        Brain\Monkey\Functions\when( 'update_option' )->alias(
            function ( $name, $value ) {
                $this->options[ $name ] = $value;

                return true;
            }
        );
        Brain\Monkey\Functions\when( 'get_post_meta' )->alias(
            function ( $post_id, $key, $single = false ) {
                unset( $single );

                $index = (int) $post_id . '|' . (string) $key;

                return array_key_exists( $index, $this->meta ) ? $this->meta[ $index ] : '';
            }
        );
        Brain\Monkey\Functions\when( 'update_post_meta' )->alias(
            function ( $post_id, $key, $value ) {
                $this->meta[ (int) $post_id . '|' . (string) $key ] = $value;

                return true;
            }
        );
        Brain\Monkey\Functions\when( 'delete_post_meta' )->alias(
            function ( $post_id, $key ) {
                unset( $this->meta[ (int) $post_id . '|' . (string) $key ] );

                return true;
            }
        );
        Brain\Monkey\Functions\when( 'sanitize_textarea_field' )->alias(
            function ( $value ) {
                return is_string( $value ) ? trim( strip_tags( $value ) ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
            }
        );
        Brain\Monkey\Functions\when( 'sanitize_text_field' )->alias(
            function ( $value ) {
                return is_string( $value ) ? trim( strip_tags( $value ) ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
            }
        );
        Brain\Monkey\Functions\when( 'wp_unslash' )->returnArg();
        Brain\Monkey\Functions\when( 'wp_generate_password' )->justReturn( str_repeat( 'z', 64 ) );
    }

    /**
     * Every row in the feedback table, read straight from the server.
     *
     * @since 1.0.0
     *
     * @return array<int, array<string, string|null>> The rows as associative arrays.
     */
    private function raw_rows() {
        $result = $this->db->query( 'SELECT * FROM ' . self::PREFIX . 'licensio_feedback ORDER BY id ASC' );
        $rows   = array();

        while ( $row = $result->fetch_assoc() ) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Count rows the long way round, without the plugin's own code.
     *
     * @since 1.0.0
     *
     * @param int $article_id The article.
     * @param int $vote       The vote value.
     *
     * @return int The number of matching rows.
     */
    private function raw_count( $article_id, $vote ) {
        $sql = sprintf(
            'SELECT COUNT(*) AS total FROM %1$slicensio_feedback WHERE article_id = %2$d AND vote = %3$d',
            self::PREFIX,
            (int) $article_id,
            (int) $vote
        );

        return (int) $this->db->query( $sql )->fetch_object()->total;
    }

    /**
     * A visitor who has voted cannot vote again on the same article.
     *
     * The lookup is a real SELECT against a row a real INSERT put there. No
     * cookie is involved, and nothing about the second request differs from the
     * first except the state of the table.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_second_vote_from_the_same_visitor_is_refused_by_the_server() {
        $this->assertFalse( $this->feedback->has_voted( 42, array( self::HASH_A ) ) );

        $this->assertTrue(
            $this->feedback->record_vote( 42, Licensio_KB_Feedback::VOTE_UP, '', self::HASH_A )
        );

        $this->assertTrue( $this->feedback->has_voted( 42, array( self::HASH_A ) ) );
        $this->assertCount( 1, $this->raw_rows() );
    }

    /**
     * A different visitor may still vote on the same article.
     *
     * Without this, a rule that refused every second vote on an article would
     * pass the test above.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_different_visitor_may_vote_on_the_same_article() {
        $this->feedback->record_vote( 42, Licensio_KB_Feedback::VOTE_UP, '', self::HASH_A );

        $this->assertFalse( $this->feedback->has_voted( 42, array( self::HASH_B ) ) );

        $this->feedback->record_vote( 42, Licensio_KB_Feedback::VOTE_DOWN, '', self::HASH_B );

        $this->assertCount( 2, $this->raw_rows() );
    }

    /**
     * The same visitor may still vote on a different article.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_same_visitor_may_vote_on_another_article() {
        $this->feedback->record_vote( 42, Licensio_KB_Feedback::VOTE_UP, '', self::HASH_A );

        $this->assertFalse( $this->feedback->has_voted( 43, array( self::HASH_A ) ) );
    }

    /**
     * A salt rotation between the two requests does not buy a second vote.
     *
     * The visitor hash is rebuilt from a salt that rotates daily, so a visitor
     * who votes shortly before a rotation arrives at the next request under a
     * new hash. Looking the vote up by every hash they could be known under is
     * what stops that being an exploitable second vote.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_salt_rotation_does_not_buy_a_second_vote() {
        $this->feedback->record_vote( 42, Licensio_KB_Feedback::VOTE_UP, '', self::HASH_B );

        // The current-salt hash first, the previous-salt hash second, as get_hashes() returns them.
        $this->assertTrue( $this->feedback->has_voted( 42, array( self::HASH_A, self::HASH_B ) ) );
    }

    /**
     * A logged-in reader cannot vote again from another browser.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_logged_in_reader_cannot_vote_twice_from_another_browser() {
        $this->feedback->record_vote( 42, Licensio_KB_Feedback::VOTE_UP, '', self::HASH_A, 7 );

        $this->assertTrue( $this->feedback->has_voted( 42, array( self::HASH_B ), 7 ) );
        $this->assertFalse( $this->feedback->has_voted( 42, array( self::HASH_B ), 8 ) );
    }

    /**
     * The cached score agrees with a recount of the raw rows.
     *
     * The counts on the right-hand side are produced by SQL this file writes
     * itself, not by the code under test, so a bug in `count_votes()` shows up
     * as a disagreement rather than as two matching wrong numbers.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_cached_score_matches_a_recount_from_raw_rows() {
        $hashes = array();

        for ( $index = 0; $index < 10; $index++ ) {
            $hashes[] = str_pad( (string) $index, 64, 'f', STR_PAD_LEFT );
        }

        foreach ( array_slice( $hashes, 0, 8 ) as $hash ) {
            $this->feedback->record_vote( 42, Licensio_KB_Feedback::VOTE_UP, '', $hash );
        }

        foreach ( array_slice( $hashes, 8 ) as $hash ) {
            $this->feedback->record_vote( 42, Licensio_KB_Feedback::VOTE_DOWN, '', $hash );
        }

        // A vote on another article must not be counted here.
        $this->feedback->record_vote( 43, Licensio_KB_Feedback::VOTE_DOWN, '', self::HASH_A );

        $result = $this->feedback->recompute_score( 42 );

        $raw_up   = $this->raw_count( 42, Licensio_KB_Feedback::VOTE_UP );
        $raw_down = $this->raw_count( 42, Licensio_KB_Feedback::VOTE_DOWN );

        $this->assertSame( 8, $raw_up );
        $this->assertSame( 2, $raw_down );
        $this->assertSame( $raw_up, $result['up'] );
        $this->assertSame( $raw_down, $result['down'] );
        $this->assertSame( Licensio_KB_Feedback::calculate_score( $raw_up, $raw_down ), $result['score'] );
        $this->assertSame( 80, $result['score'] );

        $cached = Licensio_KB_Feedback::get_score( 42 );

        $this->assertSame( $result, $cached );
    }

    /**
     * The score is recomputed, not accumulated, on every write.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_score_updates_as_votes_arrive() {
        $this->feedback->record_vote( 42, Licensio_KB_Feedback::VOTE_UP, '', self::HASH_A );

        $this->assertSame( 100, $this->feedback->recompute_score( 42 )['score'] );

        $this->feedback->record_vote( 42, Licensio_KB_Feedback::VOTE_DOWN, '', self::HASH_B );

        $this->assertSame( 50, $this->feedback->recompute_score( 42 )['score'] );
        $this->assertSame( 50, Licensio_KB_Feedback::get_score( 42 )['score'] );
    }

    /**
     * No IP address reaches the database, in any column.
     *
     * The vote is recorded under a hash built by the real Licensio_KB_Visitor
     * from a real REMOTE_ADDR, so the address genuinely passes through the
     * request. The table is then read back column by column.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_no_ip_address_reaches_the_database() {
        $_SERVER['REMOTE_ADDR']     = '198.51.100.4';
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Licensio Test Runner)';

        $visitor = new Licensio_KB_Visitor();

        $this->feedback->record_vote( 42, Licensio_KB_Feedback::VOTE_DOWN, 'From 198.51.100.4?', $visitor->get_hash() );

        $columns = $this->db->query( 'SHOW COLUMNS FROM ' . self::PREFIX . 'licensio_feedback' );

        while ( $column = $columns->fetch_object() ) {
            $this->assertStringNotContainsStringIgnoringCase(
                'ip',
                (string) $column->Field, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
                'The feedback table must have no IP column.'
            );
        }

        $rows = $this->raw_rows();

        $this->assertCount( 1, $rows );

        foreach ( $rows[0] as $column => $value ) {
            if ( 'comment' === $column ) {
                // The reader typed this; it is their words, not a recorded address.
                continue;
            }

            $this->assertStringNotContainsString(
                '198.51.100.4',
                (string) $value,
                sprintf( 'Column %s must not carry the request IP address.', $column )
            );
        }

        $this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', (string) $rows[0]['visitor_hash'] );
        $this->assertNotSame( '198.51.100.4', (string) $rows[0]['visitor_hash'] );
    }

    /**
     * A comment carrying markup is stored as plain text.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_comment_carrying_markup_is_stored_as_plain_text() {
        $submitted = 'Broken <script>alert("xss")</script> at <b>step 3</b>';

        $this->feedback->record_vote(
            42,
            Licensio_KB_Feedback::VOTE_DOWN,
            Licensio_KB_Feedback::sanitize_comment( $submitted ),
            self::HASH_A
        );

        $stored = (string) $this->raw_rows()[0]['comment'];

        $this->assertSame( 'Broken alert("xss") at step 3', $stored );
        $this->assertStringNotContainsString( '<', $stored );
        $this->assertStringNotContainsString( 'script', $stored );
    }

    /**
     * The inbox filters and pages against real rows.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_inbox_filters_pages_and_updates() {
        for ( $index = 0; $index < 5; $index++ ) {
            $this->feedback->record_vote(
                42,
                0 === $index % 2 ? Licensio_KB_Feedback::VOTE_UP : Licensio_KB_Feedback::VOTE_DOWN,
                'comment ' . $index,
                str_pad( (string) $index, 64, 'e', STR_PAD_LEFT )
            );
        }

        $this->assertSame( 5, $this->feedback->count_rows() );
        $this->assertSame( 3, $this->feedback->count_rows( array( 'vote' => Licensio_KB_Feedback::VOTE_UP ) ) );
        $this->assertSame( 2, $this->feedback->count_rows( array( 'vote' => Licensio_KB_Feedback::VOTE_DOWN ) ) );

        $page_one = $this->feedback->get_rows(
            array(
                'per_page' => 2,
                'page'     => 1,
            )
        );
        $page_two = $this->feedback->get_rows(
            array(
                'per_page' => 2,
                'page'     => 2,
            )
        );

        $this->assertCount( 2, $page_one );
        $this->assertCount( 2, $page_two );
        $this->assertNotSame( (int) $page_one[0]->id, (int) $page_two[0]->id );

        $first = $this->feedback->get_rows( array( 'per_page' => 1 ) )[0];

        $this->assertTrue( $this->feedback->update_status( (int) $first->id, Licensio_KB_Feedback::STATUS_ACTIONED ) );
        $this->assertSame(
            Licensio_KB_Feedback::STATUS_ACTIONED,
            (string) $this->feedback->get_row( (int) $first->id )->status
        );
        $this->assertSame( 1, $this->feedback->count_rows( array( 'status' => Licensio_KB_Feedback::STATUS_ACTIONED ) ) );
        $this->assertSame( 4, $this->feedback->count_rows( array( 'status' => Licensio_KB_Feedback::STATUS_NEW ) ) );
    }

    /**
     * The status counts come back from one grouped query, zeroes included.
     *
     * The inbox draws its status filter from these, so a status with no rows
     * still has to appear - a filter that hides its empty options makes the
     * set of options change as rows are triaged.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_status_counts_cover_every_status() {
        $this->feedback->record_vote( 42, Licensio_KB_Feedback::VOTE_DOWN, 'a', self::HASH_A, 0 );
        $this->feedback->record_vote( 43, Licensio_KB_Feedback::VOTE_DOWN, 'b', self::HASH_A, 0 );
        $this->feedback->record_vote( 44, Licensio_KB_Feedback::VOTE_UP, 'c', self::HASH_A, 0 );

        $first = $this->feedback->get_rows( array( 'per_page' => 1 ) )[0];
        $this->feedback->update_status( (int) $first->id, Licensio_KB_Feedback::STATUS_REVIEWED );

        $counts = $this->feedback->counts_by_status();

        $this->assertSame( 3, $counts['all'] );
        $this->assertSame( 2, $counts[ Licensio_KB_Feedback::STATUS_NEW ] );
        $this->assertSame( 1, $counts[ Licensio_KB_Feedback::STATUS_REVIEWED ] );
        $this->assertSame( 0, $counts[ Licensio_KB_Feedback::STATUS_ACTIONED ] );
    }

    /**
     * The counts describe every status under the *other* filters.
     *
     * Narrowing to negative votes must narrow the counts, or the numbers beside
     * the status filter would describe a different set of rows than the table
     * below them. Narrowing to a status must not, or every count except the
     * selected one would collapse to zero the moment a status was picked, and
     * the filter could never be widened again by reading it.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_counts_follow_the_vote_filter_but_not_the_status_filter() {
        $this->feedback->record_vote( 42, Licensio_KB_Feedback::VOTE_DOWN, 'a', self::HASH_A, 0 );
        $this->feedback->record_vote( 43, Licensio_KB_Feedback::VOTE_DOWN, 'b', self::HASH_A, 0 );
        $this->feedback->record_vote( 44, Licensio_KB_Feedback::VOTE_UP, 'c', self::HASH_A, 0 );

        $negative = $this->feedback->counts_by_status(
            array( 'vote' => Licensio_KB_Feedback::VOTE_DOWN )
        );

        $this->assertSame( 2, $negative['all'] );
        $this->assertSame( 2, $negative[ Licensio_KB_Feedback::STATUS_NEW ] );

        $narrowed = $this->feedback->counts_by_status(
            array( 'status' => Licensio_KB_Feedback::STATUS_ACTIONED )
        );

        $this->assertSame( 3, $narrowed['all'] );
        $this->assertSame( 3, $narrowed[ Licensio_KB_Feedback::STATUS_NEW ] );
        $this->assertSame( 0, $narrowed[ Licensio_KB_Feedback::STATUS_ACTIONED ] );
    }

    /**
     * A stored row keeps the values it was given.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_stored_row_round_trips() {
        $this->feedback->record_vote( 42, Licensio_KB_Feedback::VOTE_DOWN, 'Step 3 is wrong', self::HASH_A, 9 );

        $row = $this->raw_rows()[0];

        $this->assertSame( '42', (string) $row['article_id'] );
        $this->assertSame( '-1', (string) $row['vote'] );
        $this->assertSame( 'Step 3 is wrong', (string) $row['comment'] );
        $this->assertSame( self::HASH_A, (string) $row['visitor_hash'] );
        $this->assertSame( '9', (string) $row['user_id'] );
        $this->assertSame( 'new', (string) $row['status'] );
        $this->assertSame( '2026-02-01 10:00:00', (string) $row['created_at'] );
    }
}
