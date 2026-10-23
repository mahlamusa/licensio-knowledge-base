<?php
/**
 * Read-only access to the Heroic Knowledge Base data.
 *
 * Heroic keeps articles as `ht_kb` posts, so the post and term reads are the
 * shared ones in `Licensio_KB_Import_Source`. What is Heroic's own lives here:
 * its per-category article order, its category meta mapping, which post meta
 * it writes, and the slugs its URLs were built from.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads Heroic content and analytics in batches.
 *
 * @since 1.0.0
 */
class Licensio_KB_Heroic_Import_Source extends Licensio_KB_Import_Source {

    /**
     * The source driver id.
     *
     * @since 1.0.0
     *
     * @return string The id.
     */
    public function id() {
        return 'heroic';
    }

    /**
     * The source's name as the operator knows it.
     *
     * @since 1.0.0
     *
     * @return string The label.
     */
    public function label() {
        return __( 'Heroic Knowledge Base', 'licensio-knowledge-base' );
    }

    /**
     * The post type Heroic keeps its articles in.
     *
     * @since 1.0.0
     *
     * @return string The post type.
     */
    public function post_type() {
        return Licensio_KB_Heroic_Import_Schema::POST_TYPE;
    }

    /**
     * Heroic's category taxonomy.
     *
     * @since 1.0.0
     *
     * @return string The taxonomy.
     */
    public function category_taxonomy() {
        return Licensio_KB_Heroic_Import_Schema::TAXONOMY_CATEGORY;
    }

    /**
     * Heroic's tag taxonomy.
     *
     * @since 1.0.0
     *
     * @return string The taxonomy.
     */
    public function tag_taxonomy() {
        return Licensio_KB_Heroic_Import_Schema::TAXONOMY_TAG;
    }

    /**
     * Map a Heroic category's meta onto this plugin's term meta.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $meta The Heroic term meta.
     *
     * @return array{meta: array<string, mixed>, unmapped: string[]} The target meta and the unmapped keys.
     */
    public function map_category_meta( array $meta ) {
        return Licensio_KB_Heroic_Import_Map::category_meta( $meta );
    }

    /**
     * Map the stored source settings onto this plugin's.
     *
     * @since 1.0.0
     *
     * @return array{settings: array<string, mixed>, excluded: array<string, string>, unmapped: string[]} The mapping.
     */
    public function map_settings() {
        return Licensio_KB_Heroic_Import_Map::settings( Licensio_KB_Heroic_Import_Schema::settings() );
    }

    /**
     * The per-category positions of a Heroic article, and its fallback menu order.
     *
     * @since 1.0.0
     *
     * @param int $post_id The Heroic post ID.
     *
     * @return array{order: int, ambiguous: bool, source_term_id: int, positions: array<int, int>} The
     *               fallback position, and every per-category position keyed by source term ID.
     */
    public function article_order( $post_id ) {
        return Licensio_KB_Heroic_Import_Map::article_order( $this->article_orders( $post_id ) );
    }

    /**
     * What becomes of one Heroic post meta key, for the log.
     *
     * @since 1.0.0
     *
     * @param string $key The meta key.
     *
     * @return array{action: string, target: string} The action and where it went or why not.
     */
    public function classify_post_meta( $key ) {
        $key = (string) $key;

        if ( '_thumbnail_id' === $key ) {
            return array(
                'action' => 'migrated',
                'target' => '_thumbnail_id (the same attachment)',
            );
        }

        if ( 0 === strpos( $key, Licensio_KB_Heroic_Import_Schema::META_ARTICLE_ORDER_PREFIX ) ) {
            return array(
                'action' => 'migrated',
                'target' => 'licensio_order_{term} (per category)',
            );
        }

        if ( Licensio_KB_Heroic_Import_Schema::META_VIEW_COUNT === $key ) {
            return array(
                'action' => 'derived',
                'target' => 'recounted from the imported views',
            );
        }

        if ( Licensio_KB_Heroic_Import_Schema::META_USEFULNESS === $key ) {
            return array(
                'action' => 'derived',
                'target' => 'recomputed from the imported votes',
            );
        }

        if ( in_array( $key, self::CORE_POST_META, true ) ) {
            return array(
                'action' => 'skipped',
                'target' => 'WordPress bookkeeping',
            );
        }

        return array(
            'action' => 'not_migrated',
            'target' => 'no equivalent field; not copied',
        );
    }

