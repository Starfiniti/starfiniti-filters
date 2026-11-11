/**
 * Settings page: add, remove and reorder the rows of the filters table.
 * The form saves the rows in the order they are shown.
 */
( function () {
	'use strict';

	const rows = document.querySelector( '[data-sffilters-rows]' );
	const select = document.querySelector( '[data-sffilters-source]' );
	const template = document.querySelector( '[data-sffilters-template]' );
	const strings = window.sffiltersAdmin || {};

	if ( ! rows || ! select || ! template ) {
		return;
	}

	function speak( message ) {
		if ( message && window.wp && window.wp.a11y ) {
			window.wp.a11y.speak( message );
		}
	}

	function add() {
		const chosen = select.selectedOptions[ 0 ];

		if ( ! chosen || ! chosen.value ) {
			select.focus();
			return;
		}

		const row = template.content.firstElementChild.cloneNode( true );
		const name = chosen.dataset.name;
		const label = row.querySelector( '[data-sffilters-label]' );

		row.querySelector( '[data-sffilters-key]' ).value = chosen.value;
		row.querySelector( '[data-sffilters-name]' ).textContent = name;
		row.querySelector( '[data-sffilters-note]' ).hidden = ! ( 'attribute' in chosen.dataset );
		row.querySelector( '[data-sffilters-param]' ).textContent = 'sf_' + chosen.dataset.slug;
		label.placeholder = name;

		row.querySelectorAll( '[aria-label]' ).forEach( function ( item ) {
			item.setAttribute( 'aria-label', item.getAttribute( 'aria-label' ).replace( '%s', name ) );
		} );

		rows.append( row );
		chosen.disabled = true;
		select.value = '';
		label.focus();
		speak( strings.added );
	}

	function remove( row ) {
		const key = row.querySelector( '[data-sffilters-key]' ).value;
		const option = Array.from( select.options ).find( function ( item ) {
			return item.value === key;
		} );

		if ( option ) {
			option.disabled = false;
		}

		row.remove();
		select.focus();
		speak( strings.removed );
	}

	function move( button, step ) {
		const row = button.closest( '[data-sffilters-row]' );
		const other = step < 0 ? row.previousElementSibling : row.nextElementSibling;

		if ( ! other ) {
			return;
		}

		if ( step < 0 ) {
			other.before( row );
		} else {
			other.after( row );
		}

		// Moving the row drops the focus, so put it back on the same button.
		button.focus();
		speak( strings.moved );
	}

	document.querySelector( '[data-sffilters-add]' ).addEventListener( 'click', add );

	rows.addEventListener( 'click', function ( event ) {
		const mover = event.target.closest( '[data-sffilters-move]' );
		const remover = event.target.closest( '[data-sffilters-remove]' );

		if ( mover ) {
			move( mover, Number( mover.dataset.sffiltersMove ) );
		} else if ( remover ) {
			remove( remover.closest( '[data-sffilters-row]' ) );
		}
	} );
}() );
