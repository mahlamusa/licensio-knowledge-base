<?php
/**
 * Base REST controller.
 *
 * Every knowledge base REST controller extends this class. It fixes the
 * `lisensio-kb/v1` namespace, provides the shared permission callbacks and
 * the not-implemented response used by the scaffold's route stubs.
 *
 * Consumed by: #4, #8, #9, #10.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shared behaviour for the plugin's REST controllers.
 *
 * Every subclass must implement `register_routes()`. It is not redeclared
 * abstract here because WP_REST_Controller already defines it concretely and
 * PHP rejects making an inherited non-abstract method abstract; a subclass
 * that forgets gets the inherited `_doing_it_wrong()` notice.
 *
 * @since 1.0.0
 */
abstract class Lisensio_KB_REST_Controller extends WP_REST_Controller {

    /**
     * The REST namespace.
     *
     * Read from `LISENSIO_KB_REST_NAMESPACE` rather than repeated, so this and
     * Lisensio_KB_REST_API::NAMESPACE_V1 cannot drift apart.
     *
     * @since 1.0.0
     * @var string
     */
    protected $namespace = LISENSIO_KB_REST_NAMESPACE;

    /**
     * The capability required for management endpoints.
     *
     * @since 1.0.0
     * @var string
     */
    const MANAGE_CAPABILITY = 'manage_lisensio_kb';

    /**
     * Permission callback for public read endpoints.
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request The request.
     *
     * @return bool Always true; public endpoints are open.
     */
    public function public_permissions_check( $request ) {
        unset( $request );

        return true;
    }

    /**
     * Permission callback for public write endpoints.
     *
     * Requires a valid `wp_rest` nonce. Be precise about what that buys, because
     * it is not the same for both kinds of caller:
     *
     * - For a LOGGED-IN user the nonce is bound to their user ID and session
     *   token, so it is a genuine CSRF control: another origin cannot mint one.
     * - For an ANONYMOUS visitor it is not a per-visitor secret. Core computes it
     *   over user ID 0 with an empty session token, so a single nonce value is
     *   valid for every logged-out visitor site-wide for the whole 12-24 hour
     *   tick, and it is printed into public page markup. An attacker scrapes the
     *   page once and replays it from anywhere, with no cookies. It proves the
     *   caller read a page of this site recently; it does not prove much else.
     *
     * Anonymous write endpoints therefore need a second control, which is what
     * check_rate_limit() is for. This method deliberately does not apply one
     * itself: the bucket, limit and window belong to the endpoint.
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request The request.
     *
     * @return true|WP_Error True when the nonce is valid, a WP_Error otherwise.
     */
    public function public_write_permissions_check( $request ) {
        $nonce = $request->get_header( 'X-WP-Nonce' );

        if ( ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
            return new WP_Error(
                'lisensio_invalid_nonce',
                __( 'Your session has expired. Reload the page and try again.', 'lisensio-knowledge-base' ),
                array( 'status' => 403 )
            );
        }

        return true;
    }

    /**
     * Permission callback for management endpoints.
     *
     * Checks the `manage_lisensio_kb` capability. Until the capability
     * mapping lands (#3) the check falls back to `manage_options` so the
     * endpoints are never accidentally public.
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request The request.
     *
     * @return true|WP_Error True when the current user may manage the knowledge base.
     */
    public function manage_permissions_check( $request ) {
        unset( $request );

        if ( current_user_can( self::MANAGE_CAPABILITY ) || current_user_can( 'manage_options' ) ) {
            return true;
        }

        return new WP_Error(
            'lisensio_forbidden',
            __( 'You are not allowed to manage the knowledge base.', 'lisensio-knowledge-base' ),
            array( 'status' => rest_authorization_required_code() )
        );
    }

    /**
     * Hold a caller to a request budget, keyed on their visitor hash.
     *
     * The scaffold's shared implementation of the "rate-limited per visitor
     * hash" control the specification requires of the anonymous write endpoints.
     * It lives here so #8 (feedback) and #9 (views) inherit one implementation
     * instead of inventing one each, and so the counter is keyed on the privacy
     * -safe hash rather than on an IP address.
     *
     * No endpoint in this scaffold calls it — choosing a bucket, a limit and a
     * window is the consuming issue's decision. Call it from a permission
     * callback after the nonce check:
     *
     *     $limited = $this->check_rate_limit( 'feedback', 5, MINUTE_IN_SECONDS );
     *     if ( is_wp_error( $limited ) ) {
     *         return $limited;
     *     }
     *
     * The count is read across every hash the visitor could currently be known
     * by, so a salt rotation inside the window does not hand them a fresh
     * budget, and is incremented under the current hash only.
     *
     * @since 1.0.0
     *
     * @param string $bucket A short name for the thing being limited, e.g. `feedback`.
     * @param int    $limit  Requests permitted per window. Must be at least 1.
     * @param int    $window Window length in seconds.
     *
     * @return true|WP_Error True when the caller is within budget, a 429 WP_Error otherwise.
     */
    protected function check_rate_limit( $bucket, $limit, $window ) {
        $limit  = max( 1, (int) $limit );
        $window = max( 1, (int) $window );

        $visitor = new Lisensio_KB_Visitor();

        // Keyed on the address alone: a caller who varies its user agent would
        // otherwise mint a fresh identity, and a fresh budget, per request.
        $hashes = $visitor->get_abuse_hashes();

        if ( empty( $hashes ) ) {
            return true;
        }

        $used = 0;

        foreach ( $hashes as $hash ) {
            $used = max( $used, (int) get_transient( self::rate_limit_key( $bucket, $hash ) ) );
        }

        if ( $used >= $limit ) {
            return new WP_Error(
                'lisensio_rate_limited',
                __( 'Too many requests. Please wait a moment and try again.', 'lisensio-knowledge-base' ),
                array( 'status' => 429 )
            );
        }

        set_transient( self::rate_limit_key( $bucket, $hashes[0] ), $used + 1, $window );

        return true;
    }

    /**
     * The transient key a rate-limit counter is stored under.
     *
     * Hashed down so the key stays inside the 172-character option-name limit
     * whatever the bucket is called.
     *
     * @since 1.0.0
     *
     * @param string $bucket The bucket name.
     * @param string $hash   The visitor hash.
     *
     * @return string The transient key.
     */
    private static function rate_limit_key( $bucket, $hash ) {
        return 'lisensio_rl_' . md5( (string) $bucket . '|' . (string) $hash );
    }

    /**
     * The response returned by a route that has been reserved but not built.
     *
     * @since 1.0.0
     *
     * @param string $route The route path, for the error message.
     *
     * @return WP_Error A 501 error naming the unimplemented route.
     */
    protected function not_implemented( $route ) {
        return new WP_Error(
            'lisensio_not_implemented',
            sprintf(
                /* translators: %s: REST route path */
                __( 'The %s endpoint is registered but not yet implemented.', 'lisensio-knowledge-base' ),
                $route
            ),
            array( 'status' => 501 )
        );
    }
}
