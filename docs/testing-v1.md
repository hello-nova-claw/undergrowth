# Testing v1 with your own posts

This is a guide to trying Undergrowth 0.1.0 on a site with your real posts. It takes about an hour to set up. After that you mostly wait for things to show up and then look at them.

Use a local or staging copy of your site, not the live one. Undergrowth only changes a post when you press Accept, but a test is for finding surprises.

## 1. Get the zip

1. Open the repository's **Actions** tab on GitHub and choose the **Build** workflow.
2. Open the newest run on `main` and download **undergrowth.zip** from its Artifacts section. You need to be signed in to GitHub.
3. Don't unzip it.

## 2. Install it

You need WordPress 6.9 or later and PHP 7.4 or later. A local site from Studio, Local or wp-env works, and so does a staging copy.

1. Go to **Plugins > Add New > Upload Plugin**, choose `undergrowth.zip`, then **Install Now** and **Activate**.
2. Undergrowth does nothing yet. It sends nothing anywhere until you save a key.

## 3. Add your keys

Go to **Settings > Undergrowth**.

- **OpenAI API key** (needed). Paste a key from your own OpenAI account. Undergrowth uses it to turn each published post into a vector, a list of numbers that says what the post is about. It sends each post's title and text, and nothing else. The settings page says the same.
- **Anthropic API key** (optional). This is for the Dashboard questions. Each request sends one post's title and a few sentences quoted from it. If the question comes from a reader's comment, that comment's text goes too. Whole posts are never sent. **Wait until step 5 before you save this key** (see below).

Once you save a key, it is never shown again. The page only shows its last four characters. Never paste a key into an issue, a screenshot or this repository. If one slips out, revoke it in that provider's dashboard and make a new one.

The Discord section is optional, and you can skip it for a first test. If you want to try it, follow **Setting up Discord** in the plugin's readme.

## 4. Get your existing posts read

