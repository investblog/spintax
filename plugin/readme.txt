=== Spintax ===
Contributors: 301st
Tags: spintax, content generation, templates, seo, dynamic content
Requires at least: 6.2
Tested up to: 6.9
Stable tag: 1.0.1
Requires PHP: 8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Template-based dynamic content generation using spintax markup for WordPress.

== Description ==

Spintax is a WordPress plugin for template-based content generation using spintax markup. Create reusable templates with randomised text variants, variable substitution, and permutation logic — then embed them anywhere on your site via shortcodes or PHP.

**Key features:**

* **Enumerations** `{a|b|c}` — randomly pick one option, with nesting support
* **Permutations** `[<config>a|b|c]` — pick N elements, shuffle, join with custom separators
* **Variables** `%var%` — global, local (`#set`), and shortcode-level variable scopes
* **Nested templates** — embed templates within templates via `#include` or `[spintax]`
* **Object cache** — rendered output cached via WP Object Cache API (Redis/Memcached ready)
* **Cron regeneration** — optional scheduled cache refresh per template
* **Validation** — bracket matching, circular reference detection, syntax checking
* **Admin UI** — code editor, live preview, shortcode copy, settings page

**Syntax based on the GTW (Generating The Web) standard.**

== Installation ==

1. Upload the `spintax` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Create templates under the "Spintax" menu in the admin sidebar
4. Embed templates using `[spintax slug="my-template"]` in posts/pages or `spintax_render('my-template')` in theme files

== Frequently Asked Questions ==

= How do I create a template? =

Go to Spintax > Add New in the WordPress admin. Enter a title and your spintax markup in the editor.

= What syntax does the plugin use? =

* `{a|b|c}` — randomly picks one option
* `[a|b|c]` — permutation: picks N elements, shuffles, joins with space
* `[<minsize=2;maxsize=3;sep=", ";lastsep=" and "> a|b|c|d]` — configured permutation
* `%variable%` — variable reference
* `#set %var% = value` — local variable definition
* `/#comment#/` — block comment (stripped from output)
* `#include "slug"` — embed another template

= Does caching require Redis or Memcached? =

The plugin uses the WordPress Object Cache API. With a persistent backend (Redis, Memcached), cached output persists across requests. Without one, templates are re-rendered on each page load.

= Can I pass variables through shortcodes? =

Yes: `[spintax slug="greeting" name="Alice" city="Moscow"]` makes `%name%` and `%city%` available inside the template.

== Screenshots ==

1. Template editor with spintax markup and live preview.
2. Settings page with global variables editor.
3. Template list with shortcode, cache status, and cron schedule.

== External services ==

This plugin does **not** connect to any external services, APIs, or third-party servers.

All content generation happens locally on your WordPress server. No data is sent externally. No remote requests are made during activation, rendering, or caching.

== Privacy Policy ==

This plugin does not collect, store, or transmit any personal user data. It does not use cookies, tracking pixels, analytics, or any form of telemetry.

Templates and their rendered output are stored entirely within your WordPress database and object cache.

== Credits ==

* Syntax based on the [GTW (Generating The Web)](https://spintax.net) standard
* Developed by [301st](https://301.st)

== Changelog ==

= 1.0.1 =
* Fix: permutation minsize/maxsize logic when only one parameter is specified
* Fix: preview rendering no longer strips spintax config from template input
* Fix: child templates no longer inherit parent's local #set variables
* Improve: global variables editor now uses #set textarea (paste full blocks)
* Improve: validation errors displayed on template edit screen with line numbers
* Improve: "Regenerate Public Cache" now forces fresh subtree render
* Add: demo template created on first activation
* Add: SECURITY.md with responsible disclosure policy
* Add: Privacy Policy and External Services sections in readme.txt
* Code: PHPCS 0 errors, full WP.org review compliance

= 1.0.0 =
* Initial release
* GTW-compatible spintax engine with nested enumerations and permutations
* Template CPT with code editor and admin preview
* Shortcode and PHP rendering API
* Object cache with versioned keys and cascade invalidation
* Per-template cron regeneration
* Global and local variable scopes
* Settings page with global variables editor

== Upgrade Notice ==

= 1.0.1 =
Fixes permutation config handling, preview rendering, and scope isolation. Recommended update.

= 1.0.0 =
Initial release.
