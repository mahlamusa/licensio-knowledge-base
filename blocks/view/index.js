/**
 * The knowledge base view block, in the editor.
 *
 * The block renders the same view body the classic templates include, so the
 * editor previews it through ServerSideRender. The server fills the preview
 * with a real article, category, tag or search, because a template has no
 * article of its own. When there is nothing to preview yet, the editor shows
 * a labelled placeholder instead.
 *
 * @since 1.0.0
 */

import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, Placeholder, SelectControl } from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';
import { __ } from '@wordpress/i18n';

import metadata from './block.json';

/**
 * The views the block can render, with their labels.
 *
 * @since 1.0.0
 *
 * @return {Object<string, string>} View name to label.
 */
function viewLabels() {
	return {
		archive: __( 'Knowledge base home', 'licensio-knowledge-base' ),
		category: __( 'Knowledge base category', 'licensio-knowledge-base' ),
		single: __( 'Knowledge base article', 'licensio-knowledge-base' ),
		tag: __( 'Knowledge base tag', 'licensio-knowledge-base' ),
		search: __(
			'Knowledge base search results',
			'licensio-knowledge-base'
		),
	};
}

/**
 * The placeholder shown when the server has nothing to preview.
 *
 * @since 1.0.0
 *
 * @param {Object} props            Placeholder props.
 * @param {Object} props.attributes The block attributes.
 *
 * @return {Element} The placeholder.
 */
function EmptyPreview( { attributes } ) {
	const labels = viewLabels();
	const view = attributes && labels[ attributes.view ] ? attributes.view : '';

	return (
		<Placeholder
			icon="book"
			label={
				view
					? labels[ view ]
					: __( 'Knowledge base layout', 'licensio-knowledge-base' )
			}
			instructions={ __(
				'This is where the knowledge base layout renders. Publish an article to see a preview here.',
				'licensio-knowledge-base'
			) }
		/>
	);
}

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
	const labels = viewLabels();

	return (
		<div { ...blockProps }>
			<InspectorControls>
				<PanelBody
					title={ __(
						'Knowledge base view',
						'licensio-knowledge-base'
					) }
				>
					<SelectControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'View', 'licensio-knowledge-base' ) }
						help={ __(
							'Each view only renders on its own kind of page.',
							'licensio-knowledge-base'
						) }
						value={ attributes.view }
						options={ Object.keys( labels ).map( ( value ) => ( {
							value,
							label: labels[ value ],
						} ) ) }
						onChange={ ( view ) => setAttributes( { view } ) }
					/>
				</PanelBody>
			</InspectorControls>

			<ServerSideRender
				block={ metadata.name }
				attributes={ attributes }
				EmptyResponsePlaceholder={ EmptyPreview }
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
