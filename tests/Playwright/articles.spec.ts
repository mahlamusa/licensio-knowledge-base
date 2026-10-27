/**
 * The Articles app, end to end.
 *
 * Opens the app through its hidden admin page and checks what only a real
 * WordPress can answer: the pills match the database, a confirmation stands
 * before a trash and Cancel sends nothing, a quick edit saves at once without
 * one and fits a phone screen, and the list state lives in the URL: filters
 * replace the history entry while sort and page push one. An out of range page moves
 * back to the last page, a failed load shows no empty state, and a quick
 * edit reloads the list and its counts. A keyboard trash moves focus to the Undo toast, whose
 * countdown waits while it has focus. From the keyboard, Tab stays inside the
 * Filters and Columns popovers, the period radios are one tab stop, Escape
 * cancels a quick edit, and the pager keeps focus on its first and last page.
 *
 * Needs a running WordPress with this plugin active, an administrator
 * account and at least one article, so it is skipped unless
 * `LICENSIO_KB_E2E=1` and the two credential variables are present. CI
 * provides both through `bin/e2e-env.sh`.
 *
 * @since 1.0.0
 * @since 1.0.0 The status count test runs on its own, after the other specs.
 */

import { expect, test, type Page, type Request } from '@playwright/test';
import {
	answerConfirm,
	confirmDialog,
	contentManagerUrl,
	openContentManager,
} from './helpers/content-manager';
import { failOnUnresolvedComponents } from './helpers/ant-components';
import { switchOffWelcomeGuides } from './helpers/editor';
import { measureFrame } from './helpers/layout';
import { openDashboard, password, user } from './helpers/session';
import { wpCli, wpEval } from './helpers/wp-cli';

failOnUnresolvedComponents();

const enabled = process.env.LICENSIO_KB_E2E === '1';

/** How long the Undo toast stays, as `UNDO_SECONDS` in the app's UndoToast. */
const UNDO_SECONDS = 10;

/**
 * Collect every write the app sends to its REST routes.
 *
 * @since 1.0.0
 *
 * @param {Page} page The browser page.
 *
 * @return {Request[]} The list, filled as requests go out.
 */
function recordWrites( page: Page ): Request[] {
	const writes: Request[] = [];

	page.on( 'request', ( request ) => {
		const route = decodeURIComponent( request.url() );

		if (
			request.method() !== 'GET' &&
			route.includes( 'licensio-kb/v1/articles' )
		) {
			writes.push( request );
		}
	} );

	return writes;
}

/**
 * Create or delete an article through the core REST API, as the signed-in user.
 *
 * @since 1.0.0
 *
 * @param {Page}   page   The browser page, on the Articles app.
 * @param {string} method POST to create, DELETE to delete for good.
 * @param {string} path   The route after `licensio_articles`.
 * @param {Object} data   The fields to send.
 *
 * @return {Promise<{id: number}>} The response body.
 */
async function articleRequest(
	page: Page,
	method: string,
	path: string,
	data: Record< string, string > = {}
): Promise< { id: number } > {
	return page.evaluate(
		async ( { verb, route, body } ) => {
			const config = (
				window as unknown as {
					licensioKbArticles: { root: string; nonce: string };
				}
			 ).licensioKbArticles;
			const base =
				config.root.replace( 'licensio-kb/v1', 'wp/v2' ) +
				'licensio_articles' +
				route;
			const response = await fetch(
				base +
					( 'DELETE' === verb
						? ( base.includes( '?' ) ? '&' : '?' ) + 'force=true'
						: '' ),
				{
					method: verb,
					headers: {
						'X-WP-Nonce': config.nonce,
						'Content-Type': 'application/json',
					},
					credentials: 'same-origin',
					body:
						'DELETE' === verb ? undefined : JSON.stringify( body ),
				}
			);

			return response.json();
		},
		{ verb: method, route: path, body: data }
	);
}

