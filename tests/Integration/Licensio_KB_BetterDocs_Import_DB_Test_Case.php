<?php
/**
 * Base case for importing a real BetterDocs database into a real target schema.
 *
 * Builds both halves for real on a scratch database: BetterDocs' three analytics
 * tables and the core posts and postmeta tables its articles live in, and the
 * plugin's own tables from the plugin's own installer. The importer's reads,
 * article lookup, multi-row inserts and import log then run their production
 * SQL against a server instead of against a double.
 *
 * What this harness cannot do is run WordPress. There is no `wp_insert_post()`,
 * `wp_insert_term()` or `WP_Query`, so the content phases, the score recompute
 * and the driver's `run_batch()` are out of reach here; the tests seed the
 * target articles the content phase would have created, as the Heroic suite
 * does, and drive `Licensio_KB_BetterDocs_Import_Data` directly.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

/*
 * This suite connects to a real database on purpose. The WordPress Database API
 * is therefore not available or appropriate here.
 */
// phpcs:disable WordPress.DB.RestrictedClasses, WordPress.DB.RestrictedFunctions, WordPress.DB.DirectDatabaseQuery

require_once __DIR__ . '/Licensio_KB_Integration_Test_Case.php';
require_once __DIR__ . '/class-licensio-kb-live-wpdb.php';

/**
 * A scratch database holding a BetterDocs schema and the target schema.
 *
 * @since 1.0.0
 */
abstract class Licensio_KB_BetterDocs_Import_DB_Test_Case extends Licensio_KB_Integration_Test_Case {

    /**
     * The table prefix used for the scratch schema.
     *
     * @since 1.0.0
     * @var string
     */
    const PREFIX = 'lkbbd_';

    /**
     * The phases of a BetterDocs import, in run order.
     *
     * @since 1.0.0
     * @var string[]
     */
    const PHASES = array( 'terms', 'articles', 'feedback', 'views', 'searches' );

    /**
     * The mysqli-backed $wpdb adapter.
     *
     * @since 1.0.0
     * @var Licensio_KB_Live_WPDB
     */
    protected $adapter;

    /**
     * The in-memory options table backing the import state.
     *
     * @since 1.0.0
     * @var array<string, mixed>
     */
    protected $options = array();

