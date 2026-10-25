/**
 * The Articles app's bulk actions, end to end.
 *
 * Covers the flows only a real WordPress can answer: a bulk trash over more
 * than one batch runs in batches of 100 with progress, Undo puts every
 * article back, an article another user is editing is skipped and reported,
 * Cancel on the bulk Popconfirm sends nothing, a slow "Select all
 * matching" answer is dropped once the user has moved to another view,
 * unticking a row after "Select all matching" keeps the rest selected, and
 * Replace with no terms chosen is sent as a request to clear them.
 *
 * The spec seeds its own data with wp-cli and removes it afterwards: 250
 * articles whose titles share a unique word, an editor account, and an edit
 * lock held by that editor on one of the articles. So besides
 * `LICENSIO_KB_E2E=1` and the admin credentials it needs
 * `LICENSIO_KB_WP_CLI`, the command that runs wp-cli against the same site,
 * for example `echowp --dir <dir> wp <site> --` or `wp --path=<wp root>`.
 * Without them it is skipped.
 *
 * @since 1.0.0
 */

import { execFileSync } from 'node:child_process';
import { expect, test, type Page, type Request } from '@playwright/test';
import {
	cancelPopconfirm,
	confirmPopconfirm,
	contentManagerUrl,
} from './helpers/content-manager';
import { logIn, password, user } from './helpers/session';

const enabled = process.env.LICENSIO_KB_E2E === '1';
const wpCli = process.env.LICENSIO_KB_WP_CLI ?? '';

/** How many articles to seed: three batches. */
const ARTICLES = 250;

/** A word only the seeded titles contain, so a search shows only them. */
const WORD = `bulke2e${ Date.now() }`;

/**
 * Run PHP through wp-cli against the site under test.
 *
 * @since 1.0.0
 *
 * @param {string} php The PHP to run, without the opening tag.
 *
 * @return {string} What it printed.
 */
function wpEval( php: string ): string {
	// The PHP goes in as a positional argument, so the shell never expands it.
	return execFileSync( 'sh', [ '-c', `${ wpCli } eval "$0"`, php ], {
		encoding: 'utf8',
	} ).trim();
}

/**
 * Collect the app's requests to one REST route, other than GET.
 *
 * @since 1.0.0
 *
 * @param {Page}   page  The browser page.
 * @param {string} route The route, e.g. `articles/bulk`; empty for every articles route.
 *
 * @return {Request[]} The list, filled as requests go out.
 */
function recordWrites( page: Page, route = '' ): Request[] {
	const writes: Request[] = [];

	page.on( 'request', ( request ) => {
		const url = decodeURIComponent( request.url() );

		if (
			request.method() !== 'GET' &&
			url.includes(
				`licensio-kb/v1/articles${ route ? '/' + route : '' }`
			)
		) {
			writes.push( request );
		}
	} );

	return writes;
}

/**
 * Open the app showing only the seeded articles.
 *
 * @since 1.0.0
 *
 * @param {Page} page The browser page.
 *
 * @return {Promise<import('@playwright/test').Locator>} The app.
 */
async function openSeeded( page: Page ) {
	await page.goto(
		contentManagerUrl( 'articles' ) +
			'&search=' +
			encodeURIComponent( WORD )
	);

	const app = page.locator( '#licensio-kb-articles' );

	await expect( app.locator( '.ant-table-row' ).first() ).toBeVisible();

	return app;
}

/**
 * Tick the header checkbox that selects the whole page.
 *
 * @since 1.0.0
 *
 * @param {import('@playwright/test').Locator} app The app.
 *
 * @return {Promise<void>} Resolves once the bulk bar is armed.
 */
async function selectPage(
	app: import('@playwright/test').Locator
): Promise< void > {
	await app.locator( '.ant-table-thead .ant-checkbox-input' ).check();
	await expect( app.locator( '.lkb-bulk.is-armed' ) ).toBeVisible();
}

/**
 * Choose an action in the bulk bar.
 *
 * @since 1.0.0
 *
 * @param {Page}                               page  The browser page.
 * @param {import('@playwright/test').Locator} app   The app.
 * @param {string}                             label The action's label.
 *
 * @return {Promise<void>} Resolves once chosen.
 */
async function chooseAction(
	page: Page,
	app: import('@playwright/test').Locator,
	label: string
): Promise< void > {
	await app.locator( '.lkb-bulk__choice' ).click();
	await page
		.locator( '.ant-select-dropdown:not(.ant-select-dropdown-hidden)' )
		.getByText( label, { exact: true } )
		.click();
}

