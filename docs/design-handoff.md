# FolderFolio — design handoff

Input brief for brand identity and app design. Everything here was observed live in a
WordPress 7.1 / PHP 8.3 playground with 41 attachments and trees built 4–5 levels deep, not
read from documentation. Sources: the four teardown docs in this project.

---

## 1. What FolderFolio is

A folder tree for the WordPress media library. Files get organised into nested folders;
the Media Library screen filters by folder.

**It ships every feature free. There is no Pro tier, no licence key, no upsell surface, and
no telemetry.** That is not a pricing footnote — it is the product's whole shape, and it has
to be visible in the design:

- **No upsell anywhere.** No PRO badges on disabled rows, no "Go Pro" tab, no lock icons, no
  modal that intercepts a button. Every one of the four competitors puts at least one of
  these in the primary workspace.
- **No activation interstitial.** Three of the four redirect you to their own welcome page on
  activation. We land the user where they already were.
- **No account, no gate, no telemetry** — particularly on the migration path, which is
  exactly where Real Media Library puts a consent wall.

The design consequence: the rail has nothing to sell, so every pixel in it can do work. That
is a genuine layout advantage and it should read as calm rather than empty.

### The wedge

Every competitor that gates something gates **subfolders**. Three of four monetise depth, and
the fourth ships it without polishing it. So depth is the surface with no incumbent
investment, and it is where we win:

| Plugin | What it does with nesting |
|---|---|
| FileBird Lite | Free, four levels, correctly indented — the honest one |
| Real Media Library Lite | `parent` silently ignored; your subfolder lands at root with no error |
| Folders (Premio) | Data nests; the **expand chevron** redirects to a pricing page |
| CatFolders Lite | Data nests; **"New Folder" itself** opens an upsell, and children render with zero indentation |

**Design brief, stated plainly: a tree five levels deep must be effortless to read, scan and
navigate.** Indentation legible at depth, fast expand/collapse, a search that works, a
breadcrumb so you always know where you are, and drill-down that means you rarely need to
click four times.

---

## 2. Measured specs from the market

Everything here is measured, not estimated.

### Mount point — settled

Two independent implementations (FileBird, CatFolders) inject the rail as a **sibling of
`#wpbody-content`**, inside `#wpbody`, `position: sticky`, full viewport height, own
scrollbar. `.wrap` then starts at x=501px in both.

```
#wpbody
  ├ div.folderfolio-rail        ← sibling, BEFORE #wpbody-content, position:sticky
  ├ div.folderfolio-resize      ← drag handle
  └ #wpbody-content
      └ .wrap
```

Our current approach — render into `all_admin_notices` and reposition from JS — is strictly
worse and should go.

### Geometry

| Property | FileBird | CatFolders | RML | **Proposed** |
|---|---|---|---|---|
| Rail width | ~320px | 300px, resizable | resizable | **300px default, resizable, collapsible** |
| Row height | 36px | 32px | ~28px | **36px** — touch-safe, breathing room at depth |
| Indent per level | 32px | 24px (broken) | — | **20–24px** — see note below |
| Expand affordance | chevron | `⊞` box | chevron | **chevron** |
| Switcher hit area | 32×32px | — | — | **≥32×32px** |
| Guide lines | on | on | off | **on, low contrast** |
| Count badge | white pill, right-aligned | boxed number | pill | **pill, right-aligned** |
| Virtualised | yes | yes | — | **yes** |

**Indentation at depth is a real budget problem.** In a 300px rail, 32px per level leaves
~140px for a name at depth 5 after icon and badge. FileBird's 32px is too generous; 20–24px
with guide lines carrying the structural signal is the right trade. This deserves a designed
answer, not a default.

**Reserve the switcher slot on leaf nodes.** FileBird does (`sw-noop`); without it, titles
jitter horizontally between rows that do and don't have children. Small, cheap, and it is the
difference between a tree that reads as a list and one that reads as a mess.

---

## 3. Patterns to take

