<?php
/**
 * Moves a source's terms and articles into the plugin's content model.
 *
 * Idempotency here does not depend on the resume cursor. Every term and every
 * article the importer creates carries the ID it came from, in term meta and
 * post meta respectively, and both phases look that up before creating
 * anything. So a second run finds the existing target and skips it, and it does
 * so even if the state option has been wiped, the batch size changed, or the
 * previous run died between the insert and the cursor write. The cursor is a
 * speed optimisation for content; for the analytics tables it is the whole
 * mechanism, which is why the two are documented separately.
 *
 * Content the site already owns is adopted, not duplicated: a term that exists
 * under the source name is taken over by `wp_insert_term()`'s own error path,
 * a category the operator mapped onto an existing one is merged into it, and an
 * article already living under the source slug — with no provenance from any
 * importer — is updated in place and gains the provenance meta. A slug held by
 * an article imported from a different source article is a collision, not an
 * adoption, and is uniquified and reported.
 *
 * Every term and article gets one row in the import log: what it was, where it
 * went, which categories it was filed under, every source meta key and what
 * became of it, and — for anything that failed — why.
 *
 * Nothing here writes to the source. Attachments stay attached to the source
 * post; the imported article points at the same attachment IDs and URLs.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Imports one source's articles and both of its taxonomies.
 *
 * @since 1.0.0
 */
class Licensio_KB_Import_Content {

    /**
     * The batched reader over the source data.
     *
     * @since 1.0.0
     * @var Licensio_KB_Import_Source
     */
    private $source;

    /**
     * The resume point.
     *
     * @since 1.0.0
     * @var Licensio_KB_Import_State
     */
    private $state;

    /**
     * The URL map being accumulated.
     *
     * @since 1.0.0
     * @var Licensio_KB_Import_Redirects
     */
    private $redirects;

    /**
     * Where counts and lossy mappings are recorded.
     *
     * @since 1.0.0
     * @var Licensio_KB_Import_Report
     */
    private $report;

    /**
     * Where every item's outcome is recorded.
     *
     * @since 1.0.0
     * @var Licensio_KB_Import_Log
     */
    private $log;

    /**
     * Whether this run may write.
     *
     * @since 1.0.0
     * @var bool
     */
    private $dry_run = false;

    /**
     * Every provenance post meta key, from every source.
     *
     * An article carrying any of them belongs to an import and may not be
     * adopted by another one.
     *
     * @since 1.0.0
     * @var string[]
     */
    private $claim_keys = array();

    /**
     * Per target category, the offset each source category's positions get.
     *
     * Filled on first use in a request; see `position_offsets()`.
     *
     * @since 1.0.0
     * @var array<int, array<int, int>>
     */
    private $position_offsets = array();

    /**
     * Constructor.
     *
     * @since 1.0.0
     *
     * @param Licensio_KB_Import_Source    $source     The batched reader.
     * @param Licensio_KB_Import_State     $state      The resume point.
     * @param Licensio_KB_Import_Redirects $redirects  The URL map.
     * @param Licensio_KB_Import_Report    $report     The run report.
     * @param bool                         $dry_run    Whether to suppress every write.
     * @param Licensio_KB_Import_Log|null  $log        The import log; none when null.
     * @param string[]                     $claim_keys Every source's provenance post meta key; this source's when empty.
     */
    public function __construct(
        Licensio_KB_Import_Source $source,
        Licensio_KB_Import_State $state,
        Licensio_KB_Import_Redirects $redirects,
        Licensio_KB_Import_Report $report,
        $dry_run = false,
        $log = null,
        array $claim_keys = array()
    ) {
        $this->source     = $source;
        $this->state      = $state;
        $this->redirects  = $redirects;
        $this->report     = $report;
        $this->dry_run    = (bool) $dry_run;
        $this->log        = $log instanceof Licensio_KB_Import_Log ? $log : Licensio_KB_Import_Log::disabled();
        $this->claim_keys = empty( $claim_keys ) ? array( $source->provenance_post_key() ) : array_values( array_unique( $claim_keys ) );
    }

