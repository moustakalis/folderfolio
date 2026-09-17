# FolderFolio — design-to-code handoff

Implementation brief for the UI designed in this project. Written against
`moustakalis/folderfolio` v0.2.0 (WordPress 6.4+, PHP 8.1+, React via
`wp-element`, TanStack Query + Zustand).

Two bundles accompany this document:

- **`ui-assets.zip`** — drop-in files, repo-root relative: `.wordpress-org`,
  `assets/brand/`, `assets/src/core/_tokens.css`, `includes/Admin/Brand.php`.
  Its own README covers placement and the rasteriser. Unzip it first; the
  token names used throughout this document come from it.
- **`design_handoff_folderfolio.zip`** — the design references:
  `FolderFolio UI.dc.html` (13 screens) and `FolderFolio spec.dc.html`
  (18 sections). Open either directly in a browser.

## The design files are references, not code

`FolderFolio UI.dc.html` and `FolderFolio spec.dc.html` are prototypes showing
intended look and behaviour. Implement them in the plugin's own environment —
PHP templates plus React via `wp-element`, styled with the plugin's stylesheet
— using WordPress admin conventions. Do not port the HTML. Do not copy `_ds/`
into the plugin: it is the design system the mocks were drawn with, not a
dependency.

Two things in the mocks are scaffolding rather than design:

- **Archivo.** The board is a design document, so it sets the system's
  typeface. The plugin must load no webfont — use WordPress's admin system
  stack.
- **Grayscale photographs.** A spec-board device. Real thumbnails render
  unfiltered. `design` is a placeholder for media thumbnails.

Fidelity is **high**: colours, spacing, row geometry and type sizes are final
and measured. Use core's own styles wherever they already provide the control
(`.button`, `.wp-filter`, `select`, `.wp-list-table`) and reserve custom CSS
for the rail and the parts core has no equivalent for.

## Screens

Numbers match the board's left nav. The scheme picker below the nav switches
Fresh / Modern / Midnight; screens 03, 04 and 06 are interactive.

| # | Screen | Build note |
|---|---|---|
| 00 | What ships today | v0.2.0 recreated from `assets/src/core/admin.css` and `folder-tree.ts`, annotated with six deltas. Reference only — nothing in it is to be built. |
| 01 | Mark and palette | The chosen mark (S9), four rejected variants, ten folder colours, the mark in context. Assets are already cut in `ui-assets.zip`. |
| 02 | Folder row, depth 1–5 | **Build this first.** Every depth, nine states, both count modes. The other twelve screens are composition on top of it. |
| 03 | Media Library, grid | The whole screen: admin bar, menu, 300px rail, breadcrumb, drill-down cards, filter row, file grid. |
| 04 | Rail collapsed | 28px tab, reopen chevron, vertical label. |
| 05 | Rail states | Empty, inline create, drag-over, loading, error, undo toast, migration offer. |
| 06 | Media Library, list | Folder chip strip, bulk row, added Folders column. |
| 07 | Migration wizard | Detect, preview, run, summary. |
| 08 | Settings | Settings / Import / Status, including the roles matrix. |
| 09 | Gallery block | Inspector tree at 268px, editor placeholder, front-end grid. |
| 10 | Media modal | The tree at 240px inside the post editor's media frame. |
| 11 | Open states | Breadcrumb overflow at three widths, Sort menu, All-folders select, bulk flyout, colour picker. |
| 12 | wp.org assets | Banner 772×250 (ships) and 1544×500, icon sheet. |

## Suggested order

1. `_tokens.css` imported, `Brand.php` wired into `add_menu_page()` — the
   cheapest visible proof the scheme plumbing works. Check all three schemes
   before writing any component CSS.
2. **Screen 02**, the folder row, to the pixel: geometry, nine states,
   keyboard, ARIA. Everything else composes this.
