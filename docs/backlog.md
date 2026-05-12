# Backlog

Post-v1 design ideas — both engine primitives and plugin-level features. Each entry records locked decisions and the trigger conditions that promote it from "deferred" to "ready for implementation". Items live here until a real-world signal (or explicit user green-light) justifies the work — not preventively.

Product/ecosystem strategy (website, kits, API, bot, vertical packs) lives in `product-roadmap-2026.md` and references this file for individual feature designs. Released v1 behaviour lives in `spec-v1.md`.

---

# Engine primitives

---

## Plural Forms

**Status:** active — trigger fired 2026-05-09; **TS implementation shipped in casino-platform**, PHP port in progress.

### Reference implementation (canonical)

The TS implementation is the canonical reference for the primitive. Both the spec and code live in casino-platform:

- **Design spec:** `W:\projects\casino-platform\docs\spintax-plurals-engine-plan.md` (v5, post-fifth-pass review).
- **Engine code:** `W:\projects\casino-platform\packages\core\utils\spintax-plurals.ts`.
- **Tests:** `W:\projects\casino-platform\packages\core\utils\spintax-plurals.test.ts` (~70 cases).
- **Pipeline integration:** `packages/core/utils/resolve-spintax.ts` — `applyPlurals()` slots between `applyConditionals` (pass 2) and `resolveEnumerations`.

PHP port mirrors this exactly. Where the canonical TS behaviour disagrees with anything in this backlog, **the TS implementation wins** — this doc was the abstract design before code; the code is the contract now.

### Trigger confirmation (2026-05-09)

Promoted from deferred to active after a real-data cost analysis of casino-platform templates surfaced three arguments that the original abstract analysis didn't capture:

1. **Engineer-in-the-loop dependency.** The existing-primitives workaround forces every new counter (new casino data column, literal in copy, new dynamic variable) through an assembler change → worker deploy → template work loop. Editor cannot autonomously extend templates — every plural-bearing counter requires engineering coordination first. This is organizational coupling, not cosmetic friction.

2. **Literal numbers are unaddressable.** Phrases like "за 30 дней", "5 крипт", "2 категории" need plural agreement on values that don't exist in the casino data layer at all. Has-flags can't be pre-computed for them. The workaround doesn't cost more here — it doesn't work.

3. **Multiplicative explosion on real entities.** Casino entity count: Languages, Cryptos, Providers, Bonuses, FreeSpins, Days, Hours — six minimum. Each needs 3 has-flags. Some need flags per counter source (FreeSpinsCount, WagerDays, PayoutHours each separately). Plus per-noun macros (LangsNounRu, CryptosNounRu, ProvidersNounRu, BonusesNounRu, FreeSpinsNounRu, DaysNounRu, HoursNounRu) duplicated across every preset. Cost goes from "more expensive" to "unmaintainable" once enumerated on real data.

### Problem

Editors writing RU/UK content with countable variables have no correct way to express `<число> + <склоняемое существительное>`. Workarounds are all broken:

1. `%N% {язык|языка|языков}` — random pick per render, not number-gated. Silent footgun.
2. Closed pairing `{50|100|150} {фриспинов|FS}` — breaks the moment N becomes a variable.
3. Nested conditionals `{?CountIs1?язык|{?CountUnder5?языка|языков}}` — the "1, 21, 31 except 11" rule needs modulo arithmetic, which `{?…?}` doesn't support, so even this workaround can't be made correct.

The absence of `<число> + <существительное>` constructs in current templates is a symptom of the missing tool, not of missing demand.

### Industry precedent

ICU MessageFormat (`{count, plural, one {…} few {…} other {…}}`), gettext `ngettext`, FormatJS — all treat plural as a first-class primitive precisely because Slavic, Arabic, and Polish content cannot be written correctly without it.

### Locked syntax (per shipped TS implementation)

```
{plural <count>: form1|form2|form3}
```

