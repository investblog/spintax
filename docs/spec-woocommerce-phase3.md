# WooCommerce Phase 3 — product-field write targets (mini-spec)

Status: **SHIPPED in 2.4.0.** Three deliberate deviations from the plan below, each for a reason:

1. **No gated dry-run release.** The plan had PR 1 ship to WordPress.org with writes switched off, to
   let the dry-run "soak". A UI option that appears and then does nothing is a worse experience than
   waiting, and with this plugin's install base the soak would have observed nobody. Both stages
   landed on `main` and shipped together as one working feature. That also dissolves the two open
   questions about gated-state admin copy and the flag mechanism: neither was needed.
2. **The split moved to a better seam.** Instead of "validation, then writes", the two commits are
   "change the locked contract" (`validate_save` on `TargetKind` — behaviour-preserving, and where a
   regression would hide) and "add the new thing" (the WooCommerce target — purely additive). That is
   the honest boundary for review.
3. **Product context is exposed to bindings.** §0 called `%product_*%` "unrelated". It is the most
   related thing there is: without it a template generating a product description sees `%post_title%`
   and nothing else — it can vary its wording but cannot say anything true about the product. Added
   as a per-binding `expose_product_context` flag, with a separate
   `WooCommerceProductContextSource::build_for_binding()` that has no publish gate (a binding writes
   the product's own data into the product's own field — nothing crosses a boundary, and drafts are
   exactly what pre-generation is for).

`validate_runtime()` also gained a `$post_id` parameter, which the plan missed: without it the target
cannot ask "is this post actually a product", so a binding pointed at a non-product would have been
*planned as a write*, the write would have silently done nothing, and the signature meta would have
been stamped on a lie.

Verified on real WooCommerce across the full §6 matrix (all 13 rows), including the save loop.
Builds on: `docs/spec-woocommerce.md` §4 (superseded in detail here), the 2.3.0
`TargetRegistry`/`TargetKind` architecture, `docs/adr-0001-runtime-var-trust-levels.md`.
Scope: pre-generate Spintax output **into** WooCommerce product fields. This is the
first bindings **write** target for a non-post_meta/ACF kind — a **catalog-writing,
high-risk** phase, gated accordingly.

---

## 0. Goal & non-goals

**Goal.** A `woocommerce_product_field` binding target kind so an editor can bind a
Spintax template to a product's **description** / **short description** and have it
seeded/regenerated per product via the existing bindings machinery (save_post p20,
cron, Bulk Apply, manual-edit preservation).

**Non-goals (V1).** `post_title`, price, SKU, stock, sale dates, inventory, any
meta beyond the two whitelisted fields; taxonomy/term targets (Phase 4); slugs
(Phase 5); AI/providers; multilingual fan-out. Product **context variables**
(`%product_*%`, read-only, 2.2.x) are unrelated and already shipped.

Because 2.3.0 made the write path a `TargetRegistry` entry, this is **one new
descriptor + admin wiring**, not scattered kind-branches.

---

## 1. Target kind: `woocommerce_product_field`

New `Spintax\Bindings\Target\WooCommerceProductFieldTarget implements TargetKind`.

- `id()` → `'woocommerce_product_field'`.
- **Whitelist of `target.key`** (hard-capped, V1): **`description`**, **`short_description`**.
  No other key is accepted at save time or runtime. (First cut ships both; order of
  work is `description` then `short_description` — same mechanics, so both land together.)
- `read($binding,$post_id)` → `wc_get_product($post_id)` then
  `get_description()` / `get_short_description()` by key → `(string)`.
- `write($binding,$post_id,$value)` → **WC CRUD only** (see §2).
- `validate_runtime($binding)` → §3.
- `validate_save($binding)` → §3 (Phase 3 adds `validate_save` to the interface — see §4).
- `normalize_target($target)` → `kind` fixed, `key` kept **only if in the whitelist
  else `''`** (an empty key is then rejected by the existing key-empty save guard),
  `field_key` forced `''`.

### New PlanCodes (extends the set from 13 → 15)

- `SKIP_WC_NOT_LOADED = 'skip_wc_not_loaded'` — WooCommerce inactive at apply time.
  Mirrors `SKIP_ACF_NOT_LOADED`: the save layer accepts WC bindings while WC is off
  so they survive a deactivation cycle; the applier short-circuits rather than writing.
- `SKIP_INVALID_WC_FIELD = 'skip_invalid_wc_field'` — `wc_get_product($post_id)` does
  not resolve a product, or `target.key` is not in the whitelist (runtime re-check for
  CLI-imported bindings that bypass the save guard).

`PlanCode::category()` buckets both as **`blocked`** (guard/error), like the ACF pair.
`PlannerTest` gains two rows; `PlanCode::all()` count assertion updates 13 → 15.

---

## 2. Write path — WC CRUD only

