# Proposal: v2 reader mode

> **This is a proposal, not a decision.** Merging it decides nothing. Anna decides each open question in section 12, and only her answers go into `docs/handover.md`. Until then, nothing here is built and no build issue is filed. Nothing here is legal advice either: the privacy sections set out what the primary sources say so that the choices are informed. They are not an opinion on any particular site.

Drafted 2026-10-23 for #217. It reads with `docs/handover.md` open: section numbers like "handover 4" refer to that file.

## Contents

1. [What v2 adds, and what it must not change](#1-what-v2-adds-and-what-it-must-not-change)
2. [Where a principle might bend](#2-where-a-principle-might-bend)
3. [Scent collection](#3-scent-collection)
4. [Storage schema and bounds](#4-storage-schema-and-bounds)
5. [The statistical check](#5-the-statistical-check)
6. [Evergreen and Wilting become measurable](#6-evergreen-and-wilting-become-measurable)
7. [Hubs and fusion](#7-hubs-and-fusion)
8. [Prune, refresh and gap lists](#8-prune-refresh-and-gap-lists)
9. [The related-by-readers block (#97)](#9-the-related-by-readers-block-97)
10. [The WordPress AI Client path (#189 to #191)](#10-the-wordpress-ai-client-path-189-to-191)
11. [Build order](#11-build-order)
12. [Open questions for Anna](#12-open-questions-for-anna)
13. [Sources](#13-sources)

---

## 1. What v2 adds, and what it must not change

Reader mode gives each post's third field, **scent**, a value. Today `undergrowth/scent` returns `mode: germination, value: null` (`src/abilities.php`), and the per-post file has an empty `## scent` heading (`src/memory.php`). Reader mode fills both, and from scent it builds four things the handover names: the prune, refresh and gap lists, and fusion (hub proposals). It also makes the reader half of Evergreen measurable, makes Wilting possible, and gives #97's block a source.

Fixed points, from the handover, that every option below keeps:

- **Gate.** The meter (`src/meter.php`, views in the last 30 days from Jetpack Stats) is the visible milestone. Its line is whatever `undergrowth_meter_line()` returns: 1,000 by default, changed by `UNDERGROWTH_METER_LINE` or the `undergrowth_meter_line` filter. Reader mode reads that function and never the number itself. Crossing it never turns a proposal on by itself. A statistical check runs before each reader proposal (handover 4, "Switch line").
- **Reader data is data.** Comments, search queries, 404 paths and Search Console queries come from strangers. They never reach a model as instructions, are always escaped, and are assumed to contain prompt-injection attempts (principle 3).
- **Nothing reader-facing comes from a model.** Reader mode adds no model output for readers. Gap and refresh items may become one writing *question* for the author, under every rule in handover 5b.
- **The author approves every change.** Each list is a proposal list with Accept and Veto, and a veto is kept forever (principle 2). Pruning is proposed on a schedule and never automatic (principle 5).
- **The loop is fixed.** Thresholds and checks are constants in code, with filters as the escape hatch, as for `thresholds.php`. Nothing tunes itself from what the author accepts (principle 6).
- **No keyword research.** Search Console data is about the author's own pages and the readers who reached them. No volumes, no queries the site doesn't rank for, no trends (principle 8).

## 2. Where a principle might bend

These are the places where an option, including some of the recommended ones, comes close to a handover principle. Each needs Anna's explicit answer. The open questions in section 12 repeat them.

| # | Where | Principle | Why it might bend | What this proposal recommends |
|---|---|---|---|---|
| B1 | Related-by-readers block (section 9) | 2 and 7: every reader-visible link is human-approved | A block that refreshes its list from reader paths adds links nobody approved one by one. | The author approves each post's list, and approves it again when it changes (option 9B). The lighter option, where placing the block counts as approval, is set out and flagged. |
| B2 | Wilting (section 6) | 5 and 2: nothing is demoted without the author | Wilting is the first measured stage that goes *down*. The growth-stage badge block shows stages to readers. | Wilting is measured but is shown to readers only once the author confirms it (option 6.3B). Until then it appears only in the admin and on the refresh list. |
| B3 | Fusion's "Start a draft" (section 7) | 1: no generated content for readers | A draft page with a list of member links is structure Undergrowth chose, even though the author publishes it. | Offer it, with titles and links only and nothing else in the draft. Never publish it. Flagged for Anna. |
| B4 | Gap list embeddings (section 8.3) | 3: signals are data; and what leaves the site | Matching a query to the right post needs the query embedded. That sends reader-typed text to the embedding provider. | Off by default, with its own opt-in and its own settings notice. |
| B5 | Comments in writing prompts (section 3.7) | 3 and 5b | Handover 5b already lists comments as a prompt source. This means commenter text goes to Anthropic, which the settings notice does not say today. | Send the comment text only, never the name, email, URL or IP. The notice says that the text itself may hold personal data a commenter typed, and that it is sent as written. Prompts never quote a commenter: they paraphrase "readers ask", and quote only the author. |
| B6 | Search Console (section 3.6) | 8: no keyword research | Impressions are people who saw the site in Google but did not come. Using them reads demand, not readers. | Clicks only by default. |
| B7 | Search Console wording (section 3.6) | Handover 6, "Read tokens from Site Kit" | The recommended route calls Site Kit's own data endpoint and never touches its tokens. That is safer, but it changes the handover's wording. | Use Site Kit's endpoint. Anna confirms the wording change. |
| B8 | Front-end beacon (section 3.2) | Handover 7: "no tracking without opt-in" | Any script that reports reading behaviour is tracking in the ePrivacy sense, even with no cookie and no personal data stored (EDPB [S3] para 33, para 12). | Off until the owner turns it on, and gated on visitor consent by default (option 3.2B). Signals that need no script come first. |

No recommendation here breaks a principle outright. Where a recommendation is the stricter reading, the lighter option is still set out so Anna can choose it knowingly.

---

## 3. Scent collection

### 3.1 The legal frame in one page

Two laws apply, at different steps.

**ePrivacy Directive, Article 5(3)** [S1] covers *storing or gaining access to information in a visitor's device*, whether or not it is personal data. The EDPB's Guidelines 2/2023, adopted 7 October 2024 [S3], read it widely:

- JavaScript that "instructs the browser of the user to send asynchronous requests with the targeted information" is gaining access (para 33). A reading beacon is exactly that.
- Information produced locally (scroll depth, visible time) and then sent to a server is in scope too (paras 52–53).
- Pixels are in scope (para 51). An `<img>` beacon is no way out.
- The information "includes both non-personal data and personal data" (para 12), so storing only aggregates does not take a beacon outside 5(3).
- Applying 5(3) "does not systematically mean that consent needs to be collected". The exemptions still have to be assessed (para 56).

The exemptions depend on national law:

- **France.** The CNIL exempts audience measurement from consent when it is limited to the publisher's own site, produces anonymous statistics, isn't combined with other processing or shared, and keeps data no longer than 25 months [S5].
- **United Kingdom.** Since 5 February 2026, PECR Schedule A1 para 5 (inserted by the Data (Use and Access) Act 2025) exempts storage or access whose sole purpose is statistics about how the site is used, to improve it. It applies only when visitors get clear information and "a simple means of objecting", and haven't objected [S6]. Para 5(2) also says that collecting what the device emits automatically is not gaining access. The ICO reads the exemptions narrowly [S7].
- **Germany.** TDDDG § 25 allows access without consent only where it is strictly necessary for the service the visitor asked for [S8]. It has no analytics exemption, so a beacon needs consent. The Article 29 Working Party already held in 2012 that first-party analytics are not "strictly necessary", though low-risk [S4].

So there is no single "no banner needed" answer across the EU. Undergrowth can't decide it for a site. It can make the safe default easy and say plainly what each setting does.

**GDPR** [S2] applies to whatever is personal data. The beacon request carries an IP address and a user agent, which Undergrowth would read for a moment and never store. Search terms and 404 paths can contain names or email addresses. The principles that shape the design are data minimisation and storage limitation (Art. 5(1)(c) and (e)). Data that is truly anonymous once stored falls outside the GDPR (Recital 26), and the site owner is the controller.

**Signals that need no script** are a different case. Undergrowth instructs the visitor's device to do nothing for them, so Article 5(3) is arguably not engaged. They are 404s, site search, comments, the `Referer` header on an uncached page, Jetpack Stats (collected by Jetpack, not Undergrowth) and Search Console (collected by Google). The EDPB guidelines don't address the `Referer` header directly, and the UK's para 5(2) excludes automatically emitted information. The GDPR still applies to any personal data in them.

**WordPress.org guideline 7** says a plugin "may not contact external servers without explicit and authorized consent" and should document what it collects [S11]. A first-party beacon to the site's own REST API sends nothing off the site. Search Console through Site Kit is Site Kit's request, and the owner set it up. Query embeddings (B4) do leave the site, so they get their own opt-in.

### 3.2 How in-page signals are collected (dwell, scroll depth, exit position, next clicks)

These four need a script on the page, because the server cannot see them, or cannot see them past a page cache.

**Option 3.2A: no script at all.** Use only the signals in 3.3 to 3.7, and Jetpack Stats per-post views as the scent measure.
- For: nothing new on the front end, no 5(3) question, works with every cache.
- Against: no next-click paths, so no related-by-readers block, no link-click prune and no fusion from reader flow. "Scent" becomes popularity, not reading.

**Option 3.2B: a first-party beacon, off until the owner turns it on, and gated on visitor consent by default.** *(Recommended.)*
- The script, with no dependencies and under 2 KB, loads with `strategy: 'defer'` [S12] on single published, indexed, non-composted posts. It never loads for logged-in users who can edit posts, nor on previews or feeds.
- It sends one `navigator.sendBeacon()` [S13] POST per view to `/wp-json/undergrowth/v1/scent`, on the first `visibilitychange` to hidden. The body holds four fields:
  - the post ID;
  - a dwell bucket, counting visible time only;
  - a scroll-depth bucket, from `IntersectionObserver` sentinels in the post content at 25/50/75/100%, so there is no scroll handler;
  - the next same-site post, if the view ended on a click to one, with `via` set to `content` or `nav`;
  - the in-content links to the site's own posts that entered the viewport, at most 20, as **exposures** for the prune list's absence check (5C).
- Every target post ID in a beacon, for a next click or an exposure, must be a link the source post really has, or a navigation link. The server checks against a cached list per post, rebuilt on save. Anything else is dropped before any write, so forged targets cannot add path rows.
- Exit position is not a separate signal: it is the depth bucket of views with no next click.
- No cookie, no `localStorage`, no visitor or session ID, no timestamps finer than a week, and no IP or user agent stored. The server turns each beacon into counter increments (section 4) and forgets the request.
- Consent: when the WP Consent API is active [S9], the script sends only once `wp_has_consent( 'statistics-anonymous' )` is true, and listens for `wp_listen_for_consent_change`. The plugin declares itself through `wp_consent_api_registered_{plugin}`. Without the Consent API, the owner picks one of two modes on the settings page:
  - "Only with a consent tool". This is the default, and with no tool nothing is sent.
  - "Without asking, under an analytics exemption". The settings text names the CNIL and UK exemptions and the German exception, and says the choice is the owner's.
- Global Privacy Control (`navigator.globalPrivacyControl`) [S10] and `doNotTrack` are treated as an objection in both modes. That is also the UK's "simple means of objecting".
- For: the full set of reader signals, first-party, aggregate only, nothing on the device. Safe by default in every jurisdiction above.
- Against: it is a script, and needs a consent tool to collect anything in the strict mode. A site with no consent tool gets nothing until the owner chooses.

**Option 3.2C: the same beacon, on by default once the meter is crossed and the owner turns reader mode on, with no consent gate.** It relies on the exemptions in 3.1.
- For: the most data, with the least setup.
- Against: wrong by default in Germany, and anywhere else without an analytics exemption. It bends handover 7's "no tracking without opt-in" (B8). Not recommended.

**Server-side next clicks, without a script.** When a page is not served from a cache, the `Referer` header on the request for post B says the reader came from post A on the same host. This counts A → B with no script. It's worth keeping as a fallback inside 3.2A, but most sites behind a caching plugin or CDN never run PHP for cached pages, so it under-counts badly and unevenly. It can't stand alone.

**Front-end performance (3.2B):**
- One deferred script, under 2 KB.
- No layout reads on scroll, because `IntersectionObserver` does the work.
- One beacon per view, sent while the page is hiding, so it is off the critical path.

**Server performance (3.2B):**
- Each beacon boots WordPress for a REST request and runs one or two `INSERT … ON DUPLICATE KEY UPDATE` statements. For scale: at 100,000 views a month, about 2.3 beacons a minute on average.
- For large sites, a filter samples 1 in N views, and the counts are scaled back up when read.
- The upsert has to be checked against the SQLite Database Integration plugin used by Playground and the local test library.

**Caching-plugin compatibility (3.2B):**
- The script and its inline post ID are part of the cached HTML, which is correct per page.
- The endpoint is a POST, which page caches and CDNs don't cache.
- No nonce, because a nonce baked into cached HTML goes stale after 12 to 24 hours. The endpoint is public and write-only.
- It accepts only enumerated buckets for a published, indexed post, checked against a cached list of published IDs, so a bad request costs no post query.
- Before any write it checks two caps, and drops everything over them: 1,200 beacons a minute site-wide and 600 an hour per post. Both are constants with filters. The counters are kept apart from the weekly tables. They live in the object cache where there is a persistent one, and otherwise in transients keyed by the minute (`undergrowth_beacon_m_<minute>`) and by post and hour (`undergrowth_beacon_h_<post>_<hour>`), which expire after two minutes and two hours. Either way both caps hold before the write.
- It drops requests from bot user agents (read, never stored). That is a filter for honest bots only, because a user agent is the sender's choice.
- None of this stops a flood before PHP starts. Only the web server or CDN can, so the Help tab tells busy sites to rate-limit `/wp-json/undergrowth/v1/scent` there.
- Optionally, beacon views per post are capped at a multiple of Jetpack's views for the same post.
- Forged beacons can only nudge counts on real posts, and every result still goes through the statistical check and the author.
- "Delay JavaScript until interaction" settings (in WP Rocket and others) delay the script, so they under-count short visits. A Site Health test (`site_status_tests`) reports when the endpoint is unreachable, for example when a security plugin blocks anonymous REST requests, and when beacons stop arriving.

### 3.3 404s and typed URLs

- **How:** on `template_redirect` when `is_404()`, count the request path. Leave out the query string, which is where tokens and emails tend to be. 404 pages are usually not cached.
- **Minimisation:**
  - store the path only, cut to 200 characters;
  - drop paths that look like an email address or a long digit run;
  - drop known probe paths (`wp-login`, `.env`, `xmlrpc`, `.php` files), which are bots, not readers.
- **Shown** to the author only once a path has been seen at least 3 times in the retention window.
- **Use:** the gap list ("readers keep arriving at `/oca-storage`, which doesn't exist") and redirects the author may add themselves. Undergrowth never creates a redirect.
- **Options:**
  - Count every 404 (noisy).
  - Count only 404s whose `Referer` is the site itself or a search engine, which are broken links and real arrivals. *(Recommended.)*

### 3.4 Site search, and zero-result searches above all

- **How:** on the main search query, count the normalised term and whether it returned zero results. Normalising means lower case, trimmed, whitespace collapsed, and cut to 100 characters.
- **Minimisation:**
  - Strip email-like and number-like tokens before storing.
  - Store a hash for grouping and the text for display.
  - Show a term only at 3 or more searches in the window.
  - Let the author delete any term.
  - Treat the stored term as personal data anyway. Stripping tokens reduces it but cannot remove names or other details typed as words. Terms are kept only for the hard TTL in 3.8, so a term is deleted with its week's rows. An eraser request with a name or email deletes every term containing it (3.9). That match is best-effort, because terms are linked to no visitor.
- **Caching:** some full-page caches do cache `?s=` pages, and those searches are then lost. Site Health can say so.
- **Use:** handover 4 calls zero-result search "the strongest scent where site search exists". It feeds the gap list (section 8.3).
- **Jetpack Search sites:** Jetpack Search answers in JavaScript against WordPress.com, so the PHP search query may never run. A Jetpack Search adapter registered as a signal ability is a later step. Until it exists, the setting says so.

### 3.5 Jetpack Stats

The meter already needs Jetpack Stats. `WPCOM_Stats` also has `get_top_posts()`, `get_post_views( $post_id )`, `get_clicks()` and `get_search_terms()` [S14]. These are counts that Jetpack collected under its own terms, so reading them adds no collection.

- **Options:**
  - Use Jetpack per-post views as the view count everywhere.
  - Use them only as a cross-check and fallback. *(Recommended.)* The beacon's own views, where the beacon is on, line up with its dwell and depth buckets. Jetpack views fill in where the beacon is off or not consented.
- **Cost:** one API call per post doesn't scale. Read from `get_top_posts()` for the period, and call per post only for posts outside it, cached with the meter's daily transient pattern.

### 3.6 Search Console, through Site Kit

- **How:**
  - Site Kit registers a REST datapoint, `GET /wp-json/google-site-kit/v1/modules/search-console/data/searchanalytics`, with `startDate`, `endDate`, `dimensions` (`query,page`), `rowLimit` and an optional `url` [S15].
  - Undergrowth calls it in-process with `rest_do_request()`, so no HTTP request is made and Site Kit's tokens never leave Site Kit.
  - The route checks Site Kit's own capability (`googlesitekit_view_posts_insights`). A cron run has no user, so the call has to happen in an admin request by a user who has that capability.
- **Options:**
  - **3.6A: fetch from an admin request.** *(Recommended.)* Fetch at most once a week, when an editor with Site Kit access opens Posts › Undergrowth, after the page is sent, plus a "Fetch now" button. Store the result as weekly aggregates.
  - **3.6B: from cron, as Site Kit's owner user.** This means calling `wp_set_current_user()` with the owner ID. It runs unattended, but it impersonates a user, which is a pattern worth avoiding.
  - **3.6C: read Site Kit's OAuth tokens and call Google directly.** This is the handover's wording. It couples to Site Kit's encrypted storage and makes Undergrowth itself contact Google. Not recommended (B7).
- **Minimisation:**
  - Clicks only (B6), matching what readers did.
  - Queries only for pages that are the site's own indexed posts.
  - Google already withholds rare queries, and has a 2 to 3 day lag and 16 months of history [S16].
  - The stored copy follows the same retention as the rest (section 4).
- **Risk:** the datapoint is Site Kit's internal REST API, not a documented public one. Wrap it in an adapter registered as a signal ability (handover 6, "Signal sources are abilities"), test it against Site Kit's real response shape, and show "Search Console: not answering" rather than failing quietly when it changes.

### 3.7 Comments, and contact-form questions

- **Comments** are already on the site, and core's own exporter and eraser cover them.
  - **Use:** approved comments that ask a question become the post's **lacks**. Keep it simple: a sentence ending in `?`, in the post's language.
  - **Storage:** Undergrowth stores comment IDs only, never the text, and reads the text when it needs it. When core's eraser anonymises a comment, Undergrowth's next read sees the anonymised version.
  - **Retention:** a comment counts as lacks only within the retention window (3.8) from its date. The per-post file is rewritten on each cycle, so IDs that have expired or been erased drop out of `## lacks` then. The eraser also rewrites the files it touched at once.
  - **Privacy tools:** Undergrowth also registers an eraser on `wp_privacy_personal_data_erasers` [S17]. Given an email, it drops that person's comment IDs from Undergrowth's records and discards any pending prompt built from them. A matching exporter on `wp_privacy_personal_data_exporters` [S18] lists which of their comments Undergrowth used as a signal.
  - **Sent to Anthropic for a prompt:** comment text only, which may itself hold personal data a commenter typed, and the settings notice says so (B5). It travels under the contract `src/prompts.php` already keeps for untrusted data: delimited text in the user message, below a system prompt that says it is data and never instructions, and the reply must be one question to the author. A refused or cut-off reply is never shown.
- **Contact-form messages** are private messages to the owner, not published text, and every form plugin stores them differently (Jetpack's `feedback` posts, WPForms entries, or nowhere for Contact Form 7).
  - **Options:**
    - Leave them out of v2.0. *(Recommended.)*
    - Read Jetpack Forms only.
    - Accept them from other plugins through a signal ability, with a separate owner opt-in.

### 3.8 Retention and decay

The handover's nature model calls scent "pheromone trails" with a TTL. That needs two mechanisms:

- **A hard TTL.** Rows older than the retention window are deleted by a daily cron.
  - **Options:**
    - 13 weeks. Enough for the 60-day rules, but no seasonal view.
    - 26 weeks. *(Recommended.)* Two full 60-day windows plus slack, and the split-half check in section 5.
    - 13 months. Year-on-year comparison, at the CNIL's tracker-lifetime figure and well inside its 25-month retention cap [S5].
- **Soft decay for rankings.** The related-by-readers order and the fusion density use weekly buckets weighted by a half-life, so old paths fade before they are deleted.
  - **Options:** 4 weeks, or 8 weeks *(recommended)*. A constant, never tuned by usage.
- **Fixed windows for stages.** Evergreen and Wilting use the handover's plain 60-day window, not decay, so the rule reads the same as it is written.

### 3.9 WordPress privacy tools

- **Privacy policy text.** Call `wp_add_privacy_policy_content()` on `admin_init` [S19][S20]. The suggested text depends on the settings: which signals are on, that no cookie or identifier is used, what is stored and for how long, the consent mode, and that Search Console data comes from Site Kit and Google. The text changes as settings change, so it's assembled from the current settings.
- **Exporters and erasers.**
  - The stored scent counters hold no identifier, so there is nothing to export or erase by email, and the policy text says why.
  - Comment-derived records get an exporter and an eraser (3.7).
  - Search terms and 404 paths are minimised at intake (3.3, 3.4), but minimising is not anonymising. An eraser on `wp_privacy_personal_data_erasers` deletes every stored term or path containing the requester's email or the name on their account. An exporter lists them. Both are best-effort: terms and paths are linked to no visitor, so a match on text cannot promise to find everything a person typed, and the privacy policy text says so. The author can also delete any single term.
- **Personal data in the per-post file.** The `## scent` and `## lacks` sections hold counts and comment IDs, never commenter names or text, so the portable file never carries a stranger's words.

### 3.10 Recommendation for scent collection, in short

Phase it. First the signals that need no script and add nothing to the front end: 404s, zero-result site search, comments as lacks, Jetpack Stats views and Search Console clicks through Site Kit. Then the beacon (3.2B) as its own setting, off by default, consent-gated by default, with GPC respected. Each signal has its own toggle under one "Learn from readers" switch, which appears only once the meter is crossed.

---

## 4. Storage schema and bounds

Three new tables, created by `undergrowth_install_schema()` beside the vectors and links tables. Each counts by **ISO week** (stored as that Monday's date, UTC), with no finer time anywhere.

```sql
-- One row per post, week, signal and bucket.
{prefix}undergrowth_scent (
  post_id  bigint unsigned NOT NULL,
  week     date            NOT NULL,
  signal   varchar(20)     NOT NULL, -- view | dwell | depth | exit_depth | jetpack_view
  bucket   tinyint unsigned NOT NULL DEFAULT 0, -- 0 for view; 0..4 for dwell, depth, exit_depth
  hits     int unsigned    NOT NULL DEFAULT 0,
  PRIMARY KEY (post_id, week, signal, bucket),
  KEY week (week)
)

-- One row per next click from a post to another post, by week and by where the link was.
-- via = seen counts exposures: views in which an in-content link to to_id entered the viewport.
{prefix}undergrowth_paths (
  from_id  bigint unsigned NOT NULL,
  to_id    bigint unsigned NOT NULL, -- 0 = all other targets, folded
  week     date            NOT NULL,
  via      varchar(10)     NOT NULL, -- content | nav | other | seen
  hits     int unsigned    NOT NULL DEFAULT 0,
  PRIMARY KEY (from_id, to_id, week, via),
  KEY to_id (to_id),
  KEY week (week)
)

-- One row per reader-typed term, by kind, landing post and week.
{prefix}undergrowth_terms (
  kind       varchar(12)   NOT NULL, -- search | search_zero | notfound | gsc
  term_hash  char(64)      NOT NULL, -- sha256 of the normalised term
  term       varchar(200)  NOT NULL, -- minimised text, for display only
  landing_id bigint unsigned NOT NULL DEFAULT 0, -- gsc page as a post ID; 0 otherwise
  week       date          NOT NULL,
  hits       int unsigned  NOT NULL DEFAULT 0, -- searches, 404s, or GSC clicks
  PRIMARY KEY (kind, term_hash, landing_id, week),
  KEY week (week)
)
```

The bucket edges are constants:

- **Dwell:** under 10 s, 10–30 s, 30–60 s, 1–3 min, over 3 min.
- **Depth:** under 25%, then 25, 50, 75 and 100%.

**Bounds**, each enforced in code:

- **Scent.** At most 1 + 5 + 5 + 5 + 1 = 17 rows per post per week. With 1,000 posts and 26 weeks, the ceiling is 442,000 rows, about 25 MB with indexes. Real sites sit far below it, because most posts don't have every bucket every week.
- **Paths.** At the weekly close, each `from_id` keeps its top 20 targets by hits, and the rest fold into `to_id = 0`. Ceiling: 1,000 × 21 × 4 × 26 ≈ 2.2 million rows in theory. In practice next clicks are sparse, `seen` rows exist only for links the post really has, and the fold keeps it to a few targets per post.
- **Terms.** At most 500 terms per kind per week. Past that cap only existing terms are incremented. Terms are shown only at 3 or more hits in the window.
- **Retention.** A daily cron deletes everything older than the window (3.8). The tables are dropped on uninstall, like the others in `uninstall.php`.
- **No raw log at any point.** A beacon or hook becomes upserts and is gone.

**Read side:**

- `undergrowth/scent` keeps its current shape. `value` stays a number or null: the engaged reads over 60 days (6.1B), or null in germination mode or when the check fails. New optional properties sit beside it: `views`, `next` (post IDs), `check` (passes, and why not) and `median`. Callers that read only `mode` and `value` see no change. The output schema in `src/abilities.php` gains the new properties, not a new type for `value`. The same shape holds at cluster and site scale (principle 4), and each property is rebuilt from the members' weekly rows, not combined from their results:
  - `value` and `views` are sums;
  - `next` re-ranks the summed path rows to posts outside the set;
  - `check` runs again on the summed counts;
  - `median` is always the site median of eligible posts, the reference every scale is compared with.
- The `## scent` section of the per-post file is written at the monthly cycle, as a few summary lines, never on every beacon.
- `wp undergrowth scent [<post>]` prints the same numbers and the check's result, as `wp undergrowth scores` does for thresholds.

**Options for the schema:**
- **4A: the three tables above.** *(Recommended.)* Indexed reads for the monthly cycle and the block, bounded, and easy to drop.
- **4B: post meta and options only.** No schema change, but unbounded meta rows, slow cross-post medians and option bloat. Not recommended.
- **4C: one generic table** keyed by (scale, id, signal, key, week). Fewer tables, but paths and terms need different keys and indexes, so the queries become awkward.

---

## 5. The statistical check

Handover 4: "A statistical check before each reader proposal (enough visits on the top pages for proposals to repeat)." Reader proposals come in three shapes, and each needs a different question answered:

| Proposal shape | Example | The question the check must answer |
|---|---|---|
| A share | "Readers of A mostly go on to B" (block, fusion) | Is B's share of A's next clicks clearly above the others? |
| An absence | "Nobody clicks this link" (prune) | Have enough readers seen the link to say "nobody", not "nobody yet"? |
| A comparison | "This post's scent is below the site median" (Wilting) | Is it below with enough readers, and in both halves of the window? |

**Option 5A: fixed minimum counts.** For example, at least 100 views of the page in the window and at least 20 events behind the proposal.
- For: simple to explain.
- Against: not a test of repetition. 20 next clicks split 11 to 9 would pass.

**Option 5B: a split-half replication test.** Split the window into odd and even ISO weeks. The proposal must come out the same in both halves: the same top target, below the median in both, zero clicks in both.
- For: it is literally "proposals repeat", it needs no distribution assumptions, and the weekly buckets already support it.
- Against: on its own it lets a small sample through when it agrees by chance.

**Option 5C: interval bounds, plus split-half for rankings.** *(Recommended.)*
- **Shares** use a 95% Wilson score interval. B is shown as a top next post only if its lower bound is above the upper bound of the next candidate's share. Otherwise, if its lower bound is at least 5%, it shows in the order the data supports, ties included.
- **Absences** use the "rule of three": zero events in *n* chances gives a 95% upper bound of about 3/*n*. For a link, a chance is an **exposure**, a view in which the link entered the viewport, not a view of the page. A link below where most readers stop has had no chance to be clicked. So "nobody clicks this link" needs at least 300 exposures in the cycle (an upper bound of 1%), at least 100 of them in each half, and zero clicks in both halves. These are observed counts, before any sampling scale-up: with 1-in-N sampling the rule of three applies to the sample, so one observed exposure never stands for N. Page views alone never justify a link prune. Without the beacon there are no exposures, so no link is proposed for pruning.
- **Comparisons** need at least 30 views of the post in each half, and the result has to agree in both halves.
- **Everything else** says "not enough readers to say yet" and proposes nothing.
- For: each claim is backed by the kind of evidence it needs, and every number is a constant the Help tab can print.
- Against: more to explain, and more to test.

Under 5C, all constants (95%, 300, 30, 5%, 20 targets) live in one file (`src/check.php`), each with its own filter, as `thresholds.php` does. `wp undergrowth check <post>` prints which proposals pass, which fail, and why. The meter's Help tab already promises this rule ("the pages it draws on need enough visits"), and the text stays true.

---

## 6. Evergreen and Wilting become measurable

### 6.1 What "scent" is, as one number

Handover 5 says "scent above site median for 60 days". That needs a single measure per post.

- **6.1A: views.** Jetpack or beacon views over 60 days.
  - For: available without the beacon.
  - Against: this is popularity, not reading. A post that ranks for a common query and gets bounced off would be Evergreen.
- **6.1B: engaged reads.** *(Recommended.)* Views that reached 75% depth or had 30 seconds of visible time, over 60 days. It falls back to views, and says so, when the beacon is off.
  - For: it measures reading, which is what "scent" means in the handover's nature model.
  - Against: it needs the beacon for its best form.
- **6.1C: flow.** Engaged reads plus next clicks in and out.
  - For: the most "mycorrhizal" measure.
  - Against: it rewards posts with many links twice, because the structural rule already counts links.

**Eligible posts:** published at least 60 days, indexed, not composted. The median is computed over eligible posts only. Composted posts are left out of the health score, as they are today.

### 6.2 Evergreen

The structural half of the rule needs hubs. Hubs need no reader data if the author marks them (section 7), so #84 can ship **before** reader mode. In germination mode, Evergreen is then measurable on structure alone, and reader mode adds the scent condition.

- **Germination mode:**
  - in a cluster;
  - 3 or more accepted links in from indexed posts;
  - at least one of those links from a hub.
- **Reader mode:** the same, plus engaged reads above the eligible median over the last 60 days, passing the comparison check in 5C. That means above the median in both 30-day halves.

### 6.3 Wilting

The rule: the post was Evergreen, and its engaged reads have been below the median for 60 days, passing the check. It is measured on the monthly cycle only. This is the first measured downward move (B2), and the growth-stage badge block shows stages to readers.

- **6.3A: a stage like any other.** The cycle sets Wilting, and the badge shows it.
  - For: simple.
  - Against: a reader-visible label changes without the author, and the first demotion in the plugin happens on its own.
- **6.3B: measured, then confirmed.** *(Recommended.)* The cycle marks the post as wilting, and it goes on the refresh list. The stored stage, and so the badge, stays Evergreen until the author confirms Wilting from the list, or pins Evergreen. A veto ("it's fine") is kept, and the post is not proposed again for that cycle. If its scent recovers, the mark clears.
  - For: no reader-facing change without the author, consistent with principles 2 and 5.
  - Against: one more thing to click.
- **6.3C: admin-only.** Wilting is never a stored stage, only a refresh-list flag.
  - For: the simplest privacy and principle story.
  - Against: it drops a stage the handover's table defines.

**Coming back:** a Wilting post that is above the median for 60 days again returns to Evergreen on the cycle. That is a move up, so no confirmation is needed. Pins and composting stay author actions that take effect immediately, as today.

---

## 7. Hubs and fusion

### 7.1 What a hub is

Handover 3 describes hubs as "fruiting bodies": a hub page, or Map of Content, grown only where the network is dense.

- **7.1A: the author marks any post or page as a hub.** *(Recommended.)* A post meta flag `undergrowth_hub` (true or false), set from a checkbox in the editor's document panel and as a row action on Posts › Undergrowth.
  - **A hub's cluster** is computed on each cycle: the cluster most of its accepted or hand-written links point into. That matters because cluster IDs change every month, and a hub tied to a cluster ID would break.
  - For: works in germination mode, unblocks #84 now, and only the author decides what a hub is.
  - Against: an author who never marks a hub never gets Evergreen.
- **7.1B: detected automatically.** Any page linking to most of a cluster's members counts as its hub.
  - For: zero effort.
  - Against: a tag archive or a long "favourites" post would count, and it makes a stage depend on structure the author didn't mean as a hub.
- **7.1C: 7.1A, plus a germination-mode suggestion from 7.1B.** "This page links to most of your oca posts. Mark it as their hub?" Accept marks it, and a veto is kept.
  - For: the author still decides, and finds out the feature exists.
  - Against: one more proposal type.

The recommendation is 7.1A first, then 7.1C as a small follow-up.

### 7.2 Fusion: proposing a hub where readers trade heavily

- **The rule.** A cluster is dense when the share of its members' next clicks that stay inside the cluster is high, and at least 3 distinct member pairs carry clicks, with both passing 5C over the window. It qualifies when it has no hub. The proposal then reads: "Readers move between these 5 posts a lot. Would a page that ties them together help?"
- **Accept options:**
  - **7.2A: Accept only marks the proposal done.** The author writes the hub when, and if, they choose.
  - **7.2B: Accept opens "Start a draft".** *(Recommended, flagged B3.)* This creates a **draft** page with an empty title and a core List block of the member posts' titles and links. Nothing else goes in it: no headings, no text. It is never published by the plugin.
  - **7.2C: Accept creates a draft with a pattern** (headings, placeholders). Not recommended: placeholder copy is generated structure the author would be tempted to keep.
- **Veto** is kept for that cluster's member set. The proposal comes back only if the set changes by more than half.

---

## 8. Prune, refresh and gap lists

These are the three lists the handover's admin screen was designed for (handover 6, "Channels"). Each is a tab or a section on Posts › Undergrowth, proposed monthly, with Accept and Veto. Composted posts are never listed. Discord can carry them later through the existing channel code.

### 8.1 Prune list

Handover 4 names three kinds:

- **Links with no clicks.** An in-content link from A to B with zero `content` clicks, passing the absence check (5C: 300 or more exposures of the link, zero clicks in both halves).
  - **Accept** removes the `<a>` and keeps its text. It's the inverse of the link Accept in `accept.php`, through the same revision-safe path.
  - **Options for which links:**
    - only links Undergrowth proposed and the author accepted;
    - also links the author wrote by hand. *(Recommended.)* It is the author's own site, and a dead link is dead whoever added it. Accept still needs the author.
- **Posts with no readers.** No views in the cycle where the absence check passes for the site, meaning the site has enough traffic that zero means zero.
  - **Accept is not offered.** The list says what the author can do (compost, merge, redirect or noindex) and links to where each is done. The handover keeps these as author actions, and Undergrowth never deletes, redirects or noindexes.
- **Nav items nobody uses.** Menu or Navigation-block links with zero `nav` clicks, passing the absence check on site views.
  - **Accept is not offered.** Editing menus and navigation blocks from a plugin is fragile. The list links to the Site Editor or Menus screen instead.
  - **Options:**
    - list only *(recommended)*;
    - offer removal for classic menus only.

### 8.2 Refresh list

- **Wilting posts** (6.3), with Confirm and Veto.
- **Fading Evergreen posts.** Engaged reads down by at least a third against the previous 60 days, passing the check, but not yet below the median. Listed only, with no Accept, because there's nothing to apply.
- **Refreshing is the author's work.** Undergrowth may turn one refresh item into the one writing *question* the Dashboard shows (handover 5b's source "a wilting post"), never a suggested edit.

### 8.3 Gap list

Handover 4: "Queries landing on the wrong post. The owner writes the post or does not." Sources:

- zero-result site searches (3.4);
- 404 paths (3.3);
- Search Console clicks where the landing post doesn't match the query (3.6).

**"Doesn't match" needs a comparison between the query and the posts.**

- **8.3A: lexical.** *(Recommended default.)* The query's words against the landing post's title and headings, in PHP. Nothing is sent anywhere.
  - For: free and private.
  - Against: crude, and blind to synonyms and other languages.
- **8.3B: embedded.** The query goes through `undergrowth_embed()`, as a query vector with the same provider, model and language identity, never stored in the vectors table. It is compared with the landing post and with all posts.
  - For: "readers searching *oca storage* land on your planting post, but your *winter storage* post is closer". It also catches true gaps, where no post is close.
  - Against: it sends reader-typed text to the embedding provider (B4).
  - **Recommended as an opt-in** with its own settings notice.
- **8.3C: both.** Lexical by default, embedded when the owner opts in. *(This is the recommendation as a whole.)*

**What the gap list offers:**
- **"Link from here".** When a closer post exists, this becomes a link proposal through the existing self-trade path, with the author's own sentence, so it is still a link the author approves.
- **"Write about it".** It may become the one writing question.
- **Never a title, an outline or a draft** (handover 5b).

---

## 9. The related-by-readers block (#97)

**Source:** the `paths` table, `via = content` or `nav`, from the current post, with 8-week decay (3.8). It is passed through 5C's share check and stops at 3 to 5 posts. Composted, unpublished, password-protected and unindexed posts are excluded (#97's acceptance criteria). The output is titles and permalinks only.

**Rendering:** a dynamic block, server-rendered from the accepted list in post meta (`undergrowth_readers_next`). A weekly cron writes the readers' current list to a separate key (`undergrowth_readers_next_proposed`) and never touches the accepted one. Only Accept copies proposed to accepted. A page view never runs the query, and the block never reads the proposed key. Cached pages show whatever the cache holds, which is fine for a weekly list. With reader mode off, or the check failing, it renders nothing, and the editor says why.

**Approval (B1).** These are reader-visible links.

- **9A: placing the block is the approval.** The list updates itself weekly, like core's Latest Posts block. The author can veto a post from any list, and the veto is kept.
  - For: no upkeep.
  - Against: links appear that no human saw. That bends principles 2 and 7.
- **9B: the author approves each post's list.** *(Recommended.)* When the readers' list for a post changes, it shows as one proposal on Posts › Undergrowth, with the old and new lists side by side, and Accept or Veto. The block shows the last accepted list. Each post appears in the block only after the author has seen it there.
  - For: every reader-visible link is approved, and the principles hold.
  - Against: a proposal every time readers' habits shift. The share check and decay keep that rare.
- **9C: the list is frozen when the block is inserted.** It changes only when the author presses "Update from readers" in the block's sidebar.
  - For: explicit.
  - Against: it goes stale quietly, so the block stops meaning "by readers".

**Never a stand-in.** #97's out-of-scope rule holds: without reader paths there is no list. No embedding similarity is used in place of it.

---

## 10. The WordPress AI Client path (#189 to #191)

The #119 decision (handover 6) already fixes the rules: opt-in, the settings page says what is sent, identity from constants, fallback order filter → AI Client → direct key. The questions here are where it fits in v2, and what reader mode changes in it.

**What reader mode changes:**

- If the owner opts into embedded gap matching (8.3B), query text goes through `undergrowth_embed()`, and so through whichever path the owner chose.
- If comments feed prompts (3.7), comment text goes through the prompt path.
- The settings copy in #191 has to list both, for both paths, beside the existing lists.

That is a change to #191's scope, and it's noted there when the build issues are filed.

**Where it fits:**

- **10A: before reader mode.**
  - For: lowers the key-pasting hurdle for everyone first.
  - Against: #189 is blocked on WordPress 7.2, scheduled for 2026-12-08 (handover 6), so reader mode would wait on a release date.
- **10B: after reader mode.**
  - For: one settings rewrite at the end.
  - Against: the hurdle stays for longer, and #191's copy is written twice anyway.
- **10C: a parallel track.** *(Recommended.)* #190 (prompts, WordPress 7.0+) and #191 (copy) any time, since neither depends on reader data. #189 once 7.2 is released. Each step that adds something sent (8.3B, comments in prompts) updates the copy that #191 introduced.

---

## 11. Build order

Each step is one issue and one PR, under the usual rules: test first, changelog fragment, CodeRabbit. They are numbered for v2. Steps marked **(no reader data)** could ship in a v1.x release.

| Step | Issue title (draft) | Depends on | Notes |
|---|---|---|---|
| 0 | Docs: Record the reader mode decisions in the handover | Anna's answers | Turns this proposal's answers into handover text and files steps 1 to 17. |
| 1 | Stages: Let the author mark a post as a hub | — | **(no reader data)** 7.1A. Post meta, editor checkbox, row action, per-post file line. |
| 2 | Stages: Measure Evergreen once hubs exist (#84) | 1 | **(no reader data)** The structural Evergreen rule. Closes #84. |
| 3 | Settings: Add the "Learn from readers" switch | — | Appears only when the meter is crossed. Holds the per-signal toggles, consent mode and privacy policy text. Collects nothing yet. |
| 4 | Storage: Add the scent, paths and terms tables | 3 | Schema, upserts (checked on SQLite), weekly fold, retention cron, uninstall, `wp undergrowth scent`. |
| 5 | Scent: Count 404s and zero-result searches | 4 | Server-side, minimised at intake, Site Health note for cached search pages. |
| 6 | Scent: Read per-post views from Jetpack Stats | 4 | 3.5, batched through top posts. |
| 7 | Scent: Read Search Console clicks through Site Kit | 4 | 3.6A, as a signal ability, clicks only. |
| 8 | Scent: Treat questions in comments as lacks | 4 | IDs only, plus the eraser and exporter. |
| 9 | Scent: Add the reading beacon | 4 | 3.2B: the script, REST route, consent API, GPC, caps, sampling filter, Site Health test. Includes the e2e test on a cached page. |
| 10 | Scent: Add the statistical check | 4 | 5C in `src/check.php`, `wp undergrowth check`, Help tab text. Can be built alongside 5 to 9. Tests for the absence check: scaled counts that reach 300 with too few observed exposures, and exposures all in one half, both fail. |
| 11 | Abilities: Return reader scent from undergrowth/scent | 6 or 9, 10 | Fills `value`, writes the per-post `## scent` section on the cycle. |
| 12 | Stages: Add scent to Evergreen and measure Wilting | 2, 11 | 6.2 and 6.3B, refresh-list confirmation. |
| 13 | Admin: Add the refresh list | 12 | 8.2. |
| 14 | Admin: Add the prune list | 9, 10 | 8.1, with unlink Accept. |
| 15 | Admin: Add the gap list | 5, 7, 10 | 8.3A, with "Link from here" through self-trade. 8.3B as its own follow-up issue. |
| 16 | Map: Propose a hub where readers trade heavily | 1, 9, 10 | 7.2B. |
| 17 | Blocks: List the posts readers go on to read next (#97) | 9, 10 | 9B. Closes #97. |
| 18 | Prompts: Draw questions from reader signals | 8, 13, 15 | Comment questions, wilting posts and gaps as 5b sources, B5. Includes a test with adversarial comment text (instructions, fake delimiters) that checks it stays data and the reply is still one question to the author. |
| — | #190, #191, then #189 after WordPress 7.2 | — | The parallel track (10C). |

Steps 5 to 8 are independent of each other and can run in parallel sessions. So can step 10 beside any of them.

---

## 12. Open questions for Anna

Each can be answered in a sentence. The recommendation is in brackets.

1. Should reader mode collect in-page signals (dwell, scroll, next clicks) through a script at all, or only signals that need no script? [Both, in two phases. Script signals come second and have their own switch.]
2. When the beacon is on and no consent tool is installed, should the default be to send nothing? [Yes: nothing until there is consent, or until the owner picks "without asking, under an analytics exemption".]
3. Should Global Privacy Control and Do Not Track always count as an objection? [Yes.]
4. How long should scent be kept: 13 weeks, 26 weeks or 13 months? [26 weeks.]
5. Is "shown only after 3 hits, stored minimised" enough for search terms and 404 paths? [Yes, plus the author can delete any term.]
6. Search Console: clicks only, or impressions too (B6)? [Clicks only.]
7. Should Search Console data come through Site Kit's REST datapoint, fetched from an admin visit, instead of the handover's "read tokens from Site Kit" (B7)? [Yes.]
8. May reader-typed queries be sent to the embedding provider for gap matching, as a separate opt-in (B4)? [Yes, off by default.]
9. May approved comment text, without name, email or URL, be sent to Anthropic when a comment question feeds a writing prompt (B5)? [Yes, listed in the settings notice.]
10. Contact-form messages: in v2.0 or not? [Not.]
11. Should "scent" for Evergreen and Wilting mean engaged reads, rather than views or flow? [Engaged reads, falling back to views.]
12. Should Wilting reach the reader-visible badge only after the author confirms it (B2)? [Yes.]
13. Are hubs marked by the author, so Evergreen (#84) can ship before reader mode? [Yes.]
14. May a fusion Accept create an unpublished draft holding only the member posts' titles as links (B3)? [Yes, and nothing else in it.]
15. May the prune list propose removing links the author wrote by hand, not only accepted ones? [Yes, Accept still needs the author.]
16. Related-by-readers: should the author approve each post's list when it changes, or does placing the block count as approval (B1)? [Approve each change.]
17. Once the meter is crossed and reader mode is on, should reader mode pause if views fall back below the line? [No. The statistical check already stops proposals when traffic is thin.]
18. Is sampling 1 in N beacons on very busy sites acceptable? [Yes, through a filter, off by default.]
19. AI Client: a parallel track, with #190 and #191 now and #189 after 7.2? [Yes.]

---

## 13. Sources

Primary sources, checked on 2026-10-23.

- [S1] Directive 2002/58/EC (ePrivacy Directive), Article 5(3), as amended by Directive 2009/136/EC. <https://eur-lex.europa.eu/eli/dir/2002/58/oj>
- [S2] Regulation (EU) 2016/679 (GDPR): Article 5(1)(c) and (e), Article 6(1)(f), Recital 26. <https://eur-lex.europa.eu/eli/reg/2016/679/oj>
- [S3] EDPB, Guidelines 2/2023 on Technical Scope of Art. 5(3) of ePrivacy Directive, version 2.0, adopted 7 October 2024. Paragraphs 12, 33, 51–53, 55–56. <https://www.edpb.europa.eu/our-work-tools/our-documents/guidelines/guidelines-22023-technical-scope-art-53-eprivacy-directive_en>
- [S4] Article 29 Working Party, Opinion 04/2012 on Cookie Consent Exemption (WP194), on first-party analytics not being strictly necessary. <https://ec.europa.eu/justice/article-29/documentation/opinion-recommendation/files/2012/wp194_en.pdf>
- [S5] CNIL, "Cookies : solutions pour les outils de mesure d'audience", on the conditions for consent-exempt audience measurement (own site only, anonymous statistics, no combination or sharing, 13-month tracker lifetime, 25-month retention). <https://www.cnil.fr/fr/cookies-solutions-pour-les-outils-de-mesure-daudience>
- [S6] Data (Use and Access) Act 2025, Schedule 12, inserting Schedule A1 into PECR (para 5, statistical purposes). <https://www.legislation.gov.uk/ukpga/2025/18/schedule/12>
- [S7] ICO, Guidance on the use of storage and access technologies. <https://ico.org.uk/for-organisations/direct-marketing-and-privacy-and-electronic-communications/guidance-on-the-use-of-storage-and-access-technologies/>
- [S8] Germany, TDDDG § 25 (formerly TTDSG). <https://www.gesetze-im-internet.de/ttdsg/__25.html>
- [S9] WP Consent API (feature plugin): categories including `statistics-anonymous`, `wp_has_consent()`, `wp_listen_for_consent_change`, `wp_consent_api_registered_{plugin}`. <https://wordpress.org/plugins/wp-consent-api/>
- [S10] W3C, Global Privacy Control specification. <https://w3c.github.io/gpc/>
- [S11] WordPress.org Detailed Plugin Guidelines, guideline 7. <https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/>
- [S12] WordPress code reference, `wp_enqueue_script()`, including the `strategy` argument. <https://developer.wordpress.org/reference/functions/wp_enqueue_script/>
- [S13] MDN, `Navigator.sendBeacon()`. <https://developer.mozilla.org/en-US/docs/Web/API/Navigator/sendBeacon>
- [S14] Jetpack Stats package, `WPCOM_Stats` (`get_top_posts`, `get_post_views`, `get_clicks`, `get_search_terms`, `get_visits`). <https://github.com/Automattic/jetpack/blob/trunk/projects/packages/stats/src/class-wpcom-stats.php>
- [S15] Site Kit by Google: the Search Console module's `searchanalytics` datapoint and its request parameters, and the REST modules controller's `modules/{slug}/data/{datapoint}` route and permission check. <https://github.com/google/site-kit-wp/blob/develop/includes/Modules/Search_Console.php>, <https://github.com/google/site-kit-wp/blob/develop/includes/Modules/Search_Console/Datapoints/Search_Analytics_Trait.php>, <https://github.com/google/site-kit-wp/blob/develop/includes/Core/Modules/REST_Modules_Controller.php>
- [S16] Google Search Console Help, Performance report (data freshness, anonymised queries, retention); Search Analytics API `query`. <https://support.google.com/webmasters/answer/7576553>, <https://developers.google.com/webmaster-tools/v1/searchanalytics/query>
- [S17] WordPress Plugin Handbook, Adding the Personal Data Eraser to Your Plugin (`wp_privacy_personal_data_erasers`). <https://developer.wordpress.org/plugins/privacy/adding-the-personal-data-eraser-to-your-plugin/>
- [S18] WordPress Plugin Handbook, Adding the Personal Data Exporter to Your Plugin (`wp_privacy_personal_data_exporters`). <https://developer.wordpress.org/plugins/privacy/adding-the-personal-data-exporter-to-your-plugin/>
- [S19] WordPress Plugin Handbook, Suggesting text for the site privacy policy. <https://developer.wordpress.org/plugins/privacy/suggesting-text-for-the-site-privacy-policy/>
- [S20] WordPress code reference, `wp_add_privacy_policy_content()` (since 4.9.6, for use on `admin_init`). <https://developer.wordpress.org/reference/functions/wp_add_privacy_policy_content/>
