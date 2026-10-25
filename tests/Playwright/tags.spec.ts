/**
 * The Tags app, end to end.
 *
 * Adds tags through the app, then checks that every change (add, save,
 * merge, convert and delete) waits for its Popconfirm and that Cancel sends
 * nothing, that an edit to a slug another tag uses is refused with a message
 * and keeps the input, and that merge and convert to
 * category work on the selected tags, and that focus lands on the page title
 * once a bulk delete takes the Delete button away. Escape closes the Add tag
 * form and an inline edit, sending nothing. The category the convert
 * creates is deleted again through the core REST API.
 *
 * The suite needs a running WordPress with this plugin active and an
 * administrator account, so it is skipped unless `LICENSIO_KB_E2E=1` and the
 * two credential variables are present.
 *
 * @since 1.0.0
 */

import { expect, test, type Page } from '@playwright/test';
import {
	cancelPopconfirm,
	confirmPopconfirm,
	openContentManager,
} from './helpers/content-manager';
import { logIn, password, user } from './helpers/session';

const enabled = process.env.LICENSIO_KB_E2E === '1';

test.skip(
	! enabled || ! user || ! password,
	'Set LICENSIO_KB_E2E=1 and the admin credentials to run.'
);

const suffix = Date.now().toString( 36 );

/**
 * Record every write the app sends to the plugin's REST namespace.
 *
 * @since 1.0.0
 *
 * @param {Page} page The browser page.
 *
 * @return {string[]} The live list of `METHOD url` entries.
 */
function recordWrites( page: Page ): string[] {
	const writes: string[] = [];

	page.on( 'request', ( request ) => {
		if (
			request.url().includes( 'licensio-kb' ) &&
			'GET' !== request.method()
		) {
			writes.push( request.method() + ' ' + request.url() );
		}
	} );

	return writes;
}

/**
 * Open a Popconfirm, cancel it, and check no write was sent.
 *
 * @since 1.0.0
 *
 * @param {Page}     page   The browser page.
 * @param {string[]} writes The writes recorded so far.
 * @param {Function} open   Clicks the button that opens the Popconfirm.
 *
 * @return {Promise<void>} Resolves once the Popconfirm is cancelled.
 */
async function cancelSendsNothing(
	page: Page,
	writes: string[],
	open: () => Promise< void >
): Promise< void > {
	const before = writes.length;

	await open();
	await cancelPopconfirm( page );
	expect( writes.length ).toBe( before );
}

/**
 * Delete a category through the core REST API, as the signed-in user.
 *
 * @since 1.0.0
 *
 * @param {Page}   page The browser page, on the Tags app.
 * @param {number} id   The category ID.
 *
 * @return {Promise<number>} The response status.
 */
async function deleteCategory( page: Page, id: number ): Promise< number > {
	return page.evaluate( async ( categoryId ) => {
		const config = (
			window as unknown as {
				licensioKbTags: { root: string; nonce: string };
			}
		 ).licensioKbTags;
		const base = config.root.replace( 'licensio-kb/v1', 'wp/v2' );
		const url =
			base +
			'licensio_categories/' +
			categoryId +
			( base.includes( '?' ) ? '&' : '?' ) +
			'force=true';
		const response = await fetch( url, {
			method: 'DELETE',
			headers: { 'X-WP-Nonce': config.nonce },
			credentials: 'same-origin',
		} );

		return response.status;
	}, id );
}

/**
 * Add a tag through the Add tag form.
 *
 * @since 1.0.0
 *
 * @param {Page}   page The browser page.
 * @param {string} name The tag name.
 *
 * @return {Promise<void>} Resolves once the tag is listed.
 */
async function addTag( page: Page, name: string ): Promise< void > {
	await page.getByRole( 'button', { name: 'Add tag' } ).click();

	const form = page.locator( '.lkb-tags__add' );

	await form.getByLabel( 'Name' ).fill( name );
	await form.getByRole( 'button', { name: 'Add tag' } ).click();
	await confirmPopconfirm( page );
	await expect( page.locator( '.lkb-tags__add' ) ).toBeHidden();
}

/**
 * Search the table so only this run's tags are listed.
 *
 * @since 1.0.0
 *
 * @param {Page}   page The browser page.
 * @param {string} text The search text.
 *
 * @return {Promise<void>} Resolves once the search is applied.
 */
async function search( page: Page, text: string ): Promise< void > {
	await page.getByLabel( 'Search tags' ).fill( text );
	await page.getByLabel( 'Search tags' ).press( 'Enter' );
}

/**
 * The row of a tag.
 *
 * @since 1.0.0
 *
 * @param {Page}   page The browser page.
 * @param {string} name The tag name.
 *
 * @return {import('@playwright/test').Locator} The row.
 */
function row( page: Page, name: string ) {
	return page.locator( '.ant-table-row', { hasText: name } );
}

