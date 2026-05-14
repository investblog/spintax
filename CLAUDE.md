# Spintax — WordPress Plugin

## Project overview

Free WordPress plugin for spintax-based content generation. Target audience: content managers and SEO specialists. Rewrite of old plugin "nested-spintax-for-acf" with a GTW-derived engine extended with plugin-original conditionals and plural agreement.

- **GitHub:** https://github.com/investblog/spintax
- **WP.org:** https://wordpress.org/plugins/spintax/
- **Docs / playground:** https://spintax.net
- **Author:** 301st (https://301.st)
- **Current version:** 2.1.0 (unreleased — on main, awaiting Plugin Check + ACF smoke + fresh-eyes gate before tag)
- **Status:** **Live on WordPress.org through 2.0.3** (as of 2026-05-13). 2.1.0 is a major admin UX overhaul prepared on `main` and pending release gates per `docs/release-checklist.md`. 2.0.0 shipped the ACF / post-meta bindings feature in five phases per `docs/spec-acf-bindings.md`; 2.0.1 fixed 5 reviewer findings (2 P1, 3 P2) plus a form-field name collision; 2.0.2 documented Action Scheduler dependency + added an admin notice; 2.0.3 closed two follow-up reviewer findings (runtime ACF `target.field_key` re-verification via `acf_get_field()` + cumulative-failure tracking across Bulk Apply chunks, with a per-binding walk lock to prevent concurrent walks racing on the cumulative flag). 2.1.0 layers a UX overhaul on top: Logs admin page (closes the "check logs" notice gap), AdminNotice trait extended for action-link CTAs, Spintax Settings now also reachable from the spintax CPT submenu with TTL preset / custom select on both Settings + per-template meta box, Bindings form refactored into 3 ARIA tabs with full keyboard nav and PRG-survivable `active_tab` contract, ACF combobox replacing the broken `<datalist>` picker, server-side ACF row hidden for `kind=post_meta` to kill the flash, dismissible AS notice via user_meta + AJAX, inline "this binding will never run" warning + persisted (not flash-merged) stale banner with inline Bulk Apply, "Run now" sync button gated on `manage_options + (debug || !AS)`, walk-status badge from `_spintax_binding_walk_lock_<id>` with 1h orphan TTL. Engine timeline: 1.0.0 → 1.1.0 → 1.4.0 → 1.5.0 → 2.0.0 → 2.0.1 → 2.0.2 → 2.0.3 → 2.1.0.

## Reference sources

- **GTW syntax reference:** `docs/gtw-syntax-reference.md`
- **Product spec:** `docs/spec-v1.md`
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
      Validators.php             # Data normalisation helpers + 4-tier binding-key guard
    Bindings/                    # ACF / post-meta bindings (2.0.0)
      BindingApplier.php         # §4.4 decision tree (9 return codes) + ACF write helpers
      BindingResolver.php        # template-mode / per_post-mode source lookup
      BindingsRepo.php           # CRUD over single autoloaded option, fires saved/deleted actions
      BulkApply.php              # Action Scheduler walker + WP-CLI fallback path
      Defaults.php               # Default binding shape + MAX_BINDINGS=200, chunk_size constants
      Migration.php              # one-shot import from nested-spintax-for-acf
      Triggers/
        SavePostTrigger.php      # save_post priority 20 — after ACF p10
        TemplateCascadeTrigger.php # bumps per-binding cache version on template edit
        CronTrigger.php          # per-binding wp_schedule_event hooks
    CLI/
      BindingsCommand.php        # wp spintax bindings list|apply|test|export|import
    Core/Variables/              # Variable sources consumed by BindingApplier
      PostContextSource.php      # %post_id%, %post_title%, etc.
      AcfSiblingsSource.php      # %acf_<name>% for same-group siblings (top-level only)
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
- **Runtime ACF target validation (2.0.3)** — after the scope filter, `plan()` re-verifies `target.field_key` for `kind=acf_field` via `acf_get_field()`. Returns `SKIP_ACF_NOT_LOADED` when ACF isn't loaded (save layer accepts ACF bindings while ACF is inactive so they survive deactivation cycles; the applier short-circuits during such intervals rather than falling back to raw post_meta writes) or `SKIP_INVALID_ACF_FIELD` when the key resolves to a field whose name disagrees with `target.key` (renamed/deleted/foreign key). Brings total return codes to 13. `read_target` and `write_target` no longer have silent post-meta fallbacks — they're called only after the runtime guard has cleared the target.
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

## Spintax syntax

GTW-original primitives plus plugin extensions (`{?…?}` conditionals since 1.4.0, `{plural …}` since 1.5.0).

- `{a|b|c}` — enumeration: pick one. Nesting: `{a|{b|c}}`. Empty options: `{|a|b}`.
- `[a|b|c]` — permutation: pick N, shuffle, join.
  - `[< and > a|b]` — single separator
  - `[<minsize=2;maxsize=3;sep=", ";lastsep=" and "> a|b|c]` — configured
  - `[<, > a|b < and >|c]` — per-element separator: `< sep >` before `|` assigns custom separator to the next element; travels with element during shuffle
- `%var%` — variable reference (case-insensitive)
- `#set %var% = value` — local variable (value can contain spintax)
- `{?VAR?then|else}` — conditional (1.4.0): render `then` if `VAR` is truthy (set + non-whitespace), else `else` (else branch optional). Inverted form `{?!VAR?then|else}`. Resolved both before and after `%var%` expansion.
- `{plural <count>: form1|form2|form3}` — plural agreement (1.5.0): pick form by count's grammatical bucket. RU/UK/BE = 3 forms (one\|few\|many); EN-style default = 2 forms (one\|many). Count is `%var%` or literal integer (post-`expand_variables`). Form slot REJECTS nested spintax brackets `{` `}` `[` `]` — extract via `#set` first. Lenient at runtime: malformed constructs render verbatim with fullwidth braces (U+FF5B / U+FF5D) so a single bad block doesn't crash the page. Locale from template post meta `_spintax_locale` or site WP locale.
- `/#...#/` — block comments (stripped)
- `#include "slug-or-id"` — embed another template (GTW-compatible)

## Key design decisions

- **No transients** — WP Object Cache API only (no DB pollution)
- **Scope isolation** — child templates inherit global+runtime vars, NOT parent's #set locals
- **[spintax] shielding** — shortcodes inside templates are placeholder-shielded before permutation resolution to avoid bracket conflicts
- **Preview uses editor content** — AJAX sends textarea value, NOT saved DB content
- **No wp_kses_post on input** — template source is raw spintax, sanitisation only on render OUTPUT
- **minsize/maxsize defaults** — if only maxsize set, minsize=1 (not total). If only minsize set, maxsize=total.
- **Auto-spacing for word separators** — purely alphabetic separators (`<и>`, `<and>`, `<до>`) are auto-padded with spaces in `join_with_separators`. Punctuation separators (`,`, `;`) are NOT padded (post-processing handles them).
- **Per-element separator priority** — customSep > lastsep > sep. HTML tags distinguished from separators by checking for `/`, self-closing `/`, or attributes after tag name.

## Post-processing pipeline (Parser::post_process)

Order matters — incorrect sequencing causes domain/email corruption.

1. **Shield** URLs, emails, bare domains (ASCII+punycode+IDN), decimals, multi-dotted abbreviations (`т.д.`), single-token abbreviations from a curated whitelist (`соц.`, `Mr.`, `Inc.`, …) → placeholders
2. **Collapse** duplicate spaces/tabs
3. **Remove** whitespace before punctuation
4. **Add** space after `,;:` and `.!?` where missing
5. **Capitalise** first letter (skip leading HTML tags)
6. **Capitalise** after `.!?…` (through HTML tags)
7. **Capitalise** after block-level HTML tags (`<p>`, `<h1>`–`<h6>`, `<li>`, `<div>`, etc.)
8. **Capitalise** after line breaks
9. **Restore** placeholders

## Global variables

Entered as raw `#set` syntax in Settings → Spintax textarea (not key-value table). Parsed by `Parser::extract_set_directives()`, validated with line-number errors on save. Stored as both raw text (for editor) and parsed pairs (for rendering).

## WP.org compliance checklist

All met for v1.5.0:
- PHPCS: 0 errors, 0 warnings
- Plugin Check: 0 errors, 0 warnings (test files excluded from ZIP via .distignore)
- CI: fully green (lint PHP 8.0–8.3, tests PHP 8.0+8.2 × WP 6.2+latest, build ZIP)
- Nonces on all forms/AJAX
- Capability checks on all admin actions
- Input sanitisation via Validators::sanitize_spintax() — wp_check_invalid_utf8, strip null bytes/control chars, normalize line endings
- Output escaping (wp_kses_post on render output)
- $wpdb->prepare() for direct queries
- ABSPATH guard on all PHP files
- SECURITY.md with responsible disclosure
- readme.txt: External Services, Privacy Policy, Screenshots, Credits, Upgrade Notice
- .distignore for 10up deploy action
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
npm run test:php           # PHPUnit — all tests must pass (currently 430 cases)
npm run lint:php           # PHPCS — 0 errors, 0 warnings
```

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
- ACF repeater / flexible_content row-level binding rendering (V2)
- Block-editor inline editing of `per_post` source (V1 is metabox-only)
- REST API surface for bindings (V1 admin-only)
- Per-binding locale picker (currently inherits site locale)
- Visual diff in Test panel (current vs rendered)
- Gutenberg block
- REST API, WP-CLI, Import/Export
- Template taxonomy
- `#const` (correlated constants from GTW)
- Rebrand demo template from Acme to 301.st promotional content
- **TS engine port** (in progress) — lives in `W:\projects\casino-platform\packages\core\utils\spintax.ts` + `spintax-plurals.ts`
  - Full parity with PHP Parser.php including per-element separators, auto-spacing, conditionals, and plural agreement
  - Mulberry32 PRNG with `hashCode(siteId)` seed for deterministic per-site variants
  - Tests: `spintax.test.ts` (~105) + `spintax-plurals.test.ts` (74), run via `npx tsx <file>`
  - **Phase 1** (done): port engine inline in casino-platform; plural primitive vendored both sides 2026-05-10
  - **Phase 2** (next): integrate into render pipeline — ui_strings, articles, anti-footprint
  - **Phase 3** (later): extract to this project as standalone CF Worker API
  - See: `W:\projects\casino-platform\ROADMAP.md` Phase 5c
