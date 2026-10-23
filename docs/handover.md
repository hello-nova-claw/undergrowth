# Handover: Undergrowth

Status (2026-10-23): v1, germination mode, is built: build order steps 1 to 8 and the growth-stage badge block from step 9. It has had security, quality and UX reviews, and is being tested on real posts (docs/testing-v1.md). The related-by-readers block and everything under reader mode wait for v2.

This document is the record of decisions, first made on 2026-10-18 as the input for the first Claude Code session, with later decisions added in place.

**Name: Undergrowth** (locked). Slug `undergrowth` is free on WordPress.org as of 2026-10-18. Ability prefix `undergrowth/`, function prefix `undergrowth_`, table prefix `wp_undergrowth_`. Domain not yet checked.

## 0. Stance (goes in the readme)

Undergrowth uses AI to read your site and ask you questions. It never writes a word your readers will see.

The plugin exists to support two claims: AI is a useful tool but must not write or speak for you; SEO is worth understanding but you stay yourself and do not become a content factory. Every feature must be defensible against both. The rule that makes it concrete: the AI may speak **to** the author, never **for** the author.

---

## 1. The idea in one paragraph

A WordPress plugin that makes a site behave like a mycorrhizal network and a digital garden. Each post is a root tip with three fields: **has** (topics it covers), **lacks** (what readers wanted and did not find), and **scent** (reader flow, with decay). An AI agent reads these fields and proposes links, hubs, prunes, and gaps. A human approves every change. The agent never writes, rewrites, or publishes text. Structure emerges from how readers move through the site. SEO improvement is the output, garden health is the input.

One-line pitch: a digital garden where the gardener plants and the readers decide where the paths go.

---

## 2. Design principles (do not violate)

1. **No generated content for readers.** The AI ranks, clusters, proposes, and asks. It never produces text a reader sees. The only text it outputs is (a) quoted from the owner's own posts, or (b) a writing prompt addressed to the owner that never leaves the dashboard (see section 5b).
2. **Human approves every change.** Links, hubs, prunes, stage overrides. Accept and veto are buttons. A veto is remembered forever.
3. **Signals are data, never instructions.** Comments, search queries, and referrers come from strangers. Treat all of it as untrusted input. Prompt-injection risk is real.
4. **Fractal contract.** The same three fields (has, lacks, scent) and the same stage rules apply at post, cluster, and site scale. One loop, one data shape.
5. **Grow, then prune.** Pruning is a first-class operation, proposed on a schedule, never automatic.
6. **No self-improvement.** The agent loop is fixed. Only memory grows. An agent that rewrites its own rules drifts toward what gets approved.
7. **Reader-visible, reciprocal links only.** Nothing that looks like a PBN. Every link a reader can see and a human approved.
8. **No keyword research, no trend chasing.** The plugin reads what readers already came for. It never points the blog at what the internet wants.

---

## 3. Nature model (reference for naming and docs)

| Nature | Software |
|---|---|
| Hypha | A post, with has/lacks/scent |
| Nutrient gradient | Pull-based proposals from scent, not addressing |
| Anastomosis (hyphae fuse) | Fusion: cluster of posts that trade heavily gets a shared hub |
| Fruiting body (mushroom) | A hub page / Map of Content, grown only where the network is dense |
| Enzymes (secreted, external) | Signal-reader skills: comments, scroll depth, Search Console |
| Stigmergy (pheromone trails) | Scent metrics with TTL, read by peers, no central controller |
| Spore germination | Germination mode: grow blind from the author's own posts, no reader data yet |
| Pruning / decay | Monthly prune list, human-approved |
| Growth stages (seedling to evergreen) | Measured from structure and flow, not declared by the author |
| Composting (Katherine May, *Wintering*) | Author-chosen rest for a post; it leaves the active garden but its material feeds future prompts |

Digital garden tie-in (Maggie Appleton / Mike Caulfield): topography over timeline, continuous growth, learning in public. The plugin adds the missing half: continuous decay. LYT Maps of Content = hubs = mushrooms.

