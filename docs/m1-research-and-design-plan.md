# FolderFolio — competitive research → design plan

Agreed sequencing: M1 (UI/design) first, M2 (migration wizard) second. But the research pass
feeds **both**, so we capture two payloads per plugin in one sitting: the UX patterns for M1,
and the schema + activation signature for M2.

## The shortlist

| Plugin | Slug | Installs | Rating | Notes |
|---|---|---|---|---|
| FileBird | `filebird` | 200,000+ | 4.7 (1,121) | market leader, already installed |
| Real Media Library Lite | `real-media-library-lite` | 100,000+ | 4.8 (291) | **subfolders = Pro**; ships JS + REST APIs |
| Folders (Premio) | `folders` | 90,000+ | 5.0 (1,518) | also foldes posts/pages — different scope |
| CatFolders | `catfolders` | 6,000+ | 4.4 (21) | **subfolders = Pro**; already installed |

Deliberately excluded: WP Media Folder (JoomUnited) and HappyFiles Pro — not free on wp.org,
so we can't inspect them hands-on. We can still write importers for them later from their
schemas if anyone asks.

### The finding that should drive the design

Real Media Library and CatFolders both put **subfolders behind a paywall**. FolderFolio's
"unlimited nested folders, no tiers" is therefore the actual product wedge, not marketing.
The design consequence: deep trees must be genuinely pleasant — indentation that stays
readable at depth 4+, fast expand/collapse, a working search, and breadcrumbs so you always
know where you are. That is precisely the surface competitors have no incentive to polish.

## Phase 0 — setup (needs your go-ahead)

Install `real-media-library-lite` and `folders` into the playground. FileBird and CatFolders
are already there. I'd download from wp.org in the container and write them into
`wp-content/plugins/` — say the word and I'll list exact versions before anything lands.

Ground rules for the pass:
- **One competitor active at a time.** Two folder plugins both injecting into `upload.php`
  fight over the same screen — which is exactly what you noticed.
- **FolderFolio deactivated throughout**, so their UI is never polluted by ours.
- Seed a realistic library first: ~40 attachments, a tree 4 levels deep, one folder with 100+
  files. Empty libraries hide every layout problem worth finding.

**Residue warning, and an opportunity.** Each plugin leaves its tables and options behind on
deactivation. That makes our current `SHOW TABLES LIKE` detection claim all four are
"installed" forever, which is a real M2 bug — detection must mean *active*, not *ever
present*. The residue is also the perfect fixture for testing exactly that, so I'd keep it
rather than clean up.

## Phase 1 — structured inspection

A fixed rubric per plugin, so findings are comparable rather than impressionistic. Same
states, same viewport, screenshots captured for Claude Design to work from.

**Layout & interaction**
1. First run / empty state — what does it say, what does it ask you to do first
2. Tree at depth 4+ with 15 folders — indentation, density, scroll behaviour
3. Create folder — inline edit, modal, or `prompt()`; where focus lands
4. Rename / delete — and what it does with the contents
5. Filter by folder — grid **and** list mode; what happens to the count links
6. Drag file(s) onto a folder — cursor, drop-target affordance, multi-select, undo
7. Bulk select → assign/move
8. Media modal in the post editor — the cramped case that breaks most implementations
9. What number sits next to a folder, and does it count descendants
10. Narrow viewport (~900px) and a collapsed admin menu

**Technical, for M2**
- Table names and column shapes (CatFolders: `catfolders` + `catfolders_posts`;
  FileBird: `fbv_folders`/`fbv_attachment_folder`; RML and Folders TBD)
- How to detect **active** vs merely installed
- Whether they ship their own import UI worth learning from
- Whether folders are one-per-file or many-per-file — decides whether our import is lossy

## Phase 2 — synthesis

One handoff doc: layout patterns worth taking, anti-patterns to avoid, a component inventory
(tree, row, toolbar, drop target, breadcrumb, empty state, counts), the nesting-wedge
argument, annotated screenshots, and the M2 importer matrix as a table.

## Phase 3 — design

That doc seeds Claude Design for brand identity and the app design: palette, type, iconography,
the folder-row component at several depths and states, and the full Media Library screen in
grid and list.

## Phase 4 — build M1

Per the decisions already made:
- **Left rail inside `upload.php`**, one component that also ports to the modal later.
- **Drag files onto folders only.** Folder reordering and re-nesting stay menu actions.
- **Hybrid multi-folder:** drag moves; a separate "Add to folder" bulk action files a copy.

Known work this implies, from today's review:
- Replace `prompt()`/`alert()` — required for inline folder creation, and they currently make
  the plugin impossible to drive programmatically at all.
- Fix the count badge to mean attachments (#10) and the search that hides matching
  children (#9).
- Keyboard and ARIA for the tree (#22) — spans with click handlers today.
- One `/tree` fetch per page, not two (#23).

## Open question for later, not now

Should the tree show "All media" and "Unassigned" as first-class rows? The server already
supports unassigned via folder `0`. Worth watching how the competitors handle it before
deciding.
