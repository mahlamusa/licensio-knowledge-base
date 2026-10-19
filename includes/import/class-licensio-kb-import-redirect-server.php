<?php
/**
 * Serves the 301s an import recorded.
 *
 * Only requests that would otherwise 404 are considered. An old URL that still
 * resolves — because the source plugin is still active, or because the new
 * permalink is the same — is left alone, and a normal page view never reads
 * the map: the option is not autoloaded and is only fetched on a 404.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Redirects old knowledge base URLs to their imported equivalents.
 *
 * @since 1.0.0
 */
class Licensio_KB_Import_Redirect_Server {

    /**
     * Register the WordPress hooks.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function init_hooks() {
        add_action( 'template_redirect', array( $this, 'maybe_redirect' ), 1 );
    }

    /**
     * Redirect a 404 whose path an import moved.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function maybe_redirect() {
        if ( ! is_404() ) {
            return;
        }

        $requested = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
        $target    = self::target_for( $requested, Licensio_KB_Import_Redirects::served_map() );

        if ( '' === $target ) {
            return;
        }

        wp_safe_redirect( $target, 301, 'Licensio Knowledge Base' );
        exit;
    }

    /**
     * Where a requested URL should go, if an import moved it.
     *
     * The query string is carried across, so tracking parameters on an old link
     * survive the hop.
     *
     * @since 1.0.0
     *
     * @param string                $requested The request URI.
     * @param array<string, string> $map       Old path to new URL.
     *
     * @return string The absolute target URL, or '' when the path was not moved.
     */
    public static function target_for( $requested, array $map ) {
        $path = Licensio_KB_Import_Redirects::normalize_path( (string) $requested );

        if ( '' === $path || ! isset( $map[ $path ] ) || '' === (string) $map[ $path ] ) {
            return '';
        }

        $target = (string) $map[ $path ];
        $query  = wp_parse_url( (string) $requested, PHP_URL_QUERY );

        if ( is_string( $query ) && '' !== $query ) {
            $target .= ( false === strpos( $target, '?' ) ? '?' : '&' ) . $query;
        }

        return $target;
    }
}
