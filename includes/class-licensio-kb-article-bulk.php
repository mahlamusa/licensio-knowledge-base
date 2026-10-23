<?php
/**
 * Bulk changes to articles, and their undo.
 *
 * The Articles app sends bulk actions in batches of at most 100 IDs. Each ID
 * is checked on its own: an article that does not exist, that the user may
 * not change, that another user is editing, or that is in the wrong place for
 * the action (restoring an article that is not in the trash) is skipped with
 * a reason. Nothing is dropped silently.
 *
 * Every write goes through core (`wp_trash_post()`, `wp_untrash_post()`,
 * `wp_delete_post()`, `wp_update_post()`, `wp_set_object_terms()`), so the
 * hooks that keep the search index and caches in step still fire.
 *
 * Trash, restore, status and term changes return a snapshot of what each
 * article was before, which `revert()` puts back. The snapshot comes with a
 * token: a keyed hash of the snapshot itself, the time it was taken and the
 * user who took it. Revert accepts only those fields, only with a token that
 * matches that exact snapshot for this user, only in the last 15 minutes, only
 * terms that exist in their taxonomy, and checks every article's permission
 * and lock again. Author and comment
 * changes are deliberately not undoable: their earlier values are rarely
 * wanted and would widen what revert has to trust.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Runs bulk article actions and reverts them.
 *
 * @since 1.0.0
 */
class Licensio_KB_Article_Bulk {

    /**
     * The most IDs one request may carry.
     *
     * @since 1.0.0
     * @var int
     */
    const MAX_IDS = 100;

    /**
     * How long a snapshot can be reverted, in seconds.
     *
     * @since 1.0.0
     * @var int
     */
    const SNAPSHOT_TTL = 900;

    /**
     * The bulk actions.
     *
     * @since 1.0.0
     * @var string[]
     */
    const ACTIONS = array( 'trash', 'restore', 'delete', 'status', 'terms', 'author', 'comments' );

    /**
     * The actions whose earlier values are returned for undo.
     *
     * @since 1.0.0
     * @var string[]
     */
    const UNDOABLE = array( 'trash', 'restore', 'status', 'terms' );

    /**
     * The statuses the status action may set.
     *
     * @since 1.0.0
     * @var string[]
     */
    const BULK_STATUSES = array( 'publish', 'draft', 'pending', 'private' );

    /**
     * The statuses a snapshot may hold.
     *
     * @since 1.0.0
     * @var string[]
     */
    const SNAPSHOT_STATUSES = array( 'publish', 'future', 'draft', 'pending', 'private', 'trash' );

    /**
     * The fields a snapshot may hold; anything else is refused by revert.
     *
     * @since 1.0.0
     * @var string[]
     */
    const REVERTIBLE_FIELDS = array( 'status', 'terms' );

    /**
     * The term modes.
     *
     * @since 1.0.0
     * @var string[]
     */
    const TERM_MODES = array( 'add', 'remove', 'replace' );

    /**
     * The taxonomies an article's terms may be changed in.
     *
     * @since 1.0.0
     *
     * @return string[] The taxonomy names.
     */
    public static function taxonomies() {
        return array( Licensio_KB_Post_Types::TAXONOMY_CATEGORY, Licensio_KB_Post_Types::TAXONOMY_TAG );
    }

    /**
     * Run one bulk action over a batch of articles.
     *
     * The caller has validated the action and its arguments (see the REST
     * controller's schema); this checks and changes each article.
     *
     * @since 1.0.0
     *
     * @param int[]                $ids    The article IDs, at most `MAX_IDS`.
     * @param string               $action One of `ACTIONS`.
     * @param array<string, mixed> $args   The action's arguments.
     *
     * @return array{done: int[], skipped: array<int, array{id: int, reason: string}>, failed: array<int, array{id: int, message: string}>, previous: array<int, array<string, mixed>>, snapshot_at: int, token: string} What happened to each ID.
     */
    public function run( array $ids, $action, array $args ) {
        $snapshot_at = time();
        $result      = array(
            'done'        => array(),
            'skipped'     => array(),
            'failed'      => array(),
            'previous'    => array(),
            'snapshot_at' => $snapshot_at,
            'token'       => '',
        );
        $ids         = self::clean_ids( $ids );

        // Load the posts and their meta (the edit lock) in two queries, not two per article.
        _prime_post_caches( $ids, false, true );
        // Recount each changed term once at the end, not once per article.
        wp_defer_term_counting( true );

        foreach ( $ids as $id ) {
            $post   = get_post( $id );
            $reason = $this->skip_reason( $post, $action, $args );

            if ( '' !== $reason ) {
                $result['skipped'][] = array(
                    'id'     => $id,
                    'reason' => $reason,
                );
                continue;
            }

            $snapshot = in_array( $action, self::UNDOABLE, true ) ? $this->snapshot( $post, $action, $args ) : array();
            $applied  = $this->apply( $id, $action, $args );

            if ( is_wp_error( $applied ) ) {
                $result['failed'][] = array(
                    'id'      => $id,
                    'message' => $applied->get_error_message(),
                );
                continue;
            }

            $result['done'][] = $id;

            if ( ! empty( $snapshot ) ) {
                $result['previous'][ $id ] = $snapshot;
            }
        }

        wp_defer_term_counting( false );

        // Minted last, so the token covers the snapshot that goes back.
        $result['token'] = self::snapshot_token( $snapshot_at, $result['previous'] );

        return $result;
    }

