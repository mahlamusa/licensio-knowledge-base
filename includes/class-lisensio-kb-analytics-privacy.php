<?php
/**
 * WordPress privacy exporter and eraser.
 *
 * Answers a personal-data request against the three tables the knowledge base
 * writes visitor rows into: `lisensio_search_log`, `lisensio_article_views` and
 * `lisensio_feedback`.
 *
 * A personal-data request arrives as an email address, and none of these tables
 * stores one. The link is made in two steps: the email address resolves to a
 * WordPress user, and `lisensio_feedback.user_id` resolves that user to the
 * `visitor_hash` values their submissions were recorded under. Those hashes then
 * identify the same person's rows in the two tables that have no user column.
 * That is the only join between a person and their rows that exists, precisely
 * because no IP address is stored anywhere.
 *
 * A logged-out visitor who never left feedback therefore cannot be resolved from
 * an email address at all — there is nothing linking them to one. That is a
 * property of the privacy design rather than a gap in this class: the data is
 * already pseudonymous, held for at most the retention window, and keyed on a
 * salt that is destroyed daily. Sites with another way to resolve a person to a
 * hash can supply it through `lisensio_privacy_visitor_hashes`.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the knowledge base personal-data exporter and eraser.
 *
 * @since 1.0.0
 */
class Lisensio_KB_Analytics_Privacy {

    /**
     * The exporter and eraser identifier.
     *
     * @since 1.0.0
     * @var string
     */
    const HANDLER_ID = 'lisensio-knowledge-base';

    /**
     * Rows processed per table per page.
     *
     * @since 1.0.0
     * @var int
     */
    const PAGE_SIZE = 100;

    /**
     * Register the WordPress hooks.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function init_hooks() {
        add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
        add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
    }

    /**
     * Add the knowledge base exporter to the registry.
     *
     * @since 1.0.0
     *
     * @param array<string, array<string, mixed>> $exporters The registered exporters.
     *
     * @return array<string, array<string, mixed>> The exporters, with this one added.
     */
    public function register_exporter( $exporters ) {
        $exporters = is_array( $exporters ) ? $exporters : array();

        $exporters[ self::HANDLER_ID ] = array(
            'exporter_friendly_name' => __( 'Knowledge Base', 'lisensio-knowledge-base' ),
            'callback'               => array( $this, 'export' ),
        );

        return $exporters;
    }

    /**
     * Add the knowledge base eraser to the registry.
     *
     * @since 1.0.0
     *
     * @param array<string, array<string, mixed>> $erasers The registered erasers.
     *
     * @return array<string, array<string, mixed>> The erasers, with this one added.
     */
    public function register_eraser( $erasers ) {
        $erasers = is_array( $erasers ) ? $erasers : array();

        $erasers[ self::HANDLER_ID ] = array(
            'eraser_friendly_name' => __( 'Knowledge Base', 'lisensio-knowledge-base' ),
            'callback'             => array( $this, 'erase' ),
        );

        return $erasers;
    }

    /**
     * Export one page of a person's knowledge base rows.
     *
     * @since 1.0.0
     *
     * @param string $email_address The address the request was made for.
     * @param int    $page          The 1-based page number.
     *
     * @return array{data: array<int, array<string, mixed>>, done: bool} The exporter response.
     */
    public function export( $email_address, $page = 1 ) {
        $page     = max( 1, (int) $page );
        $offset   = ( $page - 1 ) * self::PAGE_SIZE;
        $identity = $this->identity( $email_address );

        if ( ! $identity['resolvable'] ) {
            return array(
                'data' => array(),
                'done' => true,
            );
        }

        $items = array();
        $done  = true;

        $searches = $this->select( Lisensio_KB_Analytics::TABLE_SEARCH_LOG, $identity, $offset );

        foreach ( $searches as $row ) {
            $items[] = $this->search_item( $row );
        }

        $views = $this->select( Lisensio_KB_Analytics::TABLE_ARTICLE_VIEWS, $identity, $offset );

        foreach ( $views as $row ) {
            $items[] = $this->view_item( $row );
        }

        $feedback = $this->select( Lisensio_KB_Analytics::TABLE_FEEDBACK, $identity, $offset );

        foreach ( $feedback as $row ) {
            $items[] = $this->feedback_item( $row );
        }

        foreach ( array( $searches, $views, $feedback ) as $set ) {
            if ( count( $set ) >= self::PAGE_SIZE ) {
                $done = false;
            }
        }

        return array(
            'data' => $items,
            'done' => $done,
        );
    }

