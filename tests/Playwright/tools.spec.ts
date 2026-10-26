/**
 * The Tools screen's Data Maintenance and Search Index tabs, end to end.
 *
 * Checks that the recount and a resumed index rebuild run on click without a
 * confirmation, that rebuilding the index and deleting expired rows still ask
 * first and send nothing when cancelled, and that the recount report is one
 * translatable sentence with a plural, not words joined around a number: the
 * answer is rewritten to report a single article, which only the singular
 * form reads right for.
 *
 * The suite needs a running WordPress with this plugin active and an
 * administrator account, so it is skipped unless `LICENSIO_KB_E2E=1` and the
 * two credential variables are present.
 *
 * @since 1.0.0
 */

import { expect, test } from '@playwright/test';
import { cancelPopconfirm, openPopconfirm } from './helpers/content-manager';
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

	// The recount runs on click, without a confirmation.
	await page.getByRole( 'button', { name: 'Recount scores' } ).click();
	await expect.poll( () => recounts.length ).toBe( 1 );
	await expect(
		page.locator( '.ant-popconfirm:not(.ant-popover-hidden)' )
	).toHaveCount( 0 );

	await expect(
		page.locator( '.licensio-kb-data-maintenance__done' )
	).toHaveText( 'Recounted the score of 1 article.' );

	// Deleting expired rows is destructive, so it still asks; Cancel sends nothing.
	const prunes: string[] = [];

	page.on( 'request', ( request ) => {
		if (
			( request.postData() ?? '' ).includes(
				'action=licensio_tools_prune_analytics'
			)
		) {
			prunes.push( request.url() );
		}
	} );

	await page.getByRole( 'button', { name: 'Delete expired rows' } ).click();
	await expect( openPopconfirm( page ) ).toContainText(
		'Permanently delete the expired rows?'
	);
	await cancelPopconfirm( page );
	expect( prunes ).toHaveLength( 0 );
} );

test( 'resuming an index rebuild runs on click and a rebuild asks first', async ( {
	page,
} ) => {
	await logIn( page );

	const chunks: string[] = [];
	const starts: string[] = [];

	// The status is rewritten to report a rebuild left part-way through, and
	// the resumed chunk is answered here, so the real index is not touched.
	await page.route( /admin-ajax\.php/, async ( route ) => {
		const body = route.request().postData() ?? '';

		if ( body.includes( 'action=licensio_tools_index_start' ) ) {
			starts.push( body );
		}

		if ( body.includes( 'action=licensio_tools_index_chunk' ) ) {
			chunks.push( body );

			await route.fulfill( {
				json: {
					success: true,
					data: {
						chunk: 0,
						finished: true,
						status: { indexed: 1, articles: 1, rebuilding: false },
					},
				},
			} );
			return;
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

	// Rebuilding empties the index first, so it asks; Cancel sends nothing.
	await page.getByRole( 'button', { name: 'Rebuild index' } ).click();
	await expect( openPopconfirm( page ) ).toContainText(
		'Empty the index and re-index every article?'
	);
	await cancelPopconfirm( page );
	expect( starts ).toHaveLength( 0 );

	// Resuming runs on click, without a confirmation.
	await page.getByRole( 'button', { name: 'Resume rebuild' } ).click();
	await expect.poll( () => chunks.length ).toBe( 1 );
	await expect(
		page.locator( '.ant-popconfirm:not(.ant-popover-hidden)' )
	).toHaveCount( 0 );
	await expect( page.getByText( 'The index was rebuilt.' ) ).toBeVisible();
	expect( starts ).toHaveLength( 0 );
} );
