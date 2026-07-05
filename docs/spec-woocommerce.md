# WooCommerce Support — Implementation Spec

Status: DRAFT (grounded in code reconnaissance 2026-07-03)
Companion to: `docs/spec-woocommerce-discussion.md` (product framing, non-goals, roadmap anchors)
Scope of this doc: the *how* — concrete classes, seams, cache-key correctness, and a phased build plan tied to the real code.

This spec supersedes the discussion draft for engineering decisions. Where the two
disagree, this one wins because it is grounded in the actual current code paths.

---

## 0. Reconnaissance summary (what the code actually does today)

Three facts drive every decision below. All were verified against the shipping 2.1.1 tree.

### 0.1 The cache key already discriminates by runtime variables — nothing else

`Renderer::render()` computes the cache identity as:

```php
// Renderer.php ~178
$context_hash = $context->with_runtime( $runtime_vars )->get_context_hash();
$cached = $this->cache->get( $template_id, $context_hash );
```

```php
// RenderContext::get_context_hash()  — the ENTIRE key input
if ( empty( $this->runtime_vars ) ) { return 'default'; }
$sorted = $this->runtime_vars; ksort( $sorted );
return md5( wp_json_encode( $sorted ) );
```

```php
// CacheManager::build_key()
return "{$template_id}_{$version}_{$context_hash}";   // group: spintax_{salt}
```