    /**
     * Import both source taxonomies, preserving hierarchy.
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
        $done = 0;

        foreach ( $this->source->taxonomy_pairs() as $from => $to ) {
            foreach ( $this->source->import_terms( $from ) as $row ) {
                if ( empty( $row['taxonomy'] ) ) {
                    $row['taxonomy'] = $from;
                }

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
     * Import one source term.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $row  The source term row.
     * @param string               $from The source taxonomy the row was read for.
     * @param string               $to   The target taxonomy.
     *
     * @return int The target term ID, or 0 when nothing was created.
     */
    private function migrate_term( array $row, $from, $to ) {
        $source_id = isset( $row['term_id'] ) ? (int) $row['term_id'] : 0;
        $name      = isset( $row['name'] ) ? (string) $row['name'] : '';
        $type      = $this->term_type( $row, $from );

        if ( $source_id <= 0 ) {
            return 0;
        }

        $label   = $this->source->label();
        $old_url = $this->source->old_term_url( $row );
        $slug    = isset( $row['slug'] ) ? (string) $row['slug'] : '';
        $meta    = Licensio_KB_Post_Types::TAXONOMY_CATEGORY === $to ? $this->source->term_meta( $source_id ) : array();

        $existing = $this->find_term( $to, $source_id );

        if ( $existing > 0 ) {
            $this->report->count( 'terms_skipped' );

            $redirect = $this->redirects->add_term( $type, $source_id, $existing, $to, $old_url, $slug );

            $this->log->entry(
                'terms',
                $type,
                $source_id,
                $existing,
                $name,
                Licensio_KB_Import_Log::STATUS_SKIPPED,
                sprintf( 'Already imported by an earlier run as term %d; left unchanged.', $existing ),
                array( 'redirect' => $redirect )
            );

            return $existing;
        }

        $parent = isset( $row['parent'] ) ? (int) $row['parent'] : 0;

        /*
         * The parent is a source term ID and has to be translated. It is
         * guaranteed to be imported already because the source returns parents
         * first; a lookup that comes back empty therefore means the parent was
         * not in this taxonomy, and the term becomes a root rather than being
         * attached to term 0 of some other tree.
         */
        $target_parent = $parent > 0 ? $this->find_term( $to, $parent ) : 0;

        $details = array(
            'slug'   => array( 'requested' => $slug ),
            'parent' => array(
                'source_id' => $parent,
                'target_id' => $target_parent,
            ),
            'meta'   => $this->describe_term_meta( $meta ),
        );

        $mapped_to = Licensio_KB_Post_Types::TAXONOMY_CATEGORY === $to ? $this->mapped_category( $source_id ) : 0;

        if ( $mapped_to > 0 ) {
            return $this->merge_term( $row, $type, $to, $mapped_to, $details, $old_url );
        }

        $note = isset( $row['import_note'] ) ? (string) $row['import_note'] : '';

        if ( $this->dry_run ) {
            $this->report->count( 'terms_created' );

            $this->log->entry( 'terms', $type, $source_id, 0, $name, '' !== $note ? Licensio_KB_Import_Log::STATUS_WARNING : Licensio_KB_Import_Log::STATUS_CREATED, $note, $details );

            return 0;
        }

        $result = wp_insert_term(
            '' !== $name ? $name : $slug,
            $to,
            array(
                'slug'        => $slug,
                'description' => isset( $row['description'] ) ? (string) $row['description'] : '',
                'parent'      => $target_parent,
            )
        );

        $status = Licensio_KB_Import_Log::STATUS_CREATED;

        if ( is_wp_error( $result ) ) {
            $term_id = $this->recover_existing_term( $result, $to );

            if ( $term_id <= 0 ) {
                $this->report->count( 'terms_failed' );
                $this->report->note(
                    'terms',
                    sprintf( '%1$s term %2$d (%3$s) could not be created: %4$s', $label, $source_id, $slug, $result->get_error_message() )
                );

                $this->log->entry(
                    'terms',
                    $type,
                    $source_id,
                    0,
                    $name,
                    Licensio_KB_Import_Log::STATUS_FAILED,
                    sprintf( 'Could not create the %1$s: %2$s', str_replace( '_', ' ', $type ), $result->get_error_message() ),
                    $details
                );

                return 0;
            }

            $this->report->count( 'terms_adopted' );

            $status = Licensio_KB_Import_Log::STATUS_ADOPTED;
        } else {
            $term_id = isset( $result['term_id'] ) ? (int) $result['term_id'] : 0;

            $this->report->count( 'terms_created' );
        }

        if ( $term_id <= 0 ) {
            return 0;
        }

        $this->add_term_provenance( $term_id, $source_id );

        $this->migrate_term_meta( $source_id, $term_id, $meta );

        $actual = (string) get_term_field( 'slug', $term_id, $to );

        $details['slug']['actual'] = $actual;
        $details['redirect']       = $this->redirects->add_term( $type, $source_id, $term_id, $to, $old_url, $slug );

        $message = trim( $note . ' ' . $this->report_slug_change( 'term', $source_id, $slug, $actual ) );

        if ( Licensio_KB_Import_Log::STATUS_ADOPTED === $status ) {
            $message = trim( sprintf( 'A term with this name already existed (%d) and was adopted. ', $term_id ) . $message );
        }

        $this->log->entry( 'terms', $type, $source_id, $term_id, $name, '' !== $message && Licensio_KB_Import_Log::STATUS_CREATED === $status ? Licensio_KB_Import_Log::STATUS_WARNING : $status, $message, $details );

        return $term_id;
    }

