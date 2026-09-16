# FolderFolio — architectural plan

Target: **1.0 on wordpress.org**. Media-library folders, unlimited depth, every feature free.

This plan takes the codebase as it stands on `refactor/merge-src-into-includes` (31 unpushed
commits) and says what it becomes, in what order, and why.

---

## 1. Decisions

| Area | Decision | Rationale |
|---|---|---|
| Scope | Media (`attachment`) only at launch, post-type-agnostic schema | Prove the deep-tree experience before multiplying surfaces |
| Hierarchy | `parent_id` as truth + **materialised path** | Subtree filter, move, delete, breadcrumb and cycle checks all become non-recursive |
| Frontend | **React via `wp-element`** | WordPress already ships React; zero bundle cost, Gutenberg-idiomatic |
| Server state | **TanStack Query** | Optimistic updates with rollback are the hard part of a drag-and-drop tree |
| UI state | **Zustand** (~1KB) | Selection, expansion, rail width — no reason to put these in a query cache |
| Settings | **Top-level `FolderFolio` admin menu** | Houses settings + the migration wizard; one findable home |
| Quality | PHPUnit + Playwright + PHPStan L6, matrixed | The tree is the part most likely to break, so it gets browser tests |
| Business | Free on wp.org, no paid tier | No licensing, no telemetry, no update server, no upsell code paths |
| Navigation | Tree + drill-down cards + breadcrumb | See `claude/design-handoff.md` |
| Multi-folder | Many-to-many; drag moves, bulk action adds | Already decided; it is also what makes our importer safe |
| Developer API | Facade + hooks + WP-CLI + documented REST | Neither market leader ships WP-CLI; both leak internals instead of a contract |
| API auth | **Application Passwords**, no API-key option | FileBird and CatFolders both keep one un-rotatable shared secret in `wp_options` |
| Front end | `folderfolio/gallery` block + shortcode, server-rendered | Folders become a content source, not just an admin filing system |

**Floor:** WordPress 6.4, PHP 8.1. WP 6.4 is where `wp-element` is a stable React 18; PHP 8.1
gives us enums, readonly properties and `never`. Tested up to current.

---

## 2. Current state, honestly

What is already right and stays:

- PSR-4 `includes/` tree with a hand-rolled `Autoloader` (no Composer autoloader shipped)
- `Plugin::boot()` with activation/deactivation hooks, a `DB_VERSION` guard and an upgrade lock
- A real domain layer: `FolderService` / `FolderRepository` / `AttachmentFolderRepository`
- `Capabilities` with three filterable gates
- `MediaLibraryFilter` doing **server-side** filtering in both grid and list mode, verified live
- `uninstall.php`, multisite-aware; `bin/build-zip.sh` allowlist packaging shared with CI
- PHPStan L6 with WordPress stubs; Yarn 4 with a cross-platform lockfile

What has to change:

| Problem | Disposition |
|---|---|
| `assets/src/core/*.ts` — hand-rolled DOM, no virtualisation, no a11y, `prompt()`/`alert()` | **Replaced** by the React app |
| Rail rendered into `all_admin_notices` and repositioned from JS | **Replaced** by a `#wpbody` sibling |
| `FileBirdImporter` targets `fbv_folders`/`fbv_assignments` — tables that do not exist | **Rewritten** against `fbv` / `fbv_attachment_folder` |
| `ImporterInterface` assumes a custom table | **Widened** to three storage shapes |
| Detection = `SHOW TABLES LIKE` (true forever after deactivation) | **Split** into "can import" vs "should offer" |
| No subtree queries; counts are direct-only | **Materialised path** + rollup |
| `template_id`, `owner_id`, `visibility`, `icon` columns nothing reads | **Decide before 1.0** — see §4.4 |
| `MediaModalIntegration` monkey-patches `wp.media.create`, unregistered | **Rewritten** on `wp.media.view.AttachmentsBrowser` |

---

## 3. Layering

```
folderfolio.php                 constants, autoloader, activation/deactivation, boot
uninstall.php

includes/
  Plugin.php                    composition root — the only place that knows everything
  Autoloader.php

  Domain/                       no WordPress, no $wpdb, no superglobals
    Folder.php                  readonly value object
    FolderTree.php              build / walk / roll up counts (pure, unit-testable)
    FolderService.php           create, rename, move, delete, assign — all invariants
    Exceptions/

  Persistence/                  the only layer that touches $wpdb
    FolderRepository.php
    AttachmentFolderRepository.php
    FolderMetaRepository.php
    PreferencesRepository.php
    Schema.php
    Migrations/                 one class per DB_VERSION step

  Rest/
    Routes.php                  registration + JSON schema in one place
    FolderController.php
    AssignmentController.php
    ImportController.php
    SettingsController.php

  Admin/
    Assets.php                  enqueue, externals manifest, inline config
    MediaLibraryScreen.php      the #wpbody sibling mount + native toolbar controls
    MediaModalScreen.php
    SettingsScreen.php          top-level menu
    MediaLibraryFilter.php      pre_get_posts / ajax_query_attachments_args / posts_clauses

  Import/
    ImporterInterface.php
    Sources/                    FileBird, RealMediaLibrary, Taxonomy, PostType
    ImportPlanner.php           produces a preview; never writes
    ImportRunner.php            executes a plan in batches

  Support/
    Capabilities.php
    Logger.php
    Settings.php
```

