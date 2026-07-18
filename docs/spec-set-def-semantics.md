# `#set` / `#def` — variable expansion semantics (spec)

Status: **DRAFT, not coded.** Supersedes the `#set` collapse-once behaviour introduced in 2.2.0
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

**2.1 Deferred constructs.** Today Stage 4b skips values containing `{?` or `{plural ` because those
may reference variables defined on other lines and must stay deferred to Stages 6a–6d. `#def` keeps
that carve-out: a `#def` value carrying a conditional or a plural is **not** rolled once — it is
substituted and resolved in the body, i.e. it behaves as a macro. This is a real hole in `#def`'s
promise and must be documented, plus diagnosed (§4).

**2.2 Brackets arriving through another variable.** `#def` rolls the brackets *literally present* in
its own value. `#def %a% = %b%` where `%b%` is a `#set` holding `{x|y}` does not roll once — the
enumeration reaches the body through substitution and rerolls per reference. Resolve `#def` values in
dependency order so a `#def` referencing another `#def` works; a `#def` referencing a `#set` macro
cannot be made to roll and is diagnosed.

**2.3 Name collisions.** A name defined by both `#set` and `#def`, or twice by either, is a
validation error — not last-wins. Silent precedence between two directives with opposite semantics is
exactly the class of bug this spec exists to remove.

**2.4 Recursion.** `#def` values resolve with the same recursion + cycle guards `expand_variables`
already applies, and the existing `variable.self-reference` / `variable.circular-reference`
diagnostics extend to `#def`.

---

## 3. Engine mechanics

### Plugin (`plugin/src/Core/`)

1. **Delete Stage 4b** (`Render/Renderer.php:246-262`). `#set` values go into the context raw.
2. **Add `#def` extraction** in `Engine/Parser.php` alongside `extract_set_directives()`. Mirror the
   existing regex exactly — `'/^[ \t]*#def[ \t]+%(\w+)%[ \t]*=[ \t]*(.*?)[ \t]*$/mu'`, name
   lowercased. **Do not use `\s`**: it consumes newlines and swallows the next directive as the value
   of an empty one. `Parser.php:102-107` carries a four-line comment about this and
   `test_extract_set_directives_empty_value_does_not_swallow_next` locks it.
3. **Add a roll stage** where 4b was: for each `#def` value, skip if it contains `{?` or `{plural `
   (§2.1), otherwise run `resolve_enumerations()` then `resolve_permutations()`. Resolve in
   dependency order with a depth cap (§2.2, §2.4).
4. Stage 5 merges `#def` values into the local layer beside `#set`, same precedence.
5. Nothing else in the pipeline moves. Stage order (6a cond → 6b expand → 6c cond → 6d plural →
   7 enum → 8 perm → 9 include/shortcode → 10 post-process → 11 sanitize) is unchanged.

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
| `def.duplicate-name` | the same name defined twice, or by both `#set` and `#def` (§2.3) | error |
| `def.not-rolled` | a `#def` value contains `{?` / `{plural ` (§2.1) or resolves through a `#set` macro (§2.2) — the roll-once promise does not hold | warning |
| `plural.count-macro` | a `{plural %v%: …}` count slot references a `#set` (macro) variable whose value contains `{` or `[` | error |

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

`docs/spec-npm-engine.md:157-163` explains why — an enumeration-valued `#set` cannot be an
exact-output cross-engine gate, because RNG selection is not part of the parity contract — and
prescribes the workaround: **RNG-free values**. That workaround was never turned into a fixture.
Do it here.

| Fixture | Template | Pins |
|---|---|---|
| `set/macro-multi-reference` | `#set %x% = {a\|a\|a}` + `%x%-%x%` → `a-a` | `#set` substitutes at every reference (all-identical alternatives make it deterministic) |
| `set/macro-permutation` | `#set %x% = [<sep=-> a\|a]` + `%x%` | `#set` does not roll permutations either |
| `def/roll-once-enumeration` | `#def %x% = {a\|a\|a}` + `%x%-%x%` | `#def` holds one value |
| `def/roll-once-permutation` | `#def %x% = [<sep=-> a\|a]` + `%x%-%x%` | roll-once covers permutations, the asymmetry is gone |
| `validate/def-malformed` | `#def %x% no equals` | `def.malformed` |
| `validate/def-duplicate-name` | `#set %x% = a` + `#def %x% = b` | `def.duplicate-name` |
| `validate/plural-count-macro` | `#set %n% = {1\|4\|9}` + `{plural %n%: a\|b\|c}` | `plural.count-macro` |

The genuinely random case — that two references to a `#set` pool *can* differ — is a within-engine
structural test, not a corpus fixture. Keep it as unit tests in each engine, the same split the BCS
change used for strict-vs-lenient.

**Use all-identical alternatives, not literals.** "RNG-free value" is satisfied by `#def %n% = 5`,
but a literal proves nothing about `#def`: there is no bracket, so no resolver runs and the fixture
would pass against an engine that implements `#def` as a plain alias of `#set`. `{a|a|a}` and
`[<sep=-> a|a]` are equally deterministic *and* actually drive the roll path. Literals belong in the
fixtures only as the negative control.

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

1. **Plugin version — 3.0.0 or 2.6.0?** 3.0.0 is honest about a semantics change; 2.6.0 matches the
   precedent set two weeks ago by the BCS release, which was also breaking. Recommend 3.0.0.
2. **Is `def.not-rolled` a warning or an error?** §2.1 and §2.2 are real holes in the promise. A
   warning documents them; an error forbids the shapes outright and keeps `#def` honest. Recommend
   warning for §2.1 (conditionals are a legitimate deferred case) and error for §2.2.
3. **Does `#def` belong in the globals textarea and per-binding overrides?** No reason to forbid it,
   but the help text and the `Validators` parsing path both assume "a raw `#set` block".
4. **How many WP.org installs adopted collapse-once in the fourteen-day window?** Unknown, and it
   decides whether the Upgrade Notice needs migration instructions or just a changelog line.
5. **Does the OpenCart port ship in lockstep or trail?** Its release route is independent and its
   installed base is the one most likely to hold enumeration-valued `#set` counters.