    /**
     * What becomes of one Heroic category meta key, for the log.
     *
     * @since 1.0.0
     *
     * @param string $key The term meta key.
     *
     * @return array{action: string, target: string} The action and where it went or why not.
     */
    public function classify_term_meta( $key ) {
        $targets = array(
            Licensio_KB_Heroic_Import_Schema::META_TERM_ORDER => Licensio_KB_Term_Meta::META_ORDER,
            Licensio_KB_Heroic_Import_Schema::META_TERM_IMAGE => Licensio_KB_Term_Meta::META_IMAGE_ID,
            Licensio_KB_Heroic_Import_Schema::META_TERM_SVG   => Licensio_KB_Term_Meta::META_ICON_SVG . ' (sanitised)',
        );

        if ( isset( $targets[ (string) $key ] ) ) {
            return array(
                'action' => 'migrated',
                'target' => $targets[ (string) $key ],
            );
        }

        return array(
            'action' => 'not_migrated',
            'target' => 'no equivalent field; not copied',
        );
    }

    /**
     * Where a Heroic article lived.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $row The Heroic post row.
     *
     * @return string The absolute URL, or ''.
     */
    public function old_article_url( array $row ) {
        $slug = isset( $row['post_name'] ) ? (string) $row['post_name'] : '';

        return '' === $slug ? '' : self::url( Licensio_KB_Heroic_Import_Schema::article_slug(), $slug );
    }

    /**
     * Where a Heroic category or tag archive lived.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $row The Heroic term row.
     *
     * @return string The absolute URL, or ''.
     */
    public function old_term_url( array $row ) {
        $slug = isset( $row['slug'] ) ? (string) $row['slug'] : '';

        if ( '' === $slug ) {
            return '';
        }

        $base = isset( $row['taxonomy'] ) && Licensio_KB_Heroic_Import_Schema::TAXONOMY_TAG === $row['taxonomy']
            ? Licensio_KB_Heroic_Import_Schema::tag_slug()
            : Licensio_KB_Heroic_Import_Schema::category_slug();

        return self::url( $base, $slug );
    }

    /**
     * The per-category positions Heroic recorded for one article.
     *
     * @since 1.0.0
     *
     * @param int $post_id The Heroic post ID.
     *
     * @return array<int, string> Heroic term ID to the stored position.
     */
    public function article_orders( $post_id ) {
        global $wpdb;

        $prefix = Licensio_KB_Heroic_Import_Schema::META_ARTICLE_ORDER_PREFIX;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT meta_key, meta_value FROM {$wpdb->postmeta}
                 WHERE post_id = %d AND meta_key LIKE %s",
                (int) $post_id,
                $wpdb->esc_like( $prefix ) . '%'
            ),
            ARRAY_A
        );

        $orders = array();

        foreach ( (array) $rows as $row ) {
            $key     = isset( $row['meta_key'] ) ? (string) $row['meta_key'] : '';
            $term_id = (int) substr( $key, strlen( $prefix ) );

            if ( $term_id > 0 ) {
                $orders[ $term_id ] = isset( $row['meta_value'] ) ? (string) $row['meta_value'] : '';
            }
        }

        return $orders;
    }


    /**
     * An absolute URL under a base slug.
     *
     * @since 1.0.0
     *
     * @param string $base The base slug.
     * @param string $slug The item slug.
     *
     * @return string The URL, with a trailing slash.
     */
    private static function url( $base, $slug ) {
        return home_url( '/' . trim( (string) $base, '/' ) . '/' . trim( (string) $slug, '/' ) . '/' );
    }
}
