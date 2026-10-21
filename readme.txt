=== Undergrowth ===
Contributors: novaclaw
Tags: internal links, digital garden, site structure, seo
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Uses AI to read your site and ask you questions. It never writes a word your readers will see.

== Description ==

Undergrowth treats your site like a garden. It reads your published posts and asks you about them. It never writes for you, and it changes a post's text only when you accept a suggestion.

The AI may speak to you, the author. It never speaks for you. Undergrowth does not write, rewrite or generate any text your readers see, and it does no keyword research, trend tracking or competitor analysis. It works from your own posts and, for some questions, from approved comments your readers left, which it treats as data, never as instructions.

What it does in this version:

* **Link suggestions.** Each post gets up to three suggested links to your closest other posts, each with the sentence of your own where the link would fit. Accept one and Undergrowth links one word of that sentence to the other post, keeping a revision. Veto one and it is never suggested again. Suggestions are listed on Posts > Link suggestions.
* **Growth stages.** Each post has a stage, Seedling, Sprout, Budding or Evergreen, measured once a month from the links you have accepted and the clusters your posts form. Stages never change when you save a post, and the monthly cycle never lowers one. You can pin a stage to keep it as it is.
* **Composting.** Move a post to compost when you want it to rest. It stays published and keeps its links, but leaves the active garden: no new suggestions, no stage changes, no timer. When a composting post sits close to what you are writing now, Undergrowth tells you once. Return it to the garden whenever you like.
* **One question at a time.** With an Anthropic key, Claude writes you an occasional question on your Dashboard, drawn from a gap between your own posts, a reader's question in an approved comment, or a post you composted. It is always a question, never a draft, a title or an outline, and it quotes nobody but you. Dismiss it for good or ask to see it next month. There are no streaks and no reminders.
* **A plain-text file for each post**, in your uploads folder, recording its stage, its suggestions, your vetoes and the questions you were asked.
* **A Growth stage block** that shows readers a post's stage as a small line of text your theme styles.
* **Discord, optionally.** Each new link suggestion can also be posted to a private channel in your own Discord server, with Accept and Veto buttons.
* **Abilities** for agents and other plugins, through the WordPress Abilities API, to read a post's stage and suggestions and to accept or veto a suggestion as the buttons do.

Undergrowth works out which posts are close by turning each post into a vector, a list of numbers, with an embedding model. By default that is OpenAI's text-embedding-3-small, using your own API key. Clusters and gaps are then found by arithmetic on the stored vectors, on your server.

Readers come into it later. A future version reads where readers go and what they ask for, once a site has enough visits. This version works from your posts and their approved comments only.

== Installation ==

1. Upload the plugin through Plugins > Add New > Upload Plugin, or unzip it into `wp-content/plugins/undergrowth/`, and activate it.
2. Go to Settings > Undergrowth and paste an OpenAI API key. The page says what is sent before anything is. Without a key, or a provider of your own through the `undergrowth_embed_provider` filter, Undergrowth sends nothing and suggests nothing.
3. Posts are read when they are saved, so posts published before the key was saved are not read yet. To read them now, select them on Posts > All Posts, choose Edit from Bulk actions, and press Update without changing anything.
4. Optionally, paste an Anthropic API key on the same page to get the occasional question on your Dashboard.
5. Optionally, set up Discord. See Setting up Discord below.

Suggestions appear on Posts > Link suggestions a few moments after a post is read. Clusters, growth stages and questions follow on the monthly cycle.

= Requirements =

* WordPress 6.9 or later, for the Abilities API.
* PHP 7.4 or later.
* WP-Cron, or a real cron job calling it. Every request to an outside service runs from cron, so the editor never waits for one.
* For Discord only: a site reachable from the internet over HTTPS.

== Frequently Asked Questions ==

= What is sent to OpenAI? =

With an OpenAI key saved, the title and text of each published post that is not password-protected are sent to OpenAI's embeddings API when the post is read, and again when you change it. Very long posts are shortened to fit the model's limit. OpenAI returns a vector; nothing it returns is shown to anyone. Drafts, private posts and comments are never sent. Remove the key on Settings > Undergrowth to stop.

= What is sent to Anthropic? =

With an Anthropic key saved, each request to Claude sends one post's title and a few sentences quoted from it, or, for a gap between your posts, one sentence from each of up to three of them. When the question comes from a reader's comment, that comment's text is sent too, as data, never as instructions. Whole posts are never sent. A question about a composting post is sent back once more, on its own, to be checked. Claude's reply is kept only if it is a single question that quotes nobody but you, and it is shown only on your Dashboard.

= What is sent to Discord? =

Only if you set Discord up: for each new link suggestion, the titles of both posts, the suggested sentence, which is your own words, and links to both posts. Nobody can be mentioned from a message. Button clicks come back to your site signed by Discord, and are checked against your application's public key.

= What does it cost? =

