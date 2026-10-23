/**
 * Stores the editor preferences on the server before the editor loads.
 *
 * On a site where the editor has never been opened, it loads its stored
 * preferences after the test utilities turn the welcome guide off, and the
 * guide comes back over whatever the test is looking at.
 *
 * @param {Object} requestUtils Request utilities.
 */
async function skipWelcomeGuide( requestUtils ) {
	await requestUtils.rest( {
		method: 'POST',
		path: '/wp/v2/users/me',
		data: {
			meta: {
				persisted_preferences: {
					'core/edit-post': { welcomeGuide: false },
					_modified: new Date().toISOString(),
				},
			},
		},
	} );
}

module.exports = { skipWelcomeGuide };
