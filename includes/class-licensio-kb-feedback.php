<?php
/**
 * Article feedback and voting.
 *
 * Owns every read and write of the `licensio_feedback` table and the derived
 * per-article usefulness score. It is also the component the plugin container
 * boots: `init_hooks()` wires the frontend widget, the admin inbox and the
 * REST layer so `Licensio_KB::boot()` gains one component rather than four.
 *
 * Two rules are structural rather than incidental:
 *
 * - No IP address is written. Visitor identity is the rotating salted hash
 *   produced by Licensio_KB_Visitor, and the row shape assembled in
 *   `record_vote()` is the only place a feedback row is ever built.
 * - Comments are stripped of markup on the way in by `sanitize_comment()`, so
 *   nothing that could be interpreted as HTML reaches the database. Every
 *   consumer escapes again on the way out.
 *
 * All row access goes through `Licensio_KB_DB::table()`; raw `$wpdb` never
 * appears here.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stores votes and derives the usefulness score.
 *
 * @since 1.0.0
 */
class Licensio_KB_Feedback {

    /**
     * The unprefixed table this component owns.
     *
     * @since 1.0.0
     * @var string
     */
    const TABLE = 'licensio_feedback';

    /**
     * The value stored for a helpful vote.
     *
     * @since 1.0.0
     * @var int
     */
    const VOTE_UP = 1;

    /**
     * The value stored for an unhelpful vote.
     *
     * @since 1.0.0
     * @var int
     */
    const VOTE_DOWN = -1;

    /**
     * Status of a row nobody has triaged yet.
     *
     * @since 1.0.0
     * @var string
     */
    const STATUS_NEW = 'new';

    /**
     * Status of a row an administrator has read.
     *
     * @since 1.0.0
     * @var string
     */
    const STATUS_REVIEWED = 'reviewed';

    /**
     * Status of a row an administrator has acted on.
     *
     * @since 1.0.0
     * @var string
     */
    const STATUS_ACTIONED = 'actioned';

    /**
     * Post meta key holding the cached usefulness percentage.
     *
     * @since 1.0.0
     * @var string
     */
    const META_SCORE = '_licensio_usefulness_score';

    /**
     * Post meta key holding the cached helpful vote count.
     *
     * @since 1.0.0
     * @var string
     */
    const META_UP = '_licensio_usefulness_up';

    /**
     * Post meta key holding the cached unhelpful vote count.
     *
     * @since 1.0.0
     * @var string
     */
    const META_DOWN = '_licensio_usefulness_down';

    /**
     * Longest comment accepted, in characters.
     *
     * @since 1.0.0
     * @var int
     */
    const COMMENT_MAX_LENGTH = 2000;

    /**
     * Rows returned by the inbox when no page size is asked for.
     *
     * @since 1.0.0
     * @var int
     */
    const DEFAULT_PER_PAGE = 20;

    /**
     * Largest page size the inbox may ask for.
     *
     * @since 1.0.0
     * @var int
     */
    const MAX_PER_PAGE = 100;

    /**
     * The settings store, used for the `enable_feedback` gate.
     *
     * @since 1.0.0
     * @var Licensio_KB_Settings|null
     */
    private $settings = null;

    /**
     * Constructor.
     *
     * @since 1.0.0
     *
     * @param Licensio_KB_Settings|null $settings The settings store. Created on demand when omitted, so the
     *                                            REST registrar can instantiate the component with no arguments.
     */
    public function __construct( $settings = null ) {
        $this->settings = $settings instanceof Licensio_KB_Settings ? $settings : null;
    }

    /**
     * Wire the feedback component into WordPress.
     *
     * The REST controller is announced through `licensio_rest_controllers`
     * rather than by hooking `rest_api_init` here, and the two routes it now
     * implements are withdrawn from the scaffold's not-implemented stub table,
     * which is the contract Licensio_KB_REST_Stubs_Controller documents.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function init_hooks() {
        add_filter( 'licensio_rest_controllers', array( $this, 'register_rest_controller' ) );
        add_filter( 'licensio_rest_stub_routes', array( $this, 'release_stub_routes' ) );

        $widget = new Licensio_KB_Feedback_Widget( $this );
        $widget->init_hooks();

        if ( is_admin() ) {
            $admin = new Licensio_KB_Feedback_Admin();
            $admin->init_hooks();
        }
    }

    /**
     * Add the feedback controller to the plugin's REST registrar.
     *
     * @since 1.0.0
     *
     * @param string[] $controllers The controller class names.
     *
     * @return string[] The controller class names, with this component's added.
     */
    public function register_rest_controller( $controllers ) {
        $controllers   = is_array( $controllers ) ? $controllers : array();
        $controllers[] = 'Licensio_KB_Feedback_REST_Controller';

        return $controllers;
    }

