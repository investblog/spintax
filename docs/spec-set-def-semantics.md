# `#set` / `#def` — variable expansion semantics (spec)

Status: **IN PROGRESS.** Decisions taken: plugin ships as **3.0.0**, the OpenCart port ships **in
lockstep**, delivery starts with `spintax/core`. Step 1 is underway — the engine half landed on
`spintax-php` main (`062bd9a`, 175 tests green including the 138-fixture corpus, verified on the PHP
8.0 floor); its validator diagnostics are next. Nothing is released until the corpus lands last.

Supersedes the `#set` collapse-once behaviour introduced in 2.2.0
(`13ac84a`, Renderer Stage 4b). Spans four engines plus the shared corpus and the public site, so it
is a cross-engine release in the shape the 2.5.0 BCS change established.

**Read this first if you are joining mid-thread:** the names were swapped once during design. The
final assignment is

- **`#set` = macro.** Expands at every reference; each reference resolves independently.
- **`#def` = roll-once.** Resolved once per render; every reference sees the same value.

`#def` reads as "define", which could suggest a macro. It does not. It defines a *value*, not a
template.

---

## 0. Why this is changing

### The defect

`#set` today has two different semantics chosen by the shape of the value, not by the author:

| Value | Today | Where |
|---|---|---|
| `#set %x% = literal` | constant (nothing to expand) | — |
| `#set %x% = {a\|b}` | **collapses once**, every reference identical | `Renderer.php:246-262`, guard `strpos($v, '{')` |
| `#set %x% = [a\|b\|c]` | **expands per reference**, each shuffles independently | no `{` → Stage 4b skips it |

Verified against the engine (php:8.2, `Parser` + a simulated Stage 4b):

```
#set %pfx% = {crypto casino|bitcoin casino|online casino|web casino}
%pfx% / %pfx% / %pfx%
→ bitcoin casino / bitcoin casino / bitcoin casino        ← collapsed

#set %cur% = [<sep=, > BTC|ETH|USDT]
A: %cur% | B: %cur%
→ A: BTC ETH USDT | B: ETH BTC USDT                       ← per reference
```

The asymmetry is an accident of a one-line guard, and it is documented **nowhere** — every doc that
states the collapse rule says "enumerations", and the docs that list legal `#set` values name
"enumerations, permutations, other variables" in the same breath.

### Why the fix is "revert", not "extend"

Collapse-once is the newcomer, not the incumbent:

- it shipped **2026-07-04** in 2.2.0 and is **fourteen days old** at the time of writing;
- it was announced in one changelog line (`plugin/readme.txt`, `= 2.2.0 =`);
- from the day it shipped it contradicted the project's own public documentation. spintax.net
  carries a named, anchored section — `#reroll`, "The reroll gotcha" / "Ловушка повторного решения",
  en + ru — which teaches the opposite as a *design rule*: *"treat every variable occurrence as an
  independent reroll. The engine is not caching resolved values across a single render."* The
  `/docs/syntax` page repeats "Variables are expanded when referenced, not when defined (lazy
  evaluation)" in **14 locales**, inherited from `docs/gtw-syntax-reference.md:123`;
- macro expansion is what the engine did from 1.5.0 through 2.1.1 — its entire life before this
  month — and what every downstream consumer written against those docs assumes.

So restoring macro expansion is not a second flip of an established contract. It reverts a two-week
regression and re-aligns the engine with its published behaviour. The migration burden lands on
whoever adopted collapse-once inside that window, and is one line per definition.

### Why collapse-once existed, and why that need survives

2.2.0 fixed a real bug: `#set %n% = {1|4|9}` followed by `{plural %n%: …}` rendered empty, because
the plural pass (Stage 6d) runs **before** enumeration resolution (Stage 7), so the count slot held
an unresolved `{1|4|9}`. Collapse-once made the count numeric.

That need does not go away. Under pure macro expansion:

```
#set %n% = {1|4|9}
Принимаем %n% валют: {plural %n%: валюта|валюты|валют}
→ Принимаем 1 валют. Ровно 4. [count slot got: 9]
```

