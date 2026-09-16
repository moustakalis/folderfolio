# FolderFolio — merge status (16 Sep 2026)

Branch `refactor/merge-src-into-includes`, 15 commits, **not pushed**. Supersedes the
"Correction Log" in `folderfolio-claude-code-handoff.md`, which had the `src/` vs
`includes/` relationship backwards.

## What changed

**Tree merged.** `src/` held the only working implementation (~1,800 lines);
`includes/` held eight stubs. Each directory moved in its own commit
(`git log --follow` survives), stubs deleted, `src/` gone. `includes/Domain/Folder.php`
kept — it was the one stub with no `src/` counterpart.

**Bootstrap wired.** `register_activation_hook` → `Schema::migrate()` (the tables were
never created); `FOLDERFOLIO_PLUGIN_FILE` defined in `folderfolio.php` and *removed*
from `phpstan-bootstrap.php`, so a constant missing at runtime can no longer pass
analysis; `folderfolio_db_version` option re-runs dbDelta after an update.

**UI moved into Media → Library.** `AdminPage` and its `add_media_page()` deleted.
`MediaLibraryIntegration` owns every `upload.php` asset and renders the tree container
server-side on `all_admin_notices` (the one insertion point shared by grid and list
mode). Config — REST URL, nonce, capability flag, labels — via `wp_add_inline_script`.
Dropped the `media_upload_filters` entry that added an "All Folders" label with nothing
behind it.

**Build fixed.** `--format=esm` → `--format=iife`: every bundle previously died on
`Unexpected token 'export'` before running. CSS is now built (`build:css`), not copied
by CI only. `esbuild` and `@playwright/test` declared; Vite dropped. `npm run build`
gates on `tsc --noEmit`, which had never run.

**One packaging path.** `bin/build-zip.sh`, called by both `mise run dev:zip` and CI.
Allowlist, not exclude list; fails if `includes/Autoloader.php` or `includes/Plugin.php`
is missing, or if any development path reached the archive. Maps dropped. `public/` no
longer packaged — it holds only a favicon nothing loads.

**PHP floor corrected to 8.1** (`readonly`, `new` in initializers). Versions aligned at
0.2.0 across header, constant, package.json, README.

## Decision: Import stays a Media → Import submenu

It is a one-off migration task, not a management screen standing in for the library
integration, so the handoff's prohibition doesn't apply. Considered gating the menu on
`detectInstalled()` and rejected it: that costs four `SHOW TABLES` queries on every
admin page load to hide one menu item. The page reports what it found instead.

Two bugs fixed while there: the inline script used jQuery and `wp.apiFetch` with neither
enqueued, and `getAvailableImporters()` returned a PHP associative array that
`json_encode` emitted as an object with no `key` on each entry — so the UI's `forEach`
threw and the Import button had no importer to post to.

## Verified / not verified

Verified: `tsc --noEmit` clean; bundles wrapped in `(()=>{...})()`; CSS emitted;
`php -l` clean on all 17 files; `bin/build-zip.sh` produces a 39-file archive with the
autoloader present and no development paths.

**Not run:** PHPUnit and PHPStan (no WordPress test library or MySQL here), Playwright,
and the clean-WordPress smoke test. `assets/build/` was built in the cloud container and
written to disk so the ZIP could be validated — run `npm install && make build` on the
Mac before trusting it, since `package.json` changed and `node_modules` still holds the
pre-change tree.

## Next

1. Smoke test the ZIP in clean WP with `WP_DEBUG` on.
2. Server-side filtering: `pre_get_posts` on `upload.php` + `ajax_query_attachments_args`,
   joined through `posts_clauses`. Today `folder-tree.ts` hides DOM tiles, which breaks
   on pagination.
3. Fix the three known frontend bugs: `closest()`/`getAttribute` mismatch in
   `folder-tree.ts`; `window.dispatchEvent` vs `document.addEventListener` between
   `folder-tree.ts` and `media-library-integration.ts`; `source_folder_id: 1` hardcoded
   in `bulk-actions.ts`.
