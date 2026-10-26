<?php
/**
 * The mapping layer, and the only place an IP address is allowed to exist.
 *
 * Every one of Heroic's four tables carries a `user_ip` column. None of that
 * data may reach any `licensio_*` table, and the plugin's headline privacy
 * promise depends on it. So the boundary is drawn here, in pure functions:
 *
 * - A Heroic row goes in. A target row comes out.
 * - The output array is built by naming each target column, never by copying,
 *   spreading or filtering the source row. There is no code path along which an
 *   unrecognised source column can survive into the output, which is what makes
 *   the guarantee structural rather than a matter of remembering to unset().
 * - The address is read exactly once per row, immediately handed to
 *   `Licensio_KB_Visitor::hash()` — the plugin's own HMAC, so the migrator and
 *   the live recorder produce the same shape of identity — and then dropped.
 *
 * Nothing here touches the database, WordPress or the filesystem, so every rule
 * below can be asserted directly, and the integration suite can seed real
 * Heroic rows carrying real addresses and then scan the whole target schema.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Turns Heroic rows into target rows, dropping the address.
 *
 * @since 1.0.0
 */
class Licensio_KB_Heroic_Import_Map {

    /**
     * The pseudo user agent every migrated visitor hash is built with.
     *
     * Heroic never stored a user agent, so there is nothing to carry over. A
     * fixed non-empty marker domain-separates imported identities from live
     * ones: even if the migration salt were somehow the same as a live salt, a
     * migrated row and a live row for the same visitor would not collide, so an
     * imported hash cannot be used to confirm a guess about a live one.
     *
     * @since 1.0.0
     * @var string
     */
    const IMPORT_AGENT = 'licensio-heroic-import';

    /**
     * Longest search term the target column holds, in characters.
     *
     * `licensio_search_log.term` is `varchar(191)`. Heroic's is `varchar(100)`,
     * so this never truncates in practice; it is here so a hand-widened Heroic
     * column cannot produce a row MySQL silently cuts.
     *
     * @since 1.0.0
     * @var int
     */
    const TERM_MAX_LENGTH = 191;

    /**
     * The visitor hash for a Heroic row.
     *
     * The address is read from the row, hashed and discarded. It is never
     * returned, never stored in a property and never logged.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $row  A Heroic row, which carries `user_ip`.
     * @param string               $salt The migration salt.
     *
     * @return string A 64-character lowercase hex digest.
     */
    public static function visitor_hash( array $row, $salt ) {
        $address = isset( $row[ Licensio_KB_Heroic_Import_Schema::COLUMN_IP ] )
            ? (string) $row[ Licensio_KB_Heroic_Import_Schema::COLUMN_IP ]
            : '';

        return Licensio_KB_Visitor::hash( $address, self::IMPORT_AGENT, (string) $salt );
    }

    /**
     * Reduce a Heroic `magnitude` to the target's vote value.
     *
     * Heroic stores 10 for up, 0 for down and 5 for neutral
     * (voting/php/ht-vote-class.php:55, :62, :69), and its own reporting query
     * counts `magnitude > 0` as an up vote and `magnitude = 0` as a down vote
     * (voting/php/ht-voting-database.php:369-370). This reproduces that split
     * exactly, so a migrated article's helpful/unhelpful counts match what
     * Heroic was reporting before the migration — including that a neutral vote
     * counted as helpful there and does here.
     *
     * @since 1.0.0
     *
     * @param mixed $magnitude The Heroic magnitude.
     *
     * @return int Licensio_KB_Feedback::VOTE_UP or Licensio_KB_Feedback::VOTE_DOWN.
     */
    public static function vote( $magnitude ) {
        return ( (int) $magnitude ) > 0 ? Licensio_KB_Feedback::VOTE_UP : Licensio_KB_Feedback::VOTE_DOWN;
    }

    /**
     * Reduce a Heroic search `source` to one of the target's source names.
     *
     * Heroic's column is an integer: 1 is the type-ahead, 2 is the full search
     * page (php/ht-knowledge-base-analytics-core.php:13-14). Rows written before
     * the column was added are NULL and are treated as full searches, which is
     * what they were — the type-ahead logger was introduced with the column.
     *
     * @since 1.0.0
     *
     * @param mixed $source The Heroic source value.
     *
     * @return string `live` or `site`.
     */
    public static function search_source( $source ) {
        if ( null === $source || '' === $source ) {
            return 'site';
        }

        return Licensio_KB_Heroic_Import_Schema::SOURCE_LIVE_SEARCH === (int) $source ? 'live' : 'site';
    }