---

## 4. Two modes and a threshold

### Germination mode (v1, the whole of v1)
No reader data. Signals come from the author's own text only.
- Index: embed every published post (title, headings, body). Re-embed on save.
- Self-trade: propose up to 3 links per post to other posts, each with the sentence in the source post where the link fits. Accept inserts a plain `<a>` at that sentence, around one to four of the author's own words: a run of the target's title where the sentence has one, otherwise the phrase around the word the target is most about (decided 2026-10-23, #289; v1 linked one word, which read like an accident). Before accepting, the author can choose other words of the same sentence as the first and last of the link (#292); nothing can be typed, so the link is always the author's own words. Veto hides it permanently.
- Map: cluster the vectors. Show clusters as a list with their posts. Flag any cluster whose centre has no post (a gap found from the author's own writing).
- Threshold meter: one number, visits in last 30 days against the switch line. Nothing else shown until crossed.
- Growth stages (see section 5).
- Pruning is OFF. Nothing auto-demoted. Wilting cannot appear.
- The plugin should NOT suggest topics from keyword research or trends.

### Reader mode (v2, gated behind the meter)
- Scent sources: dwell, scroll depth, exit position, next-click paths, 404s and typed URLs, comments and contact-form questions, Search Console queries (via Site Kit tokens, not own OAuth).
- Zero-result site search is the strongest scent where site search exists. Where it does not, Search Console query-to-page mismatch is the strongest.
- Prune list: links with no clicks in a decay cycle, posts with no readers, nav items nobody uses.
- Refresh list: evergreen posts whose scent is fading.
- Gap list: queries landing on the wrong post. The owner writes the post or does not.
- Fusion: propose a hub where a cluster trades heavily. Owner writes the hub text.

### Switch line
Mixed rule. A fixed visit number for the meter (milestone the owner can see): 1,000 page views in the last 30 days, from Jetpack / WordPress.com Stats. A statistical check before each reader proposal (enough visits on the top pages for proposals to repeat), which arrives with reader mode in v2. Crossing the fixed number alone never unlocks a proposal. Two rules, both documented in the UI.

---

## 5. Growth stages (v1, locked)

| Stage | Rule |
|---|---|
| Seedling | 0 or 1 accepted link in or out. New post default. |
| Sprout | 2+ accepted links, not in a cluster of 3+ posts. |
| Budding | In a cluster, links both in and out. |
| Evergreen | In a cluster, 3+ links in, at least one hub links to it. Once reader mode is on: scent above site median for 60 days. |
| Wilting | Was evergreen, scent below median for 60 days. Reader mode only. Goes on the refresh list. |
| Composting | Author-chosen only. The plugin never proposes it. Post stays published but leaves the active garden: no link proposals in or out, no prune or refresh listing, no stage changes, no timer, never expires. Embedding stays in the index and may feed writing prompts and resurfacing. From Katherine May, *Wintering*: a fallow period is material for what grows next, not failure. |

Composting rules:
- Only the author moves a post to compost, from any stage. The plugin never suggests it.
- Composted posts' vectors stay in the index. The prompt generator (section 5b) may draw on them, e.g. "Three years ago you wrote about X. Is there something there now?"
- Resurfacing: when a composted post's vector sits close to a new cluster, one signal appears on the map: "this old post is near what you are writing now". The author returns it to the garden or leaves it. One signal per post per cycle, dismissable. The first clustering pass is a baseline, so nothing resurfaces on it, and the same holds for a pass after one that found no clusters and for the first pass after a re-embed under a new provider, model or language (decided 2026-10-23, #240).
- No "composting for N days" message, no expiry, no nudge to return it. You cannot schedule a winter.
- Composting is not deletion, redirect, or noindex. Those remain prune actions the author can take separately.
- Storage: composting is a value of the same post meta key as the other stages, and is written to the per-post text file like any stage change. Pinning rules do not apply; composting is always an explicit author action, so no pin marker is shown.
- Composting takes effect immediately. It is an author action, like a pin, not a measured change, so it does not wait for the monthly cycle.
- Returning a post to the garden computes its stage at that moment under the normal stage rules. It does not restore the stage it had before, which may be years out of date, and does not reset it to Seedling, which would treat a rest as a failure.
- Links the author already accepted stay in place. Composting stops new link proposals in or out; it never removes a link, because that would change the site without the author's approval.
- Composted posts are left out of the health score and shown as a separate count, so resting a post never reads as decline.

Rules:
- Author can pin a stage. A pinned stage shows a small marker.
- Stages change only on the monthly cycle, never on save. Author actions (pinning, composting, returning a post from compost) are the exception and take effect immediately.
- Stage stored in post meta (themes can show it) and in the readable per-post file.
- Nothing is auto-demoted in germination mode.
- Stages double as the SEO health score. Composted posts are left out of it.

---

## 5b. Writing prompts (v1)

Personalised prompts that point the author in a direction. The one feature where the AI produces sentences, so the rules are strict.

- **Source is the garden, never the internet.** A prompt comes from: a gap in the author's own clusters (germination mode), a comment or contact-form question, a wilting post, or a Search Console query landing on the wrong page (reader mode). Never from trends, keyword volume, or competitor content.
- **Form is a question, never a draft.** Example: "Readers of your oca post keep asking how you store it over winter. What do you actually do?" Never a title, an outline, an opening line, a list of talking points, or a suggested structure.
- **Quote only the author.** When context is needed, the prompt quotes a sentence from the author's own post.
- **One at a time.** Dashboard shows one prompt. Dismiss removes it permanently. Snooze returns it next monthly cycle. No feed, no backlog view.
- **Readable prompt file.** Every prompt, dismissal, and snooze is logged in the per-post text file. Dismissals shape future prompts by exclusion only. No self-improving loop.
- **Never nags.** No streaks, no "you haven't written in X days", no cheerleading.
- Prompts are written by Claude through the Anthropic API, with its own key in settings (decided 2026-10-19). Clustering is arithmetic on stored vectors and has no model call to share, and an embedding model cannot write a question. The feature is off until the key is entered, and the settings page says what is sent: the gap or cluster summary and any sentences quoted from the author's own posts. Every other rule in this section still applies to what Claude writes.

## 6. Architecture decisions (locked)

### Embeddings: API default, local via filter
- One function, e.g. `undergrowth_embed( string $text ): array`. Returns a vector of floats. Nothing else in the plugin knows which model made it.
- Default: OpenAI `text-embedding-3-small` via `wp_remote_post` (decided 2026-10-18). Owner pastes a key in settings. Feature is OFF until a key is entered. Settings notice states that post content is sent to the provider. Voyage and Jina may be added later as settings choices; the provider code stays pluggable so that costs little.
- Filter: `apply_filters( 'undergrowth_embed_provider', null, $text )` runs before the API call. If a callback returns a vector, the API is skipped.
- Identity: a provider hooked through the filter also declares its provider and model names through `apply_filters( 'undergrowth_embed_identity', array( 'provider' => '', 'model' => '' ) )`. The plugin has to know the current model without calling the provider, both to skip posts that have not changed and to re-embed everything when the model changes. A vector without an identity is never stored.
- Anthropic offers no embedding model, so Anthropic API credits cannot pay for vectors.
- Local provider example: tiny HTTP service on localhost (sentence-transformers behind FastAPI, or Ollama embedding model), called from a mu-plugin. Nova/Atlas on the VPS can host this.
- Store provider name and model name with every vector. Refuse to compare vectors from different models. If the provider changes, re-embed everything on the next cron run.
- A change of site language re-embeds everything in the same way, when posts take their language from the site, so the index never splits into halves that are never compared. When a multilingual plugin sets each post's language through `undergrowth_post_language`, posts in different languages stay apart and a site language change re-embeds nothing (decided 2026-10-23, #239).
- Future zero-config path: in-PHP ONNX small model, or a hosted endpoint behind the Jetpack connection. Not v1.

### WordPress AI Client: decision (2026-10-22, #119)

**Decision: stay for v1, and partly move in v2 as an opt-in second path.** The direct OpenAI and Anthropic clients stay as they are. The AI Client is added later beside them, never in place of them, until the minimum version reaches the release that has everything.

What the sources say, checked against WordPress trunk, the 7.1.3 tag, `WordPress/php-ai-client` and the `ai-provider-for-*` plugins on 2026-10-22:

- **Embeddings are not in a released WordPress.** `wp_ai_client_embedding()` and `WP_AI_Client_Embedding_Builder` are marked `@since 7.2.0` and are absent from the 7.1.3 tag. 7.2 is in beta this week, with a scheduled release of 2026-12-08. `wp_ai_client_prompt()` (7.0) does text, image, speech and video, not embeddings. The SDK underneath (php-ai-client 1.4+) and the OpenAI provider plugin do support `text-embedding-3-small`.
- **The model is always named by the caller.** An embedding builder will not choose a model, because vectors from different models cannot be compared. So Undergrowth would pin `usingProviderModel( 'openai', 'text-embedding-3-small' )` and declare that same pair through `undergrowth_embed_identity`, from constants, with no call. Nothing in the identity rule changes. Vectors from the same model through the same API are the same vectors, so switching path would not need a re-embed. One caveat: the model list is fetched from the provider once and then cached, so "is it usable" may cost one request, while "what is the identity" never does.
- **Writing prompts work on 7.0, with the Anthropic provider plugin.** Core bundles no provider; the owner installs `ai-provider-for-anthropic` from Settings > Connectors. `using_system_instruction()`, `using_max_tokens()` and `using_provider( 'anthropic' )` plus a model cover what `src/prompts.php` sends. The effort setting has no method; the Anthropic provider passes unknown model-config options straight into the request, so `output_config` can travel that way. Untrusted data stays what it is today, delimited text in the user message under a system prompt; the client adds nothing and removes nothing. A reply cut off by the token limit comes back as a `WP_Error` (the provider throws on `max_tokens`), which is the behaviour `undergrowth_anthropic_message()` wants. A refusal comes back as a finish reason, so the check for it has to be kept.
- **Credentials are site-wide, not per feature.** The owner enters a key once under Settings > Connectors. It is stored in the option `connectors_ai_<provider>_api_key`, or read from an environment variable or PHP constant (`OPENAI_API_KEY`, `ANTHROPIC_API_KEY`), and is masked in REST responses. Every plugin that calls the AI Client can use that key, and the owner cannot give it to Undergrowth alone. Undergrowth cannot read it and must not.
- **Plugin Check:** the `AIProvider.DirectIntegration` sniff matches provider host names (`api.openai.com`, `api.anthropic.com`) in strings. It stops firing only when those strings are gone from the plugin. While the 6.9 fallback exists it keeps firing, and the existing `phpcs:ignore` comments stay. Moving does not remove the warning; dropping 6.9 does.

Why not move now: the plugin supports 6.9, embeddings need 7.2, writing needs 7.0 plus a plugin install, and a move that only half works leaves two code paths with no gain. Why move at all later: an owner who already has a provider set up skips pasting keys, which is the biggest hurdle for a non-developer.

Rules for the v2 path, which do not bend:

- **Off until configured.** The AI Client path is chosen by the owner on the settings page. It is never picked because a connector happens to exist on the site, so installing some other AI plugin cannot start sending post text from Undergrowth.
- **The settings page says what is sent and where.** The notice names the provider and model Undergrowth will use, says the key is the site's and shared with other plugins, and lists the same content as today (post text for vectors; the gap or cluster summary and quoted sentences for prompts).
- **No vector without an identity.** The path declares provider and model from constants, and a failed or unsupported call stores nothing.
- **Fallback.** Order is the `undergrowth_embed_provider` filter, then the AI Client when the owner chose it and the function exists, then the direct key. Below 7.2, or without a configured connector, the owner sees the existing key fields.
- **Raising "Requires at least"** to 7.2 and deleting the direct clients is a separate decision, to be made when 7.2 is out and the AI Client path has been used on real sites. It is what would clear the Plugin Check warning.

Follow-ups, all `v2`: embeddings through `wp_ai_client_embedding()`, blocked on 7.2 (#189); writing prompts through `wp_ai_client_prompt()` (#190); the settings copy that goes with both (#191).

### Storage
- Custom table for vectors.
- Readable plain-text file per post (has, lacks, scent, stage, every veto). Portable between sites. Owner can read and edit it. This is the "plain-text memory" pattern from OpenClaw/Hermes.

### Map rendering
- v1: list. Clusters as headings, posts under each, gaps flagged. No JS graph library.
- v2: force-directed graph as an optional view.

### Abilities API (WordPress 6.9+ core)
- Register via `wp_register_ability()` on `wp_abilities_api_init`.
- Read abilities (one shape at post, cluster, site scale): `undergrowth/has`, `undergrowth/lacks`, `undergrowth/scent`, `undergrowth/stage`.
- Write abilities with capability check, `meta.public` false, never exposed to MCP: `undergrowth/accept-link`, `undergrowth/veto-link`.
- Signal sources (enzymes) are abilities other plugins can register. Core only calls the registry. No Search Console ability registered means no Search Console signal.
- Multi-site anastomosis later: site B's `undergrowth/has` is already a REST endpoint under `wp-abilities/v1`. Site A calls it over a signed request.
- Caveat: pin minimum WP version to 6.9. The REST/MCP exposure flags shifted between 6.9 and 7.0 and one source says 7.1 is where it becomes fully buildable. Test exposure on each release. Official MCP Adapter plugin converts abilities to MCP tools.

### Channels
- v1 UI: one wp-admin screen with three lists (link proposals, prune proposals, gaps) plus accept/veto buttons. Default ships with ONE toggle: "Suggest links between my posts". Everything else appears only when there is something to show.
- v1 channel: Discord via the **interactions endpoint** (option 3). Discord POSTs button clicks to a public URL. WordPress receives it as a REST route with Ed25519 signature verification. No sidecar, no polling. One Discord channel per list, buttons on messages. Optional; most non-dev owners will never set it up.
- v2 channel: Telegram.
- Rejected: webhook+polling (clunky), VPS sidecar (fastest for a prototype but not plugin-ready).

### Thresholds follow the site's own scores (decided 2026-10-22)
- Each embedding model spreads cosine scores differently, so fixed numbers tuned for one model link everything, or put every post in one cluster, with another (#176, docs/quality-report-v1.md).
- Defaults come from the site's own scores, per provider and model:
  - **Links:** the 40th percentile of pairwise scores.
  - **Clusters:** the 90th percentile.
  - **Gaps:** a cluster whose nearest member to its centre is an outlier among the site's clusters, below Q1 − 1.5 × IQR of their centre scores. This needs at least 4 clusters.
- The monthly cluster cycle measures them from a sample of at most 20,000 pairs (`src/thresholds.php`), so the cost does not grow with the site. The pairwise percentiles are measured before clustering and the gap threshold after.
- The fixed 0.4 / 0.5 / 0.8 stay under 20 posts, before the first measurement, and for the providers in `UNDERGROWTH_FIXED_THRESHOLD_PROVIDERS` (filter `undergrowth_fixed_threshold_providers`).
- That list holds `openai` until the real-posts test (docs/testing-v1.md) records its percentiles.
- The threshold filters still have the last word. Each receives the derived value as its default.
- `wp undergrowth scores` prints the measured percentiles and the thresholds in use.

### Search Console
- Read tokens from Site Kit by Google if installed. Do not build own OAuth for v1 (Google app verification is a blocker for a public plugin).
- Limits: 2-3 day lag, 16 months retention, low-volume queries hidden, daily API quotas.

### Jetpack
- Jetpack Search is keyword (Elasticsearch, BM25-style) with pageview-weighted ranking. No public embeddings/vector API. Its pageview weighting is already a form of scent.
- Jetpack / WordPress.com stats are the v1 source for the threshold meter.
- Open question for later (not now): whether any WordPress.com embedding endpoint exists that Jetpack-connected sites could call. Would solve the API-key problem for non-devs.

---

## 7. Packagability notes

- Easy (zero config): install, index, three lists, buttons. ~70% of the value.
- Medium: pasting an embedding API key. Most WP owners who use an SEO or email plugin can do it.
- Hard, optional: Discord app setup. Keep admin screen as default.
- Hard, delegated: Search Console. Use Site Kit.
- WordPress.org review: core components only (`@wordpress/components`, standard wp-admin tables, no custom CSS framework). No obfuscated code, no scripts loaded from outside the plugin, no tracking without opt-in, nothing that phones home silently. GPL.
- Blocks and patterns ARE allowed: e.g. a "Growth stage" badge block, a "Related by readers" list block via `block.json`, patterns via `register_block_pattern()`. Build assets into the plugin. Do not bundle a theme or override core blocks.

---

## 8. Repo, process, and CI (locked)

### Ownership and identities
- Repo lives under **Novaclaw's** GitHub account. Novaclaw is the author identity for issues, branches, PRs.
- Anna's account is NOT tagged, requested, or in CODEOWNERS for now. CODEOWNERS stays empty. Add Anna later.
- Reviewer: **CodeRabbit** (GitHub App, free for public OSS). Its approval counts as the required review. Copilot code review rejected (needs paid seat).
- Optional later: a Claude Code review action under a second bot identity.
- Rule: nothing merges that only one agent has seen. Enforced by GitHub, not trust.

### Branch protection on `main`
- Require a PR. No direct pushes. No admin bypass.
- Require 1 approval. Dismiss stale approvals on push.
- Require all CI checks green.
- GitHub blocks self-approval by default; author and reviewer must be different accounts.

### Process
1. Every piece of work starts as an issue. Issue template, five required fields: problem, scope, out of scope, acceptance criteria, how to test. Not ready without all five. Agents fill the template before touching code.
2. GitHub Projects board with an Iteration field for sprints. Columns: Backlog, Ready, In progress, In review, Done. Issues move, not PRs.
3. Branch per issue. PR per issue. PR template, three sections: what, why, how to test. Link the issue. Changelog line, as a fragment in `changelog/`, is part of the PR.
4. Small commits, one thing each. Test commit before code commit.

### Commit and PR style (anti-AI-tell rules)
- WordPress core style commit messages: imperative, component prefix, under 72 chars, body only when there is a why. Example: `Embeddings: Store model name beside each vector.`
- No emoji. No `feat:` / conventional-commits prefixes. No bullet lists of everything touched.
- Comments explain why, never what. Delete any comment that restates the line below. One DocBlock per function in WordPress style, nothing else.
- Large single-commit PRs are the strongest tell. Do not.
- Write `CONTRIBUTING.md` and `.github/pull_request_template.md` BEFORE any code.
- `.coderabbit.yaml` points CodeRabbit at CONTRIBUTING.md and WPCS so reviews enforce project rules.

### CI (GitHub Actions, unlimited on public repos, stay on `ubuntu-latest`; larger runners are billed even on public repos)
- PHPCS with WordPress Coding Standards (WPCS). Fails on any violation.
- PHPStan, level 6 or higher.
- PHPUnit with the WordPress test suite via `wp-env`, against a WP version matrix.
- Jest for JS.
- Playwright end-to-end for the admin screen.
- Plugin Check (WordPress.org's checker).
- Coverage gate that cannot go down.
- TDD check: a PR touching `src/` must also touch a test file.
- i18n: `wp i18n make-pot` diff check, PHPCS i18n sniffs, RTL and pseudo-locale Playwright runs (see Internationalisation below).

### Internationalisation (day zero)
- Text domain `undergrowth`, matching the slug. Declared in the plugin header. Every user-facing string goes through `__()`, `_e()`, `_x()`, `_n()` or `esc_html__()` with that domain. No string is ever concatenated; use `sprintf()` with numbered placeholders so translators can reorder.
- JS strings use `@wordpress/i18n` and `wp_set_script_translations()`.
- `wp i18n make-pot` runs in CI and the generated `languages/undergrowth.pot` is committed. A PR that adds a string without updating the POT fails.
- Plugin Check's i18n sniffs (WPCS `WordPress.WP.I18n`) are part of the PHPCS run: missing text domain, wrong domain, or non-literal strings fail the build.
- Translations are hosted on translate.wordpress.org once the plugin is in the directory, so no `.po` files in the repo except a test locale.
- Embedding provider must handle the site's language. Store the post's language with its vector (from `get_locale()` or a multilingual plugin's post language). Never compare vectors across languages.
- Prompts to the author are generated in the site's locale, passed to the model as an instruction. Dates and numbers in the dashboard use `wp_date()` and `number_format_i18n()`.
- RTL: admin screen and blocks tested in an RTL locale (`ar` or `he`) as part of the Playwright run. `wp-env` can be started with a locale.
- Test locale in the repo: a pseudo-locale (`en_XA`-style, accented and lengthened strings) so layout breakage from longer translations shows up in CI, not in German production.

### Accessibility (day zero)
- `@axe-core/playwright` on every admin screen and every block in the editor. Fails on any WCAG 2.2 AA violation. This is the main linter.
- `@wordpress/eslint-plugin` for all JS (bundles `eslint-plugin-jsx-a11y`).
- `pa11y-ci` on the rendered front-end blocks as a second opinion.
- CONTRIBUTING rule: every PR touching UI states how it was tested with a keyboard.

---

## 9. Build order for v1

1. `CONTRIBUTING.md`, PR template, issue template, `.coderabbit.yaml`, CI workflows, branch protection. All before code.
2. Plugin skeleton. Custom table for vectors. Embed on save via the single embed function with the provider filter.
3. Self-trade: compute has/lacks, write one proposal per post to the readable per-post file.
4. Admin screen: three lists, accept/veto, single on/off toggle.
5. Growth stages: monthly cron, post meta, pin override.
5b. Writing prompts: one-at-a-time panel, dismiss/snooze, logged to per-post file.
6. Discord: register app, interactions endpoint as REST route with signature check, post proposals with buttons.
7. Threshold meter from Jetpack stats.
8. Abilities: register has/lacks/scent/stage (read) and accept-link/veto-link (write, non-public).
9. Blocks: growth-stage badge, related-by-readers list.

Everything in sections 4 "Reader mode" is v2.

---

## 10. Open items

- Domain for Undergrowth (name itself is locked).
- ~~Exact fixed number for the threshold meter.~~ Decided 2026-10-21: 1,000 views in the last 30 days (Jetpack Stats). It is the visible milestone; the per-proposal statistical check still applies in v2 (section 4).
- ~~Which embedding API is the default (OpenAI / Voyage / Jina).~~ Decided 2026-10-18: OpenAI `text-embedding-3-small`, others later (section 6).
- Whether to test the in-PHP ONNX embedding path for a fully non-dev install.
- WordPress.com embedding endpoint question (parked until Anna is back at work).
- ~~Whether to use the WordPress AI Client (#119).~~ Decided 2026-10-22: stay for v1, add as an opt-in second path in v2 (section 6).

---

## 11. Things explicitly rejected

- Gen-AI written or rewritten content of any kind.
- Agent self-improvement / rules that change from usage.
- Keyword volume research, title rewriting, meta description generation.
- Fixed-number-only switch line.
- Force-directed graph in v1.
- Own Search Console OAuth in v1.
- VPS sidecar for Discord as the shipped design.
- Copilot code review as the reviewer.
- Anna's account on reviews/CODEOWNERS for now.
