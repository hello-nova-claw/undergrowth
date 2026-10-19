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

== Changelog ==

= Unreleased =
* Project scaffold: contribution rules, templates and CI.
* Published posts are turned into vectors on save when a site supplies an embedding provider through the undergrowth_embed_provider filter. No post content leaves the site otherwise.
* Each published post now gets up to three proposed links to your closest other posts. Proposals are stored for review; nothing is added to a post until you accept it.
