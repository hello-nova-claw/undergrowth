# Scale test

How Undergrowth behaves on a blog of 2,000 and 5,000 posts, measured in October 2026 against `main` at a90bc1b1, and what was changed as a result. The demo blog has at most 361 posts, so before this test nothing above that size had been measured.

## Summary

| | 2,000 posts | 5,000 posts |
| --- | --- | --- |
| First read of the archive (backfill), before | ≈5,500 s of cron in 323 runs | ≈11 h of cron in ≈4,600 runs (estimated from samples) |
| First read, after #537 | — | 19.5 s in 231 runs |
| Index refresh after the read | 790 s in 94 runs | ≈4,900 s in ≈340 runs |
| Saving one post (cron) | 3.6 s | 10.4 s (8.4 s after #550) |
| Last clustering batch | 12.4 s, +73 MB | 10.5 s, +110 MB (its steps after #548: see below) |
| Map tab | 2.2 s, 4,986 queries | 3.2 s, 10,494 queries (228 after #548) |
| Orphans tab (SQLite) | 5.0 s | 20.6 s (0.1 s after #546) |
| Garden page (count, filters, feed) | — | 0.8 s, 83 MB (38 MB after #543) |
| Garden index page | — | 0.8 s, 92 MB, 1.45 MB of HTML (0.6 s, 42 MB, 209 KB after #554) |
| Vectors table | 59 MB | 147 MB |

The work that hid problems at this size was always the same few things: something done once per post that compares with every post, a check that loads each post in full when it needs four columns, and two queries whose plan on SQLite pairs every post with every other. None of the fixes changes which suggestions, clusters or stages a site gets. Where the output could be compared on the 5,000-post site, it was identical; the rest is covered by tests that compare a cut-short pass with a whole one.

## Set-up

**Machine.** A Mac with 10 cores and PHP 8.5, shared with other work: the load average stayed above 10 for most of the test and at times passed 500, so times vary by tens of percent between runs, and more at the worst moments. Times quoted are the fastest of several runs where a comparison matters. Queries and memory do not depend on load and are the better guide.

**Database.** SQLite, through the SQLite Database Integration plugin, on WordPress 7.1. MySQL could not be measured: the Homebrew `mysqld` on this machine does not start, and there is no Docker for `wp-env`. Two of the problems below (#546, #551) came from SQLite's query planner. MySQL and MariaDB use the indexes those queries need, but that was not measured here. The other bottlenecks are mainly PHP work, but they read and write through the database too, so their times on MySQL will differ somewhat.

**Memory.** Cron was measured from the command line, one event per process, the way `wp-cron.php` runs it. The command line has no OPcache, so a process starts at 42 MB with WordPress and Undergrowth loaded, where a web request starts at about 5 MB. Cron memory below is given as the peak above that 42 MB start, which is what a host's `memory_limit` has to cover on top of its own baseline. Web requests were measured through PHP's built-in server with OPcache, as total peak.

**Blog.** A generated personal blog. 24 topics, each with its own vocabulary of about 20 words, written as English-like sentences of function words, topic words and common words. A fifth of posts mix two topics. Lengths follow a log-normal curve between 300 and 2,000 words, median about 800. Dates spread over ten years. About 4% of posts are drafts, 2% private and 1% pending. A quarter of the published posts were edited once, so they have a revision. Some posts link to an earlier post on the same topic. Blocks are paragraphs and headings.

| | Posts | Published | Revisions | Post text |
| --- | --- | --- | --- | --- |
| 2,000 | 2,000 | 1,863 | 479 | 12 MB |
| 5,000 | 5,000 | 4,618 | 1,142 | 30 MB |

**Embeddings.** No key and no network. A scratch provider hashes each post's words into 1,536 dimensions (the size of OpenAI's `text-embedding-3-small`) with a sign per word, adds a small dense part seeded by the text, and normalises. The vectors are dense like real ones, 33 KB each as stored JSON, and posts on one topic score near each other. Its identity is provider `scale` and model `hash-1536`, and every post takes the site's language, `en_US`, through `undergrowth_post_language()` with no multilingual plugin. Provider latency is left out: with OpenAI each post adds a request of a few hundred milliseconds to the backfill, which Undergrowth already budgets for.

**Prompts.** No Anthropic key either: a scratch filter answered requests to the Anthropic API with a fixed question, so the weekly batch could run.

The scripts (generator, provider, cron runner, page timer) are scratch tools and are not in the repository. `bin/benchmark-refresh.php` remains the committed benchmark for one refresh.

## Backfill

Before #537, every post the backfill embedded fired `undergrowth_post_embedded`, and `undergrowth_refresh_around()` compared it with the whole index and refreshed ten or more neighbours, each against the whole index again. The pass then ends with an index refresh that redoes all of it. The whole read was O(n²).

At 2,000 posts a full read under the old code took 323 cron runs and about 5,500 s of plugin time, roughly 1.5 hours, against 1,863 published posts. The median run was 16.6 s; one in ten took longer than 19.6 s, and the longest took 40.7 s, all on the busy machine. Peak memory was +21 MB. One run during which the machine slept overnight is left out.

At 5,000 posts a full run under the old code would have taken about half a day of cron. Single runs were sampled at three index sizes:

| Index size | Posts per run | Run time | Memory above start |
| --- | --- | --- | --- |
| 1,000 | 3 | 20.2 s | +15 MB |
| 2,500 | 2 | 27.1 s | +26 MB |
| 4,598 | 1 | 17.9 s | +43 MB |

The 15-second budget is checked only before a post is started, so a run that starts a post at 14 s ends near 30 s. That is the limit many hosts set on a request, and WP-Cron often runs inside one.

**Fixed in #537** (PR #542): during a first read, or a read again after a provider, model or language change, the refresh around each post is skipped. The index refresh at the end of the pass does that work once. If some posts cannot be read, the pass still queues a refresh of the posts it did read. At 5,000 posts the read now takes 231 runs, 19.5 s of plugin time in all, with the longest run 0.6 s and +3 MB.

The index refresh that follows is unchanged. It is the real cost of a first read: 20 posts or 15 seconds per run, whichever comes first.

| | 2,000 | 5,000 |
| --- | --- | --- |
| Runs | 94 | ≈340 |
| Total | 790 s | ≈4,900 s (median run 15.1 s) |
| Longest run | 15.7 s | 16.9 s |
| Memory above start | +45 MB | +42 MB, +106 MB once cross-pollination pairs exist (+46 MB after #548) |

## Saving one post

A save queues `undergrowth_embed_post`, which refreshes the proposals around the post in cron. That is O(n) in the number of vectors, with a large constant:

| | 2,000 | 5,000 |
| --- | --- | --- |
| Read and decode every vector | 0.74 s | 1.81 s |
| Count every post's words | 0.79 s | 2.10 s |
| Count them again for the per-post files (#550) | 0.8 s | 2.1 s |
| Score one post against the index | 0.14 s | 0.37–0.60 s |
| Whole refresh around one post (about 12 refreshes) | 3.6 s, 438 queries, +18 MB | 10.4 s, 514 queries, +41 MB |

**Fixed in #550** (PR #557): the second word count. At 5,000 posts a refresh around one post now takes 8.4 s.

**Filed as #553**: vectors are stored as JSON. The table is 3.5 times the size of the posts it describes, and every refresh decodes all of it. Packed floats would be a fifth of the size and need no JSON decoding.

At 5,000 posts a save stays well inside a cron run. It grows linearly, though, and would reach 30 s at around 15,000 posts.

## Monthly cycle

| | 2,000 | 5,000 |
| --- | --- | --- |
| `undergrowth_cluster_cycle` batches | 37 runs, median 0.2 s | 93 runs, median 0.1 s |
| First batch (measures the score percentiles) | 3.4 s, +12 MB | 4.8 s, +12 MB |
| Last batch (gap threshold, garden index, labels) | 12.4 s, 7,370 queries, +73 MB | 10.5 s, 12,923 queries, +110 MB |
| `undergrowth_stage_cycle` | 19 runs, 44 s, longest 4.7 s, +9 MB | 47 runs, 142 s, longest 3.8 s, +12 MB |
| `undergrowth_pollination_cycle` | 38 runs, 157 s, longest 12.3 s, +47 MB | 93 runs, 749 s, longest 15.8 s, +107 MB |
| `undergrowth_prompt_batch` (weekly) | 5.4 s, 8,530 queries, +34 MB | 15.0 s, 14,040 queries, +73 MB (13.3 s, 1,205 queries, +14 MB after #548) |
| `undergrowth_refresh_sources` after a pollination pass, before #549 | 18 runs, 188 s, longest 18.0 s | 22 runs, median 17.9 s, longest 65.8 s under heavy load |

The last clustering batch at 5,000 posts peaked at 152 MB from the command line, about 115 MB in a web request with OPcache: close to a 128 MB limit. Three of its steps (`undergrowth_measure_gap_threshold()`, `undergrowth_build_garden_index()` and `undergrowth_label_clusters()`) each filtered every cluster's posts with `undergrowth_can_cluster()`. That function loads each post in full with two queries, and each step read each member's vector with a query of its own.

| At 5,000 posts | Before | After #548 |
| --- | --- | --- |
| `undergrowth_measure_gap_threshold()` | 3.6 s, 10,413 queries, +56 MB | 0.75 s, 95 queries, +2 MB |
| `undergrowth_build_garden_index()` | 3.0 s, 10,435 queries, +57 MB | 0.98 s, 166 queries, +2 MB |
| `undergrowth_label_clusters()` | 7.8 s, 10,459 queries, +99 MB | 5.5 s, 1,271 queries, +44 MB |
| `undergrowth_cluster_map()` | 3.0 s, 10,434 queries, +57 MB | 0.99 s, 165 queries, +2 MB |

**Fixed in #548** (PR #556). The stored gap threshold, garden index and labels are byte for byte the same as before.

The same check made every refresh heavier once a pollination pass had found pairs, because cross-pollination links go through the cluster map. At 5,000 posts one index-refresh run went from 11,000 queries and +106 MB to 1,250 queries and +46 MB.

**Fixed in #549** (PR #563): `undergrowth_refresh_sources` had no time budget. It refreshes 20 posts per run, each against the whole index, and its runs took up to 18 s at 2,000 posts. It now stops starting posts at the backfill's 15-second budget and queues the rest.

**Fixed in #551** (PR #562): on SQLite, `undergrowth_linked_group_pairs()` took 3.0 s at 5,000 posts in every request that works out cross-pollination links, which includes every refresh. The planner paired every grouped post with every other before it looked for a link. Read from the links first, the query takes 0.017 s and returns the same pairs.

**Fixed in #555** (PR #564): a pollination batch compares each of its 50 posts with every grouped post after it, so the pass is O(n²) (12 minutes of cron at 5,000 posts) and its batches had no time budget. On the busy machine they took 21–49 s each. They now stop at the 15-second budget, and the pairs found are the same.

Thresholds are already sampled (`UNDERGROWTH_SCORE_SAMPLE`), so measuring them stays at a few seconds.

## Admin pages

Web requests, logged in as an administrator, fastest of three:

| Page | 2,000 posts | 5,000 posts | After the fixes, 5,000 |
| --- | --- | --- | --- |
| Dashboard | 0.05 s, 9 MB, 56 queries | 0.05 s, 9 MB, 56 queries | |
| Posts list (Undergrowth column, stage filter) | 0.06–0.09 s, 9 MB, 50 queries | 0.07–0.11 s, 9 MB, 50 queries | |
| Suggestions | 1.4 s, 41 MB, 1,238 queries | 0.6 s, 80 MB, 36 queries | 13 MB on the first page, 8 MB on others (#559) |
| Growth stages | 0.06 s, 8 MB | 0.09 s, 8 MB | |
| Map | 2.2 s, 36 MB, 4,986 queries | 3.2 s, 74 MB, 10,494 queries | 228 queries (#548) |
| Orphans | 5.0 s, 38 MB, 4,945 queries | 20.6 s, 8 MB, 71 queries | 0.1 s (#546) |
| Prompt studio, Settings | 0.04–0.06 s, 8 MB | 0.04–0.06 s, 8 MB | |
| Block editor | 0.11 s, 11 MB | 0.15 s, 11 MB | |

At 2,000 posts every proposal had been refreshed. At 5,000, the Map and Orphans rows were measured with about half the proposals refreshed, and the Suggestions row with nearly all of them. At 2,000 posts the Suggestions tab made 1,150 single vector reads, from the cluster checks fixed in #548. At 5,000 it did not, and I did not trace why; its memory comes from loading every suggested post (#559).

The Posts list, the Growth stages tab, the Prompt studio, Settings and the editor do not grow with the archive.

- **Orphans** (fixed in #546, PR #547): the count's "no accepted link from a published post" condition joined every published post inside the subquery. On SQLite that is every post against every other: 20.4 s of the 20.6 s. Found from the link first, the count takes 0.02 s and gives the same number.
- **Map** and **Suggestions**: almost all of their queries came from the cluster check fixed in #548. The Map tab also loaded each listed post on its own, which #548 now primes in one query.
- **Suggestions** (fixed in #559, PR #567): the tab read every proposal and loaded every post they named, to show 20 rows. That took 80 MB at 5,000 posts, and it grew with the archive. Composting posts and the narrowing to one target are now left out in SQL. For someone who can edit others' published posts, the count and the page come from SQL, and only the page's posts are loaded. An author still has each suggestion checked, but only the source posts are loaded for that. Loading every post had also hidden that each cross-pollination label loaded its bridge post on its own; those now load in one query.

| Suggestions, 5,000 posts, 10,292 proposals | Before | After |
| --- | --- | --- |
| First page | 1.51 s, 76 MB, 220 queries | 1.07 s, 13 MB, 206 queries |
| Page 200 | 0.45 s, 76 MB, 36 queries | 0.13 s, 8 MB, 38 queries |
| Narrowed to one post's suggestions | 0.36 s, 76 MB, 37 queries | 0.06 s, 8 MB, 39 queries |

The rest of the first page's time and queries go on the cluster and cross-pollination checks for its rows, which do not grow with the number of proposals. Written with both `NOT EXISTS` checks in one subquery (`post_id IN ( source, target )`), the count took 4 s on SQLite, because the planner then reads all of postmeta for each row; one subquery for each post takes 0.02 s.

## Front-end blocks

Rendered for one post at 5,000 posts, cold (empty object cache, as on a host without a persistent one) and warm (a second block of the same kind in the same request):

| Block | Cold | Warm |
| --- | --- | --- |
| Growth stage | 0.002 s, 4 queries | 0.000 s |
| Linked from | 0.002 s, 5 queries | 0.000 s |
| More like this, first view of the post | 2.76 s, 63 queries, +58 MB | 0.002 s |
| Garden count | 0.47 s, +73 MB (0.41 s, +31 MB after #543) | 0.03 s |
| Garden filters | 0.87 s, +73 MB (0.61 s, +28 MB after #543) | 0.03 s |
| Garden feed | 0.49 s, +70 MB (0.26 s, +25 MB after #543) | 0.03 s |
| Garden index | 0.87 s, +55 MB, 1.38 MB of HTML | 0.41 s |

A page with the Garden count, filters and feed peaked at 83 MB, and 38 MB after #543. The memory went on loading every post's text to check four columns. A plain post page peaks at 5 MB.

**Fixed in #543** (PR #545): the count, filters and feed check visibility from the posts' status, password, type and stage only, and the feed loads only the page it shows.

**Filed as #552**: the first reader of each post waits while "More like this" scores the whole index. Every save then marks every list stale, so each post viewed afterwards queues a 2–3 s re-measure in cron.

**Fixed in #554** (PR #561), as Anna decided: the Garden index prints every post on one page, 1.45 MB of HTML and 92 MB at 5,000 posts. Each group now shows the 20 posts nearest its centre, with a link to the Garden feed filtered to that group, and the index reads only the columns a title and a link need. The page is now 209 KB and 42 MB. Every group keeps its name and the same first 20 posts.

## Storage

| | 2,000 | 5,000 |
| --- | --- | --- |
| `undergrowth_vectors` | 59 MB | 147 MB |
| `undergrowth_links` | 5,863 rows, 1.3 MB | 10,292 rows, 2.3 MB |
| `wp_posts`, for comparison | 17 MB | 42 MB |
| Undergrowth post meta | 2 rows a post, 18 KB | 2 rows a post, 46 KB |
| Per-post files | 1,867 files, 7.3 MB | 4,622 files, 18 MB |
| Autoloaded options from Undergrowth | 3, under 0.5 KB | 3, under 0.5 KB |

The vectors table has a unique key on `post_id`, which every query that reads vectors uses, and no index on provider, model and language. Filtering on those columns while walking `post_id` is cheap, because a site normally has one identity. The links table has its `pair` and `target_id` keys. No autoloaded option grows with the archive. The largest options (`undergrowth_pollination`, 91 KB at 2,000 posts; the garden index; last month's clusters) are all stored with autoload off.

Two things grow without a bound but are not loaded on every request. `undergrowth_similar` post meta is up to about 200 scored IDs for each post viewed with "More like this". The per-post files are about 4 KB each, in one folder.

## Issues and pull requests

| Issue | Problem | Status |
| --- | --- | --- |
| #537 | Backfill refreshed around every post: O(n²), runs near 30 s | Fixed in #542 |
| #543 | Garden blocks loaded every post's text | Fixed in #545 |
| #546 | Orphans count quadratic on SQLite | Fixed in #547 |
| #548 | Cluster checks loaded every clustered post | Fixed in #556 |
| #549 | Refreshing sources had no time budget | Fixed in #563 |
| #550 | Words counted twice per save | Fixed in #557 |
| #551 | Linked group pairs quadratic on SQLite | Fixed in #562 |
| #552 | "More like this" scores the index in a reader's request | Filed |
| #553 | Vectors stored as JSON | Filed |
| #554 | Garden index lists every post | Fixed in #561 (Anna chose 20 posts a group) |
| #555 | Pollination batches had no time budget | Fixed in #564 |
| #559 | Suggestions tab loads every suggested post | Fixed in #567 |