    /**
     * Erase one page of a person's knowledge base rows.
     *
     * @since 1.0.0
     *
     * @param string $email_address The address the request was made for.
     * @param int    $page          The 1-based page number.
     *
     * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool} The eraser response.
     */
    public function erase( $email_address, $page = 1 ) {
        unset( $page );

        $identity = $this->identity( $email_address );

        $response = array(
            'items_removed'  => false,
            'items_retained' => false,
            'messages'       => array(),
            'done'           => true,
        );

        if ( ! $identity['resolvable'] ) {
            return $response;
        }

        $removed = 0;
        $done    = true;

        foreach ( array(
            Lisensio_KB_Analytics::TABLE_SEARCH_LOG,
            Lisensio_KB_Analytics::TABLE_ARTICLE_VIEWS,
            Lisensio_KB_Analytics::TABLE_FEEDBACK,
        ) as $table ) {
            $rows = $this->delete( $table, $identity );

            $removed += $rows;

            if ( $rows >= self::PAGE_SIZE ) {
                $done = false;
            }
        }

        $response['items_removed'] = $removed > 0;
        $response['done']          = $done;

        return $response;
    }

    /**
     * Resolve an email address to the identifiers the tables are keyed on.
     *
     * @since 1.0.0
     *
     * @param string $email_address The address the request was made for.
     *
     * `resolvable` is an early-out that saves three queries when nothing
     * matched; it is not what keeps an unmatched request from erasing the
     * table. That is `has_criteria()`, which is checked per table immediately
     * before every read and every delete.
     *
     * @return array{user_id: int, hashes: string[], resolvable: bool} The identifiers, and whether anything could be matched.
     */
    public function identity( $email_address ) {
        $user    = get_user_by( 'email', (string) $email_address );
        $user_id = ( $user instanceof WP_User ) ? (int) $user->ID : 0;
        $hashes  = $this->visitor_hashes_for_user( $user_id );

        /**
         * Filter the visitor hashes a personal-data request resolves to.
         *
         * The plugin can only resolve a hash for somebody who left feedback
         * while logged in. A site that can identify a visitor some other way —
         * a CRM, an SSO session store — supplies the extra hashes here.
         *
         * @since 1.0.0
         *
         * @param string[] $hashes        The resolved visitor hashes.
         * @param string   $email_address The address the request was made for.
         * @param int      $user_id       The matching user ID, or 0.
         */
        $hashes = (array) apply_filters( 'lisensio_privacy_visitor_hashes', $hashes, (string) $email_address, $user_id );

        $clean = array();

        foreach ( $hashes as $hash ) {
            $hash = (string) $hash;

            if ( '' !== $hash ) {
                $clean[ $hash ] = $hash;
            }
        }

        $clean = array_values( $clean );

        return array(
            'user_id'    => $user_id,
            'hashes'     => $clean,
            'resolvable' => ( $user_id > 0 || ! empty( $clean ) ),
        );
    }

    /**
     * The visitor hashes a user's feedback submissions were recorded under.
     *
     * @since 1.0.0
     *
     * @param int $user_id The user to resolve.
     *
     * @return string[] The distinct visitor hashes, or an empty array.
     */
    public function visitor_hashes_for_user( $user_id ) {
        $user_id = (int) $user_id;

        if ( $user_id <= 0 ) {
            return array();
        }

        try {
            $rows = (array) self::builder( Lisensio_KB_Analytics::TABLE_FEEDBACK )
                ->selectRaw( 'SELECT DISTINCT visitor_hash' )
                ->where( 'user_id', $user_id )
                ->getAll( ARRAY_A );
        } catch ( Exception $e ) {
            unset( $e );

            return array();
        }

        $hashes = array();

        foreach ( $rows as $row ) {
            $hash = isset( $row['visitor_hash'] ) ? (string) $row['visitor_hash'] : '';

            if ( '' !== $hash ) {
                $hashes[] = $hash;
            }
        }

        return $hashes;
    }

