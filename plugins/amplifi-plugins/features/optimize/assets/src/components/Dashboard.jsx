import { useEffect, useState } from '@wordpress/element';
import { Card, CardBody, CardHeader, Notice, Spinner } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { api, config } from '../api';

export default function Dashboard() {
	const [ stats, setStats ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );

	useEffect( () => {
		let cancelled = false;
		api.stats()
			.then( ( s ) => {
				if ( ! cancelled ) {
					setStats( s );
					setLoading( false );
				}
			} )
			.catch( ( e ) => {
				if ( ! cancelled ) {
					setError( e.message );
					setLoading( false );
				}
			} );
		return () => {
			cancelled = true;
		};
	}, [] );

	if ( loading ) {
		return <Spinner />;
	}
	if ( error ) {
		return <Notice status="error" isDismissible={ false }>{ error }</Notice>;
	}

	const usage = stats.usage || {};
	const fixTypes = config.fixTypes || {};

	return (
		<div className="ao-dashboard">
			<h1>{ __( 'Dashboard', 'amplifi-optimize' ) }</h1>
			<div className="ao-grid">
				{ Object.entries( fixTypes ).map( ( [ slug, info ] ) => {
					const counts = stats.by_type[ slug ] || {};
					return (
						<Card key={ slug }>
							<CardHeader>{ info.label }</CardHeader>
							<CardBody>
								<dl className="ao-stat-list">
									<dt>{ __( 'Pending', 'amplifi-optimize' ) }</dt>
									<dd>{ counts.pending || 0 }</dd>
									<dt>{ __( 'Applied', 'amplifi-optimize' ) }</dt>
									<dd>{ counts.applied || 0 }</dd>
									<dt>{ __( 'Rejected', 'amplifi-optimize' ) }</dt>
									<dd>{ counts.rejected || 0 }</dd>
									<dt>{ __( 'Failed', 'amplifi-optimize' ) }</dt>
									<dd>{ counts.failed || 0 }</dd>
								</dl>
							</CardBody>
						</Card>
					);
				} ) }
			</div>

			<Card>
				<CardHeader>{ __( 'Claude usage', 'amplifi-optimize' ) }</CardHeader>
				<CardBody>
					{ Object.keys( usage ).length === 0 ? (
						<p>{ __( 'No Claude calls yet.', 'amplifi-optimize' ) }</p>
					) : (
						<table className="widefat striped">
							<thead>
								<tr>
									<th>{ __( 'Model', 'amplifi-optimize' ) }</th>
									<th>{ __( 'Calls', 'amplifi-optimize' ) }</th>
									<th>{ __( 'Input tokens', 'amplifi-optimize' ) }</th>
									<th>{ __( 'Output tokens', 'amplifi-optimize' ) }</th>
								</tr>
							</thead>
							<tbody>
								{ Object.entries( usage ).map( ( [ model, u ] ) => (
									<tr key={ model }>
										<td>{ model }</td>
										<td>{ u.calls }</td>
										<td>{ u.input }</td>
										<td>{ u.output }</td>
									</tr>
								) ) }
							</tbody>
						</table>
					) }
				</CardBody>
			</Card>
		</div>
	);
}
