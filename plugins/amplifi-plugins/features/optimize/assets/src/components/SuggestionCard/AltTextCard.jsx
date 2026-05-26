import { useState } from '@wordpress/element';
import { Button, Card, CardBody, CardHeader, TextareaControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import Reasoning from './Reasoning';

export default function AltTextCard( { suggestion, onEdit } ) {
	const [ editing, setEditing ] = useState( false );
	const [ draft, setDraft ] = useState( suggestion.proposed_value || '' );

	const meta = suggestion.proposed_metadata || {};
	const target = suggestion.target || {};
	const usedIn = meta.used_in || [];

	return (
		<Card className="ao-card">
			<CardHeader>
				<div>
					<strong>{ meta.filename || target.id }</strong>
					{ meta.is_decorative && <span className="ao-pill">{ __( 'Decorative', 'amplifi-optimize' ) }</span> }
				</div>
			</CardHeader>
			<CardBody>
				<div className="ao-alt">
					<div className="ao-alt-thumb">
						{ target.thumb ? (
							<img src={ target.thumb } alt="" />
						) : (
							<div className="ao-thumb-placeholder">{ __( 'No preview', 'amplifi-optimize' ) }</div>
						) }
					</div>
					<div className="ao-alt-body">
						<h4>{ __( 'Proposed alt text', 'amplifi-optimize' ) }</h4>
						{ editing ? (
							<>
								<TextareaControl value={ draft } onChange={ setDraft } rows={ 3 } __nextHasNoMarginBottom />
								<div className="ao-edit-actions">
									<Button variant="primary" onClick={ () => { onEdit( draft, false ); setEditing( false ); } }>{ __( 'Save', 'amplifi-optimize' ) }</Button>
									<Button variant="primary" onClick={ () => { onEdit( draft, true ); setEditing( false ); } }>{ __( 'Save and approve', 'amplifi-optimize' ) }</Button>
									<Button variant="tertiary" onClick={ () => setEditing( false ) }>{ __( 'Cancel', 'amplifi-optimize' ) }</Button>
								</div>
							</>
						) : (
							<>
								<p className="ao-diff-proposed">{ suggestion.proposed_value || <em>{ __( '(empty — marked decorative)', 'amplifi-optimize' ) }</em> }</p>
								<p className="ao-char">
									{ sprintf(
										/* translators: %d character count */
										__( '%d chars', 'amplifi-optimize' ),
										( suggestion.proposed_value || '' ).length
									) }
								</p>
								<Button variant="tertiary" onClick={ () => { setDraft( suggestion.proposed_value || '' ); setEditing( true ); } }>{ __( 'Edit (E)', 'amplifi-optimize' ) }</Button>
							</>
						) }
						{ usedIn.length > 0 && (
							<>
								<h4>{ __( 'Used in', 'amplifi-optimize' ) }</h4>
								<ul className="ao-used-in">
									{ usedIn.map( ( p ) => (
										<li key={ p.id }>{ p.title || `#${ p.id }` }</li>
									) ) }
								</ul>
							</>
						) }
					</div>
				</div>
				<Reasoning text={ meta.reasoning } />
			</CardBody>
		</Card>
	);
}
