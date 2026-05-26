import { useState } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export default function Reasoning( { text } ) {
	const [ open, setOpen ] = useState( true );
	if ( ! text ) return null;
	return (
		<div className="ao-reasoning">
			<Button variant="link" onClick={ () => setOpen( ( v ) => ! v ) }>
				{ open ? __( 'Hide reasoning', 'amplifi-optimize' ) : __( 'Show reasoning', 'amplifi-optimize' ) }
			</Button>
			{ open && <p>{ text }</p> }
		</div>
	);
}