    /**
     * Merge a source category into the existing category the operator chose.
     *
     * The target keeps its name, slug and meta; it only gains the provenance,
     * so the source category's articles are filed under it and its old URL
     * redirects to it.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $row     The source term row.
     * @param string               $type    The log item type.
     * @param string               $to      The target taxonomy.
     * @param int                  $target  The existing target term ID.
     * @param array<string, mixed> $details The log details so far.
     * @param string               $old_url Where the source archive lived.
     *
     * @return int The target term ID, or 0 when it no longer exists.
     */
    private function merge_term( array $row, $type, $to, $target, array $details, $old_url ) {
        $source_id = (int) $row['term_id'];
        $name      = isset( $row['name'] ) ? (string) $row['name'] : '';
        $term      = get_term( $target, $to );

        if ( ! $term instanceof WP_Term ) {
            $this->report->count( 'terms_failed' );

            $this->log->entry(
                'terms',
                $type,
                $source_id,
                $target,
                $name,
                Licensio_KB_Import_Log::STATUS_FAILED,
                sprintf( 'Was mapped to category %d, which no longer exists.', $target ),
                $details
            );

            return 0;
        }

        $this->report->count( 'terms_merged' );

        $details['merged_into'] = array(
            'term_id' => (int) $term->term_id,
            'name'    => (string) $term->name,
        );

        if ( ! $this->dry_run ) {
            $this->add_term_provenance( (int) $term->term_id, $source_id );

            $details['redirect'] = $this->redirects->add_term( $type, $source_id, (int) $term->term_id, $to, $old_url, isset( $row['slug'] ) ? (string) $row['slug'] : '' );
        }

        $this->log->entry(
            'terms',
            $type,
            $source_id,
            (int) $term->term_id,
            $name,
            Licensio_KB_Import_Log::STATUS_MERGED,
            sprintf( 'Merged into the existing category "%s"; its meta was not copied.', $term->name ),
            $details
        );

        return (int) $term->term_id;
    }

    /**
     * Carry a category's presentation meta across, reporting what cannot cross.
     *
     * @since 1.0.0
     *
     * @param int                  $source_id The source term ID.
     * @param int                  $term_id   The target term ID.
     * @param array<string, mixed> $meta      The source term meta.
     *
     * @return void
     */
    private function migrate_term_meta( $source_id, $term_id, array $meta ) {
        if ( empty( $meta ) ) {
            return;
        }

        $mapped = $this->source->map_category_meta( $meta );

        foreach ( $mapped['meta'] as $key => $value ) {
            update_term_meta( $term_id, $key, $value );

            $this->report->count( 'term_meta_migrated' );
        }

        foreach ( $mapped['unmapped'] as $key ) {
            $this->report->count( 'term_meta_unmapped' );
            $this->report->note(
                'unmapped_term_meta',
                sprintf( '%1$s term %2$d has %3$s, which has no field in the target content model.', $this->source->label(), $source_id, $key )
            );
        }
    }

