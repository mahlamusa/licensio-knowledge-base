/**
 * The knowledge base FAQ block, in the editor.
 *
 * Rendered through ServerSideRender so the preview is produced by the same PHP
 * render callback the front end and the shortcode use.
 *
 * @since 1.0.0
 */

import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import {
	PanelBody,
	RangeControl,
	SelectControl,
	ToggleControl,
} from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';
import { __ } from '@wordpress/i18n';

import metadata from './block.json';
import { useCategoryOptions } from '../shared/categories.js';

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
	const categories = useCategoryOptions(
		__( 'All categories', 'lisensio-knowledge-base' )
	);

	return (
		<div { ...blockProps }>
			<InspectorControls>
				<PanelBody
					title={ __( 'Questions', 'lisensio-knowledge-base' ) }
				>
					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'Category', 'lisensio-knowledge-base' ) }
						value={ attributes.category }
						options={ categories }
						onChange={ ( category ) =>
							setAttributes( { category } )
						}
					/>
					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'Order by', 'lisensio-knowledge-base' ) }
						value={ attributes.orderBy }
						options={ [
							{
								label: __(
									'Manual order',
									'lisensio-knowledge-base'
								),
								value: 'menu_order',
							},
							{
								label: __(
									'Published date',
									'lisensio-knowledge-base'
								),
								value: 'date',
							},
							{
								label: __( 'Title', 'lisensio-knowledge-base' ),
								value: 'title',
							},
						] }
						onChange={ ( orderBy ) => setAttributes( { orderBy } ) }
					/>
					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'Order', 'lisensio-knowledge-base' ) }
						value={ attributes.order }
						options={ [
							{
								label: __(
									'Ascending',
									'lisensio-knowledge-base'
								),
								value: 'asc',
							},
							{
								label: __(
									'Descending',
									'lisensio-knowledge-base'
								),
								value: 'desc',
							},
						] }
						onChange={ ( order ) => setAttributes( { order } ) }
					/>
					<RangeControl
						__nextHasNoMarginBottom
						label={ __( 'Questions', 'lisensio-knowledge-base' ) }
						value={ attributes.count }
						min={ 1 }
						max={ 50 }
						onChange={ ( count ) => setAttributes( { count } ) }
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							'Show answers',
							'lisensio-knowledge-base'
						) }
						help={ __(
							'Use the article excerpt as the answer.',
							'lisensio-knowledge-base'
						) }
						checked={ attributes.showExcerpt }
						onChange={ ( showExcerpt ) =>
							setAttributes( { showExcerpt } )
						}
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							'Open the first question',
							'lisensio-knowledge-base'
						) }
						checked={ attributes.openFirst }
						onChange={ ( openFirst ) =>
							setAttributes( { openFirst } )
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
