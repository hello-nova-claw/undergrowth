# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

Undergrowth is a WordPress 6.9+ plugin (PHP 7.4+) for personal bloggers. Read [CONTRIBUTING.md](CONTRIBUTING.md) before any change. It holds the process, commit style, code, i18n, accessibility and screenshot rules, and this file does not repeat them. [docs/handover.md](docs/handover.md) is the design record; section 2's principles are not negotiable. The core one: the AI may speak **to** the author, never **for** the author. Nothing a model writes is ever shown to readers.

## Layout

- `undergrowth.php` requires the files in `src/`, except the three list tables, which `admin.php` loads, and `cli.php`, which loads only under WP-CLI. There is no autoloader and there are no namespaces; everything global is prefixed `undergrowth_`.
- `src/`: one file per concern. `embed.php` and `openai.php` make vectors, which `vectors.php` stores packed in a custom table defined in `schema.php` and compares (`undergrowth_cosine()`). `vector-packing.php` converts rows stored as JSON before schema version 8, in cron batches. `keys.php` reads the API keys. `indexer.php` handles embedding and backfill. `links.php`, `sentences.php` and `accept.php` handle suggestions and Accept; `anchor-words.php`, `anchor-spans.php` and `choose-words.php` choose the words a link sits on, and `class-undergrowth-html-text.php` reads an HTML fragment's text as a browser does. `clusters.php`, `thresholds.php` and `stages.php` run the monthly cycle, in batches tracked by `passes.php`; `stage-actions.php` holds the author's pin, compost and return; `tend.php` notes an Evergreen post that has not been tended for a while; `resurfacing.php` signals a composting post close to recent writing; `orphans.php` finds posts no accepted link points to; `map.php` names each cluster from the author's own words and counts every post for the Map; `hubs.php` proposes hub drafts for dense groups; `pollination.php` finds cross-pollination pairs of groups for the Map and the Prompt studio (`undergrowth_pollination_pairs()`), `pollination-view.php` shows them on the Map and `cross-links.php` offers them as suggestions. `prompts.php` and `anthropic.php` write the questions in the Prompt studio (`studio.php`, Posts › Prompt studio), from the sources in `prompt-sources.php`, under the rules and checks in `prompt-rules.php`. `digest.php` sends the opt-in monthly email of new suggestions. `memory.php` writes the per-post text files, and `memory-folder.php` names and protects their folder. `visibility.php` holds the one rule for which posts readers may see on any block. The reader blocks are registered in `blocks.php` (Growth stage and More like this, and the block styles) and in `block-garden-count.php` (with the Garden page pattern), `block-garden-feed.php`, `block-garden-filters.php`, `block-garden-index.php` and `block-linked-from.php`; `type-icons.php` draws the feed's post-type icons, and `similar.php` and `popularity.php` build More like this, reading views daily from a stats plugin. `demo-images.php` answers the WordPress Importer's download of a demo illustration from `demo/images/` while an import runs. `editor.php`, `editor-links.php` and `draft-scan.php` serve the block editor's Undergrowth panel, sidebar and Scan this draft. `discord*.php`, `meter.php`, `abilities.php` (registered in `ability-registration.php`), `cli.php` (`wp undergrowth`), `privacy.php` (policy text, exporter and eraser) and `site-health.php` cover the rest. The admin screens are `admin.php` (Posts › Undergrowth, slug `undergrowth-links`: Suggestions, Orphans and Growth stages tabs, each a `class-undergrowth-*-list-table.php`, a Map tab once clusters exist, in `admin-map.php`, and a Dev tools tab on local sites, in `dev-tools.php`; `admin-helpers.php` holds the row actions, post links and notices the screens share), `settings.php` (Settings › Undergrowth), `posts-screen.php`, `dashboard.php`, `help.php` and `first-run.php`.
- The JavaScript is the seven blocks in `src/blocks/` (garden-count, garden-feed, garden-filters, garden-index, growth-stage, linked-from, more-like-this; `garden-filters/view.js` is an Interactivity API module) and the editor panel, sidebar and draft scan in `src/editor/`. `npm run build` builds them into `build/`, which is not committed. Block styles and layouts are plain CSS in `assets/`.
- `tests/phpunit/` uses `test-*.php` files with `WP_UnitTestCase`. Jetpack stand-ins live in `tests/phpunit/includes/`.
- `tests/e2e/` holds the Playwright specs. `tests/e2e/mu-plugins/undergrowth-e2e.php` is a fake embedding provider that counts fixture words, so no key is needed. `tests/e2e/states.spec.js` is the screenshot catalogue and `tests/e2e/gallery.spec.js` shoots the Garden page in ten themes. The Playground blueprints are in `tests/e2e/playground/`. CI publishes both sets of shots to the orphan `screenshots` branch, which must not be deleted.
- `tests/js/` holds the Jest tests. `bin/` holds the CI and local tools: coverage gate, native-WordPress check, changelog folding, POT tooling, pseudo-locale, screenshot and gallery comparison, pa11y, the Playground starters and the demo-content builders.
- `demo/` holds the public-domain demo blog as WXR, built by `bin/build-demo-content` (Python), with its illustrations in `demo/images/`, drawn by `bin/draw-demo-images`. About one post in four has a post format and no picture, so the feed shows text cards. See `docs/demo-content.md`.
- `changelog/` holds one fragment per PR. `docs/` holds the design record, the vision, the hooks reference (`docs/hooks.md`), the v1 reviews and reports, and `docs/examples/local-embeddings.php`.

## How it fits together

Work happens in WP-Cron, never in the request that saves a post:

- **Saving a published post** queues `undergrowth_embed_post`, which fires `undergrowth_post_embedded`. That event refreshes link proposals around the post (`links.php`) and fires `undergrowth_proposals_refreshed`. Both the per-post file (`memory.php`) and Discord listen for it. `undergrowth_post_embedded` also queues the post's More like this neighbours (`similar.php`).
- **Saving the OpenAI key**, or the hourly `undergrowth_backfill_check`, starts `undergrowth_backfill`. It embeds old posts in batches, then queues one `undergrowth_cluster_cycle`.
- **Monthly**, `undergrowth_cluster_cycle` measures thresholds and clusters in batches. It then queues `undergrowth_stage_cycle`, runs resurfacing for any new clusters, builds the garden index, labels the clusters from the author's words and starts cross-pollination. When the stage cycle ends, `undergrowth_stage_cycle_finished` sends the monthly emails.
- **Weekly**, `undergrowth_prompt_batch` tops the Prompt studio up to five new questions.
- **Daily**, `undergrowth_read_popularity` reads post views from a stats plugin, if one is active.

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
- CodeRabbit's approval is the required review. It does not review automatically (the repository has fewer than ten stars) or drafts, so comment `@coderabbitai review` once a PR is ready. Its free limit is one review an hour per repository, so batch fixes into one push. When it says "Review paused", comment `@coderabbitai resume`, then `@coderabbitai review`. It skips Dependabot's PRs (a bot has no review seat), so those need the owner's approval, plus the `no-changelog` label.
- GitHub sometimes starts a PR's workflows many minutes after a push. Wait for them; never push an empty commit or close and reopen a PR to start CI.
- Auto-merge (`gh pr merge <n> --auto --merge`) can land a PR while you are still fixing it. Run `gh pr view <n> --json state` and confirm it is `OPEN` before every push to its branch.
- Only `main` is protected. Never enable auto-merge on a PR whose base is another feature branch, because it merges into that base at once.
- When a branch falls behind, merge `main` into it. Don't rebase.
