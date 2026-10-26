/**
 * The knowledge base blocks, in the editor and on the front end.
 *
 * Proves the acceptance criteria for #7 against a real WordPress: each block
 * can be inserted from the Knowledge Base category, its editor preview is the
 * server-rendered markup rather than a placeholder, and the published page
 * carries the same block markup. The shortcode twin of each block is published
 * alongside it, so the page also proves the two entry points agree outside the
 * unit suite.
 *
 * The suite needs a running WordPress with this plugin active and an
 * administrator account, so it is skipped unless `LICENSIO_KB_E2E=1` and the
 * two credential variables are present. CI provides both through
 * `bin/e2e-env.sh`.
 *
 * @since 1.0.0
 */

import { expect, test, type Locator, type Page } from '@playwright/test';
import { switchOffWelcomeGuides } from './helpers/editor';
import { logIn, password, user } from './helpers/session';

const enabled = process.env.LICENSIO_KB_E2E === '1';

type BlockFixture = {
	name: string;
	title: string;
	shortcode: string;
	marker: string;
};

const BLOCKS: BlockFixture[] = [
	{
		name: 'licensio-kb/article-list',
		title: 'Knowledge Base Articles',
		shortcode: '[licensio_kb_articles]',
		marker: 'licensio-kb-block--article-list',
	},
	{
		name: 'licensio-kb/category-grid',
		title: 'Knowledge Base Categories',
		shortcode: '[licensio_kb_categories]',
		marker: 'licensio-kb-block--category-grid',
	},
	{
		name: 'licensio-kb/breadcrumbs',
		title: 'Knowledge Base Breadcrumbs',
		shortcode: '[licensio_kb_breadcrumbs]',
		marker: 'licensio-kb-block--breadcrumbs',
	},
	{
		name: 'licensio-kb/faq',
		title: 'Knowledge Base FAQ',
		shortcode: '[licensio_kb_faq]',
		marker: 'licensio-kb-block--faq',
	},
];

async function newPost( page: Page ): Promise< void > {
	// A new post rather than a new page: a page opens the starter pattern
	// chooser over the editor, and the blocks are the same in either.
	await page.goto( '/wp-admin/post-new.php' );

	await switchOffWelcomeGuides( page, 'core/edit-post', [ 'welcomeGuide' ] );

	await expect(
		page.getByRole( 'button', {
			name: /Block Inserter|Toggle block inserter/,
		} )
	).toBeVisible( { timeout: 20_000 } );
}

async function insertBlock( page: Page, title: string ): Promise< void > {
	await page
		.getByRole( 'button', { name: /Block Inserter|Toggle block inserter/ } )
		.click();
	await page.getByPlaceholder( 'Search' ).fill( title );
	await blockOption( page, title ).click();
}

/**
 * A block in the inserter's results, by its title.
 *
 * The option's accessible name starts with the block icon's text, so it is
 * matched on how it ends rather than exactly.
 *
 * @since 1.0.0
 *
 * @param {Page}   page  The browser page.
 * @param {string} title The block title.
 *
 * @return {Locator} The option.
 */
function blockOption( page: Page, title: string ): Locator {
	return page.getByRole( 'option', {
		name: new RegExp( `${ title }$` ),
	} );
}

test.describe( 'Knowledge base blocks', () => {
	test.skip(
		! enabled || '' === user || '' === password,
		'Set LICENSIO_KB_E2E=1, LICENSIO_KB_ADMIN_USER and LICENSIO_KB_ADMIN_PASSWORD to run this suite.'
	);

	test.beforeEach( async ( { page } ) => {
		await logIn( page );
	} );

	for ( const block of BLOCKS ) {
		test( `${ block.title } inserts and previews with its render callback`, async ( {
			page,
		} ) => {
			await newPost( page );
			await insertBlock( page, block.title );

			const canvas = page.frameLocator( 'iframe[name="editor-canvas"]' );

			// The preview is the server-rendered markup, not a JSX stand-in.
			await expect(
				canvas.locator( `.${ block.marker }` ).first()
			).toBeVisible( { timeout: 20_000 } );
		} );
	}

	test( 'every block is filed under the Knowledge Base category', async ( {
		page,
	} ) => {
		await newPost( page );
		await page
			.getByRole( 'button', {
				name: /Block Inserter|Toggle block inserter/,
			} )
			.click();
		await page.getByPlaceholder( 'Search' ).fill( 'Knowledge Base' );

		await expect(
			page.getByRole( 'heading', { name: 'Knowledge Base' } ).first()
		).toBeVisible( { timeout: 20_000 } );

		for ( const block of BLOCKS ) {
			await expect( blockOption( page, block.title ) ).toBeVisible();
		}
	} );

	test( 'a published page renders every block and every shortcode', async ( {
		page,
		request,
	} ) => {
		const content = BLOCKS.map(
			( block ) =>
				`<!-- wp:${ block.name } /-->\n\n<!-- wp:shortcode -->${ block.shortcode }<!-- /wp:shortcode -->`
		).join( '\n\n' );

		await page.goto( '/wp-admin/post-new.php?post_type=page' );

		const nonce = await page.evaluate(
			() =>
				(
					window as Window & {
						wpApiSettings?: { nonce: string };
					}
				 ).wpApiSettings?.nonce ?? ''
		);

		const cookies = await page.context().cookies();
		const cookieHeader = cookies
			.map( ( cookie ) => `${ cookie.name }=${ cookie.value }` )
			.join( '; ' );

		const created = await request.post( '/?rest_route=/wp/v2/pages', {
			headers: {
				'X-WP-Nonce': nonce,
				Cookie: cookieHeader,
			},
			data: {
				title: 'Licensio KB block coverage',
				status: 'publish',
				content,
			},
		} );

		expect( created.ok() ).toBeTruthy();

		const body = await created.json();

		try {
			await page.goto( body.link );

			for ( const block of BLOCKS ) {
				// One from the block, one from its shortcode.
				await expect(
					page.locator( `.${ block.marker }` )
				).toHaveCount( 2 );
			}
		} finally {
			// Delete the page again, or every run adds one more to the site and its menus.
			const deleted = await request.delete(
				`/?rest_route=/wp/v2/pages/${ body.id }&force=true`,
				{
					headers: {
						'X-WP-Nonce': nonce,
						Cookie: cookieHeader,
					},
				}
			);

			expect( deleted.ok(), 'The coverage page was not deleted.' ).toBe(
				true
			);
		}
	} );
} );
