import { Button, CheckboxControl, Modal, Notice, SelectControl, Spinner, TextareaControl, TextControl, TabPanel } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf, _n } from '@wordpress/i18n';
import { request } from '../core3/api';
import { buildEditPayload, normaliseSentiment, SIZING_OPTIONS, summaryToForm, TAG_OPTIONS, timeAgo } from './utils';

function Preview( { productId, version } ) {
	const [ html, setHtml ] = useState( null );
	useEffect( () => {
		request( 'preview', { method: 'POST', data: { product_id: productId } } )
			.then( ( r ) => setHtml( r.html ) )
			.catch( () => setHtml( '' ) );
	}, [ productId, version ] );
	if ( html === null ) {
		return <Spinner />;
	}
	// Server-rendered and escaped by the plugin's Renderer.
	return <div className="dev3rs-preview-frame" dangerouslySetInnerHTML={ { __html: html } } />;
}

function KeywordsEditor( { value, onChange } ) {
	const rows = value || [];
	const update = ( i, patch ) => onChange( rows.map( ( r, j ) => ( j === i ? { ...r, ...patch } : r ) ) );
	return (
		<fieldset className="dev3rs-keywords-editor">
			<legend>{ __( 'Keywords & themes', 'dev3-ai-review-summarizer-for-woocommerce' ) }</legend>
			{ rows.map( ( row, i ) => (
				<div className="dev3rs-kw-row" key={ i }>
					<TextControl __nextHasNoMarginBottom label={ __( 'Term', 'dev3-ai-review-summarizer-for-woocommerce' ) } hideLabelFromVision value={ row.term } maxLength={ 40 } onChange={ ( term ) => update( i, { term } ) } />
					<SelectControl __nextHasNoMarginBottom label={ __( 'Sentiment', 'dev3-ai-review-summarizer-for-woocommerce' ) } hideLabelFromVision value={ row.sentiment } options={ TAG_OPTIONS() } onChange={ ( sentiment ) => update( i, { sentiment } ) } />
					<Button
						icon="no-alt"
						label={ sprintf( /* translators: %s: keyword */ __( 'Remove “%s”', 'dev3-ai-review-summarizer-for-woocommerce' ), row.term ) }
						onClick={ () => onChange( rows.filter( ( _, j ) => j !== i ) ) }
					/>
				</div>
			) ) }
			{ rows.length < 8 && (
				<Button variant="link" onClick={ () => onChange( [ ...rows, { term: '', sentiment: 'neutral', mentions: 1 } ] ) }>
					+ { __( 'Add keyword', 'dev3-ai-review-summarizer-for-woocommerce' ) }
				</Button>
			) }
		</fieldset>
	);
}

