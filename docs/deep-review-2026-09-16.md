# FolderFolio — deep review of the current branch

`refactor/merge-src-into-includes` @ `7506c90`, 18 commits. Reviewed every PHP and TS file
in the tree, and ran PHPStan 2.2 at level 6 against `includes/` with the WordPress stubs.

Findings are marked **Confirmed** (verified by running something or by an unambiguous read),
**Likely** (a strong read of WordPress behaviour I could not execute here), or **Gap**
(missing, not broken). Five of the blockers are regressions from my own work today; they're
labelled as such rather than buried.

---

## Blockers

### 1. PHPStan fails — `FOLDERFOLIO_PLUGIN_FILE` not found · Confirmed · mine

```
includes/Plugin.php:76  Constant FOLDERFOLIO_PLUGIN_FILE not found.
[ERROR] Found 7 errors
```

I removed the constant from `phpstan-bootstrap.php` so a constant missing at runtime could
no longer pass analysis. Correct instinct, incomplete change: `phpstan.neon` analyses
`includes/` only, so the `define()` in `folderfolio.php` is never seen. `composer phpstan`
is red, and the CI `php-tests` job fails.

Fix: add `folderfolio.php` to `parameters.paths`. Verified — that clears it, and the
analysis is more correct with the bootstrap in scope.

The other six errors in my run (`ARRAY_A` ×5, the `wp-admin/includes/upgrade.php` require)
are artefacts of my environment: the project's real config loads
`szepeviktor/phpstan-wordpress`, which defines them. I could not install that here
(Composer hit a GitHub auth wall), so **this run is indicative, not a substitute for
`composer phpstan` on your machine.**

### 2. The colour test I added will fail · Confirmed · mine

`rejects_an_invalid_folder_colour` asserts a `WP_Error`. It won't get one. Three layers
each drop the bad value instead of rejecting it:

- `FolderController::folderArguments()` sets `'sanitize_callback' => 'sanitize_hex_color'`, which
  returns `null` for junk — so the service never sees the input.
- `FolderService::sanitize()` does the same again.
- `FolderService::validate()` guards with `$data['color'] !== null`, so a nulled colour skips
  the `preg_match` entirely.

The `folderfolio_invalid_color` error is unreachable through the REST API. Either the test
goes, or the code starts meaning it — I'd make it mean it: drop the `sanitize_callback` on
`color`, and have `sanitize()` keep the raw string when `sanitize_hex_color()` rejects it, so
`validate()` can return a 400. Silently discarding a field the caller set is worse than an error.

### 3. The folder panel renders above the page title, outside `.wrap` · Likely · mine

`MediaLibraryIntegration::renderMountPoint()` hooks `all_admin_notices`, which fires from
`admin-header.php` *before* the page's own `<div class="wrap"><h1>`. WordPress relocates
notices with `common.js` — but only elements matching `.notice, .updated, .error`, which this
container deliberately isn't. So it should land above "Media Library" with no `.wrap` padding.

I chose that hook because it's the one server-side point both grid and list mode share. That
was the wrong trade: the original inline jQuery placed the tree before `.wp-filter`, which was
ugly code in the right place. Better fix: keep the server-rendered container (for markup that
exists without JS) but position it from `folder-tree.ts` on init — move it before `.wp-filter`
in grid mode, before `.tablenav.top` in list mode.

Eyeball this first in the smoke test; it's the most visible thing on the branch.

### 4. The filter bar can't find its anchor in list mode · Likely · mine

`getOrCreateInfoBar()` requires `.wp-filter`. That's a grid-mode element — the list table
renders `.subsubsub` and `.tablenav` instead. So on a list-mode page load with
`?folderfolio_folder=12`, `renderInfoBar()` returns early and there is **no way to clear the
filter** except editing the URL. That's the mode where filtering actually works end-to-end.

Same fix as #3: one placement helper that knows about both modes.

### 5. `dbDelta` can run on a front-end request · Confirmed · mine

