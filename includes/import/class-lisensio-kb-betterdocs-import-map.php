<?php
/**
 * How each BetterDocs value becomes a value here.
 *
 * Every function is pure: rows in, rows out, no database. That is what lets
 * the mapping be tested exhaustively without a BetterDocs install.
 *
 * BetterDocs keeps analytics as daily totals — one row per article per day with
 * impressions, unique visitors and happy/normal/sad reaction counts, and one
 * row per keyword per day with found and not-found search counts. This plugin
 * keeps one row per event. The totals are expanded back into events: a day
 * with 12 impressions becomes 12 view rows on that day. The events are dated
 * at noon UTC, because the day is all BetterDocs recorded and noon keeps the
 * row on the same calendar day in every timezone a report might use.
 *
 * Visitor identities are synthetic. BetterDocs never stored an IP or any other
 * visitor identifier, so there is nothing personal to carry; the hashes are
 * keyed only by the source row, so 12 impressions from 4 unique visitors
 * become 12 view rows shared among 4 identities, and the unique-visitor count
 * survives approximately.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Maps BetterDocs rows onto this plugin's rows.
 *
 * @since 1.0.0
 */
class Lisensio_KB_BetterDocs_Import_Map {

    /**
     * The stand-in user agent every imported identity is hashed with.
     *
     * Domain-separates imported identities from live ones, as the Heroic
     * import does with its own marker.
     *
     * @since 1.0.0
     * @var string
     */
    const IMPORT_AGENT = 'lisensio-betterdocs-import';

    /**
     * The longest search term the log column holds.
     *
     * @since 1.0.0
     * @var int
     */
    const TERM_MAX_LENGTH = 191;

    /**
     * The time of day an expanded daily event is given, UTC.
     *
     * @since 1.0.0
     * @var string
     */
    const EVENT_TIME = '12:00:00';

    /**
     * Map a BetterDocs category's meta onto this plugin's term meta.
     *
     * - `doc_category_order` (and `kb_order` on a knowledge base) is an integer
     *   manual order, and so is `lisensio_order`.
     * - `doc_category_image-id` is the category icon, an attachment ID, and so
     *   is `lisensio_icon_id`.
     * - `doc_category_thumb-id` is the category image, an attachment ID, and so
     *   is `lisensio_image_id`.
     *
     * The knowledge-base membership and the article order are carried by the
     * import itself (the category's parent and the articles' menu order), so
     * they are not reported as unmapped. Anything else with a value is.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $meta The BetterDocs term meta.
     *
     * @return array{meta: array<string, mixed>, unmapped: string[]} The target meta and the unmapped keys.
     */
    public static function category_meta( array $meta ) {
        $target = array();

        foreach ( array( Lisensio_KB_BetterDocs_Import_Schema::META_CATEGORY_ORDER, Lisensio_KB_BetterDocs_Import_Schema::META_KB_ORDER ) as $order_key ) {
            if ( ! isset( $target[ Lisensio_KB_Term_Meta::META_ORDER ] ) && isset( $meta[ $order_key ] ) && is_numeric( $meta[ $order_key ] ) ) {
                $target[ Lisensio_KB_Term_Meta::META_ORDER ] = Lisensio_KB_Term_Meta::sanitize_order( $meta[ $order_key ] );
            }
        }

        $attachments = array(
            Lisensio_KB_BetterDocs_Import_Schema::META_CATEGORY_IMAGE => Lisensio_KB_Term_Meta::META_ICON_ID,
            'doc_category_thumb-id' => Lisensio_KB_Term_Meta::META_IMAGE_ID,
        );

        foreach ( $attachments as $from => $to ) {
            if ( isset( $meta[ $from ] ) ) {
                $id = Lisensio_KB_Term_Meta::sanitize_attachment_id( $meta[ $from ] );

                if ( $id > 0 ) {
                    $target[ $to ] = $id;
                }
            }
        }

        $handled  = array_merge( array_keys( $attachments ), self::handled_term_meta() );
        $unmapped = array();

        foreach ( $meta as $key => $value ) {
            if ( in_array( (string) $key, $handled, true ) || '' === trim( is_scalar( $value ) ? (string) $value : 'x' ) ) {
                continue;
            }

            $unmapped[] = (string) $key;
        }

        return array(
            'meta'     => $target,
            'unmapped' => $unmapped,
        );
    }

