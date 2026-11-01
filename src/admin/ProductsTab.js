import { Button, Notice, SearchControl, Spinner, CheckboxControl, __experimentalConfirmDialog as ConfirmDialog } from '@wordpress/components';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf, _n } from '@wordpress/i18n';
import { request } from '../core3/api';
import config from '../core3/config';
import SummaryModal from './SummaryModal';
import { driveBulk } from './bulk';
import { isJobActive, progressPercent, progressText, statusMeta, timeAgo } from './utils';

const FILTERS = () => [
	{ id: '', label: __( 'All', 'dev3-ai-review-summarizer-for-woocommerce' ), stat: 'reviewed' },
	{ id: 'none', label: __( 'Missing', 'dev3-ai-review-summarizer-for-woocommerce' ), stat: 'none' },
	{ id: 'fresh', label: __( 'Fresh', 'dev3-ai-review-summarizer-for-woocommerce' ), stat: 'fresh' },
	{ id: 'stale', label: __( 'Stale', 'dev3-ai-review-summarizer-for-woocommerce' ), stat: 'stale' },
	{ id: 'locked', label: __( 'Locked', 'dev3-ai-review-summarizer-for-woocommerce' ), stat: 'locked' },
];

function Stars( { value } ) {
	if ( ! value ) {
		return null;
	}
	return (
		<span className="dev3rs-stars" title={ sprintf( /* translators: %s: average rating */ __( 'Average rating %s out of 5', 'dev3-ai-review-summarizer-for-woocommerce' ), value ) }>
			★ { Number( value ).toFixed( 1 ) }
		</span>
	);
}

function BulkBar( { stats, progress, onStart, onCancel } ) {
	const active = isJobActive( progress );
	const pct = progressPercent( progress );
	const finished = progress && [ 'done', 'cancelled', 'failed' ].includes( progress.status ) && progress.total > 0;
	return (
		<div className="dev3rs-card dev3rs-bulk">
			<div className="dev3rs-bulk__row">
				<div>
					<h2>{ __( 'Bulk generate', 'dev3-ai-review-summarizer-for-woocommerce' ) }</h2>
					<p className="description dev3rs-m-0">{ __( 'Runs in the background and continues while this screen is open. Locked summaries are never overwritten.', 'dev3-ai-review-summarizer-for-woocommerce' ) }</p>
				</div>
				<div className="dev3rs-bulk__actions">
					<Button variant="primary" disabled={ active || ! stats.none } onClick={ () => onStart( 'missing' ) }>
						{ sprintf( /* translators: %d: products */ __( 'Generate missing (%d)', 'dev3-ai-review-summarizer-for-woocommerce' ), stats.none || 0 ) }
					</Button>
					<Button variant="secondary" disabled={ active || ! stats.stale } onClick={ () => onStart( 'stale' ) }>
						{ sprintf( /* translators: %d: products */ __( 'Refresh stale (%d)', 'dev3-ai-review-summarizer-for-woocommerce' ), stats.stale || 0 ) }
					</Button>
				</div>
			</div>
			{ ( active || finished ) && (
				<div className="dev3rs-progress" role="status" aria-live="polite">
					<div className="dev3rs-progress__bar" aria-hidden="true">
						<span style={ { width: `${ pct }%` } } />
					</div>
					<div className="dev3rs-progress__meta">
						<span>
							<strong>{ pct }%</strong> { progressText( progress ) }
							{ active && progress.current && (
								<>
									{ ' · ' }
									{ sprintf( /* translators: %s: product name */ __( 'Now: %s', 'dev3-ai-review-summarizer-for-woocommerce' ), progress.current.name ) }
								</>
							) }
							{ progress.status === 'cancelled' && ` · ${ __( 'Cancelled', 'dev3-ai-review-summarizer-for-woocommerce' ) }` }
							{ progress.status === 'done' && ` · ${ __( 'Done', 'dev3-ai-review-summarizer-for-woocommerce' ) }` }
							{ progress.status === 'failed' && ` · ${ __( 'Stopped', 'dev3-ai-review-summarizer-for-woocommerce' ) }` }
						</span>
						{ active && (
							<Button variant="tertiary" isDestructive onClick={ onCancel }>
								{ __( 'Cancel', 'dev3-ai-review-summarizer-for-woocommerce' ) }
							</Button>
						) }
					</div>
					{ progress.errors && progress.errors.length > 0 && (
						<details className="dev3rs-progress__errors">
							<summary>{ sprintf( /* translators: %d: errors */ _n( '%d error', '%d errors', progress.errors.length, 'dev3-ai-review-summarizer-for-woocommerce' ), progress.errors.length ) }</summary>
							<ul>
								{ progress.errors.map( ( e, i ) => (
									<li key={ i }>
										<strong>{ e.name }</strong>: { e.message }
									</li>
								) ) }
							</ul>
						</details>
					) }
				</div>
			) }
		</div>
	);
}

