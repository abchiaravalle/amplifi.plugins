import { useEffect, useState } from '@wordpress/element';
import {
	Button,
	CheckboxControl,
	Notice,
	SelectControl,
	Spinner,
	TextControl,
	__experimentalNumberControl as NumberControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { api } from '../api';

const POST_TYPE_OPTIONS = [ 'post', 'page' ];

export default function Settings() {
	const [ s, setS ] = useState( null );
	const [ apiKey, setApiKey ] = useState( '' );
	const [ saving, setSaving ] = useState( false );
	const [ saved, setSaved ] = useState( false );
	const [ error, setError ] = useState( null );

	useEffect( () => {
		api.getSettings().then( setS ).catch( ( e ) => setError( e.message ) );
	}, [] );

	if ( ! s ) {
		return error ? <Notice status="error">{ error }</Notice> : <Spinner />;
	}

	const update = ( key, value ) => {
		setS( { ...s, [ key ]: value } );
	};

	const save = async () => {
		setSaving( true );
		setError( null );
		setSaved( false );
		try {
			const payload = { ...s };
			delete payload.has_api_key;
			if ( apiKey ) {
				payload.api_key = apiKey;
			}
			const next = await api.updateSettings( payload );
			setS( next );
			setApiKey( '' );
			setSaved( true );
		} catch ( e ) {
			setError( e.message );
		}
		setSaving( false );
	};

	return (
		<div className="ao-settings">
			<h1>{ __( 'Settings', 'amplifi-optimize' ) }</h1>
			{ error && <Notice status="error">{ error }</Notice> }
			{ saved && <Notice status="success" onRemove={ () => setSaved( false ) }>{ __( 'Saved.', 'amplifi-optimize' ) }</Notice> }

			<h2>{ __( 'Anthropic API', 'amplifi-optimize' ) }</h2>
			<TextControl
				label={ __( 'API key', 'amplifi-optimize' ) }
				type="password"
				value={ apiKey }
				onChange={ setApiKey }
				placeholder={ s.has_api_key ? __( '(saved — enter a new value to replace)', 'amplifi-optimize' ) : __( 'sk-ant-…', 'amplifi-optimize' ) }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			<TextControl
				label={ __( 'Model', 'amplifi-optimize' ) }
				value={ s.model || '' }
				onChange={ ( v ) => update( 'model', v ) }
				help={ __( 'Default: claude-sonnet-4-5', 'amplifi-optimize' ) }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>

			<h2>{ __( 'Batching and limits', 'amplifi-optimize' ) }</h2>
			<NumberControl
				label={ __( 'Meta description batch size', 'amplifi-optimize' ) }
				value={ s.batch_size_meta }
				min={ 1 }
				max={ 25 }
				onChange={ ( v ) => update( 'batch_size_meta', parseInt( v, 10 ) ) }
			/>
			<NumberControl
				label={ __( 'Alt text batch size', 'amplifi-optimize' ) }
				value={ s.batch_size_alt }
				min={ 1 }
				max={ 20 }
				onChange={ ( v ) => update( 'batch_size_alt', parseInt( v, 10 ) ) }
			/>
			<NumberControl
				label={ __( 'Rate limit (requests/min)', 'amplifi-optimize' ) }
				value={ s.rate_limit_per_minute }
				min={ 1 }
				max={ 200 }
				onChange={ ( v ) => update( 'rate_limit_per_minute', parseInt( v, 10 ) ) }
			/>
			<NumberControl
				label={ __( 'Undo window (most-recent applies that show undo)', 'amplifi-optimize' ) }
				value={ s.undo_window }
				min={ 0 }
				max={ 500 }
				onChange={ ( v ) => update( 'undo_window', parseInt( v, 10 ) ) }
			/>

			<h2>{ __( 'Scan filters', 'amplifi-optimize' ) }</h2>
			<fieldset>
				<legend>{ __( 'Included post types', 'amplifi-optimize' ) }</legend>
				{ POST_TYPE_OPTIONS.map( ( pt ) => (
					<CheckboxControl
						key={ pt }
						label={ pt }
						checked={ ( s.included_post_types || [] ).includes( pt ) }
						onChange={ ( checked ) => {
							const set = new Set( s.included_post_types || [] );
							if ( checked ) set.add( pt );
							else set.delete( pt );
							update( 'included_post_types', Array.from( set ) );
						} }
						__nextHasNoMarginBottom
					/>
				) ) }
			</fieldset>
			<NumberControl
				label={ __( 'Skip images smaller than (px)', 'amplifi-optimize' ) }
				value={ s.min_image_dimension }
				min={ 1 }
				max={ 2000 }
				onChange={ ( v ) => update( 'min_image_dimension', parseInt( v, 10 ) ) }
			/>
			<CheckboxControl
				label={ __( 'Include SVG images', 'amplifi-optimize' ) }
				checked={ !! s.include_svg }
				onChange={ ( v ) => update( 'include_svg', v ) }
				__nextHasNoMarginBottom
			/>
			<SelectControl
				label={ __( 'SEO plugin detection', 'amplifi-optimize' ) }
				value={ s.detector_override || 'auto' }
				onChange={ ( v ) => update( 'detector_override', v ) }
				options={ [
					{ label: __( 'Auto-detect (Recommended)', 'amplifi-optimize' ), value: 'auto' },
					{ label: 'Yoast', value: 'yoast' },
					{ label: 'RankMath', value: 'rankmath' },
					{ label: 'AIOSEO', value: 'aioseo' },
					{ label: __( 'None — store internally', 'amplifi-optimize' ), value: 'none' },
				] }
				__nextHasNoMarginBottom
			/>

			<h2>{ __( 'Danger zone', 'amplifi-optimize' ) }</h2>
			<CheckboxControl
				label={ __( 'Delete plugin data on uninstall (drops the suggestions table)', 'amplifi-optimize' ) }
				checked={ !! s.delete_data_on_uninstall }
				onChange={ ( v ) => update( 'delete_data_on_uninstall', v ) }
				__nextHasNoMarginBottom
			/>

			<p>
				<Button variant="primary" onClick={ save } isBusy={ saving }>
					{ __( 'Save settings', 'amplifi-optimize' ) }
				</Button>
			</p>
		</div>
	);
}
