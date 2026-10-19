/**
 * The knowledge base search block, in the editor.
 *
 * Renders through ServerSideRender so the editor preview is produced by the
 * same PHP render callback the frontend and the shortcode use. That is the
 * point of pairing the three: a preview built from separate JSX would be a
 * second implementation of the markup, free to drift from the one that ships.
 *
 * @since 1.0.0
 */

import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, TextControl } from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';
import { __ } from '@wordpress/i18n';

import metadata from './block.json';

/**
 * The block's editor view.
 *
 * A named, capitalised component rather than an inline `edit()` method, because
 * `useBlockProps()` is a React hook and the rules of hooks are enforced by the
 * component's name.
 *
 * @since 1.0.0
 *
 * @param {Object}   props               Block props.
 * @param {Object}   props.attributes    The block attributes.
 * @param {Function} props.setAttributes Attribute setter.
 *
 * @return {Element} The editor view.
 */
function Edit( { attributes, setAttributes } ) {
	const blockProps = useBlockProps();

	return (
		<div { ...blockProps }>
			<InspectorControls>
				<PanelBody title={ __( 'Search', 'licensio-knowledge-base' ) }>
					<TextControl
						__nextHasNoMarginBottom
						label={ __( 'Placeholder', 'licensio-knowledge-base' ) }
						value={ attributes.placeholder }
						onChange={ ( placeholder ) =>
							setAttributes( { placeholder } )
						}
					/>
					<TextControl
						__nextHasNoMarginBottom
						label={ __(
							'Accessible label',
							'licensio-knowledge-base'
						) }
						help={ __(
							'Announced to screen readers. Not shown on screen.',
							'licensio-knowledge-base'
						) }
						value={ attributes.label }
						onChange={ ( label ) => setAttributes( { label } ) }
					/>
					<TextControl
						__nextHasNoMarginBottom
						label={ __( 'Button text', 'licensio-knowledge-base' ) }
						value={ attributes.button }
						onChange={ ( button ) => setAttributes( { button } ) }
					/>
				</PanelBody>
			</InspectorControls>

			<ServerSideRender
				block={ metadata.name }
				attributes={ attributes }
			/>
		</div>
	);
}

registerBlockType( metadata.name, {
	edit: Edit,

	// Rendered in PHP, so nothing is saved to post content.
	save() {
		return null;
	},
} );
