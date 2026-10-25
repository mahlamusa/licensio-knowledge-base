/**
 * A password-protected article on the front end, for a logged-out visitor.
 *
 * Proves the article behaves like a core post: the password form is shown in
 * place of the body, a wrong password keeps it locked, and the right one
 * reveals the body. The feedback widget stays hidden, the feedback endpoint
 * refuses a vote and no view is counted until the article is unlocked, as
 * core hides the comments of a protected post.
 *
 * The spec seeds its own article with wp-cli and deletes it afterwards, so
 * besides `LICENSIO_KB_E2E=1` it needs `LICENSIO_KB_WP_CLI`, the command that
 * runs wp-cli against the same site. Without them it is skipped.
 *
 * @since 1.0.0
 */

import { execFileSync } from 'node:child_process';
import { expect, test, type Page } from '@playwright/test';

const enabled = process.env.LICENSIO_KB_E2E === '1';
const wpCli = process.env.LICENSIO_KB_WP_CLI ?? '';

/** A word only the seeded article's body contains. */
const BODY = `protectedbody${ Date.now() }`;

/** The article's password. */
const SECRET = 'open-sesame';

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
 * How many views the analytics table holds for an article.
 *
 * @since 1.0.0
 *
 * @param {number} id The article ID.
 *
 * @return {number} The view count.
 */
function viewCount( id: number ): number {
	return Number(
		wpEval(
			`global $wpdb; echo (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE article_id = %d', $wpdb->prefix . 'licensio_article_views', ${ id } ) );`
		)
	);
}

/**
 * Submit the password form on the current page.
 *
 * @since 1.0.0
 *
 * @param {Page}   page  The browser page.
 * @param {string} value The password to enter.
 *
 * @return {Promise<void>} Resolves once the article has loaded again.
 */
async function submitPassword( page: Page, value: string ): Promise< void > {
	const form = page.locator( '.post-password-form' );

	await form.locator( 'input[name="post_password"]' ).fill( value );
	await Promise.all( [
		page.waitForLoadState( 'load' ),
		form.locator( '[type="submit"]' ).click(),
	] );
}

test.describe( 'Password-protected article', () => {
	test.describe.configure( { mode: 'serial' } );

	test.skip(
		! enabled || '' === wpCli,
		'Set LICENSIO_KB_E2E=1 and LICENSIO_KB_WP_CLI to run.'
	);

	let articleId = 0;

	test.beforeAll( () => {
		articleId = Number(
			wpEval(
				`echo wp_insert_post( array( 'post_type' => 'licensio_article', 'post_status' => 'publish', 'post_title' => 'Protected e2e article', 'post_content' => '<!-- wp:paragraph --><p>${ BODY }</p><!-- /wp:paragraph -->', 'post_password' => '${ SECRET }' ) );`
			)
		);
	} );

	test.afterAll( () => {
		if ( articleId > 0 ) {
			wpEval( `wp_delete_post( ${ articleId }, true );` );
		}
	} );

	test( 'a logged-out visitor unlocks it with the right password only', async ( {
		page,
	} ) => {
		expect( articleId ).toBeGreaterThan( 0 );

		await page.goto( `/?post_type=licensio_article&p=${ articleId }` );

		const form = page.locator( '.post-password-form' );
		const feedback = page.locator( '.licensio-kb-feedback' );

		await expect( page.locator( '#wpadminbar' ) ).toHaveCount( 0 );
		await expect( form ).toBeVisible();
		await expect( page.getByText( BODY ) ).toHaveCount( 0 );
		await expect( feedback ).toHaveCount( 0 );
		expect( viewCount( articleId ) ).toBe( 0 );

		await submitPassword( page, 'not-the-password' );

		await expect( form ).toBeVisible();
		await expect( page.getByText( BODY ) ).toHaveCount( 0 );
		await expect( feedback ).toHaveCount( 0 );
		expect( viewCount( articleId ) ).toBe( 0 );

		await submitPassword( page, SECRET );

		await expect( page.getByText( BODY ) ).toBeVisible();
		await expect( form ).toHaveCount( 0 );
		await expect( feedback ).toBeVisible();
		expect( viewCount( articleId ) ).toBeGreaterThan( 0 );
	} );

	test( 'a vote is refused until the visitor has unlocked the article', async ( {
		page,
		request,
	} ) => {
		await page.goto( `/?post_type=licensio_article&p=${ articleId }` );
		await submitPassword( page, SECRET );

		const settings = await page.evaluate( () => {
			const data = (
				window as unknown as {
					licensioKBFeedbackData: { endpoint: string; nonce: string };
				}
			 ).licensioKBFeedbackData;

			return { endpoint: data.endpoint, nonce: data.nonce };
		} );

		const vote = {
			headers: { 'X-WP-Nonce': settings.nonce },
			data: { article_id: articleId, vote: 1 },
		};

		// A separate request context carries no password cookie.
		const locked = await request.post( settings.endpoint, vote );

		expect( locked.status() ).toBe( 403 );
		expect( ( await locked.json() ).code ).toBe(
			'licensio_article_locked'
		);

		const unlocked = await page.request.post( settings.endpoint, vote );

		expect( unlocked.status() ).toBe( 200 );
		expect( ( await unlocked.json() ).recorded ).toBe( true );
	} );
} );
