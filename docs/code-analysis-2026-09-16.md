# FolderFolio — Deep Code Analysis

**Repo:** `github.com/moustakalis/folderfolio` (branch `main`, 68 commits, HEAD `00f6b6e`)
**Analysed:** 16 Sep 2026 · ~2,960 LOC of first-party PHP/TS
**Verdict:** The domain layer and REST API are genuinely good. The plugin, as it currently boots, runs none of them.

---

## 1. The headline problem: the plugin is wired to an empty shell

`folderfolio.php` registers an autoloader rooted at `includes/` and boots `\FolderFolio\Plugin::init()`:

```php
\FolderFolio\Autoloader::register(FOLDERFOLIO_PLUGIN_DIR); // → includes/
add_action('plugins_loaded', [\FolderFolio\Plugin::class, 'init']);
```

`includes/Plugin.php` is 25 lines and does exactly two things: register an admin menu page, and call `FoldersModule::register()` — which is a `// TODO` comment. The real implementation (Schema, FolderService, FolderRepository, FolderController, ImportController, all three Admin integrations — ~1,800 lines) still lives in `src/` and is **never loaded**.

Eighteen commits on 11 Sep are titled "Move X to includes/". They didn't move anything. They created hollow same-named stubs in `includes/` and left `src/` in place. The result:

| Namespace | `includes/` (loaded) | `src/` (dead) |
|---|---|---|
| `Plugin` | 25 lines, 2 static calls | 128 lines, full bootstrap |
| `Database\Database` / `Database\Schema` | `// TODO` | 4-table `dbDelta` migration |
| `Rest\FoldersController` / `Rest\FolderController` | `// TODO` | 10 REST routes, 401 lines |
| `Domain\Folder` | value object, unused | `FolderService` (424) + 2 repositories |
| `Modules\FoldersModule` | `// TODO` | FileBird importer (340) |
| `Support\Logger` | `error_log` one-liner | structured JSON logger |

Installing the current ZIP gives you a **Media → FolderFolio** page that prints two paragraphs of static HTML. No tables, no REST API, no folder tree.

There's a second trap in here: both trees declare the same FQCNs (`FolderFolio\Support\Logger`, `FolderFolio\Plugin`, …). `composer.json` maps PSR-4 `FolderFolio\` → `src/`, while the runtime autoloader maps it → `includes/`. If Composer's autoloader is ever loaded alongside the plugin (some test setups, some WP stacks with a global vendor dir), whichever wins silently decides whether the plugin works — and a "class already declared" fatal is one require away.

**Fix:** decide on one directory. Given the `includes/` convention is more WP-idiomatic, `git mv src/* includes/`, delete the stubs, update `composer.json` PSR-4 and `phpstan.neon`/`phpunit.xml.dist` paths, and have `Plugin::init()` instantiate and `boot()` the real class.

---

## 2. Blockers that remain even after the wiring is fixed

These would each stop the plugin working in a real WordPress install.

### 2.1 The database tables are never created
There is no `register_activation_hook` anywhere in the codebase. `Schema::migrate()` exists and is correct, but `src/Plugin::activate()` is never registered with WordPress. Every query would hit a non-existent table. Verified: `grep -rn register_activation_hook` matches only PHPStan's cache.

### 2.2 `FOLDERFOLIO_PLUGIN_FILE` is undefined
`src/Plugin::loadTextDomain()` dereferences `FOLDERFOLIO_PLUGIN_FILE`. `folderfolio.php` defines `_VERSION`, `_PLUGIN_DIR`, `_PLUGIN_URL`, `_PLUGIN_BASENAME` — not `_PLUGIN_FILE`. The only definition is in `phpstan-bootstrap.php`, i.e. static analysis passes and runtime throws `Error: Undefined constant` on `init`. A constant defined solely so the analyser stays quiet is a warning sign worth a second look elsewhere too.

### 2.3 The built JS is ESM, the PHP enqueues it as a classic script
`npm run build` runs esbuild with `--format=esm`. The output ends with `export{n as FolderTree,s as folderTree};`. Every `wp_enqueue_script` call omits `type="module"`, so the browser throws `SyntaxError: Unexpected token 'export'` and nothing initialises. Either build with `--format=iife` (right choice for WP admin scripts) or add a `script_loader_tag` filter.

