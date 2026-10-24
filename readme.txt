=== Undergrowth ===
Contributors: annamcphee
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

The AI may speak to you, the author. It never speaks for you. Undergrowth does not write, rewrite or generate any text your readers see, and it does no keyword research, trend tracking or competitor analysis. It works from your own posts and, for some questions, from approved comments, which it treats as data, never as instructions.

* **Link suggestions.** Each post gets up to three suggested links to your closest other posts, each at a sentence of your own. Accept one and Undergrowth links a few of your words in that sentence to the other post, keeping a revision. Veto one and it is never suggested again.
* **Growth stages.** Each post is a Seedling, Sprout, Budding or Evergreen, measured once a month from the links you have accepted and the clusters your posts form. Stages never go down, and you can pin one.
* **Composting.** Let a post rest. It stays published and keeps its links, but gets no new suggestions. When it sits close to what you are writing now, Undergrowth tells you once.
* **One question at a time.** With an Anthropic key, Claude writes an occasional question on your Dashboard, from a gap between your posts, a reader's question in an approved comment, or a composting post. It is always a question, never a draft, and it quotes nobody but you. There are no streaks and no reminders.
* **A plain-text file for each post**, recording its stage, its suggestions, your vetoes and the questions you were asked.
* **A Growth stage block** that shows readers a post's stage as a line of text your theme styles.
* **Discord, optionally.** Each new suggestion can be posted to a private channel in your own Discord server, with Accept and Veto buttons.
* **A readers meter.** With Jetpack Stats on, your page views in the last 30 days against a milestone of 1,000. Passing it changes nothing in this version.
* **Abilities** for agents and other plugins, through the WordPress Abilities API, to read stages and suggestions and to accept or veto a suggestion as the buttons do.

Undergrowth finds which posts are close by turning each into a vector with an embedding model: by default OpenAI's text-embedding-3-small, with your own API key. Clusters and gaps are then found by arithmetic on your server.

= External services =

Undergrowth contacts three services, each only once you set it up: OpenAI, to read your posts, once you add its key; Anthropic, to write your Dashboard question, once you add its key; and Discord, once you fill in its settings. It also reads one number through Jetpack Stats when Jetpack is connected and Stats is on. Each is a service you sign up to yourself. The first four questions in the FAQ say what each is sent, when, and where its terms and privacy policy are. Undergrowth sends nothing to its developers and loads nothing from outside the plugin.

== Installation ==

1. Install and activate the plugin.
2. Go to Settings > Undergrowth and paste an OpenAI API key. The page says what is sent before anything is. With neither a key nor your own provider through the `undergrowth_embed_provider` filter, Undergrowth sends nothing and suggests nothing.
3. Posts you published earlier are read in the background as WP-Cron runs. The settings page shows how many are done.
4. Optionally, add an Anthropic API key for the Dashboard question, and set up Discord (see the FAQ).

Suggestions appear on Posts > Undergrowth shortly after a post is read. Clusters, growth stages and questions follow on the monthly cycle.

= Requirements =

* WordPress 6.9 or later, for the Abilities API.
* PHP 7.4 or later, with the mbstring extension.
* WP-Cron, or a real cron job calling it.
* For Discord only: a site reachable from the internet over HTTPS.

== Frequently Asked Questions ==

= What is sent to OpenAI? =

