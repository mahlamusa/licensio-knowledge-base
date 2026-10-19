<?php
/**
 * Read-only access to the BetterDocs data.
 *
 * BetterDocs keeps articles as `docs` posts, so the post and term reads are
 * the shared ones in `Licensio_KB_Import_Source`. What is BetterDocs' own
 * lives here:
 *
 * - Multiple knowledge bases. BetterDocs Pro groups categories into
 *   `knowledge_base` terms, recorded on each category as a list of KB slugs.
 *   This plugin has one knowledge base, so each KB becomes a top-level
 *   category and its top-level categories become that category's children.
 *   A category in several KBs is filed under the first, and says so.
 * - Article order, which BetterDocs stores per category as a comma-separated
 *   list of post IDs rather than per post.
 * - URLs, which BetterDocs builds from its own settings and a permalink
 *   structure with `%doc_category%` and `%knowledge_base%` placeholders.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads BetterDocs content and analytics in batches.
 *
 * @since 1.0.0
 */
class Licensio_KB_BetterDocs_Import_Source extends Licensio_KB_Import_Source {

    /**
     * The category rows with knowledge bases folded in, once built.
     *
     * @since 1.0.0
     * @var array<int, array<string, mixed>>|null
     */
    private $category_rows = null;

    /**
     * The source driver id.
     *
     * @since 1.0.0
     *
     * @return string The id.
     */
    public function id() {
        return 'betterdocs';
    }

    /**
     * The source's name as the operator knows it.
     *
     * @since 1.0.0
     *
     * @return string The label.
     */
    public function label() {
        return __( 'BetterDocs', 'licensio-knowledge-base' );
    }

    /**
     * The post type BetterDocs keeps its articles in.
     *
     * @since 1.0.0
     *
     * @return string The post type.
     */
    public function post_type() {
        return Licensio_KB_BetterDocs_Import_Schema::POST_TYPE;
    }

    /**
     * BetterDocs' category taxonomy.
     *
     * @since 1.0.0
     *
     * @return string The taxonomy.
     */
    public function category_taxonomy() {
        return Licensio_KB_BetterDocs_Import_Schema::TAXONOMY_CATEGORY;
    }

    /**
     * BetterDocs' tag taxonomy.
     *
     * @since 1.0.0
     *
     * @return string The taxonomy.
     */
    public function tag_taxonomy() {
        return Licensio_KB_BetterDocs_Import_Schema::TAXONOMY_TAG;
    }

    /**
     * The terms the import reads for one taxonomy, knowledge bases folded in.
     *
     * @since 1.0.0
     *
     * @param string $taxonomy The source taxonomy.
     *
     * @return array<int, array<string, mixed>> The term rows, parents first.
     */
    public function import_terms( $taxonomy ) {
        if ( Licensio_KB_BetterDocs_Import_Schema::TAXONOMY_CATEGORY !== $taxonomy ) {
            return $this->terms( $taxonomy );
        }

        if ( null === $this->category_rows ) {
            $this->category_rows = $this->fold_knowledge_bases(
                $this->terms( Licensio_KB_BetterDocs_Import_Schema::TAXONOMY_KNOWLEDGE_BASE ),
                $this->terms( Licensio_KB_BetterDocs_Import_Schema::TAXONOMY_CATEGORY )
            );
        }

        return $this->category_rows;
    }

    /**
     * Put the categories under their knowledge bases.
     *
     * @since 1.0.0
     *
     * @param array<int, array<string, mixed>> $bases      The `knowledge_base` term rows.
     * @param array<int, array<string, mixed>> $categories The `doc_category` term rows.
     *
     * @return array<int, array<string, mixed>> One tree, knowledge bases at the root, parents first.
     */
    public function fold_knowledge_bases( array $bases, array $categories ) {
        if ( empty( $bases ) ) {
            return $categories;
        }

        $by_slug = array();
        $rows    = array();
        $known   = array();

        foreach ( $bases as $base ) {
            $base['item_type'] = 'knowledge_base';
            $base['parent']    = 0;

            $by_slug[ (string) $base['slug'] ] = (int) $base['term_id'];
            $rows[]                            = $base;
        }

        foreach ( $categories as $category ) {
            $known[ (int) $category['term_id'] ] = true;
        }

        foreach ( $categories as $category ) {
            $parent = isset( $category['parent'] ) ? (int) $category['parent'] : 0;

            if ( 0 === $parent || empty( $known[ $parent ] ) ) {
                $slugs   = Licensio_KB_BetterDocs_Import_Map::knowledge_bases( $this->term_meta_value( (int) $category['term_id'], Licensio_KB_BetterDocs_Import_Schema::META_CATEGORY_KNOWLEDGE_BASE ) );
                $matched = array_values( array_intersect( $slugs, array_keys( $by_slug ) ) );

                if ( ! empty( $matched ) ) {
                    $category['parent'] = $by_slug[ $matched[0] ];

                    if ( count( $matched ) > 1 ) {
                        $category['import_note'] = sprintf( 'Belonged to %1$d knowledge bases (%2$s); filed under the first, "%3$s".', count( $matched ), implode( ', ', $matched ), $matched[0] );
                    }
                }
            }

            $rows[] = $category;
        }

        return $this->sort_parents_first( $rows );
    }

