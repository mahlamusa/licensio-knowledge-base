<?php
/**
 * Feedback REST endpoints.
 *
 * Implements the two routes the scaffold reserved for this issue:
 *
 * - `POST /licensio-kb/v1/feedback` — public. Nonce-checked by the base
 *   controller, rate-limited per visitor hash, and refused outright when the
 *   visitor has already voted on the article. That last check is a database
 *   lookup against every hash the visitor could be known by; no cookie is
 *   consulted and no client-supplied identity is trusted.
 * - `PATCH /licensio-kb/v1/feedback/{id}` — `manage_licensio_kb`. Moves a row
 *   between the three triage statuses.
 *
 * `GET /licensio-kb/v1/feedback` is added alongside them, also gated on
 * `manage_licensio_kb`, because the feedback inbox has to read the rows it
 * triages. It is a listing of raw rows and is deliberately distinct from the
 * aggregate `/analytics/feedback` route reserved for the analytics issue.
 *
 * No IP address is read, written or returned anywhere in this file.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Serves the feedback write and triage endpoints.
 *
 * @since 1.0.0
 */
class Licensio_KB_Feedback_REST_Controller extends Licensio_KB_REST_Controller {

    /**
     * The route base.
     *
     * @since 1.0.0
     * @var string
     */
    protected $rest_base = 'feedback';

    /**
     * Votes a single visitor may submit per window.
     *
     * The one-vote-per-visitor rule already stops repeat voting on one article;
     * this bounds a caller walking the whole knowledge base.
     *
     * @since 1.0.0
     * @var int
     */
    const RATE_LIMIT = 10;

    /**
     * The rate-limit window, in seconds.
     *
     * Spelled as a literal rather than as `MINUTE_IN_SECONDS` so the controller
     * can be exercised without the WordPress constants being defined.
     *
     * @since 1.0.0
     * @var int
     */
    const RATE_LIMIT_WINDOW = 60;

    /**
     * The feedback store.
     *
     * @since 1.0.0
     * @var Licensio_KB_Feedback
     */
    private $feedback;

    /**
     * The visitor identity helper.
     *
     * @since 1.0.0
     * @var Licensio_KB_Visitor
     */
    private $visitor;

    /**
     * Constructor.
     *
     * Both collaborators are optional because Licensio_KB_REST_API constructs
     * controllers by class name with no arguments; the parameters exist so a
     * test can drive the controller against a substitute store.
     *
     * @since 1.0.0
     *
     * @param Licensio_KB_Feedback|null $feedback The feedback store.
     * @param Licensio_KB_Visitor|null  $visitor  The visitor identity helper.
     */
    public function __construct( $feedback = null, $visitor = null ) {
        $this->feedback = $feedback instanceof Licensio_KB_Feedback ? $feedback : new Licensio_KB_Feedback();
        $this->visitor  = $visitor instanceof Licensio_KB_Visitor ? $visitor : new Licensio_KB_Visitor();
    }

    /**
     * Register the feedback routes.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function register_routes() {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            array(
                array(
                    'methods'             => 'POST',
                    'callback'            => array( $this, 'create_item' ),
                    'permission_callback' => array( $this, 'create_item_permissions_check' ),
                    'args'                => $this->create_item_args(),
                ),
                array(
                    'methods'             => 'GET',
                    'callback'            => array( $this, 'get_items' ),
                    'permission_callback' => array( $this, 'manage_permissions_check' ),
                    'args'                => $this->get_items_args(),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id>[\d]+)',
            array(
                array(
                    'methods'             => 'PATCH',
                    'callback'            => array( $this, 'update_item' ),
                    'permission_callback' => array( $this, 'manage_permissions_check' ),
                    'args'                => $this->update_item_args(),
                ),
            )
        );
    }

    /**
     * The accepted arguments for a vote submission.
     *
     * @since 1.0.0
     *
     * @return array<string, array<string, mixed>> The argument schema.
     */
    public function create_item_args() {
        return array(
            'article_id' => array(
                'required'          => true,
                'type'              => 'integer',
                'sanitize_callback' => 'absint',
                'description'       => __( 'The article being voted on.', 'licensio-knowledge-base' ),
            ),
            'vote'       => array(
                'required'    => true,
                'type'        => 'integer',
                'enum'        => array( Licensio_KB_Feedback::VOTE_DOWN, Licensio_KB_Feedback::VOTE_UP ),
                'description' => __( 'One for helpful, minus one for not helpful.', 'licensio-knowledge-base' ),
            ),
            'comment'    => array(
                'required'    => false,
                'type'        => 'string',
                'description' => __( 'An optional comment offered with a negative vote.', 'licensio-knowledge-base' ),
            ),
        );
    }

