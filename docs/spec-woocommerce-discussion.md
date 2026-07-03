# WooCommerce Integration Mini Spec

Status: DISCUSSION DRAFT

Engineering spec (grounded in code recon): `docs/spec-woocommerce.md` — that doc supersedes
this one for implementation decisions; this one keeps the product framing and non-goals.

Purpose: decide whether and how Spintax should add WooCommerce support without
turning the plugin into a WooCommerce-only tool or destabilizing the existing
bindings layer.

## Roadmap Reference

WooCommerce is not named directly in the current roadmap.

Closest existing roadmap anchors:

- `docs/product-roadmap-2026.md#51-what-must-stay-in-the-free-plugin`
  keeps the free plugin focused on authoring templates, validating/previewing
  them, rendering on site, and caching/regenerating output predictably.
- `docs/product-roadmap-2026.md#53-planned-plugin-extensions-post-v1x`
  lists ACF / post-meta bindings as the planned integration surface. That work
  has already shipped in 2.0.x and is the natural base for WooCommerce.
- `docs/product-roadmap-2026.md#54-what-must-not-go-into-the-free-plugin`
  says the free plugin should not absorb hosted AI/provider/billing surfaces.
  WooCommerce support must remain local rendering and pre-generation, not an AI
  commerce suite.
- `docs/product-roadmap-2026.md#10-recommended-plugin-boundary`
  defines the plugin boundary as authoring + validation + runtime. WooCommerce
  should fit inside that boundary as an optional integration layer.
- `docs/product-roadmap-2026.md#phase-6-vertical-packs-and-commercial-ecosystem`
  mentions an "Ecommerce category text pack", which is a content/product-layer
  idea rather than a plugin-runtime requirement.

## Problem

WooCommerce stores need repeatable unique content for product descriptions,
short descriptions, product SEO blocks, category copy, attribute-driven blurbs,
FAQ snippets, and comparison fragments.

The existing Spintax plugin already solves the core generation problem:

- templates
- runtime variables
- caching
- validation
- ACF / post-meta bindings
- bulk apply
- manual-edit preservation

What is missing is a safe WooCommerce-aware context and, later, safe
WooCommerce-aware targets.

## Product Direction

Do not ship "full WooCommerce support" as one large feature.

Ship it in layers:

1. WooCommerce context variables.
2. WooCommerce-aware binding architecture.
3. Product-field targets.
4. Category / taxonomy targets.
5. Slug / SEO URL targets only if there is explicit demand.

This keeps the first release useful while avoiding catalog-wide write risks.

## Phase 1: Product Context Variables

Goal: make `[spintax]` and `spintax_render()` useful inside WooCommerce product
pages without writing anything to product records.

When WooCommerce is active and a current product can be resolved, expose product
variables in the runtime context.

Suggested variable names:

- `%product_id%`
- `%product_name%`
- `%product_slug%`
- `%product_sku%`
- `%product_type%`
- `%product_price%`
- `%product_regular_price%`
- `%product_sale_price%`
- `%product_stock_status%`
- `%product_categories%`
- `%product_tags%`
- `%product_short_description%`
- `%product_attribute_<slug>%`

Rules:

- WooCommerce remains optional. No fatal errors when it is inactive.
- Product variables are added only when a product context is known.
- Explicit shortcode/PHP runtime vars keep current precedence and override
  auto-detected WooCommerce context vars.
- Cache keys must include auto-detected context variables, especially product ID,
  otherwise product A can leak rendered output to product B.
- First implementation should not auto-render raw spintax in product content.
  Users still embed templates through `[spintax slug="..."]`.

Acceptance:

- A product page can render `[spintax slug="product-seo-block"]` using current
  product variables.
- The same template on two products gets separate cached variants.
- A non-product page keeps existing behavior.
- WooCommerce inactive keeps existing behavior.

## Phase 2: Binding Architecture Refactor

Goal: prepare the existing bindings layer for WooCommerce targets without
adding target-specific branching to `BindingApplier`.

OpenCart reference ideas worth back-porting:

- Pure planner:
  `W:/Projects/spintax-opencart/extension/upload/system/library/spintax/Core/Binding/Planner.php`
- DTO input for plan decisions:
  `W:/Projects/spintax-opencart/extension/upload/system/library/spintax/Core/Binding/PlanInput.php`
- Named plan codes and categories:
  `W:/Projects/spintax-opencart/extension/upload/system/library/spintax/Core/Binding/PlanCode.php`
- Entity/target descriptors:
  `W:/Projects/spintax-opencart/extension/upload/system/library/spintax/Core/Binding/EntityRegistry.php`
- Dry-run snapshot token:
  `W:/Projects/spintax-opencart/extension/upload/system/library/spintax/Core/Binding/DryRunToken.php`

