/**
 * Authors, contributors and editors in the Articles app and the block editor.
 *
 * Authors and contributors write their own articles, following the core
 * post model: an author publishes and deletes their own, a contributor only
 * drafts and submits them for review. Neither sees anyone else's articles,
 * nor any management screen or route. Editors keep full access.
 *
 * Users `lkb_e2e_author`, `lkb_e2e_contributor` and `lkb_e2e_editor` are
 * created (or their password reset) through wp-cli by the setup project,
 * which also saves a session for each, so the suite needs
 * `LICENSIO_KB_WP_CLI` as well as `LICENSIO_KB_E2E=1`. The articles it makes
 * are deleted afterwards.
 *
 * @since 1.0.0
 */

import { readFileSync } from 'node:fs';
import { expect, test, type Page } from '@playwright/test';
import { openContentManager } from './helpers/content-manager';
import { switchOffWelcomeGuides } from './helpers/editor';
import {
	ROLES,
	SIGNED_OUT,
	openDashboard,
	sessionFile,
	type Role,
} from './helpers/session';
import { wpCli, wpEval } from './helpers/wp-cli';

const enabled = process.env.LICENSIO_KB_E2E === '1' && '' !== wpCli;

/** Makes this run's titles unique. */
const suffix = Date.now().toString( 36 );

/** User ID by role, filled before the tests. */
const users: Record< Role, number > = {
	author: 0,
	contributor: 0,
	editor: 0,
};

/** The articles the suite made, by name. */
const articles: Record< string, { id: number; title: string } > = {};

/** An existing category the writers may file articles under. */
let category = 0;

/**
 * Create an article through wp-cli.
 *
 * @since 1.0.0
 *
 * @param {string} name   The key it is stored under.
 * @param {number} author The author.
 * @param {string} status The status.
 *
 * @return {void}
 */
function makeArticle( name: string, author: number, status: string ): void {
	const title = `Roles ${ name } ${ suffix }`;
	const id = Number(
		wpEval(
			`echo wp_insert_post( array( 'post_type' => 'licensio_article', 'post_title' => '${ title }', 'post_status' => '${ status }', 'post_author' => ${ author } ) );`
		)
	);

	articles[ name ] = { id, title };
}

/**
 * Switch to one of the test users.
 *
 * Swaps the page's cookies for the session the setup project saved for the
 * user, rather than signing in again.
 *
 * @since 1.0.0
 * @since 1.0.0 Uses the saved session instead of the login form.
 *
 * @param {Page} page The page.
 * @param {Role} role The user's role.
 *
 * @return {Promise<void>} Resolves once the admin bar shows.
 */
async function logInAs( page: Page, role: Role ): Promise< void > {
	const saved = JSON.parse( readFileSync( sessionFile( role ), 'utf8' ) );

	await page.context().clearCookies();
	await page.context().addCookies( saved.cookies );
	await openDashboard( page );
}

/**
 * Call a plugin REST route with the Articles app's nonce.
 *
 * A route starting with `/` is taken from the REST root instead, such as
 * `/wp/v2/licensio_tags`.
 *
 * @since 1.0.0
 *
 * @param {Page}   page   The page, on the Articles app.
 * @param {string} method The HTTP method.
 * @param {string} route  The route under `licensio-kb/v1`, or from the REST root.
 * @param {Object} body   The JSON body, for writes.
 *
 * @return {Promise<number>} The response status.
 */
async function callRoute(
	page: Page,
	method: string,
	route: string,
	body?: Record< string, unknown >
): Promise< number > {
	return page.evaluate(
		async ( { verb, path, data } ) => {
			const config = (
				window as unknown as {
					licensioKbArticles: { root: string; nonce: string };
				}
			 ).licensioKbArticles;
			const [ routePath, query ] = path.split( '?' );
			const url = new URL( config.root, window.location.href );
			const base = url.searchParams.get( 'rest_route' );
			const fromRoot = routePath.startsWith( '/' );
			const join = ( prefix: string ) =>
				fromRoot
					? prefix.replace( /\/?licensio-kb\/v1\/?$/, '' ) + routePath
					: prefix.replace( /\/?$/, '/' ) + routePath;

			// Plain permalinks put the route in `rest_route`.
			if ( null !== base ) {
				url.searchParams.set( 'rest_route', join( base ) );
			} else {
				url.pathname = join( url.pathname );
			}

			new URLSearchParams( query ?? '' ).forEach( ( value, key ) =>
				url.searchParams.set( key, value )
			);

			const response = await fetch( url.toString(), {
				method: verb,
				headers: {
					'X-WP-Nonce': config.nonce,
					'Content-Type': 'application/json',
				},
				credentials: 'same-origin',
				body: data ? JSON.stringify( data ) : undefined,
			} );

			return response.status;
		},
		{ verb: method, path: route, data: body }
	);
}

