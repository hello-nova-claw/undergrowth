=== Undergrowth ===
Contributors: annamcphee
Tags: internal links, digital garden, site structure, seo
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Links your posts to each other in your own words, and shows you what your archive is really about. The AI reads; you decide.

== Description ==

* **Your old posts get read again.** Each post gets up to three links to your closest other posts, each at a sentence you already wrote.
* **You see what you have been circling.** Once a month the Map groups your posts by meaning, names each group from your own words, and shows the gaps between them.
* **Questions to start your next post.** With an Anthropic key, Claude asks you questions in a Prompt studio, drawn from your posts and your readers' comments. Save the ones you like to an idea bank. Never a draft, never a streak.

You stay in charge:

* **One link per Accept, with a revision.** Nothing in a post changes until you accept a suggestion.
* **A veto is remembered.** A suggestion you veto is never made again.
* **Nothing leaves your site until you set up a service**, and nothing goes to the plugin's developers.

Internal links are SEO from a different direction: they come from what you actually wrote, between posts that belong together, so readers and search engines find your best work. The AI may speak to you, but never for you: nothing a model writes is shown to your readers, and approved comments are read as data, never as instructions.

Also in this version:

* **Growth stages.** Each post is a Seedling, Sprout, Budding or Evergreen, measured monthly from your accepted links and clusters. Stages never go down, and you can pin one.
* **Composting.** Let a post rest. It stays published and keeps its links, but gets no new suggestions. When it sits close to what you are writing now, Undergrowth tells you once.
* **Hub pages, if you want them.** Where a Map group is large and its posts already link to each other, Undergrowth proposes a hub page. Start a hub draft makes a draft holding only the group's titles as links, and you write and publish the rest. Undergrowth never publishes it. A published hub that links to a post counts towards Evergreen.
* **Cross-pollination.** The Map lists pairs of groups that sit apart but could spark something together, from your own posts: a post near both, two posts not far apart, or words both use that the rest of your site rarely does. A surprise scale, from Close cousins to Wild, sets how far apart a pair may be. A post may also get one link suggestion across groups, marked Cross-pollination, and each week's questions hold one about such a pair.
* **A plain-text file for each post**, recording its stage, suggestions, vetoes and questions.
* **A Growth stage block** that shows readers a post's stage as a line of text your theme styles.
* **Discord, optionally.** Each new suggestion can be posted to a private channel in your own Discord server, with Accept and Veto buttons.
* **A readers meter.** With Jetpack Stats on, your page views in the last 30 days against a milestone of 1,000.
* **Abilities** for agents and other plugins, through the WordPress Abilities API.

Undergrowth finds which posts are close by turning each into a vector with an embedding model: by default OpenAI's text-embedding-3-small, with your own API key.

= External services =

Undergrowth contacts three services, each only once you set it up: OpenAI, to read your posts, once you add its key; Anthropic, to write the questions in your Prompt studio, once you add its key; and Discord, once you fill in its settings. It also reads one number through Jetpack Stats when Jetpack is connected and Stats is on. The first four questions in the FAQ say what each is sent, when, and where its terms and privacy policy are. Undergrowth sends nothing to its developers and loads nothing from outside the plugin.

== Installation ==

1. Install and activate the plugin.
2. Go to Settings > Undergrowth and paste an OpenAI API key. The page says what is sent before anything is. With neither a key nor your own provider through the `undergrowth_embed_provider` and `undergrowth_embed_identity` filters, Undergrowth sends nothing and suggests nothing.
3. Posts you published earlier are read in the background as WP-Cron runs. The settings page shows how many are done.
4. Optionally, add an Anthropic API key for the Prompt studio, and set up Discord (see the FAQ).

= Requirements =

* WordPress 6.9 or later, for the Abilities API.
* PHP 7.4 or later, with the mbstring extension.
* WP-Cron, or a real cron job calling it. On a quiet site, or with `DISABLE_WP_CRON` and no server cron job, older posts may take days to be read; Settings and Site Health say so.
* For Discord only: a site reachable from the internet over HTTPS.

== Frequently Asked Questions ==

= What is sent to OpenAI? =