**The rule that makes this testable:** `Domain/` may not reference a WordPress function. Every
query lives in `Persistence/`, every hook in `Admin/`, every request concern in `Rest/`. That
is what lets `FolderTree` and `FolderService` be unit-tested without a WordPress bootstrap —
which is where most of the logic bugs live.

---

## 4. Data model

### 4.1 What exists today

```sql
{p}folderfolio_folders             id, parent_id, name, slug, color, icon, sort_order,
                                   template_id, owner_id, visibility, created_by,
                                   created_at, updated_at
{p}folderfolio_attachment_folders  folder_id, attachment_id, sort_order, assigned_at
                                   PK (folder_id, attachment_id), KEY attachment_id
{p}folderfolio_folder_meta         folder_id, meta_key, meta_value   PK (folder_id, meta_key)
{p}folderfolio_user_preferences    user_id, preferences              PK (user_id)
```

Root is `parent_id NULL` — a fourth convention, after FileBird/CatFolders' `0` and RML's `-1`.
Fine, but every importer must map it explicitly (see `claude/m2-importer-matrix.md`).

### 4.2 Add: materialised path

```sql
ALTER TABLE {p}folderfolio_folders
  ADD COLUMN path VARCHAR(255) NOT NULL DEFAULT '',
  ADD COLUMN depth TINYINT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN object_type VARCHAR(20) NOT NULL DEFAULT 'attachment',
  ADD KEY path (path),
  ADD KEY object_type_parent (object_type, parent_id);
```

`path` is **ids, slash-delimited, leading and trailing slash, including self**:

```
Brand              id 1   path /1/            depth 0
  Logos            id 7   path /1/7/          depth 1
    Primary        id 12  path /1/7/12/       depth 2
```

The trailing slash is not cosmetic. Subtree of folder 7 is `path LIKE '/1/7/%'`, and folder
70's path `/1/70/` does **not** match it, because the pattern requires a literal `/` where
`/1/70/` has a `0`. Drop the trailing slash and every id that is a prefix of another leaks
into its subtree. This has a test.

`VARCHAR(255)` holds ~30 levels at realistic id widths. `FolderService::move()` rejects
anything deeper than 20 with a real error rather than truncating.

**What path buys:**

| Operation | With path |
|---|---|
| Filter media by folder **including descendants** | `JOIN folders d ON d.id = af.folder_id AND d.path LIKE '/1/%'` |
| Move a subtree | one `UPDATE … SET path = CONCAT(:new, SUBSTRING(path, :len)), depth = depth + :delta WHERE path LIKE :old%` |
| Delete a subtree | one `DELETE … WHERE path LIKE '/1/7/%'` |
| Breadcrumb | parse the path; **zero queries** |
| Cycle prevention | reject if `newParent.path LIKE CONCAT(self.path, '%')` |

`parent_id` stays the source of truth. `path` is derived, and a `ff folders:rebuild-paths`
routine recomputes it from `parent_id` — exposed in settings as a repair action. RML ships five
`/reset/*` endpoints and CatFolders a `clean-db`; two independent competitors concluding a
folder plugin needs a repair tool is enough evidence to build one on day one rather than
discovering it in the support inbox.

### 4.3 Counts — three mechanisms, deliberately

Counts are the thing every competitor gets wrong (4/4 show direct-only), so they get designed
rather than defaulted.

1. **Direct counts** — one `GROUP BY folder_id` over the assignments table. Exact, cheap,
   always correct.
2. **Inherited counts for the tree payload** — roll the direct counts up the tree **in PHP**,
   one O(n) pass over the folder list we already loaded. No extra query, no self-join.
3. **Exact subtree count for the selected folder** — one `COUNT(DISTINCT attachment_id)` with
   the path join, for the one folder currently in view.

Why 2 and 3 both exist: with many-to-many, a file filed in two folders inside the same subtree
is counted twice by a naive rollup. Mechanism 2 is therefore *directionally* right and fast;
mechanism 3 is exact and runs once per selection. The badge in the tree uses 2, the header
above the grid uses 3, and if they disagree the header is right.

Rejected: a cached `descendant_count` column. It has to be invalidated up the entire ancestor
chain on every assign, move, unassign and delete, which is precisely how folder plugins end up
shipping a "recalculate counts" button users have to know about.