test.describe( 'Articles app', () => {
	test.skip(
		! enabled || '' === user || '' === password,
		'Set LICENSIO_KB_E2E=1 and the admin credentials to run.'
	);

	test.beforeEach( async ( { page } ) => {
		await openDashboard( page );
	} );

	test( 'loads with the header and status pills', async ( { page } ) => {
		const app = await openContentManager( page, 'articles' );

		await expect(
			app.getByRole( 'heading', { name: 'Articles' } )
		).toBeVisible();
		await expect(
			app.getByRole( 'link', { name: 'New article' } )
		).toBeVisible();
		await expect(
			app.getByRole( 'group', { name: /status/i } )
		).toContainText( 'All (' );
		await expect( app.locator( '.ant-table-row' ).first() ).toBeVisible();
	} );

	test( 'Cancel on a confirmation sends no request', async ( { page } ) => {
		const app = await openContentManager( page, 'articles' );
		const writes = recordWrites( page );
		const row = app.locator( '.ant-table-row' ).first();

		await row.hover();
		await row.getByRole( 'button', { name: 'Trash' } ).click();
		await answerConfirm( page, 'cancel' );
		await page.waitForTimeout( 500 );

		expect( writes ).toHaveLength( 0 );
	} );

	test( 'quick edit saves at once, without a confirmation', async ( {
		page,
	} ) => {
		const app = await openContentManager( page, 'articles' );
		const writes = recordWrites( page );
		const row = app.locator( '.ant-table-row' ).first();
		const title = `E2E ${ Date.now() }`;

		await row.getByRole( 'button', { name: 'Quick edit' } ).click();

		const form = app.locator( '.ant-table-expanded-row' ).first();
		const original = await form.getByLabel( 'Title' ).inputValue();

		await form.getByLabel( 'Title' ).fill( title );
		await form.getByRole( 'button', { name: /Save/ } ).click();

		try {
			await expect( app.getByText( title ) ).toBeVisible();
			await expect( confirmDialog( page ) ).toHaveCount( 0 );

			expect( writes ).toHaveLength( 1 );
			expect( writes[ 0 ].method() ).toBe( 'PATCH' );
			expect( JSON.parse( writes[ 0 ].postData() ?? '{}' ) ).toEqual( {
				title,
			} );
		} finally {
			// Put the article's title back so the site is left as found.
			if ( writes.length > 0 ) {
				const nonce = await page.evaluate(
					() =>
						(
							window as unknown as {
								licensioKbArticles?: { nonce?: string };
							}
						 ).licensioKbArticles?.nonce ?? ''
				);

				await page.request.fetch( writes[ 0 ].url(), {
					method: 'PATCH',
					headers: { 'X-WP-Nonce': nonce },
					data: { title: original },
				} );
			}
		}
	} );

	test( 'quick edit edits the stored title, not the decoded one', async ( {
		page,
	} ) => {
		const app = await openContentManager( page, 'articles' );
		const stamp = Date.now();
		const stored = `Entities &lt;b&gt;${ stamp }&lt;/b&gt; &amp; more`;
		const { id } = await articleRequest( page, 'POST', '', {
			title: stored,
			status: 'draft',
		} );

		try {
			await page.goto(
				contentManagerUrl( 'articles' ) + '&status=draft'
			);

			const row = app.locator( '.ant-table-row', {
				hasText: `Entities <b>${ stamp }</b> & more`,
			} );

			await expect(
				row,
				'The list shows the title as text.'
			).toBeVisible();

			await row.getByRole( 'button', { name: 'Quick edit' } ).click();

			const form = app.locator( '.ant-table-expanded-row' ).first();
			const field = form.getByLabel( 'Title' );

			await expect( field ).toHaveValue( stored );

			await field.fill( stored + ' edited' );
			await form.getByRole( 'button', { name: /Save/ } ).click();
			await expect(
				row,
				'The saved title is shown as text.'
			).toContainText( `Entities <b>${ stamp }</b> & more edited` );
			await expect(
				field,
				'The form keeps the stored title after the save.'
			).toHaveValue( stored + ' edited' );

			const raw = await page.evaluate( async ( postId ) => {
				const config = (
					window as unknown as {
						licensioKbArticles: { root: string; nonce: string };
					}
				 ).licensioKbArticles;
				const base =
					config.root.replace( 'licensio-kb/v1', 'wp/v2' ) +
					'licensio_articles/' +
					postId;
				const response = await fetch(
					base +
						( base.includes( '?' ) ? '&' : '?' ) +
						'context=edit',
					{
						headers: { 'X-WP-Nonce': config.nonce },
						credentials: 'same-origin',
					}
				);

				return ( await response.json() ).title.raw as string;
			}, id );

			expect( raw, 'The entities are saved as they were.' ).toBe(
				stored + ' edited'
			);
		} finally {
			await articleRequest( page, 'DELETE', '/' + id );
		}
	} );

	test( 'quick edit fits a phone screen without scrolling sideways', async ( {
		page,
	} ) => {
		await page.setViewportSize( { width: 390, height: 844 } );

		const app = await openContentManager( page, 'articles' );
		const row = app.locator( '.ant-table-row' ).first();

		await row.getByRole( 'button', { name: 'Quick edit' } ).click();

		const form = app.locator( '.lkb-quickedit' );
		const save = form.getByRole( 'button', { name: 'Save' } );

		await save.scrollIntoViewIfNeeded();
		await expect( save ).toBeInViewport( { ratio: 1 } );

		// Every field and both buttons sit inside the screen's width.
		const right = await form.evaluate( ( element ) =>
			Math.max(
				...Array.from(
					element.querySelectorAll( '.ant-form-item, .ant-btn' )
				).map( ( child ) => child.getBoundingClientRect().right )
			)
		);

		expect( right ).toBeLessThanOrEqual( 390 );
		expect( ( await measureFrame( page ) ).page, 'page overflow' ).toBe(
			0
		);
	} );

	test( 'the view is restored from the URL', async ( { page } ) => {
		await page.goto(
			contentManagerUrl( 'articles' ) +
				'&status=draft&window=7&orderby=title&order=asc'
		);

		const app = page.locator( '#licensio-kb-articles' );

		await expect( app.locator( '.lkb-pill.is-active' ) ).toContainText(
			'Drafts'
		);

		await app.locator( '.lkb-pill', { hasText: 'Published' } ).click();
		await expect( page ).toHaveURL( /status=publish/ );

		await page.reload();
		await expect( app.locator( '.lkb-pill.is-active' ) ).toContainText(
			'Published'
		);
		await expect( page ).toHaveURL( /window=7/ );
	} );

	test( 'filters replace the history entry while sort and page push one', async ( {
		page,
	} ) => {
		const app = await openContentManager( page, 'articles' );
		const active = app.locator( '.lkb-pill.is-active' );
		const entries = () => page.evaluate( () => window.history.length );
		const start = await entries();

		await app.locator( '.lkb-pill', { hasText: 'Drafts' } ).click();
		await expect( page ).toHaveURL( /status=draft/ );
		await app.getByText( '7 days', { exact: true } ).click();
		await expect( page ).toHaveURL( /window=7/ );
		expect( await entries() ).toBe( start );

		await app
			.locator( '.ant-table-thead th', { hasText: 'Title' } )
			.click();
		await expect( page ).toHaveURL( /orderby=title/ );
		expect( await entries() ).toBe( start + 1 );

		await page.goBack();
		await expect( page ).not.toHaveURL( /orderby=title/ );
		await expect( page ).toHaveURL( /status=draft.*window=7/ );
		await expect( active ).toContainText( 'Drafts' );

		await page.goForward();
		await expect( page ).toHaveURL( /orderby=title/ );
	} );

	test( 'a page past the end moves back to the last page', async ( {
		page,
	} ) => {
		await page.goto( contentManagerUrl( 'articles' ) + '&paged=999' );

		const app = page.locator( '#licensio-kb-articles' );

		await expect( app.locator( '.ant-table-row' ).first() ).toBeVisible();
		await expect( page ).not.toHaveURL( /paged=999/ );
		await expect( app.getByText( 'No articles yet.' ) ).toHaveCount( 0 );
	} );

	test( 'a page past the end of several pages lands on the last one', async ( {
		page,
	} ) => {
		test.skip( ! wpCli, 'Set LICENSIO_KB_WP_CLI to run.' );

		// One more than a page of 20, so the last page is the second.
		const word = `Clamp${ Date.now() }`;
		const ids = wpEval( `
			$ids = array();
			for ( $i = 1; $i <= 21; $i++ ) {
				$ids[] = wp_insert_post( array( 'post_type' => 'licensio_article', 'post_status' => 'publish', 'post_title' => '${ word } ' . $i ) );
			}
			echo implode( ',', $ids );
		` );

		try {
			await page.goto(
				contentManagerUrl( 'articles' ) + `&search=${ word }&paged=999`
			);

			const app = page.locator( '#licensio-kb-articles' );

			await expect( page ).toHaveURL( /paged=2/ );
			await expect( app.locator( '.ant-table-row' ) ).toHaveCount( 1 );
			await expect(
				app.locator( '.ant-pagination-item-active' )
			).toHaveText( '2' );
		} finally {
			wpEval( `
				foreach ( array( ${ ids } ) as $id ) {
					wp_delete_post( $id, true );
				}
			` );
		}
	} );

	test( 'a failed first load shows the error without the empty state', async ( {
		page,
	} ) => {
		await page.route(
			( url ) =>
				/licensio-kb(%2F|\/)v1(%2F|\/)articles(&|\?|$)/.test(
					url.href
				),
			( route ) =>
				route.fulfill( {
					status: 500,
					json: { code: 'e2e', message: 'The list is down.' },
				} )
		);

		await page.goto( contentManagerUrl( 'articles' ) );

		const app = page.locator( '#licensio-kb-articles' );

		await expect( app.locator( '.ant-alert-error' ) ).toBeVisible();
		await expect( app.getByText( 'No articles yet.' ) ).toHaveCount( 0 );
		await expect( app.getByText( 'No articles match' ) ).toHaveCount( 0 );
	} );

	// The status counts are site-wide and other specs write articles, so this
	// test runs on its own in the one-at-a-time project.
	test( 'a quick edit that publishes a draft takes it out of the Drafts view and keeps focus on the page @one-at-a-time', async ( {
		page,
	} ) => {
		const app = await openContentManager( page, 'articles' );
		const title = `Quick publish ${ Date.now() }`;
		const { id } = await articleRequest( page, 'POST', '', {
			title,
			status: 'draft',
		} );

		try {
			await page.goto(
				contentManagerUrl( 'articles' ) + '&status=draft'
			);

			const pill = ( label: string ) =>
				app.locator( '.lkb-pill', { hasText: label } );
			const count = async ( label: string ) =>
				Number(
					/\((\d+)\)/.exec(
						( await pill( label ).textContent() ) ?? ''
					)?.[ 1 ]
				);
			const row = app.locator( '.ant-table-row', { hasText: title } );

			await expect( row ).toBeVisible();

			const drafts = await count( 'Drafts' );
			const published = await count( 'Published' );

			await row.getByRole( 'button', { name: 'Quick edit' } ).click();

			const form = app.locator( '.ant-table-expanded-row' ).first();

			await form
				.locator( '.ant-form-item', { hasText: 'Status' } )
				.locator( '.ant-select' )
				.click();
			await page
				.locator(
					'.ant-select-dropdown:visible .ant-select-item-option'
				)
				.filter( { hasText: /^Published$/ } )
				.click();
			await form.getByRole( 'button', { name: /Save/ } ).click();

			await expect( row ).toHaveCount( 0 );
			await expect(
				app.locator( '.ant-table-expanded-row' )
			).toHaveCount( 0 );
			await expect(
				app.locator( '.lkb-page__title' ),
				'Focus stays on the page when the form goes away.'
			).toBeFocused();
			await expect( pill( 'Drafts' ) ).toContainText(
				`(${ drafts - 1 })`
			);
			await expect( pill( 'Published' ) ).toContainText(
				`(${ published + 1 })`
			);
		} finally {
			await articleRequest( page, 'DELETE', '/' + id );
		}
	} );

	test( 'titles match core and row actions show on hover, focus and phone width', async ( {
		page,
	} ) => {
		// The title's type and colour, the padding of its cell and the size
		// of the row actions under it.
		const style = ( element: Element ) => {
			const computed = window.getComputedStyle( element );
			const cell = window.getComputedStyle(
				element.closest( 'th, td' ) as Element
			);
			const actions = window.getComputedStyle(
				element
					.closest( 'th, td' )
					?.querySelector(
						'.row-actions, .lkb-rowactions'
					) as Element
			);

			return {
				title: [
					computed.fontSize,
					computed.fontWeight,
					computed.lineHeight,
					computed.color,
				],
				padding: cell.padding,
				actions: [
					actions.fontSize,
					actions.lineHeight,
					actions.paddingTop,
				],
			};
		};

		await page.goto( '/wp-admin/edit.php' );

		const core = await page
			.locator( '.row-title' )
			.first()
			.evaluate( style );
		const app = await openContentManager( page, 'articles' );
		const row = app.locator( '.ant-table-row' ).first();
		const actions = row.locator( '.lkb-rowactions' );

		expect( core.title.slice( 0, 2 ) ).toEqual( [ '14px', '600' ] );
		expect(
			await row.locator( '.lkb-title__link' ).evaluate( style )
		).toEqual( core );

		// Hidden only visually: still in the accessibility tree.
		await page.mouse.move( 0, 0 );
		await expect( actions ).toHaveCSS( 'opacity', '0' );
		await expect(
			row.getByRole( 'button', { name: 'Quick edit' } )
		).toBeAttached();

		await row.hover();
		await expect( actions ).toHaveCSS( 'opacity', '1' );
		await page.mouse.move( 0, 0 );
		await expect( actions ).toHaveCSS( 'opacity', '0' );

		await row.getByRole( 'link', { name: 'Edit', exact: true } ).focus();
		await expect( actions ).toHaveCSS( 'opacity', '1' );
		await row
			.getByRole( 'link', { name: 'Edit', exact: true } )
			.evaluate( ( link ) => ( link as HTMLElement ).blur() );
		await expect( actions ).toHaveCSS( 'opacity', '0' );

		// An open confirmation keeps them, so it does not point at nothing.
		await row.hover();
		await row.getByRole( 'button', { name: 'Trash' } ).click();
		await page.mouse.move( 0, 0 );
		await expect( confirmDialog( page ) ).toBeVisible();
		await expect( actions ).toHaveCSS( 'opacity', '1' );
		await page.keyboard.press( 'Escape' );
		await expect( confirmDialog( page ) ).toBeHidden();
		await row
			.getByRole( 'button', { name: 'Trash' } )
			.evaluate( ( button ) => ( button as HTMLElement ).blur() );
		await expect( actions ).toHaveCSS( 'opacity', '0' );

		await page.setViewportSize( { width: 390, height: 844 } );
		await expect( actions ).toHaveCSS( 'opacity', '1' );
	} );

	test( 'a keyboard trash hands focus to Undo and waits while it has focus', async ( {
		page,
	} ) => {
		test.setTimeout( 60_000 );

		const app = await openContentManager( page, 'articles' );
		const title = `Undo E2E ${ Date.now() }`;
		const { id } = await articleRequest( page, 'POST', '', {
			title,
			status: 'publish',
		} );

		try {
			await page.reload();
			await app.getByLabel( 'Search articles by title' ).fill( title );
			await app.getByLabel( 'Search articles by title' ).press( 'Enter' );

			const row = app.locator( '.ant-table-row', { hasText: title } );

			await expect( row ).toBeVisible();
			await row.getByRole( 'button', { name: 'Trash' } ).focus();
			await page.keyboard.press( 'Enter' );
			await expect(
				confirmDialog( page ).getByRole( 'button', { name: 'Cancel' } )
			).toBeFocused();
			await page.keyboard.press( 'Tab' );
			await page.keyboard.press( 'Enter' );

			const toast = page.locator( '.ant-notification-notice' );
			const undo = toast.getByRole( 'button', { name: 'Undo' } );

			await expect( undo ).toBeFocused();
			await expect( undo ).toHaveAccessibleDescription(
				'1 article moved to the trash.'
			);
			await expect(
				page.locator( '#a11y-speak-polite' ),
				'Undo reads the message, so it is not announced again.'
			).not.toContainText( '1 article moved to the trash.' );

			// The countdown is paused while Undo has focus.
			await page.waitForTimeout( UNDO_SECONDS * 1000 + 1500 );
			await expect( undo ).toBeVisible();

			await page.keyboard.press( 'Enter' );
			await expect( toast ).toBeHidden();
			await expect(
				page.getByText( '1 article put back.' )
			).toBeVisible();
			await expect( page.locator( '.lkb-page__title' ) ).toBeFocused();
		} finally {
			await articleRequest( page, 'DELETE', '/' + id );
		}
	} );

	test( 'trashing in the block editor offers Undo in the app', async ( {
		page,
	} ) => {
		test.skip( '' === wpCli, 'Needs LICENSIO_KB_WP_CLI.' );
		test.setTimeout( 60_000 );

		const title = `Editor trash E2E ${ Date.now() }`;
		const id = Number(
			wpEval(
				`echo wp_insert_post( array( 'post_type' => 'licensio_article', 'post_title' => '${ title }', 'post_status' => 'publish' ) );`
			)
		);

		try {
			await page.goto( `/wp-admin/post.php?post=${ id }&action=edit` );
			await switchOffWelcomeGuides( page, 'core/edit-post', [
				'welcomeGuide',
			] );

			const settings = page.getByRole( 'region', {
				name: 'Editor settings',
			} );

			if ( ! ( await settings.isVisible() ) ) {
				await page
					.getByRole( 'region', { name: 'Editor top bar' } )
					.getByRole( 'button', { name: 'Settings', exact: true } )
					.click();
			}

			await settings
				.getByRole( 'button', { name: 'Move to trash' } )
				.click();
			await page
				.getByRole( 'dialog' )
				.getByRole( 'button', {
					name: /^(Trash|Move to trash|Delete)$/,
				} )
				.click();

			// Core sends the editor to the article list, which is the app.
			await page.waitForURL( /page=licensio-kb-articles/ );

			const toast = page.locator( '.ant-notification-notice' );
			const undo = toast.getByRole( 'button', { name: 'Undo' } );

			await expect( undo ).toBeFocused();
			await expect( undo ).toHaveAccessibleDescription(
				'1 article moved to the trash.'
			);
			expect(
				new URL( page.url() ).searchParams.has( 'undo' ),
				'The token leaves the address at once.'
			).toBe( false );
			expect(
				new URL( page.url() ).searchParams.has( 'undo_count' )
			).toBe( false );

			await page.keyboard.press( 'Enter' );
			await expect( toast ).toBeHidden();
			await expect(
				page.getByText( '1 article put back.' )
			).toBeVisible();
			await expect( page.locator( '.lkb-page__title' ) ).toBeFocused();
			expect( wpEval( `echo get_post_status( ${ id } );` ) ).toBe(
				'publish'
			);

			// A reload offers nothing again.
			await page.reload();
			await expect(
				page.locator( '#licensio-kb-articles #lkb-main' )
			).toBeVisible();
			await expect( toast ).toHaveCount( 0 );
		} finally {
			wpEval( `wp_delete_post( ${ id }, true );` );
		}
	} );

	test( 'Tab stays inside the open Filters and Columns popovers', async ( {
		page,
	} ) => {
		const app = await openContentManager( page, 'articles' );
		const filtersButton = app.getByRole( 'button', { name: 'Filters' } );
		const filters = page
			.locator( '.ant-popover' )
			.filter( { hasText: 'Clear filters' } );

		await filtersButton.click();
		await expect(
			filters.getByLabel( 'Filter by category' )
		).toBeFocused();

		// Back from the first control goes round to the last, and on to the first again.
		await page.keyboard.press( 'Shift+Tab' );
		await expect(
			filters.getByRole( 'checkbox', { name: 'Needs attention' } )
		).toBeFocused();
		await page.keyboard.press( 'Tab' );
		await expect(
			filters.getByLabel( 'Filter by category' )
		).toBeFocused();
		await page.keyboard.press( 'Escape' );
		await expect( filtersButton ).toBeFocused();

		const columnsButton = app.getByRole( 'button', {
			name: 'Columns and rows per page',
		} );
		const columns = page
			.locator( '.ant-popover' )
			.filter( { hasText: 'Rows per page' } );

		await columnsButton.click();
		await expect(
			columns.getByRole( 'checkbox', { name: 'Author' } )
		).toBeFocused();

		// The rows per page group is one stop, on its checked option.
		await page.keyboard.press( 'Shift+Tab' );
		await expect(
			columns.getByRole( 'radio', { checked: true } )
		).toBeFocused();
		await page.keyboard.press( 'Tab' );
		await expect(
			columns.getByRole( 'checkbox', { name: 'Author' } )
		).toBeFocused();
		await page.keyboard.press( 'Escape' );
		await expect( columnsButton ).toBeFocused();
	} );

	test( 'the views and rating period is one tab stop with arrow keys between its options', async ( {
		page,
	} ) => {
		const app = await openContentManager( page, 'articles' );

		await app.getByRole( 'radio', { name: '30 days' } ).focus();
		await page.keyboard.press( 'ArrowRight' );
		await expect(
			app.getByRole( 'radio', { name: '90 days' } )
		).toBeChecked();
		await expect( page ).toHaveURL( /window=90/ );

		await page.keyboard.press( 'Tab' );
		await expect(
			app.getByRole( 'button', { name: 'Columns and rows per page' } )
		).toBeFocused();
		await page.keyboard.press( 'Shift+Tab' );
		await expect(
			app.getByRole( 'radio', { name: '90 days' } )
		).toBeFocused();
	} );

	test( 'Escape cancels a quick edit and returns focus to its button', async ( {
		page,
	} ) => {
		const app = await openContentManager( page, 'articles' );
		const writes = recordWrites( page );
		const rows = app.locator( '.ant-table-row' );
		const button = rows
			.first()
			.getByRole( 'button', { name: 'Quick edit' } );
		const form = app.locator( '.lkb-quickedit:visible' );
		const list = page.locator(
			'.ant-select-dropdown:not(.ant-select-dropdown-hidden)'
		);

		await button.click();
		await expect( form.getByLabel( 'Title' ) ).toBeFocused();
		await form.getByLabel( 'Title' ).fill( 'Not saved' );

		// Escape on the open author list closes only the list.
		await form.getByLabel( 'Author' ).click();
		await expect( list ).toBeVisible();
		await page.keyboard.press( 'Escape' );
		await expect( list ).toBeHidden();
		await expect( form ).toBeVisible();

		await form.getByLabel( 'Title' ).focus();
		await page.keyboard.press( 'Escape' );
		await expect( form ).toHaveCount( 0 );
		await expect( button ).toBeFocused();

		// The table keeps the closed form, hidden; Escape still closes the next one.
		const second = rows
			.nth( 1 )
			.getByRole( 'button', { name: 'Quick edit' } );

		await second.click();
		await expect( form.getByLabel( 'Title' ) ).toBeFocused();
		await page.keyboard.press( 'Escape' );
		await expect( form ).toHaveCount( 0 );
		await expect( second ).toBeFocused();
		expect( writes ).toHaveLength( 0 );
	} );

	test( 'the pager keeps focus when the page it moved to disables the pressed control', async ( {
		page,
	} ) => {
		test.skip( ! wpCli, 'Set LICENSIO_KB_WP_CLI to run.' );

		// One more than a page of 20, so the second page is the last.
		const word = `Pager${ Date.now() }`;
		const ids = wpEval( `
			$ids = array();
			for ( $i = 1; $i <= 21; $i++ ) {
				$ids[] = wp_insert_post( array( 'post_type' => 'licensio_article', 'post_status' => 'publish', 'post_title' => '${ word } ' . $i ) );
			}
			echo implode( ',', $ids );
		` );

		try {
			await page.goto(
				contentManagerUrl( 'articles' ) + `&search=${ word }`
			);

			const app = page.locator( '#licensio-kb-articles' );
			const current = app.locator( '.ant-pagination-item-active' );

			await expect( app.locator( '.ant-table-row' ) ).toHaveCount( 20 );

			await app.locator( '.ant-pagination-next' ).focus();
			await page.keyboard.press( 'Enter' );
			await expect( app.locator( '.ant-table-row' ) ).toHaveCount( 1 );
			await expect( current ).toHaveText( '2' );
			await expect( current ).toBeFocused();

			await app.locator( '.ant-pagination-prev' ).focus();
			await page.keyboard.press( 'Enter' );
			await expect( app.locator( '.ant-table-row' ) ).toHaveCount( 20 );
			await expect( current ).toHaveText( '1' );
			await expect( current ).toBeFocused();
		} finally {
			wpEval( `
				foreach ( array( ${ ids } ) as $id ) {
					wp_delete_post( $id, true );
				}
			` );
		}
	} );
} );