    /**
     * Read one page of a person's rows out of one table.
     *
     * @since 1.0.0
     *
     * @param string                                                  $table    The unprefixed table name.
     * @param array{user_id: int, hashes: string[], resolvable: bool} $identity The resolved identifiers.
     * @param int                                                     $offset   How many rows to skip.
     *
     * @return array<int, array<string, mixed>> The matching rows.
     */
    private function select( $table, array $identity, $offset ) {
        if ( ! $this->has_criteria( $table, $identity ) ) {
            return array();
        }

        try {
            $builder = self::builder( $table );

            $this->apply_criteria( $builder, $table, $identity );

            return (array) $builder
                ->orderBy( 'id', 'ASC' )
                ->limit( self::PAGE_SIZE )
                ->offset( max( 0, (int) $offset ) )
                ->getAll( ARRAY_A );
        } catch ( Exception $e ) {
            unset( $e );

            return array();
        }
    }

    /**
     * Delete one page of a person's rows from one table.
     *
     * @since 1.0.0
     *
     * @param string                                                  $table    The unprefixed table name.
     * @param array{user_id: int, hashes: string[], resolvable: bool} $identity The resolved identifiers.
     *
     * @return int How many rows were deleted.
     */
    private function delete( $table, array $identity ) {
        if ( ! $this->has_criteria( $table, $identity ) ) {
            return 0;
        }

        try {
            $builder = self::builder( $table );

            $this->apply_criteria( $builder, $table, $identity );

            $rows = $builder->limit( self::PAGE_SIZE )->delete();
        } catch ( Exception $e ) {
            unset( $e );

            return 0;
        }

        return is_numeric( $rows ) ? max( 0, (int) $rows ) : 0;
    }

    /**
     * Whether a table can be matched at all for this person.
     *
     * The search log and the view log have no user column, so they can only be
     * matched by visitor hash. Running an unfiltered query against them when no
     * hash resolved would export, or erase, every row in the table.
     *
     * @since 1.0.0
     *
     * @param string                                                  $table    The unprefixed table name.
     * @param array{user_id: int, hashes: string[], resolvable: bool} $identity The resolved identifiers.
     *
     * @return bool True when at least one criterion applies.
     */
    private function has_criteria( $table, array $identity ) {
        if ( Lisensio_KB_Analytics::TABLE_FEEDBACK === $table ) {
            return $identity['user_id'] > 0 || ! empty( $identity['hashes'] );
        }

        return ! empty( $identity['hashes'] );
    }

    /**
     * Add this person's WHERE conditions to a query builder.
     *
     * @since 1.0.0
     *
     * @param mixed                                                   $builder  The query builder.
     * @param string                                                  $table    The unprefixed table name.
     * @param array{user_id: int, hashes: string[], resolvable: bool} $identity The resolved identifiers.
     *
     * @return void
     */
    private function apply_criteria( $builder, $table, array $identity ) {
        $has_hashes = ! empty( $identity['hashes'] );

        if ( Lisensio_KB_Analytics::TABLE_FEEDBACK !== $table ) {
            $builder->whereIn( 'visitor_hash', $identity['hashes'] );

            return;
        }

        if ( $has_hashes && $identity['user_id'] > 0 ) {
            $builder->whereIn( 'visitor_hash', $identity['hashes'] );
            $builder->orWhere( 'user_id', $identity['user_id'] );

            return;
        }

        if ( $has_hashes ) {
            $builder->whereIn( 'visitor_hash', $identity['hashes'] );

            return;
        }

        $builder->where( 'user_id', $identity['user_id'] );
    }

    /**
     * Format one search log row for the exporter.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $row The database row.
     *
     * @return array<string, mixed> The export item.
     */
    private function search_item( array $row ) {
        return array(
            'group_id'    => 'lisensio-kb-searches',
            'group_label' => __( 'Knowledge Base searches', 'lisensio-knowledge-base' ),
            'item_id'     => 'lisensio-kb-search-' . (int) $this->value( $row, 'id' ),
            'data'        => array(
                array(
                    'name'  => __( 'Search term', 'lisensio-knowledge-base' ),
                    'value' => (string) $this->value( $row, 'term' ),
                ),
                array(
                    'name'  => __( 'Results returned', 'lisensio-knowledge-base' ),
                    'value' => (int) $this->value( $row, 'results_count' ),
                ),
                array(
                    'name'  => __( 'Source', 'lisensio-knowledge-base' ),
                    'value' => (string) $this->value( $row, 'source' ),
                ),
                array(
                    'name'  => __( 'Visitor identifier', 'lisensio-knowledge-base' ),
                    'value' => (string) $this->value( $row, 'visitor_hash' ),
                ),
                array(
                    'name'  => __( 'Date', 'lisensio-knowledge-base' ),
                    'value' => (string) $this->value( $row, 'created_at' ),
                ),
            ),
        );
    }

