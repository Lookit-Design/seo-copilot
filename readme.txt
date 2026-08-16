=== Lookit SEO Copilot ===
Contributors: lookitdesign
Tags: yoast, seo, keyphrase, meta description, bulk edit
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 3.34.1
License: GPL-2.0+
Author: Lookit Design
Author URI: https://lookitai.com

Bulk-edit Yoast focus keyphrases and meta descriptions across all post types, plus auto-fill Yoast fields on publish.

== Description ==

Manage Yoast SEO focus keyphrases and meta descriptions for every post type from one screen, and auto-fill Yoast fields on publish.

Four tabs:
* **Bulk Editor** — edit keyphrases and meta descriptions across all post types (incl. JetEngine CPTs), with filters, templates, and bulk fill.
* **Auto SEO Manager** — per-post-type rules that auto-fill the focus keyphrase, meta description, and related keyphrases when a post is published. Uses PHP content extraction + the free Datamuse API (no AI key, no credits). Includes a per-post "Lock SEO Fields" metabox and a Test & Reprocess tool.
* **SEO Health** — audit every post type/page against on-page SEO best practices (keyphrase optimization, titles & meta, content quality, media alt text, internal links), with a health score, per-page drill-down, and a priority-fix list. On-page checks run entirely in WordPress; deeper checks (Core Web Vitals, site crawl, Search Console, AI suggestions) are surfaced as platform-connected features.
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

== Changelog ==

= 3.34.1 =
* Shortened the display name to 'Lookit SEO Copilot' (dropped the 'for Yoast' suffix) in the Plugins list, admin menu title, and header banner. Identifiers still unchanged.

= 3.34.0 =
* Rename (display only): plugin is now 'Lookit SEO Copilot'; the admin menu reads 'SEO Copilot' with 'SEO Manager' and 'SEO Settings' submenus, and the header banner matches. All internal identifiers are unchanged — menu slugs (lookit-bulk-seo, lookit-bulk-seo-settings), option keys, text domain, AJAX actions, nonces and the folder name are preserved, so existing installs and links keep working.

= 3.33.4 =
* SEO Health: the tab now honors the Text size preference (Settings !92 Text size) like the other tabs — all SEO Health text scales via the shared --bsm-fs variable instead of fixed sizes.

= 3.33.3 =
* SEO Health: the Pages & Posts post-type filter is now sticky per user — the last selected type is remembered when you leave and return to the tab, instead of resetting to All post types.

= 3.33.2 =
* Settings: removed the example endpoint URL from the AI engine field placeholder so the internal platform endpoint is not exposed on unconfigured sites.

= 3.33.1 =
* Fix: 'Generate page content' produced output in n8n but did not display in the plugin — the result box was resolved from the button's immediate parent, which failed for the content control's nested button. Now resolved from the suggestion wrapper, so content renders with its Copy button.

= 3.33.0 =
* SEO Health: the Edit SEO fields panel is now a collapsible dropdown; each field (focus keyphrase, SEO title, meta description) shows the current value, an input for the new value, and a per-field 'Fill with AI' button.
* SEO Health: new AI suggestion 'Generate page content' with a target word-count control. Requires the updated n8n workflow (adds a 'content' task to the Prepare Input node).

= 3.32.0 =
* SEO Health detail: added an "Edit SEO fields" panel to edit the focus keyphrase, SEO title, and meta description inline and save them to Yoast (via the same writer the Bulk Editor uses); the page re-audits on save. The AI meta-description result now has a "Use" button that drops it into that field.
* SEO Health detail: the Internal links check now has an expandable dropdown listing every internal link on the page (anchor text + URL).

= 3.31.5 =
* Plugin Check pass: removed prohibited suppress_filters from the attachment lookup, escaped the AI-suggestion button's post ID, and annotated the necessary meta_query and export set_time_limit() with justified phpcs:ignore notes.

