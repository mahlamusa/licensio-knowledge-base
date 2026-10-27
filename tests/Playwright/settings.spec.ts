/**
 * The settings screen, end to end.
 *
 * Proves the acceptance criteria for #10 that only a real WordPress can answer:
 * a brand colour changed on the settings screen reaches the front end without a
 * cache flush, a save runs without a confirmation, discarding edits asks
 * first, unsaved edits warn before the browser leaves, and reset puts every
 * value back.
 *
 * The suite needs a running WordPress with this plugin active and an
 * administrator account, so it is skipped unless `LICENSIO_KB_E2E=1` and the
 * two credential variables are present. CI provides both through
 * `bin/e2e-env.sh`.
 *
 * @since 1.0.0
 */

import { expect, test, type Locator, type Page } from '@playwright/test';
import {
	answerConfirm,
	confirmDialog,
	expectNoConfirmDialog,
} from './helpers/content-manager';
import { openDashboard, password, user } from './helpers/session';

const enabled = process.env.LICENSIO_KB_E2E === '1';

const SETTINGS_URL =
	'/wp-admin/edit.php?post_type=licensio_article&page=licensio-kb-settings';

const BRAND = '#b91c1c';

/**
 * Open the settings screen and wait for the app to mount.
 *
 * @since 1.0.0
 *
 * @param {Page} page The browser page.
 *
 * @return {Promise<void>} Resolves once the screen has rendered.
 */
async function openSettings( page: Page ): Promise< void > {
	await page.goto( SETTINGS_URL );
	await expect(
		page.getByRole( 'heading', { name: 'Settings', exact: true } )
	).toBeVisible();
	await expect( sectionButton( page, 'General' ) ).toBeVisible();
}

/**
 * The sidebar button that opens a settings section.
 *
 * @since 1.0.0
 *
 * @param {Page}   page  The browser page.
 * @param {string} label The section label.
 *
 * @return {Locator} The button.
 */
function sectionButton( page: Page, label: string ): Locator {
	return page
		.getByRole( 'navigation', { name: 'Settings sections' } )
		.getByRole( 'button', { name: label, exact: true } );
}

/**
 * The text input of the brand colour row.
 *
 * @since 1.0.0
 *
 * @param {Page} page The browser page.
 *
 * @return {Locator} The input.
 */
function brandField( page: Page ): Locator {
	return page
		.locator( '.lkb-field', { hasText: 'Brand colour' } )
		.locator( 'input.ant-input' );
}

/**
 * Move to the Appearance tab and type a brand colour.
 *
 * @since 1.0.0
 *
 * @param {Page}   page  The browser page.
 * @param {string} value The colour to type.
 *
 * @return {Promise<void>} Resolves once the field holds the value.
 */
async function setBrandColour( page: Page, value: string ): Promise< void > {
	await sectionButton( page, 'Appearance' ).click();

	const field = brandField( page );

	await field.fill( value );
	await field.blur();
}