### 2.4 The CSS is never built
`Plugin::enqueueAdminAssets` and `MediaModalIntegration` enqueue `assets/build/core/admin.css` and `media-modal.css`. The npm `build` script only compiles `*.ts`. Confirmed: `assets/build/core/` contains six `.js` + six `.js.map` and no CSS. The GitHub Actions workflow copies the CSS manually as a separate step; `mise run dev:zip` does not — so CI artifacts and local ZIPs differ. `MediaLibraryIntegration::enqueueAssets` also enqueues `media-modal.css` with **no `file_exists` guard**, unlike its siblings, so it emits a hard 404.

### 2.5 The declared PHP floor is wrong
Header and `composer.json` say PHP 8.0. `FolderService` and `FolderController` use `readonly` promoted properties and `new` in parameter defaults — both **PHP 8.1**. On 8.0 this is a parse error, not a graceful degradation.

### 2.6 Version and requirement metadata disagree
Plugin header: `Version: 0.1.0`, `Requires at least: 6.0`. README: "1.0.0 – Initial Release", "WordPress 6.4+". `package.json`: `1.0.0`. The `['in_footer' => true]` array form of `wp_enqueue_script` is WP 6.3+, so 6.0 is not actually supported.

---

## 3. Features the README promises that the code doesn't deliver

The README's checklist reads as shipped. Measured against the source:

| Claim | Reality |
|---|---|
| Drag-and-drop organization | **Not implemented.** No drag handlers anywhere in `assets/src/`. |
| Media Library filtering | **Client-side DOM hiding only.** `filterMediaByFolder()` fetches attachment IDs and sets `style.display='none'` on tiles already on the page. It doesn't touch `WP_Query`/`ajax-query-attachments`, so it breaks with pagination and infinite scroll, and the counts stay wrong. |
| Folder colors and icons | Stored and validated; **no UI** ever sets them. |
| Bulk operations | Partly broken — see 3.1. |
| Multi-folder membership | Schema supports it; no UI exposes it. |
| Upload directly to folder / auto-assign | Registration bug — see 3.2. |
| Import from FileBird | **Solid.** Handles free (`fbv_*`) and Pro (`fbr_*`) tables, maps parent IDs, collects per-row errors. |
| Safe deactivation | True by omission — no `uninstall.php`, no deactivation cleanup. |

### 3.1 Bulk "move" sends a hardcoded source folder
```ts
data = { source_folder_id: 1, // TODO: Get actual source folder
```
Every bulk move unassigns from folder ID 1 regardless of where the media actually lives. `showFolderPicker` also builds a `folderOptions` `<option>` string and then discards it in favour of `prompt()` with a plain-text folder listing. It also reads `response.data?.tree`, but `GET /tree` returns the array **directly** as `data` — so the picker always shows an empty list.

### 3.2 Two dead event wires
- `media-library-integration.ts` subscribes with `document.addEventListener('folderfolio:folder-selected', …)`, but `folder-tree.ts` publishes with `window.dispatchEvent(...)`. An event dispatched on `window` has `window` as its entire propagation path — the document listener never fires. The whole module is dead code.
- `upload-integration.ts` calls `injectUploadUI()` from `init()`, and `injectUploadUI()` registers a `DOMContentLoaded` listener. But `init()` is itself called from a `DOMContentLoaded` handler (or later, when the doc is already parsed), so that inner listener can never fire. When it did fire it would create a second element with `id="folderfolio-upload-to-folder"` — the folder tree already renders one.

### 3.3 Event-delegation bug in the folder tree
```ts
if (target.closest('.folderfolio-folder-name')) {
  const folderId = parseInt(target.getAttribute('data-folder-id') || '0', 10);
```
`closest()` finds the ancestor, then the ID is read off `target` — the element actually clicked. Click the text node's parent and it works; click anything nested and you get folder `0`. Should be `target.closest('.folderfolio-folder-name')?.getAttribute(...)`. Same pattern in the toggle branch and in `media-modal.ts` (which reads `dataset.folderId` off `.folderfolio-modal-toggle`, an element that never carries that attribute — the toggle is therefore permanently broken).