    /**
     * Create both schemas and point the plugin's gateway at them.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        $this->adapter   = new Licensio_KB_Live_WPDB( $this->db, self::PREFIX );
        $GLOBALS['wpdb'] = $this->adapter;
        $this->options   = array();

        foreach ( Licensio_KB_DB_Installer::get_schemas( self::PREFIX, 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci' ) as $name => $sql ) {
            $this->assertTrue( (bool) $this->db->query( $sql ), sprintf( 'Target schema %1$s failed: %2$s', $name, $this->db->error ) );
        }

        foreach ( $this->betterdocs_schemas() as $name => $sql ) {
            $this->assertTrue( (bool) $this->db->query( $sql ), sprintf( 'BetterDocs schema %1$s failed: %2$s', $name, $this->db->error ) );
        }

        foreach ( $this->core_schemas() as $name => $sql ) {
            $this->assertTrue( (bool) $this->db->query( $sql ), sprintf( 'Core schema %1$s failed: %2$s', $name, $this->db->error ) );
        }

        $this->stub_wordpress();
    }

    /**
     * Release the adapter.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function tearDown(): void {
        unset( $GLOBALS['wpdb'] );

        parent::tearDown();
    }

    /**
     * BetterDocs' three analytics tables.
     *
     * BetterDocs is not installed on the machine these tests run on, so these
     * carry the columns the import reads (`id`, `post_id`, the reaction and
     * impression counts, the keyword link and the day) rather than a verbatim
     * copy of BetterDocs' own DDL. The reads are keyed on `id` and filtered on
     * `created_at`, which is all the importer depends on.
     *
     * @since 1.0.0
     *
     * @return array<string, string> Table name to CREATE TABLE statement.
     */
    protected function betterdocs_schemas() {
        $prefix = self::PREFIX;

        return array(
            Licensio_KB_BetterDocs_Import_Schema::TABLE_ANALYTICS => "CREATE TABLE {$prefix}betterdocs_analytics (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                post_id bigint(20) unsigned NOT NULL,
                impressions int(11) NOT NULL DEFAULT 0,
                unique_visit int(11) NOT NULL DEFAULT 0,
                happy int(11) NOT NULL DEFAULT 0,
                normal int(11) NOT NULL DEFAULT 0,
                sad int(11) NOT NULL DEFAULT 0,
                created_at date NOT NULL,
                PRIMARY KEY  (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;",

            Licensio_KB_BetterDocs_Import_Schema::TABLE_SEARCH_KEYWORD => "CREATE TABLE {$prefix}betterdocs_search_keyword (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                keyword varchar(255) NOT NULL DEFAULT '',
                PRIMARY KEY  (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;",

            Licensio_KB_BetterDocs_Import_Schema::TABLE_SEARCH_LOG => "CREATE TABLE {$prefix}betterdocs_search_log (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                keyword_id bigint(20) unsigned NOT NULL,
                `count` int(11) NOT NULL DEFAULT 0,
                not_found_count int(11) NOT NULL DEFAULT 0,
                created_at date NOT NULL,
                PRIMARY KEY  (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;",
        );
    }

    /**
     * The core tables the importer's lookups read.
     *
     * Only the columns those lookups touch, but real tables: the article
     * lookup, the existence check and the term reads run their production SQL.
     *
     * @since 1.0.0
     *
     * @return array<string, string> Table name to CREATE TABLE statement.
     */
    protected function core_schemas() {
        $prefix = self::PREFIX;

        return array(
            'posts'              => "CREATE TABLE {$prefix}posts (
                ID bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                post_author bigint(20) unsigned NOT NULL DEFAULT 0,
                post_date datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
                post_title text NOT NULL,
                post_name varchar(200) NOT NULL DEFAULT '',
                post_type varchar(20) NOT NULL DEFAULT 'post',
                post_status varchar(20) NOT NULL DEFAULT 'publish',
                post_parent bigint(20) unsigned NOT NULL DEFAULT 0,
                menu_order int(11) NOT NULL DEFAULT 0,
                PRIMARY KEY  (ID),
                KEY type_status (post_type, post_status)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;",

            'postmeta'           => "CREATE TABLE {$prefix}postmeta (
                meta_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                post_id bigint(20) unsigned NOT NULL DEFAULT 0,
                meta_key varchar(255) DEFAULT NULL,
                meta_value longtext,
                PRIMARY KEY  (meta_id),
                KEY post_id (post_id),
                KEY meta_key (meta_key(191))
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;",

            'terms'              => "CREATE TABLE {$prefix}terms (
                term_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                name varchar(200) NOT NULL DEFAULT '',
                slug varchar(200) NOT NULL DEFAULT '',
                term_group bigint(10) NOT NULL DEFAULT 0,
                PRIMARY KEY  (term_id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;",

            'term_taxonomy'      => "CREATE TABLE {$prefix}term_taxonomy (
                term_taxonomy_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                term_id bigint(20) unsigned NOT NULL DEFAULT 0,
                taxonomy varchar(32) NOT NULL DEFAULT '',
                description longtext NOT NULL,
                parent bigint(20) unsigned NOT NULL DEFAULT 0,
                count bigint(20) NOT NULL DEFAULT 0,
                PRIMARY KEY  (term_taxonomy_id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;",

            'term_relationships' => "CREATE TABLE {$prefix}term_relationships (
                object_id bigint(20) unsigned NOT NULL DEFAULT 0,
                term_taxonomy_id bigint(20) unsigned NOT NULL DEFAULT 0,
                term_order int(11) NOT NULL DEFAULT 0,
                PRIMARY KEY  (object_id, term_taxonomy_id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;",

            'termmeta'           => "CREATE TABLE {$prefix}termmeta (
                meta_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                term_id bigint(20) unsigned NOT NULL DEFAULT 0,
                meta_key varchar(255) DEFAULT NULL,
                meta_value longtext,
                PRIMARY KEY  (meta_id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;",
        );
    }

    /**
     * Supply the WordPress functions the importer's write path calls.
     *
     * The options API is backed by an in-memory store so the resume state
     * behaves as it does in production, which is what lets a test stop a run
     * part-way and start a second one.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function stub_wordpress() {
        Brain\Monkey\Functions\when( 'get_option' )->alias(
            function ( $name, $fallback = false ) {
                return array_key_exists( (string) $name, $this->options ) ? $this->options[ (string) $name ] : $fallback;
            }
        );

        Brain\Monkey\Functions\when( 'update_option' )->alias(
            function ( $name, $value ) {
                $this->options[ (string) $name ] = $value;

                return true;
            }
        );

        Brain\Monkey\Functions\when( 'delete_option' )->alias(
            function ( $name ) {
                unset( $this->options[ (string) $name ] );

                return true;
            }
        );

        Brain\Monkey\Functions\when( 'sanitize_key' )->alias(
            function ( $value ) {
                return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
            }
        );

        Brain\Monkey\Functions\when( 'sanitize_title' )->alias(
            function ( $value ) {
                return trim( (string) preg_replace( '/[^a-z0-9_-]+/', '-', strtolower( strip_tags( (string) $value ) ) ), '-' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
            }
        );

        Brain\Monkey\Functions\when( 'maybe_unserialize' )->alias(
            function ( $value ) {
                if ( ! is_string( $value ) ) {
                    return $value;
                }

                $decoded = @unserialize( $value ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize

                return false === $decoded && 'b:0;' !== $value ? $value : $decoded;
            }
        );

        Brain\Monkey\Functions\when( 'home_url' )->alias(
            function ( $path = '' ) {
                return 'https://example.test' . $path;
            }
        );

        Brain\Monkey\Functions\when( 'wp_json_encode' )->alias(
            function ( $value ) {
                return json_encode( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
            }
        );

        Brain\Monkey\Functions\when( 'wp_list_pluck' )->alias(
            function ( $items, $field ) {
                return array_column( (array) $items, $field );
            }
        );

        Brain\Monkey\Functions\when( 'current_time' )->alias(
            function () {
                return gmdate( 'Y-m-d H:i:s' );
            }
        );

        Brain\Monkey\Functions\stubTranslationFunctions();
    }

    /**
     * The fully qualified name of one of the scratch tables.
     *
     * @since 1.0.0
     *
     * @param string $table The unprefixed table name.
     *
     * @return string The prefixed table name.
     */
    protected function table( $table ) {
        return self::PREFIX . $table;
    }

    /**
     * Insert rows straight into a scratch table.
     *
     * Bypasses every importer code path on purpose: these rows stand for what
     * the live BetterDocs site already contains.
     *
     * @since 1.0.0
     *
     * @param string                           $table The unprefixed table name.
     * @param array<int, array<string, mixed>> $rows  The rows to insert.
     *
     * @return void
     */
    protected function seed( $table, array $rows ) {
        foreach ( $rows as $row ) {
            $columns = array();
            $values  = array();

            foreach ( $row as $column => $value ) {
                $columns[] = '`' . $column . '`';

                if ( null === $value ) {
                    $values[] = 'NULL';
                } elseif ( is_int( $value ) ) {
                    $values[] = (string) $value;
                } else {
                    $values[] = "'" . $this->db->real_escape_string( (string) $value ) . "'";
                }
            }

            $sql = 'INSERT INTO ' . $this->table( $table )
                . ' (' . implode( ', ', $columns ) . ') VALUES (' . implode( ', ', $values ) . ')';

            $this->assertTrue( (bool) $this->db->query( $sql ), $this->db->error );
        }
    }

    /**
     * Create a BetterDocs article in the source posts table.
     *
     * @since 1.0.0
     *
     * @param int    $post_id The BetterDocs post ID.
     * @param string $slug    The article slug.
     *
     * @return void
     */
    protected function seed_source_article( $post_id, $slug ) {
        $this->seed(
            'posts',
            array(
                array(
                    'ID'          => (int) $post_id,
                    'post_title'  => 'Doc ' . (int) $post_id,
                    'post_name'   => $slug,
                    'post_type'   => Licensio_KB_BetterDocs_Import_Schema::POST_TYPE,
                    'post_status' => 'publish',
                ),
            )
        );
    }

    /**
     * Create a target article that claims to have come from a BetterDocs article.
     *
     * This is the state the content phase leaves behind, and the state the data
     * phases depend on: a `licensio_article` post carrying the provenance meta
     * the article lookup reads.
     *
     * @since 1.0.0
     *
     * @param int    $article_id  The target article ID.
     * @param int    $betterdocs_id The BetterDocs post ID it came from.
     * @param string $slug        The article slug.
     *
     * @return void
     */
    protected function seed_imported_article( $article_id, $betterdocs_id, $slug = 'article' ) {
        $this->seed(
            'posts',
            array(
                array(
                    'ID'          => (int) $article_id,
                    'post_title'  => 'Article ' . (int) $article_id,
                    'post_name'   => $slug,
                    'post_type'   => Licensio_KB_Post_Types::POST_TYPE,
                    'post_status' => 'publish',
                ),
            )
        );

        $this->seed(
            'postmeta',
            array(
                array(
                    'post_id'    => (int) $article_id,
                    'meta_key'   => Licensio_KB_Import_Source::provenance_post_key_for( 'betterdocs' ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                    'meta_value' => (string) (int) $betterdocs_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
                ),
            )
        );
    }

    /**
     * Create a BetterDocs term, with its taxonomy row and meta.
     *
     * @since 1.0.0
     *
     * @param int                  $term_id  The term ID.
     * @param string               $taxonomy The taxonomy.
     * @param string               $name     The term name.
     * @param int                  $parent_id The parent term ID.
     * @param array<string, mixed> $meta     Term meta, key to raw stored value.
     *
     * @return void
     */
    protected function seed_term( $term_id, $taxonomy, $name, $parent_id = 0, array $meta = array() ) {
        $this->seed(
            'terms',
            array(
                array(
                    'term_id' => (int) $term_id,
                    'name'    => $name,
                    'slug'    => strtolower( str_replace( ' ', '-', $name ) ),
                ),
            )
        );

        $this->seed(
            'term_taxonomy',
            array(
                array(
                    'term_taxonomy_id' => (int) $term_id,
                    'term_id'          => (int) $term_id,
                    'taxonomy'         => $taxonomy,
                    'description'      => '',
                    'parent'           => (int) $parent_id,
                    'count'            => 1,
                ),
            )
        );

        foreach ( $meta as $key => $value ) {
            $this->seed(
                'termmeta',
                array(
                    array(
                        'term_id'    => (int) $term_id,
                        'meta_key'   => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                        'meta_value' => $value, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
                    ),
                )
            );
        }
    }

    /**
     * File a post under a term.
     *
     * @since 1.0.0
     *
     * @param int $post_id The post ID.
     * @param int $term_id The term ID; the taxonomy row shares it.
     *
     * @return void
     */
    protected function file_under( $post_id, $term_id ) {
        $this->seed(
            'term_relationships',
            array(
                array(
                    'object_id'        => (int) $post_id,
                    'term_taxonomy_id' => (int) $term_id,
                ),
            )
        );
    }

    /**
     * How many rows a scratch table holds.
     *
     * @since 1.0.0
     *
     * @param string $table The unprefixed table name.
     *
     * @return int The row count.
     */
    protected function count_rows( $table ) {
        return (int) $this->db->query( 'SELECT COUNT(*) AS total FROM ' . $this->table( $table ) )->fetch_object()->total;
    }

    /**
     * Every row of one scratch table, as associative arrays.
     *
     * @since 1.0.0
     *
     * @param string $table The unprefixed table name.
     * @param string $order The ORDER BY clause, without the keyword.
     *
     * @return array<int, array<string, mixed>> The rows.
     */
    protected function fetch_all( $table, $order = '' ) {
        $sql = 'SELECT * FROM ' . $this->table( $table );

        if ( '' !== $order ) {
            $sql .= ' ORDER BY ' . $order;
        }

        $result = $this->db->query( $sql );
        $rows   = array();

        if ( ! $result instanceof mysqli_result ) {
            return $rows;
        }

        while ( $row = $result->fetch_assoc() ) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * How many rows the data tables hold in total.
     *
     * The import log is deliberately excluded: a dry run writes it by design.
     *
     * @since 1.0.0
     *
     * @return array<string, int> Table name to row count.
     */
    protected function data_table_counts() {
        return array(
            Licensio_KB_Feedback::TABLE                => $this->count_rows( Licensio_KB_Feedback::TABLE ),
            Licensio_KB_Analytics::TABLE_ARTICLE_VIEWS => $this->count_rows( Licensio_KB_Analytics::TABLE_ARTICLE_VIEWS ),
            Licensio_KB_Search_Log::TABLE              => $this->count_rows( Licensio_KB_Search_Log::TABLE ),
        );
    }

    /**
     * A BetterDocs import state.
     *
     * @since 1.0.0
     *
     * @param bool $dry_run Whether it is a dry run's state.
     *
     * @return Licensio_KB_Import_State The state, reloaded from the option store.
     */
    protected function new_state( $dry_run = false ) {
        return new Licensio_KB_Import_State( 'betterdocs', self::PHASES, true, $dry_run );
    }

    /**
     * A BetterDocs import report.
     *
     * @since 1.0.0
     *
     * @param bool $dry_run Whether it is a dry run's report.
     *
     * @return Licensio_KB_Import_Report The report.
     */
    protected function new_report( $dry_run = false ) {
        return new Licensio_KB_Import_Report( 'betterdocs', $dry_run );
    }

    /**
     * Build the importer wired to this scratch database.
     *
     * @since 1.0.0
     *
     * @param Licensio_KB_Import_State  $state   The resume point.
     * @param Licensio_KB_Import_Report $report  The run report.
     * @param bool                      $dry_run Whether the importer may write.
     * @param Licensio_KB_Import_Log    $log     The import log; none when null.
     *
     * @return Licensio_KB_BetterDocs_Import_Data The importer under test.
     */
    protected function data_importer( $state, $report, $dry_run = false, $log = null ) {
        $source  = new Licensio_KB_BetterDocs_Import_Source();
        $content = new Licensio_KB_Import_Content(
            $source,
            $state,
            new Licensio_KB_Import_Redirects( 'betterdocs', sys_get_temp_dir() . '/licensio-kb-betterdocs-unused', true ),
            $report,
            $dry_run
        );

        return new Licensio_KB_BetterDocs_Import_Data( $source, $state, $content, $report, $dry_run, $log );
    }

    /**
     * Run every data phase to exhaustion.
     *
     * @since 1.0.0
     *
     * @param Licensio_KB_BetterDocs_Import_Data $importer The importer.
     * @param int                                $batch    The batch size.
     *
     * @return void
     */
    protected function run_all_data_phases( Licensio_KB_BetterDocs_Import_Data $importer, $batch = 100 ) {
        foreach ( array( 'migrate_feedback_batch', 'migrate_views_batch', 'migrate_searches_batch' ) as $method ) {
            do {
                $read = $importer->{$method}( $batch );
            } while ( $read > 0 );
        }
    }
}