    /**
     * Why an article must be skipped by an action, if it must.
     *
     * Checked in this order: the article exists, the user may change it, no
     * one else is editing it, and it is in the right place for the action.
     *
     * @since 1.0.0
     *
     * @param WP_Post|mixed        $post   The article, or whatever get_post() returned.
     * @param string               $action The action.
     * @param array<string, mixed> $args   The action's arguments.
     *
     * @return string The reason (`not_found`, `forbidden`, `locked`, `not_in_trash`, `in_trash`), or an empty string to go ahead.
     */
    public function skip_reason( $post, $action, array $args ) {
        if ( ! $post instanceof WP_Post || Licensio_KB_Post_Types::POST_TYPE !== $post->post_type ) {
            return 'not_found';
        }

        $id = (int) $post->ID;

        if ( ! $this->can( $id, $action, $args ) ) {
            return 'forbidden';
        }

        if ( self::lock_holder( $id ) > 0 ) {
            return 'locked';
        }

        $in_trash = 'trash' === $post->post_status;

        if ( in_array( $action, array( 'restore', 'delete' ), true ) && ! $in_trash ) {
            return 'not_in_trash';
        }

        if ( in_array( $action, array( 'trash', 'status' ), true ) && $in_trash ) {
            return 'in_trash';
        }

        return '';
    }

    /**
     * Whether the current user may apply an action to one article.
     *
     * @since 1.0.0
     *
     * @param int                  $id     The article ID.
     * @param string               $action The action.
     * @param array<string, mixed> $args   The action's arguments.
     *
     * @return bool True when allowed.
     */
    public function can( $id, $action, array $args ) {
        if ( in_array( $action, array( 'trash', 'restore', 'delete' ), true ) ) {
            return current_user_can( 'delete_post', $id );
        }

        if ( ! current_user_can( 'edit_post', $id ) ) {
            return false;
        }

        if ( 'status' === $action && isset( $args['status'] ) && in_array( $args['status'], array( 'publish', 'private' ), true ) ) {
            return current_user_can( self::type_cap( 'publish_posts' ) );
        }

        if ( 'author' === $action ) {
            return current_user_can( self::type_cap( 'edit_others_posts' ) );
        }

        if ( 'terms' === $action ) {
            return current_user_can( self::taxonomy_cap( isset( $args['taxonomy'] ) ? (string) $args['taxonomy'] : '', 'assign_terms' ) );
        }

        return true;
    }

    /**
     * What an article is now, in the fields an action is about to change.
     *
     * @since 1.0.0
     *
     * @param WP_Post              $post   The article.
     * @param string               $action The action.
     * @param array<string, mixed> $args   The action's arguments.
     *
     * @return array<string, mixed> The snapshot.
     */
    private function snapshot( $post, $action, array $args ) {
        if ( 'terms' === $action ) {
            $taxonomy = (string) $args['taxonomy'];
            $term_ids = wp_get_object_terms( (int) $post->ID, $taxonomy, array( 'fields' => 'ids' ) );

            return array(
                'terms' => array(
                    $taxonomy => is_array( $term_ids ) ? array_map( 'intval', $term_ids ) : array(),
                ),
            );
        }

        return array( 'status' => (string) $post->post_status );
    }