    /**
     * Map a BetterDocs category's meta onto this plugin's term meta.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $meta The BetterDocs term meta.
     *
     * @return array{meta: array<string, mixed>, unmapped: string[]} The target meta and the unmapped keys.
     */
    public function map_category_meta( array $meta ) {
        return Licensio_KB_BetterDocs_Import_Map::category_meta( $meta );
    }

    /**
     * Map the stored source settings onto this plugin's.
     *
     * @since 1.0.0
     *
     * @return array{settings: array<string, mixed>, excluded: array<string, string>, unmapped: string[]} The mapping.
     */
    public function map_settings() {
        return Licensio_KB_BetterDocs_Import_Map::settings( Licensio_KB_BetterDocs_Import_Schema::settings() );
    }

    /**
     * The menu order a BetterDocs article should get.
     *
     * The same rule as the Heroic import: the position in the lowest-ID
     * category that lists the article wins, and a disagreement is reported.
     *
     * @since 1.0.0
     *
     * @param int $post_id The BetterDocs post ID.
     *
     * @return array{order: int, ambiguous: bool, source_term_id: int} The kept position.
     */
    public function article_order( $post_id ) {
        $orders = array();

        foreach ( $this->post_terms( $post_id, Licensio_KB_BetterDocs_Import_Schema::TAXONOMY_CATEGORY ) as $term_id ) {
            $orders[ $term_id ] = (string) $this->term_meta_value( $term_id, Licensio_KB_BetterDocs_Import_Schema::META_DOCS_ORDER );
        }

        return Licensio_KB_Heroic_Import_Map::article_order( Licensio_KB_BetterDocs_Import_Map::positions( $post_id, $orders ) );
    }

