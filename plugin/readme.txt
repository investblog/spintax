=== Spintax ===
Contributors: 301st
Tags: spintax, content generation, templates, seo, dynamic content
Requires at least: 6.2
Tested up to: 6.9
Stable tag: 2.0.1
Requires PHP: 8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Template-based dynamic content generation using spintax markup for WordPress.

== Description ==

Spintax is a WordPress plugin for template-based content generation using spintax markup. Create reusable templates with randomised text variants, variable substitution, and permutation logic — then embed them anywhere on your site via shortcodes or PHP.

**Key features:**

* **Enumerations** `{a|b|c}` — randomly pick one option, with nesting support
* **Permutations** `[<config>a|b|c]` — pick N elements, shuffle, join with custom separators
* **Variables** `%var%` — global, local (`#set`), and shortcode-level variable scopes
* **Conditionals** `{?VAR?then|else}` — render a branch based on whether a variable is set (also `{?!VAR?then}` inverted)
* **Plural agreement** `{plural <count>: form1|form2|form3}` — pick grammatically correct noun form by count. RU/UK/BE 3-form (one|few|many), EN-style 2-form (one|many). First spintax engine with first-class plurals.
* **Nested templates** — embed templates within templates via `#include` or `[spintax]`
* **ACF / post-meta bindings (NEW in 2.0)** — configure once per post type, render Spintax templates into ACF text/textarea/wysiwyg fields or post-meta keys on every matching post. Auto-seed empty fields, preserve manual edits, Bulk Apply via Action Scheduler.
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

== Frequently Asked Questions ==

= How do I create a template? =

Go to Spintax > Add New in the WordPress admin. Enter a title and your spintax markup in the editor.

= What syntax does the plugin use? =

* `{a|b|c}` — randomly picks one option
* `[a|b|c]` — permutation: picks N elements, shuffles, joins with space
* `[<minsize=2;maxsize=3;sep=", ";lastsep=" and "> a|b|c|d]` — configured permutation
* `%variable%` — variable reference
* `#set %var% = value` — local variable definition
* `{?VAR?then|else}` — conditional: render a branch by truthiness of `%VAR%` (also `{?!VAR?then}` inverted)
* `{plural %Count%: form1|form2|form3}` — plural agreement: picks the correct grammatical form by count (RU 3-form, EN 2-form)
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

= What are ACF / post-meta bindings? =

A binding pairs a Spintax template (or a per-post inline source) with one target field on one post type — for example "Posts → ACF: hero_subtitle". Configure it once under Spintax → Bindings and the plugin populates the field on every matching post on save, on a cron schedule, or on demand via Bulk Apply. Manual edits are preserved by default (hash-tracked); flags control whether the binding auto-seeds empty fields, regenerates on every save, or clears the field when the template renders to empty.

= Can I bind to ACF fields? =

Yes. Bindings support both ACF (text / textarea / wysiwyg, top-level fields) and plain post-meta keys. ACF Free and Pro are both supported; nested fields (repeater / flexible_content rows) are not supported in 2.0 — that lands in a later release. The form-side field picker auto-fills the stable ACF field key so writes work on the first save without ACF's reference-meta handshake.

= Is there a hard cap on bindings? =

200 bindings per site. The store is a single autoloaded option (~500 bytes per binding), and the cap keeps autoload memory bounded. If you genuinely need more, please open an issue with your use case.

= On multisite, are bindings shared across the network? =

No — bindings are per-site. Each subsite manages its own. Use `wp --url=site2 spintax bindings import --file=site1-bindings.json` to copy bindings between subsites via the WP-CLI export/import round-trip.

= Can I manage bindings via REST? =

Not in 2.0; bindings are admin-only. The `wp spintax bindings` WP-CLI surface covers staging→production sync scenarios. REST API exposure is tracked for a later release.

= I'm coming from `nested-spintax-for-acf`. Is there a migration path? =

Yes. After activating Spintax 2.0, a dismissible admin banner points to **Tools → Spintax Migration**. The wizard scans for predecessor data, shows a per-row preview, and creates bindings deduped by `(post type, target field)`. Per-post sources and variables are copied non-destructively — the old plugin's data stays in place until you delete it.

== Screenshots ==

1. Template editor with spintax markup and live preview.
2. Settings page with global variables editor.
3. Template list with shortcode, cache status, and cron schedule.

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
