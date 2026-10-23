# Testing v1 with your own posts

This is a guide to trying Undergrowth 0.1.0 on a site with your real posts. It takes about an hour to set up. After that you mostly wait for things to show up and then look at them.

Use a local or staging copy of your site, not the live one. Undergrowth only changes a post's text when you press Accept, but a test is for finding surprises.

## Test locally with your own keys

If you'd rather not install anything on a site, `npm run playground:real` starts a throwaway WordPress on your own computer with your real posts. It needs Node 22 and a clone of this repository with `npm install` run once. It runs the plugin from your clone, so you can skip steps 1 to 3. It runs one of two ways:

- **Free, on your computer (try this first).** Ollama reads your posts with the `nomic-embed-text` model. Nothing is sent to OpenAI and nothing costs money. Install Ollama, then run `brew services start ollama` (or `ollama serve`) and `ollama pull nomic-embed-text`.
- **OpenAI.** Your posts are sent to OpenAI with your own key, as on a real site. Try this once the free run looks right.

An Anthropic key is optional either way. It turns on the questions on the Dashboard.

1. **Make keys just for this test, if you use any.** For OpenAI, create a new project in the OpenAI dashboard, give it a low monthly budget (a few dollars is plenty), and create an API key in that project. For Anthropic, do the same in the Anthropic Console: a separate workspace with a low spend limit, and a key in it.
2. **Put keys only in `.env.local`.** Copy `.env.local.example` to `.env.local` in the repository folder. For the free run, leave `UNDERGROWTH_EMBEDDINGS=ollama`. For OpenAI, set it to `openai` and paste your key after `OPENAI_API_KEY=`. Paste an Anthropic key after `ANTHROPIC_API_KEY=` if you have one. Git ignores `.env.local`. Don't paste a key anywhere else: not in a URL, a terminal command, an issue or a screenshot.
3. **Export your posts.** On your blog, go to **Tools > Export**, choose **Posts**, and download the file. Put its path after `WXR=` in `.env.local`.
4. **Run `npm run playground:real`.** It says which way it is reading posts and prints the address of Settings > Undergrowth on `127.0.0.1`, which only your computer can open. Open it in your browser. If WordPress asks you to log in, which happens when you run the script again in the same browser, use the username `admin` and the password `password`. Your posts are read in the background while the script runs; reload the page to watch the count go up. Once every post is read, the monthly cycle (section 5) runs by itself, and the Map tab appears on Posts > Undergrowth.
5. **Record your scores.** Work through section 7 in the browser. When you are done, stop the script with Ctrl+C. It prints the score percentiles; paste them into the feedback issue (section 8, #176), and say whether they came from Ollama or OpenAI. Then the site and everything in it is deleted.
6. **Revoke the keys.** In the OpenAI dashboard and the Anthropic Console, revoke the test keys, or delete the test project and workspace. Then delete `.env.local`. To stop Ollama, run `brew services stop ollama`.

## 1. Get the zip

1. Open the repository's **Actions** tab on GitHub and choose the **Build** workflow.
2. Open the newest run on `main` and download **undergrowth.zip** from its Artifacts section. You need to be signed in to GitHub.
3. Don't unzip it.

## 2. Install it

You need WordPress 6.9 or later and PHP 7.4 or later. A local site from Studio, Local or wp-env works, and so does a staging copy.

1. Go to **Plugins > Add New > Upload Plugin**, choose `undergrowth.zip`, then **Install Now** and **Activate**.
2. Undergrowth does nothing yet. It sends nothing anywhere until you save a key. A notice on the Plugins screen links to Settings > Undergrowth. It should go away for good once you save a key or choose **Dismiss for good**, and never show on any other screen.

## 3. Add your keys

Go to **Settings > Undergrowth**.

The page should open with one sentence on what Undergrowth does, and each key field should link to the provider's own key page. After you save the first OpenAI key, the "Settings saved" notice should link on to **Posts > Undergrowth**.

- **OpenAI API key** (needed). Paste a key from your own OpenAI account. Undergrowth uses it to turn each published post into a vector, a list of numbers that says what the post is about. It sends each post's title and text, and nothing else. The settings page says the same.
- **Anthropic API key** (optional). This is for the Dashboard questions. Each request sends one post's title and a few sentences quoted from it. If the question comes from a reader's comment, that comment's text goes too. Whole posts are never sent. **Wait until step 5 before you save this key** (see below).

Once you save a key, it is never shown again. The page only shows its last four characters. Never paste a key into an issue, a screenshot or this repository. If one slips out, revoke it in that provider's dashboard and make a new one.

The Discord section is optional, and you can skip it for a first test. If you want to try it, follow **Setting up Discord** in the plugin's readme.

## 4. Wait for your existing posts to be read

Once the OpenAI key is saved, Undergrowth reads every published post in the background, twenty at a time, as WP-Cron runs. You don't need to save any post. On a local site, WP-Cron only runs when you load pages, so click around wp-admin for a minute. **Settings > Undergrowth** shows how many posts are indexed so far. Link suggestions start to appear on **Posts > Undergrowth** as posts are read.

A post that fails to be read, for example because OpenAI was busy, is tried again within the hour.

## 5. Run the monthly cycle now

Clusters, growth stages and new questions come on a monthly cycle. The one exception is the first question, which is written when you save the Anthropic key. The first cycle ran when you activated the plugin, before any post had been read, so it found nothing. Once every post has been read, Undergrowth runs the cycle once more by itself, so clusters and stages appear a few page loads after the count on the settings page reaches the total. To run it again by hand:

- **With WP-CLI:** `wp cron event run undergrowth_cluster_cycle`, then `wp cron event run --due-now` two or three times, so the batches it queues get to run.
- **Without WP-CLI:** install the WP Crontrol plugin. Go to **Tools > Cron Events**, find `undergrowth_cluster_cycle` and choose **Run now**, then load a few admin pages.

The cycle groups your posts into clusters and then measures every post's stage. Now save the Anthropic key, if you are using one. Undergrowth writes its first question as soon as the key is saved, and a question about a gap between your posts needs clusters to exist first.

You can run the cycle as often as you like while testing. Stages only go up on each run, never down.

**Please record your scores.** The cycle also measures how your posts score against each other. With WP-CLI, run `wp undergrowth scores` after the cycle and paste its output into the feedback issue (section 8). It lists percentiles and thresholds, never post text. Undergrowth keeps OpenAI on fixed thresholds (0.4 for links, 0.5 for clusters, 0.8 for gaps) until these numbers show whether OpenAI should follow each site's scores, as other models already do.

## 6. What it should cost

These are rough figures from the providers' published prices, checked on 2026-10-21.

- **OpenAI (text-embedding-3-small): $0.02 per million tokens.** One token is about three quarters of an English word, so a 1,500-word post is about 2,000 tokens. Reading 100 posts is about 200,000 tokens, or $0.004. Reading 1,000 posts is about $0.04. Each edit you save later reads that one post again. So reading your whole archive costs a few cents at most.
- **Anthropic (Claude Opus 5.5, the default model): $4 per million input tokens and $20 per million output tokens.** Most questions take one request: about 2,000 tokens in and usually under 1,000 out, counting the model's thinking. That is $0.008 + $0.02, about **3 cents a request**. Output is capped at 4,000 tokens, so one request costs 9 cents at most. A question about a composting post is checked with a second request, so up to 18 cents. If Claude's reply breaks the rules, it is thrown away and Undergrowth tries the next source. That means one cycle can make up to three requests, about 27 cents at the very most. In practice, ten cycles run by hand come to well under a dollar, but they could pass it. The `undergrowth_prompt_model` filter can change the model, and with it the price.

To be safe, set a small monthly spending limit on both provider accounts before you start.

## 7. What to check

Work through these in any order. Note anything that surprises you, reads oddly, or seems to speak *for* you rather than *to* you.

**Link suggestions** (Posts > Undergrowth, Suggestions tab)
- The switch "Suggest links between my posts" is on Settings > Undergrowth now, under Link suggestions. Turn it off there and the Suggestions tab should say so, with a link back to Settings.
- On the Posts list, the Growth stage column should show each published post's stage, with a pin marker when pinned, and a dash for drafts.
- Does each suggestion connect two posts that really belong together?
- Is the quoted sentence a sensible place for the link?
- Is anything suggested that you would never link?

**Accept and Veto**
- Each suggestion leads with your sentence, with the word Undergrowth would link highlighted, and "Link to" the other post under it, then Accept | Veto. Try it on a phone too: all of that should show without opening the row.
- Accept a suggestion. Undergrowth picks the word itself. Open the post: the highlighted word, and nothing else, should now be a plain link. A revision should be saved too. Does it pick a sensible word?
- Veto a suggestion. It should go away and not come back, even after you edit either post.

**Growth stages** (Growth stages tab)
- Do the stages match what you would expect? Seedling has 0 or 1 accepted links. Sprout has 2 or more. Budding is in a cluster, with links both in and out. Evergreen only happens if you pin it in this version.
- Pin a stage, run the cycle again, and check the stage stays where you pinned it. Then unpin it.

**Composting** (Posts > All Posts)
- Use the **Compost** link under a post's title. The post should stay published and keep its links, but drop out of Link suggestions and the Growth stages tab.
- Use **Return to garden**. The post's stage should be measured again from scratch, not restored and not reset.
- If a composting post sits close to a new cluster, the Growth stages tab may show one notice about it. Check its wording.

**The Dashboard question** (Dashboard, the Undergrowth widget, "A question for you")
- Is it one question, addressed to you, in your site's language?
- Does it quote only you? Does it avoid suggesting a title, an outline or a topic?
- Try **Ask me next month** and **Dismiss for good**. A new question only comes at the next cycle. To get one sooner, run `undergrowth_prompt_cycle` the same way as in step 5.

**The growth stage block**
- In the editor, add the **Growth stage** block to a post or to a Query Loop. Check how it looks on the front end with your theme. A composting post should show nothing.

**The threshold meter** (Dashboard, the Undergrowth widget, "Page views, last 30 days")
- Without Jetpack, or with Stats off or not connected, the Undergrowth widget should have no meter part, and no widget at all when there is no question either. Settings > Undergrowth > Help > Page views meter says what it needs.
- With Jetpack connected and Stats on, it shows one number, your page views in the last 30 days against 1,000, and one line more. Help > Page views meter on the Dashboard explains both rules of the switch line. Compare it with the 30-day total on Jetpack's own Stats screen. The meter counts once a day, so it can lag behind by up to a day.
- Past 1,000, it should say plainly that the line is passed and that nothing changes yet.
- Log in as an editor. If Jetpack's Stats settings don't let editors see Stats, the meter shouldn't show for them either.

**The per-post files**
- On the server, open the folder that Settings > Undergrowth shows under Per-post files, `wp-content/uploads/undergrowth-<random>/posts/`. Check that `wp-content/uploads/undergrowth/` no longer exists. There is one file per post, named by post ID, for example `123.txt`. Check that each file only holds your own words, addresses, stages and the questions you were asked. The `has`, `lacks` and `scent` sections stay empty for now (#118).
- If the site runs on nginx, check that the folder can't be opened in a browser. See the readme FAQ.

## 8. Feedback checklist

Copy this into a new issue, tick what worked, and add a line about anything that didn't. If you find a security problem, such as a key or private data showing where it shouldn't, don't put it in a public issue. Report it privately through the repository's Security tab (Report a vulnerability) instead.

```markdown
**Site:** posts indexed: __ / __ · WordPress __ · PHP __ · local / staging

- [ ] Zip installed and activated without errors
- [ ] Settings page made clear what is sent where
- [ ] Existing posts were all read without saving them
- [ ] Link suggestions mostly connect posts that belong together
- [ ] Quoted sentences are good places for the links
- [ ] Accept linked one word and changed nothing else
- [ ] Veto removed a suggestion for good
- [ ] Stages look plausible after running the cycle
- [ ] Pin and unpin behave as expected
- [ ] Compost and Return to garden behave as expected
- [ ] Resurfacing notice on the Map tab (if it appeared) reads well
- [ ] Dashboard question is one question, quoting only me
- [ ] Ask me next month and Dismiss for good work
- [ ] Growth stage block looks right in my theme
- [ ] Threshold meter shows a sensible state, and its count matches Jetpack Stats
- [ ] Per-post files hold nothing unexpected
- [ ] Costs on both provider dashboards match the estimate
- [ ] Nothing anywhere spoke *for* me

**Scores** (output of `wp undergrowth scores`, or what `npm run playground:real` prints when it stops):

**Surprises:**

**Wording I'd change:**

**Anything that felt like a content factory:**
```
