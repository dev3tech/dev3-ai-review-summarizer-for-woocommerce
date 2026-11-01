import { restUrl } from '../../src/core3/config';
import { buildEditPayload, clampScore, debounce, isJobActive, linesToList, normaliseSentiment, progressPercent, progressText, statusMeta, summaryToForm, timeAgo } from '../../src/admin/utils';
import { driveBulk } from '../../src/admin/bulk';

// WordPress packages are webpack externals (window.wp.*), so mock them virtually.
jest.mock( '@wordpress/api-fetch', () => jest.fn( () => new Promise( () => {} ) ), { virtual: true } );
jest.mock(
	'@wordpress/i18n',
	() => ( {
		__: ( s ) => s,
		_n: ( s, p, n ) => ( n === 1 ? s : p ),
		sprintf: ( f, ...a ) => {
			let i = 0;
			return f.replace( /%(\d+\$)?[sd]/g, ( m, pos ) => String( pos ? a[ parseInt( pos, 10 ) - 1 ] : a[ i++ ] ) );
		},
	} ),
	{ virtual: true }
);

describe( 'restUrl', () => {
	it( 'handles pretty and plain permalinks', () => {
		expect( restUrl( 'products?page=2', '/wp-json/dev3rs/v1/' ) ).toBe( '/wp-json/dev3rs/v1/products?page=2' );
		expect( restUrl( 'summary/5?context=edit', '/index.php?rest_route=/dev3rs/v1/' ) ).toBe( '/index.php?rest_route=/dev3rs/v1/summary/5&context=edit' );
	} );
} );

describe( 'summary helpers', () => {
	it( 'maps statuses to badges', () => {
		expect( statusMeta( 'fresh' ).tone ).toBe( 'is-ok' );
		expect( statusMeta( 'stale' ).tone ).toBe( 'is-warn' );
		expect( statusMeta( 'locked' ).tone ).toBe( 'is-locked' );
		expect( statusMeta( 'whatever' ).label ).toBe( 'No summary' );
	} );

	it( 'turns lines into unique, trimmed, capped lists', () => {
		expect( linesToList( '- Soft\n\n• Soft\n  Durable \n* Cheap\na\nb\nc' ) ).toEqual( [ 'Soft', 'Durable', 'Cheap', 'a', 'b' ] );
		expect( linesToList( '' ) ).toEqual( [] );
	} );

	it( 'normalises sentiment to 100 like the PHP sanitizer', () => {
		expect( normaliseSentiment( { positive: 70, neutral: 20, negative: 10 } ) ).toEqual( { positive: 70, neutral: 20, negative: 10 } );
		expect( normaliseSentiment( { positive: 1, neutral: 1, negative: 1 } ) ).toEqual( { positive: 34, neutral: 33, negative: 33 } );
		expect( normaliseSentiment( { positive: '0.5', neutral: 0.3, negative: 0.2 } ) ).toEqual( { positive: 50, neutral: 30, negative: 20 } );
		expect( normaliseSentiment( { positive: -1 } ) ).toEqual( { positive: 0, neutral: 0, negative: 0 } );
	} );

	it( 'clamps scores or returns null', () => {
		expect( clampScore( '4.26' ) ).toBe( 4.3 );
		expect( clampScore( 9 ) ).toBe( 5 );
		expect( clampScore( 0.2 ) ).toBe( 1 );
		expect( clampScore( '' ) ).toBeNull();
		expect( clampScore( 'abc' ) ).toBeNull();
		expect( clampScore( 0 ) ).toBeNull();
	} );

	it( 'round-trips a summary through the edit form', () => {
		const summary = {
			summary: 'Good',
			pros: [ 'Soft', 'Warm' ],
			cons: [ 'Small' ],
			sentiment: { positive: 60, neutral: 30, negative: 10 },
			scores: { quality: 4.5, value: null, sizing: 'runs_small' },
			keywords: [ { term: 'fabric', sentiment: 'positive', mentions: 3 }, { term: '  ', sentiment: 'neutral', mentions: 1 } ],
		};
		const form = summaryToForm( summary );
		expect( form.pros ).toBe( 'Soft\nWarm' );
		expect( form.value ).toBe( '' );
		const payload = buildEditPayload( form, true );
		expect( payload ).toEqual( {
			summary: 'Good',
			pros: [ 'Soft', 'Warm' ],
			cons: [ 'Small' ],
			sentiment: { positive: 60, neutral: 30, negative: 10 },
			scores: { quality: 4.5, value: null, sizing: 'runs_small' },
			keywords: [ { term: 'fabric', sentiment: 'positive', mentions: 3 } ],
			locked: true,
		} );
		expect( buildEditPayload( summaryToForm( null ), false ).scores.sizing ).toBe( 'not_applicable' );
	} );
} );

