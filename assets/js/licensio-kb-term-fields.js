/**
 * Media picker for the knowledge base category term fields.
 *
 * A classic jQuery script rather than a Vite entry: it runs on core's
 * edit-tags.php and term.php screens, where it has to cooperate with the rows
 * those screens add over AJAX, and its only dependencies are jQuery and
 * wp.media, both of which core has already put on the page.
 *
 * @since 1.0.0
 */

/* global jQuery */

( function ( $ ) {
	'use strict';

	const strings = window.licensioKbTermFields || {};

	/**
	 * Clear the attachment stored in a field.
	 *
	 * @since 1.0.0
	 *
	 * @param {Object} $field The jQuery-wrapped field container.
	 * @return {void}
	 */
	function removeMedia( $field ) {
		$field.find( '.licensio-kb-media-id' ).val( 0 );
		$field.find( '.licensio-kb-media-preview' ).empty();
		$field.find( '.licensio-kb-media-remove' ).addClass( 'hidden' );
	}

	/**
	 * Open the media modal for a field and write the chosen attachment into it.
	 *
	 * @since 1.0.0
	 *
	 * @param {Object} $field The jQuery-wrapped field container.
	 * @return {void}
	 */
	function selectMedia( $field ) {
		const frame = wp.media( {
			title: strings.title || 'Select an image',
			button: { text: strings.button || 'Use this image' },
			library: { type: 'image' },
			multiple: false,
		} );

		frame.on( 'select', function () {
			const attachment = frame
				.state()
				.get( 'selection' )
				.first()
				.toJSON();

			let url = attachment.url;

			if ( attachment.sizes && attachment.sizes.thumbnail ) {
				url = attachment.sizes.thumbnail.url;
			}

			$field.find( '.licensio-kb-media-id' ).val( attachment.id );
			$field
				.find( '.licensio-kb-media-preview' )
				.html(
					$( '<img>' )
						.attr( 'src', url )
						.attr( 'alt', '' )
						.css( 'max-width', '80px' )
				);
			$field.find( '.licensio-kb-media-remove' ).removeClass( 'hidden' );
		} );

		frame.open();
	}

	$( document ).on( 'click', '.licensio-kb-media-select', function ( event ) {
		event.preventDefault();
		selectMedia( $( this ).closest( '[data-licensio-media-field]' ) );
	} );

	$( document ).on( 'click', '.licensio-kb-media-remove', function ( event ) {
		event.preventDefault();
		removeMedia( $( this ).closest( '[data-licensio-media-field]' ) );
	} );

	/*
	 * The add-category form is submitted over AJAX and core empties its inputs
	 * on success, but it knows nothing about the preview markup or the hidden
	 * media ID, so those would keep the previous category's selection.
	 */
	$( document ).on( 'ajaxSuccess', function ( event, xhr, settings ) {
		if (
			! settings ||
			! settings.data ||
			settings.data.indexOf( 'action=add-tag' ) === -1
		) {
			return;
		}

		$( '#addtag [data-licensio-media-field]' ).each( function () {
			removeMedia( $( this ) );
		} );
	} );
} )( jQuery );
