=== Lookit SEO Copilot ===
Contributors: lookitdesign
Tags: yoast, seo, keyphrase, meta description, bulk edit
Requires at least: 5.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 3.50.1
License: GPL-2.0+
Author: Lookit Design
Author URI: https://lookitai.com

Bulk-edit Yoast focus keyphrases and meta descriptions across all post types, plus auto-fill Yoast fields on publish.

== Description ==

Manage Yoast SEO focus keyphrases and meta descriptions for every post type from one screen, and auto-fill Yoast fields on publish.

Main views:
* **Bulk Editor** — edit keyphrases and meta descriptions across all post types (incl. JetEngine CPTs), with filters, templates, and bulk fill.
* **Auto SEO Manager** — per-post-type rules that auto-fill the focus keyphrase, meta description, and related keyphrases when a post is published. Uses PHP content extraction + the free Datamuse API (no AI key, no credits). Includes a per-post "Lock SEO Fields" metabox and a Test & Reprocess tool.
* **SEO Health** — audit every post type/page against on-page SEO best practices (keyphrase optimization, titles & meta, content quality, media alt text, internal links), with a health score, per-page drill-down, and a priority-fix list. On-page checks run entirely in WordPress; deeper checks (Core Web Vitals, site crawl, Search Console, AI suggestions) are surfaced as platform-connected features.
* **Reports** — run a batched site audit, review site-wide scores and highest-impact fixes, and simulate how selected improvements would affect the score.
* **Settings** — build reusable keyphrase and meta-description templates and browse available meta fields (JetEngine, ACF, Meta Box, post meta).

== External Services ==

