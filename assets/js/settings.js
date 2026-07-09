/**
 * Kraken Semantics — settings screen behavior.
 *
 * Two touches, no dependencies:
 * 1. Provider cards highlight as the radio selection changes.
 * 2. The threshold band preview resizes live as the numbers change.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		// --- Provider card selection -------------------------------------
		var cards = document.querySelectorAll( '.kraken-provider' );

		function refreshCards() {
			cards.forEach( function ( card ) {
				var radio = card.querySelector( 'input[type="radio"]' );
				card.classList.toggle( 'is-selected', !! ( radio && radio.checked ) );
			} );
		}

		cards.forEach( function ( card ) {
			var radio = card.querySelector( 'input[type="radio"]' );
			if ( radio ) {
				radio.addEventListener( 'change', refreshCards );
			}
		} );

		// --- Threshold band preview ---------------------------------------
		var highInput = document.getElementById( 'kraken-threshold-high' );
		var lowInput = document.getElementById( 'kraken-threshold-low' );
		var preview = document.getElementById( 'kraken-bandpreview' );

		if ( ! highInput || ! lowInput || ! preview ) {
			return;
		}

		function clamp( value ) {
			return Math.max( 0, Math.min( 100, parseInt( value, 10 ) || 0 ) );
		}

		function refreshPreview() {
			var high = clamp( highInput.value );
			var low = clamp( lowInput.value );

			// Mirror the server-side sanitizer: keep the bands ordered.
			if ( low > high ) {
				var swap = low;
				low = high;
				high = swap;
			}

			preview.querySelector( '.kraken-bandpreview__seg--low' ).style.width = low + '%';
			preview.querySelector( '.kraken-bandpreview__seg--medium' ).style.width = ( high - low ) + '%';
			preview.querySelector( '.kraken-bandpreview__seg--high' ).style.width = ( 100 - high ) + '%';
		}

		highInput.addEventListener( 'input', refreshPreview );
		lowInput.addEventListener( 'input', refreshPreview );
	} );
} )();
