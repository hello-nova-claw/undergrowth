# Demo content

`demo/` holds a demo blog for demos, screenshots, Playground previews and testing, so that nobody's own writing is ever needed. Every word in it was written by authors who died long ago, in books published before 1929. Titles come from the authors' own chapter headings. Nothing in it was written, summarised or titled by a model.

| File | Posts | Used by |
| --- | --- | --- |
| `demo/undergrowth-demo.xml` | 361 posts, 2 pages, about 420,000 words | `WXR=demo npm run playground:real` |
| `demo/undergrowth-demo-small.xml` | 61 posts, 2 pages | The PR Playground preview, `npm run playground`, `WXR=demo-small npm run playground:real` |

Both are WordPress export (WXR) files, so they also import into any site through **Tools › Import › WordPress**.

## What is in it

Eleven texts that overlap in theme, so that groups, links across books and gaps appear on their own:

| Book | Author | Published | Gutenberg | Category |
| --- | --- | --- | --- | --- |
| *Wood and Garden* | Gertrude Jekyll (1843–1932) | 1899 | [#36279](https://www.gutenberg.org/ebooks/36279) | Gardening |
| *My Summer in a Garden* | Charles Dudley Warner (1829–1900) | 1870 | [#3135](https://www.gutenberg.org/ebooks/3135) | Gardening |
| *Elizabeth and Her German Garden* | Elizabeth von Arnim (1866–1941) | 1898 | [#1327](https://www.gutenberg.org/ebooks/1327) | Gardening |
| *Walden* | Henry David Thoreau (1817–1862) | 1854 | [#205](https://www.gutenberg.org/ebooks/205) | Nature |
| *Walking* | Henry David Thoreau | 1862 | [#1022](https://www.gutenberg.org/ebooks/1022) | Nature |
| *Wake-Robin* | John Burroughs (1837–1921) | 1871 | [#4203](https://www.gutenberg.org/ebooks/4203) | Nature |
| *Winter Sunshine* | John Burroughs | 1875 | [#4279](https://www.gutenberg.org/ebooks/4279) | Nature |
| *Travels with a Donkey in the Cévennes* | Robert Louis Stevenson (1850–1894) | 1879 | [#535](https://www.gutenberg.org/ebooks/535) | Travel |
| *An Inland Voyage* | Robert Louis Stevenson | 1878 | [#534](https://www.gutenberg.org/ebooks/534) | Travel |
| *Backlog Studies* | Charles Dudley Warner | 1873 | [#3134](https://www.gutenberg.org/ebooks/3134) | Home |
| *The Idle Thoughts of an Idle Fellow* | Jerome K. Jerome (1859–1927) | 1886 | [#849](https://www.gutenberg.org/ebooks/849) | Idle Thoughts |

Jerome is the outlier. *Winter Sunshine* ends with a journey to England and France, so it crosses from Nature into Travel.

- **Posts.** Each chapter, essay or diary entry is split at paragraph breaks into posts of 600–1,600 words, titled with its heading and "part 2" and so on where it runs to several. Only a chapter's first three posts are kept, so no one book takes over. Verse and quotations are Quote blocks.
- **Dates.** From February 2019 to November 2025. Each book is spread over the whole span, so themes interleave as on a real blog.
- **Terms.** One category per book, and up to two of six tags (Birds, Flowers, Food, Trees, Water, Winter) for words a post uses often.
- **Edge cases.** About one post in thirty is in Classic-editor markup. Four posts are drafts, one is private and one has the password `demo`. Two pages hold front matter by the authors: Jekyll's preface and Warner's dedication. All of these are in the small file too.
- **Credit.** Every post and page ends with a line naming the book, author, year and Gutenberg number, with a link to the book's page.
- **Images.** Every post has a featured image, one of the thirty illustrations in `demo/images/`, so the Garden page shows a picture above each title. See [Images](#images).

## Images

The Garden feed shows a post's featured image above its title, so the demo blog needs pictures. No open-access photograph could be fetched when they were added, so the images are flat illustrations in quiet colours, drawn by `bin/draw-demo-images` from a seed: ten motifs in three variants each, 1200 × 800 pixels, about 25 KB a file. Nothing in them is anyone's photograph or a model's work.

| Files | Source | Author | Licence |
| --- | --- | --- | --- |
| `birds-1.jpg` to `birds-3.jpg` | Drawn by `bin/draw-demo-images` | This repository | CC0 1.0 |
| `flowers-1.jpg` to `flowers-3.jpg` | Drawn by `bin/draw-demo-images` | This repository | CC0 1.0 |
| `food-1.jpg` to `food-3.jpg` | Drawn by `bin/draw-demo-images` | This repository | CC0 1.0 |
| `hearth-1.jpg` to `hearth-3.jpg` | Drawn by `bin/draw-demo-images` | This repository | CC0 1.0 |
| `hills-1.jpg` to `hills-3.jpg` | Drawn by `bin/draw-demo-images` | This repository | CC0 1.0 |
| `leaves-1.jpg` to `leaves-3.jpg` | Drawn by `bin/draw-demo-images` | This repository | CC0 1.0 |
| `moon-1.jpg` to `moon-3.jpg` | Drawn by `bin/draw-demo-images` | This repository | CC0 1.0 |
| `trees-1.jpg` to `trees-3.jpg` | Drawn by `bin/draw-demo-images` | This repository | CC0 1.0 |
| `water-1.jpg` to `water-3.jpg` | Drawn by `bin/draw-demo-images` | This repository | CC0 1.0 |
| `winter-1.jpg` to `winter-3.jpg` | Drawn by `bin/draw-demo-images` | This repository | CC0 1.0 |

Each post shows the motif of its first tag (Birds, Winter, Trees, Flowers, Water or Food), or of its category when it has no tag: flowers for Gardening, leaves for Nature, hills for Travel, a hearth for Home and the moon for Idle Thoughts. The variant comes from a hash of the post's title, so neighbours on the Garden page differ. The builder names the picture; it never reads the file.

In the WXR files each image in use is one attachment item, shared by every post that shows it through a `_thumbnail_id` meta, with its `wp:attachment_url` under `https://raw.githubusercontent.com/hello-nova-claw/undergrowth/main/demo/images/`. A real site that imports a file with **Download and import file attachments** ticked fetches them from there. The caption of each attachment holds its credit.

To replace an illustration with a photograph, put the file in `demo/images/` under the same name, or add a motif in `IMAGE_TAG_MOTIFS` and `IMAGE_CATEGORY_MOTIFS` in `bin/build-demo-content`, add its row to the table above with the file page, author and licence, and rebuild. Only public-domain or CC0 images, at most 1200 pixels on the long edge and about 150 KB, so the set stays small. A photograph needs no change to `bin/draw-demo-images`, which only draws the motifs it knows.

## Building it

```bash
python3 -I bin/build-demo-content
```

The builder downloads the plain-text books from gutenberg.org into `~/.cache/undergrowth-demo-content` once, checks each against the SHA-256 hash recorded in the script, and writes both files to `demo/`. The same books always give the same files, byte for byte, so `git diff demo/` after a build shows only what a change to the builder did. If Gutenberg corrects a book, the hash check fails: read the new text, update its hash, rebuild and commit the result. `--list` prints every post with its length, status and tags.

The downloads are untrusted data. They stay in their own cache directory, outside the repository, and are read as plain text only. Nothing in them is run.

```bash
python3 -I bin/draw-demo-images
```

draws the illustrations into `demo/images/` again, from Pillow. The same name always gives the same picture, though the JPEG bytes can differ between Pillow versions. The WXR files name the images and never depend on their bytes, so redrawing them does not change the WXR.

## The Playground preview

The preview imports the small file and uses the e2e test provider, so it needs no key. The demo posts have none of the provider's fixture words, so while the option `undergrowth_e2e_demo` is set, the provider counts every word of five letters or more, hashed into 2,048 places. Posts that share words still get similar vectors. It is a demo, not a model: it finds books on the same subject, not posts that mean the same thing.

The importer downloads each attachment from its `wp:attachment_url`, which a Playground site cannot reach: the browser has no network for PHP, and the local CLI would fetch the `main` branch's files rather than the commit under review. So the test site answers that download itself, through the `pre_http_request` filter in `tests/e2e/mu-plugins/undergrowth-e2e.php`, with the file from `demo/images/` beside the WXR files (mounted at `wp-content/undergrowth-demo/`, or inside the plugin folder where the preview installs the whole repository). The importer then creates the attachments and sets each post's featured image as it would on any site, and no blueprint needs a step for it.

The blueprint imports the posts without reading them, then calls `undergrowth_e2e_read_demo()` (in `tests/e2e/mu-plugins/undergrowth-e2e.php`). That reads every post, runs the monthly cycle once, and suggests links for each post once. Reading each post as it was imported took about three minutes; reading them together takes seconds.

It then publishes two pages of its own: "The Garden", made from the `undergrowth/garden-page` pattern and set as the home page, so the preview opens on the reader blocks, and "Posts", set as the posts page, so the blog index stays reachable. Twenty Twenty-Four and Twenty Twenty-One are installed, not activated, beside the default theme.

## Licensing

Every text is in the public domain in the United States, as each book's page on gutenberg.org says, because it was published before 1929. Each author died more than 70 years ago (the latest, Elizabeth von Arnim, in 1941), so the texts are also out of copyright in countries whose term is the author's life plus 70 years.

The Project Gutenberg License applies to the eBook files as Project Gutenberg distributes them, and "Project Gutenberg" is a registered trademark. Its terms ask anyone who redistributes a text without the license to remove the Project Gutenberg name and trademark; the public-domain text itself is then free to use. So the builder keeps only the authors' text: the header, footer, license, transcribers' notes and producers' credits are stripped from every post. The credit line at the end of each post names Gutenberg only as where the text came from, with the eBook number and URL, so a reader can find the source. It does not present the posts as Project Gutenberg eBooks.

The repository claims no rights in the authors' words, which stay in the public domain. The builder script is under the plugin's own license.

The illustrations in `demo/images/` are released under [CC0 1.0](https://creativecommons.org/publicdomain/zero/1.0/): no rights reserved. The script that draws them is under the plugin's own license.
