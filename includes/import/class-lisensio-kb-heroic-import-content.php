<?php
/**
 * Moves Heroic terms and articles into the plugin's content model.
 *
 * Idempotency here does not depend on the resume cursor. Every term and every
 * article the migrator creates carries the ID it came from, in term meta and
 * post meta respectively, and both phases look that up before creating
 * anything. So a second run finds the existing target and skips it, and it does
 * so even if the state option has been wiped, the batch size changed, or the
 * previous run died between the insert and the cursor write. The cursor is a
 * speed optimisation for content; for the analytics tables it is the whole
 * mechanism, which is why the two are documented separately.
 *
 * Nothing here writes to a `lisensio_*` table. Articles and terms are core
 * objects, so they go through `wp_insert_post()` and `wp_insert_term()` and get
 * core's sanitisation, slug uniqueness and cache invalidation rather than a
 * hand-rolled INSERT.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Migrates `ht_kb` posts and both Heroic taxonomies.
 *
 * @since 1.0.0
 */
class Lisensio_KB_Heroic_Import_Content {

    /**
     * Post meta recording which Heroic article a target article came from.
     *
     * @since 1.0.0
     * @var string
     */
    const META_SOURCE_POST = '_lisensio_heroic_post_id';

    /**
     * Term meta recording which Heroic term a target term came from.
     *
     * @since 1.0.0
     * @var string
     */
    const META_SOURCE_TERM = '_lisensio_heroic_term_id';

    /**
     * The batched reader over the Heroic data.
     *
     * @since 1.0.0
     * @var Lisensio_KB_Heroic_Import_Source
     */
    private $source;

    /**
     * The resume point.
     *
     * @since 1.0.0
     * @var Lisensio_KB_Heroic_Import_State
     */
    private $state;

    /**
     * The URL map being accumulated.
     *
     * @since 1.0.0
     * @var Lisensio_KB_Heroic_Import_Redirects
     */
    private $redirects;

    /**
     * Where counts and lossy mappings are recorded.
     *
     * @since 1.0.0
     * @var Lisensio_KB_Heroic_Import_Report
     */
    private $report;

    /**
     * Whether this run may write.
     *
     * @since 1.0.0
     * @var bool
     */
    private $dry_run = false;

    /**
     * Constructor.
     *
     * @since 1.0.0
     *
     * @param Lisensio_KB_Heroic_Import_Source    $source    The batched reader.
     * @param Lisensio_KB_Heroic_Import_State     $state     The resume point.
     * @param Lisensio_KB_Heroic_Import_Redirects $redirects The URL map.
     * @param Lisensio_KB_Heroic_Import_Report    $report    The run report.
     * @param bool                                $dry_run   Whether to suppress every write.
     */
    public function __construct(
        Lisensio_KB_Heroic_Import_Source $source,
        Lisensio_KB_Heroic_Import_State $state,
        Lisensio_KB_Heroic_Import_Redirects $redirects,
        Lisensio_KB_Heroic_Import_Report $report,
        $dry_run = false
    ) {
        $this->source    = $source;
        $this->state     = $state;
        $this->redirects = $redirects;
        $this->report    = $report;
        $this->dry_run   = (bool) $dry_run;
    }

    /**
     * Migrate both Heroic taxonomies, preserving hierarchy.
     *
     * Categories first, then tags, and within a taxonomy parents before
     * children — a child term cannot name a parent that does not exist yet.
     *
     * @since 1.0.0
     *
     * @param callable|null $progress Called with the running total after each term.
     *
     * @return void
     */
    public function migrate_terms( $progress = null ) {
        $pairs = array(
            Lisensio_KB_Heroic_Import_Schema::TAXONOMY_CATEGORY => Lisensio_KB_Post_Types::TAXONOMY_CATEGORY,
            Lisensio_KB_Heroic_Import_Schema::TAXONOMY_TAG => Lisensio_KB_Post_Types::TAXONOMY_TAG,
        );

        $done = 0;

        foreach ( $pairs as $from => $to ) {
            foreach ( $this->source->terms( $from ) as $row ) {
                $this->migrate_term( $row, $from, $to );

                ++$done;

                if ( is_callable( $progress ) ) {
                    call_user_func( $progress, $done );
                }
            }
        }

        $this->state->complete( 'terms' );
    }