    /**
     * Import one batch of articles.
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
     * Import one source article.
     *
     * An article that already exists on the target under the source slug —
     * recreated by hand before the import, or left by an earlier import whose
     * provenance did not survive — is claimed and updated in place rather than
     * duplicated as `slug-2`. Adoption is only for an unclaimed article: one
     * carrying provenance from any import is a genuine slug collision, and core
     * uniquifies and the importer reports it, so two distinct source articles
     * can never merge into one target.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $row The source post row.
     *
     * @return int The target article ID, or 0 when nothing was created.
     */
    private function migrate_article( array $row ) {
        $source_id = isset( $row['ID'] ) ? (int) $row['ID'] : 0;
        $title     = isset( $row['post_title'] ) ? (string) $row['post_title'] : '';
        $label     = $this->source->label();

        if ( $source_id <= 0 ) {
            return 0;
        }

        $slug    = isset( $row['post_name'] ) ? (string) $row['post_name'] : '';
        $old_url = $this->source->old_article_url( $row );

        $existing = $this->find_article( $source_id );

        if ( $existing > 0 ) {
            $this->report->count( 'articles_skipped' );

            $redirect = $this->redirects->add_article( $source_id, $existing, $old_url, $slug );

            $this->log->entry(
                'articles',
                'article',
                $source_id,
                $existing,
                $title,
                Licensio_KB_Import_Log::STATUS_SKIPPED,
                sprintf( 'Already imported by an earlier run as article %d; left unchanged.', $existing ),
                array( 'redirect' => $redirect )
            );

            return $existing;
        }

        $warnings = array();
        $order    = $this->source->article_order( $source_id );

        $adopt = '' !== $slug ? $this->find_unclaimed_article_by_slug( $slug ) : 0;

        $details = array(
            'post_status' => isset( $row['post_status'] ) ? (string) $row['post_status'] : '',
            'slug'        => array( 'requested' => $slug ),
            'author'      => isset( $row['post_author'] ) ? (int) $row['post_author'] : 0,
            'date'        => isset( $row['post_date'] ) ? (string) $row['post_date'] : '',
            'order'       => array(
                'value'     => (int) $order['order'],
                'positions' => $order['positions'],
            ),
            'meta'        => $this->describe_post_meta( $source_id ),
            'attachments' => $this->source->attachments( $source_id ),
        );

        if ( $this->dry_run ) {
            $this->report->count( $adopt > 0 ? 'articles_adopted' : 'articles_created' );

            $terms = $this->assign_terms( $source_id, 0 );

            $this->log->entry(
                'articles',
                'article',
                $source_id,
                $adopt,
                $title,
                $adopt > 0 ? Licensio_KB_Import_Log::STATUS_ADOPTED : Licensio_KB_Import_Log::STATUS_CREATED,
                $adopt > 0 ? sprintf( 'Would adopt the existing article %d with the same slug.', $adopt ) : implode( ' ', $warnings ),
                array_merge( $details, $terms['details'] )
            );

            return 0;
        }

        $fields   = $this->article_fields( $row, (int) $order['order'], $slug );
        $updating = $adopt > 0;

        if ( $updating ) {
            $fields['ID'] = $adopt;

            $article_id = wp_update_post( $fields, true );
        } else {
            $article_id = wp_insert_post( $fields, true );
        }

        if ( is_wp_error( $article_id ) || (int) $article_id <= 0 ) {
            $reason = is_wp_error( $article_id ) ? $article_id->get_error_message() : 'unknown error';

            $this->report->count( 'articles_failed' );
            $this->report->note(
                'articles',
                sprintf( '%1$s article %2$d could not be %3$s: %4$s', $label, $source_id, $updating ? 'updated' : 'created', $reason )
            );

            $this->log->entry(
                'articles',
                'article',
                $source_id,
                $updating ? $adopt : 0,
                $title,
                Licensio_KB_Import_Log::STATUS_FAILED,
                sprintf( 'Could not %1$s the article: %2$s', $updating ? 'update the existing' : 'create', $reason ),
                $details
            );

            return 0;
        }

        $article_id = (int) $article_id;

        update_post_meta( $article_id, $this->source->provenance_post_key(), $source_id );

        $terms                     = $this->assign_terms( $source_id, $article_id );
        $details                   = array_merge( $details, $terms['details'] );
        $details['order']['saved'] = $this->migrate_positions( $article_id, $order['positions'], $terms['details']['categories'] );
        $warnings                  = array_merge( $warnings, $terms['warnings'] );
        $details['thumbnail_id']   = $this->migrate_thumbnail( $source_id, $article_id );
        $details['slug']['actual'] = (string) get_post_field( 'post_name', $article_id );

        $this->report->count( $updating ? 'articles_adopted' : 'articles_created' );

        $slug_change = $this->report_slug_change( 'article', $source_id, $slug, $details['slug']['actual'] );

        if ( '' !== $slug_change ) {
            $warnings[] = $slug_change;
        }

        $details['redirect'] = $this->redirects->add_article( $source_id, $article_id, $old_url, $slug );

        if ( $updating ) {
            array_unshift( $warnings, sprintf( 'Adopted the existing article %d with the same slug.', $adopt ) );
        }

        $status = $updating ? Licensio_KB_Import_Log::STATUS_ADOPTED : Licensio_KB_Import_Log::STATUS_CREATED;

        if ( ! $updating && ! empty( $warnings ) ) {
            $status = Licensio_KB_Import_Log::STATUS_WARNING;
        }

        $this->log->entry( 'articles', 'article', $source_id, $article_id, $title, $status, implode( ' ', $warnings ), $details );

        return $article_id;
    }

