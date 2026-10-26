/**
 * Every admin app fits the screen without a horizontal scroll bar.
 *
 * Visits each Vue app (and each Tools tab, and the second step of the import
 * wizard) at common desktop widths with the admin menu expanded and folded,
 * and checks that neither the page, nor the app's main region, nor a table
 * inside it scrolls sideways. On a wide monitor the screen stays a centred column with space
 * either side rather than running to the window edges. At phone width the
 * page itself must not scroll sideways; a wide table may scroll inside itself.
 *
 * The suite needs a running WordPress with this plugin active and an
 * administrator account, so it is skipped unless `LICENSIO_KB_E2E=1` and the
 * two credential variables are present.
 *
 * @since 1.0.0
 */

import { expect, test, type Page } from '@playwright/test';
import { contentManagerUrl } from './helpers/content-manager';
import { foldAdminMenu, measureFrame } from './helpers/layout';
import { logIn, password, user } from './helpers/session';

const enabled = process.env.LICENSIO_KB_E2E === '1';

const SUBMENU = '/wp-admin/edit.php?post_type=licensio_article&page=';

/** The desktop widths every screen must fit. */
const DESKTOP_WIDTHS = [ 1280, 1440, 1920 ];

/** One screen to check, and how to reach it once its URL has loaded. */
interface Screen {
	name: string;
	url: string;
	open?: ( page: Page ) => Promise< void >;
}

/**
 * Open a Tools tab by the start of its name.
 *
 * @since 1.0.0
 *
 * @param {string} name The start of the tab's accessible name.
 *
 * @return {Function} Opens the tab, or skips when the tab is not shown.
 */
function toolsTab( name: string ): ( page: Page ) => Promise< void > {
	return async ( page ) => {
		await expect(
			page.getByRole( 'tab', { name: 'Search Index' } )
		).toBeVisible();

		const tab = page.getByRole( 'tab', {
			name: new RegExp( `^${ name }` ),
		} );

		test.skip(
			0 === ( await tab.count() ),
			`The ${ name } tab is hidden.`
		);
		await tab.click();
		await expect( tab ).toHaveAttribute( 'aria-selected', 'true' );
	};
}

const SCREENS: Screen[] = [
	{ name: 'Articles', url: contentManagerUrl( 'articles' ) },
	{ name: 'Categories', url: contentManagerUrl( 'categories' ) },
	{ name: 'Tags', url: contentManagerUrl( 'tags' ) },
	{ name: 'Feedback', url: `${ SUBMENU }licensio-kb-feedback` },
	{ name: 'Analytics', url: `${ SUBMENU }licensio-kb-analytics` },
	{
		name: 'Tools, Migration',
		url: `${ SUBMENU }licensio-kb-tools`,
		open: toolsTab( 'Migration' ),
	},
	{
		name: 'Tools, import wizard categories step',
		url: `${ SUBMENU }licensio-kb-tools`,
		open: async ( page ) => {
			await toolsTab( 'Migration' )( page );

			const root = page.locator( '.licensio-kb-migration' );

			await root
				.locator( '.licensio-kb-migration__source input[type="radio"]' )
				.first()
				.check();

			const next = root.getByRole( 'button', {
				name: 'Next: categories',
			} );

			test.skip(
				0 === ( await next.count() ),
				'The first import source offers no categories step.'
			);
			await next.click();
			await expect( next ).toBeHidden();
		},
	},
	{
		name: 'Tools, Search Index',
		url: `${ SUBMENU }licensio-kb-tools`,
		open: toolsTab( 'Search Index' ),
	},
	{
		name: 'Tools, Data Maintenance',
		url: `${ SUBMENU }licensio-kb-tools`,
		open: toolsTab( 'Data' ),
	},
	{
		name: 'Tools, Duplicates',
		url: `${ SUBMENU }licensio-kb-tools`,
		open: toolsTab( 'Duplicates' ),
	},
	{ name: 'Settings', url: `${ SUBMENU }licensio-kb-settings` },
];

test.describe( 'App width', () => {
	test.skip(
		! enabled || ! user || ! password,
		'Set LICENSIO_KB_E2E=1 and the admin credentials to run.'
	);

	test.beforeEach( async ( { page } ) => {
		await logIn( page );
	} );

	for ( const screen of SCREENS ) {
		test( `${ screen.name } fits every width without a horizontal scroll bar`, async ( {
			page,
		} ) => {
			await page.setViewportSize( { width: 1440, height: 900 } );
			await page.goto( screen.url );
			await expect( page.locator( '#lkb-main' ) ).toBeVisible();
			await page.waitForLoadState( 'networkidle' );
			await screen.open?.( page );

			for ( const width of DESKTOP_WIDTHS ) {
				for ( const folded of [ false, true ] ) {
					const state = `${ width }px, menu ${
						folded ? 'folded' : 'expanded'
					}`;

					await page.setViewportSize( { width, height: 900 } );
					await foldAdminMenu( page, folded );

					// Charts redraw a frame after a resize, so the width settles.
					await expect
						.poll(
							async () => {
								const {
									page: doc,
									container,
									inner,
								} = await measureFrame( page );

								return doc + container + inner;
							},
							{ message: `overflow at ${ state }` }
						)
						.toBe( 0 );

					const frame = await measureFrame( page );

					expect(
						frame.left,
						`left gutter at ${ state }`
					).toBeGreaterThanOrEqual( 0 );

					// A wide monitor keeps the screen a centred column.
					if ( 1920 === width ) {
						expect(
							frame.left,
							`left margin at ${ state }`
						).toBeGreaterThanOrEqual( 40 );
						expect(
							frame.right,
							`right margin at ${ state }`
						).toBeGreaterThanOrEqual( 40 );
					}
				}
			}

			await foldAdminMenu( page, false );
			await page.setViewportSize( { width: 390, height: 844 } );

			await expect
				.poll( async () => ( await measureFrame( page ) ).page, {
					message: 'page overflow at 390px',
				} )
				.toBe( 0 );
		} );
	}
} );
