/**
 * The Classic screens setting, end to end.
 *
 * With the setting on, the core article list and the category and tag
 * screens load without a redirect, the Knowledge Base menu links to them
 * again, and the Articles, Categories and Tags apps still open by URL. With
 * the setting off again, the core screens redirect to the apps.
 *
 * The setting is site-wide, so it is switched through wp-cli and put back
 * to what it was when the spec ends. Other specs expect it off, so this one
 * is in the `site-wide` project of playwright.config.ts, which runs after
 * every other spec. To run it on its own, add --no-deps.
 *
 * The suite needs a running WordPress with this plugin active, an
 * administrator account and `LICENSIO_KB_WP_CLI`, so it is skipped unless
 * `LICENSIO_KB_E2E=1` and those variables are present.
 *
 * @since 1.0.0
 */

import { expect, test } from '@playwright/test';
import { failOnUnresolvedComponents } from './helpers/ant-components';
import {
	CONTENT_MANAGER_SLUGS,
	contentManagerUrl,
	openContentManager,
	pathOf,
	type ContentManagerApp,
} from './helpers/content-manager';
import { openDashboard, password, user } from './helpers/session';
import { wpCli, wpEval } from './helpers/wp-cli';

failOnUnresolvedComponents();

const enabled = process.env.LICENSIO_KB_E2E === '1';

test.skip(
	! enabled || ! user || ! password || ! wpCli,
	'Set LICENSIO_KB_E2E=1, the admin credentials and LICENSIO_KB_WP_CLI to run.'
);

test.describe.configure( { mode: 'serial' } );

/** Each core screen, by the app that replaces it. */
const CORE_SCREENS: Record< ContentManagerApp, string > = {
	articles: '/wp-admin/edit.php?post_type=licensio_article',
	categories:
		'/wp-admin/edit-tags.php?taxonomy=licensio_category&post_type=licensio_article',
	tags: '/wp-admin/edit-tags.php?taxonomy=licensio_tag&post_type=licensio_article',
};

/** The Knowledge Base menu label of each core screen. */
const MENU_LABELS: Record< ContentManagerApp, string > = {
	articles: 'All Articles',
	categories: 'Categories',
	tags: 'Tags',
};

const APPS = Object.keys( CORE_SCREENS ) as ContentManagerApp[];

/**
 * Turn the Classic screens setting on or off.
 *
 * @since 1.0.0
 *
 * @param {boolean} on True to use the classic screens.
 *
 * @return {void}
 */
function setClassicScreens( on: boolean ): void {
	wpEval(
		`licensio_kb()->settings()->set( 'classic_screens', ${
			on ? 'true' : 'false'
		} );`
	);
}

test.describe( 'Classic screens setting', () => {
	let original = false;

	test.beforeAll( () => {
		original =
			'1' ===
			wpEval(
				"echo licensio_kb()->settings()->get( 'classic_screens' ) ? '1' : '0';"
			);
	} );

	test.afterAll( () => {
		setClassicScreens( original );
	} );

	test.beforeEach( async ( { page } ) => {
		await openDashboard( page );
	} );

	test( 'on: the core screens and menu come back and the apps stay reachable', async ( {
		page,
	} ) => {
		setClassicScreens( true );

		for ( const app of APPS ) {
			await page.goto( CORE_SCREENS[ app ] );
			expect( pathOf( page ), `${ app } is not redirected` ).toBe(
				CORE_SCREENS[ app ]
			);
			await expect( page.locator( 'table.wp-list-table' ) ).toBeVisible();
		}

		const menu = page.locator( '#menu-posts-licensio_article .wp-submenu' );

		// Core writes the menu links relative to wp-admin.
		for ( const app of APPS ) {
			await expect(
				menu.getByRole( 'link', {
					name: MENU_LABELS[ app ],
					exact: true,
				} )
			).toHaveAttribute(
				'href',
				CORE_SCREENS[ app ].replace( '/wp-admin/', '' )
			);
		}

		await expect(
			menu.locator(
				APPS.map(
					( app ) =>
						`a[href*="page=${ CONTENT_MANAGER_SLUGS[ app ] }"]`
				).join( ', ' )
			),
			'the menu links to no app, so nothing is listed twice'
		).toHaveCount( 0 );

		for ( const app of APPS ) {
			await openContentManager( page, app );
			expect( pathOf( page ) ).toBe( contentManagerUrl( app ) );
		}
	} );

	test( 'off again: the core screens redirect to the apps', async ( {
		page,
	} ) => {
		setClassicScreens( true );
		setClassicScreens( false );

		for ( const app of APPS ) {
			await page.goto( CORE_SCREENS[ app ] );
			expect( pathOf( page ), `${ app } redirects` ).toBe(
				contentManagerUrl( app )
			);
		}

		await expect(
			page
				.locator( '#menu-posts-licensio_article .wp-submenu' )
				.getByRole( 'link', { name: 'All Articles', exact: true } )
		).toHaveAttribute(
			'href',
			'edit.php?post_type=licensio_article&page=licensio-kb-articles'
		);
	} );
} );
