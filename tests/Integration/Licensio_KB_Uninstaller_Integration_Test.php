<?php
/**
 * The uninstall transient delete against a real options table.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

/*
 * This suite connects to a real database on purpose: only a server can say
 * which rows a LIKE pattern matches. The WordPress Database API is therefore
 * not available or appropriate here.
 */
// phpcs:disable WordPress.DB.RestrictedClasses, WordPress.DB.RestrictedFunctions, WordPress.DB.DirectDatabaseQuery

require_once __DIR__ . '/Licensio_KB_Integration_Test_Case.php';
require_once __DIR__ . '/class-licensio-kb-live-wpdb.php';

/**
 * Deleting the rate-limit counters on uninstall.
 *
 * @since 1.0.0
 */
class Licensio_KB_Uninstaller_Integration_Test extends Licensio_KB_Integration_Test_Case {

    /**
     * The table prefix used for the scratch options table.
     *
     * @since 1.0.0
     * @var string
     */
    const PREFIX = 'lkbit_';

    /**
     * Create an options table shaped like core's.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        $GLOBALS['wpdb'] = new Licensio_KB_Live_WPDB( $this->db, self::PREFIX ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

        $this->assertTrue(
            (bool) $this->db->query(
                'CREATE TABLE ' . self::PREFIX . "options (
                    option_id bigint(20) unsigned NOT NULL auto_increment,
                    option_name varchar(191) NOT NULL default '',
                    option_value longtext NOT NULL,
                    autoload varchar(20) NOT NULL default 'yes',
                    PRIMARY KEY  (option_id),
                    UNIQUE KEY option_name (option_name)
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
            ),
            $this->db->error
        );
    }

    /**
     * Clear the global the test set.
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
     * Only the counters and their timeouts are deleted.
     *
     * The decoys differ from the prefix only where an unescaped underscore
     * would act as a wildcard, so they prove the pattern is escaped.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_only_the_rate_limit_counters_are_deleted() {
        $rows = array(
            '_transient_licensio_rl_' . md5( 'a' )         => '1',
            '_transient_timeout_licensio_rl_' . md5( 'a' ) => '1700000000',
            '_transient_licensio_rl_' . md5( 'b' )         => '3',
            '_transient_licensio_rlX' . md5( 'c' )         => 'decoy',
            '_transientXlicensio_rl_' . md5( 'd' )         => 'decoy',
            '_transient_other_plugin'                      => 'kept',
            'licensio_settings'                            => 'kept',
        );

        foreach ( $rows as $name => $value ) {
            $this->db->query( "INSERT INTO lkbit_options ( option_name, option_value ) VALUES ( '" . $this->db->real_escape_string( $name ) . "', '" . $this->db->real_escape_string( $value ) . "' )" );
        }

        Licensio_KB_Uninstaller::delete_rate_limit_transients();

        $left   = array();
        $result = $this->db->query( 'SELECT option_name FROM lkbit_options' );

        while ( $row = $result->fetch_row() ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- Reading a result set.
            $left[] = $row[0];
        }

        $this->assertEqualsCanonicalizing(
            array(
                '_transient_licensio_rlX' . md5( 'c' ),
                '_transient_other_plugin',
                '_transientXlicensio_rl_' . md5( 'd' ),
                'licensio_settings',
            ),
            $left
        );
    }

    /**
     * Only the undo snapshots and their timeouts are deleted.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_only_the_undo_snapshots_are_deleted() {
        $rows = array(
            '_transient_licensio_kb_undo_' . md5( 'a' ) => 'a:0:{}',
            '_transient_timeout_licensio_kb_undo_' . md5( 'a' ) => '1700000000',
            '_transient_licensio_kbXundo_' . md5( 'c' ) => 'decoy',
            '_transient_licensio_rl_' . md5( 'b' )      => 'kept',
        );

        foreach ( $rows as $name => $value ) {
            $this->db->query( "INSERT INTO lkbit_options ( option_name, option_value ) VALUES ( '" . $this->db->real_escape_string( $name ) . "', '" . $this->db->real_escape_string( $value ) . "' )" );
        }

        Licensio_KB_Uninstaller::delete_undo_snapshots();

        $left   = array();
        $result = $this->db->query( 'SELECT option_name FROM lkbit_options' );

        while ( $row = $result->fetch_row() ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- Reading a result set.
            $left[] = $row[0];
        }

        $this->assertEqualsCanonicalizing(
            array(
                '_transient_licensio_kbXundo_' . md5( 'c' ),
                '_transient_licensio_rl_' . md5( 'b' ),
            ),
            $left
        );
    }
}
