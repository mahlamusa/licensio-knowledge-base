<?php
/**
 * Search index maintenance.
 *
 * Keeps `licensio_search_index` in step with the articles it describes. One row
 * per published article, rewritten whenever the article, its status or its
 * terms change, and removed when the article stops being publicly readable.
 *
 * The index deliberately holds only what a visitor is allowed to find. A
 * drafted, trashed, private or password-protected article has no row, so the
 * post-status filtering the specification asks of both drivers is enforced by
 * the shape of the index rather than by a condition each driver has to remember
 * to apply.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds and maintains the knowledge base search index.
 *
 * @since 1.0.0
 */
class Licensio_KB_Search_Index {

    /**
     * The table this class owns the contents of.
     *
     * @since 1.0.0
     * @var string
     */
    const TABLE = 'licensio_search_index';

    /**
     * The cron hook that advances a chunked rebuild.
     *
     * @since 1.0.0
     * @var string
     */
    const CRON_HOOK = 'licensio_search_reindex';

    /**
     * Option holding the position of an in-progress rebuild.
     *
     * @since 1.0.0
     * @var string
     */
    const STATE_OPTION = 'licensio_search_reindex_state';

    /**
     * Option recording the plugin version the index was last built for.
     *
     * @since 1.0.0
     * @var string
     */
    const BUILT_OPTION = 'licensio_search_index_built';

    /**
     * How many articles one rebuild chunk processes.
     *
     * @since 1.0.0
     * @var int
     */
    const CHUNK_SIZE = 50;

    /**
     * How many articles a term edit reindexes inline before it defers to a
     * full rebuild instead.
     *
     * @since 1.0.0
     * @var int
     */
    const TERM_FANOUT_LIMIT = 200;

    /**
     * A digest of the last row written for each article in this request.
     *
     * WordPress fires `transition_post_status`, `save_post` and — on the REST
     * path — `set_object_terms` for a single editor save, and each of them is a
     * legitimate reason to reindex. Comparing the row about to be written with
     * the one already written keeps that to a single write per save while still
     * letting a later hook correct the row when it genuinely changed.
     *
     * @since 1.0.0
     * @var array<int, string>
     */
    private $written = array();

    /**
     * Register the WordPress hooks.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function init_hooks() {
        add_action( 'save_post_' . Licensio_KB_Post_Types::POST_TYPE, array( $this, 'handle_save_post' ), 20, 2 );
        add_action( 'transition_post_status', array( $this, 'handle_transition_post_status' ), 20, 3 );
        add_action( 'deleted_post', array( $this, 'handle_deleted_post' ), 20 );
        add_action( 'set_object_terms', array( $this, 'handle_set_object_terms' ), 20, 4 );
        add_action( 'edited_term', array( $this, 'handle_edited_term' ), 20, 3 );
        add_action( 'delete_term', array( $this, 'handle_delete_term' ), 20, 5 );

        add_action( self::CRON_HOOK, array( $this, 'handle_rebuild_cron' ) );
        add_action( 'admin_init', array( $this, 'handle_admin_init' ) );
        add_action( 'licensio_deactivated', array( $this, 'unschedule_rebuild' ) );
    }

    /**
     * Advance a rebuild from cron.
     *
     * A thin void wrapper: run_rebuild_chunk() returns the chunk size so WP-CLI
     * and the tests can see progress, and an action callback must not return a
     * value.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function handle_rebuild_cron() {
        $this->run_rebuild_chunk();
    }

    /**
     * Start the first index build when an administrator loads wp-admin.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function handle_admin_init() {
        $this->maybe_start_initial_rebuild();
    }

    /**
     * Reindex an article after it is saved.
     *
     * @since 1.0.0
     *
     * @param int     $post_id The article ID.
     * @param WP_Post $post    The article.
     *
     * @return void
     */
    public function handle_save_post( $post_id, $post ) {
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
            return;
        }

