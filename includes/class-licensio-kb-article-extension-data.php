<?php
/**
 * The extension column data of the Articles list rows.
 *
 * Asks the plugins that declared Articles columns for each row's data, only
 * for the extension columns the user shows, and returns it keyed by row and
 * column. A plugin either fills every row at once on the batch filter, with
 * one query per page, or primes its caches there and fills each row on the
 * per-row filter, which starts from what the batch filter gave that row.
 * Neither filter runs while no extension column is shown.
 *
 * @package Licensio_KB
 * @since   1.1.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Collects the extension column data of a page of articles.
 *
 * @since 1.1.0
 */
class Licensio_KB_Article_Extension_Data {

    /**
     * The extension data of each article.
     *
     * Only the column keys asked for are kept, and only data given as an
     * array, such as `array( 'text' => '72' )`. The app shows `text` when no
     * script renders the column.
     *
     * @since 1.1.0
     *
     * @param WP_Post[] $posts The page of articles.
     * @param string[]  $keys  The extension column keys shown.
     *
     * @return array<int, array<string, array<string, mixed>>> Article ID to column key to data; empty when no column is shown.
     */
    public static function for_posts( array $posts, array $keys ) {
        if ( empty( $posts ) || empty( $keys ) ) {
            return array();
        }

        /**
         * Fill or prepare the extension column data of one page of articles.
         *
         * Runs once per page, before the per-row filter, so a plugin can
         * read the data of every row in one query.
         *
         * @since 1.1.0
         *
         * @param array<int, array<string, array<string, mixed>>> $data  Article ID to column key to data. Default empty.
         * @param WP_Post[]                                       $posts The page of articles.
         * @param string[]                                        $keys  The extension column keys shown.
         */
        $batch = apply_filters( 'licensio_kb_article_extension_data_batch', array(), $posts, $keys );
        $batch = is_array( $batch ) ? $batch : array();
        $data  = array();

        foreach ( $posts as $post ) {
            $id = (int) $post->ID;

            /**
             * Filter the extension column data of one article.
             *
             * @since 1.1.0
             *
             * @param array<string, array<string, mixed>> $row  Column key to data, as the batch filter gave it. Default empty.
             * @param WP_Post                             $post The article.
             * @param string[]                            $keys The extension column keys shown.
             */
            $row = apply_filters( 'licensio_kb_article_extension_data', isset( $batch[ $id ] ) && is_array( $batch[ $id ] ) ? $batch[ $id ] : array(), $post, $keys );

            $data[ $id ] = self::clean( is_array( $row ) ? $row : array(), $keys );
        }

        return $data;
    }

    /**
     * Keep the data of the columns asked for, given as arrays.
     *
     * @since 1.1.0
     *
     * @param array<mixed, mixed> $row  Column key to data, as filtered.
     * @param string[]            $keys The extension column keys shown.
     *
     * @return array<string, array<string, mixed>> Column key to data.
     */
    private static function clean( array $row, array $keys ) {
        $clean = array();

        foreach ( $keys as $key ) {
            if ( isset( $row[ $key ] ) && is_array( $row[ $key ] ) ) {
                $clean[ $key ] = $row[ $key ];
            }
        }

        return $clean;
    }
}
