// Unit tests only. Playwright specs under tests/e2e share the .spec.js
// naming Jest would otherwise pick up.
module.exports = {
	// Editor components render into a DOM.
	testEnvironment: 'jsdom',
	testMatch: [ '<rootDir>/tests/js/**/*.test.js' ],
	testPathIgnorePatterns: [ '/node_modules/', '/vendor/' ],
	// The same Babel preset wp-scripts builds with, so editor code with JSX
	// and block.json imports runs as it does in the bundle.
	transform: {
		'\\.m?[jt]sx?$': [
			'babel-jest',
			{ presets: [ '@wordpress/babel-preset-default' ] },
		],
	},
	// jsdom would pick the packages' browser builds, which are ES modules
	// that Jest cannot require.
	testEnvironmentOptions: { customExportConditions: [ 'node', 'require' ] },
	// These ship only as ES modules, so they go through Babel too.
	transformIgnorePatterns: [
		'/node_modules/(?!(.*/)?(uuid|marked|@wordpress/theme)/)',
	],
};