- **Marker:** literal prefix `{plural ` (with trailing space) is the unambiguous discriminator from synonym `{a|b|c}`.
- **Count slot:** integer literal OR `%var%` reference. The plural pass runs **after** variable expansion, so by the time it executes the variable has already been substituted to its string value.
- **Delimiter:** `:` separates count slot from forms slot. Whitespace around it is permissive.
- **Forms:** pipe-separated. Arity must match the locale's plural family (validated; see arity table below).
- **Forms must NOT contain nested spintax brackets** (`{` `}` `[` `]`). Synonyms / conditionals / permutations inside a form raise `PluralFormError`. Authors who need conditional content in a form must extract it via `#set` and reference the resulting variable in plain form text. HTML tags (`<em>`, `<a>`) and `%`-delimited unresolved variables are allowed — only spintax-structural brackets are forbidden.

#### Examples

```
поддерживает %CasinoLanguagesCount% {plural %CasinoLanguagesCount%: язык|языка|языков}
получите %FreeSpinsCount% {plural %FreeSpinsCount%: фриспин|фриспина|фриспинов}
выводы за {plural %PayoutHours%: час|часа|часов}
выполнить отыгрыш за {plural 30: день|дня|дней}
```

EN (2-form):

```
supports %CasinoLanguagesCount% {plural %CasinoLanguagesCount%: language|languages}
withdraw within {plural 24: hour|hours}
```

#### Why colon-delimited (vs the earlier `{plural %N%|forms}` sketch)

The earlier sketch had two structural weaknesses that the colon form closes:

- **Helper-var hazard.** `#set %LangPlural% = {plural-construct}` is a common preset pattern. Under `{plural %N%|forms}`, after `expandVariables` the construct degraded into `{12|forms}` — indistinguishable from a 4-way synonym, silently mis-parsed by the enumeration resolver. The `{plural ... : ...}` shape preserves its prefix through variable expansion, so the pass can safely run after substitution.
- **Literal integers were unauthorable.** `{30|день|дня|дней}` collides with synonym `{a|b|c}` shape. The colon form makes `{plural 30: день|дня|дней}` distinct from any synonym.

### Locked locale model

- Single locale per render call (not per-construct). Locale comes from render context: in casino-platform from `allVars.lang`; in the PHP plugin from a new template post meta `_spintax_locale` (default to site WP locale or `en` if unset).
- Locale is normalized to base language: `ru-RU` → `ru`, `uk-UA` → `uk`, `pt-BR` → `pt`, `es-419` → `es`. Done by `normalizeBaseLang()`.
- Per-construct override (`{plural:en %N%: …}`) was in the abstract design but is **NOT implemented** in TS. Deferred to V2 if a real mixed-language template need surfaces.

### Locked plural rules (V1)

| Locale family | Languages | Forms | Rule |
|---|---|---|---|
| East Slavic | `ru`, `uk`, `be` | 3 (`one\|few\|many`) | `mod10===1 && mod100!==11` → one; `mod10∈[2,4] && mod100∉[12,14]` → few; else → many |
| EN-style (default) | `en`, `es`, `pt`, `de`, `it`, `fr`, `nl`, `sv`, `no`, `da`, `fi`, ... | 2 (`one\|many`) | `abs(n)===1` → one; else → many |

`bg` (Bulgarian) intentionally NOT included in V1 — has a distinct rule from East Slavic. Adds in V2 with its own bucket. `pl` / `cs` / `sk` / `sl` similarly out (4-bucket, different boundaries).

### Edge case behaviour (per shipped TS)

- **Empty / missing / non-numeric count → entire construct → empty string.** Not last slot. (Earlier draft said "last slot + warning"; reality is empty per the unknown-var-renders-empty engine contract.) Authors who want sentence-erase must gate with `{?CasinoHasFoo?…|}`.
- **Strict numeric:** `trim()` → full-string `^-?\d+$` test → `parseInt`. Rejects `"1,200"`, `"12abc"`, `"08h"`, `"%CasinoFoo%"` that didn't substitute. Comma is empty, not "1".
- **Negative numbers:** `abs(n)`, matching CLDR — `n=-22` resolves the same as `n=22`.
- **Zero:** valid, picks `many` form in RU/UK/BE ("0 языков"), picks `many` in EN ("0 languages").
- **Decimals:** count slot accepts integers only. Decimals fail the strict numeric test → empty construct.
- **Numbers > Number.MAX_SAFE_INTEGER:** undefined behaviour, returns empty.