test.describe( 'Articles bulk actions', () => {
	test.describe.configure( { mode: 'serial' } );

	test.skip(
		! enabled || '' === user || '' === password || '' === wpCli,
		'Set LICENSIO_KB_E2E=1, the admin credentials and LICENSIO_KB_WP_CLI to run.'
	);

	let lockedId = 0;

	test.beforeAll( () => {
		lockedId = Number(
			wpEval( `
				$ids = array();
				for ( $i = 1; $i <= ${ ARTICLES }; $i++ ) {
					$ids[] = wp_insert_post( array( 'post_type' => 'licensio_article', 'post_status' => 'publish', 'post_title' => '${ WORD } ' . $i ) );
				}
				$editor = get_user_by( 'login', 'bulke2e_editor' );
				$editor_id = $editor ? $editor->ID : wp_insert_user( array( 'user_login' => 'bulke2e_editor', 'user_pass' => wp_generate_password(), 'role' => 'editor' ) );
				update_post_meta( $ids[0], '_edit_lock', ( time() + HOUR_IN_SECONDS ) . ':' . $editor_id );
				echo $ids[0];
			` )
		);
	} );

	test.afterAll( () => {
		wpEval( `
			$q = new WP_Query( array( 'post_type' => 'licensio_article', 'post_status' => array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' ), 's' => '${ WORD }', 'fields' => 'ids', 'posts_per_page' => -1 ) );
			foreach ( $q->posts as $id ) { wp_delete_post( $id, true ); }
			$editor = get_user_by( 'login', 'bulke2e_editor' );
			if ( $editor ) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( $editor->ID ); }
		` );
	} );

	test.beforeEach( async ( { page } ) => {
		await logIn( page );
	} );

	test( 'Cancel on the bulk Popconfirm sends no request', async ( {
		page,
	} ) => {
		const app = await openSeeded( page );
		const writes = recordWrites( page );

		await selectPage( app );
		await chooseAction( page, app, 'Move to trash' );
		await app
			.locator( '.lkb-bulk' )
			.getByRole( 'button', { name: 'Apply' } )
			.click();
		await cancelPopconfirm( page );
		await page.waitForTimeout( 500 );

		expect( writes ).toHaveLength( 0 );
	} );

	test( 'unticking a row after Select all matching leaves the rest selected', async ( {
		page,
	} ) => {
		const app = await openSeeded( page );

		await selectPage( app );
		await app
			.getByRole( 'button', {
				name: `Select all ${ ARTICLES } matching`,
			} )
			.click();
		await expect( app.locator( '.lkb-bulk__count' ) ).toHaveText(
			`${ ARTICLES } selected`
		);

		await app
			.locator( '.ant-table-tbody .ant-checkbox-input' )
			.first()
			.uncheck();
		await expect( app.locator( '.lkb-bulk__count' ) ).toHaveText(
			`${ ARTICLES - 1 } selected`
		);
	} );

	test( 'Replace tags with none chosen removes every tag, after saying so', async ( {
		page,
	} ) => {
		const app = await openSeeded( page );
		const bulk = recordWrites( page, 'bulk' );
		const rows = await app.locator( '.ant-table-tbody .ant-table-row' ).count();

		await selectPage( app );
		await chooseAction( page, app, 'Replace tags' );

		const apply = app
			.locator( '.lkb-bulk' )
			.getByRole( 'button', { name: 'Apply' } );

		await expect( apply ).toBeEnabled();
		await apply.click();
		await expect( page.locator( '.ant-popconfirm' ).last() ).toContainText(
			`Remove every tag from ${ rows } articles? No tags are chosen to replace them.`
		);

		const answered = page.waitForResponse( ( response ) =>
			decodeURIComponent( response.url() ).includes( 'articles/bulk' )
		);

		await confirmPopconfirm( page );
		await answered;

		expect( bulk ).toHaveLength( 1 );

		const body = JSON.parse( bulk[ 0 ].postData() ?? '{}' );

		expect( body.args ).toEqual( {
			taxonomy: 'licensio_tag',
			mode: 'replace',
			term_ids: [],
		} );
	} );

	test( 'a slow Select all matching is dropped after the view changes', async ( {
		page,
	} ) => {
		const app = await openSeeded( page );
		let release: () => void = () => undefined;
		const held = new Promise< void >( ( resolve ) => {
			release = resolve;
		} );

		await page.route( /articles%2Fids|articles\/ids/, async ( route ) => {
			await held;
			await route.continue();
		} );

		const answered = page.waitForResponse( ( response ) =>
			decodeURIComponent( response.url() ).includes( 'articles/ids' )
		);

		await selectPage( app );
		await app
			.getByRole( 'button', {
				name: `Select all ${ ARTICLES } matching`,
			} )
			.click();
		await app.locator( '.lkb-pill', { hasText: 'Drafts' } ).click();
		await expect( app.locator( '.lkb-bulk.is-armed' ) ).toHaveCount( 0 );

		release();
		await answered;
		await page.waitForTimeout( 300 );

		await expect( app.locator( '.lkb-bulk.is-armed' ) ).toHaveCount( 0 );
		await expect( app.locator( '.lkb-bulk__hint' ) ).toBeVisible();
	} );

	test( 'bulk trash runs in batches, skips the locked article and Undo restores the rest', async ( {
		page,
	} ) => {
		const app = await openSeeded( page );
		const bulk = recordWrites( page, 'bulk' );
		const reverts = recordWrites( page, 'revert' );

		await selectPage( app );
		await app
			.getByRole( 'button', {
				name: `Select all ${ ARTICLES } matching`,
			} )
			.click();
		await expect( app.locator( '.lkb-bulk__count' ) ).toHaveText(
			`${ ARTICLES } selected`
		);

		await chooseAction( page, app, 'Move to trash' );
		await app
			.locator( '.lkb-bulk' )
			.getByRole( 'button', { name: 'Apply' } )
			.click();
		await expect( page.locator( '.ant-popconfirm' ).last() ).toContainText(
			`Move ${ ARTICLES } articles to the trash?`
		);
		await confirmPopconfirm( page );

		const modal = page.locator( '.ant-modal' );

		await expect( modal.locator( '.ant-progress' ) ).toBeVisible();
		await expect( modal ).toContainText(
			`${ ARTICLES } of ${ ARTICLES } processed`,
			{ timeout: 60_000 }
		);
		await expect( modal ).toContainText( `Done: ${ ARTICLES - 1 }` );
		await expect( modal ).toContainText( 'Skipped: 1' );
		await expect( modal ).toContainText( 'Being edited by someone else' );

		expect( bulk ).toHaveLength( Math.ceil( ARTICLES / 100 ) );
		bulk.forEach( ( request, index ) => {
			const body = JSON.parse( request.postData() ?? '{}' );

			expect( body.action ).toBe( 'trash' );
			expect( body.ids.length ).toBe(
				Math.min( 100, ARTICLES - index * 100 )
			);
		} );

		expect(
			Number(
				wpEval(
					`echo count( get_posts( array( 'post_type' => 'licensio_article', 'post_status' => 'trash', 's' => '${ WORD }', 'fields' => 'ids', 'posts_per_page' => -1 ) ) );`
				)
			)
		).toBe( ARTICLES - 1 );
		expect(
			wpEval( `echo get_post_status( ${ lockedId } );` ),
			'The locked article is untouched.'
		).toBe( 'publish' );

		await expect(
			page.locator( '.ant-notification-notice' ),
			'The Undo countdown waits for the modal to close.'
		).toHaveCount( 0 );
		await modal.locator( '.ant-modal-close' ).click();

		const toast = page.locator( '.ant-notification-notice' ).last();

		await expect( toast ).toContainText(
			`${ ARTICLES - 1 } articles moved to the trash.`
		);
		await expect(
			page.locator( '#a11y-speak-polite' ),
			'Focus did not move to Undo, so the message is announced.'
		).toContainText( `${ ARTICLES - 1 } articles moved to the trash.` );
		await toast.getByRole( 'button', { name: 'Undo' } ).click();
		await expect( page.locator( '.ant-message' ) ).toContainText(
			`${ ARTICLES - 1 } articles put back.`,
			{ timeout: 30_000 }
		);

		expect( reverts ).toHaveLength( Math.ceil( ARTICLES / 100 ) );
		expect(
			Number(
				wpEval(
					`echo count( get_posts( array( 'post_type' => 'licensio_article', 'post_status' => 'publish', 's' => '${ WORD }', 'fields' => 'ids', 'posts_per_page' => -1 ) ) );`
				)
			)
		).toBe( ARTICLES );
	} );
} );
