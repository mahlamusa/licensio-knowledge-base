<?php
/**
 * Database access gateway.
 *
 * The single place in the plugin that touches the Strauss-prefixed StellarWP
 * query builder. Everything else asks this class for a query builder so that
 * no file outside `includes/class-lisensio-kb-db.php` needs an import of a
 * vendored, prefixed namespace — and so raw `$wpdb` never appears in feature
 * code.
 *
 * Consumed by: #4, #8, #9, #12.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Thin facade over the vendored StellarWP query builder.
 *
 * @since 1.0.0
 */
class Lisensio_KB_DB {

    /**
     * Whether the vendored library has been loaded and configured.
     *
     * @since 1.0.0
     * @var bool
     */
    private static $booted = false;

    /**
     * Load the vendored libraries and configure the query builder.
     *
     * Safe to call repeatedly; the work happens once.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public static function init() {
        if ( self::$booted ) {
            return;
        }

        $autoload = LISENSIO_KB_ABSPATH . 'libraries/autoload.php';

        if ( is_readable( $autoload ) ) {
            require_once $autoload;
        }

        if ( self::is_available() ) {
            call_user_func( array( 'Lisensio_KB\StellarWP\DB\Config', 'setHookPrefix' ), 'lisensio' );

            $provider_class = 'Lisensio_KB\StellarWP\DB\Database\Provider';
            $provider       = new $provider_class();
            $provider->register();
        }

        self::$booted = true;
    }

    /**
     * Whether the vendored query builder is present.
     *
     * The plugin ships the library, so this is false only in a broken install
     * or in a unit-test process that has not loaded the vendor autoloader.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public static function is_available() {
        return class_exists( 'Lisensio_KB\StellarWP\DB\DB' );
    }

    /**
     * Start a query against one of the plugin tables.
     *
     * The table name is passed unprefixed — `search_index`, `search_log`,
     * `article_views` or `feedback`. The `lisensio_` prefix and the WordPress
     * table prefix are both applied here.
     *
     * @since 1.0.0
     *
     * @param string $table The unprefixed plugin table name.
     * @param string $alias Optional table alias.
     *
     * @return object The StellarWP query builder instance.
     * @throws RuntimeException When the vendored query builder is unavailable.
     */
    public static function table( $table, $alias = '' ) {
        self::init();

        if ( ! self::is_available() ) {
            throw new RuntimeException( 'The Lisensio Knowledge Base database library is unavailable.' );
        }

        /*
         * Pass the UNPREFIXED name. The query builder applies $wpdb->prefix
         * itself in QueryBuilder\Concerns\TablePrefix::prefixTable(), so handing
         * it an already-prefixed name yields wp_wp_lisensio_search_index and the
         * query silently addresses a table that does not exist. delta() and
         * query() are different: they take a fully qualified name because they
         * bypass the builder entirely.
         */
        return call_user_func( array( 'Lisensio_KB\StellarWP\DB\DB', 'table' ), self::unprefixed_table_name( $table ), $alias );
    }

    /**
     * Run a raw statement through the query builder's error checking.
     *
     * Reserved for DDL and maintenance statements that the fluent builder
     * cannot express. Feature code should use table() instead.
     *
     * @since 1.0.0
     *
     * @param string $sql The SQL statement.
     *
     * @return int|bool The wpdb::query() return value.
     * @throws RuntimeException When the vendored query builder is unavailable.
     */
    public static function query( $sql ) {
        self::init();

        if ( ! self::is_available() ) {
            throw new RuntimeException( 'The Lisensio Knowledge Base database library is unavailable.' );
        }

        return call_user_func( array( 'Lisensio_KB\StellarWP\DB\DB', 'query' ), $sql );
    }

    /**
     * Run dbDelta() through the query builder's error checking.
     *
     * @since 1.0.0
     *
     * @param string|string[] $schema One or more CREATE TABLE statements.
     *
     * @return array<string, string> The dbDelta() report.
     * @throws RuntimeException When the vendored query builder is unavailable.
     */
    public static function delta( $schema ) {
        self::init();

        if ( ! self::is_available() ) {
            throw new RuntimeException( 'The Lisensio Knowledge Base database library is unavailable.' );
        }

        return (array) call_user_func( array( 'Lisensio_KB\StellarWP\DB\DB', 'delta' ), $schema );
    }

    /**
     * The database charset and collation clause for CREATE TABLE statements.
     *
     * @since 1.0.0
     *
     * @return string The charset collate clause, or an empty string.
     */
    public static function charset_collate() {
        global $wpdb;

        return isset( $wpdb ) ? $wpdb->get_charset_collate() : '';
    }

    /**
     * The WordPress table prefix.
     *
     * @since 1.0.0
     *
     * @return string The table prefix, or an empty string outside WordPress.
     */
    public static function prefix() {
        global $wpdb;

        return isset( $wpdb ) ? (string) $wpdb->prefix : '';
    }

    /**
     * Build the WordPress-prefixed name of a plugin table.
     *
     * @since 1.0.0
     *
     * @param string $table The unprefixed plugin table name.
     *
     * @return string The full table name, including the WordPress prefix.
     */
    public static function table_name( $table ) {
        return self::prefix() . self::unprefixed_table_name( $table );
    }

    /**
     * Build the plugin-prefixed table name, without the WordPress prefix.
     *
     * Split out from table_name() so schema generation stays testable without
     * a live `$wpdb`.
     *
     * @since 1.0.0
     *
     * @param string $table The unprefixed plugin table name.
     *
     * @return string The plugin-prefixed table name.
     */
    public static function unprefixed_table_name( $table ) {
        $table = (string) $table;

        if ( 0 === strpos( $table, 'lisensio_' ) ) {
            return $table;
        }

        return 'lisensio_' . $table;
    }
}