    /**
     * Shift a Heroic datetime into UTC.
     *
     * Heroic is not internally consistent about this. Votes are written with
     * PHP's `date()` (voting/php/ht-voting-database.php:488), which is UTC under
     * WordPress; visits and searches are written with MySQL's `NOW()`
     * (php/ht-knowledge-base-analytics-core.php:144), which is the database
     * server's timezone. The target columns are UTC throughout, so the operator
     * supplies the offset and it is applied uniformly. Zero — the default —
     * means the source was already UTC.
     *
     * @since 1.0.0
     *
     * @param mixed $datetime A `Y-m-d H:i:s` string.
     * @param int   $offset   Seconds to subtract to reach UTC.
     *
     * @return string A `Y-m-d H:i:s` timestamp in UTC.
     */
    public static function to_utc( $datetime, $offset = 0 ) {
        $datetime = is_scalar( $datetime ) ? trim( (string) $datetime ) : '';

        if ( '' === $datetime || '0000-00-00 00:00:00' === $datetime ) {
            return '0000-00-00 00:00:00';
        }

        $timestamp = strtotime( $datetime . ' UTC' );

        if ( false === $timestamp ) {
            return '0000-00-00 00:00:00';
        }

        return gmdate( 'Y-m-d H:i:s', $timestamp - (int) $offset );
    }

    /**
     * Build a `licensio_feedback` row from a `hkb_voting` row.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $row        The Heroic voting row.
     * @param int                  $article_id The target article the vote belongs to.
     * @param string               $salt       The migration salt.
     * @param int                  $offset     Seconds to subtract to reach UTC.
     *
     * @return array<string, mixed> The row to insert. Carries no address.
     */
    public static function feedback_row( array $row, $article_id, $salt, $offset = 0 ) {
        return array(
            'article_id'   => (int) $article_id,
            'vote'         => self::vote( isset( $row['magnitude'] ) ? $row['magnitude'] : 0 ),
            'comment'      => Licensio_KB_Feedback::sanitize_comment( isset( $row['feedback'] ) ? (string) $row['feedback'] : '' ),
            'visitor_hash' => self::visitor_hash( $row, $salt ),
            'user_id'      => isset( $row['user_id'] ) ? max( 0, (int) $row['user_id'] ) : 0,
            'status'       => Licensio_KB_Feedback::STATUS_NEW,
            'created_at'   => self::to_utc( isset( $row['datetime'] ) ? $row['datetime'] : '', $offset ),
        );
    }

    /**
     * Build a `licensio_search_log` row from a `hkb_analytics_search_atomic` row.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $row    The Heroic search row.
     * @param string               $salt   The migration salt.
     * @param int                  $offset Seconds to subtract to reach UTC.
     *
     * @return array<string, mixed> The row to insert. Carries no address.
     */
    public static function search_log_row( array $row, $salt, $offset = 0 ) {
        $term = isset( $row['terms'] ) ? (string) $row['terms'] : '';
        $term = self::truncate( $term, self::TERM_MAX_LENGTH );

        return array(
            'term'            => $term,
            'normalized_term' => self::truncate( Licensio_KB_Search_Log::normalize( $term ), self::TERM_MAX_LENGTH ),
            'results_count'   => isset( $row['hits'] ) ? max( 0, (int) $row['hits'] ) : 0,
            'visitor_hash'    => self::visitor_hash( $row, $salt ),
            'source'          => self::search_source( isset( $row['source'] ) ? $row['source'] : null ),
            'created_at'      => self::to_utc( isset( $row['datetime'] ) ? $row['datetime'] : '', $offset ),
        );
    }

    /**
     * Build a `licensio_article_views` row from a `hkb_visits` row.
     *
     * The caller is responsible for having established that the visit was an
     * article view; `is_article_visit()` is the predicate it should use.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $row        The Heroic visit row.
     * @param int                  $article_id The target article that was viewed.
     * @param string               $salt       The migration salt.
     * @param int                  $offset     Seconds to subtract to reach UTC.
     *
     * @return array<string, mixed> The row to insert. Carries no address.
     */
    public static function article_view_row( array $row, $article_id, $salt, $offset = 0 ) {
        return array(
            'article_id'   => (int) $article_id,
            'visitor_hash' => self::visitor_hash( $row, $salt ),
            'created_at'   => self::to_utc( isset( $row['datetime'] ) ? $row['datetime'] : '', $offset ),
        );
    }

    /**
     * Whether a Heroic visit row records an article view.
     *
     * Heroic logs archive, category and tag views into the same table
     * (php/ht-knowledge-base-utility-functions.php:82-112). The target table
     * holds article views only, so the rest are counted and skipped rather than
     * bent into an `article_id` that would name a term.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $row The Heroic visit row.
     *
     * @return bool True when the row is an article view.
     */
    public static function is_article_visit( array $row ) {
        $type = isset( $row['object_type'] ) ? (string) $row['object_type'] : '';

        return Licensio_KB_Heroic_Import_Schema::OBJECT_TYPE_ARTICLE === $type;
    }

