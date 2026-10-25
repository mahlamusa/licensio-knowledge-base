/**
 * The Categories app, end to end.
 *
 * Drag and drop with Cancel and Confirm, a keyboard move, the add drawer,
 * delete with reassignment, merge, the article order pane against the live
 * order routes (a drag saved and shown on the category archive, and a saved
 * first page of a long list), and the phone-width layout. Every write must wait for its
 * Popconfirm, and a cancelled one must send nothing.
 *
 * The suite needs a running WordPress with this plugin active and an
 * administrator account, so it is skipped unless `LICENSIO_KB_E2E=1` and the
 * two credential variables are present.
 *
 * @since 1.0.0
 */

import { expect, test, type Page, type Request } from '@playwright/test';
import {
	cancelPopconfirm,
	confirmPopconfirm,
	openContentManager,
} from './helpers/content-manager';
import { logIn, password, user } from './helpers/session';

const enabled = process.env.LICENSIO_KB_E2E === '1';

interface Category {
	id: number;
	name: string;
	parent: number;
}

/**
 * Call a REST route with the page's own nonce.
 *
 * The path may carry a query string; it is joined with `&` when the REST root
 * is itself a query string (plain permalinks).
 *
 * @since 1.0.0
 *
 * @param {Page}    page      A page with the Categories app loaded.
 * @param {string}  method    The HTTP method.
 * @param {string}  path      The route below the namespace.
 * @param {unknown} body      The JSON body, if any.
 * @param {string}  namespace The namespace, the plugin's own by default.
 *
 * @return {Promise<unknown>} The decoded answer.
 */
async function api(
	page: Page,
	method: string,
	path: string,
	body?: unknown,
	namespace = 'licensio-kb/v1'
): Promise< unknown > {
	return page.evaluate(
		async ( args ) => {
			const config = (
				window as unknown as {
					licensioKbCategories: { root: string; nonce: string };
				}
			 ).licensioKbCategories;
			const root = config.root
				.replace( /\/+$/, '' )
				.replace( 'licensio-kb/v1', args.namespace );
			const [ route, query ] = args.path.split( '?' );
			let base = root + '/' + route;

			if ( query ) {
				base += ( base.includes( '?' ) ? '&' : '?' ) + query;
			}

			const response = await fetch( base, {
				method: args.method,
				headers: {
					'X-WP-Nonce': config.nonce,
					'Content-Type': 'application/json',
				},
				body: args.body ? JSON.stringify( args.body ) : undefined,
			} );

			return response.json();
		},
		{ method, path, body, namespace }
	);
}

interface OrderPage {
	items: Array< { id: number; title: string; order: number | null } >;
	total: number;
}

/**
 * Create published articles in a category, one REST call each.
 *
 * @since 1.0.0
 *
 * @param {Page}     page     The browser page.
 * @param {number}   category The category.
 * @param {string[]} titles   The article titles.
 *
 * @return {Promise<number[]>} The new article IDs, in the order of the titles.
 */
async function makeArticles(
	page: Page,
	category: number,
	titles: string[]
): Promise< number[] > {
	const ids: number[] = [];

	for ( let start = 0; start < titles.length; start += 20 ) {
		const batch = await Promise.all(
			titles.slice( start, start + 20 ).map(
				( title ) =>
					api(
						page,
						'POST',
						'licensio_articles',
						{ title, status: 'publish', licensio_categories: [ category ] },
						'wp/v2'
					) as Promise< { id: number } >
			)
		);

		ids.push( ...batch.map( ( one ) => one.id ) );
	}

	return ids;
}

/**
 * Delete every article these tests made, so each run starts clean.
 *
 * @since 1.0.0
 *
 * @param {Page} page A page with the Categories app loaded.
 *
 * @return {Promise<void>} Resolves once none is left.
 */
async function clearArticles( page: Page ): Promise< void > {
	for ( ;; ) {
		const found = ( await api(
			page,
			'GET',
			'licensio_articles?search=E2E%20order&status=any&per_page=100&_fields=id',
			undefined,
			'wp/v2'
		) ) as Array< { id: number } >;

		if ( ! Array.isArray( found ) || 0 === found.length ) {
			return;
		}

		await Promise.all(
			found.map( ( one ) =>
				api( page, 'DELETE', `licensio_articles/${ one.id }?force=true`, undefined, 'wp/v2' )
			)
		);
	}
}