    /**
     * Withdraw the two feedback routes from the not-implemented stub table.
     *
     * @since 1.0.0
     *
     * @param array<string, array<string, string>> $routes The reserved routes.
     *
     * @return array<string, array<string, string>> The reserved routes, without the feedback ones.
     */
    public function release_stub_routes( $routes ) {
        if ( ! is_array( $routes ) ) {
            return $routes;
        }

        unset( $routes['/feedback'], $routes['/feedback/(?P<id>[\d]+)'] );

        return $routes;
    }

    /**
     * The settings store.
     *
     * @since 1.0.0
     *
     * @return Licensio_KB_Settings The settings store.
     */
    public function settings() {
        if ( ! $this->settings instanceof Licensio_KB_Settings ) {
            $this->settings = new Licensio_KB_Settings();
        }

        return $this->settings;
    }

    /**
     * Whether readers may vote on articles.
     *
     * @since 1.0.0
     *
     * @return bool True when the `enable_feedback` setting is on.
     */
    public function is_enabled() {
        return (bool) $this->settings()->get( 'enable_feedback', true );
    }

    /**
     * The statuses a feedback row may hold.
     *
     * @since 1.0.0
     *
     * @return string[] The valid status values.
     */
    public static function statuses() {
        return array( self::STATUS_NEW, self::STATUS_REVIEWED, self::STATUS_ACTIONED );
    }

    /**
     * Reduce a submitted vote to one of the two stored values.
     *
     * Anything that is not recognisably a positive or negative vote returns 0,
     * which callers treat as invalid. Accepting only 1 and -1 is what keeps the
     * score arithmetic honest: a row with any other value would be counted by
     * neither branch and would silently vanish from the totals.
     *
     * @since 1.0.0
     *
     * @param mixed $vote The submitted value.
     *
     * @return int self::VOTE_UP, self::VOTE_DOWN, or 0 when the value is not a vote.
     */
    public static function normalize_vote( $vote ) {
        if ( is_string( $vote ) ) {
            $vote = trim( $vote );

            if ( 'up' === $vote || 'helpful' === $vote || 'yes' === $vote ) {
                return self::VOTE_UP;
            }

            if ( 'down' === $vote || 'unhelpful' === $vote || 'no' === $vote ) {
                return self::VOTE_DOWN;
            }
        }

        if ( ! is_numeric( $vote ) ) {
            return 0;
        }

        $vote = (int) $vote;

        if ( self::VOTE_UP === $vote || self::VOTE_DOWN === $vote ) {
            return $vote;
        }

        return 0;
    }

    /**
     * Reduce a submitted comment to storable plain text.
     *
     * `sanitize_textarea_field()` strips every tag, so no markup reaches the
     * database and nothing stored here can be rendered as HTML by a consumer
     * that forgets to escape. The length cap is applied afterwards, in
     * characters rather than bytes, so a multibyte comment is not cut mid
     * character.
     *
     * @since 1.0.0
     *
     * @param mixed $comment The submitted comment.
     *
     * @return string The sanitised comment, possibly an empty string.
     */
    public static function sanitize_comment( $comment ) {
        if ( ! is_string( $comment ) ) {
            return '';
        }

        $comment = sanitize_textarea_field( $comment );

        if ( function_exists( 'mb_substr' ) ) {
            return trim( mb_substr( $comment, 0, self::COMMENT_MAX_LENGTH ) );
        }

        return trim( substr( $comment, 0, self::COMMENT_MAX_LENGTH ) );
    }

    /**
     * Whether a value names a status a row may hold.
     *
     * @since 1.0.0
     *
     * @param mixed $status The candidate status.
     *
     * @return bool True when the status is one of the three known values.
     */
    public static function is_valid_status( $status ) {
        return is_string( $status ) && in_array( $status, self::statuses(), true );
    }