describe( 'progress helpers', () => {
	it( 'computes percent and text', () => {
		expect( progressPercent( null ) ).toBe( 0 );
		expect( progressPercent( { status: 'done', total: 0 } ) ).toBe( 100 );
		expect( progressPercent( { status: 'running', total: 3, done: 1 } ) ).toBe( 33 );
		expect( isJobActive( { status: 'running' } ) ).toBe( true );
		expect( isJobActive( { status: 'done' } ) ).toBe( false );
		expect( progressText( { status: 'running', total: 4, done: 2, generated: 1, skipped: 1, failed: 0 } ) ).toBe( '2 of 4 processed · 1 generated · 1 skipped · 0 failed' );
		expect( progressText( { status: 'idle' } ) ).toBe( '' );
	} );

	it( 'formats relative time', () => {
		const now = 1_000_000_000_000;
		expect( timeAgo( 0, now ) ).toBe( '—' );
		expect( timeAgo( now / 1000 - 30, now ) ).toBe( 'just now' );
		expect( timeAgo( now / 1000 - 60 * 5, now ) ).toBe( '5 minutes ago' );
		expect( timeAgo( now / 1000 - 3600, now ) ).toBe( '1 hour ago' );
		expect( timeAgo( now / 1000 - 86400 * 3, now ) ).toBe( '3 days ago' );
	} );

	it( 'debounces', () => {
		jest.useFakeTimers();
		const fn = jest.fn();
		const d = debounce( fn, 100 );
		d( 1 );
		d( 2 );
		jest.advanceTimersByTime( 100 );
		expect( fn ).toHaveBeenCalledTimes( 1 );
		expect( fn ).toHaveBeenCalledWith( 2 );
		jest.useRealTimers();
	} );
} );

describe( 'driveBulk', () => {
	const sleep = () => Promise.resolve();

	it( 'steps until the job is done and reports each progress', async () => {
		const states = [ { status: 'running', done: 1, total: 3 }, { status: 'running', done: 2, total: 3 }, { status: 'done', done: 3, total: 3 } ];
		const step = jest.fn( () => Promise.resolve( states.shift() ) );
		const seen = [];
		const final = await driveBulk( { step, read: jest.fn(), onProgress: ( p ) => seen.push( p.done ), stopped: () => false, sleep } );
		expect( final.status ).toBe( 'done' );
		expect( step ).toHaveBeenCalledTimes( 3 );
		expect( seen ).toEqual( [ 1, 2, 3 ] );
	} );

	it( 'waits while the background runner is busy', async () => {
		const states = [ { status: 'running', busy: true, done: 0, total: 1 }, { status: 'done', done: 1, total: 1 } ];
		const waits = [];
		const final = await driveBulk( { step: () => Promise.resolve( states.shift() ), read: jest.fn(), onProgress: () => {}, stopped: () => false, sleep: ( ms ) => waits.push( ms ) && Promise.resolve(), busyWait: 50 } );
		expect( final.status ).toBe( 'done' );
		expect( waits ).toEqual( [ 50 ] );
	} );

	it( 'stops when cancelled and retries transient errors', async () => {
		let stop = false;
		const step = jest.fn( () => {
			stop = true;
			return Promise.resolve( { status: 'running', done: 1, total: 5 } );
		} );
		await driveBulk( { step, read: jest.fn(), onProgress: () => {}, stopped: () => stop, sleep } );
		expect( step ).toHaveBeenCalledTimes( 1 );

		const flaky = jest.fn().mockRejectedValueOnce( { code: 'dev3rs_network' } ).mockResolvedValueOnce( { status: 'done', done: 1, total: 1 } );
		const read = jest.fn( () => Promise.resolve( { status: 'running', done: 0, total: 1 } ) );
		const final = await driveBulk( { step: flaky, read, onProgress: () => {}, stopped: () => false, sleep } );
		expect( read ).toHaveBeenCalledTimes( 1 );
		expect( final.status ).toBe( 'done' );

		const broken = jest.fn( () => Promise.reject( { code: 'dev3rs_down' } ) );
		await expect( driveBulk( { step: broken, read: () => Promise.reject( new Error( 'x' ) ), onProgress: () => {}, stopped: () => false, sleep } ) ).rejects.toEqual( { code: 'dev3rs_down' } );
	} );
} );
