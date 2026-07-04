# ADR-0001: Runtime variable trust levels

- **Status:** Accepted
- **Date:** 2026-07-04
- **Supersedes / relates:** codifies the security decisions shipped ad hoc in 2.2.0
  (explicit-`product_id` published-status gate) and 2.2.1 (product-value spintax
  shielding). Companion: `docs/spec-woocommerce.md` §2.5/§2.6, `CLAUDE.md` "Key
  design decisions".
- **First ADR in this repo** — establishes the `docs/adr-NNNN-*.md` log.

## Context

The render engine deliberately treats **every variable value as potential
spintax**. After `%var%` substitution (`Renderer::process_template` Stage 6b),
the merged text still passes through conditionals, plurals, enumerations,
permutations, `#include`, and nested `[spintax]` execution (Stages 6a–9). This
is a feature: it lets an author write `#set %cta% = {?bonus?Claim|Deposit}`, keep
spintax in global settings vars, or compose templates via `[spintax]`.

But **not every runtime-variable source is authored by the same actor at the
same privilege.** Some values are *data* pulled from records that a
lower-privileged actor controls (a WooCommerce product edited by a
`shop_manager` or a CSV importer; a post's fields; an ACF field value). When
such data is treated as spintax, a lower-trust actor can inject engine
constructs — an enumeration, a `%var%` reference, a nested `[spintax]`, or a
`#include` directive — into a page rendered under a *template author's* intent,
and (via id-addressable lookups) read records they were not served.

This surfaced concretely in the WooCommerce work:
- **Disclosure:** an explicit `[spintax product_id=N]` resolved any product
  regardless of `post_status` → gated to published in 2.2.0, memo-bypass of that
  gate closed in 2.2.1.
- **Re-interpretation:** product field values were parsed as spintax → shielded
  (`{ } [ ] % #` entity-encoded) in 2.2.1.

The fixes live **only in `WooCommerceProductContextSource`**. The same class of
issue exists, unaddressed, in `PostContextSource` and `AcfSiblingsSource`. Rather
than keep re-deciding this per source from memory, this ADR names the invariant
once.

## The two trust levels

### T1 — markup-authoring (values MAY be spintax)

The actor produces spintax **by design**; their input *is* code. Values flow
through the engine unmodified. No shielding.

| Source | Actor | Notes |
|--------|-------|-------|
| Template body, `#set` locals | template author (`manage_spintax_templates`) | the template itself |
| Global settings vars | site admin (Settings → Spintax) | admin-trust |
| `spintax_render($id, $vars)` PHP args | theme/plugin developer | controls the code |
| `[spintax attr="…"]` shortcode attributes | author embedding the shortcode | explicit key=value the author typed; overrides auto vars |
| Binding `variables.overrides` (#set text) | binding configurator (`manage_spintax_templates`) | authored spintax |

### T2 — data-derived (values MUST be shielded + access-gated)

The value is **content from a record**, potentially authored by a different or
lower-privileged actor than the template author. It is *data, not markup*.

| Source | Value origin | Compliant today? |
|--------|--------------|:----------------:|
| `WooCommerceProductContextSource` | WC product fields (name, sku, categories, tags, short desc, attributes) | ✅ shielded + published-gate |
| `PostContextSource` | `wp_posts` fields (`post_title`, `post_name`, author name, …) | ❌ **not shielded** |
| `AcfSiblingsSource` | ACF sibling field values (text/textarea/wysiwyg) | ❌ **not shielded** |

## Decision

1. **Classify every runtime-variable source as T1 or T2.** New sources declare
   their level explicitly (in code + review).

2. **T2 sources MUST, at the source boundary (before values enter the runtime
   layer):**
   - **Shield** spintax structural characters — entity-encode `{ } [ ] % #` —
     so the value renders literally and cannot be re-parsed as
     enum/perm/conditional/plural/`%var%`, execute a nested `[spintax]`, or
     inject a `#include`/`#set` directive. Entities survive the final
     `wp_kses_post` and render as the literal glyph.
   - **Access-gate** any record resolved by a *caller-supplied* id — e.g.
     published-status / capability checks — and make the gate un-bypassable by
     caches (see the 2.2.1 memo-scoping fix). Auto-detected records already
     limited by the main query need no extra gate.

3. **The shield is one shared utility, not copy-pasted per source.** Today
   `shield_spintax()` is private to `WooCommerceProductContextSource`. When the
   second T2 source is hardened, extract it to a shared home (e.g.
   `Spintax\Support\SpintaxShield::neutralize()` or a shared trait) so the
   invariant has exactly one definition. Until then, this ADR is the single
   source of the character set (`{ } [ ] % #`).

4. **T1 behavior is unchanged.** We do not shield authored spintax and we do not
   change the engine's "values may be spintax" semantics. The boundary is at the
   *input source*, not the engine.

## Consequences

- **Consistency debt (known, lower-urgency):** `PostContextSource` and
  `AcfSiblingsSource` are T2-non-compliant (pre-existing since 2.0 bindings).
  Their consumers are `manage_spintax_templates` content-managers configuring
  bindings that render into stored fields, so the blast radius is smaller than
  the front-end WC case — but the class is identical. Hardening = extract the
  shared shield, apply in both `build()` methods, add tests. Tracked as the
  "consistent shielding" follow-up.
- **Future sources inherit the rule for free** once the shared shield exists:
  Phase 3 WooCommerce write targets, taxonomy/term context (Phase 4), and any
  new `Core/Variables/*Source` that reads records.
- **Lossy for a deliberate "spintax in data" use case** — an author who wants a
  product/post/ACF field to *contain* live spintax loses that. This is an
  explicit non-goal: T2 values are data. Authors compose in the template (T1).
- **Shielding is defense-in-depth, gating is the real control.** Shielding
  prevents re-interpretation; the access gate prevents disclosure. A T2 source
  needs both where it resolves records by id.

## Checklist for a new / audited runtime-var source

- [ ] Declared T1 or T2 (documented in the class docblock).
- [ ] If T2: every returned value routed through the shared shield.
- [ ] If T2 and it resolves records by a caller-supplied id: status/capability
      gate applied, and not bypassable via any per-request cache/memo.
- [ ] Tests: a value containing `{ } [ ] % #` / `#include` renders literally; a
      non-visible record is not disclosed through an explicit id.
- [ ] For T1: no shielding (would corrupt intended spintax).

## Sequencing

Write this ADR → extract shared shield + make `PostContextSource` /
`AcfSiblingsSource` compliant (consistent shielding) → then Phase 2. Do not fold
the shielding refactor into Phase 2's bindings-contract work; keep the security
invariant change reviewable on its own.