= 3.31.4 =
* SEO Health: far more reliable media-library alt detection. Resolves each inline image to its attachment via the wp-image-ID class first, then URL match, then a filename lookup against the library — so alt set in the media library or Lookit Media Master is credited even on scoped/CDN/-scaled image URLs.

= 3.31.3 =
* SEO Health: the image alt-text check now also credits media-library alt text (the field Lookit Media Master writes to), not just the inline alt attribute in the post HTML — so alt fixes made in Media Master are reflected in the audit. Resized image URLs are resolved back to their attachment.
* SEO Health: added a Refresh button on the Pages & Posts toolbar (after CSV) and a Refresh page SEO button on the single-page audit, to force a fresh re-audit.

= 3.31.2 =
* SEO Health detail: the image alt-text finding now lists each missing-alt image with a "Copy file name" button (numbered n/total) that copies the searchable filename to the clipboard, so you can paste it into Media Master's Alt Text Manager search. The Media Master link now requests the Alt Text Manager tab via an mm_tab=alt parameter.

= 3.31.1 =
* SEO Health detail: the image alt-text finding now links straight into Lookit Media Master's Alt Text Manager when that plugin is installed (detected via its registered admin page); falls back to plain informational text when it isn't.

= 3.31.0 =
* SEO Health detail: AI suggestions now offers three live generators — meta description, H2 subheadings, and a content-expansion outline — each served by the existing platform endpoint (n8n !92 Bedrock, Nova Lite). Subheadings and outline require the updated n8n workflow (adds 'subheadings' and 'outline' tasks to the Prepare Input node); meta description works with the current workflow.

= 3.30.5 =
* SEO Health detail: added "Edit page" and "View live" buttons (open in a new tab) so you can jump straight from an audit to fixing or viewing the page.
* SEO Health detail: the AI suggestions box now generates a live meta description via the saved platform endpoint (reuses the same n8n !92 Bedrock metadesc task as the Bulk Editor). Subheading and content-expansion suggestions remain pending pending a new platform prompt; shows a connect-endpoint prompt when the AI engine URL is not set.

= 3.30.4 =
* SEO Health: export now offers a real, styled .xlsx (Excel) in addition to CSV — bold frozen header, colour-coded score and issue-count cells (green/amber/red), sized columns and an autofilter. Written natively via ZipArchive; no third-party library bundled. CSV export retained as a secondary option.

= 3.30.3 =
* SEO Health: added an Export CSV button to Pages & Posts. Exports the full audit (all rows, respecting the post-type filter) with title, type, URL, score, words, meta, title length, alt coverage, internal links, issue count, issue list, and focus keyphrase. UTF-8 BOM so Excel reads accents; nonce-protected admin-post handler.

= 3.30.2 =
* SEO Health: focused the tab on Pages & Posts — removed the Overview and Opportunities sub-tabs for now. Replaced the full page-number list with a compact windowed pager (first / last / current ±2, Prev/Next, and a "Page X of Y" indicator) so large sites no longer overflow the table.

= 3.30.1 =
* SEO Health: Plugin Check pass — replaced str_contains() with strpos() for WordPress 5.8 compatibility, and escaped loop counters in the priority-fix and pagination output.

= 3.30.0 =
* New: **SEO Health** tab. Audits every published post/page against on-page best practices and produces a health score, a priority-fix list, a sortable per-page table, and a full single-page drill-down. Reuses the Bulk Editor's post-type enumeration and duplicate-keyphrase detection. On-page checks (keyphrase optimization, titles/meta, word count, headings, image alt coverage, internal links) run in WordPress with no external calls. Platform-dependent checks (Core Web Vitals, full-site crawl, Google Search Console, AI content suggestions) are shown as pending until the platform is connected.
* Internal identifiers unchanged. New menu tab slug `health`; new asset handles `bsm-health`.

= 3.29.3 =
* Auto SEO Manager: the save now reads the option back from the database (bypassing the object cache) and returns it, and the browser diffs it against what it sent. Any field that doesn't persist is named on screen and logged to the console instead of failing silently.

