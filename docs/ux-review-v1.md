# UX review: Undergrowth v1

Reviewed on 2026-10-21 against `main` at 9b9abbb, as a first-time blog owner who is not a developer would meet it. Sources: every shot from `npm run screenshots` (55 states, 3 locales, 2 widths), the screen code under `src/`, and handover sections 2, 4, 5, 5b and 7. Shots are named `<screen>--<state>--<locale>--<width>.png`, as in the Screenshots comment on any pull request.

Severity: **High** stops a new owner or breaks a principle in the handover. **Medium** makes the product harder to understand or less like WordPress. **Low** is polish.

## Summary

The pieces are careful. Each screen uses core markup, says honestly what is sent where, and keeps the tone rules: no streaks, no day counts on compost, no cheerleading, and the Dashboard question says Claude wrote it. Notices take focus, buttons carry names that include both posts, and RTL and the pseudo-locale hold up at both widths.

What is missing is the path between the pieces. A new owner is given no next step after activating. On a phone, the suggestions table hides every suggestion's content. A widget on every Jetpack-less Dashboard asks for Jetpack. And the product is spread across six places under three names, so it reads as several features rather than one garden.

Six copy and markup fixes are open as pull requests. Nine structural findings are issues for Anna to decide.

## Findings

| ID | Severity | Finding | Issue | Status |
|---|---|---|---|---|
| H1 | High | No next step after activation | #146 | Decide |
| H2 | High | Suggestions unreadable at 390px | #147 | Decide |
| H3 | High | Meter widget asks for Jetpack on every site without it | #148 | Decide |
| M1 | Medium | Undergrowth is spread over six places and three names | #149 | Decide |
| M2 | Medium | Settings page has no structure for its three parts | #150 | Decide |
| M3 | Medium | Failures are reported as HTTP status codes | #151 | Decide |
| M4 | Medium | Accept doesn't show which word it will link | #152 | Decide |
| M5 | Medium | Resurfacing notice is on the wrong tab | #153 | Decide |
| M6 | Medium | New words are explained nowhere; no Help tabs | #154 | Decide |
| M7 | Medium | Jargon: "embedding provider", "vectors", "indexed", "cluster" | #139, #140, #141 | Fixed in PRs |
| M8 | Medium | Meter titled "Readers" but counts page views | #142 | Fixed in PR |
| L1 | Low | Plugins screen has no Settings link | #143 | Fixed in PR |
| L2 | Low | Quoted sentence takes the admin's direction, not its own | #144 | Fixed in PR |
| L3 | Low | Stage summary lists zero counts | #155 | Decide |
| L4 | Low | Result notices cannot be dismissed | #156 | Decide |
| L5 | Low | Badge block's editor note looks like post text | #157 | Decide |
| L6 | Low | Each Discord field re-reads the whole section intro | #158 | Decide |

### H1. No next step after activation (High, #146)

Shots: `settings--no-key--en_US--1280.png`, `suggestions--no-provider--en_US--1280.png`.

After activating, the Plugins row offers only Deactivate, and no notice appears. Settings > Undergrowth opens on an empty password field with no sentence saying what the plugin does, which key turns on which feature, or where to get one. Posts > Link suggestions, the screen the readme points to, said only that "an embedding provider" was needed.

