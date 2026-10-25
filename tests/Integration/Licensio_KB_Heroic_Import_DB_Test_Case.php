<?php
/**
 * Base case for migrating a real Heroic database into a real target schema.
 *
 * A migrator is the thing test doubles cannot honestly answer. A `$wpdb` double
 * can record that an INSERT was requested; only a server can say whether the row
 * that landed has the values it should, whether a second run inserted it twice,
 * and — the point of the whole exercise — whether an address that was demonstrably
 * present on the source side is demonstrably absent on the target side.
 *
 * So this builds both halves for real on a scratch database: Heroic's four
 * tables using Heroic's own CREATE TABLE statements, verbatim from the installed
 * 3.10.4 source, and the plugin's five tables from the plugin's own installer.
 * It also creates real `posts` and `postmeta` tables, so the migrator's article
 * lookup is the production lookup rather than a stub.
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
 * A scratch database holding both a Heroic schema and the target schema.
 *
 * @since 1.0.0
 */
abstract class Licensio_KB_Heroic_Import_DB_Test_Case extends Licensio_KB_Integration_Test_Case {

    /**
     * The table prefix used for the scratch schema.
     *
     * @since 1.0.0
     * @var string
     */
    const PREFIX = 'lkbmig_';

    /**
     * The phases of a Heroic import, in run order.
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
     * The in-memory options table backing the migration state.
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

        foreach ( $this->heroic_schemas() as $name => $sql ) {
            $this->assertTrue( (bool) $this->db->query( $sql ), sprintf( 'Heroic schema %1$s failed: %2$s', $name, $this->db->error ) );
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
     * Heroic's four tables, exactly as Heroic creates them.
     *
     * Copied from the installed 3.10.4 source rather than paraphrased, so the
     * migration is tested against the column types, nullability and charset the
     * live site actually has — including `source INT(4) NULL`, which is an
     * integer and not the string the audit implies, and `user_ip VARCHAR(15)`,
     * which is present on every one of them.
     *
     * @since 1.0.0
     *
     * @return array<string, string> Table name to CREATE TABLE statement.
     */
    protected function heroic_schemas() {
        $prefix = self::PREFIX;

        return array(
            // voting/php/ht-voting-database.php:271.
            Licensio_KB_Heroic_Import_Schema::TABLE_VOTING => "CREATE TABLE {$prefix}hkb_voting (
                vote_id BIGINT(20) unsigned NOT NULL AUTO_INCREMENT,
                vote_key VARCHAR(32) NOT NULL,
                post_id BIGINT(20) unsigned NOT NULL,
                user_id BIGINT(20) unsigned NOT NULL,
                user_ip VARCHAR(15) NOT NULL,
                datetime DATETIME NOT NULL,
                magnitude SMALLINT NOT NULL,
                feedback TEXT,
                PRIMARY KEY (vote_id)
            ) CHARACTER SET utf8 COLLATE utf8_general_ci;",

            // php/ht-knowledge-base-view-count.php:158.
            Licensio_KB_Heroic_Import_Schema::TABLE_VISITS => "CREATE TABLE {$prefix}hkb_visits (
                visit_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                object_type VARCHAR(50) NOT NULL,
                object_id bigint(20) unsigned NOT NULL,
                user_id BIGINT(20) unsigned NOT NULL,
                user_ip VARCHAR(15) NOT NULL,
                datetime DATETIME NOT NULL,
                duration INT(11) NOT NULL,
                PRIMARY KEY (visit_id)
            ) CHARACTER SET utf8 COLLATE utf8_general_ci;",