test( 'tags are edited, merged and converted only after confirmation', async ( {
	page,
} ) => {
	const writes = recordWrites( page );
	const alpha = `Alpha ${ suffix }`;
	const beta = `Beta ${ suffix }`;
	const gamma = `Gamma ${ suffix }`;

	await logIn( page );
	await openContentManager( page, 'tags' );

	// Cancel on Add sends nothing.
	await page.getByRole( 'button', { name: 'Add tag' } ).click();

	const form = page.locator( '.lkb-tags__add' );

	await form.getByLabel( 'Name' ).fill( alpha );
	await cancelSendsNothing( page, writes, () =>
		form.getByRole( 'button', { name: 'Add tag' } ).click()
	);
	await form.getByRole( 'button', { name: 'Cancel' } ).click();

	for ( const name of [ alpha, beta, gamma ] ) {
		await addTag( page, name );
	}

	await search( page, suffix );
	await expect( page.locator( '.ant-table-row' ) ).toHaveCount( 3 );

	// A slug another tag uses is refused with a message, and Cancel sends nothing.
	const before = writes.length;
	const editing = row( page, gamma );

	await editing.getByRole( 'button', { name: 'Edit' } ).click();

	const slug = page.getByLabel( 'Slug', { exact: true } ).last();

	await slug.fill( `alpha-${ suffix }` );
	await page.getByRole( 'button', { name: 'Save' } ).first().click();
	await cancelPopconfirm( page );
	expect( writes.length ).toBe( before );

	await page.getByRole( 'button', { name: 'Save' } ).first().click();
	await confirmPopconfirm( page );
	await expect(
		page.getByText(
			`Another tag already uses the slug “alpha-${ suffix }”. Choose a different slug.`
		)
	).toBeVisible();
	await expect( slug ).toHaveValue( `alpha-${ suffix }` );
	await page
		.locator( '.ant-table-row' )
		.getByRole( 'button', { name: 'Cancel' } )
		.click();
	await expect( row( page, gamma ) ).toContainText( `gamma-${ suffix }` );

	// Merge Beta into Alpha.
	await row( page, beta ).locator( '.ant-checkbox-input' ).check();
	await page.getByRole( 'button', { name: 'Merge into…' } ).click();

	const merge = page.locator( '.ant-modal', { hasText: 'Merge tags' } );

	await merge.getByLabel( 'Tag to merge into' ).click();
	await page.keyboard.type( alpha );
	await page.locator( '.ant-select-item-option', { hasText: alpha } ).click();
	await cancelSendsNothing( page, writes, () =>
		merge.getByRole( 'button', { name: 'Merge' } ).click()
	);
	await merge.getByRole( 'button', { name: 'Merge' } ).click();
	await confirmPopconfirm( page );
	await expect( page.locator( '.ant-table-row' ) ).toHaveCount( 2 );

	// Convert Gamma to a top-level category.
	await row( page, gamma ).locator( '.ant-checkbox-input' ).check();
	await page.getByRole( 'button', { name: 'Convert to category' } ).click();

	const convert = page.locator( '.ant-modal', {
		hasText: 'Convert to categories',
	} );

	await cancelSendsNothing( page, writes, () =>
		convert.getByRole( 'button', { name: 'Convert' } ).click()
	);

	const converted = page.waitForResponse(
		( response ) =>
			decodeURIComponent( response.url() ).includes( 'tags/convert' ) &&
			'POST' === response.request().method()
	);

	await convert.getByRole( 'button', { name: 'Convert' } ).click();
	await confirmPopconfirm( page );

	const categoryId: number = ( await ( await converted ).json() )
		.converted[ 0 ].category.id;

	await expect( page.locator( '.ant-table-row' ) ).toHaveCount( 1 );
	await expect( row( page, alpha ) ).toBeVisible();

	// Clean up, checking Cancel on Delete first.
	await row( page, alpha ).locator( '.ant-checkbox-input' ).check();

	const remove = page
		.locator( '.lkb-bulk' )
		.getByRole( 'button', { name: 'Delete' } );

	await cancelSendsNothing( page, writes, () => remove.click() );
	await remove.click();
	await confirmPopconfirm( page );
	await expect( page.locator( '.ant-table-row' ) ).toHaveCount( 0 );

	// The Delete button went with the selection, so focus is on the title.
	await expect( page.locator( '.lkb-page__title' ) ).toBeFocused();
	expect( await deleteCategory( page, categoryId ) ).toBe( 200 );
} );

test( 'Escape closes the Add tag form and an inline edit', async ( {
	page,
} ) => {
	const writes = recordWrites( page );
	const name = `Escape ${ suffix }`;

	await logIn( page );
	await openContentManager( page, 'tags' );

	// Escape in the Add tag form closes it and puts focus back on Add tag.
	await page.getByRole( 'button', { name: 'Add tag' } ).click();
	await page.locator( '.lkb-tags__add' ).getByLabel( 'Name' ).fill( name );
	await page.keyboard.press( 'Escape' );
	await expect( page.locator( '.lkb-tags__add' ) ).toBeHidden();
	await expect(
		page.getByRole( 'button', { name: 'Add tag' } )
	).toBeFocused();
	expect( writes ).toHaveLength( 0 );

	await addTag( page, name );
	await search( page, name );

	// Escape in an open row closes it and puts focus back on its Edit button.
	const before = writes.length;

	await row( page, name ).getByRole( 'button', { name: 'Edit' } ).click();
	await page.getByLabel( 'Name', { exact: true } ).last().fill( 'Changed' );
	await page.keyboard.press( 'Escape' );
	await expect(
		row( page, name ).getByRole( 'button', { name: 'Edit' } )
	).toBeFocused();
	await expect( row( page, name ) ).toContainText( name );
	expect( writes.length ).toBe( before );

	// Clean up.
	await row( page, name ).locator( '.ant-checkbox-input' ).check();
	await page
		.locator( '.lkb-bulk' )
		.getByRole( 'button', { name: 'Delete' } )
		.click();
	await confirmPopconfirm( page );
	await expect( page.locator( '.ant-table-row' ) ).toHaveCount( 0 );
} );
