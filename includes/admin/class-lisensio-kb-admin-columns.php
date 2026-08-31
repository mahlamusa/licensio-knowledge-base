<?php
/**
 * Article list table columns.
 *
 * Adds the category, tag, view-count and usefulness columns to the
 * `lisensio_article` list table, and guarantees the author column is present.
 *
 * The taxonomy columns are registered here rather than through the taxonomies'
 * `show_admin_column` argument. That argument was removed from
 * Lisensio_KB_Post_Types during the scaffold review as scope creep into this
 * issue, so nothing is inherited: without this class the list table ships with
 * no category and no tag column at all.
 *
 * Views and usefulness have no data behind them until the analytics (#9) and
 * feedback (#8) issues land. They render an em dash, and each has a filter a
 * consuming issue hooks to supply the real value — an approach that leaves the
 * columns visible and correctly positioned from day one instead of moving the
 * whole list table around later. Neither is sortable yet, for the same reason:
 * there is nothing to sort on. A consuming issue registers sortability through
 * core's own `manage_edit-lisensio_article_sortable_columns` filter when it has
 * data, so no extra seam is invented here.
 *
 * Row actions and bulk actions are core's. The post type is registered with
 * `show_ui` and a full capability map, so edit, quick edit, trash, view,
 * bulk edit and bulk trash all work exactly as they do for a post, and nothing
 * in this class touches them.
 *
 * Consumed by: #8, #9.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers and renders the article list table columns.
 *
 * @since 1.0.0
 */
class Lisensio_KB_Admin_Columns {

    /**
     * Column key for the categories column.
     *
     * @since 1.0.0
     * @var string
     */
    const COLUMN_CATEGORY = 'lisensio_category';

    /**
     * Column key for the tags column.
     *
     * @since 1.0.0
     * @var string
     */
    const COLUMN_TAG = 'lisensio_tag';

    /**
     * Column key for the view count column.
     *
     * @since 1.0.0
     * @var string
     */
    const COLUMN_VIEWS = 'lisensio_views';

    /**
     * Column key for the usefulness column.
     *
     * @since 1.0.0
     * @var string
     */
    const COLUMN_USEFULNESS = 'lisensio_usefulness';

    /**
     * The attachment count column.
     *
     * @since 1.0.0
     * @var string
     */
    const COLUMN_ATTACHMENTS = 'lisensio_attachments';

    /**
     * The estimated reading time column.
     *
     * @since 1.0.0
     * @var string
     */
    const COLUMN_READING_TIME = 'lisensio_reading_time';

    /**
     * Words a reader is assumed to get through in a minute.
     *
     * 200 is the conventional figure for prose on screen. Filterable because a
     * knowledge base of dense reference material reads slower than one of
     * short how-tos.
     *
     * @since 1.0.0
     * @var int
     */
    const WORDS_PER_MINUTE = 200;

    /**
     * Rendered in a cell that has nothing to show.
     *
     * @since 1.0.0
     * @var string
     */
    const PLACEHOLDER = '—';

    /**
     * Register the WordPress hooks.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function init_hooks() {
        $post_type = Lisensio_KB_Post_Types::POST_TYPE;

        add_filter( 'manage_' . $post_type . '_posts_columns', array( $this, 'filter_columns' ) );
        add_action( 'manage_' . $post_type . '_posts_custom_column', array( $this, 'render_column' ), 10, 2 );
        add_filter( 'manage_edit-' . Lisensio_KB_Post_Types::POST_TYPE . '_sortable_columns', array( $this, 'filter_sortable_columns' ) );
        add_action( 'pre_get_posts', array( $this, 'apply_sort' ) );
    }

    /**
     * Declare which knowledge base columns can be sorted.
     *
     * Only Rating is, because it is the only one backed by stored post meta.
     * Views is owned by the analytics tables and Attachment(s) and Reading Time
     * are both derived at render time, so sorting any of the three would mean
     * a query this list table cannot express. Advertising a sort that silently
     * does nothing is worse than not offering it.
     *
     * @since 1.0.0
     *
     * @param array<string, string> $columns The sortable columns.
     *
     * @return array<string, string> The sortable columns, with Rating added.
     */
    public function filter_sortable_columns( $columns ) {
        if ( ! is_array( $columns ) ) {
            return $columns;
        }

        $columns[ self::COLUMN_USEFULNESS ] = self::COLUMN_USEFULNESS;

        return $columns;
    }

