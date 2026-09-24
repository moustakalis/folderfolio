# FolderFolio

**Unlimited virtual folders for the WordPress Media Library, posts and pages. Every feature free — no tiers, no upsells, no telemetry.**

## Version

1.0.0

## What ships

Folders are virtual: nothing on disk moves, no attachment URL changes, and
deactivating the plugin cannot break a published link.

### In the Media Library

- Unlimited nested folders, in both grid and list mode
- Drag files onto a folder to file them (a move from inside a folder, an add
  from All media)
- Multi-folder membership — one file, several folders, no duplicate on disk
- Add to folder and Move to folder for a selection
- Folders arranged by hand or sorted, with a per-folder order for subfolders
  and files (files arranged by hand too)
- Cut, copy, paste and duplicate folders, with or without their files
- Uploads go to the folder currently selected; a dropped directory keeps its
  structure
- Smart folders (saved rules) and Gallery folders (images only)
- Pin, star and lock; download a folder as a ZIP
- Ten folder colours; a starting folder for the site or per person
- A folder filter on the library toolbar, in both modes
- A folder column inside the media picker (block editor, Classic Editor, ACF,
  Customizer)
- A keyboard-navigable folder tree; deleting a folder can be undone

### Posts, pages and custom post types

- Posts and Pages on by default, other types opt-in under *Folders for*
- The same rail on their list screens, a Folders panel in the editor, and
  *Add New* into the current folder

### On the front end

- A **Folder gallery** block — a folder as its source, so adding a file to the
  folder updates the page
- `[folderfolio_gallery folder="Brand/Logos"]` for classic themes
- Server-rendered: no JavaScript of ours reaches a visitor, and the lightbox is
  core's. Private, draft and trashed media are filtered out of every gallery.

### Migration

The import wizard reads folders and assignments from nine plugins — FileBird
(free and Pro), Real Media Library, CatFolders, Folders (Premio), Wicked
Folders, Enhanced Media Library, Media Library Assistant, WP Media Folder and
HappyFiles.

It previews the whole plan before writing anything, **adds and never moves**,
and stamps every folder and row with the run that created it, so one click
undoes that run exactly.

### Administration

- A settings screen: which screens have folders, count mode, default sort,
  starting folder, undo window, and a per-role matrix of six abilities
- Bulk-create from a pasted list; export the folder structure and read it back
- A **Status** tab with a health check and two repairs
- WP-CLI for everything the rail does: `wp folderfolio folder …` (list, get,
  create, rename, move, duplicate, reorder, delete, color, sort, order-files,
  lock, pin, kind, zip), `assign`/`unassign`, `smart`, `export`, `import`,
  `settings get|set`, `rebuild-paths`, `remove-orphans` and `doctor`
- A REST API and a PHP facade with the same reach, hooks on every change, and
  filters on the capability checks, the import sources and the default upload
  folder — `tests/Unit/Support/ApiSurfaceTest.php` and
  `tests/Integration/Rest/RouteDocsTest.php` hold docs/api to all three
- `uninstall.php` removes every table, option, transient and meta key the
  plugin created (`tests/Unit/Support/UninstallTest.php` holds it to that)

### Not in 1.0.0

- Folder icons. The column, the sanitizer and the REST field exist; no UI sets
  one, so the feature does not ship.
- Smart-folder rules for posts and pages — smart folders are media only.

## Requirements

- WordPress 6.4+
- PHP 8.1+
- Node.js 22 and Yarn 4 (via Corepack) to build the assets

## Quick start

```bash
corepack enable
composer install
yarn install --immutable
yarn build
```

Or through mise, which wraps the same commands:

```bash
mise run deps:install   # Composer + Yarn
mise run assets:build   # tsc --noEmit, then esbuild
```

### Link to a WordPress install

```bash
make wp-link WP_ROOT=/path/to/wordpress
yarn build
```

Then activate FolderFolio on the **Plugins** screen.

### Build an installable ZIP

```bash
mise run dev:zip     # → dist/folderfolio.zip
```

`bin/stage-plugin.sh` holds the one packaging allowlist; `bin/build-zip.sh`
zips what it stages, and the end-to-end suite mounts the same staged directory
— so the files the tests run against and the files that ship are the same files
by construction.

## Commands

| | |
|---|---|
| `yarn build` | Type-check, then build production assets |
| `yarn dev` | Rebuild on change |
| `yarn typecheck` | `tsc --noEmit` |
| `composer run phpstan` | PHPStan, level 6, pinned to PHP 8.1–8.4 |
| `composer run test:unit` | The unit suite — no WordPress, no database |
| `composer run test:integration` | Against the WordPress test library |
| `composer run test:integration:setup` | Fetch WordPress and the test library first |
| `yarn test:e2e` | Playwright, against a WordPress booted by the run itself |
| `yarn test:pipeline` / `test:slot` / `test:tree` | The three browser harnesses for failures that are silent — the wp-element aliasing, the toolbar portal, the tree's roving tabindex |
| `mise run dev:zip` | Build and package |

## REST API

`/wp-json/folderfolio/v1/` — every route, its ability and its arguments are in
[`docs/api/README.md`](docs/api/README.md). Every response is enveloped as
`{success, data}`; a failure is `{success: false, error: {code, message}}` — `/export`
alone answers with the export document itself.

## Documentation

`docs/00-start-here.md` is the cold-start page: what the plugin is, where it
stands, and the traps worth knowing before touching anything.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).

## Support

- Issues: https://github.com/moustakalis/folderfolio/issues