**Default is inherited, switchable to direct.** FileBird's API returns both (`actual` and
`display`) and defaults to the confusing one. We default to the useful one.

### 4.4 Columns to resolve before 1.0

**Decided: `template_id`, `owner_id` and `visibility` are dropped in the DB_VERSION 3
migration.** Nothing read or wrote them, and speculative columns are a migration liability —
once they ship to real sites, removing them *is* a migration.

- **`icon`** — kept. Folder colours are designed; icons are a plausible near sibling.
- **`created_by`** — kept. Genuinely useful for auditing, and it is what CatFolders scopes
  its per-user folder logic on.
- **`owner_id` / `visibility`** — dropped. Per-user folders are a real feature (CatFolders
  charges for it), but half a feature in the schema is worse than none. They come back in the
  release that ships the feature.
- **`template_id`** — dropped. No defined meaning.

The migration drops them unconditionally; no installed site has ever written to them, so there
is no data to preserve. This is still a schema change with a test, because an
`ALTER TABLE … DROP COLUMN` against a table that never had the column must not fatal.

---

## 5. REST API

Namespace `folderfolio/v1`. Every route declares a full JSON schema, so
`/wp-json/folderfolio/v1` self-documents and WordPress does the validation and sanitisation.
Real verbs, unlike the four competitors, all of which POST everything.

| Method | Route | Capability |
|---|---|---|
| `GET` | `/folders` — tree, `?counts=inherited\|direct\|none` | `upload_files` |
| `POST` | `/folders` | manage |
| `PATCH` | `/folders/{id}` — name, colour, icon | manage |
| `POST` | `/folders/{id}/move` — `{parent_id, before_id}` | manage |
| `DELETE` | `/folders/{id}?children=reparent\|cascade` | manage |
| `GET` | `/folders/counts` | `upload_files` |
| `POST` | `/assignments` — `{attachment_ids[], folder_id, mode: move\|add}` | per-attachment `edit_post` |
| `DELETE` | `/assignments` — `{attachment_ids[], folder_id}` | per-attachment `edit_post` |
| `GET` | `/import/sources` | `manage_options` |
| `POST` | `/import/preview` — returns a plan, writes nothing | `manage_options` |
| `POST` | `/import/run` — executes a plan, batched | `manage_options` |
| `GET`/`PUT` | `/settings` | `manage_options` |
| `POST` | `/maintenance/rebuild-paths` | `manage_options` |

Notes:

- **`mode` on `/assignments` is the hybrid semantic made explicit.** Drag sends `move`, the
  bulk action sends `add`. CatFolders has no such flag, which is why its importer destroys
  data (§7).
- `DELETE /folders/{id}` **requires** `children`. No silent default for "what happens to my
  subfolders".
- Capability checks are three filterable gates in `Support/Capabilities`, already built:
  `canUseFolders()` = `upload_files`; `canManageFolders()` = explicit cap or
  `edit_others_posts`; `canEditAttachment($id)` = `edit_post`. Per-attachment checks stay
  per-attachment — a contributor may move their own files and not others'.
- Every mutation returns the affected folders **with fresh counts**, so the client refreshes
  badges from the response instead of re-fetching. Borrowed from CatFolders, the one thing its
  API does better than anyone's.

---

## 6. Frontend

### 6.1 Stack

```
react, react-dom          → aliased to wp.element (external, 0 KB)
@tanstack/react-query     ~12 KB gz   server state, optimistic updates, rollback
zustand                   ~1 KB gz    selection, expansion, rail width
@dnd-kit/core + sortable   ~10 KB gz  accessible drag-and-drop, keyboard support
@tanstack/react-virtual   ~3 KB gz    virtualised tree rows
```

Budget: **≤ 45 KB gzipped** for the media-library bundle. FileBird and CatFolders both ship
React + rc-tree + a UI library; we ship neither React nor a component library.

**Not rc-tree**, despite both market leaders using it. It brings its own indentation model —
the exact thing we need to design, and the exact thing CatFolders' build breaks. `@dnd-kit` +
`@tanstack/react-virtual` over our own flat row list gives us full control of indentation,
guide lines, the reserved leaf switcher slot, and keyboard semantics, which is where the
product differentiates.

### 6.2 The `wp-element` shim — the one real integration risk

`@wordpress/element` re-exports React 18 including `useSyncExternalStore`, which both Zustand
and TanStack Query need. But third-party packages import from `react` and
`react/jsx-runtime`, not `@wordpress/element`. So:

- esbuild aliases `react` → `src/shims/react.ts`, which re-exports `window.wp.element`
- esbuild aliases `react/jsx-runtime` → `src/shims/jsx-runtime.ts`, ~15 lines over
  `wp.element.createElement`
- `react-dom` → `wp.element.createRoot` / `render`
- Enqueue declares `wp-element` as a dependency so WordPress loads it first