/**
 * The labels of the Knowledge Base menu's items.
 *
 * @since 1.0.0
 *
 * @param {Page} page The page.
 *
 * @return {Promise<string[]>} The labels, in order.
 */
async function menuItems( page: Page ): Promise< string[] > {
	return page
		.locator( '#menu-posts-licensio_article .wp-submenu li a' )
		.allTextContents();
}

/**
 * Find an article's row in the Articles app by searching its title.
 *
 * @since 1.0.0
 *
 * @param {Page}   page  The page.
 * @param {string} title The title.
 *
 * @return {Promise<import('@playwright/test').Locator>} The row locator.
 */
async function searchRow( page: Page, title: string ) {
	const search = page.getByLabel( 'Search articles by title' );

	await search.fill( title );
	await search.press( 'Enter' );
	// The search runs after a short pause; wait for it to reach the address.
	await expect
		.poll( () => new URL( page.url() ).searchParams.get( 'search' ) )
		.toBe( title );
	await expect( page.locator( '.ant-spin-spinning' ) ).toHaveCount( 0 );

	return page.locator( '.ant-table-row', { hasText: title } );
}

/**
 * Write a title in the block editor and wait for it to be ready.
 *
 * @since 1.0.0
 *
 * @param {Page}   page  The page.
 * @param {string} title The title.
 *
 * @return {Promise<void>} Resolves once typed.
 */
async function writeNewArticle( page: Page, title: string ): Promise< void > {
	await page.goto( '/wp-admin/post-new.php?post_type=licensio_article' );
	await switchOffWelcomeGuides( page, 'core/edit-post', [ 'welcomeGuide' ] );

	const canvas = page.frameLocator( 'iframe[name="editor-canvas"]' );

	await canvas.getByRole( 'textbox', { name: 'Add title' } ).fill( title );
}

/**
 * The status of the article with a title, through wp-cli.
 *
 * @since 1.0.0
 *
 * @param {string} title The title.
 *
 * @return {string} The status, or an empty string when there is none.
 */
function statusOf( title: string ): string {
	return wpEval(
		`$found = get_posts( array( 'post_type' => 'licensio_article', 'post_status' => array_keys( get_post_stati() ), 'title' => '${ title }', 'fields' => 'ids' ) ); echo $found ? get_post_status( $found[0] ) : '';`
	);
}

/**
 * How many articles of each status a user wrote, through wp-cli.
 *
 * @since 1.0.0
 *
 * @param {number} author The user.
 *
 * @return {Record<string, number>} Status to count.
 */
function ownCounts( author: number ): Record< string, number > {
	return JSON.parse(
		wpEval(
			`$counts = array(); foreach ( get_posts( array( 'post_type' => 'licensio_article', 'post_status' => array_keys( get_post_stati() ), 'author' => ${ author }, 'posts_per_page' => -1 ) ) as $post ) { $counts[ $post->post_status ] = ( isset( $counts[ $post->post_status ] ) ? $counts[ $post->post_status ] : 0 ) + 1; } echo wp_json_encode( (object) $counts );`
		)
	) as Record< string, number >;
}

/**
 * The management screens and routes stay closed to article writers.
 *
 * @since 1.0.0
 *
 * @param {Page} page The page, on the Articles app.
 *
 * @return {Promise<void>} Resolves once checked; leaves the Articles app.
 */
async function expectManagementClosed( page: Page ): Promise< void > {
	expect( await callRoute( page, 'GET', 'feedback' ) ).toBe( 403 );
	expect( await callRoute( page, 'GET', 'analytics/overview' ) ).toBe( 403 );

	for ( const slug of [
		'licensio-kb-tools',
		'licensio-kb-analytics',
		'licensio-kb-feedback',
	] ) {
		const response = await page.goto(
			`/wp-admin/admin.php?page=${ slug }`
		);

		expect( response?.status(), slug ).toBe( 403 );
	}
}

