# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

Undergrowth is a WordPress 6.9+ plugin (PHP 7.4+) for personal bloggers. Read [CONTRIBUTING.md](CONTRIBUTING.md) before any change. It holds the process, commit style, code, i18n, accessibility and screenshot rules, and this file does not repeat them. [docs/handover.md](docs/handover.md) is the design record; section 2's principles are not negotiable. The core one: the AI may speak **to** the author, never **for** the author. Nothing a model writes is ever shown to readers.

## Layout

- `undergrowth.php` requires every file in `src/`. There is no autoloader and there are no namespaces; everything global is prefixed `undergrowth_`.
- `src/`: one file per concern. `embed.php` and `openai.php` make vectors, which `vectors.php` stores packed in a custom table defined in `schema.php` and compares (`undergrowth_cosine()`). `vector-packing.php` converts rows stored as JSON before schema version 8, in cron batches. `keys.php` reads the API keys. `indexer.php` handles embedding and backfill. `links.php`, `sentences.php` and `accept.php` handle suggestions and Accept. `clusters.php`, `thresholds.php` and `stages.php` run the monthly cycle, in batches tracked by `passes.php`; `stage-actions.php` holds the author's pin, compost and return; `map.php` names each cluster from the author's own words and counts every post for the Map; `hubs.php` proposes hub drafts for dense groups; `pollination.php` finds cross-pollination pairs of groups for the Map and the Prompt studio (`undergrowth_pollination_pairs()`), `pollination-view.php` shows them on the Map and `cross-links.php` offers them as suggestions. `prompts.php` and `anthropic.php` write the Dashboard questions, from the sources in `prompt-sources.php`, under the rules and checks in `prompt-rules.php`. `memory.php` writes the per-post text files, `memory-folder.php` names and protects their folder, and `memory-import.php` reads them back in. `visibility.php` holds the one rule for which posts readers may see on any block. `discord*.php`, `meter.php`, `abilities.php` (registered in `ability-registration.php`), `blocks.php` and `site-health.php` cover the rest. The admin screens are `admin.php` (Posts › Undergrowth: Suggestions, Growth stages and Map tabs, slug `undergrowth-links`; the Map tab is in `admin-map.php`, and `admin-helpers.php` holds the row actions, post links and notices the screens share), `settings.php` (Settings › Undergrowth), `posts-screen.php`, `dashboard.php`, `help.php` and `first-run.php`.
- `src/blocks/growth-stage/` is the only JavaScript. It is built into `build/` by `npm run build`, which is not committed.
- `tests/phpunit/` uses `test-*.php` files with `WP_UnitTestCase`. Jetpack stand-ins live in `tests/phpunit/includes/`.
- `tests/e2e/` holds the Playwright specs. `tests/e2e/mu-plugins/undergrowth-e2e.php` is a fake embedding provider that counts fixture words, so no key is needed. `tests/e2e/states.spec.js` is the screenshot catalogue.
- `tests/js/` holds the Jest tests. `bin/` holds the CI tools: coverage gate, native-WordPress check, changelog folding and POT tooling.
- `demo/` holds the public-domain demo blog as WXR, built by `bin/build-demo-content` (Python). See `docs/demo-content.md`.
- `changelog/` holds one fragment per PR. `docs/` holds the design record, the v1 reviews and reports, and `docs/examples/local-embeddings.php`.

## How it fits together

Work happens in WP-Cron, never in the request that saves a post:

- **Saving a published post** queues `undergrowth_embed_post`, which fires `undergrowth_post_embedded`. That event refreshes link proposals around the post (`links.php`) and fires `undergrowth_proposals_refreshed`. Both the per-post file (`memory.php`) and Discord listen for it.
- **Saving the OpenAI key**, or the hourly `undergrowth_backfill_check`, starts `undergrowth_backfill`. It embeds old posts in batches, then queues one `undergrowth_cluster_cycle`.
- **Monthly**, `undergrowth_cluster_cycle` measures thresholds and clusters in batches. It then queues `undergrowth_stage_cycle` and runs resurfacing for any new clusters. A separate monthly `undergrowth_prompt_cycle` writes at most one question.

These rules hold across the code:

- Vectors are only compared within the same provider, model and language. A vector without an identity is never stored.
- Stages change only on the monthly cycle, never on save, and never go down. Pinning and composting are the exceptions, because they are author actions.
- Thresholds come from the site's own score percentiles (`thresholds.php`). The fixed 0.4 / 0.5 / 0.8 values apply below 20 posts and for OpenAI, until the real-posts test decides. `wp undergrowth scores` prints the thresholds in use.

## Running checks locally

The npm and composer scripts are in CONTRIBUTING.md. `npm run test:php` and `npm run test:e2e` need Docker (`wp-env`). To run a single test:

```bash
npm run test:php -- --filter Test_Memory
npm run test:js -- tests/js/changelog.test.js
npm run test:e2e -- tests/e2e/map.spec.js
```

**PHPUnit without Docker.** Use a private WordPress test library on SQLite. Each worktree gets its own copy at `~/Library/Caches/undergrowth-wpt-<name>/`, which holds:

- `develop/tests/phpunit`, from wordpress-develop at the WordPress version under test;
- `develop/wp-tests-config.php`, with its own `ABSPATH` and `DB_DIR`;
- `wordpress/`, with the SQLite Database Integration plugin and its `db.php` drop-in;
- `sqlite-db/`.

Copy an existing one and change every path in `wp-tests-config.php` and `wordpress/wp-content/db.php`. Leave `WP_TESTS_MULTISITE` out of the config, because `phpunit-multisite.xml.dist` sets it.

```bash
rm -f ~/Library/Caches/undergrowth-wpt-<name>/sqlite-db/tests.sqlite*
WP_TESTS_DIR=~/Library/Caches/undergrowth-wpt-<name>/develop/tests/phpunit \
  php -d xdebug.mode=off vendor/bin/phpunit [-c phpunit-multisite.xml.dist] [--filter ...]
```

Never share `DB_DIR` or `wordpress/` between sessions. Concurrent runs then fail with "database tables are unavailable", or `Test_Memory` fails at random through the shared uploads folder. Kill a timed-out run before starting another. Homebrew PHP has Xdebug on by default, so pass `-d xdebug.mode=off` unless you need coverage.

Both PHPUnit configs run tests in random order, and PHPUnit prints the seed; pass `--random-order-seed <n>` to replay one. On SQLite, add `--order-by=default` to multisite runs. There, the temporary tables of sites from earlier tests clash with new sites that reuse their IDs, and the driver rolls the test back. CI's MariaDB runs both suites in random order.

**PHPCS from a worktree checks nothing.** `phpcs.xml.dist` excludes `/.claude/*`, and worktrees live under `.claude/worktrees/`, so `composer lint` passes without reading a file. Run it with that line removed and explicit paths:

```bash
grep -v '/.claude/\*' phpcs.xml.dist > .phpcs-local.xml
vendor/bin/phpcs -q --standard=.phpcs-local.xml undergrowth.php uninstall.php src tests bin
rm .phpcs-local.xml
```

**Playwright without Docker.** `npm run screenshots` and `npm run playground` use WordPress Playground. To run other specs against Playground, write a scratch config that spreads `playwright.screenshots.config.js`, sets `testMatch` to the specs you want and `testIgnore` to `states.spec.js`, and runs with one worker. Don't commit it.

**POT.** Run `npm run build` before `npm run i18n:pot`. Then check that the header's `Report-Msgid-Bugs-To` has not picked up the worktree's folder name.

## Pull requests

- Every PR needs an issue with all five template fields, a branch named `<issue>-<slug>`, and a changelog fragment `changelog/<issue>-<slug>.txt`. A docs- or tooling-only PR with no user-facing change gets the `no-changelog` label instead.
- Commits use the repo-local Novaclaw git identity, in WordPress core style (see CONTRIBUTING.md).
- CodeRabbit's approval is the required review. It allows about 10 reviews an hour per repository, so batch fixes into one push. When it says "Review paused", comment `@coderabbitai resume`, then `@coderabbitai review`.
- Auto-merge (`gh pr merge <n> --auto --merge`) can land a PR while you are still fixing it. Run `gh pr view <n> --json state` and confirm it is `OPEN` before every push to its branch.
- Only `main` is protected. Never enable auto-merge on a PR whose base is another feature branch, because it merges into that base at once.
- When a branch falls behind, merge `main` into it. Don't rebase.
