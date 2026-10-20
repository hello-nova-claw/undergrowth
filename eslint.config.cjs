const wordpress = require( '@wordpress/scripts/config/eslint.config.cjs' );

// See "Use what WordPress already has" in CONTRIBUTING.md.
const notNative = {
	paths: [
		{
			name: 'react',
			message: 'Use @wordpress/element.',
		},
		{
			name: 'react-dom',
			message: 'Use @wordpress/element.',
		},
		{
			name: 'axios',
			message: 'Use @wordpress/api-fetch, or requestUtils in e2e tests.',
		},
	],
	patterns: [
		{
			group: [
				'tailwindcss',
				'tailwindcss/*',
				'bootstrap',
				'bootstrap/*',
				'react-bootstrap',
				'bulma',
				'foundation-sites',
				'semantic-ui-*',
				'antd',
				'antd/*',
				'@mui/*',
				'@material-ui/*',
				'@chakra-ui/*',
				'@mantine/*',
				'@headlessui/*',
				'@radix-ui/*',
				'primereact',
				'primereact/*',
				'styled-components',
				'@emotion/*',
			],
			message: 'Use @wordpress/components and core admin classes.',
		},
		{
			group: [
				'sinon',
				'testdouble',
				'jest-mock-extended',
				'nock',
				'msw',
				'msw/*',
				'fetch-mock',
				'axios-mock-adapter',
			],
			message:
				'Fake behaviour through WordPress: apiFetch middleware, or requestUtils in e2e tests.',
		},
	],
};

// Claude Code keeps parallel worktrees under .claude/, inside the project.
module.exports = [
	{ ignores: [ '.claude/**' ] },
	...wordpress,
	{ rules: { 'no-restricted-imports': [ 'error', notNative ] } },
];
