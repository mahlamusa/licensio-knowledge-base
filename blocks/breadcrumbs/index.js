/**
 * The knowledge base breadcrumbs block, in the editor.
 *
 * Rendered through ServerSideRender so the preview is produced by the same PHP
 * render callback the front end and the shortcode use. The trail depends on
 * what is being viewed, so in the editor it shows the trail for the page the
 * block sits on rather than a made-up example.
 *
 * @since 1.0.0
 */

import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, ToggleControl } from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';
import { __ } from '@wordpress/i18n';

import metadata from './block.json';

/**
 * The block's editor view.
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
				<PanelBody
					title={ __( 'Breadcrumbs', 'licensio-knowledge-base' ) }
				>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							'Show the home link',
							'licensio-knowledge-base'
						) }
						help={ __(
							'Start the trail at the site home rather than at the knowledge base.',
							'licensio-knowledge-base'
						) }
						checked={ attributes.showHome }
						onChange={ ( showHome ) =>
							setAttributes( { showHome } )
						}
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
