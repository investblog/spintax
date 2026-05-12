# Spec — ACF / Post-meta Bindings

**Status:** revised post-review 2026-05-12, Phase-1-ready.
**Target version:** 2.0.0.
**Author:** 301st.
**Last updated:** 2026-05-12 (post-review revision).

**Review history:**
- 2026-05-12: draft v1 written; reviewed by fresh-eyes agent pass; revision incorporated all high/medium-severity findings (signature-key by binding_id, 4-tier reserved-key guard, render cache, multisite/REST positions, i18n/RTL/uninstall).
- 2026-05-12: second-pass human-driven review flagged six additional issues (3 P1, 3 P2); this version resolves all of them:
  - P1: §4.7a cascade promised propagation pre-generation can't deliver → rewritten as "internal cache hygiene + visibility notice on template-edit screen + Stale badge on binding card"; explicitly states front-end visibility requires Bulk Apply/cron/save_post.
  - P1: §4.7 ACF-only trigger routing would silently break Quick Edit / WP-CLI / non-ACF REST → reverted to "always hook save_post priority 20"; `acf/save_post` not used in V1 (race already mitigated by priority ordering).
  - P1: §4.11 migration would create duplicate bindings on real sites → rewritten with explicit `(post_type, target.key)` dedupe step + variables conflict resolution (per-post `spintax_variables` either fold once into `binding.variables.overrides` when identical, or inline as `#set` prefix into each post's `_spintax_source_<key>` when divergent — no new scope layer introduced).
  - P2: §4.1 / §4.5 ACF targets now persist `target.field_key` (stable identity) alongside `target.key` (display name); applier uses `update_field( $field_key, ... )` per ACF docs.
  - P2: §4.5 `ajax_acf_fields` no longer recurses into sub_fields / flexible_content (matches NG1).
  - P2: §4.4 signature meta name consistency fixed in Phase 2 scope; §4.12 cache key formula now includes `variable_context_hash` in the headline.

This document is the implementation contract for binding Spintax templates to ACF and post-meta fields. It supersedes the predecessor plugin `nested-spintax-for-acf` and inherits its outcome (render spintax → write to fields) while replacing its UX (per-post metabox) with a globally-scoped binding model patterned on `images-sync-for-cloudflare` (`W:\Projects\wpci`).

---

## 1. Problem

Content managers want to populate ACF and post-meta fields with spintax-generated text — e.g. a "Hero subtitle" ACF text field across hundreds of post-type entries should render variants from a single shared template, with per-post variable substitution. Today's options all fail at scale:

1. **`[spintax slug="…"]` inline in content** — only works for `the_content`. ACF/meta fields read directly by themes are out of reach.
2. **`spintax_render()` calls in theme files** — requires dev work for every field; embeds template slugs in PHP; not usable by content managers.
3. **Hand-pasting rendered output** — kills reusability; can't re-roll variants; no link between template and field.
4. **Predecessor plugin `nested-spintax-for-acf`** — solved the rendering question via on-save sibling-meta generation, but configured per-post: editors hand-pick which fields are spintax-enabled on each post individually. Doesn't scale: 200 posts × N fields = 200 metabox sessions; no concept of "this template populates this field across the type".

## 2. Goals

- **G1.** Bind one Spintax template (or a per-post inline source) to one target field on one post type. Configure once, applies to every matching post.
- **G2.** Selective: only the fields the editor explicitly chose receive bindings. No "auto-enable all text fields" mode.
- **G3.** Auto-seed empty fields without overwriting manual edits.
- **G4.** Bulk apply to existing posts as a one-shot, with progress visible.
- **G5.** Dry-run preview before applying — both per-binding (Test panel) and per-post (resolve a specific post ID).
- **G6.** Works on both ACF Free and Pro; gracefully degrades when ACF isn't installed (post_meta still available).
- **G7.** Migration path from the predecessor plugin's data shape.

## 3. Non-goals (V1)

- **NG1.** Repeater / flexible_content per-row rendering. V1 binds to top-level text-shaped fields only.
- **NG2.** Block-editor inline editing of `per_post` source. Metabox is the V1 surface.
- **NG3.** WPML/Polylang locale fan-out. Authors create one binding per locale manually.
- **NG4.** Auto-detecting "which fields should be spintax". Explicit binding required.
- **NG5.** Real-time preview as the author types in `per_post` source. Reuse the existing template-edit AJAX preview pattern.
- **NG6.** Field-level conditional binding (apply only if predicate field has value X). V2.
- **NG7.** Visual diff in Test panel between current target value and rendered output. V2.

## 4. Design

### 4.1 Binding entity

One binding describes one rendering relationship. Stored in a single autoloaded WordPress option (`spintax_bindings`) keyed by binding ID. Mirrors wpci `MappingsRepo` shape — bindings are admin configuration, not user content, so a CPT is overkill.

```php
[
  'id'         => 'bind_a1b2c3',       // generated, unique
  'post_type'  => 'post',              // applies to this post type only
  'status'     => 'any',               // 'any' | 'publish'
  'target'     => [
    'kind'      => 'acf_field',        // 'acf_field' | 'post_meta'
    'key'       => 'hero_subtitle',    // ACF field name OR post_meta key; used for display + storage lookup
    'field_key' => 'field_5f8a1234abcd', // ACF field key (only set when kind=acf_field); stable identity
  ],
  'source'     => [
    'mode'        => 'template',       // 'template' | 'per_post'
    'template_id' => 47,               // CPT ID when mode=template
    // when mode=per_post, source lives in sibling meta
    // '_spintax_source_' . $target['key'] on each post — no extra fields here
  ],
  'variables'  => [
    'expose_post_context'  => true,    // %post_id%, %post_title%, etc.
    'expose_acf_siblings'  => false,   // %acf_<name>% for other fields in same group
    'overrides'            => '',      // raw #set block, per-binding scope
  ],
  'triggers'   => [
    'save_post'     => true,
    'acf_save_post' => false,          // reserved for V2; ignored by V1 trigger pipeline (see §4.7)
    'cron'          => 'off',          // 'off' | 'hourly' | 'twicedaily' | 'daily'
  ],
  'behavior'   => [
    'auto_seed_empty'       => true,   // write only if target empty
    'regenerate_on_save'    => false,  // overwrite on every trigger
    'preserve_manual_edits' => true,   // hash-track last render; skip if changed externally
    'clear_on_empty'        => false,  // clear target if template renders to empty
  ],
  'created_at' => 1747000000,
  'updated_at' => 1747000000,
]
```

### 4.2 Source modes

Two modes per binding, mutually exclusive:

**`template` mode** — binding points to an existing `spintax_template` CPT entry by ID. The same template can be referenced by many bindings (DRY). Best for reusable snippets (standard disclaimers, category boilerplate, structured hero blocks).

**`per_post` mode** — spintax source lives in sibling post-meta `_spintax_source_<target.key>` on each individual post. Authored via an inline metabox on that post's edit screen. Best for genuinely per-post content where the template *is* the per-post variation.

Both modes coexist freely across bindings on the same site / post type. Selection is per-binding.

### 4.3 Variable scope

Resolved in this order (later layers override earlier):

1. **Global** — variables from Settings → Spintax (always available).
2. **Binding overrides** — per-binding `#set` block.
3. **Post context** — if `expose_post_context = true`: `%post_id%`, `%post_title%`, `%post_url%`, `%post_slug%`, `%author_name%`, `%author_id%`, `%post_date%`, `%post_modified%`.
4. **ACF siblings** — if `expose_acf_siblings = true` and `target.kind = acf_field`: every text/textarea/wysiwyg field in the same ACF group as `%acf_<field_name>%`. Repeaters / groups / flexible_content excluded in V1 (returns empty string for those names).

Cron-fired regenerations seed the PRNG from `hash(post_id . binding_id . date('Y-m-d'))` for deterministic same-day variants — avoids cron-storm of fresh variants on every hour.

### 4.4 Behavior flags

Four flags govern the write logic. Defaults chosen so a newly-created binding is safe — won't trash existing content.

| Flag | Default | Semantics |
|---|---|---|
| `auto_seed_empty` | ON | Write target only if currently empty/missing. "Set up once, populates new posts as they're created." |
| `regenerate_on_save` | OFF | On every trigger, overwrite target with a fresh render. "Rotate variant on every edit." |
| `preserve_manual_edits` | ON | Hash-track last rendered value in `_spintax_last_render_sig_<binding_id>` per post. On regenerate, compare current target's hash to stored hash. If different, treat as a manual edit and skip. Only meaningful when `regenerate_on_save=ON` — `auto_seed_empty` never overwrites, so there's nothing to preserve. |
| `clear_on_empty` | OFF | If the template renders to an empty string, clear the target field. Useful for conditional content that should "uninstall" itself when its inputs go away. |

#### Decision tree (single trigger fire)

The four flags plus binding state yield a decision tree rather than a flat 16-cell matrix. The pseudocode below is the contract `BindingApplier::apply()` must implement:

```
function apply(binding, post_id):
    rendered      = renderer.render(binding.source, build_var_context(binding, post_id))
    rendered_hash = sha1(rendered)
    stored_sig    = get_post_meta(post_id, '_spintax_last_render_sig_' + binding.id)
    current       = get_target_value(binding.target, post_id)
    current_hash  = sha1(current)
    target_empty  = (current === '')

    # Path 1: regenerate-on-save supersedes auto-seed.
    if binding.behavior.regenerate_on_save:
        if binding.behavior.preserve_manual_edits AND stored_sig AND current_hash !== stored_sig:
            return SKIP_MANUAL_EDIT_DETECTED

        if rendered === '':
            if binding.behavior.clear_on_empty:
                write_target(''); set_signature(sha1(''))
                return WROTE_EMPTY
            return SKIP_EMPTY_RENDER

        write_target(rendered); set_signature(rendered_hash)
        return WROTE_REGENERATED

    # Path 2: auto-seed only writes when target is empty.
    if binding.behavior.auto_seed_empty:
        if not target_empty:
            return SKIP_TARGET_NONEMPTY
        if rendered === '':
            return SKIP_EMPTY_RENDER
        write_target(rendered); set_signature(rendered_hash)
        return WROTE_SEEDED

    # Path 3: neither trigger flag set.
    return SKIP_NO_WRITE_TRIGGER  # form-save validation should warn before this point
```

#### Cold-start (no signature yet stored)

When `regenerate_on_save=ON` and `preserve_manual_edits=ON` but `_spintax_last_render_sig_<binding_id>` is missing (binding just created, or signature was wiped):

- If `current target === ''`: treat as never-written; write rendered value; create signature. **No false manual-edit positive.**
- If `current target !== ''`: treat as manual edit; skip; log notice. To accept the existing target value as the human-authored baseline, the editor uses **"Initialize from current value"** — a one-shot button on the binding card that stamps the current value's hash as the baseline signature without writing anything. After that, normal preserve-manual-edits logic applies.

The "Initialize from current value" path matters for migrating from predecessor plugin data where target fields already have content authors want to keep as the human baseline.

#### Flag combination summary

Sixteen possible flag combinations; many are equivalent because flags supersede each other.

| `auto_seed` | `regen` | `preserve` | `clear` | Effective mode |
|---|---|---|---|---|
| OFF | OFF | * | * | **No-op.** Form save validation warns: "Binding has no write triggers — will never run." |
| ON  | OFF | * | * | **Seed-once.** Empty target → write. Non-empty target → no-op. `preserve` and `clear` irrelevant in this mode (no overwrite path). |
| OFF | ON  | OFF | OFF | **Force regenerate.** Always overwrite. Empty render → skip. |
| OFF | ON  | OFF | ON  | **Force regenerate, clear-on-empty.** Empty render → write empty (clears target). |
| OFF | ON  | ON  | OFF | **Regenerate respecting manual edits.** Skip if `current_hash !== stored_sig`. Empty render → skip. Cold start: empty target → write & seed; non-empty → skip until "Initialize" button. |
| OFF | ON  | ON  | ON  | **Regenerate respecting manual edits, clear-on-empty.** Same as above plus: clear when render is empty (still subject to manual-edit check). |
| ON  | ON  | * | * | Same as the corresponding `OFF/ON/*/*` row — `regenerate_on_save` supersedes `auto_seed_empty`. UI grays out `auto_seed_empty` when `regenerate_on_save` is checked to signal this. |

Eight "effective modes" total; the UI should expose them as a simpler "mode preset" picker (Seed-once / Force regenerate / Regenerate respecting edits / Clear when empty) with the four flags shown as the underlying advanced settings.

Bulk Apply uses the same decision tree.

### 4.5 Field discovery (admin AJAX)

Three endpoints, all gated by `current_user_can('manage_spintax_templates')` and a nonce. The capability `manage_spintax_templates` is the plugin's existing content-manager role mapping (see `Spintax\Support\Capabilities`) — bindings are content-manager territory, not site-admin territory. All admin pages and form handlers use the same capability.

**`ajax_acf_fields`** — for given `post_type`, walk `acf_get_field_groups( ['post_type' => $pt] )`, then `acf_get_fields( $group['key'] )` over **top-level fields only**. Filter `type IN (text, textarea, wysiwyg)`. Return `[{name, label, group, field_key}]` where `field_key` is the stable ACF identifier (e.g. `field_5f8a1234abcd`). Cache 5 minutes via transient `spintax_acf_fields_<post_type>`.

**Do NOT recurse** into `sub_fields` (repeaters, groups) or `flexible_content` layouts. Non-goal NG1 explicitly excludes those from V1; exposing them in the picker would invite users to configure bindings the applier can't safely write. Recursion lands in V2 alongside repeater-row rendering. The wpci `collect_image_fields` walker recurses because its V1 supports image fields nested in repeaters; ours doesn't.

The `field_key` is critical: ACF's `update_field( $key_or_name, $value, $post_id )` requires the field KEY (not name) on first write to a post where the field hasn't been touched — without the key, ACF can't establish the reference meta (`_<field_name> = <field_key>`). Reference: [ACF update_field()](https://www.advancedcustomfields.com/resources/update_field/). The applier (§4.4 decision tree) uses `update_field( $binding.target.field_key, $rendered, $post_id )` for `kind=acf_field` writes, and `update_post_meta( $post_id, $binding.target.key, $rendered )` for `kind=post_meta`. Reads are symmetric: `get_field( $binding.target.field_key, $post_id )` and `get_post_meta( $post_id, $binding.target.key, true )` respectively.

If a stored `field_key` no longer resolves (`acf_get_field( $stored_field_key )` returns null — field was deleted), the binding card surfaces "Field deleted" status and skips apply until the binding is reconfigured or deleted. Storing the name alongside lets the UI still show "was hero_subtitle (field_5f8a1234abcd)" for diagnostic clarity.

**`ajax_meta_keys`** — for given `post_type`, `SELECT DISTINCT meta_key FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.post_type = %s LIMIT 200`. Cache 5 minutes via transient `spintax_meta_keys_<post_type>`. Port wpci L294-344 verbatim, including the PHPCS suppression with justification comment. Internal-key filter applied client-side and server-side.

**`ajax_template_list`** — `get_posts( ['post_type' => 'spintax_template', 'numberposts' => -1, 'post_status' => 'publish'] )`. Return `[{id, title, slug}]`. No caching (small dataset, frequently changed).

### 4.6 Reserved-key guard

Refuse as `target.key` at form save (not write time). Three tiers:

**Tier 1 — WordPress internal meta** (any `target.kind`):
- Prefixes: `_wp_`, `_edit_`, `_oembed_`.
- Exact: `_pingme`, `_encloseme`, `_thumbnail_id`.
- Mirrors `MappingsPage::is_reserved_meta_key` (wpci L571-580).

**Tier 2 — Plugin-internal meta** (any `target.kind`):
- Prefixes: `_spintax_source_`, `_spintax_last_render_sig_`, `_spintax_binding_cache_v_`, `_spintax_`.
- Rationale: prevents a binding from writing to another binding's source, signature, or cache version stamp.

**Tier 3 — Post columns** (`target.kind = post_meta`):
- Exact: `post_title`, `post_content`, `post_excerpt`, `post_name`, `post_status`, `post_date`, `post_date_gmt`, `post_modified`, `post_modified_gmt`, `post_parent`, `post_author`, `post_type`, `post_password`, `post_content_filtered`, `menu_order`, `comment_status`, `ping_status`, `to_ping`, `pinged`, `guid`.
- Rationale: post columns aren't meta — `update_post_meta()` would create a meta row that shadows nothing; writes are silently ineffective and confusing. The Tier 1 underscore-prefix check doesn't catch these.

**Tier 4 — Binding uniqueness** (cross-binding):
- Reject creating a binding when another active binding has the same `(post_type, target.kind, target.key)`. Two bindings on the same field would race-overwrite each other's signatures and produce non-deterministic output.

All four tiers run before persisting the binding; failed tiers return field-level admin notice with the specific reason.

### 4.7 Triggers pipeline

**Default strategy: always hook `save_post` priority 20.** ACF's `acf/save_post` hook only fires when ACF data is submitted in the request — Quick Edit, WP-CLI (`wp post update`), REST saves without ACF payload, importer imports, and Gutenberg saves that don't touch ACF fields all bypass it. Routing bindings through `acf/save_post` exclusively would silently break those flows for every binding, including `post_meta`-kind bindings on non-ACF post types.

`save_post` at priority 20 runs AFTER ACF's own `save_post` handler (which fires at priority 10 by default and dispatches `acf/save_post` internally), so by the time our binding runs, ACF has already persisted submitted field values. `expose_acf_siblings=true` sees fresh values without a separate hook.

Trigger registration:

- **`save_post`** (priority 20, always): find bindings matching `get_post_type($post_id)` → for each, run filters (status, behavior flags) → call `BindingApplier::apply($binding, $post_id)`.
  - Skip when `DOING_AUTOSAVE` is true.
  - Skip in WordPress bulk edit (`isset($_REQUEST['bulk_edit'])`).
  - Skip during REST batch import where post bodies are minimal — detect via `defined('REST_REQUEST') && $post->post_status === 'auto-draft'`.
  - All other paths fire normally: Quick Edit, classic editor full save, Gutenberg REST save, ACF post-save, WP-CLI `post update`, WP Importer, custom REST endpoints. Phase 2 acceptance verifies each.
- **`acf/save_post`** hook is **not used in V1**. The race concern that motivated it (post_meta binding with `expose_acf_siblings=true` reading stale ACF values) is already handled because `save_post` priority 20 > ACF's default priority 10 — ACF has already written before we run.
  - Forward-compat: if V2 introduces ACF-only flows where save_post doesn't fire (e.g., ACF's options-page saves outside post context), revisit by adding `acf/save_post` as an additional dispatcher with a request-scoped dedup flag to prevent double-fire.