For now Undergrowth reads a post only when it is saved (#113 will fix this). So read the posts you already have like this:

1. Go to **Posts > All Posts** and show as many posts per page as you can (Screen Options, top right).
2. Select them all, choose **Edit** from **Bulk actions**, press **Apply**, and press **Update** without changing anything.
3. Do the same for each page of posts.

Each post is read in the background, a few at a time, as WP-Cron runs. On a local site, WP-Cron only runs when you load pages, so click around wp-admin for a minute. Within a few minutes, link suggestions start to appear on **Posts > Link suggestions**.

## 5. Run the monthly cycle now

Clusters, growth stages and questions only change on a monthly cycle. The first cycle ran when you activated the plugin, before any post had been read, so it found nothing. Run it again by hand:

- **With WP-CLI:** `wp cron event run undergrowth_cluster_cycle`, then `wp cron event run --due-now` two or three times, so the batches it queues get to run.
- **Without WP-CLI:** install the WP Crontrol plugin. Go to **Tools > Cron Events**, find `undergrowth_cluster_cycle` and choose **Run now**, then load a few admin pages.

The cycle groups your posts into clusters and then measures every post's stage. Now save the Anthropic key, if you are using one. Undergrowth writes its first question as soon as the key is saved, and a question about a gap between your posts needs clusters to exist first.

You can run the cycle as often as you like while testing. Stages only go up on each run, never down.

## 6. What it should cost

These are rough figures from the providers' published prices, checked on 2026-10-21.

- **OpenAI (text-embedding-3-small): $0.02 per million tokens.** One token is about three quarters of an English word, so a 1,500-word post is about 2,000 tokens. Reading 100 posts is about 200,000 tokens, or $0.004. Reading 1,000 posts is about $0.04. Each edit you save later reads that one post again. So reading your whole archive costs a few cents at most.
- **Anthropic (Claude Opus 5.5): $4 per million input tokens and $20 per million output tokens.** A question is one request: about 2,000 tokens in and usually under 1,000 out, counting the model's thinking. That is $0.008 + $0.02, about **3 cents per question**. The output is capped at 4,000 tokens, so a single question costs 9 cents at most. A question about a composting post is checked once more, which doubles that. Undergrowth writes one question when you save the key, and then one each monthly cycle. If Claude's reply breaks the rules, it is thrown away and Undergrowth tries the next source. Even if you run the cycle by hand ten times, it should come to under a dollar.

To be safe, set a small monthly spending limit on both provider accounts before you start.

## 7. What to check

Work through these in any order. Note anything that surprises you, reads oddly, or seems to speak *for* you rather than *to* you.

**Link suggestions** (Posts > Link suggestions, Suggestions tab)
- Does each suggestion connect two posts that really belong together?
- Is the quoted sentence a sensible place for the link?
- Is anything suggested that you would never link?

**Accept and Veto**
- Accept a suggestion. Undergrowth picks the word itself. Open the post: one word of that sentence should now be a plain link, and nothing else should have changed. A revision should be saved too. Does it pick a sensible word?
- Veto a suggestion. It should go away and not come back, even after you edit either post.

**Growth stages** (Growth stages tab)
- Do the stages match what you would expect? Seedling has 0 or 1 accepted links. Sprout has 2 or more. Budding is in a cluster, with links both in and out. Evergreen only happens if you pin it in this version.
- Pin a stage, run the cycle again, and check the stage stays where you pinned it. Then unpin it.

**Composting** (Posts > All Posts)
- Use the **Compost** link under a post's title. The post should stay published and keep its links, but drop out of Link suggestions and the Growth stages tab.
- Use **Return to garden**. The post's stage should be measured again from scratch, not restored and not reset.
- If a composting post sits close to a new cluster, the Growth stages tab may show one notice about it. Check its wording.

**The Dashboard question** (Dashboard, "A question for you")
- Is it one question, addressed to you, in your site's language?
- Does it quote only you? Does it avoid suggesting a title, an outline or a topic?
- Try **Ask me next month** and **Dismiss for good**. A new question only comes at the next cycle. To get one sooner, run `undergrowth_prompt_cycle` the same way as in step 5.

**The growth stage block**
- In the editor, add the **Growth stage** block to a post or to a Query Loop. Check how it looks on the front end with your theme. A composting post should show nothing.

**The threshold meter** (Dashboard, "Readers, last 30 days")
- This arrives with #104. If your build has it, the meter needs Jetpack with Stats on. Without Jetpack it should say so plainly.

**The per-post files**
- On the server, open `wp-content/uploads/undergrowth/posts/`. There is one file per post, named by post ID, for example `123.txt`. Check that each file only holds your own words, addresses, stages and the questions you were asked. The `has`, `lacks` and `scent` sections stay empty for now (#118).
- If the site runs on nginx, check that the folder can't be opened in a browser. See the readme FAQ.

## 8. Feedback checklist

Copy this into a new issue, tick what worked, and add a line about anything that didn't.

```markdown
**Site:** posts indexed: __ / __ · WordPress __ · PHP __ · local / staging

- [ ] Zip installed and activated without errors
- [ ] Settings page made clear what is sent where
- [ ] Existing posts were read after the bulk update
- [ ] Link suggestions mostly connect posts that belong together
- [ ] Quoted sentences are good places for the links
- [ ] Accept linked one word and changed nothing else
- [ ] Veto removed a suggestion for good
- [ ] Stages look plausible after running the cycle
- [ ] Pin and unpin behave as expected
- [ ] Compost and Return to garden behave as expected
- [ ] Resurfacing notice (if it appeared) reads well
- [ ] Dashboard question is one question, quoting only me
- [ ] Ask me next month and Dismiss for good work
- [ ] Growth stage block looks right in my theme
- [ ] Threshold meter shows a sensible state (if present)
- [ ] Per-post files hold nothing unexpected
- [ ] Costs on both provider dashboards match the estimate
- [ ] Nothing anywhere spoke *for* me

**Surprises:**

**Wording I'd change:**

**Anything that felt like a content factory:**
```
