/**
 * Starfiniti Filters.
 *
 * Loads the filtered page in the background and swaps the product list and the panel.
 * Without this script the panel is a normal form and the page reloads.
 */
( function () {
	'use strict';

	var config = window.sffiltersConfig || {};
	var RESULTS = config.results || '[data-sffilters-results]';
	var PAGINATION = config.pagination || '.woocommerce-pagination a';
	var PANEL = '[data-sffilters-panel]';

	var request = null;
	var shown = pageKey( window.location.href );

	function pageKey( url ) {
		var parsed = new URL( url, window.location.href );

		return parsed.pathname + parsed.search;
	}

	// Keeps commas readable, so the address stays `sf_brand=anker,xiaomi`.
	function encode( value ) {
		return encodeURIComponent( value ).replace( /%2C/gi, ',' );
	}

	function groupValue( group ) {
		if ( group.hasAttribute( 'data-sffilters-range' ) ) {
			var min = group.querySelector( '[data-sffilters-min]' ).value.trim();
			var max = group.querySelector( '[data-sffilters-max]' ).value.trim();

			return min || max ? min + '-' + max : '';
		}

		var values = [];

		group.querySelectorAll( 'input:checked' ).forEach( function ( input ) {
			values.push( input.value );
		} );

		return values.join( ',' );
	}

	function panelUrl( form ) {
		var pairs = [];

		form.querySelectorAll( 'input[type="hidden"]' ).forEach( function ( input ) {
			pairs.push( encodeURIComponent( input.name ) + '=' + encodeURIComponent( input.value ) );
		} );

		form.querySelectorAll( '[data-sffilters-param]' ).forEach( function ( group ) {
			var value = groupValue( group );

			if ( value ) {
				pairs.push( group.getAttribute( 'data-sffilters-param' ) + '=' + encode( value ) );
			}
		} );

		var action = form.getAttribute( 'action' ).split( '?' )[ 0 ];

		return pairs.length ? action + '?' + pairs.join( '&' ) : action;
	}

	// Remembers which panel control has focus, so the keyboard position survives the swap.
	function focusedControl() {
		var active = document.activeElement;
		var panel = active && active.closest ? active.closest( PANEL ) : null;

		if ( ! panel || ! active.name ) {
			return null;
		}

		return {
			panel: Array.prototype.indexOf.call( document.querySelectorAll( PANEL ), panel ),
			name: active.name,
			value: 'checkbox' === active.type ? active.value : null,
		};
	}

	function restoreFocus( control ) {
		var panel = control ? document.querySelectorAll( PANEL )[ control.panel ] : null;

		if ( ! panel ) {
			return;
		}

		Array.prototype.some.call( panel.elements, function ( element ) {
			if ( element.name !== control.name || ( null !== control.value && element.value !== control.value ) ) {
				return false;
			}

			element.focus( { preventScroll: true } );

			return true;
		} );
	}

	function swap( doc ) {
		var current = document.querySelector( RESULTS );
		var next = doc.querySelector( RESULTS );

		if ( ! current || ! next ) {
			return false;
		}

		current.replaceWith( document.importNode( next, true ) );

		var panels = doc.querySelectorAll( PANEL );

		document.querySelectorAll( PANEL ).forEach( function ( panel, index ) {
			if ( panels[ index ] ) {
				panel.replaceWith( document.importNode( panels[ index ], true ) );
			}
		} );

		if ( doc.title ) {
			document.title = doc.title;
		}

		return true;
	}

	function load( url, options ) {
		var control = focusedControl();
		var current = new AbortController();

		if ( request ) {
			request.abort();
		}

		request = current;
		document.documentElement.classList.add( 'sffilters-loading' );

		fetch( url, { signal: current.signal, credentials: 'same-origin' } )
			.then( function ( response ) {
				return response.text();
			} )
			.then( function ( html ) {
				if ( ! swap( new DOMParser().parseFromString( html, 'text/html' ) ) ) {
					window.location.assign( url );
					return;
				}

				if ( options.push ) {
					window.history.pushState( { sffilters: true }, '', url );
				}

				shown = pageKey( url );
				restoreFocus( control );

				if ( options.scroll ) {
					document.querySelector( RESULTS ).scrollIntoView( {
						behavior: window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ? 'auto' : 'smooth',
						block: 'start',
					} );
				}

				document.dispatchEvent( new CustomEvent( 'sffilters:updated', { detail: { url: url } } ) );
			} )
			.catch( function ( error ) {
				if ( 'AbortError' !== error.name ) {
					window.location.assign( url );
				}
			} )
			.then( function () {
				if ( request === current ) {
					request = null;
					document.documentElement.classList.remove( 'sffilters-loading' );
				}
			} );
	}

	document.addEventListener( 'change', function ( event ) {
		var form = event.target.closest ? event.target.closest( PANEL ) : null;

		if ( form ) {
			load( panelUrl( form ), { push: true } );
		}
	} );

	document.addEventListener( 'submit', function ( event ) {
		if ( event.target.matches( PANEL ) ) {
			event.preventDefault();
			load( panelUrl( event.target ), { push: true } );
		}
	} );

	document.addEventListener( 'click', function ( event ) {
		var link = event.target.closest ? event.target.closest( 'a[href]' ) : null;

		if ( ! link || event.defaultPrevented || 0 !== event.button || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey ) {
			return;
		}

		if ( link.matches( '[data-sffilters-clear]' ) ) {
			event.preventDefault();
			load( link.href, { push: true } );
		} else if ( link.matches( PAGINATION ) && link.closest( RESULTS ) ) {
			event.preventDefault();
			load( link.href, { push: true, scroll: true } );
		}
	} );

	// Back and forward buttons.
	window.addEventListener( 'popstate', function () {
		if ( document.querySelector( PANEL ) && pageKey( window.location.href ) !== shown ) {
			load( window.location.href, {} );
		}
	} );
}() );
