// Unit tests only. Playwright specs under tests/e2e share the .spec.js
// naming Jest would otherwise pick up.
module.exports = {
	testMatch: [ '<rootDir>/tests/js/**/*.test.js' ],
	testPathIgnorePatterns: [ '/node_modules/', '/vendor/' ],
};
