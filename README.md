# Spintax

[![WordPress Plugin Version](https://img.shields.io/wordpress/plugin/v/spintax.svg)](https://wordpress.org/plugins/spintax/)
[![License](https://img.shields.io/badge/license-GPL--2.0%2B-green.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-purple.svg)](https://php.net)
[![WordPress](https://img.shields.io/badge/WordPress-6.2%2B-blue.svg)](https://wordpress.org)

Spintax templates plus ACF / post-meta bindings, Logs, and WP-CLI — generate dynamic content at scale on WordPress.

**[Install from WordPress.org](https://wordpress.org/plugins/spintax/)** · **[Docs & playground at spintax.net](https://spintax.net)**

Spintax has two halves. The first is a content-generation **engine** with the GTW-derived spintax markup (enumerations, permutations, conditionals, plural agreement) that you embed inline via `[spintax]` shortcode or `spintax_render()`. The second is a **bindings layer** that ties a template to an ACF or post-meta field on a post type — once configured, every matching post gets its own rendered variant on save, on a cron, or on demand. There's a Logs page, a full WP-CLI surface, and manual-edit detection on the binding side.

## Features

### Spintax engine

- **Enumerations** `{a|b|c}` — randomly pick one option, with unlimited nesting
- **Permutations** `[<config>a|b|c]` — pick N elements, shuffle, join with custom separators
- **Variables** `%var%` — global, local (`#set`), and shortcode-level scopes
- **Conditionals** `{?VAR?then|else}` — render a branch based on whether a variable is set (also inverted `{?!VAR?then}`)
- **Plural agreement** `{plural <count>: form1|form2|form3}` — pick the grammatically correct noun form by count. RU/UK/BE 3-form, EN-style 2-form. First spintax engine with first-class plurals.
- **Nested templates** — embed templates via `#include` or `[spintax]` shortcode
- **Object cache** — rendered output cached via WP Object Cache API (Redis/Memcached ready), configurable TTL with presets (no caching / hourly / 6h / daily / weekly / monthly / custom seconds)
- **Cron regeneration** — optional scheduled cache refresh per template
- **Validation** — bracket matching, circular reference detection, syntax checking on save
- **Live preview** — renders current editor content without saving

### Bindings layer (`Spintax → Bindings`)

- **ACF & post-meta targets** — bind a template (or per-post inline source) to any ACF text / textarea / wysiwyg field or plain post-meta key on a post type. ACF Free and Pro both supported.
- **Triggers** — fire on post save, or run on a per-binding cron (`hourly` / `twicedaily` / `daily`)
- **Bulk Apply** — async chunked walks via Action Scheduler when installed; one-click admin button
- **Run now** — synchronous walk for admins, recommended fallback when Action Scheduler isn't available
- **Manual-edit protection** — each binding hashes its last rendered value; targets touched outside the binding are detected and skipped
- **Stale-source banner** — when a template you've bound to is edited, the affected bindings surface a "stale" badge and inline re-run controls
- **Logs page** (`Spintax → Logs`) — newest-first ring buffer of walk completions, skips, warnings; level filter + substring search + pagination
- **WP-CLI** — `wp spintax bindings list | apply | test | export | import` for staging→production sync and CI workflows
- **Per-binding variable scopes** — global `#set`, per-binding overrides, opt-in post context (`%post_id%`, `%post_title%`, …) and ACF siblings (`%acf_<field_name>%`)
- **Migration helper** — one-shot import from the predecessor plugin `nested-spintax-for-acf` at `Tools → Spintax Migration`

Full syntax reference and live playground at **[spintax.net](https://spintax.net)**.

## Quick Start

### 1. Create a template

Go to **Spintax → Add New** in the WordPress admin:

```
#set %product% = {Widget|Gadget|Tool}
#set %features% = [<minsize=2;maxsize=3;sep=", ";lastsep=" and "> fast|reliable|affordable|portable]

{Introducing|Meet} the %product% — it is %features%.
```

### 2. Embed it

**Shortcode** (in posts/pages):
```
[spintax slug="my-template"]
[spintax slug="my-template" product="Custom Name"]
```

**PHP** (in theme files):
```php
echo spintax_render( 'my-template', [ 'product' => 'Custom Name' ] );
```

### 3. Output

Rendered output is cached per (template × runtime context) using the WordPress Object Cache API. Visitors see the same generated variant until the cache expires or you regenerate it — bump the version by editing the template, hitting "Regenerate Public Cache" in the meta box, or relying on the configured TTL. Two successive cache fills against the same template + context might look like:

> Meet the Gadget — it is reliable and portable.

> Introducing the Widget — it is fast, affordable and reliable.

For bound ACF / post-meta fields, the rendered output is stored on the post itself — it's a one-shot pre-generation, not a render-on-read layer. Re-running the binding (save, cron tick, Bulk Apply, or Run now) produces a fresh variant.

## Syntax Reference

| Syntax | Description | Example |
|--------|-------------|---------|
| `{a\|b\|c}` | Enumeration — pick one | `{Hello\|Hi\|Hey}` |
| `{a\|{b\|c}}` | Nested enumeration | `{big\|{very\|super} big}` |
| `{\|a\|b}` | Empty option | sometimes nothing |
| `[a\|b\|c]` | Permutation — all, shuffled | `[x\|y\|z]` → `y z x` |
| `[<sep> a\|b]` | Custom separator | `[< and > a\|b]` → `b and a` |
| `[<config> a\|b\|c]` | Configured permutation | `[<minsize=2;sep=", "> a\|b\|c]` |
| `%var%` | Variable reference | `Hello %name%!` |
| `#set %var% = val` | Local variable | `#set %color% = {red\|blue}` |
| `{?VAR?then\|else}` | Conditional branch | `{?name?Hi %name%\|Hi there}` |
| `{?!VAR?then}` | Inverted conditional | `{?!name?Anonymous}` |
| `{plural N: f1\|f2\|f3}` | Plural agreement | `%count% {plural %count%: товар\|товара\|товаров}` |
| `/#...#/` | Block comment | `/# note #/` |
| `#include "slug"` | Embed template | `#include "header"` |

## Configuration

**Settings → Spintax:**

- **Global Variables** — `#set` syntax textarea, available to all templates
- **Default Cache TTL** — preset (no caching / 1h / 6h / 1d / 1w / 1mo) or custom seconds
- **Access Control** — allow editors to manage templates
- **Debug Mode** — turn on the Logs page entries for renders and binding walks
- **Max log entries** — ring-buffer size for `Spintax → Logs`

**Per-template (meta boxes):**

- Cache TTL override (same preset / custom selector as the global default)
- Cron schedule (hourly / twicedaily / daily)
- Regenerate public cache
- Live preview with validation

**Bindings (`Spintax → Bindings`):**

- Per-binding source (template or per-post inline), target (ACF field / post-meta key), triggers, and behavior flags (`auto_seed_empty` / `regenerate_on_save` / `preserve_manual_edits` / `clear_on_empty`)
- Test panel runs `BindingApplier::plan()` against one post and shows what would be written
- Hard cap of 200 bindings per site (single autoloaded option budget)

## Development

```bash
npm run env:start              # Start wp-env (localhost:8892)
npm run test:php               # PHPUnit
npm run lint:php               # PHPCS (0 errors, 0 warnings required)
npm run version:set -- X.Y.Z   # Bump version in plugin header, SPINTAX_VERSION, and readme.txt Stable tag
```

Release flow: bump version → commit → push to `main` (CI runs lint + tests + ZIP build) → smoke-test the user-facing surface that changed → tag `vX.Y.Z` (triggers a GitHub Release plus an SVN push to WordPress.org).

## Links

- **WordPress.org plugin page:** https://wordpress.org/plugins/spintax/
- **Documentation hub:** https://spintax.net/docs/
- **Live playground:** https://spintax.net/play/

## License

GPL-2.0+ — see [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html).

## Credits

Developed by [301st](https://301.st).
