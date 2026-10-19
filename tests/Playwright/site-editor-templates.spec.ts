/**
 * Site Editor override flow for the knowledge base block templates.
 *
 * Proves the acceptance criterion for #6 against a real block theme: the five
 * plugin templates appear in Site Editor → Templates, one of them can be
 * customised and saved, the customisation shows on the front end, and the
 * template can then be reset back to the plugin's copy.
 *
 * The suite needs a running WordPress with this plugin active, a block theme
 * (Twenty Twenty-Four or newer) and an administrator account, so it is skipped
 * unless `LICENSIO_KB_E2E=1` and the two credential variables are present. CI
 * has no such site and runs Playwright with `--pass-with-no-tests`.
 *
 * @since 1.0.0
 */

import { expect, test, type Page } from '@playwright/test';

const enabled = process.env.LICENSIO_KB_E2E === '1';
const user = process.env.LICENSIO_KB_ADMIN_USER ?? '';
const password = process.env.LICENSIO_KB_ADMIN_PASSWORD ?? '';

const MARKER = 'Licensio KB override marker';

async function logIn( page: Page ): Promise< void > {
	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', user );
	await page.fill( '#user_pass', password );
	await page.click( '#wp-submit' );
	await expect( page.locator( '#wpadminbar' ) ).toBeVisible();
}

async function openTemplateList( page: Page ): Promise< void > {
	await page.goto( '/wp-admin/site-editor.php?postType=wp_template' );
	await expect( page.getByRole( 'button', { name: 'Open Navigation' } ) ).toBeVisible( {
		timeout: 20_000,
	} );
}

test.describe( 'Knowledge base block templates in the Site Editor', () => {
	test.skip(
		! enabled || '' === user || '' === password,
		'Set LICENSIO_KB_E2E=1, LICENSIO_KB_ADMIN_USER and LICENSIO_KB_ADMIN_PASSWORD to run this suite.'
	);

	test.beforeEach( async ( { page } ) => {
		await logIn( page );
	} );

	test( 'every plugin template is listed', async ( { page } ) => {
		await openTemplateList( page );

		for ( const title of [
			'Single Article',
			'Article Archive',
			'Article Category',
			'Article Tag',
			'Article Search Results',
		] ) {
			await expect( page.getByText( title, { exact: true } ) ).toBeVisible();
		}
	} );

	test( 'the shared header part is listed', async ( { page } ) => {
		await page.goto( '/wp-admin/site-editor.php?postType=wp_template_part' );

		await expect( page.getByText( 'Knowledge Base Header', { exact: true } ) ).toBeVisible( {
			timeout: 20_000,
		} );
	} );

	test( 'a template can be customised, saved and reset', async ( { page } ) => {
		await openTemplateList( page );

		await page.getByText( 'Single Article', { exact: true } ).click();
		await page.getByRole( 'button', { name: 'Edit' } ).click();

		const canvas = page.frameLocator( 'iframe[name="editor-canvas"]' );

		await canvas.locator( '[data-type="core/post-title"]' ).first().click();
		await page.keyboard.press( 'Enter' );
		await page.keyboard.type( MARKER );

		await page.getByRole( 'button', { name: 'Save', exact: true } ).click();
		await page.getByRole( 'button', { name: 'Save', exact: true } ).last().click();

		await expect( page.getByText( 'Site updated.' ) ).toBeVisible( { timeout: 20_000 } );

		// The saved customisation is what the front end now renders.
		const article = await page.evaluate( async () => {
			const response = await window.fetch( '/wp-json/wp/v2/licensio_articles?per_page=1' );
			const items = await response.json();

			return Array.isArray( items ) && items.length > 0 ? items[ 0 ].link : '';
		} );

		test.skip( '' === article, 'The site has no published knowledge base article to render.' );

		await page.goto( article );
		await expect( page.getByText( MARKER ) ).toBeVisible();

		// Reset puts the plugin's own copy back.
		await openTemplateList( page );
		await page.getByText( 'Single Article', { exact: true } ).click();
		await page.getByRole( 'button', { name: 'Actions' } ).click();
		await page.getByRole( 'menuitem', { name: /Reset/ } ).click();
		await page.getByRole( 'button', { name: 'Reset' } ).click();

		await page.goto( article );
		await expect( page.getByText( MARKER ) ).toHaveCount( 0 );
	} );
} );
