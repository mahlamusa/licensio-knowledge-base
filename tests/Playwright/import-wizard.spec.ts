/**
 * The Migration tab, end to end.
 *
 * Walks the import wizard as far as a dry run and reads the migration log. A
 * dry run writes nothing but its own log rows, so it is safe to repeat. The spec
 * never starts a real import: it only opens and cancels the confirmation of
 * "Looks good, run the import", never clicks "Skip the dry run and import" or
 * "Resume import", and it refuses to continue past a source whose only way
 * forward is "Resume import".
 *
 * The suite needs a running WordPress with this plugin active, an
 * administrator account and at least one import source with data, so it is
 * skipped unless `LICENSIO_KB_E2E=1` and the two credential variables are
 * present. The Migration tab is hidden when no source is detected; the spec
 * skips with that reason rather than failing.
 *
 * The WordPress admin menu can carry a "BetterDocs" menu of its own, so every
 * locator is scoped to `.licensio-kb-migration`.
 *
 * @since 1.0.0
 */

import { expect, test, type Locator, type Page } from '@playwright/test';
import { cancelPopconfirm, openPopconfirm } from './helpers/content-manager';
import { logIn, password, user } from './helpers/session';

const enabled = process.env.LICENSIO_KB_E2E === '1';

const TOOLS_URL =
	'/wp-admin/edit.php?post_type=licensio_article&page=licensio-kb-tools';

/** The slowest a dry run over a real site may take, in milliseconds. */
const DRY_RUN_TIMEOUT = 120_000;

/** How many logged runs to look through for an article with details. */
const MAX_RUNS_TO_SEARCH = 40;

/**
 * Open the Tools screen and wait for its tabs to mount.
 *
 * @since 1.0.0
 *
 * @param {Page} page The browser page.
 *
 * @return {Promise<Locator>} The migration panel, or a locator that matches nothing when the tab is hidden.
 */
async function openMigration( page: Page ): Promise< Locator > {
	await page.goto( TOOLS_URL );

	// Another tab is always present, so its arrival proves the app mounted.
	await expect(
		page.getByRole( 'tab', { name: 'Search Index' } )
	).toBeVisible();

	const tab = page.getByRole( 'tab', { name: 'Migration' } );

	test.skip(
		0 === ( await tab.count() ),
		'The Migration tab is hidden because no import source was detected.'
	);

	if ( 'true' !== ( await tab.getAttribute( 'aria-selected' ) ) ) {
		await tab.click();
	}

	const root = page.locator( '.licensio-kb-migration' );

	await expect( root ).toBeVisible();

	return root;
}

/**
 * Pick the first source that can be taken through the dry run.
 *
 * A source with an interrupted import offers "Resume import", which runs the
 * real import, so it is passed over. BetterDocs is tried first when present.
 *
 * @since 1.0.0
 *
 * @param {Locator} root The migration panel.
 *
 * @return {Promise<string>} The label of the source that was selected.
 */
async function selectSafeSource( root: Locator ): Promise< string > {
	const cards = root.locator( '.licensio-kb-migration__source' );

	await expect( cards.first() ).toBeVisible();

	const labels = await cards.locator( 'strong' ).allInnerTexts();
	const order = labels
		.map( ( label, index ) => ( { label, index } ) )
		.sort( ( a, b ) => {
			if ( 'BetterDocs' === a.label ) {
				return -1;
			}

			return 'BetterDocs' === b.label ? 1 : a.index - b.index;
		} );

	for ( const { label, index } of order ) {
		await cards.nth( index ).locator( 'input[type="radio"]' ).check();

		if (
			0 <
			( await root
				.getByRole( 'button', { name: 'Next: categories' } )
				.count() )
		) {
			return label;
		}
	}

	test.skip(
		true,
		'Every detected source has an interrupted import; the spec will not resume a real import.'
	);

	return '';
}

