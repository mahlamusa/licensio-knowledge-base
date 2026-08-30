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
            self::COLUMN_CATEGORY   => __( 'Categories', 'lisensio-knowledge-base' ),
            self::COLUMN_TAG        => __( 'Tags', 'lisensio-knowledge-base' ),
            self::COLUMN_VIEWS      => __( 'Views', 'lisensio-knowledge-base' ),
            self::COLUMN_USEFULNESS => __( 'Usefulness', 'lisensio-knowledge-base' ),
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
        }
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
