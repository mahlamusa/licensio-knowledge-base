/**
 * End-to-end coverage of the knowledge base search type-ahead.
 *
 * Gated on LICENSIO_KB_E2E because it needs a WordPress site with the plugin
 * active, a page carrying the search block or the `[licensio_kb_search]`
 * shortcode, and at least one published article. The CI JavaScript job has none
 * of those, and a spec that failed there would be reporting the absence of a
 * site rather than a defect in the feature.
 *
 * Run it against a live site with:
 *
 *   LICENSIO_KB_E2E=1 \
 *   LICENSIO_KB_BASE_URL=https://lisenseserver.local \
 *   LICENSIO_KB_E2E_PATH=/kb-search-test/ \
 *   LICENSIO_KB_E2E_TERM=licence \
 *   npx playwright test
 *
 * @since 1.0.0
 */

import { expect, test, Page } from '@playwright/test';

const enabled = !! process.env.LICENSIO_KB_E2E;
const path = process.env.LICENSIO_KB_E2E_PATH ?? '/';
const term = process.env.LICENSIO_KB_E2E_TERM ?? 'licence';
const missing = process.env.LICENSIO_KB_E2E_MISSING ?? 'zzqqxxnotathing';

/**
 * Open the page carrying the search form and return the combobox.
 *
 * @since 1.0.0
 *
 * @param {Page} page The Playwright page.
 *
 * @return The combobox input and its listbox.
 */
async function openSearch( page: Page ) {
	await page.goto( path );

	const combobox = page.locator( '[data-licensio-kb-search] [role="combobox"]' ).first();
	await expect( combobox ).toBeVisible();

	const listboxId = await combobox.getAttribute( 'aria-controls' );
	expect( listboxId ).toBeTruthy();

	return { combobox, listbox: page.locator( `#${ listboxId }` ) };
}

test.describe( 'knowledge base search type-ahead', () => {
	test.skip( ! enabled, 'Set LICENSIO_KB_E2E to run against a live site.' );

	test( 'suggestions appear as the visitor types', async ( { page } ) => {
		const { combobox, listbox } = await openSearch( page );

		await expect( combobox ).toHaveAttribute( 'aria-expanded', 'false' );

		await combobox.fill( term );

		await expect( listbox.locator( '[role="option"]' ).first() ).toBeVisible();
		await expect( combobox ).toHaveAttribute( 'aria-expanded', 'true' );
	} );

	test( 'the arrow keys move aria-activedescendant through the options', async ( {
		page,
	} ) => {
		const { combobox, listbox } = await openSearch( page );

		await combobox.fill( term );
		await expect( listbox.locator( '[role="option"]' ).first() ).toBeVisible();

		await expect( combobox ).not.toHaveAttribute( 'aria-activedescendant', /./ );

		await combobox.press( 'ArrowDown' );

		const first = await listbox.locator( '[role="option"]' ).first().getAttribute( 'id' );
		await expect( combobox ).toHaveAttribute( 'aria-activedescendant', first! );
		await expect(
			listbox.locator( '[role="option"]' ).first()
		).toHaveAttribute( 'aria-selected', 'true' );

		const count = await listbox.locator( '[role="option"]' ).count();

		if ( count > 1 ) {
			await combobox.press( 'ArrowDown' );

			const second = await listbox
				.locator( '[role="option"]' )
				.nth( 1 )
				.getAttribute( 'id' );

			await expect( combobox ).toHaveAttribute( 'aria-activedescendant', second! );

			await combobox.press( 'ArrowUp' );
			await expect( combobox ).toHaveAttribute( 'aria-activedescendant', first! );
		}
	} );

	test( 'Escape closes the listbox and clears the active option', async ( {
		page,
	} ) => {
		const { combobox, listbox } = await openSearch( page );

		await combobox.fill( term );
		await expect( listbox.locator( '[role="option"]' ).first() ).toBeVisible();

		await combobox.press( 'Escape' );

		await expect( combobox ).toHaveAttribute( 'aria-expanded', 'false' );
		await expect( combobox ).not.toHaveAttribute( 'aria-activedescendant', /./ );
		await expect( listbox ).toBeHidden();
	} );

	test( 'Enter on a selected option opens that article', async ( { page } ) => {
		const { combobox, listbox } = await openSearch( page );

		await combobox.fill( term );
		await expect( listbox.locator( '[role="option"]' ).first() ).toBeVisible();

		const destination = await listbox
			.locator( '[role="option"]' )
			.first()
			.getAttribute( 'data-url' );

		await combobox.press( 'ArrowDown' );
		await combobox.press( 'Enter' );

		await page.waitForURL( destination! );

		expect( page.url() ).toContain( new URL( destination! ).pathname );
	} );

	test( 'Enter with nothing selected goes to the full results page', async ( {
		page,
	} ) => {
		const { combobox, listbox } = await openSearch( page );

		await combobox.fill( term );
		await expect( listbox.locator( '[role="option"]' ).first() ).toBeVisible();

		await combobox.press( 'Enter' );

		await page.waitForURL( /[?&]s=/ );

		expect( page.url() ).toContain( `s=${ term }` );
		expect( page.url() ).toContain( 'post_type=licensio_article' );
	} );

	test( 'a term with no matches renders a distinct empty state', async ( {
		page,
	} ) => {
		const { combobox, listbox } = await openSearch( page );

		await combobox.fill( missing );

		await expect( listbox.locator( '.licensio-kb-search__empty' ) ).toBeVisible();
		await expect( listbox.locator( '[role="option"]' ) ).toHaveCount( 0 );

		// The empty state is not selectable, so arrowing must not pick it.
		await combobox.press( 'ArrowDown' );
		await expect( combobox ).not.toHaveAttribute( 'aria-activedescendant', /./ );
	} );

	test( 'typing quickly leaves only the last request standing', async ( {
		page,
	} ) => {
		const requests: string[] = [];

		page.on( 'request', ( request ) => {
			if ( request.url().includes( 'licensio-kb/v1/search' ) ) {
				requests.push( request.url() );
			}
		} );

		const { combobox, listbox } = await openSearch( page );

		await combobox.pressSequentially( term, { delay: 30 } );
		await expect( listbox.locator( '[role="option"]' ).first() ).toBeVisible();

		/*
		 * A 250 ms debounce over keystrokes 30 ms apart must not produce one
		 * request per character. The exact count depends on timing, so the
		 * assertion is the property that matters: far fewer requests than
		 * characters, and the last one carries the whole term.
		 */
		expect( requests.length ).toBeLessThan( term.length );
		expect( requests[ requests.length - 1 ] ).toContain(
			`term=${ encodeURIComponent( term ) }`
		);
		expect( requests[ requests.length - 1 ] ).toContain( 'source=live' );
	} );
} );
