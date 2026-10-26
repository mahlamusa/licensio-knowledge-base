/**
 * The Articles app, end to end.
 *
 * Opens the app through its hidden admin page and checks what only a real
 * WordPress can answer: the pills match the database, a Popconfirm stands
 * before every write and Cancel sends nothing, a quick edit is saved only
 * once confirmed, and the list state lives in the URL: filters replace the
 * history entry while sort and page push one. An out of range page moves
 * back to the last page, a failed load shows no empty state, and a quick
 * edit reloads the list and its counts. A keyboard trash moves focus to the Undo toast, whose
 * countdown waits while it has focus.
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
	openPopconfirm,
} from './helpers/content-manager';
import { failOnUnresolvedComponents } from './helpers/ant-components';
import { logIn, password, user } from './helpers/session';

failOnUnresolvedComponents();

const enabled = process.env.LICENSIO_KB_E2E === '1';

/** How long the Undo toast stays, as `UNDO_SECONDS` in the app's UndoToast. */
const UNDO_SECONDS = 10;

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

/**
 * Create or delete an article through the core REST API, as the signed-in user.
 *
 * @since 1.0.0
 *
 * @param {Page}   page   The browser page, on the Articles app.
 * @param {string} method POST to create, DELETE to delete for good.
 * @param {string} path   The route after `licensio_articles`.
 * @param {Object} data   The fields to send.
 *
 * @return {Promise<{id: number}>} The response body.
 */
