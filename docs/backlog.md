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

**Status:** spec revised post-review 2026-05-12, Phase-1-ready. Fresh-eyes review pass on 2026-05-12 flagged 3 high-severity + 3 medium-severity issues + 8 gaps; all resolved in `docs/spec-acf-bindings.md`. Awaiting green-light to begin Phase 1.

One sentence: a `wpci`-style binding entity that maps `(post type × target field)` to either a Spintax CPT template or a per-post sibling source, with auto-seed-empty, preserve-manual-edits, bulk apply via Action Scheduler, AJAX field discovery for both ACF and post_meta, and a one-shot migration helper for `nested-spintax-for-acf` data.

**Driver:** parity with predecessor plugin + the "highest priority migration item" note in `CLAUDE.md` future-work list.
**Likely ship:** 2.0.0.
**Estimated effort:** ~1690 LOC + ~820 LOC tests, 5 phases, ~4-5 weeks wall-clock (post-review estimate; original was ~1500/700 / 3-4 weeks).
**Architectural reference:** `W:\Projects\wpci\plugin\src\Admin\MappingsPage.php` — clone the form pattern, AJAX endpoints, test panel, card-style list.

Full design + alternatives considered + risks + phased plan + reviewer prompts: see **[docs/spec-acf-bindings.md](spec-acf-bindings.md)**.

### Trigger to start work

Any one of:
- User green-light to begin Phase 1 (review feedback already applied to spec).
- A real user request from a migrating `nested-spintax-for-acf` user.
- A clear ACF-using project at 301.st / casino-platform that needs this for production templating.
