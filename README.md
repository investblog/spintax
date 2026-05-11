# Spintax

[![WordPress Plugin Version](https://img.shields.io/wordpress/plugin/v/spintax.svg)](https://wordpress.org/plugins/spintax/)
[![License](https://img.shields.io/badge/license-GPL--2.0%2B-green.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-purple.svg)](https://php.net)
[![WordPress](https://img.shields.io/badge/WordPress-6.2%2B-blue.svg)](https://wordpress.org)

Template-based dynamic content generation using spintax markup for WordPress.

**[Install from WordPress.org](https://wordpress.org/plugins/spintax/)** · **[Docs & playground at spintax.net](https://spintax.net)**

## Features

- **Enumerations** `{a|b|c}` — randomly pick one option, with unlimited nesting
- **Permutations** `[<config>a|b|c]` — pick N elements, shuffle, join with custom separators
- **Variables** `%var%` — global, local (`#set`), and shortcode-level scopes
- **Conditionals** `{?VAR?then|else}` — render a branch based on whether a variable is set (also inverted `{?!VAR?then}`)
- **Plural agreement** `{plural <count>: form1|form2|form3}` — pick the grammatically correct noun form by count. RU/UK/BE 3-form, EN-style 2-form. First spintax engine with first-class plurals.
- **Nested templates** — embed templates via `#include` or `[spintax]` shortcode
- **Object cache** — rendered output cached via WP Object Cache API (Redis/Memcached ready)
- **Cron regeneration** — optional scheduled cache refresh per template
- **Validation** — bracket matching, circular reference detection, syntax checking on save
- **Live preview** — renders current editor content without saving
- **Admin UI** — code editor, shortcode copy, settings page with global variables

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

Each page load produces a unique variant:

> Meet the Gadget — it is reliable and portable.

> Introducing the Widget — it is fast, affordable and reliable.

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
- **Default Cache TTL** — seconds (0 = no caching)
- **Access Control** — allow editors to manage templates
- **Debug Mode** — log rendering errors

**Per-template (meta boxes):**

- Cache TTL override
- Cron schedule (hourly / twicedaily / daily)
- Regenerate public cache
- Live preview with validation

## Development

```bash
npm run env:start              # Start wp-env (localhost:8892)
npm run test:php               # PHPUnit (309 tests)
npm run lint:php               # PHPCS (0 errors, 0 warnings)
npm run version:set -- X.Y.Z   # Bump version everywhere
```

## Links

- **WordPress.org plugin page:** https://wordpress.org/plugins/spintax/
- **Documentation hub:** https://spintax.net/docs/
- **Live playground:** https://spintax.net/play/

## License

GPL-2.0+ — see [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html).

## Credits

Developed by [301st](https://301.st).