= 3.29.2 =
* Fix: Auto SEO Manager dropdown settings were lost when leaving the tab on some live sites. The table now auto-saves on change, flushes any pending save on page hide (sendBeacon), and warns before discarding unsaved edits.
* Fix: Save button is now bound by delegation, so it can't end up inert if the table renders after the script.
* Fix: the save endpoint refuses empty payloads and detects requests truncated by PHP max_input_vars instead of silently writing partial settings.
* Fix: saved settings are merged rather than replaced, so post types not on screen keep their configuration.
* Fix: a stale nonce now reports "session expired" instead of a generic save error.
* Fix: the Bulk Editor fill-target switcher no longer captures the top-level nav tabs (shared .bsm-tab class).
* Asset version bumped so CDN/browser caches pick up the new admin.js.

= 3.29.1 =
* Fixed: a saved Meta Description Template or SEO Title could re-open showing the raw template string marked "(custom)" instead of the template's name. The two copies of the string were saved through different WordPress sanitisers, so any template containing a line break, a double space, a tab or a percent sequence failed an exact match on reload. Matching now ignores those differences.
* Settings: the stylesheet is now delivered inline with the page rather than as a separate file, so the Settings page can no longer render at a stale type scale behind a CDN or WAF. It starts at the same size as the Bulk Editor and Auto SEO Manager.

= 3.29.0 =
* Auto SEO Manager: the post-types table is now real table markup, styled inline alongside the Bulk Editor table. On some installs an older admin.css was taking precedence and breaking the layout so the SEO Title column fell onto its own line. Inline styles cannot be blocked by a stylesheet handle collision or served stale by a CDN, and table cells cannot wrap.
* Auto SEO Manager: the table scrolls sideways on narrow screens instead of breaking apart.

= 3.28.2 =
* Fixed: the plugin's admin stylesheet and script used the generic handle "asy-admin". If another plugin registered the same handle first, WordPress skipped ours entirely and the Auto SEO Manager table rendered with the wrong layout. Handles are now namespaced to "lookit-bsm-admin".
* Auto SEO Manager: table rows rebuilt with flex and nowrap so the SEO Title column cannot drop onto a second line, regardless of what other stylesheets are present.
* Both stylesheets now carry a version banner on line 1, so you can confirm which copy a site is serving.

= 3.28.1 =
* Fixed: on sites with many custom post types, or a narrower admin content area, the Auto SEO Manager table's SEO Title column wrapped onto its own line. Columns are now fluid with sensible minimums, and the table scrolls sideways as one piece instead of breaking apart.
* Fixed: the Auto SEO Manager table's responsive rules were defined before the column rules that override them, so they never applied. Small screens now stack the table properly, with each field labelled.
* Settings: text now starts at the same size as the Bulk Editor and Auto SEO Manager. Use Settings → Text size to scale all three together.

= 3.28.0 =
* New: Text size control under Settings → Text size. Four steps from Default to 35% larger, applied across the Bulk Editor, Auto SEO Manager and Settings. Saved per WordPress user, so it doesn't change what anyone else sees.
* Settings: body copy, hints and the How it works list now fill the width of the pane instead of stopping short.

= 3.27.1 =
* Settings: larger type throughout — headings, labels, template inputs, placeholder chips, the preview rail and the post picker all stepped up for readability.
* Settings: template name and string inputs are bigger, and template strings now render in a monospace face so placeholders are easier to scan.
* Fixed: placeholder chips hovered pale blue instead of the Lookit teal — a leftover inline style was overriding the stylesheet.
* Field browser styles moved out of an inline style block and into the enqueued stylesheet.

