=== Spintax ===
Contributors: 301st
Tags: spintax, seo, woocommerce, acf, content generation
Requires at least: 6.2
Tested up to: 7.1
Stable tag: 3.0.2
Requires PHP: 8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Generate unique content at scale with spintax — bind templates to ACF & post-meta fields, pull WooCommerce product data, Bulk Apply, cron, WP-CLI.

== Description ==

Spintax is a content-generation toolkit for WordPress that turns one template into unique, non-duplicate copy across your whole site. Author reusable templates in spintax markup (enumerations, permutations, conditionals, plural agreement), then either embed them inline via shortcodes / PHP — automatically picking up the **current WooCommerce product** on product pages — or **bind them to ACF and post-meta fields so every matching post gets its own rendered variant on save, on a cron schedule, or on demand**. A built-in Logs page surfaces what each Bulk Apply / Run-now walk did; a WP-CLI surface covers staging-to-production sync.

Ideal for content managers and SEO specialists producing many similar-but-unique pages: product descriptions, category copy, location / landing pages, listing blurbs, and FAQ snippets.

**Key features:**

* **Enumerations** `{a|b|c}` — randomly pick one option, with nesting support
* **Permutations** `[<config>a|b|c]` — pick N elements, shuffle, join with custom separators
* **Variables** `%var%` — global, local (`#set` re-picks at every use, `#def` picks once per render), and shortcode-level scopes
* **Conditionals** `{?VAR?then|else}` — render a branch based on whether a variable is set (also `{?!VAR?then}` inverted)
* **Plural agreement** `{plural <count>: form1|form2|form3}` — pick grammatically correct noun form by count. RU/UK/BE and SR/HR/BS 3-form (one|few|many), EN-style 2-form (one|many). Other languages fall back to the 2-form rule, so `pl`, `cs`, `sk`, `sl` and `bg` are bucketed by a rule that is not theirs rather than rejected. First spintax engine with first-class plurals.
* **Nested templates** — embed templates within templates via `#include` or `[spintax]`
* **ACF / post-meta bindings (NEW in 2.0)** — configure once per post type, render Spintax templates into ACF text/textarea/wysiwyg fields or post-meta keys on every matching post. Auto-seed empty fields, preserve manual edits, Bulk Apply via Action Scheduler.
* **WooCommerce product context (NEW in 2.2)** — on a single-product page, `[spintax]` / `spintax_render()` automatically expose the current product as `%product_name%`, `%product_sku%`, `%product_categories%`, `%product_attribute_<slug>%`, and more. Volatile pricing is intentionally out of scope. WooCommerce is optional — the variables simply appear when a product context is present.
* **WooCommerce product-field bindings (NEW in 2.4)** — generate a product's **description** or **short description** from a template, per product, using that product's own SKU, categories and attributes. Only those two fields are writable; price, SKU and stock are commerce data and stay out of reach. Manual edits are preserved by default.
* **Object cache** — rendered output cached via WP Object Cache API (Redis/Memcached ready)
* **Cron regeneration** — optional scheduled cache refresh per template, plus per-binding cron walks
* **WP-CLI** — `wp spintax bindings list|apply|test|export|import`
* **Validation** — bracket matching, circular reference detection, syntax checking
* **Admin UI** — code editor, live preview, shortcode copy, settings page, bindings list

