# Spintax Product and Technical Specification v1.0

## 1. Document Status

This document is the pre-implementation baseline for the `Spintax` WordPress plugin.

Reference inputs:

- Current project workspace: `W:\Projects\spintax`
- Java reference engine: `W:\spintax-java` (`spinacf-backend`, v1.2.5)
- Old WordPress plugin for lessons learned only: `C:\Users\Admin\Local Sites\testcom\app\public\wp-content\plugins\nested-spintax-for-acf`

Reference policy:

- Java is the semantic reference for the text transformation pipeline.
- The old WordPress plugin is a UX and anti-pattern reference, not a syntax or architecture reference.
- When the old plugin and the Java engine disagree, Java wins unless this document explicitly defines a new v1 behavior.

Target platform:

- WordPress 6.2+
- PHP 8.0+
- Single-site WordPress in v1

---

## 2. Product Summary

`Spintax` is a free WordPress plugin for template-based content generation using spintax markup. Templates are stored as standalone custom posts and can be rendered from post/page content through a shortcode or from PHP through a helper function.

Primary users:

- Content managers
- SEO specialists
- Theme and plugin developers

Core promise:

- Write reusable spintax templates once
- Render one resolved variant on demand
- Cache that resolved variant for a configurable time
- Reuse the same template safely across the site

---

## 3. v1 Scope

### 3.1 In Scope

- Custom Post Type `spintax_template`
- Template editor with code-oriented editing experience
- Shortcode rendering in posts and pages
- PHP rendering helper: `spintax_render( $id_or_slug, $vars = [] )`
- Global variables in plugin settings
- Local variables via `#set`
- Nested template rendering via `[spintax ...]` inside template bodies
- Validation on save
- Preview in admin
- Public cache with per-template TTL override
- Optional per-template WP-Cron regeneration
- Role-based access for template management
- i18n-ready strings with text domain `spintax`

### 3.2 Explicitly Out of Scope for v1

- Gutenberg block
- ACF or post meta mapping
- REST CRUD API for templates
- Import/export
- GraphQL integration
- WP-CLI commands
- Template taxonomy
- Analytics/history of generated variants
- Commercial feature gating
- Multisite-specific behavior

---

## 4. Core Concepts

### 4.1 Templates

A template is a standalone entity stored as a CPT entry of type `spintax_template`.

- `post_title`: human-readable template name
- `post_name`: slug used for shortcode/PHP lookup
- `post_content`: raw spintax markup

Templates are not tied to ACF fields, posts, or pages in v1.

### 4.2 Render Context

Each render request operates inside a context made of:

- The target template
- Global variables from plugin settings
- Local variables defined in the template via `#set`
- Runtime variables passed through shortcode attributes or `spintax_render()`
- The current nested-template call stack for circular reference protection

### 4.3 Variable Scopes

Variable precedence is:

1. Global variables
2. Local variables from `#set`
3. Runtime variables from shortcode/PHP

Later scopes override earlier scopes.

### 4.4 Cached Variants

Caching is per template and per runtime input context.

This is required because the same template may be rendered with different shortcode variables on different pages. A cache keyed only by template ID would leak one page's runtime values into another page's output.

---

## 5. Supported Syntax

### 5.1 Enumeration (Replacement)

Enumeration chooses one option. Uses curly braces:

```text
{a|b|c}
```

Examples:

```text
The sky is {blue|grey|clear}.
{1X{S|s}lots}
{|онлайн|интернет} казино
{лицензией {|%LicenseNumber%} от %License%}
```

Rules:

- Nested enumerations are supported to arbitrary depth: `{a|{b|c}}`
- `|` only splits the current nesting level
- Empty options are valid: `{|a|b}` means "nothing or a or b"
- Resolution is from the innermost expression outward

### 5.2 Permutation (Transposition)

Permutation chooses `N` elements, shuffles them, and joins with separators. Uses square brackets:

Simple form (all elements, space-separated):