= 3.27.0 =
* Settings: rebuilt as a sidebar layout — AI engine, Defaults, the three template kinds, Test & reprocess, Custom fields and How it works each get their own section instead of one long scroll.
* Settings: added a live preview rail showing the resolved SEO title and meta description as a search result, with character meters (60 for titles, 155 for descriptions) that flag truncation before you save.
* Settings: pick any post or page as the preview sample, searchable by title or ID and filterable by post type. The choice is remembered per user.
* Settings: placeholder chips consolidated into one palette per section instead of repeating above every template row.
* Auto SEO Manager: "Test & Reprocess" moved to Settings → Test & reprocess.
* Fixed: the Auto SEO Manager SEO Title selection was discarded on save — the value was never sent with the request, so it reset to "No SEO title" on reload.

= 3.26.3 =
* Auto SEO Manager: SEO Title dropdown now matches the styling of the other dropdowns in the table.

= 3.26.2 =
* Auto SEO Manager: SEO Title dropdown now matches the meta-description dropdown width.
* Settings: template rows now stack name/string so they fit neatly inside each card (no more cramped inputs or overflowing Remove button).

= 3.26.1 =
* Auto SEO Manager: SEO Title column now sits next to the other dropdowns (fixed-width columns) instead of stretching to the far right.
* Settings: redesigned the templates layout into a responsive card grid with a Save button at the top as well as the bottom.

= 3.26.0 =
* Bulk Editor: every source dropdown (Keyphrases, Descriptions, Related, SEO Titles) now remembers its selection across tab switches and refresh.
* Auto SEO Manager: added a per-post-type SEO Title column (AI — Nova Lite or a saved SEO title template) that fills the Yoast SEO title on publish.

= 3.25.0 =
* Settings: new SEO Title Templates section with built-in placeholders ({title}, {title_short}, {sep}, {site}, {category}, {type}).
* Bulk Editor: new "SEO Titles" tab (AI — Nova Lite, built-in, or your templates); Fill row now also fills the SEO title.
* Bulk Editor: Related keyphrases source defaults to Datamuse and is remembered across tab switches and refresh.