- **WP-Cron** per-binding schedule: register a unique hook `spintax_binding_cron_<binding_id>` with the chosen recurrence. Cron callback enqueues an Action Scheduler walk over all matching posts. Reuses existing `CronManager` infrastructure with binding-id as schedule key.

**Binding-level trigger control:** the `triggers.save_post` flag (default ON) controls whether the binding fires on post saves at all. The `triggers.acf_save_post` flag is reserved for V2 (when the additional dispatcher might be wired) and is ignored in V1; UI shows it grayed-out with an explanation tooltip. UI displays a single "Fire on post save" checkbox bound to `triggers.save_post`.

**Phase 2 acceptance must verify** the binding fires on: classic editor save, Quick Edit, Gutenberg / block editor save, ACF post save (covered by save_post p20), WP-CLI `wp post update`, REST POST/PUT, WP Importer post creation. And does NOT fire on: autosave, bulk edit, REST batch import of auto-drafts.

### 4.7a Template-edit cascade — visibility contract

**Critical contract clarification.** Bindings are a **pre-generation** system, not a render-on-read layer. Target fields hold the rendered string in the database; theme code reads `get_field()` / `get_post_meta()` directly and gets that stored value. **Editing a `spintax_template` does NOT make the new content visible on the front-end until a trigger writes a fresh value to each target field** — Bulk Apply, cron, or the next `save_post` of an individual post.

