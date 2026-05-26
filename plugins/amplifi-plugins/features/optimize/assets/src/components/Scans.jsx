import { useState } from '@wordpress/element';
import { Button, Card, CardBody, CardHeader, Notice, Spinner } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { api, config } from '../api';

export default function Scans() {
	const fixTypes = config.fixTypes || {};
	const [ running, setRunning ] = useState( null );
	const [ results, setResults ] = useState( {} );
	const [ error, setError ] = useState( null );

	const runScan = async ( type ) => {
		setError( null );
		setRunning( type );
		try {
			const r = await api.scan( type, { limit: 500 } );
			setResults( ( prev ) => ( { ...prev, [ type ]: { scan: r } } ) );
			const p = await api.propose( type, { limit: 25 } );
			setResults( ( prev ) => ( { ...prev, [ type ]: { ...prev[ type ], propose: p } } ) );
		} catch ( e ) {
			setError( e.message );
		} finally {
			setRunning( null );
		}
	};

	return (
		<div className="ao-scans">
			<h1>{ __( 'Scans', 'amplifi-optimize' ) }</h1>
			<p>{ __( 'Run a scan to find candidates, then propose fixes via Claude. Approve them in the Review Queue.', 'amplifi-optimize' ) }</p>
			{ error && <Notice status="error">{ error }</Notice> }
			<div className="ao-grid">
				{ Object.entries( fixTypes ).map( ( [ slug, info ] ) => {
					const r = results[ slug ];
					return (
						<Card key={ slug }>
							<CardHeader>{ info.label }</CardHeader>
							<CardBody>
								<Button variant="primary" isBusy={ running === slug } disabled={ !! running } onClick={ () => runScan( slug ) }>
									{ running === slug ? __( 'Running…', 'amplifi-optimize' ) : __( 'Scan + propose', 'amplifi-optimize' ) }
								</Button>
								{ r?.scan && (
									<p className="ao-result">
										{ sprintf(
											/* translators: 1: examined, 2: inserted, 3: skipped */
											__( 'Scan: examined %1$d, inserted %2$d, skipped %3$d.', 'amplifi-optimize' ),
											r.scan.examined,
											r.scan.inserted,
											r.scan.skipped
										) }
									</p>
								) }
								{ r?.propose && (
									<p className="ao-result">
										{ sprintf(
											/* translators: 1: processed, 2: failed */
											__( 'Propose: %1$d processed, %2$d failed.', 'amplifi-optimize' ),
											r.propose.processed,
											r.propose.failed
										) }
									</p>
								) }
							</CardBody>
						</Card>
					);
				} ) }
			</div>
		</div>
	);
}
