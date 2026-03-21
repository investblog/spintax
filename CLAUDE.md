# Spintax — WordPress Plugin

## Project overview

Free WordPress plugin for spintax-based content generation. Target audience: content managers and SEO specialists (with developer-friendly architecture). Rewrite of existing personal plugin "nested-spintax-for-acf" with the Java engine (spintax-java) as the reference algorithm.

## Reference sources

- **Existing WP plugin (old, buggy):** `C:\Users\Admin\Local Sites\testcom\app\public\wp-content\plugins\nested-spintax-for-acf`
- **Java spintax engine (reference algorithm):** `W:\spintax-java` — Spring Boot WAR (com.solut.tech.spinacf, v1.2.5)
- **Project structure template:** `W:\Projects\wpci` (images-sync-for-cloudflare plugin)

## Architecture decisions

- Project structure mirrors `wpci`: root dev tooling + `plugin/` directory with PSR-4 autoloaded `src/`
- Namespace: `Spintax\`, global prefix: `spintax_` / `SPINTAX_`
- PHP 8.0+, WordPress 6.2+
- wp-env for local dev (ports 8892/8893)
- PHPCS with WordPress coding standards
- PHPUnit via wp-env
- Free plugin, no feature gates. Commercial features — after traction.
- i18n: all UI strings wrapped in `__()` / `esc_html__()` with text domain `spintax`. No translation files in v1.0 — just the keys.

## Spintax syntax (Java engine — adopted as standard)

- `[a|b|c]` — replacement: randomly pick one option. Supports true nesting: `[a|[b|c]]`
- `<a|b|c>` — transposition (permutation): pick N elements, shuffle, join with separator
  - Config: `<minsize=N;maxsize=N;sep="str";lastsep="str">` inline before options
- `%var%` — variable reference
- `#set %var% = value` — variable definition (value can contain spintax)
- `#const` — NOT implementing (same functionality covered by variables)

## Templates — core concept

Templates are standalone entities (Custom Post Type `spintax_template`), not tied to specific fields or posts. A template contains spintax markup and can be embedded anywhere in the site.

### Embedding methods
- Shortcode only in posts/pages: `[spintax id="123"]` or `[spintax slug="my-template"]`
- Shortcode with inline variables: `[spintax id="123" city="Moscow"]` → `%city%` available in template
- Nested templates: a template can embed another via `[spintax id="..."]` inside its body
- PHP function: `spintax_render( $id_or_slug, $vars = [] )` — for theme developers
- Gutenberg block (future)
- Raw spintax syntax `[a|b|c]` lives ONLY inside CPT content — never in post/page content directly. No shortcode conflict.

### Generation & caching
- On first render: spin the template, cache the result (WP transients)
- Cache TTL: configurable per template (meta) with global default fallback (settings)
- Cached variant is shown to ALL visitors until TTL expires or manual purge
- Manual regeneration via admin button ("Regenerate" on template edit screen)
- Cron-based regeneration: optional, per-template schedule
- No history — just the current cached variant

### Variables — two scopes
- **Global variables:** site-wide, defined in plugin settings page. Available to all templates.
- **Local variables:** defined inside template via `#set %var% = value`. Override globals if same name.
- **Shortcode variables:** passed via shortcode attributes. Override both global and local.

### ACF / meta integration (future feature)
- Map template output to a post meta field or ACF field (wpci-style mapping)
- Not in v1.0 scope — templates + shortcodes first

## Admin UI

- Native WordPress styles only, no custom CSS frameworks. Professional look like top WP plugins.
- **Template CPT (`spintax_template`):**
  - Title: template name. Slug auto-generated from title, auto-resolve conflicts.
  - Editor: code editor (textarea) for spintax markup — NO visual/block editor
  - Meta boxes: Cache TTL override, Cron schedule, Regenerate button, Preview panel
  - Preview: shows one rendered variant with "Regenerate preview" button for a new spin
  - Validation on save: check bracket matching, undefined variables → block save with error message (like old plugin's `check_template_syntax_with_field` but done right)
  - Supports: title, editor, slug. No comments, no taxonomies, no featured image.
- **Settings page** (Settings > Spintax):
  - Global variables editor (key-value table)
  - Default cache TTL
  - Access control: allow editors to manage templates (default: yes, admins + editors)
  - Debug mode toggle

## Permissions

- Default: `manage_options` (admins) and `edit_others_posts` (editors) can manage templates
- Configurable in settings: checkbox to restrict to admins only
- Custom capability `manage_spintax_templates` mapped to roles based on setting

## Known bugs in old WP plugin (do NOT repeat)

- Spintax pattern `[^{}]*` doesn't support true nesting
- `minsize=1` forcibly reset to 2
- Dead code: `firstsep`, `parse_variables()`
- No content sanitization
- GraphQL filter with unindexed `LIKE` query
- Silent failures on iteration limit
- No caching
- No i18n

## Dev environment

- Windows 11, bash shell (Git Bash)
- PHP and Composer NOT installed system-wide — need to install for `composer install`
- Node.js available, npm dependencies installed (`@wordpress/env`)
- No git remote configured yet

## Commands

```bash
npm run env:start          # Start wp-env
npm run env:stop           # Stop wp-env
npm run wp:plugin:activate # Activate plugin
npm run lint:php           # PHPCS
npm run lint:php:fix       # Auto-fix PHPCS
npm run test:php           # PHPUnit via wp-env
```