3. The rail shell — mount point, sticky, resize, collapse (screens 03, 04).
4. Header, toolbar, fixed rows, search (screen 03 rail).
5. Breadcrumb, drill-down cards, filter-row controls (screens 03, 11).
6. Create / rename / delete with the undo toast (screen 05).
7. Drag and drop, then the bulk add-to-folder flyout (screens 05, 11).
8. List mode (screen 06).
9. Media modal and block inspector — the cramped cases, which test whether the
   row component's geometry is genuinely parameterised (screens 09, 10).
10. Wizard and settings (screens 07, 08).

## The rail — mount point

Mount as a **sibling of `#wpbody-content` inside `#wpbody`, before it**.

```
#wpbody
  ├ div.folderfolio-rail       ← sticky, 300px default, resizable
  ├ div.folderfolio-resize     ← 5px drag handle on the rail's right edge
  └ #wpbody-content
```

`position: sticky`, full viewport height, its own scrollbar. v0.2.0 renders
into `all_admin_notices` and repositions from JS; that is one of the six
deltas on screen 00 and should go.

## Geometry

| Property | Value |
|---|---|
| Rail width | `var(--ff-rail-w)` 300px default, resizable, collapses to a 28px tab |
| Row height | `var(--ff-row-h)` 36px |
| Indent per level | `var(--ff-indent)` 24px — `padding-left: 12px + 24px × depth` |
| Row padding | 12px left at depth 0, 10px right |
| Switcher slot | `var(--ff-switcher)` 20 × 20px, **reserved on leaves too** |
| Guide lines | 1px verticals at `x = 22 + 24n`, one per ancestor level, `var(--ff-guide)` |
| Folder icon | 16px Lucide `folder` / `folder-open`, 2px stroke |
| Count tag | 11px tabular, `padding: 1px 6px`, square — no radius |
| Row gap | 6px between switcher, icon, name, tag |
| Header | "Folders" label (10px, 0.12em, uppercase, 700) + 28px primary button |
| Toolbar | Four 34px labelled buttons — Rename, Delete, Sort, More — disabled until selection |
| Fixed rows | All media, Unassigned — 36px, live counts |
| Search | 30px field, 1px border, Lucide `search` at 14px |
| Footer | 32px: folder total left, Collapse right |

Zero border radius everywhere. 1px hairlines within a panel (`--ff-line`), 2px
rules between major sections (`--ff-rule`). Spacing on a 4px scale.

**The two cramped cases** prove the row is parameterised rather than
hard-coded:

| | Rail | Media modal | Block inspector |
|---|---|---|---|
| Width | 300px | 240px | 268px |
| Row height | 36px | 32px | 32px |
| Indent | 24px | 20px | 20px |
| Toolbar | Four labelled buttons | One overflow button | — |
| Count | Square tag | Plain text | Omitted (count is in the block) |
| Scroll cap | Viewport | Frame | 192px — six whole rows |

At depth 5 in the 300px rail the name gets **110px**, measured: rail 300 −
118 padding (`12 + 24 × 4` left, 10 right) − 20px switcher − 16px icon − 18px
count tag − three 6px gaps.

> This originally read 132px. That figure left out the count tag and one gap;
> the arithmetic never reached it. Accepted at 110px — "On red", "Dark
> variants" and most real folder names fit, and longer ones ellipsize, which
> is the normal behaviour of a tree at depth. Reaching a true 132px would need
> the indent down at roughly 18px, which buys 22px of name at the cost of the
> structure the guide lines carry. Re-measured by
> `design/preview/folder-row.html` on every open.

## Row states

| State | Treatment |
|---|---|
| Default | Ink on ground; colour only on the icon |
| Hover | `var(--ff-hover)` wash, no border change — nothing shifts |
| Selected | `var(--ff-wash)` wash, 3px flush-left `var(--ff-sel)` bar, name 700, filled count tag |
| Focus | `outline: 2px solid var(--ff-sel); outline-offset: -2px` — inset so the rail edge does not clip it |
| Drag over | `inset 0 0 0 2px var(--ff-sel)` frame, **not** a fill; tag previews the delta (`+3`) |
| Renaming | Same geometry, input in place of the label |
| Leaf | No chevron, slot retained — names never jitter |
| Loading | Three ghost rows, no spinner |
| Error | Framed block naming the failed endpoint, with Retry |