    /**
     * The post array both the create and the update path hand to core.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $row        The source post row.
     * @param int                  $menu_order The fallback position, for listings not limited to one category.
     * @param string               $slug       The source slug.
     *
     * @return array<string, mixed> The post fields.
     */
    private function article_fields( array $row, $menu_order, $slug ) {
        return array(
            'post_type'         => Licensio_KB_Post_Types::POST_TYPE,
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
            'menu_order'        => $menu_order,
        );
    }

    /**
     * Write an imported article's position in each imported category.
     *
     * Each source category's position is stored as `licensio_order_{term_id}`
     * against the category it was imported as. A category that was not
     * imported has no key to write.
     *
     * The imported positions follow any the category already held before the
     * run, so importing into an ordered category, or importing again after
     * the order was curated, never interleaves with it. When several source
     * categories were imported into one category, their orders are merged
     * rather than overwritten: the first source's positions come first and
     * each later source's follow them, shifted past the earlier sources (see
     * `position_offsets()`). An article listed in more than one of them keeps
     * its place from the first.
     *
     * @since 1.0.0
     *
     * @param int                              $article_id The target article ID.
     * @param array<int, int>                  $positions  Source category term ID to the article's position in it.
     * @param array<int, array<string, mixed>> $categories The category assignments `assign_terms()` reported.
     *
     * @return array<int, int> Target category term ID to the position written.
     */
    private function migrate_positions( $article_id, array $positions, array $categories ) {
        $saved = array();
        $ranks = array();

        foreach ( $categories as $category ) {
            $source = isset( $category['source_id'] ) ? (int) $category['source_id'] : 0;
            $target = isset( $category['target_id'] ) ? (int) $category['target_id'] : 0;

            if ( $target <= 0 || ! isset( $positions[ $source ] ) ) {
                continue;
            }

            $offsets = $this->position_offsets( $target );
            $rank    = array_search( $source, array_keys( $offsets ), true );
            $rank    = false === $rank ? count( $offsets ) : (int) $rank;

            if ( isset( $ranks[ $target ] ) && $ranks[ $target ] <= $rank ) {
                continue;
            }

            $ranks[ $target ] = $rank;
            $saved[ $target ] = (int) $positions[ $source ] + ( isset( $offsets[ $source ] ) ? $offsets[ $source ] : (int) $this->state->position_base( $target ) + 1 );
        }

        foreach ( $saved as $target => $position ) {
            update_post_meta( $article_id, Licensio_KB_Article_Order::meta_key( $target ), $position );
        }

        return $saved;
    }

