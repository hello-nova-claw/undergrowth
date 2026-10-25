# Vision: Undergrowth after v1

> **Confirmed by Anna on 2026-10-25.** She accepted every recommendation in section 6. The decisions are recorded in `docs/handover.md` section 12, which is the source of truth. This document keeps the reasoning.

Drafted 2026-10-25, after the October review was finished. It reads with `docs/handover.md` (the design record) and `docs/v2-reader-mode-proposal.md` open.

## 1. Why this document exists

All 57 issues from the October 2026 independent review are closed. v1 is safe, tested and ready to submit. But on 2026-10-25 Anna said it does not yet do enough, or quite what she had in mind.

The review asked whether v1 was correct and submittable. It never asked whether v1 was the right product. This document asks that question.

## 2. What Anna described

In her words, 2026-10-25:

- A **writing aide**, an **assistant**, and an **SEO tool, but not a typical one**.
- A **digital garden suite**.
- Something that encourages the author to **amplify their own voice instead of erasing it**.
- **Custom, bespoke blogging prompts**, based on what it knows and finds. They can be dismissed, replaced and saved, **any time**.
- A **"more like this" related posts block** that is better than the competitors and **changes with decay and popularity**.

None of this contradicts the stance in handover 0. The AI still speaks to the author, never for them. What changes is how much it offers and where.

## 3. Where v1 stands against that

| Anna's picture | v1 today | Gap |
|---|---|---|
| Writing aide that amplifies the author's voice | Link anchors and quotes use only the author's words (handover 2.1). | v1 is mostly reactive. It tidies up after writing and does little to help the author write. |
| Bespoke prompts, any time, saveable | At most one question a month, on the Dashboard. Dismiss or snooze; no backlog (handover 5b). | The biggest gap. The deliberate sparseness ("one at a time, no feed") is the opposite of what Anna wants. |
| "More like this", better than competitors | Not built. #97 is a different block (next clicks from readers), parked for reader mode. | Not built. The vectors that would power it already exist. |
| SEO, but not typical | Internal links in the author's words; Map groups and gaps. | No hubs, orphan list, "tend this post" nudges or backlinks. Most of these were already planned for reader mode (handover 4). |
| A digital garden suite | Growth stages, pin, compost, a stage block. | All admin-facing. Readers see a stage badge and nothing else of the garden. |

