/**
 * A category's icon source, and the icon fields a save sends for it.
 *
 * A category draws one icon, so the drawer asks for one source and, on save,
 * sends the picked source's value with every other icon field empty. That
 * clears the sources not picked, so no hidden icon is left to win by
 * precedence on the front end.
 *
 * @since 1.0.0
 */

import type { CategoryInput, CategoryItem } from './types';

/**
 * Where a category's icon comes from.
 */
export type IconSource = 'upload' | 'inline' | 'dashicon';

/**
 * The source a category's icon uses now.
 *
 * In the front end's order of precedence, so a category that still has more
 * than one set (from an import, say) opens on the icon it actually shows.
 * A category with no icon opens on Upload, the first source.
 *
 * @since 1.0.0
 *
 * @param {CategoryItem|null} item The category, or null for a new one.
 *
 * @return {IconSource} The source.
 */
export function iconSourceOf( item: CategoryItem | null ): IconSource {
	if ( item?.icon_svg || item?.icon_data ) {
		return 'inline';
	}

	if ( item?.icon_id ) {
		return 'upload';
	}

	if ( item?.icon ) {
		return 'dashicon';
	}

	return 'upload';
}

/**
 * The icon fields to send for the picked source, the others emptied.
 *
 * The inline value goes to `icon_data` when it is a Base64 raster image and
 * to `icon_svg` otherwise; the server decodes a Base64 SVG sent there.
 * `icon_id` is left out when the upload is the picked source and unchanged:
 * if its file was deleted from the media library since, the server would
 * refuse it and block every other change to the category.
 *
 * @since 1.0.0
 *
 * @param {Object}            icon        The form's icon fields.
 * @param {IconSource}        icon.source The picked source.
 * @param {string}            icon.name   The Dashicon name.
 * @param {number}            icon.id     The uploaded icon's attachment ID.
 * @param {string}            icon.inline The inline SVG or Base64 image.
 * @param {CategoryItem|null} item        The category as loaded, or null.
 *
 * @return {CategoryInput} The icon fields.
 */
export function iconInput(
	icon: { source: IconSource; name: string; id: number; inline: string },
	item: CategoryItem | null
): CategoryInput {
	const inline = 'inline' === icon.source ? icon.inline.trim() : '';
	const raster = /^data:image\/(?!svg\+xml)/i.test( inline );
	const input: CategoryInput = {
		icon: 'dashicon' === icon.source ? icon.name.trim() : '',
		icon_svg: raster ? '' : inline,
		icon_data: raster ? inline : '',
	};

	if ( 'upload' !== icon.source ) {
		input.icon_id = 0;
	} else if ( icon.id !== item?.icon_id ) {
		input.icon_id = icon.id;
	}

	return input;
}
