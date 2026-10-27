/**
 * The WordPress media modal, for the icon and image fields.
 *
 * `Licensio_KB_Categories_Admin` calls `wp_enqueue_media()`, so `wp.media`
 * is normally on the page. Only the few calls used here are typed.
 *
 * @since 1.0.0
 */

/**
 * A picked attachment.
 */
export interface PickedMedia {
	id: number;
	url: string;
}

interface Attachment {
	id: number;
	url: string;
	sizes?: Record< string, { url: string } | undefined >;
}

interface MediaFrame {
	on: ( event: 'select' | 'close', callback: () => void ) => void;
	open: () => void;
	state: () => {
		get: ( key: 'selection' ) => {
			first: () => { toJSON: () => Attachment } | undefined;
		};
	};
}

type MediaFactory = ( options: {
	title: string;
	button: { text: string };
	multiple: boolean;
	library?: { type: string };
} ) => MediaFrame;

/**
 * The page's `wp.media`, or null when it is missing.
 *
 * @since 1.0.0
 *
 * @return {MediaFactory|null} The media modal factory.
 */
function factory(): MediaFactory | null {
	const media = ( window as unknown as { wp?: { media?: unknown } } ).wp
		?.media;

	return typeof media === 'function' ? ( media as MediaFactory ) : null;
}

/**
 * Whether the media modal is available.
 *
 * @since 1.0.0
 *
 * @return {boolean} True when `wp.media` is on the page.
 */
export function hasMedia(): boolean {
	return null !== factory();
}

/**
 * Open the media modal and wait for an image.
 *
 * @since 1.0.0
 *
 * @param {string} title  The modal title.
 * @param {string} button The select button label.
 *
 * @return {Promise<PickedMedia|null>} The picked image, or null when closed.
 */
export function pickMedia(
	title: string,
	button: string
): Promise< PickedMedia | null > {
	const media = factory();

	if ( ! media ) {
		return Promise.resolve( null );
	}

	return new Promise( ( resolve ) => {
		let settled = false;
		const frame = media( {
			title,
			button: { text: button },
			multiple: false,
			library: { type: 'image' },
		} );

		frame.on( 'select', () => {
			const attachment = frame
				.state()
				.get( 'selection' )
				.first()
				?.toJSON();

			settled = true;
			resolve(
				attachment
					? {
							id: attachment.id,
							url:
								attachment.sizes?.thumbnail?.url ??
								attachment.url,
						}
					: null
			);
		} );

		// The modal closes around a selection too; let `select` answer first.
		frame.on( 'close', () => {
			setTimeout( () => {
				if ( ! settled ) {
					settled = true;
					resolve( null );
				}
			}, 0 );
		} );

		frame.open();
	} );
}