The engine (meaning-based matching, the author's words only, safe and well tested) is sound. What's missing is the experience around it.

## 4. Four pillars

### 4.1 The Prompt studio

A place, not a widget, where the author goes for ideas grounded in their own writing.

- **A weekly batch.** Each week, in cron, Undergrowth prepares up to five questions and leaves them waiting. They come from the garden only, the same sources as handover 5b: in germination mode, gaps on the Map and composted posts (handover 5); once reader mode exists, comment and contact-form questions, wilting posts and Search Console queries landing on the wrong page. Never trends or keyword data.
- **Browse, dismiss, replace, save.** Each question can be dismissed (gone), replaced (swap it for another), or saved to an **idea bank** the author can return to at any time. Saved questions keep the posts they came from, so the author can see why each was asked.
- **More on demand.** When the batch runs out, a "More questions" button asks Claude straight away. The button says plainly that this uses the site's own AI key and roughly what it costs (decided by Anna, 2026-10-25). Nothing is generated without that click.
- **Still off until there's a key.** As today, the whole studio stays off until the site's Anthropic key is entered, and the settings page says exactly what is sent for each question (handover 5b).
- **Comments stay data.** Once reader mode adds comments as a source, their text is untrusted data, never instructions to the model. A question may say "readers ask", but never quotes a commenter (principle 3; v2 proposal, B5).
- **Still questions, still the author's words.** Every rule in handover 5b about form stays: a question, never a draft, title, outline or opening line, and quoting only the author.
- **Still never nags.** No streaks, no counts of days without writing, no notifications about unread questions. The monthly email (#385) may mention that new questions are waiting, nothing more.

Principle change needed: handover 5b's "One at a time. Dashboard shows one prompt ... No feed, no backlog view" is replaced by the batch and the idea bank above. "Never nags" and every content rule stay.

### 4.2 "More like this"

A related-posts block that beats the usual ones (Jetpack Related Posts, YARPP, Contextual Related Posts), because those match tags or keywords and this matches meaning.

- **Name.** "More like this", never "Related by readers". It's Undergrowth's judgement of what is close, and saying it came from readers would be false (#97 already sets this rule).
- **Ranking.** Start with meaning (cosine similarity of the stored vectors). Then weight by:
  - **growth stage**: Evergreen and Budding posts rise, Seedlings sink;
  - **freshness, with decay**: a recently updated post gets a lift that fades over time;
  - **popularity, with decay**: recent views count, and old views fade, so "popular" means popular now;
  - **resurfacing**: one slot can go to an under-read but closely related post, so the block doesn't only make popular posts more popular.
- **Pluggable popularity** (decided by Anna, 2026-10-25). An `undergrowth_post_popularity` filter, with small adapters for Jetpack Stats (through its public `WPCOM_Stats` class, as `src/meter.php` already does), Koko Analytics and Post Views Counter. With none of them, the block simply leaves popularity out. **No built-in view counter**: page caches hide most views from PHP, a beacon endpoint can be spammed, honest de-duplication needs personal data, and most sites already run a stats tool.
- **Never a model's words.** Titles, excerpts the author wrote, featured images and links only.
- **Composted, private, draft and unindexed posts never appear.**

Decision needed (D2 below): how the author approves a list that changes by itself.

### 4.3 Garden SEO

SEO from the garden's side: structure readers can follow, not keywords. Much of this is already in handover 4 for reader mode. What's new is doing what can be done **without reader data first**.

- **Orphans.** Posts no other post links to, listed with suggestions to link in.
- **Hubs and cornerstones.** Where a Map group is dense, propose a hub page. The author writes the hub text. Undergrowth may offer a draft holding titles and links only (v2 proposal, B3).
- **Tend this post.** An Evergreen post that hasn't been touched in a long time, or whose group has grown around it, gets a gentle "worth a look" in the admin. It's never a demotion, and never shown to readers.
- **Backlinks on the post.** An optional "Linked from" list under a post, showing which of the author's posts link to it. It's built only from links the author already accepted, so nothing new needs approving.
- **Internal links stay the core.** All of today's linking work carries on unchanged.

Principle 8 (no keyword research, no trend chasing) is unchanged. The SEO here is about the site's own structure.

### 4.4 The reader-facing garden

Today a reader sees a stage badge. A garden should be somewhere a reader can wander.

- **Garden index.** A block (or page template) listing the author's posts by Map group and growth stage. It's the "topography over timeline" view from handover 3.
- **Stage badges and "Linked from"** on posts, both optional blocks.
- **"More like this"** (4.2).
- Later, with reader mode: hubs as real pages, and "Related by readers" (#97).

One visibility rule covers every reader-facing surface (the garden index, stage badges, "Linked from" and "More like this"). A post appears only if it is published, public, not password-protected, in the index, and not composted. "Linked from" applies the rule to the linking posts too, so a composted or private post never shows up as a source. The link inside its own content stays where the author put it.

Everything reader-facing is the author's own titles and words, arranged by structure the author approved. Principle 1 holds.

### 4.5 Cross-pollination

Added by Anna on 2026-10-25: creativity often comes from combining ideas that don't obviously belong together (Koestler's "bisociation"). Undergrowth already finds what is *close*. Cross-pollination finds pairs that are *apart* but could spark something.

- **Finding pairs.** Three signals, all from the author's own writing:
  - **sweet spot**: two posts in different Map groups with moderate similarity, neither obvious nor unrelated;
  - **bridges**: two groups with no links between them, and one post or distinctive phrase that sits near both;
  - **shared rare threads**: a distinctive word or idea that appears in two distant groups.
- **A surprise scale** (decided by Anna). A setting from "close cousins" to "wild", which moves the similarity band and how strong a bridge must be. The default sits towards the cautious end, so early results make sense. The author can turn it up.
- **Three outputs:**
  - **questions** in the Prompt studio, under every 5b rule (a question only, quoting only the author). Each weekly batch holds **at least one** cross-pollination question when a good pair exists (decided by Anna);
  - **bridge post ideas**: "a post connecting these two groups would link both halves of your garden". That is unexpected SEO from the author's own material, never keyword research (principle 8);
  - **cross-links**: link suggestions across groups, labelled as cross-pollination so the author knows why they're unusual, and approved like every other link (principle 2).
- **Where it shows:** a Cross-pollination view on the Map, with pairs of groups and the bridging post or phrase between them. Dismiss and veto work as everywhere else, and are kept forever.

## 5. Decisions so far

- **Prompts:** a weekly batch of five, plus on-demand generation with a clear cost notice when the batch runs out (Anna, 2026-10-25).
- **Popularity:** pluggable (Jetpack Stats, Koko Analytics, Post Views Counter, or none). No built-in counter (Anna, 2026-10-25).
- **Not ready to submit yet** (Anna, 2026-10-25). v1 stays unreleased until this direction is settled.
- **No profit, ever.** GPL, open source, no premium tier, upsells, ads, affiliate links or donate link.

## 6. Decisions (confirmed 2026-10-25: every recommendation accepted)

- **D1. Release shape.** Option (a): ship v1 as it is now and build the pillars as 0.2, 0.3 and so on. Option (b): hold the first release until the Prompt studio and "More like this" exist, so the first public version feels like Undergrowth. Recommendation: (b), because first impressions on WordPress.org are hard to change, and those two are what make the plugin distinct.
- **D2. Approval for "More like this".** Principles 2 and 7 say a human approves every reader-visible link. A list that changes with decay and popularity can't be approved link by link. Options: (a) placing the block is the approval, with a per-post way to hide a suggestion forever (a veto, kept like every other veto); (b) the author approves each post's list, and again whenever it changes. Recommendation: (a). The block is a visible, author-placed feature whose rules are stated, and the veto keeps the author in charge.
- **D3. Garden index.** A block the author places anywhere, or a ready-made page. Recommendation: a block, which is more native to WordPress and works in any theme.
- **D4. What the monthly email says about prompts.** Recommendation: one line, "New questions are waiting in your Prompt studio", only when the batch is fresh and unread, and only for authors who opted into the email.
- **D5. Order of the reader-mode parts.** Reader mode (v2) stays gated behind the meter. Recommendation: build the four pillars on germination data first, and add reader signals to them later.

## 7. Suggested build order

(Cross-pollination, added later, follows hubs and feeds the Prompt studio's weekly batch.)

1. **Prompt studio**: batch, idea bank, on-demand with the cost notice. It replaces the single Dashboard question.
2. **"More like this" block**: meaning plus stage plus freshness first, then popularity adapters.
3. **Garden SEO without reader data**: orphans, the "Linked from" block, and "tend this post".
4. **Garden index block.**
5. **Hubs**, proposed from dense Map groups.
6. **First public release** (if D1 is (b)).
7. **Reader mode (v2)** as already proposed, feeding scent into everything above.

Each step becomes issues and lanes in the usual way once Anna has confirmed this document.

## 8. What stays exactly as it is

- The AI speaks to the author, never for the author. No model text reaches readers.
- A human approves every change, and a veto is kept forever.
- Signals are data, never instructions.
- The loop is fixed. Nothing tunes itself from what gets accepted.
- No keyword research, no trend chasing.
- Work happens in WP-Cron, never in the request that saves a post.
- Free, GPL and open source.
