/**
 * The content manager switch-on, end to end.
 *
 * Checks that the core article list and the category and tag screens
 * redirect to the apps with their filters, that the editor screens do not,
 * that the apps sit in the Knowledge Base menu in core's order with the
 * current one highlighted, and that a popover opened from the keyboard takes
 * focus and gives it back on Escape.
 *
 * Expects the classic screens setting to be off. The suite needs a running
 * WordPress with this plugin active and an administrator account, so it is
 * skipped unless `LICENSIO_KB_E2E=1` and the two credential variables are
 * present.
 *
 * @since 1.0.0
 */

import { expect, test, type Page } from '@playwright/test';
import { logIn, password, user } from './helpers/session';

const enabled = process.env.LICENSIO_KB_E2E === '1';

test.skip(
	! enabled || ! user || ! password,
	'Set LICENSIO_KB_E2E=1 and the admin credentials to run.'
);

/** The app URL the redirects land on. */
const APP = '/wp-admin/admin.php?page=';

/**
 * The path and query of the page's URL.
 *
 * @since 1.0.0
 *
 * @param {Page} page The browser page.
 *
 * @return {string} The path and query.
 */
function pathOf( page: Page ): string {
	const url = new URL( page.url() );

	return url.pathname + url.search;
}

test.describe( 'Content manager switch-on', () => {
	test.beforeEach( async ( { page } ) => {
		await logIn( page );
	} );

	test( 'core list and term screens redirect to the apps', async ( { page } ) => {
		await page.goto(
			'/wp-admin/edit.php?post_type=licensio_article&post_status=draft&s=reset'
		);
		expect( pathOf( page ) ).toBe(
			`${ APP }licensio-kb-articles&status=draft&search=reset`
		);
		await expect( page.locator( '#licensio-kb-articles .lkb-page' ) ).toBeVisible();

		await page.goto(
			'/wp-admin/edit-tags.php?taxonomy=licensio_category&post_type=licensio_article'
		);
		expect( pathOf( page ) ).toBe( `${ APP }licensio-kb-categories` );

		await page.goto( '/wp-admin/edit-tags.php?taxonomy=licensio_tag&s=faq' );
		expect( pathOf( page ) ).toBe( `${ APP }licensio-kb-tags&search=faq` );

		await page.goto( '/wp-admin/term.php?taxonomy=licensio_tag&tag_ID=999999' );
		expect( pathOf( page ) ).toBe( `${ APP }licensio-kb-tags&term=999999` );
	} );

	test( 'the editor and other post types stay on core', async ( { page } ) => {
		await page.goto( '/wp-admin/post-new.php?post_type=licensio_article' );
		expect( pathOf( page ) ).toBe(
			'/wp-admin/post-new.php?post_type=licensio_article'
		);

		await page.goto( '/wp-admin/edit.php?post_type=page' );
		expect( pathOf( page ) ).toBe( '/wp-admin/edit.php?post_type=page' );

		await page.goto( '/wp-admin/edit-tags.php?taxonomy=category' );
		expect( pathOf( page ) ).toBe( '/wp-admin/edit-tags.php?taxonomy=category' );
	} );

	test( 'the apps replace the core menu items, in core order', async ( { page } ) => {
		await page.goto( '/wp-admin/edit-tags.php?taxonomy=licensio_tag' );

		const items = page.locator( '#menu-posts-licensio_article .wp-submenu li a' );

		await expect( items.nth( 0 ) ).toHaveText( 'All Articles' );
		await expect( items.nth( 0 ) ).toHaveAttribute(
			'href',
			'edit.php?post_type=licensio_article&page=licensio-kb-articles'
		);
		await expect( items.nth( 1 ) ).toHaveText( 'Add New Article' );
		await expect( items.nth( 2 ) ).toHaveText( 'Categories' );
		await expect( items.nth( 3 ) ).toHaveText( 'Tags' );
		await expect(
			page.locator( '#menu-posts-licensio_article .wp-submenu li.current a' )
		).toHaveText( 'Tags' );

		// The Knowledge Base menu link itself opens All Articles, marked current.
		await page.goto( '/wp-admin/index.php' );
		const top = page.locator( '#menu-posts-licensio_article > a' );

		await top.click();
		await expect( page ).toHaveURL( /page=licensio-kb-articles/ );
		await expect(
			page.locator( '#menu-posts-licensio_article .wp-submenu li.current a' )
		).toHaveText( 'All Articles' );
	} );

	test( 'a popover opened from the keyboard takes and returns focus', async ( { page } ) => {
		await page.goto( `${ APP }licensio-kb-articles` );

		const filters = page.locator( 'button', { hasText: /^\s*Filters\s*$/ } );

		await filters.focus();
		await page.keyboard.press( 'Enter' );
		await expect( page.getByRole( 'combobox', { name: 'Filter by category' } ) ).toBeFocused();

		await page.keyboard.press( 'Escape' );
		await expect( filters ).toBeFocused();
	} );
} );