    /**
     * Apply an action to one article.
     *
     * @since 1.0.0
     *
     * @param int                  $id     The article ID.
     * @param string               $action The action.
     * @param array<string, mixed> $args   The action's arguments.
     *
     * @return true|WP_Error True on success.
     */
    private function apply( $id, $action, array $args ) {
        switch ( $action ) {
            case 'trash':
                return self::outcome( wp_trash_post( $id ) );

            case 'restore':
                return self::outcome( wp_untrash_post( $id ) );

            case 'delete':
                return self::outcome( wp_delete_post( $id, true ) );

            case 'status':
                return self::update( $id, array( 'post_status' => (string) $args['status'] ) );

            case 'author':
                return self::update( $id, array( 'post_author' => (int) $args['author'] ) );

            case 'comments':
                return self::update( $id, array( 'comment_status' => (string) $args['comment_status'] ) );

            case 'terms':
                return $this->apply_terms( $id, (string) $args['taxonomy'], (string) $args['mode'], (array) $args['term_ids'] );
        }

        return new WP_Error( 'licensio_kb_unknown_action', __( 'Unknown bulk action.', 'licensio-knowledge-base' ) );
    }

    /**
     * Add, remove or replace one article's terms in one taxonomy.
     *
     * @since 1.0.0
     *
     * @param int    $id       The article ID.
     * @param string $taxonomy The taxonomy.
     * @param string $mode     `add`, `remove` or `replace`.
     * @param int[]  $term_ids The term IDs.
     *
     * @return true|WP_Error True on success.
     */
    private function apply_terms( $id, $taxonomy, $mode, array $term_ids ) {
        // Integers, never numeric strings: core treats a string as a term name.
        $term_ids = array_map( 'intval', $term_ids );

        if ( 'remove' === $mode ) {
            $removed = wp_remove_object_terms( $id, $term_ids, $taxonomy );

            return is_wp_error( $removed ) ? $removed : true;
        }

        $set = wp_set_object_terms( $id, $term_ids, $taxonomy, 'add' === $mode );

        return is_wp_error( $set ) ? $set : true;
    }

    /**
     * Put back the fields a bulk action changed.
     *
     * @since 1.0.0
     *
     * @param array<int|string, mixed> $previous    Article ID to snapshot, from a bulk answer.
     * @param int                      $snapshot_at When the snapshot was taken, from the same answer.
     * @param string                   $token       The snapshot token, from the same answer.
     *
     * @return array{done: int[], skipped: array<int, array{id: int, reason: string}>, failed: array<int, array{id: int, message: string}>}|WP_Error What happened to each ID, or an error when the snapshot is refused.
     */
    public function revert( array $previous, $snapshot_at, $token ) {
        $valid = self::validate_snapshot( $previous, (int) $snapshot_at, (string) $token );

        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        $result = array(
            'done'    => array(),
            'skipped' => array(),
            'failed'  => array(),
        );

        _prime_post_caches( array_map( 'intval', array_keys( $previous ) ), false, true );
        wp_defer_term_counting( true );

        foreach ( $previous as $id => $snapshot ) {
            $id     = (int) $id;
            $post   = get_post( $id );
            $reason = $this->revert_skip_reason( $post, (array) $snapshot );

            if ( '' !== $reason ) {
                $result['skipped'][] = array(
                    'id'     => $id,
                    'reason' => $reason,
                );
                continue;
            }

            $applied = $this->apply_snapshot( $post, (array) $snapshot );

            if ( is_wp_error( $applied ) ) {
                $result['failed'][] = array(
                    'id'      => $id,
                    'message' => $applied->get_error_message(),
                );
                continue;
            }

            $result['done'][] = $id;
        }

        wp_defer_term_counting( false );

        return $result;
    }

