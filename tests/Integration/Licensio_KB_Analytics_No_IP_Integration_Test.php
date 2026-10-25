<?php
/**
 * The no-IP guarantee, proven against a real database.
 *
 * The plugin's headline privacy claim is that it never stores an IP address:
 * visitor identity is an HMAC keyed by a salt that is rotated daily. A test
 * asserting only that no column is named `user_ip` would not catch an IP
 * written into a column that is named something else, which is exactly the
 * shape a regression here would take.
 *
 * So this drives the real recorders with a known REMOTE_ADDR and then scans
 * every column of every row of every table the plugin owns for that value.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

require_once __DIR__ . '/Licensio_KB_Analytics_DB_Test_Case.php';

/**
 * Captures real events and searches the whole schema for the visitor's IP.
 *
 * @since 1.0.0
 */
class Licensio_KB_Analytics_No_IP_Integration_Test extends Licensio_KB_Analytics_DB_Test_Case {

    /**
     * The address the visitor arrives from.
     *
     * @since 1.0.0
     * @var string
     */
    const VISITOR_IP = '198.51.100.77';

    /**
     * Record a search and an article view as a real visitor would.
     *
     * @since 1.0.0
     *
     * @return void
     */
    private function capture_real_events() {
        $_SERVER['REMOTE_ADDR']     = self::VISITOR_IP;
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (integration)';

        /*
         * The visitor salt lives in an option. Back it with an in-memory store
         * so rotation behaves exactly as it does in production; the point of
         * this test is what reaches the database, not where the salt is kept.
         */
        // The in-memory option store backing the salt. Seeded with the key it
        // will hold so its shape is known; null reads fall through to the
        // caller's default exactly as an absent option would.
        $options = array( Licensio_KB_Visitor::OPTION_NAME => null );

        Brain\Monkey\Functions\when( 'get_option' )->alias(
            function ( $name, $fallback = false ) use ( &$options ) {
                $key = (string) $name;

                return isset( $options[ $key ] ) ? $options[ $key ] : $fallback;
            }
        );

        Brain\Monkey\Functions\when( 'update_option' )->alias(
            function ( $name, $value ) use ( &$options ) {
                $options[ (string) $name ] = $value;

                return true;
            }
        );

        Brain\Monkey\Functions\when( 'wp_unslash' )->returnArg();
        Brain\Monkey\Functions\when( 'wp_rand' )->justReturn( 1234 );
        Brain\Monkey\Functions\when( 'post_password_required' )->justReturn( false );

        Brain\Monkey\Functions\when( 'wp_generate_password' )->alias(
            function ( $length = 12 ) {
                return substr( str_repeat( 'a1B2c3D4e5F6g7H8', 8 ), 0, (int) $length );
            }
        );

        $analytics = new Licensio_KB_Analytics( $this->settings( array() ), new Licensio_KB_Visitor() );

        $analytics->record_search_event( 'reset password', 3 );
        $analytics->record_search_event( 'nothing matches this', 0 );

        /*
         * Both capture paths must be exercised. Searches and article views write
         * to different tables through different methods, and a test covering one
         * proves nothing about the other.
         */
        $post     = new WP_Post();
        $post->ID = 4242;

        $analytics->record_article_view( $post );
    }

    /**
     * Every value the plugin stores, across every table it owns.
     *
     * @since 1.0.0
     *
     * @return array<int, string> Each stored value, as a string.
     */
    private function every_stored_value() {
        $values = array();

        foreach ( Licensio_KB_DB_Installer::get_table_names() as $table ) {
            $rows = $this->db->query( 'SELECT * FROM ' . $this->table( $table ) );

            if ( ! $rows instanceof mysqli_result ) {
                continue;
            }

            while ( $row = $rows->fetch_assoc() ) {
                foreach ( $row as $value ) {
                    $values[] = (string) $value;
                }
            }
        }

        return $values;
    }

    /**
     * No table the plugin owns has a column that stores an address.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_no_table_declares_an_address_column() {
        foreach ( Licensio_KB_DB_Installer::get_table_names() as $table ) {
            $columns = $this->db->query( 'SHOW COLUMNS FROM ' . $this->table( $table ) );

            $this->assertInstanceOf( 'mysqli_result', $columns, sprintf( 'Table %s is missing.', $table ) );

            while ( $column = $columns->fetch_assoc() ) {
                $name = strtolower( (string) $column['Field'] );

                $this->assertStringNotContainsString( 'ip', $name, sprintf( 'Table %1$s has an address-shaped column %2$s.', $table, $name ) );
            }
        }
    }

    /**
     * Capturing real events writes the visitor's address nowhere at all.
     *
     * This is the assertion that matters. It does not care which column an
     * address might end up in, only that the value never appears.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_captured_visitors_address_is_stored_nowhere() {
        $this->capture_real_events();

        $stored = $this->every_stored_value();

        $this->assertNotEmpty( $stored, 'Nothing was captured, so this test would pass vacuously.' );
        $this->assertNotContains( self::VISITOR_IP, $stored, 'The visitor IP address was written to the database.' );

        foreach ( $stored as $value ) {
            $this->assertStringNotContainsString( self::VISITOR_IP, $value, 'A stored value contains the visitor IP address.' );
        }
    }

    /**
     * What is stored instead is a hash, and it is not derived reversibly.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_what_is_stored_is_a_hash_of_the_expected_shape() {
        $this->capture_real_events();

        $rows = $this->db->query( 'SELECT visitor_hash FROM ' . $this->table( 'licensio_search_log' ) );

        $this->assertInstanceOf( 'mysqli_result', $rows );
        $this->assertGreaterThan( 0, $rows->num_rows, 'No search was captured, so this test would pass vacuously.' );

        while ( $row = $rows->fetch_assoc() ) {
            $hash = (string) $row['visitor_hash'];

            $this->assertSame( 64, strlen( $hash ), 'A visitor hash is not a sha256 digest.' );
            $this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $hash, 'A visitor hash is not lowercase hex.' );
            $this->assertNotSame( self::VISITOR_IP, $hash );
        }
    }
}
