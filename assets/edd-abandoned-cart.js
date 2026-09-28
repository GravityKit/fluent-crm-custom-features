/**
 * Sends the checkout email (and name) to FluentCRM as soon as the shopper types it, so a cart
 * abandoned before "Purchase" can still be followed up. Only loaded on the EDD checkout page.
 */
( function () {
	'use strict';

	var config = window.customcrmEddAbCart;

	if ( ! config ) {
		return;
	}

	var EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
	var lastSent = '';
	var timer = null;
	var inFlight = false;
	var queued = false;

	function field( selectors ) {
		for ( var i = 0; i < selectors.length; i++ ) {
			var el = document.querySelector( selectors[ i ] );
			if ( el ) {
				return el;
			}
		}
		return null;
	}

	function emailField() {
		return field( [ '#edd-email', 'input[name="edd_email"]', '#edd_purchase_form input[type="email"]' ] );
	}

	function post( action, data ) {
		var body = new URLSearchParams();
		body.append( 'action', action );
		body.append( 'nonce', config.nonce );
		Object.keys( data || {} ).forEach( function ( key ) {
			body.append( key, data[ key ] );
		} );

		return fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString(),
		} );
	}

	function sync() {
		var email = emailField();
		if ( ! email ) {
			return;
		}

		var value = ( email.value || '' ).trim();
		var first = field( [ '#edd-first', 'input[name="edd_first"]' ] );
		var last = field( [ '#edd-last', 'input[name="edd_last"]' ] );
		var signature = [ value, first ? first.value : '', last ? last.value : '' ].join( '|' );

		if ( ! EMAIL_RE.test( value ) || signature === lastSent ) {
			return;
		}

		// One request at a time, so the first one creates the cart and later ones update it.
		if ( inFlight ) {
			queued = true;
			return;
		}

		inFlight = true;
		lastSent = signature;

		post( config.syncAction, {
			email: value,
			first_name: first ? first.value.trim() : '',
			last_name: last ? last.value.trim() : '',
		} )
			.catch( function () {
				lastSent = '';
			} )
			.then( function () {
				inFlight = false;
				if ( queued ) {
					queued = false;
					sync();
				}
			} );
	}

	function schedule() {
		clearTimeout( timer );
		timer = setTimeout( sync, 800 );
	}

	function addGdprNotice() {
		var email = emailField();
		if ( ! config.gdprMessage || ! email || document.getElementById( 'customcrm-ab-cart-gdpr' ) ) {
			return;
		}

		var notice = document.createElement( 'p' );
		notice.id = 'customcrm-ab-cart-gdpr';
		notice.className = 'edd-description';
		notice.innerHTML = config.gdprMessage;
		email.parentNode.insertBefore( notice, email.nextSibling );

		var optOut = notice.querySelector( '#fc_ab_opt_out' );
		if ( optOut ) {
			optOut.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				post( config.optOutAction ).then( function () {
					notice.textContent = '';
				} );
			} );
		}
	}

	// EDD loads the purchase form over AJAX (and reloads it when the gateway changes), so the
	// email field usually does not exist yet when this runs. Set up each field as it appears.
	var seenField = null;

	function onFormReady() {
		var email = emailField();
		if ( ! email || email === seenField ) {
			return;
		}
		seenField = email;
		addGdprNotice();
		// Logged-in customers and browser autofill arrive with the email already filled in.
		sync();
	}

	function init() {
		onFormReady();

		if ( window.MutationObserver ) {
			new MutationObserver( onFormReady ).observe( document.body, { childList: true, subtree: true } );
		}

		// Delegated, because EDD re-renders the purchase form when the gateway changes.
		document.addEventListener( 'input', function ( event ) {
			if ( event.target && event.target.matches && event.target.matches( '#edd-email, #edd-first, #edd-last, input[name="edd_email"]' ) ) {
				schedule();
			}
		} );
		document.addEventListener( 'change', function ( event ) {
			if ( event.target && event.target.matches && event.target.matches( '#edd-email, #edd-first, #edd-last, input[name="edd_email"]' ) ) {
				sync();
			}
		} );

	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
