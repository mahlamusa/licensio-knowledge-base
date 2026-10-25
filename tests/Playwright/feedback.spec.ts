/**
 * The Feedback inbox, end to end.
 *
 * Checks that a row's status change and a bulk mark each ask in a Popconfirm
 * first, that Cancel sends nothing and puts the row's status back, and that
 * a confirmed change is saved.
 *
 * Seeds one article with two votes through wp-cli and filters the inbox to
 * it, so the spec reads only its own rows. It needs a running WordPress with
 * this plugin active, the administrator credentials and wp-cli, so it is
 * skipped unless `LICENSIO_KB_E2E=1`, the credential variables and
 * `LICENSIO_KB_WP_CLI` are present.
 *
 * @since 1.0.0
 */

import { expect, test, type Page } from '@playwright/test';
import {
	cancelPopconfirm,
	confirmPopconfirm,
	openPopconfirm,
} from './helpers/content-manager';
import { logIn, password, user } from './helpers/session';
import { wpCli, wpEval } from './helpers/wp-cli';

const enabled = process.env.LICENSIO_KB_E2E === '1';

const FEEDBACK_URL =
	'/wp-admin/edit.php?post_type=licensio_article&page=licensio-kb-feedback';

/**
 * Collect every status change the inbox sends.
 *
 * @since 1.0.0
 *
 * @param {Page} page The browser page.
 *
 * @return {string[]} The live list of request URLs.
 */
function recordWrites( page: Page ): string[] {
	const writes: string[] = [];

	page.on( 'request', ( request ) => {
		if (
			'PATCH' === request.method() &&
			decodeURIComponent( request.url() ).includes(
				'licensio-kb/v1/feedback'
			)
		) {
			writes.push( request.url() );
		}
	} );

	return writes;
}

/**
 * Pick a status in a row's status select.
 *
 * @since 1.0.0
 *
 * @param {Page}   page   The browser page.
 * @param {string} text   Text that identifies the row.
 * @param {string} status The status label to pick.
 *
 * @return {Promise<void>} Resolves once the option is clicked.
 */
async function pickStatus(
	page: Page,
	text: string,
	status: string
): Promise< void > {
	await page
		.locator( '.ant-table-row', { hasText: text } )
		.locator( '.ant-select' )
		.click();
	await page
		.locator( '.ant-select-dropdown:visible .ant-select-item-option' )
		.filter( { hasText: new RegExp( `^${ status }$` ) } )
		.click();
}

test.describe( 'Feedback inbox', () => {
	test.skip(
		! enabled || ! user || ! password || ! wpCli,
		'Set LICENSIO_KB_E2E=1, the admin credentials and LICENSIO_KB_WP_CLI to run.'
	);

	let articleId = 0;

	test.beforeAll( () => {
		articleId = Number(
			wpEval( `
				$id = wp_insert_post( array( 'post_type' => 'licensio_article', 'post_status' => 'publish', 'post_title' => 'Feedback E2E' ) );
				$feedback = licensio_kb()->feedback();
				$feedback->record_vote( $id, 1, 'First E2E comment', md5( 'feedback-e2e-1' ) );
				$feedback->record_vote( $id, -1, 'Second E2E comment', md5( 'feedback-e2e-2' ) );
				echo $id;
			` )
		);
	} );

	test.afterAll( () => {
		wpEval( `
			global $wpdb;
			$wpdb->delete( Licensio_KB_DB::table_name( 'licensio_feedback' ), array( 'article_id' => ${ articleId } ) );
			wp_delete_post( ${ articleId }, true );
		` );
	} );

	test( 'a status change and a bulk mark are sent only once confirmed', async ( {
		page,
	} ) => {
		await logIn( page );

		const writes = recordWrites( page );

		await page.goto( `${ FEEDBACK_URL }&article_id=${ articleId }` );

		const rows = page.locator( '.ant-table-row' );
		const first = rows.filter( { hasText: 'First E2E comment' } );
		const status = first.locator( '.ant-select-selection-item' );

		await expect( rows ).toHaveCount( 2 );

		await pickStatus( page, 'First E2E comment', 'Reviewed' );
		await expect( openPopconfirm( page ) ).toContainText(
			'Change the status of this feedback?'
		);
		await cancelPopconfirm( page );
		await expect( status ).toHaveText( 'New' );
		expect( writes ).toHaveLength( 0 );

		await pickStatus( page, 'First E2E comment', 'Reviewed' );
		await confirmPopconfirm( page );
		await expect.poll( () => writes.length ).toBe( 1 );
		await expect( status ).toHaveText( 'Reviewed' );

		await page.locator( '.ant-table-thead .ant-checkbox-input' ).check();
		await page.getByRole( 'button', { name: 'Mark actioned' } ).click();
		await expect( openPopconfirm( page ) ).toContainText(
			'Mark 2 feedback items as actioned?'
		);
		await cancelPopconfirm( page );
		expect( writes ).toHaveLength( 1 );

		await page.getByRole( 'button', { name: 'Mark actioned' } ).click();
		await confirmPopconfirm( page );
		await expect.poll( () => writes.length ).toBe( 3 );
		await expect( rows.locator( '.ant-select-selection-item' ) ).toHaveText( [
			'Actioned',
			'Actioned',
		] );
	} );
} );
