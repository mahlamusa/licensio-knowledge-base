/**
 * Admin notices sit above the admin apps, never inside them.
 *
 * WordPress moves every admin notice to just after `.wp-header-end`, or,
 * without one, after the first `.wrap h1`. On an app screen that heading is
 * inside the app's own header once it has mounted, so a notice from this
 * plugin or any other would land between the page title and its buttons.
 * The page shell prints the marker before the mount element; this checks a
 * notice ends up there on every app screen, and the app still renders.
 *
 * The notice is added to the page's HTML in the browser, so the site under
 * test is never changed.
 *
 * The suite needs a running WordPress with this plugin active and an
 * administrator account, so it is skipped unless `LICENSIO_KB_E2E=1` and the
 * two credential variables are present.
 *
 * @since 1.0.0
 */

import { expect, test } from '@playwright/test';
import { contentManagerUrl } from './helpers/content-manager';
import { logIn, password, user } from './helpers/session';

const enabled = process.env.LICENSIO_KB_E2E === '1';

const SUBMENU = '/wp-admin/edit.php?post_type=licensio_article&page=';

/** The shell's opening tag, which the notice is printed in front of. */
const WRAP = '<div class="wrap lkb-wrap">';

/** A notice as WordPress prints one, before the screen's own markup. */
const NOTICE =
	'<div class="notice notice-info lkb-e2e-notice"><p>E2E notice</p></div>';

const SCREENS: Array< { name: string; url: string } > = [
	{ name: 'Articles', url: contentManagerUrl( 'articles' ) },
	{ name: 'Categories', url: contentManagerUrl( 'categories' ) },
	{ name: 'Tags', url: contentManagerUrl( 'tags' ) },
	{ name: 'Feedback', url: `${ SUBMENU }licensio-kb-feedback` },
	{ name: 'Analytics', url: `${ SUBMENU }licensio-kb-analytics` },
	{ name: 'Tools', url: `${ SUBMENU }licensio-kb-tools` },
	{ name: 'Settings', url: `${ SUBMENU }licensio-kb-settings` },
];

test.describe( 'Admin notices on the app screens', () => {
	test.skip(
		! enabled || ! user || ! password,
		'Set LICENSIO_KB_E2E=1 and the admin credentials to run.'
	);

	test.beforeEach( async ( { page } ) => {
		await logIn( page );

		await page.route( '**/wp-admin/**', async ( route ) => {
			if ( 'document' !== route.request().resourceType() ) {
				await route.continue();
				return;
			}

			const response = await route.fetch();
			const body = ( await response.text() ).replace(
				WRAP,
				NOTICE + WRAP
			);

			await route.fulfill( { response, body } );
		} );
	} );

	for ( const screen of SCREENS ) {
		test( `${ screen.name } shows a notice above the app`, async ( {
			page,
		} ) => {
			await page.goto( screen.url );

			const head = page.locator( '.lkb-page__head' ).first();
			const notice = page.locator( '.lkb-e2e-notice' );

			await expect( head ).toBeVisible();
			await expect( notice ).toBeVisible();
			await expect(
				page.locator( '.lkb-page .lkb-e2e-notice' )
			).toHaveCount( 0 );

			// Core moved it to just after the marker, in front of the mount element.
			expect(
				await notice.evaluate( ( el ) => [
					el.previousElementSibling?.className ?? '',
					el.parentElement?.classList.contains( 'lkb-wrap' ) ?? false,
				] )
			).toEqual( [ 'wp-header-end', true ] );

			const noticeBox = await notice.boundingBox();
			const headBox = await head.boundingBox();

			expect( noticeBox && headBox && noticeBox.y < headBox.y ).toBe(
				true
			);
		} );
	}
} );