export default function ProductsTab() {
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ notice, setNotice ] = useState( null );
	const [ filter, setFilter ] = useState( '' );
	const [ search, setSearch ] = useState( '' );
	const [ page, setPage ] = useState( 1 );
	const [ busy, setBusy ] = useState( {} );
	const [ selected, setSelected ] = useState( [] );
	const [ modal, setModal ] = useState( null );
	const [ confirm, setConfirm ] = useState( null );
	const [ progress, setProgress ] = useState( null );
	const stop = useRef( false );
	const driving = useRef( false );

	const load = useCallback(
		() =>
			request( `products?page=${ page }&per_page=20&status=${ filter }&search=${ encodeURIComponent( search ) }` )
				.then( ( res ) => {
					setData( res );
					setProgress( ( p ) => ( driving.current ? p : res.bulk ) );
					return res;
				} )
				.catch( setError ),
		[ page, filter, search ]
	);

	useEffect( () => {
		const t = setTimeout( load, search ? 300 : 0 );
		return () => clearTimeout( t );
	}, [ load, search ] );

	const drive = useCallback( () => {
		if ( driving.current ) {
			return;
		}
		driving.current = true;
		stop.current = false;
		driveBulk( {
			step: () => request( 'bulk/step', { method: 'POST' } ),
			read: () => request( 'bulk' ),
			onProgress: setProgress,
			stopped: () => stop.current,
		} )
			.catch( setError )
			.finally( () => {
				driving.current = false;
				load();
			} );
	}, [ load ] );

	// Resume a job that was started earlier (e.g. before a reload).
	useEffect( () => {
		if ( isJobActive( progress ) && ! driving.current ) {
			drive();
		}
	}, [ progress, drive ] );

	useEffect(
		() => () => {
			stop.current = true;
		},
		[]
	);

	const startBulk = ( mode, ids = [] ) => {
		setError( null );
		request( 'bulk', { method: 'POST', data: { mode, ids } } )
			.then( ( p ) => {
				setProgress( p );
				setSelected( [] );
				if ( isJobActive( p ) ) {
					drive();
				}
			} )
			.catch( setError );
	};

	const cancelBulk = () => {
		stop.current = true;
		request( 'bulk', { method: 'DELETE' } ).then( setProgress ).catch( setError ).finally( load );
	};

	const regenerate = ( row, unlock = false ) => {
		setBusy( ( b ) => ( { ...b, [ row.id ]: true } ) );
		setError( null );
		setNotice( null );
		request( `regenerate/${ row.id }`, { method: 'POST', data: { force: true, unlock } } )
			.then( () => {
				/* translators: %s: product name */
				setNotice( sprintf( __( 'New AI summary generated for “%s”.', 'dev3-ai-review-summarizer-for-woocommerce' ), row.name ) );
				return load();
			} )
			.catch( ( e ) => setError( { ...e, message: `${ row.name }: ${ e.message }` } ) )
			.finally( () => setBusy( ( b ) => ( { ...b, [ row.id ]: false } ) ) );
	};

	const onRegenerate = ( row ) => {
		if ( row.locked && ConfirmDialog ) {
			setConfirm( row );
		} else if ( row.locked ) {
			// eslint-disable-next-line no-alert
			if ( window.confirm( __( 'This summary is locked. Regenerate it and remove the lock?', 'dev3-ai-review-summarizer-for-woocommerce' ) ) ) {
				regenerate( row, true );
			}
		} else {
			regenerate( row );
		}
	};

	if ( ! config.wooActive ) {
		return <Notice status="error" isDismissible={ false }>{ __( 'WooCommerce is not active. Activate it to summarise product reviews.', 'dev3-ai-review-summarizer-for-woocommerce' ) }</Notice>;
	}
	if ( ! data && ! error ) {
		return <Spinner />;
	}
	const stats = ( data && data.stats ) || {};
	const items = ( data && data.items ) || [];
	const allChecked = items.length > 0 && items.every( ( r ) => selected.includes( r.id ) );
	const active = isJobActive( progress );

	return (
		<div className="dev3rs-products">
			<div className="dev3rs-stats">
				{ [
					[ stats.reviewed, __( 'Products with reviews', 'dev3-ai-review-summarizer-for-woocommerce' ) ],
					[ stats.fresh, __( 'Fresh summaries', 'dev3-ai-review-summarizer-for-woocommerce' ) ],
					[ stats.stale, __( 'Stale (new reviews)', 'dev3-ai-review-summarizer-for-woocommerce' ) ],
					[ stats.locked, __( 'Locked', 'dev3-ai-review-summarizer-for-woocommerce' ) ],
					[ stats.none, __( 'Missing (eligible)', 'dev3-ai-review-summarizer-for-woocommerce' ) ],
				].map( ( [ n, label ] ) => (
					<div className="dev3rs-stat" key={ label }>
						<strong>{ n || 0 }</strong>
						<span>{ label }</span>
					</div>
				) ) }
			</div>

			<BulkBar stats={ stats } progress={ progress } onStart={ startBulk } onCancel={ cancelBulk } />

			{ error && (
				<Notice status="error" onRemove={ () => setError( null ) }>
					{ error.message } { error.hint && <em>{ error.hint }</em> }
				</Notice>
			) }
			{ notice && (
				<Notice status="success" onRemove={ () => setNotice( null ) }>
					{ notice }
				</Notice>
			) }

			<div className="dev3rs-toolbar">
				<div className="dev3rs-filters" role="tablist" aria-label={ __( 'Filter by status', 'dev3-ai-review-summarizer-for-woocommerce' ) }>
					{ FILTERS().map( ( f ) => (
						<button
							key={ f.id || 'all' }
							type="button"
							role="tab"
							aria-selected={ filter === f.id }
							className={ filter === f.id ? 'is-active' : '' }
							onClick={ () => {
								setFilter( f.id );
								setPage( 1 );
							} }
						>
							{ f.label } <span className="count">({ stats[ f.stat ] || 0 })</span>
						</button>
					) ) }
				</div>
				<div className="dev3rs-toolbar__right">
					{ selected.length > 0 && (
						<Button variant="secondary" disabled={ active } onClick={ () => startBulk( 'selected', selected ) }>
							{ sprintf( /* translators: %d: products */ __( 'Regenerate selected (%d)', 'dev3-ai-review-summarizer-for-woocommerce' ), selected.length ) }
						</Button>
					) }
					<SearchControl
						__nextHasNoMarginBottom
						label={ __( 'Search products', 'dev3-ai-review-summarizer-for-woocommerce' ) }
						value={ search }
						onChange={ ( v ) => {
							setSearch( v );
							setPage( 1 );
						} }
					/>
				</div>
			</div>

			<table className="widefat striped dev3rs-table">
				<thead>
					<tr>
						<td className="check-column">
							<CheckboxControl
								__nextHasNoMarginBottom
								aria-label={ __( 'Select all', 'dev3-ai-review-summarizer-for-woocommerce' ) }
								checked={ allChecked }
								onChange={ ( on ) => setSelected( on ? items.filter( ( r ) => r.eligible ).map( ( r ) => r.id ) : [] ) }
							/>
						</td>
						<th>{ __( 'Product', 'dev3-ai-review-summarizer-for-woocommerce' ) }</th>
						<th>{ __( 'Reviews', 'dev3-ai-review-summarizer-for-woocommerce' ) }</th>
						<th>{ __( 'Summary', 'dev3-ai-review-summarizer-for-woocommerce' ) }</th>
						<th>{ __( 'Updated', 'dev3-ai-review-summarizer-for-woocommerce' ) }</th>
						<th className="dev3rs-col-actions">{ __( 'Actions', 'dev3-ai-review-summarizer-for-woocommerce' ) }</th>
					</tr>
				</thead>
				<tbody>
					{ items.length === 0 && (
						<tr>
							<td colSpan="6" className="dev3rs-empty">
								{ __( 'No products match. Products appear here once they have approved reviews.', 'dev3-ai-review-summarizer-for-woocommerce' ) }
							</td>
						</tr>
					) }
					{ items.map( ( row ) => {
						const meta = statusMeta( row.status );
						const rowBusy = !! busy[ row.id ] || ( active && progress.current && progress.current.id === row.id );
						return (
							<tr key={ row.id } data-product={ row.id }>
								<th scope="row" className="check-column">
									<CheckboxControl
										__nextHasNoMarginBottom
										aria-label={ sprintf( /* translators: %s: product */ __( 'Select %s', 'dev3-ai-review-summarizer-for-woocommerce' ), row.name ) }
										disabled={ ! row.eligible }
										checked={ selected.includes( row.id ) }
										onChange={ ( on ) => setSelected( ( s ) => ( on ? [ ...s, row.id ] : s.filter( ( id ) => id !== row.id ) ) ) }
									/>
								</th>
								<td>
									<div className="dev3rs-product">
										{ row.thumbnail ? <img src={ row.thumbnail } alt="" width="36" height="36" /> : <span className="dev3rs-thumb" aria-hidden="true" /> }
										<div>
											<a href={ row.edit_link } className="dev3rs-product__name">
												{ row.name }
											</a>
											<div className="row-actions visible">
												<a href={ row.permalink } target="_blank" rel="noreferrer">
													{ __( 'View product', 'dev3-ai-review-summarizer-for-woocommerce' ) }
												</a>
											</div>
										</div>
									</div>
								</td>
								<td>
									{ row.review_count } <Stars value={ row.average } />
									{ ! row.eligible && (
										<div className="dev3rs-muted">
											{ sprintf( /* translators: %d: minimum reviews */ __( 'Needs %d to summarise', 'dev3-ai-review-summarizer-for-woocommerce' ), data.min_reviews ) }
										</div>
									) }
								</td>
								<td>
									<span className={ `dev3rs-badge ${ meta.tone }` } data-status={ row.status }>
										{ meta.label }
									</span>
									{ row.locked && row.changed && <div className="dev3rs-muted">{ __( 'New reviews since locking', 'dev3-ai-review-summarizer-for-woocommerce' ) }</div> }
									{ row.pending > 0 && <div className="dev3rs-muted">{ __( 'Update queued', 'dev3-ai-review-summarizer-for-woocommerce' ) }</div> }
								</td>
								<td>
									{ row.meta ? (
										<>
											{ timeAgo( row.meta.edited_at || row.meta.generated_at ) }
											<div className="dev3rs-muted">{ row.meta.source === 'edited' ? __( 'Edited by a moderator', 'dev3-ai-review-summarizer-for-woocommerce' ) : row.meta.model }</div>
										</>
									) : (
										'—'
									) }
								</td>
								<td className="dev3rs-col-actions">
									<Button variant="secondary" size="compact" className="dev3rs-regen" isBusy={ rowBusy } disabled={ rowBusy || ! row.eligible || active } onClick={ () => onRegenerate( row ) }>
										{ rowBusy ? __( 'Generating…', 'dev3-ai-review-summarizer-for-woocommerce' ) : row.summary ? __( 'Regenerate', 'dev3-ai-review-summarizer-for-woocommerce' ) : __( 'Generate with DEV3 AI', 'dev3-ai-review-summarizer-for-woocommerce' ) }
									</Button>
									<Button variant="tertiary" size="compact" className="dev3rs-view" disabled={ ! row.summary } onClick={ () => setModal( row ) }>
										{ __( 'View / Edit', 'dev3-ai-review-summarizer-for-woocommerce' ) }
									</Button>
								</td>
							</tr>
						);
					} ) }
				</tbody>
			</table>

			{ data && data.pages > 1 && (
				<div className="dev3rs-pager">
					<Button variant="secondary" size="compact" disabled={ page <= 1 } onClick={ () => setPage( page - 1 ) }>
						‹ { __( 'Previous', 'dev3-ai-review-summarizer-for-woocommerce' ) }
					</Button>
					<span>{ sprintf( /* translators: 1: page, 2: pages */ __( 'Page %1$d of %2$d', 'dev3-ai-review-summarizer-for-woocommerce' ), page, data.pages ) }</span>
					<Button variant="secondary" size="compact" disabled={ page >= data.pages } onClick={ () => setPage( page + 1 ) }>
						{ __( 'Next', 'dev3-ai-review-summarizer-for-woocommerce' ) } ›
					</Button>
				</div>
			) }

			{ modal && (
				<SummaryModal
					productId={ modal.id }
					name={ modal.name }
					onClose={ () => setModal( null ) }
					onSaved={ ( msg ) => {
						setNotice( msg );
						load();
					} }
				/>
			) }
			{ confirm && ConfirmDialog && (
				<ConfirmDialog
					isOpen
					confirmButtonText={ __( 'Regenerate and unlock', 'dev3-ai-review-summarizer-for-woocommerce' ) }
					onConfirm={ () => {
						const row = confirm;
						setConfirm( null );
						regenerate( row, true );
					} }
					onCancel={ () => setConfirm( null ) }
				>
					{ sprintf( /* translators: %s: product */ __( 'The summary for “%s” is locked by a moderator. Replace it with a new AI summary and remove the lock?', 'dev3-ai-review-summarizer-for-woocommerce' ), confirm.name ) }
				</ConfirmDialog>
			) }
		</div>
	);
}