This is not a limitation we can engineer around without abandoning pre-generation. The reviewer's expectation that a "cache bump" alone makes the front-end see fresh content does not match the data flow — the front-end never invokes our renderer, it reads stored strings.

**What the cascade actually does:**

On `save_post` of a `spintax_template` post, run an inverse-lookup against `BindingsRepo` to find every binding where `source.mode = template` and `source.template_id = <edited_template_id>`. For each such binding:

1. **Bump internal render-cache version** stored in option `_spintax_binding_cache_v_<binding_id>`. This invalidates the per-post-per-binding cache (§4.12) so the NEXT render — triggered by Bulk Apply / cron / save_post — uses the fresh template content. Without this bump, a cron-scheduled regenerate could replay a cached stale render and silently no-op.
2. **Surface a visibility notice** on the `spintax_template` edit screen (the post-update flash): "N bindings depend on this template. Run Bulk Apply to push changes to N matching posts." With a button that enqueues Bulk Apply for each affected binding.
3. **Per-binding card** gets a `Stale: source template edited 2026-05-12 14:23` badge until the next successful run for that binding completes.

**What the cascade does NOT do:**
- It does NOT automatically rewrite target fields. Authors who want propagation explicitly run Bulk Apply (one click via the notice).
- It does NOT make the front-end see fresh content. Stored target values remain until rewritten.
- It does NOT change the contract between bindings and consumers (themes / blocks / REST readers) — those continue to read stored strings.

