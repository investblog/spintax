# Backlog

Post-v1 design ideas — both engine primitives and plugin-level features. Each entry records locked decisions and the trigger conditions that promote it from "deferred" to "ready for implementation". Items live here until a real-world signal (or explicit user green-light) justifies the work — not preventively.

Released v1 behaviour lives in `spec-v1.md`.

---

# Engine primitives

---

## Plural Forms

**Status:** SHIPPED 1.5.0. Engine + validator + 74 PHPUnit cases live in `plugin/src/Core/Engine/Plurals.php` + `PluralArityError.php` + `PluralFormError.php`. The backlog entry is kept as the locked design + open V2 questions.

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

- Single locale per render call (not per-construct). Locale comes from template post meta `_spintax_locale` (default: site WP locale, falls back to `en` if unset).
- Locale is normalized to base language: `ru-RU` → `ru`, `uk-UA` → `uk`, `pt-BR` → `pt`, `es-419` → `es`. Done by `normalizeBaseLang()`.
- Per-construct override (`{plural:en %N%: …}`) was in the abstract design but is **NOT implemented**. Deferred to V2 if a real mixed-language template need surfaces.

### Locked plural rules (V1)

| Locale family | Languages | Forms | Rule |
|---|---|---|---|
| East Slavic | `ru`, `uk`, `be` | 3 (`one\|few\|many`) | `mod10===1 && mod100!==11` → one; `mod10∈[2,4] && mod100∉[12,14]` → few; else → many |
| EN-style (default) | `en`, `es`, `pt`, `de`, `it`, `fr`, `nl`, `sv`, `no`, `da`, `fi`, ... | 2 (`one\|many`) | `abs(n)===1` → one; else → many |

`bg` (Bulgarian) intentionally NOT included in V1 — has a distinct rule from East Slavic. Adds in V2 with its own bucket. `pl` / `cs` / `sk` / `sl` similarly out (4-bucket, different boundaries).

### Edge case behaviour (per shipped 1.5.0)

- **Empty / missing / non-numeric count → entire construct → empty string.** Not last slot. (Earlier draft said "last slot + warning"; reality is empty per the unknown-var-renders-empty engine contract.) Authors who want sentence-erase must gate with `{?CasinoHasFoo?…|}`.
- **Strict numeric:** `trim()` → full-string `^-?\d+$` test → `parseInt`. Rejects `"1,200"`, `"12abc"`, `"08h"`, `"%CasinoFoo%"` that didn't substitute. Comma is empty, not "1".
- **Negative numbers:** `abs(n)`, matching CLDR — `n=-22` resolves the same as `n=22`.
- **Zero:** valid, picks `many` form in RU/UK/BE ("0 языков"), picks `many` in EN ("0 languages").
- **Decimals:** count slot accepts integers only. Decimals fail the strict numeric test → empty construct.
- **Numbers > Number.MAX_SAFE_INTEGER:** undefined behaviour, returns empty.

### Lenient mode (production runtime)

- Renderer catches `PluralArityError` / `PluralFormError` per-block, emits the verbatim block text with **fullwidth braces** (`｛plural N: a｜b｝` — codepoints U+FF5B / U+FF5D) instead of throwing.
- Fullwidth braces survive subsequent pipeline stages: the synonym resolver doesn't see them as ASCII `{}`, so `{plural ...}` doesn't degrade into a random-pick enumeration.
- Strict mode is used by `Validator.php` and PHPUnit so structural issues fail loudly at edit time.

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

### V2 expansion

V2 locales (Polish 4-form, Arabic 6-form, Bulgarian, Czech / Slovak / Slovenian, Welsh, Hebrew, Latvian, French 0/1=singular) each require their own per-language trigger — don't pre-build. The arity/rule table extension is mechanical, but the test coverage burden compounds, and we should only add a family when we have a real template that needs it.

### Open questions for future community input

- Should locale be settable globally (site-wide default) in addition to per-template? Helpful for single-language sites; redundant for multilingual.
- Should the validator warn or hard-error on unsupported locale tags?
- Should V2 expansion (Polish/Arabic) wait for explicit demand per language, or batch on first non-RU/EN trigger?
- Number formatting (NBSP separators, locale-aware decimal) — separate primitive or part of plural? Lean separate.

These are deliberately not pre-decided.

---

# Plugin features

---

## ACF / Post-meta Bindings — SHIPPED 2.0.0 / 2.0.1 / 2.0.2 / 2.0.3

**Status:** shipped to WordPress.org 2026-05-12 (2.0.0/2.0.1/2.0.2) and 2026-05-13 (2.0.3). Spec at `docs/spec-acf-bindings.md` is the locked contract.