    /**
     * The accepted arguments for an inbox listing.
     *
     * @since 1.0.0
     *
     * @return array<string, array<string, mixed>> The argument schema.
     */
    public function get_items_args() {
        return array(
            'vote'       => array(
                'type'    => 'integer',
                'enum'    => array( Licensio_KB_Feedback::VOTE_DOWN, 0, Licensio_KB_Feedback::VOTE_UP ),
                'default' => 0,
            ),
            'status'     => array(
                'type'    => 'string',
                'enum'    => array_merge( array( '' ), Licensio_KB_Feedback::statuses() ),
                'default' => '',
            ),
            'article_id' => array(
                'type'              => 'integer',
                'sanitize_callback' => 'absint',
                'default'           => 0,
            ),
            'page'       => array(
                'type'              => 'integer',
                'sanitize_callback' => 'absint',
                'default'           => 1,
            ),
            'per_page'   => array(
                'type'              => 'integer',
                'sanitize_callback' => 'absint',
                'default'           => Licensio_KB_Feedback::DEFAULT_PER_PAGE,
            ),
        );
    }

    /**
     * The accepted arguments for a status change.
     *
     * @since 1.0.0
     *
     * @return array<string, array<string, mixed>> The argument schema.
     */
    public function update_item_args() {
        return array(
            'id'     => array(
                'required'          => true,
                'type'              => 'integer',
                'sanitize_callback' => 'absint',
            ),
            'status' => array(
                'required' => true,
                'type'     => 'string',
                'enum'     => Licensio_KB_Feedback::statuses(),
            ),
        );
    }

    /**
     * Permission callback for a vote submission.
     *
     * The nonce check comes from the base controller. It is followed by the
     * per-visitor rate limit, because for a logged-out caller the `wp_rest`
     * nonce is a single site-wide value printed into public markup and proves
     * very little on its own.
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request The request.
     *
     * @return true|WP_Error True when the caller may submit, a WP_Error otherwise.
     */
    public function create_item_permissions_check( $request ) {
        $allowed = $this->public_write_permissions_check( $request );

        if ( is_wp_error( $allowed ) ) {
            return $allowed;
        }

        return $this->check_rate_limit( 'feedback', self::RATE_LIMIT, self::RATE_LIMIT_WINDOW );
    }

    /**
     * Record a vote.
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request The request.
     *
     * @return array<string, mixed>|WP_Error The recorded result, or an error.
     */
    public function create_item( $request ) {
        if ( ! $this->feedback->is_enabled() ) {
            return new WP_Error(
                'licensio_feedback_disabled',
                __( 'Article feedback is turned off on this site.', 'licensio-knowledge-base' ),
                array( 'status' => 403 )
            );
        }

        $article_id = (int) $request->get_param( 'article_id' );
        $vote       = Licensio_KB_Feedback::normalize_vote( $request->get_param( 'vote' ) );

        if ( 0 === $vote ) {
            return new WP_Error(
                'licensio_invalid_vote',
                __( 'A vote must be either helpful or not helpful.', 'licensio-knowledge-base' ),
                array( 'status' => 400 )
            );
        }

        if ( ! $this->is_votable_article( $article_id ) ) {
            return new WP_Error(
                'licensio_unknown_article',
                __( 'That article could not be found.', 'licensio-knowledge-base' ),
                array( 'status' => 404 )
            );
        }

        /*
         * The abuse identity, not the analytics one. `get_hashes()` mixes in
         * the user agent, which the caller supplies, so rotating that header
         * minted a fresh identity and the one-vote-per-article rule fell over:
         * six votes on one article from one address, all accepted.
         *
         * The same value is stored on the row it writes, so the next vote from
         * that address is recognised.
         */
        $hashes  = $this->visitor->get_abuse_hashes();
        $user_id = (int) get_current_user_id();

        if ( $this->feedback->has_voted( $article_id, $hashes, $user_id ) ) {
            return new WP_Error(
                'licensio_already_voted',
                __( 'You have already given feedback on this article.', 'licensio-knowledge-base' ),
                array( 'status' => 409 )
            );
        }

        $comment  = Licensio_KB_Feedback::sanitize_comment( $request->get_param( 'comment' ) );
        $hash     = isset( $hashes[0] ) ? (string) $hashes[0] : '';
        $recorded = $this->feedback->record_vote( $article_id, $vote, $comment, $hash, $user_id );

        if ( ! $recorded ) {
            return new WP_Error(
                'licensio_feedback_not_saved',
                __( 'Your feedback could not be saved. Please try again.', 'licensio-knowledge-base' ),
                array( 'status' => 500 )
            );
        }

        $score = $this->feedback->recompute_score( $article_id );

        return array(
            'recorded' => true,
            'vote'     => $vote,
            'score'    => $score['score'],
            'up'       => $score['up'],
            'down'     => $score['down'],
            'message'  => Licensio_KB_Feedback::VOTE_UP === $vote
                ? __( 'Thanks for your feedback.', 'licensio-knowledge-base' )
                : __( 'Thanks — your feedback helps us improve this article.', 'licensio-knowledge-base' ),
        );
    }