The failure is **not** "the count slot is non-numeric". It is that the *displayed* number and the
*agreed* word must be the same number. A counter is almost always both shown and agreed.

Hence a roll-once mechanism must remain expressible. It becomes `#def`.

---

## 1. Semantics

### `#set` — macro

- The value is stored as text and substituted at **every** `%var%` reference.
- Whatever brackets it contains resolve in the body pipeline, independently per reference.
- Bracket type is irrelevant: `{…}`, `[…]`, nesting, all behave alike.
- A literal value is a constant for free — there is nothing to expand.

### `#def` — roll-once

- The value is resolved **once per render**, before the body pipeline, and the resolved string is
  held for every reference.
- Resolution covers **both** enumerations and permutations, in body order (enumerations, then
  permutations), so `#def %x% = {a|[b|c]}` rolls exactly like the same text would in the body.
- Every reference in that render yields the identical string.
- Across renders the value varies normally — this is *not* a literal.

### Both

- Same line-anchored grammar, same name rules, same scope layering, same precedence over globals.
- Child templates inherit globals and runtime variables but **never** a parent's `#set` / `#def`
  locals — unchanged from today.
- `#set` and `#def` lines are stripped from output — unchanged.

---

## 2. Carve-outs and limits (state them; do not let them be discovered)

**2.1 A `#def` value is rendered as a miniature body, once.** The earlier draft carved out values
containing `{?` / `{plural ` (inherited from Stage 4b) and values whose brackets arrive through
another variable, and diagnosed both as holes in the promise. Both carve-outs dissolve once the roll
stage is placed correctly (§3.1): with the full variable context assembled, a `#def` value can be run
through the same passes the body gets — conditionals, plurals, enumerations, permutations — and the
result held. The mental model is one sentence: **a `#def` value is rendered once, as if it were a
tiny template, and the output is frozen for every reference.** No carve-outs, nothing to diagnose.

`#include` is the exception: it resolves at Stage 9, after everything here, and is **not** permitted
inside a `#def` value (§4, `def.include-in-value`).

**2.2 Name collisions must be caught before the map flattens them.** `Parser::extract_set_directives`
writes `$variables[$name] = $m[2]` (`Parser.php:99-110`), so a duplicate is already lost by the time
anyone can see it — last-wins, silently. Adding `extract_def_directives()` on the same pattern would
inherit that, and produce the worst outcome: the validator reports an error while the renderer
happily proceeds on last-wins. Contract: the **raw directive scan preserves every occurrence with its
line number**; the validator is built from that list; the renderer must not silently continue past a
collision on an already-validated path. This applies to `#set`/`#set` duplicates too, which are
silently last-wins today.

**2.3 Recursion.** `#def` values resolve in dependency order with the recursion + cycle guards
`expand_variables` already applies; the existing `variable.self-reference` /
`variable.circular-reference` diagnostics extend to `#def`.

---

## 3. Engine mechanics

### Plugin (`plugin/src/Core/`)

### 3.1 Where the roll stage goes — and what context it sees

This is the load-bearing detail the first draft got wrong by saying "a roll stage where 4b was".
Stage 4b sits at `Renderer.php:246-262`, i.e. **before** Stage 5 assembles the context
(`:264-269`) and before runtime variables merge. A `#def` placed there would see neither globals nor
runtime, so `#def %x% = %product_name% {a|b}` would freeze a literal `%product_name%`.

Decisions:

- **`#def` sees the full context: globals, runtime, `#set` macros, and earlier `#def` values.** The
  roll stage therefore runs **after** Stage 5, not where 4b was.
- **Precedence is unchanged: runtime > local > global** (`RenderContext.php:15, :72-74`). A runtime
  variable with the same name as a `#def` overrides it, exactly as it overrides a `#set` today. The
  roll then never happens for that name — the runtime value wins, and it is data, not a template.
- **A `#def` referencing a `#set` macro freezes it.** `#def %a% = %b%` with `#set %b% = {x|y}`
  expands `%b%` inside the `#def` value, rolls the enumeration there, and holds the result. This is
  what dissolves the old §2.2 carve-out, and it is the intuitive reading: the author asked for this
  value to be fixed.