    /**
     * Check a snapshot before anything is reverted.
     *
     * The snapshot must hold at most `MAX_IDS` articles and only the
     * revertible fields with well-formed values. Its token must match this
     * exact snapshot, its time and the current user, so a changed snapshot or
     * one taken by someone else is refused. It must be no more than 15
     * minutes old, and every term in it must exist in its taxonomy.
     *
     * @since 1.0.0
     *
     * @param array<int|string, mixed> $previous    Article ID to snapshot.
     * @param int                      $snapshot_at When the snapshot was taken.
     * @param string                   $token       The snapshot token.
     *
     * @return true|WP_Error True when the snapshot may be reverted.
     */
    public static function validate_snapshot( array $previous, $snapshot_at, $token ) {
        if ( empty( $previous ) || count( $previous ) > self::MAX_IDS ) {
            return self::invalid( 'previous', __( 'Send between 1 and 100 articles to undo.', 'licensio-knowledge-base' ) );
        }

        foreach ( $previous as $id => $snapshot ) {
            if ( (int) $id < 1 || ! is_array( $snapshot ) || empty( $snapshot ) ) {
                return self::invalid( 'previous', __( 'Each article to undo needs its earlier values.', 'licensio-knowledge-base' ) );
            }

            $unknown = array_diff( array_keys( $snapshot ), self::REVERTIBLE_FIELDS );

            if ( ! empty( $unknown ) ) {
                return self::invalid(
                    'previous',
                    /* translators: %s: the field names that cannot be undone. */
                    sprintf( __( 'These fields cannot be undone: %s.', 'licensio-knowledge-base' ), implode( ', ', array_map( 'strval', $unknown ) ) )
                );
            }

            if ( isset( $snapshot['status'] ) && ! in_array( $snapshot['status'], self::SNAPSHOT_STATUSES, true ) ) {
                return self::invalid( 'previous', __( 'A status to undo to is not valid.', 'licensio-knowledge-base' ) );
            }

            if ( isset( $snapshot['terms'] ) && ! self::valid_terms( $snapshot['terms'] ) ) {
                return self::invalid( 'previous', __( 'The terms to undo to are not valid.', 'licensio-knowledge-base' ) );
            }
        }

        if ( ! hash_equals( self::snapshot_token( (int) $snapshot_at, $previous ), (string) $token ) ) {
            return new WP_Error( 'licensio_kb_snapshot_invalid', __( 'This undo is not valid for your account.', 'licensio-knowledge-base' ), array( 'status' => 400 ) );
        }

        $age = time() - (int) $snapshot_at;

        if ( $age < -60 || $age > self::SNAPSHOT_TTL ) {
            return new WP_Error( 'licensio_kb_snapshot_expired', __( 'This change is too old to undo.', 'licensio-knowledge-base' ), array( 'status' => 400 ) );
        }

        foreach ( $previous as $snapshot ) {
            if ( isset( $snapshot['terms'] ) && ! self::terms_exist( $snapshot['terms'] ) ) {
                return self::invalid( 'previous', __( 'The terms to undo to are not valid.', 'licensio-knowledge-base' ) );
            }
        }

        return true;
    }

