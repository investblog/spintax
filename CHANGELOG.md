# Changelog

All notable changes to the Spintax WordPress plugin, newest first. The WordPress.org listing (`plugin/readme.txt`) carries only the most recent releases, because the directory shows at most 5,000 characters of changelog; this file is the complete history. Upgrade notices live in `plugin/readme.txt`.

## 3.2.0
* **Engine catch-up: the built-in engine is back in step with `spintax/core` 0.9.0.** The same three changes, locked by the shared cross-engine corpus.
* **Fix: a sentence glued to the next one gets its space back.** The engine protects bare domains and email addresses from the spacing pass, and it was taking any `word.Word` for a domain — so `kept compact.Game categories` and `конец.Начало` stayed glued. A domain's last label must now be all lower case or all upper case. `example.com`, `ASP.NET`, `info@Example.COM` and `例子.中国` are untouched; the accepted cost is that `Yandex.Money` renders as `Yandex. Money` and `info@example.Com` is no longer treated as an address. **Rendered text changes for these shapes** — the only user-visible change in this release.
* **Fix: a long dotted chain no longer costs seconds of CPU.** Text like `a.a.a.…a.Game` made the address and domain protection restart from every label: 2,000 one-letter labels took 48 ms where they had taken 0.7. Now 0.1–0.9 ms. Ordinary text costs 2–4% more.
* **Much faster rendering of templates with many `#def` definitions.** Three inner loops were charging the whole variable map for every definition in it — ordering them, rebuilding the map on each roll, and, in the validator, re-walking a cycle to count the names a shortened message leaves out. Measured on PHP 8.4, same machine: 3,200 chained definitions 8.7 s → 0.010 s, 12,800 independent ones 14.5 s → 0.021 s, and validating one cycle of 16,000 names 6.1 s → 0.163 s. Nothing a template renders changes: byte-identical across 3,000 generated definition-graph documents driven through the renderer's own roll, with six deliberate mutations proving the check can see breakage first.
* Known and unchanged, recorded so it is not mistaken for new: a definition named only with digits (`#def %7%`) still does not resolve here, where every other engine in the family resolves it ([spintax-js#84](https://github.com/investblog/spintax-js/issues/84)). And this engine rolls definitions in its own order, which the family has not yet decided on ([spintax-js#82](https://github.com/investblog/spintax-js/issues/82)); the test suite now pins that order so it cannot drift by accident.

## 3.1.0
* **Engine catch-up: the built-in engine is back in step with `spintax/core` 0.8.0.** Six fixes that already shipped across the family — Composer 0.6–0.8, npm (`@spintax/core` 0.4–0.6), PyPI, Object Pascal and .NET — every one locked by the shared cross-engine corpus.
* **Fix: a 62-character template could exhaust memory in the validator.** `#set %a% = %b% %b%` over `#set %b% = %a% %a%` doubles the text on every expansion pass, and there is no cycle, so the circular-reference guard never fired. Plural-form expansion now stops at 64 KB and reports the count as unknowable instead of dying. Every engine in the family had this.
* **Fix: the same template could exhaust memory at render.** A render now expands at most 1 MB of `%variable%` text; past that a reference is left as a literal `%name%` — exactly what an undefined name already does. The budget is per render and shared by `#include`d templates, so a nested include cannot reset it.
* **Fix: plural forms are counted after definitions expand, the way rendering counts them.** `#def %tail% = few|many` with `{plural 2: one|%tail%}` under `ru` rendered correctly yet was reported as the wrong number of forms. The validator now substitutes definitions first — and only where the count is provably fixed; a value carrying brackets suppresses the verdict instead of guessing. Templates that were wrongly flagged are now valid; the one new error is a `#set` whose value smuggles extra `|`-separated forms into a block, which always rendered as fullwidth braces anyway.
* **Fix: one circular-reference error per variable, not per path.** A converging chain of definitions feeding a cycle produced an exponential number of identical errors — 457 bytes of template, 524,288 diagnostics. Now one per name, with the printed route capped at eight names.
* New in the validator API: a `{plural …}` block with a non-default form count validated **without a locale** now carries a warning instead of passing silently. The template editor always passes the template's own locale, so editors keep getting the definite verdict as before.
* **Fix: a render that hit the expansion budget is no longer stored in the object cache.** A child template cut short inside a large parent could otherwise be served truncated, under its own cache key, to a page that renders it alone and could afford it in full. Found in the release review; a debug log line now records the truncation.
* Tested up to WordPress 7.1. Tests: +22 since 3.0.2 (711 PHPUnit); the shared cross-engine corpus stands at 248 cases.
* Listing refresh: the .NET engine (`Spintax.Core` on NuGet) joins the family list, Spintax Studio's engine version is current, and a new FAQ covers the n8n node and the MCP server.

## 3.0.2
* **Engine catch-up: the plugin's built-in engine is back in step with the standalone `spintax/core` 0.5.2.** The standalone engines (Composer, npm, PyPI, Object Pascal) had moved ahead of the plugin; every change below ships identically across the family and is locked by the shared cross-engine corpus.
* **Fix: a circular `#set` no longer publishes an empty render.** A template whose definitions reference each other in a cycle used to render as an empty string; it now stops expanding at the depth budget and emits the partially-expanded text with the unresolved reference left visible — what every other engine in the family already does. The validator still reports the cycle as an error.
* **Fix: directive and `#include` recognition follows the family grammar exactly.** Variable names are ASCII, as documented — `#set %имя% = …` was silently accepted (and expanded) by this engine alone while being an error to every other; the editor now reports it and the line renders as text. Likewise an `#include` separated by a non-breaking space is plain text rather than an include, a CRLF line ending no longer leaks a carriage return into a directive's value, and a stray control character before a `#set` no longer flags a valid template as malformed.
* **Much faster validation of large templates.** The circular-reference walk and the plural-agreement analysis are now iterative: a 1,600-definition chain validates in 86 ms where it previously took 15.7 s, and definition shapes that previously hung the validator complete in seconds. Line-number reporting scales linearly too. Diagnostic output is byte-identical — order, count and messages verified against the previous engine on a 464-document differential.

## 3.0.1
* **Fix: rendered output can no longer contain a stray null character.** In rare templates that place a link, a `mailto:`/`tel:` address, an email or a nested `[spintax]` shortcode directly next to one another, the engine could emit an invalid U+0000 (NUL) byte — which some databases reject and browsers show as a replacement character. The internal placeholder handling in both the post-processor and the renderer was reworked so this can no longer happen.
* **Faster rendering of large pages.** Restoring those internal placeholders is now linear instead of quadratic, so a very large render that previously took tens of seconds now finishes in a fraction of one. No template changes are needed. Engine parity with `@spintax/core` and `spintax/core` 0.3.1, locked by the shared cross-engine corpus.

## 3.0.0
* **Breaking: `#set` is a macro again — it is re-picked at every use.** A variable defined with `#set` now behaves as it did before 2.2.0 and as this plugin's documentation has always described: the value is substituted wherever the variable appears, and any `{a|b}` or `[a|b]` inside it is rolled fresh each time. Between 2.2.0 and this release an enumeration in a `#set` value was picked once and held, which contradicted the documented behaviour from the day it shipped.
* **New: `#def` — a value picked once per render and held.** `#def %brand% = {Acme|Acme Group}` picks one variant and every `%brand%` in that page reads the same. Use it whenever two mentions disagreeing would look like a mistake: a product name, a tone, and above all a number you both print and agree grammatically.
* **Action required if you used `#set` for a plural count.** `#set %n% = {1|4|9}` followed by `{plural %n%: …}` no longer works — the count is still spintax when the plural is decided, so the block renders empty. Change that one line to `#def %n% = {1|4|9}`; the `%n%` references stay exactly as they are. The template editor now reports this as an error, so you can find the affected templates by opening and saving them.
* Same one-line change applies to a `#set` used to extract a synonym out of a `{plural}` form slot, and to any `#set` you relied on reading the same twice in one page.
* **Fix: the editor's preview and validator now use the template's own language.** Both previously fell back to the site language, so a template with a per-template locale previewed under the wrong plural rules — a correct three-form block looked broken and a broken one looked fine.
* **Fix: bindings render under the template's language too.** A binding stores what it renders, so a three-form template applied on a two-form site was writing `｛plural …｝` into the target field.
* **Fix: a shortcode carried in by a variable now works.** `[spintax slug="…"]` reaching the page through a variable value was being stripped of its brackets and printed as text.
* New editor checks: a name defined twice (by either directive), `#include` inside a `#def` value, and an empty `#set` value no longer being reported as malformed.

## 2.5.0
* **New: Serbian, Croatian and Bosnian plural agreement.** `{plural %n%: sat|sata|sati}` now picks the right form for `sr`, `hr` and `bs`, on the same boundaries as Russian — one for 1, 21, 101 (but not 11), few for 2-4, 22-24 (but not 12-14), many for the rest. Serbian works in both scripts: `sr-Latn` and `sr-Cyrl` follow identical grammar, and the script lives only in the words you write.
* **Action required if you already write Croatian, Serbian or Bosnian templates.** These languages previously fell back to the English 2-form rule, so `{plural %n%: kolačić|kolačići}` was accepted — and quietly rendered from the wrong set of forms. They now require **three** forms. A two-form construct is an error: at render time it is left in the page verbatim inside fullwidth braces (`｛plural 3: …｝`) instead of producing text. Search your templates for `{plural` on those languages and add the third form before updating. No other language changes behaviour.
* Tests: BCS bucket boundaries, both Serbian scripts, region suffixes (`sr_RS`), and the two-form error on both the strict and the lenient path. The rule ships identically in `@spintax/core` 0.2.0 and `spintax/core`, locked by the shared cross-engine corpus, so all three engines agree.

## 2.4.0
* **New: WooCommerce product-field bindings.** Bind a Spintax template to a product's description or short description and have it seeded — or regenerated — per product, through the machinery you already use: save, cron, Bulk Apply, manual-edit preservation. Writes go through WooCommerce itself rather than straight into the database, so its caches, lookup tables and save hooks stay consistent.
* **New: product data inside a binding.** Tick "Expose WooCommerce product data" and a template generating product copy can use `%product_name%`, `%product_sku%`, `%product_type%`, `%product_categories%`, `%product_tags%`, `%product_attribute_<slug>%` and more — so the copy can say something *true* about the product instead of merely varying its wording. Pricing stays out on purpose: it is volatile, and folding it into stored copy would churn on every price change.
* **Only two fields are writable.** `description` and `short_description`, and nothing else. Price, SKU, stock and sale dates are commerce data, not copy — a template cannot reach them, at save time or at run time. "Preserve manual edits" remains on by default, so a regeneration will not destroy copy a shop owner wrote by hand.
* Internal: the binding target contract gains `validate_save()`, so each target kind now owns both halves of its validation instead of scattering kind checks through the admin. Two new outcome codes (15 in total) cover WooCommerce being inactive and a product field that is not writable. A generic re-entrancy guard stops the loop a product write would otherwise cause — `$product->save()` fires the same hook the binding trigger listens on.
* Hardening: the binding Test panel no longer previews a post you do not have permission to view — it now checks `read_post` on the target before rendering, so a binding dry-run cannot be used to read a draft product's fields you could not otherwise see. Closes a pre-existing gap for post and ACF context too.
* Tests: +43 (634 PHPUnit). Verified against real WooCommerce across the full scenario matrix: seeding, regeneration, manual-edit detection, clear-on-empty, per-product isolation, Bulk Apply walks, WooCommerce deactivation, and the save loop.

## 2.3.3
* Fixed (post-processing): a run of sentence punctuation is no longer split apart. `Wait... what?` came out as `Wait. . . What?`, `Wow!!!` as `Wow! ! !` and `Really?!` as `Really? !` — the "add a space after .!?" rule fired *between* the marks of a run. A run is now treated as one sentence end, in every language.
* Fixed (post-processing): `mailto:` and `tel:` links survive rendering. `<a href="mailto:you@example.com">` was rewritten to `href="mailto: you@example.com"` — a broken link — because the address was shielded out from under its prefix and the leftover colon then got a space.
* Fixed (post-processing): Spanish sentence openers. `¿` and `¡` open a sentence, and the capitaliser upper-cases the first *character* after a sentence end — an inverted mark, which has no uppercase form — so every Spanish question quietly kept a lowercase first letter. Openers now carry the capital through, including `¡¿Qué haces?!` (two marks) and `<p>¿<a href="/ayuda">Necesitas ayuda</a>?</p>` (an opener followed by markup).
* Bindings note: text already generated into an ACF / post-meta field keeps the old rendering until the binding runs again — re-run Bulk Apply to regenerate it.
* Tests: +13 post-processing cases (591 PHPUnit). The same three fixes ship in `@spintax/core` and are locked by the shared cross-engine corpus, so both engines stay byte-identical.

## 2.3.2
* Docs / listing: refreshed the WordPress.org description and tags — surface the ACF and WooCommerce integrations and lead with the core benefit (one template → unique, non-duplicate copy across the site). No code or behavior change.

## 2.3.1
* Internal (bindings): restore the "a scope skip is cheap" ordering in the 2.3.0 Planner refactor — an out-of-scope binding (wrong post type / status) now rejects *before* resolving the source, as it did pre-2.3.0. Return codes and outputs are unchanged; this only avoids a redundant template / per-post source read on out-of-scope dry-runs (Test panel / WP-CLI / defensive calls). No user-facing change.

## 2.3.0
* Internal (bindings architecture): the binding write-decision is now a pure function (`Planner`) fed a `PlanInput` DTO, and target-kind read/write/validation is dispatched through a `TargetRegistry` instead of inline `acf_field`/`post_meta` branches. **No behavior change** — all 13 binding outcome codes, and the Test-panel dry-run vs live apply, are byte-for-byte identical. Verified by a new 13-outcome table test, the entire existing binding suite passing unchanged, and a fresh-eyes contract audit of the diff. Groundwork for future target kinds; end users see no difference.
* Tests: +27 (pure-Planner 13-outcome table, PlanCode helpers, and `plan()` array-shape locks). 577 PHPUnit tests.

## 2.2.2
* Security (hardening, data-derived context): post-context and ACF-sibling binding variables are now shielded the same way WooCommerce product values already were. The render engine can no longer re-interpret a record-sourced value (e.g. `%post_title%`, `%acf_<field>%`) as spintax — enumeration / permutation / conditional / plural / `%var%` — execute a nested `[spintax]`, or inject a `#include`. All three data-derived sources now share one `SpintaxShield` utility, so the "record data is content, not markup" rule holds everywhere (see the trust-level ADR in the repo's `docs/`).
* Behavior note: a post or ACF field value that *contained* spintax and previously expanded now renders literally. This is intentional — data is data; author spintax in the template. Template body, `#set` locals, global variables, `spintax_render()` arguments and shortcode attributes are unaffected.
* Tests: +6 (SpintaxShield unit + post-context shielding). 550 PHPUnit tests.

## 2.2.1
* Security (hardening): an explicit `[spintax product_id=N]` could still surface a draft/private product's context if that product had first been auto-detected earlier in the same request — the per-request memo was returned before the published-status gate. The memo is now scoped per resolution path (auto vs explicit), so the gate always applies. Follow-up to the 2.2.0 explicit-id gate.
* Security (defense-in-depth): WooCommerce product values (name, SKU, categories, tags, short description, attributes) are neutralized so spintax structural characters (`{` `}` `[` `]` `%` `#`) render literally instead of being re-interpreted as enumerations / permutations / variables, a nested `[spintax]`, or a `#include` directive. Product data is content, not markup.
* Tests: +5 (memo-bypass regression, product-value shielding unit + render, `#include` shield, `spintax_render()` variable pass-through). 544 PHPUnit tests.

## 2.2.0
* Feature (WooCommerce): product context variables. On a single-product page, `[spintax]` and `spintax_render()` now auto-expose the current product as `%product_id%`, `%product_name%`, `%product_slug%`, `%product_sku%`, `%product_type%`, `%product_stock_status%`, `%product_categories%`, `%product_tags%`, `%product_short_description%`, and one `%product_attribute_<slug>%` per attribute. Read-only — nothing is written to products. Volatile pricing data is intentionally excluded.
* Feature (WooCommerce): pass `product_id="123"` to target a specific product regardless of the current page; explicit shortcode / PHP variables always override auto-detected product variables. Explicit `product_id` exposes published products only, so it can't surface draft or private product data.
* Correctness: product variables enter the runtime layer, so each product renders (and caches) its own variant — product A's cached output can never leak to product B. Non-product pages and WooCommerce-inactive sites are byte-for-byte unchanged.
* Performance: the product variable map (including the term lookups behind `%product_categories%` / `%product_tags%`) is memoised per product for the request; nested `[spintax]` / `#include` inherit the product context without re-detecting it.
* Fix (engine): `{plural %n%: …}` no longer renders empty when the count variable was `#set` to an enumeration (e.g. `#set %n% = {1|4|9}`). Enumerations inside `#set` values now collapse once, so a variable holds a single stable value — the plural count sees a real number and every `%n%` reference stays consistent. Values carrying conditionals/plurals are left deferred (unchanged).
* Internal: new `WooCommerceProductContextSource` + `RuntimeContextBuilder`, wired into the shortcode and `spintax_render()` entry points. WooCommerce remains an optional dependency — no fatal errors when it is absent. 539 PHPUnit tests (was 520).

## 2.1.1
* UX (Bindings list): "Bulk Apply" button now disables and exposes a tooltip pointing at the Run-now / WP-CLI fallback when Action Scheduler isn't installed — previously the click hit the `no_action_scheduler` error path so users had to click-to-learn.
* UX (Bindings list): clean synchronous Run-now walks now write a log entry too (`Bulk Apply run_synchronously completed for binding <id> — wrote=N skipped=M cleared=K`), so the "View details in Logs →" CTA on the success notice always lands on a populated page. Previously only failures logged.
* UX (Bindings form): the stale-source banner above the edit form now mirrors the list-view's Bulk Apply / Run-now pair. Without Action Scheduler, Bulk Apply is disabled with the same explanatory tooltip and Run-now is promoted to the primary action — previously the banner's only CTA routed straight into the no-AS error path.
* UX (Bindings form): the ACF field picker no longer collapses to an empty list after you select a field and refocus the input. The display string the picker writes (`name (field_key)`) is now stripped before the haystack filter, so "browse without retyping" works as documented.
* UX (Bindings form): defensive — the ACF field-key row is hidden unless `kind=acf_field` exactly (previously hid only when `kind=post_meta`, leaving an empty-kind edge case where the row could render without `hidden`).
* UX (Bindings list): Run-now capability failure now redirects back to the binding's edit form, consistent with the rest of the binding-edit error paths, instead of bouncing to the silent list view.
* UX (Settings): the **Max log entries** ring-buffer size (clamped 10–5000, default 200) is now exposed as a form field on Settings → Spintax. The option key was already wired up internally; this release adds the missing control.
* Internal: tightened `test_run_now_handler_rejects_non_admin` — replaces a conditional soft assertion with two unconditional invariants (walk-lock never acquired, no "Wrote N" success flash).
* Internal: 520 PHPUnit tests, 950 assertions (was 514 / 938 in 2.1.0).

## 2.1.0
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

## 2.0.3
* Fix: ACF target validation now runs on every apply, not just at form save. `BindingApplier::plan()` rejects bindings whose stored `target.field_key` no longer resolves to a field with the expected name (deleted, renamed, or re-assigned in ACF). Two new return codes: `skip_acf_not_loaded` (ACF deactivated since the binding was saved) and `skip_invalid_acf_field` (key + name disagreement). Closes a path where CLI-imported or imported-while-ACF-inactive bindings could write through `update_field()` to the wrong field.
* Fix: `BindingApplier::read_target()` and `::write_target()` no longer fall back to plain `update_post_meta()` / `get_post_meta()` for `kind = acf_field` when ACF isn't loaded. The applier short-circuits at the runtime guard above, so the low-level methods are the sole writer for verified targets. Pre-2.0.3 the silent fallback could write the rendered value to a post-meta row ACF would never see again.
* Fix: Bulk Apply now tracks failures cumulatively across chunks via a persistent `_spintax_binding_walk_failed_v_<id>` flag. The final chunk gates `stamp_last_applied_version()` on the cumulative flag. 2.0.1 only checked the current chunk, so a multi-chunk walk that failed in chunk 1 and succeeded in the final chunk would still clear the Stale badge.
* Fix: Concurrent Bulk Apply walks on the same binding are now refused with `WP_Error 'walk_in_progress'`. Both `enqueue()` and `run_synchronously()` acquire a per-binding lock (option `_spintax_binding_walk_lock_<id>`) at walk start; stale locks older than one hour are auto-overwritten so a crashed walk doesn't permanently jam the binding.
* Internal: 11 new PHPUnit cases — runtime ACF guard, multi-chunk failure tracking, walk-lock acquisition / release, stale-lock recovery. 441 tests total (was 430).
* Tooling: `npm run lint:php` and `lint:php:fix` moved to `scripts/lint-php.sh` / `scripts/lint-php-fix.sh`. The inline command tripped over bash-c quoting on Windows. `.gitattributes` enforces LF endings on shipped text files.
* Internal: CLI `wp spintax bindings import --overwrite` help text updated to reflect the 2.0.1 `(post_type, target.key)` uniqueness contract.

## 2.0.2
* Docs: new FAQ entries — Action Scheduler dependency, full `wp spintax bindings` WP-CLI surface, variable scopes (global / per-binding / post context / ACF siblings), trigger options (save_post + per-binding cron), manual edit detection, template-edit propagation, reserved-key tiers.
* Docs: Installation section now flags Action Scheduler as a recommended optional dependency with the specific features it enables.
* UX: Spintax → Bindings shows an info notice at the top of the page when Action Scheduler isn't loaded, explaining the two features that degrade (admin Bulk Apply, async cron walks) and linking to the install screen. Notice disappears when AS is loaded by any source (direct install, WooCommerce / Jetpack bundle, mu-plugin, etc.).
* Internal: no functional changes to the bindings engine or core spintax engine — patch is documentation + a single admin-page notice.

## 2.0.1
* Fix: ACF and post-meta bindings on the same `(post_type, field name)` no longer coexist — they wrote to the same database row and silently raced. Tier 4 uniqueness now ignores `target.kind`. Existing pre-2.0.1 conflicts remain in the data store but the next save of either binding will reject.
* Fix: ACF bindings now require a non-empty `target.field_key` and validate it against the live ACF field when ACF is loaded. Previously a missing or mistyped field key could route `update_field()` writes to a different field.
* Fix: Test panel and Bulk Apply now report `skip_out_of_scope_type` / `skip_out_of_scope_status` for posts that wouldn't match the binding's scope in live triggers. Two new applier return codes — total now 11 instead of 9.
* Fix: Bulk Apply only clears the Stale badge when the walk had zero failures. Partial-failure walks keep the binding flagged so editors notice the divergence and retry.
* Fix: Binding form validation errors no longer throw the editor back to the list view — the form re-renders with submitted values via a short-lived transient flash, with the specific error inline.
* Internal: 21 new PHPUnit cases covering each fix path; bindings unit suite is now exhaustive on scope-filter, cross-kind dedup, ACF field_key validation, and Bulk Apply stamp gating.

## 2.0.0
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

## 1.5.0
* Add: plural agreement primitive `{plural <count>: form1|form2|form3}` — pick the correct grammatical form by count. RU/UK/BE = 3 forms (`one|few|many`); EN/ES/PT/DE etc. = 2 forms (`one|many`). Count is a `%var%` reference or literal integer (resolved after variable expansion, so helper-var patterns via `#set` work). Locale comes from per-template post meta `_spintax_locale` or the WordPress site locale. Lenient at runtime: malformed constructs render verbatim with fullwidth braces instead of crashing the page. First spintax engine to treat plural as a first-class primitive.
* Add: validator surface for plural blocks — structural check (form slot rejects nested `{}`, `[]`) always on; arity check (RU expects 3, EN expects 2) when locale is known.
* Internal: 74 PHPUnit cases mirroring the canonical TS implementation (`spintax-plurals.test.ts` in casino-platform). Engine classes `Plurals`, `PluralArityError`, `PluralFormError` ship alongside `Conditionals` from 1.4.0.

## 1.4.0
* Add: conditional syntax `{?VAR?then|else}` — render a branch based on whether a variable is set/non-empty (also `{?!VAR?then}` for inverted, optional else). Resolves both before and after `%var%` expansion, so conditionals inside variable values work too.
* Add: single-token abbreviation whitelist in post-processing — known shorthands like `соц.`, `эл.`, `Mr.`, `Inc.` no longer trigger sentence-end capitalisation of the next word. Covers Russian editorial/address/unit shorthands plus English titles and business suffixes.
* Fix: `#set` directive with an empty value (`#set %x% =`) no longer silently swallows the next directive on the following line.
* Fix: HTML start tags inside permutation alternatives (e.g. `[<li>item</li>|<li>...]`) are no longer mis-parsed as a `<config>` block.
* Improve: cache description in template meta box and global settings now explains that visitors see the same generated variant per runtime context until expiry or regeneration.
* Internal: regression tests for IDN domains flanked by Cyrillic letters and for randomisation behaviour across renders.

## 1.1.0
* Add: per-element permutation separators — assign custom separator to each element via `< sep >` before `|`
* Add: auto-spacing for purely alphabetic word separators (e.g. `<and>`, `<или>`)
* Security: sanitize raw spintax input with custom sanitize_spintax() — strips invalid UTF-8, null bytes, and control characters while preserving angle-bracket syntax

## 1.0.1
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

## 1.0.0
* Initial release
* GTW-compatible spintax engine with nested enumerations and permutations
* Template CPT with code editor and admin preview
* Shortcode and PHP rendering API
* Object cache with versioned keys and cascade invalidation
* Per-template cron regeneration
* Global and local variable scopes
* Settings page with global variables editor