### Lenient mode (production runtime)

- `applyPlurals(text, lang, { lenient: true })` catches `PluralArityError` / `PluralFormError` per-block, emits the verbatim block text with **fullwidth braces** (`｛plural N: a｜b｝` — codepoints U+FF5B / U+FF5D) instead of throwing.
- Fullwidth braces survive subsequent pipeline stages: the synonym resolver doesn't see them as ASCII `{}`, so `{plural ...}` doesn't degrade into a random-pick enumeration.
- Optional `onError` callback receives each caught error for telemetry.
- Strict mode (default) throws on first error — used by validators and tests so structural issues fail loudly.

### Out of scope (not even V2)

- Noun declension by case — dictionary problem, not algorithmic.
- Gender agreement — same.
- Number formatting (`1,200 spins` / `1 200 spinов` with NBSP) — adjacent feature, separate ship.
- Full CLDR coverage of ~200 locales — endless moving target.

### Deferred to V2 (separate trigger required per language)

- Polish 4-form (`one|few|many|other`).
- Czech / Slovak (3-bucket but different boundaries from East Slavic).
- Bulgarian (different from East Slavic despite Cyrillic).
- Arabic 6-form (`zero|one|two|few|many|other`).
- Welsh 6-form, Hebrew 4-form, Latvian 3-form, French (0/1 = singular).
- Per-construct locale override (`{plural:en %N%: …}`).
- Admin UI chip-list helper for inserting plural constructs.

### Implementation context

- **TS engine:** SHIPPED in casino-platform (`packages/core/utils/spintax-plurals.ts`). Wired into `resolveForSite()` pipeline. ~70 tests passing. Validator surface: `scripts/validate-spintax.ts` (file-based, syntactic) + planned `scripts/validate-plurals-db.ts` (DB-backed bulk per phase 2A of the canonical plan).
- **PHP plugin:** PORT IN PROGRESS. Mirror TS algorithm exactly. Pipeline insertion in `Renderer.php` between conditional resolution and enumeration resolution. Extend `Validator.php` to surface arity/form errors at edit time.
- **V2 expansion (Polish, Arabic, etc.):** still requires its own per-language trigger — don't pre-build.

### Open questions for future community input

- Should locale be settable globally (site-wide default) in addition to per-template? Helpful for single-language sites; redundant for multilingual.
- Should the validator warn or hard-error on unsupported locale tags?
- Should V2 expansion (Polish/Arabic) wait for explicit demand per language, or batch on first non-RU/EN trigger?
- Number formatting (NBSP separators, locale-aware decimal) — separate primitive or part of plural? Lean separate.

These are deliberately not pre-decided. Once a community forms around `spintax.net`, they can go to public vote.

### Estimated effort (PHP port — V1)

- Engine code: ~150 LOC parser-side (brace-aware scanner, resolve_plural_block, helpers, two error classes).
- Renderer wiring: ~5 LOC (one pipeline insertion + locale resolution).
- Validator extension: ~30 LOC (new method + integration in existing validator surface).
- Tests: ~70 PHPUnit cases mirroring TS coverage.
- Docs: gtw-syntax-reference update + CLAUDE.md sync + readme.txt changelog.
- Version bump: 1.4.0 → 1.5.0 (3-place sync).

Roughly 2× the weight of the conditionals primitive that shipped in 1.2.0. Bounded but not free — the real cost is keeping rule tables in sync with TS over time, not the initial port.

---

# Plugin features

---

## ACF / Post-meta Bindings

**Status:** deferred — design locked 2026-05-12. Awaiting decision to start work. Primary driver is parity with predecessor `nested-spintax-for-acf` and migration pressure once approached by old-plugin users; secondary is the "highest priority migration item" note in `CLAUDE.md` future-work list.

### Reference points

