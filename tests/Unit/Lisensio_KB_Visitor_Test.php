<?php
/**
 * Unit tests for the visitor identity helper.
 *
 * Covers the hash contract, daily salt rotation, the 24-hour previous-salt
 * retention window and the guarantee that no IP address is ever persisted.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

use Brain\Monkey\Functions;

/**
 * Tests Lisensio_KB_Visitor.
 *
 * @since 1.0.0
 */
class Lisensio_KB_Visitor_Test extends Lisensio_KB_Test_Case {

    /**
     * The IP used throughout these tests.
     *
     * @since 1.0.0
     * @var string
     */
    const TEST_IP = '198.51.100.17';

    /**
     * The user agent used throughout these tests.
     *
     * @since 1.0.0
     * @var string
     */
    const TEST_AGENT = 'Mozilla/5.0 (Lisensio Test Runner)';

    /**
     * Put a known IP and user agent on the request.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        $_SERVER['REMOTE_ADDR']     = self::TEST_IP;
        $_SERVER['HTTP_USER_AGENT'] = self::TEST_AGENT;
    }

    /**
     * Remove the request superglobals again.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function tearDown(): void {
        unset( $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT'] );

        parent::tearDown();
    }

    /**
     * The hash is an HMAC over the delimited identity, keyed by the salt.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_hash_is_hmac_of_identity_keyed_by_salt() {
        $expected = hash_hmac( 'sha256', self::TEST_IP . '|' . self::TEST_AGENT, 'the-salt' );

        $this->assertSame( $expected, Lisensio_KB_Visitor::hash( self::TEST_IP, self::TEST_AGENT, 'the-salt' ) );
        $this->assertSame( 64, strlen( Lisensio_KB_Visitor::hash( self::TEST_IP, self::TEST_AGENT, 'the-salt' ) ) );
    }

    /**
     * Two visitors cannot collide across the ip and user agent boundary.
     *
     * Without a delimiter, ip `1.2.3.4` with agent `5Mozilla` and ip `1.2.3.45`
     * with agent `Mozilla` concatenate identically and resolve to one identity,
     * which would break one-vote-per-visitor and per-visitor rate limiting.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_hash_does_not_collide_across_the_identity_boundary() {
        $this->assertNotSame(
            Lisensio_KB_Visitor::hash( '1.2.3.4', '5Mozilla', 'the-salt' ),
            Lisensio_KB_Visitor::hash( '1.2.3.45', 'Mozilla', 'the-salt' )
        );
    }

    /**
     * A different salt produces a different hash for the same visitor.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_hash_changes_with_the_salt() {
        $this->assertNotSame(
            Lisensio_KB_Visitor::hash( self::TEST_IP, self::TEST_AGENT, 'salt-one' ),
            Lisensio_KB_Visitor::hash( self::TEST_IP, self::TEST_AGENT, 'salt-two' )
        );
    }

    /**
     * A salt is generated and stored the first time one is needed.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_salt_is_created_on_first_use() {
        $visitor = new Lisensio_KB_Visitor();

        $this->assertArrayNotHasKey( Lisensio_KB_Visitor::OPTION_NAME, $this->options );

        $salts = $visitor->get_salts();

        $this->assertNotSame( '', $salts['current'] );
        $this->assertSame( '', $salts['previous'] );
        $this->assertArrayHasKey( Lisensio_KB_Visitor::OPTION_NAME, $this->options );
    }

    /**
     * The request hash is built from the current salt.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_get_hash_uses_the_current_salt() {
        $visitor = new Lisensio_KB_Visitor();
        $salts   = $visitor->get_salts();

        $this->assertSame(
            Lisensio_KB_Visitor::hash( self::TEST_IP, self::TEST_AGENT, $salts['current'] ),
            $visitor->get_hash()
        );
    }

    /**
     * Rotation replaces the current salt and keeps the old one as previous.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_rotation_moves_the_current_salt_to_previous() {
        $visitor = new Lisensio_KB_Visitor();
        $before  = $visitor->get_salts();

        $new   = $visitor->rotate_salt();
        $after = $visitor->get_salts();

        $this->assertNotSame( $before['current'], $new );
        $this->assertSame( $new, $after['current'] );
        $this->assertSame( $before['current'], $after['previous'] );
    }

    /**
     * A stale salt rotates on read, without cron ever firing.
     *
     * The privacy guarantee depends on the salt being destroyed on schedule, and
     * WP-cron is not dependable: DISABLE_WP_CRON with a broken system cron, or a
     * site with almost no traffic, would otherwise hold one salt for the entire
     * retention window. Anyone able to read the option could then brute-force the
     * IPv4 space against a known user agent and recover every stored hash.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_stale_salt_rotates_lazily_without_cron() {
        $visitor = new Lisensio_KB_Visitor();
        $before  = $visitor->get_salts();

        $stored                    = $this->options[ Lisensio_KB_Visitor::OPTION_NAME ];
        $stored['current_created'] = time() - ( Lisensio_KB_Visitor::ROTATION_INTERVAL + 60 );
        $this->options[ Lisensio_KB_Visitor::OPTION_NAME ] = $stored;

        $after = $visitor->get_salts();

        $this->assertNotSame( $before['current'], $after['current'] );
        $this->assertSame( $before['current'], $after['previous'] );
    }

    /**
     * A salt still inside its rotation window is left alone.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_fresh_salt_is_not_rotated_on_read() {
        $visitor = new Lisensio_KB_Visitor();
        $before  = $visitor->get_salts();

        $after = $visitor->get_salts();

        $this->assertSame( $before['current'], $after['current'] );
    }

    /**
     * Straight after a rotation the visitor is recognised under both salts.
     *
     * This is what stops a rotation double-counting a returning visitor.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_previous_salt_hash_is_offered_within_the_retention_window() {
        $visitor = new Lisensio_KB_Visitor();
        $before  = $visitor->get_salts();
        $old     = Lisensio_KB_Visitor::hash( self::TEST_IP, self::TEST_AGENT, $before['current'] );

        $visitor->rotate_salt();

        $hashes = $visitor->get_hashes();

        $this->assertCount( 2, $hashes );
        $this->assertSame( $visitor->get_hash(), $hashes[0] );
        $this->assertContains( $old, $hashes );
    }

    /**
     * Once the previous salt is older than 24 hours it is dropped.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_previous_salt_is_dropped_after_the_retention_window() {
        $visitor = new Lisensio_KB_Visitor();
        $visitor->get_salts();
        $visitor->rotate_salt();

        $stored                     = $this->options[ Lisensio_KB_Visitor::OPTION_NAME ];
        $stored['previous_created'] = time() - ( Lisensio_KB_Visitor::PREVIOUS_SALT_TTL + 60 );
        $this->options[ Lisensio_KB_Visitor::OPTION_NAME ] = $stored;

        $salts = $visitor->get_salts();

        $this->assertSame( '', $salts['previous'] );
        $this->assertCount( 1, $visitor->get_hashes() );
    }

    /**
     * Rotating twice retires the intermediate salt, not the original.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_only_one_previous_salt_is_retained() {
        $visitor = new Lisensio_KB_Visitor();
        $first   = $visitor->get_salts();

        $second = $visitor->rotate_salt();
        $visitor->rotate_salt();

        $salts = $visitor->get_salts();

        $this->assertSame( $second, $salts['previous'] );
        $this->assertNotSame( $first['current'], $salts['previous'] );
    }

    /**
     * Nothing the helper persists contains the raw IP address.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_no_ip_address_is_ever_persisted() {
        $visitor = new Lisensio_KB_Visitor();

        $visitor->get_salts();
        $visitor->get_hash();
        $visitor->rotate_salt();
        $visitor->get_hashes();

        $this->assertNotEmpty( $this->writes, 'The helper is expected to write at least one option.' );

        foreach ( $this->writes as $write ) {
            $serialised = var_export( $write['value'], true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export

            $this->assertStringNotContainsString( self::TEST_IP, $serialised );
            $this->assertStringNotContainsString( self::TEST_AGENT, $serialised );
        }
    }

    /**
     * No public method is named as an IP accessor.
     *
     * A naming-convention guard, not the privacy guarantee itself — that is
     * test_no_ip_address_is_ever_persisted(). Matched on whole `ip` words rather
     * than the bare substring, which used to fire on any future method whose
     * name merely contained the letters, such as `get_description()`.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_no_public_method_is_named_as_an_ip_accessor() {
        $reflection = new ReflectionClass( 'Lisensio_KB_Visitor' );

        foreach ( $reflection->getMethods( ReflectionMethod::IS_PUBLIC ) as $method ) {
            $this->assertDoesNotMatchRegularExpression(
                '/(^|_)ips?($|_)/',
                strtolower( $method->getName() ),
                sprintf( '%s reads as an IP accessor.', $method->getName() )
            );
        }
    }

    /**
     * The regex behind the previous guard does not fire on innocent names.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_ip_accessor_guard_does_not_fire_on_innocent_names() {
        foreach ( array( 'get_description', 'skip_cache', 'get_recipient' ) as $name ) {
            $this->assertDoesNotMatchRegularExpression( '/(^|_)ips?($|_)/', $name );
        }

        foreach ( array( 'get_ip', 'ip_address', 'get_request_ips' ) as $name ) {
            $this->assertMatchesRegularExpression( '/(^|_)ips?($|_)/', $name );
        }
    }

    /**
     * Deactivation clears every scheduled rotation, not just the next one.
     *
     * wp_unschedule_event() removes a single timestamped entry, so a duplicate
     * event — the usual result of a half-failed activation — survived and kept
     * rotating the salt for a plugin that was no longer running.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_unscheduling_clears_every_occurrence_of_the_event() {
        $cleared = array();

        Functions\when( 'wp_clear_scheduled_hook' )->alias(
            function ( $hook ) use ( &$cleared ) {
                $cleared[] = $hook;

                return 2;
            }
        );
        Functions\when( 'wp_unschedule_event' )->alias(
            function () {
                throw new RuntimeException( 'Single-occurrence unscheduling leaves duplicates behind.' );
            }
        );

        $visitor = new Lisensio_KB_Visitor();
        $visitor->unschedule_rotation();

        $this->assertSame( array( Lisensio_KB_Visitor::CRON_HOOK ), $cleared );
    }

    /**
     * The returned hash is not derived in a way that leaks the IP.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_hash_does_not_contain_the_ip() {
        $visitor = new Lisensio_KB_Visitor();

        $this->assertStringNotContainsString( self::TEST_IP, $visitor->get_hash() );
        $this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $visitor->get_hash() );
    }
    /**
     * The abuse identity ignores the user agent.
     *
     * This is the whole point of the split. `get_hashes()` mixes in the user
     * agent, which is a header the caller supplies, so a client that varies it
     * mints a fresh identity per request. Anything meant to *restrain* a caller
     * keyed on that is defeated by changing a header - as the rate limiter and
     * the one-vote rule both were.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_abuse_identity_survives_a_rotating_user_agent() {
        $visitor = new Lisensio_KB_Visitor();

        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (first)';
        $first                      = $visitor->get_abuse_hashes();

        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (second, entirely different)';
        $second                     = $visitor->get_abuse_hashes();

        $_SERVER['HTTP_USER_AGENT'] = '';
        $third                      = $visitor->get_abuse_hashes();

        $this->assertSame( $first, $second, 'a changed user agent produced a new abuse identity' );
        $this->assertSame( $first, $third, 'an emptied user agent produced a new abuse identity' );
    }

    /**
     * A different address is still a different caller.
     *
     * The counterpart to the test above: an identity that ignored everything
     * would also survive a rotating user agent, and would restrain the entire
     * internet as one visitor.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_abuse_identity_still_separates_addresses() {
        $visitor = new Lisensio_KB_Visitor();

        $_SERVER['REMOTE_ADDR'] = '203.0.113.10';
        $first                  = $visitor->get_abuse_hashes();

        $_SERVER['REMOTE_ADDR'] = '203.0.113.11';
        $second                 = $visitor->get_abuse_hashes();

        $this->assertNotSame( $first, $second );
    }

    /**
     * The analytics identity still counts two browsers as two readers.
     *
     * The split must not flatten analytics: for counting distinct visitors the
     * user agent is a genuine signal, and two browsers on one machine really
     * are two readers. Only the abuse controls give it up.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_analytics_identity_still_varies_with_the_user_agent() {
        $visitor = new Lisensio_KB_Visitor();

        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (first)';
        $first                      = $visitor->get_hashes();

        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (second)';
        $second                     = $visitor->get_hashes();

        $this->assertNotSame( $first, $second );
    }

    /**
     * The abuse identity is not the analytics identity.
     *
     * Guards the split itself: if `get_abuse_hashes()` were ever quietly
     * aliased back to `get_hashes()`, every test above would still pass on a
     * request whose user agent happens not to change.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_two_identities_are_not_the_same_value() {
        $visitor = new Lisensio_KB_Visitor();

        $this->assertNotSame( $visitor->get_hashes(), $visitor->get_abuse_hashes() );
    }
}