/**
 * Wait for the migration log to load its runs.
 *
 * The log shows its empty state until the request for the runs returns, so an
 * absent run picker only means "no runs" once it has had time to arrive.
 *
 * @since 1.0.0
 *
 * @param {Locator} log The migration log.
 *
 * @return {Promise<boolean>} True when the log has at least one run.
 */
async function logHasRuns( log: Locator ): Promise< boolean > {
	return log
		.locator( '.licensio-kb-migration-log__run' )
		.waitFor( { timeout: 10_000 } )
		.then( () => true )
		.catch( () => false );
}

/**
 * Choose an option in an Ant Design select.
 *
 * @since 1.0.0
 *
 * @param {Page}          page   The browser page.
 * @param {Locator}       select The select.
 * @param {string|RegExp} option The option's text.
 *
 * @return {Promise<void>} Resolves once the option is picked.
 */
async function choose(
	page: Page,
	select: Locator,
	option: string | RegExp
): Promise< void > {
	await select.click();
	await page
		.locator( '.ant-select-dropdown:visible .ant-select-item-option' )
		.filter( { hasText: option } )
		.first()
		.click();
	await page.waitForLoadState( 'networkidle' );
}

test.describe( 'Migration wizard', () => {
	test.skip(
		! enabled || ! user || ! password,
		'Set LICENSIO_KB_E2E=1, LICENSIO_KB_ADMIN_USER and LICENSIO_KB_ADMIN_PASSWORD to run.'
	);

	test.beforeEach( async ( { page } ) => {
		await logIn( page );
	} );

	test( 'a dry run walks the wizard and lands in the log', async ( {
		page,
	} ) => {
		test.setTimeout( 180_000 );

		const root = await openMigration( page );

		// Source cards render with their counts.
		const cards = root.locator( '.licensio-kb-migration__source' );

		await expect( cards.first() ).toBeVisible();

		for ( const card of await cards.all() ) {
			await expect( card.locator( 'strong' ) ).not.toBeEmpty();
			await expect( card.locator( 'dt' ).first() ).toBeVisible();

			for ( const count of await card.locator( 'dd' ).all() ) {
				await expect( count ).toHaveText( /^\d+$/ );
			}
		}

		// Select a source and move on to the categories.
		const label = await selectSafeSource( root );

		await expect(
			root
				.locator( '.licensio-kb-migration__source', { hasText: label } )
				.locator( 'input[type="radio"]' )
		).toBeChecked();
		await root.getByRole( 'button', { name: 'Next: categories' } ).click();

		// Every source category defaults to being created new.
		const map = root.locator( 'table.licensio-kb-migration__map' );

		await expect( map ).toBeVisible();

		const rows = map.locator( 'tbody tr' );

		await expect( rows.first() ).toBeVisible();

		for ( const row of await rows.all() ) {
			await expect(
				row.locator(
					'.licensio-kb-migration__target .ant-select-selection-item'
				)
			).toHaveText( 'Create a new category' );
		}

		// The dry run reports what it would do and says it changed nothing.
		await root.getByRole( 'button', { name: 'Start dry run' } ).click();

		await expect(
			root.getByRole( 'heading', { name: 'Verifying import (dry run)' } )
		).toBeVisible();
		await expect( root.getByText( /Dry run complete/ ) ).toBeVisible( {
			timeout: DRY_RUN_TIMEOUT,
		} );
		await expect(
			root
				.locator(
					'.licensio-kb-migration__counts-table .ant-descriptions-item-label'
				)
				.first()
		).toBeVisible();
		await expect(
			root.getByRole( 'button', { name: 'Looks good, run the import' } )
		).toBeVisible();

		// The real run asks first; cancelling leaves the dry run report alone.
		await root
			.getByRole( 'button', { name: 'Looks good, run the import' } )
			.click();
		await expect( openPopconfirm( page ) ).toContainText(
			'Run the import now?'
		);
		await cancelPopconfirm( page );
		await expect(
			root.getByRole( 'heading', { name: 'Verifying import (dry run)' } )
		).toBeVisible();
		await expect( root.getByText( /Dry run complete/ ) ).toBeVisible();

		// The run is the first thing in the migration log, flagged as a dry run.
		const log = root.locator( '.licensio-kb-migration-log' );

		await expect(
			log.locator(
				'.licensio-kb-migration-log__run .ant-select-selection-item'
			)
		).toContainText( 'Dry run' );
		await expect(
			log.locator( 'tbody tr.ant-table-row' ).first()
		).toBeVisible();
		await expect(
			log.getByText(
				'Dry run started: nothing is written except this log.'
			)
		).toBeVisible();
	} );

	test( 'the migration log lists runs and rows', async ( { page } ) => {
		const root = await openMigration( page );
		const log = root.locator( '.licensio-kb-migration-log' );

		await expect(
			root.getByRole( 'heading', { name: 'Migration log' } )
		).toBeVisible();

		test.skip(
			! ( await logHasRuns( log ) ),
			'No import has been logged on this site yet.'
		);

		await expect(
			log.locator(
				'.licensio-kb-migration-log__run .ant-select-selection-item'
			)
		).toContainText( / UTC · / );
		await expect(
			log.locator( 'tbody tr.ant-table-row' ).first()
		).toBeVisible();
		await expect(
			log.getByRole( 'link', { name: 'Download CSV' } )
		).toBeVisible();
		await expect(
			log.getByRole( 'columnheader', { name: 'Title' } )
		).toBeVisible();
	} );

	test( 'expanding an article row shows its meta and categories', async ( {
		page,
	} ) => {
		test.setTimeout( 180_000 );

		const root = await openMigration( page );
		const log = root.locator( '.licensio-kb-migration-log' );
		const runSelect = log.locator( '.licensio-kb-migration-log__run' );

		test.skip(
			! ( await logHasRuns( log ) ),
			'No import has been logged on this site yet.'
		);

		// Only an article that was created or adopted carries its meta and filing; a skipped one does not.
		await choose(
			page,
			log.locator( '.licensio-kb-migration-log__status' ),
			'Created'
		);

		// The run list is virtual, so step through it with the keyboard from the newest run down.
		const seen = new Set< string >();

		let found = false;

		for ( let step = 0; step < MAX_RUNS_TO_SEARCH && ! found; step++ ) {
			const label = await runSelect
				.locator( '.ant-select-selection-item' )
				.innerText();

			if ( seen.has( label ) ) {
				break;
			}

			seen.add( label );

			const article = log
				.locator( 'tbody tr.ant-table-row' )
				.filter( {
					has: page.locator( 'td', { hasText: /^article$/ } ),
				} )
				.first();

			// The rows of a newly picked run arrive after the picker changes.
			await article
				.waitFor( { timeout: 5_000 } )
				.catch( () => undefined );

			if ( 0 < ( await article.count() ) ) {
				await article.locator( '.ant-table-row-expand-icon' ).click();

				const details = log
					.locator( '.licensio-kb-migration-log__details' )
					.first();

				if (
					0 <
						( await details
							.getByRole( 'heading', { name: 'Meta' } )
							.count() ) &&
					0 <
						( await details
							.getByRole( 'heading', {
								name: 'Categories and tags',
							} )
							.count() )
				) {
					found = true;

					await expect(
						details.getByRole( 'heading', { name: 'Meta' } )
					).toBeVisible();
					await expect(
						details.getByRole( 'heading', {
							name: 'Categories and tags',
						} )
					).toBeVisible();
					await expect(
						details.getByRole( 'columnheader', { name: 'Outcome' } )
					).toBeVisible();
				}
			}

			if ( ! found ) {
				await runSelect.click();
				await page.keyboard.press( 'ArrowDown' );
				await page.keyboard.press( 'Enter' );
				await page.waitForLoadState( 'networkidle' );
			}
		}

		test.skip(
			! found,
			'No logged run on this site contains a created article with meta and categories.'
		);
	} );
} );