/**
 * The titles in the article order pane, top first.
 *
 * @since 1.0.0
 *
 * @param {Page} page The browser page.
 *
 * @return {Promise<string[]>} The titles.
 */
async function paneTitles( page: Page ): Promise< string[] > {
	return (
		await page.locator( '.lkb-order__item .lkb-order__name > a' ).allTextContents()
	).map( ( title ) => title.trim() );
}

/**
 * Remove every category, so each test starts from an empty tree.
 *
 * @since 1.0.0
 *
 * @param {Page} page A page with the Categories app loaded.
 *
 * @return {Promise<void>} Resolves once the tree is empty.
 */
async function clearCategories( page: Page ): Promise< void > {
	const items = ( await api( page, 'GET', 'categories' ) ) as Category[];

	for ( const item of items.filter( ( one ) => 0 === one.parent ) ) {
		await deleteTree( page, item, items );
	}
}

/**
 * Delete a category after its children.
 *
 * @since 1.0.0
 *
 * @param {Page}       page  The browser page.
 * @param {Category}   item  The category.
 * @param {Category[]} items Every category.
 *
 * @return {Promise<void>} Resolves once it is gone.
 */
async function deleteTree(
	page: Page,
	item: Category,
	items: Category[]
): Promise< void > {
	for ( const child of items.filter( ( one ) => one.parent === item.id ) ) {
		await deleteTree( page, child, items );
	}

	await api( page, 'DELETE', `categories/${ item.id }` );
}

/**
 * Create a category through the REST route.
 *
 * @since 1.0.0
 *
 * @param {Page}   page   The browser page.
 * @param {string} name   The name.
 * @param {number} parent The parent, 0 for the top level.
 *
 * @return {Promise<number>} The new category ID.
 */
async function make( page: Page, name: string, parent = 0 ): Promise< number > {
	const created = ( await api( page, 'POST', 'categories', {
		name,
		parent,
	} ) ) as Category;

	return created.id;
}

/**
 * The ID of a category, looked up by name.
 *
 * @since 1.0.0
 *
 * @param {Page}   page The browser page.
 * @param {string} name The category name.
 *
 * @return {Promise<number>} The category ID.
 */
async function categoryId( page: Page, name: string ): Promise< number > {
	const items = ( await api( page, 'GET', 'categories' ) ) as Category[];
	const found = items.find( ( item ) => name === item.name );

	if ( ! found ) {
		throw new Error( `No category named ${ name }.` );
	}

	return found.id;
}

/**
 * Collect the category write requests the page sends.
 *
 * @since 1.0.0
 *
 * @param {Page} page The browser page.
 *
 * @return {Request[]} A live list of the requests.
 */
function recordWrites( page: Page ): Request[] {
	const writes: Request[] = [];

	page.on( 'request', ( request ) => {
		if (
			'GET' !== request.method() &&
			request.url().includes( 'categories' )
		) {
			writes.push( request );
		}
	} );

	return writes;
}

/**
 * The tree node of a category.
 *
 * @since 1.0.0
 *
 * @param {Page}   page The browser page.
 * @param {string} name The category name.
 *
 * @return {import('@playwright/test').Locator} The node.
 */
function node( page: Page, name: string ) {
	return page
		.locator( '.ant-tree-treenode' )
		.filter( {
			has: page.locator( '.lkb-tree__name', {
				hasText: new RegExp(
					'^\\s*' + name.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' ) + '\\s*$'
				),
			} ),
		} )
		.first();
}

/**
 * The names of the top-level categories, in tree order.
 *
 * @since 1.0.0
 *
 * @param {Page} page The browser page.
 *
 * @return {Promise<string[]>} The names.
 */
async function topLevel( page: Page ): Promise< string[] > {
	return page
		.locator(
			'.ant-tree-treenode:not(:has(.ant-tree-indent-unit)) .lkb-tree__name'
		)
		.allTextContents();
}

/**
 * Open a category's actions menu and pick an item.
 *
 * @since 1.0.0
 *
 * @param {Page}   page   The browser page.
 * @param {string} name   The category name.
 * @param {string} action The menu item label.
 *
 * @return {Promise<void>} Resolves once the item is clicked.
 */