    /**
     * The offset each source category's positions get in one target category.
     *
     * The sources are the target's provenance, in the order they were imported.
     * The first source starts one past the highest position the target held
     * before this run, or at 0 when it held none; each later source starts one
     * past the highest position of the sources before it. The target's
     * highest position is recorded in the run state the first time the run
     * reaches the target, and the sources' highest positions are read from the
     * source, so the result is the same in every batch of the run and in a
     * resumed run. If the run state is lost part-way, the next batch records
     * the target again, after the positions earlier batches wrote: later
     * articles still follow the existing order, only further down.
     *
     * @since 1.0.0
     *
     * @param int $target The target category term ID.
     *
     * @return array<int, int> Source category term ID to offset, first source first.
     */
    private function position_offsets( $target ) {
        if ( isset( $this->position_offsets[ $target ] ) ) {
            return $this->position_offsets[ $target ];
        }

        $sources = array_values( array_unique( array_map( 'intval', (array) get_term_meta( $target, $this->source->provenance_term_key(), false ) ) ) );
        $offsets = array();
        $base    = $this->state->position_base( $target );
        $last    = count( $sources ) - 1;

        if ( null === $base ) {
            $base = $this->highest_position( $target );

            $this->state->set_position_base( $target, $base );
        }

        $next = $base + 1;

        foreach ( $sources as $index => $source ) {
            $offsets[ $source ] = $next;

            // The last source's size shifts nothing, so it is not read.
            if ( $index < $last ) {
                $next += max( -1, $this->source->last_position( $source ) ) + 1;
            }
        }

        $this->position_offsets[ $target ] = $offsets;

        return $offsets;
    }

