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
		__( 'All categories', 'licensio-knowledge-base' )
	);

	return (
		<div { ...blockProps }>
			<InspectorControls>
				<PanelBody
					title={ __( 'Articles', 'licensio-knowledge-base' ) }
				>
					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'Category', 'licensio-knowledge-base' ) }
						value={ attributes.category }
						options={ categories }
						onChange={ ( category ) =>
							setAttributes( { category } )
						}
					/>
					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'Order by', 'licensio-knowledge-base' ) }
						value={ attributes.orderBy }
						options={ [
							{
								label: __(
									'Published date',
									'licensio-knowledge-base'
								),
								value: 'date',
							},
							{
								label: __(
									'Last modified',
									'licensio-knowledge-base'
								),
								value: 'modified',
							},
							{
								label: __( 'Title', 'licensio-knowledge-base' ),
								value: 'title',
							},
							{
								label: __(
									'Manual order',
									'licensio-knowledge-base'
								),
								value: 'menu_order',
							},
						] }
						onChange={ ( orderBy ) => setAttributes( { orderBy } ) }
					/>
					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'Order', 'licensio-knowledge-base' ) }
						value={ attributes.order }
						options={ [
							{
								label: __(
									'Descending',
									'licensio-knowledge-base'
								),
								value: 'desc',
							},
							{
								label: __(
									'Ascending',
									'licensio-knowledge-base'
								),
								value: 'asc',
							},
						] }
						onChange={ ( order ) => setAttributes( { order } ) }
					/>
					<RangeControl
						__nextHasNoMarginBottom
						label={ __( 'Articles', 'licensio-knowledge-base' ) }
						value={ attributes.count }
						min={ 1 }
						max={ 50 }
						onChange={ ( count ) => setAttributes( { count } ) }
					/>
					<RangeControl
						__nextHasNoMarginBottom
						label={ __( 'Columns', 'licensio-knowledge-base' ) }
						value={ attributes.columns }
						min={ 1 }
						max={ 4 }
						onChange={ ( columns ) => setAttributes( { columns } ) }
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							'Show excerpts',
							'licensio-knowledge-base'
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
