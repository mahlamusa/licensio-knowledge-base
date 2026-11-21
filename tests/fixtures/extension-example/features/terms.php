<?php
/**
 * Example extension: columns and row actions in the Categories and Tags apps.
 *
 * Declares on both term apps:
 *
 * - `example/term-note`, a default visible column a script renders, reading
 *   the term meta `licensio_kb_example_note` (a counter) through the batch
 *   filter, in one meta query per page;
 * - `example/term-text`, a default visible column with no script, so the app
 *   shows the `text` the per-term filter fills, placed after Slug, so in the
 *   Tags table it sits between Name and Description, under the Quick edit
 *   row's shared cell;
 * - `example/term-hidden`, a column that is not default visible, so neither
 *   app shows it and the REST list does not fill it;
 * - `example/term-bump`, a row action whose script adds one to the counter
 *   through this plugin's own REST route, then calls `ctx.refresh( [ id ] )`;
 * - `example/term-silent`, a row action with no script, which stays hidden;
 * - `example/term-fail`, a row action whose script always fails, so the app
 *   shows its generic error toast.
 *
 * Nothing is declared unless the option `licensio_kb_example_terms` is on.
 * The fixture is active for the whole browser suite, and the width checks
 * expect the Tags table with its own columns alone to fit at 1024px; with
 * any more it scrolls inside its card there. The term extension spec turns
 * the option on while it runs, alone.
 *
 * @package Licensio_KB
 * @since   1.1.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'licensio_kb_register_extensions', 'licensio_kb_example_terms_declare' );
add_filter( 'licensio_kb_term_extension_data_batch', 'licensio_kb_example_terms_batch', 10, 3 );
add_filter( 'licensio_kb_term_extension_data', 'licensio_kb_example_terms_row', 10, 3 );
add_action( 'licensio_kb_enqueue_app_extensions', 'licensio_kb_example_terms_script', 10, 2 );
add_action( 'rest_api_init', 'licensio_kb_example_terms_route' );

/**
 * The term meta key of the example counter.
 *
 * @since 1.1.0
 *
 * @return string The meta key.
 */
function licensio_kb_example_terms_meta_key() {
    return 'licensio_kb_example_note';
}

/**
 * The taxonomy of each term app.
 *
 * @since 1.1.0
 *
 * @return array<string, string> Screen to taxonomy.
 */
function licensio_kb_example_terms_taxonomies() {
    return array(
        'categories' => 'licensio_category',
        'tags'       => 'licensio_tag',
    );
}

/**
 * Declare the example columns and row actions on both term apps.
 *
 * @since 1.1.0
 *
 * @param Licensio_KB_Extensions $extensions The registry.
 *
 * @return void
 */
function licensio_kb_example_terms_declare( $extensions ) {
    if ( ! get_option( 'licensio_kb_example_terms' ) ) {
        return;
    }

    foreach ( array_keys( licensio_kb_example_terms_taxonomies() ) as $screen ) {
        $extensions->add_column(
            $screen,
            'example/term-note',
            array(
                'label'           => 'Example note',
                'default_visible' => true,
            )
        );
        $extensions->add_column(
            $screen,
            'example/term-text',
            array(
                'label'           => 'Example text',
                'default_visible' => true,
                'position'        => 'slug',
            )
        );
        $extensions->add_column( $screen, 'example/term-hidden', array( 'label' => 'Example hidden' ) );
        $extensions->add_row_action( $screen, 'example/term-bump', array( 'label' => 'Bump note' ) );
        $extensions->add_row_action( $screen, 'example/term-silent', array( 'label' => 'Silent action' ) );
        $extensions->add_row_action( $screen, 'example/term-fail', array( 'label' => 'Failing action' ) );
        $extensions->add_row_action( $screen, 'example/term-marked', array( 'label' => 'Marked action' ) );
    }
}

/**
 * Fill the note column for a whole page, with one meta query.
 *
 * @since 1.1.0
 *
 * @param array<int, array<string, array<string, mixed>>> $data        Term ID to column key to cell data.
 * @param array<int, WP_Term>                             $terms       The page's terms, keyed by term ID.
 * @param string[]                                        $column_keys The visible extension column keys.
 *
 * @return array<int, array<string, array<string, mixed>>> The data.
 */
function licensio_kb_example_terms_batch( $data, $terms, $column_keys ) {
    if ( ! in_array( 'example/term-note', $column_keys, true ) ) {
        return $data;
    }

    update_termmeta_cache( array_keys( $terms ) );

    foreach ( $terms as $id => $term ) {
        $count = (int) get_term_meta( $term->term_id, licensio_kb_example_terms_meta_key(), true );

        $data[ $id ]['example/term-note'] = array(
            'count' => $count,
            'text'  => 'Note ' . $count,
        );
    }

    return $data;
}