- **Order within the roll stage** mirrors the body: expand `%var%` → conditionals → plurals →
  enumerations → permutations. Dependency order across several `#def` lines, with the cycle guard.
- **Dependency discovery must follow `#set` aliases, not just direct references.** A `#def` can
  reach another `#def` through a macro — `#def %b% = %s%` where `#set %s% = %a%` and `%a%` is a
  `#def`. Because a `#set` is expanded at *reference* time, that dependency is invisible in `%b%`'s
  own text. Ordering on direct `%name%` matches alone rolls `%b%` first and freezes an unexpanded
  `%a%` into it; if `%b%` then feeds a plural count, the block silently vanishes. Walk the reference
  graph through `#set` values to a fixed point. Found in the `spintax-php` implementation by review,
  fixed in `25ddca4`, and **every port must carry it** — it is not visible from the semantics alone.

- **The alias map is every macro value the roll can see, not just local `#set`.** Same defect one
  layer out, found on the second review pass: a global or runtime `%s% = %a%` pointing at a local
  `#def %a%` is as real a dependency as a local `#set` doing it, and as invisible. Build the graph
  from globals + `#set` + runtime, **minus the definition names themselves** — a `#def` shadows a
  global of the same name, and hopping through the shadowed value computes the wrong graph.
- **Name comparison is case-insensitive at every gate.** `%var%` references are, so an override
  check that uses raw array keys will let `#def %x%` beat a caller-supplied `X`.

**The convenience API counts as surface.** `spintax-php` exposes `Parser::process()`, a subset
pipeline. Reimplementing its `#set` extractor on top of the combined directive scan made it strip
`#def` lines while returning no value for them, so a template silently lost its definition and
printed `%x%`. Rule for every engine: a directive-aware helper either handles both directives or
leaves the one it does not handle **in the body**, where it is visible. Never strip what you will
not resolve.

1. **Delete Stage 4b** (`Render/Renderer.php:246-262`). `#set` values go into the context raw.
2. **Add `#def` extraction** in `Engine/Parser.php` alongside `extract_set_directives()`. Mirror the
   existing regex exactly — `'/^[ \t]*#def[ \t]+%(\w+)%[ \t]*=[ \t]*(.*?)[ \t]*$/mu'`, name
   lowercased. **Do not use `\s`**: it consumes newlines and swallows the next directive as the value
   of an empty one. `Parser.php:102-107` carries a four-line comment about this and
   `test_extract_set_directives_empty_value_does_not_swallow_next` locks it.
3. **Add the roll stage after Stage 5** (§3.1), not where 4b was. Each `#def` value is rendered once
   against the assembled context and the result replaces the raw value in the local layer.
4. **Align the validator grammar with the parser grammar** — see §3.2. Do this for `#set` in the same
   change, or `#def` will duplicate an existing divergence.
5. Nothing else in the pipeline moves. Stage order (6a cond → 6b expand → 6c cond → 6d plural →
   7 enum → 8 perm → 9 include/shortcode → 10 post-process → 11 sanitize) is unchanged.

### 3.1a A pre-existing hole the roll stage exposes: host constructs inside variable values