    /**
     * The usefulness percentage for a pair of vote counts.
     *
     * Pure arithmetic, so the number the column and the API report can be
     * asserted without a database.
     *
     * @since 1.0.0
     *
     * @param int $up   Helpful votes.
     * @param int $down Unhelpful votes.
     *
     * @return int|null The percentage 0-100, or null when nobody has voted.
     */
    public static function calculate_score( $up, $down ) {
        $up    = max( 0, (int) $up );
        $down  = max( 0, (int) $down );
        $total = $up + $down;

        if ( 0 === $total ) {
            return null;
        }

        return (int) round( ( $up / $total ) * 100 );
    }

    /**
     * Whether this visitor has already voted on this article.
     *
     * Two independent lookups rather than one composite condition, because the
     * two identities answer different questions. The hash set covers a
     * logged-out visitor and deliberately includes the previous-salt hash, so a
     * salt rotation does not hand somebody a second vote. The user ID covers a
     * logged-in reader who changed browser, network or user agent, none of
     * which should buy another vote.
     *
     * This is the server-side enforcement of one vote per visitor per article.
     * No cookie is consulted.
     *
     * @since 1.0.0
     *
     * @param int      $article_id The article being voted on.
     * @param string[] $hashes     Every visitor hash the caller could be known by.
     * @param int      $user_id    The logged-in user ID, or 0.
     *
     * @return bool True when a vote from this visitor already exists.
     */
    public function has_voted( $article_id, $hashes, $user_id = 0 ) {
        $article_id = (int) $article_id;
        $user_id    = (int) $user_id;

        if ( $article_id <= 0 ) {
            return false;
        }

        $hashes = array_values(
            array_filter(
                array_map( 'strval', (array) $hashes ),
                static function ( $hash ) {
                    return '' !== $hash;
                }
            )
        );

        if ( ! empty( $hashes ) ) {
            $row = Licensio_KB_DB::table( self::TABLE )
                ->select( 'id' )
                ->where( 'article_id', $article_id )
                ->whereIn( 'visitor_hash', $hashes )
                ->limit( 1 )
                ->get();

            if ( ! empty( $row ) ) {
                return true;
            }
        }

        if ( $user_id > 0 ) {
            $row = Licensio_KB_DB::table( self::TABLE )
                ->select( 'id' )
                ->where( 'article_id', $article_id )
                ->where( 'user_id', $user_id )
                ->limit( 1 )
                ->get();

            if ( ! empty( $row ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Write a vote.
     *
     * The row shape is assembled here and nowhere else. It carries no IP
     * column and no IP value, and the schema owned by Licensio_KB_DB_Installer
     * has no column one could be written to.
     *
     * @since 1.0.0
     *
     * @param int    $article_id The article being voted on.
     * @param int    $vote       self::VOTE_UP or self::VOTE_DOWN.
     * @param string $comment    The already sanitised comment, or an empty string.
     * @param string $hash       The visitor hash the vote is recorded under.
     * @param int    $user_id    The logged-in user ID, or 0.
     *
     * @return bool True when the row was written.
     */
    public function record_vote( $article_id, $vote, $comment, $hash, $user_id = 0 ) {
        $data = array(
            'article_id'   => (int) $article_id,
            'vote'         => (int) $vote,
            'comment'      => (string) $comment,
            'visitor_hash' => (string) $hash,
            'user_id'      => (int) $user_id,
            'status'       => self::STATUS_NEW,
            'created_at'   => self::now(),
        );

        $inserted = Licensio_KB_DB::table( self::TABLE )->insert(
            $data,
            array( '%d', '%d', '%s', '%s', '%d', '%s', '%s' )
        );

        if ( ! $inserted ) {
            return false;
        }

        /**
         * Fires after a vote has been written.
         *
         * The comment is deliberately not passed: it is reader-supplied text
         * and hook arguments are routinely serialised into debug logs.
         *
         * @since 1.0.0
         *
         * @param int $article_id The article voted on.
         * @param int $vote       The vote value.
         */
        do_action( 'licensio_feedback_recorded', (int) $article_id, (int) $vote );

        return true;
    }

    /**
     * The raw helpful and unhelpful counts for an article.
     *
     * Counted from the rows every time it is called; nothing here reads the
     * cached meta, which is what lets a test assert that the cache agrees with
     * a recomputation.
     *
     * @since 1.0.0
     *
     * @param int $article_id The article.
     *
     * @return array{up: int, down: int} The vote counts.
     */
    public function count_votes( $article_id ) {
        $article_id = (int) $article_id;

        return array(
            'up'   => $this->count_votes_of( $article_id, self::VOTE_UP ),
            'down' => $this->count_votes_of( $article_id, self::VOTE_DOWN ),
        );
    }

    /**
     * Count the rows for one article carrying one vote value.
     *
     * A fresh builder per call: `count()` appends to the builder's select list,
     * so reusing one would compound the aggregates.
     *
     * @since 1.0.0
     *
     * @param int $article_id The article.
     * @param int $vote       The vote value to count.
     *
     * @return int The number of matching rows.
     */
    private function count_votes_of( $article_id, $vote ) {
        return (int) Licensio_KB_DB::table( self::TABLE )
            ->where( 'article_id', (int) $article_id )
            ->where( 'vote', (int) $vote )
            ->count();
    }

    /**
     * Recount an article's votes and cache the derived score.
     *
     * Called on every write, so the number the list table and the article
     * footer show is never stale. The cache lives in post meta, which means
     * reading it costs nothing on a list table that has already primed the
     * meta cache for the whole page.
     *
     * @since 1.0.0
     *
     * @param int $article_id The article to recompute.
     *
     * @return array{score: int|null, up: int, down: int} The recomputed figures.
     */
    public function recompute_score( $article_id ) {
        $article_id = (int) $article_id;
        $counts     = $this->count_votes( $article_id );
        $score      = self::calculate_score( $counts['up'], $counts['down'] );

        update_post_meta( $article_id, self::META_UP, $counts['up'] );
        update_post_meta( $article_id, self::META_DOWN, $counts['down'] );

        if ( null === $score ) {
            delete_post_meta( $article_id, self::META_SCORE );
        } else {
            update_post_meta( $article_id, self::META_SCORE, $score );
        }

        $result = array(
            'score' => $score,
            'up'    => $counts['up'],
            'down'  => $counts['down'],
        );

        /**
         * Fires after an article's usefulness score has been recomputed.
         *
         * @since 1.0.0
         *
         * @param int                                     $article_id The article.
         * @param array{score: int|null, up: int, down: int} $result   The recomputed figures.
         */
        do_action( 'licensio_feedback_score_updated', $article_id, $result );

        return $result;
    }

    /**
     * The cached usefulness figures for an article.
     *
     * Reads the meta cache only. An article that has never been voted on has
     * no meta and reports a null score, which every consumer renders as "no
     * data" rather than as zero per cent.
     *
     * @since 1.0.0
     *
     * @param int $article_id The article.
     *
     * @return array{score: int|null, up: int, down: int} The cached figures.
     */
    public static function get_score( $article_id ) {
        $article_id = (int) $article_id;
        $up         = (int) get_post_meta( $article_id, self::META_UP, true );
        $down       = (int) get_post_meta( $article_id, self::META_DOWN, true );
        $stored     = get_post_meta( $article_id, self::META_SCORE, true );
        $score      = ( '' === $stored || null === $stored ) ? null : (int) $stored;

        return array(
            'score' => $score,
            'up'    => max( 0, $up ),
            'down'  => max( 0, $down ),
        );
    }

    /**
     * A page of feedback rows for the inbox.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $args Query arguments: `vote`, `status`, `article_id`, `page`, `per_page`, `order`.
     *
     * @return array<int, object> The matching rows, newest first by default.
     */
    public function get_rows( $args = array() ) {
        $args    = $this->normalize_query_args( $args );
        $query   = $this->build_query( $args );
        $offset  = ( $args['page'] - 1 ) * $args['per_page'];
        $results = $query
            ->orderBy( 'created_at', $args['order'] )
            ->orderBy( 'id', $args['order'] )
            ->limit( $args['per_page'] )
            ->offset( $offset )
            ->getAll();

        return is_array( $results ) ? $results : array();
    }

    /**
     * How many rows match a set of inbox filters.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $args The same arguments accepted by get_rows().
     *
     * @return int The number of matching rows.
     */
    public function count_rows( $args = array() ) {
        return (int) $this->build_query( $this->normalize_query_args( $args ) )->count();
    }

    /**
     * How many rows sit in each triage status.
     *
     * The inbox offers a status filter, and a filter that cannot say how many
     * rows it would leave is a guess: a triager wants to know there are four
     * new entries before deciding to open the New view. Counted in one grouped
     * query rather than one query per status, and the `status` argument is
     * ignored on purpose - the counts describe every status under the *other*
     * filters, which is what makes them useful next to the filter itself.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $args The inbox query arguments.
     *
     * @return array<string, int> Status name to row count, plus `all`. Every known status is present, zero included.
     */
    public function counts_by_status( $args = array() ) {
        $args           = $this->normalize_query_args( $args );
        $args['status'] = '';
        $counts         = array( 'all' => 0 );

        foreach ( self::statuses() as $status ) {
            $counts[ $status ] = 0;
        }

        $rows = $this->build_query( $args )
            ->select( 'status' )
            ->selectRaw( 'COUNT(*) AS total' )
            ->groupBy( 'status' )
            ->getAll();

        foreach ( (array) $rows as $row ) {
            if ( ! is_object( $row ) || ! isset( $row->status, $row->total ) ) {
                continue;
            }

            $status = (string) $row->status;
            $total  = (int) $row->total;

            $counts['all'] += $total;

            if ( array_key_exists( $status, $counts ) ) {
                $counts[ $status ] = $total;
            }
        }

        return $counts;
    }

    /**
     * One feedback row by ID.
     *
     * @since 1.0.0
     *
     * @param int $id The row ID.
     *
     * @return object|null The row, or null when it does not exist.
     */
    public function get_row( $id ) {
        $id = (int) $id;

        if ( $id <= 0 ) {
            return null;
        }

        $row = Licensio_KB_DB::table( self::TABLE )->where( 'id', $id )->limit( 1 )->get();

        return is_object( $row ) ? $row : null;
    }

    /**
     * Move a feedback row to a new triage status.
     *
     * @since 1.0.0
     *
     * @param int    $id     The row ID.
     * @param string $status One of self::statuses().
     *
     * @return bool True when the row was updated.
     */
    public function update_status( $id, $status ) {
        $id = (int) $id;

        if ( $id <= 0 || ! self::is_valid_status( $status ) ) {
            return false;
        }

        $updated = Licensio_KB_DB::table( self::TABLE )
            ->where( 'id', $id )
            ->update( array( 'status' => (string) $status ), array( '%s' ) );

        return false !== $updated;
    }

    /**
     * Apply the inbox filters to a query builder.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $args Normalised query arguments.
     *
     * @return object The query builder with the filters applied.
     */
    private function build_query( array $args ) {
        $query = Licensio_KB_DB::table( self::TABLE );

        if ( 0 !== $args['vote'] ) {
            $query->where( 'vote', $args['vote'] );
        }

        if ( '' !== $args['status'] ) {
            $query->where( 'status', $args['status'] );
        }

        if ( $args['article_id'] > 0 ) {
            $query->where( 'article_id', $args['article_id'] );
        }

        return $query;
    }

    /**
     * Bound and default the inbox query arguments.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $args The raw arguments.
     *
     * @return array{vote: int, status: string, article_id: int, page: int, per_page: int, order: string} The normalised arguments.
     */
    private function normalize_query_args( $args ) {
        $args = is_array( $args ) ? $args : array();

        $vote   = isset( $args['vote'] ) ? self::normalize_vote( $args['vote'] ) : 0;
        $status = isset( $args['status'] ) && self::is_valid_status( $args['status'] ) ? (string) $args['status'] : '';
        $order  = isset( $args['order'] ) && 'ASC' === strtoupper( (string) $args['order'] ) ? 'ASC' : 'DESC';

        return array(
            'vote'       => $vote,
            'status'     => $status,
            'article_id' => isset( $args['article_id'] ) ? max( 0, (int) $args['article_id'] ) : 0,
            'page'       => isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1,
            'per_page'   => isset( $args['per_page'] )
                ? min( self::MAX_PER_PAGE, max( 1, (int) $args['per_page'] ) )
                : self::DEFAULT_PER_PAGE,
            'order'      => $order,
        );
    }

    /**
     * The current UTC time in MySQL datetime format.
     *
     * @since 1.0.0
     *
     * @return string The timestamp, e.g. `2026-01-31 09:15:00`.
     */
    private static function now() {
        return (string) current_time( 'mysql', true );
    }
}