**Risk:** a dependency reaches for a React export `wp.element` does not re-export. **Escape
hatch:** flip one alias and bundle React ourselves (+45 KB gz). Budget a day for the shim and
verify with a smoke test that imports every React API our dependencies use.

### 6.3 Data layer

```ts
// server state — TanStack Query
useFolderTree()                 // GET /folders, staleTime 30s
useCreateFolder()               // optimistic insert, rollback on error
useMoveFolder()                 // optimistic reparent, rollback on error
useAssign()                     // optimistic count deltas, rollback on error

// UI state — Zustand, persisted to folderfolio_user_preferences
selectedFolderId, expandedIds, railWidth, railCollapsed, countMode, sort
```

Every mutation is optimistic with rollback. Dragging a folder across a tree and watching it
snap back 400ms later is the single loudest quality signal in this kind of UI, and it is
exactly what hand-rolled state gets wrong.

**URL is a first-class input, not an afterthought.** `?folderfolio_folder=12` on load →
expand every ancestor (parsed from the path, no query) → scroll into view → select. On
selection change → `history.replaceState` in grid, form submit in list. This is the one thing
none of the four competitors do, so it gets a test.

### 6.4 Mount

```php
add_action('in_admin_header', [$this, 'renderRailMount']);  // before #wpbody-content
```

A real DOM sibling of `#wpbody-content`, `position: sticky`, its own scroll container — the
pattern FileBird and CatFolders converged on independently. The current
`all_admin_notices`-plus-JS-reposition approach goes.

Config is injected with `wp_add_inline_script(..., 'before')`: REST root, nonce, capabilities,
settings, and the i18n map — already how `MediaLibraryIntegration` works today, and it stays.

### 6.5 Bundles

| Bundle | Screen | Entry |
|---|---|---|
| `media-library` | `upload.php` | rail, cards, breadcrumb, toolbar controls |
| `media-modal` | anywhere `wp.media` opens | the rail, narrowed |
| `settings` | our top-level menu | settings + migration wizard |

Three entries, shared chunks, CSS extracted per bundle.

### 6.6 Accessibility — non-negotiable

Today the tree is spans with click handlers. Target: `role="tree"` / `treeitem` / `group`,
`aria-expanded`, `aria-level`, `aria-selected`, roving tabindex, and the full arrow-key
contract (←/→ collapse/expand, ↑/↓ move, Home/End, type-ahead). `@dnd-kit` gives keyboard
drag-and-drop for free, which no competitor has. This is a Playwright test, not a hope.

---

## 7. Import architecture

The spec is written against what CatFolders' importer actually did to a live tree — created
five duplicate folders and moved files out of the user's own folders, silently. Full account
in `claude/research-04-catfolders.md`.

```
ImporterInterface
  ├ supports(): bool          data present?          → "can import"
  ├ isActive(): bool          plugin active?         → "should offer"
  ├ describe(): SourceInfo    name, author, counts
  └ read(): SourceTree        normalised folders + assignments

Sources/
  CustomTableSource           FileBird (fbv), RML (realmedialibrary), CatFolders (catfolders)
  TaxonomySource              Folders, EML, MLA, WPMF, HappyFiles, Wicked — six for one path
  PostTypeSource              WP Media Library Folders (mgmlp_media_folder)
```

`supports()` and `isActive()` being separate methods **is** the fix for the detection bug: the
Tools screen lists everything with data, the in-context prompt fires only for active plugins.

**`ImportPlanner` produces a plan and writes nothing.** The plan is the preview:

```
create   12 folders
merge     3 folders by name         (Brand, Campaigns, Archive — existing folders reused)
add     247 files to folders
move      0 files out of folders    ← always zero, by design
skip      4 assignments             (attachments no longer exist)
```

**`move` is always zero because we add rather than move.** Many-to-many means an import never
has to take a file out of a folder the user made. That single property removes the entire
class of failure CatFolders ships.

`ImportRunner` executes a plan in batches of ~200 through the REST endpoint with progress, and
writes provenance per folder into `folderfolio_folder_meta`:

```
_import_source     filebird
_import_source_id  17
_imported_at       2026-09-16T14:02:11Z
```

Per-folder, not per-source — so a re-run reconciles (update the renamed ones, add the new
ones) instead of CatFolders' all-or-nothing "Already Imported".

Priority: FileBird (200k installs) → RML (100k) → the taxonomy path, which buys six sources at
once → CatFolders. Every importer gets a fixture-based test; the playground already holds real
residual tables from all four, which is what they are for.

---

## 8. Developer platform

### 8.1 What the market actually exposes

Read from source, not documentation.

