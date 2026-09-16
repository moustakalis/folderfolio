# Competitive teardown 01 — FileBird Lite 6.5.8

WordPress 7.1, PHP 8.3, 41 files, 20 folders, 4 levels deep. FolderFolio and all other folder
plugins deactivated. Observed live, not read from docs.

## Stack and injection

React + **rc-tree** (Ant's tree) + **Radix UI** for menus, styled with a prefixed Tailwind
build (`fb-flex`, `fb-m-[8px_16px_8px_0]`). Class names like `fb-tree-treenode`,
`fb-tree-switcher_close`, `fb-tree-show-line`.

Injection is the notable part:

```
#wpbody
  ├ #filebird-root            ← injected BEFORE #wpbody-content
  │   └ #filebird-tree        fb-h-[calc(100vh-72px)]
  └ #wpbody-content
      └ .wrap                 pushed right; left edge at 501px
```

Plus a body class, `filebird-upload-php`. So the rail is a sibling of the whole WordPress
content area, not something squeezed inside `.wrap`. Full viewport height, sticky, with its own
scrollbar. **This is the mechanism to copy** — we currently render into `all_admin_notices` and
reposition from JS, which is strictly worse.

## Tree anatomy — measured

| Property | Value |
|---|---|
| Row height | 36px |
| Indent per level | 32px |
| Switcher hit area | 32×32px |
| Leaf nodes | get a `sw-noop` switcher placeholder so titles stay aligned |
| Guide lines | on (`fb-tree-show-line`) |
| Count badge | white pill, `right: 7px`, 10px text, vertically centred |
| Rail width | ~320px, collapsible via a chevron on the right edge |
| List | virtualised (`fb-tree-list-holder-inner`) |

Reserving switcher space on leaves is a small thing that matters: without it, titles jitter
horizontally between rows that do and don't have children.

## The best idea here: drill-down cards

Selecting a folder filters the grid **and renders that folder's children as cards above the
file tiles** — `fb-grid fb-grid-cols-right-folder`, each card white, `border-radius: 3px`,
`box-shadow: 0 2px 5px rgba(0,0,0,.06)`, 14px text, count in an `::after` at `right: 15px`.

It's the Finder/Explorer model: you are *inside* a folder, so you see its subfolders and its
files together. Worth taking. It also quietly fixes the count problem below — you don't need a
parent's count to include descendants when the children are right there to click.

## The worst idea here: counts that read as zero

Counts are **direct-only by default**. With 12 files sitting in their children, the tree showed:

```
Campaigns   0
Product     0
Archive     0
```

Anyone reading that concludes the folder is empty. The API clearly knows this is contentious —
`get-folders` returns counts in a separate object with **two maps, `actual` and `display`**, and
there's a `set-folder-counter` endpoint taking a `type`, so inherited counts are a user setting.
They built the machinery and then defaulted to the confusing option.

**For us (review #10):** compute both, show inherited by default, make it a setting. A parent
that reads `0` while holding a hundred files is worse than no badge at all.

## No URL state at all

Selecting a folder changes neither `location.search` nor `history.state`. Consequences: no deep
link to a folder, no browser back, nothing shareable, and a refresh drops you at All Files.

We already beat this — `?folderfolio_folder=` plus `replaceState` — and it's worth keeping as a
deliberate, stated difference rather than an accident.

## Folder creation — corrected on a second pass

**My first account of this was wrong in two ways.** Re-examined against the DOM:

There is exactly **one** text input in the rail. Its placeholder is `Enter folder name...`, but
its parent element is `.fb-search-bar`, it carries a folder-with-magnifier icon, and **typing
into it filters the tree** — type a string matching no folder and the tree empties. It is the
folder search, wearing a create field's label.

"New Folder" **focuses that same input**. It opens no modal, and the tree-node count does not
change (10 before, 10 after). Pressing Enter then switches modes: the full tree returns, a new
folder is created under the current selection, and an inline row appears in the tree with
**Cancel / Save** buttons.

So the two corrections:

1. The always-visible field is **search**, not a create field. My note called it a create field
   with a misleading dual role.
2. Creation ends in **explicit Cancel / Save**, not "commit on Enter, discard on blur".

Both corrections point the same way as CatFolders: **inline row in the tree, with explicit
Cancel and Save, is the market standard**, not one vendor's idea. That strengthens the
recommendation rather than weakening it — and it still beats our `prompt()` on every axis.

**The part worth *not* copying:** one input that both filters and creates, disambiguated only
by whether you pressed a button first, is genuinely confusing. Keep search and create as
separate controls.

## It shows an in-context import notice too

Not visible on my first pass, because no other folder plugin had data yet. With CatFolders,
RML and Premio tables present, FileBird renders a card **in the rail**: *"Import folders to
FileBird — You have some folders created by other media plugins. Would you like to import
them?"* with **Import now** / **No, thanks**.

That is the in-context detection I credited to RML — and FileBird's version is better: two
plain buttons, a permanent dismissal, no licence wall, no telemetry checkboxes. So the
in-context notice is **two of four**, and this is the shape to copy, not RML's.

Cost: the card sits above the fixed rows, pushing "All Files" and the tree down by ~150px.
Better than RML's two stacked notices, still a real tax on the top of the rail.

## Other observations

- **Rename / Delete** sit in a toolbar above the tree, disabled and grey until a folder is
  selected, then primary-blue. Teaches that folders are selectable objects.
- **"All Files" and "Uncategorized" are fixed rows** at the top with live counts. That answers
  the open question in the plan: yes, show both, as first-class rows.
- **No breadcrumb.** Once you drill two levels via cards, only the tree selection tells you
  where you are.
- **Empty state** is heavy: mascot illustration, "Add your first folder", body copy, and a CTA
  duplicating the header button.
- Tree nodes carry `draggable`; context menus are Radix dialogs on the title.
- **Capability: `upload_files` for all folder CRUD**, including delete — the same permissive
  choice we moved away from this morning. The market leader ships it, so it clearly isn't a
  dealbreaker, but our tighter default is defensible.

## Corrections to earlier notes

- **FileBird Lite does not gate nesting.** I created four levels and it rendered them. My
  research table implied only RML and CatFolders paywall subfolders — that stands, FileBird is
  not among them.
- **Folder colours exist**, stored in the `fbv_folder_colors` **option** keyed by folder id, not
  in the `fbv` table. The importer matrix said "no colour" based on the table alone. Wrong.
- FileBird's REST API includes `export-csv` / `import-csv` and an importer framework keyed by a
  source prefix (`/import/run/(?P<prefix>)`). A CSV round-trip is a credible migration escape
  hatch worth considering for M2.

## Not captured

Drag-and-drop feel (synthetic drags are unreliable and I'd rather report nothing than guess),
the media modal, and narrow viewports. Folder creation through the real UI failed under
synthetic keystrokes — a limitation of my input, not of FileBird; I drove its REST API instead.
