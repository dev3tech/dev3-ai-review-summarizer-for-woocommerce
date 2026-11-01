import { __, sprintf, _n } from '@wordpress/i18n';

/**
 * Status badge meta for a summary status.
 *
 * @param {string} status none|fresh|stale|locked.
 * @return {{label: string, tone: string}} Label and badge tone.
 */
export function statusMeta( status ) {
	switch ( status ) {
		case 'fresh':
			return { label: __( 'Fresh', 'dev3-ai-review-summarizer-for-woocommerce' ), tone: 'is-ok' };
		case 'stale':
			return { label: __( 'Stale', 'dev3-ai-review-summarizer-for-woocommerce' ), tone: 'is-warn' };
		case 'locked':
			return { label: __( 'Locked', 'dev3-ai-review-summarizer-for-woocommerce' ), tone: 'is-locked' };
		default:
			return { label: __( 'No summary', 'dev3-ai-review-summarizer-for-woocommerce' ), tone: 'is-none' };
	}
}

/**
 * Textarea (one item per line) → list.
 *
 * @param {string} text Text.
 * @param {number} max  Max items.
 * @return {string[]} Items.
 */
export function linesToList( text, max = 5 ) {
	const seen = new Set();
	const out = [];
	String( text || '' )
		.split( /\r?\n/ )
		.map( ( line ) => line.replace( /^\s*[-•*]\s*/, '' ).trim() )
		.forEach( ( line ) => {
			const key = line.toLowerCase();
			if ( line && ! seen.has( key ) && out.length < max ) {
				seen.add( key );
				out.push( line );
			}
		} );
	return out;
}

/**
 * List → textarea text.
 *
 * @param {string[]} list Items.
 * @return {string} Text.
 */
export const listToLines = ( list ) => ( Array.isArray( list ) ? list.join( '\n' ) : '' );

/**
 * Sentiment shares as integers summing to 100 (largest remainder), mirroring the PHP sanitizer.
 *
 * @param {Object} s { positive, neutral, negative }.
 * @return {Object} Normalised shares.
 */
export function normaliseSentiment( s ) {
	const keys = [ 'positive', 'neutral', 'negative' ];
	const vals = keys.map( ( k ) => Math.max( 0, Number( ( s || {} )[ k ] ) || 0 ) );
	const total = vals.reduce( ( a, b ) => a + b, 0 );
	if ( total <= 0 ) {
		return { positive: 0, neutral: 0, negative: 0 };
	}
	const exact = vals.map( ( v ) => ( v * 100 ) / total );
	const out = exact.map( Math.floor );
	let left = 100 - out.reduce( ( a, b ) => a + b, 0 );
	exact
		.map( ( v, i ) => [ v - Math.floor( v ), i ] )
		.sort( ( a, b ) => b[ 0 ] - a[ 0 ] )
		.forEach( ( [ , i ] ) => {
			if ( left > 0 ) {
				out[ i ]++;
				left--;
			}
		} );
	return { positive: out[ 0 ], neutral: out[ 1 ], negative: out[ 2 ] };
}

/**
 * Score field → number 1–5 (one decimal) or null for "not discussed".
 *
 * @param {*} value Raw.
 * @return {number|null} Score.
 */
export function clampScore( value ) {
	if ( value === '' || value === null || value === undefined ) {
		return null;
	}
	const n = Number( value );
	if ( ! Number.isFinite( n ) || n <= 0 ) {
		return null;
	}
	return Math.round( Math.min( 5, Math.max( 1, n ) ) * 10 ) / 10;
}

/**
 * Edit form state → PUT /summary body.
 *
 * @param {Object}  form   Form state.
 * @param {boolean} locked Lock.
 * @return {Object} Payload.
 */
export function buildEditPayload( form, locked ) {
	return {
		summary: String( form.summary || '' ).trim(),
		pros: linesToList( form.pros ),
		cons: linesToList( form.cons ),
		sentiment: normaliseSentiment( form.sentiment ),
		scores: { quality: clampScore( form.quality ), value: clampScore( form.value ), sizing: form.sizing || 'not_applicable' },
		keywords: ( form.keywords || [] )
			.filter( ( k ) => k && String( k.term || '' ).trim() )
			.slice( 0, 8 )
			.map( ( k ) => ( { term: String( k.term ).trim(), sentiment: k.sentiment || 'neutral', mentions: Math.max( 1, parseInt( k.mentions, 10 ) || 1 ) } ) ),
		locked: !! locked,
	};
}

/**
 * Stored summary → edit form state.
 *
 * @param {Object|null} summary Summary.
 * @return {Object} Form.
 */
