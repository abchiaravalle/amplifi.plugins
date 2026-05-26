import { useCallback, useEffect, useState } from '@wordpress/element';
import { Button, Notice, SelectControl, Spinner } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { api, config } from '../api';

export default function History() {
	const [ items, setItems ] = useState( [] );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );
	const [ filter, setFilter ] = useState( 'applied' );
	const [ type, setType ] = useState( '' );

	const load = useCallback( async () => {
		setLoading( true );
		try {
			const r = await api.listSuggestions( {
				status: filter,
				type,
				per_page: 50,
			} );
			setItems( r.items );
		} catch ( e ) {
			setError( e.message );
		}
		setLoading( false );
	}, [ filter, type ] );

	useEffect( () => {
		load();
	}, [ load ] );

	const undo = async ( id ) => {
		try {
			await api.undo( id );
			load();
		} catch ( e ) {
			setError( e.message );
		}
	};

	const fixTypes = config.fixTypes || {};

	return (
		<div className="ao-history">
			<h1>{ __( 'History', 'amplifi-optimize' ) }</h1>
			<div className="ao-filters">
				<SelectControl
					label={ __( 'Status', 'amplifi-optimize' ) }
					value={ filter }
					onChange={ setFilter }
					options={ [
						{ label: __( 'Applied', 'amplifi-optimize' ), value: 'applied' },
						{ label: __( 'Rejected', 'amplifi-optimize' ), value: 'rejected' },
						{ label: __( 'Failed', 'amplifi-optimize' ), value: 'failed' },
					] }
					__nextHasNoMarginBottom
				/>
				<SelectControl
					label={ __( 'Fix type', 'amplifi-optimize' ) }
					value={ type }
					onChange={ setType }
					options={ [
						{ label: __( 'All', 'amplifi-optimize' ), value: '' },
						...Object.entries( fixTypes ).map( ( [ slug, info ] ) => ( {
							label: info.label,
							value: slug,
						} ) ),
					] }
					__nextHasNoMarginBottom
				/>
			</div>
			{ error && <Notice status="error">{ error }</Notice> }
			{ loading ? (
				<Spinner />
			) : (
				<table className="widefat striped">
					<thead>
						<tr>
							<th>{ __( 'ID', 'amplifi-optimize' ) }</th>
							<th>{ __( 'Type', 'amplifi-optimize' ) }</th>
							<th>{ __( 'Target', 'amplifi-optimize' ) }</th>
							<th>{ __( 'Proposed', 'amplifi-optimize' ) }</th>
							<th>{ __( 'When', 'amplifi-optimize' ) }</th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						{ items.length === 0 && (
							<tr><td colSpan="6">{ __( 'No items.', 'amplifi-optimize' ) }</td></tr>
						) }
						{ items.map( ( row ) => (
							<tr key={ row.id }>
								<td>{ row.id }</td>
								<td>{ fixTypes[ row.fix_type ]?.label || row.fix_type }</td>
								<td>
									{ row.target?.url ? (
										<a href={ row.target.url } target="_blank" rel="noopener noreferrer">{ row.target.title || `#${ row.target_id }` }</a>
									) : (
										`#${ row.target_id }`
									) }
								</td>
								<td className="ao-truncate">{ row.proposed_value }</td>
								<td>{ row.applied_at || row.updated_at }</td>
								<td>
									{ filter === 'applied' && (
										<Button variant="link" onClick={ () => undo( row.id ) }>{ __( 'Undo', 'amplifi-optimize' ) }</Button>
									) }
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }
		</div>
	);
}
