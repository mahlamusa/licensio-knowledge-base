/**
 * The Tags app, end to end.
 *
 * Adds tags through the app, then checks that every change waits for its
 * Popconfirm (Cancel sends nothing), that a taken slug is made unique, and
 * that merge and convert to category work on the selected tags.
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

const enabled = process.env.LICENSIO_KB_E2E === '1';
const user = process.env.LICENSIO_KB_ADMIN_USER ?? '';
const password = process.env.LICENSIO_KB_ADMIN_PASSWORD ?? '';

test.skip(
	! enabled || ! user || ! password,
	'Set LICENSIO_KB_E2E=1 and the admin credentials to run.'
);

const suffix = Date.now().toString( 36 );

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

	for ( const name of [ alpha, beta, gamma ] ) {
		await addTag( page, name );
	}

	await search( page, suffix );
	await expect( page.locator( '.ant-table-row' ) ).toHaveCount( 3 );

	// A slug that is taken is made unique, and Cancel sends nothing.
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
	await expect( row( page, gamma ) ).toContainText( `alpha-${ suffix }-2` );

	// Merge Beta into Alpha.
	await row( page, beta ).locator( '.ant-checkbox-input' ).check();
	await page.getByRole( 'button', { name: 'Merge into…' } ).click();

	const merge = page.locator( '.ant-modal', { hasText: 'Merge tags' } );

	await merge.getByLabel( 'Tag to merge into' ).click();
	await page.keyboard.type( alpha );
	await page.locator( '.ant-select-item-option', { hasText: alpha } ).click();
	await merge.getByRole( 'button', { name: 'Merge' } ).click();
	await confirmPopconfirm( page );
	await expect( page.locator( '.ant-table-row' ) ).toHaveCount( 2 );

	// Convert Gamma to a top-level category.
	await row( page, gamma ).locator( '.ant-checkbox-input' ).check();
	await page.getByRole( 'button', { name: 'Convert to category' } ).click();

	const convert = page.locator( '.ant-modal', {
		hasText: 'Convert to categories',
	} );

	await convert.getByRole( 'button', { name: 'Convert' } ).click();
	await confirmPopconfirm( page );
	await expect( page.locator( '.ant-table-row' ) ).toHaveCount( 1 );
	await expect( row( page, alpha ) ).toBeVisible();

	// Clean up.
	await row( page, alpha ).locator( '.ant-checkbox-input' ).check();
	await page
		.locator( '.lkb-bulk' )
		.getByRole( 'button', { name: 'Delete' } )
		.click();
	await confirmPopconfirm( page );
	await expect( page.locator( '.ant-table-row' ) ).toHaveCount( 0 );
} );
