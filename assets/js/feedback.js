/**
 * Article voting.
 *
 * Dependency-free progressive enhancement over the markup rendered by
 * `templates/feedback-form.php`. Nothing here builds UI: the buttons, the
 * comment field and the live region are already in the document, so a reader
 * with a slow connection sees the question before this file arrives, and a
 * theme that overrides the template keeps its own markup.
 *
 * Behaviour:
 * - A positive vote posts immediately.
 * - A negative vote reveals the comment field and waits, so the reader can say
 *   why. Sending is a second, explicit action.
 * - Every outcome is written into a `role="status"` live region, which is how
 *   a screen reader learns the vote was recorded without focus moving.
 *
 * The endpoint enforces one vote per visitor per article server side. The
 * disabled buttons below are a courtesy, not the rule.
 *
 * @since 1.0.0
 */

( function () {
	'use strict';

	window.lisensioKB = window.lisensioKB || {};

	const settings = window.lisensioKBFeedbackData || {};
	const strings = settings.i18n || {};

	/**
	 * Read one element out of a widget by its data attribute.
	 *
	 * @since 1.0.0
	 *
	 * @param {Element} widget    The widget root.
	 * @param {string}  attribute The data attribute to find.
	 *
	 * @return {Element|null} The element, or null when the template omits it.
	 */
	function part( widget, attribute ) {
		return widget.querySelector( '[' + attribute + ']' );
	}

	/**
	 * Announce a message in the widget's live region.
	 *
	 * @since 1.0.0
	 *
	 * @param {Element} widget  The widget root.
	 * @param {string}  message The message to announce.
	 *
	 * @return {void}
	 */
	function announce( widget, message ) {
		const status = part( widget, 'data-lisensio-kb-feedback-status' );

		if ( ! status ) {
			return;
		}

		// textContent, never innerHTML: the message must never be parsed as markup.
		status.textContent = message || '';
	}

	/**
	 * Turn every button in a widget on or off.
	 *
	 * @since 1.0.0
	 *
	 * @param {Element} widget   The widget root.
	 * @param {boolean} disabled Whether the buttons should be disabled.
	 *
	 * @return {void}
	 */
	function setDisabled( widget, disabled ) {
		widget.querySelectorAll( 'button' ).forEach( function ( button ) {
			button.disabled = disabled;
		} );
	}

	/**
	 * Hide the voting controls once a vote has been accepted.
	 *
	 * @since 1.0.0
	 *
	 * @param {Element} widget The widget root.
	 *
	 * @return {void}
	 */
	function collapse( widget ) {
		const actions = part( widget, 'data-lisensio-kb-feedback-actions' );
		const comment = part( widget, 'data-lisensio-kb-feedback-comment' );

		if ( actions ) {
			actions.hidden = true;
		}

		if ( comment ) {
			comment.hidden = true;
		}
	}

	/**
	 * Post a vote to the REST endpoint.
	 *
	 * @since 1.0.0
	 *
	 * @param {Element} widget  The widget root.
	 * @param {number}  vote    1 for helpful, -1 for not helpful.
	 * @param {string}  comment The optional comment.
	 *
	 * @return {void}
	 */
	function submit( widget, vote, comment ) {
		if ( ! settings.endpoint ) {
			return;
		}

		setDisabled( widget, true );
		announce( widget, strings.sending || '' );

		window
			.fetch( settings.endpoint, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': settings.nonce || '',
				},
				body: JSON.stringify( {
					article_id: parseInt(
						widget.getAttribute( 'data-article-id' ),
						10
					),
					vote,
					comment: comment || '',
				} ),
			} )
			.then( function ( response ) {
				return response.json().then( function ( body ) {
					return { ok: response.ok, body };
				} );
			} )
			.then( function ( result ) {
				if ( ! result.ok ) {
					const known =
						result.body &&
						result.body.code === 'lisensio_already_voted';

					announce(
						widget,
						known
							? strings.alreadyDone || ''
							: ( result.body && result.body.message ) ||
									strings.error ||
									''
					);

					if ( known ) {
						collapse( widget );
					} else {
						setDisabled( widget, false );
					}

					return;
				}

				collapse( widget );
				announce(
					widget,
					result.body.message ||
						( vote > 0 ? strings.thanks : strings.thanksSorry ) ||
						''
				);
			} )
			.catch( function () {
				setDisabled( widget, false );
				announce( widget, strings.error || '' );
			} );
	}

	/**
	 * Attach the behaviour to one widget.
	 *
	 * @since 1.0.0
	 *
	 * @param {Element} widget The widget root.
	 *
	 * @return {void}
	 */
	function enhance( widget ) {
		if ( widget.getAttribute( 'data-lisensio-kb-feedback-ready' ) ) {
			return;
		}

		widget.setAttribute( 'data-lisensio-kb-feedback-ready', '1' );

		const commentBox = part( widget, 'data-lisensio-kb-feedback-comment' );
		const textarea = part( widget, 'data-lisensio-kb-feedback-textarea' );
		const send = part( widget, 'data-lisensio-kb-feedback-send' );

		widget
			.querySelectorAll( '[data-lisensio-kb-vote]' )
			.forEach( function ( button ) {
				button.addEventListener( 'click', function ( event ) {
					const vote = parseInt(
						event.currentTarget.getAttribute(
							'data-lisensio-kb-vote'
						),
						10
					);

					if ( vote < 0 && commentBox ) {
						commentBox.hidden = false;

						if ( textarea ) {
							textarea.focus();
						}

						return;
					}

					submit( widget, vote, '' );
				} );
			} );

		if ( send ) {
			send.addEventListener( 'click', function () {
				submit( widget, -1, textarea ? textarea.value : '' );
			} );
		}
	}

	/**
	 * Enhance every feedback widget on the page.
	 *
	 * @since 1.0.0
	 *
	 * @return {void}
	 */
	function init() {
		document
			.querySelectorAll( '[data-lisensio-kb-feedback]' )
			.forEach( enhance );
	}

	window.lisensioKB.feedback = { init };

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
