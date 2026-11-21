<?php
/**
 * The extension column data of the Categories and Tags apps' rows.
 *
 * Fills each term row's `extensions` from the visible extension columns of
 * its screen. The term apps have no column picker, so a visible column is
 * one the current user may see that is declared `default_visible`; the apps
 * show the same ones.
 *
 * Two filters fill the data, and neither runs when no column is visible:
 *
 * - `licensio_kb_term_extension_data_batch` once per answer, with every term
 *   of the page, so a consumer reads what it needs in one query;
 * - `licensio_kb_term_extension_data` once per term, given that term's part
 *   of the batch answer.
 *
 * Only the requested column keys with an array value are kept, and a cell's
 * `text` is always a string. Each row's `extensions` is a JSON object keyed
 * by column key, `{}` when a term has no data.
 *
 * Consumed by `Licensio_KB_Categories_REST_Controller` and
 * `Licensio_KB_Tags_REST_Controller`.
 *
 * @package Licensio_KB
 * @since   1.1.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Adds the extension column data to term rows.
 *
 * @since 1.1.0
 */
class Licensio_KB_Term_Extension_Data {

    /**
     * The extension registry.
     *
     * @since 1.1.0
     * @var Licensio_KB_Extensions
     */
    private $extensions;

    /**
     * Build the term extension data.
     *
     * @since 1.1.0
     *
     * @param Licensio_KB_Extensions $extensions The extension registry.
     */
    public function __construct( Licensio_KB_Extensions $extensions ) {
        $this->extensions = $extensions;
    }

    /**
     * The keys of the extension columns a term app shows the current user.
     *
     * @since 1.1.0
     *
     * @param string $screen `categories` or `tags`.
     *
     * @return string[] The column keys, in the order they were added.
     */
    public function column_keys( $screen ) {
        $keys = array();

        foreach ( $this->extensions->allowed( $screen, 'columns' ) as $key => $column ) {
            if ( ! empty( $column['default_visible'] ) ) {
                $keys[] = (string) $key;
            }
        }

        return $keys;
    }

    /**
     * Add `extensions` to each row.
     *
     * Rows are left as they are when the screen shows no extension column.
     *
     * @since 1.1.0
     *
     * @param string                           $screen   `categories` or `tags`.
     * @param string                           $taxonomy The rows' taxonomy.
     * @param array<int, array<string, mixed>> $rows     The rows, each with its term `id`.
     *
     * @return array<int, array<string, mixed>> The rows, each with an `extensions` object.
     */
    public function add( $screen, $taxonomy, array $rows ) {
        $keys = $this->column_keys( $screen );

        if ( empty( $keys ) || empty( $rows ) ) {
            return $rows;
        }

        $terms = $this->terms( array_map( 'absint', array_column( $rows, 'id' ) ), $taxonomy );

        /**
         * Fill the extension column data of a page of term rows at once.
         *
         * Runs once per Categories or Tags answer, before
         * `licensio_kb_term_extension_data` runs for each term, and only when
         * the app shows an extension column. Read the data for every term in
         * one query here, then either return it or prime a cache the per-term
         * filter reads.
         *
         * @since 1.1.0
         *
         * @param array<int, array<string, array<string, mixed>>> $data        Term ID to column key to cell data. Default empty.
         * @param array<int, WP_Term>                             $terms       The page's terms, keyed by term ID.
         * @param string[]                                        $column_keys The visible extension column keys.
         */
        $batch = apply_filters( 'licensio_kb_term_extension_data_batch', array(), $terms, $keys );
        $batch = is_array( $batch ) ? $batch : array();

        foreach ( $rows as $index => $row ) {
            $id    = absint( $row['id'] );
            $cells = isset( $batch[ $id ] ) && is_array( $batch[ $id ] ) ? $batch[ $id ] : array();

            if ( isset( $terms[ $id ] ) ) {
                /**
                 * Fill the extension column data of one term row.
                 *
                 * A cell is an array; its `text` is what the app shows when
                 * no script renders the column. Anything else in it reaches
                 * the column's `render()` as `row.extensions[ key ]`.
                 *
                 * @since 1.1.0
                 *
                 * @param array<string, array<string, mixed>> $cells       Column key to cell data, from the batch filter.
                 * @param WP_Term                             $term        The category or tag.
                 * @param string[]                            $column_keys The visible extension column keys.
                 */
                $cells = apply_filters( 'licensio_kb_term_extension_data', $cells, $terms[ $id ], $keys );
            }

            $rows[ $index ]['extensions'] = self::clean( is_array( $cells ) ? $cells : array(), $keys );
        }

        return $rows;
    }

    /**
     * The terms of the rows, loaded with one query.
     *
     * @since 1.1.0
     *
     * @param int[]  $ids      The term IDs.
     * @param string $taxonomy The taxonomy.
     *
     * @return array<int, WP_Term> The terms that exist, keyed by term ID.
     */
    private function terms( array $ids, $taxonomy ) {
        _prime_term_caches( $ids, false );

        $terms = array();

        foreach ( $ids as $id ) {
            $term = get_term( $id, $taxonomy );

            if ( $term instanceof WP_Term ) {
                $terms[ $id ] = $term;
            }
        }

        return $terms;
    }

    /**
     * Keep the requested cells, each an array with a string `text` if any.
     *
     * @since 1.1.0
     *
     * @param array<mixed> $cells The filtered cells.
     * @param string[]     $keys  The visible column keys.
     *
     * @return object Column key to cell data, encoded as a JSON object even when empty.
     */
    private static function clean( array $cells, array $keys ) {
        $clean = array();

        foreach ( $keys as $key ) {
            if ( ! isset( $cells[ $key ] ) || ! is_array( $cells[ $key ] ) ) {
                continue;
            }

            $cell = $cells[ $key ];

            if ( array_key_exists( 'text', $cell ) ) {
                $cell['text'] = is_scalar( $cell['text'] ) ? (string) $cell['text'] : '';
            }

            $clean[ $key ] = $cell;
        }

        return (object) $clean;
    }
}