- **Predecessor (do NOT replicate UX):** `C:\Users\Admin\Local Sites\testcom\app\public\wp-content\plugins\nested-spintax-for-acf`. Demonstrates the desired *outcome* (render spintax → write to ACF/meta field) but with per-post metabox UX that doesn't scale beyond a handful of fields.
- **Architectural reference (UX to mirror):** `W:\Projects\wpci` (images-sync-for-cloudflare). Specifically `plugin/src/Admin/MappingsPage.php` for the Mapping form pattern, AJAX field discovery, dry-run, bulk-via-Action-Scheduler; `Core/SourceResolver.php` for the source-type switch pattern.

### Problem

Editors writing template-driven content want to populate ACF and post-meta fields with spintax-generated text — e.g. a "Hero subtitle" ACF text field across 200 post-type posts should render a variant from a single shared template, with per-post variable substitution. Current options:

1. **Inline `[spintax slug="…"]` shortcode in content** — only works for `the_content`, not for arbitrary ACF/meta fields the theme reads directly.
2. **`spintax_render()` calls in the theme** — requires theme editing for every field; binds template names into PHP; not usable by content managers without dev.
3. **Pre-generation by hand** — author copies rendered output into the field; can't easily re-roll variants in bulk; defeats reusability.

The predecessor plugin attempted on-save sibling-meta generation but exposed it through a per-post metabox where authors hand-picked fields one post at a time. That scales poorly: 200 posts × N fields each = 200 metabox clicks; no concept of "this template populates this field type-wide".

### Locked design — "Spintax Binding" entity

