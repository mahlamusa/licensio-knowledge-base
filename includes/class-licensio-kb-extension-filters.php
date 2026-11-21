<?php
/**
 * The extension filter values a request may carry.
 *
 * Turns raw input into the `{key} => value` map of extension filters the
 * current user may use, each value one of its declared options. The articles
 * list endpoint reads it from `ext_filters`, and the core screen redirect
 * from each filter's `query_arg`. Anything else is dropped silently, so a
 * stale link or a deactivated extension never breaks the list.
 *
 * @package Licensio_KB
 * @since   1.1.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Picks the valid extension filter values out of a request.
 *
 * @since 1.1.0
 */
class Licensio_KB_Extension_Filters {

    /**
     * The request and app URL argument holding the chosen values, as `ext_filters[{key}]=value`.
     *
     * @since 1.1.0
     */
    const PARAM = 'ext_filters';

    /**
     * The extension registry.
     *
     * @since 1.1.0
     * @var Licensio_KB_Extensions
     */
    private $extensions;

    /**
     * The screen whose filters are read: `articles`, `categories` or `tags`.
     *
     * @since 1.1.0
     * @var string
     */
    private $screen;

    /**
     * Read the filters of one screen.
     *
     * @since 1.1.0
     *
     * @param Licensio_KB_Extensions $extensions The registry.
     * @param string                 $screen     `articles`, `categories` or `tags`.
     */
    public function __construct( Licensio_KB_Extensions $extensions, $screen ) {
        $this->extensions = $extensions;
        $this->screen     = $screen;
    }

    /**
     * The valid values of a `{key} => value` map.
     *
     * A key must be a filter of the screen the current user may use, and its
     * value one of that filter's options once passed through `sanitize_key()`,
     * as the options were when declared.
     *
     * @since 1.1.0
     *
     * @param mixed $value The raw map.
     *
     * @return array<string, string> The chosen option of each filter, keyed by filter key.
     */
    public function sanitize( $value ) {
        $chosen = array();

        if ( ! is_array( $value ) ) {
            return $chosen;
        }

        foreach ( $this->extensions->allowed( $this->screen, 'filters' ) as $key => $filter ) {
            if ( ! isset( $value[ $key ] ) || ! is_scalar( $value[ $key ] ) ) {
                continue;
            }

            $option = sanitize_key( (string) $value[ $key ] );

            if ( '' !== $option && isset( $filter['options'][ $option ] ) ) {
                $chosen[ $key ] = $option;
            }
        }

        return $chosen;
    }

    /**
     * The valid values a core screen request carries in the filters' `query_arg`s.
     *
     * Filters declared without a `query_arg` are skipped.
     *
     * @since 1.1.0
     *
     * @param array<string, mixed> $query The request's query arguments, unslashed.
     *
     * @return array<string, string> The chosen option of each filter, keyed by filter key.
     */
    public function from_query_args( array $query ) {
        $values = array();

        foreach ( $this->extensions->allowed( $this->screen, 'filters' ) as $key => $filter ) {
            if ( '' !== $filter['query_arg'] && isset( $query[ $filter['query_arg'] ] ) ) {
                $values[ $key ] = $query[ $filter['query_arg'] ];
            }
        }

        return $this->sanitize( $values );
    }
}
