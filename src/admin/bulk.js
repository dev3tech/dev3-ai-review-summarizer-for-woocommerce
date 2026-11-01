/**
 * Drives a bulk job from the admin screen: calls POST /bulk/step until the job stops.
 * When the background runner holds the lock (`busy`), waits and re-reads progress instead.
 *
 * @param {Object}   deps
 * @param {Function} deps.step       () => Promise<progress> (POST bulk/step).
 * @param {Function} deps.read       () => Promise<progress> (GET bulk).
 * @param {Function} deps.onProgress (progress) => void.
 * @param {Function} deps.stopped    () => boolean — true when the screen unmounted / user cancelled.
 * @param {Function} [deps.sleep]    (ms) => Promise.
 * @param {number}   [deps.busyWait] Wait while busy (ms).
 * @param {number}   [deps.maxSteps] Safety cap.
 * @return {Promise<Object>} Final progress.
 */
export async function driveBulk( { step, read, onProgress, stopped, sleep = defaultSleep, busyWait = 2000, maxSteps = 5000 } ) {
	let progress = null;
	let errors = 0;
	for ( let i = 0; i < maxSteps; i++ ) {
		if ( stopped() ) {
			return progress;
		}
		try {
			progress = await step();
			errors = 0;
		} catch ( e ) {
			// Transient network/server errors: back off and re-read; give up after 3 in a row.
			errors++;
			if ( errors >= 3 ) {
				throw e;
			}
			await sleep( busyWait );
			const latest = await read().catch( () => null );
			if ( ! latest ) {
				continue; // Could not confirm the state: try the step again.
			}
			progress = latest;
		}
		if ( progress ) {
			onProgress( progress );
		}
		if ( ! progress || progress.status !== 'running' ) {
			return progress;
		}
		if ( progress.busy ) {
			await sleep( busyWait );
		}
	}
	return progress;
}

function defaultSleep( ms ) {
	return new Promise( ( resolve ) => setTimeout( resolve, ms ) );
}
