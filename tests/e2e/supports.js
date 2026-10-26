const { expect } = require( '@playwright/test' );

// Custom values, so the check does not depend on the theme's presets.
const STYLED = {
	style: {
		color: { background: '#ffeeaa' },
		spacing: { padding: { top: '30px', bottom: '30px' } },
	},
};

/**
 * Reads the background and top padding of the first element a locator finds.
 *
 * @param {import('@playwright/test').Locator} locator Element.
 * @return {Promise<{background: string, padding: string}>} Computed styles.
 */
function looks( locator ) {
	return locator.first().evaluate( ( element ) => {
		const style = window.getComputedStyle( element );

		return {
			background: style.backgroundColor,
			padding: style.paddingTop,
		};
	} );
}

/**
 * Checks that a block's colour and spacing show once in the editor, as on
 * the front end: on the block's wrapper, and not again on the server's
 * preview inside it.
 *
 * @param {Object}                          args          Arguments.
 * @param {import('@playwright/test').Page} args.page     Page.
 * @param {Object}                          args.admin    Admin utilities.
 * @param {Object}                          args.editor   Editor utilities.
 * @param {number}                          args.postId   Post holding the block with STYLED attributes.
 * @param {string}                          args.name     Block name, such as undergrowth/growth-stage.
 * @param {string}                          args.rendered Selector of the server's markup, inside the block.
 */
async function expectSupportsOnce( {
	page,
	admin,
	editor,
	postId,
	name,
	rendered,
} ) {
	const wrapper = `.wp-block-${ name.replace( '/', '-' ) }`;

	await page.goto( `/?p=${ postId }` );
	const front = await looks( page.locator( wrapper ) );
	expect( front ).toEqual( {
		background: 'rgb(255, 238, 170)',
		padding: '30px',
	} );

	await admin.editPost( postId );
	const block = editor.canvas.locator( `[data-type="${ name }"]` );
	await expect( block.locator( rendered ) ).toBeVisible();

	expect( await looks( block ) ).toEqual( front );
	expect( await looks( block.locator( rendered ) ) ).toEqual( {
		background: 'rgba(0, 0, 0, 0)',
		padding: '0px',
	} );
}

module.exports = { STYLED, expectSupportsOnce };
