# M2 — verified importer matrix

Read from each plugin's own `CREATE TABLE` source in the playground, not from documentation.
Versions: FileBird 6.5.8, CatFolders 2.5.6, Real Media Library Lite 4.23.4, Folders 3.2.0.

## The correction

**Our FileBird importer is aimed at tables that do not exist.** It looks for
`{prefix}fbv_folders` / `{prefix}fbv_assignments`, plus `fbr_folders` / `fbr_assignments`
for a supposed Pro variant. FileBird's real tables are `{prefix}fbv` and
`{prefix}fbv_attachment_folder`, and there is no separate Pro schema — Pro uses the same two.

Consequences:

- `FileBirdImporter::isInstalled()` returns false against every real FileBird install, so the
  Import page reports "Not installed" with FileBird active and full of data.
- `getFolderCount()` / `getAttachmentCount()` always return 0.
- `import()` returns `filebird_not_installed` and can never run.
- The importer reads `$folder['color']` and `$folder['icon']`, and FileBird has no such
  columns — those would be undefined-key warnings the moment the names were corrected.
- `sort_order` is really `ord`; `parent_id` is really `parent`, and `0` means root, not `NULL`.

I described this importer twice today as "the strongest asset in the codebase" and "solid,
handles both schema variants". Both statements were wrong. It reads cleanly and handles a
schema nobody has. This is exactly what the live playground was for.

Independent confirmation: both CatFolders and Premio's Folders ship their own FileBird
importers, and both reference `fbv` and `fbv_attachment_folder`.

## Real schemas

### FileBird 6.5.8
```
{prefix}fbv                   id, name, parent, type(int), ord, created_by
{prefix}fbv_attachment_folder folder_id, attachment_id      PK (folder_id, attachment_id)
```
Root = `parent 0`. No colour, no icon. `type` separates post types. The PK on the pair means
the schema permits many folders per file even though the UI enforces one.

### CatFolders 2.5.6
```
{prefix}catfolders        id, title, parent, type(varchar), ord, created_by
{prefix}catfolders_posts  folder_id, post_id               UNIQUE (folder_id, post_id)
```
Root = `parent 0`. Name lives in `title`. Filter `type = 'attachment'` or you import folders
belonging to other post types.

### Real Media Library Lite 4.23.4
```
{prefix}realmedialibrary        id, parent, name, slug, absolute, owner, ord, type,
                                contentCustomOrder, restrictions, cnt, importId
{prefix}realmedialibrary_posts  attachment, fid, isShortcut, nr, importData
                                PK (attachment, isShortcut)
{prefix}realmedialibrary_meta   meta_id, realmedialibrary_id, meta_key, meta_value
```
Root = **`parent -1`**, a third convention. `isShortcut` is RML's way of letting one file
appear in several folders — which maps directly onto our many-to-many table. Importing RML
shortcuts as ordinary assignments is a genuine feature-parity win, and one their own free
tier gates.

### Folders (Premio) 3.2.0
Not a custom table at all — WordPress **taxonomy terms**: `term_taxonomy`,
`term_relationships`, `termmeta`. Importing it means walking terms, not rows. Different code
path, and worth supporting because its data is visible to any taxonomy-aware code.

## What this changes

1. **Three different root markers** — `0` (FileBird, CatFolders), `-1` (RML), `NULL` (ours).
   Each importer needs its own mapping, and getting it wrong silently creates a phantom
   parent.
2. **Every source has a `type` column** scoping folders to a post type. All importers must
   filter to attachments.
3. **`ImporterInterface` needs a second shape** for taxonomy-backed sources like Folders.
4. **Detection must mean active, not present.** Four plugins' tables will survive
   deactivation in this playground, so `SHOW TABLES` will report all of them forever.
5. **Re-run safety is still missing.** Record `source` + `source_id` per imported folder in
   `folderfolio_folder_meta` so a second run updates instead of duplicating.

## Priority

FileBird (200k installs) → Real Media Library (100k) → Folders (90k) → CatFolders (6k).
CatFolders is last by reach but first by ease: its schema is closest to ours.

---

## Update, after the CatFolders teardown (2026-09-16)

CatFolders' own `ImportController` hardcodes **nine** sources with their detection keys. This
is the market's own answer to "what do people migrate from", and it is more complete than our
shortlist.

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

### Three storage shapes, not two

Point 3 above said `ImporterInterface` needs a second shape for taxonomy-backed sources. It
needs a **third**: `WMLF` stores folders as rows in `wp_posts` under a custom post type.

- **Custom table** — FileBird, RML, CatFolders
- **Taxonomy terms** — Folders, Wicked, EML, MLA, WPMF, HappyFiles
- **Post type** — WP Media Library Folders

### CatFolders' own schema, confirmed live

```
{prefix}catfolders        id, title, parent, type(varchar), ord, created_by
{prefix}catfolders_posts  folder_id, post_id    UNIQUE (folder_id, post_id)
```

`created_by` scopes folder ownership per user even in Lite (the *feature* is Pro). Root =
`parent 0`. `type = 'attachment'` must be filtered.

**One folder per file, enforced in code.** `FolderModel::set_attachments()` deletes every
existing row for those attachment ids, then inserts. The UNIQUE key would permit
many-to-many; the code does not. Same shape as FileBird.

### Detection: two different questions

CatFolders detects by `COUNT(*) > 0` on the source's storage, with every source plugin
deactivated. That is right for an **importer** and wrong for a **prompt**:

- *"Which sources can I import from?"* → data present. Residual tables are the point.
- *"Should I offer to migrate you right now?"* → plugin **active**. This is the one our
  current `SHOW TABLES LIKE` gets wrong.

Build both, and don't conflate them.

### Re-run safety, observed

Option per source, `catf_<PREFIX>_success_import`; once set, the row renders "Already
Imported" and the button disables. Confirms the `source`/`source_id` plan — but ours should be
**per folder** in `folderfolio_folder_meta`, so a re-run can reconcile rather than refuse.

### What a live import run destroyed

Running CatFolders' FileBird import over a hand-built tree, with no confirmation, preview,
progress or undo:

- **Duplicate folders.** Its own `new_folder` rejects duplicate sibling names; the importer
  skips that check. Five duplicate top-level names in one tree.
- **Files moved out of existing folders.** Delete-then-insert means importing an assignment
  removes the file from wherever the user had filed it. Three folders went to zero;
  Uncategorized went 14 → 5.

Requirements this puts on our wizard:

1. **Preview before writing** — created / merged / moved counts, and from where.
2. **Merge on name collision**, or namespace the import; never blind-create a duplicate.
3. **Add, don't move.** Our many-to-many means an import never has to take a file out of a
   folder the user made. This removes the entire failure class.
4. **Per-folder provenance** so re-runs reconcile.
5. **A summary at the end**, and a documented way back.
6. **`manage_options` on import routes** — CatFolders does this, and it is right.

### Priority, updated

FileBird (200k) → Real Media Library (100k) → Folders (90k) → Enhanced Media Library →
WP Media Folder → CatFolders (6k). CatFolders stays last by reach and first by ease; the
taxonomy-backed group (Folders, EML, MLA, WPMF, HappyFiles, Wicked) shares one code path, so
shipping that path buys six sources at once.