Undergrowth is free. The services it uses bill your own accounts. Embedding is very cheap: at OpenAI's published price of $0.02 per million tokens for text-embedding-3-small, reading 500 posts of about 1,500 words each costs about two cents, and each later edit a fraction of a cent. Each question from Claude is one or two short requests, usually a few cents each, and at most a handful are written each month.

= Does it need a key to work at all? =

Link suggestions, clusters and growth stages need an embedding provider: an OpenAI key, or your own provider through the `undergrowth_embed_provider` and `undergrowth_embed_identity` filters, for example a small model on your own server. Questions need an Anthropic key. With no key and no provider of your own, Undergrowth sends nothing and does nothing but wait.

= Where does Undergrowth keep its notes about each post? =

Each published post that has been indexed gets a plain-text file in the site's uploads folder, named after the post's ID: `wp-content/uploads/undergrowth/posts/` on a single site, or `wp-content/uploads/sites/<site ID>/undergrowth/posts/` on each site of a multisite network. You can read it with any text editor. It holds only the post's title, address and slug, words and sentences from your own post, the addresses of other posts, and the questions Undergrowth asked you about it. Nothing in it is written for your readers.

A file looks like this:

    # Storing oca
    url: https://example.com/storing-oca/
    slug: storing-oca
    updated: 2026-11-01T09:00:00+00:00

    ## stage
    sprout
    - 2026-11-01: seedling -> sprout (monthly cycle)

    ## resurfacing

    ## has
    words: oca, tubers, frost, sweeten, sand, winter, store, harvest
    - https://example.com/growing-oca/

    ## lacks
    - https://example.com/yacon-syrup/ | "I keep a few yacon plants nearby for their syrup."

    ## scent

    ## proposed links
    - https://example.com/yacon-syrup/ | "I keep a few yacon plants nearby for their syrup."

    ## accepted links

    ## vetoes
    - https://example.com/tax-returns/

    ## prompts
    - 2026-11-01: asked (comment) "A reader asked how you keep oca through the winter. What do you actually do?"
    - 2026-11-03: snoozed (comment) "A reader asked how you keep oca through the winter. What do you actually do?"

The stage section names the post's growth stage, marked `(pinned)` when you have pinned it, then one line for each change: the date, the old and new stage, and whether the monthly cycle or you made it. The resurfacing section lists, with the date, each `signal shown` when a composting post is found close to what you are writing now, and each `signal dismissed by the author`. The prompts section lists every question Undergrowth asked you about the post on your Dashboard, and every time you dismissed or snoozed one, with the date and where the question came from. A reader's own words are never written here. The has section lists the words from your post that most set it apart from your other posts, then the published posts it links to. The lacks section lists the links suggested for it that you have not yet accepted or vetoed, best first, each with the sentence from your post where it fits. The scent section stays empty until a later version fills it. The file is rewritten whenever the post itself, its proposals, stage, resurfacing record or prompts change, so edits to it are not kept. The plugin can read a file back to restore a post's stage, pin and history; it reads only the stage and resurfacing sections and ignores the rest.

= Can visitors open these files? =

On Apache, no: the folder holds an `.htaccess` file that refuses web access. nginx ignores `.htaccess`, so on nginx add a rule like this to your server block:

    location ~ ^/wp-content/uploads/(sites/[0-9]+/)?undergrowth/ { deny all; }

= Setting up Discord =

Discord is optional. Posts > Link suggestions does everything Discord does. With Discord set up, each new link suggestion is also posted to a private channel in your own server, with Accept and Veto buttons. Your site must be reachable from the internet over HTTPS, because Discord sends each button click to it.

1. In the Discord developer portal, create a new application just for Undergrowth. Do not reuse an existing bot: once an application has an Interactions Endpoint URL, Discord sends its interactions to that address instead of over the gateway, which can stop an existing bot from working.
2. Copy the Application ID and the Public Key from its General Information page into Settings > Undergrowth.
3. On the Bot page, reset the token and paste it into Bot token. Undergrowth never shows it again.
4. Invite the bot to your server with the scope `bot` and the permissions View Channel and Send Messages, and give it access to a private channel. Anyone who can read the channel sees your suggestions.
5. In Discord, turn on Developer Mode (User Settings > Advanced), right-click the channel, choose Copy Channel ID, and paste it into Channel ID for link suggestions. Save the settings.
6. Copy the Interactions endpoint URL shown on the settings page into Interactions Endpoint URL on the application's General Information page, and save it there. Discord checks the address before it accepts it.
7. An administrator enters the Discord user ID of each person who may press the buttons: on Users > Profile for their own account, or on Users > All Users > Edit for anyone else. A click works only for a linked Discord user whose WordPress account can edit the post; anyone else gets a private refusal and nothing changes.

Each message sends Discord the titles of both posts, the suggested sentence, which is your own words, and links to both posts. Only suggestions made after you set the channel are posted.