export default function SummaryModal( { productId, name, onClose, onSaved } ) {
	const [ item, setItem ] = useState( null );
	const [ form, setForm ] = useState( null );
	const [ lock, setLock ] = useState( true );
	const [ error, setError ] = useState( null );
	const [ saving, setSaving ] = useState( false );
	const [ version, setVersion ] = useState( 0 );

	const load = () =>
		request( `summary/${ productId }?context=edit` )
			.then( ( res ) => {
				setItem( res );
				setForm( summaryToForm( res.summary ) );
				setLock( true );
			} )
			.catch( setError );

	useEffect( () => {
		load();
	}, [ productId ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const set = ( key ) => ( value ) => setForm( { ...form, [ key ]: value } );
	const setShare = ( key ) => ( value ) => setForm( { ...form, sentiment: { ...form.sentiment, [ key ]: value } } );
	const shares = form ? normaliseSentiment( form.sentiment ) : null;

	const save = () => {
		setSaving( true );
		setError( null );
		request( `summary/${ productId }`, { method: 'PUT', data: buildEditPayload( form, lock ) } )
			.then( ( res ) => {
				setItem( res );
				setForm( summaryToForm( res.summary ) );
				setVersion( ( v ) => v + 1 );
				onSaved( lock ? __( 'Summary saved and locked.', 'dev3-ai-review-summarizer-for-woocommerce' ) : __( 'Summary saved.', 'dev3-ai-review-summarizer-for-woocommerce' ) );
			} )
			.catch( setError )
			.finally( () => setSaving( false ) );
	};

	const toggleLock = ( locked ) => {
		setSaving( true );
		request( `summary/${ productId }`, { method: 'PUT', data: { locked } } )
			.then( ( res ) => {
				setItem( res );
				onSaved( locked ? __( 'Summary locked.', 'dev3-ai-review-summarizer-for-woocommerce' ) : __( 'Summary unlocked. It will update automatically when reviews change.', 'dev3-ai-review-summarizer-for-woocommerce' ) );
			} )
			.catch( setError )
			.finally( () => setSaving( false ) );
	};

	const remove = () => {
		// eslint-disable-next-line no-alert
		if ( ! window.confirm( __( 'Delete this summary? It disappears from the product page until a new one is generated.', 'dev3-ai-review-summarizer-for-woocommerce' ) ) ) {
			return;
		}
		request( `summary/${ productId }`, { method: 'DELETE' } )
			.then( () => {
				onSaved( __( 'Summary deleted.', 'dev3-ai-review-summarizer-for-woocommerce' ) );
				onClose();
			} )
			.catch( setError );
	};

	const meta = item && item.meta;
	return (
		<Modal
			title={ sprintf( /* translators: %s: product */ __( 'AI review summary: %s', 'dev3-ai-review-summarizer-for-woocommerce' ), name ) }
			onRequestClose={ onClose }
			className="dev3rs-root dev3rs-modal"
			size="large"
		>
			{ error && (
				<Notice status="error" onRemove={ () => setError( null ) }>
					{ error.message }
				</Notice>
			) }
			{ ! item && ! error && <Spinner /> }
			{ item && form && (
				<>
					<p className="dev3rs-modal__meta">
						{ item.locked && <span className="dev3rs-badge is-locked">{ __( 'Locked', 'dev3-ai-review-summarizer-for-woocommerce' ) }</span> }{ ' ' }
						{ meta &&
							sprintf(
								/* translators: 1: time ago, 2: review count, 3: model */
								__( 'Generated %1$s from %2$d reviews · %3$s', 'dev3-ai-review-summarizer-for-woocommerce' ),
								timeAgo( meta.generated_at ),
								meta.review_count,
								meta.model || '—'
							) }
						{ meta && meta.edited_at > 0 && ` · ${ sprintf( /* translators: %s: time ago */ __( 'edited %s', 'dev3-ai-review-summarizer-for-woocommerce' ), timeAgo( meta.edited_at ) ) }` }
					</p>
					{ meta && meta.flagged && meta.flagged.length > 0 && (
						<Notice status="warning" isDismissible={ false } className="dev3rs-flagged">
							<p>
								{ sprintf(
									/* translators: %d: count */
									_n(
										'The AI ignored %d review that looked like instructions to the AI, spam or off-topic text. You may want to moderate it:',
										'The AI ignored %d reviews that looked like instructions to the AI, spam or off-topic text. You may want to moderate them:',
										meta.flagged.length,
										'dev3-ai-review-summarizer-for-woocommerce'
									),
									meta.flagged.length
								) }
							</p>
							<ul>
								{ meta.flagged.map( ( r ) => (
									<li key={ r.id }>
										<a href={ r.link }>{ r.excerpt }</a>
									</li>
								) ) }
							</ul>
						</Notice>
					) }
					<TabPanel
						className="dev3rs-modal__tabs"
						tabs={ [
							{ name: 'preview', title: __( 'Preview', 'dev3-ai-review-summarizer-for-woocommerce' ) },
							{ name: 'edit', title: __( 'Edit', 'dev3-ai-review-summarizer-for-woocommerce' ) },
						] }
					>
						{ ( tab ) =>
							tab.name === 'preview' ? (
								<div className="dev3rs-modal__body">
									<Preview productId={ productId } version={ version } />
								</div>
							) : (
								<div className="dev3rs-modal__body dev3rs-edit">
									<TextareaControl __nextHasNoMarginBottom label={ __( 'Summary paragraph', 'dev3-ai-review-summarizer-for-woocommerce' ) } value={ form.summary } rows={ 4 } maxLength={ 700 } onChange={ set( 'summary' ) } />
									<div className="dev3rs-edit__grid">
										<TextareaControl __nextHasNoMarginBottom label={ __( 'What buyers love (one per line)', 'dev3-ai-review-summarizer-for-woocommerce' ) } value={ form.pros } rows={ 5 } onChange={ set( 'pros' ) } />
										<TextareaControl __nextHasNoMarginBottom label={ __( 'Things to consider (one per line)', 'dev3-ai-review-summarizer-for-woocommerce' ) } value={ form.cons } rows={ 5 } onChange={ set( 'cons' ) } />
									</div>
									<fieldset className="dev3rs-edit__grid dev3rs-edit__grid--3">
										<legend>
											{ __( 'Sentiment (%)', 'dev3-ai-review-summarizer-for-woocommerce' ) }{ ' ' }
											<span className="dev3rs-muted">
												{ sprintf( /* translators: 1-3: percentages */ __( 'saved as %1$d / %2$d / %3$d', 'dev3-ai-review-summarizer-for-woocommerce' ), shares.positive, shares.neutral, shares.negative ) }
											</span>
										</legend>
										<TextControl __nextHasNoMarginBottom type="number" min={ 0 } max={ 100 } label={ __( 'Positive', 'dev3-ai-review-summarizer-for-woocommerce' ) } value={ form.sentiment.positive } onChange={ setShare( 'positive' ) } />
										<TextControl __nextHasNoMarginBottom type="number" min={ 0 } max={ 100 } label={ __( 'Neutral', 'dev3-ai-review-summarizer-for-woocommerce' ) } value={ form.sentiment.neutral } onChange={ setShare( 'neutral' ) } />
										<TextControl __nextHasNoMarginBottom type="number" min={ 0 } max={ 100 } label={ __( 'Negative', 'dev3-ai-review-summarizer-for-woocommerce' ) } value={ form.sentiment.negative } onChange={ setShare( 'negative' ) } />
									</fieldset>
									<div className="dev3rs-edit__grid dev3rs-edit__grid--3">
										<TextControl __nextHasNoMarginBottom type="number" step="0.1" min={ 1 } max={ 5 } label={ __( 'Quality (1–5, empty = hide)', 'dev3-ai-review-summarizer-for-woocommerce' ) } value={ form.quality } onChange={ set( 'quality' ) } />
										<TextControl __nextHasNoMarginBottom type="number" step="0.1" min={ 1 } max={ 5 } label={ __( 'Value (1–5, empty = hide)', 'dev3-ai-review-summarizer-for-woocommerce' ) } value={ form.value } onChange={ set( 'value' ) } />
										<SelectControl __nextHasNoMarginBottom label={ __( 'Sizing', 'dev3-ai-review-summarizer-for-woocommerce' ) } value={ form.sizing } options={ SIZING_OPTIONS() } onChange={ set( 'sizing' ) } />
									</div>
									<KeywordsEditor value={ form.keywords } onChange={ set( 'keywords' ) } />
									<CheckboxControl
										__nextHasNoMarginBottom
										label={ __( 'Lock this summary (never overwrite it automatically)', 'dev3-ai-review-summarizer-for-woocommerce' ) }
										checked={ lock }
										onChange={ setLock }
									/>
									<div className="dev3rs-modal__actions">
										<Button variant="primary" className="dev3rs-save" isBusy={ saving } disabled={ saving } onClick={ save }>
											{ lock ? __( 'Save & lock', 'dev3-ai-review-summarizer-for-woocommerce' ) : __( 'Save', 'dev3-ai-review-summarizer-for-woocommerce' ) }
										</Button>
									</div>
								</div>
							)
						}
					</TabPanel>
					<div className="dev3rs-modal__footer">
						{ item.locked ? (
							<Button variant="secondary" disabled={ saving } onClick={ () => toggleLock( false ) }>
								{ __( 'Unlock', 'dev3-ai-review-summarizer-for-woocommerce' ) }
							</Button>
						) : (
							<Button variant="secondary" disabled={ saving } onClick={ () => toggleLock( true ) }>
								{ __( 'Lock', 'dev3-ai-review-summarizer-for-woocommerce' ) }
							</Button>
						) }
						<Button variant="tertiary" isDestructive onClick={ remove }>
							{ __( 'Delete summary', 'dev3-ai-review-summarizer-for-woocommerce' ) }
						</Button>
						<Button variant="tertiary" onClick={ onClose }>
							{ __( 'Close', 'dev3-ai-review-summarizer-for-woocommerce' ) }
						</Button>
					</div>
				</>
			) }
		</Modal>
	);
}
