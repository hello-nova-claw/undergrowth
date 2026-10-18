// Unit tests only. Playwright specs under tests/e2e share the .spec.js
// naming Jest would otherwise pick up.
module.exports = {
	roots: [ '<rootDir>/tests/js' ],
	testMatch: [ '**/*.test.js' ],
};
