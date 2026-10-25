const path = require( 'path' );

/**
 * Reports every test that passed only after a retry.
 *
 * On CI each test gets two retries, so a flaky test passes without anyone
 * seeing it. This names each one in the log and as a warning on the run.
 */
class RetryReporter {
	constructor() {
		this.retried = [];
	}

	/**
	 * Notes a test that passed on a retry.
	 *
	 * @param {Object} test   The test.
	 * @param {Object} result The result of this attempt.
	 */
	onTestEnd( test, result ) {
		if ( result.retry > 0 && 'passed' === result.status ) {
			this.retried.push( {
				title: test.titlePath().filter( Boolean ).join( ' › ' ),
				file: path.relative( process.cwd(), test.location.file ),
				line: test.location.line,
				retry: result.retry,
			} );
		}
	}

	/**
	 * Lists the tests that needed a retry, once the run is over.
	 */
	onEnd() {
		if ( 0 === this.retried.length ) {
			return;
		}

		// eslint-disable-next-line no-console
		console.log(
			`${ this.retried.length } test(s) passed only after a retry:`
		);

		for ( const { title, file, line, retry } of this.retried ) {
			// eslint-disable-next-line no-console
			console.log( `  ${ title } (attempt ${ retry + 1 })` );

			if ( process.env.GITHUB_ACTIONS ) {
				// eslint-disable-next-line no-console
				console.log(
					`::warning file=${ file },line=${ line },title=Flaky test::${ title } passed only on attempt ${
						retry + 1
					}`
				);
			}
		}
	}

	/**
	 * Leaves the console to the other reporters.
	 *
	 * @return {boolean} False.
	 */
	printsToStdio() {
		return false;
	}
}

module.exports = RetryReporter;