One binding = `(post type) × (target field) × (source template) × (triggers) × (behavior)`. Globally scoped: configure once for a post type, applies to every post of that type (subject to status filter). Multiple bindings can target different fields on the same post type. Storage: option-store via `BindingsRepo` (mirror wpci's `MappingsRepo` shape — bindings are admin configuration, not user content; CPT would be overkill).

**Form sections (mirror wpci `MappingsPage::render_form`):**

| Section | Fields | Notes |
|---|---|---|
| Scope | `post_type` (dropdown of public types), `status` (`any` \| `publish`) | wpci L640-732 pattern |
| Target | `target.kind` (`acf_field` \| `post_meta`), `target.key` (AJAX-suggested) | reserved-key guard refuses `_wp_*`, `_edit_*`, `_oembed_*`, `_thumbnail_id` |
| Source | `source.mode` (`template` \| `per_post`), `source.template_id` OR auto-derived sibling key `_spintax_source_<target.key>` | mutually exclusive per-binding; mixable across bindings |
| Variables | `expose_post_context` (bool), `expose_acf_siblings` (bool), `overrides` (raw `#set` block, per-binding) | global Settings vars always inherited |
| Triggers | `save_post` (bool), `acf_save_post` (bool), `cron.schedule` (off \| hourly \| twicedaily \| daily) | graceful disable of ACF triggers if ACF not active |
| Behavior | `auto_seed_empty` (default ON), `regenerate_on_save` (default OFF), `preserve_manual_edits` (default ON), `clear_on_empty` (default OFF) | see "Auto-seed semantics" below |
| Test | post ID input → dry-run preview (resolved source, would-overwrite flag, current target value, rendered preview) | no side effects, mirrors `ajax_test_mapping` L437-563 |
| Bulk apply | "Apply to all matching posts" → Action Scheduler `spintax_apply_binding` chunks | chunk_size=20, mirror `enqueue_bulk_sync` L238-269 |

### Source modes (the A+C parallel)

- **`template` mode:** binding points to an existing `spintax_template` CPT entry by ID. DRY: same template across many bindings / post types. Best for reusable sniippets ("standard disclaimer", "category boilerplate").
- **`per_post` mode:** spintax source lives in sibling post-meta `_spintax_source_<target.key>` on each individual post. Authored via inline metabox on the post edit screen. Best for one-off content where the template is genuinely per-post.

Both modes coexist freely across bindings on the same site / post type. Selection happens per binding, not globally.

### Variable scope inside source

Resolved in this order (later sources override earlier):
1. Global variables from Settings → Spintax (always available).
2. Per-binding `#set` overrides.
3. Post-context vars (if `expose_post_context`): `%post_id%`, `%post_title%`, `%post_url%`, `%post_slug%`, `%author_name%`, `%author_id%`, `%post_date%`, `%post_modified%`.
4. ACF sibling vars (if `expose_acf_siblings` and `target.kind = acf_field`): every text/textarea/wysiwyg field in the same ACF group exposed as `%acf_<field_name>%`. Repeaters/groups/flexible_content excluded in V1.

Cron-fired regenerations seed the PRNG from `post_id + binding_id` for deterministic same-day variants (avoid cron-storm of new variants on every cron cycle).

### Auto-seed semantics

The user-facing reason this entry exists — make initial population effortless without clobbering manual edits.

- **`auto_seed_empty` (default ON):** on trigger, write to target ONLY if target is currently empty or missing. Never overwrites existing content. This is the "set up once, populates new posts as they're created" mode.
- **`regenerate_on_save` (default OFF):** on every trigger, overwrite target with a fresh render. Use for "rotate variant on every edit" workflows.
- **`preserve_manual_edits` (default ON):** plugin stores a hash of last-rendered value in `_spintax_last_render_sig_<target.key>`. On regenerate, compare current target value to last-rendered hash. If they differ, treat as manual edit and skip regeneration (with admin notice). Authors can opt-in to overwrite via per-post checkbox.
- **`clear_on_empty` (default OFF):** if template renders to empty string, clear the target field. Useful for conditional content where the binding should "uninstall" if its inputs go away.

Bulk Apply path uses the same flags — won't clobber manually-edited fields by default.

### Field discovery (admin AJAX)

- **`ajax_acf_fields`:** for given `post_type`, walk `acf_get_field_groups` → `acf_get_fields` recursively through `sub_fields` + `flexible_content` layouts, filter `type IN (text, textarea, wysiwyg)`, return `[{name, label, group}]`. 5-min transient cache. Direct port of wpci `MappingsPage::collect_image_fields` L397-427 with the image-type filter swapped for text-type.
- **`ajax_meta_keys`:** `SELECT DISTINCT meta_key FROM postmeta JOIN posts WHERE post_type = ? LIMIT 200`, transient cache. Port wpci L294-344 verbatim.
- **`ajax_template_list`:** simple WP_Query over `spintax_template` CPT, returns `[{id, title, slug}]` for the source dropdown.

### Triggers pipeline

- `save_post` (priority 20) → find matching bindings for `get_post_type($post_id)` → for each, check filters (status, manual-edit guard) → resolve + write.
- `acf/save_post` (priority 20, after ACF saves all fields) → same path. Avoids race where ACF siblings haven't been written yet when `save_post` fires.
- WP-Cron per-binding schedule → enqueue Action Scheduler walk over all matching posts in chunks. Reuse existing `CronManager` infrastructure with binding-id as schedule key.

### Reserved-key guard

Refuse as `target.key`: any key starting with `_wp_`, `_edit_`, `_oembed_`, plus exact-match list `_pingme`, `_encloseme`, `_thumbnail_id`. Mirrors wpci `MappingsPage::is_reserved_meta_key` L571-580. Block at save-form validation, not at write time.

### Migration helper (optional but planned)

Detect predecessor `nested-spintax-for-acf` data on activation:
- `ns4acf_selected_spintax_fields` per post → suggest creating bindings.
- `spintax_<field>` sibling meta → import as `per_post` source for the binding.
- `spintax_variables` per post → import into per-binding `overrides`.

One-shot conversion wizard accessible from Tools → Spintax Migration. Skipped if no old-plugin data found.

### Out of scope (V1)

- ACF repeater / flexible_content per-row binding. V1 binds to top-level fields only; repeater rows are V2.
- Gutenberg block bindings — separate primitive, separate ship.
- Per-binding cache TTL (inherit from `spintax_template` if `template` mode; default TTL otherwise).
- Multilingual binding fan-out (WPML/Polylang) — bind one per locale manually.
- Block-editor inline editing of `per_post` source (metabox-only in V1).

### Deferred to V2 (separate triggers required)

- Repeater / flexible_content with row-level variable scope.
- Locale picker per-binding (currently inherits site locale or template `_spintax_locale`).
- Visual diff in Test panel (current target vs rendered).
- Binding-level cache versioning + invalidation cascade.
- Inline source editor in Gutenberg.
- Field-level conditional binding (apply only if other field matches predicate).

### Out of scope permanently

- Auto-detect "which fields should be spintax" — explicit binding is always required.
- Generate templates from existing field content — separate authoring concern, not binding concern.
- Real-time preview as user types in `per_post` source — defer to existing template-edit preview workflow.

### Open questions

- Inline `per_post` source editor: metabox, ACF-injected element, or Gutenberg block in V2? Lean metabox first (simplest, no ACF Pro dependency).
- ACF sibling vars: stable naming convention. `%acf_<name>%` distinct from regular `%<name>%` to prevent global-var collisions; document explicitly.
- Storage shape: single autoloaded option with all bindings (wpci pattern, fine to <100 bindings) or per-binding options? Lean single-autoloaded for V1.
- Cron granularity: per-binding (proposed) vs reusing per-template cron schedules. Lean per-binding — a single template across multiple bindings may want different cadences per binding.
- ACF Free vs Pro: V1 supports both. Pro's `acf_register_field_setting` is NOT used (would require Pro dep); all binding config stays in the admin Bindings page.

### Implementation context

- **PHP classes (planned):** `Spintax\Bindings\BindingsRepo`, `Spintax\Bindings\BindingResolver`, `Spintax\Bindings\BindingApplier`, `Spintax\Bindings\Triggers\SavePostTrigger`, `Spintax\Bindings\Triggers\AcfSavePostTrigger`, `Spintax\Bindings\Triggers\CronTrigger`, `Spintax\Admin\BindingsPage`, `Spintax\Admin\BindingsAjax`.
- **Reuse:** existing `Renderer` (call with binding-supplied variable context), existing `CronManager` (extend `get_schedule()` to handle binding schedule keys), existing `Validators::sanitize_spintax()` for source content.
- **New variable sources:** `Spintax\Core\Variables\PostContextSource`, `Spintax\Core\Variables\AcfSiblingsSource`, `Spintax\Core\Variables\BindingOverridesSource` plug into the renderer's variable pipeline alongside the existing global/local sources.
- **WP-CLI:** `wp spintax bindings list|apply|test --binding=<id> [--post=<id>|--all]`.
- **Action Scheduler:** hard requirement for Bulk Apply (graceful fallback to WP-CLI with admin notice if AS not available; mirrors wpci L248-253).

### Estimated effort

- Data layer (BindingsRepo, Defaults, validators): ~200 LOC.
- Admin UI (BindingsPage + AJAX endpoints, mirror wpci sizing): ~600 LOC.
- Resolver + applier (post-context vars, ACF siblings, preserve-manual-edits hash): ~250 LOC.
- Triggers (save_post, acf_save_post, cron, Action Scheduler bulk): ~120 LOC.
- Tests: ~150 PHPUnit cases (CRUD, resolver scenarios with mocked ACF, applier edge cases including auto-seed / preserve-edits / clear-on-empty).
- WP-CLI: ~60 LOC.
- Migration helper (predecessor plugin import): ~100 LOC, optional.
- Docs: spec addendum + readme.txt FAQ + spintax.net guide (`/docs/acf-bindings/`).

Total: ~1500 LOC plus tests/docs. **Largest single ship on the post-1.x backlog** — significantly heavier than plurals (~370 LOC) or conditionals (~150 LOC). Most cost is the admin UI (the wpci MappingsPage clone). Suggested version bump: 1.x → **2.0.0** (binding model is a substantial new surface, not an additive engine feature).

### Trigger to start work

Any one of:
- A real user request from a migrating `nested-spintax-for-acf` user (existing user base is small but non-zero).
- A clear ACF-using project at 301.st / casino-platform that needs this for production templating.
- Explicit user green-light independent of demand signal (i.e. "we ship 2.0 now").

Until then, this entry stays locked-design / not-yet-built.
