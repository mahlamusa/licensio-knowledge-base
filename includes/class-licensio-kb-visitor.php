<?php
/**
 * Privacy-safe visitor identity.
 *
 * Produces `hash_hmac( 'sha256', ip . '|' . user_agent, daily_salt )` so analytics can count
 * unique visitors without ever writing an IP address to the database. The
 * salt lives in an autoloaded option and is rotated daily by cron; the
 * previous salt is kept for 24 hours so a visitor is not counted twice across
 * a rotation.
 *
 * The raw IP address is read from the request and immediately hashed. It is
 * never returned by a public method, never stored and never logged.
 *
 * Consumed by: #4, #8, #9.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Rotating salted visitor hash.
 *
 * @since 1.0.0
 */
class Licensio_KB_Visitor {

    /**
     * Option holding the current and previous salts.
     *
     * @since 1.0.0
     * @var string
     */
    const OPTION_NAME = 'licensio_visitor_salt';

    /**
     * Cron hook that rotates the salt.
     *
     * @since 1.0.0
     * @var string
     */
    const CRON_HOOK = 'licensio_rotate_visitor_salt';

    /**
     * How long the previous salt stays usable, in seconds.
     *
     * @since 1.0.0
     * @var int
     */
    const PREVIOUS_SALT_TTL = 86400;

    /**
     * How long a salt stays current before it must be rotated, in seconds.
     *
     * @since 1.0.0
     *
     * @var int
     */
    const ROTATION_INTERVAL = 86400;

    /**
     * Length in characters of a salt.
     *
     * @since 1.0.0
     * @var int
     */
    const SALT_LENGTH = 64;

    /**
     * Register the cron handler.
     *
     * Scheduling itself happens on activation; this only guarantees the
     * handler exists and that a site which lost its cron entry gets it back.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function init_hooks() {
        add_action( self::CRON_HOOK, array( $this, 'handle_scheduled_rotation' ) );
        add_action( 'init', array( $this, 'schedule_rotation' ) );
    }

    /**
     * Cron handler: rotate the salt and discard the return value.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function handle_scheduled_rotation() {
        $this->rotate_salt();
    }

    /**
     * Ensure the daily rotation event is scheduled.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function schedule_rotation() {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + self::PREVIOUS_SALT_TTL, 'daily', self::CRON_HOOK );
        }
    }

    /**
     * Remove the daily rotation event.
     *
     * Clears every occurrence, not just the next one. `wp_unschedule_event()`
     * removes a single timestamped entry, so a duplicate — the usual result of a
     * half-failed activation — survived deactivation and kept rotating the salt
     * for a plugin that was no longer running. This is also what `uninstall.php`
     * uses, so the two teardown paths now agree.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function unschedule_rotation() {
        wp_clear_scheduled_hook( self::CRON_HOOK );
    }

    /**
     * The visitor hash for the current request, under the current salt.
     *
     * @since 1.0.0
     *
     * @return string A 64-character lowercase hex digest.
     */
    public function get_hash() {
        $salts = $this->get_salts();

        return self::hash( $this->get_request_ip(), $this->get_user_agent(), $salts['current'] );
    }

    /**
     * Every hash the current request could have been recorded under.
     *
     * Contains the current-salt hash and, while the previous salt is still
     * within its 24-hour retention window, the previous-salt hash. Consumers
     * enforcing "once per visitor" must look the visitor up by all of these
     * so a salt rotation does not let the same person vote or get counted
     * twice.
     *
     * @since 1.0.0
     *
     * @return string[] One or two 64-character hex digests, current salt first.
     */
    public function get_hashes() {
        $salts    = $this->get_salts();
        $ip       = $this->get_request_ip();
        $agent    = $this->get_user_agent();
        $hashes   = array( self::hash( $ip, $agent, $salts['current'] ) );
        $previous = $salts['previous'];

        if ( '' !== $previous ) {
            $hashes[] = self::hash( $ip, $agent, $previous );
        }

        return $hashes;
    }

