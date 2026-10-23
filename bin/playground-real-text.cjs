/**
 * What `npm run playground:real` prints once WordPress is running.
 */

/**
 * Builds the message printed once the site is up.
 *
 * Playground logs in once per browser and remembers that in a cookie on
 * 127.0.0.1, which outlives the site. A second run in the same browser then
 * meets a login page, so the message says what to type there.
 *
 * @param {Object}  options
 * @param {string}  options.siteUrl   Address WordPress listens on.
 * @param {boolean} options.local     Whether Ollama reads the posts.
 * @param {string}  options.model     Ollama model that reads them.
 * @param {boolean} options.anthropic Whether an Anthropic key is set.
 * @return {string} The message, ending in a blank line.
 */
function startMessage( { siteUrl, local, model, anthropic } ) {
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
		'Stop with Ctrl+C. It prints the score percentiles, then deletes the site and the temporary blueprint.',
		'',
	].join( '\n' );
}

module.exports = { startMessage };
