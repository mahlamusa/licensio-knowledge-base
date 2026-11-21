/**
 * Extension bulk actions in the Articles app, end to end.
 *
 * Uses the example extension's bulk actions
 * (`tests/fixtures/extension-example/features/bulk-actions.php`): the bulk bar
 * offers the one with a handler, and hides the one with no handler and the
 * one the user lacks the capability for. Applying it hands the handler the
 * selected IDs with no confirmation or progress of the app's own, and the
 * handler's `ctx.refresh( ids )` fetches just those rows with `include`
 * instead of reloading the list, and `ctx.clearSelection()` empties the
 * selection. The Trash view offers no extension bulk actions.
 *
 * The spec seeds three articles in a category of its own with wp-cli and
 * removes them afterwards, so besides `LICENSIO_KB_E2E=1` and the admin
 * credentials it needs `LICENSIO_KB_WP_CLI` (see `helpers/wp-cli.ts`) and
 * the example extension active. Without them it is skipped.
 *
 * @since 1.1.0
 */

import { expect, test, type Locator, type Page } from '@playwright/test';
import { contentManagerUrl } from './helpers/content-manager';
import { failOnUnresolvedComponents } from './helpers/ant-components';
import { openDashboard, password, user } from './helpers/session';
import { wpCli, wpEval } from './helpers/wp-cli';

failOnUnresolvedComponents();

const enabled = process.env.LICENSIO_KB_E2E === '1';

/** A word only the seeded category and titles contain. */
const WORD = `extbulke2e${ Date.now() }`;

/**
 * Open the bulk action chooser, scrolled to its end.
 *
 * The dropdown is a virtual list that renders only the options in view, and
 * the extension actions come after the built-in ones.
 *
 * @since 1.1.0
 *
 * @param {Page}    page The browser page.
 * @param {Locator} app  The app.
 *
 * @return {Locator} The open dropdown.
 */
async function openChooser( page: Page, app: Locator ): Promise< Locator > {
	await app.locator( '.lkb-bulk__choice' ).click();

	const dropdown = page.locator(
		'.ant-select-dropdown:not(.ant-select-dropdown-hidden)'
	);

	await expect( dropdown ).toBeVisible();
	await dropdown
		.locator( '.rc-virtual-list-holder' )
		.evaluate( ( holder ) => {
			holder.scrollTop = holder.scrollHeight;
		} );

	return dropdown;
}