Selection owns the row, which is why folder colour never does — see below.

## Colour

Tokens ship in `assets/src/core/_tokens.css`. **No hex literals in component
CSS.** Eight schemes ship with core; a literal will be wrong in seven.

| Token | Fresh | Modern | Midnight |
|---|---|---|---|
| `--ff-sel` | `#2271b1` | `#3858e9` | `#69a8bb` |
| `--ff-sel-hover` | `#135e96` | `#1d35b4` | `#89bac9` |
| `--ff-on-sel` | `#fff` | `#fff` | `#1d2327` |
| `--ff-bar` | `#1d2327` | `#1e1e1e` | `#26292c` |
| `--ff-canvas` | `#f0f0f1` | `#f0f0f1` | `#32373c` |
| `--ff-panel` | `#fff` | `#fff` | `#26292c` |
| `--ff-ink` | `#1d2327` | `#1d2327` | `#f0f0f1` |
| `--ff-muted` | `#50575e` | `#50575e` | `#d7dade` |
| `--ff-dim` | `#646970` | `#646970` | `#b4b9bd` |
| `--ff-off` | `#8c8f94` | `#8c8f94` | `#8c8f94` |
| `--ff-line` | `#dcdcde` | `#dcdcde` | `#4f5559` |
| `--ff-guide` | `#e6e6e8` | `#e6e6e8` | `#3c4145` |
| `--ff-wash` | `#f0f6fc` | `#f0f6fc` | accent at 20% |
| `--ff-hover` | `#f6f7f7` | `#f6f7f7` | white at 7% |
| `--ff-field` | `#fff` | `#fff` | `#32373c` |

Plus `--ff-danger` `#d63638`, `--ff-danger-text` `#b32d2e` on white, and
`--ff-accent-text` `#135e96` for accent at body size on white.

The remaining five core schemes fall through to Fresh deliberately: they differ
from Fresh only in accent, and inheriting Fresh is better than inheriting the
wrong accent.

**Folder colours** — ten fixed swatches, `--ff-folder-*`, no free picker: a
user hex cannot be guaranteed legible across eight schemes. Each has a
dark-row pair under `.admin-color-midnight`. Colour paints the **16px icon
only, never the row** — that is what keeps selection readable everywhere.

## Type

