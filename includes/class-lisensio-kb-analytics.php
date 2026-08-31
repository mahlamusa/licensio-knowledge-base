<?php
/**
 * Search and article-view capture.
 *
 * Writes one row per search and one row per article view, and nothing else.
 * Every row carries the rotating salted visitor hash produced by
 * Lisensio_KB_Visitor and no IP address: not a truncated one, not a hashed
 * column beside one, none. `REMOTE_ADDR` is never read here — the only code in
 * the plugin permitted to read it is Lisensio_KB_Visitor, which hashes it
 * immediately and never returns it.
 *
 * Collection is gated on the `enable_analytics` setting. When it is off, every
 * write path in this class returns before touching the database.
 *
 * Consumed by: #3 (the article views column), #10 (the settings screen).
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Records search events and article views.
 *
 * @since 1.0.0
 */
class Lisensio_KB_Analytics {

    /**
     * The search log table, without the WordPress prefix.
     *
     * @since 1.0.0
     * @var string
     */
    const TABLE_SEARCH_LOG = 'lisensio_search_log';

    /**
     * The article views table, without the WordPress prefix.
     *
     * @since 1.0.0
     * @var string
     */
    const TABLE_ARTICLE_VIEWS = 'lisensio_article_views';

    /**
     * The feedback table, without the WordPress prefix.
     *
     * @since 1.0.0
     * @var string
     */
    const TABLE_FEEDBACK = 'lisensio_feedback';

    /**
     * The accepted values of the search log `source` column.
     *
     * @since 1.0.0
     * @var string[]
     */
    const SOURCES = array( 'site', 'live', 'ticket' );

    /**
     * Maximum stored length of a search term, matching the column width.
     *
     * @since 1.0.0
     * @var int
     */
    const TERM_MAX_LENGTH = 191;

    /**
     * The settings store.
     *
     * @since 1.0.0
     * @var Lisensio_KB_Settings
     */
    private $settings;

    /**
     * The visitor identity helper.
     *
     * @since 1.0.0
     * @var Lisensio_KB_Visitor
     */
    private $visitor;

    /**
     * Article IDs already recorded as viewed during this request.
     *
     * The three frontend hooks can all fire for one article under a block
     * theme, and a loop that renders the same article twice would otherwise
     * count it twice.
     *
     * @since 1.0.0
     * @var array<int, bool>
     */
    private $recorded_views = array();

    /**
     * Whether a search has already been recorded during this request.
     *
     * @since 1.0.0
     * @var bool
     */
    private $recorded_search = false;

    /**
     * View counts primed for the current admin list table, keyed by article ID.
     *
     * @since 1.0.0
     * @var array<int, int>|null
     */
    private $column_counts = null;

    /**
     * Build the recorder.
     *
     * @since 1.0.0
     *
     * @param Lisensio_KB_Settings $settings The settings store.
     * @param Lisensio_KB_Visitor  $visitor  The visitor identity helper.
     */
    public function __construct( Lisensio_KB_Settings $settings, Lisensio_KB_Visitor $visitor ) {
        $this->settings = $settings;
        $this->visitor  = $visitor;
    }

    /**
     * Register the WordPress hooks.
     *
     * View tracking attaches to `lisensio_after_article`, which both the
     * classic template layer (#5) and the block template layer (#6) fire, so
     * one registration covers both theme paradigms.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function init_hooks() {
        add_action( 'lisensio_after_article', array( $this, 'handle_article_view' ) );
        add_action( 'wp', array( $this, 'handle_site_search' ) );
        add_action( 'lisensio_search_performed', array( $this, 'handle_search_event' ), 10, 3 );

        add_filter( 'lisensio_article_views_column', array( $this, 'filter_views_column' ), 10, 2 );
        add_filter( 'lisensio_rest_controllers', array( $this, 'register_rest_controller' ) );
        add_filter( 'lisensio_rest_stub_routes', array( $this, 'release_stub_routes' ) );
    }

    /**
     * Action handler: record a view and discard the result.
     *
     * The recording methods return whether a row was written, which is what
     * makes them testable; an action callback must return nothing.
     *
     * @since 1.0.0
     *
     * @param WP_Post|int|null $post The article being rendered.
     *
     * @return void
     */
    public function handle_article_view( $post ) {
        $this->record_article_view( $post );
    }

    /**
     * Action handler: record the front-end search and discard the result.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function handle_site_search() {
        $this->maybe_record_site_search();
    }

    /**
     * Action handler: record a search event and discard the result.
     *
     * @since 1.0.0
     *
     * @param string $term          The raw term the visitor typed.
     * @param int    $results_count How many articles the search returned.
     * @param string $source        Where the search came from.
     *
     * @return void
     */
    public function handle_search_event( $term, $results_count, $source = 'site' ) {
        $this->record_search_event( $term, $results_count, $source );
    }

