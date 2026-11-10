# Undergrowth

Undergrowth links your posts to each other in your own words, and shows you what your archive is really about. The AI reads; you decide.

It is a WordPress plugin for personal bloggers. It treats a site as a garden: it finds links between your own posts, groups posts that belong together, tracks how each post grows, and now and then asks you a question about your own writing. You approve every change, and a veto is remembered.

The rule behind every feature: the AI may speak **to** the author, never **for** the author. It ranks, groups, proposes and asks. It never writes, rewrites or publishes text for readers. Internal links in your own words, between posts that belong together, are SEO from a different direction.

## Features

- **Link suggestions.** Each post gets up to three suggested links to your closest other posts, each at a sentence you wrote, with the words to link highlighted. You can choose other words of the same sentence before you accept. **Accept** makes that word a link and saves a revision. **Veto** removes the suggestion for good. They are listed on **Posts › Undergrowth**.
- **Growth stages.** Each post is a Seedling, Sprout, Budding or Evergreen, measured once a month from the links you have accepted and the groups your posts form. Stages never go down, and you can pin one. A Growth stage column shows them on the Posts list.
- **Composting.** Move a post to compost to let it rest. It stays published and keeps its links but leaves the active garden. When an old composting post sits close to what you are writing now, Undergrowth tells you once.
- **The Map.** Posts that are close in meaning are grouped once a month and listed on the Map tab. Each group is named after the title of its post nearest the middle, with words from your own posts under it. No model names them. A group with no post at its middle is marked as a gap.
- **Prompt studio.** With an Anthropic key, Claude writes up to five new questions a week under **Posts › Prompt studio**, from a gap on the Map, a reader's question in an approved comment, a composting post or a cross-pollination pair of groups. Save one to your idea bank, replace it or dismiss it. It is always a question, never a draft, a title or an outline, and it quotes nobody but you. There are no streaks and no reminders.
- **A page views meter.** With Jetpack Stats on, the Dashboard shows your page views in the last 30 days against a milestone of 1,000. Passing it changes nothing yet; it marks when the reader features of v2 can start.
- **Discord, optionally.** Each new suggestion can also be posted to a private channel in your own Discord server, with Accept and Veto buttons.
- **Abilities.** Through the WordPress Abilities API, `undergrowth/has`, `undergrowth/lacks`, `undergrowth/scent` and `undergrowth/stage` read a post, a group or the whole site. `undergrowth/accept-link` and `undergrowth/veto-link` work like the buttons and are never exposed over REST or MCP.
- **Orphans and hubs.** The Orphans tab lists posts that no accepted link points to. A dense group can be offered as a hub draft. An Evergreen post you have not edited for a long time gets a quiet "Worth a look" note.
- **Reader blocks.** A **Garden page** pattern sets a **Garden count** (with a "Quiet" style), **Garden filters** and a **Garden feed** on a page. The feed shows every post readers may see, newest tended first, as image cards and text cards, in a Grid or Masonry layout, with a "Seed packet" style. The other blocks are **Garden index** (with a "Specimen" style), **Linked from**, **More like this** (with a "Columns" style) and **Growth stage**, which shows a post's stage as an icon and a line of text. Every block takes its colours and spacing from your theme.
- **In the editor.** An Undergrowth panel and sidebar show a post's stage and its suggestions, and **Scan this draft** suggests links for a draft when you ask.
- **A monthly email, optionally,** of the best new link suggestions.
- **A plain-text file for each post** in the uploads folder, recording its stage, its suggestions, your vetoes and the questions you were asked.

Settings › Undergrowth has three sections (Link suggestions, Questions in your Prompt studio, and Discord) and Help tabs that say what is sent where. OpenAI, Anthropic and Discord are sent nothing until you set each one up. An embedding provider of your own is given each published post's title and text, and sends them wherever its code says. The user documentation, including what each service receives and what it costs, is in [readme.txt](readme.txt), with the per-post file format in [docs/per-post-files.md](docs/per-post-files.md) and the Discord steps in [docs/discord.md](docs/discord.md).