test.describe( 'Extension bulk actions', () => {
	test.describe.configure( { mode: 'serial' } );

	test.skip(
		! enabled || '' === user || '' === password || '' === wpCli,
		'Set LICENSIO_KB_E2E=1, the admin credentials and LICENSIO_KB_WP_CLI to run.'
	);

	let category = 0;

	test.beforeAll( () => {
		category = Number(
			wpEval( `
				$term = wp_insert_term( '${ WORD }', 'licensio_category' );
				for ( $i = 1; $i <= 3; $i++ ) {
					$id = wp_insert_post( array( 'post_type' => 'licensio_article', 'post_status' => 'publish', 'post_title' => '${ WORD } ' . $i ) );
					wp_set_object_terms( $id, array( $term['term_id'] ), 'licensio_category' );
				}
				echo $term['term_id'];
			` )
		);
	} );

	test.afterAll( () => {
		wpEval( `
			$q = new WP_Query( array( 'post_type' => 'licensio_article', 'post_status' => 'any', 'tax_query' => array( array( 'taxonomy' => 'licensio_category', 'terms' => ${ category } ) ), 'fields' => 'ids', 'posts_per_page' => -1 ) );
			foreach ( $q->posts as $id ) { wp_delete_post( $id, true ); }
			wp_delete_term( ${ category }, 'licensio_category' );
		` );
	} );

	test.beforeEach( async ( { page } ) => {
		await openDashboard( page );
	} );

	test( 'the handler gets the selected IDs and refreshes just those rows', async ( {
		page,
	} ) => {
		await page.goto(
			contentManagerUrl( 'articles' ) + '&category=' + category
		);

		const app = page.locator( '#licensio-kb-articles' );
		const rows = app.locator( '.ant-table-tbody .ant-table-row' );

		await expect( rows ).toHaveCount( 3 );

		const keys = await rows.evaluateAll( ( trs ) =>
			trs.map( ( tr ) => Number( ( tr as HTMLElement ).dataset.rowKey ) )
		);
		const chosen = keys.slice( 0, 2 );

		for ( const id of chosen ) {
			await app
				.locator( `tr[data-row-key="${ id }"] .ant-checkbox-input` )
				.check();
		}

		await expect( app.locator( '.lkb-bulk.is-armed' ) ).toBeVisible();

		// PHP sends the declared action with no handler, but not the one the
		// user lacks the capability for.
		expect(
			await page.evaluate( () =>
				(
					window as unknown as {
						licensioKbArticles: {
							extensions: {
								bulkActions: Array< { key: string } >;
							};
						};
					}
				 ).licensioKbArticles.extensions.bulkActions.map(
					( action ) => action.key
				)
			)
		).toEqual( [ 'example/retitle', 'example/no-handler' ] );

		const dropdown = await openChooser( page, app );

		await expect(
			dropdown.getByText( 'Example: retitle', { exact: true } )
		).toBeVisible();
		await expect(
			dropdown.getByText( 'Example: no handler', { exact: true } ),
			'An action with no handler is hidden.'
		).toHaveCount( 0 );
		await expect(
			dropdown.getByText( 'Example: forbidden', { exact: true } ),
			'An action the user lacks the capability for is hidden.'
		).toHaveCount( 0 );

		await dropdown.getByText( 'Example: retitle', { exact: true } ).click();

		// Every list request from here on: the refresh, and any reload.
		const lists: URL[] = [];

		page.on( 'request', ( request ) => {
			const url = new URL( request.url() );
			const route = url.searchParams.get( 'rest_route' ) ?? url.pathname;

			if (
				'GET' === request.method() &&
				/licensio-kb\/v1\/articles\/?$/.test( route )
			) {
				lists.push( url );
			}
		} );

		await app
			.locator( '.lkb-bulk' )
			.getByRole( 'button', { name: 'Apply' } )
			.click();

		for ( const id of chosen ) {
			await expect(
				app.locator( `tr[data-row-key="${ id }"]` )
			).toContainText( `Retitled ${ id }` );
		}

		const given = await page.evaluate(
			() =>
				(
					window as unknown as {
						licensioKbExampleBulk?: {
							ids: number[];
							screen: string;
						};
					}
				 ).licensioKbExampleBulk
		);

		expect( given?.screen ).toBe( 'articles' );
		expect( [ ...( given?.ids ?? [] ) ].sort() ).toEqual(
			[ ...chosen ].sort()
		);

		// The handler cleared the selection.
		await expect( app.locator( '.lkb-bulk.is-armed' ) ).toHaveCount( 0 );

		// No confirmation or progress of the app's own.
		await expect( page.getByRole( 'alertdialog' ) ).toHaveCount( 0 );
		await expect( page.locator( '.ant-modal:visible' ) ).toHaveCount( 0 );

		expect(
			lists.map( ( url ) =>
				url.searchParams.getAll( 'include[]' ).map( Number ).sort()
			),
			'One request, for just the chosen rows.'
		).toEqual( [ [ ...chosen ].sort() ] );

		// The row not chosen is untouched.
		await expect(
			app.locator( `tr[data-row-key="${ keys[ 2 ] }"]` )
		).toContainText( `${ WORD } ` );
	} );

	test( 'the Trash view offers no extension bulk actions', async ( {
		page,
	} ) => {
		wpEval( `
			$q = new WP_Query( array( 'post_type' => 'licensio_article', 'tax_query' => array( array( 'taxonomy' => 'licensio_category', 'terms' => ${ category } ) ), 'fields' => 'ids', 'posts_per_page' => 1 ) );
			wp_trash_post( $q->posts[0] );
		` );

		await page.goto(
			contentManagerUrl( 'articles' ) +
				'&status=trash&category=' +
				category
		);

		const app = page.locator( '#licensio-kb-articles' );
		const rows = app.locator( '.ant-table-tbody .ant-table-row' );

		await expect( rows ).toHaveCount( 1 );
		await rows.first().locator( '.ant-checkbox-input' ).check();

		const dropdown = await openChooser( page, app );

		await expect(
			dropdown.getByText( 'Restore', { exact: true } )
		).toBeVisible();
		await expect(
			dropdown.getByText( 'Example: retitle', { exact: true } )
		).toHaveCount( 0 );
	} );
} );
