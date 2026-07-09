/**
 * Kraken Semantics — editor meta box behavior.
 *
 * Powers the "Scan now" button: calls the plugin's REST scan route and
 * reloads the editor so the refreshed score renders server-side. No build
 * step, no dependencies — plain browser JS.
 *
 * Expects `krakenSemantics` (restBase, nonce, i18n) via wp_localize_script.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var button = document.getElementById( 'kraken-semantics-scan' );

		if ( ! button || typeof window.krakenSemantics === 'undefined' ) {
			return;
		}

		var config = window.krakenSemantics;
		var status = document.querySelector( '.kraken-semantics-scan-status' );

		/**
		 * Writes a short status message next to the button.
		 *
		 * @param {string} text Message to show.
		 */
		function setStatus( text ) {
			if ( status ) {
				status.textContent = text;
			}
		}

		button.addEventListener( 'click', function () {
			var postId = button.getAttribute( 'data-post' );

			// Prevent double-submits while a scan is in flight — a scan can
			// take tens of seconds against a long post.
			button.disabled = true;
			setStatus( config.i18n.scanning );

			window
				.fetch( config.restBase + '/posts/' + postId + '/scan', {
					method: 'POST',
					headers: {
						// Core REST cookie auth: nonce proves the logged-in user.
						'X-WP-Nonce': config.nonce,
					},
					credentials: 'same-origin',
				} )
				.then( function ( response ) {
					// The route returns JSON for both success and error;
					// decode first, then branch on response.ok.
					return response.json().then( function ( body ) {
						return { ok: response.ok, body: body };
					} );
				} )
				.then( function ( result ) {
					if ( ! result.ok ) {
						throw new Error(
							result.body && result.body.message
								? result.body.message
								: 'HTTP error'
						);
					}

					setStatus( config.i18n.scanned );

					// Reload so the meta box, list column, and any editor
					// meta panels all show the new score consistently.
					window.location.reload();
				} )
				.catch( function ( error ) {
					button.disabled = false;
					setStatus( config.i18n.failed + ' ' + error.message );
				} );
		} );
	} );
} )();
