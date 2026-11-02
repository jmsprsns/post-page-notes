/**
 * Content Powered > Post and Page Notes page behaviour. Only loaded on that page.
 *
 * Tabs (the page comes back to the same tab after saving) and the reply
 * email switch, which shows or hides who gets emails and the example.
 */

( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var form = document.querySelector( '.ppn-form' );

		if ( ! form ) {
			return;
		}

		initTabs( form );
		initMasters();
	} );

	function initTabs( form ) {
		var tabs = document.querySelectorAll( '[data-ppn-tab]' );
		var panels = document.querySelectorAll( '[data-ppn-panel]' );
		var referer = form.querySelector( 'input[name="_wp_http_referer"]' );
		var refererBase = referer ? referer.value.split( '#' )[ 0 ] : '';

		function show( id ) {
			var found = false;

			tabs.forEach( function ( tab ) {
				var active = tab.getAttribute( 'data-ppn-tab' ) === id;

				found = found || active;
				tab.classList.toggle( 'is-active', active );
				tab.setAttribute( 'aria-selected', active ? 'true' : 'false' );
			} );

			if ( ! found && tabs.length ) {
				show( tabs[ 0 ].getAttribute( 'data-ppn-tab' ) );
				return;
			}

			panels.forEach( function ( panel ) {
				panel.hidden = panel.getAttribute( 'data-ppn-panel' ) !== id;
			} );

			// Come back to this tab after saving.
			if ( referer ) {
				referer.value = refererBase + '#' + id;
			}
		}

		tabs.forEach( function ( tab ) {
			tab.addEventListener( 'click', function ( event ) {
				var id = tab.getAttribute( 'data-ppn-tab' );

				event.preventDefault();
				show( id );
				history.replaceState( null, '', '#' + id );
			} );
		} );

		window.addEventListener( 'hashchange', function () {
			show( window.location.hash.replace( '#', '' ) );
		} );

		show( window.location.hash.replace( '#', '' ) );
	}

	function initMasters() {
		document.querySelectorAll( '[data-ppn-master]' ).forEach( function ( input ) {
			var box = input.closest( '.inside' );
			var body = box ? box.querySelector( '[data-ppn-body]' ) : null;
			var off = box ? box.querySelector( '[data-ppn-off]' ) : null;

			function sync() {
				if ( body ) {
					body.hidden = ! input.checked;
				}

				if ( off ) {
					off.hidden = input.checked;
				}
			}

			input.addEventListener( 'change', sync );
			sync();
		} );
	}
} )();
