# Spintax — WordPress Plugin

## Project overview

Free WordPress plugin for spintax-based content generation. Slug `spintax` on WP.org (submitted, awaiting review). Target audience: content managers and SEO specialists. Rewrite of old plugin "nested-spintax-for-acf" with GTW engine syntax.

- **GitHub:** https://github.com/investblog/spintax
- **Plugin URI:** https://spintax.net
- **Author:** 301st (https://301.st)
- **Current version:** 1.0.1
- **Status:** All 7 implementation phases complete. WP.org review pending.

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
        Parser.php               # GTW-compatible recursive-descent parser
        Validator.php            # Static syntax analysis with line/column errors
      PostType/
        TemplatePostType.php     # CPT registration, block editor disabled
      Render/
        RenderContext.php        # Immutable variable scopes + call stack
        Renderer.php             # 12-stage pipeline with cache + dependency tracking
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
      Validators.php             # Data normalisation helpers
  assets/css/admin.css           # Native WP styles augmentation
  assets/js/admin.js             # Preview AJAX, copy shortcode, variables
```

## Spintax syntax (GTW original)

- `{a|b|c}` — enumeration: pick one. Nesting: `{a|{b|c}}`. Empty options: `{|a|b}`.
- `[a|b|c]` — permutation: pick N, shuffle, join.
  - `[< and > a|b]` — single separator
  - `[<minsize=2;maxsize=3;sep=", ";lastsep=" and "> a|b|c]` — configured
- `%var%` — variable reference (case-insensitive)
- `#set %var% = value` — local variable (value can contain spintax)
- `/#...#/` — block comments (stripped)
- `#include "slug-or-id"` — embed another template (GTW-compatible)

## Key design decisions

- **No transients** — WP Object Cache API only (no DB pollution)
- **Scope isolation** — child templates inherit global+runtime vars, NOT parent's #set locals
- **[spintax] shielding** — shortcodes inside templates are placeholder-shielded before permutation resolution to avoid bracket conflicts
- **Preview uses editor content** — AJAX sends textarea value, NOT saved DB content
- **No wp_kses_post on input** — template source is raw spintax, sanitisation only on render OUTPUT
- **minsize/maxsize defaults** — if only maxsize set, minsize=1 (not total). If only minsize set, maxsize=total.

## Post-processing pipeline (Parser::post_process)

Order matters — incorrect sequencing causes domain/email corruption.

1. **Shield** URLs, emails, bare domains (ASCII+punycode+IDN), decimals, abbreviations → placeholders
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

All met for v1.0.1:
- PHPCS: 0 errors (5 acceptable warnings)
- Plugin Check: passing (test files excluded from ZIP)
- Nonces on all forms/AJAX
- Capability checks on all admin actions
- Input sanitisation (raw spintax exempted with phpcs:disable + explanation)
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
npm run test:php           # PHPUnit — all tests must pass
npm run lint:php           # PHPCS — 0 errors, 0 warnings
```

Then on WP.org for releases:
1. Build ZIP: exclude tests/, phpunit.xml.dist, composer.*, .phpunit.result.cache
2. Run Plugin Check in wp-admin — 0 errors, 0 warnings
3. Bump version if uploading new ZIP: `npm run version:set -- X.Y.Z`

**Common traps:**
- `wp_kses_post()` on template INPUT destroys spintax config — only sanitise OUTPUT
- `$wpdb->get_col()` triggers PCP DirectDatabaseQuery warning — use `get_posts()` instead
- `meta_query` triggers PCP SlowDBQuery warning — add `phpcs:ignore` with justification
- Inline `/** @var type */` without short description — PHPCS requires multi-line doc block
- `$post_id`, `$role` in uninstall.php — prefix with `spintax_` to avoid GlobalVariablesOverride

## CI/CD (GitHub Actions)

- `ci.yml` — PHPCS on PHP 8.0-8.3, PHPUnit on PHP 8.0+8.2 × WP 6.2+latest, build ZIP
- `release.yml` — version validation (all 3 sources), PHPCS, build, GitHub Release
- `wporg-deploy.yml` — 10up action for WP.org SVN (needs SVN_USERNAME/SVN_PASSWORD secrets)

## Future work (not in v1)

- ACF / post meta mapping (wpci-style) — highest priority for migration
- Gutenberg block
- REST API, WP-CLI, Import/Export
- Template taxonomy
- `#const` (correlated constants from GTW)
- Rebrand demo template from Acme to 301.st promotional content
- **Standalone API Worker** — after TS engine is battle-tested in casino-platform
  - Native TS port lives in `W:\projects\casino-platform\packages\core\utils\spintax.ts` first
  - Once stable, extract into this project as a standalone CF Worker with HTTP API
  - API: `POST /resolve { template, variables, seed }` → resolved text
  - Serves as public spintax-as-a-service for WP plugin REST API and third-party consumers
  - See: `W:\projects\casino-platform\ROADMAP.md` Phase 5c