### Drill-down cards — the single best idea in the market

FileBird: selecting a folder filters the grid **and renders that folder's children as cards
above the file tiles**. White cards, 3px radius, soft shadow, 14px label, count at the right.

It's the Finder/Explorer model — you are *inside* a folder, so you see its subfolders and its
files together. Two things it buys us:

1. **Navigation at depth without precise clicking.** Four levels down is four big card
   targets, not four 36px tree rows.
2. **It defuses the count problem.** A parent's badge matters much less when its children are
   right there with their own counts.

We take it, and we add the breadcrumb FileBird doesn't have.

### Inline folder creation, with explicit Save/Cancel

Two of four create folders **inline in the tree** — a new row materialises where the folder
will live, focus lands in it, and the row carries explicit **Cancel / Save** buttons. No modal,
no `prompt()`. FileBird and CatFolders arrived at the same pattern independently, so this is
the market standard rather than one vendor's idea. Take it.

This also kills our current `prompt()` — which is unstyleable, untranslatable, and makes the
plugin impossible to drive programmatically.

**But do not copy FileBird's input.** It has exactly one text field in the rail, labelled
`Enter folder name...`, whose parent is `.fb-search-bar` and which **filters the tree as you
type**. "New Folder" merely focuses it; Enter switches it into create mode. One control, two
jobs, disambiguated only by whether you pressed a button first. **Keep search and create as
separate controls.**

### Fixed rows at the top, always

All four ship **two fixed rows above the tree** with live counts: everything, and the files in
no folder. That question in the plan is answered — yes, both, as first-class rows.

Naming: FileBird and CatFolders say *Uncategorized*, RML says *Unorganized*, Folders says
*Unassigned Files*. **Use "Unassigned"** — it matches our folder `0`, and it doesn't collide
with a folder a user might actually name "Uncategorized".

### A toolbar that enables on selection

All of them grey out Rename/Delete until a folder is selected, then light them up. It's a
cheap, effective way to teach that folders are selectable objects. CatFolders gets it right
with four labelled-by-`title` icons (Rename, Delete, Sort, More); RML gets it wrong with seven
unlabelled ones. **Four or fewer, with visible labels or at minimum tooltips.**

### Native toolbar integration

Folders (Premio) is the only one that injects into WordPress's **own** media filter row — an
"All Folders" dropdown and a "Bulk Organize" button next to the existing All-media-items and
All-dates selects. More discoverable than a rail-only interaction, and it degrades gracefully
when the rail is collapsed. **Take it: a native filter control *and* a tree.**

### Undo with a grace window

Folders has a setting: **"Undo action" with a configurable timeout, default 5 seconds.** For
destructive folder operations that is a better answer than a confirm dialog — it doesn't
interrupt the common case and it still protects the rare one. Worth designing an undo toast.

### Mutation responses carry fresh counts

CatFolders' assign endpoint returns the **full counter map for every folder**, not just the
one touched, so every badge refreshes from the mutation response with no second request.
Cheap and smart.

---

## 4. Anti-patterns — the market's mistakes, catalogued

Every one of these was observed. Avoiding them is most of the design brief.

### Counts that read as zero — 4 out of 4

With 27 files sitting in their children, every plugin tested showed:

```
Brand       0
Campaigns   0
Product     0
Archive     0
```

Anyone reading that concludes the folder is empty. FileBird's API even returns two count maps,
`actual` and `display`, and exposes a `set-folder-counter` endpoint — they built the machinery
and defaulted to the confusing option.

**Ours: compute both, show inherited by default, make it a setting.** A parent reading `0`
while holding a hundred files is worse than no badge at all.

### No URL state — 4 out of 4 (in grid)

Selecting a folder in grid mode changes neither `location.search` nor `history.state` in any
of the four. No deep link, no browser back, nothing shareable, and a refresh drops you at All
Files. CatFolders' list mode *does* write `catf=15` to the URL — along with eight empty params
and **a nonce**, in a link a user might share.

