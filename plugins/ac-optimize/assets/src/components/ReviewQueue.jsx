import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import { Button, ButtonGroup, Notice, Spinner, TabPanel, ToggleControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { api, config } from '../api';
import SuggestionCard from './SuggestionCard';

const FIX_TYPES = Object.entries( config.fixTypes || {} ).map( ( [ name, info ] ) => ( {
	name,
	title: info.label,
} ) );

export default function ReviewQueue() {
	if ( FIX_TYPES.length === 0 ) {
		return <p>{ __( 'No fix types registered.', 'amplifi-optimize' ) }</p>;
	}
	return (
		<div className="ao-queue">
			<h1>{ __( 'Review Queue', 'amplifi-optimize' ) }</h1>
			<TabPanel tabs={ FIX_TYPES } className="ao-tabs">
				{ ( tab ) => <Tab fixType={ tab.name } /> }
			</TabPanel>
		</div>
	);
}

function Tab( { fixType } ) {
	const [ items, setItems ] = useState( [] );
	const [ idx, setIdx ] = useState( 0 );
	const [ total, setTotal ] = useState( 0 );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );
	const [ buffered, setBuffered ] = useState( false );
	const [ pendingBatch, setPendingBatch ] = useState( [] );

	const load = useCallback( async () => {
		setLoading( true );
		setError( null );
		try {
			const r = await api.listSuggestions( { type: fixType, status: 'pending', per_page: 50 } );
			setItems( r.items );
			setTotal( r.total );
			setIdx( 0 );
		} catch ( e ) {
			setError( e.message );
		}
		setLoading( false );
	}, [ fixType ] );

	useEffect( () => {
		load();
	}, [ load ] );

	const current = items[ idx ];

	const next = useCallback( () => {
		setIdx( ( i ) => Math.min( i + 1, items.length ) );
	}, [ items.length ] );

	const removeCurrent = useCallback( () => {
		setItems( ( arr ) => {
			const out = arr.slice();
			out.splice( idx, 1 );
			return out;
		} );
		setTotal( ( t ) => Math.max( 0, t - 1 ) );
	}, [ idx ] );

	const flushBuffer = useCallback( async () => {
		if ( pendingBatch.length === 0 ) {
			return;
		}
		await api.batchApprove( pendingBatch );
		setPendingBatch( [] );
		load();
	}, [ pendingBatch, load ] );

	const onApprove = useCallback( async () => {
		if ( ! current ) return;
		if ( buffered ) {
			setPendingBatch( ( p ) => [ ...p, current.id ] );
			removeCurrent();
			if ( pendingBatch.length + 1 >= 10 ) {
				await flushBuffer();
			}
			return;
		}
		try {
			await api.approve( current.id );
			removeCurrent();
		} catch ( e ) {
			setError( e.message );
		}
	}, [ current, buffered, pendingBatch.length, flushBuffer, removeCurrent ] );

	const onReject = useCallback( async () => {
		if ( ! current ) return;
		try {
			await api.reject( current.id );
			removeCurrent();
		} catch ( e ) {
			setError( e.message );
		}
	}, [ current, removeCurrent ] );

	const onEdit = useCallback(
		async ( newValue, thenApprove ) => {
			if ( ! current ) return;
			try {
				await api.edit( current.id, newValue, thenApprove );
				if ( thenApprove ) {
					removeCurrent();
				} else {
					setItems( ( arr ) => {
						const out = arr.slice();
						out[ idx ] = { ...out[ idx ], proposed_value: newValue };
						return out;
					} );
				}
			} catch ( e ) {
				setError( e.message );
			}
		},
		[ current, idx, removeCurrent ]
	);

	useEffect( () => {
		const handler = ( e ) => {
			if ( [ 'INPUT', 'TEXTAREA' ].includes( e.target.tagName ) ) return;
			if ( e.key === 'a' ) onApprove();
			else if ( e.key === 'r' ) onReject();
			else if ( e.key === 's' ) next();
			else if ( e.key === 'ArrowRight' ) next();
		};
		window.addEventListener( 'keydown', handler );
		return () => window.removeEventListener( 'keydown', handler );
	}, [ onApprove, onReject, next ] );

	if ( loading ) return <Spinner />;
	if ( error ) return <Notice status="error">{ error }</Notice>;
	if ( ! current ) {
		return (
			<div className="ao-empty">
				<p>{ __( 'No pending suggestions for this fix type.', 'amplifi-optimize' ) }</p>
				<Button variant="secondary" onClick={ load }>{ __( 'Refresh', 'amplifi-optimize' ) }</Button>
			</div>
		);
	}

	return (
		<div className="ao-queue-pane">
			<div className="ao-queue-toolbar">
				<span className="ao-queue-counter">
					{ sprintf(
						/* translators: 1: current index, 2: total */
						__( '%1$d of %2$d', 'amplifi-optimize' ),
						Math.min( idx + 1, total ),
						total
					) }
				</span>
				<ToggleControl
					label={ __( 'Buffered approve mode', 'amplifi-optimize' ) }
					checked={ buffered }
					onChange={ setBuffered }
					__nextHasNoMarginBottom
				/>
				{ buffered && pendingBatch.length > 0 && (
					<>
						<span className="ao-batch-count">
							{ sprintf(
								/* translators: %d pending count */
								__( '%d pending sync', 'amplifi-optimize' ),
								pendingBatch.length
							) }
						</span>
						<Button variant="secondary" onClick={ flushBuffer }>{ __( 'Sync now', 'amplifi-optimize' ) }</Button>
					</>
				) }
			</div>

			<SuggestionCard suggestion={ current } onEdit={ onEdit } />

			<ButtonGroup className="ao-actions">
				<Button variant="primary" onClick={ onApprove }>{ __( 'Approve (A)', 'amplifi-optimize' ) }</Button>
				<Button variant="secondary" isDestructive onClick={ onReject }>{ __( 'Reject (R)', 'amplifi-optimize' ) }</Button>
				<Button variant="tertiary" onClick={ next }>{ __( 'Skip (S / →)', 'amplifi-optimize' ) }</Button>
			</ButtonGroup>
		</div>
	);
}
