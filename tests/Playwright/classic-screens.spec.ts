/**
 * The Classic screens setting, end to end.
 *
 * With the setting on, the core article list and the category and tag
 * screens load without a redirect, the Knowledge Base menu links to them
 * again, and the Articles, Categories and Tags apps still open by URL. With
 * the setting off again, the core screens redirect to the apps.
 *
 * The setting is site-wide, so it is switched through wp-cli and put back
 * to what it was when the spec ends. Other specs expect it off: run this one
 * on its own or with one worker (as CI does), never in parallel with them.
 *
 * The suite needs a running WordPress with this plugin active, an
 * administrator account and `LICENSIO_KB_WP_CLI`, so it is skipped unless
 * `LICENSIO_KB_E2E=1` and those variables are present.
 *
 * @since 1.0.0
 */

import { expect, test, type Page } from '@playwright/test';
import { failOnUnresolvedComponents } from './helpers/ant-components';
import {
	contentManagerUrl,
	openContentManager,
	type ContentManagerApp,
} from './helpers/content-manager';
import { logIn, password, user } from './helpers/session';
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

/** The Knowledge Base menu link of each core screen, as core writes it. */
const CORE_MENU_LINKS: Record< ContentManagerApp, [ string, string ] > = {
	articles: [ 'All Articles', 'edit.php?post_type=licensio_article' ],
	categories: [
		'Categories',
		'edit-tags.php?taxonomy=licensio_category&post_type=licensio_article',
	],
	tags: [
		'Tags',
		'edit-tags.php?taxonomy=licensio_tag&post_type=licensio_article',
	],
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

/**
 * The path and query of the page's URL.
 *
 * @since 1.0.0
 *
 * @param {Page} page The browser page.
 *
 * @return {string} The path and query.
 */
function pathOf( page: Page ): string {
	const url = new URL( page.url() );

	return url.pathname + url.search;
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
		await logIn( page );
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

		for ( const app of APPS ) {
			const [ label, href ] = CORE_MENU_LINKS[ app ];

			await expect(
				menu.getByRole( 'link', { name: label, exact: true } )
			).toHaveAttribute( 'href', href );
		}

		await expect(
			menu.locator( 'a[href*="page=licensio-kb-articles"]' ),
			'the menu does not list All Articles twice'
		).toHaveCount( 0 );
		await expect(
			menu.locator(
				'a[href*="page=licensio-kb-categories"], a[href*="page=licensio-kb-tags"]'
			)
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
