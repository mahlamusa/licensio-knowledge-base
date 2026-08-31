/**
 * The settings screen, end to end.
 *
 * Proves the acceptance criteria for #10 that only a real WordPress can answer:
 * a brand colour changed on the settings screen reaches the front end without a
 * cache flush, unsaved edits warn before the browser leaves, and reset puts
 * every value back.
 *
 * The suite needs a running WordPress with this plugin active and an
 * administrator account, so it is skipped unless `LISENSIO_KB_E2E=1` and the
 * two credential variables are present. CI has no such site and runs Playwright
 * with `--pass-with-no-tests`.
 *
 * @since 1.0.0
 */

import { expect, test, type Page } from '@playwright/test';

const enabled = process.env.LISENSIO_KB_E2E === '1';
const user = process.env.LISENSIO_KB_ADMIN_USER ?? '';
const password = process.env.LISENSIO_KB_ADMIN_PASSWORD ?? '';

const SETTINGS_URL =
	'/wp-admin/edit.php?post_type=lisensio_article&page=lisensio-kb-settings';

const BRAND = '#b91c1c';

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
 * Open the settings screen and wait for the app to mount.
 *
 * @since 1.0.0
 *
 * @param {Page} page The browser page.
 *
 * @return {Promise<void>} Resolves once the screen has rendered.
 */
async function openSettings( page: Page ): Promise< void > {
	await page.goto( SETTINGS_URL );
	await expect(
		page.getByRole( 'heading', { name: 'Knowledge Base Settings' } )
	).toBeVisible();
}

/**
 * Move to the Appearance tab and type a brand colour.
 *
 * @since 1.0.0
 *
 * @param {Page}   page  The browser page.
 * @param {string} value The colour to type.
 *
 * @return {Promise<void>} Resolves once the field holds the value.
 */
async function setBrandColour( page: Page, value: string ): Promise< void > {
	await page.getByRole( 'tab', { name: 'Appearance' } ).click();

	const field = page
		.locator( '.ant-form-item', { hasText: 'Brand colour' } )
		.locator( 'input.ant-input' );

	await field.fill( value );
	await field.blur();
}

test.describe( 'Knowledge base settings', () => {
	test.skip(
		! enabled || ! user || ! password,
		'Set LISENSIO_KB_E2E=1, LISENSIO_KB_ADMIN_USER and LISENSIO_KB_ADMIN_PASSWORD to run.'
	);

	test.beforeEach( async ( { page } ) => {
		await logIn( page );
	} );

	test.afterEach( async ( { page } ) => {
		await openSettings( page );
		await page.getByRole( 'button', { name: 'Reset to defaults' } ).click();
		await page
			.getByRole( 'dialog' )
			.getByRole( 'button', { name: 'Reset to defaults' } )
			.click();
		await expect( page.getByText( 'Every setting is back to' ) ).toBeVisible();
	} );

	test( 'a brand colour saved here reaches the front end', async ( {
		page,
	} ) => {
		await openSettings( page );
		await setBrandColour( page, BRAND );

		// The preview reflects the draft before anything is saved.
		await expect( page.locator( '.lisensio-kb-settings__preview' ) ).toHaveCSS(
			'--lisensio-kb-brand',
			BRAND
		);

		await page.getByRole( 'button', { name: 'Save changes' } ).click();
		await expect( page.getByText( 'Settings saved.' ) ).toBeVisible();

		// No permalink save, no cache flush: straight to the archive.
		await page.goto( '/?post_type=lisensio_article' );

		const root = page.locator( '.lisensio-kb' ).first();

		await expect( root ).toHaveCSS( '--lisensio-kb-brand', BRAND );
	} );

	test( 'leaving with unsaved changes warns first', async ( { page } ) => {
		await openSettings( page );
		await setBrandColour( page, '#0f766e' );

		await expect( page.getByText( 'You have unsaved changes.' ) ).toBeVisible();

		let asked = false;

		page.on( 'dialog', async ( dialog ) => {
			asked = true;

			await dialog.dismiss();
		} );

		await page.getByRole( 'link', { name: 'Dashboard' } ).first().click();

		await expect.poll( () => asked ).toBe( true );
		await expect(
			page.getByRole( 'heading', { name: 'Knowledge Base Settings' } )
		).toBeVisible();
	} );

	test( 'an invalid value is refused per field and nothing is saved', async ( {
		page,
	} ) => {
		await openSettings( page );

		await page.getByRole( 'tab', { name: 'General' } ).click();

		const perPage = page
			.locator( '.ant-form-item', { hasText: 'Articles per page' } )
			.locator( 'input' );

		await perPage.fill( '0' );
		await setBrandColour( page, '#123456' );

		await page.getByRole( 'button', { name: 'Save changes' } ).click();

		await expect(
			page.getByText( 'One or more settings are invalid' )
		).toBeVisible();

		// The good field in the same payload was not written either.
		await page.reload();
		await expect(
			page.getByRole( 'heading', { name: 'Knowledge Base Settings' } )
		).toBeVisible();
		await page.getByRole( 'tab', { name: 'Appearance' } ).click();

		await expect(
			page
				.locator( '.ant-form-item', { hasText: 'Brand colour' } )
				.locator( 'input.ant-input' )
		).not.toHaveValue( '#123456' );
	} );
} );
