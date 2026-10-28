# WordPress.org submission check

What was checked before submitting 0.1.0 to the plugin directory, on 28 October 2026, against `main` at `a90bc1b`, and what was found. Each item is either fixed, with its issue and pull request, or explained as fine.

Three problems were found, and none would have stopped the plugin installing or working:

| Problem | Issue | Fix |
|---|---|---|
| Plugin Check reported nine unescaped-SQL warnings. | [#538](https://github.com/hello-nova-claw/undergrowth/issues/538) | [#541](https://github.com/hello-nova-claw/undergrowth/pull/541) |
| The directory screenshots showed screens from before the Orphans tab, named Map groups and the Prompt studio, and screenshot 5 showed a different screen from its caption. | [#539](https://github.com/hello-nova-claw/undergrowth/issues/539) | This pull request |
| The release checklist said the readme was under 10 KB, and that Plugin Check warns about AI providers. Neither was true. | [#540](https://github.com/hello-nova-claw/undergrowth/issues/540) | This pull request |

## 1. Plugin Check

Plugin Check 2.1.0, the current release, was run on WordPress 7.1.3 against the `.distignore` copy that ships, with `--include-experimental --include-low-severity-errors --include-low-severity-warnings`. The **Plugin Check** job runs the same checks on every pull request.

- **Errors:** none.
- **Warnings:** nine `PluginCheck.Security.DirectDB.UnescapedDBParameter`, in `src/indexer.php` (4), `src/stages.php` (2), `src/orphans.php` (2) and `src/map.php` (1). Each query wrote a SQL fragment into `$wpdb->prepare()`. The fragments were safe, because every value went through a placeholder and every caller passed a literal alias, but a reviewer cannot tell that from the query, and interpolated SQL is what the review team's "unsafe SQL" reply is for. [#541](https://github.com/hello-nova-claw/undergrowth/pull/541) makes the functions refuse anything but a safe alias or select list, passes the orphan query's table names through `%i`, and adds the sniff to each query's `phpcs` comment. Plugin Check then reports nothing.
- **Readme:** no warnings. Plugin Check's readme check uses the directory's own readme parser.
- **AI providers:** `PluginCheck.CodeAnalysis.AIProvider.DirectIntegration` no longer appears. The `phpcs:ignore` beside each provider address in `src/openai.php` and `src/anthropic.php` says why the plugin calls the provider with the owner's own key (see the handover, section 6). Mention the direct calls in the submission anyway, since a reviewer reads the code.

## 2. The detailed plugin guidelines

| Guideline | Finding |
|---|---|
| 1. GPL | `GPL-2.0-or-later` in the plugin header, `readme.txt`, `composer.json` and `package.json`, and `LICENSE` holds the GPL version 2 text. Every bundled file is the plugin's own. Fine. |
| 4. Readable code | `build/` is minified by `wp-scripts`, and its sources ship beside it in `src/blocks/` and `src/editor/`. No PHP is obfuscated: no `eval`, `base64_decode`, `gzinflate`, `create_function` or shell calls. Fine. |
| 5. No trialware | Everything works without paying anyone but the owner's own providers. No premium tier, locked feature, upsell, ad, affiliate or donate link. Fine. |
| 6. Services | The plugin's only outbound requests go to `api.openai.com` (`src/openai.php`), `api.anthropic.com` (`src/anthropic.php`) and `discord.com` (`src/discord-messages.php`, `src/discord-clicks.php`), each only once the owner has saved its key or settings, plus one hourly request from the site to its own uploads folder (`src/memory-folder.php`) to check the per-post files cannot be read from the web. One request comes in: once Discord is set up, Discord posts each button click to the plugin's public REST route (`src/discord.php`), which checks Discord's Ed25519 signature before `src/discord-clicks.php` reads it. Jetpack Stats is read through Jetpack, which fetches from WordPress.com as it does for its own screen. Fine. |
| 7. No tracking without consent | Nothing is sent to the developers or to anyone the owner has not set up. Before a key or Discord's settings are saved, nothing goes to any outside service; the only request is the hourly one from the site to its own uploads folder. Fine. |
| 7 and 6. Disclosure | `readme.txt` has an "External services" section and an FAQ entry for each service: what is sent, when, from where, and links to the terms and privacy policy of OpenAI, Anthropic, Discord, and WordPress.com for Jetpack. Ollama is not contacted by the plugin: it appears only as an example a site owner may install themselves through the `undergrowth_embed_provider` filter, and `docs/examples/` does not ship. Fine. |
| 8. No remote code | No script, style, font or image loads from outside the plugin. Every URL in shipped code is one of the three services above, the plugin's GitHub page (as a User-Agent and in help links), or the providers' key pages, as links. Fine. |
| 9. Nothing illegal or dishonest | The AI never writes for readers (handover, section 2). Fine. |
| 11. No hijacking the admin | Only two notices reach screens outside the plugin's own: the first-run notice, on the Plugins screen only, for administrators, until a key is saved or it is dismissed, and the result of a compost action on the Posts list. No nag, no counted days, no wizard. Fine. |
| 12. No spam in the readme | Four tags, under the limit of five. No keyword stuffing, no affiliate links. Fine. |
| 13. Core libraries | Only `@wordpress/*` packages are used, enqueued as core's own script handles from the generated `.asset.php` files. Fine. |
| 17. Trademarks | The name is "Undergrowth" and the slug `undergrowth`. Neither starts with "WordPress", "WP" or another project's mark. No plugin in the directory uses the slug. Fine. |

### Security

PHPCS with WordPress Coding Standards runs in CI with no errors or warnings. It finds the escaping, sanitising, nonce and capability patterns a machine can see; it cannot prove every path, and it skips any line with a `phpcs:ignore`. Those comments were read one by one:

- **Escaping:** each `OutputNotEscaped` ignore prints markup escaped where it was built (`undergrowth_nonce_input()`, `undergrowth_hub_action_form()`, list-table row forms), or a file to download (`undergrowth_handle_export_map()`, whose CSV also neutralises spreadsheet formulas). Fine.
- **Nonces and capabilities:** every `admin-post` and form handler calls `check_admin_referer()` and `current_user_can()`. The `NonceVerification` ignores are reads of `$_GET` for tabs, filters and notices that change nothing. Every REST route has a `permission_callback` that checks a capability, except Discord's interactions endpoint, which verifies Discord's Ed25519 signature instead. Fine.
- **Settings:** every `register_setting()` has a `sanitize_callback`. Fine.
- **Direct access:** every PHP file starts with `defined( 'ABSPATH' ) || exit;`, and `uninstall.php` checks `WP_UNINSTALL_PLUGIN`. Fine.
- **Dev tools:** `src/dev-tools.php` ships, but registers nothing unless `wp_get_environment_type()` is `local` or `development`, and no filter can turn it on elsewhere. Fine.

### Text domain and versions

- The text domain is `undergrowth`, matching the slug, in the plugin header, in every PHP string and in all seven `block.json` files, and `wp_set_script_translations()` uses it.
- WordPress 6.9 and PHP 7.4 are the minimums in the plugin header, `readme.txt`, `phpcs.xml.dist` and `composer.json`. `test_readme_headers_match_plugin_header` checks the header against the readme.
- `Version:`, `UNDERGROWTH_VERSION`, `Stable tag:` and every `block.json` say `0.1.0`.

## 3. `readme.txt`

- **Format:** the directory's parser, through Plugin Check, reports nothing.
- **Stable tag:** `0.1.0`, as the plugin header.
- **Tested up to:** `7.1`. The current release is 7.1.3, and the field takes the major version only.
- **Short description:** 124 characters, under the limit of 150, and the same as the plugin header's description.
- **Contributors:** `annamcphee`, an existing WordPress.org profile.
- **Size:** 12.8 KB, against the readme standard's warning that over 10 KB "may result in errors". That warning is about the parser's per-section limits: 2,500 words for most sections and 5,000 for the FAQ and changelog. The whole file is 2,127 words. Fine; the checklist now says so ([#540](https://github.com/hello-nova-claw/undergrowth/issues/540)).
- **FAQ:** thirteen entries, including what each service is sent and what uninstalling removes.
- **Screenshots:** six captions and six `.wordpress-org/screenshot-N.png`. The images were taken on 23 October; `screenshot-5.png` showed the Dashboard widget's old question card where caption 5 describes the Prompt studio, and `screenshot-1.png` showed the Suggestions tab without its checkboxes. The others showed the Undergrowth screen without its Orphans tab, Map groups as "Group 1", and Settings without the post types, surprise scale or Prompt studio sections. All six are retaken from the current screens, and the release checklist now names the catalogue shot behind each ([#539](https://github.com/hello-nova-claw/undergrowth/issues/539)).
- **Changelog:** `= 0.1.0 =` with `* First release.`, as the release checklist decides.

## 4. The release zip

Built as the **Build** workflow builds it: `npm ci && npm run build`, then `rsync -a --exclude-from=.distignore`, then `zip`. 394 KB, one top-level `undergrowth/` folder holding `undergrowth.php`, `uninstall.php`, `readme.txt`, `LICENSE`, `assets/`, `build/`, `languages/undergrowth.pot` and `src/`. No dotfiles, `tests/`, `node_modules/`, `vendor/`, `bin/`, `demo/`, `docs/`, `changelog/`, `.wordpress-org/`, `CLAUDE.md`, config files or source maps.

`src/blocks/` and `src/editor/` ship beside `build/` on purpose: guideline 4 asks for the human-readable source of built code, and these are it.

## 5. Smoke tests

The zip was installed on a clean site each time, with `WP_DEBUG_LOG` on. On each, the plugin was activated, a post published, every Undergrowth cron event run once, and these screens loaded as an administrator: Plugins, Settings › Undergrowth, Posts › Undergrowth (Suggestions, Growth stages, Map), Posts › Prompt studio, the Dashboard, Posts, Add Post, Site Health and the front page. Then the plugin was deactivated and deleted from the Plugins screen's code path (`delete_plugins()`).

| Site | Screens | Deactivate | Delete |
|---|---|---|---|
| PHP 7.4.33, WordPress 6.9.10 (Playground) | All 200, no PHP notice in the log | Every cron event cleared | No table, option, post meta, user meta or folder left |
| PHP 8.5, WordPress 7.1.3 (Playground, and natively on SQLite) | All 200, no PHP notice in the log | Every cron event cleared | Nothing left, including the per-post folder the native run had made |
| Multisite, PHP 8.5, WordPress 7.1.3, two sites, network-activated (Playground) | All 200 on both sites, and the network screens | Every cron event cleared on both sites | All four tables and every option on both sites removed, nothing in `sitemeta` |

Deactivating keeps the settings, as the FAQ says; deleting removes them.

Two things went wrong in the test sites and not in the plugin: Playground's SQLite file was corrupted when core's background auto-update ran during one run (rerun with `AUTOMATIC_UPDATER_DISABLED`), and multisite needs a site address without a port.

## For the submission

- Submit from the `annamcphee` account, with two-factor authentication on.
- Say that the plugin calls OpenAI and Anthropic directly with the owner's own keys, and why (handover, section 6), and that each service is described in the readme's FAQ.