    /**
     * Category meta keys the import carries by other means than a meta copy.
     *
     * @since 1.0.0
     *
     * @return string[] The keys.
     */
    public static function handled_term_meta() {
        return array(
            Lisensio_KB_BetterDocs_Import_Schema::META_CATEGORY_ORDER,
            Lisensio_KB_BetterDocs_Import_Schema::META_KB_ORDER,
            Lisensio_KB_BetterDocs_Import_Schema::META_CATEGORY_KNOWLEDGE_BASE,
            Lisensio_KB_BetterDocs_Import_Schema::META_DOCS_ORDER,
        );
    }

    /**
     * An article's position in each category whose order lists it.
     *
     * @since 1.0.0
     *
     * @param int                $post_id The BetterDocs post ID.
     * @param array<int, string> $orders  Category term ID to its `_docs_order` value.
     *
     * @return array<int, int> Category term ID to the article's 0-based position.
     */
    public static function positions( $post_id, array $orders ) {
        $positions = array();

        foreach ( $orders as $term_id => $list ) {
            $ids = array_values( array_map( 'intval', array_filter( array_map( 'trim', explode( ',', (string) $list ) ), array( __CLASS__, 'is_filled' ) ) ) );
            $at  = array_search( (int) $post_id, $ids, true );

            if ( false !== $at ) {
                $positions[ (int) $term_id ] = (int) $at;
            }
        }

        return $positions;
    }

    /**
     * The knowledge-base slugs a category belongs to.
     *
     * @since 1.0.0
     *
     * @param mixed $value The stored `doc_category_knowledge_base` value: a serialised array.
     *
     * @return string[] The slugs, in stored order.
     */
    public static function knowledge_bases( $value ) {
        $value = maybe_unserialize( $value );

        if ( is_string( $value ) && '' !== $value ) {
            $value = array( $value );
        }

        if ( ! is_array( $value ) ) {
            return array();
        }

        return array_values( array_filter( array_map( 'sanitize_title', array_map( 'strval', $value ) ), array( __CLASS__, 'is_filled' ) ) );
    }

    /**
     * A BetterDocs day as a datetime.
     *
     * @since 1.0.0
     *
     * @param mixed $date The stored `created_at` date, Y-m-d.
     *
     * @return string The datetime at noon, or the zero date when the day is unusable.
     */
    public static function day_to_datetime( $date ) {
        $date = is_scalar( $date ) ? trim( (string) $date ) : '';

        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || '0000-00-00' === $date ) {
            return '0000-00-00 00:00:00';
        }

