/**
 * The knowledge base category grid block, in the editor.
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
		__( 'Top level', 'licensio-knowledge-base' )
	);

	return (
		<div { ...blockProps }>
			<InspectorControls>
				<PanelBody
					title={ __( 'Categories', 'licensio-knowledge-base' ) }
				>
					<SelectControl
						__nextHasNoMarginBottom
						label={ __(
							'Show children of',
							'licensio-knowledge-base'
						) }
						value={ attributes.parent }
						options={ categories }
						onChange={ ( parent ) => setAttributes( { parent } ) }
					/>
					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'Order by', 'licensio-knowledge-base' ) }
						value={ attributes.orderBy }
						options={ [
							{
								label: __( 'Name', 'licensio-knowledge-base' ),
								value: 'name',
							},
							{
								label: __(
									'Article count',
									'licensio-knowledge-base'
								),
								value: 'count',
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
									'Ascending',
									'licensio-knowledge-base'
								),
								value: 'asc',
							},
							{
								label: __(
									'Descending',
									'licensio-knowledge-base'
								),
								value: 'desc',
							},
						] }
						onChange={ ( order ) => setAttributes( { order } ) }
					/>
					<RangeControl
						__nextHasNoMarginBottom
						label={ __(
							'Categories (0 for all)',
							'licensio-knowledge-base'
						) }
						value={ attributes.count }
						min={ 0 }
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
							'Show article counts',
							'licensio-knowledge-base'
						) }
						checked={ attributes.showCount }
						onChange={ ( showCount ) =>
							setAttributes( { showCount } )
						}
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Show icons', 'licensio-knowledge-base' ) }
						checked={ attributes.showIcon }
						onChange={ ( showIcon ) =>
							setAttributes( { showIcon } )
						}
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							'Show sub-categories',
							'licensio-knowledge-base'
						) }
						checked={ attributes.showChildren }
						onChange={ ( showChildren ) =>
							setAttributes( { showChildren } )
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