/**
 * Fill the text column, and the hidden one to prove it is never asked for.
 *
 * @since 1.1.0
 *
 * @param array<string, array<string, mixed>> $cells       Column key to cell data, from the batch.
 * @param WP_Term                             $term        The term.
 * @param string[]                            $column_keys The visible extension column keys.
 *
 * @return array<string, array<string, mixed>> The cells.
 */
function licensio_kb_example_terms_row( $cells, $term, $column_keys ) {
    unset( $column_keys );

    $cells['example/term-text']   = array( 'text' => 'Text for ' . html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ) );
    $cells['example/term-hidden'] = array( 'text' => 'Never shown' );

    return $cells;
}

/**
 * Register the counter route the bump action calls.
 *
 * @since 1.1.0
 *
 * @return void
 */
function licensio_kb_example_terms_route() {
    register_rest_route(
        'licensio-kb-example/v1',
        '/bump',
        array(
            'methods'             => 'POST',
            'callback'            => 'licensio_kb_example_terms_bump',
            'permission_callback' => function () {
                return current_user_can( Licensio_KB_Capabilities::CAPABILITY );
            },
            'args'                => array(
                'id'     => array(
                    'type'     => 'integer',
                    'minimum'  => 1,
                    'required' => true,
                ),
                'screen' => array(
                    'type'     => 'string',
                    'enum'     => array_keys( licensio_kb_example_terms_taxonomies() ),
                    'required' => true,
                ),
            ),
        )
    );
}

/**
 * Add one to a term's counter.
 *
 * @since 1.1.0
 *
 * @param WP_REST_Request $request The request.
 *
 * @return WP_REST_Response|WP_Error The new count.
 */
function licensio_kb_example_terms_bump( $request ) {
    $taxonomies = licensio_kb_example_terms_taxonomies();
    $term       = get_term( (int) $request->get_param( 'id' ), $taxonomies[ $request->get_param( 'screen' ) ] );

    if ( ! $term instanceof WP_Term ) {
        return new WP_Error( 'example_no_term', 'No such term.', array( 'status' => 404 ) );
    }

    $count = (int) get_term_meta( $term->term_id, licensio_kb_example_terms_meta_key(), true ) + 1;

    update_term_meta( $term->term_id, licensio_kb_example_terms_meta_key(), $count );

    return rest_ensure_response( array( 'count' => $count ) );
}

/**
 * Register the note column and the bump action on a term app page.
 *
 * The column records each render cleanup in
 * `window.licensioKbExampleTermCleanups`, so a test can tell a refreshed
 * row's cell was cleaned up before it rendered again.
 *
 * @since 1.1.0
 *
 * @param string $screen      `articles`, `categories` or `tags`.
 * @param string $hook_suffix The app page's hook suffix.
 *
 * @return void
 */
function licensio_kb_example_terms_script( $screen, $hook_suffix ) {
    unset( $hook_suffix );

    if ( ! array_key_exists( $screen, licensio_kb_example_terms_taxonomies() ) ) {
        return;
    }

    wp_register_script( 'licensio-kb-example-terms', false, array( 'licensio-kb-extensions' ), '1.1.0', false );
    wp_enqueue_script( 'licensio-kb-example-terms' );
    wp_add_inline_script(
        'licensio-kb-example-terms',
        sprintf(
            '( function ( screen, bumpUrl ) {
                window.licensioKbExampleTermCleanups = [];
                window.licensioKb.extensions.register( screen, {
                    columns: {
                        "example/term-note": {
                            render: function ( el, row ) {
                                var cell = ( row.extensions || {} )[ "example/term-note" ] || {};
                                var note = document.createElement( "span" );
                                note.setAttribute( "data-example-note", String( row.id ) );
                                note.textContent = "Rendered " + ( cell.count || 0 );
                                el.appendChild( note );
                                return function () {
                                    window.licensioKbExampleTermCleanups.push( row.id );
                                };
                            }
                        }
                    },
                    rowActions: {
                        "example/term-bump": {
                            run: function ( row, ctx ) {
                                return window.fetch( bumpUrl, {
                                    method: "POST",
                                    credentials: "same-origin",
                                    headers: { "Content-Type": "application/json", "X-WP-Nonce": ctx.rest.nonce },
                                    body: JSON.stringify( { id: row.id, screen: ctx.screen } )
                                } ).then( function ( response ) {
                                    if ( ! response.ok ) {
                                        throw new Error( "The note could not be bumped." );
                                    }
                                    return ctx.refresh( [ row.id ] );
                                } );
                            }
                        },
                        "example/term-fail": {
                            run: function () {
                                return Promise.reject( new Error( "The example action failed." ) );
                            }
                        },
                        "example/term-marked": {
                            run: function () {},
                            visible: function ( row ) {
                                return 0 === row.name.indexOf( "Marked" );
                            }
                        }
                    }
                } );
            } )( %1$s, %2$s );',
            wp_json_encode( $screen ),
            wp_json_encode( rest_url( 'licensio-kb-example/v1/bump' ) )
        )
    );
}