This is the inverse of `DependencyInvalidator` — that one invalidates upward through `#include` references for in-render template embedding; this one invalidates downward through binding references for the pre-generation cache. The two systems are independent.

**Edge cases:**
- Template moves to `trash` or `draft`: bindings pointing at it skip apply (treated as "source not found") and surface in the binding card's status as "Source unavailable."
- Template gets deleted permanently: bindings remain configured but stop firing; admin notice on the binding card recommends rebinding or deleting the binding.
- Per-post-mode bindings: not affected by this cascade (no template_id reference).

### 4.8 Admin UI

Top-level admin menu entry "Spintax" already exists. Add submenu "Bindings" after "Templates". Page renders one of two views:

**List view** (default): cards (no WP_List_Table — wpci uses cards for visual scanability of binding semantics). Each card: post type label + binding ID, source (template name or `per_post`), arrow, target (`acf:hero_subtitle` or `meta:_my_key`), preset triggers summary, action row (Edit / Bulk Apply / Delete).

**Form view** (`?action=new` or `?action=edit&binding_id=X`): sections matching `MappingsPage::render_form`:

1. **Scope** — post_type dropdown (public types), status (`any` | `publish`).
2. **Target field** — `target.kind` radio (ACF / post_meta); `target.key` field with AJAX-suggested options based on chosen post_type and kind.
3. **Source** — `source.mode` radio (template / per_post); when template: dropdown of Spintax CPT entries; when per_post: read-only display of the auto-derived sibling meta key + note explaining the inline metabox.
4. **Variables** — two checkboxes (post context / ACF siblings), plus a textarea for `#set` overrides with the same Validator surface as Settings global vars.
5. **Triggers** — "Fire on post save" checkbox (bound to `triggers.save_post`), `cron` dropdown. The legacy `triggers.acf_save_post` field persists in the payload (default OFF, reserved for V2) but is not surfaced in the form UI in V1.
6. **Behavior** — four checkboxes with inline descriptions.
7. **Test** — Post ID number input + "Test" button, results panel below.
8. **Submit** — Save / Cancel.

Per-post inline metabox (only when at least one `per_post`-mode binding matches the current post type): one textarea per matching binding, labeled with target field name. Saves to `_spintax_source_<target.key>`.

### 4.9 Test / Dry-run

Endpoint `ajax_test_binding`. Input: binding form state (or saved binding ID) + a post ID. Output:

```json
{
  "post_title": "Sample Post",
  "post_type": "post",
  "matches_filters": true,
  "source_found": true,
  "source_preview": "Hello {world|there}",
  "current_target_value": "Existing content...",
  "would_write": true,
  "would_skip_reason": null,
  "rendered_preview": "Hello world",
  "rendered_hash": "abc123",
  "rendered_is_empty": false
}
```

No side effects. Same logic path as `BindingApplier::apply` but returns the planned action instead of executing.

### 4.10 Bulk Apply

"Apply to all matching posts" button on each binding card. Confirms (`confirm('Apply binding to N matching posts?')`), then enqueues Action Scheduler job `spintax_apply_binding` with `{binding_id, offset, chunk_size: 20}`. The handler processes chunk, re-enqueues with new offset until exhausted, logs progress. Falls back to a `wp spintax bindings apply --binding=X --all` WP-CLI command + admin notice if Action Scheduler isn't available (mirrors wpci L248-253).

### 4.11 Migration helper

Detects predecessor `nested-spintax-for-acf` data on plugin activation (dismissible banner only) OR on demand via Tools → Spintax Migration. Predecessor stored selections per-post, but our binding model is global per `(post_type, target.kind, target.key)`. Migration must dedupe across all predecessor data, not create one binding per (post, field) pair.

**Algorithm:**

```
predecessor_data = scan_postmeta_for('ns4acf_selected_spintax_fields')
   # → list of (post_id, selected_field_names[])

# Step 1: dedupe by (post_type, field_name) across all posts.
target_keys = {}  # key: (post_type, field_name); value: list of post_ids
for (post_id, fields) in predecessor_data:
    post_type = get_post_type(post_id)
    for field_name in fields:
        target_keys[(post_type, field_name)] ||= []
        target_keys[(post_type, field_name)].append(post_id)

# Step 2: classify each target.
planned_bindings = []
for ((post_type, field_name), post_ids) in target_keys:
    kind = 'acf_field' if acf_get_field_object(field_name, post_ids[0]) else 'post_meta'
    field_key = (kind === 'acf_field') ? acf_get_field_object(field_name, post_ids[0]).key : null
    planned_bindings.append({
        post_type: post_type,
        target: { kind: kind, key: field_name, field_key: field_key },
        source: { mode: 'per_post' },
        # per-binding overrides: derived from posts' `spintax_variables`?
        # See "Variables conflict resolution" below.
        affected_post_ids: post_ids,
    })

# Step 3: preview to admin (one row per planned binding, with N affected posts shown).
# Admin confirms → execute.

# Step 4: execute.
for binding in planned_bindings:
    create_binding(binding)  # one binding per (post_type, target.key) — passes Tier 4 uniqueness
    for post_id in binding.affected_post_ids:
        # Copy per-post source meta from predecessor key to new sibling key.
        source_content = get_post_meta(post_id, 'spintax_' + field_name)
        update_post_meta(post_id, '_spintax_source_' + field_name, source_content)
```

**Variables conflict resolution.** Predecessor stored `spintax_variables` per-post (a `#set` block authored individually on each post). Our binding `variables.overrides` is per-binding (global to all posts using that binding). Migration policy avoids introducing a new variable scope layer by folding per-post variables into the per-post source content itself:

- If all `affected_post_ids` had identical `spintax_variables` content (after trim/normalize), copy it once into `binding.variables.overrides`. Per-post `_spintax_source_<key>` gets only the original `spintax_<key>` body.
- If `spintax_variables` differed across posts (or only some had it), leave `binding.variables.overrides` empty AND prepend each post's `spintax_variables` content (as raw `#set` block) to that post's imported `_spintax_source_<key>` body. Example: post 42's source becomes `#set %tone% = friendly\n#set %signoff% = "Best,"\n` + original spintax body. The renderer treats inline `#set` declarations at template start as local scope, so per-post variables remain effective without a new sibling-meta scope layer.

Document the chosen path clearly in the preview: "10 posts had matching variables → copied to binding overrides. 3 posts had unique variables → inlined into per-post source."

This keeps the V1 variable resolution model unchanged (§4.3) and the only sibling meta introduced is `_spintax_source_<target.key>` — no new `_spintax_overrides_<key>` key.