CatFolders' workaround is a `startupFolder` setting that reopens the last folder. That is not
deep-linking, not shareable, and not back-button-able.

**We already beat this** — `?folderfolio_folder=` plus `replaceState`, in both modes. Keep it
deliberately, make sure the design accounts for arriving at a deep URL cold (the tree must
auto-expand to the selected folder and scroll it into view), and say it out loud in the README.

### Activation interstitials — 3 out of 4

FileBird, RML and Folders all redirect to their own page on activation; RML's welcome page
leads with **"Get your PRO license now!"** and Folders opens a modal with an embedded YouTube
video. Only CatFolders stays put and shows a dismissible notice.

**We do nothing on activation** except, at most, one dismissible notice pointing at the media
library.

### Upsell in the workspace

- RML stacks **two notices ~180px tall above the tree** — one offering import, one asking for
  money, hideable only for 30 days. On a laptop that pushes the tree most of the way down the
  rail.
- CatFolders renders PRO-badged settings **visible but disabled**. Showing someone a control
  they can't have is more irritating than not showing it.
- Folders paints the rail magenta — its brand, not WordPress's.

**Nothing in our rail sells anything.** And our chrome should read as WordPress, not as a
guest application (see §6).

### Silent failure is worse than a loud one

RML's Lite accepts `parent`, ignores it, and creates the folder at root with **no error, no
warning, no upsell**. Their own source says so out loud. The user thinks they made the
subfolder and finds out later it's in the wrong place.

General principle for us: **never quietly rewrite what the user asked for.** If something
can't happen, say so where it was attempted.

### A primary button that becomes an ad

CatFolders: with a folder selected, **"New Folder" doesn't create a folder** — it opens a
"Want Subfolders?" upsell. You must click All Files first, and nothing tells you that. The
primary action changes meaning based on invisible state.

### Import that destroys your work

CatFolders' importer is the best-presented in the market — one row per detected source, each
with a live count ("FileBird — 20 folders found to import"). Then you press it and it
completes instantly with **no confirmation, no preview, no progress, no summary, no undo**,
and does two silent things:

1. **Creates duplicate folders.** Its own create endpoint refuses duplicate sibling names; the
   importer bypasses the check. Five duplicate top-level names in one tree — two `Brand`, two
   `Campaigns`, two `Product`, two `Events`, two `Archive`, at identical (zero) indentation.
2. **Moves files out of your existing folders.** Every assign is delete-then-insert, so
   importing takes each file from wherever you had put it. Three folders went to zero.

This is the reference specimen for our migration wizard — see §7.

### Hostile admin habits

- CatFolders' menu capability is `read`, so every subscriber sees a top-level "CatFolders"
  menu item and then gets *"You do not have sufficient permissions"*. (We ship a top-level
  menu too — ours is gated on `manage_options`, so it only appears to people it works for.)
- Its settings page calls `remove_all_actions('admin_notices')`, suppressing every other
  plugin's notices.
- Deactivation surveys on 3 of 4 — RML's has a **required** field; Folders' pre-fills your
  email address.

**None of these.** Deactivation is silent.

---

## 5. Screens and states to design

### 5.1 The rail — `upload.php`, grid and list

```
┌─ Folders ─────────────── [+ New folder] ─┐
│ [Rename] [Delete] [Sort] [⋯]             │  ← enabled only on selection
│                                          │
│ 📁 All media                        41   │  ← fixed
│ 📁 Unassigned                       14   │  ← fixed
│                                          │
│ [ Search folders…                    ]   │
│                                          │
│ ▾ 📂 Brand                           12  │  ← inherited count
│   ▾ 📂 Logos                          8  │
│     ▸ 📂 Primary                      5  │
│   ▸ 📂 Typography                     4  │
│ ▸ 📂 Campaigns                       19  │
│ ▸ 📂 Archive                          6  │
│   📂 Events                           3  │  ← leaf: switcher slot reserved
└──────────────────────────────────────────┘
        ↕ resize handle    ‹ collapse
```