Not part of the `#set`/`#def` contract, but it surfaces during this work and every engine carries
it. Host constructs (the plugin's `[spintax …]`; whatever a host registers as protected) are
shielded **once**, before the body is processed. Anything arriving afterwards — via a `#set`, a
global, a runtime variable, or a frozen `#def` — meets the permutation resolver unprotected, which
reads `[spintax slug="x"]` as a single-element permutation, strips the brackets, and delivers inert
text to the nested-render hook.

Verified in `spintax-php`: body, `#def`, `#set` and global sources were checked, and the three
non-body ones all failed identically. So it predates `#def` by as long as both features have
existed; the roll stage merely added a second entrance and made review look.

Fix, applied in `31ecde3`: a **second shield pass immediately after variable expansion** (placed
there, not later, so a construct arriving via a variable skips as few passes as a body one does),
plus shielding definition values across their roll. Both share one placeholder map so a single
restore covers every pass.

**No corpus fixture can cover this** — the protect list is a host seam and is empty in a host-free
run, which is exactly why nothing caught it. Each engine needs its own regression test; the one
written here is a four-case provider (body / `#def` / `#set` / global) whose point is that all four
agree.

Security note for the plugin, worth stating before someone asks: this does **not** widen anything.
A variable can now carry a `[spintax …]` through to the nested-render hook, but data-derived (T2)
values are entity-encoded by `SpintaxShield` before reaching the engine, so a value containing a
live shortcode can only come from a markup-authoring (T1) source already trusted to write one — per
`docs/adr-0001-runtime-var-trust-levels.md`.

### 3.2 The validator and the parser disagree about `#set` today — fix before duplicating it

`Engine/Validator.php:147` matches `'/^#set\s+%(\w+)%\s*=\s*(.+)$/u'`; `Engine/Parser.php:107`
matches `'/^[ \t]*#set[ \t]+%(\w+)%[ \t]*=[ \t]*(.*?)[ \t]*$/mu'`. Two differences, and the second is
a live defect, verified:

```
'#set %x% ='    parser: matches, value=''      validator: NO MATCH → "Malformed #set directive"
'#set %x% = '   parser: matches, value=''      validator: matches, value=' '
```

An empty `#set` value is a supported case — `ParserTest` locks it via
`test_extract_set_directives_empty_value_does_not_swallow_next` — yet the editor reports it as
malformed unless the author happens to leave a trailing space. That is a real bug today, independent
of this spec.

Contract going forward: **the validator grammar mirrors the parser grammar for both directives** —
`[ \t]` rather than `\s`, and `(.*?)` rather than `(.+)` so empty values validate. The same applies
to the globals textarea path (`Admin/SettingsPage.php:363-365`) and to every port. Adding `#def` on
the current pattern would give the project four regexes to keep in sync instead of two; there should
be one shared grammar definition per engine, referenced by both.

### 3.3 `#def` is allowed everywhere `#set` is — decided, not deferred

The globals textarea (`Admin/SettingsPage.php:338-344`) and per-binding overrides
(`Admin/BindingsPage.php:1188-1192`) are real user surfaces for `#set`, parsed by
`Parser::extract_set_directives` through `Validators`. Leaving this open would make §1's claim of
"same scope layering" false in the only two places a non-developer actually types a directive.

`#def` is accepted in both. That means parse, save-validation, help text and the placeholder examples
move together in the same change — four touch points per surface, not one. Costed here so it is not
discovered mid-implementation.

Note the interaction with §3.1: a global `#def` rolls once per render of *each* template that
references it, not once per site. It is a variable, not a cached constant.

### `spintax/core` (`W:\projects\spintax-php`)

Byte-parity target with the plugin engine. The two `Plurals.php` copies currently differ only by the
`ABSPATH` guard and three `phpcs:disable` pairs; hold that standard here.

### `@spintax/core` (`W:\Projects\spintax-js`)

Same semantics. Note `packages/core/README.md` uses the word "collapse" at lines 20 and 155 as a
parity-gate name without ever defining it for the reader — this change is the moment to fix that.

### OpenCart port

Its kernel is a port of the plugin's `Core/Engine` + `Core/Render`; mirror the change.

### spintax.net

The site vendors a **second copy of the engine** (`src/engine/spintax.ts`) *and* depends on
`@spintax/core ^0.1.3`. Both must move, or the playground will demonstrate one semantics while the
prose next to it asserts the other.

---

## 4. Validator diagnostics

New codes. A verdict change is breaking under the npm spec's §0.1, so these ride the same release.

| Code | Fires when | Level |
|---|---|---|
| `def.malformed` | `#def` line without `=`, mirroring `set.malformed` | error |
| `def.duplicate-name` | the same name defined twice, or by both `#set` and `#def` (§2.2) | error |
| `def.include-in-value` | `#include` inside a `#def` value — resolves at Stage 9, cannot be rolled (§2.1) | error |
| `plural.count-macro` | a `{plural %v%: …}` count slot resolves, **transitively**, to a `#set` macro carrying `{` or `[` | error |

`def.not-rolled` from the first draft is gone: §2.1 removed the carve-outs it was meant to report.

**`plural.count-macro` must be dependency-aware, not literal.** Checking only whether the named
variable's own value contains a bracket misses the chain:

```
#set %m% = {1|4|9}
#set %n% = %m%
{plural %n%: a|b|c}
```

`%n%` holds no bracket, yet the count is macro-tainted and the render breaks exactly as if it did.
The diagnostic must propagate taint through `#set` → `#set` references to a fixed point: a name is
tainted if its value contains `{`/`[` **or** references a tainted name. A `#def` is always untainted —
it is frozen before the plural pass — which is what makes it the fix the diagnostic points at.

`plural.count-macro` is the lint that replaces what collapse-once used to fix implicitly. It is the
diagnostic that tells a casino-style template author "this counter needs `#def`".

**Blocker for the plugin half.** The plugin's validator is currently dead code on this path:
`Engine/Validator.php:26` defaults `$locale = ''` and `:245-246` skips locale-dependent checks when
it is empty, while the only content-side caller — `Admin/MetaBoxes.php:361` — passes no locale. A new
diagnostic added today would never be seen in WordPress. Thread the resolved template locale
(`_spintax_locale` → site locale, the ladder at `Render/Renderer.php:196`) into that call **before**
or **with** this change. Already filed in `docs/backlog.md` as "The plugin's plural diagnostics are
locale-blind".

---

## 5. Corpus

**Today no fixture pins multi-reference behaviour at all.** The `#set`-touching fixtures are
`set/global-scope-inside-group` (literal value), `extract/refs-sets-includes`, `validate/set-valid`
(one reference, literal), `validate/set-malformed`, `validate/variable-self-reference`,
`validate/variable-circular-reference`. None of them would have caught the 2.2.0 change in either
direction.

**The all-identical-alternatives idea from the first draft does not work, and the reason is worth
keeping.** `#def %x% = {a|a|a}` + `%x%-%x%` → `a-a` — but so does `#set %x% = {a|a|a}`. The fixture
is deterministic and says nothing: it passes under both semantics, and would pass against an engine
implementing `#def` as a plain alias of `#set`. It is the same trap as the literal `#def %n% = 5`,
one level subtler.

**But the corpus can pin this properly, because it has seeded RNG.**
`packages/conformance/fixtures/render-rng-selection.json` already carries `"rng": {"sequence": [1,1]}`,
`"rng": "first"` and `"rng": "last"` fixtures, with notes explaining draw-by-draw which index each
pass consumes. That is exactly the instrument this needs: the semantic difference between macro and
roll-once **is a difference in how many draws the render consumes**, so a seeded sequence
distinguishes them deterministically and cross-engine.

This does make the fixtures depend on draw *count* and *order*, a stronger contract than output
equality. That is deliberate: draw-order parity is already pinned by the existing `enum/*` and
`perm/*` RNG fixtures, and it is precisely the property that would silently drift if one engine
rolled `#def` in a different place in the pipeline.

| Fixture | Template | RNG | Expect | Pins |
|---|---|---|---|---|
| `set/macro-multi-reference` | `#set %x% = {a\|b}` + `%x%-%x%` | `sequence: [0,1]` | `a-b` | `#set` resolves per reference — two draws |
| `def/roll-once-enumeration` | `#def %x% = {a\|b}` + `%x%-%x%` | `sequence: [0,1]` | `a-a` | `#def` resolves once — one draw, held; the second draw is never consumed |
| `def/roll-once-permutation` | `#def %x% = [<sep=-> a\|b]` + `%x%\|%x%` | `sequence` pinned per the existing `perm/*` convention | both sides identical | roll-once covers permutations — the bracket asymmetry is gone |
| `set/macro-permutation` | `#set %x% = [<sep=-> a\|b]` + `%x%\|%x%` | as above, two shuffles | the two sides may differ | `#set` does not roll permutations either — the pair with the row above is what makes each meaningful |
| `def/sees-runtime-context` | `#def %x% = %name%-{a\|b}` + runtime `name=Acme` | `sequence: [0]` | `Acme-a` twice | §3.1 — `#def` resolves against the full context, not a pre-Stage-5 one |
Validation fixtures (no RNG needed): `validate/def-malformed` (`#def %x% no equals` →
`def.malformed`), `validate/def-duplicate-name` (`#set %x% = a` + `#def %x% = b` →
`def.duplicate-name`), `validate/plural-count-macro` — and a **chained** variant, `#set %m% = {1|4|9}`
+ `#set %n% = %m%` + `{plural %n%: …}`, which is the case a literal check misses (§4).

`validate/set-empty-value` also belongs here, pinning that an empty `#set` value is *valid* — the
divergence in §3.2 exists because nothing asserted it.

**What stays in unit tests.** That two references to a `#set` pool *can* differ under a real RNG is a
statistical claim, not a fixture; keep it per engine. The seeded fixtures above pin the mechanism;
the unit tests pin that the mechanism is reached with a live RNG. Same split the BCS change used for
strict-vs-lenient.

**Reviewer note worth preserving:** the first draft claimed these fixtures "pin multi-reference
semantics" when its chosen values could not distinguish the two engines' behaviour. If the seeded-RNG
approach turns out not to hold cross-engine — draw order is a stronger contract than this project has
previously leaned on — then the honest fallback is the reviewer's: keep only smoke and validation
fixtures in the corpus, pin semantics per engine, and **do not describe the corpus as pinning it**.
Overstating what a gate covers is how 2.2.0 flipped the semantics unnoticed in the first place.

---

## 6. Documentation surface

Derived from a full five-repo inventory. Two categories, and the second is the one that bites.

**Becomes correct again — no edit needed for the `#set` rule itself:**
spintax.net `guides/variables.ts` (the whole `#reroll` section, en + ru, incl. the LLM-prompt snippet
at :212 and the mistakes table at :228); spintax.net `strings.ts` `syntaxVarRules` ×14 locales;
`docs/gtw-syntax-reference.md:123` "lazy evaluation".

**Becomes wrong — must be corrected:**

| Repo | File | Currently says |
|---|---|---|
| spintax-php | `README.md:60` | "enumerations inside collapse once, so every reference sees the same value" — the best-worded statement anywhere, and now inverted |
| spintax-js | `packages/authoring-prompt/src/index.ts:276-282` | teaches `#set` as "chosen ONCE, and every `%v%` is the SAME" — **LLM-facing**, must be rewritten to `#def` for consistency / `#set` for variation |
| spintax-js | `packages/authoring-prompt/test/prompt.test.ts` | asserts the prompt's collapse-once wording — *"Exactly one of the two words, never a mix — that is the collapse-once promise"* |
| spintax-js | `docs/spec-npm-engine.md:157-163, 208` | §3.1 "collapse-once semantics" as a required parity item |
| spintax-js | `CLAUDE.md:46,63,71,153`, `AGENTS.md:37` | "`#set` collapse-once" in the parity list |
| spintax (WP) | `CLAUDE.md:194` | the collapse-once note (version already corrected 2.2.1 → 2.2.0) |
| opencart | `CHANGELOG.md:146-148` | frames the collapse as an engine fix |

**Needs `#def` added:** `docs/gtw-syntax-reference.md` §3 + syntax table (:289), `docs/spec-v1.md`
§5.4 + §5.9, `plugin/readme.txt` syntax section + changelog + upgrade notice, `README.md` table,
`plugin/src/Admin/SettingsPage.php` globals help text, `plugin/src/Admin/BindingsPage.php` overrides
help text, `packages/core/README.md`, spintax-js `README.md`, spintax-php `README.md`, opencart
`README.md`, spintax.net syntax table ×14 locales + `play.ts` cheatsheet +
`static/downloads/Spintax.sublime-syntax` grammar token.

**The authoring prompt's example moves, it does not get deleted.** The prompt currently sells
collapse-once with `#set %product% = {course|training}` and *"Get our course today — the training
starts Monday"*, and its test asserts *"Exactly one of the two words, never a mix — that is the
collapse-once promise"*. That example is the single best demonstration of why roll-once must exist:
two references in one sentence where a mix reads as a defect. Re-point it at `#def`, keep the prose,
and re-point the test with it. The prompt then needs a *second* example teaching `#set` for the
opposite case — a synonym pool that should vary across a page — so a model learns the pair, not a
replacement. Without that, models will simply use `#def` everywhere and lose all variation, which is
the failure mode in the opposite direction.

**`#const` collision.** `docs/gtw-syntax-reference.md:291` and `docs/spec-v1.md:291` already reserve
`#const` for a deferred GTW primitive — "correlated parallel selection", i.e. picking a matched index
across parallel pools. That is a different feature from roll-once. `#def` avoids the collision, but
those two rows should be re-read so nobody later assumes `#def` delivered `#const`.

---

## 7. Delivery

**Order is load-bearing, and was verified against the workflows during the 2.5.0 release:** every
repo checks the others out with `actions/checkout` and no `ref:`, so each job floats on the others'
default branch. Nothing is pinned and no pin-bump commit is needed — but a corpus fixture landing
before the engines turns both `php-parity` legs red.

1. **`spintax-php`** — engine + validator + README.
2. **`spintax`** (this repo) — engine + validator + locale threading (§4) + docs + readme/upgrade
   notice. Run the corpus locally against the *unmerged* corpus branch before pushing.
3. **`spintax-js`** — engine, corpus fixtures, authoring prompt, spec. Say in the PR body that
   `php-parity` is expected red until the two PHP merges land, if CI starts early.
4. **OpenCart port** — mirror, its own release route (`scripts/release.sh`).
5. **spintax.net** — engine bump + the second engine copy + `#def` docs. User-owned; not touched
   without an explicit ask.

**Verify parity by the counter, not the badge.** A corpus runner that discovers no fixtures still
exits 0 and prints `OK`. Measured on the 2.5.0 release run (`29650073630`) and re-run locally on
2026-07-18, the true figure is **138 tests / 151 assertions / 1 skip**, identical on both matrix
legs. Two rules follow: a count *below* the last known figure is a red flag regardless of colour, and
the two legs must agree — if they diverge, one of them checked out a stale default branch, which is
precisely the failure this merge order exists to prevent. CLAUDE.md's pre-push checklist advertised
107 until this spec was written; a stale reference number is worse than none, because it trains the
reader to ignore the mismatch.

**Versioning.** This changes what existing templates mean, wider than the BCS change did — that one
was breaking for three locales and shipped as a minor. Recommend **plugin 3.0.0**, `spintax/core`
0.3.0, `@spintax/core` 0.3.0. Upgrade Notice mandatory, and under WP.org's 300-character cap as
counted by Plugin Check (the 2.4.0 release learned this the hard way at 379 characters).

**User migration.** Anyone who adopted `#set %pool% = {…}` as a plural counter between 2026-07-04 and
this release changes one line: `#set` → `#def`. References are untouched. The Upgrade Notice should
say exactly that, and `plural.count-macro` finds the sites for them.

---

## 8. Open questions

1. ~~**Plugin version — 3.0.0 or 2.6.0?**~~ **Resolved: 3.0.0.**
2. ~~**Is `def.not-rolled` a warning or an error?**~~ **Resolved** — the diagnostic no longer exists.
   Placing the roll stage after Stage 5 (§3.1) removed both carve-outs it was meant to report.
4. **How many WP.org installs adopted collapse-once in the fourteen-day window?** Unknown, and it
   decides whether the Upgrade Notice needs migration instructions or just a changelog line.
5. ~~**Does the OpenCart port ship in lockstep or trail?**~~ **Resolved: lockstep.** Its release
   still goes through its own `scripts/release.sh`, but it does not trail a release behind.

### Findings from step 1 worth carrying forward

- **Nothing pinned collapse-once.** Not one test in `spintax-php` — and per the inventory, not one
  corpus fixture — asserted the behaviour introduced in 0.2.0. Removing it broke nothing, which
  sounds reassuring and is the opposite: the semantics could have flipped in either direction
  unnoticed, and did.
- **The corpus needed no changes to keep passing.** 138/138 green against the new engine before a
  single fixture was written. Read that as confirmation the gate does not cover this, not as
  evidence the change is safe.
- The permutation test asserts only that the two references *agree*, not what they resolve to.
  Shuffle output under a sequenced RNG is an implementation detail; agreement is the contract.