```text
[a|b|c]
```

Single separator form:

```text
[< и > a|b|c]
```

Configured form:

```text
[<minsize=2;maxsize=3;sep=", ";lastsep=" и "> a|b|c|d]
```

Per-element separator form:

```text
[<,> 1|2|3 < и >|4]
```

Interpretation:

- `[...]` is the permutation expression
- The optional `<...>` prefix inside `[` is the inline configuration block
- Per-element `<sep>` before `|` overrides the separator for that specific element

Supported config keys:

| Key | Meaning | Default |
| --- | --- | --- |
| `minsize` | Minimum selected items | all items |
| `maxsize` | Maximum selected items | number of options |
| `sep` | Separator between non-final items | `" "` |
| `lastsep` | Separator before the final item | same as `sep` |

Rules:

- `minsize` cannot exceed `maxsize`
- `maxsize` cannot exceed the number of available options
- Empty options are ignored
- Nested enumerations/permutations inside options are supported
- HTML elements inside options are supported: `[<minsize=3;> <li>item1</li>|<li>item2</li>]`

### 5.3 Variable References

Variables are referenced as:

```text
%var%
```

Rules:

- Variable names are case-insensitive in v1 storage and lookup
- Normalized storage form does not include `%`
- Variable values are stored raw and expanded lazily when referenced

### 5.4 Local Variable Definitions

Local variables are declared one per line:

```text
#set %greeting% = [Hello|Hi|Hey]
#set %city% = Moscow
```

Rules:

- `#set` must start at the beginning of a logical line
- The value may contain spintax
- `#set` lines are stripped from frontend output

### 5.5 Nested Templates

Templates may embed other templates via the plugin shortcode:

```text
[spintax id="123"]
[spintax slug="hero-text"]
[spintax slug="city-card" city="%city%"]
```

Rules:

- Only the `spintax` shortcode is executed inside template bodies in v1
- Nested calls inherit the current runtime variable context
- Explicit attributes on the nested shortcode override inherited runtime variables
- Circular references must be detected both at validation time where possible and at runtime always

### 5.6 Include (Template Embedding)

`#include` embeds another template by slug or ID. This is the GTW-compatible form of nested template rendering.

```text
#include "hero-text"
#include "123"
```

Rules:

- Value in quotes is resolved as template slug first, then as numeric ID
- `#include` does not support passing variables — it inherits the current runtime context
- For parameterized embedding, use the `[spintax slug="..." var="val"]` shortcode form instead
- `#include` lines are replaced with the rendered output of the referenced template
- Circular reference detection applies the same way as for nested `[spintax]` shortcodes

Rationale: GTW users author templates in the desktop editor using `#include "file.txt"`. Supporting `#include` in WordPress with slug-based resolution provides full GTW workflow compatibility — files become CPT entries.

### 5.8 Comments

Comments are stripped from template output:

```text
/# This is a comment
   spanning multiple lines #/
```

Rules:

- Start delimiter: `/#`
- End delimiter: `#/`
- Comments cannot be nested
- Comments are removed before any other processing

Additionally, HTML-style section markers are commonly used in templates and pass through as-is (stripped by browser):

```text
<--// Section Title //-->
```

### 5.9 Not Supported in v1

- `#const` (correlated constants from GTW — may be added post-v1 if demand exists)
- Links syntax (`URL [kw1; kw2]`) — same result achievable via variables: `#set %link% = <a href="url">{kw1|kw2}</a>`. Document as a recipe.
- Synonym dictionaries
- Shingles (repetition filtering)
- Raw spintax in normal post/page content outside template CPT content

---

## 6. Embedding and Public API

### 6.1 Shortcode

Supported forms:

```text
[spintax id="123"]
[spintax slug="my-template"]
[spintax id="123" city="Moscow" name="John"]
```

Rules:

- Exactly one of `id` or `slug` is required
- Additional attributes become runtime variables
- WordPress lowercases shortcode attribute names before binding
- Frontend rendering only uses published templates

