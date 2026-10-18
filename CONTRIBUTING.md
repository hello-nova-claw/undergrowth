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
4. **Pull request.** Use the template. Link the issue with `Fixes #12`. Add a changelog line under `= Unreleased =` in `readme.txt`. If the change really has no user-facing effect, say so in the PR and a maintainer will add the `no-changelog` label.
5. **Review.** CodeRabbit reviews every pull request, and its approval is the required review. Nothing merges that only one agent has seen. `main` is protected: no direct pushes, all checks green, stale approvals dismissed on push.

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
- JavaScript follows `@wordpress/eslint-plugin` and uses `@wordpress/components` for UI. No other CSS or component frameworks.
- Nothing loads from outside the plugin. No CDN scripts, no remote fonts, no tracking, nothing that phones home. Outbound requests happen only to a provider the site owner has configured, and the settings screen says what is sent.
- Escape late, sanitise early, check capabilities and nonces on every write.

### Comments

Comments explain why. Delete any comment that restates the line below it. Each function gets one DocBlock in WordPress style and that is all.

Test methods are the exception: a test's name says what it checks, so a DocBlock would only repeat it. Give a test method a DocBlock only when it needs one, such as for a `@dataProvider` or `@group`, or to explain a setup that isn't obvious.

## Tests

- Write the test first, and commit it first.
- A pull request that touches `src/` must also touch a file under `tests/`. CI checks this.
- PHP tests use PHPUnit with the WordPress test suite, run through `wp-env`, across the supported WordPress versions.
- JavaScript unit tests use Jest. End-to-end tests use Playwright.
- Line coverage must not drop below the figure in `.github/coverage-baseline`. When your change raises coverage, raise the baseline in the same pull request.

Run everything locally (needs Docker for `wp-env`):

```bash
composer install && npm install
npm run env:start
composer lint && composer analyse
npm run test:php
npm run lint:js && npm run test:js
npm run test:e2e
```

## Internationalisation

- Text domain is `undergrowth`. Every user-facing string goes through a translation function with that domain: `__()`, `_e()`, `_x()`, `_n()`, `esc_html__()` and friends.
- Never build a sentence by concatenation. Use `sprintf()` with numbered placeholders (`%1$s`, `%2$d`) so translators can reorder them, and add a `/* translators: */` comment for every placeholder.
- JavaScript uses `@wordpress/i18n`, and each script is registered with `wp_set_script_translations()`.
- Dates use `wp_date()`. Numbers use `number_format_i18n()`.
- `languages/undergrowth.pot` is committed. Regenerate it with `npm run i18n:pot` whenever you add or change a string. CI fails if it is out of date.
- No `.po` or `.mo` files in the repository. Translations live on translate.wordpress.org. The pseudo-locale used in CI is generated from the POT at test time.
- Vectors carry the language of the post they came from. Never compare vectors across languages.

## Accessibility

- Every admin screen and every block in the editor is checked with axe through Playwright, against WCAG 2.2 AA. Any violation fails the build.
- Front-end block output is checked again with pa11y-ci.
- End-to-end tests also run in Hebrew (right to left) and in a pseudo-locale with lengthened strings. Layouts must survive both.
- **Every pull request that touches UI must say how it was tested with a keyboard alone**: what you tabbed through, what you activated, where focus went afterwards.

## Security

Report vulnerabilities privately through GitHub's security advisory form on this repository, not in a public issue.
