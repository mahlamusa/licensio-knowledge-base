<?php
/**
 * Database installer.
 *
 * Owns the schema and the schema version for the four knowledge base tables.
 * Schema only: this class creates, upgrades and drops tables and records
 * whether the FULLTEXT index landed. It performs no reads and no writes of table rows — those
 * belong to the consuming sub-issues.
 *
 * No table defined here has an IP column. Visitor identity is the rotating
 * salted hash produced by Licensio_KB_Visitor.
 *
 * Consumed by: #4, #8, #9, #12.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Creates and versions the knowledge base tables.
 *
 * @since 1.0.0
 */
class Licensio_KB_DB_Installer {

    /**
     * Option storing the installed schema version.
     *
     * @since 1.0.0
     * @var string
     */
    const VERSION_OPTION = 'licensio_db_version';

    /**
     * Option storing the result of the FULLTEXT capability probe.
     *
     * @since 1.0.0
     * @var string
     */
    const FULLTEXT_OPTION = 'licensio_fulltext_supported';

    /**
     * The unprefixed names of every table this plugin owns.
     *
     * @since 1.0.0
     *
     * @return string[] Table names without the WordPress prefix.
     */
    public static function get_table_names() {
        return array(
            'licensio_search_index',
            'licensio_search_log',
            'licensio_article_views',
            'licensio_feedback',
            'licensio_import_log',
        );
    }

    /**
     * The CREATE TABLE statement for every table.
     *
     * Pure string generation: nothing here touches the database, so the
     * schema can be asserted in a unit test without a live WordPress.
     *
     * @since 1.0.0
     *
     * @param string $prefix          The WordPress table prefix.
     * @param string $charset_collate The charset and collation clause.
     *
     * @return array<string, string> Unprefixed table name to CREATE TABLE statement.
     */
    public static function get_schemas( $prefix = '', $charset_collate = '' ) {
        $schemas = array();

        $schemas['licensio_search_index'] = "CREATE TABLE {$prefix}licensio_search_index (
            article_id bigint(20) unsigned NOT NULL,
            title text NOT NULL,
            excerpt text NOT NULL,
            content longtext NOT NULL,
            terms text NOT NULL,
            updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
            PRIMARY KEY  (article_id),
            KEY updated_at (updated_at)
        ) {$charset_collate};";

