# Release checklist

How to cut a release of Undergrowth, and how to submit it to the WordPress.org plugin directory the first time. Work through it in order. Each step that changes files goes through a pull request like any other change: issue, branch, CodeRabbit, green checks.

The maintainer decides when to release, tags, and submits. Nothing in CI does any of those on its own.

## 1. Before the first release only

These are one-off decisions. Settle them before 0.1.0.

- [ ] **Contributors.** `Contributors:` in `readme.txt` must list WordPress.org usernames, case-sensitive. `novaclaw` is not a WordPress.org account today (its profile page is a 404), so the directory would show no profile for it. Put the owner's WordPress.org username first, and add `novaclaw` only once that account exists.
- [ ] **Author.** `Author:` in `undergrowth.php` is shown on the directory page and in wp-admin. Decide whether it stays `Novaclaw`, and add an `Author URI:` if wanted.
- [ ] **Branding.** Everything in `.wordpress-org/` except the screenshots is a placeholder: `icon.svg`, `icon-128x128.png`, `icon-256x256.png`, `banner-772x250.png` and `banner-1544x500.png`. Replace them with the real design. The directory reads only PNG or JPG banners; the banner SVGs are sources and are not shown. An `icon.svg` needs the PNG icons beside it as a fallback. See [Plugin assets](https://developer.wordpress.org/plugins/wordpress-org/plugin-assets/) for sizes and limits: 1 MB for an icon, 4 MB for a banner, 10 MB for a screenshot.
- [ ] **Readme size.** The readme standard warns that a `readme.txt` over 10 KB may cause errors. Ours is about 21 KB, mostly the FAQ's per-post file example and the Unreleased changelog. Before submitting, move the long developer detail to the GitHub repository and link to it, and keep the changelog short (see step 3).
- [ ] **Slug.** The directory assigns the slug from the plugin name on submission, and it cannot be changed afterwards. `undergrowth` is what the deploy workflow and the text domain expect. If the reviewers assign another, change `SLUG` in `.github/workflows/deploy.yml`; the text domain has to match the slug, so every string would change too.

## 2. Bump the version

Use [semantic versioning](https://semver.org/): `MAJOR.MINOR.PATCH`, numbers and dots only. The version appears in four places, and they must all match:

- [ ] `undergrowth.php`: the `Version:` header.
- [ ] `undergrowth.php`: `define( 'UNDERGROWTH_VERSION', ... )`.
- [ ] `readme.txt`: `Stable tag:`. `test_readme_headers_match_plugin_header` fails if it differs from `Version:`.
- [ ] `src/blocks/growth-stage/block.json`: `"version"`. It busts the block's asset cache.

Then check the other readme headers:

- [ ] `Tested up to:` is the latest major WordPress version you have tested on, for example `7.1`, never a patch release such as `7.1.3`. Check the current release at [api.wordpress.org](https://api.wordpress.org/core/version-check/1.7/).
- [ ] `Requires at least:` and `Requires PHP:` still match `undergrowth.php`. The same test checks this.

## 3. Fold the changelog

- [ ] Run `npm run changelog`. It appends every fragment in `changelog/` under `= Unreleased =` in `readme.txt`, in issue order, and deletes the fragments.
- [ ] Rename `= Unreleased =` to the version, for example `= 0.1.0 =`, and add a fresh empty `= Unreleased =` above it: the script refuses to run without one.
- [ ] For 0.1.0, consider replacing the development history with a single line, `* First release.`: the history describes steps no user ever installed, and it is the bulk of the readme's size. Keep the full list in the GitHub release notes instead.
- [ ] Add an `== Upgrade Notice ==` entry from 0.2.0 on, when there is something an existing user should know before updating.

## 4. Regenerate the POT

- [ ] Run `npm run build`, then `npm run i18n:pot`. The build comes first so the block's strings are in the POT.
- [ ] Check the header's `Report-Msgid-Bugs-To` has not picked up a worktree folder name, and that `Project-Id-Version` names the new version.
- [ ] The i18n workflow fails if the committed POT is out of date, so this lands in the same pull request as the version bump.

## 5. Check the release pull request

Open one pull request with steps 2 to 4, labelled `no-changelog`. Before merging:

- [ ] Every check is green, including **Plugin Check**, which runs WordPress.org's own checker against the copy that would ship. It must show no errors. The `AIProvider.DirectIntegration` warnings are expected and justified in code (see the handover, section 6); mention them in the submission.
- [ ] Paste `readme.txt` into the [readme validator](https://wordpress.org/plugins/developers/readme-validator/) and fix anything it reports.
- [ ] Open the **Preview in WordPress Playground** link and click through Posts > Undergrowth, Settings > Undergrowth and the Dashboard.
- [ ] Read the **Screenshots** comment. If a screen in `.wordpress-org/` has changed, retake it: `npm run screenshots`, then copy the `en_US--1280` shot over the matching `screenshot-N.png` (the captions in `readme.txt` say which screen each one is).

## 6. Build and check the zip

Every push to `main` and every tag runs the **Build** workflow, which uploads `undergrowth.zip`. It is built from the same `.distignore` copy that Plugin Check checks.

- [ ] Download the zip from the Build run on the merged release commit.
- [ ] Check its `unzip -l` output in the run log: one top-level `undergrowth/` folder holding `undergrowth.php`, `uninstall.php`, `readme.txt`, `LICENSE`, `src/`, `build/` and `languages/`, and nothing from `.wordpress-org/`, `tests/`, `docs/`, `node_modules/` or `vendor/`.
- [ ] Install it on a clean site (Plugins > Add New > Upload Plugin), activate it, save a key and check a post is read. Deactivate and delete it, and check `uninstall.php` removed its options and table.

## 7. Tag and release

- [ ] Tag the merged release commit on `main` with the bare version, no `v`: `git tag 0.1.0 && git push origin 0.1.0`. The deploy workflow checks the tag matches `Version:` and `Stable tag:`.
- [ ] The tag push runs **Build** again; attach its `undergrowth.zip` to a GitHub release named after the version, with the changelog lines as its notes.

## 8. Submit to the directory (first release only)

- [ ] Read the [detailed plugin guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/) once more. The ones that matter most here: 6 and 7 (external services, documented in the readme's FAQ with links to each service's terms and privacy policy), 8 (no code from outside the plugin) and 12 (at most 5 tags).
- [ ] Sign in to WordPress.org with the account that will own the plugin, turn on two-factor authentication (required for committers), and upload the zip at [Add your plugin](https://wordpress.org/plugins/developers/add/).
- [ ] Watch the email for the review team. Answer from the same account, and fix anything they raise through a normal pull request and a new zip. Reviews take days to weeks.
- [ ] On approval, the account gets an SVN repository at `https://plugins.svn.wordpress.org/undergrowth/`. The first commit to it is the deploy below.

## 9. Deploy to WordPress.org

`.github/workflows/deploy.yml` uses [10up/action-wordpress-plugin-deploy](https://github.com/10up/action-wordpress-plugin-deploy), pinned to 2.3.0 by commit. It builds the plugin, makes the `.distignore` copy, commits it to `trunk` and `tags/<version>`, and copies `.wordpress-org/` to the SVN `assets` folder. It is off until the plugin is approved.

- [ ] Add two repository secrets under Settings > Secrets and variables > Actions:
  - `SVN_USERNAME`: the WordPress.org username with commit access.
  - `SVN_PASSWORD`: that account's SVN password, set under Account & Security on its WordPress.org profile. It is not the login password.
- [ ] Run **Deploy to WordPress.org** from the Actions tab with the tag and **dry run** ticked. Read the log: the files it would add to `trunk` and `assets` should match the zip and `.wordpress-org/`.
- [ ] Run it again with dry run unticked.
- [ ] Check the directory page after a few minutes: version, screenshots and captions, icon, banner, and that "Download" gives the same files as the zip.
- [ ] Once a release has gone out this way, decide whether to deploy every tag automatically: uncomment the `push: tags` trigger at the top of the workflow.

## Between releases

- A change to `readme.txt` or `.wordpress-org/` alone, such as a new screenshot or a new `Tested up to:`, does not need a version. It reaches the directory with the next deploy, or straight away through 10up's separate [readme/assets update action](https://github.com/10up/action-wordpress-plugin-asset-update) if that is ever added.
- Changelog fragments keep accumulating in `changelog/` until the next release folds them.