`Plugin::boot()` calls `maybeUpgradeSchema()` before the `is_admin()` branch, on
`plugins_loaded`. After a plugin update, the first hit — from anyone, including an
anonymous visitor, possibly several concurrently — runs four `CREATE TABLE` statements
through `dbDelta`, which loads `wp-admin/includes/upgrade.php` on the front end.

Move it behind `admin_init`, and take a short transient lock so concurrent requests don't
race. Activation stays as it is.

### 6. `media-modal.spec.ts` asserts on functionality that is now off · Confirmed · mine

I unregistered `MediaModalIntegration` (it patches `wp.media.create` globally). The e2e spec
still expects `#folderfolio-modal-tree` to be visible. It's guarded by
`if (await mediaButton.isVisible())`, so it may pass by skipping — which is worse than failing,
because it looks like coverage. Skip it explicitly with a reason until the modal phase lands.

---

## Bugs

### 7. Clearing the filter leaves the tree showing the old folder as selected · Confirmed · mine

`folder-tree.ts` listens for `folderfolio:folder-selected` and calls `applyFilter()` — but never
updates `this.activeFolderId` or re-renders. Clicking "Clear filter" clears the query and leaves
the folder highlighted. My rewrite dropped the `clearFilter()` method that used to do this.

### 8. Grid mode doesn't update the URL · Confirmed · mine

`applyFilter()` sets the collection prop and returns. The address bar never changes, so a
refresh, a bookmark, or a shared link loses the filter — and `folderFromUrl()`, which every
bundle calls on init, reports "no filter". Use `history.replaceState` alongside the prop set.

### 9. Folder search hides matching children · Confirmed · pre-existing

`filterTree()` sets `display:none` on `.folderfolio-tree-item` elements. Children are nested
`<li>`s *inside* their parent's `<li>`, so hiding a non-matching parent hides every matching
descendant with it. Searching a nested tree mostly returns nothing. Match bottom-up: show a
node if it or any descendant matches.

### 10. The number beside each folder is its subfolder count · Confirmed · pre-existing

`.folderfolio-folder-count` renders `folder.children.length`. Every user will read that as
"files in this folder". Either show the attachment count (the tree endpoint would need to
return it — one `GROUP BY` in `FolderService::tree()`, not an N+1) or drop the badge.

### 11. `TRUNCATE` in test setup breaks transaction isolation · Confirmed · pre-existing

`FolderServiceIntegrationTest::setUp()` and `FolderControllerTest::setUp()` both run
`TRUNCATE TABLE`. In MySQL/MariaDB that causes an implicit commit, which defeats the
transaction `WP_UnitTestCase` wraps each test in — so tests leak state into each other and
into the database. Use `DELETE FROM` instead. (`Schema::migrate()` in `setUp` has the same
problem via `CREATE TABLE`; run it once in `set_up_before_class()`.)

### 12. `post_type` comparison assumes a string · Confirmed · mine

`'attachment' !== $query->get('post_type')` in `MediaLibraryFilter`. `post_type` can be an
array. Harmless today on `upload.php`, but it silently disables the filter if anything ever
sets `['attachment']`. Normalise with `(array)` and `in_array`.

---

## Security and data integrity

### 13. Every folder operation is gated on `upload_files` · pre-existing

Contributors and Authors hold that capability. Any of them can rename, move, or delete
**any** folder — including ones they didn't create — and `DELETE /folders/{id}?reassign_to=`
bulk-reassigns other people's media. The schema has `owner_id` and `visibility` columns for
exactly this; nothing reads them.

Split it: `upload_files` for read and assign, a custom `manage_media_folders` cap (granted to
Editor and above on activation) for folder mutation. This is the one item I'd fix before
anyone else installs the plugin.

### 14. `assignAttachments()` never checks who owns the attachment · pre-existing

```php
if (!wp_attachment_is_image($id) && get_post_type($id) !== 'attachment') {
```

That only asserts "is an attachment" — expressed confusingly, since `wp_attachment_is_image()`
implies it. An Author can assign any other user's media, including private attachments, into
their own folder. Should be `current_user_can('edit_post', $attachmentId)`.