async function articleRequest(
	page: Page,
	method: string,
	path: string,
	data: Record< string, string > = {}
): Promise< { id: number } > {
	return page.evaluate(
		async ( { verb, route, body } ) => {
			const config = (
				window as unknown as {
					licensioKbArticles: { root: string; nonce: string };
				}
			 ).licensioKbArticles;
			const base =
				config.root.replace( 'licensio-kb/v1', 'wp/v2' ) +
				'licensio_articles' +
				route;
			const response = await fetch(
				base +
					( 'DELETE' === verb
						? ( base.includes( '?' ) ? '&' : '?' ) + 'force=true'
						: '' ),
				{
					method: verb,
					headers: {
						'X-WP-Nonce': config.nonce,
						'Content-Type': 'application/json',
					},
					credentials: 'same-origin',
					body:
						'DELETE' === verb ? undefined : JSON.stringify( body ),
				}
			);

			return response.json();
		},
		{ verb: method, route: path, body: data }
	);
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

	test( 'filters replace the history entry while sort and page push one', async ( {
		page,
	} ) => {
		const app = await openContentManager( page, 'articles' );
		const active = app.locator( '.lkb-pill.is-active' );
		const entries = () => page.evaluate( () => window.history.length );
		const start = await entries();

		await app.locator( '.lkb-pill', { hasText: 'Drafts' } ).click();
		await expect( page ).toHaveURL( /status=draft/ );
		await app.getByText( '7 days', { exact: true } ).click();
		await expect( page ).toHaveURL( /window=7/ );
		expect( await entries() ).toBe( start );

		await app.locator( '.ant-table-thead th', { hasText: 'Title' } ).click();
		await expect( page ).toHaveURL( /orderby=title/ );
		expect( await entries() ).toBe( start + 1 );

		await page.goBack();
		await expect( page ).not.toHaveURL( /orderby=title/ );
		await expect( page ).toHaveURL( /status=draft.*window=7/ );
		await expect( active ).toContainText( 'Drafts' );

		await page.goForward();
		await expect( page ).toHaveURL( /orderby=title/ );
	} );

	test( 'a page past the end moves back to the last page', async ( {
		page,
	} ) => {
		await page.goto( contentManagerUrl( 'articles' ) + '&paged=999' );

		const app = page.locator( '#licensio-kb-articles' );

		await expect( app.locator( '.ant-table-row' ).first() ).toBeVisible();
		await expect( page ).not.toHaveURL( /paged=999/ );
		await expect( app.getByText( 'No articles yet.' ) ).toHaveCount( 0 );
	} );

	test( 'a failed first load shows the error without the empty state', async ( {
		page,
	} ) => {
		await page.route(
			( url ) =>
				/licensio-kb(%2F|\/)v1(%2F|\/)articles(&|\?|$)/.test( url.href ),
			( route ) =>
				route.fulfill( {
					status: 500,
					json: { code: 'e2e', message: 'The list is down.' },
				} )
		);

		await page.goto( contentManagerUrl( 'articles' ) );

		const app = page.locator( '#licensio-kb-articles' );

		await expect( app.locator( '.ant-alert-error' ) ).toBeVisible();
		await expect( app.getByText( 'No articles yet.' ) ).toHaveCount( 0 );
		await expect( app.getByText( 'No articles match' ) ).toHaveCount( 0 );
	} );

	test( 'a quick edit that publishes a draft takes it out of the Drafts view', async ( {
		page,
	} ) => {
		const app = await openContentManager( page, 'articles' );
		const title = `Quick publish ${ Date.now() }`;
		const { id } = await articleRequest( page, 'POST', '', {
			title,
			status: 'draft',
		} );

		try {
			await page.goto( contentManagerUrl( 'articles' ) + '&status=draft' );

			const pill = ( label: string ) =>
				app.locator( '.lkb-pill', { hasText: label } );
			const count = async ( label: string ) =>
				Number(
					/\((\d+)\)/.exec( ( await pill( label ).textContent() ) ?? '' )?.[ 1 ]
				);
			const row = app.locator( '.ant-table-row', { hasText: title } );

			await expect( row ).toBeVisible();

			const drafts = await count( 'Drafts' );
			const published = await count( 'Published' );

			await row.getByRole( 'button', { name: 'Quick edit' } ).click();

			const form = app.locator( '.ant-table-expanded-row' ).first();

			await form
				.locator( '.ant-form-item', { hasText: 'Status' } )
				.locator( '.ant-select' )
				.click();
			await page
				.locator( '.ant-select-dropdown:visible .ant-select-item-option' )
				.filter( { hasText: /^Published$/ } )
				.click();
			await form.getByRole( 'button', { name: /Save/ } ).click();
			await confirmPopconfirm( page );

			await expect( row ).toHaveCount( 0 );
			await expect( app.locator( '.ant-table-expanded-row' ) ).toHaveCount( 0 );
			await expect( pill( 'Drafts' ) ).toContainText( `(${ drafts - 1 })` );
			await expect( pill( 'Published' ) ).toContainText(
				`(${ published + 1 })`
			);
		} finally {
			await articleRequest( page, 'DELETE', '/' + id );
		}
	} );

	test( 'titles match core and row actions show on hover, focus and phone width', async ( {
		page,
	} ) => {
		const style = ( element: Element ) => {
			const computed = window.getComputedStyle( element );

			return [ computed.fontSize, computed.fontWeight ];
		};

		await page.goto( '/wp-admin/edit.php' );

		const core = await page.locator( '.row-title' ).first().evaluate( style );
		const app = await openContentManager( page, 'articles' );
		const row = app.locator( '.ant-table-row' ).first();
		const actions = row.locator( '.lkb-rowactions' );

		expect( core ).toEqual( [ '14px', '600' ] );
		expect(
			await row.locator( '.lkb-title__link' ).evaluate( style )
		).toEqual( core );

		// Hidden only visually: still in the accessibility tree.
		await page.mouse.move( 0, 0 );
		await expect( actions ).toHaveCSS( 'opacity', '0' );
		await expect(
			row.getByRole( 'button', { name: 'Quick edit' } )
		).toBeAttached();

		await row.hover();
		await expect( actions ).toHaveCSS( 'opacity', '1' );
		await page.mouse.move( 0, 0 );
		await expect( actions ).toHaveCSS( 'opacity', '0' );

		await row.getByRole( 'link', { name: 'Edit', exact: true } ).focus();
		await expect( actions ).toHaveCSS( 'opacity', '1' );
		await row
			.getByRole( 'link', { name: 'Edit', exact: true } )
			.evaluate( ( link ) => ( link as HTMLElement ).blur() );
		await expect( actions ).toHaveCSS( 'opacity', '0' );

		await page.setViewportSize( { width: 390, height: 844 } );
		await expect( actions ).toHaveCSS( 'opacity', '1' );
	} );

	test( 'a keyboard trash hands focus to Undo and waits while it has focus', async ( {
		page,
	} ) => {
		test.setTimeout( 60_000 );

		const app = await openContentManager( page, 'articles' );
		const title = `Undo E2E ${ Date.now() }`;
		const { id } = await articleRequest( page, 'POST', '', {
			title,
			status: 'publish',
		} );

		try {
			await page.reload();
			await app.getByLabel( 'Search articles by title' ).fill( title );
			await app.getByLabel( 'Search articles by title' ).press( 'Enter' );

			const row = app.locator( '.ant-table-row', { hasText: title } );

			await expect( row ).toBeVisible();
			await row.getByRole( 'button', { name: 'Trash' } ).focus();
			await page.keyboard.press( 'Enter' );
			await expect(
				openPopconfirm( page ).getByRole( 'button', { name: 'Cancel' } )
			).toBeFocused();
			await page.keyboard.press( 'Tab' );
			await page.keyboard.press( 'Enter' );

			const toast = page.locator( '.ant-notification-notice' );
			const undo = toast.getByRole( 'button', { name: 'Undo' } );

			await expect( undo ).toBeFocused();
			await expect( undo ).toHaveAccessibleDescription(
				'1 article moved to the trash.'
			);
			await expect(
				page.locator( '#a11y-speak-polite' ),
				'Undo reads the message, so it is not announced again.'
			).not.toContainText( '1 article moved to the trash.' );

			// The countdown is paused while Undo has focus.
			await page.waitForTimeout( UNDO_SECONDS * 1000 + 1500 );
			await expect( undo ).toBeVisible();

			await page.keyboard.press( 'Enter' );
			await expect( toast ).toBeHidden();
			await expect(
				page.getByText( '1 article put back.' )
			).toBeVisible();
			await expect( page.locator( '.lkb-page__title' ) ).toBeFocused();
		} finally {
			await articleRequest( page, 'DELETE', '/' + id );
		}
	} );
} );
