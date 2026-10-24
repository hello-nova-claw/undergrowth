<?php
/**
 * Plugin Name:       Undergrowth
 * Plugin URI:        https://github.com/hello-nova-claw/undergrowth
 * Description:       Uses AI to read your site and ask you questions. It never writes a word your readers will see.
 * Version:           0.1.0
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Author:            Novaclaw
 * Author URI:        https://github.com/hello-nova-claw
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       undergrowth
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

define( 'UNDERGROWTH_VERSION', '0.1.0' );
define( 'UNDERGROWTH_FILE', __FILE__ );

require_once __DIR__ . '/src/schema.php';
require_once __DIR__ . '/src/vectors.php';
require_once __DIR__ . '/src/embed.php';
require_once __DIR__ . '/src/openai.php';
require_once __DIR__ . '/src/anthropic.php';
require_once __DIR__ . '/src/prompts.php';
require_once __DIR__ . '/src/indexer.php';
require_once __DIR__ . '/src/anchor-words.php';
require_once __DIR__ . '/src/anchor-spans.php';
require_once __DIR__ . '/src/sentences.php';
require_once __DIR__ . '/src/links.php';
require_once __DIR__ . '/src/clusters.php';
require_once __DIR__ . '/src/map.php';
require_once __DIR__ . '/src/thresholds.php';
require_once __DIR__ . '/src/memory.php';
require_once __DIR__ . '/src/class-undergrowth-html-text.php';
require_once __DIR__ . '/src/accept.php';
require_once __DIR__ . '/src/choose-words.php';
require_once __DIR__ . '/src/stages.php';
require_once __DIR__ . '/src/abilities.php';
require_once __DIR__ . '/src/resurfacing.php';
require_once __DIR__ . '/src/settings.php';
require_once __DIR__ . '/src/first-run.php';
require_once __DIR__ . '/src/discord-settings.php';
require_once __DIR__ . '/src/discord.php';
require_once __DIR__ . '/src/discord-messages.php';
require_once __DIR__ . '/src/discord-clicks.php';
require_once __DIR__ . '/src/admin.php';
require_once __DIR__ . '/src/dashboard.php';
require_once __DIR__ . '/src/meter.php';
require_once __DIR__ . '/src/posts-screen.php';
require_once __DIR__ . '/src/help.php';
require_once __DIR__ . '/src/blocks.php';
require_once __DIR__ . '/src/editor.php';
require_once __DIR__ . '/src/editor-links.php';
require_once __DIR__ . '/src/draft-scan.php';
require_once __DIR__ . '/src/dev-tools.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/src/cli.php';
}

register_activation_hook( __FILE__, 'undergrowth_activate' );
register_deactivation_hook( __FILE__, 'undergrowth_deactivate' );
add_action( 'plugins_loaded', 'undergrowth_maybe_upgrade_schema' );
