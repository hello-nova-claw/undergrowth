const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

// With --experimental-modules the default config is a pair: scripts, then
// the view modules. The blocks are found from their block.json files. The
// editor panel has none, so it is added beside the scripts.
const [ scripts, ...modules ] = [].concat( defaultConfig );

module.exports = [
	{
		...scripts,
		entry: () => ( {
			...scripts.entry(),
			'editor/index': './src/editor/index.js',
		} ),
	},
	...modules,
];
