/**
 * What `npm run playground:real` prints once WordPress is running.
 */

/**
 * Playground logs in once per browser and remembers that in a cookie on
 * 127.0.0.1, which outlives the site. A second run in the same browser then
 * meets a login page, so the message says what to type there.
 *
 * @param {Object}  options
 * @param {string}  options.siteUrl   Address WordPress listens on.
 * @param {boolean} options.local     Whether Ollama reads the posts.
 * @param {string}  options.model     Ollama model that reads them.
 * @param {boolean} options.anthropic Whether an Anthropic key is set.
 * @param {boolean} options.acceptAll Whether every suggestion is accepted once posts are read.
 * @return {string} The message, ending in a blank line.
 */
function startMessage( { siteUrl, local, model, anthropic, acceptAll } ) {
	return [
		'',
		`Undergrowth is running. Open ${ siteUrl }/wp-admin/options-general.php?page=undergrowth`,
		'If WordPress asks you to log in, use the username admin and the password password.',
		local
			? `Posts are read by ${ model } in Ollama, on this computer. Nothing is sent to OpenAI.`
			: 'Posts are read by OpenAI, with your key.',
		anthropic
			? 'Questions are written by Claude, with your Anthropic key.'
			: 'No Anthropic key, so no questions on the Dashboard.',
		'Posts are read in the background, a few at a time; Settings > Undergrowth counts them.',
		...( acceptAll
			? [
					'UNDERGROWTH_AUTO_ACCEPT is on, for a test site only: once every post is read, every suggestion is accepted and the monthly cycle runs. Posts > Undergrowth > Dev tools undoes it.',
				]
			: [] ),
		'Stop with Ctrl+C. It prints the score percentiles, then deletes the site and the temporary blueprint.',
		'',
	].join( '\n' );
}

/**
 * Tells whether .env.local asks for every suggestion to be accepted once
 * the posts are read. Only `1` turns it on.
 *
 * @param {Object} env Values read from .env.local.
 * @return {boolean} True when UNDERGROWTH_AUTO_ACCEPT is 1.
 */
function autoAccept( env ) {
	return '1' === ( env.UNDERGROWTH_AUTO_ACCEPT ?? '' ).trim();
}

/**
 * What the script prints once it has accepted every suggestion and run the
 * cycle.
 *
 * @param {Object} result        What the site reported.
 * @param {Object} result.counts How many suggestions ended each way, keyed as Accept's results are.
 * @param {number} result.groups Groups the cycle made.
 * @return {string} The message, ending in a blank line.
 */
function autoAcceptMessage( { counts, groups } ) {
	const accepted = counts.accepted ?? 0;
	const refused = Object.entries( counts )
		.filter( ( [ key ] ) => 'accepted' !== key )
		.reduce( ( sum, [ , count ] ) => sum + count, 0 );

	return [
		'',
		`Every post is read. Accepted ${ accepted } suggestions${
			refused > 0
				? `; ${ refused } were refused, as the Accept button would refuse them`
				: ''
		}.`,
		`The monthly cycle ran and made ${ groups } groups. Reload Posts > Undergrowth to see stages and the Map.`,
		'',
	].join( '\n' );
}

module.exports = { startMessage, autoAccept, autoAcceptMessage };
