import { useState } from '@wordpress/element';
import { Button, Card, CardBody, CardHeader, TextControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import Reasoning from './Reasoning';

export default function TitleCard( { suggestion, onEdit } ) {
	const [ editing, setEditing ] = useState( false );
	const [ draft, setDraft ] = useState( suggestion.proposed_value || '' );

	const meta = suggestion.proposed_metadata || {};
	const target = suggestion.target || {};
	const currentLen = ( suggestion.current_value || '' ).length;
	const proposedLen = ( suggestion.proposed_value || '' ).length;

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
						<h4>{ __( 'Current title', 'amplifi-optimize' ) }</h4>
						<p className="ao-diff-current">{ suggestion.current_value }</p>
						<p className="ao-char ao-char--bad">
							{ sprintf(
								/* translators: %d character count */
								__( '%d chars', 'amplifi-optimize' ),
								currentLen
							) }
						</p>
					</div>
					<div>
						<h4>{ __( 'Proposed title', 'amplifi-optimize' ) }</h4>
						{ editing ? (
							<>
								<TextControl value={ draft } onChange={ setDraft } __nextHasNoMarginBottom __next40pxDefaultSize />
								<div className="ao-edit-actions">
									<Button variant="primary" onClick={ () => { onEdit( draft, false ); setEditing( false ); } }>{ __( 'Save', 'amplifi-optimize' ) }</Button>
									<Button variant="primary" onClick={ () => { onEdit( draft, true ); setEditing( false ); } }>{ __( 'Save and approve', 'amplifi-optimize' ) }</Button>
									<Button variant="tertiary" onClick={ () => setEditing( false ) }>{ __( 'Cancel', 'amplifi-optimize' ) }</Button>
								</div>
							</>
						) : (
							<>
								<p className="ao-diff-proposed">{ suggestion.proposed_value }</p>
								<p className="ao-char ao-char--good">
									{ sprintf(
										/* translators: %d character count */
										__( '%d chars', 'amplifi-optimize' ),
										proposedLen
									) }
								</p>
								{ meta.brand_preview && (
									<p className="ao-brand-preview">
										{ __( 'With brand:', 'amplifi-optimize' ) } <em>{ meta.brand_preview }</em>
									</p>
								) }
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
