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

Undergrowth treats your site like a garden. It reads your published posts, proposes links between them, and groups them into clusters so you can see where your writing is dense and where it has gaps. You approve or veto every proposal. Nothing changes on your site without you.

The AI may speak to you, the author. It never speaks for you. Undergrowth does not write, rewrite or generate any text your readers see, and it does no keyword research.

== Frequently Asked Questions ==

= Where does Undergrowth keep its notes about each post? =

Each published post that has been indexed gets a plain-text file in the site's uploads folder, named after the post's ID: `wp-content/uploads/undergrowth/posts/` on a single site, or `wp-content/uploads/sites/<site ID>/undergrowth/posts/` on each site of a multisite network. You can read it with any text editor. It holds only the post's title, address and slug, the addresses of other posts, and sentences quoted from your own post. Nothing in it is written for your readers.

A file looks like this:

    # Storing oca
    url: https://example.com/storing-oca/
    slug: storing-oca
    updated: 2026-11-01T09:00:00+00:00

    ## stage
    sprout
    - 2026-11-01: seedling -> sprout (monthly cycle)

    ## has

    ## lacks

    ## scent

    ## proposed links
    - https://example.com/yacon-syrup/ | "I keep a few yacon plants nearby for their syrup."

    ## accepted links

    ## vetoes
    - https://example.com/tax-returns/

The stage section names the post's growth stage, marked `(pinned)` when you have pinned it, then one line for each change: the date, the old and new stage, and whether the monthly cycle or you made it. The has, lacks and scent sections stay empty until later versions fill them. The file is rewritten whenever the post's proposals or stage change, so edits to it are not kept yet.

= Can visitors open these files? =

On Apache, no: the folder holds an `.htaccess` file that refuses web access. nginx ignores `.htaccess`, so on nginx add a rule like this to your server block:

    location ~ ^/wp-content/uploads/(sites/[0-9]+/)?undergrowth/ { deny all; }

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