    /**
     * Order the list table by usefulness when that column is clicked.
     *
     * An article with no votes has no meta row at all, so a plain meta ordering
     * would drop every unrated article off the list. The clause below keeps
     * them, sorting them as if unrated rather than excluding them.
     *
     * @since 1.0.0
     *
     * @param WP_Query $query The query being prepared.
     *
     * @return void
     */
    public function apply_sort( $query ) {
        if ( ! is_admin() || ! $query instanceof WP_Query || ! $query->is_main_query() ) {
            return;
        }

        if ( self::COLUMN_USEFULNESS !== $query->get( 'orderby' ) ) {
            return;
        }

        $query->set(
            'meta_query',
            array(
                'relation' => 'OR',
                'rated'    => array(
                    'key'     => '_lisensio_usefulness_score',
                    'compare' => 'EXISTS',
                    'type'    => 'NUMERIC',
                ),
                'unrated'  => array(
                    'key'     => '_lisensio_usefulness_score',
                    'compare' => 'NOT EXISTS',
                ),
            )
        );

        $query->set( 'orderby', array( 'rated' => $query->get( 'order' ) ) );
    }

    /**
     * The columns this class adds, keyed by column name.
     *
     * @since 1.0.0
     *
     * @return array<string, string> Column key to its translated heading.
     */
    public function columns() {
        return array(
            self::COLUMN_CATEGORY     => __( 'Article Categories', 'lisensio-knowledge-base' ),
            self::COLUMN_TAG          => __( 'Article Tags', 'lisensio-knowledge-base' ),
            self::COLUMN_ATTACHMENTS  => __( 'Attachment(s)', 'lisensio-knowledge-base' ),
            self::COLUMN_USEFULNESS   => __( 'Rating', 'lisensio-knowledge-base' ),
            self::COLUMN_VIEWS        => __( 'Views', 'lisensio-knowledge-base' ),
            self::COLUMN_READING_TIME => __( 'Reading Time', 'lisensio-knowledge-base' ),
        );
    }

    /**
     * Insert the knowledge base columns into the article list table.
     *
     * They go immediately before the date column, which is where core puts
     * taxonomy columns, so the list table reads title, author, taxonomies,
     * metrics, date. Any column another plugin added is preserved in place.
     *
     * @since 1.0.0
     *
     * @param array<string, string> $columns The existing columns.
     *
     * @return array<string, string> The columns, with the knowledge base ones inserted.
     */
    public function filter_columns( $columns ) {
        if ( ! is_array( $columns ) ) {
            return $columns;
        }

        /*
         * Core adds the author column for a post type that supports `author`,
         * which this one does. Adding it here as well costs nothing and means
         * the column set this class promises does not depend on that detail of
         * WP_Posts_List_Table staying true.
         */
        if ( ! isset( $columns['author'] ) ) {
            $columns['author'] = __( 'Author', 'lisensio-knowledge-base' );
        }

        $mine     = $this->columns();
        $ordered  = array();
        $inserted = false;

        /*
         * Core puts its comment column immediately after author. The knowledge
         * base screen reads better with the article's own facts together and
         * the comment count beside the date, so it is moved to the end rather
         * than left splitting the taxonomies off from the metrics.
         */
        $comments = null;

        if ( isset( $columns['comments'] ) ) {
            $comments = $columns['comments'];
            unset( $columns['comments'] );
        }

        if ( null !== $comments ) {
            $mine['comments'] = $comments;
        }

        foreach ( $columns as $key => $label ) {
            if ( 'date' === $key && ! $inserted ) {
                foreach ( $mine as $mine_key => $mine_label ) {
                    $ordered[ $mine_key ] = $mine_label;
                }

                $inserted = true;
            }

            $ordered[ $key ] = $label;
        }

        if ( ! $inserted ) {
            foreach ( $mine as $mine_key => $mine_label ) {
                $ordered[ $mine_key ] = $mine_label;
            }
        }

        return $ordered;
    }

    /**
     * Render one knowledge base column for one article.
     *
     * @since 1.0.0
     *
     * @param string $column  The column being rendered.
     * @param int    $post_id The article being rendered.
     *
     * @return void
     */
    public function render_column( $column, $post_id ) {
        switch ( $column ) {
            case self::COLUMN_CATEGORY:
                $this->render_terms( (int) $post_id, Lisensio_KB_Post_Types::TAXONOMY_CATEGORY );
                break;

            case self::COLUMN_TAG:
                $this->render_terms( (int) $post_id, Lisensio_KB_Post_Types::TAXONOMY_TAG );
                break;

            case self::COLUMN_VIEWS:
                /**
                 * Filter the value rendered in the article views column.
                 *
                 * Returning an empty string leaves the placeholder in place.
                 * Hooked by the analytics issue (#9), which owns the view data.
                 *
                 * @since 1.0.0
                 *
                 * @param string $value   The value to render. Empty by default.
                 * @param int    $post_id The article being rendered.
                 */
                $this->render_metric( apply_filters( 'lisensio_article_views_column', '', (int) $post_id ) );
                break;

            case self::COLUMN_USEFULNESS:
                /**
                 * Filter the value rendered in the article usefulness column.
                 *
                 * Returning an empty string leaves the placeholder in place.
                 * Hooked by the feedback issue (#8), which owns the vote data.
                 *
                 * @since 1.0.0
                 *
                 * @param string $value   The value to render. Empty by default.
                 * @param int    $post_id The article being rendered.
                 */
                $this->render_metric( apply_filters( 'lisensio_article_usefulness_column', '', (int) $post_id ) );
                break;

            case self::COLUMN_ATTACHMENTS:
                $this->render_attachments( (int) $post_id );
                break;

            case self::COLUMN_READING_TIME:
                $this->render_reading_time( (int) $post_id );
                break;
        }
    }

