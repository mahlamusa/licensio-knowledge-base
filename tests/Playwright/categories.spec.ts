/**
 * The Categories app, end to end.
 *
 * Drag and drop with Cancel and Confirm, a keyboard move, the add drawer,
 * delete with reassignment, merge, the article order pane before the order
 * routes exist, and the phone-width layout. Every write must wait for its
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

const enabled = process.env.LICENSIO_KB_E2E === '1';
const user = process.env.LICENSIO_KB_ADMIN_USER ?? '';
const password = process.env.LICENSIO_KB_ADMIN_PASSWORD ?? '';

interface Category {
	id: number;
	name: string;
	parent: number;
}

/**
 * Sign in as the administrator.
 *
 * @since 1.0.0
 *
 * @param {Page} page The browser page.
 *
 * @return {Promise<void>} Resolves once the admin bar is visible.
 */
async function logIn( page: Page ): Promise< void > {
	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', user );
	await page.fill( '#user_pass', password );
	await page.click( '#wp-submit' );
	await expect( page.locator( '#wpadminbar' ) ).toBeVisible();
}

/**
 * Call a categories route with the page's own nonce.
 *
 * @since 1.0.0
 *
 * @param {Page}    page   A page with the Categories app loaded.
 * @param {string}  method The HTTP method.
 * @param {string}  path   The route below the namespace.
 * @param {unknown} body   The JSON body, if any.
 *
 * @return {Promise<unknown>} The decoded answer.
 */
async function api(
	page: Page,
	method: string,
	path: string,
	body?: unknown
): Promise< unknown > {
	return page.evaluate(
		async ( args ) => {
			const config = (
				window as unknown as {
					licensioKbCategories: { root: string; nonce: string };
				}
			 ).licensioKbCategories;
			const base = config.root.replace( /\/+$/, '' ) + '/' + args.path;
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
		{ method, path, body }
	);
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

	test( 'the article order pane says when ordering is not available', async ( { page } ) => {
		await node( page, 'Alpha' ).locator( '.lkb-tree__name' ).click();

		const pane = page.getByRole( 'region', { name: 'Article order' } );
		const answer = await api( page, 'GET', 'categories/1/articles' );

		test.skip(
			'licensio_kb_not_implemented' !== ( answer as { code?: string } ).code,
			'The article order routes are implemented.'
		);

		await expect( pane ).toContainText( 'Article ordering is not available yet.' );
	} );

	test( 'the article order pane saves a reordered list after confirming', async ( { page } ) => {
		const saved: unknown[] = [];

		// Answer the order contract here, so the pane is tested whatever state the routes are in.
		await page.route( /categories(%2F|\/)\d+(%2F|\/)articles/, async ( route ) => {
			const request = route.request();

			if ( 'PUT' === request.method() ) {
				saved.push( request.postDataJSON() );
				await route.fulfill( { json: request.postDataJSON() } );
				return;
			}

			await route.fulfill( {
				json: {
					items: [
						{ id: 11, title: 'First', status: 'publish', order: 10, edit_url: '' },
						{ id: 12, title: 'Second', status: 'draft', order: 20, edit_url: '' },
					],
					total: 2,
					pages: 1,
				},
			} );
		} );

		await node( page, 'Alpha' ).locator( '.lkb-tree__name' ).click();

		const pane = page.getByRole( 'region', { name: 'Article order' } );

		await pane.getByRole( 'button', { name: 'Move “Second” up' } ).click();
		await pane.getByRole( 'button', { name: 'Save order' } ).click();
		await cancelPopconfirm( page );
		expect( saved ).toHaveLength( 0 );

		await pane.getByRole( 'button', { name: 'Save order' } ).click();
		await confirmPopconfirm( page );
		await expect.poll( () => saved ).toEqual( [ { ids: [ 12, 11 ] } ] );
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