    /**
     * Translate Heroic's category term meta into the keys the plugin owns.
     *
     * `Licensio_KB_Term_Meta` owns the target keys and their sanitizers, so the
     * values are passed through them here rather than written raw.
     *
     * Three mappings are exact and one has no home:
     *
     * - `term_order` is an integer manual order, and so is `licensio_order`.
     * - `meta_image` is an attachment ID (Heroic hands it straight to
     *   `wp_get_attachment_image_src()`), and so is `licensio_image_id`.
     * - `meta_svg` is raw inline SVG markup — php/ht-knowledge-base-category-meta.php:150
     *   echoes it into the page. The target's `licensio_icon_svg` holds inline
     *   SVG too, but never raw: it goes through `Licensio_KB_SVG_Sanitizer`,
     *   which strips anything unsafe. Markup that survives survives, and markup
     *   the sanitizer empties is reported as unmapped rather than carried.
     * - `meta_svg_color` has no target field at all.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $meta The Heroic term meta, keyed by Heroic's key.
     *
     * @return array{meta: array<string, mixed>, unmapped: string[]} The target meta, and the Heroic
     *                                                                 keys that had a value but no home.
     */
    public static function category_meta( array $meta ) {
        $target = array();

        if ( isset( $meta[ Licensio_KB_Heroic_Import_Schema::META_TERM_ORDER ] ) ) {
            $target[ Licensio_KB_Term_Meta::META_ORDER ] = Licensio_KB_Term_Meta::sanitize_order(
                $meta[ Licensio_KB_Heroic_Import_Schema::META_TERM_ORDER ]
            );
        }

        if ( isset( $meta[ Licensio_KB_Heroic_Import_Schema::META_TERM_IMAGE ] ) ) {
            $image = Licensio_KB_Term_Meta::sanitize_attachment_id( $meta[ Licensio_KB_Heroic_Import_Schema::META_TERM_IMAGE ] );

            if ( $image > 0 ) {
                $target[ Licensio_KB_Term_Meta::META_IMAGE_ID ] = $image;
            }
        }

        $unmapped = array();

        $svg = isset( $meta[ Licensio_KB_Heroic_Import_Schema::META_TERM_SVG ] )
            ? trim( (string) $meta[ Licensio_KB_Heroic_Import_Schema::META_TERM_SVG ] )
            : '';

        if ( '' !== $svg ) {
            $sanitized = Licensio_KB_SVG_Sanitizer::sanitize( $svg );

            if ( '' !== $sanitized ) {
                $target[ Licensio_KB_Term_Meta::META_ICON_SVG ] = $sanitized;
            } else {
                $unmapped[] = Licensio_KB_Heroic_Import_Schema::META_TERM_SVG;
            }
        }

        $color = isset( $meta[ Licensio_KB_Heroic_Import_Schema::META_TERM_SVG_COLOR ] )
            ? trim( (string) $meta[ Licensio_KB_Heroic_Import_Schema::META_TERM_SVG_COLOR ] )
            : '';

        if ( '' !== $color ) {
            $unmapped[] = Licensio_KB_Heroic_Import_Schema::META_TERM_SVG_COLOR;
        }

        return array(
            'meta'     => $target,
            'unmapped' => $unmapped,
        );
    }

    /**
     * An article's per-category positions, and the one `menu_order` to fall back on.
     *
     * Heroic orders an article once per category it belongs to, in a separate
     * post meta key each time: `hkb_set_custom_article_order()` at
     * ht-knowledge-base.php:2722 writes `_ht_article_order_{term_id}`. An article
     * filed in three categories therefore carries three independent positions.
     * Heroic never writes `menu_order` at all, and does not even expose it — the
     * CPT's `supports` array (ht-knowledge-base.php:469) omits `page-attributes`.
     *
     * The target model keeps a position per category too
     * (`licensio_order_{term_id}`), so every numeric position is returned in
     * `positions`, keyed by the source term ID, for the importer to write
     * against the imported category.
     *
     * `menu_order` is still set, as the fallback for listings that are not
     * limited to one category. The rule for it: the position recorded against
     * the LOWEST category term ID wins. Two rules were considered and this one
     * chosen:
     *
     * - Not the smallest order value. That picks a position out of whichever
     *   category happens to hold a small number, which is not the article's
     *   place in any list a reader ever saw.
     * - Not "the primary category", because Heroic has no such concept —
     *   `hkb_set_custom_article_order()` is the only writer and it treats every
     *   category identically. Lowest term ID is the one deterministic,
     *   explainable stand-in: it names one real list, and it gives the same
     *   answer on a re-run.
     *
     * @since 1.0.0
     *
     * @param array<int, mixed> $orders The per-category positions, keyed by source term ID.
     *
     * @return array{order: int, positions: array<int, int>} The fallback position, and every
     *               numeric position keyed by source term ID, lowest term ID first.
     */
    public static function article_order( array $orders ) {
        $values = array();

        foreach ( $orders as $term_id => $order ) {
            if ( is_scalar( $order ) && is_numeric( $order ) ) {
                $values[ (int) $term_id ] = (int) $order;
            }
        }

        if ( empty( $values ) ) {
            return array(
                'order'     => 0,
                'positions' => array(),
            );
        }

        ksort( $values, SORT_NUMERIC );

        $term_ids = array_keys( $values );
        $chosen   = $term_ids[0];

        return array(
            'order'     => $values[ $chosen ],
            'positions' => $values,
        );
    }

