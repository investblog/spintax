# Spec — ACF / Post-meta Bindings

**Status:** draft, awaiting review.
**Target version:** 2.0.0.
**Author:** 301st.
**Last updated:** 2026-05-12.

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
    'kind' => 'acf_field',             // 'acf_field' | 'post_meta'
    'key'  => 'hero_subtitle',
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
    'acf_save_post' => true,           // disabled (and grayed out) if ACF inactive
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
| `auto_seed_empty` | ON | Write target only if currently empty/missing. The "set up once, populates new posts as they're created" mode. |
| `regenerate_on_save` | OFF | On every trigger, overwrite target with fresh render. Use for "rotate variant on every edit". |
| `preserve_manual_edits` | ON | Store `sha1(rendered_value)` in `_spintax_last_render_sig_<target.key>` per post. On regenerate, compare current target's hash to stored hash. If different, treat as manual edit; skip regeneration; log a notice. |
| `clear_on_empty` | OFF | If template renders to empty string, clear the target field. Useful for conditional content where the binding should "uninstall" if its inputs go away. |

Interaction matrix:

| auto_seed | regen_on_save | preserve_edits | Result on save_post |
|---|---|---|---|
| ON | OFF | ON | Empty target → render & write. Non-empty target → no-op. |
| ON | OFF | OFF | Empty target → render & write. Non-empty target → no-op. (same as above; preserve only matters when regenerating) |
| OFF | ON | ON | Always regenerate IF current target hash matches last-rendered hash. If it doesn't match → skip + admin notice. |
| OFF | ON | OFF | Always regenerate, blow away manual edits silently. |
| OFF | OFF | * | No-op. (Misconfigured; warn at form save with: "Binding has no write triggers — it will never run.") |

Bulk Apply path uses the same flags.

### 4.5 Field discovery (admin AJAX)

Three endpoints, all gated by `current_user_can('manage_options')` and a nonce.

**`ajax_acf_fields`** — for given `post_type`, walk `acf_get_field_groups( ['post_type' => $pt] )`, then `acf_get_fields( $group['key'] )` recursively through `sub_fields` and `flexible_content` layouts. Filter `type IN (text, textarea, wysiwyg)`. Return `[{name, label, group}]`. Cache 5 minutes via transient `spintax_acf_fields_<post_type>`. Direct port of `MappingsPage::collect_image_fields` (wpci, lines 397-427) with the image-type filter swapped for text-type.

**`ajax_meta_keys`** — for given `post_type`, `SELECT DISTINCT meta_key FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.post_type = %s LIMIT 200`. Cache 5 minutes via transient `spintax_meta_keys_<post_type>`. Port wpci L294-344 verbatim, including the PHPCS suppression with justification comment. Internal-key filter applied client-side and server-side.

**`ajax_template_list`** — `get_posts( ['post_type' => 'spintax_template', 'numberposts' => -1, 'post_status' => 'publish'] )`. Return `[{id, title, slug}]`. No caching (small dataset, frequently changed).

### 4.6 Reserved-key guard

Refuse as `target.key`: any key starting with `_wp_`, `_edit_`, `_oembed_`, plus exact-match list `_pingme`, `_encloseme`, `_thumbnail_id`. Block at form save with admin notice — not at write time. Mirrors `MappingsPage::is_reserved_meta_key` (wpci L571-580).

### 4.7 Triggers pipeline

- **`save_post`** (priority 20): find bindings matching `get_post_type($post_id)` → for each, run filters (status, behavior flags) → call `BindingApplier::apply($binding, $post_id)`. Skipped during autosave (`DOING_AUTOSAVE`), bulk edit, and REST batch import.
- **`acf/save_post`** (priority 20, runs after ACF saved its own fields): same path. Required because `save_post` fires before ACF persists sibling field values — without this hook, `expose_acf_siblings` would see pre-save values.
- **WP-Cron** per-binding schedule: register a unique hook `spintax_binding_cron_<binding_id>` with the chosen recurrence. Cron callback enqueues an Action Scheduler walk over all matching posts. Reuses existing `CronManager` infrastructure with binding-id as schedule key.

### 4.8 Admin UI

