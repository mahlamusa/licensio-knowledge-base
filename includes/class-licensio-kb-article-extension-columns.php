<?php
/**
 * The extension columns of the Articles app.
 *
 * Reads the columns other plugins declared for the `articles` screen of the
 * extension registry and answers what the Articles REST routes need to know
 * about them: which keys the route schemas accept, which ones the current
 * user may see, which ones join the default columns and which ones the list
 * can be sorted by.
 *
 * Route schemas are built while the routes register, before the current
 * user is known, so they read every declared key; the request-time answers
 * keep only what the current user may see.
 *
 * @package Licensio_KB
 * @since   1.1.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Answers which extension columns the Articles app has.
 *
 * @since 1.1.0
 */
class Licensio_KB_Article_Extension_Columns {

    /**
     * The extension registry.
     *
     * @since 1.1.0
     * @var Licensio_KB_Extensions
     */
    private $extensions;

    /**
     * Build the lookup.
     *
     * @since 1.1.0
     *
     * @param Licensio_KB_Extensions $extensions The extension registry.
     */
    public function __construct( Licensio_KB_Extensions $extensions ) {
        $this->extensions = $extensions;
    }

    /**
     * Every declared column key, whoever may see it, for the route schemas.
     *
     * @since 1.1.0
     *
     * @return string[] The keys, in the order they were declared.
     */
    public function keys() {
        return array_keys( $this->extensions->items( Licensio_KB_Extensions::SCREEN_ARTICLES, 'columns' ) );
    }

    /**
     * Every declared sortable column key, whoever may see it, for the route schemas.
     *
     * @since 1.1.0
     *
     * @return string[] The keys, in the order they were declared.
     */
    public function sortable_keys() {
        return self::sortable( $this->extensions->items( Licensio_KB_Extensions::SCREEN_ARTICLES, 'columns' ) );
    }

    /**
     * The column keys the current user may see.
     *
     * @since 1.1.0
     *
     * @return string[] The keys, in the order they were declared.
     */
    public function allowed_keys() {
        return array_keys( $this->allowed() );
    }

    /**
     * The column keys the current user may see that show before they choose columns.
     *
     * @since 1.1.0
     *
     * @return string[] The keys, in the order they were declared.
     */
    public function default_keys() {
        return array_keys(
            array_filter(
                $this->allowed(),
                function ( $column ) {
                    return ! empty( $column['default_visible'] );
                }
            )
        );
    }

    /**
     * The default columns the user has not been offered yet.
     *
     * A column declared visible by default after the user saved their
     * columns shows once for them; once they save again it is known, and
     * stays hidden if they hid it.
     *
     * @since 1.1.0
     *
     * @param string[] $known The extension column keys the user was offered when they last saved.
     *
     * @return string[] The keys, in the order they were declared.
     */
    public function new_default_keys( array $known ) {
        return array_values( array_diff( $this->default_keys(), $known ) );
    }

    /**
     * Whether the current user may sort the list by a column.
     *
     * @since 1.1.0
     *
     * @param string $key The column key.
     *
     * @return bool True for a sortable column the current user may see.
     */
    public function can_sort_by( $key ) {
        return in_array( $key, self::sortable( $this->allowed() ), true );
    }

    /**
     * The extension columns among a list of shown columns, for the row data.
     *
     * @since 1.1.0
     *
     * @param string[] $columns The columns the user shows, core and extension.
     *
     * @return string[] The extension column keys the current user may see, in the order given.
     */
    public function visible_keys( array $columns ) {
        return array_values( array_intersect( $columns, $this->allowed_keys() ) );
    }

    /**
     * The declared columns the current user may see.
     *
     * @since 1.1.0
     *
     * @return array<string, array<string, mixed>> Key to column.
     */
    private function allowed() {
        return $this->extensions->allowed( Licensio_KB_Extensions::SCREEN_ARTICLES, 'columns' );
    }

    /**
     * The keys of the sortable columns.
     *
     * @since 1.1.0
     *
     * @param array<string, array<string, mixed>> $columns Key to column.
     *
     * @return string[] The keys.
     */
    private static function sortable( array $columns ) {
        return array_keys(
            array_filter(
                $columns,
                function ( $column ) {
                    return ! empty( $column['sortable'] );
                }
            )
        );
    }
}
