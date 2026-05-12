# Release checklist

Run this end-to-end before tagging any `vX.Y.Z`. Skipping steps caused the 2.0.0 → 2.0.1 hot-fix; the cost of a same-day re-release (queue review, user trust, memory drift) is much higher than running the checklist.

The checklist has two tracks:

- **Patch (X.Y.Z, Z > 0)** — gates A, B, C only. Skip the integration-smoke track if the diff doesn't touch the relevant feature surface.
- **Major (X.Y.0 or X.0.0)** — all gates, including ACF integration smoke and the reviewer pass.

## Gate A — Local logic and lint (always required)

```bash
npm run env:start
npm run test:php       # All tests green. Currently 430 cases.
npm run lint:php       # PHPCS 0 errors, 0 warnings.
```

If either fails, fix before continuing. Do not tag.

## Gate B — Plugin Check (always required)

Plugin Check catches WP.org guideline issues PHPUnit doesn't see (output escaping, prepared statements, deprecated function use, prefix collisions, ABSPATH guards, etc).

```bash
# Option 1 — wp-admin GUI:
#   Tools → Plugin Check → Spintax → Check it!
#   Enable "Include experimental checks" before running.
#
# Option 2 — WP-CLI inside wp-env:
npx wp-env run cli wp plugin install plugin-check --activate
npx wp-env run cli wp plugin check spintax --include-experimental
```

**Required result:** 0 errors AND 0 warnings.

Common findings to expect on this codebase:
- `meta_query` triggers `WordPress.DB.SlowDBQuery.slow_db_query_meta_query` — already suppressed with justification comments in the codebase.
- Direct DB queries in `uninstall.php` already carry `phpcs:ignore` comments.

New findings: investigate root cause. Do **not** add `phpcs:ignore` to silence the linter without understanding what it's flagging.

## Gate C — Bindings integration smoke (major releases, or any patch touching `src/Bindings/` / `src/Admin/Bindings*`)

PHPUnit runs without ACF loaded. The 2.0.0 P1 "ACF binding persists without verified field_key" bug went unseen because the integration path it lives in is never executed by the unit suite. The smoke test below catches it.

**Setup** (once per dev environment):

```bash
# 1. Install ACF Free via wp-admin or:
npx wp-env run cli wp plugin install advanced-custom-fields --activate

# 2. Activate the spintax plugin and seed a template:
npx wp-env run cli wp plugin activate spintax
# Visit http://localhost:8892 — the demo template should already exist.

# 3. Create an ACF field group with one text field:
#    - Group title: "Hero"
#    - Field label: "Hero subtitle"
#    - Field name: "hero_subtitle"
#    - Field type: Text
#    - Location: Post type is equal to Post
#    - Save.
#
#    Note the field key shown in the URL or via:
npx wp-env run cli wp post list --post_type=acf-field --post_status=publish --fields=post_name
#    → returns "field_xxxxxxxxxxxxx"
```

**Smoke scenarios** (run each, observe expected behavior):

### S1 — ACF binding: form validation

1. Spintax → Bindings → Add New.
2. Scope: post.
3. Target: ACF field, key `hero_subtitle`. **Leave ACF field key blank.**
4. Try to save. **Expected:** error "ACF field key is required for ACF targets." Form preserves all other values.
5. Paste the wrong key (e.g. `field_zzzzzzzzzzzzz`). Try to save. **Expected:** error "ACF field key ... was not found."
6. Paste the right key. Try to save. **Expected:** success.

### S2 — Save_post seed

1. Create a new Post in wp-admin (any title; ACF field `hero_subtitle` left empty in the post editor).
2. Publish.
3. Refresh and inspect the ACF Hero subtitle value. **Expected:** populated with rendered template output.

### S3 — Manual-edit detection

1. Open the post from S2. Manually edit Hero subtitle to "human content".
2. Update the post.
3. Edit and re-update the post (no other changes).
4. Inspect Hero subtitle. **Expected:** still "human content" (binding skipped due to manual-edit detection because `preserve_manual_edits=ON` by default and `regenerate_on_save` was not enabled).
5. Edit the binding, enable `regenerate_on_save`, save the binding.
6. Re-update the post.
7. **Expected:** Hero subtitle still "human content" (signature check still preserves; binding skips with `SKIP_MANUAL_EDIT_DETECTED`).
8. Check `Spintax → Logs` for the skip line.

### S4 — Cross-kind duplicate rejection

1. Bindings → Add New. post_type=post. **Kind = Post meta**, key = `hero_subtitle` (same name as the ACF field from S1).
2. Try to save. **Expected:** error "Another binding already targets this field on this post type. ACF and post-meta bindings on the same field name collide because they write to the same database row."

### S5 — Test panel out-of-scope

1. Edit any binding.
2. Test panel: enter the ID of a **Page** (not a Post).
3. **Expected:** result = `skip_out_of_scope_type`, `would_write = false`.
4. Enter the ID of a draft post when the binding has `status = publish only`.
5. **Expected:** result = `skip_out_of_scope_status`.

### S6 — Bulk Apply Stale gating

1. Create 3 posts via WP-CLI:
   ```bash
   npx wp-env run cli wp post generate --count=3 --post_type=post --post_status=publish
   ```
2. Edit the binding's source template (Spintax → Templates → Edit). Save.
3. **Expected:** admin notice on the template-edit screen warns about N affected bindings. Binding card shows "Stale" badge.
4. Bulk Apply. **Expected:** stale badge clears, all 3 posts get updated values.
5. To test the failure path: temporarily break a binding (e.g. point template_id at a deleted template), re-bump the cache version (re-save the binding's source template), then run Bulk Apply via WP-CLI:
   ```bash
   npx wp-env run cli wp spintax bindings apply --binding=<id> --all
   ```
   **Expected:** stale badge **persists** because failures > 0.

If any scenario diverges from expected, file an issue (or self-fix) before continuing to gate D.

## Gate D — Reviewer pass (major releases only)

Cold-eyes review of the diff between the previous release tag and the proposed tag. For 2.0.1, that meant the agent re-read `docs/spec-acf-bindings.md` and the 5 reviewer findings before approving.

For an agent-driven review: dispatch a fresh agent with a prompt like "Review the diff `git diff vX.Y.Z-1...HEAD` against `docs/spec-acf-bindings.md` and the surrounding contracts. Flag P1/P2 bugs, missing test coverage, and contract violations. Don't suggest cosmetics."

Don't tag until reviewer findings are addressed or explicitly deferred to the next release.

## Tag and push

Only after all applicable gates pass:

```bash
npm run version:set -- X.Y.Z
git commit -am "Release X.Y.Z: <summary>"
git push origin main           # ci.yml validates
git tag vX.Y.Z
git push origin vX.Y.Z         # → release.yml + wporg-deploy.yml fire in parallel
```

## Verify after release

- GitHub Releases page shows the new version with attached ZIP.
- WP.org plugin page (after ~5 min) shows the new Stable tag and changelog.
- Download URL `downloads.wordpress.org/plugin/spintax.X.Y.Z.zip` returns HTTP 200.
- `wp plugin update spintax` on a separate test site picks up the new version.