**FileBird** has the deepest surface: **9 actions** (`fbv_after_folder_created`,
`fbv_after_folder_renamed`, `fbv_after_parent_updated`, `fbv_after_assign_folder`,
`fbv_before_setting_folder`, …) and **~14 filters** (`fbv_can_delete_folder`,
`fbv_counter_type`, `fbv_folder_created_by`, `fbv_ids_assigned_to_folder`,
`fbv_in_not_in_where_query`, `filebird_post_types`, …). `FileBird\Model\Folder` carries 30+
public static methods — `newFolder`, `newOrGet`, `getFoldersOfPost`, `assignFolder`,
`deleteFolderAndItsChildren`, `getChildrenIds`, `exportAll` — which is a de facto PHP API
whether or not they call it one. Plus a `publicRestApi*` controller with twelve methods behind
a bearer token.

**CatFolders** has two filters and a `/CatFolders/v1/public/*` namespace behind a 40-character
key generated by `POST /generate-api-key` and stored in the `catf_rest_api_key` option.

**Neither ships WP-CLI.** Verified — `grep -rl WP_CLI` finds nothing in either tree.

### 8.2 The auth decision, and why both of them are wrong

FileBird and CatFolders each store **a single long-lived shared secret in `wp_options`**. It
is not scoped, not rotatable, not attributable to a person, has no expiry, and bypasses the
WordPress user model entirely — whoever holds that string acts with unbounded rights.

**We use Application Passwords instead.** In core since 5.6: per-user, individually revocable,
listed in the user's own profile, and authenticated through the normal capability stack — so
the three gates in `Support/Capabilities` apply unchanged, with no second auth path to write,
test and get wrong.

Consequence: **no `/public` namespace and no API key.** `folderfolio/v1` is the API, for the
admin app and for third parties alike. One surface, one set of permission callbacks, one set
of tests.

### 8.3 Four layers

**Hooks** — the extension surface.

```
Actions
  folderfolio_folder_created           (Folder $folder)
  folderfolio_folder_renamed           (Folder $folder, string $previousName)
  folderfolio_folder_moved             (Folder $folder, ?int $previousParentId)
  folderfolio_folder_deleted           (int $id, string $childStrategy)
  folderfolio_attachments_assigned     (int[] $ids, int $folderId, string $mode)
  folderfolio_attachments_unassigned   (int[] $ids, ?int $folderId)
  folderfolio_import_completed         (ImportResult $result)

Filters
  folderfolio_can_use_folders          (bool)                    — exists
  folderfolio_can_manage_folders       (bool)                    — exists
  folderfolio_can_edit_attachment      (bool, int $id)           — exists
  folderfolio_default_folder_for_upload (?int, int $attachmentId)
  folderfolio_max_depth                (int, default 20)
  folderfolio_count_mode               (string)
  folderfolio_tree_query_args          (array)
  folderfolio_rest_folder_response     (array, Folder)
```

`folderfolio_default_folder_for_upload` is the one that matters: auto-filing uploads by rule is
what Premio sells as per-post-type default folders. One filter, free, and it is what every
"file my WooCommerce product images under /products" integration needs.

**Facade** — `FolderFolio` as the stable contract, delegating to `Domain/`. The point is that
`Domain/` and `Persistence/` stay refactorable while this does not move:

```php
FolderFolio::createFolder(string $name, ?int $parent = null): Folder;
FolderFolio::renameFolder(int $id, string $name): Folder;
FolderFolio::moveFolder(int $id, ?int $parent, ?int $beforeId = null): Folder;
FolderFolio::deleteFolder(int $id, string $children = 'reparent'): void;

FolderFolio::getFolder(int $id): ?Folder;
FolderFolio::findFolderByPath(string $path): ?Folder;      // 'Brand/Logos/Primary'
FolderFolio::getOrCreateByPath(string $path): Folder;
FolderFolio::getTree(?int $rootId = null): FolderTree;
FolderFolio::getChildren(int $id): array;
FolderFolio::getAncestors(int $id): array;                 // zero queries — parsed from path
FolderFolio::getDescendantIds(int $id): array;

FolderFolio::assign(array $attachmentIds, int $folderId, string $mode = 'add'): void;
FolderFolio::unassign(array $attachmentIds, ?int $folderId = null): void;
FolderFolio::getFoldersOf(int $attachmentId): array;
FolderFolio::getAttachmentIds(int $folderId, bool $includeDescendants = false): array;
FolderFolio::countAttachments(int $folderId, bool $includeDescendants = true): int;
```

`getOrCreateByPath('2026/Campaigns/Spring')` is the method integrators actually reach for —
importers, upload rules, WP All Import, migration scripts. FileBird's nearest equivalent,
`newOrGet($name, $parent)`, resolves one level and leaves the caller to walk the rest.
`getAncestors()` costs zero queries because the path column already holds the answer (§4.2).

**WP-CLI** — nobody in this market has it, and for someone running WordPress across a hosting
estate it is the difference between a plugin and a tool.