**Preview screen** shows one row per planned binding: `post_type → target.kind:target.key (N posts affected, source mode: per_post)`. Editor can deselect individual bindings before commit. Re-running migration after partial commit picks up only un-migrated entries (idempotent on `(post_type, target.key)` — already-existing bindings are skipped, their posts' source meta is still copied if missing).

**Defensive handling of malformed predecessor data:**
- `ns4acf_selected_spintax_fields` is not an array → skip that post, log warning.
- Field name in selection but corresponding `spintax_<field>` meta missing → import as empty source (auto_seed_empty=ON binding will no-op for that post until author writes content).
- ACF field detection returns null for one post but works for another within the same `(post_type, field_name)` → use the first non-null detection; fall back to `post_meta` if all return null.
- Predecessor field names containing invalid characters (anything outside `[a-zA-Z0-9_-]`) → skip with warning in preview.

Old plugin's data is **never deleted** by the migration — `ns4acf_selected_spintax_fields`, `spintax_<field>`, `spintax_variables`, and `ns4acf_*` options remain untouched. User uninstalls the predecessor plugin themselves once migration is verified working. Re-running migration after deletion is safe (predecessor data already absent → migration reports "no predecessor data found").

### 4.12 Render caching

Per-post-per-binding rendered-output cache via the existing `Spintax\Core\Cache\CacheManager`, keyed by **`(binding_id, post_id, binding_cache_version, variable_context_hash)`** where:
- `binding_cache_version` comes from option `_spintax_binding_cache_v_<binding_id>` (bumped by §4.7a template-edit cascade).
- `variable_context_hash = sha1(serialize(resolved_variables))` — captures the actual per-render variable values so any context change (post title edit, ACF sibling change, override edit, etc.) naturally misses the cache.

TTL inherits from the underlying template's TTL for `template`-mode; defaults to global cache TTL for `per_post`-mode.

**Why this cache matters most for Bulk Apply:** rendering a 50KB casino-review template through full parse + permute is expensive; a 500-post Bulk Apply that re-renders 500 times for substantively-identical variable contexts is wasteful. Posts whose variable context is unchanged since the last render hit the cache.

**Cache invalidation:**
- Binding cache version bump (§4.7a template-edit cascade) invalidates all post-renders for that binding.
- Per-post-context vars change (post title edit, etc.) — cache key includes a `sha1(variable_context)` component, so context change naturally misses cache.
- Manual "Clear binding cache" button on binding card.

**Bulk Apply specifically:** cache fill happens as the walk progresses; subsequent identical jobs (e.g. a cron-triggered re-walk same day) replay from cache. The seed-from-date logic (§4.3) ensures same-day re-walks produce identical variants for stable caching.

## 5. UI flow (reviewer-facing illustration)

```
WP Admin → Spintax → Bindings
┌─────────────────────────────────────────────────┐
│ Spintax — Bindings              [+ Add New]     │
├─────────────────────────────────────────────────┤
│ ┌─────────────────────────────────────────────┐ │
│ │ Post (post)               bind_a1b2c3       │ │
│ │                                              │ │
│ │ Source        → Target       → Preset       │ │
│ │ Template:       acf:           save_post,    │ │
│ │ "Hero block"    hero_subtitle  cron daily    │ │
│ │                                              │ │
│ │ [Edit] [Bulk Apply] [Delete]                 │ │
│ └─────────────────────────────────────────────┘ │
│ ┌─────────────────────────────────────────────┐ │
│ │ Casino Review (casino_review)  bind_d4e5f6  │ │
│ │ ...                                          │ │
│ └─────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────┘

Form (Add New / Edit):
[ Scope     ] post_type, status
[ Target    ] kind + key (AJAX-suggested)
[ Source    ] mode + template OR per-post explanation
[ Variables ] context toggles + #set overrides
[ Triggers  ] save_post (always; ACF-aware via priority 20) / cron
[ Behavior  ] auto_seed / regen / preserve / clear
[ Test      ] post_id → preview
[ Submit    ]
```

## 6. Alternatives considered

### 6.1 Per-field flag (predecessor plugin's approach)

**Idea:** add a "Spintax-enable this field" checkbox to each ACF field's settings; editors flip it per field per post.

**Rejected because:** scales linearly in editor-clicks with `posts × fields`. Predecessor plugin had this exact UX; users complained. Selective binding via globally-scoped configuration scales as `bindings`, independent of post count.

### 6.2 `acf/format_value` filter (Model B from design discussion)

**Idea:** mark fields with a meta-flag "render through Spintax"; hook ACF's format-value filter to run all flagged fields through the renderer at read time.

**Rejected because:**
- Every field read becomes a render cycle (cache mandatory, but still adds latency).
- No clear path for "freeze the chosen variant" — every page load is a fresh roll. Bad for SEO consistency.
- Hooking `get_post_metadata` globally for post-meta support is invasive — touches every plugin that reads meta keys.
- Editor can't see the rendered output until front-end — it's live every time.

Pre-generation (the chosen approach) writes the rendered value into the field itself. Theme reads it normally. Variant is stable across views. Editors can see the value in the admin.

### 6.3 Single global config

**Idea:** one settings page with a list of `(field_key → template_id)` pairs.

**Rejected because:** can't express post-type scoping, status filtering, per-binding variables, or per-binding behavior. The binding entity carries operational config (triggers, behavior) that a flat key-template map can't.

### 6.4 CPT for bindings (instead of option-store)

**Idea:** model each binding as a post in a `spintax_binding` CPT.

**Rejected because:** bindings aren't user-facing content. They don't need revisions, slugs, taxonomies, authors, or any of the CPT machinery. Option-store is simpler, mirrors wpci's proven pattern, and ~100 bindings per site is well within single-option autoload limits (each binding ~500 bytes serialized).

## 7. Risks + mitigations

| Risk | Severity | Mitigation |
|---|---|---|
| Mass overwrite of existing content on first binding creation | high | `auto_seed_empty=ON` default + `preserve_manual_edits=ON` default — newly-created bindings can't clobber until explicitly opted in. |
| Cron storm regenerating thousands of posts | medium | Action Scheduler chunking (20 posts/chunk, configurable). PRNG seed includes date so daily cron doesn't shuffle every hour. |
| Database bloat from sibling meta + signature meta | low | Two meta keys per `per_post` binding per post (source + last-render-sig). ~hundreds of bytes per post per binding. Within normal WP scale. |
| Migration helper misclassifies field type | medium | Always preview before applying; never auto-trigger. ACF detection uses `acf_get_field_object` (returns null for non-ACF); fall back to `post_meta` kind. User can adjust before commit. |
| ACF Pro vs Free behavioral divergence | low | All binding config lives in our admin page, not in ACF's field settings UI. No `acf_register_field_setting` dep. |
| Action Scheduler not installed | medium | Detect at form load; gray out Bulk Apply with explanatory notice; offer WP-CLI fallback. |
| Plugin Check (PCP) flags direct DB query in `ajax_meta_keys` | low | Same query exists in wpci with phpcs:ignore + justification — proven pattern. Transient caching mitigates the actual concern (repeated full-table scan). |
| Reserved-key guard misses an edge case | low | Form-time validation, not write-time. Add comprehensive test fixtures for all four tiers (§4.6) — internal meta, plugin meta, post columns, binding-uniqueness. |
| Two bindings on the same `(post_type, target.kind, target.key)` race-overwrite each other | medium | Tier 4 uniqueness check at form save (§4.6). Test fixture verifies the second binding's save returns a field-level error. |
| Signature-meta cache rot from theme/plugin direct `update_post_meta` writes outside the binding flow | medium | `preserve_manual_edits` correctly treats this as a manual edit (hash diverges). Documented behavior; admin notice on first detection per post: "Target was modified outside Spintax; binding is now in preserve-edits-passive mode." "Initialize from current value" button re-syncs. |
| Cold-start false manual-edit positive on first run with `regenerate_on_save=ON, preserve_manual_edits=ON, non-empty target` | low | Documented as "Initialize from current value" button flow (§4.4 Cold-start). Form-save warns the editor about this state when both flags are checked and target already has content. |

## 8. Open questions

Resolved during 2026-05-12 review pass — kept here for traceability. Items marked **RESOLVED** are no longer open; **OPEN** items still need pre-Phase-1 decisions.

- **Q1 (RESOLVED).** Inline `per_post` source editor: metabox. Block-editor support deferred to V2; metabox is simpler, no ACF Pro / no block dep. Confirmed by review.
- **Q2 (RESOLVED).** ACF sibling vars naming: prefixed `%acf_<field_name>%`. Confirmed by review. **Documented collision rule:** ACF siblings override global vars (resolution order §4.3); a global var named `acf_foo` is shadowed by an actual ACF sibling field `foo`.
- **Q3 (RESOLVED).** Storage: single autoloaded option `spintax_bindings` with a **hard cap of 200 bindings per site** validated at `BindingsRepo::create()`. Site Health surfaces a warning at 150+ bindings. Per-binding-option alternative kept as a forward path if the cap is raised.
- **Q4 (RESOLVED).** Cron granularity: per-binding. Justification: a single template referenced by N bindings legitimately wants different cadences (one binding for fresh-cache cron, another for stable seed). Confirmed by review.
- **Q5 (RESOLVED).** Predecessor migration: opt-in only, plus a one-line banner ("Predecessor data detected — review in Tools → Spintax Migration") on activation. Banner is dismissible and non-destructive. Confirmed by review.
- **Q6 (RESOLVED — lean reversed by review).** Signature storage: keyed by `binding_id`, not by `target.key`. Reason: two bindings on the same `(post_type, target.kind, target.key)` were originally allowed but are now rejected by Tier 4 of the reserved-key guard (§4.6); however, keying signature by `binding_id` still matters for the binding-deletion-and-recreation case and for postmeta-inspection clarity when multiple bindings exist on the same post. Stored as `_spintax_last_render_sig_<binding_id>`.
- **Q7 (RESOLVED — lean adjusted).** Per-post-per-binding render cache: **add** in V1 (§4.12). Keyed by `(binding_id, post_id, binding_cache_version, variable_context_hash)`, TTL inherits from template's cache TTL. Cheap addition with significant Bulk Apply savings. The previous "skip caching for V1" lean is reversed.
- **Q8 (OPEN — new).** Bulk Apply `chunk_size` configurability: site-wide default (current proposal: 20) vs per-binding override. Lean: **per-binding override, default 20**, exposed in binding form's "Advanced" section. Reviewer to confirm before Phase 4. Rationale: render cost varies 100× by template; one chunk size doesn't fit.
- **Q9 (OPEN — new).** Template-edit cascade scope: cache-version bump only (§4.7a) vs cache-version bump *plus* an optional admin notice "X bindings depend on this template — run Bulk Apply to propagate" on the template's save screen. Lean: **add the notice**. Reviewer to confirm before Phase 4.

The two new questions (Q8, Q9) are minor scoping decisions for later phases; they don't block Phase 1.

## 9. Phased implementation plan

Five milestones. All land in 2.0.0 — bindings are too coupled to ship in pieces meaningfully. Each milestone has acceptance criteria; we don't proceed to N+1 until N is green.

### Phase 1 — Data layer + minimal admin (no rendering yet)

**Scope:**
- `Spintax\Bindings\BindingsRepo` — CRUD over a single autoloaded option. `all()`, `find()`, `create()`, `update()`, `delete()`.
- `Spintax\Bindings\Defaults` — factory methods for default binding shape.
- `Spintax\Support\Validators::is_valid_binding_id` — `^bind_[a-z0-9]{6}$`.
- `Spintax\Admin\BindingsPage` — list (card view) + form (all sections rendered, no AJAX or test panel yet). Save handler with reserved-key guard. PRG pattern + nonces + capability checks.
- Admin menu entry "Bindings".

**Acceptance:**
- Can create / edit / delete bindings; survives plugin deactivate-activate.
- PHPCS 0 errors / 0 warnings.
- Plugin Check 0 errors / 0 warnings.
- PHPUnit coverage: BindingsRepo CRUD, reserved-key guard fixtures, Defaults factory.
- No save_post integration yet — bindings persist but do nothing on render.

**Exit test:** create three bindings of mixed kinds (acf_field + post_meta, both source modes), reload, edit, delete. UI works without JS.

### Phase 2 — Resolver + applier + save_post trigger

**Scope:**
- `Spintax\Bindings\BindingResolver` — given binding + post_id, resolves source string (template-mode: fetch CPT content; per_post-mode: fetch sibling meta).
- `Spintax\Bindings\BindingApplier::apply($binding, $post_id)` — orchestrates resolve → render → write logic with all four behavior flags.
- `Spintax\Core\Variables\PostContextSource` — exposes %post_id%, %post_title%, etc.
- `Spintax\Core\Variables\BindingOverridesSource` — parses per-binding `#set` block.
- `Spintax\Bindings\Triggers\SavePostTrigger` — hooks `save_post` priority 20.
- Per-post metabox renderer for `per_post`-mode bindings.
- Signature meta `_spintax_last_render_sig_<binding_id>` lifecycle (per §4.4 cold-start handling).

**Scope additions over Phase 1:**
- `Spintax\Admin\BindingsAjax::test_binding` — minimal AJAX endpoint (no JS UI yet). Returns the same payload as §4.9 without side effects. Enables dogfooding `template` and `per_post` modes via curl / browser devtools before the full Test panel UI lands in Phase 3.

**Acceptance:**
- Behavior decision tree tested exhaustively (§4.4 pseudocode) — all 7 distinct return codes (`WROTE_SEEDED`, `WROTE_REGENERATED`, `WROTE_EMPTY`, `SKIP_MANUAL_EDIT_DETECTED`, `SKIP_TARGET_NONEMPTY`, `SKIP_EMPTY_RENDER`, `SKIP_NO_WRITE_TRIGGER`) reached by at least one PHPUnit case each.
- Cold-start path: empty target + missing signature + `regenerate_on_save=ON, preserve=ON` → writes & seeds (no false manual-edit positive). Non-empty target + missing signature + same flags → skips with notice.
- Autosave / bulk-edit / REST-import don't trigger bindings.
- Manual edit (target's hash ≠ stored signature) → `preserve_manual_edits=ON` skips, `OFF` clobbers.
- Template-edit cascade (§4.7a): editing a `spintax_template` bumps `_spintax_binding_cache_v_<binding_id>` for all bindings referencing it; no automatic post writes occur.
- Per-post metabox saves source content with nonce + `manage_spintax_templates` check.
- ACF-active vs ACF-inactive trigger routing (§4.7): only one of `save_post` / `acf/save_post` fires per binding per save, never both.
- `ajax_test_binding` returns the planned action without side effects (verified by postmeta snapshot before/after).

**Exit test:** create a `per_post` binding on Posts → `acf_field:hero_text` with default flags. Author writes spintax in metabox, saves post → field populated. Hand-edit field → save post → preserved. Toggle `preserve_manual_edits=OFF` → save → clobbered. Switch binding to `template` mode → re-save the underlying template → next render of the target post shows the updated template (cache invalidated) without the target field being rewritten yet. Bulk Apply (manual via WP-CLI in this phase since no UI yet) rewrites the target field.

### Phase 3 — AJAX field discovery + Test panel

**Scope:**
- `Spintax\Admin\BindingsAjax::meta_keys` — direct port of wpci `ajax_meta_keys`.
- `Spintax\Admin\BindingsAjax::acf_fields` — adapt wpci `collect_image_fields` walker, text-type filter.
- `Spintax\Admin\BindingsAjax::template_list` — list `spintax_template` CPT.
- `Spintax\Admin\BindingsAjax::test_binding` — dry-run endpoint.
- Form-side JS: dependent dropdowns (kind → key suggestions), Test panel UI.

**Acceptance:**
- AJAX endpoints all gated by `manage_spintax_templates` + nonce; PHPUnit covers unauth / wrong-nonce / wrong-post-type paths.
- ACF active → field walker enumerates flat + nested (sub_fields, flexible_content); ACF inactive → endpoint returns empty array gracefully.
- Test panel: same path as `apply()` but returns plan instead of executing; no side effects (verified by snapshot of postmeta before/after).

**Exit test:** form's "Target Key" input shows live suggestions as user types; "Test" with post ID shows accurate `would_write` flag matching what apply() would actually do.

### Phase 4 — Bulk Apply + cron + WP-CLI export/import + AcfSiblingsSource

**Scope:**
- Action Scheduler integration (graceful fallback to WP-CLI if AS missing).
- `Spintax\Bindings\Triggers\CronTrigger` — registers `spintax_binding_cron_<binding_id>` hooks per binding, walks via Action Scheduler.
- `Spintax\Core\Variables\AcfSiblingsSource` — exposes `%acf_<name>%` for sibling text/textarea/wysiwyg fields in the same group. Documents collision rule (siblings override globals).
- Per-binding `chunk_size` override (Q8) in binding form's Advanced section, default 20.
- Template-edit cascade admin notice (Q9): "N bindings depend on this template — Run Bulk Apply to propagate" with action button.
- WP-CLI commands:
  - `wp spintax bindings list [--format=table|json|csv]`
  - `wp spintax bindings apply --binding=<id> [--post=<id>|--all]`
  - `wp spintax bindings test --binding=<id> --post=<id>`
  - `wp spintax bindings export [--format=json|yaml] [--binding=<id>|--all]` — for staging→prod workflows
  - `wp spintax bindings import --file=<path> [--dry-run] [--overwrite]`

Note: V1 uses `save_post` priority 20 only (§4.7). There is no separate `AcfSavePostTrigger` class in V1 — the priority 20 hook runs after ACF's own save_post handler at priority 10, so ACF-aware sibling reads work without a second hook. V2 may reintroduce an `acf/save_post` dispatcher with request-scoped dedup if a use case surfaces.

**Acceptance:**
- Bulk Apply on a binding with 500 matching posts completes via AS in chunks; logs progress to `Spintax\Support\Logging`; respects `preserve_manual_edits`.
- Per-binding `chunk_size` override takes effect; site-wide default 20 used when unset.
- Cron schedule changes (off → daily, daily → off) update the registered hook correctly; re-schedule survives a binding rename/edit cycle.
- ACF siblings: `expose_acf_siblings=true` on an ACF binding sees other text fields in the same group but not repeater rows / group sub-fields / flexible_content layouts (V1 boundary).
- WP-CLI `apply --all` produces identical results to Bulk Apply UI.
- WP-CLI `export` round-trips through `import` with byte-identical binding payloads (excluding `id` which is regenerated unless `--preserve-ids`).
- Template-edit cascade notice appears on `spintax_template` post-edit screen when ≥1 binding references it.

**Exit test:** bulk-apply on a fresh post type with 100 posts → 100 fields populated; one was hand-edited beforehand → that one preserved; cron daily triggers next-day regeneration without manual intervention. Export bindings from this site → import to a clean WP installation → bindings recreated identically.

### Phase 5 — Migration + i18n/RTL + uninstall + docs + ship

**Scope:**
- `Spintax\Admin\MigrationPage` under Tools menu.
- Detector: scans postmeta for `ns4acf_selected_spintax_fields`; groups by post type.
- Activation banner: dismissible one-liner pointing to Tools → Spintax Migration when predecessor data detected.
- Preview: shows planned bindings before commit (no DB writes until confirmed).
- Importer: creates bindings + copies sibling meta + copies variables.
- **i18n / RTL pass:** all admin strings wrapped with `__()` / `_n()` using existing text domain `spintax`; RTL CSS pass for `assets/css/admin.css` Bindings page section; verify with a `dir="rtl"` site (e.g. Arabic locale).
- **`uninstall.php` updates:** delete option `spintax_bindings`; bulk-delete all sibling-meta keys across all posts via `$wpdb->prepare()`: `_spintax_source_*` (per-post template sources), `_spintax_last_render_sig_*` (manual-edit-detection signatures), `_spintax_binding_cache_v_*` (per-binding cache version stamps). Existing uninstall already handles plugin options and CPT — extend, don't replace.
- **Multisite documentation:** readme.txt FAQ entry "On multisite, are bindings shared across the network?" — answer: no, per-site. Each subsite manages its own bindings independently. No network admin page in V1.
- **REST API position:** readme.txt FAQ entry "Can I manage bindings via REST?" — answer: not in V1; admin-only. WP-CLI export/import (Phase 4) covers staging→prod workflows. REST API exposure tracked as V2.
- **Hard cap documentation:** readme.txt FAQ entry mentions 200-binding cap with rationale (autoload option size).
- Docs: `spec-v1.md` addendum, `readme.txt` Changelog entry, Upgrade Notice for 2.0.0, spintax.net guide page `/docs/acf-bindings/`.
- Screenshot pass for WP.org assets.
- Version bump to 2.0.0; CHANGELOG + Upgrade Notice flagging binding model as a major feature.

**Acceptance:**
- Migration preview is accurate (manual diff against predecessor data on a test site).
- Migration is idempotent (run twice → second run no-op).
- Predecessor plugin's data isn't touched (user uninstalls it manually after verifying).
- Spec-v1 documents the binding model.
- All Bindings admin UI strings translate cleanly via WP language files; RTL layout verified visually.
- `uninstall.php` leaves no orphan meta after plugin removal (test: install + create binding on 10 posts + uninstall + verify postmeta is clean).
- Plugin Check 0 errors / 0 warnings with `--include-experimental`.

**Exit test:** full release rehearsal — version-set 2.0.0 → push → CI green → tag v2.0.0 → push tag → `wporg-deploy.yml` succeeds → wordpress.org/plugins/spintax/ shows 2.0.0 with the new feature in the changelog. Migration tested on a clean WP install with predecessor sample data.

## 10. Effort estimate

| Phase | LOC (impl) | LOC (tests) | Calendar (rough, dedicated) |
|---|---|---|---|
| 1. Data layer + admin (CRUD + reserved-key guard with 4 tiers) | ~280 | ~100 | 2-3 days |
| 2. Resolver + applier + triggers + cache + ajax_test_binding | ~480 | ~300 | 5-6 days |
| 3. AJAX field discovery + Test panel UI | ~280 | ~120 | 2-3 days |
| 4. Bulk + cron + ACF siblings + WP-CLI (incl. export/import) + cascade notice | ~400 | ~180 | 4-5 days |
| 5. Migration + i18n/RTL + uninstall + docs + ship | ~250 + docs | ~120 | 3 days |
| **Total** | **~1690** | **~820** | **~16-20 working days** |

Calendar assumes single dedicated developer with no other interruptions. Realistic wall-clock with reviews / iteration / unexpected ACF edge cases: **~4-5 weeks**. Revision adds ~190 impl LOC and ~120 test LOC over the original estimate, primarily from the §4.7a template-edit cascade, §4.12 render caching, expanded §4.6 reserved-key guard (4 tiers), §4.4 cold-start handling, WP-CLI export/import, and i18n/RTL/uninstall in Phase 5.

## 11. Reviewer prompts

Original fresh-eyes review (2026-05-12) resolved most prompts. Remaining items for a second-pass or pre-implementation sanity check:

### Resolved by 2026-05-12 review (kept for traceability)

- **Behavior matrix.** Reviewer flagged that "16 cells" was a misnomer for 4 displayed rows; matrix is now a decision tree (§4.4 pseudocode) plus a flag-combination summary table; cold-start subcase documented with "Initialize from current value" button.
- **Cascade on template edit.** Resolved: cache-version bump per binding, no automatic post writes (§4.7a). Bulk Apply remains the explicit propagation tool. Admin notice (Q9) flags binding count when template edited.
- **Auto-seed safety.** Resolved: §4.6 reserved-key guard expanded to 4 tiers including post columns (`post_title`, etc.) and plugin-internal meta prefixes (`_spintax_*`).
- **Concurrent write race.** Resolved: race accepted as benign (worst case: one extra regenerate). No locking added.
- **Bulk Apply chunk_size.** Resolved: per-binding override, default 20, in binding form's Advanced section.
- **Capability mismatch.** Resolved: `manage_spintax_templates` throughout (was hardcoded to `manage_options`).
- **Signature key.** Resolved: keyed by `binding_id`, not `target.key`.
- **Render caching.** Resolved: per-post-per-binding cache added (§4.12), keyed by `(binding_id, post_id, binding_cache_version, variable_context_hash)`.
- **Phasing.** Resolved: minimal `ajax_test_binding` endpoint moved into Phase 2 (no JS yet); enables dogfooding before Phase 3 UI lands.

### Open for second-pass review (Q8, Q9)

- **Q8: Bulk Apply `chunk_size` defaults / configurability.** Confirm 20 default + per-binding override is right, or argue for a different default / scope.
- **Q9: Template-edit cascade notice.** Confirm the inline admin notice on `spintax_template` edit screen ("N bindings depend on this template — Run Bulk Apply to propagate") is the right scope, or argue for a different surface (dashboard widget, email, action bar).

### Open for any-time review (low priority)

- **Per-post metabox UX with many bindings.** A post with 5+ `per_post`-mode bindings shows 5+ textareas. Is collapsible-by-default the right call? Grouping by ACF group when applicable?
- **Predecessor data shape edge cases.** Migration helper assumes `ns4acf_selected_spintax_fields` is a clean array. What if it's `null`, serialized weirdly, contains stale references to deleted ACF fields? Phase 5 includes defensive handling; second-pass reviewer might suggest fixtures.
- **Test coverage gaps.** Phase 2 acceptance lists 8 explicit fixtures from the first review. Any additional scenarios worth pre-specifying? Specifically interested in: post-revisions interaction (does `save_post` for a revision trigger the binding?), import via WordPress Importer (`wp_import_post_meta` filter chain), block editor REST save lifecycle vs classic editor.

## 12. Multisite, REST, headless, observability

Aspects that don't fit in a single design section but matter for the V1 boundary.

### 12.1 Multisite

`spintax_bindings` is a per-site option (`get_option` / `update_option`, not `get_site_option` / `update_site_option`). Each subsite manages its own bindings independently. No network admin page in V1.

Implications:
- Bulk binding sync across the network requires WP-CLI export/import per site (`wp --url=site2 spintax bindings import --file=site1-bindings.json`).
- The 200-binding cap applies per subsite, not network-wide.
- Memory cost: each subsite's autoloaded `spintax_bindings` option loads on every request for that subsite. With ~100 subsites × ~50 bindings each × ~500 bytes = ~2.5MB total, but each subsite only loads its own slice.

Documented in `readme.txt` FAQ; multisite-specific code paths are not part of V1 scope.

### 12.2 REST API

Bindings are admin-only in V1. The Bindings option is not registered with the REST API; no `register_rest_route` calls for binding CRUD. Editing bindings requires admin access via the WP admin UI (or WP-CLI).

Rationale: bindings affect content rendering across the site; REST exposure expands the attack surface without a current concrete use case. Headless WP setups that need binding management can use WP-CLI on the server side (typical pattern).

REST API exposure tracked as a V2 candidate if user demand surfaces.

### 12.3 Headless WordPress

The renderer runs on the WP server regardless of front-end stack. Headless setups read rendered output via REST/GraphQL on the target fields normally — no binding-specific API needed because bindings pre-generate into standard meta keys (or ACF fields, which ACF's REST/GraphQL integrations already expose).

### 12.4 Observability

Bulk Apply and cron-triggered runs log to the existing `Spintax\Support\Logging` ring buffer. Each binding card surfaces a "Last run" panel:

```
Last run: 2026-05-12 14:23 (cron daily)
  47 wrote        — renders applied to empty/changed targets
   3 skipped      — manual edits preserved
   2 failed       — template "Hero block" not found (deleted?)
   1 cleared      — render returned empty, clear_on_empty=ON
```

Each line is clickable to filter the Logs view to that run. Failure rows link to per-post detail.

WP-CLI surfaces the same data via `wp spintax bindings status --binding=<id>`.

## 13. References

- **Predecessor plugin (do NOT replicate UX):** `C:\Users\Admin\Local Sites\testcom\app\public\wp-content\plugins\nested-spintax-for-acf`.
- **Architectural reference (UX + AJAX patterns):** `W:\Projects\wpci\plugin\src\Admin\MappingsPage.php`, `W:\Projects\wpci\plugin\src\Core\SourceResolver.php`, `W:\Projects\wpci\plugin\src\Jobs\`.
- **Plugin's existing core (reuse, don't reinvent):** `Spintax\Core\Render\Renderer`, `Spintax\Core\Cache\CacheManager`, `Spintax\Core\Cron\CronManager`, `Spintax\Support\Validators::sanitize_spintax()`.
- **Backlog entry (now a summary pointing here):** `docs/backlog.md#acf--post-meta-bindings`.
- **Roadmap reference:** `docs/product-roadmap-2026.md#53-planned-plugin-extensions-post-v1x`.
