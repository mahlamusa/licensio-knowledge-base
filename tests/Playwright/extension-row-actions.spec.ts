/**
 * Extension row actions in the Articles app, end to end.
 *
 * The example extension (`tests/fixtures/extension-example/features/row-actions.php`)
 * declares three row actions: one with a handler, one without, and one behind
 * a capability nobody has. Only the first shows, among the row's own actions,
 * on hover and on keyboard focus. Running it calls the handler with the row
 * and the app's context, and `ctx.refresh( [ id ] )` fetches just that row
 * again, so the new title shows without a page reload.
 *
 * Needs a running WordPress with this plugin and the example extension active
 * and an administrator account, so it is skipped unless `LICENSIO_KB_E2E=1`
 * and the two credential variables are present.
 *
 * @since 1.1.0
 */

import { expect, test, type Page } from '@playwright/test';
import {
	contentManagerMount,
	contentManagerUrl,
} from './helpers/content-manager';
import { failOnUnresolvedComponents } from './helpers/ant-components';
import { password, user } from './helpers/session';

failOnUnresolvedComponents();

const enabled = process.env.LICENSIO_KB_E2E === '1';

test.skip(
	! enabled || ! user || ! password,
	'Set LICENSIO_KB_E2E=1 and the admin credentials to run.'
);

/**
 * Create or delete an article through the core REST API, as the signed-in user.
 *
 * @since 1.1.0
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

test.describe( 'Extension row actions', () => {
	let id = 0;
	let title = '';

	test.beforeEach( async ( { page } ) => {
		await page.goto( contentManagerUrl( 'articles' ) );
		await expect(
			contentManagerMount( page, 'articles' ).locator( '#lkb-main' )
		).toBeVisible();

		// An article of its own: other specs search for the titles of theirs.
		title = `Row action ${ Date.now() }-${ test.info().workerIndex }`;
		( { id } = await articleRequest( page, 'POST', '', {
			title,
			status: 'publish',
		} ) );

		await page.goto(
			contentManagerUrl( 'articles' ) +
				'&search=' +
				encodeURIComponent( title )
		);
	} );

	test.afterEach( async ( { page } ) => {
		if ( id ) {
			await articleRequest( page, 'DELETE', `/${ id }` );
		}
	} );

	test( 'only the permitted actions with a handler show, on hover', async ( {
		page,
	} ) => {
		const app = contentManagerMount( page, 'articles' );
		const row = app.locator( '.ant-table-row', { hasText: title } );
		const actions = row.locator( '.lkb-rowactions' );

		await expect( row ).toBeVisible();

		const declared = await page.evaluate( () =>
			(
				window as unknown as {
					licensioKbArticles: {
						extensions: { rowActions: Array< { key: string } > };
					};
				}
			 ).licensioKbArticles.extensions.rowActions
				.map( ( action ) => action.key )
				.filter( ( key ) => key.startsWith( 'example/' ) )
				.sort()
		);

		// The capability check runs in PHP; a missing handler hides in JS.
		expect( declared ).toEqual( [
			'example/no-script',
			'example/retitle',
		] );
		await expect(
			row.getByRole( 'button', { name: 'Example retitle' } )
		).toHaveCount( 1 );
		await expect( row.getByText( 'Example no script' ) ).toHaveCount( 0 );
		await expect( row.getByText( 'Example forbidden' ) ).toHaveCount( 0 );

		await page.mouse.move( 0, 0 );
		await expect( actions ).toHaveCSS( 'opacity', '0' );
		await row.hover();
		await expect( actions ).toHaveCSS( 'opacity', '1' );
	} );

	test( 'runs from the keyboard and refreshes just that row', async ( {
		page,
	} ) => {
		const app = contentManagerMount( page, 'articles' );
		const row = app.locator( '.ant-table-row', { hasText: title } );
		const actions = row.locator( '.lkb-rowactions' );
		const action = row.getByRole( 'button', { name: 'Example retitle' } );
		const lists: string[] = [];

		await expect( row ).toBeVisible();
		await page.mouse.move( 0, 0 );

		// Tab from the title link through the row's actions to the
		// extension's, as a keyboard user would.
		await row.getByRole( 'link', { name: title } ).focus();

		for ( let step = 0; step < 10; step++ ) {
			await page.keyboard.press( 'Tab' );

			if (
				await action.evaluate(
					( el ) => el === el.ownerDocument.activeElement
				)
			) {
				break;
			}
		}

		await expect( action ).toBeFocused();
		await expect( actions ).toHaveCSS( 'opacity', '1' );

		page.on( 'request', ( request ) => {
			const url = decodeURIComponent( request.url() );

			if (
				'GET' === request.method() &&
				/licensio-kb\/v1\/articles(?!\/)/.test( url )
			) {
				lists.push( url );
			}
		} );

		await page.keyboard.press( 'Enter' );

		await expect(
			row.getByRole( 'link', { name: `${ title } (retitled)` } )
		).toBeVisible();

		const runs = await page.evaluate(
			() =>
				(
					window as unknown as {
						licensioKbExampleRowRuns?: Array< {
							id: number;
							screen: string;
						} >;
					}
				 ).licensioKbExampleRowRuns
		);

		expect( runs ).toEqual( [ { id, screen: 'articles' } ] );
		// One list request, for that row alone: no reload of the page.
		expect( lists ).toHaveLength( 1 );
		expect( lists[ 0 ] ).toContain( `include[]=${ id }` );
	} );
} );
