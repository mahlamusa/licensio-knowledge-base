/**
 * Extension row actions in the Articles app, end to end.
 *
 * The example extension (`tests/fixtures/extension-example/features/row-actions.php`)
 * declares five row actions: two with a handler, one without, one behind a
 * capability nobody has, and one whose `visible( row )` allows only titles
 * marked `[marked]`. Only the two with a handler show on a plain row, among
 * the row's own actions, on hover and on keyboard focus; the marked action
 * shows on a marked row alone, and none show in the Trash view. Running one calls the handler with the row and the app's context,
 * and `ctx.refresh( [ id ] )` fetches just that row again, so the new title
 * shows without a page reload and focus stays on the action. A double click
 * runs it once. A handler that rejects gets the app's error toast, its error
 * is logged to the console, and the row stays usable.
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
 * @param {Page}    page   The browser page, on the Articles app.
 * @param {string}  method POST to create, DELETE to delete.
 * @param {string}  path   The route after `licensio_articles`.
 * @param {Object}  data   The fields to send.
 * @param {boolean} force  Whether a DELETE skips the Trash.
 *
 * @return {Promise<{id: number}>} The response body.
 */
async function articleRequest(
	page: Page,
	method: string,
	path: string,
	data: Record< string, string > = {},
	force = true
): Promise< { id: number } > {
	return page.evaluate(
		async ( { verb, route, body, skipTrash } ) => {
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
					( 'DELETE' === verb && skipTrash
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
		{ verb: method, route: path, body: data, skipTrash: force }
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
			'example/fails',
			'example/marked',
			'example/no-script',
			'example/retitle',
		] );
		await expect(
			row.getByRole( 'button', { name: 'Example retitle' } )
		).toHaveCount( 1 );
		await expect(
			row.getByRole( 'button', { name: 'Example fails' } )
		).toHaveCount( 1 );
		await expect( row.getByText( 'Example no script' ) ).toHaveCount( 0 );
		await expect( row.getByText( 'Example forbidden' ) ).toHaveCount( 0 );

		await page.mouse.move( 0, 0 );
		await expect( actions ).toHaveCSS( 'opacity', '0' );
		await row.hover();
		await expect( actions ).toHaveCSS( 'opacity', '1' );
	} );

	test( 'an action shows only on the rows its visible check allows', async ( {
		page,
	} ) => {
		const markedTitle = `${ title } [marked]`;
		const { id: markedId } = await articleRequest( page, 'POST', '', {
			title: markedTitle,
			status: 'publish',
		} );

		try {
			await page.reload();

			const app = contentManagerMount( page, 'articles' );
			const rowOf = ( name: string ) =>
				app.locator( '.ant-table-row' ).filter( {
					has: page.getByRole( 'link', { name, exact: true } ),
				} );
			const plain = rowOf( title );
			const marked = rowOf( markedTitle );

			await expect( plain ).toHaveCount( 1 );
			await expect( marked ).toHaveCount( 1 );
			await expect(
				plain.getByRole( 'button', { name: 'Example retitle' } )
			).toHaveCount( 1 );
			await expect(
				plain.getByRole( 'button', { name: 'Example marked' } )
			).toHaveCount( 0 );
			await expect(
				marked.getByRole( 'button', { name: 'Example retitle' } )
			).toHaveCount( 1 );

			await marked.hover();
			await marked
				.getByRole( 'button', { name: 'Example marked' } )
				.click();

			await expect
				.poll( () =>
					page.evaluate(
						() =>
							(
								window as unknown as {
									licensioKbExampleRowRuns?: unknown[];
								}
							 ).licensioKbExampleRowRuns
					)
				)
				.toEqual( [ { id: markedId, screen: 'articles' } ] );
		} finally {
			await articleRequest( page, 'DELETE', `/${ markedId }` );
		}
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
		// The refreshed row keeps the keyboard user where they were.
		await expect( action ).toBeFocused();
		await expect( actions ).toHaveCSS( 'opacity', '1' );

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

	test( 'a double click runs the action once', async ( { page } ) => {
		const app = contentManagerMount( page, 'articles' );
		const row = app.locator( '.ant-table-row', { hasText: title } );

		await row.hover();
		await row.getByRole( 'button', { name: 'Example retitle' } ).dblclick();

		await expect(
			row.getByRole( 'link', { name: `${ title } (retitled)` } )
		).toBeVisible();
		await expect(
			row.getByRole( 'button', { name: 'Example retitle' } )
		).toHaveAttribute( 'aria-busy', 'false' );

		const runs = await page.evaluate(
			() =>
				(
					window as unknown as {
						licensioKbExampleRowRuns?: unknown[];
					}
				 ).licensioKbExampleRowRuns
		);

		expect( runs ).toHaveLength( 1 );
	} );

	test( 'a failing action shows an error and leaves the row usable', async ( {
		page,
	} ) => {
		const logged: string[] = [];

		page.on( 'console', ( entry ) => {
			if ( 'error' === entry.type() ) {
				logged.push( entry.text() );
			}
		} );

		const app = contentManagerMount( page, 'articles' );
		const row = app.locator( '.ant-table-row', { hasText: title } );

		await row.hover();
		await row.getByRole( 'button', { name: 'Example fails' } ).click();

		await expect( page.locator( '.ant-message' ) ).toContainText(
			'Example fails could not be completed.'
		);
		// The original error is logged for the extension's author.
		expect( logged.join( '\n' ) ).toContain(
			'Row action example/fails failed:'
		);

		await row.getByRole( 'button', { name: 'Example retitle' } ).click();

		await expect(
			row.getByRole( 'link', { name: `${ title } (retitled)` } )
		).toBeVisible();
	} );

	test( 'trashed rows show no extension actions', async ( { page } ) => {
		const app = contentManagerMount( page, 'articles' );

		await articleRequest( page, 'DELETE', `/${ id }`, {}, false );
		await page.goto(
			contentManagerUrl( 'articles' ) +
				'&status=trash&search=' +
				encodeURIComponent( title )
		);

		const row = app.locator( '.ant-table-row', { hasText: title } );

		await expect(
			row.getByRole( 'button', { name: 'Restore' } )
		).toBeVisible();
		await expect( row.locator( '[data-row-action]' ) ).toHaveCount( 0 );
	} );
} );
