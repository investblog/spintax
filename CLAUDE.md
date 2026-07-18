# Spintax — WordPress Plugin

## Project overview

Free WordPress plugin for spintax-based content generation. Target audience: content managers and SEO specialists. Rewrite of old plugin "nested-spintax-for-acf" with a GTW-derived engine extended with plugin-original conditionals and plural agreement.

- **GitHub:** https://github.com/investblog/spintax
- **WP.org:** https://wordpress.org/plugins/spintax/
- **Docs / playground:** https://spintax.net
- **Author:** 301st (https://301.st)
- **Current version:** 2.5.0
- **Status:** live on WordPress.org. Shipping surfaces: spintax engine (templates + shortcode + `spintax_render()`), **WooCommerce product context variables** (`%product_*%`, read-only, 2.2.0), bindings (ACF + post-meta + **`woocommerce_product_field` write targets since 2.4.0**, with Bulk Apply / Run-now / cron triggers; decision engine is a pure `Planner` + `TargetRegistry` since 2.3.0), Logs page, WP-CLI `wp spintax bindings *`. Detailed release notes live in `plugin/readme.txt` changelog — don't duplicate them here. Reviewer-driven contracts that aren't obvious from the code live in the "Bindings" section below.
- **Active roadmap:** WooCommerce integration. Phase 1 (context vars) SHIPPED 2.2.0; Phase 2 (pure Planner / TargetRegistry refactor) SHIPPED 2.3.0; **Phase 3 (`woocommerce_product_field` write targets) SHIPPED 2.4.0** — `docs/spec-woocommerce-phase3.md`, status block records the deviations from the mini-spec. **Next: Phase 4 (term targets) / Phase 5 (slugs) — deferred, no trigger yet.** Product framing: `docs/spec-woocommerce.md` + `docs/spec-woocommerce-discussion.md`. Separately, the engine is now a standalone package (`spintax/core` on Packagist, `@spintax/core` on npm); the plugin does **not** consume it yet (deferred — the cross-engine corpus CI gate already closes the drift risk that motivated it).
- **Cross-engine releases ship as a trio, in a fixed order.** 2.5.0 (sr/hr/bs plurals) is the worked example: plugin `v2.5.0` + `spintax/core v0.2.0` + `@spintax/core v0.2.0`, all three released 2026-07-18. **Merge the two PHP engines FIRST, the `spintax-js` corpus LAST.** Every repo's CI checks the others out with `actions/checkout` and no `ref:`, so each job floats on the others' default branch — nothing is pinned, no pin-bump commit is ever needed, but a corpus fixture landing before the engines turns both `php-parity` legs red. Say so in the PR description if CI may start early.

## Reference sources

- **GTW syntax reference:** `docs/gtw-syntax-reference.md`
- **Product spec:** `docs/spec-v1.md`
- **Bindings design + locked contracts:** `docs/spec-acf-bindings.md`
- **Runtime-var trust levels (T1/T2 shielding):** `docs/adr-0001-runtime-var-trust-levels.md`
- **WooCommerce:** `docs/spec-woocommerce.md` (engineering) + `docs/spec-woocommerce-discussion.md` (product) + `docs/spec-woocommerce-phase3.md` (write-targets mini-spec)
- **`#set` / `#def` variable semantics (DRAFT, not coded):** `docs/spec-set-def-semantics.md` — reverts 2.2.0's `#set` collapse-once to macro expansion and adds `#def` for roll-once. Cross-engine + spintax.net.
- **Release protocol:** `docs/release-checklist.md`; **backlog:** `docs/backlog.md`
- **Cross-engine parity corpus (`@spintax/core`, the npm/TS port):** `W:\Projects\spintax-js` — `packages/conformance/fixtures/*.json` is the **shared golden corpus**, and `packages/conformance/php` drives it through *this* engine. It is the only thing binding the two engines together, and **it runs in no CI** (see the pre-push checklist).
- **OpenCart port (Planner/TargetRegistry design origin):** `W:\projects\spintax-opencart` — its kernel is a byte-identical port of `plugin/src/Core/Engine` + `Core/Render` (guarded there by `PortIntegrityTest`), so an engine fix here needs mirroring there.
- **Old WP plugin (buggy, for reference only):** `C:\Users\Admin\Local Sites\testcom\app\public\wp-content\plugins\nested-spintax-for-acf`
- **Java spintax engine (algorithm reference):** `W:\spintax-java`
- **Project structure template:** `W:\Projects\wpci` (images-sync-for-cloudflare, approved on WP.org)

## Architecture

```
plugin/
  spintax.php                    # Bootstrap: PSR-4 autoloader, hooks, demo seed
  uninstall.php                  # Full cleanup with $wpdb->prepare()
  readme.txt                     # WP.org metadata
  src/
    Admin/
      AdminMenu.php              # Centralised admin wiring + asset enqueuing
      AdminNotice.php            # PRG flash-message trait
      MetaBoxes.php              # Cache settings, preview (AJAX), usage boxes
      SettingsPage.php           # Global variables textarea, TTL, access, debug
      TemplateEditor.php         # List columns, forced text editor
    Core/
      Cache/
        CacheManager.php         # WP Object Cache API, versioned keys, TTL
        DependencyInvalidator.php # Cascade invalidation up dependency graph
      Cron/
        CronManager.php          # Per-template WP-Cron scheduling
      Engine/
        Conditionals.php         # `{?VAR?then|else}` resolver (1.4.0)
        Parser.php               # GTW-compatible recursive-descent parser
        PluralArityError.php     # Wrong-form-count exception (1.5.0)
        PluralFormError.php      # Nested-bracket-in-form exception (1.5.0)
        Plurals.php              # `{plural <count>: forms}` resolver (1.5.0)
        Validator.php            # Static syntax analysis with line/column errors
      PostType/
        TemplatePostType.php     # CPT registration, block editor disabled
      Render/
        RenderContext.php        # Immutable variable scopes + call stack
        Renderer.php             # Multi-stage pipeline with cache + dependency tracking
        functions.php            # Global spintax_render() helper
      Settings/
        SettingsRepository.php   # CRUD for settings, global vars, cache salt
      Shortcode/
        ShortcodeController.php  # [spintax] handler
    Support/
      Capabilities.php           # manage_spintax_templates role mapping
      Defaults.php               # Factory methods for default config
      Logging.php                # Ring-buffer debug logger
      OptionKeys.php             # Centralised option/meta key constants
      Validators.php             # Data normalisation helpers + binding-key guard tiers
      SpintaxShield.php          # neutralize {}[]%# in T2 data-derived values (2.2.2; ADR-0001)
    Bindings/                    # ACF / post-meta bindings (2.0.0)
      BindingApplier.php         # fact-resolver + assembler; delegates decision→Planner, dispatch→TargetRegistry (2.3.0)
      BindingResolver.php        # template-mode / per_post-mode source lookup
      BindingsRepo.php           # CRUD over single autoloaded option, fires saved/deleted actions
      BulkApply.php              # Action Scheduler walker + WP-CLI fallback path
      Defaults.php               # Default binding shape + MAX_BINDINGS=200; target_kinds() delegates to TargetRegistry
      Migration.php              # one-shot import from nested-spintax-for-acf
      Plan/                      # Pure write-decision (2.3.0)
        PlanCode.php             # single source of the 13 outcome strings + is_write/category/all
        PlanInput.php            # immutable fact DTO (snake_case promoted props)
        Planner.php              # PURE scope_reject() + plan() — no WP calls; the decision authority
      Target/                    # Target-kind descriptors (2.3.0)
        TargetKind.php           # interface: id/read/write/validate_runtime/normalize_target
        AcfFieldTarget.php       # get_field/update_field($field_key) + runtime ACF guard
        PostMetaTarget.php       # get/update_post_meta; default for unknown kind
        TargetRegistry.php       # static get/ids/all — allow-list source of truth
      Triggers/
        SavePostTrigger.php      # save_post priority 20 — after ACF p10
        TemplateCascadeTrigger.php # bumps per-binding cache version on template edit
        CronTrigger.php          # per-binding wp_schedule_event hooks
    CLI/
      BindingsCommand.php        # wp spintax bindings list|apply|test|export|import
    Core/Variables/              # Variable sources (T2 data-derived = shielded; see ADR-0001)
      PostContextSource.php      # %post_id%, %post_title%, … (binding path; SpintaxShield'd)
      AcfSiblingsSource.php      # %acf_<name>% same-group siblings, top-level only (shielded)
      WooCommerceProductContextSource.php # %product_*% read-only, front-end (2.2.0; shielded, memoised)
      RuntimeContextBuilder.php  # merges auto product ctx UNDER explicit vars (shortcode/spintax_render)
  assets/css/admin.css           # Native WP styles augmentation
  assets/js/admin.js             # Preview AJAX, copy shortcode, variables
  assets/js/bindings.js          # Bindings form: field discovery datalist + Test panel
```

## Bindings (2.0.0)

ACF / post-meta bindings let editors configure once-per-post-type "render this template into that field on every matching post", with auto-seed, preserve-manual-edits, Bulk Apply, and per-binding cron. Full design + phase-by-phase plan lives in `docs/spec-acf-bindings.md`.

**Key contracts** (read the spec for full detail):
- One binding = `(post_type × target_kind × target_key × source × triggers × behavior)`. Single autoloaded option `spintax_bindings`, capped at 200 bindings/site.
- Source modes: `template` (binds to a `spintax_template` CPT entry) or `per_post` (binds to sibling meta `_spintax_source_<key>` authored inline on each post).
- Triggers: V1 hooks `save_post` priority 20 ONLY (after ACF's own p10). `acf/save_post` is NOT used — it only fires on ACF payloads, would silently break Quick Edit / WP-CLI / non-ACF REST flows.
- Template-edit cascade is **internal render-cache hygiene only**, NOT front-end visibility — bindings pre-generate into stored fields, consumers read those directly. Editing a template surfaces an admin notice telling the editor to run Bulk Apply.
- ACF targets persist `target.field_key` (e.g. `field_5f8a1234abcd`) alongside `target.key` (the human field name). `update_field( $field_key, ... )` is required by ACF on first write to establish the reference meta. **`target.field_key` is required for `kind=acf_field` (Tier 5 guard, 2.0.1)** — verified against `acf_get_field( $key )` when ACF is loaded.
- `ajax_acf_fields` does NOT recurse into `sub_fields` / `flexible_content` (V1 non-goal NG1).
- Reserved-key guard has 5 tiers: WP internal meta (`_wp_*`), plugin-internal (`_spintax_*`), wp_posts columns (`post_title`, etc.), **uniqueness on `(post_type, target.key)` — regardless of `target.kind` (2.0.1; ACF and post_meta on same name collide because they share the wp_postmeta row)**, and ACF field_key validity for `kind=acf_field` (2.0.1).
- BindingApplier.plan() runs **scope filter first** (2.0.1) — returns `SKIP_OUT_OF_SCOPE_TYPE` if `post.post_type != binding.post_type`, `SKIP_OUT_OF_SCOPE_STATUS` if `binding.status === 'publish'` and `post.post_status !== 'publish'`. Test panel inherits this transparently.
- **Runtime ACF target validation (2.0.3)** — after the scope filter, `plan()` re-verifies `target.field_key` for `kind=acf_field` via `acf_get_field()`. Returns `SKIP_ACF_NOT_LOADED` when ACF isn't loaded (save layer accepts ACF bindings while ACF is inactive so they survive deactivation cycles; the applier short-circuits during such intervals rather than falling back to raw post_meta writes) or `SKIP_INVALID_ACF_FIELD` when the key resolves to a field whose name disagrees with `target.key` (renamed/deleted/foreign key). Brings total return codes to 13. No silent post-meta fallback for `kind=acf_field` — the target is read/written only after the runtime guard clears it.
- **Pure decision engine (2.3.0, Phase 2)** — the write-decision is now a pure function in `Bindings/Plan/`: `BindingApplier::plan()` resolves I/O facts into a `PlanInput` DTO, `Planner::plan()` decides (no WP calls), and a pure `assemble()` rebuilds the 6-key `plan()` array. The 13 codes live in `PlanCode` (`BindingApplier::` constants are aliases to it — the wire contract; don't change the strings). Target-kind read/write/validate/normalize dispatch through `Bindings/Target/TargetRegistry` (`AcfFieldTarget`/`PostMetaTarget`), NOT inline `=== 'acf_field'` branches. `TargetRegistry::ids()` is the allow-list; unknown kind falls back to `post_meta`. Behavior byte-for-byte preserved (existing binding suite passes unedited; `PlannerTest` locks all 13 codes + the `rendered_hash` `''`-vs-`sha1('')` distinction). **Runtime-core scope only** — admin form radio/combobox, AJAX discovery, `Migration::classify`, list badge/metabox label and `AcfSiblingsSource` gate stay per-kind branches by design. `TargetKind::validate_save` was **deferred to Phase 3** (adding it in Phase 2 would reorder admin first-error precedence) — **resolved in 2.4.0**, moved into the interface with the ACF check lifted verbatim and the first-error order locked by tests.
- **Cheap scope-skip ordering (2.3.1)** — `plan()` resolves facts lazily/staged: scope (post/type/status) → runtime target → source, rejecting via `Planner::scope_reject()` after each stage, so an out-of-scope dry-run never pays for `resolve_source()` or render. Not-yet-resolved facts default to "passing" so each staged `scope_reject` call isolates its gate.
- Bulk Apply Stale-badge gating: `stamp_last_applied_version()` only fires when the **entire walk** had zero failures. 2.0.1 gated per-chunk; 2.0.3 added a cumulative flag in option `_spintax_binding_walk_failed_v_<id>` so failures in any earlier chunk also block the final stamp.
- **Walk lock (2.0.3)** — `BulkApply::enqueue()` and `::run_synchronously()` acquire a per-binding lock (option `_spintax_binding_walk_lock_<id>`, value = timestamp) at walk start and refuse to start if another walk is in flight; stale locks (>1h) are auto-overwritten. Prevents admin + cron concurrent walks racing on the cumulative flag.
- BindingsPage save-flow (2.0.1): validation errors flash form state into transient `spintax_binding_form_flash_<user_id>` (TTL 60s) and redirect back to the form, not the list — `render_form()` consumes the flash.
- Capability: `manage_spintax_templates` (content-manager, not site-admin).
- Bulk Apply: Action Scheduler when available, `WP_Error 'no_action_scheduler'` otherwise → admin notice points at `wp spintax bindings apply --binding=<id> --all`.
- WP-CLI `export` / `import` for staging→prod sync (JSON, deduped by target triple, `--dry-run` and `--overwrite` supported).
- Migration helper at Tools → Spintax Migration imports from predecessor `nested-spintax-for-acf` non-destructively (original data never deleted).

**Action Scheduler dependency (documented as optional in readme.txt 2.0.2):** Recommended for binding-heavy sites, NOT required. Two features degrade without it:
1. Admin "Bulk Apply" button — `BulkApply::enqueue` returns `WP_Error 'no_action_scheduler'` instead of dispatching async chunks. The notice points editors at `wp spintax bindings apply --binding=<id> --all` instead.
2. Per-binding cron schedules — `CronTrigger::fire()` falls back to `BulkApply::run_synchronously()`, running the entire walk on the cron tick. Risk of PHP-FPM timeouts on large catalogues.
Detection via `BulkApply::action_scheduler_available()` (checks `function_exists('as_enqueue_async_action')`). Bindings admin page renders an info notice via `BindingsPage::render_action_scheduler_notice()` when AS is missing — links to `plugin-install.php?s=action+scheduler` and the wp.org listing. Many WP shops ship AS bundled with WooCommerce / Jetpack / etc., so check before adding it as a separate install.

## WooCommerce

**Product context variables (2.2.0, read-only).** On a singular product page,
`WooCommerceProductContextSource::build()` exposes the current product as `%product_id%`
(always present — the cache discriminator), `%product_name%`, `%product_slug%`, `%product_sku%`,
`%product_type%`, `%product_stock_status%`, `%product_categories%`, `%product_tags%`,
`%product_short_description%`, and `%product_attribute_<slug>%` per attribute. **Pricing is
deliberately excluded** (volatile → cache churn).

- Vars enter the **runtime layer** (via `RuntimeContextBuilder::merge`, auto UNDER explicit) at
  `ShortcodeController::handle()` and `spintax_render()`. `Renderer` is untouched — nested
  `[spintax]` / `#include` inherit product context via `for_child_render()` (Strategy A: engine
  stays WC-agnostic). Runtime membership means the cache key discriminates per product (no A→B
  bleed).
- Detection: singular product only (`get_queried_object`); loops deferred. `product_id="123"`
  shortcode attr forces a specific product but is **gated to published** (2.2.1) and the memo is
  path-scoped (`explicit:`/`auto:`) so a draft can't leak via cache.
- Values are **shielded** (`SpintaxShield`) so product content can't be re-parsed as spintax
  (T2 source, ADR-0001). WooCommerce is optional — no fatals when inactive.
- Latent (backlog): `locale` is not in the render cache key → multilingual plural collision risk.

**Write targets (Phase 3 — SHIPPED 2.4.0).** `woocommerce_product_field` is a `TargetKind` in the
registry: a template renders into a product's `description` / `short_description`, per product,
through the existing binding machinery (save_post p20, cron, Bulk Apply, manual-edit preservation).

- **Whitelist of two, enforced twice.** Only `description` and `short_description`. Price, SKU and
  stock are commerce data, not copy. Checked at save time (`validate_save`) *and* re-checked before
  every write (`validate_runtime`), because `wp spintax bindings import` bypasses the admin form.
- **WC CRUD only** — `wc_get_product()` → `set_description()` / `set_short_description()` → `save()`.
  Never `wp_update_post()`/`$wpdb`: the fields map to `post_content`/`post_excerpt`, so a direct write
  would appear to work while leaving WooCommerce's product cache and `wc_product_meta_lookup` stale
  and skipping every `woocommerce_*` save hook. (HPOS is **not** the reason — that is order storage.)
- **`Bindings\ReentrancyGuard`** — `$product->save()` fires `save_post`, the hook `SavePostTrigger`
  listens on, so a regenerate-on-save binding would loop forever. The target enters the guard around
  `save()` (released in a `finally`); the trigger stands down while it is up. Generic, not WC-specific.
- **`TargetKind::validate_save()`** (the Phase-2 deferral, resolved) and **`validate_runtime()` now
  takes `$post_id`** — without it a target cannot ask "is this post actually a product", and a binding
  pointed at a non-product would be planned as a write that silently does nothing.
- **2 new PlanCodes → 15**: `SKIP_WC_NOT_LOADED` (mirrors the ACF precedent — the save layer accepts a
  WC binding while WC is off so it survives a deactivation cycle; generated copy is never reverted),
  `SKIP_INVALID_WC_FIELD` (bad key, non-product post type, or a post WC won't resolve). Both `blocked`.
- **`variables.expose_product_context`** (new per-binding flag) merges `%product_*%` into the binding
  render via `WooCommerceProductContextSource::build_for_binding()` — no publish gate, deliberately:
  a binding writes the product's own data into the product's own field, and drafts are exactly what
  pre-generation is for. Values stay shielded (T2). Deviates from the spec, which called the context
  vars "unrelated"; see the spec's status block for why that was wrong.

Verified on real WooCommerce across the full 13-row matrix in `docs/spec-woocommerce-phase3.md` §6.

## Spintax syntax

GTW-original primitives plus plugin extensions (`{?…?}` conditionals since 1.4.0, `{plural …}` since 1.5.0).

- `{a|b|c}` — enumeration: pick one. Nesting: `{a|{b|c}}`. Empty options: `{|a|b}`.
- `[a|b|c]` — permutation: pick N, shuffle, join.
  - `[< and > a|b]` — single separator
  - `[<minsize=2;maxsize=3;sep=", ";lastsep=" and "> a|b|c]` — configured
  - `[<, > a|b < and >|c]` — per-element separator: `< sep >` before `|` assigns custom separator to the next element; travels with element during shuffle
- `%var%` — variable reference (case-insensitive)
- `#set %var% = value` — local variable (value can contain spintax). **Enumerations in a `#set` value collapse ONCE at set-time (2.2.0, Renderer Stage 4b)** — so `#set %n% = {1|4|9}` binds a single stable value across every `%n%` reference (was an independent roll per use), which is what makes `{plural %n%: …}` see a numeric count. Values containing `{?…}` / `{plural …}` are left deferred (they may reference vars on other lines).
- `{?VAR?then|else}` — conditional (1.4.0): render `then` if `VAR` is truthy (set + non-whitespace), else `else` (else branch optional). Inverted form `{?!VAR?then|else}`. Resolved both before and after `%var%` expansion.
- `{plural <count>: form1|form2|form3}` — plural agreement (1.5.0): pick form by count's grammatical bucket. RU/UK/BE **+ SR/HR/BS (2.5.0)** = 3 forms (one\|few\|many); EN-style default = 2 forms (one\|many). BCS shares the East-Slavic integer rule exactly and reuses that branch — CLDR's `other` is positionally the same slot as `many`, and the genuine BCS divergence is fractional-only, hence unreachable (a non-numeric count slot is erased before the bucket math). Script/region subtags carry no plural grammar: `sr-Latn`, `sr-Cyrl`, `sr_RS` all normalise to `sr`. Everything else (`pl`, `cs`, `sk`, `sl`, `bg`) is **not rejected** — it is silently bucketed by the EN 2-form rule that is not its own, so don't trust the fallback. Count is `%var%` or literal integer (post-`expand_variables`). Form slot REJECTS nested spintax brackets `{` `}` `[` `]` — extract via `#set` first. Lenient at runtime: malformed constructs render verbatim with fullwidth braces (U+FF5B / U+FF5D) so a single bad block doesn't crash the page. Locale from template post meta `_spintax_locale` or site WP locale.
- `/#...#/` — block comments (stripped)
- `#include "slug-or-id"` — embed another template (GTW-compatible)

## Key design decisions

- **No transients** — WP Object Cache API only (no DB pollution)
- **Scope isolation** — child templates inherit global+runtime vars, NOT parent's #set locals
- **[spintax] shielding** — shortcodes inside templates are placeholder-shielded before permutation resolution to avoid bracket conflicts
- **Preview uses editor content** — AJAX sends textarea value, NOT saved DB content
- **No wp_kses_post on input** — template source is raw spintax, sanitisation only on render OUTPUT
- **Runtime-var trust levels** — the engine treats every variable value as potential spintax (resolved after `%var%` expansion). Sources split into **T1 markup-authoring** (template/`#set`/globals/`spintax_render` args/shortcode attrs — values MAY be spintax, no shielding) and **T2 data-derived** (`*ContextSource`/`*SiblingsSource` reading records — values MUST be shielded `{ } [ ] % #` + access-gated). Full contract in `docs/adr-0001-runtime-var-trust-levels.md`. All T2 sources (`WooCommerceProductContextSource`, `PostContextSource`, `AcfSiblingsSource`) route through the shared `Spintax\Support\SpintaxShield::neutralize_map()` (since 2.2.2) — new data-derived sources must do the same.
- **minsize/maxsize defaults** — if only maxsize set, minsize=1 (not total). If only minsize set, maxsize=total.
- **Auto-spacing for word separators** — purely alphabetic separators (`<и>`, `<and>`, `<до>`) are auto-padded with spaces in `join_with_separators`. Punctuation separators (`,`, `;`) are NOT padded (post-processing handles them).
- **Per-element separator priority** — customSep > lastsep > sep. HTML tags distinguished from separators by checking for `/`, self-closing `/`, or attributes after tag name.

## Post-processing pipeline (Parser::post_process)

Order matters — incorrect sequencing causes domain/email corruption.

1. **Shield** URLs → **`mailto:`/`tel:` URIs** → emails → bare domains (ASCII+punycode+IDN) → decimals → multi-dotted abbreviations (`т.д.`) → single-token abbreviations from a curated whitelist (`соц.`, `Mr.`, `Inc.`, …) → placeholders. **`mailto:`/`tel:` must be shielded before the email/domain passes** — they have no `//` authority, so the URL pass misses them, and if the address is carved out from under the prefix the leftover colon gets a space: `href="mailto: you@example.com"`, a broken link (2.3.3).
2. **Collapse** duplicate spaces/tabs
3. **Remove** whitespace before punctuation
4. **Add** space after `,;:` and after a **run** of `.!?` — `([.!?]+)(?![.!?])`. `...`, `?!` and `!!!` are ONE sentence end, not several, so the space goes after the whole run and never inside it. **A greedy `+` alone does not work**: it backtracks *into* the run to satisfy the lookaheads and yields `Wow!! !`. The `(?![.!?])` guard is what completes the run — and it is the portable shape, since JS has no possessive quantifiers and `@spintax/core` must match byte-for-byte (2.3.3).
5. **Bind** sentence openers to the word they open (`¿ qué` → `¿qué`), deliberately before capitalisation so the passes below see a letter rather than a space (2.3.3)
6. **Capitalise** at four sites — start of text, after `.!?…`, after a block-level HTML tag (`<p>`, `<h1>`–`<h6>`, `<li>`, `<div>`, etc.), and after a line break — each looking through the shared **`$lead`**: any run of HTML tags, sentence openers (`¿` `¡`) and whitespace, in any order. The lead is what carries the capital through `¡¿Qué haces?!` (two openers — RAE's question-exclamation) and `<p>¿<a href="/ayuda">Necesitas ayuda</a>?</p>` (a tag *after* the opener). The opener **set** stays deliberately narrow: quotes and brackets both open AND close, so capitalising after them would rewrite list markers (`Elige una. (a) primero`). A corpus fixture locks that (2.3.3).
7. **Restore** placeholders

## Global variables

Entered as raw `#set` syntax in Settings → Spintax textarea (not key-value table). Parsed by `Parser::extract_set_directives()`, validated with line-number errors on save. Stored as both raw text (for editor) and parsed pairs (for rendering).

## WP.org compliance checklist

Verified at each release tag (re-run before SVN deploy):

- PHPCS: 0 errors, 0 warnings
- Plugin Check (`--include-experimental`): 0 errors, 0 warnings on the shipping surface (test files excluded from ZIP via `.distignore`)
- CI fully green (lint PHP 8.0–8.3, tests PHP 8.0+8.2 × WP 6.2+latest, build ZIP)
- Nonces on all forms/AJAX
- Capability checks on all admin actions
- Input sanitisation via `Validators::sanitize_spintax()` — `wp_check_invalid_utf8`, strip null bytes/control chars, normalize line endings
- Output escaping (`wp_kses_post` on render output)
- `$wpdb->prepare()` for direct queries
- `ABSPATH` guard on all PHP files
- `SECURITY.md` with responsible disclosure
- `readme.txt`: External Services, Privacy Policy, Screenshots, Credits, Upgrade Notice
- `.distignore` for 10up deploy action
- Demo template seeded on first activation

## Versioning

Version in 3 places (must sync): plugin header, SPINTAX_VERSION constant, readme.txt Stable tag.

```bash
npm run version:set -- X.Y.Z   # Update all 3
npm run version:check           # Verify sync
```

Release: version:set → commit → `git tag vX.Y.Z` → push + push tags. CI validates all 3 match.

## Commands

```bash
npm run env:start              # Start wp-env (ports 8892/8893)
npm run env:stop               # Stop wp-env
npm run wp:plugin:activate     # Activate plugin
npm run lint:php               # PHPCS via wp-env container
npm run lint:php:fix           # Auto-fix PHPCS
npm run lint:php:ci            # PHPCS via local composer (CI)
npm run test:php               # PHPUnit via wp-env
npm run test:php:setup         # Install PHPUnit + polyfills in container
npm run version:set -- X.Y.Z  # Set version everywhere
npm run version:check          # Verify version sync
```

## Pre-push checklist (MANDATORY before every push)

Run ALL of these locally. Zero errors AND zero warnings required:

```bash
npm run test:php           # PHPUnit — all tests must pass
npm run lint:php           # PHPCS — 0 errors, 0 warnings
```

**If the diff touches `src/Core/Engine/` or `src/Core/Render/`, run the cross-engine golden corpus locally before pushing.** CI enforces it too — the `conformance` job runs it on every push and `build` depends on it — but finding a parity break in 30 seconds locally beats finding it after the push. No local PHP needed; run from **PowerShell** (Git Bash mangles the container paths):

```powershell
docker run --rm -v "W:\Projects\spintax-js:/js" -w /js/packages/conformance/php composer:2 install
docker run --rm -v "W:\Projects\spintax-js:/js" -v "W:\projects\spintax:/spintax" `
  -w /js/packages/conformance/php -e SPINTAX_PLUGIN_SRC=/spintax/plugin/src php:8.2-cli vendor/bin/phpunit
```

Green = **138 tests, 151 assertions, 1 known skip** (`neutralize`, a deliberate TS-only divergence), as of 2026-07-18. **Read the counter, not the exit code.** The corpus grows, so this number dates — treat a count *lower* than the last known figure as a red flag (a runner that discovers no fixtures still exits 0 and prints a cheerful `OK`), and update this line when you add fixtures. In CI the same figure must appear on **both** `php-parity` legs; two legs disagreeing means one engine checked out a stale default branch.

The corpus is the **only** machine check binding this engine to `@spintax/core`, the `spintax/core` Composer package and the OpenCart port. It used to be a manual gate, and that is exactly how three post-process defects reached users in the 2.3.2 window — a Spanish fix shipped here with zero PHP-side tests, its only guard sitting in another repository's corpus that nothing here ran. Both directions are now wired: this repo's CI runs the corpus against this engine, and `spintax-js`'s CI runs a changed corpus against both PHP engines, so a fixture cannot land there without them agreeing.

A change that alters engine output still has to land in **every engine** plus a corpus fixture — a unit test in one engine binds only that engine.

## Release gates (MANDATORY before tagging `vX.Y.Z`)

PHPUnit/PHPCS green is the bar for push-to-main; it is NOT the bar for a SVN release. **Every release** must additionally pass:

1. **Plugin Check** with `--include-experimental` — 0 errors, 0 warnings. Run from wp-admin Tools → Plugin Check (or `wp plugin check spintax --include-experimental` via CLI). PHPUnit covers logic; Plugin Check covers WP.org guideline conformance the unit suite doesn't see.
2. **Smoke-test the user-facing surface that this release actually changes.** For binding-touching releases, this means installing ACF Free in the dev WP, creating a `target_kind=acf_field` binding, and exercising the four behavior scenarios (save_post seed, regenerate, manual-edit detection, clear_on_empty). The full protocol lives in `docs/release-checklist.md` — follow it section-by-section, don't skim. PHPUnit cannot catch ACF integration bugs because the test suite runs without ACF loaded.
3. **Independent reviewer pass** for X.Y.0 (major) releases — agent fresh-eyes review of the diff against `docs/spec-acf-bindings.md` and the surrounding contracts. Not optional for major surface changes; lessons from 2.0.0 → 2.0.1 hot-fix demonstrate.

The 2.0.0 release skipped gates 1 and 2 and shipped two P1 bugs (cross-kind dedup, missing ACF field_key validation) plus three P2 bugs that required a same-day 2.0.1 hot-fix. **Don't make that mistake again.** Patch releases (X.Y.Z where Z > 0) may skip gate 2 if the diff doesn't touch the relevant surface, but gate 1 is non-negotiable.

Release flow (fully automated via SVN deploy):

```bash
npm run version:set -- X.Y.Z   # Sync plugin header + SPINTAX_VERSION + Stable tag
git commit -am "Release X.Y.Z: <summary>"
git push origin main           # ci.yml runs
# DO NOT TAG YET — run docs/release-checklist.md first.
git tag vX.Y.Z
git push origin vX.Y.Z         # → release.yml + wporg-deploy.yml fire in parallel
```

`release.yml` cuts a GitHub Release with the ZIP. `wporg-deploy.yml` pushes to `plugins.svn.wordpress.org/spintax/` (trunk + `tags/X.Y.Z` + `/assets/`) using the 10up action. No manual ZIP, no manual SVN.

**Common traps:**
- `wp_kses_post()` on template INPUT destroys spintax config — only sanitise OUTPUT
- `$wpdb->get_col()` triggers PCP DirectDatabaseQuery warning — use `get_posts()` instead
- `meta_query` triggers PCP SlowDBQuery warning — add `phpcs:ignore` with justification
- Inline `/** @var type */` without short description — PHPCS requires multi-line doc block
- `$post_id`, `$role` in uninstall.php — prefix with `spintax_` to avoid GlobalVariablesOverride

## CI/CD (GitHub Actions)

- `ci.yml` — PHPCS on PHP 8.0-8.3, PHPUnit on PHP 8.0+8.2 × WP 6.2+latest, build ZIP. Runs on every push to main.
- `release.yml` — version validation (all 3 sources match the tag), PHPCS, build, GitHub Release with ZIP attached. Triggered by tag `v*`.
- `wporg-deploy.yml` — 10up action for WP.org SVN. Triggered by tag `v*`. Pushes `plugin/` → SVN trunk + `tags/<version>/` and `assets/` → SVN `/assets/`. Scoped to GitHub Environment `svn` (`environment: svn` in the workflow), which is where `SVN_USERNAME` (`301st`) and `SVN_PASSWORD` live. Repository-level secrets are NOT used — must be Environment-level. Manual SVN ops can use `.env.svn` (gitignored).

## Future work (post-2.0)

- ACF / post-meta bindings ✅ **DELIVERED in 2.0.0** (see Bindings section).
- WooCommerce integration — Phase 1 context vars ✅ **2.2.0**, Phase 2 pure Planner/TargetRegistry ✅ **2.3.0**, Phase 3 `woocommerce_product_field` write targets ✅ **2.4.0** (`docs/spec-woocommerce-phase3.md`). **Next: Phase 4 term targets / Phase 5 slugs (deferred, no trigger yet).**
- ACF repeater / flexible_content row-level binding rendering (V2 — no trigger yet)
- Block-editor inline editing of `per_post` source (V1 is metabox-only)
- REST API surface for bindings (V1 admin-only)
- Per-binding locale picker (currently inherits site locale)
- ACF Pro `acf_register_field_setting` checkbox in the ACF field UI
- Visual diff in Test panel (current vs rendered)
- Gutenberg block (V2)
- Template taxonomy (V2)
- `#const` (correlated constants from GTW — V2 engine primitive)

V2 items above are locked-but-deferred — promote on real user signal, do not preemptively build.
