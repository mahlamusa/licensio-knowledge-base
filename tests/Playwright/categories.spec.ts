/**
 * The Categories app, end to end.
 *
 * Drag and drop and keyboard moves saved at once, the Add New Category form
 * in the left column and Edit loading a category into it as Edit Category
 * (focused on Name, with Update and Cancel), the icon source switch
 * and its clearing of the other sources, Base64 and inline SVG icons on the
 * front end, the slug following the name, the merge dialog opening on its
 * first field, delete with reassignment, merge, the article order pane
 * against the live order routes (a drag saved and shown on the category
 * archive, and a saved first page of a long list), and the layout: form left
 * of the tree and article order on a desktop, above them on a phone. Update
 * keeps a move made while the form was open, unsaved form changes are only
 * dropped once confirmed, a deleted category leaves the form, and `?term=`
 * loads a category into it. Creating, saving and moving are not confirmed; deleting, merging
 * and discarding an unsaved order are, and a cancelled one sends nothing.
 *
 * The slug the app makes from a name is also compared with core's
 * `sanitize_title()` on the same site, which needs `LICENSIO_KB_WP_CLI`.
 *
 * The suite needs a running WordPress with this plugin active and an
 * administrator account, so it is skipped unless `LICENSIO_KB_E2E=1` and the
 * two credential variables are present.
 *
 * @since 1.0.0
 */

import { expect, test, type Page, type Request } from '@playwright/test';
import {
	answerConfirm,
	contentManagerUrl,
	expectNoConfirmDialog,
	openContentManager,
} from './helpers/content-manager';
import { failOnUnresolvedComponents } from './helpers/ant-components';
import { logIn, password, user } from './helpers/session';
import { wpCli, wpEval } from './helpers/wp-cli';
import { slugify } from '../../apps/categories/slug';