- **2.0.0** (15:54 UTC, SVN rev 3530118) — feature delivered across 5 phase PRs into main, ~2000 LOC + ~100 PHPUnit cases. Per-post-type bindings to ACF text/textarea/wysiwyg or post_meta keys; template or per_post source modes; save_post p20 + per-binding cron triggers; Bulk Apply via Action Scheduler with WP-CLI fallback; AJAX field discovery; admin Test panel; migration helper for `nested-spintax-for-acf`; full uninstall cleanup.
- **2.0.1** (19:04 UTC, same-day hot-fix PR #6) — 5 reviewer findings + 1 bonus bug, all post-deploy. P1 cross-kind dedup (drop `target.kind` from Tier 4 — ACF + post_meta share `wp_postmeta` row); P1 Tier 5 ACF field_key validation (required + name match via `acf_get_field()`); P2 scope-filter codes (`SKIP_OUT_OF_SCOPE_TYPE` / `_STATUS`) added to `BindingApplier::plan()`; P2 BulkApply stamps `_spintax_binding_last_applied_v_*` only when zero failures; P2 form value preservation via transient flash + redirect-to-form. Bonus: form field renamed `post_type` → `spintax_post_type` to avoid clobbering `$_REQUEST['post_type']` which WP uses to set `$typenow` for menu-hook resolution.
- **2.0.2** (19:30 UTC, docs+UX patch PR #7) — readme.txt FAQ +7 entries (AS dependency, full `wp spintax bindings` CLI reference, 4-layer variable scope, save_post+cron scheduling, manual edit signature semantics, template-edit pre-generation contract + Stale badge, 5-tier reserved-key guard). `BindingsPage::render_action_scheduler_notice()` shows an info notice when AS isn't loaded.
- **2.0.3** (next-day, runtime-guard PR #8) — runtime ACF target validation in `BindingApplier::plan()` (closes a wrong-field-write path that CLI import / ACF-deactivation cycles could trigger; new codes `SKIP_ACF_NOT_LOADED` / `SKIP_INVALID_ACF_FIELD`, 13 total). Cumulative-failure tracking across Bulk Apply chunks via `_spintax_binding_walk_failed_v_<id>` (was per-chunk only in 2.0.1, missed mid-walk failures). Per-binding walk lock `_spintax_binding_walk_lock_<id>` with 1h stale-timeout prevents concurrent walks racing on the cumulative flag. Tooling: `npm run lint:php` moved out of inline package.json into `scripts/lint-php.sh`; `.gitattributes` enforces LF endings.

Tests at 441 (was 309 pre-2.0.0). Plugin Check `--include-experimental` clean for plugin-source files. The "we shipped without Plugin Check / ACF smoke" lesson from 2.0.0 → 2.0.1 is encoded as the four release gates in `docs/release-checklist.md`.

**V2 deferrals (still future work, no trigger yet):**
- ACF repeater / flexible_content row-level rendering.
- REST API surface (V1 is admin + WP-CLI only).
- Block-editor inline editor for `per_post`-mode source (V1 is metabox-only).
- Per-binding locale picker (inherits site locale today).
- ACF Pro `acf_register_field_setting` checkbox in the ACF field UI.
- Visual diff in Test panel (current target vs rendered).

Trigger for any V2 item: a real user request, a project at 301.st / casino-platform that needs it, or the user's explicit green-light. Do not preemptively design.

### Polish: auto-dismiss migration banner after a successful run

**Status:** deferred — minor UX wart, no functional impact. User-flagged 2026-05-15.

**Problem.** `MigrationPage::maybe_render_banner()` shows the "legacy data detected → open migration" admin notice whenever `Migration::has_predecessor_data()` is true and the `spintax_migration_banner_dismissed` option is unset. Predecessor `nested-spintax-for-acf` data is **intentionally never deleted** (non-destructive migration is a locked contract). So after the editor runs the migration successfully, `has_predecessor_data()` still returns true and the banner keeps nagging on every admin page until they manually click "Dismiss" — asking them to migrate data they just migrated.

**Fix options (pick at implementation time):**
1. In `handle_actions()` after `Migration::execute()` succeeds, also `update_option( self::DISMISSED_OPTION, 1, false )` so the banner self-suppresses post-run. Simplest; one line. Downside: a later genuinely-new predecessor binding wouldn't re-surface the banner (acceptable — the Tools page is still discoverable, and re-running migration is explicitly safe/idempotent).
2. Make the banner gate on "predecessor data exists AND at least one predecessor row has no corresponding binding yet" instead of bare `has_predecessor_data()`. Self-clears once everything is migrated, re-appears if new legacy data shows up. More correct, more code (needs a "has unmigrated rows" check reusing `Migration::build_plan()`'s already-migrated detection).

Lean option 1 unless a user actually hits the "added new legacy data after first migration" case.

**Trigger:** bundle into the next bindings-touching patch, or promote if a user complains about the persistent banner.

### Latent: locale absent from render cache key

**Status:** deferred — latent, surfaced during WooCommerce 2.2.0 context-variables work. Not a 2.2.0 regression.

**Problem.** `RenderContext::get_context_hash()` derives the render cache key from the runtime-variable map only. Template `_spintax_locale` meta / site locale drive `{plural …}` output but are **not** part of the cache key. Two renders that differ only by locale — same template, same runtime vars — collide on one cache entry, so whichever renders first wins until the entry rotates. Pre-existing since plurals (1.5.0); harmless on single-locale sites, which is why it stayed hidden.

**Why WooCommerce raises the stakes.** A multilingual WooCommerce store (WPML / Polylang) renders the same product template under multiple locales through identical `%product_*%` runtime vars. With locale out of the key, a product's plural-bearing copy could serve the wrong grammatical forms across languages. 2.2.0 explicitly scopes out multilingual fan-out (non-goal), so this is deferred, not fixed — but recorded so it is not rediscovered as a "Woo bug".

**Fix (at implementation time).** Fold the effective locale into the cache key — e.g. include it in `get_context_hash()`'s hashed payload, or pass it into `CacheManager::build_key()` alongside `template_id` / `version` / `context_hash`. Keep it cheap; locale is a single short string. Add a regression test: same template + same runtime vars + two locales → two cache entries.

**Trigger:** first multilingual/plural WooCommerce (or any multilingual plurals) signal, or bundle into the Phase 2 bindings refactor if the cache-key surface is being touched anyway.
