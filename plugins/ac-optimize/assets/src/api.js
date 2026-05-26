/**
 * apiFetch wrappers for the amplifi.optimize REST API.
 */
import apiFetch from '@wordpress/api-fetch';

const cfg = window.AmplifiOptimize || {};

apiFetch.use( apiFetch.createNonceMiddleware( cfg.nonce ) );
apiFetch.use( apiFetch.createRootURLMiddleware( cfg.restUrl || '/wp-json/amplifi-optimize/v1/' ) );

const path = ( p ) => ( p.startsWith( '/' ) ? p.slice( 1 ) : p );

export const api = {
	scan( type, opts = {} ) {
		return apiFetch( {
			path: path( `scan/${ type }` ),
			method: 'POST',
			data: opts,
		} );
	},
	propose( type, opts = {} ) {
		return apiFetch( {
			path: path( `propose/${ type }` ),
			method: 'POST',
			data: opts,
		} );
	},
	progress() {
		return apiFetch( { path: path( 'scan/progress' ) } );
	},
	listSuggestions( params = {} ) {
		const qs = new URLSearchParams( params ).toString();
		return apiFetch( { path: path( `suggestions?${ qs }` ) } );
	},
	approve( id ) {
		return apiFetch( { path: path( `suggestions/${ id }/approve` ), method: 'POST' } );
	},
	reject( id ) {
		return apiFetch( { path: path( `suggestions/${ id }/reject` ), method: 'POST' } );
	},
	edit( id, proposed_value, then_approve = false ) {
		return apiFetch( {
			path: path( `suggestions/${ id }/edit` ),
			method: 'POST',
			data: { proposed_value, then_approve },
		} );
	},
	undo( id ) {
		return apiFetch( { path: path( `suggestions/${ id }/undo` ), method: 'POST' } );
	},
	retry( id ) {
		return apiFetch( { path: path( `suggestions/${ id }/retry` ), method: 'POST' } );
	},
	batchApprove( ids ) {
		return apiFetch( {
			path: path( 'suggestions/batch-approve' ),
			method: 'POST',
			data: { ids },
		} );
	},
	stats() {
		return apiFetch( { path: path( 'stats' ) } );
	},
	getSettings() {
		return apiFetch( { path: path( 'settings' ) } );
	},
	updateSettings( data ) {
		return apiFetch( { path: path( 'settings' ), method: 'POST', data } );
	},
};

export const config = cfg;
