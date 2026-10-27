/**
 * Load failures and empty screens across the admin apps.
 *
 * A load that fails shows its error with a Retry that recovers, and never
 * looks like an empty screen: Settings hides its save bar (so nothing unseen
 * can be reset), Analytics shows no report of zeros, and Tags and Feedback do
 * not claim to be empty. An empty Feedback inbox, and an Analytics range with
 * nothing recorded, say how their data arrives.
 *
 * The failures are made in the browser: the plugin's GET requests answer 500
 * until the route is lifted, so the site under test is never changed.
 *
 * The suite needs a running WordPress with this plugin active and an
 * administrator account, so it is skipped unless `LICENSIO_KB_E2E=1` and the
 * two credential variables are present.
 *
 * @since 1.0.0
 */

import { expect, test, type Page } from '@playwright/test';
import { openDashboard, password, user } from './helpers/session';

const enabled = process.env.LICENSIO_KB_E2E === '1';

const SUBMENU = '/wp-admin/edit.php?post_type=licensio_article&page=';

/**
 * Make every GET to the plugin's REST routes matching a path answer 500.
 *
 * @since 1.0.0
 *
 * @param {Page}   page The browser page.
 * @param {string} path The part of the route to fail, e.g. `settings`.
 *
 * @return {Promise<Function>} Lifts the failure again.
 */
async function failGets(
	page: Page,
	path: string
): Promise< () => Promise< void > > {
	const matches = ( url: URL ): boolean =>
		decodeURIComponent( url.toString() ).includes(
			`licensio-kb/v1/${ path }`
		);

	await page.route( matches, ( route ) =>
		'GET' === route.request().method()
			? route.fulfill( {
					status: 500,
					contentType: 'application/json',
					body: JSON.stringify( {
						code: 'e2e_failure',
						message: 'The server could not answer.',
						data: { status: 500 },
					} ),
				} )
			: route.continue()
	);

	return () => page.unroute( matches );
}

test.describe( 'App load failures and empty screens', () => {
	test.skip(
		! enabled || ! user || ! password,
		'Set LICENSIO_KB_E2E=1 and the admin credentials to run.'
	);

	test.beforeEach( async ( { page } ) => {
		await openDashboard( page );
	} );

	test( 'Settings hides its save bar until a failed load is retried', async ( {
		page,
	} ) => {
		const lift = await failGets( page, 'settings' );

		await page.goto( `${ SUBMENU }licensio-kb-settings` );

		const alert = page.locator( '.ant-alert-error' );

		await expect( alert ).toContainText( 'The server could not answer.' );
		await expect(
			page.getByRole( 'button', { name: 'Reset all' } )
		).toHaveCount( 0 );
		await expect(
			page.getByRole( 'button', { name: 'Save changes' } )
		).toHaveCount( 0 );

		await lift();
		await alert.getByRole( 'button', { name: 'Retry' } ).click();
		await expect( alert ).toHaveCount( 0 );
		await expect(
			page.getByRole( 'button', { name: 'Reset all' } )
		).toBeVisible();
		await expect(
			page.getByRole( 'navigation', { name: 'Settings sections' } )
		).toBeVisible();
	} );

	test( 'Analytics shows no report of zeros under a failed load', async ( {
		page,
	} ) => {
		const lift = await failGets( page, 'analytics' );

		await page.goto( `${ SUBMENU }licensio-kb-analytics` );

		const alert = page.locator( '.ant-alert-error' );

		await expect( alert ).toBeVisible();
		await expect( page.locator( '.ant-statistic' ) ).toHaveCount( 0 );

		await lift();
		await alert.getByRole( 'button', { name: 'Retry' } ).click();
		await expect( alert ).toHaveCount( 0 );
		await expect( page.locator( '.ant-statistic' ) ).toHaveCount( 4 );
	} );

	test( 'a range with nothing recorded says how analytics arrive', async ( {
		page,
	} ) => {
		await page.goto( `${ SUBMENU }licensio-kb-analytics` );
		await expect( page.locator( '.ant-statistic' ) ).toHaveCount( 4 );

		await page
			.getByRole( 'textbox', { name: 'From', exact: true } )
			.fill( '2001-01-01' );
		await page
			.getByRole( 'textbox', { name: 'To', exact: true } )
			.fill( '2001-01-31' );
		await page.getByRole( 'button', { name: 'Apply' } ).click();
		await expect( page.locator( '.ant-alert-info' ) ).toContainText(
			'Nothing was recorded in this range yet.'
		);
	} );

	test( 'Feedback retries a failed load and explains an empty inbox', async ( {
		page,
	} ) => {
		const lift = await failGets( page, 'feedback' );

		await page.goto( `${ SUBMENU }licensio-kb-feedback` );

		const alert = page.locator( '.ant-alert-error' );

		await expect( alert ).toBeVisible();
		await expect( page.locator( '.ant-table' ) ).toContainText(
			'The feedback could not be loaded.'
		);
		await expect( page.locator( '.ant-table' ) ).not.toContainText(
			'No feedback yet.'
		);

		await lift();

		// An empty inbox, answered by the browser, so the site keeps its rows.
		await page.route(
			( url ) =>
				decodeURIComponent( url.toString() ).includes(
					'licensio-kb/v1/feedback'
				),
			( route ) =>
				route.fulfill( {
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify( {
						items: [],
						counts: {},
						total: 0,
						pages: 0,
					} ),
				} )
		);
		await alert.getByRole( 'button', { name: 'Retry' } ).click();
		await expect( alert ).toHaveCount( 0 );
		await expect( page.locator( '.ant-table' ) ).toContainText(
			'No feedback yet.'
		);
		await expect(
			page.getByRole( 'link', { name: 'Feedback settings' } )
		).toHaveAttribute( 'href', /licensio-kb-settings/ );

		// Filtered to one article, an empty list is a filter that matched
		// nothing, not an empty inbox.
		await page.goto( `${ SUBMENU }licensio-kb-feedback&article_id=999999` );
		await expect( page.locator( '.ant-table' ) ).toContainText(
			'No feedback matches these filters.'
		);
		await expect( page.locator( '.ant-table' ) ).not.toContainText(
			'No feedback yet.'
		);
	} );

	test( 'Tags does not call a failed load an empty list', async ( {
		page,
	} ) => {
		const lift = await failGets( page, 'tags' );

		await page.goto( `${ SUBMENU }licensio-kb-tags` );

		const alert = page.locator( '.ant-alert-error' );

		await expect( alert ).toBeVisible();
		await expect( page.locator( '.ant-table' ) ).toContainText(
			'The tags could not be loaded.'
		);

		await lift();
		await alert.getByRole( 'button', { name: 'Retry' } ).click();
		await expect( alert ).toHaveCount( 0 );
		await expect( page.locator( '.ant-table-row' ).first() ).toBeVisible();
	} );
} );