async function act( page: Page, name: string, action: string ): Promise< void > {
	await page.getByRole( 'button', { name: `Actions for ${ name }`, exact: true } ).click();
	await page
		.locator( '.ant-dropdown:not(.ant-dropdown-hidden) .ant-dropdown-menu-item' )
		.filter( { hasText: action } )
		.click();
}

test.describe( 'Categories app', () => {
	test.describe.configure( { mode: 'serial' } );
	test.skip( ! enabled || ! user || ! password, 'Needs LICENSIO_KB_E2E=1 and admin credentials.' );

	test.beforeEach( async ( { page } ) => {
		await logIn( page );
		await openContentManager( page, 'categories' );
		await clearArticles( page );
		await clearCategories( page );
		await make( page, 'Alpha' );
		await make( page, 'Beta' );
		const gamma = await make( page, 'Gamma' );
		await make( page, 'Gamma child', gamma );
		await page.reload();
		await expect( node( page, 'Gamma child' ) ).toBeVisible();
	} );

	test( 'a cancelled drop puts the tree back and sends nothing', async ( { page } ) => {
		const writes = recordWrites( page );

		await node( page, 'Alpha' ).dragTo( node( page, 'Gamma' ), {
			targetPosition: { x: 60, y: 22 },
		} );

		await cancelPopconfirm( page );

		expect( await topLevel( page ) ).toEqual( [ 'Alpha', 'Beta', 'Gamma' ] );
		expect( writes ).toHaveLength( 0 );
	} );

	test( 'a confirmed drop is saved and survives a reload', async ( { page } ) => {
		await node( page, 'Alpha' ).dragTo( node( page, 'Gamma' ), {
			targetPosition: { x: 60, y: 22 },
		} );

		await confirmPopconfirm( page );
		await expect( page.locator( '.ant-message' ) ).toContainText( 'Category moved.' );

		const after = await topLevel( page );

		expect( after ).not.toEqual( [ 'Alpha', 'Beta', 'Gamma' ] );

		await page.reload();
		await expect( node( page, 'Gamma child' ) ).toBeVisible();
		expect( await topLevel( page ) ).toEqual( after );
	} );

	test( 'a category cannot be dropped into its own child', async ( { page } ) => {
		const writes = recordWrites( page );

		await node( page, 'Gamma' ).dragTo( node( page, 'Gamma child' ) );

		await expect(
			page.locator( '.lkb-move-confirm:not(.ant-popover-hidden)' )
		).toHaveCount( 0 );
		expect( writes ).toHaveLength( 0 );
	} );

	test( 'move down from the keyboard menu asks first', async ( { page } ) => {
		const writes = recordWrites( page );

		await act( page, 'Alpha', 'Move down' );
		await cancelPopconfirm( page );
		expect( writes ).toHaveLength( 0 );
		expect( await topLevel( page ) ).toEqual( [ 'Alpha', 'Beta', 'Gamma' ] );

		await act( page, 'Alpha', 'Move down' );
		await confirmPopconfirm( page );
		await expect.poll( () => topLevel( page ) ).toEqual( [ 'Beta', 'Alpha', 'Gamma' ] );
		expect( writes ).toHaveLength( 1 );
	} );

	test( 'the actions menu works from the keyboard alone', async ( { page } ) => {
		const writes = recordWrites( page );
		const trigger = page.getByRole( 'button', { name: 'Actions for Alpha', exact: true } );
		const edit = page.getByRole( 'menuitem', { name: 'Edit' } ).locator( 'visible=true' );

		// Escape closes the menu and puts focus back on its button.
		await trigger.focus();
		await page.keyboard.press( 'Enter' );
		await expect( edit ).toBeFocused();
		await page.keyboard.press( 'Escape' );
		await expect( trigger ).toBeFocused();
		await expect( trigger ).toHaveAttribute( 'aria-expanded', 'false' );

		// Edit, Add child, then Move down (Move up is disabled for the first category).
		await page.keyboard.press( 'Enter' );
		await expect( edit ).toBeFocused();
		await page.keyboard.press( 'ArrowDown' );
		await page.keyboard.press( 'ArrowDown' );
		await expect( page.getByRole( 'menuitem', { name: 'Move down' } ).locator( 'visible=true' ) ).toBeFocused();
		await page.keyboard.press( 'Enter' );

		await expect( page.locator( '.lkb-move-confirm .ant-popconfirm-buttons .ant-btn-primary' ) ).toBeFocused();
		await page.keyboard.press( 'Enter' );
		await expect.poll( () => topLevel( page ) ).toEqual( [ 'Beta', 'Alpha', 'Gamma' ] );
		expect( writes ).toHaveLength( 1 );
	} );

	test( 'the drawer adds a category with a Dashicon and an SVG icon', async ( { page } ) => {
		await page.getByRole( 'button', { name: 'Add category' } ).first().click();

		const drawer = page.locator( '.ant-drawer-content' );

		await drawer.getByLabel( 'Name' ).fill( 'Delta & co' );
		await drawer.getByLabel( 'Icon', { exact: true } ).fill( 'dashicons-book-alt' );
		await drawer
			.getByLabel( 'Inline SVG icon' )
			.fill( '<svg viewBox="0 0 1 1"><script>alert(1)</script><path d="M0 0h1v1z"/></svg>' );

		await drawer.getByRole( 'button', { name: 'Save' } ).click();
		await confirmPopconfirm( page );

		await expect( node( page, 'Delta & co' ) ).toBeVisible();

		const items = ( await api( page, 'GET', 'categories' ) ) as Array<
			Category & { icon: string; icon_svg: string }
		>;
		const delta = items.find( ( item ) => 'Delta & co' === item.name );

		expect( delta?.icon ).toBe( 'dashicons-book-alt' );
		expect( delta?.icon_svg ).toContain( '<path' );
		expect( delta?.icon_svg ).not.toContain( 'script' );
	} );

	test( 'delete moves the children to the chosen category', async ( { page } ) => {
		await act( page, 'Gamma', 'Delete…' );

		const dialog = page.locator( '.ant-modal-content' );

		await dialog.getByText( 'Move under another category' ).click();
		await dialog
			.getByLabel( 'Category to move the child categories under' )
			.click();
		await page
			.locator( '.ant-select-item-option' )
			.filter( { hasText: 'Beta' } )
			.click();

		await dialog.getByRole( 'button', { name: 'Delete' } ).click();
		await confirmPopconfirm( page );

		await expect( node( page, 'Gamma' ) ).toHaveCount( 0 );

		const items = ( await api( page, 'GET', 'categories' ) ) as Category[];
		const beta = items.find( ( item ) => 'Beta' === item.name );
		const child = items.find( ( item ) => 'Gamma child' === item.name );

		expect( child?.parent ).toBe( beta?.id );
	} );

	test( 'merge re-parents children and deletes the source', async ( { page } ) => {
		await act( page, 'Gamma', 'Merge…' );

		const dialog = page.locator( '.ant-modal-content' );

		await dialog.getByLabel( 'Merge into' ).click();
		await page
			.locator( '.ant-select-item-option' )
			.filter( { hasText: 'Alpha' } )
			.click();

		await dialog.getByRole( 'button', { name: 'Merge' } ).click();
		await confirmPopconfirm( page );

		await expect( node( page, 'Gamma' ) ).toHaveCount( 0 );

		const items = ( await api( page, 'GET', 'categories' ) ) as Category[];
		const alpha = items.find( ( item ) => 'Alpha' === item.name );

		expect(
			items.find( ( item ) => 'Gamma child' === item.name )?.parent
		).toBe( alpha?.id );
	} );

	test( 'a dragged article order is saved, survives a reload and orders the archive', async ( { page } ) => {
		const alpha = await categoryId( page, 'Alpha' );
		const [ a, b, c, d ] = await makeArticles( page, alpha, [
			'E2E order A',
			'E2E order B',
			'E2E order C',
			'E2E order D',
		] );

		// C and A have positions; B and D have none, so they follow by title.
		await api( page, 'PUT', `categories/${ alpha }/articles/order`, { ids: [ c, a ] } );

		await page.reload();
		await node( page, 'Alpha' ).locator( '.lkb-tree__name' ).click();

		const pane = page.getByRole( 'region', { name: 'Article order' } );
		const items = pane.locator( '.lkb-order__item' );

		await expect.poll( () => paneTitles( page ) ).toEqual( [
			'E2E order C',
			'E2E order A',
			'E2E order B',
			'E2E order D',
		] );

		await items.nth( 3 ).dragTo( items.nth( 0 ) );

		const expected = [ 'E2E order D', 'E2E order C', 'E2E order A', 'E2E order B' ];

		expect( await paneTitles( page ) ).toEqual( expected );

		const writes = recordWrites( page );

		await pane.getByRole( 'button', { name: 'Save order' } ).click();
		await cancelPopconfirm( page );
		expect( writes ).toHaveLength( 0 );

		await pane.getByRole( 'button', { name: 'Save order' } ).click();
		await confirmPopconfirm( page );
		await expect( page.locator( '.ant-message' ) ).toContainText( 'Article order saved.' );
		expect( writes ).toHaveLength( 1 );
		expect( writes[ 0 ].postDataJSON() ).toEqual( { ids: [ d, c, a, b ] } );

		await page.reload();
		await node( page, 'Alpha' ).locator( '.lkb-tree__name' ).click();
		await expect.poll( () => paneTitles( page ) ).toEqual( expected );
		await expect( pane ).not.toContainText( 'not available' );

		const term = ( await api(
			page,
			'GET',
			`licensio_categories/${ alpha }?_fields=link`,
			undefined,
			'wp/v2'
		) ) as { link: string };

		await page.goto( term.link );

		const archive = (
			await page
				.locator( '.licensio-kb-card--article .licensio-kb-card__title' )
				.allTextContents()
		).map( ( title ) => title.trim() );

		expect( archive ).toEqual( expected );
	} );

	test( 'saving the first page of a long list renumbers the rest after it', async ( { page } ) => {
		test.setTimeout( 240_000 );

		const alpha = await categoryId( page, 'Alpha' );
		const titles = Array.from(
			{ length: 207 },
			( _, index ) => `E2E order ${ String( index + 1 ).padStart( 3, '0' ) }`
		);
		const ids = await makeArticles( page, alpha, titles );

		// The first 205 get positions, last made first; the last two have none.
		const positioned = ids.slice( 0, 205 ).reverse();

		await api( page, 'PUT', `categories/${ alpha }/articles/order`, { ids: positioned } );

		await page.reload();
		await node( page, 'Alpha' ).locator( '.lkb-tree__name' ).click();

		const pane = page.getByRole( 'region', { name: 'Article order' } );

		await expect( pane ).toContainText( 'Only the first 200 of 207 articles are listed.' );
		await expect.poll( () => paneTitles( page ).then( ( list ) => list.length ) ).toBe( 200 );

		await pane.getByRole( 'button', { name: 'Move “E2E order 204” up' } ).click();
		await pane.getByRole( 'button', { name: 'Save order' } ).click();
		await confirmPopconfirm( page );
		await expect( page.locator( '.ant-message' ) ).toContainText( 'Article order saved.' );

		const firstPage = [ positioned[ 1 ], positioned[ 0 ], ...positioned.slice( 2, 200 ) ];
		const one = ( await api(
			page,
			'GET',
			`categories/${ alpha }/articles?page=1&per_page=200`
		) ) as OrderPage;
		const two = ( await api(
			page,
			'GET',
			`categories/${ alpha }/articles?page=2&per_page=200`
		) ) as OrderPage;

		expect( one.total ).toBe( 207 );
		expect( one.items.map( ( item ) => item.id ) ).toEqual( firstPage );
		expect( one.items.map( ( item ) => item.order ) ).toEqual(
			firstPage.map( ( _, index ) => ( index + 1 ) * 10 )
		);

		// The five positioned articles past the first page follow in their old order; the two without a position keep none.
		expect( two.items.map( ( item ) => item.id ) ).toEqual( [
			...positioned.slice( 200 ),
			ids[ 205 ],
			ids[ 206 ],
		] );
		expect( two.items.map( ( item ) => item.order ) ).toEqual( [
			2010,
			2020,
			2030,
			2040,
			2050,
			null,
			null,
		] );
	} );

	test( 'the panes stack at phone width', async ( { page } ) => {
		await page.setViewportSize( { width: 390, height: 844 } );

		const cards = page.locator( '.lkb-categories > *' );
		const first = await cards.nth( 0 ).boundingBox();
		const second = await cards.nth( 1 ).boundingBox();

		expect( second?.y ?? 0 ).toBeGreaterThan(
			( first?.y ?? 0 ) + ( first?.height ?? 0 ) - 1
		);

		const overflow = await page.evaluate(
			() => document.documentElement.scrollWidth - window.innerWidth
		);

		expect( overflow ).toBeLessThanOrEqual( 0 );
	} );
} );
