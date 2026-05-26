import { useState } from '@wordpress/element';
import { Button, Card, CardBody, CardHeader, TextareaControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import Reasoning from './Reasoning';

export default function MetaDescriptionCard( { suggestion, onEdit } ) {
	const [ editing, setEditing ] = useState( false );
	const [ draft, setDraft ] = useState( suggestion.proposed_value || '' );

	const meta = suggestion.proposed_metadata || {};
	const target = suggestion.target || {};

	return (
		<Card className="ao-card">
			<CardHeader>
				<div>
					<strong>{ target.title || `#${ suggestion.target_id }` }</strong>
					<div className="ao-card-sub">
						<a href={ target.url } target="_blank" rel="noopener noreferrer">{ target.url }</a>
					</div>
				</div>
			</CardHeader>
			<CardBody>
				<div className="ao-diff">
					<div>
						<h4>{ __( 'Current', 'amplifi-optimize' ) }</h4>
						<p className="ao-diff-current">
							{ suggestion.current_value || <em>{ __( '(empty)', 'amplifi-optimize' ) }</em> }
						</p>
					</div>
					<div>
						<h4>{ __( 'Proposed', 'amplifi-optimize' ) }</h4>
						{ editing ? (
							<>
								<TextareaControl
									value={ draft }
									onChange={ setDraft }
									rows={ 3 }
									__nextHasNoMarginBottom
								/>
								<div className="ao-edit-actions">
									<Button variant="primary" onClick={ () => { onEdit( draft, false ); setEditing( false ); } }>{ __( 'Save', 'amplifi-optimize' ) }</Button>
									<Button variant="primary" onClick={ () => { onEdit( draft, true ); setEditing( false ); } }>{ __( 'Save and approve', 'amplifi-optimize' ) }</Button>
									<Button variant="tertiary" onClick={ () => setEditing( false ) }>{ __( 'Cancel', 'amplifi-optimize' ) }</Button>
								</div>
							</>
						) : (
							<>
								<p className="ao-diff-proposed">{ suggestion.proposed_value }</p>
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
					</div>
				</div>
				<Reasoning text={ meta.reasoning } />
			</CardBody>
		</Card>
	);
}
