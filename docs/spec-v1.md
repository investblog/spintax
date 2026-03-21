# Spintax — Product Specification v1.0

## 1. Overview

**Spintax** — WordPress plugin for dynamic content generation using spintax templates. Templates are standalone entities (CPT) that can be embedded anywhere on the site via shortcodes or PHP functions. The plugin spins template content on first render, caches the result for a configurable period, and serves the cached variant to all visitors until TTL expires or manual/cron regeneration.

**Target audience:** content managers, SEO specialists, theme developers.

**License model:** free, no feature gates. Commercial features planned after traction.

**Requirements:** WordPress 6.2+, PHP 8.0+.

---

## 2. Spintax Syntax

Based on the Java engine (spintax-java v1.2.5). The old WP plugin syntax (`{}`/`[]`) is abandoned.

### 2.1 Replacement `[a|b|c]`

Randomly picks one option from pipe-separated alternatives.

```
The sky is [blue|grey|clear].
```

Supports true nesting to any depth:

```
I [like|love [very much|a lot]] this [city|town|[small|big] village].
```

### 2.2 Transposition `<options>`

Picks N elements, shuffles them, and joins with a separator. Inline configuration via semicolon-separated parameters before the options:

```
<minsize=2;maxsize=3;sep=", ";lastsep=" and ">apples|oranges|bananas|grapes
```

**Parameters:**
| Parameter | Default         | Description                        |
|-----------|-----------------|------------------------------------|
| `minsize` | 2               | Minimum number of elements to pick |
| `maxsize` | count of all    | Maximum number of elements to pick |
| `sep`     | `" "`           | Separator between elements         |
| `lastsep` | same as `sep`   | Separator before the last element  |

### 2.3 Variables `%var%`

Reference a variable by name. Variable resolution order (later overrides earlier):

1. **Global variables** — defined in plugin settings, available to all templates.
2. **Local variables** — defined inside the template via `#set`.
3. **Shortcode variables** — passed as shortcode attributes.

### 2.4 Variable Definition `#set %var% = value`

Defines a local variable. The value can contain spintax syntax (expanded on use).

```
#set %greeting% = [Hello|Hi|Hey]
#set %name% = [World|Everyone]
%greeting%, %name%!
```

`#set` lines are stripped from the output.

### 2.5 NOT Implementing

- `#const` — redundant, same functionality covered by `#set` with spintax values.

---

## 3. Templates

### 3.1 Custom Post Type

- **Slug:** `spintax_template`
- **Supports:** title, editor (plain textarea / code editor, NO block editor)
- **Public:** no (not visible on frontend, no archive, no single page)
- **Menu:** top-level admin menu item "Spintax"

### 3.2 Template Content

The post content (`post_content`) holds the raw spintax markup. This is the only place raw spintax syntax lives — never in regular post/page content.

### 3.3 Template Slug

Auto-generated from title, auto-resolve conflicts (WordPress default behavior). Used for referencing in shortcodes: `[spintax slug="my-template"]`.

---

## 4. Embedding

### 4.1 Shortcode

```
[spintax id="123"]
[spintax slug="my-template"]
[spintax id="123" city="Moscow" name="John"]
```

- `id` or `slug` — required (one of them)
- Any other attributes become shortcode variables (`%city%`, `%name%`)
- Shortcode variables override local and global variables

### 4.2 Nested Templates

A template body can contain `[spintax id="..."]` shortcodes to embed other templates. Circular references must be detected and reported as errors.

### 4.3 PHP Function

```php
echo spintax_render( 'my-template', [ 'city' => 'Moscow' ] );
echo spintax_render( 123 );
```

- First argument: template ID (int) or slug (string)
- Second argument: optional associative array of variables

### 4.4 Gutenberg Block

Not in v1.0 scope. Future feature.

---

## 5. Generation & Caching

### 5.1 Flow

1. Shortcode or `spintax_render()` is called.
2. Check transient cache for this template (key: `spintax_cache_{template_id}`).
3. If cached and not expired → return cached HTML.
4. If not cached or expired:
   a. Load template content from CPT.
   b. Merge variables: global → local (`#set`) → shortcode attributes.
   c. Process nested `[spintax]` shortcodes first (with circular reference guard).
   d. Run spintax engine: variable substitution → replacement `[]` → transposition `<>`.
   e. Post-process: cleanup spaces, capitalize sentences.
   f. Sanitize output.
   g. Store result in transient with TTL.
   h. Return HTML.

### 5.2 Cache TTL

- **Global default:** set in plugin settings (e.g. 3600 seconds = 1 hour).
- **Per-template override:** meta field `_spintax_cache_ttl`. Value `0` = no caching (spin on every pageview).

### 5.3 Cache Invalidation

- **Manual:** "Regenerate" button on template edit screen → deletes transient.
- **On save:** saving the template clears its cache.
- **Cron:** optional per-template WP-Cron schedule → deletes transient periodically.
- **Bulk:** "Purge all cache" button on settings page.

### 5.4 Cron Regeneration

- Per-template meta field `_spintax_cron_interval` (e.g. `hourly`, `twicedaily`, `daily`, or custom seconds).
- Empty/disabled = no cron. Template only regenerates on TTL expiry or manual action.
- Uses WP-Cron single events, rescheduled after each run.

---

## 6. Validation

### 6.1 On Save

When a template is saved, validate the spintax markup before persisting:

- **Bracket matching:** every `[` has a matching `]`, every `<` has a matching `>`.
- **Undefined variables:** warn if `%var%` is used but not defined in `#set` or global variables (warning, not blocking — shortcode vars can't be checked at save time).
- **Circular references:** if template embeds `[spintax id="..."]`, check for cycles.

If validation fails → block save, show admin notice with error details (line/position).

### 6.2 On Render

- If template not found → return empty string, log error if debug mode.
- If circular reference detected at runtime → return error message, log.
- If engine hits iteration limit → return partially processed text with error logged.

---

## 7. Admin UI

### 7.1 Design Principles

- Native WordPress admin styles only. No custom CSS frameworks.
- Professional look consistent with top WP plugins (Yoast, ACF, WooCommerce).
- All strings wrapped in `__()` / `esc_html__()` with text domain `spintax`. No translation files shipped in v1.0.

### 7.2 Template Edit Screen

- **Title:** template name
- **Editor:** plain textarea or CodeMirror for spintax markup (no TinyMCE, no Gutenberg)
- **Meta box "Cache Settings":**
  - Cache TTL input (seconds, 0 = disabled)
  - Cron interval dropdown (disabled / hourly / twicedaily / daily)
- **Meta box "Preview":**
  - Shows one rendered variant of the template
  - "Regenerate preview" button (AJAX) — spins a new variant without affecting the public cache
  - Displays validation errors if any
- **Meta box "Usage":**
  - Shows shortcode to copy: `[spintax slug="..."]`
  - Shows PHP snippet: `spintax_render('...')`
- **Publish box:** standard WP publish meta box

### 7.3 Template List Screen

Standard WP list table with columns:
- Title
- Slug
- Shortcode (copyable)
- Cache status (cached / expired)
- Last regenerated (date)

### 7.4 Settings Page (Settings > Spintax)

- **Global Variables:** key-value table with add/remove rows. Variable name (without `%%`) + value (can contain spintax).
- **Default Cache TTL:** input in seconds.
- **Access Control:** checkbox "Allow editors to manage templates" (default: checked).
- **Debug Mode:** checkbox. When enabled, errors are logged to `WP_DEBUG_LOG`.
- **Purge All Cache:** button to clear all spintax transients.

---

## 8. Permissions

- Custom capability: `manage_spintax_templates`
- Default mapping: granted to `administrator` and `editor` roles
- Configurable: settings checkbox restricts to `administrator` only
- Capability is added on plugin activation, removed on uninstall

---

## 9. Spintax Engine — Processing Pipeline

Ordered processing stages (mirrors Java engine architecture):

| Stage | Input | Output | Description |
|-------|-------|--------|-------------|
| 1. Parse `#set` | Raw template | Variables map + cleaned template | Extract local variables, strip `#set` lines |
| 2. Merge variables | Local + global + shortcode vars | Final variables map | Shortcode > local > global priority |
| 3. Variable substitution | Template + variables | Template with vars expanded | Replace `%var%` references, max 50 iterations |
| 4. Replacement `[a\|b\|c]` | Template | Template with replacements resolved | Recursive from innermost, true nesting support |
| 5. Transposition `<a\|b\|c>` | Template | Template with transpositions resolved | Pick N, shuffle, join with separators |
| 6. Post-processing | Expanded text | Final text | Cleanup spaces, sentence capitalization |

### 9.1 Nesting Strategy

Process from innermost brackets outward. Use recursive descent or stack-based parser — NOT regex iteration with arbitrary limit.

### 9.2 Post-Processing

- Remove extra spaces before punctuation: ` ,` → `,`
- Ensure space after punctuation: `.Word` → `. Word`
- Collapse multiple spaces
- Capitalize first letter after sentence-ending punctuation (`. `, `! `, `? `)
- Capitalize after opening `<p>`, `<h1>`–`<h6>` tags
- Preserve domains (`example.com`) and decimal numbers (`3.14`) during capitalization

---

## 10. Error Handling

- All errors logged via `error_log()` when WP_DEBUG or plugin debug mode is on.
- Admin notices for save-time validation errors (bracket mismatch, circular refs).
- Frontend: never expose raw spintax or error messages to visitors. Return empty string or last cached variant on failure.
- Engine exceptions caught and logged, never bubble to frontend.

---

## 11. Data Storage

| Data | Storage | Key/Meta |
|------|---------|----------|
| Template content | `wp_posts` (CPT `spintax_template`) | `post_content` |
| Cache TTL override | `wp_postmeta` | `_spintax_cache_ttl` |
| Cron interval | `wp_postmeta` | `_spintax_cron_interval` |
| Cached output | WP transients | `spintax_cache_{id}` |
| Global variables | `wp_options` | `spintax_global_variables` |
| Plugin settings | `wp_options` | `spintax_settings` |

---

## 12. Uninstall

On plugin deletion (not deactivation):
- Remove all `spintax_template` posts and their meta
- Remove options: `spintax_global_variables`, `spintax_settings`
- Remove all transients matching `spintax_cache_*`
- Remove custom capabilities from all roles

---

## 13. Future Features (NOT in v1.0)

- Gutenberg block for template embedding
- ACF / post meta field mapping (wpci-style)
- REST API for template CRUD and rendering
- WP-CLI commands
- Import/Export templates
- Bulk generation
- Template categories/tags
- Usage analytics (which templates, how often)