Top-level admin menu entry "Spintax" already exists. Add submenu "Bindings" after "Templates". Page renders one of two views:

**List view** (default): cards (no WP_List_Table — wpci uses cards for visual scanability of binding semantics). Each card: post type label + binding ID, source (template name or `per_post`), arrow, target (`acf:hero_subtitle` or `meta:_my_key`), preset triggers summary, action row (Edit / Bulk Apply / Delete).

**Form view** (`?action=new` or `?action=edit&binding_id=X`): sections matching `MappingsPage::render_form`:

1. **Scope** — post_type dropdown (public types), status (`any` | `publish`).
2. **Target field** — `target.kind` radio (ACF / post_meta); `target.key` field with AJAX-suggested options based on chosen post_type and kind.
3. **Source** — `source.mode` radio (template / per_post); when template: dropdown of Spintax CPT entries; when per_post: read-only display of the auto-derived sibling meta key + note explaining the inline metabox.
4. **Variables** — two checkboxes (post context / ACF siblings), plus a textarea for `#set` overrides with the same Validator surface as Settings global vars.
5. **Triggers** — `save_post`, `acf_save_post` (grayed out without ACF), `cron` dropdown.
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

Detects predecessor `nested-spintax-for-acf` data on plugin activation OR on demand via Tools → Spintax Migration:

- Scans `wp_postmeta` for `ns4acf_selected_spintax_fields` rows. For each post-with-selection:
  - For each selected field name, create a `per_post` binding (post type derived from the post, target.kind inferred — ACF if `acf_get_field_object` finds it, else `post_meta`).
  - Copy `spintax_<field>` sibling content → `_spintax_source_<field>`.
  - Copy `spintax_variables` → binding `variables.overrides`.
- Preview screen shows planned migrations before executing.
- One-shot operation; safe to skip (no auto-trigger on activation, only via Tools page).

Old plugin's data is **never deleted** by the migration — user uninstalls it themselves once migration is verified.

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
[ Triggers  ] save_post / acf_save_post / cron
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
| Reserved-key guard misses an edge case | low | Form-time validation, not write-time. Add comprehensive test fixtures for `_wp_*`, `_edit_*`, `_oembed_*`, manual exact-list, and arbitrary user-supplied weird keys. |

## 8. Open questions

These are the points where the design isn't fully locked and the reviewer's input would be most valuable.