    /**
     * Migrate one Heroic term.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $row      The Heroic term row.
     * @param string               $from     The Heroic taxonomy.
     * @param string               $to       The target taxonomy.
     *
     * @return int The target term ID, or 0 when nothing was created.
     */
    private function migrate_term( array $row, $from, $to ) {
        $source_id = isset( $row['term_id'] ) ? (int) $row['term_id'] : 0;

        if ( $source_id <= 0 ) {
            return 0;
        }

        $existing = $this->find_term( $to, $source_id );

        if ( $existing > 0 ) {
            $this->report->count( 'terms_skipped' );

            $this->redirects->add_term( $from, $row, $to, $existing );

            return $existing;
        }

        $slug   = isset( $row['slug'] ) ? (string) $row['slug'] : '';
        $parent = isset( $row['parent'] ) ? (int) $row['parent'] : 0;

        /*
         * The parent is a Heroic term ID and has to be translated. It is
         * guaranteed to be migrated already because the source returns parents
         * first; a lookup that comes back empty therefore means the parent was
         * not in this taxonomy, and the term becomes a root rather than being
         * attached to term 0 of some other tree.
         */
        $target_parent = $parent > 0 ? $this->find_term( $to, $parent ) : 0;

        if ( $this->dry_run ) {
            $this->report->count( 'terms_created' );

            return 0;
        }

        $result = wp_insert_term(
            isset( $row['name'] ) ? (string) $row['name'] : $slug,
            $to,
            array(
                'slug'        => $slug,
                'description' => isset( $row['description'] ) ? (string) $row['description'] : '',
                'parent'      => $target_parent,
            )
        );

        if ( is_wp_error( $result ) ) {
            $term_id = $this->recover_existing_term( $result, $to );

            if ( $term_id <= 0 ) {
                $this->report->count( 'terms_failed' );
                $this->report->note(
                    'terms',
                    sprintf( 'Heroic term %1$d (%2$s) could not be created: %3$s', $source_id, $slug, $result->get_error_message() )
                );

                return 0;
            }

            $this->report->count( 'terms_adopted' );
        } else {
            $term_id = isset( $result['term_id'] ) ? (int) $result['term_id'] : 0;

            $this->report->count( 'terms_created' );
        }

        if ( $term_id <= 0 ) {
            return 0;
        }

        update_term_meta( $term_id, self::META_SOURCE_TERM, $source_id );

        $this->migrate_term_meta( $source_id, $term_id, $from );

        $this->report_slug_change( 'term', $source_id, $slug, (string) get_term_field( 'slug', $term_id, $to ) );

        $this->redirects->add_term( $from, $row, $to, $term_id );

        return $term_id;
    }

    /**
     * Carry a category's presentation meta across, reporting what cannot cross.
     *
     * Only categories have this meta; tags carry none.
     *
     * @since 1.0.0
     *
     * @param int    $source_id The Heroic term ID.
     * @param int    $term_id   The target term ID.
     * @param string $from      The Heroic taxonomy.
     *
     * @return void
     */
    private function migrate_term_meta( $source_id, $term_id, $from ) {
        if ( Lisensio_KB_Heroic_Import_Schema::TAXONOMY_CATEGORY !== $from ) {
            return;
        }

        $mapped = Lisensio_KB_Heroic_Import_Map::category_meta( $this->source->term_meta( $source_id ) );

        foreach ( $mapped['meta'] as $key => $value ) {
            update_term_meta( $term_id, $key, $value );

            $this->report->count( 'term_meta_migrated' );
        }

        foreach ( $mapped['unmapped'] as $key ) {
            $this->report->count( 'term_meta_unmapped' );
            $this->report->note(
                'unmapped_term_meta',
                sprintf( 'Heroic term %1$d has %2$s, which has no field in the target content model.', $source_id, $key )
            );
        }
    }

    /**
     * Migrate one batch of articles.
     *
     * @since 1.0.0
     *
     * @param int $batch How many articles to process.
     *
     * @return int How many source rows this batch read. Zero means the phase is done.
     */
    public function migrate_articles_batch( $batch ) {
        $after = $this->state->cursor( 'articles' );
        $rows  = $this->source->articles( $after, $batch );

        if ( empty( $rows ) ) {
            $this->state->complete( 'articles' );

            return 0;
        }

        foreach ( $rows as $row ) {
            $this->migrate_article( $row );

            $this->state->set_cursor( 'articles', isset( $row['ID'] ) ? (int) $row['ID'] : 0 );
        }

        return count( $rows );
    }