### 6.2 PHP API

```php
$html = spintax_render( 123 );
$html = spintax_render( 'my-template', [ 'city' => 'Moscow' ] );
```

Rules:

- First argument accepts template ID or slug
- Second argument is an associative array of runtime variables
- The helper returns a string and does not echo directly
- The PHP helper and shortcode share the same rendering pipeline

---

## 7. Rendering Pipeline

The PHP implementation should match the Java reference pipeline semantically while using WordPress-native architecture.

### 7.1 Ordered Stages

1. Resolve the template by ID or slug
2. Load raw `post_content`
3. Parse local `#set` definitions and strip them from the visible template body
4. Build the effective variable context:
   - global settings
   - local `#set`
   - runtime variables
5. Expand `%var%` references lazily with recursion and cycle guards
6. Resolve enumeration expressions `{...}` from the innermost level outward
7. Resolve permutation expressions `[...]` from the innermost level outward
8. Resolve `#include` directives and nested `[spintax ...]` shortcodes in the already-selected output branches only
9. Apply lightweight formatting cleanup and sentence correction
10. Sanitize the final HTML for frontend output
11. Store or refresh cache if caching is enabled

### 7.2 Important Semantics

- Variable values may themselves contain spintax and are expanded only when used
- Nested templates are rendered after branch selection, so unused branches do not trigger nested renders
- The parser must use a nesting-aware approach, not regex-only iteration for the main grammar
- Any iteration/recursion safety limit must produce an explicit error path and log entry, never a silent bailout

### 7.3 Sentence and Whitespace Correction

v1 keeps this stage intentionally lightweight and close to the Java reference.

Guaranteed goals:

- Remove obviously broken spacing around punctuation
- Collapse accidental duplicate spaces
- Uppercase the first letter of the text
- Uppercase the first letter after sentence-ending punctuation and line breaks where safe

Non-goal:

- Heavy natural-language normalization

### 7.4 Runtime Error Behavior

If rendering fails:

- Log the failure when `WP_DEBUG` or plugin debug mode is enabled
- Return the last known cached value if a stale cache entry exists
- Otherwise return an empty string on the frontend
- In admin preview, show the error message in a controlled UI panel

---

## 8. Caching and Regeneration

### 8.1 Effective TTL

TTL resolution order:

1. Template-level override
2. Global default from settings

Special case:

- `0` means no persistent caching

### 8.2 Cache Key Strategy

Cache keys must include:

- A global cache salt/version
- Template ID
- Template cache version
- Runtime context hash

Runtime context hash is based on normalized runtime variables passed by shortcode/PHP, not on global/local variables. Global and local changes invalidate caches through version bumps instead.

Example shape:

```text
spintax_{global_salt}_{template_id}_{template_version}_{context_hash}
```

### 8.3 Why Versioned Keys Are Required

WordPress transients do not support reliable wildcard deletion. Versioned keys let the plugin invalidate caches safely by bumping versions instead of scanning the database for every possible key.

### 8.4 Invalidation Rules

The following actions invalidate public caches:

- Saving a template
- Clicking the public "Regenerate" button
- Changing global variables
- Changing global settings that affect rendering or caching, such as the default TTL
- Uninstalling the plugin

When a template changes, any parent template that embeds it must also be invalidated.

### 8.5 Default vs Contextual Cache Entries

- The default render context is the render with no runtime variables
- Contextual renders are keyed by runtime variable hash
- v1 stores no historical archive of old variants; only active cache entries for the current versions exist

### 8.6 Manual Regeneration

The edit screen has two different actions:

- `Regenerate public cache`: invalidates the template's public cache version and warms the default context
- `Regenerate preview`: renders a fresh preview without touching public cache

Manual public regeneration should bypass child caches for that single regeneration request so the new public variant reflects a fresh full subtree render.

### 8.7 Cron Regeneration

Per-template cron is optional.