    /**
     * The identities the abuse controls key on, current salt first.
     *
     * Deliberately **not** `get_hashes()`. That identity includes the user
     * agent, which is a request header the caller chooses, so a client that
     * varies it mints a fresh identity on every request. That is harmless for
     * counting distinct visitors — two browsers on one machine genuinely are
     * two readers — but it is fatal for anything meant to *restrain* a caller:
     * the rate limiter and the one-vote-per-article rule were both defeated by
     * rotating a header, which was demonstrated by voting six times on one
     * article from a single address.
     *
     * So the abuse controls key on the address alone. The tradeoff is real and
     * chosen: readers sharing an address — an office, a school, carrier-grade
     * NAT — now share one identity and one vote per article between them. That
     * costs a handful of honest votes; the alternative costs the integrity of
     * every rating on the site to anyone able to set a header.
     *
     * Still an HMAC under the rotating salt, so this changes what the identity
     * is derived from and not the promise that no address is ever stored.
     *
     * @since 1.0.0
     *
     * @return string[] One or two 64-character hex digests, current salt first.
     */
    public function get_abuse_hashes() {
        $salts    = $this->get_salts();
        $ip       = $this->get_request_ip();
        $hashes   = array( self::hash( $ip, '', $salts['current'] ) );
        $previous = $salts['previous'];

        if ( '' !== $previous ) {
            $hashes[] = self::hash( $ip, '', $previous );
        }

        return $hashes;
    }

    /**
     * Hash an identity triple.
     *
     * Pure function: given the same inputs it always returns the same digest,
     * which is what makes the rotation behaviour testable.
     *
     * @since 1.0.0
     *
     * @param string $ip         The visitor IP address. Hashed, never stored.
     * @param string $user_agent The visitor user agent string.
     * @param string $salt       The salt in force.
     *
     * @return string A 64-character lowercase hex digest.
     */
    public static function hash( $ip, $user_agent, $salt ) {
        /*
         * HMAC rather than a plain digest: the salt is a key, not a prefix. The
         * `|` delimiter keeps the inputs unambiguous, so IP `1.2.3.4` with user
         * agent `5Mozilla` cannot collide with IP `1.2.3.45` and `Mozilla` — two
         * distinct visitors resolving to one identity would break the
         * one-vote-per-visitor and rate-limiting rules built on this hash.
         */
        return hash_hmac( 'sha256', (string) $ip . '|' . (string) $user_agent, (string) $salt );
    }

    /**
     * The current salt, and the previous salt while it is still in retention.
     *
     * Creates the salt pair the first time it is asked for.
     *
     * @since 1.0.0
     *
     * @return array{current: string, previous: string} The usable salts. `previous` is an empty string when there is none.
     */
    public function get_salts() {
        $stored = get_option( self::OPTION_NAME, array() );

        if ( ! is_array( $stored ) || empty( $stored['current'] ) ) {
            $stored = $this->store_salts( $this->generate_salt(), time(), '', 0 );
        }

        /*
         * Rotate lazily as well as on cron. The privacy guarantee depends on the
         * salt being destroyed on schedule, and WP-cron is not reliable: a site
         * with DISABLE_WP_CRON and a misconfigured system cron, or simply very
         * little traffic, would otherwise keep one salt for the whole retention
         * window. Anyone who could then read the option could brute-force the
         * IPv4 space against a known user agent and recover every stored hash.
         */
        $created = isset( $stored['current_created'] ) ? (int) $stored['current_created'] : 0;

        if ( $created <= 0 || ( time() - $created ) >= self::ROTATION_INTERVAL ) {
            $this->rotate_salt();

            $stored = get_option( self::OPTION_NAME, array() );
        }

        $previous = '';

        if ( ! empty( $stored['previous'] ) ) {
            $rotated_at = isset( $stored['previous_created'] ) ? (int) $stored['previous_created'] : 0;

            if ( ( time() - $rotated_at ) < self::PREVIOUS_SALT_TTL ) {
                $previous = (string) $stored['previous'];
            }
        }

        return array(
            'current'  => (string) $stored['current'],
            'previous' => $previous,
        );
    }