    /**
     * What becomes of one BetterDocs post meta key, for the log.
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

        if ( Licensio_KB_BetterDocs_Import_Schema::META_VIEWS === $key ) {
            return array(
                'action' => 'derived',
                'target' => 'recounted from the imported views',
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
     * What becomes of one BetterDocs category meta key, for the log.
     *
     * @since 1.0.0
     *
     * @param string $key The term meta key.
     *
     * @return array{action: string, target: string} The action and where it went or why not.
     */
    public function classify_term_meta( $key ) {
        $targets = array(
            Licensio_KB_BetterDocs_Import_Schema::META_CATEGORY_ORDER => Licensio_KB_Term_Meta::META_ORDER,
            Licensio_KB_BetterDocs_Import_Schema::META_KB_ORDER => Licensio_KB_Term_Meta::META_ORDER,
            Licensio_KB_BetterDocs_Import_Schema::META_CATEGORY_IMAGE => Licensio_KB_Term_Meta::META_ICON_ID,
            'doc_category_thumb-id' => Licensio_KB_Term_Meta::META_IMAGE_ID,
            Licensio_KB_BetterDocs_Import_Schema::META_CATEGORY_KNOWLEDGE_BASE => 'the parent category (one per knowledge base)',
            Licensio_KB_BetterDocs_Import_Schema::META_DOCS_ORDER => 'the articles\' menu order',
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
     * Where a BetterDocs article lived.
     *
     * Rebuilt the way BetterDocs builds it (includes/Core/PostType.php:258-299
     * and Rewrite::permalink_structure()): the permalink structure under the
     * docs base, `%doc_category%` replaced by the article's first category by
     * name — the order `wp_get_object_terms()` returns — and `%knowledge_base%`
     * by that category's first knowledge base, or dropped when there is none.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $row The BetterDocs post row.
     *
     * @return string The absolute URL, or ''.
     */
    public function old_article_url( array $row ) {
        $slug = isset( $row['post_name'] ) ? (string) $row['post_name'] : '';

        if ( '' === $slug ) {
            return '';
        }

        $structure = Licensio_KB_BetterDocs_Import_Schema::slug_setting( 'permalink_structure', Licensio_KB_BetterDocs_Import_Schema::DEFAULT_DOCS_SLUG );

        if ( 0 === strpos( $structure, '%' ) ) {
            $structure = $this->docs_base() . '/' . $structure;
        }

        $category = $this->first_category( isset( $row['ID'] ) ? (int) $row['ID'] : 0 );

        if ( false !== strpos( $structure, '%doc_category%' ) ) {
            $structure = str_replace( '%doc_category%', null === $category ? 'uncategorized' : $this->category_path( $category ), $structure );
        }

        if ( false !== strpos( $structure, '%knowledge_base%' ) ) {
            $bases     = null === $category ? array() : Licensio_KB_BetterDocs_Import_Map::knowledge_bases( $this->term_meta_value( (int) $category['term_id'], Licensio_KB_BetterDocs_Import_Schema::META_CATEGORY_KNOWLEDGE_BASE ) );
            $structure = empty( $bases ) ? str_replace( '%knowledge_base%/', '', str_replace( '/%knowledge_base%', '', $structure ) ) : str_replace( '%knowledge_base%', $bases[0], $structure );
        }

        return home_url( '/' . trim( $structure, '/' ) . '/' . $slug . '/' );
    }

    /**
     * Where a BetterDocs category, tag or knowledge base archive lived.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $row The BetterDocs term row.
     *
     * @return string The absolute URL, or ''.
     */
    public function old_term_url( array $row ) {
        $slug = isset( $row['slug'] ) ? (string) $row['slug'] : '';

        if ( '' === $slug ) {
            return '';
        }

        $taxonomy = isset( $row['taxonomy'] ) ? (string) $row['taxonomy'] : '';

        if ( Licensio_KB_BetterDocs_Import_Schema::TAXONOMY_TAG === $taxonomy ) {
            $base = Licensio_KB_BetterDocs_Import_Schema::slug_setting( 'tag_slug', Licensio_KB_BetterDocs_Import_Schema::DEFAULT_TAG_SLUG );
        } elseif ( Licensio_KB_BetterDocs_Import_Schema::TAXONOMY_KNOWLEDGE_BASE === $taxonomy ) {
            // BetterDocs Pro serves a knowledge base at the docs base and its slug.
            $base = $this->docs_base();
        } else {
            $base = Licensio_KB_BetterDocs_Import_Schema::slug_setting( 'category_slug', Licensio_KB_BetterDocs_Import_Schema::DEFAULT_CATEGORY_SLUG );
            $slug = $this->category_path( $row );
        }

        return home_url( '/' . trim( $base, '/' ) . '/' . trim( $slug, '/' ) . '/' );
    }

    /**
     * The docs base slug: the docs page's slug when one is set, else `docs_slug`.
     *
     * @since 1.0.0
     *
     * @return string The base, without slashes.
     */
    private function docs_base() {
        global $wpdb;

        $settings = Licensio_KB_BetterDocs_Import_Schema::settings();
        $base     = Licensio_KB_BetterDocs_Import_Schema::slug_setting( 'docs_slug', Licensio_KB_BetterDocs_Import_Schema::DEFAULT_DOCS_SLUG );

        if ( isset( $settings['builtin_doc_page'] ) && ! $settings['builtin_doc_page'] && ! empty( $settings['docs_page'] ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $page = (string) $wpdb->get_var( $wpdb->prepare( "SELECT post_name FROM {$wpdb->posts} WHERE ID = %d", (int) $settings['docs_page'] ) );

            if ( '' !== $page ) {
                $base = $page;
            }
        }

        return trim( $base, '/' );
    }

    /**
     * An article's first category, by name, as BetterDocs picks it for URLs.
     *
     * Compared case- and accent-insensitively, approximating the `_ci`
     * collation that orders `wp_get_object_terms()`.
     *
     * @since 1.0.0
     *
     * @param int $post_id The BetterDocs post ID.
     *
     * @return array<string, mixed>|null The category row, or null when it has none.
     */
    private function first_category( $post_id ) {
        $ids = $this->post_terms( $post_id, Licensio_KB_BetterDocs_Import_Schema::TAXONOMY_CATEGORY );

        if ( empty( $ids ) ) {
            return null;
        }

        $rows = array();

        foreach ( $this->terms( Licensio_KB_BetterDocs_Import_Schema::TAXONOMY_CATEGORY ) as $row ) {
            if ( in_array( (int) $row['term_id'], $ids, true ) ) {
                $rows[] = $row;
            }
        }

        usort(
            $rows,
            static function ( $a, $b ) {
                return strcasecmp( remove_accents( (string) $a['name'] ), remove_accents( (string) $b['name'] ) );
            }
        );

        return empty( $rows ) ? null : $rows[0];
    }

    /**
     * A category's URL segment: its slug, or its whole parent chain when
     * BetterDocs' hierarchical slugs are on.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $row The category row.
     *
     * @return string The path segment.
     */
    private function category_path( array $row ) {
        if ( ! Licensio_KB_BetterDocs_Import_Schema::hierarchical_slugs() ) {
            return (string) $row['slug'];
        }

        $by_id = array();

        foreach ( $this->terms( Licensio_KB_BetterDocs_Import_Schema::TAXONOMY_CATEGORY ) as $term ) {
            $by_id[ (int) $term['term_id'] ] = $term;
        }

        $path = array( (string) $row['slug'] );
        $seen = array( (int) $row['term_id'] => true );
        $next = isset( $row['parent'] ) ? (int) $row['parent'] : 0;

        while ( $next > 0 && isset( $by_id[ $next ] ) && ! isset( $seen[ $next ] ) ) {
            $seen[ $next ] = true;

            array_unshift( $path, (string) $by_id[ $next ]['slug'] );

            $next = (int) $by_id[ $next ]['parent'];
        }

        return implode( '/', $path );
    }

    /**
     * One term meta value, unserialised.
     *
     * @since 1.0.0
     *
     * @param int    $term_id The term ID.
     * @param string $key     The meta key.
     *
     * @return mixed The value, '' when absent.
     */
    private function term_meta_value( $term_id, $key ) {
        $meta = $this->term_meta( $term_id );

        return isset( $meta[ $key ] ) ? $meta[ $key ] : '';
    }
}