### 15. No transactions on multi-write operations · pre-existing

`moveAttachments()` assigns to the destination and *then* unassigns from the source, as two
independent calls. A failure between them leaves media in both folders. `delete()` does three
sequential writes the same way. Wrap in `START TRANSACTION` / `COMMIT` (InnoDB only — check
the engine, or accept the risk explicitly).

### 16. Nothing cleans up when an attachment is deleted · pre-existing

No `delete_attachment` hook. Assignment rows outlive their media forever, and `dbDelta` can't
express foreign keys so the database won't do it for you. Cheap fix, and it stops the table
growing unboundedly on a busy site.

### 17. `FolderRepository::update()` has no column whitelist · pre-existing

It forwards `$data` straight to `$wpdb->update()`. Safe *today* only because
`FolderService::sanitize()` whitelists keys upstream. One direct caller and it becomes an
arbitrary-column write. Whitelist at the repository boundary too — that's where the invariant
belongs.

### 18. Schema problems worth fixing before there's data · pre-existing

- `folderfolio_folder_meta` has `PRIMARY KEY (folder_id)`. One meta row per folder, ever.
  Should be `(folder_id, meta_key)`.
- No unique constraint on `(parent_id, name)` or on `slug`. Duplicate sibling folders and
  colliding slugs are both allowed.
- `template_id`, `owner_id`, `visibility`, and the whole `folder_meta` and `user_preferences`
  tables are created and never read or written. Build them or drop them — schema you ship is
  schema you migrate forever.
- `Plugin::DB_VERSION` is `'1'` while four tables already exist in the wild from earlier
  activations. The first upgrade run will `dbDelta` them; that's fine, but the version should
  start tracking meaningfully now.

### 19. No direct-access guards · Gap

16 of 17 files under `includes/` lack `if (!defined('ABSPATH')) exit;`. Low risk — they only
declare classes — but wp.org review flags it, and it costs one line each.

---

## Gaps

### 20. The i18n config has no consumers · Confirmed · mine

I added an `i18n` block to the config object — `folders`, `newFolder`, `searchPlaceholder`,
`namePrompt`, and five more — and not one bundle reads it. `window.folderFolio` is referenced
exactly once in the entire frontend, for `mediaNewUrl`. Every visible string is still a hardcoded
English literal in the TS.

That is the same "config with nothing behind it" I deleted the `media_upload_filters` entry for,
and I shipped it in the same commit. Either wire the strings up or drop the block.

### 21. `prompt()` and `alert()` are still the UI · pre-existing

Folder creation is a `prompt()`. Six `alert()` calls across three bundles. Two `console.log`s
ship to production. Chrome suppresses dialogs in some contexts, and none of it is translatable.

### 22. The tree is not keyboard accessible · pre-existing

Folder names and toggles are `<span>`s with click handlers — no `tabindex`, no `role`, no
`Enter`/`Space` handling, no `aria-expanded` on toggles, no `aria-current` on the active folder.
I added `role="navigation"` to the container, which is decoration on top of an unreachable
control set.

### 23. Two identical `/tree` requests per page load · Confirmed · mine

`folder-tree.ts` and `bulk-actions.ts` each `GET /tree` on init. Same data, same page. Have one
fetch and publish the result, or cache it in a tiny shared module.

### 24. Filtering doesn't reach the counts · Gap

The "All / Images / Audio" status links, the attachment totals and the month dropdown all come
from `wp_count_attachments()` and separate queries, which the `posts_clauses` join never
touches. Filter to a folder and the counts above the table still describe the whole library.

### 25. REST media requests aren't filtered · Gap

