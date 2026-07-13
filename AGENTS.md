# AGENTS.md

**`CLAUDE.md` is the source of truth for this repo.** Read it first: architecture, the locked
bindings contracts, the spintax syntax, the post-processing pipeline, the common traps, and the
release protocol all live there.

This file is a pointer, not a second copy. It used to *be* a second copy — a pre-1.0 brief that
still called ACF bindings a "future feature" (they shipped in 2.0.0 and are now a flagship surface)
and told you to cache with WP transients (the plugin deliberately uses **no transients** — WP Object
Cache API only). That is what a duplicate becomes. Keep this file short; put the truth in CLAUDE.md.

## Non-negotiables

**Before every push** — both green, zero errors *and* zero warnings:

```bash
npm run test:php     # PHPUnit
npm run lint:php     # PHPCS
```

**If you touched the engine** (`plugin/src/Core/Engine/`, `plugin/src/Core/Render/`) there is a
third gate, and **no CI runs it**: the shared cross-engine golden corpus. This engine is ported into
two other projects, and the corpus is the only machine check that all three agree. An engine change
is not finished until it lands in every engine *and* gains a corpus **fixture** — a unit test in one
engine binds only that engine. That gap is exactly how three post-process defects reached users
(punctuation runs split apart in every language, broken `mailto:` links, Spanish sentences losing
their capital). Recipe and rationale: CLAUDE.md → "Pre-push checklist".

- `@spintax/core` (TS / npm) — `W:\Projects\spintax-js`. Feature work there goes through **PRs**.
- OpenCart port — `W:\projects\spintax-opencart`. Its kernel is a byte-identical copy of
  `plugin/src/Core/Engine`, enforced by its own `PortIntegrityTest`.

**Before tagging a release** — work through `docs/release-checklist.md` section by section. Plugin
Check `--include-experimental` must be 0/0 on the shipping surface. A tag push is **irreversible and
outward-facing**: it deploys to the WordPress.org SVN repository. Confirm with the user first.

## Conventions

- Commit straight to `main` here once the gates are green; do **not** open a PR unless asked
  (single-dev project). The sibling repos differ — see above.
- The version lives in three places and must stay in sync: `npm run version:set -- X.Y.Z`.
- Template source is raw spintax: sanitise **output**, never input. `wp_kses_post()` on template
  input destroys the markup.

## Map

| | |
| --- | --- |
| Architecture, contracts, traps | `CLAUDE.md` |
| Product spec | `docs/spec-v1.md` |
| Bindings design (locked contracts) | `docs/spec-acf-bindings.md` |
| Runtime-var trust levels (T1/T2 shielding) | `docs/adr-0001-runtime-var-trust-levels.md` |
| WooCommerce (shipped + Phase 3 spec) | `docs/spec-woocommerce*.md` |
| Release protocol | `docs/release-checklist.md` |
| Backlog | `docs/backlog.md` |