test.describe( 'Knowledge base settings', () => {
	// Every test writes the same stored settings, so they cannot overlap.
	test.describe.configure( { mode: 'serial' } );

	test.skip(
		! enabled || ! user || ! password,
		'Set LICENSIO_KB_E2E=1, LICENSIO_KB_ADMIN_USER and LICENSIO_KB_ADMIN_PASSWORD to run.'
	);

	test.beforeEach( async ( { page } ) => {
		await openDashboard( page );
	} );

	test.afterEach( async ( { page } ) => {
		// A test may leave unsaved edits behind; let the guard wave this through.
		page.on( 'dialog', ( dialog ) =>
			dialog.accept().catch( () => undefined )
		);

		await openSettings( page );
		await page.getByRole( 'button', { name: 'Reset all' } ).click();
		await answerConfirm( page, 'confirm' );
		await expect(
			page.getByText( 'Every setting is back to its default.' )
		).toBeVisible();
	} );

	test( 'a brand colour saved here reaches the front end', async ( {
		page,
	} ) => {
		await openSettings( page );
		await setBrandColour( page, BRAND );

		// The preview reflects the draft before anything is saved.
		await expect(
			page.locator( '.licensio-kb-settings__preview' )
		).toHaveCSS( '--licensio-kb-brand', BRAND );

		// A save is an update, so it runs on click without a confirmation.
		await page.getByRole( 'button', { name: 'Save changes' } ).click();
		await expect( page.getByText( 'Settings saved.' ) ).toBeVisible();
		await expectNoConfirmDialog( page );

		// No permalink save, no cache flush: straight to the archive.
		await page.goto( '/?post_type=licensio_article' );

		const root = page.locator( '.licensio-kb' ).first();

		await expect( root ).toHaveCSS( '--licensio-kb-brand', BRAND );
	} );

	test( 'clearing the brand colour hands the colours back to the theme', async ( {
		page,
	} ) => {
		await openSettings( page );
		await setBrandColour( page, BRAND );
		await page.getByRole( 'button', { name: 'Save changes' } ).click();
		await expect( page.getByText( 'Settings saved.' ) ).toBeVisible();

		// An empty colour is a valid value, not a rejected one.
		await setBrandColour( page, '' );

		const [ saved ] = await Promise.all( [
			page.waitForResponse(
				( response ) =>
					response.url().includes( 'settings' ) &&
					response.request().method() === 'POST'
			),
			page.getByRole( 'button', { name: 'Save changes' } ).click(),
		] );

		expect( saved.ok() ).toBe( true );

		await page.goto( '/?post_type=licensio_article' );

		const brand = await page
			.locator( '.licensio-kb' )
			.first()
			.evaluate( ( element ) =>
				getComputedStyle( element )
					.getPropertyValue( '--licensio-kb-brand' )
					.trim()
			);

		expect( brand ).toBe( '' );
	} );

	test( 'discarding unsaved changes asks first', async ( { page } ) => {
		await openSettings( page );
		await setBrandColour( page, '#0f766e' );

		// Cancel keeps the edit.
		await page.getByRole( 'button', { name: 'Discard' } ).click();
		await expect( confirmDialog( page ) ).toContainText(
			'Discard your unsaved changes?'
		);
		await answerConfirm( page, 'cancel' );
		await expect( brandField( page ) ).toHaveValue( '#0f766e' );

		// Confirming puts the stored value back.
		await page.getByRole( 'button', { name: 'Discard' } ).click();
		await answerConfirm( page, 'confirm' );
		await expect( brandField( page ) ).not.toHaveValue( '#0f766e' );
		await expect( page.locator( '.lkb-bar__status' ) ).toContainText(
			'All changes saved.'
		);

		// Discard is disabled now, so focus lands on the page title, not the body.
		await expect(
			page.getByRole( 'button', { name: 'Discard' } )
		).toBeDisabled();
		await expect( page.locator( '.lkb-page__title' ) ).toBeFocused();
	} );

	test( 'Reset all asks in a centred dialog that blocks the page', async ( {
		page,
	} ) => {
		await openSettings( page );

		const trigger = page.getByRole( 'button', { name: 'Reset all' } );
		const dialog = confirmDialog( page );
		const box = dialog.locator( '.ant-modal' );
		const ok = dialog.getByRole( 'button', { name: 'Reset everything' } );

		await trigger.click();

		// An alertdialog named by its question and described by its body.
		await expect( dialog ).toBeVisible();
		await expect( dialog ).toHaveAccessibleName( 'Reset every setting?' );
		await expect( dialog ).toHaveAccessibleDescription(
			/Every setting goes back to its default/
		);
		await expect(
			dialog.getByRole( 'button', { name: 'Cancel' } )
		).toBeFocused();
		await expect( ok ).toHaveClass( /ant-btn-dangerous/ );

		// Centred on the screen, not beside the button, once it has zoomed in.
		const viewport = page.viewportSize() ?? { width: 0, height: 0 };
		const offCentre = async (): Promise< number > => {
			const rect = await box.boundingBox();

			return rect
				? Math.max(
						Math.abs(
							rect.x + rect.width / 2 - viewport.width / 2
						),
						Math.abs(
							rect.y + rect.height / 2 - viewport.height / 2
						)
					)
				: Infinity;
		};

		await expect.poll( offCentre ).toBeLessThan( 2 );

		// A translucent mask covers the whole screen, admin menu included,
		// and a click on it neither reaches the page nor closes the dialog.
		const mask = page.locator( '.ant-modal-mask' ).last();

		await expect( mask ).toBeVisible();
		await expect( mask ).toHaveCSS(
			'background-color',
			/rgba\(0, 0, 0, 0\.\d+\)/
		);
		await page.mouse.click( 40, 60 );
		await expect( page ).toHaveURL( /page=licensio-kb-settings/ );
		await expect( dialog ).toBeVisible();
		expect(
			await page.evaluate(
				() =>
					document
						.elementFromPoint( 40, 60 )
						?.closest( '#adminmenu' ) === null
			)
		).toBe( true );

		// Tab stays inside the dialog.
		for ( let i = 0; i < 4; i++ ) {
			await page.keyboard.press( 'Tab' );
			expect(
				await dialog.evaluate( ( el ) =>
					el.contains( el.ownerDocument.activeElement )
				)
			).toBe( true );
		}

		// Escape cancels and hands focus back to the button.
		await page.keyboard.press( 'Escape' );
		await expect( dialog ).toBeHidden();
		await expect( trigger ).toBeFocused();

		// While the request runs the confirm button spins; a failure keeps
		// the dialog open with the error inside it.
		let release = (): void => undefined;
		const held = new Promise< void >( ( resolve ) => {
			release = resolve;
		} );

		await page.route(
			( url ) =>
				decodeURIComponent( url.href ).includes(
					'licensio-kb/v1/settings'
				),
			async ( route ) => {
				if ( 'DELETE' !== route.request().method() ) {
					await route.fallback();
					return;
				}

				await held;
				await route.fulfill( {
					status: 500,
					contentType: 'application/json',
					body: JSON.stringify( {
						code: 'lkb_test_failure',
						message: 'The reset failed on purpose.',
						data: { status: 500 },
					} ),
				} );
			}
		);

		await trigger.click();
		await ok.click();
		await expect( ok ).toHaveClass( /ant-btn-loading/ );
		await expect(
			dialog.getByRole( 'button', { name: 'Cancel' } )
		).toBeDisabled();
		release();

		await expect( dialog.getByRole( 'alert' ) ).toContainText(
			'The reset failed on purpose.'
		);
		// The page alert behind the dialog stays empty, so the error is
		// announced once.
		await expect(
			page.getByText( 'The reset failed on purpose.' )
		).toHaveCount( 1 );
		await expect( ok ).not.toHaveClass( /ant-btn-loading/ );
		await expect( dialog ).toBeVisible();

		await page.unrouteAll( { behavior: 'wait' } );
		await answerConfirm( page, 'cancel' );
		await expect( trigger ).toBeFocused();
	} );

	test( 'the confirmation does not animate when reduced motion is asked for', async ( {
		page,
	} ) => {
		await page.emulateMedia( { reducedMotion: 'reduce' } );
		await openSettings( page );

		// Note every zoom or fade class Ant Design adds while the dialog opens.
		await page.evaluate( () => {
			const seen: string[] = [];

			( window as unknown as { lkbMotion: string[] } ).lkbMotion = seen;
			new MutationObserver( ( records ) => {
				records.forEach( ( record ) => {
					const nodes =
						'attributes' === record.type
							? [ record.target ]
							: Array.from( record.addedNodes );

					nodes.forEach( ( node ) => {
						if (
							node instanceof Element &&
							/ant-(zoom|fade)-/.test( node.className )
						) {
							seen.push( node.className );
						}
					} );
				} );
			} ).observe( document.body, {
				subtree: true,
				childList: true,
				attributes: true,
				attributeFilter: [ 'class' ],
			} );
		} );

		await page.getByRole( 'button', { name: 'Reset all' } ).click();
		await expect( confirmDialog( page ) ).toBeVisible();
		await answerConfirm( page, 'cancel' );

		expect(
			await page.evaluate(
				() => ( window as unknown as { lkbMotion: string[] } ).lkbMotion
			)
		).toEqual( [] );
	} );

	test( 'leaving with unsaved changes warns first', async ( { page } ) => {
		await openSettings( page );
		await setBrandColour( page, '#0f766e' );

		await expect( page.locator( '.lkb-bar__status' ) ).toContainText(
			'Unsaved changes'
		);

		let asked = false;

		page.once( 'dialog', async ( dialog ) => {
			asked = true;

			await dialog.dismiss();
		} );

		await page.getByRole( 'link', { name: 'Dashboard' } ).first().click();

		await expect.poll( () => asked ).toBe( true );
		await expect( page ).toHaveURL( /page=licensio-kb-settings/ );
		await expect( brandField( page ) ).toHaveValue( '#0f766e' );
	} );

	test( 'an invalid value is refused per field and nothing is saved', async ( {
		page,
	} ) => {
		await openSettings( page );

		// The number control clamps to its bounds in the browser, so the
		// invalid value goes into the free-text side of the colour row.
		const perPage = page
			.locator( '.lkb-field', { hasText: 'Articles per page' } )
			.locator( 'input' );

		await perPage.fill( '7' );
		await perPage.blur();
		await setBrandColour( page, 'not-a-colour' );

		await page.getByRole( 'button', { name: 'Save changes' } ).click();

		await expect(
			page.getByText( 'One or more settings are invalid' )
		).toBeVisible();
		await expect(
			page.locator( '.lkb-field.is-invalid', { hasText: 'Brand colour' } )
		).toBeVisible();

		// The good field in the same payload was not written either; the
		// previous test's Reset all left it on its default of 20.
		page.on( 'dialog', ( dialog ) =>
			dialog.accept().catch( () => undefined )
		);
		await page.reload();
		await expect( sectionButton( page, 'General' ) ).toBeVisible();

		await expect(
			page
				.locator( '.lkb-field', { hasText: 'Articles per page' } )
				.locator( 'input' )
		).toHaveValue( '20' );
	} );
} );