States to design: default · a folder selected · hover · drag-over (drop target) · inline
create/rename row · search active with matches · search with no matches · loading · error ·
**empty (no folders yet)** · collapsed rail · depth 5.

**Empty state.** FileBird's is heavy — mascot, headline, body copy, and a CTA duplicating the
header button. Ours should be one clear line and one action, and since we have nothing to
sell, it can be genuinely quiet.

### 5.2 The content area

Breadcrumb · drill-down cards for child folders · the WordPress grid or list below. Design the
breadcrumb for depth 5 including its overflow behaviour (`Brand / … / Primary / Dark`).

### 5.3 Native toolbar

An "All folders" select and a bulk "Add to folder" control inside WordPress's existing media
filter row, matching WP's own control styling exactly.

### 5.4 Migration wizard

Detect → **preview** → run → summary. See §7.

### 5.5 Settings — a top-level FolderFolio menu

Its own top-level admin menu, three tabs: **Settings · Import · Status**. Needs a 20×20
monochrome menu icon.

The design problem: every competitor's settings screen is a branded marketing surface — dark
header bar with a logo, a "Go Pro" tab, PRO-badged rows rendered visible-but-disabled. We have
a top-level menu like they do and none of the things that make theirs unpleasant. So this
screen has to answer: **what does a settings page look like when it has nothing to sell?**

Contents: count mode (inherited/direct), default sort, undo timeout, folder colours, and a
real roles matrix for who can manage folders — which two competitors charge for. Plus Import
(the wizard) and Status (schema version, row counts, repair actions).

No PRO rows, because there is no PRO.

### 5.6 The media modal

The cramped case that breaks most implementations — the tree inside the post editor's media
frame. Design it, ship it after the library screen.

### 5.7 The gallery block — the only front-end surface

A `folderfolio/gallery` block renders a folder's contents on the public site. Two things to
design:

- **The editor sidebar**: a folder picker reusing the tree component at ~280px inside
  Gutenberg's inspector, plus columns, gap, order, "include subfolders", and link behaviour.
  The tree has to survive being squeezed into a third of its usual width.
- **The front-end grid**: an honest, theme-neutral image grid that inherits typography and
  spacing from the theme rather than imposing ours. This is the one place FolderFolio is seen
  by someone who isn't an editor, so it should be quiet to the point of invisibility —
  competitors' front-end galleries are recognisably theirs, which is a bug, not a feature.

Also needs: a block icon consistent with the menu mark, and an editor placeholder state for a
block with no folder chosen yet.

---

## 6. Brand and visual direction

**The hardest constraint: this lives inside WordPress admin.** WordPress ships eight admin
colour schemes, and the user picked theirs. Folders (Premio) ignores this and paints
everything magenta; it reads as the plugin's screen rather than the user's site.

Direction: **FolderFolio should look like a well-made part of WordPress, with an identity that
lives in the brand mark, the empty states, the wizard, and the plugin listing — not in the
chrome of the rail.**

Concretely for the design work:

- Inherit WordPress admin colour scheme variables for selection, focus and primary actions.
  Our own colour is for the mark and for moments the user isn't working in (onboarding, the
  wizard, docs) — plus, optionally, **user-assigned folder colours**, which is the one place
  colour belongs in the tree.
- System font stack, WordPress admin type scale. No web fonts in admin.
- Respect `prefers-reduced-motion`, `prefers-color-scheme`, and WordPress's own dark-ish
  schemes.
- Icon set: folder (closed/open/empty), chevron, drag handle, search, sort, rename, delete,
  more, import, undo, drop-target. One coherent family, 16px and 20px, stroke weight matched
  to Dashicons' visual weight without imitating it.

Needed from Claude Design:

1. Wordmark and mark, on light and dark, at favicon/menu-icon size (WordPress admin menus want
   a 20×20 monochrome icon) and at plugin-banner size (772×250 and 1544×500 for wp.org).
