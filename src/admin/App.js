import { useEffect, useState } from '@wordpress/element';
import { Notice, Spinner } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { request } from '../core3/api';
import config from '../core3/config';
import ApiTab from '../core3/ApiTab';
import UsageTab from '../core3/UsageTab';
import ProductsTab from './ProductsTab';
import DisplayTab from './DisplayTab';
import GeneralTab from './GeneralTab';

const ALL_TABS = () => [
	{ id: 'products', label: __( 'Products', 'dev3-ai-review-summarizer-for-woocommerce' ) },
	{ id: 'display', label: __( 'Display', 'dev3-ai-review-summarizer-for-woocommerce' ), manage: true },
	{ id: 'general', label: __( 'General Settings', 'dev3-ai-review-summarizer-for-woocommerce' ), manage: true },
	{ id: 'api', label: __( 'API Config', 'dev3-ai-review-summarizer-for-woocommerce' ), manage: true },
	{ id: 'usage', label: __( 'Usage Logs', 'dev3-ai-review-summarizer-for-woocommerce' ), manage: true },
];

const tabs = () => ALL_TABS().filter( ( t ) => ! t.manage || config.canManage );

const fromHash = () => {
	const id = window.location.hash.replace( /^#\/?/, '' );
	const list = tabs();
	return list.some( ( t ) => t.id === id ) ? id : list[ 0 ].id;
};

export default function App() {
	const [ tab, setTab ] = useState( fromHash() );
	const [ settings, setSettings ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ saved, setSaved ] = useState( false );

	useEffect( () => {
		const onHash = () => setTab( fromHash() );
		window.addEventListener( 'hashchange', onHash );
		if ( config.canManage ) {
			request( 'settings' ).then( setSettings ).catch( setError );
		}
		return () => window.removeEventListener( 'hashchange', onHash );
	}, [] );

	const save = ( patch ) =>
		request( 'settings', { method: 'POST', data: patch } )
			.then( ( next ) => {
				setSettings( next );
				setSaved( true );
				setTimeout( () => setSaved( false ), 2500 );
				return next;
			} )
			.catch( ( e ) => {
				setError( e );
				throw e;
			} );

	const needsSettings = [ 'display', 'general', 'api' ].includes( tab );

	return (
		<div className="dev3rs-root">
			<h1 className="wp-heading-inline dev3rs-title">
				{ __( 'DEV3 AI Review Summaries', 'dev3-ai-review-summarizer-for-woocommerce' ) } <span className="dev3rs-text-xs dev3rs-text-gray-500">v{ config.version }</span>
			</h1>
			<nav className="dev3rs-admin-nav">
				{ tabs().map( ( t ) => (
					<a key={ t.id } href={ `#/${ t.id }` } className={ tab === t.id ? 'is-active' : '' } aria-current={ tab === t.id ? 'page' : undefined }>
						{ t.label }
					</a>
				) ) }
			</nav>
			{ error && (
				<Notice status="error" onRemove={ () => setError( null ) }>
					{ error.message }
				</Notice>
			) }
			{ saved && (
				<Notice status="success" isDismissible={ false }>
					{ __( 'Settings saved.', 'dev3-ai-review-summarizer-for-woocommerce' ) }
				</Notice>
			) }
			{ config.canManage && settings && ! settings.api_key_set && tab === 'products' && (
				<Notice status="warning" isDismissible={ false }>
					{ __( 'Add your Google Gemini API key under API Config to start generating summaries.', 'dev3-ai-review-summarizer-for-woocommerce' ) } <a href="#/api">{ __( 'Open API Config', 'dev3-ai-review-summarizer-for-woocommerce' ) }</a>
				</Notice>
			) }
			{ needsSettings && ! settings && ! error && <Spinner /> }
			{ tab === 'products' && <ProductsTab /> }
			{ settings && tab === 'display' && <DisplayTab settings={ settings } save={ save } /> }
			{ settings && tab === 'general' && <GeneralTab settings={ settings } save={ save } /> }
			{ settings && tab === 'api' && <ApiTab settings={ settings } save={ save } /> }
			{ tab === 'usage' && <UsageTab /> }
		</div>
	);
}