Proposed WP refactor:

- Extract the write decision tree from `Spintax\Bindings\BindingApplier` into a
  pure `Planner`.
- Add `PlanInput` and `PlanCode` equivalents.
- Add a `TargetRegistry` / descriptor layer for target kinds:
  - `acf_field`
  - `post_meta`
  - future `woocommerce_product_field`
  - future `woocommerce_term_field`
- Add a `VariableSource` layer:
  - post context
  - ACF siblings
  - WooCommerce product context
  - future taxonomy context

This is mostly internal but reduces risk before catalog-wide writes.

Acceptance:

- Existing ACF / post-meta tests still pass.
- Test panel and live apply still use one decision path.
- No WooCommerce target is added yet in this phase.

## Phase 3: WooCommerce Product Targets

Goal: allow pre-generation into selected WooCommerce product fields.

Suggested first target fields:

- product short description: `post_excerpt`
- product long description: `post_content`
- selected product meta keys only by explicit whitelist

Rules:

- These are not ordinary post-meta bindings. They need a dedicated target kind.
- `post_title`, price, SKU, stock, sale dates, and inventory fields are out of
  scope. They are commerce data, not generated copy.
- Default behavior should be seed-empty + preserve manual edits.
- Regenerate-on-save should remain available but should be visually treated as
  a stronger action.
- Product save trigger should use the broad WordPress/WooCommerce save path, not
  a narrow WooCommerce-only hook that misses imports or REST updates.
- Bulk apply must keep the current walk lock and zero-failure stale-badge gate.

Recommended target kind shape:

```php
'woocommerce_product_field'
```

Allowed target keys:

- `short_description`
- `description`

Open question: should this write via `wp_update_post()` or direct targeted
database updates? Lean `wp_update_post()` for WordPress cache/hooks correctness,
with recursion guards and narrow payloads.

Acceptance:

- Binding can target product short description.
- Binding can target product long description.
- Manual edits are preserved by signature.
- Existing post-meta and ACF bindings are unaffected.
- Bulk apply on products logs write/skip/failure counts.

## Phase 4: Product Category Targets

Goal: support WooCommerce category copy after product-field targets are stable.

Candidate targets:

- term description
- selected term meta keys

Risks:

- taxonomy term APIs have different cache invalidation behavior than posts.
- term descriptions are often shared with theme/SEO-plugin output.
- multilingual plugins may duplicate or split terms in different ways.

Recommendation: defer until product targets have real usage.

## Phase 5: Slug / SEO Targets

Goal: consider generated slugs only if users explicitly ask for it.

OpenCart has a dedicated slug output mode:

- `W:/Projects/spintax-opencart/extension/upload/system/library/spintax/Slug/SlugAdapter.php`

For WooCommerce this should remain deferred because product/category slugs affect
live URLs and SEO history.

If ever implemented:

- default to seed-once
- never regenerate slugs on save by default
- collision-check before write
- probably rely on WordPress `wp_unique_post_slug()` / term slug APIs
- document permalink risk clearly

## Non-Goals

- No AI provider integration.
- No OpenAI/API-key management.
- No automatic rewriting of all product content on activation.
- No direct price/SKU/stock generation.
- No raw spintax parsing in normal WooCommerce product content.
- No multilingual WPML/Polylang fan-out in the first WooCommerce release.

## Main Risks

- Cache bleed between products if auto context is not included in the cache key.
- Product save recursion if generated descriptions are written through the wrong
  save path.
- Accidental overwrite of human product copy.
- Bulk apply reporting success after partial catalog failure.
- SEO plugin conflicts if generated fields overlap with SEO plugin meta.
- UX overload if WooCommerce targets are added before the bindings UI is ready.

## Recommended First Slice

Build Phase 1 only:

- `WooCommerceProductContextSource`
- automatic product context detection
- cache-safe runtime variable merge
- unit/integration tests for product/non-product/Woo-inactive cases
- docs examples for `[spintax slug="product-seo-block"]`

Do not add WooCommerce write targets in the same release.

## Discussion Questions

- Should WooCommerce context variables be enabled globally, per template, or
  always-on when a product context exists?
- Should product context apply only on singular product pages, or also inside
  product loops/cards?
- Should shortcodes inside product descriptions use the current global product,
  the queried product, or an explicit `product_id` attribute when supplied?
- Should `%product_price%` be formatted for display or raw numeric? Lean display
  string for authoring, with raw variants later if needed.
- Which SEO plugins, if any, should be explicitly supported as meta targets?
- Should the first WooCommerce release be documented as "context variables" rather
  than "WooCommerce bindings" to avoid overpromising?