    /**
     * Migrate one Heroic article.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $row The Heroic post row.
     *
     * @return int The target article ID, or 0 when nothing was created.
     */
    private function migrate_article( array $row ) {
        $source_id = isset( $row['ID'] ) ? (int) $row['ID'] : 0;

        if ( $source_id <= 0 ) {
            return 0;
        }

        $existing = $this->find_article( $source_id );

        if ( $existing > 0 ) {
            $this->report->count( 'articles_skipped' );

            $this->redirects->add_article( $row, $existing );

            return $existing;
        }

        $order = Lisensio_KB_Heroic_Import_Map::article_order( $this->source->article_orders( $source_id ) );

        if ( $order['ambiguous'] ) {
            $this->report->count( 'articles_order_collapsed' );
            $this->report->note(
                'article_order',
                sprintf(
                    'Heroic article %1$d had different positions in different categories; kept %2$d from category %3$d.',
                    $source_id,
                    $order['order'],
                    $order['source_term_id']
                )
            );
        }

        if ( $this->dry_run ) {
            $this->report->count( 'articles_created' );

            return 0;
        }

        $slug = isset( $row['post_name'] ) ? (string) $row['post_name'] : '';

        $article_id = wp_insert_post(
            array(
                'post_type'         => Lisensio_KB_Post_Types::POST_TYPE,
                'post_title'        => isset( $row['post_title'] ) ? (string) $row['post_title'] : '',
                'post_content'      => isset( $row['post_content'] ) ? (string) $row['post_content'] : '',
                'post_excerpt'      => isset( $row['post_excerpt'] ) ? (string) $row['post_excerpt'] : '',
                'post_status'       => isset( $row['post_status'] ) ? (string) $row['post_status'] : 'publish',
                'post_author'       => isset( $row['post_author'] ) ? (int) $row['post_author'] : 0,
                'post_name'         => $slug,
                'post_date'         => isset( $row['post_date'] ) ? (string) $row['post_date'] : '',
                'post_date_gmt'     => isset( $row['post_date_gmt'] ) ? (string) $row['post_date_gmt'] : '',
                'post_modified'     => isset( $row['post_modified'] ) ? (string) $row['post_modified'] : '',
                'post_modified_gmt' => isset( $row['post_modified_gmt'] ) ? (string) $row['post_modified_gmt'] : '',
                'comment_status'    => isset( $row['comment_status'] ) ? (string) $row['comment_status'] : 'closed',
                'ping_status'       => isset( $row['ping_status'] ) ? (string) $row['ping_status'] : 'closed',
                'menu_order'        => (int) $order['order'],
            ),
            true
        );

        if ( is_wp_error( $article_id ) || (int) $article_id <= 0 ) {
            $this->report->count( 'articles_failed' );
            $this->report->note(
                'articles',
                sprintf(
                    'Heroic article %1$d could not be created: %2$s',
                    $source_id,
                    is_wp_error( $article_id ) ? $article_id->get_error_message() : 'unknown error'
                )
            );

            return 0;
        }

        $article_id = (int) $article_id;

        update_post_meta( $article_id, self::META_SOURCE_POST, $source_id );

        $this->assign_terms( $source_id, $article_id );
        $this->migrate_thumbnail( $source_id, $article_id );
        $this->reparent_attachments( $source_id, $article_id );

        $this->report->count( 'articles_created' );

        $this->report_slug_change( 'article', $source_id, $slug, (string) get_post_field( 'post_name', $article_id ) );

        $this->redirects->add_article( $row, $article_id );

        return $article_id;
    }

    /**
     * File a migrated article under the migrated equivalents of its Heroic terms.
     *
     * @since 1.0.0
     *
     * @param int $source_id  The Heroic post ID.
     * @param int $article_id The target article ID.
     *
     * @return void
     */
    private function assign_terms( $source_id, $article_id ) {
        $pairs = array(
            Lisensio_KB_Heroic_Import_Schema::TAXONOMY_CATEGORY => Lisensio_KB_Post_Types::TAXONOMY_CATEGORY,
            Lisensio_KB_Heroic_Import_Schema::TAXONOMY_TAG => Lisensio_KB_Post_Types::TAXONOMY_TAG,
        );

        foreach ( $pairs as $from => $to ) {
            $target_ids = array();

            foreach ( $this->source->post_terms( $source_id, $from ) as $heroic_term_id ) {
                $target = $this->find_term( $to, $heroic_term_id );

                if ( $target > 0 ) {
                    $target_ids[] = $target;
                } else {
                    $this->report->count( 'article_terms_missing' );
                    $this->report->note(
                        'article_terms',
                        sprintf( 'Heroic article %1$d referenced %2$s term %3$d, which was not migrated.', $source_id, $from, $heroic_term_id )
                    );
                }
            }

            if ( ! empty( $target_ids ) ) {
                wp_set_object_terms( $article_id, $target_ids, $to, false );
            }
        }
    }