`MediaLibraryFilter` is registered inside `is_admin()`. Grid-mode AJAX goes through
`admin-ajax.php`, so it's covered — but `/wp/v2/media` isn't admin, so anything reading media
over REST (the block editor's newer paths, headless clients, your own future code) sees an
unfiltered library. Register the `posts_clauses` half unconditionally.

### 26. Release hygiene · Gap

No `uninstall.php` — four tables and a `folderfolio_db_version` option are left behind forever.
No `readme.txt`, which wp.org requires. No `languages/` or POT file, even though the PHP is
fully `__()`-wrapped — that work is already done and unusable.

### 27. Multisite · Gap

`register_activation_hook` fires once for a network activation, so per-site tables are never
created on the other sites. Standard fix: loop sites on network activation, and hook
`wp_initialize_site` for new ones.

### 28. Features the README still claims · pre-existing

Drag-and-drop doesn't exist. Folder colours and icons are stored, validated and never settable
from any UI. Multi-folder membership is in the schema and exposed nowhere. Grid mode has no
bulk assignment at all — `bulk-actions.ts` is list-mode only by design, which is defensible,
but the README doesn't say so.

### 29. Small things

- `bin/build-zip.sh` parses `unzip -l` with `awk '{print $4}'`; a filename containing a space
  splits and the forbidden-path check silently misses it.
- The CI `build` job still runs `Setup PHP` and installs Composer, then uses neither.
- `.distignore` is read by nothing now that packaging is an allowlist.
- `folder-tree.ts` re-binds its container listener on every `render()`, adding a duplicate
  click handler each time. It's on the container, which is replaced wholesale by
  `innerHTML`, so the old one is garbage — but the pattern will bite as soon as rendering
  becomes incremental.

---

## What holds up

The domain layer is still the strongest part: sanitize → validate → act, `WP_Error` rather than
exceptions, real cycle detection in `move()`, an O(n) tree build. The REST envelope is
consistent and the controllers hold no logic. The FileBird importer handles both schema
variants and reports per-row failures. None of the findings above are in that code — they're
in the edges: wiring, placement, capabilities, and the UI layer that was always the thinnest
part of this plugin.

## Suggested order

1. #1, #2 — CI is red until both are fixed. Ten minutes.
2. #3, #4, #7, #8 — placement and filter-state bugs. Nothing about the Media Library
   integration is trustworthy until these are right, and they're all in two files.
3. #13, #14 — capabilities. Before anyone else installs it.
4. #5, #11, #16 — the dbDelta call site, test isolation, orphan cleanup.
5. #20 — wire the i18n strings or delete the block. Don't leave it half-built.
6. #18 — schema, before there's data to migrate.
7. Then features: #10, #9, #22, #24, and the README reconciliation in #28.

---

# Blockers fixed (same day) — `a9be03d` … `fd5afc2`

Branch now at 23 commits, still unpushed.

| # | Fix | Verified |
|---|---|---|
| 1 | `phpstan.neon` analyses `folderfolio.php` too | PHPStan 2.2 L6 — error gone |
| 2 | REST no longer pre-sanitizes `color`; `sanitize()` keeps the raw value so `validate()` can reject it | code path traced end to end |
| 3 | Panel stays printed on `all_admin_notices`, client moves it below `.wp-header-end` on init | `tsc` clean, bundle rebuilt |
| 4 | Filter bar falls back: panel → `.wp-filter` → `.tablenav.top` | `tsc` clean |
| 5 | `maybeUpgradeSchema()` → `admin_init`, behind a transient lock | `php -l`, PHPStan |
| 6 | Modal specs `test.describe.skip` with a reason | — |
| 7 | `handleFolderSelect()` only dispatches; the listener owns `activeFolderId` and re-renders | `tsc` clean |
| 8 | Grid mode `history.replaceState()`s alongside the collection prop | `tsc` clean |

Also fixed while in there, and worse than the review described it: `bindEvents()` ran from
every `render()`, stacking another delegated listener on the container each time. A folder
click fired twice, then three times, then four. Bound once in `init()` now, with the search
input delegated as well since `render()` replaces that element.

## One thing I could not verify

`Plugin::maybeUpgradeSchema()` uses `MINUTE_IN_SECONDS`. My container PHPStan reports it as
`constant.notFound` — but it reports `ARRAY_A` the same way, and `ARRAY_A` has been in
`FolderRepository` since before this branch with CI green. Both are WordPress core constants
resolved by `szepeviktor/phpstan-wordpress`, which I could not install here (Composer hit a
GitHub auth wall). So this is almost certainly noise from my setup.

If `composer phpstan` does flag it on your machine, the fix is one line: `5 * 60` in place of
`5 * MINUTE_IN_SECONDS`. I left the idiomatic constant rather than write a magic number to
appease a tool I couldn't configure properly.

## Still open from the review

Nothing in Bugs #9–#12, Security #13–#19, or Gaps #20–#29 has been touched. The order I'd
take them: capabilities (#13, #14) before anyone else installs this, then test isolation
(#11) and orphan cleanup (#16), then the i18n block (#20) — wire it or delete it.

---

# Second pass — security, data integrity, i18n (`17bd6be` … `6e8b2f8`)

Six more commits, branch at 29, still unpushed. `php -l` clean on all 19 files, PHPStan
unchanged at the same 7 environmental errors (no new real ones), `tsc` clean, ZIP at 42 files.

## #13 / #14 — capabilities

New `includes/Support/Capabilities.php`. Reading folders and filing media stays
`upload_files`; creating, renaming, moving and deleting folders now need
`folderfolio_manage_folders`, falling back to `edit_others_posts` — Editors and
Administrators, not Authors or Contributors.

Chose the fallback over adding the cap to roles on activation deliberately: no roles-table
write means no migration, no cleanup, and it works on existing installs immediately. Both
checks go through `apply_filters`, so a site can widen or narrow them.

Assignment additionally checks `edit_post` per attachment, so an Author can no longer file
another user's private media into their own folder. The old guard —
`!wp_attachment_is_image($id) && get_post_type($id) !== 'attachment'` — only ever asserted
"is an attachment", confusingly, since the first call implies the second.

## #12 / #25 / #16 / #17 — filter and data integrity

- `post_type` normalised through `(array)` + `in_array`.
- `posts_clauses` registered unconditionally. The class was only instantiated inside
  `is_admin()`, which covers `admin-ajax.php` but not `/wp/v2/media`.
- `delete_attachment` → `AttachmentFolderRepository::deleteForAttachment()`. Nothing had
  been removing assignment rows for deleted media.
- `FolderRepository::update()` whitelists its columns.

## #11 — test isolation

`TRUNCATE` → `DELETE` in both integration suites.

## #20 — the i18n block now has consumers

`api.ts` gains `t(key, fallback, ...values)` — `%s` placeholders positionally, as on the PHP
side, with a fallback so a missing key degrades to the old string rather than `undefined`.
Every visible literal across the four shipped bundles now goes through it, and the label set
grew to cover the bulk bar, filter bar and upload modal, with translator comments on the
placeholders. Verified: no hardcoded UI strings left outside `media-modal.ts`.

## #18 — schema

`folderfolio_folder_meta` had `PRIMARY KEY (folder_id)`. dbDelta adds columns and indexes but
will not alter a primary key, so the table is dropped and recreated — **only when it holds no
rows**, checked rather than assumed. Nothing reads or writes it yet, so this was the moment.
`DB_VERSION` → `2`.

Duplicate sibling folder names are rejected with a message instead of a unique index: MySQL
treats every `NULL` `parent_id` as distinct, so an index would let root folders through.

## #19 / #26 — release hygiene

`uninstall.php` drops the four tables, the option and the transient, per site on multisite.
Deactivation still keeps everything. The packaging script copies it and **fails the build if
it is missing**, since WordPress only runs it when it is in the archive — which it wasn't when
I first wrote that allowlist. All 17 files under `includes/` got their `ABSPATH` guard.

## Still open

- **#9** folder search hides matching children (nested `<li>`s).
- **#10** the count badge shows subfolders, not attachments.
- **#15** no transactions on `moveAttachments()` / `delete()`.
- **#21** `prompt()` / `alert()` are still the UI.
- **#22** the tree is not keyboard accessible — spans with click handlers.
- **#23** two identical `/tree` requests per page load.
- **#24** status links and month dropdown still show unfiltered counts.
- **#26** no `readme.txt` — left deliberately: it needs a real "Tested up to", a changelog and
  screenshots, which are yours to decide, not mine to invent.
- **#27** multisite network activation still won't create per-site tables.
- **#28** README still claims drag-and-drop, colours and icons.
- **#29** `unzip -l | awk '{print $4}'` breaks on filenames with spaces.

Nothing here is verified against a running WordPress. The smoke test is still the gate.

---

# Live smoke test — WordPress 7.1, PHP 8.3 (MAMP), Comet

Symlinked into `/Users/nickosmoustakas/Dev/playground`, alongside an active FileBird.
Everything below was observed in a real browser against a real database, not reasoned about.

## Confirmed working

| What | Evidence |
|---|---|
| Activation | No FolderFolio entries in `debug.log`. The only ones there are the 11 Sep `Failed opening required .../includes/Autoloader.php` fatals — history. |
| IIFE build | All four bundles + `admin.css` load 200, **zero console errors**. The ESM `export` that killed every bundle is gone. |
| Panel placement (#3) | Renders below the "Media Library" heading, inside `.wrap`. `positionPanel()` does its job. |
| Filter bar anchor (#4) | Bar renders in **both** grid and list mode, with a working Clear button. |
| **Grid filtering** | Captured XHR payload contains `query[folderfolio_folder]=3`. The Backbone prop *does* survive into the request — the one thing I could not determine by reading. Server returned nothing for an empty folder; grid showed "No media items found". |
| List filtering | `?mode=list&folderfolio_folder=3` → "No media files found"; `=1` → 1 row. `pre_get_posts` + `posts_clauses` working. |
| Unassigned (folder 0) | `folderfolio_folder=0` → 0 rows, correct: the only attachment is in folder 1. The `LEFT JOIN … IS NULL` branch works. |
| URL sync (#8) | Grid click updates the address bar to `?folderfolio_folder=1` via `replaceState`. |
| Clear deselects (#7) | After Clear: param gone, no `.active` in the tree, bar removed, tiles restored. |
| Duplicate names | `folderfolio_duplicate_name` returned — today's sibling check. |
| **Invalid colour** | `folderfolio_invalid_color` returned. This error was **unreachable this morning**; the integration test I wrote for it will now pass. |
| Empty name | `folderfolio_name_required`. |
| Nested tree | `2026` nests under `Screenshots`; colour `#3047a8` stored and returned. |
| Bulk bar | Appears on row select ("1 selected"), dropdown populated and depth-indented (`— 2026`). Move disabled with its explanation when no folder filter is active, **enabled** once one is — the source-folder logic works. |
| Capabilities | `canManageFolders: true` for an administrator; all mutations accepted. |
| i18n | 19 keys delivered and consumed. |
| PHP notices | None. Not one, across every request made during the test. |

## Confirmed broken, as described

- **#23 duplicate `/tree` fetch** — `treeRequests: 2` in list mode. Real. (Grid only fires one:
  `bulk-actions` bails early because the list checkboxes don't exist, so my review over-stated
  this as universal.)
- **#10 count badge** — the tree shows "Screenshots 1" where the 1 is its *subfolder* count.
  On screen next to a file count it reads exactly as misleading as predicted.
- **#21 `prompt()`** — I could not click "New" at all: a modal dialog freezes the browser
  extension. Anything that drives this plugin programmatically hits the same wall, which makes
  this more than a polish issue.

## Not tested

Capability *denial* (everything ran as administrator — the Contributor path is unproven),
the FileBird importer, upload-to-folder, and the media modal (still unregistered).

## Test data left in the playground database

Folders `Screenshots` (1), `2026` (2, child of 1), `Coloured` (3, `#3047a8`); attachment 17
assigned to folder 1. Say the word and I'll remove them.