== Changelog ==

= Unreleased =
* Project scaffold: contribution rules, templates and CI.
* Published posts are turned into vectors on save when a site supplies an embedding provider through the undergrowth_embed_provider filter. No post content leaves the site otherwise.
* New Settings > Undergrowth page for an OpenAI API key. With a key saved, published posts are embedded with OpenAI text-embedding-3-small; the page says what is sent before anything is.
* Each published post now gets up to three proposed links to your closest other posts. Proposals are stored for review; nothing is added to a post until you accept it.
* Each proposed link now names the sentence in your post where it fits, in your own words with formatting removed.
* Each indexed post now has a readable plain-text file listing its proposed, accepted and vetoed links, in the site's uploads folder under undergrowth/posts/.
* New Posts > Link suggestions screen listing every proposed link with the sentence it would sit in. Veto a suggestion so it is never made again, or switch suggestions off.
* Accept a suggestion to link one word of your own sentence to the suggested post. A revision is kept, and nothing changes when the word can't be linked safely.
* Each post now has a growth stage in post meta, undergrowth_stage, that themes and the REST API can read. Every post starts as a Seedling.
* Growth stages are measured once a month from the links you have accepted: a post with two or more becomes a Sprout. Stages never change when you save a post, and the monthly cycle never lowers one.
* Each post's file now names its growth stage and lists every change to it, with the date and what made it.
* A post's growth stage can be pinned, so the monthly cycle leaves it alone. A pinned stage is marked in post meta, undergrowth_stage_pinned, and in the post's file.
* A post can be moved to compost, a rest you choose, and returned to the garden whenever you like. A composting post stays published and keeps its accepted links, but gets no new link suggestions in or out. It has no timer and never expires. On return its stage is measured again.
* Settings > Undergrowth now fits a phone screen when a translation has very long words.
* Suggestions no longer pick a sentence inside a quote, a pullquote or a Custom HTML block, since those can never be linked. The suggestion uses the next best sentence instead.
* New Growth stages tab on Posts > Link suggestions lists each post Undergrowth reads (published and not password-protected) with its stage. Pin a stage to keep it as it is, or unpin it to have it measured again. A pinned stage shows a pin marker.
* The Growth stages tab lists nothing when the undergrowth_post_types filter leaves no post type to read, instead of falling back to ordinary posts.
* Text inside a Custom HTML, quote or pullquote block is no longer offered as a suggestion's sentence when the block's markup contains a block comment inside an attribute.
* New abilities for agents and other plugins, through the Abilities API, each reading one post: undergrowth/has (its most distinctive words and the posts it links to), undergrowth/lacks (its open suggestions, each with the sentence quoted from the post), undergrowth/scent (empty until reader mode) and undergrowth/stage (its growth stage, pin and changes). Only someone who can edit the post can read them. They return only what comes from your posts and the plugin's own counting, never model output.
* New abilities undergrowth/accept-link and undergrowth/veto-link let code on your site accept or veto a suggestion, exactly as the buttons do, for a user who can edit the post. They are never available over the REST API or MCP.
* Posts are now grouped into clusters once a month, from the vectors already stored, before growth stages are measured. Only posts embedded with the same provider, model and language are compared, composted posts are left out, and three or more similar posts make a cluster. Nothing is sent anywhere to do this.
* A post in a cluster of three or more, with accepted links both to and from it, now becomes Budding on the monthly cycle. Evergreen also needs a hub post linking to it, and hubs come with reader mode, so for now a post reaches Evergreen only when you pin it.
* On the Posts screen, a published post now has a Compost link, and a composting post a Return to garden link. Both are bulk actions too. Composting posts are marked Composting and have their own view. They are left out of Posts > Link suggestions: no suggestion to or from one is listed, and the Growth stages tab does not list them.
* Settings > Undergrowth has a second key, for Anthropic, which will let Claude write you an occasional question about your own writing. The page says what is sent: one post's title, sentences quoted from it and, for a question from a reader, that comment's text. Nothing is sent until you save a key, and nothing written by Claude is ever shown to your readers.
* With an Anthropic key saved, Undergrowth looks once a month for a reader's question in an approved comment and asks Claude to turn it into one question for you. The reader's words are sent as data, never as instructions. A reply is kept only if it is a single question that quotes nobody but you; anything else is thrown away.
* New Dashboard widget shows the one question waiting for you, with the post it came from. Choose Ask me next month to see it again at the next monthly cycle, or Dismiss for good so that question never comes back. Nothing new appears until the next cycle, and there is never a list to clear. Every question, dismissal and snooze is written in the post's file.
* Each post's file now has a resurfacing section, ready to list each time an old composting post is shown as close to what you are writing now, and each time you dismiss that. The plugin can also read a post's file back, on this site or another, and restore the post's stage, pin and history, so a composting post stays composting. There is no import screen yet.