**The syntax is an open, documented standard.** Its core is GTW-compatible (enumerations, permutations, variables, includes), extended over the years with its own primitives — value-driven conditionals, plural agreement, roll-once `#def` variables — and documented in full at [spintax.net](https://spintax.net/docs/syntax). Six independent engines — five installable libraries (PHP, JavaScript/TypeScript, Python, .NET, Object Pascal) plus this plugin — are held to one shared test corpus, and a free native Windows editor — [Spintax Studio](https://apps.microsoft.com/detail/9mw3ch7b530p) — authors it with live preview and validation.

== Installation ==

1. Upload the `spintax` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Create templates under the "Spintax" menu in the admin sidebar
4. Embed templates using `[spintax slug="my-template"]` in posts/pages or `spintax_render('my-template')` in theme files

**Recommended optional dependency:** install [Action Scheduler](https://wordpress.org/plugins/action-scheduler/) if you plan to use the "Bulk Apply" button on ACF / post-meta bindings, or schedule bindings via per-binding cron on a site with many matching posts. The plugin works without it — admins can use the synchronous "Run now" button on each binding card, and the same walk is available as `wp spintax bindings apply --binding=<id> --all`. Action Scheduler turns those into one-click chunked async jobs that don't block the request. If you already use WooCommerce or another plugin that bundles Action Scheduler, you're already set; the Bindings page only shows the install notice when AS isn't loaded.

== Frequently Asked Questions ==

= How do I create a template? =

Go to Spintax > Add New in the WordPress admin. Enter a title and your spintax markup in the editor.

= What syntax does the plugin use? =

* `{a|b|c}` — randomly picks one option
* `[a|b|c]` — permutation: picks N elements, shuffles, joins with space
* `[<minsize=2;maxsize=3;sep=", ";lastsep=" and "> a|b|c|d]` — configured permutation
* `%variable%` — variable reference
* `#set %var% = value` — local variable, a macro: re-picked at every use
* `#def %var% = value` — local variable, picked once per render and held at every use
* `{?VAR?then|else}` — conditional: render a branch by truthiness of `%VAR%` (also `{?!VAR?then}` inverted)
* `{plural %Count%: form1|form2|form3}` — plural agreement: picks the correct grammatical form by count (RU/UK/BE and SR/HR/BS 3-form, EN-style 2-form)
* `/#comment#/` — block comment (stripped from output)
* `#include "slug"` — embed another template

Full syntax reference with examples and a live playground: https://spintax.net/docs/syntax

= Where can I learn more? =

* **Documentation hub:** https://spintax.net/docs/ — guides, reference, recipes
* **Compact syntax reference:** https://spintax.net/docs/syntax — all primitives in one page (13 languages)
* **Plural agreement guide:** https://spintax.net/docs/plural-spintax/ — `{plural N: form1|form2|form3}` in depth (EN/RU)
* **Conditional spintax guide:** https://spintax.net/docs/conditional-spintax/ — `{?VAR?then|else}` value-driven branching (EN/RU)
* **Authoring mindset:** https://spintax.net/docs/authoring-mindset/ — write the final text first, add markup last (EN/RU)
* **Live playground:** https://spintax.net/play/ — write a template, set variables, render N variants in your browser (EN/RU)
* **Editors & tooling:** https://spintax.net/spintax-editor/ — Spintax Studio for Windows, VS Code and Sublime Text extensions, and the archived GTW application that started the syntax
* **Engine family:** https://spintax.net/spintax-engines/ — the five standalone libraries (PHP, JavaScript/TypeScript, Python, .NET, Object Pascal) and how one shared corpus keeps them identical
* **Spintax in PHP:** https://spintax.net/spintax-for-php/ — the standalone `spintax/core` package this plugin's engine is kept in step with

= Is there a desktop editor for spintax templates? =

Yes — **Spintax Studio**, a free native Windows editor built for exactly this syntax: two-pane live preview rendered by a real engine, inline validation with per-error documentation, variable panels, variant counting and export, and built-in help in 14 languages. Install it from the [Microsoft Store](https://apps.microsoft.com/detail/9mw3ch7b530p); an overview of all the editor tooling (including VS Code and Sublime Text extensions) lives at https://spintax.net/spintax-editor/

Studio embeds `spintax-win` v0.8.1 — the Object Pascal engine from the same corpus-locked family as this plugin's — so a template that validates and previews there behaves the same way when this plugin renders it.

= Does caching require Redis or Memcached? =

The plugin uses the WordPress Object Cache API. With a persistent backend (Redis, Memcached), cached output persists across requests. Without one, templates are re-rendered on each page load.

= Can I pass variables through shortcodes? =

Yes: `[spintax slug="greeting" name="Alice" city="Moscow"]` makes `%name%` and `%city%` available inside the template.

= Can I use product data from WooCommerce? =

Yes, since 2.2. On a single-product page the plugin auto-detects the current product and exposes it to `[spintax]` and `spintax_render()` as `%product_*%` variables — for example `%product_name%`, `%product_slug%`, `%product_sku%`, `%product_type%`, `%product_stock_status%`, `%product_categories%`, `%product_tags%`, `%product_short_description%`, and one `%product_attribute_<slug>%` per product attribute. So a template embedded as `[spintax slug="product-seo-block"]` on a product renders that product's data, and the same template on two products gets two separate cached variants.

Pricing (`%product_price%` and friends) is intentionally **not** exposed: it is volatile commerce data, not generated copy, and folding it into templates would churn the render cache on every price change.

To target a specific product regardless of the current page, pass `[spintax slug="…" product_id="123"]`; any explicit variable you pass always overrides the auto-detected one. WooCommerce is optional: with it inactive, or on non-product pages, behavior is unchanged.

Since 2.4 the plugin can also **write** generated copy into a product — see the next question. Product loops and cards are still deferred.

= Can Spintax write the product description itself? =

Yes, since 2.4. Create a binding with the target kind **WooCommerce product field**, on the **Product** post type, and pick **Description** or **Short description**. Every matching product then gets its own rendered copy — seeded when the field is empty, or regenerated on save if you ask for that — through the same machinery as ACF and post-meta bindings: cron schedules, Bulk Apply, WP-CLI, and the Logs page.

Turn on **Expose WooCommerce product data** in the binding's Variables tab and the template can use that product's own facts — `%product_name%`, `%product_sku%`, `%product_type%`, `%product_categories%`, `%product_tags%`, `%product_attribute_<slug>%` — so each product gets copy that is actually about *it*, not just a differently-worded version of the same sentence.

Three deliberate limits:

* **Only those two fields are writable.** Price, SKU, stock and sale dates are commerce data, not copy. A template cannot reach them — the whitelist is enforced when you save the binding and again before every write.
* **Manual edits win.** With "Preserve manual edits" on (the default), a description a human has changed is never overwritten; the binding skips it and says so in the Logs.
* **Writes go through WooCommerce.** Not straight into the database — so WooCommerce's own caches, lookup tables and save hooks stay consistent, and other plugins that listen for product saves still hear them.

With WooCommerce deactivated, product bindings simply stop writing. Copy that was already generated stays where it is: by then it is the product's real description, and reverting it would destroy content.

= What are ACF / post-meta bindings? =

A binding pairs a Spintax template (or a per-post inline source) with one target field on one post type — for example "Posts → ACF: hero_subtitle". Configure it once under Spintax → Bindings and the plugin populates the field on every matching post on save, on a cron schedule, or on demand via Bulk Apply. Manual edits are preserved by default (hash-tracked); flags control whether the binding auto-seeds empty fields, regenerates on every save, or clears the field when the template renders to empty.

= Can I bind to ACF fields? =

Yes. Bindings support both ACF (text / textarea / wysiwyg, top-level fields) and plain post-meta keys. ACF Free and Pro are both supported; nested fields (repeater / flexible_content rows) are not supported in 2.0 — that lands in a later release. The form-side field picker auto-fills the stable ACF field key so writes work on the first save without ACF's reference-meta handshake.

= Do I need Action Scheduler? =

It's a recommended optional dependency for binding-heavy sites. The plugin works without it: admins can run a walk via the synchronous **Run now** button on each binding card, or `wp spintax bindings apply --binding=<id> --all` from the CLI. What Action Scheduler adds is chunked async execution, so:

* The admin **Bulk Apply** button can dispatch a non-blocking background job instead of holding the request.
* Per-binding cron schedules enqueue an async job instead of running the walk inline on the cron tick — useful on large catalogues where the synchronous path risks PHP-FPM timeouts.

Many WP shops already ship Action Scheduler bundled with WooCommerce or other plugins — check Plugins → Installed Plugins for "Action Scheduler" before installing it separately. If the Bindings admin page shows an "Action Scheduler is not installed" notice at the top, you don't have it loaded yet.

= What's the difference between Bulk Apply and Run now? =

Both walk every matching post for a binding and produce the same writes. They differ in *how* the walk runs:

* **Bulk Apply** — dispatches the walk to Action Scheduler as chunked async jobs. The request returns immediately and you can watch progress on the Logs page. Requires Action Scheduler.
* **Run now** — runs the entire walk synchronously in the current request. No async dependency, but the page blocks until the walk finishes. Available to administrators, and the recommended path on sites without Action Scheduler.

When Action Scheduler isn't loaded, the Bulk Apply button is disabled with a tooltip pointing at Run now / WP-CLI; the stale-source banner on the binding edit form promotes Run now to its primary action.

= Where do I see Bulk Apply or Run now progress? =

**Spintax → Logs** in the admin sidebar. Both paths log a completion entry per walk (e.g. `Bulk Apply run_synchronously completed for binding <id> — wrote=N skipped=M cleared=K.`), plus warnings for partial failures. The Logs page supports level filtering, substring search, and pagination; entries are kept in a ring buffer sized by Settings → Spintax → Max log entries.

= What WP-CLI commands does the plugin add? =

Five subcommands under `wp spintax bindings`:

* `wp spintax bindings list [--format=table|json|csv]` — list all bindings on the site.
* `wp spintax bindings apply --binding=<id> [--all|--post=<id>]` — run a binding against every matching post (`--all`) or a single post (`--post=<id>`). This is the synchronous fallback path for Bulk Apply.
* `wp spintax bindings test --binding=<id> --post=<id>` — dry-run a binding against one post and report what would be written (target value, rendered preview, skip reason). Same logic as the admin Test panel; use this instead of `apply` when you want a preview.
* `wp spintax bindings export {--binding=<id>|--all} [> bindings.json]` — emit one binding or the full store as JSON to stdout, deduped by `(post_type, target.key)`.
* `wp spintax bindings import --file=bindings.json [--overwrite] [--dry-run]` — import bindings from JSON. `--overwrite` updates matches on the same target triple; without it, duplicates are skipped. Use `--dry-run` to preview the plan without writing.

The export/import pair is the recommended staging→production sync path; bindings are not exposed over REST in 2.0.

= What variables can I use inside a bound template? =

A binding template sees four layered variable sources (later layers override earlier ones):

* **Global variables** — the `#set` block in Settings → Spintax. Site-wide.
* **Per-binding overrides** — a block of `#set` / `#def` lines in the binding's Variables tab. Applies to that binding only.
* **Post context** (opt-in checkbox) — `%post_id%`, `%post_title%`, `%post_url%`, `%post_slug%`, `%post_date%`, `%post_modified%`, `%author_id%`, `%author_name%`.
* **ACF sibling fields** (opt-in checkbox, ACF-target bindings only) — every top-level text / textarea / wysiwyg field in the same ACF group, available as `%acf_<field_name>%`. Siblings are always fresh on save: the binding runs after ACF persists.

The binding's source can also use the rest of the Spintax syntax (`{a|b|c}`, `[a|b]`, `{?VAR?then|else}`, `{plural %N%: …}`, `#include "slug"`, `/#comment#/`).

= How do I schedule bindings to run automatically? =

Two trigger paths, both configurable per binding under "Triggers":

* **Fire on post save** (checkbox, default on) — runs after the post (and ACF, if present) finishes saving. Skipped on autosaves, bulk-edits, batch REST imports, revisions, and trash flips.
* **Cron schedule** (dropdown: disabled / hourly / twicedaily / daily) — each binding gets its own scheduled tick. With Action Scheduler installed the tick enqueues an async walk; without it, the walk runs synchronously on the cron worker.

For a one-off "apply now", click **Bulk Apply** (async, needs Action Scheduler) or **Run now** (synchronous, admins) on the binding card.

= How does the plugin handle manual edits to bound fields? =

Each binding signs its last-rendered value and re-checks the target before every write. With **Preserve manual edits** enabled (default):

* If the current value still matches the last render, the binding is free to regenerate.
* If the value has been edited outside the binding, the run is skipped and the skip is logged.

Pair this with **Regenerate on every save** for a "refresh on save unless edited" workflow. With **Auto-seed empty fields** alone, the binding only writes when the target is empty — manual edits are preserved by definition.

Cold-start safety net: when a binding first sees a post with non-empty target content and no prior render on file, it treats the existing value as a manual baseline and skips that post until the field is cleared or the binding's "Initialize from current value" flow is run.

= I edited a template. Why aren't the changes showing up on the front end? =

Bindings are a **pre-generation** system, not a render-on-read layer. The rendered string is stored in the target field; consumers (themes, blocks, REST readers) get that stored value directly. Editing the source template doesn't propagate to existing posts until a trigger writes a fresh value to each one.

When you edit a template that has bindings pointing at it, the plugin:

1. Bumps an internal render-cache version on each affected binding.
2. Surfaces an admin notice on the template-edit screen ("N bindings depend on this template").
3. Shows a "Stale: source template edited" badge on each affected binding's card.

To push the new content to existing posts, click **Bulk Apply** on each affected binding (or run `wp spintax bindings apply --binding=<id> --all` from the CLI). The Stale badge only clears when the entire walk completes with zero failures — partial-failure walks keep the badge so you notice the divergence and retry.

= Is there a hard cap on bindings? =

200 bindings per site. The store is a single autoloaded option (~500 bytes per binding), and the cap keeps autoload memory bounded. If you genuinely need more, please open an issue with your use case.

= Which fields can't I bind to? =

The form rejects a handful of unsafe targets at save time:

* WordPress-internal meta keys (anything starting with `_wp_`, `_edit_`, `_oembed_`, etc.).
* Plugin-internal `_spintax_*` slots used to store source, signatures, and cache versions.
* `wp_posts` columns like `post_title`, `post_content`, `post_excerpt`. These are not post-meta and writing to them via the meta API would silently create shadow rows.
* The same target name already bound by another binding — one binding per (post type, target field), whether the kind is ACF or post-meta.
* For ACF targets: the stable ACF field key must be present and resolvable when ACF is loaded.

= On multisite, are bindings shared across the network? =

No — bindings are per-site. Each subsite manages its own. Use `wp --url=site2 spintax bindings import --file=site1-bindings.json` to copy bindings between subsites via the WP-CLI export/import round-trip.

= Can I manage bindings via REST? =

Not in 2.0; bindings are admin-only. The `wp spintax bindings` WP-CLI surface covers staging→production sync scenarios. REST API exposure is tracked for a later release.

= I'm coming from `nested-spintax-for-acf`. Is there a migration path? =

Yes. After activating Spintax 2.0, a dismissible admin banner points to **Tools → Spintax Migration**. The wizard scans for predecessor data, shows a per-row preview, and creates bindings deduped by `(post type, target field)`. Per-post sources and variables are copied non-destructively — the old plugin's data stays in place until you delete it.

= Can I use the same engine outside WordPress? =

Yes — the engine is published as a family of standalone open-source libraries, so a template you author here renders identically elsewhere:

* **PHP:** `composer require spintax/core` — https://packagist.org/packages/spintax/core
* **JavaScript / TypeScript:** `npm i @spintax/core` — https://www.npmjs.com/package/@spintax/core
* **Python:** `pip install spintax-core` — https://pypi.org/project/spintax-core/
* **.NET:** `dotnet add package Spintax.Core` — https://www.nuget.org/packages/Spintax.Core — `netstandard2.0` and `net472`, so .NET Framework 4.7.2+ and every .NET since
* **Object Pascal:** `spintax-win` v0.8.1 — https://github.com/investblog/spintax-win — the engine inside Spintax Studio
* **OpenCart 3.x:** a separate extension built on the same engine.

All five libraries are MIT-licensed and dependency-free. Together with this plugin they make six independent engines held to a shared golden corpus — one set of fixtures every engine must reproduce, enforced in continuous integration — so "renders identically" is a verified guarantee rather than an intention. The family is described at https://spintax.net/spintax-engines/. Handy when a headless front end, a CLI job, or a non-WordPress site has to produce the same copy as your WordPress pages.

= Can I use spintax in n8n, or from an AI agent? =

Yes — the same engine also ships as automation and agent tooling, held to the same corpus as this plugin:

* **n8n:** the community node `n8n-nodes-spintax` renders, validates and checks templates inside a workflow — one unique message per row of a sheet, for example. Guide: https://spintax.net/spintax-for-n8n/
* **MCP (AI agents):** `@spintax/mcp` lets Claude, Cursor or any MCP-capable agent validate and render a template before it is published — hosted at `https://spintax.net/mcp`, or locally via `npx @spintax/mcp`. Guide: https://spintax.net/spintax-mcp/
* **Drafting with an LLM:** `@spintax/authoring-prompt` is the maintained prompt for writing templates in this syntax; the approach is written up at https://spintax.net/ai-spintax-templates/

A template drafted anywhere in that toolchain pastes straight into this plugin and renders the same way.

== Screenshots ==

1. Template editor with spintax markup and live preview.
2. Settings page with global variables editor.
3. Template list with shortcode, cache status, and cron schedule.
4. Binding edit form: three-tab layout (Source & Target / Behavior / Test), ACF combobox with stable field-key autofill, post-type and status scope filters, shared-template vs per-post source modes.

== External services ==

This plugin does **not** connect to any external services, APIs, or third-party servers.

All content generation happens locally on your WordPress server. No data is sent externally. No remote requests are made during activation, rendering, or caching.

== Privacy Policy ==

This plugin does not collect, store, or transmit any personal user data. It does not use cookies, tracking pixels, analytics, or any form of telemetry.

Templates and their rendered output are stored entirely within your WordPress database and object cache.

== Credits ==

* Syntax: the open spintax standard documented at [spintax.net](https://spintax.net), rooted in the historical [GTW (Generating The Web)](https://spintax.net/spintax-editor/) application
* Developed by [301st](https://301.st)

== Changelog ==

= 3.1.0 =
* **Engine catch-up: the built-in engine is back in step with `spintax/core` 0.8.0.** Six fixes that already shipped across the family — Composer 0.6–0.8, npm (`@spintax/core` 0.4–0.6), PyPI, Object Pascal and .NET — every one locked by the shared cross-engine corpus.
* **Fix: a 62-character template could exhaust memory in the validator.** `#set %a% = %b% %b%` over `#set %b% = %a% %a%` doubles the text on every expansion pass, and there is no cycle, so the circular-reference guard never fired. Plural-form expansion now stops at 64 KB and reports the count as unknowable instead of dying. Every engine in the family had this.
* **Fix: the same template could exhaust memory at render.** A render now expands at most 1 MB of `%variable%` text; past that a reference is left as a literal `%name%` — exactly what an undefined name already does. The budget is per render and shared by `#include`d templates, so a nested include cannot reset it.
* **Fix: plural forms are counted after definitions expand, the way rendering counts them.** `#def %tail% = few|many` with `{plural 2: one|%tail%}` under `ru` rendered correctly yet was reported as the wrong number of forms. The validator now substitutes definitions first — and only where the count is provably fixed; a value carrying brackets suppresses the verdict instead of guessing. Templates that were wrongly flagged are now valid; the one new error is a `#set` whose value smuggles extra `|`-separated forms into a block, which always rendered as fullwidth braces anyway.
* **Fix: one circular-reference error per variable, not per path.** A converging chain of definitions feeding a cycle produced an exponential number of identical errors — 457 bytes of template, 524,288 diagnostics. Now one per name, with the printed route capped at eight names.
* New in the validator API: a `{plural …}` block with a non-default form count validated **without a locale** now carries a warning instead of passing silently. The template editor always passes the template's own locale, so editors keep getting the definite verdict as before.
* **Fix: a render that hit the expansion budget is no longer stored in the object cache.** A child template cut short inside a large parent could otherwise be served truncated, under its own cache key, to a page that renders it alone and could afford it in full. Found in the release review; a debug log line now records the truncation.
* Tested up to WordPress 7.1. Tests: +22 since 3.0.2 (711 PHPUnit); the shared cross-engine corpus stands at 248 cases.
* Listing refresh: the .NET engine (`Spintax.Core` on NuGet) joins the family list, Spintax Studio's engine version is current, and a new FAQ covers the n8n node and the MCP server.

= 3.0.2 =
* **Engine catch-up: the plugin's built-in engine is back in step with the standalone `spintax/core` 0.5.2.** The standalone engines (Composer, npm, PyPI, Object Pascal) had moved ahead of the plugin; every change below ships identically across the family and is locked by the shared cross-engine corpus.
* **Fix: a circular `#set` no longer publishes an empty render.** A template whose definitions reference each other in a cycle used to render as an empty string; it now stops expanding at the depth budget and emits the partially-expanded text with the unresolved reference left visible — what every other engine in the family already does. The validator still reports the cycle as an error.
* **Fix: directive and `#include` recognition follows the family grammar exactly.** Variable names are ASCII, as documented — `#set %имя% = …` was silently accepted (and expanded) by this engine alone while being an error to every other; the editor now reports it and the line renders as text. Likewise an `#include` separated by a non-breaking space is plain text rather than an include, a CRLF line ending no longer leaks a carriage return into a directive's value, and a stray control character before a `#set` no longer flags a valid template as malformed.
* **Much faster validation of large templates.** The circular-reference walk and the plural-agreement analysis are now iterative: a 1,600-definition chain validates in 86 ms where it previously took 15.7 s, and definition shapes that previously hung the validator complete in seconds. Line-number reporting scales linearly too. Diagnostic output is byte-identical — order, count and messages verified against the previous engine on a 464-document differential.

Earlier releases (3.0.1 back to 1.0.0) are listed in full in `CHANGELOG.md` in the plugin's GitHub repository: https://github.com/investblog/spintax/blob/main/CHANGELOG.md

== Upgrade Notice ==

= 3.1.0 =
Engine catch-up with spintax/core 0.8.0: validation and rendering stay memory-safe on pathological templates, plural forms are counted after #def expansion (fewer false arity errors), one circular-reference error per variable. Tested up to WordPress 7.1. No template changes needed.

= 3.0.2 =
Engine parity catch-up with spintax/core 0.5.2: a circular #set renders partial text instead of empty, directive/#include grammar matches the whole engine family (ASCII variable names, as documented), and validating large templates is orders of magnitude faster.

= 3.0.1 =
Fixes a rare stray null character in rendered output near adjacent links, emails or nested shortcodes, and makes large renders much faster. No template changes needed.

= 3.0.0 =
Breaking: #set is a macro again, re-picked at every use. The new #def holds one value for the whole page. If you used #set for a plural count, that block now renders empty - change the one #set line to #def. References stay as they are; the editor flags the affected templates.

= 2.5.0 =
Adds Serbian, Croatian and Bosnian plural agreement, both Serbian scripts. Breaking for those three only: they used to accept a 2-form {plural} and now need 3, having silently used the English rule. A stale 2-form block renders as fullwidth braces - add the third form before updating.

= 2.4.0 =
New: WooCommerce product-field bindings. Generate a product's description or short description from a template, per product, with that product's own SKU, categories and attributes. Only those two fields are writable; manual edits are preserved.

= 2.3.3 =
Post-processing fixes: repeated punctuation (`...`, `!!!`, `?!`) is no longer split apart, `mailto:` / `tel:` links are no longer broken, and Spanish `¿ ¡` sentences keep their capital. If a binding already wrote mangled text into a field, re-run Bulk Apply to regenerate it.

= 2.3.2 =
WordPress.org listing refresh (description + tags). No code or behavior change.

= 2.3.1 =
Internal follow-up to 2.3.0: restores cheap out-of-scope skips in the bindings applier (no redundant source read on out-of-scope dry-runs). No behavior or output change. Safe upgrade.

= 2.3.0 =
Internal bindings refactor (pure Planner + target registry). No behavior change — every binding outcome is byte-for-byte identical, verified by the full test suite passing unchanged plus a new 13-outcome table test and a contract audit. Safe upgrade; nothing to do.

= 2.2.2 =
Extends 2.2.1's product-value spintax shielding to post-context and ACF-sibling binding variables via a shared utility. Record-sourced values (post_title, acf_*) now render literally instead of being re-interpreted as spintax. Template / #set / global authoring is unchanged.

= 2.2.1 =
Security hardening for 2.2.0's WooCommerce context variables: closes a same-request memo bypass of the published-product gate on explicit product_id, and neutralizes spintax characters in product values so they render literally. Recommended for 2.2.0 users.

= 2.2.0 =
Read-only WooCommerce product context variables (`%product_name%`, `%product_categories%`, `%product_attribute_<slug>%`, and more) in `[spintax]` / `spintax_render()` on single-product pages; each product caches its own variant. Pricing excluded. WooCommerce optional; non-product sites unchanged.

= 2.1.1 =
Bindings UX polish: Bulk Apply disables with a tooltip when Action Scheduler is missing, the stale-source banner promotes Run-now instead, the ACF picker keeps its selection, and clean Run-now walks write a Logs entry so the success notice's CTA has something to show.

= 2.1.0 =
Admin UX overhaul. New Logs page closes the "check logs" gap. Bindings form is now three keyboard-friendly tabs with a real ACF combobox. TTL fields use presets. Stale banner + trigger warning + Run-now sync button on the list. No data migration; recommended for binding users.

= 2.0.3 =
Adds runtime ACF target validation (closes a wrong-field-write path under ACF reactivation / WP-CLI imports), cumulative-failure tracking across Bulk Apply chunks (Stale badge no longer clears on partial failures), and a per-binding walk lock that refuses concurrent walks. Strongly recommended.

= 2.0.2 =
Documentation refresh for the 2.0 binding surface (Action Scheduler as a recommended optional dependency, full WP-CLI command set, variable scopes, scheduling, manual edits) plus an admin notice on the Bindings page when Action Scheduler isn't loaded. No functional changes to the engine.

= 2.0.1 =
Hot-fix for 2.0.0: cross-kind binding collisions, missing ACF field_key validation, Test panel scope-filter parity, Bulk Apply Stale-badge gating, and form value preservation on validation errors. Highly recommended if you're on 2.0.0.

= 2.0.0 =
Major release — adds ACF / post-meta bindings, per-binding cron, Bulk Apply with Action Scheduler, full WP-CLI surface, and a one-shot migration wizard for `nested-spintax-for-acf` users. No breaking changes to the existing template / shortcode / render API.

= 1.4.0 =
New `{?VAR?then|else}` conditional syntax, smarter sentence-end capitalisation around abbreviations, and a fix for `#set` directives with empty values.

= 1.1.0 =
Per-element permutation separators, auto-spacing for word separators, improved input sanitization.

= 1.0.1 =
Fixes permutation config handling, preview rendering, and scope isolation. Recommended update.

= 1.0.0 =
Initial release.
