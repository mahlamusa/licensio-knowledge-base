/**
 * Site Editor override flow for the knowledge base block templates.
 *
 * Proves the block template acceptance criterion against a real block theme:
 * the five plugin templates appear in Site Editor → Templates, each previews
 * its knowledge base layout through the view block rather than showing raw
 * shortcode text, one of them can be customised and saved, the customisation
 * shows on the front end, and the template can then be reset back to the
 * plugin's copy.
 *
 * The suite needs a running WordPress with this plugin active, a block theme
 * (Twenty Twenty-Four or newer) and an administrator account, so it is skipped
 * unless `LICENSIO_KB_E2E=1` and the two credential variables are present. CI
 * provides both through `bin/e2e-env.sh`.
 *
 * @since 1.0.0
 * @since 1.0.0 Covers the view block preview and finds cards by searching.
 * @since 1.0.0 Checks the Site Editor offers the view block in its inserter.
 */

import { expect, test, type Locator, type Page } from '@playwright/test';
import { switchOffWelcomeGuides } from './helpers/editor';
import { openDashboard, password, user } from './helpers/session';

const enabled = process.env.LICENSIO_KB_E2E === '1';

const MARKER = 'Licensio KB override marker';

/**
 * How long a cold Site Editor may take to show its template list.
 *
 * The first Site Editor load of a run builds the editor bundle and fetches
 * every template, which has taken longer than 20 seconds on a busy machine.
 */
const COLD_SITE_EDITOR = 60_000;

/**
 * Open Site Editor → Templates and wait for the list to load.
 *
 * Waits for the first template card, not only the heading, so a check made
 * straight after this runs against a loaded list.
 *
 * @since 1.0.0
 * @since 1.0.0 Waits for the list itself, with a cold load allowance.
 *
 * @param {Page} page The browser page.
 *
 * @return {Promise<void>} Resolves once the list shows a template.
 */
async function openTemplateList( page: Page ): Promise< void > {
	await page.goto( '/wp-admin/site-editor.php?postType=wp_template' );

	const region = page.getByRole( 'region', { name: 'Templates' } );

	await expect(
		region.getByRole( 'heading', { name: 'Templates' } )
	).toBeVisible( { timeout: COLD_SITE_EDITOR } );
	await expect( region.getByRole( 'gridcell' ).first() ).toBeVisible( {
		timeout: COLD_SITE_EDITOR,
	} );
}

/**
 * Narrow the template list to one title with its search field.
 *
 * The list is paged, and which page a template lands on depends on how many
 * templates the theme ships, so a card is looked up by searching for it.
 *
 * @since 1.0.0
 *
 * @param {Page}   page  The browser page.
 * @param {string} title The template title.
 *
 * @return {Promise<void>} Resolves once the list shows only matching cards.
 */
async function searchTemplates( page: Page, title: string ): Promise< void > {
	const region = page.getByRole( 'region', { name: 'Templates' } );

	await region.getByRole( 'searchbox' ).fill( title );

	// Wait for the list to settle, or a click can land on a card being replaced.
	await expect(
		region.getByRole( 'gridcell' ).filter( { hasNotText: title } )
	).toHaveCount( 0, { timeout: 20_000 } );
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
		await openDashboard( page );
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
			await searchTemplates( page, title );
			await expect( templateCard( page, title ) ).toBeVisible( {
				timeout: 20_000,
			} );
		}
	} );

	test( 'every plugin template previews its layout in the editor', async ( {
		page,
	} ) => {
		// Five cold editor canvases do not fit the default test budget.
		test.slow();

		for ( const title of [
			'Single Article',
			'Article Archive',
			'Article Category',
			'Article Tag',
			'Article Search Results',
		] ) {
			await openTemplateList( page );
			await searchTemplates( page, title );
			await templateCard( page, title ).click();

			await switchOffWelcomeGuides( page, 'core/edit-site', [
				'welcomeGuide',
				'welcomeGuideTemplate',
			] );

			const canvas = page.frameLocator( 'iframe[name="editor-canvas"]' );
			const view = canvas.locator( '[data-type="licensio-kb/view"]' );

			await expect( view ).toHaveCount( 1, {
				timeout: COLD_SITE_EDITOR,
			} );
			// The server renders the real layout: every view opens with a heading.
			await expect( view.locator( 'h1' ).first() ).toBeVisible( {
				timeout: 20_000,
			} );
			await expect( canvas.locator( 'body' ) ).not.toContainText(
				'licensio_kb_view'
			);
		}

		// The Site Editor offers the view block in its inserter, so a view
		// removed from a template can be put back.
		const inserter = await page.evaluate(
			() =>
				(
					window as Window & {
						wp?: {
							blocks?: {
								getBlockType: ( name: string ) => {
									supports?: { inserter?: boolean };
								};
							};
						};
					}
				 ).wp?.blocks?.getBlockType( 'licensio-kb/view' )?.supports
					?.inserter
		);

		expect( inserter ).toBe( true );
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
		 * Every plugin template renders its view through one view block, so
		 * the customisation is a paragraph inserted after that block.
		 */
		await searchTemplates( page, 'Article Search Results' );
		await templateCard( page, 'Article Search Results' ).click();

		await switchOffWelcomeGuides( page, 'core/edit-site', [
			'welcomeGuide',
			'welcomeGuideTemplate',
		] );

		const canvas = page.frameLocator( 'iframe[name="editor-canvas"]' );

		await canvas.locator( '[data-type="licensio-kb/view"]' ).click();
		await page.keyboard.press( 'ControlOrMeta+Alt+Y' );
		await expect(
			canvas.locator( 'main [data-type="core/paragraph"]' ).first()
		).toBeFocused();
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
		await searchTemplates( page, 'Article Search Results' );
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
