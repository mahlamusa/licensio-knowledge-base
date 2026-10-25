/**
 * The Tools screen's Data Maintenance tab, end to end.
 *
 * Checks that the recount report is one translatable sentence with a plural,
 * not words joined around a number: the answer is rewritten to report a
 * single article, which only the singular form reads right for.
 *
 * The suite needs a running WordPress with this plugin active and an
 * administrator account, so it is skipped unless `LICENSIO_KB_E2E=1` and the
 * two credential variables are present.
 *
 * @since 1.0.0
 */

import { expect, test } from '@playwright/test';
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

	await page.goto( TOOLS_URL );
	await page.getByRole( 'tab', { name: 'Data Maintenance' } ).click();
	await page.getByRole( 'button', { name: 'Recount scores' } ).click();

	await expect(
		page.locator( '.licensio-kb-data-maintenance__done' )
	).toHaveText( 'Recounted the score of 1 article.' );
} );