/**
 * The status pills count only the user's own articles.
 *
 * @since 1.0.0
 *
 * @param {Page}   page   The page, on the Articles app.
 * @param {number} author The user.
 *
 * @return {Promise<void>} Resolves once checked.
 */
async function expectOwnPillCounts(
	page: Page,
	author: number
): Promise< void > {
	const counts = ownCounts( author );
	const pills = page.getByRole( 'group', { name: 'Filter by status' } );

	await expect( pills ).toContainText(
		`Published (${ counts.publish ?? 0 })`
	);
	await expect( pills ).toContainText( `Drafts (${ counts.draft ?? 0 })` );
	await expect( pills ).toContainText( `Trash (${ counts.trash ?? 0 })` );
}

test.describe( 'Article writers by role', () => {
	test.skip(
		! enabled,
		'Set LICENSIO_KB_E2E=1 and LICENSIO_KB_WP_CLI to run.'
	);

	test.describe.configure( { mode: 'serial' } );

	// Each test switches to a test user's saved session itself.
	test.use( { storageState: SIGNED_OUT } );

	test.beforeAll( () => {
		// The setup project created the users; their passwords are not reset
		// here, since that would sign out the sessions it saved.
		const ids = JSON.parse(
			wpEval( `
				$ids = array();
				foreach ( array( '${ ROLES.join( "', '" ) }' ) as $role ) {
					$user         = get_user_by( 'login', 'lkb_e2e_' . $role );
					$ids[ $role ] = $user ? (int) $user->ID : 0;
				}
				echo wp_json_encode( $ids );
			` )
		) as Record< Role, number >;

		Object.assign( users, ids );

		makeArticle( 'author own', users.author, 'publish' );
		makeArticle( 'contributor own', users.contributor, 'draft' );
		makeArticle( 'contributor published', users.contributor, 'publish' );
		makeArticle( 'someone else', 1, 'publish' );

		category = Number(
			wpEval(
				`$term = wp_insert_term( 'Roles category ${ suffix }', 'licensio_category' ); echo is_wp_error( $term ) ? 0 : $term['term_id'];`
			)
		);
	} );

	test.afterAll( () => {
		wpEval( `
			foreach ( get_posts( array( 'post_type' => 'licensio_article', 'post_status' => array_keys( get_post_stati() ), 'posts_per_page' => -1, 's' => '${ suffix }', 'fields' => 'ids' ) ) as $id ) {
				wp_delete_post( $id, true );
			}
			foreach ( array( 'licensio_tag', 'licensio_category' ) as $taxonomy ) {
				foreach ( get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false, 'search' => '${ suffix }', 'fields' => 'ids' ) ) as $term_id ) {
					wp_delete_term( $term_id, $taxonomy );
				}
			}
		` );
	} );

	test( 'an author writes, publishes and edits only their own articles', async ( {
		page,
	} ) => {
		test.setTimeout( 90_000 );

		await logInAs( page, 'author' );
		await page.goto( '/wp-admin/' );

		expect( await menuItems( page ) ).toEqual( [
			'All Articles',
			'Add New Article',
		] );

		const app = await openContentManager( page, 'articles' );

		await expect(
			app.getByRole( 'link', { name: /settings/i } )
		).toHaveCount( 0 );

		await expect(
			await searchRow( page, articles[ 'someone else' ].title )
		).toHaveCount( 0 );

		await expectOwnPillCounts( page, users.author );

		const row = await searchRow( page, articles[ 'author own' ].title );

		await expect( row ).toBeVisible();
		await expect(
			row.getByRole( 'button', { name: 'Trash' } )
		).toBeAttached();

		// Quick edit: publishing is offered, choosing an author is not.
		await row.getByRole( 'button', { name: 'Quick edit' } ).click();

		const form = app.locator( '.lkb-quickedit' );

		await expect( form.getByLabel( 'Author' ) ).toHaveCount( 0 );
		await expect( form.getByText( 'Visibility' ) ).toBeVisible();
		await form
			.getByLabel( 'Title' )
			.fill( `Roles author edited ${ suffix }` );
		await form.getByRole( 'button', { name: 'Save' } ).click();
		await expect
			.poll( () =>
				wpEval(
					`echo get_the_title( ${ articles[ 'author own' ].id } );`
				)
			)
			.toBe( `Roles author edited ${ suffix }` );
		articles[ 'author own' ].title = `Roles author edited ${ suffix }`;

		// Someone else's article and the management routes are closed.
		expect(
			await callRoute(
				page,
				'PATCH',
				`articles/${ articles[ 'someone else' ].id }`,
				{ title: 'Taken over' }
			)
		).toBe( 403 );
		expect( await callRoute( page, 'GET', 'categories' ) ).toBe( 403 );
		expect( await callRoute( page, 'GET', 'tags' ) ).toBe( 403 );
		expect( await callRoute( page, 'GET', 'settings' ) ).toBe( 403 );
		expect( await callRoute( page, 'GET', 'authors' ) ).toBe( 403 );
		expect(
			await callRoute( page, 'GET', 'terms?taxonomy=licensio_tag' )
		).toBe( 200 );

		// Existing terms only: core's term routes refuse to create any.
		expect(
			await callRoute( page, 'POST', '/wp/v2/licensio_tags', {
				name: `Roles author tag ${ suffix }`,
			} )
		).toBe( 403 );
		expect(
			await callRoute( page, 'POST', '/wp/v2/licensio_categories', {
				name: `Roles author category ${ suffix }`,
			} )
		).toBe( 403 );

		// Filing an own article under an existing category.
		expect(
			await callRoute(
				page,
				'PATCH',
				`articles/${ articles[ 'author own' ].id }`,
				{ categories: [ category ] }
			)
		).toBe( 200 );
		expect(
			wpEval(
				`echo has_term( ${ category }, 'licensio_category', ${ articles[ 'author own' ].id } ) ? 'yes' : 'no';`
			)
		).toBe( 'yes' );

		// An author trashes their own published article.
		expect(
			await callRoute( page, 'POST', 'articles/bulk', {
				ids: [ articles[ 'author own' ].id ],
				action: 'trash',
				args: {},
			} )
		).toBe( 200 );
		expect( statusOf( articles[ 'author own' ].title ) ).toBe( 'trash' );

		await expectManagementClosed( page );

		const categories = await page.goto(
			'/wp-admin/admin.php?page=licensio-kb-categories'
		);

		expect( categories?.status() ).toBe( 403 );

		// Writing and publishing in the block editor.
		const title = `Roles author new ${ suffix }`;

		await writeNewArticle( page, title );
		await page
			.getByRole( 'region', { name: 'Editor top bar' } )
			.getByRole( 'button', { name: 'Publish', exact: true } )
			.click();
		await page
			.getByRole( 'region', { name: 'Editor publish' } )
			.getByRole( 'button', { name: 'Publish', exact: true } )
			.click();
		await expect.poll( () => statusOf( title ) ).toBe( 'publish' );

		await openContentManager( page, 'articles' );
		await expect( await searchRow( page, title ) ).toBeVisible();
	} );

	test( 'a contributor drafts and submits their own articles but cannot publish', async ( {
		page,
	} ) => {
		test.setTimeout( 90_000 );

		await logInAs( page, 'contributor' );
		await page.goto( '/wp-admin/' );

		expect( await menuItems( page ) ).toEqual( [
			'All Articles',
			'Add New Article',
		] );

		const app = await openContentManager( page, 'articles' );

		await expect(
			await searchRow( page, articles[ 'someone else' ].title )
		).toHaveCount( 0 );

		await expectOwnPillCounts( page, users.contributor );

		// A published article of their own cannot be trashed.
		const published = await searchRow(
			page,
			articles[ 'contributor published' ].title
		);

		await expect( published ).toBeVisible();
		await expect(
			published.getByRole( 'button', { name: 'Trash' } )
		).toHaveCount( 0 );
		expect(
			await callRoute( page, 'POST', 'articles/bulk', {
				ids: [ articles[ 'contributor published' ].id ],
				action: 'trash',
				args: {},
			} )
		).toBe( 200 );
		expect( statusOf( articles[ 'contributor published' ].title ) ).toBe(
			'publish'
		);

		// Filing an own draft under an existing category.
		expect(
			await callRoute(
				page,
				'PATCH',
				`articles/${ articles[ 'contributor own' ].id }`,
				{ categories: [ category ] }
			)
		).toBe( 200 );
		expect(
			wpEval(
				`echo has_term( ${ category }, 'licensio_category', ${ articles[ 'contributor own' ].id } ) ? 'yes' : 'no';`
			)
		).toBe( 'yes' );

		const row = await searchRow(
			page,
			articles[ 'contributor own' ].title
		);

		await expect( row ).toBeVisible();

		await row.getByRole( 'button', { name: 'Quick edit' } ).click();

		const form = app.locator( '.lkb-quickedit' );

		await expect( form.getByLabel( 'Author' ) ).toHaveCount( 0 );
		await expect( form.getByText( 'Visibility' ) ).toHaveCount( 0 );
		await form.getByLabel( 'Status' ).click();
		await expect(
			page.locator( '.ant-select-item-option', { hasText: 'Published' } )
		).toHaveCount( 0 );
		await page
			.locator( '.ant-select-item-option', { hasText: 'Pending review' } )
			.click();
		await form.getByRole( 'button', { name: 'Save' } ).click();
		await expect
			.poll( () => statusOf( articles[ 'contributor own' ].title ) )
			.toBe( 'pending' );

		// Bulk actions offer no author change and no publishing.
		await openContentManager( page, 'articles' );

		const bulkRow = await searchRow(
			page,
			articles[ 'contributor own' ].title
		);

		await bulkRow.locator( '.ant-checkbox-input' ).check();
		await page.getByLabel( 'Bulk action' ).click();
		await expect(
			page.locator( '.ant-select-item-option', {
				hasText: 'Change author',
			} )
		).toHaveCount( 0 );
		await page.keyboard.press( 'Escape' );

		// Publishing through the route is refused too.
		expect(
			await callRoute(
				page,
				'PATCH',
				`articles/${ articles[ 'contributor own' ].id }`,
				{ status: 'publish' }
			)
		).toBe( 403 );
		expect(
			await callRoute(
				page,
				'PATCH',
				`articles/${ articles[ 'someone else' ].id }`,
				{ title: 'Taken over' }
			)
		).toBe( 403 );
		expect( await callRoute( page, 'GET', 'categories' ) ).toBe( 403 );
		expect(
			await callRoute( page, 'POST', '/wp/v2/licensio_tags', {
				name: `Roles contributor tag ${ suffix }`,
			} )
		).toBe( 403 );
		expect(
			await callRoute( page, 'POST', '/wp/v2/licensio_categories', {
				name: `Roles contributor category ${ suffix }`,
			} )
		).toBe( 403 );

		await expectManagementClosed( page );

		// A draft from the block editor lands in the app.
		const title = `Roles contributor new ${ suffix }`;

		await writeNewArticle( page, title );
		await page
			.getByRole( 'region', { name: 'Editor top bar' } )
			.getByRole( 'button', { name: 'Save draft' } )
			.click();
		await expect.poll( () => statusOf( title ) ).toBe( 'draft' );

		await openContentManager( page, 'articles' );
		await expect( await searchRow( page, title ) ).toBeVisible();
	} );

	test( 'an editor sees and manages everyone’s articles', async ( {
		page,
	} ) => {
		await logInAs( page, 'editor' );
		await page.goto( '/wp-admin/' );

		const items = await menuItems( page );

		expect( items ).toContain( 'Categories' );
		expect( items ).toContain( 'Tags' );

		const app = await openContentManager( page, 'articles' );

		await expect(
			await searchRow( page, articles[ 'someone else' ].title )
		).toBeVisible();

		await app.getByRole( 'button', { name: 'Filters' } ).click();
		await expect( page.getByLabel( 'Filter by author' ) ).toBeVisible();

		expect( await callRoute( page, 'GET', 'authors' ) ).toBe( 200 );
		expect( await callRoute( page, 'GET', 'categories' ) ).toBe( 200 );
		expect(
			await callRoute( page, 'POST', '/wp/v2/licensio_tags', {
				name: `Roles editor tag ${ suffix }`,
			} )
		).toBe( 201 );
	} );
} );