    /**
     * Whether every term in a well-formed snapshot exists in its taxonomy.
     *
     * @since 1.0.0
     *
     * @param array<string, array<int|string>> $terms Taxonomy to term IDs.
     *
     * @return bool True when every term belongs to the taxonomy it is listed under.
     */
    private static function terms_exist( array $terms ) {
        foreach ( $terms as $taxonomy => $term_ids ) {
            foreach ( $term_ids as $term_id ) {
                if ( ! term_exists( (int) $term_id, (string) $taxonomy ) ) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Whether a snapshot's terms are a map of known taxonomy to term IDs.
     *
     * @since 1.0.0
     *
     * @param mixed $terms The snapshot's terms.
     *
     * @return bool True when well formed.
     */
    private static function valid_terms( $terms ) {
        if ( ! is_array( $terms ) || empty( $terms ) ) {
            return false;
        }

        foreach ( $terms as $taxonomy => $term_ids ) {
            if ( ! in_array( $taxonomy, self::taxonomies(), true ) || ! is_array( $term_ids ) ) {
                return false;
            }

            foreach ( $term_ids as $term_id ) {
                if ( ! is_int( $term_id ) && ! ( is_string( $term_id ) && ctype_digit( $term_id ) ) ) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Why an article must be skipped by a revert, if it must.
     *
     * @since 1.0.0
     *
     * @param WP_Post|mixed        $post     The article, or whatever get_post() returned.
     * @param array<string, mixed> $snapshot The article's snapshot.
     *
     * @return string The reason, or an empty string to go ahead.
     */
    public function revert_skip_reason( $post, array $snapshot ) {
        if ( ! $post instanceof WP_Post || Licensio_KB_Post_Types::POST_TYPE !== $post->post_type ) {
            return 'not_found';
        }

        $id = (int) $post->ID;

        if ( isset( $snapshot['status'] ) && ! $this->can_revert_status( $post, (string) $snapshot['status'] ) ) {
            return 'forbidden';
        }

        if ( isset( $snapshot['terms'] ) ) {
            if ( ! current_user_can( 'edit_post', $id ) ) {
                return 'forbidden';
            }

            foreach ( array_keys( (array) $snapshot['terms'] ) as $taxonomy ) {
                if ( ! current_user_can( self::taxonomy_cap( (string) $taxonomy, 'assign_terms' ) ) ) {
                    return 'forbidden';
                }
            }
        }

        return self::lock_holder( $id ) > 0 ? 'locked' : '';
    }

    /**
     * Whether the current user may move an article back to a status.
     *
     * Leaving or entering the trash needs `delete_post`; any other change
     * needs `edit_post`, and `publish_posts` for a published, scheduled or
     * private target.
     *
     * @since 1.0.0
     *
     * @param WP_Post $post   The article.
     * @param string  $target The status to move it back to.
     *
     * @return bool True when allowed.
     */
    private function can_revert_status( $post, $target ) {
        $id = (int) $post->ID;

        if ( ( 'trash' === $target || 'trash' === $post->post_status ) && ! current_user_can( 'delete_post', $id ) ) {
            return false;
        }

        if ( 'trash' !== $target && ! current_user_can( 'edit_post', $id ) ) {
            return false;
        }

        if ( in_array( $target, array( 'publish', 'future', 'private' ), true ) && ! current_user_can( self::type_cap( 'publish_posts' ) ) ) {
            return false;
        }

        return true;
    }

    /**
     * Put one article's snapshot back.
     *
     * @since 1.0.0
     *
     * @param WP_Post              $post     The article.
     * @param array<string, mixed> $snapshot The snapshot.
     *
     * @return true|WP_Error True on success.
     */
    private function apply_snapshot( $post, array $snapshot ) {
        $id = (int) $post->ID;

        if ( isset( $snapshot['status'] ) ) {
            $restored = self::restore_status( $post, (string) $snapshot['status'] );

            if ( is_wp_error( $restored ) ) {
                return $restored;
            }
        }

        if ( isset( $snapshot['terms'] ) ) {
            foreach ( (array) $snapshot['terms'] as $taxonomy => $term_ids ) {
                $set = wp_set_object_terms( $id, array_map( 'intval', (array) $term_ids ), (string) $taxonomy, false );

                if ( is_wp_error( $set ) ) {
                    return $set;
                }
            }
        }

        return true;
    }

    /**
     * Move an article back to a status, through the trash functions when the
     * trash is involved.
     *
     * Core restores a trashed article as a draft. Here the status it is going
     * back to is known, so `wp_untrash_post_status` is filtered to it for the
     * one call.
     *
     * @since 1.0.0
     *
     * @param WP_Post $post   The article.
     * @param string  $target The status to move it back to.
     *
     * @return true|WP_Error True on success.
     */
    private static function restore_status( $post, $target ) {
        $id      = (int) $post->ID;
        $current = (string) $post->post_status;

        if ( $current === $target ) {
            return true;
        }

        if ( 'trash' === $target ) {
            return self::outcome( wp_trash_post( $id ) );
        }

        if ( 'trash' === $current ) {
            $to_target = static function () use ( $target ) {
                return $target;
            };

            add_filter( 'wp_untrash_post_status', $to_target, 99 );
            $untrashed = wp_untrash_post( $id );
            remove_filter( 'wp_untrash_post_status', $to_target, 99 );

            return self::outcome( $untrashed );
        }

        return self::update( $id, array( 'post_status' => $target ) );
    }

    /**
     * Update an article through wp_update_post().
     *
     * @since 1.0.0
     *
     * @param int                  $id     The article ID.
     * @param array<string, mixed> $fields The post fields to change.
     *
     * @return true|WP_Error True on success.
     */
    private static function update( $id, array $fields ) {
        // wp_update_post() expects slashed data, as core's own callers send it.
        $updated = wp_update_post( wp_slash( array_merge( array( 'ID' => (int) $id ), $fields ) ), true );

        return is_wp_error( $updated ) ? $updated : self::outcome( $updated );
    }

    /**
     * Turn a core write function's return value into true or an error.
     *
     * @since 1.0.0
     *
     * @param mixed $outcome What the core function returned.
     *
     * @return true|WP_Error True when it reports success.
     */
    private static function outcome( $outcome ) {
        if ( is_wp_error( $outcome ) ) {
            return $outcome;
        }

        if ( empty( $outcome ) ) {
            return new WP_Error( 'licensio_kb_write_failed', __( 'The article could not be changed.', 'licensio-knowledge-base' ) );
        }

        return true;
    }

    /**
     * Who else is editing an article, if anyone.
     *
     * @since 1.0.0
     *
     * @param int $id The article ID.
     *
     * @return int The ID of the other user holding the edit lock, or 0.
     */
    public static function lock_holder( $id ) {
        self::load_post_functions();

        return (int) wp_check_post_lock( (int) $id );
    }

    /**
     * Load core's admin post functions, which REST requests do not load.
     *
     * `wp_check_post_lock()` lives there.
     *
     * @since 1.0.0
     *
     * @return void
     */
    private static function load_post_functions() {
        if ( ! function_exists( 'wp_check_post_lock' ) ) {
            require_once ABSPATH . 'wp-admin/includes/post.php';
        }
    }

    /**
     * The article post type's name for a primitive capability.
     *
     * @since 1.0.0
     *
     * @param string $cap The generic capability, e.g. `publish_posts`.
     *
     * @return string The post type's capability.
     */
    public static function type_cap( $cap ) {
        $type = get_post_type_object( Licensio_KB_Post_Types::POST_TYPE );

        return is_object( $type ) && isset( $type->cap->$cap ) ? (string) $type->cap->$cap : (string) $cap;
    }

    /**
     * A taxonomy's name for a term capability.
     *
     * @since 1.0.0
     *
     * @param string $taxonomy The taxonomy.
     * @param string $cap      The generic capability, e.g. `assign_terms`.
     *
     * @return string The taxonomy's capability, or `do_not_allow` for an unknown taxonomy.
     */
    public static function taxonomy_cap( $taxonomy, $cap ) {
        if ( ! in_array( $taxonomy, self::taxonomies(), true ) ) {
            return 'do_not_allow';
        }

        $object = get_taxonomy( $taxonomy );

        return is_object( $object ) && isset( $object->cap->$cap ) ? (string) $object->cap->$cap : 'do_not_allow';
    }

    /**
     * The token that ties a snapshot to its content, its time and the current user.
     *
     * A keyed hash (`wp_hash()` with the nonce salt) over the time, the user
     * ID and a hash of the snapshot in canonical form, so the same snapshot
     * gives the same token however its keys and term IDs arrive.
     *
     * @since 1.0.0
     *
     * @param int                      $snapshot_at When the snapshot was taken, as a Unix time.
     * @param array<int|string, mixed> $previous    Article ID to its snapshot.
     *
     * @return string The token.
     */
    public static function snapshot_token( $snapshot_at, array $previous ) {
        $content = hash( 'sha256', (string) wp_json_encode( self::canonical_snapshot( $previous ) ) );

        return wp_hash( 'licensio_kb_revert|' . (int) $snapshot_at . '|' . get_current_user_id() . '|' . $content, 'nonce' );
    }

    /**
     * A snapshot in canonical form: sorted by article ID, field and taxonomy,
     * with term IDs as sorted integers.
     *
     * @since 1.0.0
     *
     * @param array<int|string, mixed> $previous Article ID to its snapshot.
     *
     * @return array<int, array<int, mixed>> The canonical snapshot, as lists so key order cannot matter.
     */
    private static function canonical_snapshot( array $previous ) {
        $canonical = array();

        foreach ( $previous as $id => $snapshot ) {
            $fields = array();

            foreach ( (array) $snapshot as $field => $value ) {
                if ( 'terms' === $field ) {
                    $terms = array();

                    foreach ( (array) $value as $taxonomy => $term_ids ) {
                        $term_ids = array_map( 'intval', (array) $term_ids );
                        sort( $term_ids );
                        $terms[] = array( (string) $taxonomy, $term_ids );
                    }

                    sort( $terms );
                    $value = $terms;
                }

                $fields[] = array( (string) $field, $value );
            }

            sort( $fields );
            $canonical[] = array( (int) $id, $fields );
        }

        sort( $canonical );

        return $canonical;
    }

    /**
     * A field validation error in the shape core's REST argument errors use.
     *
     * @since 1.0.0
     *
     * @param string $param   The field.
     * @param string $message What is wrong with it.
     *
     * @return WP_Error A 400 error naming the field.
     */
    public static function invalid( $param, $message ) {
        return new WP_Error(
            'rest_invalid_param',
            $message,
            array(
                'status' => 400,
                'params' => array( $param => $message ),
            )
        );
    }

    /**
     * Positive, unique integer IDs, in their first order.
     *
     * @since 1.0.0
     *
     * @param array<mixed> $ids The IDs.
     *
     * @return int[] The cleaned IDs.
     */
    public static function clean_ids( array $ids ) {
        $clean = array();

        foreach ( $ids as $id ) {
            $id = (int) $id;

            if ( $id > 0 ) {
                $clean[ $id ] = $id;
            }
        }

        return array_values( $clean );
    }
}
