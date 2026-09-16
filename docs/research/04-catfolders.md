# Competitive teardown 04 — CatFolders Lite 2.5.6

6,000+ installs, 4.4★ — smallest of the four by reach, closest to us by schema.
Folders (Premio) deactivated, all four plugins' tables left in place.

## First run — the only one that doesn't hijack you

Activation stayed on `plugins.php` and showed an inline admin notice: a mascot, **"Get
Started — Start organizing with CatFolders now. Add your first folder"**. No redirect, no
welcome page, no modal, no video.

That is **one out of four**. FileBird, RML and Folders all redirect to their own screen on
activation. CatFolders shows a dismissible notice and gets out of the way. Take this one.

## Injection — same mechanism as FileBird

```
#wpbody
  ├ div.catf-app-wrapper.resizable   ← injected BEFORE #wpbody-content, position:sticky
  ├ div.catf-resize-wrap             ← drag handle
  └ #wpbody-content
      └ .wrap                        left edge at 501px
```

Rail 300px, sticky, with a drag-resize handle and a collapse chevron. No body class.
Two independent implementations (FileBird, CatFolders) landing on the identical
sibling-of-`#wpbody-content` pattern settles it: **that is how we should mount**, not our
current `all_admin_notices` + JS reposition.

Stack is also the same as FileBird: **rc-tree** with a `catf-` prefix (`catf-tree-treenode`,
`catf-tree-node-content-wrapper`, `catf-tree-indent-unit`). Drag-and-drop is **jQuery UI**
(`ui-droppable` on every node wrapper), not HTML5 DnD — a different choice from FileBird's
`draggable` attribute.

| Property | CatFolders | FileBird |
|---|---|---|
| Row height | 32px | 36px |
| Indent unit (CSS) | 24px | 32px |
| Rail width | 300px, resizable | ~320px |
| Expand affordance | `⊞` / `⊟` box | chevron |
| Ordering | creation order (`ord`) | creation order |

## The paywall: the primary button becomes an ad

This is the sharpest monetisation of the four, and it is worth describing precisely.

**With a folder selected, clicking "New Folder" does not create a folder.** It opens a modal
titled **"Want Subfolders?" → "Get CatFolders Pro"**. The plugin assumes any create while a
folder is selected means a subfolder, and blocks the entire action.

To create a folder at all you must first click **All Files** to clear the selection. Nothing
tells you that. The primary action of the plugin silently turns into an upsell depending on
state the user has no reason to be tracking.

Pro list from that modal: Create Subfolders · Advanced Sort Options · Folders for Pages and
Post Types · Multi-Level Document Gallery · 20+ Page Builders · Multilingual · Auto Update ·
1-1 Live Chat.

### The gate is client-side only, and the tree renders nesting badly

There is **no server-side nesting gate**. `new_folder` takes `parent` and honours it; I built
a 15-folder tree four levels deep through `POST /CatFolders/v1/folders` and every parent id
stuck. The `/folders` payload returns it correctly nested.

But the rendered tree shows **zero indentation**. Measured, every row's switcher sits at
x=181 and every title at x=205 — root or depth 4, identical. The indent element exists and is
24px wide for a child node, but computes to `display:block`, so it takes its own line and
contributes nothing horizontally. A child is visually indistinguishable from its parent's
siblings.

The Pro modal's own marketing screenshot shows a properly indented tree. So Lite ships the
data model, the expander, and the CSS rule — and withholds the one thing that makes depth
legible.

**Where the four land on nesting:**

| Plugin | How nesting is gated |
|---|---|
| FileBird Lite | not gated — four levels, free, correctly indented |
| Real Media Library Lite | `parent` silently ignored; folder lands at root, no error |
| Folders (Premio) | data nests fine; the **expand chevron** redirects to pricing |
| CatFolders Lite | data nests fine; **"New Folder" itself** upsells, and children render unindented |

Three of four monetise subfolders, and the two that don't block the data instead break the
experience of it. FolderFolio's "unlimited nested folders, no tiers" is the wedge — and the
bar for *feeling* good at depth is on the floor.

## Counts: four out of four show direct-only

Root folders read `0` while their children hold 27 files. Same as FileBird, same as Folders;
RML Lite shows no folder counts at all. **Nobody in this market shows inherited counts.**
Review item #10 stands: compute both, show inherited by default, make it a setting.

One good idea though: `POST /attachment-to-folder` returns **the full counter map for every
folder**, not just the one touched. The client refreshes all badges from the mutation
response with no second request. There is also a separate `/folder-counter` endpoint, and
`data-count` inline in the `/folders` tree — belt, braces and a third belt.

## URL state — four out of four have none (in grid)

Grid mode: selecting a folder sets the Backbone collection prop `catf: "15"` (plus an
`ignore: <timestamp>` cache-buster) and leaves `location.search` empty and `history.state` at
`{}`.