        $this->sync( $post );
    }

    /**
     * Reindex an article whose status changed.
     *
     * Covers trashing, untrashing, publishing, unpublishing and scheduling in
     * one callback, because each of them is the same question to the index: is
     * this article publicly readable right now.
     *
     * @since 1.0.0
     *
     * @param string  $new_status The new post status.
     * @param string  $old_status The previous post status.
     * @param WP_Post $post       The post.
     *
     * @return void
     */
    public function handle_transition_post_status( $new_status, $old_status, $post ) {
        unset( $new_status, $old_status );

        if ( ! $post instanceof WP_Post || Licensio_KB_Post_Types::POST_TYPE !== $post->post_type ) {
            return;
        }

        $this->sync( $post );
    }

    /**
     * Remove an article from the index when it is deleted for good.
     *
     * @since 1.0.0
     *
     * @param int $post_id The deleted post ID.
     *
     * @return void
     */
    public function handle_deleted_post( $post_id ) {
        $this->delete( (int) $post_id );
    }

    /**
     * Reindex an article whose terms were changed.
     *
     * @since 1.0.0
     *
     * @param int    $object_id The article ID.
     * @param mixed  $terms     The terms that were set.
     * @param int[]  $tt_ids    The term taxonomy IDs that were set.
     * @param string $taxonomy  The taxonomy that changed.
     *
     * @return void
     */
    public function handle_set_object_terms( $object_id, $terms, $tt_ids, $taxonomy ) {
        unset( $terms, $tt_ids );

        if ( ! in_array( $taxonomy, self::indexed_taxonomies(), true ) ) {
            return;
        }

        $this->reindex( (int) $object_id );
    }

    /**
     * Reindex every article carrying a term that was edited.
     *
     * A renamed category changes the indexed `terms` text of every article in
     * it, so leaving those rows alone would make the old name keep matching and
     * the new one never match.
     *
     * @since 1.0.0
     *
     * @param int    $term_id  The edited term ID.
     * @param int    $tt_id    The term taxonomy ID.
     * @param string $taxonomy The taxonomy.
     *
     * @return void
     */
    public function handle_edited_term( $term_id, $tt_id, $taxonomy ) {
        unset( $tt_id );

        if ( ! in_array( $taxonomy, self::indexed_taxonomies(), true ) ) {
            return;
        }

        $object_ids = get_objects_in_term( (int) $term_id, $taxonomy );

        if ( is_wp_error( $object_ids ) ) {
            return;
        }

        $this->reindex_many( array_map( 'intval', (array) $object_ids ) );
    }

    /**
     * Reindex every article that carried a term which was deleted.
     *
     * The objects are supplied by the action, because by the time it fires the
     * relationship rows are already gone and the articles can no longer be
     * found from the term.
     *
     * @since 1.0.0
     *
     * @param int    $term         The deleted term ID.
     * @param int    $tt_id        The term taxonomy ID.
     * @param string $taxonomy     The taxonomy.
     * @param mixed  $deleted_term The term object that was deleted.
     * @param int[]  $object_ids   The objects that had the term.
     *
     * @return void
     */
    public function handle_delete_term( $term, $tt_id, $taxonomy, $deleted_term, $object_ids ) {
        unset( $term, $tt_id, $deleted_term );

        if ( ! in_array( $taxonomy, self::indexed_taxonomies(), true ) ) {
            return;
        }

        $this->reindex_many( array_map( 'intval', (array) $object_ids ) );
    }

    /**
     * Write, update or remove the index row for one article.
     *
     * @since 1.0.0
     *
     * @param WP_Post|int|null $post The article, or its ID.
     *
     * @return bool True when the index changed.
     */
    public function sync( $post ) {
        $post = get_post( $post );

        if ( ! $post instanceof WP_Post || Licensio_KB_Post_Types::POST_TYPE !== $post->post_type ) {
            return false;
        }

        if ( ! self::is_indexable( $post ) ) {
            return $this->delete( (int) $post->ID );
        }

        $row    = $this->build_row( $post );
        $digest = md5( (string) wp_json_encode( $row ) );

        if ( isset( $this->written[ $post->ID ] ) && $this->written[ $post->ID ] === $digest ) {
            return false;
        }

        $row['updated_at'] = self::now();

        Licensio_KB_DB::table( self::TABLE )->upsert( $row, array( 'article_id' ) );

        $this->written[ $post->ID ] = $digest;

        /**
         * Fires after an article's search index row has been written.
         *
         * @since 1.0.0
         *
         * @param int     $article_id The article ID.
         * @param WP_Post $post       The article.
         */
        do_action( 'licensio_search_index_updated', (int) $post->ID, $post );

        return true;
    }

    /**
     * Reindex an article by ID, ignoring the per-request write cache.
     *
     * @since 1.0.0
     *
     * @param int $post_id The article ID.
     *
     * @return bool True when the index changed.
     */
    public function reindex( $post_id ) {
        unset( $this->written[ (int) $post_id ] );

        return $this->sync( (int) $post_id );
    }

    /**
     * Reindex a list of articles, or schedule a rebuild when there are too many.
     *
     * @since 1.0.0
     *
     * @param int[] $post_ids The article IDs.
     *
     * @return int How many articles were reindexed inline. Zero when a rebuild was scheduled.
     */
    public function reindex_many( array $post_ids ) {
        $post_ids = array_values( array_unique( array_filter( array_map( 'intval', $post_ids ) ) ) );

        if ( empty( $post_ids ) ) {
            return 0;
        }

        if ( count( $post_ids ) > self::TERM_FANOUT_LIMIT ) {
            $this->start_rebuild();

            return 0;
        }

        $count = 0;

        foreach ( $post_ids as $post_id ) {
            if ( $this->reindex( $post_id ) ) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * Remove an article's row from the index.
     *
     * @since 1.0.0
     *
     * @param int $post_id The article ID.
     *
     * @return bool True when a row was removed.
     */
    public function delete( $post_id ) {
        $post_id = (int) $post_id;

        if ( $post_id <= 0 ) {
            return false;
        }

        unset( $this->written[ $post_id ] );

        $deleted = Licensio_KB_DB::table( self::TABLE )->where( 'article_id', $post_id )->delete();

        /**
         * Fires after an article has been removed from the search index.
         *
         * @since 1.0.0
         *
         * @param int $article_id The article ID.
         */
        do_action( 'licensio_search_index_deleted', $post_id );

        return (bool) $deleted;
    }

    /**
     * Empty the index.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function truncate() {
        $this->written = array();

        Licensio_KB_DB::query( 'DELETE FROM ' . Licensio_KB_DB::table_name( self::TABLE ) );
    }

    /**
     * How many articles are indexed.
     *
     * @since 1.0.0
     *
     * @return int The row count.
     */
    public function count() {
        return (int) Licensio_KB_DB::table( self::TABLE )->count();
    }

    /**
     * Begin a chunked rebuild and schedule its first chunk.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function start_rebuild() {
        update_option(
            self::STATE_OPTION,
            array(
                'offset'  => 0,
                'started' => time(),
            ),
            false
        );

        $this->schedule_next_chunk();
    }

    /**
     * Run one chunk of a rebuild and schedule the next.
     *
     * @since 1.0.0
     *
     * @return int How many articles this chunk indexed.
     */
    public function run_rebuild_chunk() {
        $state  = get_option( self::STATE_OPTION, array() );
        $state  = is_array( $state ) ? $state : array();
        $offset = isset( $state['offset'] ) ? (int) $state['offset'] : 0;

        $indexed = $this->rebuild_chunk( $offset, self::CHUNK_SIZE );

        if ( $indexed < self::CHUNK_SIZE ) {
            $this->finish_rebuild();

            return $indexed;
        }

        $state['offset'] = $offset + $indexed;

        update_option( self::STATE_OPTION, $state, false );

        $this->schedule_next_chunk();

        return $indexed;
    }

    /**
     * Index one page of articles.
     *
     * @since 1.0.0
     *
     * @param int $offset Where in the article list to start.
     * @param int $size   How many articles to index.
     *
     * @return int How many articles were read. Fewer than `$size` means the end was reached.
     */
    public function rebuild_chunk( $offset, $size ) {
        $posts = get_posts(
            array(
                'post_type'        => Licensio_KB_Post_Types::POST_TYPE,
                'post_status'      => 'any',
                'posts_per_page'   => max( 1, (int) $size ),
                'offset'           => max( 0, (int) $offset ),
                'orderby'          => 'ID',
                'order'            => 'ASC',
                'suppress_filters' => false,
                'no_found_rows'    => true,
            )
        );

        foreach ( $posts as $post ) {
            $this->reindex( (int) $post->ID );
        }

        return count( $posts );
    }

    /**
     * Rebuild the whole index in one go, chunk by chunk.
     *
     * Used by WP-CLI, where there is no request budget to respect. Cron uses
     * run_rebuild_chunk() instead so a single request never runs long.
     *
     * @since 1.0.0
     *
     * @param callable|null $progress Optional callback invoked with the running total after each chunk.
     *
     * @return int How many articles were processed.
     */
    public function rebuild_all( $progress = null ) {
        $offset = 0;

        do {
            $indexed = $this->rebuild_chunk( $offset, self::CHUNK_SIZE );
            $offset += $indexed;

            if ( is_callable( $progress ) ) {
                call_user_func( $progress, $offset );
            }
        } while ( $indexed >= self::CHUNK_SIZE );

        $this->finish_rebuild();

        return $offset;
    }

    /**
     * Build the index once per plugin version, without anyone asking.
     *
     * Activation cannot do this: the activation hook runs in a request where
     * `plugins_loaded` fired before the plugin was active, so nothing this class
     * registers has been hooked yet. Checking a version marker on `admin_init`
     * covers a first activation, an upgrade, and a multisite subsite that was
     * never activated directly.
     *
     * @since 1.0.0
     *
     * @return bool True when a rebuild was started.
     */
    public function maybe_start_initial_rebuild() {
        if ( (string) get_option( self::BUILT_OPTION, '' ) === (string) LICENSIO_KB_VERSION ) {
            return false;
        }

        update_option( self::BUILT_OPTION, LICENSIO_KB_VERSION, false );

        $this->start_rebuild();

        return true;
    }

    /**
     * Schedule the next rebuild chunk.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function schedule_next_chunk() {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_single_event( time() + 1, self::CRON_HOOK );
        }
    }

    /**
     * Clear a pending rebuild.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function unschedule_rebuild() {
        wp_clear_scheduled_hook( self::CRON_HOOK );

        delete_option( self::STATE_OPTION );
    }

    /**
     * Record that a rebuild finished.
     *
     * @since 1.0.0
     *
     * @return void
     */
    private function finish_rebuild() {
        delete_option( self::STATE_OPTION );

        /**
         * Fires when a full search index rebuild completes.
         *
         * @since 1.0.0
         */
        do_action( 'licensio_search_index_rebuilt' );
    }

    /**
     * Whether an article belongs in the index.
     *
     * @since 1.0.0
     *
     * @param WP_Post $post The article.
     *
     * @return bool True when the article is publicly readable.
     */
    public static function is_indexable( $post ) {
        $indexable = $post instanceof WP_Post
            && 'publish' === $post->post_status
            && '' === (string) $post->post_password;

        /**
         * Filter whether an article is added to the search index.
         *
         * Returning false removes the article's row, so a gating add-on can keep
         * restricted documentation out of public search results entirely.
         *
         * @since 1.0.0
         *
         * @param bool    $indexable Whether the article should be indexed.
         * @param WP_Post $post      The article.
         */
        return (bool) apply_filters( 'licensio_search_indexable', $indexable, $post );
    }

    /**
     * The taxonomies whose terms are indexed with an article.
     *
     * @since 1.0.0
     *
     * @return string[] The taxonomy names.
     */
    public static function indexed_taxonomies() {
        $taxonomies = array(
            Licensio_KB_Post_Types::TAXONOMY_CATEGORY,
            Licensio_KB_Post_Types::TAXONOMY_TAG,
        );

        /**
         * Filter the taxonomies whose term names are written into the index.
         *
         * @since 1.0.0
         *
         * @param string[] $taxonomies The taxonomy names.
         */
        return (array) apply_filters( 'licensio_search_indexed_taxonomies', $taxonomies );
    }

    /**
     * Build the index row for an article.
     *
     * `updated_at` is deliberately absent: it changes on every call and would
     * defeat the per-request digest that keeps a single editor save to one write.
     * sync() adds it immediately before the row is stored.
     *
     * @since 1.0.0
     *
     * @param WP_Post $post The article.
     *
     * @return array{article_id: int, title: string, excerpt: string, content: string, terms: string} The row.
     */
    public function build_row( $post ) {
        $content = self::plain_text( $post->post_content );

        return array(
            'article_id' => (int) $post->ID,
            'title'      => (string) $post->post_title,
            'excerpt'    => self::excerpt( $post, $content ),
            'content'    => $content,
            'terms'      => self::term_names( (int) $post->ID ),
        );
    }

    /**
     * The term names indexed with an article.
     *
     * @since 1.0.0
     *
     * @param int $post_id The article ID.
     *
     * @return string The term names, comma separated.
     */
    public static function term_names( $post_id ) {
        $terms = wp_get_object_terms(
            (int) $post_id,
            self::indexed_taxonomies(),
            array( 'fields' => 'names' )
        );

        if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
            return '';
        }

        return implode( ', ', array_map( 'strval', $terms ) );
    }

    /**
     * The excerpt stored for an article.
     *
     * @since 1.0.0
     *
     * @param WP_Post $post    The article.
     * @param string  $content The article's plain text body.
     *
     * @return string The excerpt.
     */
    public static function excerpt( $post, $content ) {
        $excerpt = self::plain_text( (string) $post->post_excerpt );

        if ( '' === $excerpt ) {
            $excerpt = $content;
        }

        if ( function_exists( 'mb_substr' ) ) {
            return trim( mb_substr( $excerpt, 0, 300 ) );
        }

        return trim( substr( $excerpt, 0, 300 ) );
    }

    /**
     * Reduce post content to indexable plain text.
     *
     * The stored content is never rendered, so `the_content` is deliberately not
     * applied: running every content filter for every article of a rebuild is
     * both slow and unsafe, because those filters expect to be inside a loop on
     * a front-end request. Shortcodes are expanded away and the markup is
     * stripped, so neither shortcode attributes nor block delimiters become
     * searchable text — a search for "paragraph" must not match every article on
     * the site because every body contains `<!-- wp:paragraph -->`.
     *
     * Block delimiters need no pattern of their own: they are HTML comments, and
     * `wp_strip_all_tags()` removes those. Two earlier revisions carried a
     * `wp:`-specific regex and then a general comment regex in front of it; both
     * were redundant, and neither could be removed without every test staying
     * green, which is how they survived.
     *
     * The closing-tag replacement above the strip is NOT redundant. Without it
     * `<p>first</p><p>second</p>` collapses to `firstsecond` and neither word is
     * findable.
     *
     * @since 1.0.0
     *
     * @param string $content The raw post content.
     *
     * @return string The plain text.
     */
    public static function plain_text( $content ) {
        $content = (string) $content;
        $content = strip_shortcodes( $content );

        /*
         * Decode before stripping, not after.
         *
         * The other order resurrects the markup the strip just removed: run on
         * `Intro &lt;script&gt;alert(1)&lt;/script&gt; end` it returned
         * `Intro <script>alert(1)</script> end` - live markup out of a method
         * named for plain text. Nothing prints that today (the content column
         * is only ever compared with `mb_stripos`, the excerpt column is never
         * read back, and the REST snippet is escaped before its `mark` is
         * inserted), so this was latent rather than exploitable. It is still
         * one `echo` from being real, and a `public static` helper should not
         * depend on every future caller knowing that.
         *
         * The cost is that an article *about* markup loses the tag it quotes:
         * text displayed as `&lt;script&gt;` decodes to a tag and is then
         * stripped, so the word is gone from the index. That is a small search
         * quality loss against a method that no longer hands back executable
         * markup, which is the right way round.
         */
        $content = html_entity_decode( $content, ENT_QUOTES, 'UTF-8' );
        $content = str_replace( array( '<br>', '<br/>', '<br />', '</p>', '</div>', '</li>' ), ' ', $content );
        $content = wp_strip_all_tags( $content );
        $content = (string) preg_replace( '/\s+/u', ' ', $content );

        return trim( $content );
    }

    /**
     * The current time, in the format the index column stores.
     *
     * @since 1.0.0
     *
     * @return string A GMT `Y-m-d H:i:s` timestamp.
     */
    private static function now() {
        if ( function_exists( 'current_time' ) ) {
            return (string) current_time( 'mysql', true );
        }

        return gmdate( 'Y-m-d H:i:s' );
    }
}
