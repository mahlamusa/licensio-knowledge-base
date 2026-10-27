/**
 * Knowledge base search type-ahead.
 *
 * Dependency-free by design: no Vue, no jQuery, no build step. It is served
 * straight from `assets/js/` as a classic script so it runs in document order
 * alongside `licensio-kb.js`, which declares the shared namespace it reads its
 * configuration from.
 *
 * Implements the ARIA 1.2 combobox pattern. The input carries `role="combobox"`
 * with `aria-expanded`, `aria-controls` and `aria-activedescendant`; the popup
 * is a sibling `role="listbox"` whose children are `role="option"`. Nothing is
 * focused except the input — `aria-activedescendant` is what moves the screen
 * reader's attention, which is why arrowing through suggestions never steals
 * the caret out of the field the visitor is typing in.
 *
 * @since 1.0.0
 */

( function () {
	'use strict';

	const DEFAULTS = {
		endpoint: '',
		minChars: 2,
		debounce: 250,
		perPage: 8,
		source: 'live',
		i18n: {
			noResults: 'No articles found.',
			error: 'Search is unavailable right now.',
			results: '%d articles found.',
			oneResult: '1 article found.',
		},
	};

	/**
	 * Read the configuration the server printed, falling back to the defaults.
	 *
	 * @since 1.0.0
	 *
	 * @return {Object} The merged configuration.
	 */
	function config() {
		const namespace = window.licensioKB || {};
		const supplied = namespace.search || {};
		const merged = {};
		let key;

		for ( key in DEFAULTS ) {
			if ( Object.prototype.hasOwnProperty.call( DEFAULTS, key ) ) {
				merged[ key ] =
					supplied[ key ] === undefined
						? DEFAULTS[ key ]
						: supplied[ key ];
			}
		}

		merged.i18n = merged.i18n || DEFAULTS.i18n;

		return merged;
	}

	/**
	 * A single search form's type-ahead behaviour.
	 *
	 * @since 1.0.0
	 *
	 * @param {HTMLElement} root     The form wrapper.
	 * @param {Object}      settings The merged configuration.
	 *
	 * @return {void}
	 */
	function TypeAhead( root, settings ) {
		this.root = root;
		this.settings = settings;
		this.form = root.querySelector( 'form' );
		this.input = root.querySelector( '[role="combobox"]' );
		this.listbox = root.querySelector( '[role="listbox"]' );
		this.status = root.querySelector( '[role="status"]' );

		this.timer = null;
		this.controller = null;
		this.items = [];
		this.active = -1;
		this.open = false;
		this.lastTerm = '';

		if ( this.input && this.listbox ) {
			this.bind();
		}
	}

	TypeAhead.prototype.bind = function () {
		const self = this;

		this.input.addEventListener( 'input', function () {
			self.schedule();
		} );

		this.input.addEventListener( 'keydown', function ( event ) {
			self.onKeyDown( event );
		} );

		this.input.addEventListener( 'blur', function () {
			// Deferred so a pointer press on an option lands before the close.
			window.setTimeout( function () {
				self.close();
			}, 150 );
		} );

		this.listbox.addEventListener( 'mousedown', function ( event ) {
			const option = event.target.closest
				? event.target.closest( '[role="option"]' )
				: null;

			if ( ! option ) {
				return;
			}

			event.preventDefault();
			self.go( option.getAttribute( 'data-url' ) );
		} );

		if ( this.form ) {
			this.form.addEventListener( 'submit', function ( event ) {
				if ( self.active > -1 && self.items[ self.active ] ) {
					event.preventDefault();
					self.go( self.items[ self.active ].url );
				}
			} );
		}
	};

	/**
	 * Debounce a fetch behind the configured delay.
	 *
	 * @since 1.0.0
	 *
	 * @return {void}
	 */
	TypeAhead.prototype.schedule = function () {
		const self = this;
		const term = this.input.value.trim();

		window.clearTimeout( this.timer );

		if ( term.length < this.settings.minChars ) {
			this.abort();
			this.close();

			return;
		}

		this.timer = window.setTimeout( function () {
			self.fetch( term );
		}, this.settings.debounce );
	};

	/**
	 * Cancel any request still in flight.
	 *
	 * Every keystroke supersedes the request before it, and responses can and do
	 * arrive out of order. Aborting is what stops a slow response for "ins"
	 * overwriting the already-rendered results for "install".
	 *
	 * @since 1.0.0
	 *
	 * @return {void}
	 */
	TypeAhead.prototype.abort = function () {
		if ( this.controller ) {
			this.controller.abort();
			this.controller = null;
		}
	};

	/**
	 * Ask the REST endpoint for suggestions.
	 *
	 * @since 1.0.0
	 *
	 * @param {string} term The search term.
	 *
	 * @return {void}
	 */
	TypeAhead.prototype.fetch = function ( term ) {
		const self = this;

		if ( ! this.settings.endpoint ) {
			return;
		}

		this.abort();

		const url =
			this.settings.endpoint +
			( this.settings.endpoint.indexOf( '?' ) === -1 ? '?' : '&' ) +
			'term=' +
			encodeURIComponent( term ) +
			'&per_page=' +
			encodeURIComponent( this.settings.perPage ) +
			'&source=' +
			encodeURIComponent( this.settings.source );

		this.controller =
			typeof window.AbortController === 'function'
				? new window.AbortController()
				: null;

		this.lastTerm = term;

		window
			.fetch( url, {
				credentials: 'same-origin',
				signal: this.controller ? this.controller.signal : undefined,
			} )
			.then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( 'HTTP ' + response.status );
				}

				return response.json();
			} )
			.then( function ( items ) {
				// A later keystroke has already superseded this response.
				if ( term !== self.lastTerm ) {
					return;
				}

				self.render( Array.isArray( items ) ? items : [] );
			} )
			.catch( function ( error ) {
				if ( error && error.name === 'AbortError' ) {
					return;
				}

				self.renderMessage( self.settings.i18n.error );
			} );
	};

	/**
	 * Paint the suggestion list.
	 *
	 * @since 1.0.0
	 *
	 * @param {Array} items The results from the endpoint.
	 *
	 * @return {void}
	 */
	TypeAhead.prototype.render = function ( items ) {
		const self = this;

		this.items = items;
		this.active = -1;
		this.listbox.innerHTML = '';

		if ( ! items.length ) {
			this.renderMessage( this.settings.i18n.noResults );

			return;
		}

		items.forEach( function ( item, index ) {
			self.listbox.appendChild( self.option( item, index ) );
		} );

		this.setOpen( true );
		this.announce(
			items.length === 1
				? this.settings.i18n.oneResult
				: this.settings.i18n.results.replace(
						'%d',
						String( items.length )
					)
		);
	};

	/**
	 * Build one `role="option"` element.
	 *
	 * The snippet arrives as HTML because the server built it: it is escaped
	 * there and then wrapped in `<mark>`, so `innerHTML` here receives text whose
	 * only markup is the highlight this feature added. Every other field is set
	 * with `textContent`.
	 *
	 * @since 1.0.0
	 *
	 * @param {Object} item  One result.
	 * @param {number} index Its position in the list.
	 *
	 * @return {HTMLElement} The option element.
	 */
	TypeAhead.prototype.option = function ( item, index ) {
		const option = document.createElement( 'li' );
		const title = document.createElement( 'span' );
		let meta;

		option.className = 'licensio-kb-search__option';
		option.setAttribute( 'role', 'option' );
		option.setAttribute( 'id', this.input.id + '-option-' + index );
		option.setAttribute( 'aria-selected', 'false' );
		option.setAttribute( 'data-url', item.url || '' );

		title.className = 'licensio-kb-search__option-title';
		title.textContent = item.title || '';
		option.appendChild( title );

		if ( item.snippet ) {
			meta = document.createElement( 'span' );
			meta.className = 'licensio-kb-search__option-snippet';
			meta.innerHTML = item.snippet;
			option.appendChild( meta );
		}

		if ( item.category && item.category.name ) {
			meta = document.createElement( 'span' );
			meta.className = 'licensio-kb-search__option-category';
			meta.textContent = item.category.name;
			option.appendChild( meta );
		}

		return option;
	};

	/**
	 * Show a message in place of results.
	 *
	 * Rendered as `role="presentation"` rather than as an option, because an
	 * empty state is not something the visitor can choose, and offering it as a
	 * selectable option would let Enter "select" it and go nowhere.
	 *
	 * @since 1.0.0
	 *
	 * @param {string} message The message.
	 *
	 * @return {void}
	 */
	TypeAhead.prototype.renderMessage = function ( message ) {
		const empty = document.createElement( 'li' );

		this.items = [];
		this.active = -1;
		this.listbox.innerHTML = '';

		empty.className = 'licensio-kb-search__empty';
		empty.setAttribute( 'role', 'presentation' );
		empty.textContent = message;

		this.listbox.appendChild( empty );
		this.setOpen( true );
		this.announce( message );
	};

	/**
	 * Handle the keyboard.
	 *
	 * @since 1.0.0
	 *
	 * @param {KeyboardEvent} event The key event.
	 *
	 * @return {void}
	 */
	TypeAhead.prototype.onKeyDown = function ( event ) {
		if ( event.key === 'ArrowDown' ) {
			event.preventDefault();
			this.move( 1 );

			return;
		}

		if ( event.key === 'ArrowUp' ) {
			event.preventDefault();
			this.move( -1 );

			return;
		}

		if ( event.key === 'Escape' ) {
			this.abort();
			this.close();

			return;
		}

		if (
			event.key === 'Enter' &&
			this.active > -1 &&
			this.items[ this.active ]
		) {
			event.preventDefault();
			this.go( this.items[ this.active ].url );
		}

		// Enter with nothing selected falls through, and the form submits to the
		// full results page. That is the behaviour, not an omission.
	};

	/**
	 * Move the active option, wrapping at both ends.
	 *
	 * @since 1.0.0
	 *
	 * @param {number} delta 1 to move down, -1 to move up.
	 *
	 * @return {void}
	 */
	TypeAhead.prototype.move = function ( delta ) {
		const count = this.items.length;
		let next;

		if ( ! count ) {
			return;
		}

		if ( ! this.open ) {
			this.setOpen( true );
		}

		if ( this.active === -1 ) {
			next = delta > 0 ? 0 : count - 1;
		} else {
			next = ( this.active + delta + count ) % count;
		}

		this.setActive( next );
	};

	/**
	 * Mark one option as the active descendant.
	 *
	 * @since 1.0.0
	 *
	 * @param {number} index The option index.
	 *
	 * @return {void}
	 */
	TypeAhead.prototype.setActive = function ( index ) {
		const options = this.listbox.querySelectorAll( '[role="option"]' );
		let i;

		for ( i = 0; i < options.length; i++ ) {
			options[ i ].setAttribute(
				'aria-selected',
				i === index ? 'true' : 'false'
			);
			options[ i ].classList.toggle( 'is-active', i === index );
		}

		this.active = index;

		if ( index > -1 && options[ index ] ) {
			this.input.setAttribute(
				'aria-activedescendant',
				options[ index ].getAttribute( 'id' )
			);
			options[ index ].scrollIntoView( { block: 'nearest' } );
		} else {
			this.input.removeAttribute( 'aria-activedescendant' );
		}
	};

	/**
	 * Open or close the listbox.
	 *
	 * @since 1.0.0
	 *
	 * @param {boolean} open Whether the listbox is open.
	 *
	 * @return {void}
	 */
	TypeAhead.prototype.setOpen = function ( open ) {
		this.open = !! open;
		this.listbox.hidden = ! this.open;
		this.input.setAttribute(
			'aria-expanded',
			this.open ? 'true' : 'false'
		);

		if ( ! this.open ) {
			this.input.removeAttribute( 'aria-activedescendant' );
		}
	};

	/**
	 * Close the listbox and forget the current suggestions.
	 *
	 * @since 1.0.0
	 *
	 * @return {void}
	 */
	TypeAhead.prototype.close = function () {
		this.items = [];
		this.active = -1;
		this.listbox.innerHTML = '';
		this.setOpen( false );
	};

	/**
	 * Announce a message to assistive technology.
	 *
	 * @since 1.0.0
	 *
	 * @param {string} message The message.
	 *
	 * @return {void}
	 */
	TypeAhead.prototype.announce = function ( message ) {
		if ( this.status ) {
			this.status.textContent = message;
		}
	};

	/**
	 * Navigate to a result.
	 *
	 * @since 1.0.0
	 *
	 * @param {string} url The article permalink.
	 *
	 * @return {void}
	 */
	TypeAhead.prototype.go = function ( url ) {
		if ( url ) {
			window.location.href = url;
		}
	};

	/**
	 * Attach the type-ahead to every search form on the page.
	 *
	 * @since 1.0.0
	 *
	 * @return {void}
	 */
	function init() {
		const settings = config();
		const roots = document.querySelectorAll( '[data-licensio-kb-search]' );
		let i;

		for ( i = 0; i < roots.length; i++ ) {
			if ( ! roots[ i ].hasAttribute( 'data-licensio-kb-bound' ) ) {
				roots[ i ].setAttribute( 'data-licensio-kb-bound', 'true' );
				new TypeAhead( roots[ i ], settings );
			}
		}
	}

	window.licensioKB = window.licensioKB || {};
	window.licensioKB.initSearch = init;

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