List mode is different — it submits the form, so the folder *does* land in the URL as
`catf=15`. But the URL it produces is
`?mode=list&attachment-filter&m=0&catf=15&s&action=-1&paged=1&action2=-1&affected&_ajax_nonce=5db46f7fa9&ps`
— eight empty params and **a nonce in a link the user might share**.

Switching grid → list drops the selection back to All Files, because grid never wrote it
anywhere. Their answer is a setting, `startupFolder`, that reopens the last folder — which is
not deep-linking, not shareable, and not back-button-able.

**Our `?folderfolio_folder=` in both modes plus `replaceState` is four-for-four unmatched.**

## Folder creation: inline in the tree, with explicit Save/Cancel

Once the selection is cleared, "New Folder" inserts an editing row **in the tree** — folder
icon, an input placeholder `Enter folder name`, focus placed in it, and **Cancel / Save**
buttons beneath. No `prompt()`, no modal.

That is two of four (FileBird, CatFolders) creating inline in the tree, and CatFolders'
version is the better of the two: FileBird commits on Enter and **discards on blur**, so
clicking away loses your typing. Explicit Save/Cancel is the pattern to take.

Toolbar above the tree: four icon buttons with `title` attributes — **Rename, Delete, Sort,
More options** — greyed until a folder is selected. Better than RML's seven unlabelled icons,
same enable-on-selection teaching as FileBird.

## One folder per file, enforced by delete-then-insert

`FolderModel::set_attachments()` deletes every existing row for those attachment ids before
inserting the new one:

> `DELETE FROM catfolders_posts WHERE post_id IN (...) AND folder_id IN (...)` then
> `INSERT INTO catfolders_posts (folder_id, post_id) VALUES ...`

So every assign is a **move**, never an add, despite `catfolders_posts` carrying a
`UNIQUE (folder_id, post_id)` that would permit many-to-many. Same shape as FileBird: the
schema allows it, the code forbids it. Our many-to-many with the hybrid drag-moves /
bulk-adds semantics is a real capability none of them offer.

The delete is scoped by `created_by`, so per-user folder ownership is in the Lite schema even
though "Media Folder Permissions — allow user roles to manage media folders" is a **PRO**
setting. We ship capability gating for free; that is another line for the comparison table.

## The importer — the best in the category, and the clearest warning

Tools tab, `Import From Other Plugins`, one row per detected source:

```
FileBird (by NinjaTeam)                 20 folders found to import   [Import now]
WP Real Media Library (by devowl.io)     7 folders found to import   [Import now]
Folders (by Premio)                     15 folders found to import   [Import now]
```

All three were **deactivated** at the time. Detection is `COUNT(*) > 0` against each source's
own storage, not activation — correct for an importer (you import from the plugin you already
turned off), and a useful split from the *prompt* case, which should mean active.

**Nine sources are hardcoded, with their detection keys — free intelligence for our matrix:**

| Prefix | Plugin | Author | Detected via |
|---|---|---|---|
| `FB` | FileBird | NinjaTeam | table `{p}fbv` |
| `RML` | Real Media Library | devowl.io | table `{p}realmedialibrary` |
| `Folders` | Folders | Premio | taxonomy `media_folder` |
| `WF` | Wicked Folders | Wicked Plugins | taxonomy `wf_attachment_folders` |
| `EML` | Enhanced Media Library | wpUXsolutions | taxonomy `media_category` |
| `MLA` | Media Library Assistant | David Lingren | taxonomy `attachment_category` |
| `WPMF` | WP Media Folder | JoomUnited | taxonomy `wpmf-category` |
| `HP` | HappyFiles | Codeer | taxonomy `happyfiles_category` |
| `WMLF` | WP Media Library Folders | Max Foundry | **post type** `mgmlp_media_folder` |

`WMLF` is a **fourth storage shape** — folders as posts. Our `ImporterInterface` needs three
shapes, not the two the matrix assumed: custom table, taxonomy terms, and post-type rows.

Re-run guard is an option per source, `catf_<PREFIX>_success_import`; once set the counter is
forced to `-1` and the row renders **"Already Imported"** with the button disabled. Crude, but
it confirms the `source` / `source_id` marker our M2 plan called for — ours should be
per-folder, not per-source, so a re-run can *update* rather than refuse.

Import routes check `manage_options`, unlike every other route in the plugin
(`upload_files`). Our Import page should do the same.

### What the import actually did — and why our wizard must not

I ran the FileBird import against a tree I had already built by hand. It completed instantly:
no confirmation, no preview, no progress, no summary, no undo. The button just changed to
"Already Imported".

Then I read the tree back:

```
Brand #1  (0)        …my folders, now empty
  Logos #2  (0)
Campaigns #5 (0)
Product #10 (0)
  Packshots #11 (5)  …was 7
Archive #12 (0)
  2019 #13 (3)       …was 4
Events #15 (0)       …was 3
Brand #16 (4)        …imported, duplicate name
Campaigns #20 (0)    …imported, duplicate name
Product #27 (0)      …imported, duplicate name
Events #32 (3)       …imported, duplicate name
Archive #33 (0)      …imported, duplicate name
```

Two failures, both silent:

1. **Duplicate names.** `new_folder` refuses a duplicate sibling name with *"A folder with
   this name already exists"* — and the importer bypasses that check entirely. The user is
   left with two `Brand`, two `Campaigns`, two `Product`, two `Events`, two `Archive` at the
   same (zero) indentation, with no way to tell them apart.
2. **Files moved out of the user's own folders.** Because every assign is delete-then-insert,
   importing an assignment *takes the file out of wherever the user had put it*. My
   `Dark variants`, `Stories` and `Events` went from 5, 8 and 3 files to zero. Uncategorized
   went 14 → 5. Nothing warned, nothing logged, nothing can undo it.

**This is the single most useful finding of the four teardowns for M2.** Our wizard has to:

- **preview before it writes** — n folders created, n merged by name, n files that will move,
  and from where;
- **merge on name collision** rather than re-creating, or at minimum namespace the import;
- **never silently move a file out of a folder the user made** — with many-to-many we can
  simply add, which sidesteps the whole class of failure;
- **record per-folder provenance** so a second run updates instead of duplicating or refusing;
- **report what it did** when it finishes.

Everything RML gates behind a licence and telemetry wall, and everything CatFolders does
without asking, we can do plainly: no account, no gate, a preview, and a summary.

## Smaller notes

- **Menu capability is `read`.** Every subscriber sees a top-level "CatFolders" menu item and
  then gets *"You do not have sufficient permissions"*. The menu cap should match the page
  cap. Don't copy.
- The settings page calls `remove_all_actions('admin_notices')` — it suppresses every other
  plugin's notices on its own screen. Common, hostile, don't copy.
- Settings are three rows: SVG upload, and two PRO-badged rows rendered visible-but-disabled.
  Showing a disabled control you can't have is more irritating than not showing it.
- REST namespace is `CatFolders/v1` — mixed case in a URL path. Ours (`folderfolio/v1`) is
  conventional; theirs is not.
- `clean-db` endpoint — a repair tool, echoing RML's five `/reset/*` routes. Second
  independent signal that a folder plugin needs an integrity-check tool. Plan one.
- `export-csv` / `import-csv` exist here too, as in FileBird. A CSV round-trip is a credible
  migration escape hatch for sources we don't have an importer for.
- `generate-api-key` — an external REST API for folders, keyed. Nobody else has this.
- Fixed rows are **All Files** and **Uncategorized**, both with live counts. Uncategorized
  updated correctly as I filed files (41 → 14 → 5). Four of four ship both rows; that
  question from the plan is answered.

## Running tally — all four

| Question | FileBird | RML | Folders | CatFolders |
|---|---|---|---|---|
| Mount | sibling of `#wpbody-content` | left rail | left rail | sibling of `#wpbody-content` |
| Tree lib | rc-tree | own | own | rc-tree |
| Rail width | ~320px | resizable | collapsible | 300px, resizable |
| Row height | 36px | ~28px | — | 32px |
| Fixed rows | All Files / **Uncategorized** | All files / **Unorganized** | All Files / **Unassigned Files** | All Files / **Uncategorized** |
| Counts on parents | direct-only | none in Lite | direct-only | direct-only |
| URL state (grid) | none | none | none | none |
| URL state (list) | none | none | none | yes, with a nonce in it |
| Nesting free? | yes | no (silent) | no (chevron → pricing) | no (create button → pricing) |
| Folder creation | inline, Enter/blur | toolbar icon | — | inline, **Save/Cancel** |
| Activation interstitial | yes | yes | yes | **no** |
| Deactivation survey | yes | yes (required field) | yes (email prefilled) | not reached |
| Folder CRUD capability | `upload_files` | — | — | `upload_files` |
| Role permissions | — | — | — | PRO |
| Own importer | framework, by prefix | 4 routes, licence-gated | — | **9 sources, counts shown** |
| Many files per folder | schema yes, code no | Pro (`isShortcut`) | taxonomy, yes | schema yes, code no |

Three words for "no folder": Uncategorized ×2, Unorganized, Unassigned. **Uncategorized wins
the vote 2–1–1**, but "Unassigned" matches our folder `0` and reads better against a folder
named *Uncategorized* that a user might actually create. Worth a deliberate call in design.

## Not captured

Drag-and-drop feel (jQuery UI droppable — synthetic drags unreliable), the right-click
context menu (`#catf-context-menu` exists in the CSS; my synthetic `contextmenu` didn't open
it), the media modal, bulk select → assign, narrow viewports, and the Pro pricing page. All of
these are in the deferred "needs your hands" pile.
