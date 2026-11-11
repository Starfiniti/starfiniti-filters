/**
 * Starfiniti Filters.
 *
 * Loads the filtered page in the background and swaps the product list and the panel.
 * Without this script the panel is a normal form and the page reloads.
 */
( function () {
	'use strict';

	var config = window.sffiltersConfig || {};
	var strings = config.strings || {};
	var RESULTS = config.results || '[data-sffilters-results]';
	var PRODUCTS = config.products || 'ul.products';
	var PAGINATION = config.pagination || '.woocommerce-pagination a';
	var NEXT = config.next || '.woocommerce-pagination a.next';
	var ORDERING = config.ordering || '.woocommerce-ordering';
	var MODE = config.mode || 'pages';
	var PANEL = '[data-sffilters-panel]';
	var TOGGLE = '[data-sffilters-toggle]';
	var MORE = '[data-sffilters-more]';

	var mobile = window.matchMedia( config.mobile || '(max-width: 768px)' );
	var request = null;
	var shown = pageKey( window.location.href );
	var drawer = null;
	var slot = null;
	var pages = 1;
	var observer = null;

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

	// The address the sorting dropdown leads to: page one of this list in the new order.
	function sortUrl( form ) {
		var params = new URLSearchParams( new FormData( form ) );
		var base = form.closest( RESULTS ).getAttribute( 'data-sffilters-base' );

		if ( base ) {
			params.delete( 'paged' );
		}

		var query = params.toString().replace( /%2C/gi, ',' );
		var action = ( base || form.getAttribute( 'action' ) || window.location.pathname ).split( '?' )[ 0 ];

		return query ? action + '?' + query : action;
	}

	function findPanel( root, number ) {
		return root.querySelector( '[data-sffilters-panel="' + number + '"]' );
	}

	function controlKey( element ) {
		return element.name || element.getAttribute( 'data-sffilters-thumb' ) || '';
	}

	// Remembers which control has focus, so the keyboard position survives the swap.
	function focusedControl() {
		var active = document.activeElement;
		var panel = active && active.closest ? active.closest( PANEL ) : null;

		if ( panel && controlKey( active ) ) {
			return {
				panel: panel.getAttribute( 'data-sffilters-panel' ),
				key: controlKey( active ),
				value: 'checkbox' === active.type ? active.value : null,
			};
		}

		return active && active.closest && active.closest( '.sffilters-chips' ) ? { chips: true } : null;
	}

	function restoreFocus( control ) {
		if ( ! control ) {
			return;
		}

		if ( control.chips ) {
			var chip = document.querySelector( '.sffilters-chips a' );

			if ( chip ) {
				chip.focus( { preventScroll: true } );
			}

			return;
		}

		var panel = findPanel( document, control.panel );

		Array.prototype.some.call( panel ? panel.querySelectorAll( 'input' ) : [], function ( element ) {
			if ( controlKey( element ) !== control.key || ( null !== control.value && element.value !== control.value ) ) {
				return false;
			}

			element.focus( { preventScroll: true } );

			return true;
		} );
	}

	// Adds a two-handle slider above the price inputs. The inputs stay the source of the value.
	function slider( group ) {
		var range = group.querySelector( '.sffilters-range' );
		var ends = [ group.querySelector( '[data-sffilters-min]' ), group.querySelector( '[data-sffilters-max]' ) ];
		var low = range ? parseFloat( range.getAttribute( 'data-min' ) ) : NaN;
		var high = range ? parseFloat( range.getAttribute( 'data-max' ) ) : NaN;
		var limits = [ low, high ];

		if ( ! ( high > low ) || group.querySelector( '.sffilters-slider' ) ) {
			return;
		}

		var box = document.createElement( 'div' );

		box.className = 'sffilters-slider';

		var thumbs = ends.map( function ( input, index ) {
			var thumb = document.createElement( 'input' );
			var label = input.closest( 'label' );

			thumb.type = 'range';
			thumb.min = low;
			thumb.max = high;
			thumb.step = high - low > 20 ? 1 : 0.1;
			thumb.value = '' === input.value ? limits[ index ] : input.value;
			thumb.setAttribute( 'data-sffilters-thumb', index ? 'max' : 'min' );
			thumb.setAttribute( 'aria-label', label ? label.textContent.trim() : '' );
			box.appendChild( thumb );

			return thumb;
		} );

		function paint() {
			var from = ( thumbs[ 0 ].value - low ) / ( high - low ) * 100;
			var to = ( thumbs[ 1 ].value - low ) / ( high - low ) * 100;

			box.style.setProperty( '--sffilters-from', from );
			box.style.setProperty( '--sffilters-to', to );

			// With both handles at the top end, the lower one has to stay within reach.
			thumbs[ 0 ].style.zIndex = from > 50 ? 2 : '';
		}

		box.addEventListener( 'input', function ( event ) {
			var moved = event.target === thumbs[ 0 ] ? 0 : 1;

			if ( parseFloat( thumbs[ 0 ].value ) > parseFloat( thumbs[ 1 ].value ) ) {
				thumbs[ moved ].value = thumbs[ 1 - moved ].value;
			}

			thumbs.forEach( function ( thumb, index ) {
				ends[ index ].value = parseFloat( thumb.value ) === limits[ index ] ? '' : thumb.value;
			} );

			paint();
		} );

		paint();
		range.parentNode.insertBefore( box, range );
	}

	// On small screens the panel opens in a dialog and goes back to its place when the dialog closes.
	function buildDrawer() {
		var dialog = document.createElement( 'dialog' );

		dialog.className = 'sffilters-drawer';
		dialog.setAttribute( 'aria-label', strings.title || 'Filters' );
		dialog.innerHTML = '<div class="sffilters-drawer__head"><strong class="sffilters-drawer__title"></strong>' +
			'<button type="button" class="sffilters-drawer__close" data-sffilters-close>&times;</button></div>' +
			'<div class="sffilters-drawer__body"></div>' +
			'<div class="sffilters-drawer__foot"><button type="button" class="sffilters-drawer__show button" data-sffilters-close></button></div>';
		dialog.querySelector( '.sffilters-drawer__title' ).textContent = strings.title || 'Filters';
		dialog.querySelector( '.sffilters-drawer__close' ).setAttribute( 'aria-label', strings.close || 'Close filters' );

		// A click on the backdrop lands on the dialog itself.
		dialog.addEventListener( 'click', function ( event ) {
			if ( event.target === dialog ) {
				closeDrawer();
			}
		} );

		// The Escape key closes the dialog without asking.
		dialog.addEventListener( 'close', closeDrawer );

		document.body.appendChild( dialog );

		return dialog;
	}

	// Safe to call twice: the second call finds the panel back in its place.
	function closeDrawer() {
		var panel = drawer ? drawer.querySelector( PANEL ) : null;
		var toggle = document.querySelector( TOGGLE );

		if ( drawer && drawer.open ) {
			drawer.close();
		}

		if ( ! panel ) {
			return;
		}

		if ( slot && slot.isConnected ) {
			slot.replaceWith( panel );
		} else {
			panel.remove();
		}

		document.documentElement.classList.remove( 'sffilters-open' );

		if ( toggle ) {
			toggle.focus( { preventScroll: true } );
		}
	}

	function syncDrawer() {
		var toggle = document.querySelector( TOGGLE );

		if ( drawer && toggle ) {
			drawer.querySelector( '.sffilters-drawer__show' ).textContent = toggle.getAttribute( 'data-sffilters-show' );
		}
	}

	function openDrawer() {
		var panel = document.querySelector( PANEL );

		if ( ! panel || ! window.HTMLDialogElement || ( drawer && drawer.open ) ) {
			return;
		}

		drawer = drawer || buildDrawer();
		slot = document.createComment( 'sffilters-panel' );
		panel.parentNode.insertBefore( slot, panel );
		drawer.querySelector( '.sffilters-drawer__body' ).appendChild( panel );
		syncDrawer();
		document.documentElement.classList.add( 'sffilters-open' );
		drawer.showModal();
	}

	// In `infinite` mode the button loads by itself shortly before it scrolls into view.
	function watch( button ) {
		if ( 'infinite' !== MODE || ! window.IntersectionObserver ) {
			return;
		}

		observer = observer || new IntersectionObserver( function ( entries ) {
			entries.forEach( function ( entry ) {
				if ( entry.isIntersecting ) {
					loadMore( entry.target );
				}
			} );
		}, { rootMargin: '0px 0px 600px 0px' } );

		observer.disconnect();
		observer.observe( button );
	}

	// Replaces the page links with a "Load more" button that points at the next page.
	function more() {
		var results = document.querySelector( RESULTS );
		var list = results ? results.querySelector( PRODUCTS ) : null;
		var next = results ? results.querySelector( NEXT ) : null;

		pages = 1;

		if ( observer ) {
			observer.disconnect();
		}

		if ( 'pages' === MODE || ! list || ! next || results.querySelector( MORE ) ) {
			return;
		}

		results.querySelectorAll( PAGINATION ).forEach( function ( link ) {
			( link.closest( 'nav' ) || link.parentNode ).classList.add( 'sffilters-hidden' );
		} );

		var wrap = document.createElement( 'p' );
		var button = document.createElement( 'a' );

		wrap.className = 'sffilters-more';
		button.className = 'sffilters-more__button button';
		button.href = next.href;
		button.textContent = strings.more || 'Load more';
		button.setAttribute( 'data-sffilters-more', '' );
		wrap.appendChild( button );
		list.after( wrap );
		watch( button );
	}

	// Adds the products of the next page to the list. Resolves to true while there is another page.
	function loadMore( button ) {
		var url = button.href;
		var page = shown;
		var busy = 'sffilters-more--busy';

		if ( button.classList.contains( busy ) ) {
			return Promise.resolve( false );
		}

		button.classList.add( busy );
		button.setAttribute( 'aria-busy', 'true' );

		return fetch( url, { credentials: 'same-origin' } )
			.then( function ( response ) {
				return response.text();
			} )
			.then( function ( html ) {
				var here = document.querySelector( RESULTS );
				var there = new DOMParser().parseFromString( html, 'text/html' ).querySelector( RESULTS );
				var list = here ? here.querySelector( PRODUCTS ) : null;
				var fresh = there ? there.querySelector( PRODUCTS ) : null;
				var next = there ? there.querySelector( NEXT ) : null;
				var first = null;

				// The list was filtered, or the shopper went Back, in the meantime.
				if ( ! button.isConnected || page !== shown ) {
					return false;
				}

				if ( ! list || ! fresh ) {
					throw new Error( 'sffilters' );
				}

				Array.prototype.slice.call( fresh.children ).forEach( function ( item ) {
					var added = list.appendChild( document.importNode( item, true ) );

					first = first || added;
				} );

				pages++;
				window.history.replaceState( { sffilters: true, pages: pages }, '' );

				// Keyboard users continue at the first new product.
				if ( document.activeElement === button && first && first.querySelector( 'a[href]' ) ) {
					first.querySelector( 'a[href]' ).focus( { preventScroll: true } );
				}

				if ( next ) {
					button.href = next.href;
					button.classList.remove( busy );
					button.removeAttribute( 'aria-busy' );
					watch( button );
				} else {
					button.parentNode.remove();
				}

				document.dispatchEvent( new CustomEvent( 'sffilters:updated', { detail: { url: url, appended: true } } ) );

				return !! next;
			} )
			.catch( function () {
				if ( button.isConnected && page === shown ) {
					window.location.assign( url );
				}

				return false;
			} );
	}

	// After Back from a product page, loads the pages that were on screen before and returns to the same spot.
	function restore() {
		var state = window.history.state || {};
		var target = state.sffilters ? state.pages : 1;
		var y = state.y;

		if ( 'pages' === MODE || ! ( target > 1 ) ) {
			return;
		}

		( function step( again ) {
			var button = document.querySelector( MORE );

			if ( again && button && pages < target ) {
				loadMore( button ).then( step );
			} else if ( 'number' === typeof y ) {
				window.scrollTo( 0, y );
			}
		}( true ) );
	}

	// Prepares what the server sent: the slider, the drawer button and the "Load more" button.
	function enhance() {
		if ( ! document.querySelector( PANEL ) ) {
			document.querySelectorAll( TOGGLE ).forEach( function ( toggle ) {
				var bar = toggle.parentNode;

				toggle.remove();

				if ( bar && ! bar.children.length ) {
					bar.remove();
				}
			} );
		}

		document.querySelectorAll( '[data-sffilters-range]' ).forEach( slider );
		syncDrawer();
		more();
	}

	function swap( doc ) {
		var current = document.querySelector( RESULTS );
		var next = doc.querySelector( RESULTS );

		if ( ! current || ! next ) {
			return false;
		}

		current.replaceWith( document.importNode( next, true ) );

		document.querySelectorAll( PANEL ).forEach( function ( panel ) {
			var fresh = findPanel( doc, panel.getAttribute( 'data-sffilters-panel' ) );

			if ( fresh ) {
				panel.replaceWith( document.importNode( fresh, true ) );
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
		var address = url;

		if ( request ) {
			request.abort();
		}

		request = current;
		// Set now, so Back or Forward during the load is compared with the page on its way.
		shown = pageKey( url );
		document.documentElement.classList.add( 'sffilters-loading' );

		fetch( url, { signal: current.signal, credentials: 'same-origin' } )
			.then( function ( response ) {
				// The server may answer from another address, for example page 1 after sorting on page 2.
				if ( response.redirected ) {
					address = response.url;
				}

				return response.text();
			} )
			.then( function ( html ) {
				if ( ! swap( new DOMParser().parseFromString( html, 'text/html' ) ) ) {
					window.location.assign( url );
					return;
				}

				if ( options.push ) {
					window.history.pushState( { sffilters: true }, '', address );
				}

				shown = pageKey( address );
				enhance();
				restoreFocus( control );

				if ( options.restore ) {
					restore();
				}

				if ( options.scroll ) {
					document.querySelector( RESULTS ).scrollIntoView( {
						behavior: window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ? 'auto' : 'smooth',
						block: 'start',
					} );
				}

				document.dispatchEvent( new CustomEvent( 'sffilters:updated', { detail: { url: address } } ) );
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

	// Runs before other scripts see the event, so WooCommerce does not submit the sorting form.
	document.addEventListener( 'change', function ( event ) {
		var target = event.target;
		var form = target.closest ? target.closest( PANEL ) : null;
		var sorting = target.closest ? target.closest( ORDERING ) : null;

		if ( form ) {
			load( panelUrl( form ), { push: true } );
		} else if ( sorting && sorting.closest( RESULTS ) ) {
			event.stopPropagation();
			load( sortUrl( sorting ), { push: true } );
		}
	}, true );

	document.addEventListener( 'submit', function ( event ) {
		if ( event.target.matches( PANEL ) ) {
			event.preventDefault();
			load( panelUrl( event.target ), { push: true } );
		}
	} );

	document.addEventListener( 'click', function ( event ) {
		var target = event.target.closest ? event.target.closest( 'a[href], button' ) : null;

		if ( ! target || event.defaultPrevented || 0 !== event.button || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey ) {
			return;
		}

		if ( target.matches( TOGGLE ) ) {
			openDrawer();
		} else if ( target.matches( '[data-sffilters-close]' ) ) {
			closeDrawer();
		} else if ( target.matches( '[data-sffilters-clear], [data-sffilters-remove]' ) ) {
			event.preventDefault();
			load( target.href, { push: true } );
		} else if ( target.matches( MORE ) ) {
			event.preventDefault();
			loadMore( target );
		} else if ( target.matches( PAGINATION ) && target.closest( RESULTS ) ) {
			event.preventDefault();
			load( target.href, { push: true, scroll: true } );
		}
	} );

	// Back and forward buttons.
	window.addEventListener( 'popstate', function () {
		if ( document.querySelector( RESULTS ) && pageKey( window.location.href ) !== shown ) {
			load( window.location.href, { restore: true } );
		}
	} );

	// Remembers how far the shopper scrolled, for the way back from a product page.
	window.addEventListener( 'pagehide', function () {
		if ( pages > 1 ) {
			window.history.replaceState( { sffilters: true, pages: pages, y: window.scrollY }, '' );
		}
	} );

	// The drawer belongs to small screens only.
	mobile.addEventListener( 'change', function () {
		if ( ! mobile.matches ) {
			closeDrawer();
		}
	} );

	enhance();
	restore();
}() );