            // exits/php/ht-kb-exits-database.php:70.
            Licensio_KB_Heroic_Import_Schema::TABLE_EXITS  => "CREATE TABLE {$prefix}hkb_exits (
                exit_id BIGINT(20) unsigned NOT NULL AUTO_INCREMENT,
                object_type VARCHAR(50) NOT NULL,
                object_id bigint(20) unsigned NOT NULL,
                source VARCHAR(50),
                user_id BIGINT(20) unsigned NOT NULL,
                user_ip VARCHAR(15) NOT NULL,
                datetime DATETIME NOT NULL,
                url VARCHAR(2083) NOT NULL,
                PRIMARY KEY (exit_id)
            ) CHARACTER SET utf8 COLLATE utf8_general_ci;",

            // php/ht-knowledge-base-analytics-core.php:271.
            Licensio_KB_Heroic_Import_Schema::TABLE_SEARCHES => "CREATE TABLE {$prefix}hkb_analytics_search_atomic (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                terms VARCHAR(100) NOT NULL,
                datetime DATETIME NOT NULL,
                hits INT(11) NOT NULL,
                user_id BIGINT(20) unsigned NOT NULL,
                user_ip VARCHAR(15) NOT NULL,
                source INT( 4 ) NULL,
                PRIMARY KEY  (id)
            ) CHARACTER SET utf8 COLLATE utf8_general_ci;",
        );
    }

    /**
     * The two core tables the migrator's article lookup reads.
     *
     * Only the columns that lookup touches, but real tables: the point is that
     * `Licensio_KB_Import_Content::find_article()` runs its production
     * SQL rather than being replaced by a stub that cannot get it wrong.
     *
     * @since 1.0.0
     *
     * @return array<string, string> Table name to CREATE TABLE statement.
     */
    protected function core_schemas() {
        $prefix = self::PREFIX;

        return array(
            'posts'    => "CREATE TABLE {$prefix}posts (
                ID bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                post_author bigint(20) unsigned NOT NULL DEFAULT 0,
                post_date datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
                post_title text NOT NULL,
                post_content longtext,
                post_name varchar(200) NOT NULL DEFAULT '',
                post_type varchar(20) NOT NULL DEFAULT 'post',
                post_status varchar(20) NOT NULL DEFAULT 'publish',
                post_parent bigint(20) unsigned NOT NULL DEFAULT 0,
                menu_order int(11) NOT NULL DEFAULT 0,
                PRIMARY KEY  (ID),
                KEY type_status (post_type, post_status)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;",

            'postmeta' => "CREATE TABLE {$prefix}postmeta (
                meta_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                post_id bigint(20) unsigned NOT NULL DEFAULT 0,
                meta_key varchar(255) DEFAULT NULL,
                meta_value longtext,
                PRIMARY KEY  (meta_id),
                KEY post_id (post_id),
                KEY meta_key (meta_key(191))
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;",
        );
    }

    /**
     * Supply the WordPress functions the migrator's write path calls.
     *
     * The options API is backed by an in-memory store so the resume state
     * behaves exactly as it does in production, which is what lets a test kill a
     * run part-way and start a second one.
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

        Brain\Monkey\Functions\when( 'sanitize_textarea_field' )->alias(
            function ( $value ) {
                return trim( strip_tags( (string) $value ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
            }
        );

        Brain\Monkey\Functions\when( 'wp_generate_password' )->alias(
            function ( $length = 12 ) {
                return substr( str_repeat( 'a1B2c3D4e5F6g7H8', 8 ), 0, (int) $length );
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
     * Bypasses every migrator code path on purpose: these rows stand for what
     * the live Heroic site already contains, and driving a writer to create them
     * would make a seeding failure and a migration failure look the same.
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
     * Create a target article that claims to have come from a Heroic article.
     *
     * This is the state the content phase leaves behind, and the state the data
     * phases depend on: a `licensio_article` post carrying the provenance meta
     * the article lookup reads.
     *
     * @since 1.0.0
     *
     * @param int    $article_id The target article ID.
     * @param int    $heroic_id  The Heroic post ID it came from.
     * @param string $slug       The article slug.
     *
     * @return void
     */
    protected function seed_migrated_article( $article_id, $heroic_id, $slug = 'article' ) {
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
                    'meta_key'   => '_licensio_heroic_post_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                    'meta_value' => (string) (int) $heroic_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
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
     * Every value stored in every column of every row of every target table.
     *
     * Deliberately schema-blind. A scan that checked only for a column called
     * `user_ip` would miss an address written into a column named something
     * else, which is exactly the shape a regression here would take.
     *
     * @since 1.0.0
     *
     * @return array<int, string> Each stored value, as a string.
     */
    protected function every_target_value() {
        $values = array();

        foreach ( Licensio_KB_DB_Installer::get_table_names() as $table ) {
            foreach ( $this->fetch_all( $table ) as $row ) {
                foreach ( $row as $value ) {
                    $values[] = (string) $value;
                }
            }
        }

        return $values;
    }

    /**
     * A Heroic import state.
     *
     * @since 1.0.0
     *
     * @param bool $persist Whether the state is written to the option.
     *
     * @return Licensio_KB_Import_State The state.
     */
    protected function new_state( $persist = true ) {
        return new Licensio_KB_Import_State( 'heroic', self::PHASES, $persist );
    }

    /**
     * A Heroic import report.
     *
     * @since 1.0.0
     *
     * @return Licensio_KB_Import_Report The report.
     */
    protected function new_report() {
        return new Licensio_KB_Import_Report( 'heroic' );
    }

    /**
     * Build a migrator wired to this scratch database.
     *
     * @since 1.0.0
     *
     * @param Licensio_KB_Import_State  $state   The resume point.
     * @param Licensio_KB_Import_Report $report  The run report.
     * @param bool                      $dry_run Whether the migrator may write.
     * @param int                       $offset  Seconds to subtract to reach UTC.
     *
     * @return Licensio_KB_Heroic_Import_Data The migrator under test.
     */
    protected function data_migrator( $state, $report, $dry_run = false, $offset = 0 ) {
        $source  = new Licensio_KB_Heroic_Import_Source();
        $content = new Licensio_KB_Import_Content(
            $source,
            $state,
            new Licensio_KB_Import_Redirects( 'heroic', sys_get_temp_dir() . '/licensio-kb-migration-unused', true ),
            $report,
            $dry_run
        );

        return new Licensio_KB_Heroic_Import_Data( $source, $state, $content, $report, $dry_run, $offset );
    }

    /**
     * Run every data phase to exhaustion.
     *
     * @since 1.0.0
     *
     * @param Licensio_KB_Heroic_Import_Data $migrator The migrator.
     * @param int                            $batch    The batch size.
     *
     * @return void
     */
    protected function run_all_data_phases( Licensio_KB_Heroic_Import_Data $migrator, $batch = 100 ) {
        foreach ( array( 'migrate_feedback_batch', 'migrate_views_batch', 'migrate_searches_batch' ) as $method ) {
            do {
                $read = $migrator->{$method}( $batch );
            } while ( $read > 0 );
        }
    }
}