```
wp folderfolio folder list [--tree] [--parent=<id>] [--format=table|json|csv|ids]
wp folderfolio folder create <path> [--porcelain]
wp folderfolio folder move <id> --parent=<id>
wp folderfolio folder delete <id> --children=reparent|cascade
wp folderfolio assign <ids> --folder=<id> [--mode=add|move]
wp folderfolio import list | preview <source> | run <source> [--yes]
wp folderfolio rebuild-paths
wp folderfolio doctor          # orphans, cycles, path drift, count drift
```

`wp folderfolio import run filebird` across a fleet, non-interactively, is a genuinely
different proposition from clicking a button per site.

**REST** — §5, documented with real schemas so `/wp-json/folderfolio/v1` is self-describing.

### 8.4 The compatibility promise

Facade and hooks are **semver-stable from 1.0**. Everything under `Domain/`, `Persistence/`
and `Rest/Controllers` is explicitly internal and may change in any release — stated in the
docs, so nobody ends up depending on our internals the way integrators now depend on
`FileBird\Model\Folder`.

`@since` on every public symbol. Removals go through `_deprecated_function()` with a
two-minor-release window. A `docs/` directory in the repo and a `readme.txt` FAQ section;
every documented example is a snippet lifted from a passing test, so the docs cannot rot
silently.

---

## 9. Front end — the gallery block

Folders stop being an admin filing system and become a content source.

**Block `folderfolio/gallery`, server-rendered.** Attributes: `folderIds[]`,
`includeDescendants`, `columns`, `gap`, `orderBy`, `order`, `limit`, `linkTo`, `lightbox`.
Plus `[folderfolio_gallery folder="Brand/Logos" columns="4"]` for classic themes and page
builders — a shortcode takes a *path*, because that is what a human writes by hand.

**Server-rendered, not client-rendered**, and the reason is the security model: a
client-rendered block needs a publicly readable REST endpoint, which is a new unauthenticated
query surface. `render.php` means the front end makes no API call at all, works under
full-page caching, and costs nothing in Core Web Vitals.

**Use core's lightbox, not our own.** WordPress ships an Interactivity-API lightbox on the core
Image block since 6.4. FileBird bundles PhotoSwipe; we can render `<figure>` markup that opts
into core's behaviour and ship **zero front-end JavaScript**. If that proves too constrained,
the fallback is a ~6KB lightbox, still less than PhotoSwipe.

**This is the first code path an unauthenticated visitor reaches.** Everything else in the
plugin is `upload_files`-gated. So:

- queries are constrained to `post_type=attachment`, `post_status=inherit`
- `includeDescendants` uses the path `LIKE` join from §4.2, with the resolved id set cached in
  `wp_cache`
- **folders are not access control**, and the docs say so in those words — putting a file in a
  folder does not make it private, and never will
- a test asserts the block cannot surface an attachment the theme would not otherwise show

---

## 10. Build and tooling

**Keep esbuild.** Builds are ~50ms and it already works. Add roughly 100 lines of glue:

- an externals plugin mapping `react`/`react-dom`/`@wordpress/*` to WP script handles
- an asset-manifest generator writing `*.asset.php` (dependencies + content-hash version), the
  thing `@wordpress/scripts` is chiefly valuable for
- `--format=iife`, per-bundle CSS, sourcemaps in dev only

Reversible: if the shim or the manifest becomes a maintenance chore, `@wordpress/scripts` is a
half-day swap and reviewers know it. Not worth paying webpack's cost up front.

```
mise run dev          esbuild --watch
mise run typecheck    tsc --noEmit
mise run lint         PHPCS (WordPress-Extra) + ESLint + Prettier
mise run analyse      PHPStan L6
mise run test         PHPUnit + Vitest
mise run e2e          Playwright against wp-env
mise run zip          bin/build-zip.sh
```

`bin/build-zip.sh` stays the single packaging path for both local and CI — it already fails
the build when `Autoloader.php`, `Plugin.php` or `uninstall.php` are missing, and its
forbidden-path regex keeps `node_modules`, `vendor`, `tests` and sources out of the zip.

---

## 11. Testing and CI

| Layer | Tool | Covers |
|---|---|---|
| Unit | PHPUnit, no WP bootstrap | `FolderTree` rollup, path arithmetic, cycle detection, `ImportPlanner` |
| Integration | PHPUnit + `WP_UnitTestCase` | repositories, REST routes, capabilities, `posts_clauses` SQL |
| Migration | PHPUnit | each `DB_VERSION` step forward from every prior version |
| Static | PHPStan L6 + PHPCS | types, WordPress conventions, escaping, i18n |
| Frontend unit | Vitest | tree reducers, path parsing, URL sync |
| E2E | Playwright + `wp-env` | create/rename/move/delete, drag, filter, URL state, keyboard tree, import preview |
| Package | `bin/build-zip.sh` + Plugin Check | contents, headers, wp.org rules |

