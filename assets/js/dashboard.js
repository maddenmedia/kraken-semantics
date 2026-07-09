/**
 * Kraken Semantics — dashboard behavior.
 *
 * One shared tooltip for every element carrying a data-ks-tip attribute
 * (histogram buckets, trend points). Charts themselves are server-rendered
 * SVG; this file only positions the hover layer.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var tooltip = document.querySelector( '.kraken-dash__tooltip' );

		if ( ! tooltip ) {
			return;
		}

		/**
		 * Places the tooltip near the cursor, flipping when it would leave
		 * the viewport.
		 *
		 * @param {MouseEvent} event Source event.
		 */
		function position( event ) {
			var pad = 14;
			var x = event.clientX + pad;
			var y = event.clientY + pad;
			var rect = tooltip.getBoundingClientRect();

			if ( x + rect.width > window.innerWidth - 8 ) {
				x = event.clientX - rect.width - pad;
			}
			if ( y + rect.height > window.innerHeight - 8 ) {
				y = event.clientY - rect.height - pad;
			}

			tooltip.style.left = x + 'px';
			tooltip.style.top = y + 'px';
		}

		document.addEventListener( 'mouseover', function ( event ) {
			var target = event.target.closest ? event.target.closest( '[data-ks-tip]' ) : null;

			if ( ! target ) {
				return;
			}

			tooltip.textContent = target.getAttribute( 'data-ks-tip' );
			tooltip.classList.add( 'is-visible' );
			tooltip.setAttribute( 'aria-hidden', 'false' );
			position( event );
		} );

		document.addEventListener( 'mousemove', function ( event ) {
			if ( tooltip.classList.contains( 'is-visible' ) ) {
				position( event );
			}
		} );

		document.addEventListener( 'mouseout', function ( event ) {
			var target = event.target.closest ? event.target.closest( '[data-ks-tip]' ) : null;

			if ( target ) {
				tooltip.classList.remove( 'is-visible' );
				tooltip.setAttribute( 'aria-hidden', 'true' );
			}
		} );
	} );
} )();