This plugin connects to the Datamuse API (https://api.datamuse.com) to expand keyphrases into semantically related search phrases. It is only called by the Auto SEO Manager when "Auto related keyphrases" is enabled for a post type and a post is published or reprocessed.

* What is sent: the post title and short candidate phrases derived from the post content.
* When: on publish, on Elementor save, and when "Generate Keyphrases" is run from the Test & Reprocess tool.
* Datamuse requires no account or API key.
* Datamuse Terms / about: https://www.datamuse.com/api/

This plugin can also connect to a Lookit platform webhook (self-hosted n8n) that relays requests to AWS Bedrock (Amazon Nova Lite) to generate focus keyphrases and meta descriptions. It is only called from the Bulk Editor when the "AI — Nova Lite via platform" source is chosen and a Fill action is run.

* What is sent: the page title, a short content excerpt, the primary category/term, and the post-type label.
* When: only on an explicit AI Fill action in the Bulk Editor.
* Endpoint is configured by the site owner; no AWS keys are stored in WordPress.
* [Vadim] add auth on the webhook and finalize vendor ToS/Privacy links before WP.org submission.

This plugin can also connect to a separate Lookit platform webhook (self-hosted n8n) that relays an image to AWS Bedrock (Amazon Nova Lite vision) to generate image alt text. It is only called from SEO Health → Media when a "Generate" (or "Generate all missing") action is run on an image, and only when a vision endpoint has been configured.

* What is sent: the image itself (as a base64 data URI), its MIME type, a generation prompt, and the site name/URL.
* When: only on an explicit alt-text Generate action in SEO Health.
* Endpoint is configured by the site owner; supports an optional Bearer token. No AWS keys are stored in WordPress.
* [Vadim] add/verify auth on the vision webhook, rate-limit the generate endpoint, and finalize vendor ToS/Privacy links before WP.org submission.

== Changelog ==

= 3.50.1 =
* Added per-user Task Manager lists from Impact Simulator selections, with bounded report item references and progress tracking.
* Added URL slug suggestions, safe 301 fallback integration, URL-length auditing, report distribution, and longest-address panels.
* Kept Task Manager nonce verification inline for Plugin Check.

= 3.46.2 =
* Added Website Report and Impact Simulator views with exact check-weight projections and batched site auditing.
* Added print-to-PDF fidelity and corrected the progress bar's initial hidden state.

= 3.45.2 =
* Fixed admin.css and admin.js being served from cache: both were pinned to a version constant that has not changed since 3.15.3, so style updates never reached the browser. They now version with the plugin.
* Auto SEO metabox: the "New focus keyphrase" heading now sits inside the suggestion box, and the wide Suggest button hides once a suggestion is showing (WP button styling was overriding the hidden attribute).


= 3.45.1 =
* Auto SEO metabox: the keyphrase suggester now has a "New focus keyphrase" heading, and the wide Suggest button steps aside once a suggestion is showing, since "Show another" beside it does the same job. The button comes back if a request fails.


= 3.45.0 =
* Auto SEO metabox: new "Suggest new focus keyphrase" button on the post editor, with a note explaining why auto fill tends to return the same phrase. Suggestions come one at a time; "Use this" drops the phrase into the Yoast field and "Show another" moves on. Nothing is written until you update the post.
* The metabox script only loads when an AI endpoint is configured in SEO Settings.


= 3.44.0 =
* Bulk Editor redesign. The four field types are now the main navigation, each showing how many pages on the current page still need that field. Choosing one shows only the columns it edits, so the screen holds about eight inputs instead of seventy-five.
* Fill controls moved into a bar below the table that reports the selection and names the count on the button. The per-row Fill button appears on hover or keyboard focus instead of repeating down the page.
* Per-row Fill now fills the field you are working on, rather than silently staging keyphrase, description and title at once.
* Plugin Check: Tested up to raised to 7.1, changelog trimmed under the readme limit, related-keyphrase and view-restore input sanitized inline, and the Focus picker no longer uses post__not_in.

= 3.43.2 =
* SEO Health: Edit SEO fields is no longer collapsible. It is a plain always-open panel like the check groups, so the header can't be clicked shut by accident.

= 3.43.1 =
* "Last page I was on" now restores the exact view, not just the tab: the page you were auditing in SEO Health, the filtered and paged list in the Bulk Editor, the Settings pane. One-shot parameters (save notices, nonces) are never replayed.

= 3.43.0 =
* Settings -> Defaults: new "Opening tab" dropdown, under Related keyphrases to generate. Choose "Main (SEO Health)" or "Last page I was on". Saved by the existing Save defaults button. Option: bsm_landing_tab.

= 3.42.0 =
* SEO Copilot now reopens on whichever tab you were last using, per user. First visit lands on SEO Health.
* Fixed the Focus pre-router, which redirected every tab-less page load to Focus and overrode the default set in 3.41.0. It now runs only when Focus is actually the tab being rendered.

= 3.41.0 =
* SEO Copilot now opens on SEO Health. Previously the menu landed on Focus.

= 3.40.1 =
* Tab order is now SEO Health, Auto SEO Manager, Bulk Editor, Focus, Settings.

= 3.40.0 =
* Edit SEO fields now shows the page's current related keyphrases as chips, each with an × to remove it. Removals and any suggestions added from below are written together when you press Save to Yoast.
* Applying related keyphrases from AI suggestions now merges with what is already saved instead of replacing it, and the applied phrases appear immediately in the chips above.

= 3.39.1 =
* SEO Health → AI suggestions: two new cards, "Focus keyphrase" and "Related keyphrases". Generate returns a set of pickable phrases; press Generate again for a fresh set, and the request tells the platform what you have already been shown so it doesn't repeat.
* Focus keyphrase: click a suggestion to load it into Edit SEO fields, review it, then Save to Yoast as usual. Nothing is written until you save.
* Related keyphrases: multi-select and apply straight to Yoast (there is no related field in Edit SEO fields to route through).
* Auto SEO on publish is unchanged: the focus keyphrase still comes from the post type rules, so the automated path stays predictable.
* SEO Health layout: the AI suggestions panel now sits directly under Edit SEO fields instead of at the bottom of the page, and Edit SEO fields is expanded by default rather than collapsed.

Older entries have been trimmed to keep this file within the WordPress.org readme limit. The full history lives in the plugin repository.
