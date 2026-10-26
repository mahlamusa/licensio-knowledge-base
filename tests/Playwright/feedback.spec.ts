/**
 * The Feedback inbox, end to end.
 *
 * Checks that a row's status change is saved as soon as it is picked, without
 * a confirmation, and that a bulk mark asks first, sends nothing when
 * cancelled and is saved once confirmed, and that the pager keeps focus on
 * its first and last page.
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
	answerConfirm,
	confirmDialog,
	expectNoConfirmDialog,
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

	test( 'a status change saves on pick and a bulk mark asks first', async ( {
		page,
	} ) => {
		await logIn( page );

		const writes = recordWrites( page );

		await page.goto( `${ FEEDBACK_URL }&article_id=${ articleId }` );

		const rows = page.locator( '.ant-table-row' );
		const first = rows.filter( { hasText: 'First E2E comment' } );
		const status = first.locator( '.ant-select-selection-item' );

		await expect( rows ).toHaveCount( 2 );

		// The inbox keeps Ant Design's own cells; core's list cells are only
		// for the Articles and Tags tables.
		await expect( first.locator( 'td' ).last() ).not.toHaveCSS(
			'vertical-align',
			'top'
		);

		// A single row's status is an update, so it is saved on pick.
		await pickStatus( page, 'First E2E comment', 'Reviewed' );
		await expect.poll( () => writes.length ).toBe( 1 );
		await expect( status ).toHaveText( 'Reviewed' );
		await expectNoConfirmDialog( page );

		// A bulk mark asks first, and Cancel sends nothing.

		await page.locator( '.ant-table-thead .ant-checkbox-input' ).check();
		await page.getByRole( 'button', { name: 'Mark actioned' } ).click();
		await expect( confirmDialog( page ) ).toContainText(
			'Mark 2 feedback items as actioned?'
		);
		await answerConfirm( page, 'cancel' );
		expect( writes ).toHaveLength( 1 );

		await page.getByRole( 'button', { name: 'Mark actioned' } ).click();
		await answerConfirm( page, 'confirm' );
		await expect.poll( () => writes.length ).toBe( 3 );
		await expect( rows.locator( '.ant-select-selection-item' ) ).toHaveText(
			[ 'Actioned', 'Actioned' ]
		);
	} );

	test( 'the pager keeps focus when the page it moved to disables the pressed control', async ( {
		page,
	} ) => {
		// One more than a page of 20, so the second page is the last.
		const id = Number(
			wpEval( `
				$id = wp_insert_post( array( 'post_type' => 'licensio_article', 'post_status' => 'publish', 'post_title' => 'Feedback pager E2E' ) );
				$feedback = licensio_kb()->feedback();
				for ( $i = 1; $i <= 21; $i++ ) {
					$feedback->record_vote( $id, 1, 'Pager E2E ' . $i, md5( 'feedback-pager-e2e-' . $i ) );
				}
				echo $id;
			` )
		);

		try {
			await logIn( page );
			await page.goto( `${ FEEDBACK_URL }&article_id=${ id }` );

			const rows = page.locator( '.ant-table-row' );
			const current = page.locator( '.ant-pagination-item-active' );

			await expect( rows ).toHaveCount( 20 );

			await page.locator( '.ant-pagination-next' ).focus();
			await page.keyboard.press( 'Enter' );
			await expect( rows ).toHaveCount( 1 );
			await expect( current ).toHaveText( '2' );
			await expect( current ).toBeFocused();

			await page.locator( '.ant-pagination-prev' ).focus();
			await page.keyboard.press( 'Enter' );
			await expect( rows ).toHaveCount( 20 );
			await expect( current ).toHaveText( '1' );
			await expect( current ).toBeFocused();
		} finally {
			wpEval( `
				global $wpdb;
				$wpdb->delete( Licensio_KB_DB::table_name( 'licensio_feedback' ), array( 'article_id' => ${ id } ) );
				wp_delete_post( ${ id }, true );
			` );
		}
	} );
} );
