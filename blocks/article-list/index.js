/**
 * The knowledge base article list block, in the editor.
 *
 * Rendered through ServerSideRender so the preview is produced by the same PHP
 * render callback the front end and the shortcode use. A preview built from
 * separate JSX would be a second implementation of the markup, free to drift
 * from the one that ships.
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
	const categories = useCategoryOptions(
		__( 'All categories', 'lisensio-knowledge-base' )
	);

	return (
		<div { ...blockProps }>
			<InspectorControls>
				<PanelBody
					title={ __( 'Articles', 'lisensio-knowledge-base' ) }
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
									'Published date',
									'lisensio-knowledge-base'
								),
								value: 'date',
							},
							{
								label: __(
									'Last modified',
									'lisensio-knowledge-base'
								),
								value: 'modified',
							},
							{
								label: __( 'Title', 'lisensio-knowledge-base' ),
								value: 'title',
							},
							{
								label: __(
									'Manual order',
									'lisensio-knowledge-base'
								),
								value: 'menu_order',
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
									'Descending',
									'lisensio-knowledge-base'
								),
								value: 'desc',
							},
							{
								label: __(
									'Ascending',
									'lisensio-knowledge-base'
								),
								value: 'asc',
							},
						] }
						onChange={ ( order ) => setAttributes( { order } ) }
					/>
					<RangeControl
						__nextHasNoMarginBottom
						label={ __( 'Articles', 'lisensio-knowledge-base' ) }
						value={ attributes.count }
						min={ 1 }
						max={ 50 }
						onChange={ ( count ) => setAttributes( { count } ) }
					/>
					<RangeControl
						__nextHasNoMarginBottom
						label={ __( 'Columns', 'lisensio-knowledge-base' ) }
						value={ attributes.columns }
						min={ 1 }
						max={ 4 }
						onChange={ ( columns ) => setAttributes( { columns } ) }
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							'Show excerpts',
							'lisensio-knowledge-base'
						) }
						checked={ attributes.showExcerpt }
						onChange={ ( showExcerpt ) =>
							setAttributes( { showExcerpt } )
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
