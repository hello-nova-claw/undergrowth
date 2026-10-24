# Per-post files

Undergrowth keeps a plain-text file for each published post it has read. This page shows what one holds and how to keep the folder private on nginx and Caddy. The plugin's readme has the short version.

## Where they are

Each published post that has been indexed gets a plain-text file in the site's uploads folder, named after the post's ID. The folder is `wp-content/uploads/undergrowth-<random>/posts/` on a single site, or `wp-content/uploads/sites/<site ID>/undergrowth-<random>/posts/` on each site of a multisite network. `<random>` is 12 random characters, 0 to 9 and a to f, made once for each site. Settings › Undergrowth shows the exact folder. You can read it with any text editor. It holds only the post's title, address and slug, words and sentences from your own post, the addresses of other posts, and the questions Undergrowth asked you about it. Nothing in it is written for your readers.

## What a file holds

Every file is a record in one fixed format, so the plugin can read it back: its headings and labels are in English and its dates in UTC, whatever the site's language and time zone. Titles, words and sentences from your posts stay as you wrote them.

```text
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
```

The stage section names the post's growth stage, marked `(pinned)` when you have pinned it, then one line for each change: the date, the old and new stage, and whether the monthly cycle or you made it. The resurfacing section lists, with the date, each `signal shown` when a composting post is found close to what you are writing now, and each `signal dismissed by the author`. The prompts section lists every question Undergrowth asked you about the post on your Dashboard, and every time you dismissed or snoozed one, with the date and where the question came from. A reader's own words are never written here. The has section lists the words from your post that most set it apart from your other posts, then the published posts it links to. The lacks section lists the links suggested for it that you have not yet accepted or vetoed, best first, each with the sentence from your post where it fits. The scent section stays empty until a later version fills it. The file is rewritten whenever the post itself, its proposals, stage, resurfacing record or prompts change, and once a month in any case, since how distinctive a post's words are depends on your other posts. Edits to it are not kept. The plugin can read a file back to restore a post's stage, pin and history; it reads only the stage and resurfacing sections and ignores the rest.

## Keeping the folder private

The folder holds an `.htaccess` file, which refuses web access on Apache, and a `web.config` file, which does the same on IIS. On any server, the folder's name ends in random characters that only Settings › Undergrowth shows, which makes its address hard to guess, and each folder holds an `index.php` so it is never listed. The random name is not access control: those files, or one of the rules below, are.

Undergrowth checks this for itself. Every hour, and whenever Site Health runs its tests, it writes a small file into the folder, asks for it at the site's own address and deletes it again. The first check runs in the background right after the first file is written. If the file comes back, Site Health shows a recommended improvement and Settings › Undergrowth shows a warning beside the folder. The prompts section, the questions Claude wrote, is written only once a check has found the folder refused: while it can be read, and before any check has answered, it is left out of every file, and the files already there lose it. An answer that proves nothing, such as a redirect, a password prompt, a server error, or uploads served from another host, counts as no answer. The section comes back the next time each file is written after a check finds the folder refused.

nginx and Caddy ignore `.htaccess` and `web.config`. On nginx, add this rule to your server block:

```nginx
location ~ "^/wp-content/uploads/(sites/[0-9]+/)?undergrowth(-[0-9a-f]{12})?/" { deny all; }
```

On Caddy, add these lines to your site block:

```caddy
@undergrowth path_regexp ^/wp-content/uploads/(sites/[0-9]+/)?undergrowth(-[0-9a-f]{12})?/
respond @undergrowth 403
```