### 3.4 Monkey-patching `wp.media.create`
`media-modal.ts` reassigns `wp.media.create` globally to intercept frame construction. Any other plugin doing the same, or any code path using `new wp.media.view.MediaFrame.*` directly, bypasses it. WordPress does expose proper extension points here (`wp.media.view.AttachmentsBrowser` / `l10n` filters, `wp.media.controller.Library` state); the patch is the fragile version.

---

## 4. Security and data-integrity review

Nothing critical — no SQL injection, no missing permission callbacks — but three things worth fixing before a public release.

1. **Capability is too broad.** Every folder route uses `current_user_can('upload_files')`, which Authors and Contributors hold. Any of them can rename, move, or delete *any* folder including other users' — and `DELETE /folders/{id}?reassign_to=` will bulk-reassign media they don't own. The schema already has `owner_id` and `visibility` columns for exactly this; nothing reads them. Split the capability: `upload_files` for read/assign, `manage_options` (or a custom `manage_media_folders` cap) for destructive folder operations.
2. **`FolderRepository::update()` forwards its `$data` array straight to `$wpdb->update()`** with no column whitelist. It's safe *today* only because `FolderService::sanitize()` whitelists keys upstream. One direct caller, one new code path, and it becomes arbitrary-column write. Whitelist at the repository boundary too.
3. **No transactions, no cleanup hooks.** `moveAttachments()` assigns to the destination and *then* unassigns from the source as two independent operations — a failure halfway leaves media in both folders. `delete()` does three sequential writes the same way. And nothing hooks `delete_attachment`, so assignment rows outlive their media indefinitely. `dbDelta` also can't express foreign keys, so the DB won't clean up for you.

**Schema bugs:**
- `folderfolio_folder_meta` has `PRIMARY KEY (folder_id)` — one meta row per folder, ever. Should be `PRIMARY KEY (folder_id, meta_key)`.
- No unique constraint on `(parent_id, name)` or `slug`, so duplicate sibling folders are allowed and slugs collide.
- `template_id`, `owner_id`, `visibility`, and the entire `folder_meta` and `user_preferences` tables are created and never read or written by anything. Either build the features or drop the columns before 1.0 — schema you ship is schema you have to migrate forever.
- `FolderService::delete()` flattens children to root rather than cascading or blocking. Defensible, but it's silent data reorganisation and isn't mentioned anywhere in the UI or README.

---

## 5. Tooling: three overlapping build systems, none complete