= 3.24.0 =
* Bulk Editor: added Current SEO Title and New SEO Title columns (writes Yoast's _yoast_wpseo_title). Current titles render with Yoast variables resolved.
* Bulk Editor: the plugin now uses the full page width.

= 3.23.0 =
* Moved the plugin to its own top-level admin menu (Bulk SEO) instead of living under Tools.
* Settings: narrower "Related keyphrases to generate" dropdown.

= 3.22.4 =
* Settings: vertically centered the value in the "Related keyphrases to generate" dropdown.

= 3.22.3 =
* Bulk Editor: the Keyphrases dropdown now always shows the "Your templates" group (with a "No templates yet" hint when empty), matching the Descriptions dropdown.

= 3.22.2 =
* Bulk Editor: keyphrase source options grouped under "Built-in" to match the descriptions dropdown; removed "Best word in title", "Top content word", and "Primary category / term".

= 3.22.1 =
* Related keyphrases (AI and Datamuse) no longer repeat the focus keyphrase, and duplicates are removed — filtering is now centralized on save.
* Datamuse: fixed a double URL-encoding bug in the rel_trg query so the association lookup returns results.

= 3.22.0 =
* Bulk Editor: the per-row Save button is replaced by a "✦ Fill row" button that fills that row's keyphrase and meta description together (using the sources selected above).
* Bulk Editor: Save All Changes (top and bottom) now saves via AJAX — no full page reload. The per-cell Fill buttons were removed in favor of the row/tab actions.

= 3.21.1 =
* Bulk Editor: vertically centered the text in the filter dropdowns and search box.

= 3.21.0 =
* Auto SEO Manager: description text no longer wraps early.
* Settings: smaller "Related keyphrases to generate" dropdown.
* Bulk Editor: search box and Filter button match the filter dropdowns; Save All Changes moved into the switcher row, after the fill buttons.

= 3.20.2 =
* Bulk Editor: switcher tabs, source dropdown, and buttons now share one height; filter status dropdowns no longer clip their labels under the arrow.

= 3.20.1 =
* Settings: fixed the width of the "Related keyphrases to generate" dropdown.
* Auto SEO Manager: moved the "Add templates in Settings" hint into the top description (off the per-row cells).
* Bulk Editor: larger switcher tabs, with the source dropdown and buttons on the same line as the tabs.

= 3.20.0 =
* Auto SEO Manager: fixed the post-types table column alignment (header and row cells now line up; dropdowns fill their columns).
* Moved "Related keyphrases to generate" (shared by Bulk Editor and Auto SEO) and the "How it works" panel into the Settings tab.

= 3.19.0 =
* Bulk Editor: the three fill rows are now a segmented switcher (Keyphrases / Descriptions / Related keyphrases) that shows one target's source dropdown and its two buttons at a time. Remembers the last-used tab per session. Same fill logic underneath.

= 3.18.0 =
* Bulk Editor: Save All Changes moved into the top row of the control panel (with the filters), tightening the layout.
* Bulk Editor: new Related Keyphrases dropdown (Off / Datamuse / AI — Nova Lite) with Generate All / Selected — generates related keyphrases and saves them to Yoast (count from the Auto SEO "Keyphrases to generate" setting). Replaces the old "Related Keyphrases — Bulk Editor" settings box, which has been removed.

= 3.17.0 =
* Auto SEO Manager: Auto Keyphrase, Related Keyphrases, and Meta Description are now dropdowns (each can pick AI — Nova Lite). Replaces the per-post-type checkboxes and the separate Use AI toggle; existing saved settings are migrated on read.
* Bulk Editor: filters (post type, status, search, Filter) moved into the same control panel as the fill dropdowns and Save All Changes.

= 3.16.0 =
* Bulk Editor: AI-generated meta descriptions are now capped to the ideal max length (word-boundary trim), matching the other fill options.
* Auto SEO Manager: the per-post-type Meta Description Template is now a dropdown populated from the saved Description Templates (Settings), instead of a free-text field.

= 3.15.2 =
* Plugin Check: escape the AI endpoint field output (esc_url) and sanitize the keyphrase array input in the AI fill handler.

= 3.15.1 =
* Bulk Editor: both fill dropdowns now default to the AI (Nova Lite) source.

= 3.15.0 =
* Auto SEO Manager: new per-post-type **Use AI (Bedrock)** switch — on publish it generates the focus keyphrase and meta description with Amazon Bedrock (and related keyphrases when "Auto related keyphrases" is on), falling back to the deterministic checkboxes if the platform is unreachable.
* Settings: new **Related Keyphrases — Bulk Editor** box (above Meta Field Browser) — when on, an AI keyphrase Fill in the Bulk Editor also generates related keyphrases and saves them to Yoast; related terms are surfaced as pills under the keyphrase cell.

= 3.14.1 =
* Moved the **AI engine** endpoint box to the Settings tab (it configures both the Bulk Editor AI fills and the Auto SEO Use AI button).
* Auto SEO Manager: new **Use AI** button in Test & Reprocess — generates the focus keyphrase, related keyphrases, and meta description with Amazon Bedrock and saves all three to Yoast for the given post.

= 3.14.0 =
* Bulk Editor: new **AI — Nova Lite via platform** source for both keyphrases and meta descriptions. Thin client — posts page context to the platform webhook (n8n → AWS Bedrock); no API key in WordPress. Added an AI engine box to set/save the webhook endpoint.

= 3.13.0 =
* Auto SEO Manager: laid out the keyphrase checkboxes in two columns — Title/Slug on the first row, Title short/Slug short on the second, with Top content word on its own row at the bottom. Tidier and shorter than the old single stack.

= 3.12.0 =
* Auto SEO Manager: renamed the keyphrase checkboxes to Title, Slug, Top content word, and added two new options — **Title short** and **Slug short** (first 3 words of the title / slug). Priority when several are ticked: Top content word → Title short → Slug short → Slug → Title.
* Auto SEO Manager: related keyphrases no longer repeat any significant word from the focus keyphrase, so related terms stay complementary instead of echoing the main keyphrase. (Now compares against the keyphrase actually set on the post, not just the title.)

= 3.11.0 =
* Settings: fixed drag-and-drop reordering of Keyphrase and Description templates — dragging by the handle now works (previously only the ▲/▼ buttons reordered rows).
* Bulk Editor: fixed `{meta:fieldname}` placeholders in Description templates — when a field has no value on a post, the placeholder now collapses to empty instead of leaving the raw `{meta:...}` token in the meta description. (Keyphrase templates already behaved this way.)

= 3.10.0 =
* Bulk Editor: added a publish-status filter. The list now defaults to **Published (live)** posts, with opt-in options for Drafts, Pending review, Scheduled, Private, and All statuses.
* Bulk Editor: rows that aren't published now show a small status badge (e.g. "Draft") next to the title.
* Bulk Editor: widened the Fill keyphrases / Fill descriptions source dropdowns so the longer strategy names are no longer cut off.

= 3.9.0 =
* Bulk Editor: the top Fill bar is no longer sticky — it stays at the top of the page as before.
* Bulk Editor: the per-row ✦ Fill buttons now bottom-align within their cells, so the Keyphrase and Meta Description Fill buttons line up on the same baseline regardless of field height.

= 3.8.0 =
* Bulk Editor: moved the Fill controls back to a bar above the table (full-width, so the table isn't cramped) and made that bar **sticky** — it stays pinned below the admin bar as you scroll long lists, so the source dropdowns, Preview & Fill buttons, and Save All Changes are always reachable.

= 3.7.0 =
* Bulk Editor: removed the separate Fill Keyphrase / Fill Meta Description columns — the per-row ✦ Fill buttons now sit directly beneath the New Keyphrase and New Meta Description fields again. Moved the Save Row column to the end of the table.

= 3.6.0 =
* Bulk Editor: moved the Fill controls (keyphrase & description source dropdowns, Preview & Fill All / Selected, and Save All Changes) into a **sticky side panel** that stays in view while scrolling long lists, instead of a bar that scrolled away at the top.
* Bulk Editor: the per-row Fill buttons now have their own dedicated columns — **Fill Keyphrase** and **Fill Meta Description** — alongside the Save Row column, rather than sitting beneath the input fields.

= 3.5.0 =
* Bulk Editor: added a per-row **✦ Fill** button under each New Keyphrase and New Meta Description field, so a single post can be filled/previewed on its own without ticking its checkbox and using the top buttons. Each row button applies whichever source is currently selected in the matching top dropdown (including custom templates).

= 3.4.0 =
* Settings: added custom **Keyphrase Templates**, mirroring the existing Description Templates — compose short focus keyphrases from post data using placeholders. New recommended placeholders: {title_short} (first 4 words of title), {parent} (parent page title), and {slug} (URL slug as words), alongside {title}, {category}, {type}, and {site}. Saved keyphrase templates appear under a "Your templates" group in the Bulk Editor's "Fill keyphrases" dropdown.
* Bulk Editor: realigned the fill toolbar. "Fill keyphrases" and "Fill descriptions" now sit on two aligned rows (label / dropdown / Fill All / Fill Selected line up in columns); removed the redundant "Bulk actions:" label. "Save All Changes" now uses the standard WordPress button styling to match the Filter and Reset buttons.

= 3.3.1 =
* Plugin Check: resolved two warnings on the duplicate-keyphrase query — removed the dynamically interpolated IN() placeholder (post types are now filtered in PHP), and widened the existing SlowDBQuery annotation to cover the meta_key ordering used by the "Duplicated keyphrases" filter.

= 3.3.0 =
* Bulk Editor: "From the title" keyphrase sources now pick the strongest single word ("Best word in title") rather than the whole title, and added "Title ∩ slug overlap" (words shared by title and slug — a high-confidence signal).
* Bulk Editor: added a "Duplicated keyphrases" option to the keyphrase-status filter. Shows only posts whose Yoast focus keyphrase is shared by two or more posts (case-insensitive), grouped together so collisions are easy to spot and re-key. Serves as a duplicate-keyphrase guard.

= 3.2.0 =
* Bulk Editor: expanded the "Fill keyphrases" sources. New "Best match" option scores title words/phrases by how well they're echoed across the excerpt, first sentence, and body — the strongest local signal for a focus keyphrase (now the recommended default). Also added "Cleaned title" (drops filler words and any subtitle after a colon/dash) and "Primary category / term". Sources are grouped in the dropdown. All local — no external API or key.

= 3.1.2 =
* Bulk Editor: tidied the top "Save All Changes" button — now a right-aligned footer row within the toolbar instead of a floating button.

= 3.1.1 =
* Bulk Editor: added a "Save All Changes" button to the top toolbar (submits the same form as the existing button at the bottom of the page), so you don't have to scroll down to save.

= 3.1.0 =
* Bulk Editor: replaced the "Copy titles → Keyphrase" buttons with a "Fill keyphrases" source dropdown (Use title / Use slug / Top content word), mirroring the Fill descriptions control. Reuses the local Auto SEO engine — no external API, no key. Results stage into the New Keyphrase column for review before Save.

= 3.0.2 =
* Second Plugin Check pass: escaped the max() value output into the template-row script, sanitized the bulk keyphrase/meta-description POST arrays inline on read, annotated the settings template read (sanitized downstream) and the read-only tab routing, and set Tested up to 7.0.

= 3.0.1 =
* Plugin Check compliance pass: unified all text domains to the plugin slug, escaped all admin output, added wp_unslash() to form/AJAX input, converted the OpenRouter prompt heredoc to a standard string, added object caching to the meta-key lookup, set a version on the web font enqueue, added translator comments, and tidied the readme header (5 tags, short description, Tested up to). No behaviour, option keys, class names, constants, or AJAX action names changed.

= 3.0.0 =
* Merged the "Auto SEO for Yoast" plugin in as a new "Auto SEO Manager" tab — a single plugin now covers bulk editing and on-publish auto-fill.
* Bulk Editor / Auto SEO Manager / Settings are now tabs on one screen.
* Generated meta descriptions are kept in the Yoast green sweet spot (107–141 chars): trimmed on a word boundary if too long, and extended from post content if too short. Applies to Auto SEO on publish and the Bulk Editor "Fill descriptions" action. The character counter green zone now matches (107–141).
* Auto SEO option keys, post meta keys, class names, and AJAX actions are unchanged, so an existing Auto SEO for Yoast install keeps its settings.
* Auto SEO engine: focus keyphrase (title / slug / top content word), meta description templates, related keyphrases via content extraction + Datamuse, per-post Lock SEO Fields metabox, Test & Reprocess.

= 1.6.0 =
* Fixed: "Save all changes" was not saving Copy Titles / AI-generated values
* Root cause: nested <form> elements (invalid HTML) caused browsers to exclude outer form fields
* Fix: removed all nested forms — "Save row" now works via AJAX (no page reload, shows ✓ Saved)
* Added: dedicated Settings page under Lookit → SEO Settings
* Added: AI Prompt field in Settings — customise the prompt with {title} {type} {keyphrase} {excerpt} placeholders
* Added: Reset to default prompt button
* Plugin now lives under Lookit main menu (not Tools)
* ⚙ Settings link in top bar for quick access

= 1.5.0 =
* Fixed: JS-filled keyphrase values (Copy Titles) were skipped on Save All
* Fixed: AI credits error — removed all paid models, free-only list

= 1.4.0 =
* Lookit branding, AI generation, Copy Titles button

= 1.3.0 =
* Meta description columns + live char counter

= 1.2.0 =
* Fixed post type filter "Cannot load" error

= 1.1.0 =
* JetEngine + all CPT support

= 1.0.0 =
* Initial release