    /**
     * Map Heroic's settings onto this plugin's.
     *
     * Read from the `ht_knowledge_base_settings` option; every key and default
     * here was read from Heroic 3.10.4 (php/ht-knowledge-base-settings.php).
     * URL slugs are deliberately not carried: changing this plugin's slugs
     * would move every URL the import has just redirected to. Everything else
     * with no equivalent is reported by key; no value of an unmapped setting is
     * returned, because the option also holds Heroic's licence key.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $settings The stored Heroic settings.
     *
     * @return array{settings: array<string, mixed>, excluded: array<string, string>, unmapped: string[]} Target
     *         setting to value, excluded source key to reason, and the unmapped source keys.
     */
    public static function settings( array $settings ) {
        $direct   = array(
            'num-articles'                     => 'articles_per_page',
            'archive-columns'                  => 'archive_columns',
            'kb-archive-page-header-text'      => 'archive_header_text',
            'search-placeholder-text'          => 'search_placeholder',
            'kb-headerstyle-img-attachment-id' => 'header_image_id',
            'kb-linkcolor'                     => 'brand_color',
        );
        $toggles  = array(
            'display-sub-cats'        => 'show_subcategories',
            'display-article-count'   => 'show_article_counts',
            'article-sticky-sidebar'  => 'sticky_sidebar',
            'enable-article-feedback' => 'enable_feedback',
        );
        $sidebars = array(
            'archive-sidebar-position'  => 'archive_sidebar_position',
            'category-sidebar-position' => 'category_sidebar_position',
            'article-sidebar-position'  => 'article_sidebar_position',
        );
        $slugs    = array( Licensio_KB_Heroic_Import_Schema::SETTING_ARTICLE_SLUG, Licensio_KB_Heroic_Import_Schema::SETTING_CATEGORY_SLUG, Licensio_KB_Heroic_Import_Schema::SETTING_TAG_SLUG );

        $out = array(
            'settings' => array(),
            'excluded' => array(),
            'unmapped' => array(),
        );

        foreach ( $settings as $key => $value ) {
            $key = (string) $key;

            if ( isset( $direct[ $key ] ) && is_scalar( $value ) && '' !== (string) $value ) {
                $out['settings'][ $direct[ $key ] ] = $value;
            } elseif ( isset( $toggles[ $key ] ) ) {
                $out['settings'][ $toggles[ $key ] ] = ! empty( $value ) && 'false' !== $value;
            } elseif ( isset( $sidebars[ $key ] ) && in_array( $value, array( 'left', 'right', 'off' ), true ) ) {
                $out['settings'][ $sidebars[ $key ] ] = 'off' === $value ? 'none' : $value;
            } elseif ( 'kb-headerstyle' === $key ) {
                $out['excluded'][ $key ] = 'Header style; the archive header shows the header image when one is set and is plain otherwise';
            } elseif ( in_array( $key, $slugs, true ) ) {
                $out['excluded'][ $key ] = 'URL slug; kept as it is so the imported redirects stay valid';
            } else {
                $out['unmapped'][] = $key;
            }
        }

        return $out;
    }

    /**
     * Cut a string to a character length without splitting a multibyte character.
     *
     * @since 1.0.0
     *
     * @param string $value  The value.
     * @param int    $length The maximum length in characters.
     *
     * @return string The value, no longer than $length characters.
     */
    private static function truncate( $value, $length ) {
        $value = (string) $value;

        if ( function_exists( 'mb_substr' ) ) {
            return mb_substr( $value, 0, (int) $length );
        }

        return substr( $value, 0, (int) $length );
    }
}