Fix: a Settings link on the Plugins row (done in #143). Then one notice on the Plugins screen after activation, while no key is saved, dismissible for good. Add a one-sentence intro at the top of Settings, a link beside each key field to the provider's key page, and a link on to Posts > Link suggestions once the first key is saved.

### H2. Suggestions unreadable at 390px (High, #147)

Shots: `suggestions--table--en_US--390.png`, `stages--table--en_US--390.png`, `stages--resurfacing--en_US--390.png`.

At phone width, `WP_List_Table` keeps only the primary column, here "In this post". The owner sees "Oca, Oca, Yacon, Yacon…" and must open each row to find the sentence, the target and the Accept and Veto buttons. Growth stages hides the stage and Pin the same way.

Fix: follow core's Comments screen. Make the quoted sentence the primary column, put "Link to …" under it, and make Accept | Veto row actions. On Growth stages, show the stage beside the title and make Pin a row action.

### H3. Meter widget asks for Jetpack on every site without it (High, #148)

Shots: `dashboard--meter-no-stats--en_US--1280.png`, `dashboard--meter-below--en_US--390.png`, `dashboard--meter-crossed--en_US--1280.png`.

Without Jetpack Stats, every Dashboard load shows a widget telling the owner to install Jetpack, connect it to WordPress.com and turn on Stats. It does this for a meter that, by its own words, changes nothing in this version. That is a standing nudge toward another product, against the handover's "never nags" rule (5b) and its rule that the meter shows one number and nothing else (4). With Stats, the widget is four paragraphs long.

Fix (a decision for Anna, since #103 chose this deliberately): no widget without Stats, with the requirement stated once on the Settings page or in a Help tab. With Stats, show the bold number line and at most one more line, and move both switch-line rules to a Help tab so they are still documented in the UI.

### M1. Spread over six places and three names (Medium, #149)

Shots: `suggestions--table--en_US--1280.png`, `posts--composting--en_US--1280.png`, `dashboard--prompt--en_US--1280.png`, `profile--discord-user--en_US--1280.png`.

The product currently lives in six places:

- Settings > Undergrowth.
- Posts > **Link suggestions**, which also holds Growth stages and Map.
- The Posts list, which has the Compost action and the Composting view.
- Two Dashboard widgets, "A question for you" and "Readers, last 30 days", neither of which names the plugin.
- The user profile, which holds the Discord ID.

A suggestions switch, with its own Save button, sits on a list screen. Each place is reasonable, but nothing ties them together.

Recommended layout:

| Where | What |
|---|---|
| Settings > Undergrowth | Everything that sends data out: OpenAI key and progress, the "Suggest links between my posts" switch, Anthropic key, Discord. Sections per M2. |
| Posts > Undergrowth (renamed from Link suggestions) | Tabs Suggestions, Growth stages, Map. Resurfacing on Map (M5). Help tabs (M6). No settings form. |
| Posts list | A Growth stage column beside Compost and the Composting view, so a post's state sits with the post. |
| Dashboard | One "Undergrowth" widget holding the question, plus the meter line only when Stats exist (H3). Or keep two widgets, each named for Undergrowth. |
| Profile | Discord user ID, as now, because core keeps per-user settings there. |
| Editor | Growth stage badge block, as now. |

### M2. Settings page has no structure (Medium, #150)

Shots: `settings--no-key--en_US--1280.png`, `settings--no-key--he_IL--390.png`, `settings--no-key--en_XA--390.png`, `settings--key-saved--en_US--1280.png`.

The OpenAI and Anthropic rows sit under no heading, while Discord gets an `h2` and an intro. Nothing says which key turns on which feature. The Anthropic description runs to seven sentences, and at 390px in `en_XA` it fills most of a screen. The key rows are hand-written `<tr>`s, unlike the Discord fields on the same page, which use the Settings API.

Fix: three Settings API sections, "Link suggestions", "Questions on your Dashboard" and "Discord (optional)". Give each a one-sentence intro. Cut field descriptions to two sentences about what is sent, and put the full detail in a Help tab.

### M3. Failures reported as status codes (Medium, #151)

Shots: `settings--last-error--en_US--1280.png`, `settings--prompt-last-error--en_US--1280.png`, `settings--discord-last-error--en_US--1280.png`.

"The last request to OpenAI failed on … (status 429)." The owner cannot act on 429, and "status 0" means nothing to them.

Fix: name the cause and keep the code in brackets. For 401 or 403, the key was not accepted. For 429, the owner is going too fast or the account is out of credit. For 0, the service could not be reached. For anything else, the service had a problem.

### M4. Accept doesn't show its word (Medium, #152)

Shots: `suggestions--table--en_US--1280.png`, `suggestions--notice-accepted--en_US--1280.png`.

The owner accepts a link without seeing which word it will make into a link, and finds out only from "See the change". Every row also has a blue primary Accept button, where core keeps one primary action per screen. "Link to" opens the front end while "In this post" opens the editor.

Fix: mark the word to be linked inside the quote (`<mark>`), using the same choice `undergrowth_accept_link()` makes. Make Accept a secondary button or a row action (H2). Link both titles the same way.

### M5. Resurfacing on the wrong tab (Medium, #153)

Shot: `stages--resurfacing--en_US--1280.png`.

The notice that a composting post is "near what you are writing now" sits on Growth stages, which lists no composting posts and no groups. Handover 5 puts the signal "on the map", and the Map tab now exists.

Fix: show it on the Map tab, and show that tab whenever a notice is waiting.

### M6. No Help tabs (Medium, #154)

Shots: `stages--table--en_US--1280.png`, `map--clusters--en_US--1280.png` and `settings--no-key--en_US--1280.png` have no Help button. Compare `dashboard--prompt--en_US--1280.png` and `posts--composting--en_US--1280.png`, where Help belongs to core and says nothing about Undergrowth.

A newcomer meets these words with no explanation anywhere:

- Seedling, Sprout, Budding and Evergreen, and what moves a post between them.
- Compost, which appears on the Posts list beside "Move to Trash" and so can read as a kind of deletion.
- Garden.
- A group "with a gap".
- Veto.

Core puts this kind of explanation in the Help tab, and every Undergrowth screen lacks one.

Fix: add Help tabs to Posts > Link suggestions (Suggestions, Growth stages, Map, Compost), to Settings (what each key sends), and a Compost tab on the Posts list that says the post stays published and nothing is deleted.

### M7. Jargon (Medium, fixed in #159, #160, #161)

Shots: `suggestions--no-provider--*`, `settings--index-*`, `map--clusters--*`, `stages--resurfacing--*`.

- "Link suggestions need an embedding provider. Add an API key in Settings" now reads "Link suggestions need an OpenAI API key. Add one in Settings". The switch, which did nothing without a key, is no longer shown (#159).
- "Turned into vectors" and "Index / 3 of 5 posts indexed" now read "so Undergrowth can tell which of your posts are about similar things" and "Posts read / 3 of 5 posts read", matching the suggestions screen's "read" (#160).
- "Cluster 1, with a gap" now reads "Group 1, with a gap", on the Map tab and in the resurfacing notice (#161). Code names are unchanged.

### M8. "Readers" counts views (Medium, fixed in #162)

Shot: `dashboard--meter-below--en_US--1280.png`.

The widget title said "Readers, last 30 days" over a count of page views. It now says "Page views, last 30 days".

### L1 to L6

- **L1. No Settings link on the Plugins row** (#143, fixed in #163). The catalogue had no Plugins screen. #163 adds `plugins--row--*`, which shows the link.
- **L2. Sentence direction** (#144, fixed in #164). In `suggestions--table--he_IL--1280.png`, English sentences showed as ".Oca grows here". The `<q>` now has `dir="auto"`.
- **L3. Zero counts** (#155). `stages--table--en_US--1280.png` reads "0 posts are sprouts. 0 posts are budding. 0 posts are evergreen.", which looks like a scorecard on a new site. Leave out the stages that have no posts. Today's tests assert the zeros, so this needs a decision.
- **L4. Notices not dismissible** (#156). `suggestions--notice-*`, `dashboard--notice-*` and `posts--notice-*` have no dismiss button. Core's "Post updated." does. Add `is-dismissible`.
- **L5. Badge block editor note** (#157). `block-growth-stage--editor-composting--en_US--1280.png` shows the note in the post's own font, so it reads as post content. Use core's Placeholder.
- **L6. Discord field descriptions** (#158). In `settings--discord-saved--en_US--1280.png`, each of the four Discord fields points `aria-describedby` at the whole intro of about 90 words. Give each field its own one-line description of where to find the value.

## What holds up

- **Tone.** No string counts days, keeps a streak or cheers the owner on. Composting never mentions time or nudges a post back. "Dismiss for good" and "Ask me next month" are plain, and the question states that Claude wrote it and only the owner can see it.
- **Honesty about data.** Each key's description says what is sent and that nothing is sent before a key is saved. Discord lists what each message carries.
- **Core patterns.** The screens use `.wrap`, a single `h1`, `nav-tab-wrapper` with `aria-current`, `WP_List_Table`, `.notice`, `.form-table`, row and bulk actions, a post state and a list view for Composting. There are no inline styles, and the only stylesheet is a single `overflow-wrap` rule.
- **Keyboard and screen readers.** Result notices take focus. Accept, Veto, Pin and Compost carry names with the post titles. Map headings carry the gap mark, so the heading list announces it.
- **RTL and long strings.** `he_IL` mirrors cleanly at both widths, and `en_XA` wraps without horizontal scroll at 390px.

## Not covered

- Real keys and real posts. Every shot uses the e2e provider, which also means the `settings--no-key` shots show an Index row a real site without a key would not show.
- A live screen reader pass. The keyboard and screen reader notes come from the markup and the axe runs, not from VoiceOver or NVDA.
- The front end beyond the growth stage badge.
