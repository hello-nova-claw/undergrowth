const { expect } = require( '@playwright/test' );
const { default: AxeBuilder } = require( '@axe-core/playwright' );

const WCAG_22_AA = [
	'wcag2a',
	'wcag2aa',
	'wcag21a',
	'wcag21aa',
	'wcag22a',
	'wcag22aa',
];

// Best-practice rules on top of WCAG. Heading order keeps the Map's
// structure from regressing.
const BEST_PRACTICES = [ 'heading-order' ];

/**
 * Fails the test on any WCAG 2.2 AA violation inside the given part of the
 * page, or any break of the best-practice rules above.
 *
 * Scoped to our own markup because violations in WordPress core screens are
 * not ours to fix and would make the check meaningless.
 *
 * @param {import('@playwright/test').Page} page     Page under test.
 * @param {string}                          selector Root of the markup to check.
 */
async function expectNoA11yViolations( page, selector ) {
	const wcag = await new AxeBuilder( { page } )
		.include( selector )
		.withTags( WCAG_22_AA )
		.analyze();
	const practices = await new AxeBuilder( { page } )
		.include( selector )
		.withRules( BEST_PRACTICES )
		.analyze();

	expect( [ ...wcag.violations, ...practices.violations ] ).toEqual( [] );
}

module.exports = { expectNoA11yViolations };