    /**
     * Whether analytics collection is switched on.
     *
     * @since 1.0.0
     *
     * @return bool True when rows may be written.
     */
    public function is_enabled() {
        $enabled = (bool) $this->settings->get( 'enable_analytics', true );

        /**
         * Filter whether knowledge base analytics collection is enabled.
         *
         * Returning false stops every write in this class. It does not delete
         * anything already recorded; that is the retention job's business.
         *
         * @since 1.0.0
         *
         * @param bool $enabled Whether collection is enabled.
         */
        return (bool) apply_filters( 'lisensio_analytics_enabled', $enabled );
    }

    /**
     * Record a view of the article a frontend hook is rendering.
     *
     * @since 1.0.0
     *
     * @param WP_Post|int|null $post The article being rendered.
     *
     * @return bool True when a row was written.
     */
    public function record_article_view( $post ) {
        if ( $post instanceof WP_Post ) {
            $article_id = (int) $post->ID;
        } elseif ( is_numeric( $post ) ) {
            $article_id = (int) $post;
        } else {
            $article_id = 0;
        }

        if ( $article_id <= 0 ) {
            return false;
        }

        if ( isset( $this->recorded_views[ $article_id ] ) ) {
            return false;
        }

        if ( ! $this->is_enabled() ) {
            return false;
        }

        $this->recorded_views[ $article_id ] = true;

        return $this->insert(
            self::TABLE_ARTICLE_VIEWS,
            array(
                'article_id'   => $article_id,
                'visitor_hash' => $this->visitor->get_hash(),
                'created_at'   => $this->now(),
            ),
            array( '%d', '%s', '%s' )
        );
    }

    /**
     * Record a search performed against the knowledge base.
     *
     * @since 1.0.0
     *
     * @param string $term          The raw term the visitor typed.
     * @param int    $results_count How many articles the search returned.
     * @param string $source        Where the search came from: `site`, `live` or `ticket`.
     *
     * @return bool True when a row was written.
     */
    public function record_search_event( $term, $results_count, $source = 'site' ) {
        $term = $this->clean_term( $term );

        if ( '' === $term ) {
            return false;
        }

        if ( ! $this->is_enabled() ) {
            return false;
        }

        return $this->insert(
            self::TABLE_SEARCH_LOG,
            array(
                'term'            => $term,
                'normalized_term' => self::normalize_term( $term ),
                'results_count'   => max( 0, (int) $results_count ),
                'visitor_hash'    => $this->visitor->get_hash(),
                'source'          => in_array( (string) $source, self::SOURCES, true ) ? (string) $source : 'site',
                'created_at'      => $this->now(),
            ),
            array( '%s', '%s', '%d', '%s', '%s', '%s' )
        );
    }

    /**
     * Record the search behind a front-end knowledge base results page.
     *
     * Only a search that asked for the article post type is recorded; a
     * site-wide search belongs to the theme, and logging it would fill the
     * knowledge base's documentation-gap report with terms that were never
     * aimed at the knowledge base.
     *
     * @since 1.0.0
     *
     * @return bool True when a row was written.
     */
    public function maybe_record_site_search() {
        if ( $this->recorded_search || is_admin() || ! is_search() ) {
            return false;
        }

        if ( ! Lisensio_KB_Templates::is_article_search() ) {
            return false;
        }

        $this->recorded_search = true;

        global $wp_query;

        $found = ( isset( $wp_query ) && isset( $wp_query->found_posts ) ) ? (int) $wp_query->found_posts : 0;

        return $this->record_search_event( get_search_query(), $found, 'site' );
    }

    /**
     * Reduce a search term to its stored comparison form.
     *
     * Lower-cased, with runs of whitespace collapsed to one space and the
     * surrounding whitespace removed, so `Reset  Password` and `reset password`
     * group together in the top-searches report instead of appearing as two
     * separate documentation gaps.
     *
     * @since 1.0.0
     *
     * @param string $term The raw term.
     *
     * @return string The normalised term, at most TERM_MAX_LENGTH characters.
     */
    public static function normalize_term( $term ) {
        $term = (string) $term;

        $term = function_exists( 'mb_strtolower' ) ? mb_strtolower( $term, 'UTF-8' ) : strtolower( $term );
        $term = preg_replace( '/\s+/u', ' ', $term );

        return self::truncate( trim( (string) $term ) );
    }

    /**
     * Supply the view count rendered in the article list table column.
     *
     * Hooked to the filter #3 exposes, rather than editing #3's class.
     *
     * @since 1.0.0
     *
     * @param string $value   The value #3 would render. Empty by default.
     * @param int    $post_id The article being rendered.
     *
     * @return string The formatted view count, or the incoming value when there is nothing to show.
     */
    public function filter_views_column( $value, $post_id ) {
        $post_id = (int) $post_id;

        if ( $post_id <= 0 ) {
            return $value;
        }

        $counts = $this->column_view_counts( $post_id );

        if ( ! isset( $counts[ $post_id ] ) ) {
            return $value;
        }

        return number_format_i18n( $counts[ $post_id ] );
    }