4. Tighten capabilities — all folder CRUD is still `upload_files`, which Contributors hold.
5. `folder_meta` primary key `(folder_id)` → `(folder_id, meta_key)` before anyone has data.
6. Modal phase: rework `MediaModalIntegration` off the global `wp.media.create` patch,
   then re-register it in `Plugin::boot()` and drop it from the tsconfig exclude.

## Note

I overwrote your uncommitted 55-line `.distignore` with `git checkout --` (its staged
copy was empty). Restored byte-identical, 764 bytes. Nothing reads it now that packaging
is an allowlist, so it can be deleted.

---

# Update — filtering and the frontend bugs (same day)

Three more commits on the same branch (`58bee02`, `eb7c697`, `7506c90`), still unpushed.

## Filtering moved into SQL

New `includes/Admin/MediaLibraryFilter.php`. The library has two entry points so
the filter has two: `pre_get_posts` for list mode (folder in the URL) and
`ajax_query_attachments_args` for grid mode. Grid has to read `$_REQUEST['query']`
directly — `wp_ajax_query_attachments()` intersects the posted query against a fixed
key list and drops anything custom. Both converge on one query var that `posts_clauses`
turns into a join.

Folder `0` means "in no folder", via `LEFT JOIN … IS NULL`, which is why the id is
normalised to null-or-int rather than through `absint()`. `(folder_id, attachment_id)`
is the table's primary key, so the inner join matches at most one row per attachment
and needs no `DISTINCT`.

Client side: selecting a folder sets the collection prop in grid mode, or reloads with
the query arg in list mode (dropping `paged` — page 3 of the old folder isn't page 3 of
the new one). The tree reads the active folder back off the URL so a list-mode reload
doesn't reset it to All media.

## Four frontend bugs closed

- **Dead event wire.** `folder-tree.ts` dispatched on `window`; `media-library-integration.ts`
  listened on `document`, which is never in the propagation path of a window-dispatched
  event. That module had never run. It now owns the filter bar, including on a list-mode
  page load where there's no event to react to.
- **Delegation.** The folder id was read off the click target instead of the ancestor
  `closest()` matched, so any click on a nested node selected folder 0.
- **Bulk actions**, rewritten. It watched `.attachment-sel`, which WordPress does not
  render — the list view uses `input[name="media[]"]`, so the selection was always empty.
  Move posted `source_folder_id: 1` unconditionally; the source is now the folder being
  filtered on, and Move is disabled with a reason when there isn't one. The picker read
  `response.data.tree` where `/tree` returns the tree as `data`, so it was always empty —
  now a real `<select>`, built with `textContent`, indented by depth. Grid mode untouched:
  separate selection model, belongs with the media frame.
- **Upload-to-folder.** `injectUploadUI()` registered a `DOMContentLoaded` handler from
  inside `init()`, which `init()` was called from — it could never fire, and would have
  duplicated an id the tree already renders. Now listens for the event the tree already
  dispatches. The `media-new.php` fallback no longer hardcodes `/wp-admin/`.

## Verified / not verified

Verified: `tsc --noEmit` clean, all bundles IIFE, CSS emitted, `php -l` clean, ZIP builds
with `MediaLibraryFilter.php` present and no dev paths.

**Still not run:** PHPUnit, PHPStan, Playwright, clean-WordPress smoke test. Specifically
unverified: **grid-mode filtering**. The PHP reads the raw request precisely because WP
strips custom query keys, and the JS sets the collection prop — but whether that prop
reaches `$_REQUEST['query']` depends on Backbone internals I could not exercise without a
running WordPress. List mode is a plain URL parameter and is low-risk. Test grid first.

## Next

1. Smoke test in clean WP with `WP_DEBUG` on — grid filtering first.
2. Tighten capabilities: all folder CRUD is still `upload_files`, which Contributors hold.
3. `folder_meta` primary key `(folder_id)` → `(folder_id, meta_key)` before anyone has data.
4. Folder column / count in the list table, and an "Unassigned" entry in the tree (the
   server already supports folder 0).
5. Modal phase: rework `MediaModalIntegration` off the global `wp.media.create` patch,
   re-register it in `Plugin::boot()`, drop it from the tsconfig exclude.