    /**
     * Rotate the salt: the current salt becomes the previous one.
     *
     * @since 1.0.0
     *
     * @return string The newly generated current salt.
     */
    public function rotate_salt() {
        $stored  = get_option( self::OPTION_NAME, array() );
        $current = is_array( $stored ) && ! empty( $stored['current'] ) ? (string) $stored['current'] : '';
        $salt    = $this->generate_salt();
        $now     = time();

        $this->store_salts( $salt, $now, $current, $now );

        /**
         * Fires after the visitor salt has been rotated.
         *
         * The salt itself is deliberately NOT passed. It is a secret whose whole
         * purpose is to be unrecoverable once rotated, and hook arguments are
         * routinely serialised into debug logs and action-logger tables. Consumers
         * that need an identifier call `get_hash()`.
         *
         * @since 1.0.0
         *
         * @param int $rotated_at Unix timestamp the rotation happened.
         */
        do_action( 'licensio_visitor_salt_rotated', $now );

        return $salt;
    }

    /**
     * Persist the salt pair to the autoloaded option.
     *
     * @since 1.0.0
     *
     * @param string $current          The new current salt.
     * @param int    $current_created  Timestamp the current salt was created.
     * @param string $previous         The previous salt, or an empty string.
     * @param int    $previous_created Timestamp the previous salt was retired.
     *
     * @return array{current: string, current_created: int, previous: string, previous_created: int} The stored value.
     */
    private function store_salts( $current, $current_created, $previous, $previous_created ) {
        $value = array(
            'current'          => (string) $current,
            'current_created'  => (int) $current_created,
            'previous'         => (string) $previous,
            'previous_created' => (int) $previous_created,
        );

        update_option( self::OPTION_NAME, $value, true );

        return $value;
    }

    /**
     * Generate a new random salt.
     *
     * @since 1.0.0
     *
     * @return string A 64-character random string.
     */
    private function generate_salt() {
        try {
            return bin2hex( random_bytes( self::SALT_LENGTH / 2 ) );
        } catch ( Exception $e ) {
            return wp_generate_password( self::SALT_LENGTH, true, true );
        }
    }

    /**
     * The requesting IP address.
     *
     * Deliberately reads only `REMOTE_ADDR`; proxy headers are attacker
     * controlled and must never be trusted blindly.
     *
     * Sites behind a reverse proxy may override this through the
     * `licensio_visitor_ip` filter, but MUST validate before doing so: return an
     * `X-Forwarded-For` entry only after confirming `REMOTE_ADDR` is itself in a
     * configured trusted-proxy allowlist, and take the entry the trusted proxy
     * appended rather than the left-most one. Returning an unvalidated header
     * lets a client vary it per request to mint a fresh visitor hash every time,
     * which forges search analytics and defeats both one-vote-per-visitor and
     * per-visitor rate limiting.
     */
    private function get_request_ip() {
        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

        /**
         * Filter the IP address used to build the visitor hash.
         *
         * The value is hashed immediately and is never written to the
         * database, so overriding it does not create a privacy liability.
         *
         * @since 1.0.0
         *
         * Overriding this creates no privacy liability, since the value is only
         * ever hashed and never stored. It does create an integrity liability if
         * the replacement is not validated against a trusted-proxy allowlist.
         *
         * @param string $ip The IP address read from `REMOTE_ADDR`.
         */
        return (string) apply_filters( 'licensio_visitor_ip', $ip );
    }

    /**
     * The requesting user agent string.
     *
     * @since 1.0.0
     *
     * @return string The user agent, or an empty string when unavailable.
     */
    private function get_user_agent() {
        return isset( $_SERVER['HTTP_USER_AGENT'] )
            ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) )
            : '';
    }
}
