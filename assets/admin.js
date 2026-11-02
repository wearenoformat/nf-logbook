/**
 * Noformat Logbook admin: expanders, auto-submitting filter forms and the
 * chain verification on the Status page. Vanilla JS, no dependencies.
 */
( function () {
	'use strict';

	// Expanders: <button aria-expanded aria-controls data-nflog-toggle>.
	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest ? event.target.closest( '[data-nflog-toggle]' ) : null;
		if ( ! button ) {
			return;
		}
		var target = document.getElementById( button.getAttribute( 'aria-controls' ) || '' );
		if ( ! target ) {
			return;
		}
		var open = 'true' !== button.getAttribute( 'aria-expanded' );
		button.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		target.hidden = ! open;
	} );

	// Filter forms submit on change (chips, selects); text and date inputs on Enter / Apply.
	Array.prototype.forEach.call( document.querySelectorAll( 'form[data-nflog-autosubmit]' ), function ( form ) {
		form.addEventListener( 'change', function ( event ) {
			var el = event.target;
			if ( 'radio' === el.type || 'checkbox' === el.type || 'SELECT' === el.tagName ) {
				cleanAndSubmit( form );
			}
		} );
		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			cleanAndSubmit( form );
		} );
	} );

	// Submit without empty parameters so URLs stay short and bookmarkable.
	function cleanAndSubmit( form ) {
		var params = new URLSearchParams();
		Array.prototype.forEach.call( form.elements, function ( el ) {
			if ( ! el.name || el.disabled ) {
				return;
			}
			if ( ( 'radio' === el.type || 'checkbox' === el.type ) && ! el.checked ) {
				return;
			}
			if ( '' === el.value ) {
				return;
			}
			params.append( el.name, el.value );
		} );
		window.location.href = ( form.getAttribute( 'action' ) || '' ) + '?' + params.toString();
	}

	// Status: verify the chain over the last 1 000 events.
	var verify = document.querySelector( '[data-nflog-verify]' );
	if ( verify ) {
		var result = document.querySelector( '[data-nflog-verify-result]' );
		verify.addEventListener( 'click', function () {
			verify.disabled = true;
			result.className = 'nflog-verify-result';
			result.textContent = '…';
			var body = new URLSearchParams( { action: 'nflog_verify', _ajax_nonce: verify.getAttribute( 'data-nonce' ) } );
			fetch( verify.getAttribute( 'data-url' ), { method: 'POST', credentials: 'same-origin', body: body } )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( json ) {
					var data = json && json.data ? json.data : {};
					result.textContent = data.message || '';
					result.className = 'nflog-verify-result ' + ( json.success && data.ok ? 'is-ok' : 'is-bad' );
				} )
				.catch( function () {
					result.textContent = '—';
					result.className = 'nflog-verify-result is-bad';
				} )
				.then( function () {
					verify.disabled = false;
				} );
		} );
	}
}() );