        return $date . ' ' . self::EVENT_TIME;
    }

    /**
     * A synthetic visitor hash, keyed by where the event came from.
     *
     * @since 1.0.0
     *
     * @param string $identity What the identity is derived from.
     * @param string $salt     The run salt.
     *
     * @return string A 64-character hex digest.
     */
    public static function visitor_hash( $identity, $salt ) {
        return Lisensio_KB_Visitor::hash( 'betterdocs:' . (string) $identity, self::IMPORT_AGENT, (string) $salt );
    }

    /**
     * Expand one day's reactions into feedback rows.
     *
     * Happy and normal count as helpful and sad as not, the same rule the
     * Heroic import applies to its neutral vote.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $row        The `betterdocs_analytics` row.
     * @param int                  $article_id The target article ID.
     * @param string               $salt       The run salt.
     *
     * @return array<int, array<string, mixed>> The feedback rows.
     */
    public static function feedback_rows( array $row, $article_id, $salt ) {
        $rows  = array();
        $id    = isset( $row['id'] ) ? (int) $row['id'] : 0;
        $when  = self::day_to_datetime( isset( $row['created_at'] ) ? $row['created_at'] : '' );
        $kinds = array(
            'happy'  => Lisensio_KB_Feedback::VOTE_UP,
            'normal' => Lisensio_KB_Feedback::VOTE_UP,
            'sad'    => Lisensio_KB_Feedback::VOTE_DOWN,
        );

        foreach ( $kinds as $kind => $vote ) {
            $count = isset( $row[ $kind ] ) ? max( 0, (int) $row[ $kind ] ) : 0;

            for ( $i = 0; $i < $count; $i++ ) {
                $rows[] = array(
                    'article_id'   => (int) $article_id,
                    'vote'         => $vote,
                    'comment'      => '',
                    'visitor_hash' => self::visitor_hash( 'reaction:' . $id . ':' . $kind . ':' . $i, $salt ),
                    'user_id'      => 0,
                    'status'       => Lisensio_KB_Feedback::STATUS_NEW,
                    'created_at'   => $when,
                );
            }
        }

        return $rows;
    }

    /**
     * Expand one day's impressions into view rows.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $row        The `betterdocs_analytics` row.
     * @param int                  $article_id The target article ID.
     * @param string               $salt       The run salt.
     *
     * @return array<int, array<string, mixed>> The view rows.
     */
    public static function view_rows( array $row, $article_id, $salt ) {
        $id     = isset( $row['id'] ) ? (int) $row['id'] : 0;
        $count  = isset( $row['impressions'] ) ? max( 0, (int) $row['impressions'] ) : 0;
        $unique = isset( $row['unique_visit'] ) ? (int) $row['unique_visit'] : 0;
        $unique = max( 1, min( $count, $unique > 0 ? $unique : $count ) );
        $when   = self::day_to_datetime( isset( $row['created_at'] ) ? $row['created_at'] : '' );
        $rows   = array();

        for ( $i = 0; $i < $count; $i++ ) {
            $rows[] = array(
                'article_id'   => (int) $article_id,
                'visitor_hash' => self::visitor_hash( 'view:' . $id . ':' . ( $i % $unique ), $salt ),
                'created_at'   => $when,
            );
        }

        return $rows;
    }

    /**
     * Expand one keyword's day of searches into search log rows.
     *
     * BetterDocs counts searches that found something and searches that found
     * nothing separately (includes/Core/Query.php:1546-1556), and does not
     * store how many results a search found. A search that found something is
     * logged with one result: enough for every "found / not found" report,
     * though not the exact count.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $row  The `betterdocs_search_log` row, with its `keyword`.
     * @param string               $salt The run salt.
     *
     * @return array<int, array<string, mixed>> The search log rows; none when the keyword is empty.
     */
    public static function search_rows( array $row, $salt ) {
        $term = isset( $row['keyword'] ) ? self::truncate( trim( (string) $row['keyword'] ), self::TERM_MAX_LENGTH ) : '';

        if ( '' === $term ) {
            return array();
        }

        $id         = isset( $row['id'] ) ? (int) $row['id'] : 0;
        $when       = self::day_to_datetime( isset( $row['created_at'] ) ? $row['created_at'] : '' );
        $normalized = self::truncate( Lisensio_KB_Search_Log::normalize( $term ), self::TERM_MAX_LENGTH );
        $rows       = array();
        $groups     = array(
            1 => isset( $row['count'] ) ? max( 0, (int) $row['count'] ) : 0,
            0 => isset( $row['not_found_count'] ) ? max( 0, (int) $row['not_found_count'] ) : 0,
        );

        foreach ( $groups as $results => $count ) {
            for ( $i = 0; $i < $count; $i++ ) {
                $rows[] = array(
                    'term'            => $term,
                    'normalized_term' => $normalized,
                    'results_count'   => $results,
                    'visitor_hash'    => self::visitor_hash( 'search:' . $id . ':' . $results . ':' . $i, $salt ),
                    'source'          => 'site',
                    'created_at'      => $when,
                );
            }
        }

        return $rows;
    }

    /**
     * Map BetterDocs' settings onto this plugin's.
     *
     * Read from the `betterdocs_settings` option; every key and default here
     * was read from BetterDocs 4.9.3 (includes/Core/Settings.php:225-330). URL
     * settings are deliberately not carried, because changing this plugin's
     * URLs would move every address the import has just redirected to.
     * Everything else with no equivalent is reported by key only: the option
     * also holds API keys.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $settings The stored BetterDocs settings.
     *
     * @return array{settings: array<string, mixed>, excluded: array<string, string>, unmapped: string[]} Target
     *         setting to value, excluded source key to reason, and the unmapped source keys.
     */
    public static function settings( array $settings ) {
        $direct  = array(
            'search_placeholder' => 'search_placeholder',
            'column_number'      => 'archive_columns',
            'posts_number'       => 'articles_per_page',
        );
        $toggles = array(
            'nested_subcategory' => 'show_subcategories',
            'post_count'         => 'show_article_counts',
            'enable_toc'         => 'show_table_of_contents',
        );
        $urls    = array( 'docs_slug', 'category_slug', 'tag_slug', 'permalink_structure', 'enable_category_hierarchy_slugs', 'docs_page', 'builtin_doc_page' );

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
            } elseif ( in_array( $key, $urls, true ) ) {
                $out['excluded'][ $key ] = 'URL setting; kept as it is so the imported redirects stay valid';
            } else {
                $out['unmapped'][] = $key;
            }
        }

        return $out;
    }

    /**
     * Whether a string is non-empty, for array_filter().
     *
     * @since 1.0.0
     *
     * @param string $value The value.
     *
     * @return bool True when the string has at least one character.
     */
    public static function is_filled( $value ) {
        return '' !== (string) $value;
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
            return mb_substr( $value, 0, $length );
        }

        return substr( $value, 0, $length );
    }
}
