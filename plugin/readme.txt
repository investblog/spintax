=== Spintax ===
Contributors: 301st
Tags: spintax, seo, woocommerce, acf, content generation
Requires at least: 6.2
Tested up to: 7.0
Stable tag: 2.5.0
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

**Syntax based on the GTW (Generating The Web) standard.**

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
* **Per-binding overrides** — a `#set` block in the binding's Variables tab. Applies to that binding only.
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

Yes — it is published as a standalone open-source library, so a template you author here renders identically elsewhere:

* **PHP:** `composer require spintax/core` — https://packagist.org/packages/spintax/core
* **JavaScript / TypeScript:** `npm i @spintax/core` — https://www.npmjs.com/package/@spintax/core
* **OpenCart 3.x:** a separate extension built on the same engine.

Both libraries are MIT-licensed and dependency-free. They are held to a shared golden corpus — one set of fixtures every engine must reproduce, enforced in continuous integration — so "renders identically" is a verified guarantee rather than an intention. Handy when a headless front end, a CLI job, or a non-WordPress site has to produce the same copy as your WordPress pages.

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

* Syntax based on the [GTW (Generating The Web)](https://spintax.net) standard
* Developed by [301st](https://301.st)

== Changelog ==

= 2.5.0 =
* **New: Serbian, Croatian and Bosnian plural agreement.** `{plural %n%: sat|sata|sati}` now picks the right form for `sr`, `hr` and `bs`, on the same boundaries as Russian — one for 1, 21, 101 (but not 11), few for 2-4, 22-24 (but not 12-14), many for the rest. Serbian works in both scripts: `sr-Latn` and `sr-Cyrl` follow identical grammar, and the script lives only in the words you write.
* **Action required if you already write Croatian, Serbian or Bosnian templates.** These languages previously fell back to the English 2-form rule, so `{plural %n%: kolačić|kolačići}` was accepted — and quietly rendered from the wrong set of forms. They now require **three** forms. A two-form construct is an error: at render time it is left in the page verbatim inside fullwidth braces (`｛plural 3: …｝`) instead of producing text. Search your templates for `{plural` on those languages and add the third form before updating. No other language changes behaviour.
* Tests: BCS bucket boundaries, both Serbian scripts, region suffixes (`sr_RS`), and the two-form error on both the strict and the lenient path. The rule ships identically in `@spintax/core` 0.2.0 and `spintax/core`, locked by the shared cross-engine corpus, so all three engines agree.

= 2.4.0 =
* **New: WooCommerce product-field bindings.** Bind a Spintax template to a product's description or short description and have it seeded — or regenerated — per product, through the machinery you already use: save, cron, Bulk Apply, manual-edit preservation. Writes go through WooCommerce itself rather than straight into the database, so its caches, lookup tables and save hooks stay consistent.
* **New: product data inside a binding.** Tick "Expose WooCommerce product data" and a template generating product copy can use `%product_name%`, `%product_sku%`, `%product_type%`, `%product_categories%`, `%product_tags%`, `%product_attribute_<slug>%` and more — so the copy can say something *true* about the product instead of merely varying its wording. Pricing stays out on purpose: it is volatile, and folding it into stored copy would churn on every price change.
* **Only two fields are writable.** `description` and `short_description`, and nothing else. Price, SKU, stock and sale dates are commerce data, not copy — a template cannot reach them, at save time or at run time. "Preserve manual edits" remains on by default, so a regeneration will not destroy copy a shop owner wrote by hand.
* Internal: the binding target contract gains `validate_save()`, so each target kind now owns both halves of its validation instead of scattering kind checks through the admin. Two new outcome codes (15 in total) cover WooCommerce being inactive and a product field that is not writable. A generic re-entrancy guard stops the loop a product write would otherwise cause — `$product->save()` fires the same hook the binding trigger listens on.
* Hardening: the binding Test panel no longer previews a post you do not have permission to view — it now checks `read_post` on the target before rendering, so a binding dry-run cannot be used to read a draft product's fields you could not otherwise see. Closes a pre-existing gap for post and ACF context too.
* Tests: +43 (634 PHPUnit). Verified against real WooCommerce across the full scenario matrix: seeding, regeneration, manual-edit detection, clear-on-empty, per-product isolation, Bulk Apply walks, WooCommerce deactivation, and the save loop.

= 2.3.3 =
* Fixed (post-processing): a run of sentence punctuation is no longer split apart. `Wait... what?` came out as `Wait. . . What?`, `Wow!!!` as `Wow! ! !` and `Really?!` as `Really? !` — the "add a space after .!?" rule fired *between* the marks of a run. A run is now treated as one sentence end, in every language.
* Fixed (post-processing): `mailto:` and `tel:` links survive rendering. `<a href="mailto:you@example.com">` was rewritten to `href="mailto: you@example.com"` — a broken link — because the address was shielded out from under its prefix and the leftover colon then got a space.
* Fixed (post-processing): Spanish sentence openers. `¿` and `¡` open a sentence, and the capitaliser upper-cases the first *character* after a sentence end — an inverted mark, which has no uppercase form — so every Spanish question quietly kept a lowercase first letter. Openers now carry the capital through, including `¡¿Qué haces?!` (two marks) and `<p>¿<a href="/ayuda">Necesitas ayuda</a>?</p>` (an opener followed by markup).
* Bindings note: text already generated into an ACF / post-meta field keeps the old rendering until the binding runs again — re-run Bulk Apply to regenerate it.
* Tests: +13 post-processing cases (591 PHPUnit). The same three fixes ship in `@spintax/core` and are locked by the shared cross-engine corpus, so both engines stay byte-identical.

= 2.3.2 =
* Docs / listing: refreshed the WordPress.org description and tags — surface the ACF and WooCommerce integrations and lead with the core benefit (one template → unique, non-duplicate copy across the site). No code or behavior change.

= 2.3.1 =
* Internal (bindings): restore the "a scope skip is cheap" ordering in the 2.3.0 Planner refactor — an out-of-scope binding (wrong post type / status) now rejects *before* resolving the source, as it did pre-2.3.0. Return codes and outputs are unchanged; this only avoids a redundant template / per-post source read on out-of-scope dry-runs (Test panel / WP-CLI / defensive calls). No user-facing change.

= 2.3.0 =
* Internal (bindings architecture): the binding write-decision is now a pure function (`Planner`) fed a `PlanInput` DTO, and target-kind read/write/validation is dispatched through a `TargetRegistry` instead of inline `acf_field`/`post_meta` branches. **No behavior change** — all 13 binding outcome codes, and the Test-panel dry-run vs live apply, are byte-for-byte identical. Verified by a new 13-outcome table test, the entire existing binding suite passing unchanged, and a fresh-eyes contract audit of the diff. Groundwork for future target kinds; end users see no difference.
* Tests: +27 (pure-Planner 13-outcome table, PlanCode helpers, and `plan()` array-shape locks). 577 PHPUnit tests.

= 2.2.2 =
* Security (hardening, data-derived context): post-context and ACF-sibling binding variables are now shielded the same way WooCommerce product values already were. The render engine can no longer re-interpret a record-sourced value (e.g. `%post_title%`, `%acf_<field>%`) as spintax — enumeration / permutation / conditional / plural / `%var%` — execute a nested `[spintax]`, or inject a `#include`. All three data-derived sources now share one `SpintaxShield` utility, so the "record data is content, not markup" rule holds everywhere (see the trust-level ADR in the repo's `docs/`).
* Behavior note: a post or ACF field value that *contained* spintax and previously expanded now renders literally. This is intentional — data is data; author spintax in the template. Template body, `#set` locals, global variables, `spintax_render()` arguments and shortcode attributes are unaffected.
* Tests: +6 (SpintaxShield unit + post-context shielding). 550 PHPUnit tests.

= 2.2.1 =
* Security (hardening): an explicit `[spintax product_id=N]` could still surface a draft/private product's context if that product had first been auto-detected earlier in the same request — the per-request memo was returned before the published-status gate. The memo is now scoped per resolution path (auto vs explicit), so the gate always applies. Follow-up to the 2.2.0 explicit-id gate.
* Security (defense-in-depth): WooCommerce product values (name, SKU, categories, tags, short description, attributes) are neutralized so spintax structural characters (`{` `}` `[` `]` `%` `#`) render literally instead of being re-interpreted as enumerations / permutations / variables, a nested `[spintax]`, or a `#include` directive. Product data is content, not markup.
* Tests: +5 (memo-bypass regression, product-value shielding unit + render, `#include` shield, `spintax_render()` variable pass-through). 544 PHPUnit tests.

= 2.2.0 =
* Feature (WooCommerce): product context variables. On a single-product page, `[spintax]` and `spintax_render()` now auto-expose the current product as `%product_id%`, `%product_name%`, `%product_slug%`, `%product_sku%`, `%product_type%`, `%product_stock_status%`, `%product_categories%`, `%product_tags%`, `%product_short_description%`, and one `%product_attribute_<slug>%` per attribute. Read-only — nothing is written to products. Volatile pricing data is intentionally excluded.
* Feature (WooCommerce): pass `product_id="123"` to target a specific product regardless of the current page; explicit shortcode / PHP variables always override auto-detected product variables. Explicit `product_id` exposes published products only, so it can't surface draft or private product data.
* Correctness: product variables enter the runtime layer, so each product renders (and caches) its own variant — product A's cached output can never leak to product B. Non-product pages and WooCommerce-inactive sites are byte-for-byte unchanged.
* Performance: the product variable map (including the term lookups behind `%product_categories%` / `%product_tags%`) is memoised per product for the request; nested `[spintax]` / `#include` inherit the product context without re-detecting it.
* Fix (engine): `{plural %n%: …}` no longer renders empty when the count variable was `#set` to an enumeration (e.g. `#set %n% = {1|4|9}`). Enumerations inside `#set` values now collapse once, so a variable holds a single stable value — the plural count sees a real number and every `%n%` reference stays consistent. Values carrying conditionals/plurals are left deferred (unchanged).
* Internal: new `WooCommerceProductContextSource` + `RuntimeContextBuilder`, wired into the shortcode and `spintax_render()` entry points. WooCommerce remains an optional dependency — no fatal errors when it is absent. 539 PHPUnit tests (was 520).

= 2.1.1 =
* UX (Bindings list): "Bulk Apply" button now disables and exposes a tooltip pointing at the Run-now / WP-CLI fallback when Action Scheduler isn't installed — previously the click hit the `no_action_scheduler` error path so users had to click-to-learn.
* UX (Bindings list): clean synchronous Run-now walks now write a log entry too (`Bulk Apply run_synchronously completed for binding <id> — wrote=N skipped=M cleared=K`), so the "View details in Logs →" CTA on the success notice always lands on a populated page. Previously only failures logged.
* UX (Bindings form): the stale-source banner above the edit form now mirrors the list-view's Bulk Apply / Run-now pair. Without Action Scheduler, Bulk Apply is disabled with the same explanatory tooltip and Run-now is promoted to the primary action — previously the banner's only CTA routed straight into the no-AS error path.
* UX (Bindings form): the ACF field picker no longer collapses to an empty list after you select a field and refocus the input. The display string the picker writes (`name (field_key)`) is now stripped before the haystack filter, so "browse without retyping" works as documented.
* UX (Bindings form): defensive — the ACF field-key row is hidden unless `kind=acf_field` exactly (previously hid only when `kind=post_meta`, leaving an empty-kind edge case where the row could render without `hidden`).
* UX (Bindings list): Run-now capability failure now redirects back to the binding's edit form, consistent with the rest of the binding-edit error paths, instead of bouncing to the silent list view.
* UX (Settings): the **Max log entries** ring-buffer size (clamped 10–5000, default 200) is now exposed as a form field on Settings → Spintax. The option key was already wired up internally; this release adds the missing control.
* Internal: tightened `test_run_now_handler_rejects_non_admin` — replaces a conditional soft assertion with two unconditional invariants (walk-lock never acquired, no "Wrote N" success flash).
* Internal: 520 PHPUnit tests, 950 assertions (was 514 / 938 in 2.1.0).

= 2.1.0 =
* UX (Settings): Spintax Settings is now also reachable from the Spintax submenu (under Bindings), not only from WP Settings → Spintax — both menu paths resolve to the same page.
* UX (Settings): Default Cache TTL and per-template Cache TTL no longer use a bare seconds input. Both surfaces now offer human presets (No caching / 1 hour / 6 hours / 1 day / 1 week / 1 month) plus a "Custom…" option for any exact-seconds value.
* UX (Settings): "Purge All Template Caches" button moved inline into the Default Cache TTL row.
* UX (Bindings): New **Logs** admin page under Spintax → Logs — newest-first table of ring-buffer entries with level filter, substring search, pagination clamped to settings.logs_max. Editors view; admins clear. Replaces the pre-2.1.0 "Check logs" admin notice that pointed at a screen that didn't exist.
* UX (Bindings): Admin notices that point at logs (Bulk Apply enqueued, etc.) now ship a real "View progress in Logs →" link. The flash-notice trait accepts a `{text, action_url, action_label}` payload alongside legacy strings.
* UX (Bindings): Binding edit / create form is now a three-tab layout (Source & Target / Behavior / Test). WAI-ARIA tablist + keyboard navigation (Arrow keys, Home / End). Validation errors redirect back to whichever tab the offending field lives on; the active tab survives the PRG round-trip via flash transient and `?active_tab=` query arg.
* UX (Bindings): ACF field picker on the form is now a custom searchable combobox (replaces the buggy `<input list>` + `<datalist>`). Group → field grouping, substring search across group / label / name, full ARIA combobox semantics, and clicking a row autofills both the field name and the stable ACF field key in one go.
* UX (Bindings): Inline "This binding will never run" warning under Triggers when both `save_post` is off and cron is disabled — live update on checkbox / select change so editors notice the problem before submitting.
* UX (Bindings): Stale-source banner above the binding form (and an inline "Bulk Apply now" button) when the persisted binding's source template has been edited since the last walk.
* UX (Bindings): "Run now" button next to Bulk Apply (admins only, gated on debug=true OR Action Scheduler absent). Runs the walk synchronously via `BulkApply::run_synchronously()` — useful for dev sites without cron traffic and for installs without Action Scheduler. Walk-status badge ("Running (started Ns ago)") appears on the card while the per-binding walk lock is held.
* UX (Bindings): The "Action Scheduler is not installed" notice is now per-user dismissible via a new `wp_ajax_spintax_dismiss_admin_notice` endpoint. Whitelisted notice ids prevent the endpoint from filling `wp_usermeta` with arbitrary rows.
* UX (Bindings): Stale "Phase 3 will add a dropdown" copy removed (Phase 3 shipped in 2.0.0). "Bind to a Spintax template (DRY across posts)" softened to "Shared template — render the same source on every matching post".
* Internal: AdminNotice trait extended with backward-compat for both legacy `{message, type}` and the new rich payload. BindingsPage constructor now accepts an optional `BulkApply` for test injection. New shared `Spintax\Support\TtlField` helper backs the preset / custom TTL widget. Notice action-url is `esc_url()`-filtered so `javascript:` and other unsafe schemes are stripped.
* Internal: 73 new PHPUnit cases — TTL preset / custom resolver, Settings + meta-box save paths, dual-menu registration, AdminNotice payload shapes, LogsPage filtering / pagination / capability gating, Bindings tabs ARIA + PRG round-trip, ACF combobox rendering, dismissible notice endpoint, stale banner from persisted entity (not flash draft), trigger warning visibility, Run now handler gates + walk badge thresholds. 514 tests total (was 441).

= 2.0.3 =
* Fix: ACF target validation now runs on every apply, not just at form save. `BindingApplier::plan()` rejects bindings whose stored `target.field_key` no longer resolves to a field with the expected name (deleted, renamed, or re-assigned in ACF). Two new return codes: `skip_acf_not_loaded` (ACF deactivated since the binding was saved) and `skip_invalid_acf_field` (key + name disagreement). Closes a path where CLI-imported or imported-while-ACF-inactive bindings could write through `update_field()` to the wrong field.
* Fix: `BindingApplier::read_target()` and `::write_target()` no longer fall back to plain `update_post_meta()` / `get_post_meta()` for `kind = acf_field` when ACF isn't loaded. The applier short-circuits at the runtime guard above, so the low-level methods are the sole writer for verified targets. Pre-2.0.3 the silent fallback could write the rendered value to a post-meta row ACF would never see again.
* Fix: Bulk Apply now tracks failures cumulatively across chunks via a persistent `_spintax_binding_walk_failed_v_<id>` flag. The final chunk gates `stamp_last_applied_version()` on the cumulative flag. 2.0.1 only checked the current chunk, so a multi-chunk walk that failed in chunk 1 and succeeded in the final chunk would still clear the Stale badge.
* Fix: Concurrent Bulk Apply walks on the same binding are now refused with `WP_Error 'walk_in_progress'`. Both `enqueue()` and `run_synchronously()` acquire a per-binding lock (option `_spintax_binding_walk_lock_<id>`) at walk start; stale locks older than one hour are auto-overwritten so a crashed walk doesn't permanently jam the binding.
* Internal: 11 new PHPUnit cases — runtime ACF guard, multi-chunk failure tracking, walk-lock acquisition / release, stale-lock recovery. 441 tests total (was 430).
* Tooling: `npm run lint:php` and `lint:php:fix` moved to `scripts/lint-php.sh` / `scripts/lint-php-fix.sh`. The inline command tripped over bash-c quoting on Windows. `.gitattributes` enforces LF endings on shipped text files.
* Internal: CLI `wp spintax bindings import --overwrite` help text updated to reflect the 2.0.1 `(post_type, target.key)` uniqueness contract.

= 2.0.2 =
* Docs: new FAQ entries — Action Scheduler dependency, full `wp spintax bindings` WP-CLI surface, variable scopes (global / per-binding / post context / ACF siblings), trigger options (save_post + per-binding cron), manual edit detection, template-edit propagation, reserved-key tiers.
* Docs: Installation section now flags Action Scheduler as a recommended optional dependency with the specific features it enables.
* UX: Spintax → Bindings shows an info notice at the top of the page when Action Scheduler isn't loaded, explaining the two features that degrade (admin Bulk Apply, async cron walks) and linking to the install screen. Notice disappears when AS is loaded by any source (direct install, WooCommerce / Jetpack bundle, mu-plugin, etc.).
* Internal: no functional changes to the bindings engine or core spintax engine — patch is documentation + a single admin-page notice.

= 2.0.1 =
* Fix: ACF and post-meta bindings on the same `(post_type, field name)` no longer coexist — they wrote to the same database row and silently raced. Tier 4 uniqueness now ignores `target.kind`. Existing pre-2.0.1 conflicts remain in the data store but the next save of either binding will reject.
* Fix: ACF bindings now require a non-empty `target.field_key` and validate it against the live ACF field when ACF is loaded. Previously a missing or mistyped field key could route `update_field()` writes to a different field.
* Fix: Test panel and Bulk Apply now report `skip_out_of_scope_type` / `skip_out_of_scope_status` for posts that wouldn't match the binding's scope in live triggers. Two new applier return codes — total now 11 instead of 9.
* Fix: Bulk Apply only clears the Stale badge when the walk had zero failures. Partial-failure walks keep the binding flagged so editors notice the divergence and retry.
* Fix: Binding form validation errors no longer throw the editor back to the list view — the form re-renders with submitted values via a short-lived transient flash, with the specific error inline.
* Internal: 21 new PHPUnit cases covering each fix path; bindings unit suite is now exhaustive on scope-filter, cross-kind dedup, ACF field_key validation, and Bulk Apply stamp gating.

= 2.0.0 =
* **ACF / post-meta bindings** — a Spintax template (or a per-post inline source) can now be bound to any ACF text/textarea/wysiwyg field or post-meta key on a post type. Configure once under Spintax → Bindings and the plugin populates the field on save, cron, or via Bulk Apply.
* Decision-tree write behaviour with four flags: `auto_seed_empty` (default on; never clobbers existing content), `regenerate_on_save`, `preserve_manual_edits` (hash-tracks the last rendered value so external edits are detected), `clear_on_empty`. Cold-start behaviour documented to avoid false manual-edit positives.
* Per-binding cron schedules (hourly / twicedaily / daily) registered as individual `wp_schedule_event` hooks per binding.
* Bulk Apply via Action Scheduler with chunked processing; a clean WP-CLI fallback when Action Scheduler isn't installed.
* New `%post_id%`, `%post_title%`, `%post_url%`, `%post_slug%`, `%post_date%`, `%post_modified%`, `%author_id%`, `%author_name%` post-context variables — opt-in per binding.
* New `%acf_<field_name>%` variables — opt-in per binding, exposes ACF sibling fields in the same group.
* Template-edit cascade — editing a Spintax template that is referenced by bindings bumps an internal cache version and surfaces a notice telling the editor that stored target fields will refresh on the next Bulk Apply / cron / save_post.
* `wp spintax bindings list|apply|test|export|import` — full WP-CLI surface for staging→production workflows and Action-Scheduler-less environments.
* One-shot migration helper at **Tools → Spintax Migration** for users coming from the predecessor plugin `nested-spintax-for-acf`. Detects, previews, and imports legacy data deduped by `(post_type, target.key)`. Original predecessor data is never deleted by the migration.
* Reserved-key guard rejects WP-internal meta keys, plugin-internal `_spintax_*` prefixes, wp_posts column names, and duplicate `(post_type, target.kind, target.key)` triples at form save.
* Hard cap of 200 bindings per site (single autoloaded option size budget).
* Per-binding chunk size override in the Advanced form section.
* Uninstall cleans every bindings option family and sibling post-meta — no orphan rows left behind.
* Internal: 398+ PHPUnit tests, including exhaustive decision-tree coverage and migration import edge cases.

= 1.5.0 =
* Add: plural agreement primitive `{plural <count>: form1|form2|form3}` — pick the correct grammatical form by count. RU/UK/BE = 3 forms (`one|few|many`); EN/ES/PT/DE etc. = 2 forms (`one|many`). Count is a `%var%` reference or literal integer (resolved after variable expansion, so helper-var patterns via `#set` work). Locale comes from per-template post meta `_spintax_locale` or the WordPress site locale. Lenient at runtime: malformed constructs render verbatim with fullwidth braces instead of crashing the page. First spintax engine to treat plural as a first-class primitive.
* Add: validator surface for plural blocks — structural check (form slot rejects nested `{}`, `[]`) always on; arity check (RU expects 3, EN expects 2) when locale is known.
* Internal: 74 PHPUnit cases mirroring the canonical TS implementation (`spintax-plurals.test.ts` in casino-platform). Engine classes `Plurals`, `PluralArityError`, `PluralFormError` ship alongside `Conditionals` from 1.4.0.

= 1.4.0 =
* Add: conditional syntax `{?VAR?then|else}` — render a branch based on whether a variable is set/non-empty (also `{?!VAR?then}` for inverted, optional else). Resolves both before and after `%var%` expansion, so conditionals inside variable values work too.
* Add: single-token abbreviation whitelist in post-processing — known shorthands like `соц.`, `эл.`, `Mr.`, `Inc.` no longer trigger sentence-end capitalisation of the next word. Covers Russian editorial/address/unit shorthands plus English titles and business suffixes.
* Fix: `#set` directive with an empty value (`#set %x% =`) no longer silently swallows the next directive on the following line.
* Fix: HTML start tags inside permutation alternatives (e.g. `[<li>item</li>|<li>...]`) are no longer mis-parsed as a `<config>` block.
* Improve: cache description in template meta box and global settings now explains that visitors see the same generated variant per runtime context until expiry or regeneration.
* Internal: regression tests for IDN domains flanked by Cyrillic letters and for randomisation behaviour across renders.

= 1.1.0 =
* Add: per-element permutation separators — assign custom separator to each element via `< sep >` before `|`
* Add: auto-spacing for purely alphabetic word separators (e.g. `<and>`, `<или>`)
* Security: sanitize raw spintax input with custom sanitize_spintax() — strips invalid UTF-8, null bytes, and control characters while preserving angle-bracket syntax

= 1.0.1 =
* Fix: permutation minsize/maxsize logic when only one parameter is specified
* Fix: preview rendering no longer strips spintax config from template input
* Fix: child templates no longer inherit parent's local #set variables
* Improve: global variables editor now uses #set textarea (paste full blocks)
* Improve: validation errors displayed on template edit screen with line numbers
* Improve: "Regenerate Public Cache" now forces fresh subtree render
* Add: demo template created on first activation
* Add: SECURITY.md with responsible disclosure policy
* Add: Privacy Policy and External Services sections in readme.txt
* Code: PHPCS 0 errors, full WP.org review compliance

= 1.0.0 =
* Initial release
* GTW-compatible spintax engine with nested enumerations and permutations
* Template CPT with code editor and admin preview
* Shortcode and PHP rendering API
* Object cache with versioned keys and cascade invalidation
* Per-template cron regeneration
* Global and local variable scopes
* Settings page with global variables editor

== Upgrade Notice ==

= 2.5.0 =
Adds Serbian, Croatian and Bosnian plural agreement (both Serbian scripts). Breaking for those three languages only: they used to accept a 2-form `{plural}` and now require 3, because they were silently using the English rule. A stale 2-form construct renders as `｛plural …｝` instead of text — add the third form before updating. All other languages are unaffected.

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
