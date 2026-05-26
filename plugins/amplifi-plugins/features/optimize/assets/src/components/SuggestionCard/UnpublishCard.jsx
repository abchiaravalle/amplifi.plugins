import { Card, CardBody, CardHeader } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import Reasoning from './Reasoning';

const ACTION_LABELS = {
	delete: { label: 'Move to trash', tone: 'destructive' },
	redirect: { label: 'Create 301 redirect', tone: 'warning' },
	noindex: { label: 'Set noindex', tone: 'warning' },
	keep: { label: 'Keep as-is', tone: 'success' },
};

export default function UnpublishCard( { suggestion } ) {
	const meta = suggestion.proposed_metadata || {};
	const target = suggestion.target || {};
	const action = meta.action || suggestion.proposed_value;
	const info = ACTION_LABELS[ action ] || { label: action, tone: 'neutral' };

	return (
		<Card className="ao-card">
			<CardHeader>
				<div>
					<strong>{ suggestion.current_value || target.title }</strong>
					<div className="ao-card-sub">
						<a href={ target.url || meta.url } target="_blank" rel="noopener noreferrer">{ target.url || meta.url }</a>
					</div>
				</div>
				<span className={ `ao-verdict ao-verdict--${ info.tone }` }>{ info.label }</span>
			</CardHeader>
			<CardBody>
				<dl className="ao-stat-list">
					<dt>{ __( 'Last modified', 'amplifi-optimize' ) }</dt>
					<dd>{ target.modified || meta.modified }</dd>
					{ ( meta.reasons || [] ).length > 0 && (
						<>
							<dt>{ __( 'Flag reasons', 'amplifi-optimize' ) }</dt>
							<dd>{ meta.reasons.join( ', ' ) }</dd>
						</>
					) }
					{ action === 'redirect' && (
						<>
							<dt>{ __( 'Redirect target', 'amplifi-optimize' ) }</dt>
							<dd>{ meta.redirect_target || <em>{ __( '(none — will fail to apply)', 'amplifi-optimize' ) }</em> }</dd>
						</>
					) }
				</dl>
				<Reasoning text={ meta.reasoning } />
			</CardBody>
		</Card>
	);
}