    /**
     * List feedback rows for the inbox.
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request The request.
     *
     * @return array<string, mixed> The page of rows plus its pagination figures.
     */
    public function get_items( $request ) {
        $args = array(
            'vote'       => $request->get_param( 'vote' ),
            'status'     => $request->get_param( 'status' ),
            'article_id' => (int) $request->get_param( 'article_id' ),
            'page'       => (int) $request->get_param( 'page' ),
            'per_page'   => (int) $request->get_param( 'per_page' ),
        );

        $total    = $this->feedback->count_rows( $args );
        $per_page = max( 1, min( Licensio_KB_Feedback::MAX_PER_PAGE, (int) $args['per_page'] ) );
        $items    = array();

        foreach ( $this->feedback->get_rows( $args ) as $row ) {
            $items[] = $this->prepare_row( $row );
        }

        return array(
            'items'    => $items,
            'total'    => $total,
            'pages'    => (int) ceil( $total / $per_page ),
            'page'     => max( 1, (int) $args['page'] ),
            'perPage'  => $per_page,
            'statuses' => Licensio_KB_Feedback::statuses(),
            'counts'   => $this->feedback->counts_by_status( $args ),
        );
    }

    /**
     * Change one row's triage status.
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request The request.
     *
     * @return array<string, mixed>|WP_Error The updated row, or an error.
     */
    public function update_item( $request ) {
        $id     = (int) $request->get_param( 'id' );
        $status = (string) $request->get_param( 'status' );

        if ( ! Licensio_KB_Feedback::is_valid_status( $status ) ) {
            return new WP_Error(
                'licensio_invalid_status',
                __( 'That is not a feedback status.', 'licensio-knowledge-base' ),
                array( 'status' => 400 )
            );
        }

        $row = $this->feedback->get_row( $id );

        if ( null === $row ) {
            return new WP_Error(
                'licensio_feedback_not_found',
                __( 'That feedback entry could not be found.', 'licensio-knowledge-base' ),
                array( 'status' => 404 )
            );
        }

        if ( ! $this->feedback->update_status( $id, $status ) ) {
            return new WP_Error(
                'licensio_feedback_not_updated',
                __( 'That feedback entry could not be updated.', 'licensio-knowledge-base' ),
                array( 'status' => 500 )
            );
        }

        /*
         * The row already in hand, with the status just written, rather than a
         * second read of the same row: one query fewer, and the response cannot
         * disagree with what was stored.
         */
        $row->status = $status;

        return $this->prepare_row( $row );
    }

    /**
     * Shape one stored row for the API.
     *
     * The comment is returned as the plain text that was stored: markup was
     * already stripped on input, and the inbox renders it through Vue's text
     * interpolation, never `v-html`. Nothing in this payload is ever inserted
     * into a page as markup.
     *
     * @since 1.0.0
     *
     * @param object $row The stored row.
     *
     * @return array<string, mixed> The row as the API reports it.
     */
    public function prepare_row( $row ) {
        $article_id = isset( $row->article_id ) ? (int) $row->article_id : 0;
        $user_id    = isset( $row->user_id ) ? (int) $row->user_id : 0;

        return array(
            'id'          => isset( $row->id ) ? (int) $row->id : 0,
            'articleId'   => $article_id,
            'articleName' => $article_id > 0 ? (string) get_the_title( $article_id ) : '',
            'editUrl'     => $article_id > 0 ? (string) get_edit_post_link( $article_id, 'raw' ) : '',
            'viewUrl'     => $article_id > 0 ? (string) get_permalink( $article_id ) : '',
            'vote'        => isset( $row->vote ) ? (int) $row->vote : 0,
            'comment'     => isset( $row->comment ) ? Licensio_KB_Feedback::sanitize_comment( $row->comment ) : '',
            'status'      => isset( $row->status ) ? (string) $row->status : Licensio_KB_Feedback::STATUS_NEW,
            'createdAt'   => isset( $row->created_at ) ? (string) $row->created_at : '',
            'userId'      => $user_id,
            'userName'    => $this->user_name( $user_id ),
        );
    }

    /**
     * The display name behind a user ID.
     *
     * @since 1.0.0
     *
     * @param int $user_id The user ID, or 0 for a logged-out visitor.
     *
     * @return string The display name, or an empty string.
     */
    private function user_name( $user_id ) {
        if ( $user_id <= 0 ) {
            return '';
        }

        $user = get_userdata( $user_id );

        return ( $user && isset( $user->display_name ) ) ? (string) $user->display_name : '';
    }

    /**
     * Whether an ID names an article a reader is allowed to vote on.
     *
     * @since 1.0.0
     *
     * @param int $article_id The candidate article.
     *
     * @return bool True when the article exists, is a knowledge base article and is published.
     */
    private function is_votable_article( $article_id ) {
        if ( $article_id <= 0 ) {
            return false;
        }

        $post = get_post( $article_id );

        if ( ! $post instanceof WP_Post ) {
            return false;
        }

        return Licensio_KB_Post_Types::POST_TYPE === $post->post_type && 'publish' === $post->post_status;
    }
}
