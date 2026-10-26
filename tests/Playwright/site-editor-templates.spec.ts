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
 * provides both through `bin/e2e-env.sh`.
 *
 * @since 1.0.0
 */

import { expect, test, type Locator, type Page } from '@playwright/test';
import { switchOffWelcomeGuides } from './helpers/editor';
import { logIn, password, user } from './helpers/session';

const enabled = process.env.LICENSIO_KB_E2E === '1';

const MARKER = 'Licensio KB override marker';

async function openTemplateList( page: Page ): Promise< void > {
	await page.goto( '/wp-admin/site-editor.php?postType=wp_template' );
	await expect(
		page
			.getByRole( 'region', { name: 'Templates' } )
			.getByRole( 'heading', { name: 'Templates' } )
	).toBeVisible( { timeout: 20_000 } );
}

/**
 * A template's card in the template list, by its title.
 *
 * @since 1.0.0
 *
 * @param {Page}   page  The browser page.
 * @param {string} title The template title.
 *
 * @return {Locator} The button that opens the template.
 */
function templateCard( page: Page, title: string ): Locator {
	return page.getByRole( 'button', { name: title, exact: true } ).first();
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
			await expect( templateCard( page, title ) ).toBeVisible( {
				timeout: 20_000,
			} );
		}
	} );

	test( 'the shared header part is listed', async ( { page } ) => {
		await page.goto(
			'/wp-admin/site-editor.php?postType=wp_template_part'
		);

		await expect(
			page.getByText( 'Knowledge Base Header', { exact: true } )
		).toBeVisible( {
			timeout: 20_000,
		} );
	} );

	test( 'a template can be customised, saved and reset', async ( {
		page,
	} ) => {
		// Two cold Site Editor loads and two saves do not fit the default test budget.
		test.slow();

		await openTemplateList( page );

		/*
		 * The search results template, because it is still built from blocks
		 * the canvas can click into. The single article, archive and category
		 * templates render their whole view through one HTML block.
		 */
		await templateCard( page, 'Article Search Results' ).click();

		await switchOffWelcomeGuides( page, 'core/edit-site', [
			'welcomeGuide',
			'welcomeGuideTemplate',
		] );

		const canvas = page.frameLocator( 'iframe[name="editor-canvas"]' );

		await canvas
			.locator( '[data-type="core/query-title"]' )
			.first()
			.click();
		await page.keyboard.press( 'Enter' );
		await page.keyboard.type( MARKER );

		await page
			.getByRole( 'button', { name: 'Save', exact: true } )
			.first()
			.click();

		// With more than one changed entity the editor asks which to save first.
		const saved = page
			.getByRole( 'button', { name: 'Dismiss this notice' } )
			.or( page.locator( '.components-snackbar' ) );
		const panelSave = page
			.locator( '.entities-saved-states__panel' )
			.getByRole( 'button', { name: 'Save', exact: true } );

		await expect( saved.or( panelSave ).first() ).toBeVisible( {
			timeout: 20_000,
		} );

		if ( await panelSave.isVisible() ) {
			await panelSave.click();
		}

		await expect(
			page.locator( '.components-snackbar' ).first()
		).toContainText( /updated|saved/i, { timeout: 20_000 } );

		// The saved customisation is what the front end now renders.
		const results = '/?s=licence&post_type=licensio_article';

		await page.goto( results );
		await expect( page.getByText( MARKER ).first() ).toBeVisible();

		// Reset puts the plugin's own copy back.
		await openTemplateList( page );
		await page
			.getByRole( 'gridcell' )
			.filter( { has: templateCard( page, 'Article Search Results' ) } )
			.getByRole( 'button', { name: 'Actions' } )
			.click();
		await page.getByRole( 'menuitem', { name: /Reset/ } ).click();
		await page.getByRole( 'button', { name: 'Reset' } ).click();
		await expect(
			page.locator( '.components-snackbar' ).first()
		).toContainText( /reset/i, { timeout: 20_000 } );

		await page.goto( results );
		await expect( page.getByText( MARKER ) ).toHaveCount( 0 );
	} );
} );