    /**
     * The highest position a target category holds.
     *
     * Read over every article holding the category's key, trashed ones
     * included, as a category merge does.
     *
     * @since 1.0.0
     *
     * @param int $target The target category term ID.
     *
     * @return int The highest position, or -1 when the category has none.
     */
    private function highest_position( $target ) {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $highest = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT MAX(CAST(meta_value AS SIGNED)) FROM %i WHERE meta_key = %s',
                $wpdb->postmeta,
                Licensio_KB_Article_Order::meta_key( $target )
            )
        );

        return null === $highest ? -1 : (int) $highest;
    }

    /**
     * The target article already living under a source slug, if it may be claimed.
     *
     * @since 1.0.0
     *
     * @param string $slug The source slug.
     *
     * @return int The adoptable article ID, or 0 when the slug is free or taken.
     */
    private function find_unclaimed_article_by_slug( $slug ) {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $candidate = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts}
                 WHERE post_name = %s AND post_type = %s
                 ORDER BY ID ASC
                 LIMIT 1",
                (string) $slug,
                Licensio_KB_Post_Types::POST_TYPE
            )
        );

        if ( $candidate <= 0 ) {
            return 0;
        }

        /*
         * A candidate carrying provenance from any import belongs to a
         * different source article — this one is a genuine collision, not an
         * adoption, and the create path will uniquify and report it. A
         * candidate claiming this very source ID cannot reach here: the
         * provenance lookup at the top of migrate_article() already handled it.
         */
        foreach ( $this->claim_keys as $key ) {
            if ( '' !== (string) get_post_meta( $candidate, $key, true ) ) {
                return 0;
            }
        }

        return $candidate;
    }

    /**
     * File an imported article under the imported equivalents of its source terms.
     *
     * With no article (a dry run) nothing is assigned; the result still says
     * which categories and tags the article would be filed under.
     *
     * @since 1.0.0
     *
     * @param int $source_id  The source post ID.
     * @param int $article_id The target article ID, or 0 for a dry run.
     *
     * @return array{details: array<string, array<int, array<string, mixed>>>, warnings: string[]} The
     *               categories and tags for the log, and any warnings.
     */
    private function assign_terms( $source_id, $article_id ) {
        $details  = array(
            'categories' => array(),
            'tags'       => array(),
        );
        $warnings = array();

        foreach ( $this->source->taxonomy_pairs() as $from => $to ) {
            $group      = Licensio_KB_Post_Types::TAXONOMY_TAG === $to ? 'tags' : 'categories';
            $source_ids = $this->source->post_terms( $source_id, $from );
            $names      = empty( $source_ids ) ? array() : $this->source->term_names( $source_ids );
            $target_ids = array();

            foreach ( $source_ids as $source_term_id ) {
                $target = $this->find_term( $to, $source_term_id );
                $name   = isset( $names[ $source_term_id ] ) ? $names[ $source_term_id ] : '';

                if ( $target > 0 ) {
                    $target_ids[] = $target;
                    $status       = 'assigned';
                } elseif ( $article_id <= 0 ) {
                    $status = 'pending';
                } else {
                    $status = 'missing';

                    $this->report->count( 'article_terms_missing' );

                    $warning = sprintf( '%1$s article %2$d referenced %3$s term %4$d, which was not migrated.', $this->source->label(), $source_id, $from, $source_term_id );

                    $this->report->note( 'article_terms', $warning );

                    $warnings[] = sprintf( 'Its %1$s "%2$s" (%3$d) was not imported, so the article is not filed under it.', 'tags' === $group ? 'tag' : 'category', $name, $source_term_id );
                }

                $details[ $group ][] = array(
                    'source_id' => (int) $source_term_id,
                    'name'      => $name,
                    'target_id' => $target,
                    'status'    => $status,
                );
            }

            if ( $article_id > 0 && ! empty( $target_ids ) ) {
                wp_set_object_terms( $article_id, array_values( array_unique( $target_ids ) ), $to, false );
            }
        }

        return array(
            'details'  => $details,
            'warnings' => $warnings,
        );
    }

    /**
     * Carry the featured image across.
     *
     * The attachment itself is not copied or re-parented. It is a `wp_posts`
     * row that both the old and the new article can point at; duplicating it
     * would duplicate the file, and re-parenting it would write to the source.
     *
     * @since 1.0.0
     *
     * @param int $source_id  The source post ID.
     * @param int $article_id The target article ID.
     *
     * @return int The attachment ID carried across, or 0.
     */
    private function migrate_thumbnail( $source_id, $article_id ) {
        $thumbnail = (int) get_post_meta( $source_id, '_thumbnail_id', true );

        if ( $thumbnail <= 0 ) {
            return 0;
        }

        update_post_meta( $article_id, '_thumbnail_id', $thumbnail );

        $this->report->count( 'thumbnails_migrated' );

        return $thumbnail;
    }

    /**
     * Every source post meta key with what becomes of it, for the log.
     *
     * @since 1.0.0
     *
     * @param int $source_id The source post ID.
     *
     * @return array<int, array<string, string>> One entry per meta key.
     */
    private function describe_post_meta( $source_id ) {
        $out = array();

        foreach ( $this->source->post_meta( $source_id ) as $key => $value ) {
            $class = $this->source->classify_post_meta( $key );
            $out[] = Licensio_KB_Import_Log::meta_entry( $key, $value, $class['action'], $class['target'] );
        }

        return $out;
    }

    /**
     * Every source term meta key with what becomes of it, for the log.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $meta The source term meta.
     *
     * @return array<int, array<string, string>> One entry per meta key.
     */
    private function describe_term_meta( array $meta ) {
        if ( empty( $meta ) ) {
            return array();
        }

        $mapped   = $this->source->map_category_meta( $meta );
        $unmapped = array_flip( $mapped['unmapped'] );
        $out      = array();

        foreach ( $meta as $key => $value ) {
            if ( isset( $unmapped[ $key ] ) ) {
                $out[] = Licensio_KB_Import_Log::meta_entry( $key, $value, 'not_migrated', 'no field in the target content model' );
                continue;
            }

            $class = $this->source->classify_term_meta( $key );
            $out[] = Licensio_KB_Import_Log::meta_entry( $key, $value, $class['action'], $class['target'] );
        }

        return $out;
    }

    /**
     * Note it when the target had to take a different slug from the source.
     *
     * Core silently uniquifies a colliding slug, which would move a URL without
     * telling anybody. Comparing what was asked for against what was stored is
     * the only way to catch every case, including the ones this importer did
     * not predict — a reserved slug, a conflicting page, a taxonomy clash.
     *
     * @since 1.0.0
     *
     * @param string $kind      `article` or `term`.
     * @param int    $source_id The source ID.
     * @param string $requested The slug the source had.
     * @param string $actual    The slug the target got.
     *
     * @return string The note for the log, or '' when the slug is unchanged.
     */
    private function report_slug_change( $kind, $source_id, $requested, $actual ) {
        if ( '' === $requested || $requested === $actual ) {
            return '';
        }

        $this->report->count( 'slug_collisions' );
        $this->report->note(
            'slug_collisions',
            sprintf( '%1$s %2$s %3$d wanted slug "%4$s" and got "%5$s".', $this->source->label(), $kind, $source_id, $requested, $actual )
        );

        return sprintf( 'The slug "%1$s" was taken, so it became "%2$s"; the old URL redirects.', $requested, $actual );
    }

    /**
     * The target category the operator mapped a source category onto.
     *
     * @since 1.0.0
     *
     * @param int $source_id The source term ID.
     *
     * @return int The target term ID, or 0 to create a new category.
     */
    private function mapped_category( $source_id ) {
        $map = $this->state->category_map();

        return isset( $map[ (int) $source_id ] ) ? (int) $map[ (int) $source_id ] : 0;
    }

    /**
     * Record that a target term holds a source term.
     *
     * Added rather than updated, because several source categories can be
     * merged into one target and each must still be found by its own ID.
     *
     * @since 1.0.0
     *
     * @param int $term_id   The target term ID.
     * @param int $source_id The source term ID.
     *
     * @return void
     */
    private function add_term_provenance( $term_id, $source_id ) {
        $key     = $this->source->provenance_term_key();
        $current = array_map( 'intval', (array) get_term_meta( $term_id, $key, false ) );

        if ( ! in_array( (int) $source_id, $current, true ) ) {
            add_term_meta( $term_id, $key, (int) $source_id );
        }
    }

    /**
     * The log item type of a source term.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $row  The source term row.
     * @param string               $from The source taxonomy the row was read for.
     *
     * @return string `category`, `tag`, or the row's own `item_type`.
     */
    private function term_type( array $row, $from ) {
        if ( ! empty( $row['item_type'] ) ) {
            return (string) $row['item_type'];
        }

        return $this->source->tag_taxonomy() === $from ? 'tag' : 'category';
    }

    /**
     * The target term imported from a given source term, if there is one.
     *
     * @since 1.0.0
     *
     * @param string $taxonomy  The target taxonomy.
     * @param int    $source_id The source term ID.
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
                $this->source->provenance_term_key(),
                (string) (int) $source_id,
                (string) $taxonomy
            )
        );

        return $term_id;
    }

    /**
     * The target article imported from a given source article, if there is one.
     *
     * @since 1.0.0
     *
     * @param int $source_id The source post ID.
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
                $this->source->provenance_post_key(),
                (string) (int) $source_id,
                Licensio_KB_Post_Types::POST_TYPE
            )
        );

        return $post_id;
    }

    /**
     * Adopt an existing term when `wp_insert_term()` reports it already exists.
     *
     * This happens when the operator created the target taxonomy's terms by
     * hand before running the import. Adopting is better than failing: the
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