export function summaryToForm( summary ) {
	const s = summary || {};
	const scores = s.scores || {};
	return {
		summary: s.summary || '',
		pros: listToLines( s.pros ),
		cons: listToLines( s.cons ),
		sentiment: { positive: 0, neutral: 0, negative: 0, ...( s.sentiment || {} ) },
		quality: scores.quality === null || scores.quality === undefined ? '' : String( scores.quality ),
		value: scores.value === null || scores.value === undefined ? '' : String( scores.value ),
		sizing: scores.sizing || 'not_applicable',
		keywords: ( s.keywords || [] ).map( ( k ) => ( { ...k } ) ),
	};
}

/**
 * Whether a bulk job is still working.
 *
 * @param {Object|null} progress Progress.
 * @return {boolean} Active.
 */
export const isJobActive = ( progress ) => !! progress && progress.status === 'running';

/**
 * Bulk progress percentage.
 *
 * @param {Object|null} progress Progress.
 * @return {number} 0–100.
 */
export function progressPercent( progress ) {
	if ( ! progress || ! progress.total ) {
		return progress && progress.status === 'done' ? 100 : 0;
	}
	return Math.min( 100, Math.max( 0, Math.floor( ( 100 * ( progress.done || 0 ) ) / progress.total ) ) );
}

/**
 * Bulk summary line.
 *
 * @param {Object} p Progress.
 * @return {string} Text.
 */
export function progressText( p ) {
	if ( ! p || p.status === 'idle' ) {
		return '';
	}
	return sprintf(
		/* translators: 1: done, 2: total, 3: generated, 4: skipped, 5: failed */
		__( '%1$d of %2$d processed · %3$d generated · %4$d skipped · %5$d failed', 'dev3-ai-review-summarizer-for-woocommerce' ), p.done || 0, p.total || 0, p.generated || 0, p.skipped || 0, p.failed || 0 );
}

/**
 * "5 minutes ago" for a unix timestamp.
 *
 * @param {number} ts  Timestamp (s).
 * @param {number} now Now (ms).
 * @return {string} Relative time.
 */
export function timeAgo( ts, now = Date.now() ) {
	if ( ! ts ) {
		return '—';
	}
	const sec = Math.max( 0, Math.round( now / 1000 - ts ) );
	if ( sec < 60 ) {
		return __( 'just now', 'dev3-ai-review-summarizer-for-woocommerce' );
	}
	const units = [
		[ 86400, ( n ) => sprintf( /* translators: %d: days */ _n( '%d day ago', '%d days ago', n, 'dev3-ai-review-summarizer-for-woocommerce' ), n ) ],
		[ 3600, ( n ) => sprintf( /* translators: %d: hours */ _n( '%d hour ago', '%d hours ago', n, 'dev3-ai-review-summarizer-for-woocommerce' ), n ) ],
		[ 60, ( n ) => sprintf( /* translators: %d: minutes */ _n( '%d minute ago', '%d minutes ago', n, 'dev3-ai-review-summarizer-for-woocommerce' ), n ) ],
	];
	for ( const [ size, fmt ] of units ) {
		if ( sec >= size ) {
			return fmt( Math.floor( sec / size ) );
		}
	}
	return '';
}

/**
 * Trailing debounce.
 *
 * @param {Function} fn Function.
 * @param {number}   ms Delay.
 * @return {Function} Debounced function with .cancel().
 */
export function debounce( fn, ms ) {
	let t = null;
	const wrapped = ( ...args ) => {
		clearTimeout( t );
		t = setTimeout( () => fn( ...args ), ms );
	};
	wrapped.cancel = () => clearTimeout( t );
	return wrapped;
}

export const SIZING_OPTIONS = () => [
	{ value: 'not_applicable', label: __( 'Not applicable', 'dev3-ai-review-summarizer-for-woocommerce' ) },
	{ value: 'runs_small', label: __( 'Runs small', 'dev3-ai-review-summarizer-for-woocommerce' ) },
	{ value: 'slightly_small', label: __( 'Slightly small', 'dev3-ai-review-summarizer-for-woocommerce' ) },
	{ value: 'true_to_size', label: __( 'True to size', 'dev3-ai-review-summarizer-for-woocommerce' ) },
	{ value: 'slightly_large', label: __( 'Slightly large', 'dev3-ai-review-summarizer-for-woocommerce' ) },
	{ value: 'runs_large', label: __( 'Runs large', 'dev3-ai-review-summarizer-for-woocommerce' ) },
];

export const TAG_OPTIONS = () => [
	{ value: 'positive', label: __( 'Positive', 'dev3-ai-review-summarizer-for-woocommerce' ) },
	{ value: 'neutral', label: __( 'Neutral', 'dev3-ai-review-summarizer-for-woocommerce' ) },
	{ value: 'negative', label: __( 'Negative', 'dev3-ai-review-summarizer-for-woocommerce' ) },
	{ value: 'mixed', label: __( 'Mixed', 'dev3-ai-review-summarizer-for-woocommerce' ) },
];