```php
$product = wc_get_product( $post_id );          // false → SKIP_INVALID_WC_FIELD (guarded pre-write)
if ( 'short_description' === $key ) {
    $product->set_short_description( $value );
} else {
    $product->set_description( $value );
}
$product->save();                                // canonical writer
```

**Rationale (WC CRUD, never `wp_update_post`/`$wpdb`).** `description` /
`short_description` map to `post_content` / `post_excerpt`, but the WC CRUD writer
keeps WooCommerce's own product cache + the `wc_product_meta_lookup` table + any
`woocommerce_*` save hooks consistent. (Note for reviewers: **HPOS is NOT relevant
here** — HPOS is *order* storage; product descriptions are always `wp_posts` fields.
The earlier "HPOS" rationale from spec-woocommerce.md §4.2 was wrong; the real reason
is lookup-table/hook consistency.)

---

## 3. Exact validation & order behavior

### Save-time (`validate_save`, admin)

`WooCommerceProductFieldTarget::validate_save($binding)` returns an error string
(or null):
1. `target.key` ∉ {`description`, `short_description`} → error "not a writable
   product field".
2. `binding.post_type !== 'product'` → error "product-field targets require the
   Product post type". (Guards a WC target bound to a non-product type.)
3. (Optional, non-blocking if WC inactive) if WC is loaded, no further check needed —
   the field set is static, not per-product.

**Slotting into `BindingsPage::handle_save` without reordering ACF/post_meta.**
Phase 2 deferred `validate_save` precisely because folding it in risked reordering
the admin's first-error precedence. Phase 3 resolves it **surgically**:
- The kind-agnostic reserved-key **Tiers 1-2** stay in `run_target_guard` (step 1).
- The post_meta **Tier-3** wp_posts-column check **stays** in `run_target_guard`
  (step 1) — NOT moved. So post_meta precedence is unchanged.
- Only the **step-4** kind-specific check is genericised: replace the direct
  `validate_acf_field_key(...)` call with
  `$err = TargetRegistry::get($kind)?->validate_save($data)`, where
  `AcfFieldTarget::validate_save` = the moved field-key check, `PostMetaTarget::validate_save`
  = `null` (its Tier-3 already ran at step 1), `WooCommerceProductFieldTarget::validate_save`
  = the rules above. ACF and post_meta first-error order is **byte-for-byte preserved**;
  WC slots in at the same step-4 position ACF used.
- This adds `validate_save` to the `TargetKind` interface (all three implement it).

### Runtime (`validate_runtime`, applier — spec §4.4.1 order)

Runs at **Stage 2** of the applier's lazy gate order (post/type/status → **runtime** →
source → render), per the 2.3.1 ordering:
1. `! function_exists('wc_get_product')` → `SKIP_WC_NOT_LOADED`.
2. `! wc_get_product($post_id)` (not a product) OR `key` ∉ whitelist →
   `SKIP_INVALID_WC_FIELD`.
3. else null.

So an out-of-scope or WC-inactive product never reaches render/write — same cheap-skip
guarantee the 2.3.1 fix restored.

### Behavior flags — unchanged semantics

`auto_seed_empty`, `regenerate_on_save`, `preserve_manual_edits`, `clear_on_empty`
behave exactly as for ACF/post_meta (the pure `Planner` is kind-agnostic). Manual-edit
detection uses the same `sha1(rendered)` signature meta. **Default posture:
seed-empty + preserve-manual-edits** (never clobber human product copy). The admin
form should render `regenerate_on_save` for a product target with **stronger visual
weight / a confirm** — it overwrites live catalog copy.

---

## 4. Re-entrancy guard (the write-loop hazard)

`$product->save()` fires `save_post` → `SavePostTrigger` (p20) → could re-apply the
same binding → `write()` → `save()` → ∞.

**Guard.** A generic `Spintax\Bindings\ReentrancyGuard` (static): `enter($post_id)`,
`is_active($post_id)`, `leave($post_id)`.
- `WooCommerceProductFieldTarget::write()` wraps `save()`:
  `ReentrancyGuard::enter($post_id); try { …set…; $product->save(); } finally { ReentrancyGuard::leave($post_id); }`.
- `SavePostTrigger::on_save_post()` returns early when `ReentrancyGuard::is_active($post_id)`.
- Generic (not WC-specific) so any future save()-triggering target reuses it.

**Test:** a regen-on-save WC binding on a product, saving the product, must run the
applier exactly once (assert no second apply / no runaway) — spy the trigger or count
applier invocations.

---

## 5. Rollback / restore expectations

- **No transactional rollback.** Each product write is committed independently
  (`$product->save()` per post); a mid-walk failure does not revert earlier writes.
  This matches ACF/post_meta. The existing **walk lock** + **zero-failure stale-badge
  gate** apply unchanged (a partial-failure walk does not clear the Stale badge).
- **Manual edits are never silently clobbered** — `preserve_manual_edits` (default on)
  detects a human edit via signature mismatch → `SKIP_MANUAL_EDIT_DETECTED`. This is
  the "restore" guarantee: editor changes survive regeneration.