    /**
     * Add the analytics REST controller to the registrar.
     *
     * @since 1.0.0
     *
     * @param string[] $controllers The controller class names.
     *
     * @return string[] The controller class names, with the analytics one appended.
     */
    public function register_rest_controller( $controllers ) {
        $controllers   = is_array( $controllers ) ? $controllers : array();
        $controllers[] = 'Lisensio_KB_REST_Analytics_Controller';

        return $controllers;
    }

    /**
     * Release the analytics routes from the scaffold's not-implemented stubs.
     *
     * @since 1.0.0
     *
     * @param array<string, array<string, string>> $routes The reserved routes.
     *
     * @return array<string, array<string, string>> The reserved routes, without the analytics ones.
     */
    public function release_stub_routes( $routes ) {
        if ( ! is_array( $routes ) ) {
            return $routes;
        }

        foreach ( Lisensio_KB_REST_Analytics_Controller::REPORTS as $report ) {
            unset( $routes[ '/analytics/' . $report ] );
        }

        return $routes;
    }

    /**
     * View counts for the current admin list table, loaded once.
     *
     * The column filter fires once per row, so a count query per row would be
     * one query per article on every list table load. The first call loads
     * every article in the current admin query in one grouped query; a post
     * that was not in it falls back to a single lookup.
     *
     * @since 1.0.0
     *
     * @param int $post_id The article about to be rendered.
     *
     * @return array<int, int> Article ID to view count.
     */
    private function column_view_counts( $post_id ) {
        if ( null === $this->column_counts ) {
            $this->column_counts = Lisensio_KB_Analytics_Repository::view_counts( $this->current_screen_article_ids() );
        }

        if ( ! array_key_exists( $post_id, $this->column_counts ) ) {
            $this->column_counts += Lisensio_KB_Analytics_Repository::view_counts( array( $post_id ) );
        }

        return $this->column_counts;
    }

    /**
     * The article IDs the current admin query is about to render.
     *
     * @since 1.0.0
     *
     * @return int[] The article IDs, or an empty array outside a list table.
     */
    private function current_screen_article_ids() {
        global $wp_query;

        if ( ! isset( $wp_query ) || ! isset( $wp_query->posts ) || ! is_array( $wp_query->posts ) ) {
            return array();
        }

        $ids = array();

        foreach ( $wp_query->posts as $post ) {
            if ( $post instanceof WP_Post ) {
                $ids[] = (int) $post->ID;
            } elseif ( is_numeric( $post ) ) {
                $ids[] = (int) $post;
            }
        }

        return $ids;
    }

    /**
     * Reduce a raw search term to what is stored in the `term` column.
     *
     * @since 1.0.0
     *
     * @param mixed $term The raw term.
     *
     * @return string The sanitized term, at most TERM_MAX_LENGTH characters.
     */
    private function clean_term( $term ) {
        if ( ! is_scalar( $term ) ) {
            return '';
        }

        return self::truncate( trim( sanitize_text_field( (string) $term ) ) );
    }

    /**
     * Cut a term to the stored column width without splitting a character.
     *
     * @since 1.0.0
     *
     * @param string $term The term.
     *
     * @return string The term, at most TERM_MAX_LENGTH characters long.
     */
    private static function truncate( $term ) {
        if ( function_exists( 'mb_substr' ) ) {
            return mb_substr( $term, 0, self::TERM_MAX_LENGTH, 'UTF-8' );
        }

        return substr( $term, 0, self::TERM_MAX_LENGTH );
    }

    /**
     * The current site time, in the format the datetime columns store.
     *
     * @since 1.0.0
     *
     * @return string A `Y-m-d H:i:s` timestamp.
     */
    private function now() {
        return current_time( 'mysql' );
    }

    /**
     * Write one row through the database gateway.
     *
     * Failures are swallowed. Analytics is a side effect of rendering a page or
     * running a search, and a missing database library or a table that has not
     * been installed yet must not turn a visitor's page view into a fatal.
     *
     * @since 1.0.0
     *
     * @param string               $table  The unprefixed table name.
     * @param array<string, mixed> $data   Column to value.
     * @param string[]             $format The `$wpdb` format for each value, in order.
     *
     * @return bool True when a row was written.
     */
    private function insert( $table, array $data, array $format ) {
        try {
            return (bool) Lisensio_KB_DB::table( $table )->insert( $data, $format );
        } catch ( Exception $e ) {
            unset( $e );

            return false;
        }
    }
}