With an OpenAI key saved, the title and text of each published post that is not password-protected are sent to OpenAI's embeddings API when the post is read, and again when you change it. Very long posts are shortened to fit the model. OpenAI returns a vector, which nobody sees. A draft is sent only when you press Scan this draft in the editor: its title and text, once, and nothing about it is kept. Private posts, posts behind a password and comments are never sent. Requests go to `api.openai.com` from WP-Cron, or from the editor for a scan, under [OpenAI's services agreement](https://openai.com/policies/services-agreement/) and [privacy policy](https://openai.com/policies/privacy-policy/). Remove the key to stop.

= What is sent to Anthropic? =

With an Anthropic key saved, each request sends one post's title and a few sentences quoted from it, or, for a gap, one sentence from each of up to three posts, and for a cross-pollination pair, one sentence from each of its two posts. For a reader's question, that comment's text is sent too, as data, and Claude is told not to follow instructions in it. Whole posts are never sent. A question about a composting post is sent once more, on its own, to be checked. A reply is kept only if it is a single question that quotes nobody but you, and it is shown only in your Prompt studio. Requests go to `api.anthropic.com` from WP-Cron, at most ten for each batch. A batch runs once soon after you save the key, then once a week, and writes up to five questions. Each time you choose Replace in your Prompt studio, at most three more go straight away, and More questions sends at most six. All of this is under [Anthropic's commercial terms](https://www.anthropic.com/legal/commercial-terms) and [privacy policy](https://www.anthropic.com/legal/privacy). Remove the key to stop.

= What is sent to Discord? =

Only if you set Discord up: for each new suggestion, the titles of both posts, the suggested sentence, which is your own words, and links to both posts. Messages go to `discord.com` from WP-Cron. When a button is clicked, WP-Cron updates that message on `discord.com`. Clear the channel ID to stop. Discord's [terms of service](https://discord.com/terms) and [privacy policy](https://discord.com/privacy) apply.

= What does the readers meter read from Jetpack? =

Only the site's total page views for each of the last 30 days, added up into one number, once a day. Jetpack fetches them from WordPress.com as it does for its own Stats screen, under the [WordPress.com terms of service](https://wordpress.com/tos/) and [Automattic's privacy policy](https://automattic.com/privacy/). Undergrowth contacts no one itself.

= Does "More like this" count my visitors? =

No. Undergrowth has no view counter of its own. If Jetpack Stats, Koko Analytics or Post Views Counter is active, it reads your posts' views over the last 30 days from that plugin once a day (from Jetpack, only its most viewed 500), in the background, so recent views can lift a post a little. Recent days count more and older views fade. Nothing new leaves your site: Koko Analytics and Post Views Counter are read from your own database, and Jetpack fetches its numbers from WordPress.com as it does for its own Stats screen. With none of them, the block leaves popularity out, unless code on your site supplies views through the `undergrowth_post_popularity` filter. Settings > Undergrowth says which one is used.

= How do I make a garden page? =

Add a new page and choose Garden page from the patterns offered for it, or insert it later from the Undergrowth category of patterns. It sets a heading, an introduction for you to write, the number of posts in your garden, the garden filters and the garden feed, newest tended first. Change the heading, write your introduction and publish. Every block takes its colours and spacing from your theme. Readers see only your published posts that are public and not composting, and nothing a model wrote.

= What does it cost? =

Undergrowth is free. The services bill your own accounts. At OpenAI's published price for text-embedding-3-small, reading 500 posts of about 1,500 words costs about two cents. Each question from Claude is one or two short requests, usually one or two cents each.

= Does it need a key to work at all? =

Link suggestions, clusters and growth stages need an embedding provider: an OpenAI key, or your own through the `undergrowth_embed_provider` and `undergrowth_embed_identity` filters. [An example](https://github.com/hello-nova-claw/undergrowth/blob/main/docs/examples/local-embeddings.php) uses a model on your own server, such as Ollama, so nothing leaves it. Questions need an Anthropic key. With neither, Undergrowth sends nothing and waits.

= Why is there no question in my Prompt studio yet? =

Undergrowth writes a question only when a reader asks a question in an approved comment, a gap appears on the Map, or you compost a post. A new site may have none of these for a while. Settings > Undergrowth says so under the key, and the weekly batch keeps looking. Once there are questions, Posts > Prompt studio lists the new ones and the ones you saved to your idea bank, and the Dashboard says how many new ones wait.

= Who can read my keys? =

Keys saved on Settings > Undergrowth are stored in the database, so anyone with access to the database or its backups can read them. To keep them out of the database, define them in `wp-config.php` instead:

`define( 'UNDERGROWTH_OPENAI_API_KEY', 'your-openai-key' );`
`define( 'UNDERGROWTH_ANTHROPIC_API_KEY', 'your-anthropic-key' );`

The settings page then says the key is in `wp-config.php` and never shows it.

= Where does Undergrowth keep its notes about each post? =

In a plain-text file for each post, in `wp-content/uploads/undergrowth-<random>/posts/`. Settings > Undergrowth shows the exact folder. A file holds the post's title, address, words and sentences from your own post, the addresses of other posts, and the questions you were asked. Their headings are in English and their dates in UTC, whatever the site's language and time zone, so other tools can read them. `.htaccess` and `web.config` files refuse web access on Apache and IIS. Undergrowth checks every hour. If the files can be opened, Site Health and Settings say so and Claude's questions are left out of them; add the nginx or Caddy rule from [the per-post files page](https://github.com/hello-nova-claw/undergrowth/blob/main/docs/per-post-files.md), which also shows an example file.

= What does deleting the plugin remove? =

Deleting Undergrowth on the Plugins screen removes its database tables, its settings and saved keys, the growth stages and its other post meta, and the per-post files, on every site of a network. Keys in `wp-config.php` stay there. Links you accepted stay in your posts. Deactivating only stops its scheduled work.

= Setting up Discord =

Create a new Discord application just for Undergrowth, never an existing bot, then follow [the Discord setup steps](https://github.com/hello-nova-claw/undergrowth/blob/main/docs/discord.md). Your site must be reachable from the internet over HTTPS.

== Screenshots ==

1. Posts > Undergrowth: each suggested link with the sentence in your own post where it would fit, ready to accept or veto.
2. The Growth stages tab: every post's stage, with a pin to keep one as it is.
3. The Map tab: your posts in groups that are close in meaning, with any gap between them flagged.
4. Settings > Undergrowth: the keys, what is sent to each service, and how many of your posts have been read.
5. The Prompt studio: questions Claude wrote from your own posts, to save to your idea bank, replace or dismiss.
6. The Growth stage block on a post, showing readers its stage as a line of text your theme styles.

== Changelog ==

= 0.1.0 =
* First release.