Supported schedules in v1:

- disabled
- hourly
- twicedaily
- daily

Behavior:

- Cron is available only when effective TTL is greater than `0`
- Cron invalidates the current public cache version and warms the default context only
- Contextual cache entries remain lazy and are generated on demand
- Scheduling uses the WordPress site timezone

---

## 9. Validation

### 9.1 Save-Time Blocking Errors

The template must not save when any of the following is detected:

- Unbalanced or mismatched delimiters for `{}` and `[]`
- Malformed configured permutation syntax (invalid `<config>` inside `[...]`)
- Malformed `#set` declarations
- Circular template references that can be resolved from current IDs/slugs
- Self-referential variable loops that are statically detectable
- Nested `spintax` shortcode references that point to a non-existent template

Validation errors should point to line and column where possible.

### 9.2 Save-Time Warnings

These do not block save in v1:

- Variables that are not found in local/global scopes but may legitimately be supplied at runtime by shortcode or PHP

Reason:

- Blocking on all unresolved variables would make valid runtime-driven templates impossible to save

Warnings should still be shown in the editor and preview panel.

### 9.3 Preview Diagnostics

Preview should surface:

- Resolved output
- Blocking validation errors
- Non-blocking unresolved-variable warnings

### 9.4 Runtime Guards

Runtime must also detect and stop:

- Circular nested template calls
- Variable recursion loops
- Excessive parser/transform recursion

No guard may fail silently.

---

## 10. Admin UX

### 10.1 Template CPT

- Post type: `spintax_template`
- Menu label: `Spintax`
- Supports: title, editor
- Not public, no archive, no frontend single view
- No comments, taxonomies, featured image, or block editor UI

The editor must behave like a code editor, not a visual or block editor.

### 10.2 Edit Screen

Required areas:

- Title field
- Slug field managed by WordPress core
- Main template editor for raw spintax markup
- Cache settings meta box
- Preview meta box
- Usage meta box

Cache settings meta box:

- Template TTL override in seconds
- Cron schedule selector
- Public regenerate button

Preview meta box:

- One rendered preview variant
- `Regenerate preview` button
- Validation status
- Optional ad hoc preview variables input that is not persisted

Usage meta box:

- Copyable shortcode examples by ID and slug
- PHP helper example

### 10.3 List Table

Columns for v1:

- Title
- Slug
- Shortcode
- Effective TTL
- Cron schedule
- Last default regeneration time

### 10.4 Settings Page

Location:

- `Settings > Spintax`

Fields:

- Global variables editor (key/value rows)
- Default cache TTL
- Access control toggle: editors may manage templates
- Debug mode toggle
- Purge all cache button

Rules:

- Settings values are stored raw, validated on save, and translated into normalized runtime configuration
- Variable names are stored without `%`

### 10.5 Design Constraints

- Native WordPress admin UI only
- No CSS framework
- All labels/messages wrapped in translation functions with text domain `spintax`

---

## 11. Permissions and Security

### 11.1 Capabilities

Custom capability:

- `manage_spintax_templates`

Default mapping:

- Administrators: yes
- Editors: yes by default, removable via plugin setting

Rules:

- Template CPT screens and AJAX actions use `manage_spintax_templates`
- Settings page remains `manage_options`

### 11.2 Frontend Safety

- Frontend output is sanitized with a post-safe HTML policy such as `wp_kses_post()`
- Raw template source is never exposed to visitors
- Frontend failures return empty string or stale cache, never raw exceptions
- Only the plugin's own `[spintax]` shortcode is executed inside templates in v1

### 11.3 Admin Safety

- All save and AJAX actions must use nonces
- Template content is stored unsanitized to preserve author intent, but validated and sanitized on render
- Debug mode affects logging and diagnostics only, not visitor-visible output

---

## 12. Data Model

### 12.1 WordPress Storage

