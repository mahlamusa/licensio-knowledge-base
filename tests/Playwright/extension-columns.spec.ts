/**
 * Extension columns in the Articles app, end to end.
 *
 * The example extension (`tests/fixtures/extension-example/features/columns.php`)
 * declares two Articles columns in an "Example" group, hidden by default:
 * "Example ID", placed after the title, sortable and rendered by its script,
 * and "Example batch", shown from its `text` with no script, which says how
 * many times the batch data filter ran on the request.
 *
 * The test turns both on in the Columns picker, checks where they sit and
 * what they show, that the choice survives a reload, that the ID column
 * sorts both ways, and that hiding it sticks. The batch column reads
 * "batch 1" on every row: the whole page's data comes from one batch.
 *
 * Column choices are the administrator's own preferences, which every spec
 * shares, so the test runs on its own (`@one-at-a-time`) and puts the saved
 * preferences back when it ends.
 *
 * Needs a running WordPress with this plugin and the example extension
 * active, so it is skipped unless `LICENSIO_KB_E2E=1` and the admin
 * credentials are present.
 *
 * @since 1.1.0
 */

import { expect, test, type Page } from '@playwright/test';
import { openContentManager } from './helpers/content-manager';
import { password, user } from './helpers/session';

const enabled = process.env.LICENSIO_KB_E2E === '1';

test.skip(
	! enabled || ! user || ! password,
	'Set LICENSIO_KB_E2E=1 and the admin credentials to run.'
);

/**
 * Read or write the administrator's list preferences through the REST API.
 *
 * @since 1.1.0
 *
 * @param {Page}    page  A page with the Articles app open.
 * @param {unknown} value The preferences to save; leave out to read them.
 *
 * @return {Promise<unknown>} The saved preferences.
 */
function preferences( page: Page, value?: unknown ): Promise< unknown > {
	return page.evaluate( async ( body ) => {
		const { root, nonce } = (
			window as unknown as {
				licensioKbArticles: { root: string; nonce: string };
			}
		 ).licensioKbArticles;
		const response = await fetch(
			root.replace( /\/+$/, '' ) + '/preferences',
			{
				method: body ? 'PUT' : 'GET',
				headers: {
					'X-WP-Nonce': nonce,
					'Content-Type': 'application/json',
				},
				body: body ? JSON.stringify( body ) : undefined,
			}
		);

		return response.json();
	}, value );
}

/**
 * Wait for the Columns picker's save to reach the server.
 *
 * @since 1.1.0
 *
 * @param {Page} page The browser page.
 *
 * @return {Promise<unknown>} Resolves with the response.
 */
function preferencesSaved( page: Page ): Promise< unknown > {
	return page.waitForResponse(
		( response ) =>
			response.url().includes( 'preferences' ) &&
			'PUT' === response.request().method()
	);
}

test.describe( 'extension columns @one-at-a-time', () => {
	let saved: unknown;

	test.beforeEach( async ( { page } ) => {
		await openContentManager( page, 'articles' );
		saved = await preferences( page );
	} );

	test.afterEach( async ( { page } ) => {
		await openContentManager( page, 'articles' );
		await preferences( page, saved );
	} );

	test( 'the example columns show, persist, sort and hide', async ( {
		page,
	} ) => {
		const app = await openContentManager( page, 'articles' );
		const headers = app.locator( '.ant-table-thead th' );
		const idHeader = headers.filter( { hasText: 'Example ID' } );
		const ids = app.locator( '.ant-table-row .example-id' );
		const columnsButton = app.getByRole( 'button', {
			name: 'Columns and rows per page',
		} );
		const picker = page
			.locator( '.ant-popover' )
			.filter( { hasText: 'Rows per page' } );

		await expect( app.locator( '.ant-table-row' ).first() ).toBeVisible();
		await expect( idHeader ).toHaveCount( 0 );

		// Both columns are offered under their group, and turned on.
		await columnsButton.click();
		await expect(
			picker.getByText( 'Example', { exact: true } )
		).toBeVisible();

		let save = preferencesSaved( page );

		await picker.getByRole( 'checkbox', { name: 'Example ID' } ).check();
		await picker.getByRole( 'checkbox', { name: 'Example batch' } ).check();
		await save;
		await page.keyboard.press( 'Escape' );

		// The ID column follows the title and is drawn by its script; the
		// batch column shows its text, one batch for the whole page.
		await expect( ids.first() ).toHaveText( /^#\d+$/ );

		const titles = await headers.allInnerTexts();

		expect(
			titles.map( ( title ) => title.trim() ).indexOf( 'Example ID' )
		).toBe(
			titles.map( ( title ) => title.trim() ).indexOf( 'Title' ) + 1
		);

		const rows = await app.locator( '.ant-table-row' ).count();

		await expect(
			app.locator( '.ant-table-row' ).getByText( 'batch 1', {
				exact: true,
			} )
		).toHaveCount( rows );

		// The choice is saved.
		await page.reload();
		await expect( ids.first() ).toHaveText( /^#\d+$/ );

		// Sorting by it sorts by article ID, descending first.
		await idHeader.click();
		await expect( page ).toHaveURL( /orderby=example%2Fid/ );

		const descending = ( await ids.allInnerTexts() ).map( ( text ) =>
			Number( text.slice( 1 ) )
		);

		expect( descending ).toEqual(
			[ ...descending ].sort( ( a, b ) => b - a )
		);

		await idHeader.click();
		await expect( page ).toHaveURL( /order=asc/ );
		await expect( ids.first() ).not.toHaveText( `#${ descending[ 0 ] }` );

		const ascending = ( await ids.allInnerTexts() ).map( ( text ) =>
			Number( text.slice( 1 ) )
		);

		expect( ascending ).toEqual(
			[ ...ascending ].sort( ( a, b ) => a - b )
		);

		// The sort survives a reload.
		await page.reload();
		await expect( ids.first() ).toHaveText( `#${ ascending[ 0 ] }` );

		// Hidden again, and it stays hidden.
		await columnsButton.click();
		save = preferencesSaved( page );
		await picker.getByRole( 'checkbox', { name: 'Example ID' } ).uncheck();
		await save;
		await page.keyboard.press( 'Escape' );
		await expect( idHeader ).toHaveCount( 0 );

		await page.reload();
		await expect( app.locator( '.ant-table-row' ).first() ).toBeVisible();
		await expect( idHeader ).toHaveCount( 0 );
		await expect(
			headers.filter( { hasText: 'Example batch' } )
		).toHaveCount( 1 );
	} );
} );