WordPress admin system stack. 13px rows and body, 12.5px controls, 11px count
tags and meta, 10px uppercase eyebrows at 0.12em tracking, 23px page title at
weight 400 (core's own). No webfont.

## Icons

Lucide, 16px in rows and 20px in toolbars, 2px stroke. Set needed: `folder`,
`folder-open`, `chevron-right`, `chevron-down`, `search`, `arrow-up-down`
(sort), `pencil` (rename), `trash`, `ellipsis` (more), `rotate-ccw` (undo),
`grip-vertical` (drag).

## Interactions

- **Select a folder** — filters the library, writes `?folderfolio_folder=<id>`
  with `replaceState` in **both** grid and list, updates breadcrumb and cards.
  Arriving at a deep URL cold must expand the tree to that folder and scroll it
  into view.
- **Expand / collapse** — chevron or Space. Icon and folder form both follow
  state.
- **Search** — a control separate from create. Typing replaces the tree with a
  flat result list: name over its full path (11px), count right-aligned. No
  match shows one line naming the query.
- **Create / rename** — inline, in the row where the folder will live, parent
  named beside explicit Save and Cancel. Enter saves, Escape cancels. No modal;
  no `window.prompt()` (v0.2.0 uses it — delta on screen 00).
- **Delete** — no confirm dialog. Acts immediately, then a toast at the
  bottom-left of the content area: `#1d2327` sheet, one sentence, a 2px bar
  draining the 5s window, Undo. Hover pauses. `prefers-reduced-motion` replaces
  the bar with a numeral.
- **Drag** — files onto folders only; moves. The bulk "Add to folder" flyout
  **adds** a membership (checkboxes, not radios) and never removes an existing
  one. Drop targets are the tree row and the drill-down card, both with the
  same 2px frame.
- **Breadcrumb overflow** — root and current folder are never dropped, the
  parent is kept while it fits, the rest collapse into a 26 × 22px ellipsis
  button listing hidden levels indented.
- **Counts** — inherited by default, direct as a setting. In direct mode show
  the own count plus the subtree figure in lighter ink (`0 / 12`) with a dashed
  tag; **never a bare `0` on a parent holding files** (this is the bug the
  market has). Mutation responses return the full counter map so every badge
  refreshes without a second request.
- **Optimistic everything** — drag, rename and move apply immediately, with
  rollback on failure.
- **Never quietly rewrite** what the user asked for. If something cannot
  happen, say so where it was attempted.

## Keyboard and ARIA

The tree is a **single tab stop**. Focus is tracked separately from selection,
so arrowing does not re-filter.

| Key | Does |
|---|---|
| Down / Up | Next / previous **visible** row, crossing levels |
| Right | Expand; if already expanded, move to first child |
| Left | Collapse; if already collapsed, move to parent |
| Home / End | First row / last visible row |
| Enter | Select — filters, writes URL, updates breadcrumb |
| Space | Toggle expand **without** selecting |
| Printable keys | Type-ahead, 1s buffer; does not open the search field |
| F2 | Rename in place |
| Delete | Delete + undo toast |
| Escape | Cancel inline row → close menu → clear search, in that order |
| `*` | Expand every sibling at the focused level |

Roles: `tree` of `treeitem` with `aria-expanded`, `aria-level`,
`aria-selected`, and an `aria-label` of name + count ("Brand, 12 files"). Only
the focused row carries `tabindex="0"`. Selection announces through a polite
live region; the undo toast is assertive.

## State

| Key | Shape |
|---|---|
| `folders` | Tree — adjacency list + materialised path, so breadcrumbs need no queries |
| `selectedId` | `null` = All media, `0` = Unassigned, else folder id; mirrored in the URL |
| `expandedIds` | Set; auto-expanded to `selectedId` on cold load |
| `query` | Search string; non-empty switches the rail to result-list mode |
| `railOpen`, `railWidth` | Persisted per user |
| `countMode` | `inherited \| direct` |
| `pendingUndo` | `{ action, payload, expiresAt }` |
| `inlineRow` | `{ mode: 'create' \| 'rename', parentId, value }` |

REST surface already exists: `/folderfolio/v1/tree`, `/folders`,
`/folders/{id}`, `/folders/{id}/move`, `/attachments/assign`,
`/attachments/unassign`, `/attachments/bulk-move`, `/import/detect`,
`/import/{importer}`.

## Definition of done

- The rail is a sibling of `#wpbody-content`, sticky, resizable, collapsible —
  not injected into a notices hook.
- All three designed schemes are correct, and the five undesigned ones fall
  through to Fresh without a stray colour.
- Depth 5 in a 300px rail leaves 110px for the name (measured; the 132px
  first stated here omitted the count tag and a gap).
- The tree is one tab stop, fully operable from the keyboard, and announces
  selection.
- No parent folder holding files ever shows a bare `0`.
- Deleting a folder never opens a dialog; it always offers undo.
- Bulk add-to-folder adds, never moves.
- The menu icon inherits the scheme's icon colour and is never red.
- `?folderfolio_folder=<id>` round-trips in grid and list, including cold load.
- No webfont is enqueued, and no hex literal appears in component CSS.

## Still open

- The mark is drawn, chosen and in use, but it is my construction rather than a
  designer's. Worth a professional pass before wp.org, particularly the 45°
  chamfer at 16px.
- Whether drill-down cards appear at all when a folder has no children, or the
  eyebrow line stands alone.
