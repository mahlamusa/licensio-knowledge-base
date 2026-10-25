<?php
/**
 * Finds and resolves duplicate knowledge base articles.
 *
 * The failure mode this exists for: an article already exists under the slug a
 * migration wanted, so the migrator (before it learned to adopt) created a
 * twin beside it as `slug-2`. The twin carries everything the import wrote —
 * provenance, terms, votes — while the original carries whatever the site
 * already had. Two articles answer one question, and readers find both.
 *
 * A duplicate group is posts that agree on four things: title, slug once any
 * trailing `-2`-style suffix is stripped, publication date, and the opening
 * lines of the content. Four agreements is deliberately strict — the fix
 * overwrites one post with another and soft-deletes the loser, so the
 * evidence must be beyond a shared name.
 *
 * Fixing a group means: the member with the most meta of that post type is
 * the survivor's content — its fields, meta, terms and analytics are moved
 * onto the member whose slug is the group's base — and every other member
 * goes to the trash, its term assignments following the survivor. Groups
 * that involve a post type the knowledge base does not own are never fixed;
 * they are flagged for a human, because the tool has no business deciding
 * what a page or a product twin should become.
 *
 * Categories and tags are scanned for the same disease: same name, same
 * slug, same description. A duplicate term's meta and article assignments
 * move onto the term that owns the base slug, and the empty duplicate is
 * deleted — a term has no trash to soft-delete into. And one further group
 * is residue rather than duplication: a migration source post beside the
 * article it became. The article is the replacement; the fix is moving the
 * source post to the trash, which is the deletion the migration report
 * leaves to the operator.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Detects and resolves duplicate articles.
 *
 * @since 1.0.0
 */
class Licensio_KB_Duplicates {

    /**
     * How much of the content, in characters, two duplicates must share.
     *
     * @since 1.0.0
     * @var int
     */
    const CONTENT_SIGNATURE_LENGTH = 200;

    /**
     * Post types that are scaffolding rather than content, never duplicate
     * candidates.
     *
     * @since 1.0.0
     * @var string[]
     */
    const EXCLUDED_POST_TYPES = array(
        'revision',
        'attachment',
        'nav_menu_item',
        'customize_changeset',
        'customize_snapshot',
        'oembed_cache',
        'wp_block',
        'wp_template',
        'wp_template_part',
        'wp_global_styles',
        'wp_navigation',
        'wp_font_family',
        'wp_font_face',
        'user_request',
        'wp_changeset',
    );

    /**
     * Post meta keys that are editor noise rather than content.
     *
     * @since 1.0.0
     * @var string[]
     */
    const SKIPPED_META_KEYS = array( '_edit_lock', '_edit_last', '_wp_trash_meta_time', '_wp_trash_meta_status', '_wp_old_slug' );

    /**
     * Post types a migration reads from. A group of one knowledge base
     * article and the source post it was migrated from is migration residue,
     * not a duplicate to merge: the article is the replacement, and the fix
     * is soft-deleting the source post.
     *
     * Read from every registered importer, so a new source is covered without
     * a change here.
     *
     * @since 1.0.0
     *
     * @return string[] Post types.
     */
    private static function source_post_types() {
        static $types = null;

        if ( null === $types ) {
            $importer = new Licensio_KB_Importer();
            $types    = $importer->source_post_types();
        }

        return $types;
    }

    /**
     * Every duplicate group on the site, fixable and flagged.
     *
     * @since 1.0.0
     *
     * @return array{posts: array<int, array<string, mixed>>, terms: array<int, array<string, mixed>>} Post groups and term groups.
     */
    public function scan() {
        return array(
            'posts' => $this->scan_posts(),
            'terms' => $this->scan_terms(),
        );
    }

    /**
     * Duplicate posts, fixable and flagged.
     *
     * @since 1.0.0
     *
     * @return array<int, array<string, mixed>> The post groups. See describe_group() for the shape.
     */
    public function scan_posts() {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ID, post_type, post_title, post_name, post_date, post_status,
                        LEFT( post_content, 1000 ) AS post_content
                 FROM {$wpdb->posts}
                 WHERE post_status NOT IN ( 'trash', 'auto-draft', 'inherit' )
                   AND post_type NOT IN ( " . implode( ', ', array_fill( 0, count( self::EXCLUDED_POST_TYPES ), '%s' ) ) . ' )',
                self::EXCLUDED_POST_TYPES
            ),
            ARRAY_A
        );

