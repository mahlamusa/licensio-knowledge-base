/**
 * The Articles app, end to end.
 *
 * Opens the app through its hidden admin page and checks what only a real
 * WordPress can answer: the pills match the database, a Popconfirm stands
 * before every write and Cancel sends nothing, a quick edit is saved only
 * once confirmed, and the list state lives in the URL, with back and forward
 * stepping through it.
 *
 * Needs a running WordPress with this plugin active, an administrator
 * account and at least one article, so it is skipped unless
 * `LICENSIO_KB_E2E=1` and the two credential variables are present. CI
 * provides both through `bin/e2e-env.sh`.
 *
 * @since 1.0.0
 */

import { expect, test, type Page, type Request } from '@playwright/test';
import {
	cancelPopconfirm,
	confirmPopconfirm,
	contentManagerUrl,
	openContentManager,
} from './helpers/content-manager';
import { logIn, password, user } from './helpers/session';

const enabled = process.env.LICENSIO_KB_E2E === '1';

/**
 * Collect every write the app sends to its REST routes.
 *
 * @since 1.0.0
 *
 * @param {Page} page The browser page.
 *
 * @return {Request[]} The list, filled as requests go out.
 */
function recordWrites( page: Page ): Request[] {
	const writes: Request[] = [];

	page.on( 'request', ( request ) => {
		const route = decodeURIComponent( request.url() );

		if (
			request.method() !== 'GET' &&
			route.includes( 'licensio-kb/v1/articles' )
		) {
			writes.push( request );
		}
	} );

	return writes;
}

test.describe( 'Articles app', () => {
	test.skip(
		! enabled || '' === user || '' === password,
		'Set LICENSIO_KB_E2E=1 and the admin credentials to run.'
	);

	test.beforeEach( async ( { page } ) => {
		await logIn( page );
	} );

	test( 'loads with the header and status pills', async ( { page } ) => {
		const app = await openContentManager( page, 'articles' );

		await expect(
			app.getByRole( 'heading', { name: 'Articles' } )
		).toBeVisible();
		await expect(
			app.getByRole( 'link', { name: 'New article' } )
		).toBeVisible();
		await expect(
			app.getByRole( 'group', { name: /status/i } )
		).toContainText( 'All (' );
		await expect( app.locator( '.ant-table-row' ).first() ).toBeVisible();
	} );

	test( 'Cancel on a Popconfirm sends no request', async ( { page } ) => {
		const app = await openContentManager( page, 'articles' );
		const writes = recordWrites( page );
		const row = app.locator( '.ant-table-row' ).first();

		await row.hover();
		await row.getByRole( 'button', { name: 'Trash' } ).click();
		await cancelPopconfirm( page );
		await page.waitForTimeout( 500 );

		expect( writes ).toHaveLength( 0 );
	} );

	test( 'quick edit saves only after confirmation', async ( { page } ) => {
		const app = await openContentManager( page, 'articles' );
		const writes = recordWrites( page );
		const row = app.locator( '.ant-table-row' ).first();
		const title = `E2E ${ Date.now() }`;

		await row.getByRole( 'button', { name: 'Quick edit' } ).click();

		const form = app.locator( '.ant-table-expanded-row' ).first();
		const original = await form.getByLabel( 'Title' ).inputValue();

		await form.getByLabel( 'Title' ).fill( title );
		await form.getByRole( 'button', { name: /Save/ } ).click();

		expect( writes ).toHaveLength( 0 );

		await confirmPopconfirm( page );

		try {
			await expect( app.getByText( title ) ).toBeVisible();

			expect( writes ).toHaveLength( 1 );
			expect( writes[ 0 ].method() ).toBe( 'PATCH' );
			expect( JSON.parse( writes[ 0 ].postData() ?? '{}' ) ).toEqual( {
				title,
			} );
		} finally {
			// Put the article's title back so the site is left as found.
			if ( writes.length > 0 ) {
				const nonce = await page.evaluate(
					() =>
						(
							window as unknown as {
								licensioKbArticles?: { nonce?: string };
							}
						 ).licensioKbArticles?.nonce ?? ''
				);

				await page.request.fetch( writes[ 0 ].url(), {
					method: 'PATCH',
					headers: { 'X-WP-Nonce': nonce },
					data: { title: original },
				} );
			}
		}
	} );

	test( 'the view is restored from the URL', async ( { page } ) => {
		await page.goto(
			contentManagerUrl( 'articles' ) +
				'&status=draft&window=7&orderby=title&order=asc'
		);

		const app = page.locator( '#licensio-kb-articles' );

		await expect( app.locator( '.lkb-pill.is-active' ) ).toContainText(
			'Drafts'
		);

		await app.locator( '.lkb-pill', { hasText: 'Published' } ).click();
		await expect( page ).toHaveURL( /status=publish/ );

		await page.reload();
		await expect( app.locator( '.lkb-pill.is-active' ) ).toContainText(
			'Published'
		);
		await expect( page ).toHaveURL( /window=7/ );
	} );

	test( 'back and forward step through status and window changes', async ( {
		page,
	} ) => {
		const app = await openContentManager( page, 'articles' );
		const active = app.locator( '.lkb-pill.is-active' );

		await app.locator( '.lkb-pill', { hasText: 'Drafts' } ).click();
		await expect( page ).toHaveURL( /status=draft/ );
		await app.getByText( '7 days', { exact: true } ).click();
		await expect( page ).toHaveURL( /window=7/ );

		await page.goBack();
		await expect( page ).not.toHaveURL( /window=7/ );
		await expect( active ).toContainText( 'Drafts' );

		await page.goBack();
		await expect( page ).not.toHaveURL( /status=draft/ );
		await expect( active ).toContainText( 'All' );

		await page.goForward();
		await expect( active ).toContainText( 'Drafts' );
	} );
} );