    /**
     * Render the number of media items attached to an article.
     *
     * Counted rather than stored, because an attachment is re-parented by the
     * media library without this plugin being told.
     *
     * @since 1.0.0
     *
     * @param int $post_id The article.
     *
     * @return void
     */
    private function render_attachments( $post_id ) {
        $children = get_children(
            array(
                'post_parent' => $post_id,
                'post_type'   => 'attachment',
                'fields'      => 'ids',
                'numberposts' => -1,
            )
        );

        $count = is_array( $children ) ? count( $children ) : 0;

        if ( $count < 1 ) {
            echo '<span class="lisensio-kb-column__empty" aria-hidden="true">&mdash;</span>';
            echo '<span class="screen-reader-text">' . esc_html__( 'No attachments', 'lisensio-knowledge-base' ) . '</span>';

            return;
        }

        printf(
            '<span class="lisensio-kb-badge lisensio-kb-badge--neutral"><span class="dashicons dashicons-paperclip" aria-hidden="true"></span>%s</span>',
            esc_html( number_format_i18n( $count ) )
        );
    }

    /**
     * Render the estimated time to read an article.
     *
     * Derived from the word count at read time. Storing it would mean a value
     * that goes stale the moment the article is edited outside this plugin.
     *
     * @since 1.0.0
     *
     * @param int $post_id The article.
     *
     * @return void
     */
    private function render_reading_time( $post_id ) {
        $minutes = self::reading_time( $post_id );

        if ( $minutes < 1 ) {
            echo '<span class="lisensio-kb-column__empty" aria-hidden="true">&mdash;</span>';
            echo '<span class="screen-reader-text">' . esc_html__( 'Nothing to read', 'lisensio-knowledge-base' ) . '</span>';

            return;
        }

        printf(
            /* translators: %s: number of minutes. */
            esc_html( _n( '%s minute', '%s minutes', $minutes, 'lisensio-knowledge-base' ) ),
            esc_html( number_format_i18n( $minutes ) )
        );
    }

    /**
     * The estimated reading time of an article, in whole minutes.
     *
     * Anything with content at all reads as at least a minute, because zero
     * would be indistinguishable from an article with no body.
     *
     * @since 1.0.0
     *
     * @param int|WP_Post $post_id The article, or its id. get_post() takes both.
     *
     * @return int Whole minutes, or 0 when there is no content.
     */
    public static function reading_time( $post_id ) {
        $post = get_post( $post_id );

        if ( ! $post instanceof WP_Post ) {
            return 0;
        }

        $words = str_word_count( wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ) ) );

        if ( $words < 1 ) {
            return 0;
        }

        /**
         * Filter the words-per-minute figure used for the reading estimate.
         *
         * @since 1.0.0
         *
         * @param int $words_per_minute The assumed reading speed.
         * @param int $post_id          The article being estimated.
         */
        $rate = (int) apply_filters( 'lisensio_words_per_minute', self::WORDS_PER_MINUTE, $post_id );

        if ( $rate < 1 ) {
            $rate = self::WORDS_PER_MINUTE;
        }

        return max( 1, (int) ceil( $words / $rate ) );
    }

    /**
     * Render a taxonomy cell as links that filter the list table.
     *
     * @since 1.0.0
     *
     * @param int    $post_id  The article being rendered.
     * @param string $taxonomy The taxonomy to list.
     *
     * @return void
     */
    private function render_terms( $post_id, $taxonomy ) {
        $terms = get_the_terms( $post_id, $taxonomy );

        if ( ! is_array( $terms ) || empty( $terms ) ) {
            echo esc_html( self::PLACEHOLDER );

            return;
        }

        $links = array();

        foreach ( $terms as $term ) {
            $url = add_query_arg(
                array(
                    'post_type' => Lisensio_KB_Post_Types::POST_TYPE,
                    $taxonomy   => $term->slug,
                ),
                'edit.php'
            );

            $links[] = sprintf( '<a href="%1$s">%2$s</a>', esc_url( $url ), esc_html( $term->name ) );
        }

        echo wp_kses_post( implode( ', ', $links ) );
    }

    /**
     * Render a metric cell, falling back to the placeholder.
     *
     * @since 1.0.0
     *
     * @param mixed $value The filtered value.
     *
     * @return void
     */
    private function render_metric( $value ) {
        $value = is_scalar( $value ) ? (string) $value : '';

        echo esc_html( '' === $value ? self::PLACEHOLDER : $value );
    }
}