        $schemas['licensio_search_log'] = "CREATE TABLE {$prefix}licensio_search_log (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            term varchar(191) NOT NULL DEFAULT '',
            normalized_term varchar(191) NOT NULL DEFAULT '',
            results_count int(11) NOT NULL DEFAULT 0,
            visitor_hash char(64) NOT NULL DEFAULT '',
            source varchar(20) NOT NULL DEFAULT 'site',
            created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
            PRIMARY KEY  (id),
            KEY term (term),
            KEY normalized_term (normalized_term),
            KEY visitor_hash (visitor_hash),
            KEY created_at (created_at),
            KEY results_created (results_count, created_at)
        ) {$charset_collate};";

        $schemas['licensio_article_views'] = "CREATE TABLE {$prefix}licensio_article_views (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            article_id bigint(20) unsigned NOT NULL DEFAULT 0,
            visitor_hash char(64) NOT NULL DEFAULT '',
            created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
            PRIMARY KEY  (id),
            KEY article_id (article_id),
            KEY visitor_hash (visitor_hash),
            KEY created_at (created_at),
            KEY article_created (article_id, created_at)
        ) {$charset_collate};";

        $schemas['licensio_feedback'] = "CREATE TABLE {$prefix}licensio_feedback (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            article_id bigint(20) unsigned NOT NULL DEFAULT 0,
            vote tinyint(4) NOT NULL DEFAULT 0,
            comment text NOT NULL,
            visitor_hash char(64) NOT NULL DEFAULT '',
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'new',
            created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
            PRIMARY KEY  (id),
            KEY article_id (article_id),
            KEY visitor_hash (visitor_hash),
            KEY user_id (user_id),
            KEY status (status),
            KEY created_at (created_at),
            KEY article_visitor (article_id, visitor_hash)
        ) {$charset_collate};";

        $schemas['licensio_import_log'] = "CREATE TABLE {$prefix}licensio_import_log (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            run_id varchar(64) NOT NULL DEFAULT '',
            source varchar(32) NOT NULL DEFAULT '',
            dry_run tinyint(1) NOT NULL DEFAULT 0,
            phase varchar(20) NOT NULL DEFAULT '',
            item_type varchar(20) NOT NULL DEFAULT '',
            source_id bigint(20) unsigned NOT NULL DEFAULT 0,
            target_id bigint(20) unsigned NOT NULL DEFAULT 0,
            title varchar(255) NOT NULL DEFAULT '',
            status varchar(20) NOT NULL DEFAULT '',
            message text NOT NULL,
            details longtext NOT NULL,
            created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
            PRIMARY KEY  (id),
            KEY run_status (run_id, status),
            KEY created_at (created_at)
        ) {$charset_collate};";

        return $schemas;
    }

    /**
     * Create or upgrade every table.
     *
     * Returns false rather than throwing when the vendored query builder is
     * missing. This runs from `admin_init` and from activation, and an uncaught
     * RuntimeException on either path locks the administrator out of the very
     * screen they would use to deactivate the plugin.
     *
     * @since 1.0.0
     *
     * @return bool True when the schema was installed, false when it could not run.
     */
    public static function install() {
        Licensio_KB_DB::init();

        if ( ! Licensio_KB_DB::is_available() ) {
            return false;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        /*
         * Create the table WITHOUT the FULLTEXT key, add the key out of band,
         * then read back what the server actually did.
         *
         * The key must not ride along in the dbDelta() CREATE TABLE: a server
         * that rejects the key rejects the whole statement, leaving no search
         * index table at all, which is strictly worse than having no index. A
         * separate suppressed ALTER costs only the index when it fails.
         *
         * An earlier implementation instead probed with a throwaway
         * `CREATE TEMPORARY TABLE ... FULLTEXT ... ENGINE=InnoDB`, which MySQL
         * rejects outright (errno 1796) even where the permanent table accepts
         * the same key, so every MySQL 8 host was told FULLTEXT was unavailable
         * and silently fell back to LIKE.
         *
         * dbDelta() never drops indexes, so the out-of-band key survives every
         * later run even though the schema string no longer mentions it.
         */
        $schemas = self::get_schemas( Licensio_KB_DB::prefix(), Licensio_KB_DB::charset_collate() );

        foreach ( $schemas as $schema ) {
            Licensio_KB_DB::delta( $schema );
        }

        self::add_fulltext_index();

        $fulltext = self::detect_fulltext_index();

        update_option( self::FULLTEXT_OPTION, $fulltext ? 'yes' : 'no', true );
        update_option( self::VERSION_OPTION, LICENSIO_KB_DB_VERSION, true );

        /**
         * Fires after the knowledge base tables have been created or upgraded.
         *
         * @since 1.0.0
         *
         * @param bool $fulltext Whether the FULLTEXT index was created.
         */
        do_action( 'licensio_db_installed', $fulltext );

        return true;
    }

    /**
     * Run install() when the stored schema version is behind the code.
     *
     * @since 1.0.0
     *
     * @return bool True when an install or upgrade ran.
     */
    public static function maybe_install() {
        $installed = (string) get_option( self::VERSION_OPTION, '' );

        if ( version_compare( $installed, LICENSIO_KB_DB_VERSION, '>=' ) ) {
            return false;
        }

        self::install();

        return true;
    }

    /**
     * Drop every table and forget the schema version. Used by uninstall only.
     *
     * @since 1.0.0
     *
     * @return void
     * @throws RuntimeException When the vendored query builder is unavailable.
     */
    public static function uninstall() {
        self::drop_tables();

        delete_option( self::VERSION_OPTION );
        delete_option( self::FULLTEXT_OPTION );
    }

    /**
     * Drop every table, leaving the options alone.
     *
     * Split out from uninstall() so the uninstaller can drop the data tables
     * conditionally while its own option and cron cleanup always runs.
     *
     * @since 1.0.0
     *
     * @return void
     * @throws RuntimeException When the vendored query builder is unavailable.
     */
    public static function drop_tables() {
        foreach ( self::get_table_names() as $table ) {
            Licensio_KB_DB::query( 'DROP TABLE IF EXISTS ' . Licensio_KB_DB::table_name( $table ) );
        }
    }

    /**
     * Whether the recorded detection says FULLTEXT indexing is available.
     *
     * @since 1.0.0
     *
     * @return bool True when the search index table carries a FULLTEXT key.
     */
    public static function supports_fulltext() {
        return 'yes' === get_option( self::FULLTEXT_OPTION, 'no' );
    }

    /**
     * Attempt to add the FULLTEXT key to the search index table.
     *
     * Errors are suppressed: a server that cannot build the index, or one where
     * it already exists (errno 1061), must not surface a warning. The outcome is
     * never trusted from the return value; `detect_fulltext_index()` reads the
     * truth back from the table.
     *
     * @since 1.0.0
     *
     * @return void
     */
    private static function add_fulltext_index() {
        global $wpdb;

        if ( ! isset( $wpdb ) ) {
            return;
        }

        $table    = Licensio_KB_DB::table_name( 'licensio_search_index' );
        $suppress = $wpdb->suppress_errors( true );

        // The table name is ours, not user input, and DDL takes no placeholders.
        $wpdb->query( "ALTER TABLE {$table} ADD FULLTEXT KEY ft_all (title, content, terms)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange

        $wpdb->suppress_errors( $suppress );
    }

    /**
     * Whether the search index table actually carries its FULLTEXT key.
     *
     * Read back from the live table after the index has been attempted, rather
     * than predicted in advance. Scans for a row whose `Index_type` is
     * `FULLTEXT`, which also catches a server that accepted the key name but
     * built a different index type.
     *
     * @since 1.0.0
     *
     * @return bool True when the search index table carries `ft_all`.
     */
    public static function detect_fulltext_index() {
        global $wpdb;

        if ( ! isset( $wpdb ) ) {
            return false;
        }

        $table = Licensio_KB_DB::table_name( 'licensio_search_index' );

        // The table name is ours, not user input, and SHOW INDEX takes no placeholders.
        $rows = $wpdb->get_results( "SHOW INDEX FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

        $found = false;

        foreach ( (array) $rows as $row ) {
            /*
             * Match on Index_type, not on Key_name: a key called ft_all that the
             * server quietly created as BTREE would otherwise read as success.
             */
            // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- SHOW INDEX column name is MySQL's.
            if ( isset( $row->Index_type ) && 'FULLTEXT' === $row->Index_type ) {
                $found = true;
                break;
            }
        }

        /**
         * Filter whether FULLTEXT indexing is considered available.
         *
         * Set to false to force the LIKE search driver on a host where
         * FULLTEXT exists but performs badly.
         *
         * @since 1.0.0
         *
         * @param bool $supported Whether the FULLTEXT index is present.
         */
        return (bool) apply_filters( 'licensio_supports_fulltext', $found );
    }
}
