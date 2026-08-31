<?php
/**
 * Unit tests for the feedback REST endpoints.
 *
 * The controller is driven against an in-memory store that genuinely
 * implements the feedback contract: a row recorded by one call is visible to
 * the next. It is a substitute for the database, not for the rules — so a
 * second vote is refused here because the first one was really recorded, and
 * deleting the refusal from the controller fails this file.
 *
 * The rule itself, executed by a real server, is proved in
 * tests/Integration/Lisensio_KB_Feedback_Integration_Test.php.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

use Brain\Monkey\Functions;

/**
 * An in-memory feedback store.
 *
 * @since 1.0.0
 */
class Lisensio_KB_Feedback_Memory_Store extends Lisensio_KB_Feedback {

    /**
     * The stored rows.
     *
     * @since 1.0.0
     * @var array<int, object>
     */
    public $rows = array();

    /**
     * Whether voting is on.
     *
     * @since 1.0.0
     * @var bool
     */
    public $enabled = true;

    /**
     * Whether the next write should fail.
     *
     * @since 1.0.0
     * @var bool
     */
    public $write_fails = false;

    /**
     * The next row ID to hand out.
     *
     * @since 1.0.0
     * @var int
     */
    private $next_id = 1;

    /**
     * Whether voting is on.
     *
     * @since 1.0.0
     *
     * @return bool True when voting is on.
     */
    public function is_enabled() {
        return $this->enabled;
    }