        $groups = $this->group_posts( is_array( $rows ) ? $rows : array() );
        $counts = $this->meta_counts( $groups );

        $out = array();

        foreach ( $groups as $members ) {
            $out[] = $this->describe_group( $members, $counts );
        }

        return $out;
    }

    /**
     * Duplicate categories and tags, fixable and flagged.
     *
     * @since 1.0.0
     *
     * @return array<int, array<string, mixed>> The term groups. See describe_term_group() for the shape.
     */
    public function scan_terms() {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT t.term_id, t.name, t.slug, tt.description, tt.count, tt.taxonomy, tt.term_taxonomy_id
                 FROM {$wpdb->terms} AS t
                 INNER JOIN {$wpdb->term_taxonomy} AS tt ON tt.term_id = t.term_id
                 WHERE tt.taxonomy IN ( %s, %s )",
                Licensio_KB_Post_Types::TAXONOMY_CATEGORY,
                Licensio_KB_Post_Types::TAXONOMY_TAG
            ),
            ARRAY_A
        );

        $groups = $this->group_terms( is_array( $rows ) ? $rows : array() );
        $counts = $this->term_meta_counts( $groups );

        $out = array();

        foreach ( $groups as $members ) {
            $out[] = $this->describe_term_group( $members, $counts );
        }

        return $out;
    }

    /**
     * Fix every fixable group the scanner finds.
     *
     * Works from a fresh scan rather than from anything the browser sent: the
     * decision overwrites posts, so it is made against the database as it is
     * now, not as it was when the page loaded.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed> What was done: counters, per-group notes, and the groups left flagged.
     */
    public function fix() {
        $report = array(
            'groups_merged'         => 0,
            'posts_trashed'         => 0,
            'meta_copied'           => 0,
            'terms_assigned'        => 0,
            'votes_moved'           => 0,
            'views_moved'           => 0,
            'term_groups_merged'    => 0,
            'terms_deleted'         => 0,
            'term_meta_copied'      => 0,
            'articles_reassigned'   => 0,
            'source_groups_cleaned' => 0,
            'sources_trashed'       => 0,
            'notes'                 => array(),
            'flagged'               => array(
                'posts' => array(),
                'terms' => array(),
            ),
        );

        foreach ( $this->scan_posts() as $group ) {
            if ( 'flagged' === $group['kind'] ) {
                $report['flagged']['posts'][] = $group;
                continue;
            }

            $result = 'source' === $group['kind']
                ? $this->fix_source_group( $group )
                : $this->fix_group( $group );

            if ( null === $result ) {
                $report['flagged']['posts'][] = $group;
                continue;
            }

            if ( 'source' === $group['kind'] ) {
                ++$report['source_groups_cleaned'];
                $report['sources_trashed'] += $result['posts_trashed'];
                $report['notes'][]          = $result['note'];
                continue;
            }

            ++$report['groups_merged'];
            $report['posts_trashed']  += $result['posts_trashed'];
            $report['meta_copied']    += $result['meta_copied'];
            $report['terms_assigned'] += $result['terms_assigned'];
            $report['votes_moved']    += $result['votes_moved'];
            $report['views_moved']    += $result['views_moved'];
            $report['notes'][]         = $result['note'];
        }

        foreach ( $this->scan_terms() as $group ) {
            if ( ! $group['fixable'] ) {
                $report['flagged']['terms'][] = $group;
                continue;
            }

            $result = $this->fix_term_group( $group );

            if ( null === $result ) {
                $report['flagged']['terms'][] = $group;
                continue;
            }

            ++$report['term_groups_merged'];
            $report['terms_deleted']       += $result['terms_deleted'];
            $report['term_meta_copied']    += $result['term_meta_copied'];
            $report['articles_reassigned'] += $result['articles_reassigned'];
            $report['notes'][]              = $result['note'];
        }

        return $report;
    }

    /**
     * Merge one fixable group onto its un-suffixed member.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $group A fixable group from describe_group().
     *
     * @return array<string, mixed>|null The fix counters and note, or null when the group turned out unfixable.
     */
    private function fix_group( array $group ) {
        $canonical = (int) $group['canonical'];

        if ( $canonical <= 0 ) {
            return null;
        }

        $winner = (int) $group['winner'];
        $losers = array();

        foreach ( $group['members'] as $member ) {
            if ( (int) $member['id'] !== $canonical ) {
                $losers[] = (int) $member['id'];
            }
        }

        $meta_copied    = 0;
        $terms_assigned = 0;

        if ( $winner > 0 && $winner !== $canonical ) {
            $meta_copied = $this->copy_content( $winner, $canonical );
        }

        /*
         * Terms come from every other member, not just the winner: a loser's
         * categories would otherwise die with it in the trash, and a
         * duplicate filed under a category the winner never had is still
         * evidence the canonical article belongs there.
         */
        foreach ( $group['members'] as $member ) {
            if ( (int) $member['id'] !== $canonical ) {
                $terms_assigned += $this->merge_terms( (int) $member['id'], $canonical );
            }
        }

        $votes_moved = $this->move_analytics( Licensio_KB_Feedback::TABLE, $losers, $canonical );
        $views_moved = $this->move_analytics( Licensio_KB_Analytics::TABLE_ARTICLE_VIEWS, $losers, $canonical );

        foreach ( $losers as $loser ) {
            wp_trash_post( $loser );
        }

        if ( $votes_moved > 0 ) {
            ( new Licensio_KB_Feedback() )->recompute_score( $canonical );
        }

        return array(
            'posts_trashed'  => count( $losers ),
            'meta_copied'    => $meta_copied,
            'terms_assigned' => $terms_assigned,
            'votes_moved'    => $votes_moved,
            'views_moved'    => $views_moved,
            'note'           => sprintf(
                'Merged "%1$s" (%2$d posts): #%3$d now carries #%4$d\'s content; trashed %5$s.',
                $group['title'],
                count( $group['members'] ),
                $canonical,
                $winner,
                implode( ', ', array_map( 'intval', $losers ) )
            ),
        );
    }

    /**
     * Clean up one migration-source group: trash the source post.
     *
     * The knowledge base article is the replacement and is not touched — it
     * may have been edited since the migration, and its content is the one
     * the site serves. All that remains of the group is the source post,
     * whose deletion the migration report leaves to the operator. This is
     * that deletion.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $group A source group from describe_group().
     *
     * @return array<string, mixed>|null The fix counters and note, or null when the group turned out unfixable.
     */
    private function fix_source_group( array $group ) {
        $canonical = (int) $group['canonical'];

        if ( $canonical <= 0 ) {
            return null;
        }

        $sources = array();

        foreach ( $group['members'] as $member ) {
            if ( (int) $member['id'] !== $canonical ) {
                $sources[] = (int) $member['id'];
            }
        }

        foreach ( $sources as $source ) {
            wp_trash_post( $source );
        }

        return array(
            'posts_trashed' => count( $sources ),
            'note'          => sprintf(
                'Migration source of "%1$s" trashed; article #%2$d replaces it.',
                $group['title'],
                $canonical
            ),
        );
    }

    /**
     * Copy one post's content, fields and meta onto another.
     *
     * Meta the winner does not carry is left on the target as it is; meta it
     * does carry overwrites. The slug is deliberately not touched — the
     * target keeps the un-suffixed slug that made it the canonical one.
     *
     * @since 1.0.0
     *
     * @param int $from The post whose content wins.
     * @param int $to   The post being updated in place.
     *
     * @return int How many meta keys were copied.
     */
    private function copy_content( $from, $to ) {
        $source = get_post( $from );

        if ( ! $source instanceof WP_Post ) {
            return 0;
        }

        wp_update_post(
            array(
                'ID'             => (int) $to,
                'post_title'     => $source->post_title,
                'post_content'   => $source->post_content,
                'post_excerpt'   => $source->post_excerpt,
                'post_status'    => $source->post_status,
                'post_author'    => (int) $source->post_author,
                'post_date'      => $source->post_date,
                'post_date_gmt'  => $source->post_date_gmt,
                'comment_status' => $source->comment_status,
                'ping_status'    => $source->ping_status,
                'menu_order'     => (int) $source->menu_order,
            ),
            true
        );

        $copied = 0;

        foreach ( get_post_meta( $from ) as $key => $values ) {
            if ( in_array( $key, self::SKIPPED_META_KEYS, true ) ) {
                continue;
            }

            update_post_meta( (int) $to, $key, maybe_unserialize( $values[0] ) );
            ++$copied;
        }

        return $copied;
    }

    /**
     * Add the winner's terms to the canonical post.
     *
     * Union, not replace: terms only the canonical carried are kept.
     *
     * @since 1.0.0
     *
     * @param int $from The post whose terms are merged.
     * @param int $to   The post gaining them.
     *
     * @return int How many term assignments were added.
     */
    private function merge_terms( $from, $to ) {
        $added = 0;

        foreach ( array( Licensio_KB_Post_Types::TAXONOMY_CATEGORY, Licensio_KB_Post_Types::TAXONOMY_TAG ) as $taxonomy ) {
            $existing = wp_get_post_terms( (int) $to, $taxonomy, array( 'fields' => 'ids' ) );
            $incoming = wp_get_post_terms( (int) $from, $taxonomy, array( 'fields' => 'ids' ) );

            if ( is_wp_error( $existing ) || is_wp_error( $incoming ) ) {
                continue;
            }

            $new = array_diff( $incoming, $existing );

            if ( array() !== $new ) {
                wp_set_object_terms( (int) $to, array_map( 'intval', array_merge( $existing, $incoming ) ), $taxonomy, false );
                $added += count( $new );
            }
        }

        return $added;
    }

    /**
     * Point analytics rows at the post that survives the merge.
     *
     * @since 1.0.0
     *
     * @param string $table     Unprefixed analytics table name.
     * @param int[]  $from_ids  The posts being trashed.
     * @param int    $to_id     The post the rows now belong to.
     *
     * @return int How many rows moved.
     */
    private function move_analytics( $table, array $from_ids, $to_id ) {
        global $wpdb;

        if ( array() === $from_ids ) {
            return 0;
        }

        $name   = Licensio_KB_DB::table_name( $table );
        $ids    = implode( ',', array_map( 'intval', $from_ids ) );
        $target = (int) $to_id;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name is a class constant and the IDs are cast to integers.
        return (int) $wpdb->query( "UPDATE {$name} SET article_id = {$target} WHERE article_id IN ( {$ids} )" );
    }

    /**
     * Group post rows by the four-way duplicate signature.
     *
     * @since 1.0.0
     *
     * @param array<int, array<string, mixed>> $rows Post rows with ID, post_type, post_title, post_name, post_date, post_content.
     *
     * @return array<string, array<int, array<string, mixed>>> Signature to the rows sharing it; only signatures with two or more rows are kept.
     */
    public function group_posts( array $rows ) {
        $by_signature = array();

        foreach ( $rows as $row ) {
            $signature = $this->signature( $row );

            if ( '' === $signature ) {
                continue;
            }

            $by_signature[ $signature ][] = $row;
        }

        return array_filter(
            $by_signature,
            function ( $members ) {
                return count( $members ) > 1;
            }
        );
    }

    /**
     * The duplicate signature of a post row.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $row The post row.
     *
     * @return string The signature, empty when the row lacks anything to compare.
     */
    private function signature( array $row ) {
        $title = $this->normalize( isset( $row['post_title'] ) ? (string) $row['post_title'] : '' );
        $slug  = self::base_slug( isset( $row['post_name'] ) ? (string) $row['post_name'] : '' );
        $date  = isset( $row['post_date'] ) ? substr( (string) $row['post_date'], 0, 10 ) : '';
        $body  = substr( $this->normalize( isset( $row['post_content'] ) ? (string) $row['post_content'] : '' ), 0, self::CONTENT_SIGNATURE_LENGTH );

        if ( '' === $title || '' === $slug || '' === $date ) {
            return '';
        }

        return $title . '|' . $slug . '|' . $date . '|' . $body;
    }

    /**
     * A slug with any trailing `-2`-style uniquifier removed.
     *
     * @since 1.0.0
     *
     * @param string $slug The slug.
     *
     * @return string The base slug.
     */
    public static function base_slug( $slug ) {
        return (string) preg_replace( '/-\d+$/', '', (string) $slug );
    }

    /**
     * Whether a slug carries a uniquifier suffix.
     *
     * @since 1.0.0
     *
     * @param string $slug The slug.
     *
     * @return bool True when the slug ends in `-<digits>`.
     */
    public static function has_suffix( $slug ) {
        return (bool) preg_match( '/-\d+$/', (string) $slug );
    }

    /**
     * Lowercase, tag-free, whitespace-collapsed text for comparison.
     *
     * @since 1.0.0
     *
     * @param string $text The text.
     *
     * @return string The normalized text.
     */
    private function normalize( $text ) {
        $text = wp_strip_all_tags( (string) $text );
        $text = preg_replace( '/\s+/u', ' ', $text );

        return function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( (string) $text ) ) : strtolower( trim( (string) $text ) );
    }

    /**
     * Meta counts for every post in every group.
     *
     * @since 1.0.0
     *
     * @param array<string, array<int, array<string, mixed>>> $groups The groups.
     *
     * @return array<int, int> Post ID to its meta row count.
     */
    private function meta_counts( array $groups ) {
        global $wpdb;

        $ids = array();

        foreach ( $groups as $members ) {
            foreach ( $members as $member ) {
                $ids[] = (int) $member['ID'];
            }
        }

        if ( array() === $ids ) {
            return array();
        }

        $id_list = implode( ',', array_map( 'intval', array_unique( $ids ) ) );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The IDs are cast to integers.
        $rows = $wpdb->get_results( "SELECT post_id, COUNT( * ) AS total FROM {$wpdb->postmeta} WHERE post_id IN ( {$id_list} ) GROUP BY post_id", ARRAY_A );

        $counts = array();

        foreach ( (array) $rows as $row ) {
            $counts[ (int) $row['post_id'] ] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Describe one group for the report and the UI.
     *
     * A group is fixable when every member is a knowledge base article and one
     * member's slug carries no suffix — that member is what the others are
     * merged into. Anything else is flagged: the tool does not overwrite post
     * types it does not own, and it cannot invent a canonical target.
     *
     * @since 1.0.0
     *
     * @param array<int, array<string, mixed>> $members The group's post rows.
     * @param array<int, int>                  $counts  Post ID to meta count.
     *
     * @return array<string, mixed> The group description.
     */
    public function describe_group( array $members, array $counts ) {
        $described  = array();
        $kb_ids     = array();
        $source_ids = array();
        $foreign    = array();
        $canonical  = 0;

        /*
         * The base slug is the group's shared identity, so the member worth
         * keeping is the one whose full slug IS the base — not "the one
         * without a numeric tail", which misreads naturally numbered slugs
         * like `heroic-article-1` as uniquified.
         */
        $base = self::base_slug( (string) $members[0]['post_name'] );

        foreach ( $members as $member ) {
            $id    = (int) $member['ID'];
            $is_kb = Licensio_KB_Post_Types::POST_TYPE === $member['post_type'];

            if ( $is_kb ) {
                $kb_ids[] = $id;

                if ( (string) $member['post_name'] === $base ) {
                    $canonical = $id;
                }
            } elseif ( in_array( $member['post_type'], self::source_post_types(), true ) ) {
                $source_ids[] = $id;
            } else {
                $foreign[] = $id;
            }

            $described[] = array(
                'id'        => $id,
                'type'      => (string) $member['post_type'],
                'slug'      => (string) $member['post_name'],
                'status'    => (string) $member['post_status'],
                'metaCount' => isset( $counts[ $id ] ) ? $counts[ $id ] : 0,
            );
        }

        $winner = 0;
        $best   = -1;

        foreach ( $described as $member ) {
            if ( $member['metaCount'] > $best ) {
                $best   = $member['metaCount'];
                $winner = $member['id'];
            }
        }

        /*
         * Three kinds of group. All knowledge base articles with a member
         * owning the base slug: a duplicate to merge. One knowledge base
         * article beside its migration source post: residue to clean up — the
         * article is the replacement whatever its slug says. Anything else —
         * a post type the knowledge base does not own, or no member to merge
         * into — is a human's decision.
         */
        if ( array() !== $foreign ) {
            $kind = 'flagged';
        } elseif ( array() !== $source_ids ) {
            $kind = 1 === count( $kb_ids ) ? 'source' : 'flagged';
        } else {
            $kind = $canonical > 0 ? 'merge' : 'flagged';
        }

        if ( 'source' === $kind ) {
            $canonical = (int) $kb_ids[0];
        }

        return array(
            'title'     => (string) $members[0]['post_title'],
            'baseSlug'  => $base,
            'date'      => substr( (string) $members[0]['post_date'], 0, 10 ),
            'members'   => $described,
            'canonical' => $canonical,
            'winner'    => $winner,
            'kind'      => $kind,
            'fixable'   => 'flagged' !== $kind,
        );
    }

    /**
     * Merge one fixable term group onto its un-suffixed member.
     *
     * Terms carry no content of their own — name, slug and description already
     * agree, because agreeing on them is what made the group — so the merge is
     * the two things that can still differ: the duplicate's meta, and the
     * articles filed under it. Both move to the un-suffixed term, and the
     * empty duplicate is deleted: a term has no trash to soft-delete into.
     *
     * `licensio_kb_terms_merged` fires after the articles move and before the
     * duplicates are deleted, with the same arguments as a merge from the
     * Categories or Tags app, so the per-category article order is carried to
     * the kept term before deleting a duplicate drops the duplicate's order.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $group A fixable group from describe_term_group().
     *
     * @return array<string, mixed>|null The fix counters and note, or null when the group turned out unfixable.
     */
    private function fix_term_group( array $group ) {
        $canonical = (int) $group['canonical'];

        if ( $canonical <= 0 ) {
            return null;
        }

        $taxonomy = (string) $group['taxonomy'];
        $winner   = (int) $group['winner'];
        $losers   = array();

        foreach ( $group['members'] as $member ) {
            if ( (int) $member['id'] !== $canonical ) {
                $losers[] = $member;
            }
        }

        $term_meta_copied = 0;

        if ( $winner > 0 && $winner !== $canonical ) {
            foreach ( get_term_meta( $winner ) as $key => $values ) {
                update_term_meta( $canonical, $key, maybe_unserialize( $values[0] ) );
                ++$term_meta_copied;
            }
        }

        $reassigned = 0;

        foreach ( $losers as $loser ) {
            $reassigned += $this->move_term_objects( (int) $loser['termTaxonomyId'], (int) $group['canonicalTermTaxonomyId'], $taxonomy );
        }

        /** This action is documented in includes/class-licensio-kb-term-operations.php */
        do_action(
            'licensio_kb_terms_merged',
            array_map(
                function ( $loser ) {
                    return (int) $loser['id'];
                },
                $losers
            ),
            $canonical,
            $taxonomy
        );

        foreach ( $losers as $loser ) {
            wp_delete_term( (int) $loser['id'], $taxonomy );
        }

        return array(
            'terms_deleted'       => count( $losers ),
            'term_meta_copied'    => $term_meta_copied,
            'articles_reassigned' => $reassigned,
            'note'                => sprintf(
                'Merged %1$s "%2$s": term #%3$d kept its slug and gained %4$d meta and %5$d article assignments; deleted %6$s.',
                $taxonomy,
                $group['name'],
                $canonical,
                $term_meta_copied,
                $reassigned,
                implode(
                    ', ',
                    array_map(
                        function ( $loser ) {
                            return '#' . (int) $loser['id'];
                        },
                        $losers
                    )
                )
            ),
        );
    }

    /**
     * Re-file every object of one duplicate term under the canonical term.
     *
     * The relationship rows move in two statements rather than one core call
     * per article. The moved articles' term caches are cleaned afterwards, so
     * a persistent object cache does not keep serving the duplicate term.
     *
     * @since 1.0.0
     *
     * @param int    $loser_tt     The duplicate term's term_taxonomy_id.
     * @param int    $canonical_tt The canonical term's term_taxonomy_id.
     * @param string $taxonomy     The taxonomy both terms belong to.
     *
     * @return int How many object relationships moved.
     */
    private function move_term_objects( $loser_tt, $canonical_tt, $taxonomy ) {
        global $wpdb;

        $from = (int) $loser_tt;
        $to   = (int) $canonical_tt;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $object_ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT object_id FROM %i WHERE term_taxonomy_id = %d', $wpdb->term_relationships, $from ) ) );

        if ( array() === $object_ids ) {
            return 0;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $moved = (int) $wpdb->query(
            $wpdb->prepare(
                'INSERT IGNORE INTO %i ( object_id, term_taxonomy_id ) SELECT object_id, %d FROM %i WHERE term_taxonomy_id = %d',
                $wpdb->term_relationships,
                $to,
                $wpdb->term_relationships,
                $from
            )
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE term_taxonomy_id = %d', $wpdb->term_relationships, $from ) );

        clean_object_term_cache( $object_ids, Licensio_KB_Post_Types::POST_TYPE );
        wp_update_term_count( array( $from, $to ), $taxonomy );

        return $moved;
    }

    /**
     * Group term rows by taxonomy and the duplicate signature.
     *
     * @since 1.0.0
     *
     * @param array<int, array<string, mixed>> $rows Term rows joined with term_taxonomy.
     *
     * @return array<string, array<int, array<string, mixed>>> Taxonomy and signature to the rows sharing it; only groups of two or more are kept.
     */
    public function group_terms( array $rows ) {
        $by_signature = array();

        foreach ( $rows as $row ) {
            $signature = $this->term_signature( $row );

            if ( '' === $signature ) {
                continue;
            }

            $by_signature[ $row['taxonomy'] . '|' . $signature ][] = $row;
        }

        return array_filter(
            $by_signature,
            function ( $members ) {
                return count( $members ) > 1;
            }
        );
    }

    /**
     * The duplicate signature of a term row.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $row The term row.
     *
     * @return string The signature, empty when the row lacks anything to compare.
     */
    private function term_signature( array $row ) {
        $name = $this->normalize( isset( $row['name'] ) ? (string) $row['name'] : '' );
        $slug = self::base_slug( isset( $row['slug'] ) ? (string) $row['slug'] : '' );
        $desc = $this->normalize( isset( $row['description'] ) ? (string) $row['description'] : '' );

        if ( '' === $name || '' === $slug ) {
            return '';
        }

        return $name . '|' . $slug . '|' . $desc;
    }

    /**
     * Term meta counts for every term in every group.
     *
     * @since 1.0.0
     *
     * @param array<string, array<int, array<string, mixed>>> $groups The term groups.
     *
     * @return array<int, int> Term ID to its meta row count.
     */
    private function term_meta_counts( array $groups ) {
        global $wpdb;

        $ids = array();

        foreach ( $groups as $members ) {
            foreach ( $members as $member ) {
                $ids[] = (int) $member['term_id'];
            }
        }

        if ( array() === $ids ) {
            return array();
        }

        $id_list = implode( ',', array_map( 'intval', array_unique( $ids ) ) );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The IDs are cast to integers.
        $rows = $wpdb->get_results( "SELECT term_id, COUNT( * ) AS total FROM {$wpdb->termmeta} WHERE term_id IN ( {$id_list} ) GROUP BY term_id", ARRAY_A );

        $counts = array();

        foreach ( (array) $rows as $row ) {
            $counts[ (int) $row['term_id'] ] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Describe one term group for the report and the UI.
     *
     * Fixable when one member's slug carries no suffix — the term the others
     * are merged into. Every member is a knowledge base term by construction:
     * the scan only reads the knowledge base taxonomies.
     *
     * @since 1.0.0
     *
     * @param array<int, array<string, mixed>> $members The group's term rows.
     * @param array<int, int>                  $counts  Term ID to meta count.
     *
     * @return array<string, mixed> The group description.
     */
    public function describe_term_group( array $members, array $counts ) {
        $described    = array();
        $canonical    = 0;
        $canonical_tt = 0;

        // The member whose full slug is the group's base owns the base slug;
        // a numeric tail alone would misread naturally numbered slugs.
        $base = self::base_slug( (string) $members[0]['slug'] );

        foreach ( $members as $member ) {
            $id = (int) $member['term_id'];

            if ( (string) $member['slug'] === $base ) {
                $canonical    = $id;
                $canonical_tt = (int) $member['term_taxonomy_id'];
            }

            $described[] = array(
                'id'             => $id,
                'name'           => (string) $member['name'],
                'slug'           => (string) $member['slug'],
                'count'          => (int) $member['count'],
                'metaCount'      => isset( $counts[ $id ] ) ? $counts[ $id ] : 0,
                'termTaxonomyId' => (int) $member['term_taxonomy_id'],
            );
        }

        $winner = 0;
        $best   = -1;

        foreach ( $described as $member ) {
            if ( $member['metaCount'] > $best ) {
                $best   = $member['metaCount'];
                $winner = $member['id'];
            }
        }

        return array(
            'name'                    => (string) $members[0]['name'],
            'baseSlug'                => self::base_slug( (string) $members[0]['slug'] ),
            'taxonomy'                => (string) $members[0]['taxonomy'],
            'members'                 => $described,
            'canonical'               => $canonical,
            'canonicalTermTaxonomyId' => $canonical_tt,
            'winner'                  => $winner,
            'fixable'                 => $canonical > 0,
        );
    }
}
