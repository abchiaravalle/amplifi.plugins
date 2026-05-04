/* amplifi.security admin shell — minimal vanilla JS */
( function ( $ ) {
	'use strict';
	// Confirm before destructive form submissions that flag data-confirm.
	$( document ).on( 'click', '[data-confirm]', function ( e ) {
		const msg = $( this ).attr( 'data-confirm' );
		if ( msg && ! window.confirm( msg ) ) {
			e.preventDefault();
		}
	} );
} )( window.jQuery );
