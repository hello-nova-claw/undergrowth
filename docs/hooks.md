# Hooks reference

Every filter and action Undergrowth offers, in one place. Each one's DocBlock in `src/` has its parameters in full. This page says where to find it and what it is for. Entries are grouped by what they change.

Undergrowth's rules are fixed in code (handover section 2, principle 6). These hooks let a site connect its own embedding provider, tune thresholds to that provider's scores, and react to what Undergrowth does. They are not a way to change what the AI may say or to whom.

## Filters

### Embeddings

| Filter | Where | What it changes |
|---|---|---|
| `undergrowth_embed_provider` | `src/embed.php` | Supplies the vector for a text yourself, for example from a model on the same server. Return a list of numbers, or `null` to let the next provider run. Hook `undergrowth_embed_identity` as well. A vector without its own identity is never stored, and while an OpenAI key is saved it is replaced by OpenAI's. See `docs/examples/local-embeddings.php`. |
| `undergrowth_embed_identity` | `src/embed.php` | The provider and model names stored with every vector, as `array( 'provider' => …, 'model' => … )`. Vectors from different models are never compared. Changing either name re-embeds the whole index from cron, a batch at a time. |
| `undergrowth_post_language` | `src/indexer.php` | The language stored with a post's vector, the site locale by default. Multilingual plugins return each post's own language, and posts in different languages are never compared. While nothing hooks this, a change of site locale re-embeds the whole index. |
| `undergrowth_post_types` | `src/indexer.php` | The post types whose published posts are read. Default the ones ticked under Post types on Settings > Undergrowth, `array( 'post' )` until then. While it is used, those boxes cannot be changed. |
| `undergrowth_backfill_batch_size` | `src/indexer.php` | How many posts one backfill run embeds. Default 20. |
| `undergrowth_backfill_time_budget` | `src/indexer.php` | How many seconds one backfill run may keep starting new posts. Default 15. |
| `undergrowth_post_chunk_size` | `src/links.php` | How many posts are read in one query wherever posts are read in chunks, such as building the word counts or checking link targets. Default 100. A smaller chunk holds less in memory and sends more queries. |
| `undergrowth_vector_chunk_size` | `src/links.php` | How many stored vectors are read in one query. Default 100. |

### Thresholds

The link, cluster and gap thresholds are measured from the site's own scores where that applies (`src/thresholds.php`). The resurfacing threshold follows the cluster threshold. The fixed value applies:

- before anything has been measured;
- below 20 posts;
- when the scores were measured with another provider or model than the current one;
- for a provider listed in `undergrowth_fixed_threshold_providers`;
- for the gap only, while there are too few clusters to compare;
- whenever the stored scores hold no number for that threshold.

`wp undergrowth scores` prints the values in use.

| Filter | Where | What it changes |
|---|---|---|
| `undergrowth_min_link_similarity` | `src/links.php` | The lowest similarity worth proposing a link for. Fixed 0.4, measured as the 40th percentile. |
| `undergrowth_min_cluster_similarity` | `src/clusters.php` | The lowest similarity at which a post joins a cluster. Fixed 0.5, measured as the 90th percentile. |
| `undergrowth_gap_similarity` | `src/clusters.php` | How close a post must be to its cluster's centre for the cluster to have no gap. Fixed 0.8, measured from how far the site's clusters stand out from one another. |
| `undergrowth_min_resurfacing_similarity` | `src/resurfacing.php` | How close a composting post must be to a new cluster's leader to resurface. Defaults to the cluster threshold, so a signal means the post would have joined. |
| `undergrowth_fixed_threshold_providers` | `src/thresholds.php` | Providers that keep the fixed thresholds whatever their scores. Default `array( 'openai' )`. Their scores are still measured. |

### Links

| Filter | Where | What it changes |
|---|---|---|
| `undergrowth_anchor_stopwords` | `src/anchor-words.php` | The words that cannot carry a link in a language: `never` never carry one, and `named` only when the target's title or a heading holds them. |

### Writing prompts

| Filter | Where | What it changes |
|---|---|---|
| `undergrowth_prompt_sources` | `src/prompts.php` | The source records a question may be written from, best first. Reader comments, cluster gaps and composting posts are added at priorities 10, 15 and 20. A source must come from the garden (handover section 5b). Text from anyone but the author is sent only as data. |
| `undergrowth_prompt_model` | `src/anthropic.php` | The Claude model that writes questions. It must accept the effort setting. |

### Threshold meter

| Filter | Where | What it changes |
|---|---|---|
| `undergrowth_meter_line` | `src/meter.php` | The page views in 30 days the meter counts towards. Default 1,000. Crossing it unlocks nothing on its own (handover section 4). |

## Actions

Each action passes the post ID first.

| Action | Where | Fires |
|---|---|---|
| `undergrowth_post_embedded` | `src/indexer.php` | After a post's vector is stored or replaced. Proposals around the post are refreshed from it. |
| `undergrowth_post_left_index` | `src/indexer.php` | After a post leaves the index, for example when it is unpublished. |
| `undergrowth_proposals_refreshed` | `src/links.php` | After a post's proposed links are rewritten. The per-post file and Discord listen for it. |
| `undergrowth_stage_changed` | `src/stages.php` | After a post's stage changes, or the author pins, unpins, composts or returns it. |
| `undergrowth_stage_cycled` | `src/stages.php` | After the monthly stage pass has measured a post, whether or not its stage changed. |
| `undergrowth_resurfacing_logged` | `src/resurfacing.php` | After a resurfacing signal is shown or dismissed. |
| `undergrowth_prompt_written` | `src/prompts.php` | After a question is written and waiting for the author. Also passes the prompt. |
| `undergrowth_prompt_logged` | `src/prompts.php` | After an event is added to a post's prompt log. |

## Cron events

Each is unscheduled on deactivation and on uninstall (`tests/phpunit/test-uninstall.php` checks both against `src/`). They are listed here so a site can see them in a cron viewer. They are not for hooking.

`undergrowth_embed_post`, `undergrowth_backfill`, `undergrowth_backfill_check` (hourly), `undergrowth_memory_folder_check`, `undergrowth_cluster_cycle` (monthly), `undergrowth_stage_cycle`, `undergrowth_resurfacing_cycle`, `undergrowth_prompt_cycle` (monthly), `undergrowth_write_prompt`, `undergrowth_discord_post_proposals`, `undergrowth_discord_finish_click`.