## Requirements

- WordPress 6.9 or later (for the Abilities API).
- PHP 7.4 or later, with the mbstring extension.
- WP-Cron, or a real cron job calling it.
- An OpenAI API key, or your own embedding provider through the `undergrowth_embed_provider` and `undergrowth_embed_identity` filters. [docs/examples/local-embeddings.php](docs/examples/local-embeddings.php) uses a model on your own machine instead.
- Optional: an Anthropic API key for the Prompt studio questions, Jetpack Stats for the meter, and an HTTPS site for Discord.

## Install

There is no WordPress.org release yet. To install a build:

1. On GitHub, open **Actions › Build**, open the newest run on `main`, and download `undergrowth.zip` from its artifacts.
2. In WordPress, go to **Plugins › Add New › Upload Plugin**, upload the zip and activate it.
3. Go to **Settings › Undergrowth** and paste an OpenAI API key. Your published posts are read in the background.

[docs/testing-v1.md](docs/testing-v1.md) walks through a full test on your own posts, with what to check and what it should cost.

## Development

You need Node 24 (see `.nvmrc`), Composer, and Docker for `wp-env`.

```bash
npm install
composer install
npm run build
```

Run a local site:

- `npm run env:start` starts WordPress in Docker with `wp-env`, with the plugin active. `npm run env:stop` stops it.
- `npm run playground` starts WordPress Playground without Docker, with your working copy mounted and the small demo blog, already read, on a Garden page. It uses the e2e test provider, which counts fixture words instead of calling an API, so no key is needed.
- `npm run playground:real` starts Playground with Ollama or your own OpenAI and Anthropic keys, and can import your own posts or the demo blog. See [docs/testing-v1.md](docs/testing-v1.md).

Take screenshots of every screen and state, in three locales and two widths, without Docker:

```bash
npm run screenshots
```

Run the checks (most need `npm run env:start` first):

```bash
composer lint            # PHPCS with WordPress Coding Standards
composer analyse         # PHPStan
npm run test:php         # PHPUnit, single site
npm run test:php:multisite
npm run lint:js && npm run lint:css && npm run check:native
npm run test:js          # Jest
npm run test:e2e         # Playwright, with axe
```

CI runs all of these on every pull request, plus Plugin Check, a coverage gate, the POT check, pa11y-ci and a Playground preview link. Pull requests from this repository, not from forks, also get the screenshot comparison, and those labelled `theme-gallery` get the Garden page in ten themes.

## Documentation

- [CONTRIBUTING.md](CONTRIBUTING.md): how work flows, commit style, the rules for code, tests, i18n and accessibility. Read it before opening an issue or a pull request.
- [docs/handover.md](docs/handover.md): the design record and the principles nothing may break.
- [docs/testing-v1.md](docs/testing-v1.md): testing v1 on your own posts.
- [docs/ux-review-v1.md](docs/ux-review-v1.md): the v1 review of the admin experience.
- [docs/quality-report-v1.md](docs/quality-report-v1.md): how good the suggestions are on a realistic set of posts.
- [docs/vision.md](docs/vision.md): why the post-v1 pillars came before the first release.
- [docs/hooks.md](docs/hooks.md): the actions and filters other code can use.
- [docs/demo-content.md](docs/demo-content.md): the public-domain demo blog and how it is built.

## Status and roadmap

Version 0.1.0, not yet released. v1 ("germination mode") and the post-v1 work are built: the Prompt studio, More like this, orphans, hubs, cross-pollination and the reader blocks. All of it works from your own posts and their approved comments only. v1 has been through security, quality and UX reviews.

v2 ("reader mode") reads where readers go and what they ask for, once a site has enough visits. It will add suggestions from reader paths and search, a prune list, a refresh list and hubs drawn from reader paths, each still approved by you.

## Licence

GPL-2.0-or-later. See [LICENSE](LICENSE).