- **Q1.** Inline `per_post` source editor: metabox vs ACF-injected element vs Gutenberg block? Current lean: **metabox** (simplest, no ACF Pro dep, no block dep). Reviewer: is there a strong reason to start with block-editor support?
- **Q2.** ACF sibling vars naming: `%acf_<field_name>%` vs flat `%<field_name>%`. Current lean: **prefixed** to avoid collision with global vars. Reviewer: clearer disambiguation pattern?
- **Q3.** Storage: one autoloaded option `spintax_bindings` with all bindings array, vs per-binding option `spintax_binding_<id>`. Current lean: **single autoloaded** for typical scale (<100 bindings). Reviewer: tipping point where per-binding is better?
- **Q4.** Cron granularity: per-binding schedules (proposed) vs reuse of per-template cron in CPT (existing). Current lean: **per-binding** — a single template across multiple bindings may want different cadences. Reviewer: is the duplication worth it?
- **Q5.** Predecessor migration: opt-in via Tools page (proposed) vs auto-suggest banner on detection. Current lean: **opt-in only** (no surprises). Reviewer: agree?
- **Q6.** `preserve_manual_edits` storage: per-target meta key `_spintax_last_render_sig_<target.key>` (proposed) vs single per-binding-and-post meta. Current lean: **per-target** for transparency in postmeta inspection. Reviewer: any objection?
- **Q7.** Per-binding cache: skip entirely in V1 (rely on the underlying template's cache for `template` mode; render fresh for `per_post` mode). Reviewer: enough?

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
- Signature meta `_spintax_last_render_sig_<key>` lifecycle.

**Acceptance:**
- Behavior matrix tested exhaustively (`auto_seed` × `regenerate_on_save` × `preserve_manual_edits` × `clear_on_empty` = 16 cells, each PHPUnit'd).
- Autosave / bulk-edit / REST-import don't trigger bindings.
- Manual edit (target value's hash ≠ last-render hash) → preserve_manual_edits=ON skips, OFF clobbers.
- Template-mode binding works when its template is later edited (no stale render).
- Per-post metabox saves source content with nonce + capability check.

**Exit test:** create a `per_post` binding on Posts → `acf_field:hero_text`. Author writes spintax in metabox, saves post → field populated. Hand-edit field → save post → preserved. Toggle `preserve_manual_edits=OFF` → save → clobbered.

### Phase 3 — AJAX field discovery + Test panel

**Scope:**
- `Spintax\Admin\BindingsAjax::meta_keys` — direct port of wpci `ajax_meta_keys`.
- `Spintax\Admin\BindingsAjax::acf_fields` — adapt wpci `collect_image_fields` walker, text-type filter.
- `Spintax\Admin\BindingsAjax::template_list` — list `spintax_template` CPT.
- `Spintax\Admin\BindingsAjax::test_binding` — dry-run endpoint.
- Form-side JS: dependent dropdowns (kind → key suggestions), Test panel UI.

**Acceptance:**
- AJAX endpoints all gated by `manage_options` + nonce; PHPUnit covers unauth / wrong-nonce / wrong-post-type paths.
- ACF active → field walker enumerates flat + nested (sub_fields, flexible_content); ACF inactive → endpoint returns empty array gracefully.
- Test panel: same path as `apply()` but returns plan instead of executing; no side effects (verified by snapshot of postmeta before/after).

**Exit test:** form's "Target Key" input shows live suggestions as user types; "Test" with post ID shows accurate `would_write` flag matching what apply() would actually do.

### Phase 4 — Bulk Apply + acf_save_post + cron + WP-CLI + AcfSiblingsSource

**Scope:**
- Action Scheduler integration (graceful fallback to WP-CLI if AS missing).
- `Spintax\Bindings\Triggers\AcfSavePostTrigger` — hooks `acf/save_post` priority 20.
- `Spintax\Bindings\Triggers\CronTrigger` — registers `spintax_binding_cron_<id>` hooks per binding, walks via Action Scheduler.
- `Spintax\Core\Variables\AcfSiblingsSource` — exposes `%acf_<name>%` for sibling text/textarea/wysiwyg fields in the same group.
- WP-CLI: `wp spintax bindings list|apply|test --binding=<id> [--post=<id>|--all]`.

**Acceptance:**
- Bulk Apply on a binding with 500 matching posts completes via AS in chunks; logs progress; respects `preserve_manual_edits`.
- Cron schedule changes (off → daily, daily → off) update the registered hook correctly.
- ACF siblings var integration: `expose_acf_siblings=true` on an ACF binding sees other text fields in the same group but not repeater rows.
- WP-CLI `wp spintax bindings apply --binding=X --all` produces same result as Bulk Apply UI.

**Exit test:** bulk-apply on a fresh post type with 100 posts → 100 fields populated; one of them was hand-edited beforehand → that one preserved; cron daily triggers next-day regeneration without manual intervention.

### Phase 5 — Migration helper + docs + ship

**Scope:**
- `Spintax\Admin\MigrationPage` under Tools menu.
- Detector: scans postmeta for `ns4acf_selected_spintax_fields`; groups by post type.
- Preview: shows planned bindings before commit (no DB writes until confirmed).
- Importer: creates bindings + copies sibling meta + copies variables.
- Docs: `spec-v1.md` addendum, `readme.txt` FAQ entry, spintax.net guide page `/docs/acf-bindings/`.
- Screenshot pass for WP.org.
- Version bump to 2.0.0; CHANGELOG + Upgrade Notice.

**Acceptance:**
- Migration preview is accurate (manual diff against predecessor data on a test site).
- Migration is idempotent (run twice → second run no-op).
- Predecessor plugin's data isn't touched (user uninstalls it manually after verifying).
- Spec-v1 documents the new binding model; readme upgrade notice flags it as a major feature.

**Exit test:** install predecessor on a test site with sample data → install new plugin → run migration → verify bindings match expected shape → original `ns4acf_*` data untouched → uninstall predecessor → bindings still work.

## 10. Effort estimate

| Phase | LOC (impl) | LOC (tests) | Calendar (rough, dedicated) |
|---|---|---|---|
| 1. Data layer + admin | ~250 | ~80 | 2-3 days |
| 2. Resolver + applier + save_post | ~400 | ~250 | 4-5 days |
| 3. AJAX + Test panel | ~300 | ~120 | 2-3 days |
| 4. Bulk + cron + WP-CLI + AcfSiblings | ~350 | ~150 | 3-4 days |
| 5. Migration + docs + ship | ~200 + docs | ~100 | 2 days |
| **Total** | **~1500** | **~700** | **~13-17 working days** |

Calendar assumes single dedicated developer with no other interruptions. Realistic wall-clock with reviews / iteration / unexpected ACF edge cases: ~3-4 weeks.

## 11. Reviewer prompts

For the fresh-eyes review pass — specific things to push back on:

- **Behavior matrix correctness.** Re-verify the 16-cell behavior table (Section 4.4). Are there cells where the documented behavior contradicts the intent? Are there cells where the "user mental model" would expect something different?
- **Source-mode interaction with template-CPT updates.** A `template`-mode binding renders by fetching its template's content. If the template is edited after the binding has populated 200 fields, what should happen? Current design: nothing automatic; user runs Bulk Apply. Is that right, or should template-edit trigger downstream regenerations? (Cascade invalidation already exists in CacheManager for this kind of thing.)
- **Auto-seed safety.** Is `auto_seed_empty=ON` default actually safe? Consider: editor creates a binding for `post_title` (against the reserved-key guard, but pretend it's allowed). Does anything blow up? Should we explicitly block `post_title`, `post_content`, `post_excerpt` as targets (not just internal `_*` meta keys)?
- **Concurrent write race.** Two save_post fires in quick succession (e.g., user clicks Update twice fast). Can the second fire read stale `last_render_sig` before the first finished writing? Mitigation: file-level locking via `wp_cache_set` with short TTL? Or accept the race as benign (worst case: one extra regenerate)?
- **Per-post metabox UX.** A post has 3 bindings of mode `per_post`. The metabox shows 3 textareas. Is that visually clear? Is there a better grouping (collapsible, by ACF group, etc.)?
- **Predecessor plugin data shape edge cases.** Migration helper assumes `ns4acf_selected_spintax_fields` is an array of field names. What if it's serialized weirdly, contains stale entries pointing to deleted ACF fields, or is empty/missing? Add defensive handling spec.
- **Bulk Apply throttling.** Action Scheduler chunk_size=20 — is that right? For a render-heavy template, 20 may be too many per chunk. For a trivial template, 20 is too conservative. Should the chunk size be configurable per-binding or per-site?
- **Test coverage gaps.** What scenarios am I not thinking to test? Specifically interested in: malformed binding option (e.g., manual user edit of the option), missing template_id in template-mode, post type that no longer exists, deleted ACF field.
- **Phasing dependencies.** Phase 3 depends on Phase 2 (Test panel calls applier). Is there a cleaner cut where Phase 2 ships separately as a beta to dogfood the apply path before adding AJAX UI?

## 12. References

- **Predecessor plugin (do NOT replicate UX):** `C:\Users\Admin\Local Sites\testcom\app\public\wp-content\plugins\nested-spintax-for-acf`.
- **Architectural reference (UX + AJAX patterns):** `W:\Projects\wpci\plugin\src\Admin\MappingsPage.php`, `W:\Projects\wpci\plugin\src\Core\SourceResolver.php`, `W:\Projects\wpci\plugin\src\Jobs\`.
- **Plugin's existing core (reuse, don't reinvent):** `Spintax\Core\Render\Renderer`, `Spintax\Core\Cache\CacheManager`, `Spintax\Core\Cron\CronManager`, `Spintax\Support\Validators::sanitize_spintax()`.
- **Backlog entry (now a summary pointing here):** `docs/backlog.md#acf--post-meta-bindings`.
- **Roadmap reference:** `docs/product-roadmap-2026.md#53-planned-plugin-extensions-post-v1x`.