- **Vite** — `vite.config.ts` defines six entry points, one named `folderfolio` (the PHP expects `folder-tree.js`). Nothing runs it. `vite` is listed as a `devDependency` at `^6.2.0` *and* a `dependency` at `^8.2.2`; the lockfile resolved 6.4.3.
- **esbuild** — what actually builds, and it is **not declared in `package.json` at all**. It's only present transitively under Vite. `npm ci` on a Vite version that vendors a different esbuild, and the build breaks.
- **TypeScript** — `tsconfig.json` is strict and never runs. `.mise.toml` describes `assets:build` as "Type-check and build production assets"; it runs `npm run build`, which is esbuild alone (esbuild strips types, it doesn't check them). Running `tsc --noEmit` today produces **19 errors**: `Folder` used but not imported in `bulk-actions.ts`, `wp` and `jQuery` undeclared across three files, and an illegal `declare global` inside a non-module. Adding `tsc --noEmit &&` to the build script and installing `@types/jquery` + `@types/wordpress__media-utils` would have caught most of section 3.

**Packaging:** `mise run dev:zip` copies `folderfolio.php` + `includes/` + built assets — i.e. it ships the stubs and leaves the working code out. The CI workflow instead `rsync`s the whole tree (shipping `src/`, `.mise.toml`, `.distignore`, `.idea/`…). Neither respects `.distignore`, which is a well-written file that nothing reads. Three packaging paths, three different outputs.

**Tests:**
- `tests/Unit/Domain/FolderServiceTest.php` is four `markTestIncomplete()` calls — the one file testing the most important 424 lines of logic.
- `ImporterFactoryTest` is under `Unit/` but `new FileBirdImporter()` does `global $wpdb` into a typed `wpdb` property — a `TypeError` outside WordPress. It isn't a unit test.
- `phpunit.xml.dist` puts Unit and Integration in one suite behind a bootstrap that `exit(1)`s without the WP test library, so you can't run the fast tests alone.
- `@playwright/test` is imported by both e2e specs and by `playwright.config.ts`, and is **not in `package.json`**. `npm run test:e2e` cannot work from a clean checkout.
- CI installs Subversion, then uses `sjinks/setup-wordpress-test-library` which doesn't need it. `bin/install-wp-tests.sh` is likewise unused.
- PHPStan is at level 6 over `src/` — the code it analyses is the code that doesn't run, and `includes/` is analysed by nothing.

**Repo hygiene:** `node_modules/` and `vendor/` are correctly gitignored but present on disk; `.DS_Store` files and a stale `.idea/` are in the working tree; `src/components/*.vue`, `src/stores/counter.ts`, and `src/assets/*.css` are **empty tracked files** left from a Vue scaffold (two commits say "remove old Vue scaffold file"; the files are still there at 0 bytes); `dist/` contains only a `.DS_Store`; `.distignore` is staged-but-modified and `.mise.local.toml` is untracked and empty.

---

## 6. What's actually good

Worth saying plainly, because the fixable problems above are mostly wiring, not design:

- **`FolderService` is well-built.** Sanitise → validate → act, `WP_Error` returned rather than thrown, cycle detection in `move()` via `isDescendant()`, self-parent guard, reassign-on-delete. The tree builder is a clean single-pass bucket-by-parent then recurse — O(n), not the N+1 query loop most folder plugins ship.
- **Consistent REST envelope.** `{success, data}` / `{success, error:{code,message}}` with a small `result()` helper collapsing the `WP_Error` branch. Returning the refreshed tree from every mutation is exactly right for a tree UI. A `/health` endpoint is a nice touch.
- **Repository/Service/Controller separation is real**, not ceremonial — the controllers hold no logic.
- **Thorough PHPStan `@phpstan-type` annotations** on the array shapes crossing layers.
- **The FileBird importer is the strongest asset in the codebase** and the clearest competitive wedge. It handles both schema variants and reports per-row failures instead of aborting.
- **Many-to-many attachment↔folder from day one**, where FileBird is one-folder-per-file. Good product call, if the UI ever exposes it.

---

## 7. Suggested order of work

**Unblock (nothing works until these land)**
1. Collapse `src/` and `includes/` into one tree; delete the stubs; update `composer.json`, `phpstan.neon`, `phpunit.xml.dist`.
2. Add `register_activation_hook(__FILE__, ...)` → `Schema::migrate()`, plus a `folderfolio_db_version` option and an upgrade check on `plugins_loaded`.
3. Define `FOLDERFOLIO_PLUGIN_FILE` in `folderfolio.php`; remove it from `phpstan-bootstrap.php` so the analyser can't hide it again.
4. Build with `--format=iife`; add CSS copying to the npm build script so CI and `dev:zip` produce identical output.
5. Bump the PHP floor to 8.1 in the header and `composer.json`; reconcile version and WP-version metadata.

**Make it trustworthy**
6. Add `tsc --noEmit` to the build and fix the 19 type errors — that alone surfaces §3.1–3.3.
7. Fix the three event wires and the `closest()`/`getAttribute` pattern.
8. Write the four real `FolderServiceTest` cases; move `ImporterFactoryTest` to `Integration/`; split the PHPUnit suites so unit tests run without WordPress.
9. Add `@playwright/test` and `esbuild` to `package.json`; delete `vite.config.ts` and the duplicate `vite` entries, or commit to Vite and delete the esbuild scripts.

**Make it correct**
10. Server-side filtering: hook `pre_get_posts` on `upload.php` and `ajax_query_attachments_args` so folder filtering survives pagination.
11. Tighten capabilities; wire `owner_id`/`visibility` or drop them, and fix the `folder_meta` primary key, before anyone has data to migrate.
12. Hook `delete_attachment` to clean assignment rows; wrap multi-write operations in transactions.
13. Then build the features the README already advertises — drag-and-drop, colour/icon pickers, a real folder-picker modal instead of `prompt()`.

**Before release**
14. `readme.txt` in wp.org format, a `languages/` dir with a POT file (the code is fully `__()`-wrapped already — that work is done), an `uninstall.php` that at minimum offers table cleanup, and a `.distignore`-driven packaging path used by both CI and local.
15. Rewrite the README feature list to describe what ships. Right now it reads as a spec for a finished product; a user installing on that promise would file issues within minutes.
