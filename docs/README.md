# FolderFolio — documentation

Planning, research and design documents. Not shipped in the plugin ZIP —
`bin/build-zip.sh` uses an allowlist, so this directory is excluded by construction.

## Plan

| Document | What it is |
|---|---|
| [`00-start-here.md`](00-start-here.md) | **Start here.** What state the plugin is in, how to build and test it, the three rules that shaped the code, and the WordPress traps that already cost a day each |
| [`../DESIGN-TO-CODE.md`](../DESIGN-TO-CODE.md) | The design handoff the admin UI is being rebuilt against: screens, measured geometry, row states, the keyboard and ARIA contract, definition of done |
| [`architecture-plan.md`](architecture-plan.md) | Tech stack, layering, data model, REST surface, developer platform, gallery block, build, testing, and the phase-by-phase sequence to 1.0 |
| [`design-handoff.md`](design-handoff.md) | The *input brief* that produced the design — patterns to take, anti-patterns to avoid. Superseded as a spec by `DESIGN-TO-CODE.md`; kept as the record of what was asked for |
| [`m1-research-and-design-plan.md`](m1-research-and-design-plan.md) | The research plan these came out of |
| [`m2-importer-matrix.md`](m2-importer-matrix.md) | Verified schemas and detection keys for every migration source |

## Competitive research

Observed live in a WordPress 7.1 / PHP 8.3 playground with 41 attachments and trees 4–5 levels
deep — measured and read from source, not taken from documentation.

| Document | Plugin | Installs |
|---|---|---|
| [`research/01-filebird.md`](research/01-filebird.md) | FileBird Lite 6.5.8 | 200,000+ |
| [`research/02-real-media-library.md`](research/02-real-media-library.md) | Real Media Library Lite 4.23.4 | 100,000+ |
| [`research/03-folders-premio.md`](research/03-folders-premio.md) | Folders (Premio) 3.2.0 | 90,000+ |
| [`research/04-catfolders.md`](research/04-catfolders.md) | CatFolders Lite 2.5.6 | 6,000+ |

`screenshots/` holds the visual references those documents refer to.

## Code history

| Document | What it is |
|---|---|
| [`code-analysis-2026-09-16.md`](code-analysis-2026-09-16.md) | First full read of the codebase |
| [`merge-status-2026-09-16.md`](merge-status-2026-09-16.md) | The `src/` → `includes/` merge |
| [`deep-review-2026-09-16.md`](deep-review-2026-09-16.md) | Full review, the fixes applied, and what is still open |

## A note on this directory

The architecture plan reserves `docs/` for the **public developer API documentation** that 1.0
ships (§8.4). When that lands it goes in `docs/api/`, and these planning documents stay where
they are. The API docs are the ones whose examples are lifted from passing tests; everything
here is a working record and may age.