**Included in the key:** `template_id`, per-template `version` counter, global `salt`, and
the **full `runtime_vars` map** (md5 of ksort'd JSON).
**NOT included:** global settings vars (rotate via salt), `#set` locals (deterministic from
template, covered by version), **locale**, **post/queried-object id**.

Consequence for Woo: **if product context enters the render as `runtime_vars`, it folds into
the key with zero key-composition changes.** If it enters as a global or a `#set` local, it
renders but does **not** vary the key → product A's cached HTML leaks to product B. This is
*the* correctness pivot of Phase 1.

### 0.2 The render path reads no ambient context — Woo detection is net-new

Grep across `src/` for `get_the_ID` / `get_queried_object` / `global $post` / `$GLOBALS['post']`:
**zero hits on the render/shortcode path.** The bindings path always threads an explicit
`$post_id`. `ShortcodeController::handle()` passes **only explicit shortcode attributes** as
runtime vars and knows nothing about a "current product."

Consequence: there is no ambient-context resolver to extend. `WooCommerceProductContextSource`
plus its wiring into the three render entry points is entirely new code — but small and
well-isolated.

### 0.3 Variable "sources" are ad-hoc, and bindings target-kind dispatch is hardcoded

- `PostContextSource::build(int $post_id): array` and
  `AcfSiblingsSource::build(array $binding, int $post_id): array` share **no interface** —
  different signatures, instantiated inline by `BindingApplier`, feeding the **uncached**
  `process_template()` path (not `render()`).
- Target-kind handling is hardcoded `=== 'acf_field'` / `=== 'post_meta'` branches in **≥9
  sites**: `Defaults::target_kinds()`, `BindingsRepo::normalize`, `BindingApplier::read_target`
  / `write_target` / `validate_acf_target_runtime`, `AcfSiblingsSource::build`,
  `BindingsPage` (radio UI, Tier-5 validator, target guard, list badge),
  `BindingsAjax` (discovery endpoints), `BindingsMetaBox` label. No registry.
- `BindingApplier::plan()` is **not pure** — it calls `get_post`, `get_post_meta`, `get_field`,
  `acf_get_field` inline. 13 return codes today.

The OpenCart port (`W:\projects\spintax-opencart\...\Core\Binding\`) already solved this with a
**pure `Planner`** (`plan(PlanInput): string`), a flat 17-field `PlanInput` DTO, a `PlanCode`
class with `category() → write|blocked|skip`, declarative `EntityType` descriptors in a static
`EntityRegistry`, and a config-only `DryRunToken`. That is the back-port target for Phase 2.

---

## 1. Phasing (unchanged from discussion doc, restated with engineering intent)

| Phase | Deliverable | Writes to product records? | Risk |
|-------|-------------|:--------------------------:|------|
| **1** | `WooCommerceProductContextSource` + render-entry injection + cache-key safety | No | Low — additive, read-only |
| **2** | Extract pure `Planner` + `PlanInput` + `PlanCode` + `TargetRegistry` | No (refactor) | Medium — touches locked bindings contract |
| **3** | `woocommerce_product_field` target kind (`short_description`, `description`) | Yes | High — catalog writes |
| **4** | `woocommerce_term_field` (category/term description) | Yes | High — taxonomy cache semantics |
| **5** | Slug/SEO targets (`SlugAdapter` port) | Yes | Deferred — live-URL/SEO history risk |

**Ship Phase 1 alone first.** Phases 2–5 are separately gated. Phase 2 is a pure refactor and
should ship (or at least merge) before any Phase 3 write target, so the write logic lands on the
pure Planner rather than growing a third hardcoded branch in the old `plan()`.

**The dominant risk is sequencing, not Phase 1 itself.** Phase 1 is genuinely small, additive,
read-only, and easy to make safe. The real danger is starting Phase 2/3 too early — Phase 2
rewrites the *locked* bindings contract (13 return codes, scope-filter-first, runtime ACF guard,
Tier-4 dedup), and "behavior is unchanged" is exactly the framing that let 2.0.0 ship two P1 bugs.
Therefore:
- Phase 1 must reach production and sit there before Phase 2 begins. Do not bundle them.
- Phase 2 is treated as a **potentially dangerous release regardless of its "pure refactor"
  label** — full `bindings-contract` review + the 13-outcome table test are mandatory, not
  courtesy. A refactor that "can't change behavior" gets *more* scrutiny, not less.
- No Phase 3 write target lands on the old scattered `plan()`; it waits for the pure Planner.

---

## 2. Phase 1 — Product Context Variables (RECOMMENDED FIRST SLICE)

Goal: make `[spintax slug="…"]` and `spintax_render()` product-aware on WooCommerce pages,
**without writing anything to product records** and **without any cache bleed**.

### 2.1 New class: `Spintax\Core\Variables\WooCommerceProductContextSource`

Mirror `PostContextSource`'s shape (single `build()` → `array<string,string>`), but resolve the
product itself. It must be a no-op when WooCommerce is absent.

```php
namespace Spintax\Core\Variables;

final class WooCommerceProductContextSource {

    /** True only when WC is loaded. Cheap guard, called before build(). */
    public function is_available(): bool {
        return function_exists( 'wc_get_product' );
    }

    /**
     * Resolve the current product and return %product_*% vars.
     * @param int $product_id  Explicit id (0 = auto-detect from the main query).
     * @return array<string,string>  Empty when no product context is resolvable.
     */
    public function build( int $product_id = 0 ): array {
        if ( ! $this->is_available() ) { return array(); }

        if ( $product_id <= 0 ) {
            $product_id = $this->detect_current_product_id(); // see 2.3
        }
        if ( $product_id <= 0 ) { return array(); }

        $product = wc_get_product( $product_id );
        if ( ! $product ) { return array(); }

        return $this->map( $product ); // see 2.2
    }
}
```

### 2.2 Variable set (Phase 1)

Emit only cheap, display-safe, read-only fields. `product_id` is **mandatory in every
non-empty result** — it is the cache-key discriminator (see 2.5).

| Variable | Source (`WC_Product` API) | Notes |
|----------|---------------------------|-------|
| `%product_id%` | `$product->get_id()` | **Always present.** Cache discriminator. |
| `%product_name%` | `get_name()` | |
| `%product_slug%` | `get_slug()` | |
| `%product_sku%` | `get_sku()` | may be `''` |
| `%product_type%` | `$product->get_type()` | simple/variable/… |
| `%product_stock_status%` | `get_stock_status()` | instock/outofstock/onbackorder |
| `%product_categories%` | `wp_get_post_terms( product_cat, fields=names )` | comma-joined plain text; no linked HTML |
| `%product_tags%` | `wp_get_post_terms( product_tag, fields=names )` | comma-joined plain text |
| `%product_short_description%` | `get_short_description()` stripped to text | no raw HTML in Phase 1 |
| `%product_attribute_<normalized_slug>%` | `get_attributes()` loop | one var per normalized attribute slug |

Variable-key rule: the engine only expands `%(\w+)%`, so all generated variable names must use
`[A-Za-z0-9_]` only. Attribute names/slugs are normalized with `sanitize_key()` plus
`[^A-Za-z0-9_] → _`; taxonomy attributes should also strip the leading `pa_` for the ergonomic
alias (`pa_color` → `%product_attribute_color%`). If stripping creates a collision, keep the
fully-qualified key too (`%product_attribute_pa_color%`) and document the collision in debug logs.

Rules (from discussion doc §Phase 1, kept):
- WooCommerce stays optional — **no fatal errors when inactive** (guarded by `is_available()`).
- Product vars added **only** when a product context resolves.
- Explicit shortcode/PHP runtime vars keep precedence and **override** auto-detected Woo vars
  (see 2.4 merge order).
- First release does **not** auto-render raw spintax in product content; authors still embed
  via `[spintax slug="…"]`.

### 2.3 Current-product detection

New, isolated helper. Resolve in this order, first hit wins:

1. Explicit `product_id` passed by caller (shortcode attr / `spintax_render()` arg).
2. `get_queried_object()` when it is a `WP_Post` of `post_type === 'product'` (singular product).
3. `wc_get_product( get_the_ID() )` inside the loop (product cards / archives) — **only if**
   we decide Phase 1 supports loops (see Q2 / 2.7; default recommendation: **singular only**).

```php
private function detect_current_product_id(): int {
    $obj = get_queried_object();
    if ( $obj instanceof \WP_Post && 'product' === $obj->post_type ) {
        return (int) $obj->ID;
    }
    // Loop support intentionally deferred — see §2.7. Return 0 for now.
    return 0;
}
```

### 2.4 Wiring — two front-end entry points (nested inherits for free)

Product vars must enter as **runtime vars**, merged *under* any explicit caller vars so explicit
wins. The merge lives once in `Spintax\Core\Variables\RuntimeContextBuilder::merge()` so the entry
points cannot drift on precedence or `product_id` handling.

**Decided on the performance criterion (Strategy A):** keep the engine (`Renderer`)
WooCommerce-agnostic — symmetric with `PostContextSource` / `AcfSiblingsSource`, which live
outside the engine — and inject at the top-level front-end entry points only. The rejected
alternative (injecting the source into `Renderer`'s constructor and merging inside `render()`)
re-runs product detection at every recursion level of `resolve_nested()` and on cron / admin
renders; Strategy A detects once at the top and lets nested renders inherit.

Touch exactly **two** sites:

1. **`ShortcodeController::handle()`** — gains a nullable `?WooCommerceProductContextSource` ctor
   param (default `?? new …`); before the existing `render()` call:
   `$runtime_vars = RuntimeContextBuilder::merge( $this->product_context, $runtime_vars );`. The
   optional `product_id` attribute is read inside the builder as the explicit override.
2. **`spintax_render()`** (`Core/Render/functions.php`) — a function-static source (mirroring the
   function-static `Renderer`) feeds the same `RuntimeContextBuilder::merge()` before `render()`.

**`Renderer::resolve_nested()` is deliberately untouched.** Nested `[spintax]` and `#include`
inherit the product vars through the runtime layer via `RenderContext::for_child_render()`, so
detection is not repeated down the tree. Supporting a mid-tree product switch via
`[spintax product_id="123"]` *inside* a template is deferred to Phase 1.1 (it would be the only
reason to edit `resolve_nested`).

**Precedence invariant:** `array_merge( $auto, $explicit )` — auto-detected under explicit.

**Performance:** `WooCommerceProductContextSource` memoises the full var map per `product_id` for
the request, so the `wp_get_post_terms()` lookups behind `%product_categories%` / `%product_tags%`
run at most once per product regardless of how many `[spintax]` blocks reference it.

**Note on `wc_price()`:** `format_price()` falls back to the raw price string when `wc_price()` is
unavailable (defensive, and it lets the populated map be unit-tested without WooCommerce loaded).

### 2.5 Cache correctness (THE risk — must-verify)

Because `%product_id%` is always in the injected map and the whole map is md5'd into the key,
two products with coincidentally identical exposed fields still get **distinct** cache entries.
No key-composition code changes. Required checks:

- Every non-empty `build()` result includes `product_id`. Add a unit assert.
- The same template on product A vs product B yields two cache entries (integration test 2.8).
- A non-product page: `build()` returns `[]` → `runtime_vars` unchanged → **byte-identical
  behavior and key** to today. Regression guard.

**Implementation rule — correctness before cache economy.** Do NOT try to be clever about which
product vars enter the runtime map to keep the key stable. The full map (price, stock, categories
included) goes into the key even though volatile fields will bust cache entries more often. That
is the honest, safe default: a stale price in cached copy is a real bug; a rebuilt cache entry is
just cost. Ship the whole map first; only after Phase 1 is proven in production do we consider a
narrower "cache-significant subset" — and only with an explicit test proving no field silently
drops out of the key. Any such optimization is its own change with its own review, never smuggled
into Phase 1.

**Pre-existing latent bug to note (do not fix in Phase 1, but log it):** `locale` is not in the
cache key. If a Woo store is multilingual and the same product renders under two locales through
the same runtime vars, plural output can collide. Out of Phase 1 scope (non-goal: no WPML/Polylang
fan-out), but capture it in `docs/backlog.md` so it isn't rediscovered as a Woo regression.

### 2.6 Pricing is excluded (resolves Q4)

**No price variables ship** — not `%product_price%`, `%product_regular_price%`, or
`%product_sale_price%`. Rationale:
- Price is volatile commerce data, not generated copy. Spintax authors reusable *text*; a live
  number does not belong in a cached template render.
- Because runtime vars are in the cache key, exposing price would **churn the cache on every price
  change** — every repricing invalidates the affected product's cached copy for no editorial gain.
- It also removes the `wc_price()` dependency and its formatting edge cases entirely.

**"But what about conditionals?"** Raw price/regular/sale numbers are *not* usable in
`{?VAR?…}` anyway — that primitive only tests set / non-empty, not numeric thresholds, and a
price string is always truthy. If commerce-*state*-driven copy is wanted (e.g.
`{?product_on_sale?Now on sale!|}`), the correct primitive is **derived boolean flags** — e.g.
`%product_on_sale%` (`'1'` when `is_on_sale()`), `%product_in_stock%` (`'1'` when `is_in_stock()`) —
which are low-churn (flip only on state change, not on every price tweak) and semantically clean.
Those flags are a **candidate follow-up, not shipped in 2.2.0**; raw prices stay out regardless.
(`%product_stock_status%` — the display string, always truthy — remains for display use.)

### 2.7 Loops/cards decision (resolves Q2)

**Phase 1 = singular product pages only.** Loop support turns "current product" into a moving
target within one request; the key must then discriminate by the product resolved *at shortcode
execution time*, not the page. That is fine (the key already hashes per-call runtime vars), but it
widens the test surface and the detection helper. Defer to a Phase 1.1 once singular is proven.
`detect_current_product_id()` returns `0` outside a singular product now; loop support is a
localized change to that one method plus tests.

### 2.8 Acceptance tests (Phase 1)

Unit (`WooCommerceProductContextSource`):
- WC inactive → `build()` returns `[]`. Do not depend on monkey-patching `function_exists()`; use
  an injectable availability/product resolver or cover this as a Woo-inactive integration test.
- Valid product id → map contains `product_id` + expected keys; missing optional fields are `''`.
- `product_id` present in every non-empty result (cache-discriminator assert).
- Attribute variable keys contain only `[A-Za-z0-9_]` and expand through the existing parser.

Integration (render path):
- Product page renders `[spintax slug="product-seo-block"]` using current product vars.
- Same template on two products → **two distinct cache entries**, distinct output.
- Non-product page → existing behavior byte-identical (key unchanged).
- Explicit `[spintax slug="x" product_name="Override"]` → explicit wins over auto.

Docs:
- README/usage example for `[spintax slug="product-seo-block"]` on a product template.

### 2.9 Phase 1 file touch-list

- **New:** `plugin/src/Core/Variables/WooCommerceProductContextSource.php`
- **New:** `plugin/src/Core/Variables/RuntimeContextBuilder.php` (shared merge helper)
- **Edit:** `plugin/src/Core/Shortcode/ShortcodeController.php` (nullable source ctor param + merge)
- **Edit:** `plugin/src/Core/Render/functions.php` (function-static source + merge in `spintax_render()`)
- **Unchanged:** `plugin/src/Core/Render/Renderer.php` — nested renders inherit product context via
  `for_child_render()`; no edit (a mid-tree `[spintax product_id="…"]` switch is Phase 1.1).
- **New tests:** `tests/Core/Variables/WooCommerceProductContextSourceTest.php` (unit) +
  `tests/Core/Render/ProductContextRenderTest.php` (integration) per 2.8
- **Docs:** readme.txt Description bullet + FAQ entry; `docs/backlog.md` locale-in-key note

No bindings code changes. No new option. No admin UI. Shipped surface for **2.2.0**.

---

## 3. Phase 2 — Pure Planner refactor (prereq for any write target)

Goal: collapse the ≥9 hardcoded `=== 'acf_field'` branches and make the write decision a **pure
function**, so Phase 3's Woo write path is one registry entry, not a third scattered branch.
Behavior-preserving: existing ACF/post_meta tests must pass unchanged.

### 3.1 Port the OpenCart shapes (adapted to WP)

**`Spintax\Bindings\Plan\PlanCode`** — port the flat string-constant class + `category()`.
Reuse the **exact string values already shipping** in `BindingApplier` (the 13 codes:
`wrote_seeded`, `wrote_regenerated`, `wrote_empty`, `skip_manual_edit_detected`,
`skip_target_nonempty`, `skip_empty_render`, `skip_no_write_trigger`, `skip_source_not_found`,
`skip_cold_start_manual`, `skip_out_of_scope_type`, `skip_out_of_scope_status`,
`skip_acf_not_loaded`, `skip_invalid_acf_field`) so logs/telemetry/CLI output are unchanged.
Add `category(string): 'write'|'blocked'|'skip'` (write = the three `wrote_*`; blocked =
`skip_source_not_found`, `skip_invalid_acf_field`, `skip_acf_not_loaded`; skip = the rest).

**`Spintax\Bindings\Plan\PlanInput`** — flat DTO carrying every fact `plan()` needs, resolved by
the caller. WP field set (superset of OpenCart's, minus store/language, plus ACF/scope facts):

```
postExists            bool     // get_post() != null
postTypeMatches       bool     // '' binding type OR post_type === expected
statusInScope         bool     // status!='publish' OR post_status==='publish'
targetKindValid       bool     // registry knows the kind
targetRuntimeValid    bool     // e.g. ACF field_key resolves & name matches (kind-specific)
targetRuntimeCode     ?string  // pre-computed SKIP_* when targetRuntimeValid is false
sourceFound           bool
rendered              string
currentTarget         string
storedSignature       ?string
regenerateOnSave      bool
autoSeedEmpty         bool
preserveManualEdits   bool
clearOnEmpty          bool
```

The ACF-not-loaded / invalid-field distinction is resolved *before* the Planner (by the
kind's runtime validator) and passed in via `targetRuntimeValid` + `targetRuntimeCode`, keeping
`plan()` free of `function_exists('acf_get_field')`.

**`Spintax\Bindings\Plan\Planner`** — pure. `plan(PlanInput): string`. Reproduce the exact
existing decision order (scope → target validity → source → render/write paths) so the return
code for every input equals today's `BindingApplier::plan()` output. This is a mechanical
extraction; guard it with a table-driven test that enumerates all 13 outcomes.

### 3.2 `TargetRegistry` + `TargetKind` descriptor

Introduce a descriptor so read/write/validate/UI stop branching on strings. Unlike OpenCart's
purely-declarative `EntityType` (it builds SQL generically), WP targets need behavior ports
(ACF vs post_meta call different WP functions), so the descriptor carries **injected read/write
ports + a runtime validator**, not just table names:

```php
interface TargetKind {
    public function id(): string;                       // 'acf_field' | 'post_meta' | 'woocommerce_product_field'
    public function label(): string;                    // UI + list badge
    public function read( array $binding, int $post_id ): string;
    public function write( array $binding, int $post_id, string $value ): void;
    /** @return ?string  a PlanCode SKIP_* when invalid, null when OK. */
    public function validate_runtime( array $binding ): ?string;
    /** Reserved-key / legality guard at save time (admin). */
    public function validate_save( array $binding ): ?WP_Error;
}
```

`TargetRegistry::get(string $kind): ?TargetKind`, `::all(): array`, `::ids(): array` — the latter
replaces `Defaults::target_kinds()` as the single allow-list consumed by `BindingsRepo::normalize`.

Migrate `AcfFieldTarget` and `PostMetaTarget` out of `BindingApplier`'s inline branches into two
`TargetKind` implementations. `BindingApplier` becomes: resolve facts → build `PlanInput` →
`Planner::plan()` → on write, call `registry->get(kind)->write()`.

### 3.3 Phase 2 acceptance

- All existing ACF + post_meta PHPUnit tests pass unchanged.
- New table-driven Planner test: every one of the 13 codes reproduced from crafted `PlanInput`.
- Test panel (`plan()` dry-run) and live apply provably share one decision path (they both call
  `Planner::plan`).
- **No Woo target added in this phase.**
- **Gate:** run the `bindings-contract` subagent against the diff — this touches the locked
  contract (return codes, scope-filter-first, runtime ACF guard, Tier-4 dedup). Treat "pure
  refactor" as *more* dangerous, not less.

---

## 4. Phase 3 — WooCommerce product-field targets

Goal: pre-generate into selected product fields via a **dedicated** target kind, on the pure
Planner from Phase 2.

> **Detailed mini-spec:** `docs/spec-woocommerce-phase3.md` (spec-first, two-PR delivery,
> validation order, re-entrancy guard, rollback/restore, smoke matrix). The sketch below is
> the original outline; the mini-spec supersedes it for implementation.

### 4.1 New `TargetKind`: `woocommerce_product_field`

Allowed target keys (whitelist, hard-capped):
- `short_description` → `WC_Product::set_short_description()` / `post_excerpt`
- `description` → `WC_Product::set_description()` / `post_content`

Out of scope, permanently for V1: `post_title`, price, SKU, stock, sale dates, inventory —
commerce data, not generated copy. The `validate_save` port rejects any key outside the whitelist.

### 4.2 Write path (resolves the `wp_update_post` vs direct-SQL open question)

Write through the **WooCommerce product CRUD API**, not raw SQL and not bare `wp_update_post`:
```php
$product = wc_get_product( $post_id );
$product->set_short_description( $value );   // or set_description()
$product->save();
```
Rationale: goes through WooCommerce's product data store, hooks, caches, and lookup-table refreshes;
do not cite HPOS here, because HPOS is order storage, not product-description storage. Guard against
save recursion — the `save_post`/product-save trigger must short-circuit re-entrancy (a static
in-progress flag keyed by product id), because `$product->save()` fires `save_post` again.

Save-time guard: `woocommerce_product_field` bindings are valid only for `post_type = product`.
The repo/admin validator must reject any other post type for this target kind.

**Trigger:** reuse the existing broad `save_post` priority-20 path (already the contract), **not**
a WooCommerce-only hook — narrow WC hooks miss CSV imports, REST, and Quick Edit (same reasoning
that rejected `acf/save_post` in 2.0.0).

### 4.3 Behavior & bulk

- Default behavior: **seed-empty + preserve-manual-edits** (unchanged default shape).
- `regenerate_on_save` stays available but the admin UI should treat it as a **stronger action**
  (visual weight / confirm), because it overwrites live catalog copy.
- Bulk Apply keeps the **walk lock** (`_spintax_binding_walk_lock_<id>`) and the **zero-failure
  stale-badge gate** (`_spintax_binding_walk_failed_v_<id>`) exactly as-is.
- Manual-edit detection: signature = `sha1(rendered)` in the existing per-binding signature meta,
  identical mechanism to ACF/post_meta.

### 4.4 Registry & UI touch-list (now one place each, thanks to Phase 2)

- Register `WooCommerceProductFieldTarget` in `TargetRegistry`.
- Add `'woocommerce_product_field'` to the allow-list (auto via `TargetRegistry::ids()`).
- Admin form: third radio + key-select (the two whitelisted keys) — one new UI branch.
- List badge / metabox label: driven by `TargetKind::label()` — no new branch.
- Tier-4 dedup: **reconsider** — WC fields live in `wp_posts` columns (`post_excerpt`/
  `post_content`), **not** `wp_postmeta`, so the "same postmeta row" collision rationale does
  **not** apply. The `(post_type, key)` uniqueness may need a per-kind exemption or a separate
  namespace. Flag for the `bindings-contract` reviewer.

### 4.5 Acceptance (Phase 3)

- Binding targets product short description; another targets long description.
- Manual edits preserved by signature.
- Existing post_meta + ACF bindings unaffected.
- Bulk apply on products logs write/skip/failure counts; walk lock + stale gate honored.
- Save recursion guard verified (no infinite `save_post` loop on `$product->save()`).
- **Gates:** Plugin Check `--include-experimental` clean; live smoke test on a real WC install
  (install WooCommerce in dev WP, exercise the four behavior scenarios); `bindings-contract` +
  `wporg-compliance` subagents on the diff. This is a major surface change → full X.Y.0 gate set.

---

## 5. Phase 4 — Category / term targets (deferred)

`woocommerce_term_field` TargetKind for term description / selected term meta. Deferred until
product targets have real usage. Risks unchanged from discussion doc: term-cache invalidation
differs from posts; term descriptions often shared with theme/SEO output; multilingual term
split. When built: write via `wp_update_term()` / `update_term_meta()`, and the context source
gains a taxonomy branch.

## 6. Phase 5 — Slug / SEO targets (deferred, high risk)

Port `Spintax\Slug\SlugAdapter` (Cyrillic→latin translit + `[^a-z0-9]+ → -` + length cap) from
OpenCart. Rules (unchanged): seed-once default, never regenerate slugs on save by default,
collision-check before write (`wp_unique_post_slug()` / term-slug APIs), document permalink risk.
Only build on explicit user demand.

---

## 7. Non-goals (from discussion doc, binding)

No AI/provider integration; no API-key management; no automatic rewrite of all product content on
activation; no direct price/SKU/stock generation; no raw spintax parsing in normal product
content; no WPML/Polylang fan-out in the first Woo release.

---

## 8. Consolidated risk register

| Risk | Phase | Mitigation |
|------|:-----:|------------|
| **Starting Phase 2/3 too early** (the dominant risk) | seq | Phase 1 to production first; Phase 2 reviewed as a dangerous release despite "pure refactor"; no write target on the old `plan()` |
| Premature cache-context optimization | 1 | Ship the full runtime map; no "significant subset" without its own test + review (see §2.5) |
| Cache bleed A→B | 1 | Inject product vars into **runtime layer only**; `product_id` always present; regression test on non-product pages |
| Locale absent from key | 1 (latent) | Out of scope; log in backlog; revisit before multilingual Woo |
| Refactor drifts return codes | 2 | Table-driven 13-outcome test; reuse exact string values; `bindings-contract` gate |
| Product save recursion | 3 | Re-entrancy flag around `$product->save()` |
| Overwrite of human copy | 3 | seed-empty + preserve-manual-edits default; signature detection |
| Bulk apply false success | 3 | Existing walk lock + zero-failure stale gate, untouched |
| Tier-4 dedup wrong for WC columns | 3 | Per-kind exemption; reviewer flag |
| SEO-plugin meta conflict | 3/4 | Whitelist target keys; document overlap |

---

## 9. Open questions → recommended resolutions

| # | Question | Recommendation |
|---|----------|----------------|
| Q1 | Enable Woo context globally / per-template / always-on? | **Always-on when a product context resolves** — zero config, no key impact, no-op off-product. |
| Q2 | Singular only, or loops/cards? | **Singular only in Phase 1**; loops in 1.1 (one-method change). |
| Q3 | Which product for shortcodes in descriptions? | Explicit `product_id` attr wins; else queried object; else (loop, later) `get_the_ID()`. |
| Q4 | `%product_price%` display or raw? | **Neither — excluded** (volatile commerce data, cache churn). Commerce-state conditionals, if wanted, use derived boolean flags — see §2.6. |
| Q5 | Which SEO plugins supported as meta targets? | None named in V1; whitelist only WC-native fields; revisit on demand. |
| Q6 | Frame first release as "context variables" not "bindings"? | **Yes** — ship Phase 1 as "WooCommerce context variables" to avoid overpromising writes. |

---

## 10. Recommended first commit boundary

Ship **Phase 1 only** as 2.2.0: `WooCommerceProductContextSource` + three-entry-point injection +
cache-safety tests + docs. No write targets, no bindings changes, no admin UI. Phase 2 (pure
Planner refactor) lands next as an internal-only release, and only then does Phase 3 introduce the
first Woo write target.