    /**
     * Whether a row from this visitor already exists for this article.
     *
     * @since 1.0.0
     *
     * @param int      $article_id The article.
     * @param string[] $hashes     The visitor hashes.
     * @param int      $user_id    The user ID, or 0.
     *
     * @return bool True when a matching row exists.
     */
    public function has_voted( $article_id, $hashes, $user_id = 0 ) {
        foreach ( $this->rows as $row ) {
            if ( (int) $row->article_id !== (int) $article_id ) {
                continue;
            }

            if ( in_array( (string) $row->visitor_hash, array_map( 'strval', (array) $hashes ), true ) ) {
                return true;
            }

            if ( (int) $user_id > 0 && (int) $row->user_id === (int) $user_id ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Store a row.
     *
     * @since 1.0.0
     *
     * @param int    $article_id The article.
     * @param int    $vote       The vote.
     * @param string $comment    The comment.
     * @param string $hash       The visitor hash.
     * @param int    $user_id    The user ID.
     *
     * @return bool True when the row was stored.
     */
    public function record_vote( $article_id, $vote, $comment, $hash, $user_id = 0 ) {
        if ( $this->write_fails ) {
            return false;
        }

        $this->rows[] = (object) array(
            'id'           => $this->next_id++,
            'article_id'   => (int) $article_id,
            'vote'         => (int) $vote,
            'comment'      => (string) $comment,
            'visitor_hash' => (string) $hash,
            'user_id'      => (int) $user_id,
            'status'       => Lisensio_KB_Feedback::STATUS_NEW,
            'created_at'   => '2026-02-01 10:00:00',
        );

        return true;
    }

    /**
     * Count the stored rows for one article.
     *
     * @since 1.0.0
     *
     * @param int $article_id The article.
     *
     * @return array{up: int, down: int} The counts.
     */
    public function count_votes( $article_id ) {
        $up   = 0;
        $down = 0;

        foreach ( $this->rows as $row ) {
            if ( (int) $row->article_id !== (int) $article_id ) {
                continue;
            }

            if ( Lisensio_KB_Feedback::VOTE_UP === (int) $row->vote ) {
                ++$up;
            }

            if ( Lisensio_KB_Feedback::VOTE_DOWN === (int) $row->vote ) {
                ++$down;
            }
        }

        return array(
            'up'   => $up,
            'down' => $down,
        );
    }

    /**
     * Recompute the score without touching post meta.
     *
     * @since 1.0.0
     *
     * @param int $article_id The article.
     *
     * @return array{score: int|null, up: int, down: int} The figures.
     */
    public function recompute_score( $article_id ) {
        $counts = $this->count_votes( $article_id );

        return array(
            'score' => Lisensio_KB_Feedback::calculate_score( $counts['up'], $counts['down'] ),
            'up'    => $counts['up'],
            'down'  => $counts['down'],
        );
    }

    /**
     * One row by ID.
     *
     * @since 1.0.0
     *
     * @param int $id The row ID.
     *
     * @return object|null The row, or null.
     */
    public function get_row( $id ) {
        foreach ( $this->rows as $row ) {
            if ( (int) $row->id === (int) $id ) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Move a row to a new status.
     *
     * @since 1.0.0
     *
     * @param int    $id     The row ID.
     * @param string $status The status.
     *
     * @return bool True when the row was updated.
     */
    public function update_status( $id, $status ) {
        $row = $this->get_row( $id );

        if ( null === $row || ! Lisensio_KB_Feedback::is_valid_status( $status ) ) {
            return false;
        }

        $row->status = (string) $status;

        return true;
    }

    /**
     * Rows matching the inbox filters.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $args The filters.
     *
     * @return array<int, object> The matching rows.
     */
    public function get_rows( $args = array() ) {
        return array_values( $this->matching( $args ) );
    }

    /**
     * How many rows match the inbox filters.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $args The filters.
     *
     * @return int The number of matching rows.
     */
    public function count_rows( $args = array() ) {
        return count( $this->matching( $args ) );
    }

    /**
     * How many rows sit in each triage status.
     *
     * Mirrors the real store: the status filter is ignored so the counts
     * describe every status under the other filters.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $args The filters.
     *
     * @return array<string, int> Status name to row count, plus `all`.
     */
    public function counts_by_status( $args = array() ) {
        $args           = is_array( $args ) ? $args : array();
        $args['status'] = '';

        $counts = array( 'all' => 0 );

        foreach ( self::statuses() as $status ) {
            $counts[ $status ] = 0;
        }

        foreach ( $this->matching( $args ) as $row ) {
            ++$counts['all'];

            $status = (string) $row->status;

            if ( array_key_exists( $status, $counts ) ) {
                ++$counts[ $status ];
            }
        }

        return $counts;
    }

    /**
     * Apply the inbox filters in memory.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $args The filters.
     *
     * @return array<int, object> The matching rows.
     */
    private function matching( $args ) {
        $vote   = isset( $args['vote'] ) ? (int) $args['vote'] : 0;
        $status = isset( $args['status'] ) ? (string) $args['status'] : '';

        return array_filter(
            $this->rows,
            static function ( $row ) use ( $vote, $status ) {
                if ( 0 !== $vote && (int) $row->vote !== $vote ) {
                    return false;
                }

                return '' === $status || (string) $row->status === $status;
            }
        );
    }
}

/**
 * Tests Lisensio_KB_Feedback_REST_Controller.
 *
 * @since 1.0.0
 */
class Lisensio_KB_Feedback_REST_Test extends Lisensio_KB_Test_Case {

    /**
     * The in-memory store the controller is driven against.
     *
     * @since 1.0.0
     * @var Lisensio_KB_Feedback_Memory_Store
     */
    private $store;

    /**
     * The controller under test.
     *
     * @since 1.0.0
     * @var Lisensio_KB_Feedback_REST_Controller
     */
    private $controller;

    /**
     * The in-memory transient store backing the rate limiter.
     *
     * @since 1.0.0
     * @var array<string, mixed>
     */
    private $transients = array();

    /**
     * Every route the controller registered.
     *
     * @since 1.0.0
     * @var array<int, array<string, mixed>>
     */
    private $routes = array();

    /**
     * Stub the WordPress surface the controller touches.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        $this->transients = array();
        $this->routes     = array();

        $_SERVER['REMOTE_ADDR']     = '198.51.100.4';
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Lisensio Test Runner)';

        Functions\when( 'rest_authorization_required_code' )->justReturn( 401 );
        Functions\when( 'is_wp_error' )->alias(
            function ( $thing ) {
                return $thing instanceof WP_Error;
            }
        );
        Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\when( 'get_current_user_id' )->justReturn( 0 );
        Functions\when( 'absint' )->alias(
            function ( $value ) {
                return abs( (int) $value );
            }
        );
        Functions\when( 'sanitize_textarea_field' )->alias(
            function ( $value ) {
                return is_string( $value ) ? trim( strip_tags( $value ) ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
            }
        );
        Functions\when( 'get_transient' )->alias(
            function ( $key ) {
                return array_key_exists( $key, $this->transients ) ? $this->transients[ $key ] : false;
            }
        );
        Functions\when( 'set_transient' )->alias(
            function ( $key, $value ) {
                $this->transients[ $key ] = $value;

                return true;
            }
        );
        Functions\when( 'get_post' )->alias(
            function ( $id ) {
                if ( 42 === (int) $id ) {
                    return new WP_Post(
                        array(
                            'ID'          => 42,
                            'post_type'   => Lisensio_KB_Post_Types::POST_TYPE,
                            'post_status' => 'publish',
                            'post_title'  => 'Installing the plugin',
                        )
                    );
                }

                if ( 43 === (int) $id ) {
                    return new WP_Post(
                        array(
                            'ID'          => 43,
                            'post_type'   => Lisensio_KB_Post_Types::POST_TYPE,
                            'post_status' => 'draft',
                        )
                    );
                }

                if ( 44 === (int) $id ) {
                    return new WP_Post(
                        array(
                            'ID'          => 44,
                            'post_type'   => 'post',
                            'post_status' => 'publish',
                        )
                    );
                }

                return null;
            }
        );
        Functions\when( 'get_the_title' )->justReturn( 'Installing the plugin' );
        Functions\when( 'get_edit_post_link' )->alias(
            function ( $id ) {
                return 'https://example.test/wp-admin/post.php?post=' . (int) $id . '&action=edit';
            }
        );
        Functions\when( 'get_permalink' )->justReturn( 'https://example.test/docs/installing/' );
        Functions\when( 'get_userdata' )->justReturn( false );
        Functions\when( 'register_rest_route' )->alias(
            function ( $route_namespace, $route, $args ) {
                $this->routes[] = array(
                    'namespace' => $route_namespace,
                    'route'     => $route,
                    'args'      => $args,
                );

                return true;
            }
        );

        $this->store      = new Lisensio_KB_Feedback_Memory_Store( new Lisensio_KB_Settings() );
        $this->controller = new Lisensio_KB_Feedback_REST_Controller( $this->store, new Lisensio_KB_Visitor() );
    }

    /**
     * Drop the request superglobals.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function tearDown(): void {
        unset( $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT'] );

        parent::tearDown();
    }

    /**
     * Build a vote request.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $params The request parameters.
     * @param bool                 $nonce  Whether to send a nonce header.
     *
     * @return WP_REST_Request The request.
     */
    private function vote_request( $params = array(), $nonce = true ) {
        $params = array_merge(
            array(
                'article_id' => 42,
                'vote'       => Lisensio_KB_Feedback::VOTE_UP,
                'comment'    => '',
            ),
            $params
        );

        return new WP_REST_Request(
            $nonce ? array( 'X-WP-Nonce' => 'a-valid-nonce' ) : array(),
            '/lisensio-kb/v1/feedback',
            $params
        );
    }

    /**
     * A vote without a valid nonce never reaches the handler.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_vote_without_a_valid_nonce_is_refused() {
        Functions\when( 'wp_verify_nonce' )->justReturn( false );

        $result = $this->controller->create_item_permissions_check( $this->vote_request( array(), false ) );

        $this->assertInstanceOf( 'WP_Error', $result );
        $this->assertSame( 'lisensio_invalid_nonce', $result->get_error_code() );
        $this->assertSame( 403, $result->error_data['lisensio_invalid_nonce']['status'] );
        $this->assertCount( 0, $this->store->rows, 'No row may be written by a refused request.' );
    }

    /**
     * A vote with a valid nonce passes the permission check.
     *
     * Without this the nonce test above would also pass a controller that
     * refused every request for any reason at all.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_vote_with_a_valid_nonce_is_permitted() {
        $this->assertTrue( $this->controller->create_item_permissions_check( $this->vote_request() ) );
    }

    /**
     * A caller who spends their budget is rate limited.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_caller_who_spends_their_budget_is_rate_limited() {
        for ( $attempt = 0; $attempt < Lisensio_KB_Feedback_REST_Controller::RATE_LIMIT; $attempt++ ) {
            $this->assertTrue( $this->controller->create_item_permissions_check( $this->vote_request() ) );
        }

        $result = $this->controller->create_item_permissions_check( $this->vote_request() );

        $this->assertInstanceOf( 'WP_Error', $result );
        $this->assertSame( 'lisensio_rate_limited', $result->get_error_code() );
        $this->assertSame( 429, $result->error_data['lisensio_rate_limited']['status'] );
    }

    /**
     * A first vote is recorded and reports the new score.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_first_vote_is_recorded() {
        $result = $this->controller->create_item( $this->vote_request() );

        $this->assertIsArray( $result );
        $this->assertTrue( $result['recorded'] );
        $this->assertSame( 100, $result['score'] );
        $this->assertSame( 1, $result['up'] );
        $this->assertSame( 0, $result['down'] );
        $this->assertCount( 1, $this->store->rows );
        $this->assertSame( 42, (int) $this->store->rows[0]->article_id );
    }

    /**
     * Rotating the user agent does not buy a second vote.
     *
     * The one-vote-per-article rule is the only thing standing between an
     * anonymous caller and every rating on the site, and it keyed on an
     * identity built partly from a header the caller sets. Demonstrated
     * against the live site: six accepted votes on one article from a single
     * address, by varying that header alone.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_rotating_user_agent_does_not_buy_a_second_vote() {
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (first client)';

        $this->controller->create_item( $this->vote_request() );

        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (second client, quite different)';

        $result = $this->controller->create_item( $this->vote_request() );

        $this->assertInstanceOf( 'WP_Error', $result, 'a new user agent was allowed a second vote' );
        $this->assertSame( 'lisensio_already_voted', $result->get_error_code() );
    }

    /**
     * A second vote from the same visitor is refused by the server.
     *
     * The refusal is a database question, not a cookie: nothing about the
     * request changes between the two calls except that the first one really
     * wrote a row.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_second_vote_from_the_same_visitor_is_refused() {
        $this->controller->create_item( $this->vote_request() );

        $result = $this->controller->create_item( $this->vote_request( array( 'vote' => Lisensio_KB_Feedback::VOTE_DOWN ) ) );

        $this->assertInstanceOf( 'WP_Error', $result );
        $this->assertSame( 'lisensio_already_voted', $result->get_error_code() );
        $this->assertSame( 409, $result->error_data['lisensio_already_voted']['status'] );
        $this->assertCount( 1, $this->store->rows, 'The second vote must not be written.' );
    }

    /**
     * The same visitor may still vote on a different article.
     *
     * Guards against a fix that refuses a second vote outright rather than a
     * second vote on the same article.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_same_visitor_may_vote_on_another_article() {
        $this->controller->create_item( $this->vote_request() );

        Functions\when( 'get_post' )->alias(
            function ( $id ) {
                return new WP_Post(
                    array(
                        'ID'          => (int) $id,
                        'post_type'   => Lisensio_KB_Post_Types::POST_TYPE,
                        'post_status' => 'publish',
                    )
                );
            }
        );

        $result = $this->controller->create_item( $this->vote_request( array( 'article_id' => 99 ) ) );

        $this->assertIsArray( $result );
        $this->assertTrue( $result['recorded'] );
        $this->assertCount( 2, $this->store->rows );
    }

    /**
     * A vote that is neither helpful nor unhelpful is refused.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_unrecognised_vote_is_refused() {
        $result = $this->controller->create_item( $this->vote_request( array( 'vote' => 7 ) ) );

        $this->assertInstanceOf( 'WP_Error', $result );
        $this->assertSame( 'lisensio_invalid_vote', $result->get_error_code() );
        $this->assertCount( 0, $this->store->rows );
    }

    /**
     * Votes are only accepted for published knowledge base articles.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_votes_are_only_accepted_for_published_articles() {
        foreach ( array( 0, 43, 44, 999 ) as $article_id ) {
            $result = $this->controller->create_item( $this->vote_request( array( 'article_id' => $article_id ) ) );

            $this->assertInstanceOf( 'WP_Error', $result, sprintf( 'Article %d must be refused.', $article_id ) );
            $this->assertSame( 'lisensio_unknown_article', $result->get_error_code() );
        }

        $this->assertCount( 0, $this->store->rows );
    }

    /**
     * A comment carrying markup is stored as plain text.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_comment_carrying_markup_is_never_stored_as_markup() {
        $this->controller->create_item(
            $this->vote_request(
                array(
                    'vote'    => Lisensio_KB_Feedback::VOTE_DOWN,
                    'comment' => 'Broken <script>alert(1)</script> here',
                )
            )
        );

        $stored = (string) $this->store->rows[0]->comment;

        $this->assertSame( 'Broken alert(1) here', $stored );
        $this->assertStringNotContainsString( '<', $stored );
        $this->assertStringNotContainsString( 'script', $stored );
    }

    /**
     * The API never hands the inbox a comment containing markup.
     *
     * Belt and braces over the input sanitisation: even a row written by some
     * other route — a migration, a hand-edited table — is returned as text.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_api_never_returns_a_comment_containing_markup() {
        $this->store->rows[] = (object) array(
            'id'           => 5,
            'article_id'   => 42,
            'vote'         => Lisensio_KB_Feedback::VOTE_DOWN,
            'comment'      => 'Legacy <b>row</b> <img src=x onerror=alert(1)>',
            'visitor_hash' => str_repeat( 'a', 64 ),
            'user_id'      => 0,
            'status'       => Lisensio_KB_Feedback::STATUS_NEW,
            'created_at'   => '2026-01-01 00:00:00',
        );

        $prepared = $this->controller->prepare_row( $this->store->rows[0] );

        $this->assertSame( 'Legacy row', $prepared['comment'] );
        $this->assertStringNotContainsString( '<', $prepared['comment'] );
        $this->assertStringNotContainsString( 'onerror', $prepared['comment'] );
    }

    /**
     * The prepared row carries neither a visitor hash nor an IP address.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_prepared_row_leaks_no_visitor_identity() {
        $this->controller->create_item( $this->vote_request() );

        $prepared = $this->controller->prepare_row( $this->store->rows[0] );
        $encoded  = wp_json_encode( $prepared );

        $this->assertArrayNotHasKey( 'visitorHash', $prepared );
        $this->assertArrayNotHasKey( 'visitor_hash', $prepared );
        $this->assertStringNotContainsString( '198.51.100.4', (string) $encoded );
        $this->assertStringNotContainsString( (string) $this->store->rows[0]->visitor_hash, (string) $encoded );
    }

    /**
     * The inbox listing filters by vote and by status.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_inbox_listing_filters_by_vote_and_status() {
        $this->seed_rows();

        $all = $this->controller->get_items( $this->list_request() );

        $this->assertSame( 3, $all['total'] );
        $this->assertCount( 3, $all['items'] );

        $negative = $this->controller->get_items( $this->list_request( array( 'vote' => Lisensio_KB_Feedback::VOTE_DOWN ) ) );

        $this->assertSame( 2, $negative['total'] );

        $reviewed = $this->controller->get_items( $this->list_request( array( 'status' => Lisensio_KB_Feedback::STATUS_REVIEWED ) ) );

        $this->assertSame( 1, $reviewed['total'] );
        $this->assertSame( Lisensio_KB_Feedback::STATUS_REVIEWED, $reviewed['items'][0]['status'] );
    }

    /**
     * Each listed row deep links to the article editor.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_each_listed_row_links_to_the_article_editor() {
        $this->seed_rows();

        $page = $this->controller->get_items( $this->list_request() );

        $this->assertSame(
            'https://example.test/wp-admin/post.php?post=42&action=edit',
            $page['items'][0]['editUrl']
        );
    }

    /**
     * A status change is written and reported back.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_status_change_is_written() {
        $this->seed_rows();

        $result = $this->controller->update_item( $this->patch_request( 1, Lisensio_KB_Feedback::STATUS_ACTIONED ) );

        $this->assertIsArray( $result );
        $this->assertSame( Lisensio_KB_Feedback::STATUS_ACTIONED, $result['status'] );
        $this->assertSame( Lisensio_KB_Feedback::STATUS_ACTIONED, (string) $this->store->get_row( 1 )->status );
    }

    /**
     * A status the model does not know is refused.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_unknown_status_is_refused() {
        $this->seed_rows();

        $result = $this->controller->update_item( $this->patch_request( 1, 'spam' ) );

        $this->assertInstanceOf( 'WP_Error', $result );
        $this->assertSame( 'lisensio_invalid_status', $result->get_error_code() );
        $this->assertSame( Lisensio_KB_Feedback::STATUS_NEW, (string) $this->store->get_row( 1 )->status );
    }

    /**
     * A status change for a row that does not exist is a 404.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_status_change_for_a_missing_row_is_not_found() {
        $result = $this->controller->update_item( $this->patch_request( 999, Lisensio_KB_Feedback::STATUS_REVIEWED ) );

        $this->assertInstanceOf( 'WP_Error', $result );
        $this->assertSame( 'lisensio_feedback_not_found', $result->get_error_code() );
        $this->assertSame( 404, $result->error_data['lisensio_feedback_not_found']['status'] );
    }

    /**
     * Voting is refused outright when the setting is off.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_voting_is_refused_when_feedback_is_disabled() {
        $this->store->enabled = false;

        $result = $this->controller->create_item( $this->vote_request() );

        $this->assertInstanceOf( 'WP_Error', $result );
        $this->assertSame( 'lisensio_feedback_disabled', $result->get_error_code() );
        $this->assertCount( 0, $this->store->rows );
    }

    /**
     * A failed write is reported rather than answered as success.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_failed_write_is_reported() {
        $this->store->write_fails = true;

        $result = $this->controller->create_item( $this->vote_request() );

        $this->assertInstanceOf( 'WP_Error', $result );
        $this->assertSame( 'lisensio_feedback_not_saved', $result->get_error_code() );
    }

    /**
     * The routes carry the permission callbacks the specification requires.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_routes_carry_the_right_permission_callbacks() {
        $this->controller->register_routes();

        $this->assertCount( 2, $this->routes );

        $collection = $this->routes[0];

        $this->assertSame( '/feedback', $collection['route'] );
        $this->assertSame( LISENSIO_KB_REST_NAMESPACE, $collection['namespace'] );
        $this->assertSame( 'POST', $collection['args'][0]['methods'] );
        $this->assertSame( 'create_item_permissions_check', $collection['args'][0]['permission_callback'][1] );
        $this->assertSame( 'GET', $collection['args'][1]['methods'] );
        $this->assertSame( 'manage_permissions_check', $collection['args'][1]['permission_callback'][1] );

        $single = $this->routes[1];

        $this->assertSame( '/feedback/(?P<id>[\d]+)', $single['route'] );
        $this->assertSame( 'PATCH', $single['args'][0]['methods'] );
        $this->assertSame( 'manage_permissions_check', $single['args'][0]['permission_callback'][1] );
    }

    /**
     * The management routes are refused without the capability.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_management_routes_are_refused_without_the_capability() {
        Functions\when( 'current_user_can' )->justReturn( false );

        $result = $this->controller->manage_permissions_check( $this->list_request() );

        $this->assertInstanceOf( 'WP_Error', $result );
        $this->assertSame( 'lisensio_forbidden', $result->get_error_code() );
    }

    /**
     * Put three rows in the store: two negative, one positive, one reviewed.
     *
     * @since 1.0.0
     *
     * @return void
     */
    private function seed_rows() {
        $this->store->record_vote( 42, Lisensio_KB_Feedback::VOTE_DOWN, 'Step 3 is wrong', str_repeat( 'a', 64 ), 0 );
        $this->store->record_vote( 42, Lisensio_KB_Feedback::VOTE_DOWN, 'Missing screenshot', str_repeat( 'b', 64 ), 0 );
        $this->store->record_vote( 42, Lisensio_KB_Feedback::VOTE_UP, '', str_repeat( 'c', 64 ), 0 );

        $this->store->update_status( 2, Lisensio_KB_Feedback::STATUS_REVIEWED );
    }

    /**
     * Build an inbox listing request.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $params The request parameters.
     *
     * @return WP_REST_Request The request.
     */
    private function list_request( $params = array() ) {
        return new WP_REST_Request(
            array(),
            '/lisensio-kb/v1/feedback',
            array_merge(
                array(
                    'vote'       => 0,
                    'status'     => '',
                    'article_id' => 0,
                    'page'       => 1,
                    'per_page'   => Lisensio_KB_Feedback::DEFAULT_PER_PAGE,
                ),
                $params
            )
        );
    }

    /**
     * Build a status-change request.
     *
     * @since 1.0.0
     *
     * @param int    $id     The row ID.
     * @param string $status The status.
     *
     * @return WP_REST_Request The request.
     */
    private function patch_request( $id, $status ) {
        return new WP_REST_Request(
            array(),
            '/lisensio-kb/v1/feedback/' . (int) $id,
            array(
                'id'     => (int) $id,
                'status' => $status,
            )
        );
    }
}
