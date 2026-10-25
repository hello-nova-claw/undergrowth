# Contributing to Undergrowth

These rules apply to everyone, human or agent. The reviewer enforces them, and CI enforces the parts that can be checked by a machine.

## The rule above all others

The AI may speak to the author, never for the author. Before you build anything, check it against the design principles in [docs/handover.md](docs/handover.md#2-design-principles-do-not-violate). A pull request that breaks one of them will be closed, however good the code is. In short:

1. Nothing the AI produces is ever shown to readers. It ranks, clusters, proposes and asks. The only sentences it outputs are quotes from the author's own posts, or a question to the author that stays in the dashboard.
2. A human approves every change to the site. A veto is stored forever.
3. Comments, search queries, referrers and anything else from visitors is untrusted data. Never put it into a model prompt as an instruction, never echo it unescaped, and assume it contains prompt-injection attempts.
4. The agent's rules are fixed in code. Nothing learns from approvals or rewrites its own behaviour.
5. No keyword research, trend data, or competitor content, ever.

## How work flows

1. **Issue first.** Every change starts as an issue made from the task template. An issue is not ready until all five fields are filled in: problem, scope, out of scope, acceptance criteria, how to test. Agents fill the template before writing code.
2. **Board.** Issues move across the project board: Backlog, Ready, In progress, In review, Done. Move the issue, not the pull request.
3. **One branch and one pull request per issue.** Name the branch `<issue number>-<short-slug>`, for example `12-vector-table`.
4. **Pull request.** Use the template. Link the issue with `Fixes #12`. Add your changelog line as a fragment, `changelog/<issue>-<slug>.txt`, for example `changelog/12-vector-table.txt`. Do not edit the changelog in `readme.txt`: when several pull requests are open, they would all change the same lines and conflict whenever one merges. At release time `npm run changelog` folds every fragment into `= Unreleased =` and deletes them. If the change really has no user-facing effect, say so in the PR and a maintainer will add the `no-changelog` label.
5. **Review.** CodeRabbit reviews every pull request, and its approval is the required review. Nothing merges that only one agent has seen. `main` is protected: no direct pushes, all checks green, stale approvals dismissed on push.

## Housekeeping

Finish work as cleanly as you start it.

- **Branches go when they merge.** GitHub deletes a pull request's branch automatically on merge. Prune your local copy too: run `git config fetch.prune true` once per checkout, then `git fetch` removes branches that are gone, and `git branch -d <branch>` removes your local branch.
- **`main` is the only long-lived branch.** Branch from an up-to-date `main` and keep the branch to its one issue. If a branch falls behind, merge `main` into it rather than rebasing a branch someone else may have checked out.
- **An issue is done when its pull request merges.** Every pull request closes its issue with `Fixes #N`, and the issue ends in Done on the board. Work found along the way gets its own issue, not a quiet extra commit.
- **Commit only source.** Never commit build output, coverage reports, `vendor/`, `node_modules/`, or editor and agent tool folders such as `.claude/` and `.vscode/`. If something new turns up in `git status` that is not yours to commit, add it to `.gitignore` in its own commit.
- **Leave no scaffolding.** Remove debug output, commented-out code, and TODOs without an issue number before asking for review. A TODO that stays names its issue: `// TODO(#12): ...`.

## Commits

Write commit messages in WordPress core style.

- Imperative mood, a component prefix, under 72 characters, ending with a full stop: `Embeddings: Store model name beside each vector.`
- Add a body only when there is a reason worth recording. Explain why, not what.
- No emoji. No `feat:` or other Conventional Commits prefixes. No bullet list of every file you touched.
- Keep commits small, one thing each. Commit the test before the code that makes it pass.
- A large pull request with a single commit will be sent back.

Common prefixes: `Embeddings`, `Storage`, `Self-trade`, `Map`, `Stages`, `Prompts`, `Admin`, `Discord`, `Abilities`, `Blocks`, `I18N`, `A11y`, `Build/Test Tools`, `Docs`.

## Code

- PHP follows the [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/). PHPCS runs with WPCS and fails on any error or warning.
- PHPStan runs at level 6. Do not add baseline entries to get a pull request through.
- Prefix everything global: functions with `undergrowth_`, hooks with `undergrowth_`, options and meta keys with `undergrowth_`, abilities with `undergrowth/`, tables with `{$wpdb->prefix}undergrowth_`.
- JavaScript follows `@wordpress/eslint-plugin` and uses `@wordpress/components` for UI. No other CSS or component frameworks. See [Use what WordPress already has](#use-what-wordpress-already-has).
- Nothing loads from outside the plugin. No CDN scripts, no remote fonts, no tracking, nothing that phones home. Outbound requests happen only to a provider the site owner has configured, and the settings screen says what is sent.
- Escape late, sanitise early, check capabilities and nonces on every write.

### Use what WordPress already has

Undergrowth should feel like part of WordPress. Before you write anything custom, use the core equivalent. If there isn't one, say so in the pull request.

- **Admin UI.** Core markup and classes: `.wrap`, one `h1`, `.notice` and `.notice-success`, `.button` and `.button-primary`, `.form-table`, `.description`. Core helpers: `submit_button()`, `WP_List_Table`, the Settings API, `add_posts_page()` and `add_options_page()`. Dashicons for icons.
- **Styling.** No `style` attributes. If CSS is ever needed, it goes in an enqueued file and uses the admin colour scheme's custom properties, such as `--wp-admin-theme-color`, never a hard-coded colour.
- **JavaScript.** `@wordpress/*` packages only: `components`, `i18n`, `api-fetch`, `data`, `element`. Build with `wp-scripts` and enqueue with the dependencies from the generated `.asset.php` file.
- **Core APIs.** REST through `register_rest_route()` with a `permission_callback`. HTTP through `wp_remote_*()`. WP-Cron, options and transients for scheduling and storage. `WP_Filesystem` for files. `wp_kses()` for markup. Nonces and capabilities on every write.
- **PHP tests.** `WP_UnitTestCase`, `self::factory()` for fixtures, `wp_set_current_user()` for roles. Fake HTTP with the `pre_http_request` filter and other behaviour with core hooks and filters. No Mockery, WP_Mock, Brain Monkey or PHPUnit mocks of WordPress functions.
- **End-to-end tests.** `@wordpress/e2e-test-utils-playwright`: `admin`, `requestUtils` and `editor`, not raw navigation or SQL.

CI enforces what a machine can: `npm run lint:css` refuses hard-coded colours, ESLint refuses imports of UI frameworks and mocking libraries, and `npm run check:native` refuses `style` attributes in `src/`, mocking libraries in `composer.json`, and npm packages that are neither `@wordpress/*` nor on the allowlist in `bin/check-native.js`.

### Comments

Comments explain why. Delete any comment that restates the line below it. Each function gets one DocBlock in WordPress style and that is all.

Test methods are the exception: a test's name says what it checks, so a DocBlock would only repeat it. Give a test method a DocBlock only when it needs one, such as for a `@dataProvider` or `@group`, or to explain a setup that isn't obvious.

## Tests

- Write the test first, and commit it first.
- A pull request that touches `src/` must also touch a file under `tests/`. CI checks this.
- PHP tests use PHPUnit with the WordPress test suite, run through `wp-env`, across the supported WordPress versions.
- JavaScript unit tests use Jest. End-to-end tests use Playwright.
- Statement coverage must not drop below the figure in `.github/coverage-baseline` on the branch you merge into, and lowering the baseline fails the gate. When coverage rises by more than half a point, set the baseline to the figure the Coverage gate prints, in the same pull request. A smaller rise passes, and the gate prints the figure for whoever next changes the file, so parallel pull requests do not all edit it.

Run everything locally (needs Docker for `wp-env`):

```bash
composer install && npm install
npm run env:start
composer lint && composer analyse
npm run test:php
npm run lint:js && npm run lint:css && npm run check:native && npm run test:js
npm run test:e2e
```

## Internationalisation

- Text domain is `undergrowth`. Every user-facing string goes through a translation function with that domain: `__()`, `_e()`, `_x()`, `_n()`, `esc_html__()` and friends.
- Never build a sentence by concatenation. Use `sprintf()` with numbered placeholders (`%1$s`, `%2$d`) so translators can reorder them, and add a `/* translators: */` comment for every placeholder.
- JavaScript uses `@wordpress/i18n`, and each script is registered with `wp_set_script_translations()`.
- Dates use `wp_date()`. Numbers use `number_format_i18n()`. The per-post files are the exception: they are fixed-format records in UTC, read by tools, not people, so they use `gmdate()`.
- `languages/undergrowth.pot` is committed. Regenerate it with `npm run i18n:pot` whenever you add or change a string. CI fails if it is out of date. Its references name the file but not the line, and it has no creation date, so moving code doesn't change it and open pull requests don't conflict over it.
- No `.po` or `.mo` files in the repository. Translations live on translate.wordpress.org. The pseudo-locale used in CI is generated from the POT at test time.
- Vectors carry the language of the post they came from. Never compare vectors across languages.

## Accessibility

- Every admin screen and every block in the editor is checked with axe through Playwright, against WCAG 2.2 AA. Any violation fails the build.
- pa11y-ci checks the admin screens, the Dashboard widget and the Growth stage block on the front end again, with both axe and HTML_CodeSniffer, in WordPress Playground. `npm run test:a11y` runs it without Docker. The pages and the rules it skips, each with its reason, are in `.pa11yci.js`.
- End-to-end tests also run in Hebrew (right to left) and in a pseudo-locale with lengthened strings. Layouts must survive both.
- **Every pull request that touches UI must say how it was tested with a keyboard alone**: what you tabbed through, what you activated, where focus went afterwards.

## Screenshots

- Every pull request from this repository that touches UI gets a **Screenshots** comment: before and after images of every shot that changed or is new, and a collapsed list of the ones that didn't. Reviewers look at it before approving, across all three locales and both widths. A visual change never fails the check, so nothing else will catch one.
- The images are kept on the `screenshots` branch under `pr-<number>/before/` and `pr-<number>/after/`, and each push adds a commit there, so the record stays after the pull request merges. The workflow run also has the full set as an artifact.
- `npm run screenshots` takes the same set locally, without Docker: it starts WordPress Playground with the e2e test provider and saves every screen in every state, locale (`en_US`, `he_IL`, `en_XA`) and width (1280 and 390) to `artifacts/screenshots/`, named `<screen>--<state>--<locale>--<width>.png`. When you add a screen or a state, add it to `tests/e2e/states.spec.js`.

## Trying a pull request

- Every pull request from this repository gets a **Preview in WordPress Playground** comment. The link opens the pull request's commit in the browser, logged in as admin, on Posts > Undergrowth with the small demo blog: 61 public-domain posts, already read and grouped (see [docs/demo-content.md](docs/demo-content.md)). It uses the e2e test provider, which counts words instead of calling an API, so no key is needed and none is ever asked for.
- `npm run playground` starts the same site locally from `tests/e2e/playground/preview.json`, without Docker, with your working copy mounted as the plugin. Open the address it prints. Changes to PHP files show on the next page load; the demo posts are imported afresh each time it starts.
- `npm run playground:real` starts a local site without the e2e test provider, reading posts with Ollama on your computer, or with OpenAI when `.env.local` gives a key, and can import your own posts or the demo blog. See [Test locally with your own keys](docs/testing-v1.md#test-locally-with-your-own-keys).

## Security

Report vulnerabilities privately through GitHub's security advisory form on this repository, not in a public issue.