Matrix: PHP 8.1 / 8.2 / 8.3 / 8.4 × WP 6.4 / latest / trunk. Multisite on one cell — WP
`activate_plugin` on a network runs activation per site, which the current code has never been
exercised against (review item #27).

Tests that exist because a competitor taught us to write them:

- a child path never leaks into a sibling's subtree (the `/1/7/` vs `/1/70/` trap)
- inherited counts are non-zero on a parent whose files are all in children (4/4 fail this)
- selecting a folder writes the URL in **both** grid and list (4/4 fail this)
- an import creates **zero** file moves out of pre-existing folders (CatFolders fails this)
- an import over an existing tree produces **zero** duplicate sibling names (CatFolders fails this)
- a deep-linked `?folderfolio_folder=` expands ancestors and scrolls into view on cold load

---

## 12. Security, i18n, performance

**Security.** Nonce on every REST call (`wp_rest`); capability gate on every route; `$wpdb->prepare`
everywhere, with column whitelists via `array_intersect_key` on update (already built);
`esc_html`/`esc_attr` on every PHP-rendered string; the React app renders text as children, never
`dangerouslySetInnerHTML`. Folder names are stored raw and escaped on output. No remote calls of
any kind — nothing to phone home to.

**i18n.** Text domain `folderfolio`, matching the slug. All strings pass through `__()` in PHP.
The JS side keeps the current approach — a translated map injected via inline script and read by
`t(key, fallback, ...values)` — rather than `wp.i18n`, because the map is already built and
`wp_set_script_translations` needs JED files generated at build time. Revisit once the string
count justifies it. `wp.i18n` is the "correct" answer and the migration is mechanical.

**Performance budget.** Tree of 1,000 folders renders in < 100ms (virtualised, so DOM cost is
constant). `GET /folders` under 50ms at 1,000 folders / 50,000 attachments. Media grid filter
adds one INNER JOIN and one indexed `LIKE` prefix. Bundle ≤ 45 KB gz. No query in an admin page
load that isn't in the tree fetch — which also fixes the duplicate `/tree` request (review #23).

---

## 13. The settings screen

Top-level admin menu, `folderfolio`, `dashicons-portfolio`, **capability `manage_options` on
the menu itself** — not `read`, which is CatFolders' mistake and why every subscriber on their
sites sees a menu item that then refuses them.

Three tabs: **Settings · Import · Status**.

- *Settings* — count mode, default sort, undo timeout, who can manage folders (a real roles
  matrix, which two competitors charge for), and folder colours.
- *Import* — detected sources with live counts, preview, run, history.
- *Status* — schema version, table row counts, rebuild-paths, orphan cleanup. The thing RML and
  CatFolders both discovered they needed.

What it does **not** do: no branded header bar, no "Go Pro" tab, no PRO-badged disabled rows, no
`remove_all_actions('admin_notices')`, no deactivation survey. All four observed; all four
declined.

---

## 14. Sequence

1.0 ships whole — admin app, migration wizard, developer platform and gallery block together.

**The public API is not a late phase.** The facade and the hooks are the domain layer's public
face, so they get built *with* it, not bolted on afterwards. Hooks added at the end miss firing
points; a facade designed after the UI ends up shaped by the UI's needs rather than an
integrator's. Moving them early costs nothing and is the single biggest quality lever on the
API surface.

**Phase 0 — land what exists (½ day).** Push `refactor/merge-src-into-includes`, `yarn install`
on the Mac (node_modules predates the package.json changes), green CI on the current code.

**Phase 1 — schema, domain and hooks (3 days).** `path` + `depth` + `object_type` as
DB_VERSION 3, with a migration that backfills from `parent_id`. `FolderTree`, subtree
move/delete, cycle detection, rebuild-paths, counts. Resolve the speculative columns.
**Every action and filter from §8.3 fires from `FolderService` in this phase**, with a test per
hook. All unit-tested before a line of UI.

**Phase 2 — facade and REST, together (3–4 days).** Both are consumers of the same domain
services, so they get designed as a pair. **Write `docs/` and the example snippets first**, then
implement the facade to satisfy them — the API is designed from the caller's side or not at
all. Schema-driven REST routes, real verbs, `mode` on assignments, `children` on delete, fresh
counts in every mutation response. Application-Password auth verified end to end. Integration
tests for both.

**Phase 3 — WP-CLI (1 day).** Thin commands over the facade. Cheap precisely because phase 2
came first — if this phase is expensive, the facade is wrong, which makes it a useful check.

**Phase 4 — frontend foundation (3–4 days).** esbuild externals + asset manifest, the
`wp-element` shim with its smoke test, the `#wpbody` sibling mount, React + Query + Zustand
wired, the tree rendering read-only with correct indentation and virtualisation. **Design lands
here** — this is the phase that consumes Claude Design's output.

**Phase 5 — interaction (4–5 days).** Inline create/rename with Save/Cancel, drag with dnd-kit,
full keyboard tree, search, drill-down cards, breadcrumb, undo toast, URL state, native toolbar
controls. Playwright throughout.

**Phase 6 — modal and settings (2–3 days).** The media modal rail on
`wp.media.view.AttachmentsBrowser` (not the `wp.media.create` monkey-patch), and the settings
screen with its three tabs.

**Phase 7 — import (3–4 days).** `ImporterInterface` in three shapes, FileBird against the real
schema, RML, the taxonomy path. Planner, preview UI, batched runner, provenance. Uses the
facade, which is a second check on it.

**Phase 8 — gallery block (4–5 days).** Block registration, `render.php`, the editor sidebar
reusing the tree component at inspector width, the shortcode, core-lightbox integration,
theme-compat passes against the last three default themes plus a page builder, and the
unauthenticated-access tests.

**Phase 9 — release candidate and hardening (4 days).** See below.

**Phase 10 — release (2 days).** `readme.txt` with your "Tested up to" and changelog,
screenshots, Plugin Check clean, wp.org SVN, accessibility and i18n audits.

Roughly **six weeks of focused work**. Phases 1–2 and 4–5 are the ones worth not rushing.

### Phase 9 exists because 1.0 is one shot

Shipping everything at once means the compatibility promise gets frozen with no integrator
feedback, and wp.org gives one first impression — a rough 1.0 collects reviews that outlive the
fix. So the feedback loop moves *before* the wp.org submission rather than after it:

- **Tag an RC on GitHub** and install it across a slice of your own hosting estate. Real sites,
  real libraries, real PHP versions — better test data than any playground.
- **Put the facade in front of three or four developers** with the docs and nothing else, and
  watch where they reach for something that isn't there. `getOrCreateByPath()` exists because
  that is the method integrators want; there may be a second one we haven't thought of, and
  finding it now is far cheaper than deprecating around it later.
- **Freeze the public surface at the end of this phase.** Whatever is in `docs/` on the day we
  submit is the contract.

This buys most of what a staged 1.0/1.1/1.2 would have bought, without splitting the release.

---

## 15. Risks

| Risk | Likelihood | Response |
|---|---|---|
| `wp-element` missing a React export a dependency needs | medium | Shim smoke test in phase 3; escape hatch is bundling React (+45 KB) |
| Path column drifts from `parent_id` | low | `parent_id` is truth; rebuild-paths in Status; every move is one transaction |
| Inherited counts over-count many-to-many files | certain, by design | Exact count for the selected folder; documented; direct mode available |
| `posts_clauses` collides with another plugin | medium | Uniquely-aliased join, already done; ship with a conflict note |
| `ajax_query_attachments_args` key intersection changes in core | low | Currently read from `$_REQUEST['query']` directly; integration test pinned per WP version |
| wp.org review friction | low | No remote calls, no telemetry, no bundled binaries, Plugin Check in CI |
| Scope creep into post types | **high** | Schema is ready (`object_type`); the UI is explicitly out of scope for 1.0 |
| The facade locks in a contract we regret | medium | Keep it thin and delegating; 16 methods, no leaked internals; deprecation policy from day one |
| Gallery block conflicts with theme CSS | **high** | Minimal, well-scoped markup; test against the last three default themes plus a page builder |
| Front-end block becomes an information-disclosure bug | medium | Server-rendered only, `post_status=inherit` constrained, explicit test, "folders are not access control" documented |
| Core's lightbox proves too constrained | medium | Fallback is a ~6KB lightbox, still smaller than PhotoSwipe |
| Big-bang 1.0: contract frozen with no integrator feedback, one wp.org first impression | **high** | Phase 9 — GitHub RC on your own hosting estate, facade in front of real developers, surface frozen only at submission |
| Six weeks before anything is installable | medium | RC tags from phase 5 onward; the admin app is usable well before the block exists |

---

## 16. Open, for a later conversation

1. Does an "unlimited depth" claim need a practical cap? 20 is proposed; it should be a stated
   limit with a real error, not a truncation.
2. CSV export/import as a generic escape hatch — FileBird and CatFolders both ship it, and
   with the facade in place it is a thin WP-CLI command plus a settings action.
3. Folder-level sort order for files (`sort_order` exists in the assignments table, unused) —
   now more relevant, because a gallery block wants a curated order.
4. Whether `owner_id` / `visibility` come back as per-user folders in 1.1.
5. Whether the gallery block ships a second variant (slider/masonry) or stays one honest grid.
6. Page-builder integrations — CatFolders advertises "20+ page builders". With a documented
   facade and a shortcode, most of that is other people's work rather than ours.
