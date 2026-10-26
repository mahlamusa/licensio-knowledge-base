/**
 * The Tools screen's Data Maintenance and Search Index tabs, end to end.
 *
 * Checks that the recount and a resumed index rebuild ask first and send
 * nothing when cancelled, and that the recount report is one translatable sentence with a plural, not words
 * joined around a number: the answer is rewritten to report a single article,
 * which only the singular form reads right for.
 *
 * The suite needs a running WordPress with this plugin active and an
 * administrator account, so it is skipped unless `LICENSIO_KB_E2E=1` and the
 * two credential variables are present.
 *
 * @since 1.0.0
 */

import { expect, test } from '@playwright/test';
import {
	cancelPopconfirm,
	confirmPopconfirm,
	openPopconfirm,
} from './helpers/content-manager';
import { logIn, password, user } from './helpers/session';

const enabled = process.env.LICENSIO_KB_E2E === '1';

const TOOLS_URL =
	'/wp-admin/edit.php?post_type=licensio_article&page=licensio-kb-tools';

test.skip(
	! enabled || ! user || ! password,
	'Set LICENSIO_KB_E2E=1 and the admin credentials to run.'
);

test( 'the recount report reads as one sentence for a single article', async ( {
	page,
} ) => {
	await logIn( page );

	// The recount runs for real; its answer is rewritten to one article.
	await page.route( /admin-ajax\.php/, async ( route ) => {
		if (
			! ( route.request().postData() ?? '' ).includes(
				'action=licensio_tools_recount_scores'
			)
		) {
			await route.continue();
			return;
		}

		const response = await route.fetch();
		const envelope = await response.json();

		await route.fulfill( {
			response,
			json: { ...envelope, data: { ...envelope.data, articles: 1 } },
		} );
	} );

	const recounts: string[] = [];

	page.on( 'request', ( request ) => {
		if (
			( request.postData() ?? '' ).includes(
				'action=licensio_tools_recount_scores'
			)
		) {
			recounts.push( request.url() );
		}
	} );

	await page.goto( TOOLS_URL );
	await page.getByRole( 'tab', { name: 'Data Maintenance' } ).click();

	// The recount asks first, and Cancel sends nothing.
	await page.getByRole( 'button', { name: 'Recount scores' } ).click();
	await expect( openPopconfirm( page ) ).toContainText(
		'Recount the score of every article?'
	);
	await cancelPopconfirm( page );
	expect( recounts ).toHaveLength( 0 );

	await page.getByRole( 'button', { name: 'Recount scores' } ).click();
	await confirmPopconfirm( page );

	await expect(
		page.locator( '.licensio-kb-data-maintenance__done' )
	).toHaveText( 'Recounted the score of 1 article.' );
} );

test( 'resuming an index rebuild asks first', async ( { page } ) => {
	await logIn( page );

	const chunks: string[] = [];

	// The status is rewritten to report a rebuild left part-way through.
	await page.route( /admin-ajax\.php/, async ( route ) => {
		const body = route.request().postData() ?? '';

		if ( body.includes( 'action=licensio_tools_index_chunk' ) ) {
			chunks.push( body );
		}

		if ( ! body.includes( 'action=licensio_tools_index_status' ) ) {
			await route.continue();
			return;
		}

		const response = await route.fetch();
		const envelope = await response.json();

		await route.fulfill( {
			response,
			json: { ...envelope, data: { ...envelope.data, rebuilding: true } },
		} );
	} );

	await page.goto( TOOLS_URL );
	await page.getByRole( 'tab', { name: 'Search Index' } ).click();
	await page.getByRole( 'button', { name: 'Resume rebuild' } ).click();
	await expect( openPopconfirm( page ) ).toContainText(
		'Resume the rebuild from where it stopped?'
	);
	await cancelPopconfirm( page );
	expect( chunks ).toHaveLength( 0 );
} );
