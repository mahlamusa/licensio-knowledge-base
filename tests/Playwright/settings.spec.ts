/**
 * The settings screen, end to end.
 *
 * Proves the acceptance criteria for #10 that only a real WordPress can answer:
 * a brand colour changed on the settings screen reaches the front end without a
 * cache flush, unsaved edits warn before the browser leaves, and reset puts
 * every value back.
 *
 * The suite needs a running WordPress with this plugin active and an
 * administrator account, so it is skipped unless `LICENSIO_KB_E2E=1` and the
 * two credential variables are present. CI provides both through
 * `bin/e2e-env.sh`.
 *
 * @since 1.0.0
 */

import { expect, test, type Locator, type Page } from '@playwright/test';
import { confirmPopconfirm } from './helpers/content-manager';
import { logIn, password, user } from './helpers/session';

const enabled = process.env.LICENSIO_KB_E2E === '1';

const SETTINGS_URL =
	'/wp-admin/edit.php?post_type=licensio_article&page=licensio-kb-settings';

const BRAND = '#b91c1c';

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
		page.getByRole( 'heading', { name: 'Settings', exact: true } )
	).toBeVisible();
	await expect( sectionButton( page, 'General' ) ).toBeVisible();
}

/**
 * The sidebar button that opens a settings section.
 *
 * @since 1.0.0
 *
 * @param {Page}   page  The browser page.
 * @param {string} label The section label.
 *
 * @return {Locator} The button.
 */
function sectionButton( page: Page, label: string ): Locator {
	return page
		.getByRole( 'navigation', { name: 'Settings sections' } )
		.getByRole( 'button', { name: label, exact: true } );
}

/**
 * The text input of the brand colour row.
 *
 * @since 1.0.0
 *
 * @param {Page} page The browser page.
 *
 * @return {Locator} The input.
 */
function brandField( page: Page ): Locator {
	return page
		.locator( '.lkb-field', { hasText: 'Brand colour' } )
		.locator( 'input.ant-input' );
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
	await sectionButton( page, 'Appearance' ).click();

	const field = brandField( page );

	await field.fill( value );
	await field.blur();
}

test.describe( 'Knowledge base settings', () => {
	// Every test writes the same stored settings, so they cannot overlap.
	test.describe.configure( { mode: 'serial' } );

	test.skip(
		! enabled || ! user || ! password,
		'Set LICENSIO_KB_E2E=1, LICENSIO_KB_ADMIN_USER and LICENSIO_KB_ADMIN_PASSWORD to run.'
	);

	test.beforeEach( async ( { page } ) => {
		await logIn( page );
	} );

	test.afterEach( async ( { page } ) => {
		// A test may leave unsaved edits behind; let the guard wave this through.
		page.on( 'dialog', ( dialog ) => dialog.accept().catch( () => undefined ) );

		await openSettings( page );
		await page.getByRole( 'button', { name: 'Reset all' } ).click();
		await page
			.getByRole( 'dialog' )
			.getByRole( 'button', { name: 'Reset everything' } )
			.click();
		await expect(
			page.getByText( 'Every setting is back to its default.' )
		).toBeVisible();
	} );

	test( 'a brand colour saved here reaches the front end', async ( {
		page,
	} ) => {
		await openSettings( page );
		await setBrandColour( page, BRAND );

		// The preview reflects the draft before anything is saved.
		await expect( page.locator( '.licensio-kb-settings__preview' ) ).toHaveCSS(
			'--licensio-kb-brand',
			BRAND
		);

		await page.getByRole( 'button', { name: 'Save changes' } ).click();
		await confirmPopconfirm( page );
		await expect( page.getByText( 'Settings saved.' ) ).toBeVisible();

		// No permalink save, no cache flush: straight to the archive.
		await page.goto( '/?post_type=licensio_article' );

		const root = page.locator( '.licensio-kb' ).first();

		await expect( root ).toHaveCSS( '--licensio-kb-brand', BRAND );
	} );

	test( 'leaving with unsaved changes warns first', async ( { page } ) => {
		await openSettings( page );
		await setBrandColour( page, '#0f766e' );

		await expect( page.locator( '.lkb-bar__status' ) ).toContainText(
			'Unsaved changes'
		);

		let asked = false;

		page.once( 'dialog', async ( dialog ) => {
			asked = true;

			await dialog.dismiss();
		} );

		await page.getByRole( 'link', { name: 'Dashboard' } ).first().click();

		await expect.poll( () => asked ).toBe( true );
		await expect( page ).toHaveURL( /page=licensio-kb-settings/ );
		await expect( brandField( page ) ).toHaveValue( '#0f766e' );
	} );

	test( 'an invalid value is refused per field and nothing is saved', async ( {
		page,
	} ) => {
		await openSettings( page );

		// The number control clamps to its bounds in the browser, so the
		// invalid value goes into the free-text side of the colour row.
		const perPage = page
			.locator( '.lkb-field', { hasText: 'Articles per page' } )
			.locator( 'input' );

		await perPage.fill( '7' );
		await perPage.blur();
		await setBrandColour( page, 'not-a-colour' );

		await page.getByRole( 'button', { name: 'Save changes' } ).click();
		await confirmPopconfirm( page );

		await expect(
			page.getByText( 'One or more settings are invalid' )
		).toBeVisible();
		await expect(
			page.locator( '.lkb-field.is-invalid', { hasText: 'Brand colour' } )
		).toBeVisible();

		// The good field in the same payload was not written either; the
		// previous test's Reset all left it on its default of 20.
		page.on( 'dialog', ( dialog ) => dialog.accept().catch( () => undefined ) );
		await page.reload();
		await expect( sectionButton( page, 'General' ) ).toBeVisible();

		await expect(
			page
				.locator( '.lkb-field', { hasText: 'Articles per page' } )
				.locator( 'input' )
		).toHaveValue( '20' );
	} );
} );