With an OpenAI key saved, the title and text of each published post that is not password-protected are sent to OpenAI's embeddings API when the post is read, and again when you change it. Very long posts are shortened to fit the model. OpenAI returns a vector, which nobody sees. Drafts, private posts and comments are never sent. Requests go to `api.openai.com` from WP-Cron, under [OpenAI's services agreement](https://openai.com/policies/services-agreement/) and [privacy policy](https://openai.com/policies/privacy-policy/). Remove the key to stop.

= What is sent to Anthropic? =

With an Anthropic key saved, each request sends one post's title and a few sentences quoted from it, or, for a gap, one sentence from each of up to three posts. For a reader's question, that comment's text is sent too, as data, and Claude is told not to follow instructions in it. Whole posts are never sent. A question about a composting post is sent once more, on its own, to be checked. A reply is kept only if it is a single question that quotes nobody but you, and it is shown only on your Dashboard. Requests go to `api.anthropic.com` from WP-Cron, a handful a month at most, under [Anthropic's commercial terms](https://www.anthropic.com/legal/commercial-terms) and [privacy policy](https://www.anthropic.com/legal/privacy). Remove the key to stop.

= What is sent to Discord? =

Only if you set Discord up: for each new suggestion, the titles of both posts, the suggested sentence, which is your own words, and links to both posts. Messages go to `discord.com` from WP-Cron. Clear the channel ID to stop. Discord's [terms of service](https://discord.com/terms) and [privacy policy](https://discord.com/privacy) apply.

= What does the readers meter read from Jetpack? =

Only the site's total page views for each of the last 30 days, added up into one number, once a day. Jetpack fetches them from WordPress.com as it does for its own Stats screen, under the [WordPress.com terms of service](https://wordpress.com/tos/) and [Automattic's privacy policy](https://automattic.com/privacy/). Undergrowth contacts no one itself, and shows the number only to users Jetpack lets see Stats.

= What does it cost? =

Undergrowth is free. The services bill your own accounts. At OpenAI's published price for text-embedding-3-small, reading 500 posts of about 1,500 words costs about two cents. Each question from Claude is one or two short requests, usually a few cents each.

= Does it need a key to work at all? =

Link suggestions, clusters and growth stages need an embedding provider: an OpenAI key, or your own through the `undergrowth_embed_provider` and `undergrowth_embed_identity` filters. [An example](https://github.com/hello-nova-claw/undergrowth/blob/main/docs/examples/local-embeddings.php) uses a model on your own server, such as Ollama, so nothing leaves it. Questions need an Anthropic key. With neither, Undergrowth sends nothing and waits.

= Who can read my keys? =

Keys saved on Settings > Undergrowth are stored in the database, so anyone with access to the database or its backups can read them. To keep them out of the database, define them in `wp-config.php` instead:

`define( 'UNDERGROWTH_OPENAI_API_KEY', 'your-openai-key' );`
`define( 'UNDERGROWTH_ANTHROPIC_API_KEY', 'your-anthropic-key' );`

The settings page then says the key is in `wp-config.php` and never shows it.

= Where does Undergrowth keep its notes about each post? =

In a plain-text file for each post, in `wp-content/uploads/undergrowth-<random>/posts/`. Settings > Undergrowth shows the exact folder. A file holds the post's title, address, words and sentences from your own post, the addresses of other posts, and the questions you were asked. Their headings are in English and their dates in UTC, whatever the site's language and time zone, so the plugin can read them back. On Apache an `.htaccess` file refuses web access. The folder's random name is hard to guess but is no protection on its own, so on nginx, or any server that ignores `.htaccess`, add the deny rule from [the per-post files page](https://github.com/hello-nova-claw/undergrowth/blob/main/docs/per-post-files.md), which also shows an example file.

= What does deleting the plugin remove? =

Deleting Undergrowth on the Plugins screen removes its database tables, its settings and saved keys, the growth stages and its other post meta, and the per-post files, on every site of a network. Keys in `wp-config.php` stay there. Links you accepted stay in your posts. Deactivating only stops its scheduled work.

= Setting up Discord =

Create a new Discord application just for Undergrowth, never an existing bot, then follow [the Discord setup steps](https://github.com/hello-nova-claw/undergrowth/blob/main/docs/discord.md). Your site must be reachable from the internet over HTTPS. Posts > Undergrowth does everything Discord does.

== Screenshots ==

1. Posts > Undergrowth: each suggested link with the sentence in your own post where it would fit, ready to accept or veto.
2. The Growth stages tab: every post's stage, with a pin to keep one as it is.
3. The Map tab: your posts in groups that are close in meaning, with a flag on any group that circles something none of them is about yet.
4. Settings > Undergrowth: the keys, what is sent to each service, and how many of your posts have been read.
5. The Dashboard widget with the one question waiting for you, to snooze until next month or dismiss for good.
6. The Growth stage block on a post, showing readers its stage as a line of text your theme styles.

== Changelog ==

= 0.1.0 =
* First release.
