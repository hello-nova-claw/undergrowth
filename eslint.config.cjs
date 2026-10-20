const wordpress = require( '@wordpress/scripts/config/eslint.config.cjs' );

// Claude Code keeps parallel worktrees under .claude/, inside the project.
module.exports = [ { ignores: [ '.claude/**' ] }, ...wordpress ];