| Data | Storage | Key |
| --- | --- | --- |
| Template source | `wp_posts` | `post_type = spintax_template`, `post_content` |
| Template TTL override | `wp_postmeta` | `_spintax_cache_ttl` |
| Template cron schedule | `wp_postmeta` | `_spintax_cron_schedule` |
| Template cache version | `wp_postmeta` | `_spintax_cache_version` |
| Embedded template dependency list | `wp_postmeta` | `_spintax_embeds` |
| Last default regeneration timestamp | `wp_postmeta` | `_spintax_last_regenerated_at` |
| Global variables | `wp_options` | `spintax_global_variables` |
| Plugin settings | `wp_options` | `spintax_settings` |
| Global cache salt/version | `wp_options` | `spintax_cache_salt` |
| Cached output | transients | versioned key pattern |

### 12.2 Dependency Tracking

The plugin stores normalized embedded-template references per template so that parent caches can be invalidated when a child template changes.

### 12.3 Uninstall

On uninstall, the plugin removes:

- All `spintax_template` posts and their post meta
- All plugin options
- All plugin capabilities from roles
- All current cache entries by bumping/removing cache salt and deleting known transients where practical

Deactivation does not delete data.

---

## 13. Recommended PHP Architecture

The project should mirror the `wpci` layout:

- Root-level dev tooling
- Runtime plugin code inside `plugin/`
- PSR-4 classes in `plugin/src/`

Recommended high-level modules:

- `Spintax\Core\Plugin`
- `Spintax\Core\PostType\TemplatePostType`
- `Spintax\Core\Render\Renderer`
- `Spintax\Core\Render\RenderContext`
- `Spintax\Core\Engine\Parser`
- `Spintax\Core\Engine\Validator`
- `Spintax\Core\Cache\CacheManager`
- `Spintax\Core\Cache\DependencyInvalidator`
- `Spintax\Core\Settings\SettingsRepository`
- `Spintax\Core\Cron\CronManager`
- `Spintax\Core\Shortcode\ShortcodeController`
- `Spintax\Admin\TemplateEditor`
- `Spintax\Admin\MetaBoxes`
- `Spintax\Admin\SettingsPage`
- `Spintax\Support\Capabilities`
- `Spintax\Support\Logging`

Implementation notes:

- Keep bootstrap in `plugin/spintax.php` thin
- Prefer services with explicit responsibilities over one large manager class
- Keep parsing/transform logic framework-agnostic so it can be unit-tested without WordPress
- Match Java service semantics, not Java package structure

---

## 14. Testing and Acceptance

### 14.1 Unit Test Focus

- Bracket matching for nested `{}` and `[]`
- Enumeration resolution (curly braces)
- Permutation resolution with and without config (square brackets)
- Variable precedence and lazy expansion
- Variable recursion guards
- Nested template circular-reference guards
- Cache key normalization
- Dependency invalidation logic

### 14.2 WordPress Integration Test Focus

- CPT registration
- Capability mapping
- Shortcode rendering
- PHP helper rendering
- Save-time validation behavior
- Preview endpoint behavior
- Public cache regeneration
- Cron scheduling

### 14.3 Acceptance Criteria for v1

- Editors can manage templates when the setting allows it
- A published template renders via shortcode and PHP helper
- Runtime variables do not bleed across pages through shared cache
- Nested templates work and circular references are blocked
- Saving an invalid template shows a useful validation error
- Preview never mutates the public cache
- Regenerating a child template invalidates parents that embed it
- All user-facing strings are i18n-ready

---

## 15. Migration and Future Work

### 15.1 Migration Position

There is no automatic migration from `nested-spintax-for-acf` in v1.

Reason:

- Old plugin data model is ACF/meta-field centric
- New plugin data model is template centric
- The old plugin also contains algorithmic bugs that should not be preserved

### 15.2 Future Candidates

- Gutenberg block
- ACF/post meta mapping
- Import/export
- REST endpoints
- WP-CLI tooling
- Template analytics
- Revision/history for generated variants