- **WC deactivation** — generated values persist (they are real `post_content` /
  `post_excerpt`); the applier returns `SKIP_WC_NOT_LOADED` and writes nothing until WC
  returns. No corruption, no auto-revert.
- **Uninstall** — generated product copy is **NOT** reverted (it is the product's real
  description by then; reverting would destroy content). `uninstall.php` cleans only
  plugin bookkeeping (binding option, per-binding signature/cache meta) — confirm the
  `_spintax_last_render_sig_*` cleanup already covers product posts (it is post-id
  keyed, so it does).
- **Clear-on-empty** writes `''` (a real content wipe) only when explicitly enabled;
  document it as destructive in the admin.

---

## 6. Smoke matrix (live WC, gate before tag)

Install WooCommerce in the dev WP, create **two** products (A, B) with distinct data.
Run each × {`description`, `short_description`}:

| # | Scenario | Setup | Expect |
|---|----------|-------|--------|
| 1 | Seed empty | `auto_seed_empty`, empty field | field seeded from template; signature stamped |
| 2 | Skip non-empty | `auto_seed_empty`, field already has copy | `SKIP_TARGET_NONEMPTY`, no write |
| 3 | Regenerate | `regenerate_on_save`, signature matches | overwritten with fresh render |
| 4 | Manual-edit preserved | edit field by hand, then save | `SKIP_MANUAL_EDIT_DETECTED`, edit kept |
| 5 | Clear on empty | `clear_on_empty`, template renders '' | field emptied (`WROTE_EMPTY`) |
| 6 | Empty no-clear | empty render, `clear_on_empty` off | `SKIP_EMPTY_RENDER`, field untouched |
| 7 | Per-product isolation | same template, A vs B | A's field ≠ B's field (distinct data) |
| 8 | Re-entrancy | regen-on-save, save product | applier runs once, no loop/timeout |
| 9 | WC inactive | deactivate WC, trigger apply | `SKIP_WC_NOT_LOADED`, no fatal, value persists |
| 10 | Non-product post_type | WC target bound to `post` (should be blocked at save) | save rejected; if forced via CLI import → `SKIP_INVALID_WC_FIELD` at runtime |
| 11 | Bulk Apply walk | ≥2 products, Bulk Apply | write/skip/failure counts logged; walk lock honored; stale gate on zero-failure |
| 12 | **Regression** | existing ACF + post_meta bindings | seed/regenerate/manual-edit/clear all still pass (no drift from Phase 2/3) |
| 13 | **Cache regression** | `%product_*%` context vars (2.2.x) on a product page | unaffected — writing a product field must not corrupt the render cache |

HPOS on/off is **not** part of the matrix (product descriptions are `wp_posts`,
HPOS-independent) — note explicitly so a reviewer doesn't demand it.

---

## 7. Delivery — two PRs, code only after this spec is signed off

**PR 1 — registry + validation + dry-run plan (NO writes reach products).**
- `WooCommerceProductFieldTarget` with `id/read/validate_runtime/validate_save/normalize_target`.
- `validate_save` added to the `TargetKind` interface; `AcfFieldTarget` field-key check
  moved in, `PostMetaTarget` returns null; `BindingsPage` step-4 genericised (§3).
- Two new PlanCodes + `PlannerTest` rows.
- Admin: third target-kind radio + a fixed 2-option key select (no AJAX discovery).
- **`write()` is not wired to fire**: save_post / cron / Bulk Apply **skip
  `woocommerce_product_field` bindings** behind a flag, so the Test panel (`plan()`
  dry-run) fully previews `would_write` + rendered value, but nothing mutates a
  product. Ship + review + let the trust model / dry-run soak.
- Gates: PHPUnit + PHPCS + `bindings-contract` (interface change touches the contract).

**PR 2 — actual write via WC CRUD.**
- Implement `write()` (§2) + `ReentrancyGuard` (§4); remove the PR-1 skip flag.
- Gates (X.Y.0 write phase): Plugin Check `--include-experimental`, **live WC smoke
  (the full §6 matrix on two products)**, `bindings-contract` + `wporg-compliance`.

**Separate: live WC-smoke run** (its own checklist section in `docs/release-checklist.md`),
plus the **cache/binding regression** (rows 12-13) run explicitly, before the PR-2 tag.

---

## 8. Open questions (resolve before PR 1)

- Version: PR 1 = 2.4.0 (new surface, dry-run only) or a 2.3.x preview? Lean **2.4.0**
  (it adds a user-visible target-kind option even if write is gated).
- Admin copy for the gated PR-1 state — how to signal "preview only, writes coming"?
- Should PR-1's gate be a constant, a capability, or a per-binding "enabled" flag? Lean a
  simple internal constant flag flipped in PR 2 (smallest surface).
- Confirm `wc_get_product()` on a variable/variation product resolves the parent for
  description writes (V1 targets the parent product only).