    /**
     * Format one article view row for the exporter.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $row The database row.
     *
     * @return array<string, mixed> The export item.
     */
    private function view_item( array $row ) {
        return array(
            'group_id'    => 'lisensio-kb-article-views',
            'group_label' => __( 'Knowledge Base article views', 'lisensio-knowledge-base' ),
            'item_id'     => 'lisensio-kb-view-' . (int) $this->value( $row, 'id' ),
            'data'        => array(
                array(
                    'name'  => __( 'Article', 'lisensio-knowledge-base' ),
                    'value' => $this->article_label( (int) $this->value( $row, 'article_id' ) ),
                ),
                array(
                    'name'  => __( 'Visitor identifier', 'lisensio-knowledge-base' ),
                    'value' => (string) $this->value( $row, 'visitor_hash' ),
                ),
                array(
                    'name'  => __( 'Date', 'lisensio-knowledge-base' ),
                    'value' => (string) $this->value( $row, 'created_at' ),
                ),
            ),
        );
    }

    /**
     * Format one feedback row for the exporter.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $row The database row.
     *
     * @return array<string, mixed> The export item.
     */
    private function feedback_item( array $row ) {
        return array(
            'group_id'    => 'lisensio-kb-feedback',
            'group_label' => __( 'Knowledge Base feedback', 'lisensio-knowledge-base' ),
            'item_id'     => 'lisensio-kb-feedback-' . (int) $this->value( $row, 'id' ),
            'data'        => array(
                array(
                    'name'  => __( 'Article', 'lisensio-knowledge-base' ),
                    'value' => $this->article_label( (int) $this->value( $row, 'article_id' ) ),
                ),
                array(
                    'name'  => __( 'Vote', 'lisensio-knowledge-base' ),
                    'value' => (int) $this->value( $row, 'vote' ) > 0
                        ? __( 'Helpful', 'lisensio-knowledge-base' )
                        : __( 'Not helpful', 'lisensio-knowledge-base' ),
                ),
                array(
                    'name'  => __( 'Comment', 'lisensio-knowledge-base' ),
                    'value' => (string) $this->value( $row, 'comment' ),
                ),
                array(
                    'name'  => __( 'Status', 'lisensio-knowledge-base' ),
                    'value' => (string) $this->value( $row, 'status' ),
                ),
                array(
                    'name'  => __( 'Visitor identifier', 'lisensio-knowledge-base' ),
                    'value' => (string) $this->value( $row, 'visitor_hash' ),
                ),
                array(
                    'name'  => __( 'Date', 'lisensio-knowledge-base' ),
                    'value' => (string) $this->value( $row, 'created_at' ),
                ),
            ),
        );
    }

    /**
     * A human-readable label for an article ID.
     *
     * @since 1.0.0
     *
     * @param int $article_id The article.
     *
     * @return string The article title, or the ID when the article is gone.
     */
    private function article_label( $article_id ) {
        $title = get_the_title( $article_id );

        return ( is_string( $title ) && '' !== $title ) ? $title : (string) $article_id;
    }

    /**
     * Read a column out of a result row.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $row    The result row.
     * @param string               $column The column name.
     *
     * @return mixed The value, or an empty string when the column is absent.
     */
    private function value( array $row, $column ) {
        return array_key_exists( $column, $row ) ? $row[ $column ] : '';
    }

    /**
     * A query builder for one of the plugin tables.
     *
     * Wrapped so the fluent calls that follow are typed as `mixed` rather than
     * as the gateway's declared `object`, which static analysis cannot call
     * methods on.
     *
     * @since 1.0.0
     *
     * @param string $table The unprefixed table name.
     *
     * @return mixed The StellarWP query builder.
     */
    private static function builder( $table ) {
        return Lisensio_KB_DB::table( $table );
    }
}