failOnUnresolvedComponents();

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
						{
							title,
							status: 'publish',
							licensio_categories: [ category ],
						},
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
	for (;;) {
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
				api(
					page,
					'DELETE',
					`licensio_articles/${ one.id }?force=true`,
					undefined,
					'wp/v2'
				)
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
		await page
			.locator( '.lkb-order__item .lkb-order__name > a' )
			.allTextContents()
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
					'^\\s*' +
						name.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' ) +
						'\\s*$'
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
 * The left form, Add New Category or Edit Category.
 *
 * @since 1.0.0
 *
 * @param {Page} page The browser page.
 *
 * @return {import('@playwright/test').Locator} The form card.
 */
function categoryForm( page: Page ) {
	return page.locator( '.lkb-category-form' );
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
async function act(
	page: Page,
	name: string,
	action: string
): Promise< void > {
	// A menu opened before has finished closing, so only this one is matched.
	await expect( page.locator( '.ant-dropdown:visible' ) ).toHaveCount( 0 );
	await page
		.getByRole( 'button', { name: `Actions for ${ name }`, exact: true } )
		.click();
	await page
		.locator(
			'.ant-dropdown:not(.ant-dropdown-hidden):visible .ant-dropdown-menu-item'
		)
		.filter( { hasText: action } )
		.click();
}

test.describe( 'Categories app', () => {
	test.describe.configure( { mode: 'serial' } );
	test.skip(
		! enabled || ! user || ! password,
		'Needs LICENSIO_KB_E2E=1 and admin credentials.'
	);

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

	test( 'a drop is saved at once and survives a reload', async ( {
		page,
	} ) => {
		const writes = recordWrites( page );

		await node( page, 'Alpha' ).dragTo( node( page, 'Gamma' ), {
			targetPosition: { x: 60, y: 22 },
		} );

		await expect( page.locator( '.ant-message' ) ).toContainText(
			'Category moved.'
		);
		expect( writes ).toHaveLength( 1 );
		await expectNoConfirmDialog( page );

		const after = await topLevel( page );

		expect( after ).not.toEqual( [ 'Alpha', 'Beta', 'Gamma' ] );

		await page.reload();
		await expect( node( page, 'Gamma child' ) ).toBeVisible();
		expect( await topLevel( page ) ).toEqual( after );
	} );

	test( 'a category cannot be dropped into its own child', async ( {
		page,
	} ) => {
		const writes = recordWrites( page );

		await node( page, 'Gamma' ).dragTo( node( page, 'Gamma child' ) );

		expect( writes ).toHaveLength( 0 );
		expect( await topLevel( page ) ).toEqual( [
			'Alpha',
			'Beta',
			'Gamma',
		] );
	} );

	test( 'move down from the keyboard menu moves at once', async ( {
		page,
	} ) => {
		const writes = recordWrites( page );

		await act( page, 'Alpha', 'Move down' );
		await expect
			.poll( () => topLevel( page ) )
			.toEqual( [ 'Beta', 'Alpha', 'Gamma' ] );
		expect( writes ).toHaveLength( 1 );
	} );

	test( 'the actions menu works from the keyboard alone', async ( {
		page,
	} ) => {
		const writes = recordWrites( page );
		const trigger = page.getByRole( 'button', {
			name: 'Actions for Alpha',
			exact: true,
		} );
		const edit = page
			.getByRole( 'menuitem', { name: 'Edit' } )
			.locator( 'visible=true' );

		// Escape closes the menu and puts focus back on its button.
		await trigger.focus();
		await page.keyboard.press( 'Enter' );
		await expect( edit ).toBeFocused();
		await page.keyboard.press( 'Escape' );
		await expect( trigger ).toBeFocused();
		await expect( trigger ).toHaveAttribute( 'aria-expanded', 'false' );
		expect( writes ).toHaveLength( 0 );

		// Edit, Add child, then Move down (Move up is disabled for the first category).
		await page.keyboard.press( 'Enter' );
		await expect( edit ).toBeFocused();
		await page.keyboard.press( 'ArrowDown' );
		await page.keyboard.press( 'ArrowDown' );
		await expect(
			page
				.getByRole( 'menuitem', { name: 'Move down' } )
				.locator( 'visible=true' )
		).toBeFocused();
		await page.keyboard.press( 'Enter' );

		// The move is saved at once and focus goes back to the moved category's button.
		await expect
			.poll( () => topLevel( page ) )
			.toEqual( [ 'Beta', 'Alpha', 'Gamma' ] );
		await expect( trigger ).toBeFocused();
		expect( writes ).toHaveLength( 1 );
	} );

	test( 'the icon source switch shows only that source and saving clears the others', async ( {
		page,
	} ) => {
		const alpha = await categoryId( page, 'Alpha' );

		// Two sources set, as an import could leave them: the inline SVG wins.
		await api( page, 'PATCH', `categories/${ alpha }`, {
			icon: 'dashicons-book-alt',
			icon_svg: '<svg viewBox="0 0 1 1"><path d="M0 0h1v1z"/></svg>',
		} );
		await page.reload();
		await act( page, 'Alpha', 'Edit' );

		const form = categoryForm( page );
		const inline = form.getByLabel( 'SVG or Base64 image' );
		const dashicon = form.getByLabel( 'Dashicon name' );
		const upload = form.getByRole( 'button', {
			name: 'Icon file: Select',
		} );

		// It opens on the source the category shows.
		await expect(
			form.getByRole( 'radio', { name: 'Inline' } )
		).toBeChecked();
		await expect( inline ).toHaveValue( /<path/ );
		await expect( dashicon ).toHaveCount( 0 );
		await expect( upload ).toHaveCount( 0 );

		await form.getByText( 'Dashicon', { exact: true } ).click();
		await expect( dashicon ).toBeVisible();
		await expect( dashicon ).toHaveValue( 'dashicons-book-alt' );
		await expect( inline ).toHaveCount( 0 );
		await expect( upload ).toHaveCount( 0 );

		await form.getByText( 'Upload', { exact: true } ).click();
		await expect( upload ).toBeVisible();
		await expect( inline ).toHaveCount( 0 );
		await expect( dashicon ).toHaveCount( 0 );

		await form.getByText( 'Dashicon', { exact: true } ).click();
		await dashicon.fill( 'dashicons-lock' );
		await form.getByRole( 'button', { name: 'Update' } ).click();
		await expect( page.locator( '.ant-message' ) ).toContainText(
			'Category saved.'
		);

		const items = ( await api( page, 'GET', 'categories' ) ) as Array<
			Category & {
				icon: string;
				icon_id: number;
				icon_svg: string;
				icon_data: string;
			}
		>;
		const saved = items.find( ( item ) => alpha === item.id );

		expect( saved?.icon ).toBe( 'dashicons-lock' );
		expect( saved?.icon_svg ).toBe( '' );
		expect( saved?.icon_data ).toBe( '' );
		expect( saved?.icon_id ).toBe( 0 );
	} );

	test( 'a Base64 image and an inline SVG icon render on the front end', async ( {
		page,
	} ) => {
		const png =
			'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

		for ( const [ name, value ] of [
			[ 'Alpha', png ],
			[
				'Beta',
				'<svg viewBox="0 0 1 1"><script>alert(1)</script><path class="e2e-icon" d="M0 0h1v1z"/></svg>',
			],
		] ) {
			// A fresh page each time, so only this category's menu is in the DOM.
			await page.reload();
			await act( page, name, 'Edit' );

			const form = categoryForm( page );

			await form.getByText( 'Inline', { exact: true } ).click();
			await form.getByLabel( 'SVG or Base64 image' ).fill( value );
			await expect(
				form.locator( '.lkb-icon-field__preview' )
			).toBeVisible();
			await form.getByRole( 'button', { name: 'Update' } ).click();
			await expect(
				form.getByRole( 'heading', { name: 'Add New Category' } )
			).toBeVisible();
		}

		const items = ( await api( page, 'GET', 'categories' ) ) as Array<
			Category & { icon_svg: string; icon_data: string }
		>;

		expect(
			items.find( ( item ) => 'Alpha' === item.name )?.icon_data
		).toBe( png );
		expect(
			items.find( ( item ) => 'Beta' === item.name )?.icon_svg
		).not.toContain( 'script' );

		// The grid hides empty categories, so each gets an article.
		await makeArticles( page, await categoryId( page, 'Alpha' ), [
			'E2E order icon A',
		] );
		await makeArticles( page, await categoryId( page, 'Beta' ), [
			'E2E order icon B',
		] );

		const created = ( await api(
			page,
			'POST',
			'pages',
			{
				title: 'E2E category icons',
				status: 'publish',
				content:
					'<!-- wp:licensio-kb/category-grid {"showIcon":true} /-->',
			},
			'wp/v2'
		) ) as { id: number; link: string };

		try {
			await page.goto( created.link );

			const card = ( name: string ) =>
				page
					.locator( '.licensio-kb-card--category' )
					.filter( { hasText: name } );

			await expect(
				card( 'Alpha' ).locator( 'img.licensio-kb-card__icon-image' )
			).toHaveAttribute( 'src', png );
			expect(
				await card( 'Alpha' )
					.locator( 'img.licensio-kb-card__icon-image' )
					.evaluate( ( img: HTMLImageElement ) => img.naturalWidth )
			).toBe( 1 );
			await expect(
				card( 'Beta' ).locator(
					'.licensio-kb-card__icon--svg svg path'
				)
			).toHaveCount( 1 );
			await expect( card( 'Beta' ).locator( 'script' ) ).toHaveCount( 0 );
		} finally {
			await page.goBack();
			await api(
				page,
				'DELETE',
				`pages/${ created.id }?force=true`,
				undefined,
				'wp/v2'
			);
		}
	} );

	test( 'a new slug follows the name on type and paste until it is edited', async ( {
		page,
	} ) => {
		const form = categoryForm( page );
		const name = form.getByLabel( 'Name' );
		const slug = form.getByLabel( 'Slug' );

		await name.click();
		await page.keyboard.type( 'Café & Crème' );
		await expect( slug ).toHaveValue( 'cafe-creme' );

		await name.fill( '' );
		await name.focus();
		await page.keyboard.insertText( 'Getting Started: “Quick” Guide' );
		await expect( slug ).toHaveValue( 'getting-started-quick-guide' );

		await form.getByRole( 'button', { name: 'Add New Category' } ).click();
		await expect(
			node( page, 'Getting Started: “Quick” Guide' )
		).toBeVisible();

		const items = ( await api( page, 'GET', 'categories' ) ) as Array<
			Category & { slug: string }
		>;

		// The preview matched what the server stored.
		expect(
			items.find(
				( item ) => 'Getting Started: “Quick” Guide' === item.name
			)?.slug
		).toBe( 'getting-started-quick-guide' );

		// The form is cleared for the next category, with focus on Name.
		await expect( name ).toHaveValue( '' );
		await expect( name ).toBeFocused();

		// Once the slug is edited by hand, it no longer follows the name.
		await name.fill( 'Billing' );
		await expect( slug ).toHaveValue( 'billing' );
		await slug.fill( 'payments' );
		await name.fill( 'Billing and invoices' );
		await expect( slug ).toHaveValue( 'payments' );
	} );

	test( 'renaming a category keeps its slug and Generate from name makes one', async ( {
		page,
	} ) => {
		const writes = recordWrites( page );

		await act( page, 'Beta', 'Edit' );

		const form = categoryForm( page );
		const slug = form.getByLabel( 'Slug' );

		await expect( slug ).toHaveValue( 'beta' );
		await form.getByLabel( 'Name' ).fill( 'Beta renamed' );
		await expect( slug ).toHaveValue( 'beta' );

		await form
			.getByRole( 'button', { name: 'Generate from name' } )
			.click();
		await expect( slug ).toHaveValue( 'beta-renamed' );
		expect( writes ).toHaveLength( 0 );

		await form.getByRole( 'button', { name: 'Update' } ).click();
		await expect( node( page, 'Beta renamed' ) ).toBeVisible();

		const items = ( await api( page, 'GET', 'categories' ) ) as Array<
			Category & { slug: string }
		>;

		expect(
			items.find( ( item ) => 'Beta renamed' === item.name )?.slug
		).toBe( 'beta-renamed' );
	} );

	test( 'Edit loads a category into the left form and Cancel returns it to Add', async ( {
		page,
	} ) => {
		const writes = recordWrites( page );
		const form = categoryForm( page );
		const trigger = page.getByRole( 'button', {
			name: 'Actions for Gamma child',
			exact: true,
		} );

		await expect(
			form.getByRole( 'heading', { name: 'Add New Category' } )
		).toBeVisible();
		await expect(
			form.getByRole( 'button', { name: 'Cancel' } )
		).toHaveCount( 0 );

		await act( page, 'Gamma child', 'Edit' );

		await expect(
			form.getByRole( 'heading', { name: 'Edit Category' } )
		).toBeVisible();
		await expect( form.getByLabel( 'Name' ) ).toHaveValue( 'Gamma child' );
		await expect( form.getByLabel( 'Name' ) ).toBeFocused();
		await expect( form.getByLabel( 'Parent category' ) ).toBeVisible();
		await expect( form.locator( '.ant-select-selection-item' ) ).toHaveText(
			'Gamma'
		);
		await expect(
			form.getByRole( 'button', { name: 'Update' } )
		).toBeVisible();

		await form.getByRole( 'button', { name: 'Cancel' } ).click();
		await expect(
			form.getByRole( 'heading', { name: 'Add New Category' } )
		).toBeVisible();
		await expect( form.getByLabel( 'Name' ) ).toHaveValue( '' );
		await expect( trigger ).toBeFocused();
		expect( writes ).toHaveLength( 0 );

		// Add child starts a new category under the chosen parent.
		// A fresh page, so only this category's menu is in the DOM.
		await page.reload();
		await act( page, 'Beta', 'Add child' );
		await expect(
			form.getByRole( 'heading', { name: 'Add New Category' } )
		).toBeVisible();
		await expect( form.getByLabel( 'Name' ) ).toBeFocused();
		await expect( form.locator( '.ant-select-selection-item' ) ).toHaveText(
			'Beta'
		);
		await page.keyboard.type( 'Beta child' );
		await page.keyboard.press( 'Enter' );
		await expect( node( page, 'Beta child' ) ).toBeVisible();

		const items = ( await api( page, 'GET', 'categories' ) ) as Category[];

		expect(
			items.find( ( item ) => 'Beta child' === item.name )?.parent
		).toBe( items.find( ( item ) => 'Beta' === item.name )?.id );
	} );

	test( 'Update keeps a move made in the tree while the form was open', async ( {
		page,
	} ) => {
		const writes = recordWrites( page );
		const form = categoryForm( page );
		const parent = form.locator( '.ant-select-selection-item' );

		await act( page, 'Gamma child', 'Edit' );
		await expect( parent ).toHaveText( 'Gamma' );

		// Moved to the top level from the tree while the form is open.
		await act( page, 'Gamma child', 'Move to…' );

		const dialog = page.locator( '.ant-modal-content' );

		await dialog.getByText( 'Top level' ).click();
		await dialog
			.getByRole( 'button', { name: 'Move', exact: true } )
			.click();
		await expect( page.locator( '.ant-message' ) ).toContainText(
			'Category moved.'
		);

		// The form follows the move, and Update does not undo it.
		await expect( parent ).toHaveCount( 0 );
		await form.getByLabel( 'Description' ).fill( 'Edited after the move' );
		await form.getByRole( 'button', { name: 'Update' } ).click();
		await expect(
			form.getByRole( 'heading', { name: 'Add New Category' } )
		).toBeVisible();

		const update = writes.find(
			( request ) => 'PATCH' === request.method()
		);

		expect( update?.postDataJSON() ).not.toHaveProperty( 'parent' );

		const items = ( await api( page, 'GET', 'categories' ) ) as Category[];

		expect(
			items.find( ( item ) => 'Gamma child' === item.name )?.parent
		).toBe( 0 );
	} );

	test( 'the form returns to Add when the category in it is deleted', async ( {
		page,
	} ) => {
		const form = categoryForm( page );

		await act( page, 'Alpha', 'Edit' );
		await expect(
			form.getByRole( 'heading', { name: 'Edit Category' } )
		).toBeVisible();

		await act( page, 'Alpha', 'Delete…' );
		await page
			.locator( '.ant-modal-content' )
			.getByRole( 'button', { name: 'Delete' } )
			.click();
		await answerConfirm( page, 'confirm' );

		await expect( node( page, 'Alpha' ) ).toHaveCount( 0 );
		await expect(
			form.getByRole( 'heading', { name: 'Add New Category' } )
		).toBeVisible();
		await expect( form.getByLabel( 'Name' ) ).toHaveValue( '' );
	} );

	test( 'loading another category or cancelling asks before it drops unsaved changes', async ( {
		page,
	} ) => {
		const writes = recordWrites( page );
		const form = categoryForm( page );
		const heading = ( name: string ) =>
			form.getByRole( 'heading', { name } );

		// Nothing changed: Edit on another category loads it without asking.
		await act( page, 'Alpha', 'Edit' );
		await act( page, 'Beta', 'Edit' );
		await expectNoConfirmDialog( page );
		await expect( form.getByLabel( 'Name' ) ).toHaveValue( 'Beta' );

		// The category in the form is marked in the tree.
		await expect(
			node( page, 'Beta' ).locator( '[aria-current="true"]' )
		).toHaveCount( 1 );
		await expect(
			node( page, 'Alpha' ).locator( '[aria-current="true"]' )
		).toHaveCount( 0 );

		// A change: Edit on another category asks, and Cancel keeps the input.
		await form.getByLabel( 'Name' ).fill( 'Beta changed' );
		await act( page, 'Gamma', 'Edit' );
		await answerConfirm( page, 'cancel' );
		await expect( heading( 'Edit Category' ) ).toBeVisible();
		await expect( form.getByLabel( 'Name' ) ).toHaveValue( 'Beta changed' );

		// The form's own Cancel asks too.
		await form.getByRole( 'button', { name: 'Cancel' } ).click();
		await answerConfirm( page, 'cancel' );
		await expect( form.getByLabel( 'Name' ) ).toHaveValue( 'Beta changed' );

		// Discarding loads the other category.
		await act( page, 'Gamma', 'Add child' );
		await answerConfirm( page, 'confirm' );
		await expect( heading( 'Add New Category' ) ).toBeVisible();
		await expect( form.locator( '.ant-select-selection-item' ) ).toHaveText(
			'Gamma'
		);

		// A typed new category is kept the same way.
		await form.getByLabel( 'Name' ).fill( 'Draft' );
		await act( page, 'Alpha', 'Edit' );
		await answerConfirm( page, 'cancel' );
		await expect( form.getByLabel( 'Name' ) ).toHaveValue( 'Draft' );
		await act( page, 'Alpha', 'Edit' );
		await answerConfirm( page, 'confirm' );
		await expect( heading( 'Edit Category' ) ).toBeVisible();
		await expect( form.getByLabel( 'Name' ) ).toHaveValue( 'Alpha' );

		expect( writes ).toHaveLength( 0 );
	} );

	test( 'Update returns focus to the category that was edited', async ( {
		page,
	} ) => {
		const form = categoryForm( page );

		await act( page, 'Alpha', 'Edit' );

		// A dialog opened and cancelled on another category in between.
		await act( page, 'Beta', 'Delete…' );

		const dialog = page.locator( '.ant-modal-content' );

		await dialog.getByRole( 'button', { name: 'Cancel' } ).click();
		await expect( dialog ).toBeHidden();

		await form.getByRole( 'button', { name: 'Update' } ).click();
		await expect(
			form.getByRole( 'heading', { name: 'Add New Category' } )
		).toBeVisible();
		await expect(
			page.getByRole( 'button', {
				name: 'Actions for Alpha',
				exact: true,
			} )
		).toBeFocused();
	} );

	test( 'a page opened for one category loads it into the form', async ( {
		page,
	} ) => {
		const id = await categoryId( page, 'Beta' );
		const form = categoryForm( page );

		await page.goto(
			`${ contentManagerUrl( 'categories' ) }&term=${ id }`
		);

		await expect(
			form.getByRole( 'heading', { name: 'Edit Category' } )
		).toBeVisible();
		await expect( form.getByLabel( 'Name' ) ).toHaveValue( 'Beta' );

		// The tree has a heading for screen readers.
		await expect(
			page.getByRole( 'heading', { name: 'Category tree' } )
		).toBeAttached();
	} );

	test( 'the name hint shows only once the Name field was left empty', async ( {
		page,
	} ) => {
		const form = categoryForm( page );
		const hint = form.getByText( 'Enter a name to save.' );

		await expect( hint ).toHaveCount( 0 );
		await form.getByLabel( 'Name' ).focus();
		await page.keyboard.press( 'Tab' );
		await expect( hint ).toBeVisible();
		await form.getByLabel( 'Name' ).fill( 'Named' );
		await expect( hint ).toHaveCount( 0 );
	} );

	test( 'Escape on an open select closes only the select, not the edit', async ( {
		page,
	} ) => {
		await act( page, 'Beta', 'Edit' );

		const form = categoryForm( page );
		const list = page.locator(
			'.ant-select-dropdown:not(.ant-select-dropdown-hidden)'
		);

		await form.getByLabel( 'Name' ).fill( 'Kept name' );
		await form.getByLabel( 'Parent category' ).click();
		await expect( list ).toBeVisible();
		await page.keyboard.press( 'Escape' );
		await expect( list ).toBeHidden();
		await expect(
			form.getByRole( 'heading', { name: 'Edit Category' } )
		).toBeVisible();
		await expect( form.getByLabel( 'Name' ) ).toHaveValue( 'Kept name' );

		// A second Escape leaves the edit once the change is discarded,
		// sending nothing.
		await page.keyboard.press( 'Escape' );
		await answerConfirm( page, 'confirm' );
		await expect(
			form.getByRole( 'heading', { name: 'Add New Category' } )
		).toBeVisible();
		await expect( node( page, 'Beta' ) ).toBeVisible();
	} );

	test( 'a slug another category uses is shown once, on the Slug field', async ( {
		page,
	} ) => {
		await act( page, 'Beta', 'Edit' );

		const form = categoryForm( page );
		const message =
			'Another category already uses the slug “alpha”. Choose a different slug.';

		await form.getByLabel( 'Slug' ).fill( 'alpha' );
		await form.getByRole( 'button', { name: 'Update' } ).click();

		await expect(
			form.locator( '.ant-form-item-explain-error' )
		).toHaveText( message );
		await expect( form.getByText( message ) ).toHaveCount( 1 );
		await expect( form.locator( '.lkb-category-form__error' ) ).toHaveCount(
			0
		);
	} );

	test( 'Escape on an open select closes only the select, not the merge dialog', async ( {
		page,
	} ) => {
		await act( page, 'Gamma', 'Merge…' );

		const dialog = page.locator( '.ant-modal-content' );
		const list = page.locator( '.ant-select-dropdown:visible' );

		for ( const label of [ 'Categories to merge', 'Merge into' ] ) {
			await dialog.getByLabel( label ).click();
			await expect( list ).toBeVisible();
			await page.keyboard.press( 'Escape' );
			await expect( list ).toHaveCount( 0 );
			await expect(
				dialog,
				`Escape on ${ label } leaves the dialog open.`
			).toBeVisible();
		}

		// A second Escape closes the dialog.
		await page.keyboard.press( 'Escape' );
		await expect( dialog ).toBeHidden();
	} );

	test( 'delete moves the children to the chosen category', async ( {
		page,
	} ) => {
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
		await answerConfirm( page, 'confirm' );

		await expect( node( page, 'Gamma' ) ).toHaveCount( 0 );

		const items = ( await api( page, 'GET', 'categories' ) ) as Category[];
		const beta = items.find( ( item ) => 'Beta' === item.name );
		const child = items.find( ( item ) => 'Gamma child' === item.name );

		expect( child?.parent ).toBe( beta?.id );
	} );

	test( 'merge re-parents children and deletes the source', async ( {
		page,
	} ) => {
		await act( page, 'Gamma', 'Merge…' );

		const dialog = page.locator( '.ant-modal-content' );

		// The dialog opens with focus on its first field.
		await expect(
			dialog.getByLabel( 'Categories to merge' )
		).toBeFocused();
		await dialog.getByLabel( 'Merge into' ).click();
		await page
			.locator( '.ant-select-item-option' )
			.filter( { hasText: 'Alpha' } )
			.click();

		await dialog.getByRole( 'button', { name: 'Merge' } ).click();
		await answerConfirm( page, 'confirm' );

		await expect( node( page, 'Gamma' ) ).toHaveCount( 0 );

		const items = ( await api( page, 'GET', 'categories' ) ) as Category[];
		const alpha = items.find( ( item ) => 'Alpha' === item.name );

		expect(
			items.find( ( item ) => 'Gamma child' === item.name )?.parent
		).toBe( alpha?.id );
	} );

	test( 'Escape on an expanded control that is not a select closes the dialog', async ( {
		page,
	} ) => {
		await act( page, 'Gamma', 'Delete…' );

		const dialog = page.locator( '.ant-modal-content' );

		await expect( dialog ).toBeVisible();

		// Stands in for an expanded tree node or collapse header in a dialog.
		await dialog.evaluate( ( root ) => {
			const toggle = document.createElement( 'button' );

			toggle.type = 'button';
			toggle.textContent = 'Expanded section';
			toggle.setAttribute( 'aria-expanded', 'true' );
			root.appendChild( toggle );
			toggle.focus();
		} );

		await page.keyboard.press( 'Escape' );
		await expect( dialog ).toBeHidden();
	} );

	test( 'an unsaved article order is kept until the user agrees to drop it', async ( {
		page,
	} ) => {
		const alpha = await categoryId( page, 'Alpha' );

		await makeArticles( page, alpha, [
			'E2E order guard A',
			'E2E order guard B',
		] );
		await page.reload();
		await node( page, 'Alpha' ).locator( '.lkb-tree__name' ).click();

		const pane = page.getByRole( 'region', { name: 'Article order' } );
		const moved = [ 'E2E order guard B', 'E2E order guard A' ];

		await expect
			.poll( () => paneTitles( page ) )
			.toEqual( [ 'E2E order guard A', 'E2E order guard B' ] );
		await pane
			.getByRole( 'button', { name: 'Move “E2E order guard B” up' } )
			.click();
		expect( await paneTitles( page ) ).toEqual( moved );

		const asked: string[] = [];

		page.on( 'dialog', async ( dialog ) => {
			asked.push( `${ dialog.type() }: ${ dialog.message() }` );
			await dialog.dismiss();
		} );

		const question =
			'confirm: The article order has unsaved changes. Discard them?';

		// Another category, the selected one again, and a merge each ask first.
		await node( page, 'Beta' ).locator( '.lkb-tree__name' ).click();
		await expect.poll( () => asked ).toEqual( [ question ] );
		await node( page, 'Alpha' ).locator( '.lkb-tree__name' ).click();
		await expect.poll( () => asked ).toHaveLength( 2 );
		await act( page, 'Beta', 'Merge…' );
		await expect
			.poll( () => asked )
			.toEqual( [ question, question, question ] );
		await expect( page.locator( '.ant-modal-content' ) ).toHaveCount( 0 );

		await expect( pane ).toContainText( 'Articles in “Alpha”' );
		expect( await paneTitles( page ) ).toEqual( moved );

		// Leaving the page brings up the browser's leave warning.
		await node( page, 'Beta' ).locator( '.lkb-tree__count' ).click();
		await expect.poll( () => asked[ 3 ] ?? '' ).toMatch( /^beforeunload/ );
		await expect( page ).toHaveURL( /page=licensio-kb-categories/ );
		expect( await paneTitles( page ) ).toEqual( moved );

		// Agreeing drops the order and selects the other category.
		page.removeAllListeners( 'dialog' );
		page.once( 'dialog', ( dialog ) => dialog.accept() );
		await node( page, 'Beta' ).locator( '.lkb-tree__name' ).click();
		await expect( pane ).toContainText( 'Articles in “Beta”' );
	} );

	test( 'a category count links to its articles and the pane names statuses', async ( {
		page,
	} ) => {
		const alpha = await categoryId( page, 'Alpha' );

		await api(
			page,
			'POST',
			'licensio_articles',
			{
				title: 'E2E order draft',
				status: 'draft',
				licensio_categories: [ alpha ],
			},
			'wp/v2'
		);
		await page.reload();
		await node( page, 'Alpha' ).locator( '.lkb-tree__name' ).click();

		const pane = page.getByRole( 'region', { name: 'Article order' } );

		await expect( pane.locator( '.lkb-order__status' ) ).toHaveText(
			'Draft'
		);

		const count = node( page, 'Alpha' ).locator( '.lkb-tree__count' );

		await expect( count ).toHaveAttribute(
			'href',
			new RegExp( `page=licensio-kb-articles&category=${ alpha }$` )
		);
		await count.click();
		await expect( page ).toHaveURL(
			new RegExp( `page=licensio-kb-articles.*category=${ alpha }` )
		);
		await expect(
			page.locator( '#licensio-kb-articles .ant-table-row', {
				hasText: 'E2E order draft',
			} )
		).toBeVisible();
	} );

	test( 'a dragged article order is saved, survives a reload and orders the archive', async ( {
		page,
	} ) => {
		const alpha = await categoryId( page, 'Alpha' );
		const [ a, b, c, d ] = await makeArticles( page, alpha, [
			'E2E order A',
			'E2E order B',
			'E2E order C',
			'E2E order D',
		] );

		// C and A have positions; B and D have none, so they follow by title.
		await api( page, 'PUT', `categories/${ alpha }/articles/order`, {
			ids: [ c, a ],
		} );

		await page.reload();
		await node( page, 'Alpha' ).locator( '.lkb-tree__name' ).click();

		const pane = page.getByRole( 'region', { name: 'Article order' } );
		const items = pane.locator( '.lkb-order__item' );

		await expect
			.poll( () => paneTitles( page ) )
			.toEqual( [
				'E2E order C',
				'E2E order A',
				'E2E order B',
				'E2E order D',
			] );

		await items.nth( 3 ).dragTo( items.nth( 0 ) );

		const expected = [
			'E2E order D',
			'E2E order C',
			'E2E order A',
			'E2E order B',
		];

		expect( await paneTitles( page ) ).toEqual( expected );

		const writes = recordWrites( page );

		// Reset asks first, since it throws the new order away; Cancel keeps it.
		await pane.getByRole( 'button', { name: 'Reset' } ).click();
		await answerConfirm( page, 'cancel' );
		expect( await paneTitles( page ) ).toEqual( expected );

		// Saving is not confirmed, and focus moves to the pane heading.
		await pane.getByRole( 'button', { name: 'Save order' } ).click();
		await expect( page.locator( '.ant-message' ) ).toContainText(
			'Article order saved.'
		);
		await expect( pane.locator( '.lkb-order__title' ) ).toBeFocused();
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
				.locator(
					'.licensio-kb-card--article .licensio-kb-card__title'
				)
				.allTextContents()
		).map( ( title ) => title.trim() );

		expect( archive ).toEqual( expected );
	} );

	test( 'an article order the server could not save shows the error', async ( {
		page,
	} ) => {
		const alpha = await categoryId( page, 'Alpha' );

		await makeArticles( page, alpha, [ 'E2E order X', 'E2E order Y' ] );

		await page.reload();
		await node( page, 'Alpha' ).locator( '.lkb-tree__name' ).click();

		const pane = page.getByRole( 'region', { name: 'Article order' } );

		await expect
			.poll( () => paneTitles( page ) )
			.toEqual( [ 'E2E order X', 'E2E order Y' ] );

		// The server rolled the write back, as on a deadlock between two saves.
		await page.route( /articles(%2F|\/)order/, ( route ) =>
			route.fulfill( {
				status: 500,
				contentType: 'application/json',
				body: JSON.stringify( {
					code: 'licensio_kb_order_not_saved',
					message: 'The order could not be saved. Try again.',
					data: { status: 500 },
				} ),
			} )
		);

		await pane
			.getByRole( 'button', { name: 'Move “E2E order Y” up' } )
			.click();
		await pane.getByRole( 'button', { name: 'Save order' } ).click();

		await expect( pane.locator( '.lkb-order__error' ) ).toContainText(
			'The order could not be saved. Try again.'
		);
		await expect( page.getByText( 'Article order saved.' ) ).toHaveCount(
			0
		);
	} );

	test( 'saving the first page of a long list renumbers the rest after it', async ( {
		page,
	} ) => {
		test.setTimeout( 240_000 );

		const alpha = await categoryId( page, 'Alpha' );
		const titles = Array.from(
			{ length: 207 },
			( _, index ) =>
				`E2E order ${ String( index + 1 ).padStart( 3, '0' ) }`
		);
		const ids = await makeArticles( page, alpha, titles );

		// The first 205 get positions, last made first; the last two have none.
		const positioned = ids.slice( 0, 205 ).reverse();

		await api( page, 'PUT', `categories/${ alpha }/articles/order`, {
			ids: positioned,
		} );

		await page.reload();
		await node( page, 'Alpha' ).locator( '.lkb-tree__name' ).click();

		const pane = page.getByRole( 'region', { name: 'Article order' } );

		await expect( pane ).toContainText(
			'Only the first 200 of 207 articles are listed.'
		);
		await expect
			.poll( () => paneTitles( page ).then( ( list ) => list.length ) )
			.toBe( 200 );

		await pane
			.getByRole( 'button', { name: 'Move “E2E order 204” up' } )
			.click();
		await pane.getByRole( 'button', { name: 'Save order' } ).click();
		await expect( page.locator( '.ant-message' ) ).toContainText(
			'Article order saved.'
		);

		const firstPage = [
			positioned[ 1 ],
			positioned[ 0 ],
			...positioned.slice( 2, 200 ),
		];
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

	test( 'tree rows are sized like a core list table row', async ( {
		page,
	} ) => {
		const alpha = await categoryId( page, 'Alpha' );

		await makeArticles( page, alpha, [ 'E2E order size' ] );
		await page.reload();
		await node( page, 'Alpha' ).locator( '.lkb-tree__name' ).click();

		const name = node( page, 'Alpha' ).locator( '.lkb-tree__name' );
		const item = page.locator( '.lkb-order__item' ).first();

		await expect( name ).toHaveCSS( 'font-size', '14px' );
		await expect( name ).toHaveCSS( 'font-weight', '600' );
		await expect( item.locator( '.lkb-order__name' ) ).toHaveCSS(
			'font-size',
			'14px'
		);

		for ( const width of [ 1280, 390 ] ) {
			await page.setViewportSize( { width, height: 900 } );

			const row =
				( await node( page, 'Alpha' ).boundingBox() )?.height ?? 0;
			const child = node( page, 'Gamma child' ).locator(
				'.lkb-tree__name'
			);
			const parent = node( page, 'Gamma' ).locator( '.lkb-tree__name' );
			const indent =
				( ( await child.boundingBox() )?.x ?? 0 ) -
				( ( await parent.boundingBox() )?.x ?? 0 );

			expect( row, `row height at ${ width }px` ).toBeGreaterThanOrEqual(
				37
			);
			expect( row, `row height at ${ width }px` ).toBeLessThanOrEqual(
				56
			);
			expect(
				indent,
				`child indent at ${ width }px`
			).toBeGreaterThanOrEqual( 20 );
			expect(
				( await item.boundingBox() )?.height ?? 0,
				`order row height at ${ width }px`
			).toBeGreaterThanOrEqual( 37 );
		}

		await node( page, 'Beta' ).hover();
		await expect( node( page, 'Beta' ) ).not.toHaveCSS(
			'background-color',
			'rgba(0, 0, 0, 0)'
		);
	} );

	test( 'the form sits left of the tree on a desktop and above it on a phone', async ( {
		page,
	} ) => {
		const form = categoryForm( page );
		const list = page.locator( '.lkb-categories' );

		await page.setViewportSize( { width: 1440, height: 900 } );

		const left = await form.boundingBox();
		const right = await list.boundingBox();

		expect( left && right ).toBeTruthy();

		if ( ! left || ! right ) {
			return;
		}

		// Side by side, top aligned, about a third and two thirds as in core.
		expect( Math.abs( left.y - right.y ) ).toBeLessThanOrEqual( 1 );
		expect( left.x + left.width ).toBeLessThanOrEqual( right.x );
		expect( left.width / ( left.width + right.width ) ).toBeGreaterThan(
			0.3
		);
		expect( left.width / ( left.width + right.width ) ).toBeLessThan( 0.4 );

		// The article order pane is under the tree, in the right column.
		const tree = page.getByRole( 'region', { name: 'Category tree' } );
		const order = page.getByRole( 'region', { name: 'Article order' } );
		const treeBox = await tree.boundingBox();
		const orderBox = await order.boundingBox();

		expect( orderBox?.y ?? 0 ).toBeGreaterThan(
			( treeBox?.y ?? 0 ) + ( treeBox?.height ?? 0 ) - 1
		);
		expect( orderBox?.x ?? 0 ).toBeGreaterThanOrEqual( right.x );

		await page.setViewportSize( { width: 390, height: 844 } );

		const top = await form.boundingBox();
		const below = await list.boundingBox();

		expect( below?.y ?? 0 ).toBeGreaterThan(
			( top?.y ?? 0 ) + ( top?.height ?? 0 ) - 1
		);

		const overflow = await page.evaluate(
			() => document.documentElement.scrollWidth - window.innerWidth
		);

		expect( overflow ).toBeLessThanOrEqual( 0 );
	} );
} );

test.describe( 'Category slug from a name', () => {
	test.skip(
		! enabled || ! wpCli,
		'Needs LICENSIO_KB_E2E=1 and LICENSIO_KB_WP_CLI.'
	);

	test( 'matches what core sanitize_title makes on the server', () => {
		const names = [
			'Café crème',
			'Getting started: quick guide',
			'Hello & World / 2?',
			'“Smart” quotes — and dashes…',
			'<b>Bold</b> text',
			'Настройки',
			'Ёлка',
			'Ελληνικά άλφα',
			'Straße',
			'Ĳsselmeer',
			'1ª 2º',
			'Tiếng Việt',
			'Đồ ăn',
			'Șțș ǎ ǜ',
			'Ə ǝ ɑ € £',
			'Ǹ ẃ ḱ',
			'x\u0301y',
			'3 × 4',
			'日本語 😀',
			'ü'.repeat( 120 ),
		];
		const core = JSON.parse(
			wpEval(
				`echo wp_json_encode( array_map( 'sanitize_title', json_decode( base64_decode( '${ Buffer.from(
					JSON.stringify( names )
				).toString( 'base64' ) }' ), true ) ) );`
			)
		) as string[];

		expect( names.map( slugify ) ).toEqual( core );
	} );
} );
