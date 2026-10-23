const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

// The blocks are found from their block.json files. The editor panel has none,
// so it is added beside them.
module.exports = {
	...defaultConfig,
	entry: () => ( {
		...defaultConfig.entry(),
		'editor/index': './src/editor/index.js',
	} ),
};