    /**
     * Carry the featured image across.
     *
     * The attachment itself is not copied. It is a `wp_posts` row that both the
     * old and the new article can point at, and duplicating it would duplicate
     * the file in the uploads directory for no benefit.
     *
     * @since 1.0.0
     *
     * @param int $source_id  The Heroic post ID.
     * @param int $article_id The target article ID.
     *
     * @return void
     */
    private function migrate_thumbnail( $source_id, $article_id ) {
        $thumbnail = (int) get_post_meta( $source_id, '_thumbnail_id', true );

        if ( $thumbnail <= 0 ) {
            return;
        }

        update_post_meta( $article_id, '_thumbnail_id', $thumbnail );

        $this->report->count( 'thumbnails_migrated' );
    }

    /**
     * Re-parent an article's uploaded attachments onto the new article.
     *
     * The Heroic posts are deleted once the migration is signed off, and an
     * attachment whose parent has gone drops out of the "Uploaded to this post"
     * media filter and out of any code that walks a post's attachments. Moving
     * the parent pointer keeps that association intact. The file itself does not
     * move and the attachment URL does not change.
     *
     * @since 1.0.0
     *
     * @param int $source_id  The Heroic post ID.
     * @param int $article_id The target article ID.
     *
     * @return void
     */
    private function reparent_attachments( $source_id, $article_id ) {
        foreach ( $this->source->attachments( $source_id ) as $attachment_id ) {
            wp_update_post(
                array(
                    'ID'          => $attachment_id,
                    'post_parent' => $article_id,
                )
            );

            $this->report->count( 'attachments_reparented' );
        }
    }

    /**
     * Note it when the target had to take a different slug from the source.
     *
     * Core silently uniquifies a colliding slug, which would move a URL without
     * telling anybody. Comparing what was asked for against what was stored is
     * the only way to catch every case, including the ones this tool did not
     * predict — a reserved slug, a conflicting page, a taxonomy clash.
     *
     * @since 1.0.0
     *
     * @param string $kind      `article` or `term`.
     * @param int    $source_id The Heroic ID.
     * @param string $requested The slug the source had.
     * @param string $actual    The slug the target got.
     *
     * @return void
     */
    private function report_slug_change( $kind, $source_id, $requested, $actual ) {
        if ( '' === $requested || $requested === $actual ) {
            return;
        }

        $this->report->count( 'slug_collisions' );
        $this->report->note(
            'slug_collisions',
            sprintf( 'Heroic %1$s %2$d wanted slug "%3$s" and got "%4$s".', $kind, $source_id, $requested, $actual )
        );
    }

    /**
     * The target term migrated from a given Heroic term, if there is one.
     *
     * @since 1.0.0
     *
     * @param string $taxonomy  The target taxonomy.
     * @param int    $source_id The Heroic term ID.
     *
     * @return int The target term ID, or 0.
     */
    public function find_term( $taxonomy, $source_id ) {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $term_id = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT tm.term_id
                 FROM {$wpdb->termmeta} AS tm
                 INNER JOIN {$wpdb->term_taxonomy} AS tt ON tt.term_id = tm.term_id
                 WHERE tm.meta_key = %s AND tm.meta_value = %s AND tt.taxonomy = %s
                 LIMIT 1",
                self::META_SOURCE_TERM,
                (string) (int) $source_id,
                (string) $taxonomy
            )
        );

        return $term_id;
    }

    /**
     * The target article migrated from a given Heroic article, if there is one.
     *
     * @since 1.0.0
     *
     * @param int $source_id The Heroic post ID.
     *
     * @return int The target article ID, or 0.
     */
    public function find_article( $source_id ) {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $post_id = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT pm.post_id
                 FROM {$wpdb->postmeta} AS pm
                 INNER JOIN {$wpdb->posts} AS p ON p.ID = pm.post_id
                 WHERE pm.meta_key = %s AND pm.meta_value = %s AND p.post_type = %s
                 LIMIT 1",
                self::META_SOURCE_POST,
                (string) (int) $source_id,
                Lisensio_KB_Post_Types::POST_TYPE
            )
        );

        return $post_id;
    }

    /**
     * Adopt an existing term when `wp_insert_term()` reports it already exists.
     *
     * This happens when the operator created the target taxonomy's terms by
     * hand before running the migrator. Adopting is better than failing: the
     * term the site is already using keeps its ID, and it gains the provenance
     * meta so the articles get filed under it.
     *
     * @since 1.0.0
     *
     * @param WP_Error $error    The error `wp_insert_term()` returned.
     * @param string   $taxonomy The target taxonomy.
     *
     * @return int The existing term ID, or 0 when the error was something else.
     */
    private function recover_existing_term( $error, $taxonomy ) {
        unset( $taxonomy );

        if ( 'term_exists' !== $error->get_error_code() ) {
            return 0;
        }

        $data = $error->get_error_data();

        if ( is_array( $data ) && isset( $data['term_id'] ) ) {
            return (int) $data['term_id'];
        }

        return is_numeric( $data ) ? (int) $data : 0;
    }
}
