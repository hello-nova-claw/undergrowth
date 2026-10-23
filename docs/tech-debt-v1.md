# Tech debt audit: Undergrowth v1

Audited on 2026-10-23 against `main` at 5575a40. About 40 sessions built v1 in parallel, and CodeRabbit reviewed each pull request on its own. This audit reads the codebase as a whole instead. It looks for:

- duplicated helpers;
- drift in naming and comments;
- dead code;
- gaps in cleanup;
- the health of the test suite.

Every line of `src/` was read. The suite (1304 PHPUnit tests, 116 s of test time) was timed from its JUnit log.

Severity:

- **Bug**: behaviour that is wrong for some site or some owner.
- **High**: duplication or drift that has already caused a bug, or will cause the next one.
- **Medium**: costs time on every change to the area.
- **Low**: polish.

The rule for fixes is no change in behaviour, except for bugs. Each bug has its own issue and is fixed test-first in its own pull request. Each refactor is a small pull request on one theme, with the same tests passing before and after. Restructures too large for now are issues.

## Summary

The pieces are sound. Uninstall removes everything the plugin stores. That was checked against an inventory built from the source, which is now a test (#226), so a key added later and forgotten fails CI. Every action the plugin fires has a listener, and every filter it applies is a deliberate extension point.

The debt sits in three places:

1. **Failure paths that nobody joined up.** A prompt reply cut off by the token limit blocks the prompt queue for good (B1). File-write failures are recorded but never shown (B2). A refused source is reported as a provider fault that will be retried (B6).
2. **Rules that moved while their copies stayed.** The resurfacing threshold still copies the old fixed cluster threshold (B3). Four SQL copies of "is this post in the garden" sit beside `undergrowth_is_indexable()`. Three copies of each link-result message, and of the veto outcome, sit in the admin screen, the abilities and Discord.
3. **Admin markup copied from screen to screen.** Post links, focus-taking result notices, Help tabs and settings URLs are each built three or four times. One copy has already drifted, leaving an untitled post's link empty (B5).

The test suite is thorough, but planting a post runs the whole embed pipeline, so the tests that plant many posts are slow. Per-post files also leak between tests.

## Bugs

| ID | Finding | Where | Issue | Status |
|---|---|---|---|---|
| B0 | Deactivation leaves `undergrowth_embed_post` and `undergrowth_discord_post_proposals` queued. Every other event is unscheduled. | `stages.php` `undergrowth_deactivate()` | #226 | Fixed in #227 |
| B1 | A prompt reply that stops at `max_tokens` counts as a failed request. The source is never recorded as sent, and sources are tried best first, so every later run pays for the same request and never reaches another source. | `prompts.php` `undergrowth_write_prompt_from()` | #229 | To fix |
| B2 | `undergrowth_last_file_error` is written for five kinds of failure, but nothing shows it. Settings shows the folder as if files were being written. | `memory.php`, `settings.php` `undergrowth_render_memory_folder_field()` | #230 | To fix |
| B3 | Resurfacing uses a fixed 0.5. Its comment says this is the cluster threshold, but that threshold has been measured per site since #176. | `resurfacing.php` `UNDERGROWTH_MIN_RESURFACING_SIMILARITY` | #231 | To fix |
| B4 | Post text is stripped of tags but entities are not decoded, so `nbsp`, `amp` and `8217` reach the embedding and the word counts. Sentences are decoded, so those tokens can never be linked. Reader comments sent for prompts have the same gap. | `indexer.php` `undergrowth_post_text()`, `prompts.php` comment source | #232 | To fix |
| B5 | The Dashboard prompt widget builds its link by hand, so an untitled post gets a link with no name. | `dashboard.php` `undergrowth_render_prompt_widget()` | #234 | To fix |
| B6 | A refusal is recorded as the last prompt error with status 200. Settings then calls it a fault on the provider's side and says it will be retried, but a refused source is skipped for good. | `anthropic.php`, `settings.php` `undergrowth_failure_text()` | #235 | To fix |
| B7 | `undergrowth_notice` and `revision` are not removable query arguments. A reload repeats the notice, and a stage action from the Map tab can show two contradicting notices with the same `id`. | `posts-screen.php`, `admin.php`, `dashboard.php` | #233 | To fix |
| B8 | The post language is not cut to its `varchar(20)` column, as provider and model are. A longer filtered value never matches the stored one, so every save calls the provider again. | `indexer.php` `undergrowth_post_language()` | #236 | To fix |
| B9 | Two clustering passes can interleave (monthly, end of backfill, WP-CLI). The second's first batch wipes the first's meta, and both then share the leaders option. | `clusters.php` `undergrowth_run_cluster_cycle()` | #237 | Later: needs a pass ID |

## Decisions needed

These might be bugs, depending on what the rule should be. Each is an issue for Anna.

| ID | Question | Issue |
|---|---|---|
| D1 | The first real pass after install compares with an empty pass, so every cluster is new and every composting post near one resurfaces. The comment says no cluster is new on the first pass. | #240 |
| D2 | Changing the site language splits the index: old vectors keep the old locale and are never compared with new posts. Should a locale change re-embed, as a model change does? | #239 |
| D3 | The backfill fills only posts with no vector. A post whose embed event was lost keeps a vector for its old text until it is saved again. | #238 |
| D4 | Importing a per-post file (`undergrowth_import_memory()`, about 200 lines) is reachable only from tests. It also writes the stage meta directly, so `undergrowth_stage_changed` never fires. Should it be wired up or removed? | #241 |

## Duplicated helpers

| ID | Severity | Finding | Proposed fix |
|---|---|---|---|
| H1 | High | "Is this post in the garden" is written out in SQL four times (`indexer.php` twice, `stages.php` twice), beside `undergrowth_is_indexable()`. A change to the rule would need all five changed. | One helper that returns the `WHERE` fragment and its arguments. |
| H2 | High | Each link-result message (`gone`, `unchanged`, `not_proposed`, `edited`, `no_word`, `needs_admin`), and the veto outcome, is written three times: in `admin.php`, `abilities.php` and `discord-clicks.php`. | `undergrowth_link_result_message()` and `undergrowth_veto_result()` in `links.php`. |
| M1 | Medium | A stored embedding is decoded and sanitised by hand five times (`links.php`, `clusters.php` twice, `thresholds.php`, `resurfacing.php`). The "same model and language" key is built four times, with two different separators. The dot product is written out twice beside `undergrowth_packed_dot()`. | `undergrowth_decode_embedding()` and `undergrowth_vector_space_key()` in `vectors.php`, and call the existing dot product. |
| M2 | Medium | `undergrowth_has_embed_provider()` lives in `first-run.php`, and its check is written inline five more times (`indexer.php` three times, `thresholds.php`, `admin.php`). | Move it to `embed.php` and call it everywhere. |
| M3 | Medium | Result notices that take focus are rendered by hand three times (`admin.php`, `dashboard.php`, `posts-screen.php`). | `undergrowth_print_result_notice()`. |
| M4 | Medium | A post's title link is built four ways: `undergrowth_post_link()`, the links list table's `edit_link()`, inline in the stages table, and inline in the Dashboard widget. That last copy is B5. | Give `undergrowth_post_link()` an optional class and use it everywhere. |
| M5 | Medium | Help tab content is built three times (`help.php`, `settings.php`, `meter.php`). | Route all three through `undergrowth_add_help_tab()`. |
| M6 | Medium | Four "last error" recorders store the same `{code, status, time}` shape under three naming patterns (`undergrowth_record_embed_error()`, `undergrowth_prompt_request_failed()`, `undergrowth_record_file_error()`). Discord's record has a fourth shape, and its `pending` reader is repeated three times. | One `undergrowth_record_failure()`, plus `undergrowth_discord_pending()`. |
| L1 | Low | `admin_url( 'options-general.php?page=undergrowth' )` is hard-coded four times. `first-run.php` builds the Suggestions URL by hand instead of calling `undergrowth_suggestions_url()`, which exists to avoid the `post_type=post` trap. | `undergrowth_settings_url()`, and call the existing helper. |
| L2 | Low | The four garden stages are listed by hand four times, beside `UNDERGROWTH_STAGES`. The pinned marker's markup is copied byte for byte into two files. | `UNDERGROWTH_GARDEN_STAGES` and `undergrowth_pinned_marker()`. |
| L3 | Low | The "last failed request" warning block is printed twice (`settings.php`, `discord-settings.php`). | Have `undergrowth_render_failure_notice()` print the whole block. |
| L4 | Low | Five `is_protected_meta` filters, one per file. `stages.php` protects a key that belongs to `resurfacing.php`. | One list of protected keys. |
| L5 | Low | The Discord fields are listed again in `discord-messages.php` and in uninstall, beside `undergrowth_discord_options()`. The two key getters in `openai.php` and `anthropic.php` differ only in their names. | Use the existing list, and share one key getter. |
| L6 | Low | `undergrowth_cluster_members()` and `undergrowth_cluster_posts()` run the same query. `undergrowth_stage_log()` and `undergrowth_resurfacing_log()` share their validate-and-sort code. | Build one on the other, and extract the log sort. |

## Dead code

| ID | Severity | Finding | Proposed fix |
|---|---|---|---|
| X1 | Low | `undergrowth_rank_targets()` has no caller in `src/`; four test files use it. `undergrowth_refresh_proposals()` repeats its loop. | Point the tests at `undergrowth_open_targets()` and remove it. |
| X2 | Low | `undergrowth_schedule_stage_cycle()` clears the stage event's old no-argument schedule on every `init`, forever. | Keep it until a schema bump, then gate it on the schema version. |
| X3 | Low | `undergrowth_stop_autoloading_key()` documents a default argument that the hook always overrides. | Drop the default. |

Checked and sound: every `do_action()` has a listener, and every `apply_filters()` is an extension point. Every option, post meta key, user meta key, transient, table, cron event and file the plugin creates is removed on uninstall, on every site of a network. Five filters are documented only in code comments (`undergrowth_post_language`, `undergrowth_backfill_time_budget`, `undergrowth_min_resurfacing_similarity`, `undergrowth_prompt_sources` and `undergrowth_meter_line`). A hooks reference in `docs/` would list all 14 filters and 8 actions in one place.

## Comments that no longer match the code

| ID | Where | Drift |
|---|---|---|
| C1 | `schema.php` `undergrowth_activate()` | Says it schedules the stage cycle. It schedules the cluster cycle, the backfill check, a backfill and the first-run step. |
| C2 | `embed.php` `undergrowth_embed()` | Says a filter vector with no identity is "stored as OpenAI's when a key is saved". The code discards it and calls OpenAI. |
| C3 | `admin.php` | The file header says "the link suggestions screen", but the file holds three tabs. The resurfacing dismissal "returns to the stages tab" but redirects to Map. `$tab` is documented without `'map'`. |
| C4 | `thresholds.php` `undergrowth_scores_report()` | Labels a value "fixed" when nothing is measured, even when a filter changed it. |
| C5 | `meter.php` `undergrowth_meter_sanitize_line()` | Hard-codes 1000 instead of using `UNDERGROWTH_METER_LINE`. |
| C6 | `prompts.php` | The system prompt asks for "under 250 characters" and the check allows 300, with no comment saying the gap is deliberate. The docblock of `undergrowth_write_prompt()` says each source is sent at most once "whatever comes back", which B1 shows is not so. |
| C7 | `abilities.php` | The header says the same fields answer at every scale, but one post's words are weighed against a different corpus alone than in a set. The not-indexed message leaves out the post type. |
| C8 | `stages.php` | The file header describes storage, which is now a fifth of the file. |

## Naming

| ID | Severity | Finding | Proposed fix |
|---|---|---|---|
| N1 | Low | `undergrowth_cluster_leaders()` reads post meta. The option `undergrowth_cluster_leaders` holds a pass in progress. | Rename the option to `undergrowth_cluster_pass` when B9 adds a pass ID. |
| N2 | Low | `undergrowth_backfill` is a cron hook, an option and a lock name at once. | Leave it. Renaming a stored option needs a migration and buys little. |
| N3 | Low | Readers mix `get_` (`undergrowth_get_stage()`) with bare nouns (`undergrowth_stage_log()`). Removers mix `delete_`, `remove_from_` and `forget_`. | Adopt bare nouns for readers and `forget_` for removers in new code. Renaming existing functions is not worth the churn. |

All functions, hooks, options, meta keys, tables and abilities carry the `undergrowth_` or `undergrowth/` prefix.

## File organisation

| ID | Severity | Finding | Proposed fix |
|---|---|---|---|
| F1 | Medium | `stages.php` (771 lines) holds meta registration, admin counts, measuring, the cycle, plugin-wide deactivation and author actions. Deactivation is split between it and `prompts.php`, while activation lives in `schema.php`. | Move deactivation beside activation now. Move author actions to their own file later (#242). |
| F2 | Medium | `links.php` (766 lines) holds the vector maths that clustering, thresholds and resurfacing also use. | Move it to `vectors.php` (#242). |
| F3 | Medium | `prompts.php` (932), `memory.php` (807) and `abilities.php` (863) each hold two or three concerns. | Split by concern (#242). |
| F4 | Low | `admin.php` holds helpers that six files use, and `settings.php` holds the key readers that the provider clients use. | Move them to `admin-helpers.php` and `keys.php` (#242). |

## Test suite

| ID | Severity | Finding | Proposed fix |
|---|---|---|---|
| T1 | High | `Plants_Posts::plant()` writes per-post files, but five classes that use it never remove them. Files are left in the uploads folder after a run, and SQLite reuses post IDs, so a later test can read another test's file. | Clean up in the trait's `tear_down`. |
| T2 | High | `plant()` runs the whole embed pipeline: proposals around the post, its file, and Discord. Planting N posts costs about N². `Test_Thresholds` takes 24 s and `Test_Corpus` 20 s, and one test takes 13 s. | A `seed()` helper that stores a vector and fires nothing (#243). |
| T3 | Medium | Copied helpers: `stop_redirect` six times; a queued fake HTTP response seven times; `member()` and `post_with_vector()` twice each. There are three `set_state()` helpers with three different meanings. | `Stops_Redirects` and `Fakes_HTTP` traits; one `set_state()` in `Plants_Posts`. |
| T4 | Medium | Some tests assert SQL text. One asserts core's own internal `delete_post_meta_by_key()` query (`test-stages.php`), which any WordPress release can change. Others match query prefixes to count chunks. | Assert the effect: the meta is gone, or the number of batches. |
| T5 | Low | `test-admin-compost.php` overwrites `$GLOBALS['pagenow']` and `$GLOBALS['wp_the_query']` without restoring them. One backfill test sleeps 0.2 s for each post. | Restore the globals in `tear_down`. Leave the sleep, which is the test's subject. |

## Order of work

Bugs first, by value: B1, B2, B3, B4, then B5 to B8. Then the refactors, each with no change in behaviour:

1. T1: per-post files cleaned up in tests.
2. H2: link results.
3. M2: the provider check.
4. M1: vector decoding.
5. H1: the SQL rule.
6. M3, M4, L1 and L2: admin helpers.
7. M6: failure records.
8. C1 to C8: comments.
9. F1: deactivation in one place.
10. A hooks reference.
11. T3: shared test traits.

B9, the decisions and F1 to F4 beyond deactivation wait for their issues. Files that other pull requests are changing (`sentences.php`, `accept.php`, `readme.txt`) are left until those merge.