2. Colour palette, incl. the folder-colour swatch set (8–12 colours that stay distinguishable
   at 16px and pass contrast on both light and dark rows).
3. The folder row component at depths 1–5 in every state.
4. Full Media Library screen, grid and list, rail expanded and collapsed.
5. Drill-down cards and breadcrumb.
6. Empty states, the create/rename inline row, the undo toast.
7. The migration wizard, all four steps.
8. The gallery block — editor sidebar, editor placeholder, front-end grid, block icon.
9. wp.org screenshots and banner.

**Tone of voice:** plain, concrete, unexcited. "20 folders found to import" beats "Supercharge
your media library!" — which, incidentally, is also what the best competitor does.

---

## 7. The migration wizard

M2, but it shapes the brand more than anything else, because it is where four competitors
behave worst and where we can be visibly decent at no cost.

**What the market does:** RML puts migration behind a free-licence wall with four
checkboxes — auto-updates pre-checked, telemetry, newsletter — where Accept is a big blue
button and decline is a small link reading *"Continue without any support and without e.g.
discount announcements"*. CatFolders just runs it and breaks your tree.

**Ours:**

1. **Detect in context.** Two of four notice leftover data and offer migration **in the
   sidebar, on arrival**, not on a Tools page you have to find. Copy **FileBird's** version of
   this card, not RML's: one sentence, **Import now** / **No, thanks**, permanently
   dismissible, no licence wall and no telemetry checkboxes. Budget for the ~150px it takes
   off the top of the rail, and make it collapse to a single line once dismissed. Distinguish
   two questions — *"which sources can I import from?"* (data present, including deactivated
   plugins) versus *"should I offer right now?"* (plugin active).
2. **Preview before writing.** n folders created, n merged by name, n files moved and from
   where. Nobody in this market does this.
3. **Add, don't move.** Our many-to-many model means an import never has to take a file out of
   a folder the user made — which removes CatFolders' entire failure class.
4. **Merge on name collision**, never blind-create a duplicate.
5. **Per-folder provenance** so a re-run reconciles instead of duplicating or refusing.
6. **A summary, and a way back.**
7. **No account, no licence, no telemetry, no gate.**

Nine sources are worth supporting, six of which share one taxonomy-backed code path — full
matrix in `claude/m2-importer-matrix.md`.

---

## 8. Decisions already locked

- **Media only at launch**, on a post-type-agnostic schema so posts/pages can follow with no
  migration.
- **Drag files onto folders only.** Folder reordering and re-nesting stay menu actions.
- **Hybrid multi-folder:** drag moves; a separate "Add to folder" bulk action files a copy.
- **React via `wp-element`** for the tree.
- **Free on wp.org, no paid tier ever.**
- **Tree + drill-down cards + breadcrumb** for navigation.
- **Unassigned** for files in no folder.
- **Inherited counts by default**, switchable.
- **URL state in both grid and list.**
- **A top-level FolderFolio admin menu** for settings, import and status — gated on
  `manage_options`, with none of the branded-marketing furniture the competitors put there.
- **Adjacency list + materialised path** in the schema, so breadcrumbs need no queries and
  depth costs nothing.
- **TanStack Query + Zustand** behind the UI, so every drag, rename and move is optimistic
  with rollback — the loudest quality signal in this kind of interface.

## 9. Open for design to answer

1. Indentation per level in a 300px rail that stays readable at depth 5 — the core problem.
2. What a folder row looks like when its own count is 0 but its subtree isn't, under both
   count modes.
3. How search presents matches at depth — competitors hide matching children (our review
   item #9). Show ancestors greyed? Flatten to a result list with paths?
4. Whether drill-down cards replace or sit above the file grid, and what happens in list mode.
5. Breadcrumb overflow at depth 5+ in a narrow content area.
6. The drop-target affordance — the tree row, the card, or both.
7. Collapsed-rail behaviour: a thin tab (Folders' approach), icons only, or nothing.
8. Folder colours: swatch on the icon, the row, or both — and how they survive eight
   WordPress admin colour schemes.
