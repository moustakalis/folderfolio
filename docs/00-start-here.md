# Start here

One page for anyone — or anything — opening this repository cold. Read this,
then `DESIGN-TO-CODE.md` in the root if you are touching the UI, then
`architecture-plan.md` for anything structural.

## What this is

A WordPress media-library folder plugin, targeting **1.0 on wordpress.org**:
unlimited nesting, every feature free, no paid tier, no telemetry, no update
server. WordPress 6.4+, PHP 8.1+.

## Where it stands

`0.2.0` shipped a working plugin with a hand-rolled admin UI. That UI is being
**rebuilt against a design handoff**, not extended, and the rebuild is most of
the current diff. The domain layer, the REST surface and the importers are
0.2.0's and are sound; `includes/Admin/` and `assets/src/` are new.

| | Status |
|---|---|
| Design tokens, folder row, rail shell | done |
| Folder tree, search, breadcrumb, drill-down cards | done |
| Create / rename / delete with an undo window | done |
| Drag files onto folders | done |
| Filter-row folder select + bulk Add-to-folder flyout | done |
| List mode — the Folders column | done |
| Media picker — the folder column at 240px | done |
| Block inspector tree at 268px | done |
| Settings screen — three tabs, and the roles matrix | done |
| Migration wizard — four steps, nine sources, undo | done |
| Gallery block | done — block, shortcode, lightbox, inspector, and a theme-compat spec |
| More, and the colour picker behind it | done — and a folder stores a swatch name now, not a hex |
| Uploads into the selected folder | done — a request parameter, not a DOM watcher |
| The rail footer's folder total | done — it had been an empty span since step 3 |

`DESIGN-TO-CODE.md`'s "Suggested order" is the sequence being followed, and its
screen numbers are referenced throughout the code comments.

### Two numberings — do not confuse them

`architecture-plan.md` has **phases 0–10**, the road to 1.0. `DESIGN-TO-CODE.md`
has **steps 1–10**, the order the admin UI is being rebuilt in. Different lists;
the design steps all sit inside phases 4–6.

| Phase | | Status |
|---|---|---|
| 0 | Land what exists | done |
| 1 | Schema, domain, hooks | done |
| 2 | Facade and REST | done |
| 3 | WP-CLI | done |
| 4 | Frontend foundation | done |
| 5 | Interaction | done |
| 6 | Modal and settings | done |
| 7 | Import | done |
| 8 | Gallery block | done |
| 9 | Release candidate and hardening | **current** |
| 10 | Release | not started |

**Phase 6, done.** The media picker's folder column landed the way the plan
specified — on `wp.media.view.AttachmentsBrowser`, not the `wp.media.create`
monkey-patch the plan names as the thing to avoid. The settings screen is in:
one page at `admin.php?page=folderfolio`, three tabs, no React.

The plugin had no options layer before it — `grep -rn "get_option" includes/`
returned the DB version constant and nothing else. It has one now, in a single
option row, and three of its five settings reach the library: `count_mode`
decides what a folder badge counts, `default_sort` seeds the rail's sort, and
`undo_window` is the toast's grace period.

The fifth is the roles matrix, which two competitors charge for. It made
`Capabilities` answer four questions instead of one, and two rules hold
whatever the table says: **`upload_files` first**, so no tick box can hand the
folder tree to somebody WordPress keeps out of the media library, and
**administrators are pinned**, because a matrix that locks every administrator
out leaves nobody able to open the screen that would undo it. A role the table
has never heard of — Shop Manager, or anything a plugin registers — keeps the
capability its route asked for before the table existed. Nothing is written to
`wp_user_roles`.

Three deltas from the design board, each recorded in `SettingsPage`'s docblock:
the matrix is editable so its ●/○ glyphs are checkboxes; Status's first button
is "Rebuild paths" rather than "Rebuild counts", because counts are computed on
every read and a button that runs nothing is worse than no button; and
v0.2.0's `folderfolio-import` slug is gone rather than redirected, because
wp-admin refuses an unregistered page slug before `admin_init` runs.

**Phase 7, done.** The Import tab is screen 07's four-step wizard — choose a
source, read the preview, watch it run, read the report — and the module
behind it is new from the ground up. v0.2.0's importer was written against
tables FileBird has never had, so it reported "not installed" on every real
FileBird site.

Nine sources in three storage shapes, every schema read from the plugin's own
`CREATE TABLE`: custom tables (FileBird, Real Media Library, CatFolders),
taxonomy terms (Folders, Wicked Folders, Enhanced Media Library, Media Library
Assistant, WP Media Folder, HappyFiles — one reader, six sources) and a post
type (WP Media Library Folders, which has no reader, because its schema has
not been read and a reader written from a guess is worse than none).

The properties worth knowing:

- **Detection asks two questions** — is the data there, and is the plugin
  running. The case this feature is for is a plugin deactivated a year ago
  whose rows are still in the database.
- **The planner writes nothing** and predicts exactly what the run will do.
- **Add, never move.** Our assignment table permits a file in many folders and
  so does the code, so an import never takes a file out of a folder the user
  made. Running CatFolders' own FileBird import over a hand-built tree emptied
  three folders and made five duplicates; this cannot.
- **Per-folder provenance**, keyed `import:<source>:<id>` — the ids are in the
  key, not the value, because several source folders can land in one folder
  here. A second run reconciles; renaming a folder does not break the link.
- **Batched and resumable**, cursor on the server, which is what lets the
  screen say "you can leave this page".
- **Undo**, which keeps any folder somebody has put their own files into
  since — and, since `import_run` landed on the assignments table, any *file*
  they filed too. It used to delete by (folder, time window), which cannot
  tell a row the import wrote from one written by the person the run invited
  to carry on working while it ran.

**Phase 8, done.** The block is in: `folderfolio/gallery`, a folder as
a content source, server-rendered from `blocks/gallery/render.php`. Grid and
masonry, columns, gap, order, link-to, include-subfolders.

Three properties worth knowing before touching it:

- **Server-rendered is a security decision**, not a performance one. A
  client-rendered block needs a publicly readable REST route, which is a new
  unauthenticated query surface on every site that installs this. As it
  stands the front end makes no API call and ships **no JavaScript at all** —
  one 782-byte stylesheet, verified on a real page.
- **`Blocks\GalleryQuery` is the first code path an unauthenticated visitor
  reaches.** Attachments, `post_status = inherit`, and WP_Query rather than
  SQL of ours, so core's own visibility rules apply. Folders are not access
  control and never will be. 500 images is the ceiling.
- **The editor preview is the same renderer**, through
  `wp.serverSideRender` — so a preview cannot promise a layout the page does
  not deliver.

This also closed design step 9b. The 268px inspector tree turned out to be a
container class and an error state, because `.folderfolio-tree--inspector`
had been in `_row.css` since step 2 waiting for a block to live in.

**The shortcode takes a path**: `[folderfolio_gallery folder="Brand/Logos"
columns="4" layout="masonry" subfolders="yes" lightbox="yes"]`. A person
writing one by hand knows their folder as "Brand/Logos" and nobody knows it as
47. It is resolved with `findByPath()`, never `getOrCreateByPath()`, so a typo
cannot create a folder — and everything else is mapped to the block's
attributes and handed to `render_block()`, so the shortcode and the block are
one renderer.

**The lightbox is core's.** Opting into it means rendering each image *as* a
`core/image` block with `lightbox.enabled`, which is what the Interactivity
API wires up; the plugin still ships no front-end JavaScript of its own, and
`galleryId` context goes along so the arrow keys page through the gallery.

**A gallery cannot show what the theme would not.** `is_post_publicly_viewable()`
filters the result, and the check does not depend on who is looking — an
editor sees exactly the gallery a visitor gets. That filter exists because the
test found the hole: `post_status => 'inherit'` returns attachments whose
parent is private, draft or trashed, to anybody.

**Theme compatibility is a test**, not an afternoon of looking at screens:
`tests/e2e/gallery.spec.ts` publishes a page carrying the block and the
shortcode, then walks every theme this WordPress has — Twenty Twenty-Five,
Twenty Twenty-Four and Twenty Twenty-Three — activating each one, checking the
grid really is a grid with the right number of tracks, that the images have
width, that the masonry variant has the right `column-count`, and that **the
document is not pushed sideways**. Then it puts the theme back. A page builder
is not in the suite: Elementor is 10MB and belongs on the development site,
where the shortcode path was verified by hand.

**Phase 8 is done.** Phase 9, the release candidate, is current — and the
first work inside it was the four design leftovers rather than the release
items.

**Folder colour is a swatch name, not a hex.** The `color` column held a free
`#rrggbb` and the folder row wrote it straight into `--ff-folder`, which meant
the ten `--ff-folder-*` tokens and their Midnight pairs — in `_tokens.css`
since step 1 — could never fire. A colour chosen while looking at Fresh stayed
that exact hex on Midnight, where it was never going to be legible.

`Support\Swatches` is the list of ten plus a nearest-swatch mapper, DB_VERSION
5 converts what is already stored, and the REST field still accepts a
0.2.0-era hex and snaps it, because 0.2.0's API took one. Anything that is
neither a name nor a hex is a 400 naming the ten.

The same ten names are now written in three places — that PHP map,
`_tokens.css`, and `assets/src/lib/swatches.ts`. Drift between them fails
nothing at runtime: the custom property resolves to nothing and the icon
renders as though the folder had no colour. `TokensTest` asserts all three
agree, including order.

**More is the fourth toolbar button**, and the colour picker (screen 11, §9.8)
is all of it — the board draws nothing else inside it, and every other folder
action already has a home. Setting a colour checks the `rename` ability,
because it is the same route.

**Uploads go to the selected folder**, which they never did before 18 Sep.
`upload-integration.ts` was supposed to do it and every path through it was
unreachable: its modal ran only from an event nothing dispatches, and its
MutationObserver returned on its first line because `.attachments` is
Backbone-rendered after `wp.media` boots while `init()` runs at
`DOMContentLoaded`. It is deleted.

What replaced it is the mechanism all four competitors use — a parameter on
the upload request, read back on `add_attachment`. `Support\UploadTarget`
answers `folderfolio_default_folder_for_upload`, the filter `UploadRouter`
already asks, so the grid, the picker, drag-and-drop and `media-new.php` are
one path. `core/upload-target.ts` puts the id on the request.

Four properties worth knowing before touching it:

- **An id, never a path.** FileBird and CatFolders both accept
  `12/Brand/Logos` on their parameter and *create* the missing segments,
  which makes an upload a way to write folders. `ctype_digit` or nothing.
- **Priority 5, below the default.** A site's own callback on that filter is
  a standing rule, and a person's current folder must not silently beat it.
- **No permission check in `UploadTarget`.** It answers *which folder*;
  `assignAttachments()` checks the `assign` ability and `edit_post`.
- **Two client seams, because neither covers the other.**
  `wp.Uploader.defaults.multipart_params` is what a *new* uploader copies
  from; `param()` sets one on a live instance; and a live uploader's
  `multipart_params` is a different object from the defaults. Live instances
  are not enumerable, so `wp.Uploader.prototype.init` is wrapped additively to
  record them — the `lib/media-frame.ts` shape, not the forbidden
  `wp.media.create` patch.

Screen 10's footer line renders left of Select, and only while a folder is
selected: a sentence that is false half the time teaches people to stop
reading it.

### The three debts, and what they became

Found by re-reading the plan against the code, and closed the same day. None
was what it first looked like, and the measurements are in
`tools/measure-tree.mjs` so the next person does not have to take this on
trust.

**Virtualisation (phase 4) — measured, then not built.** Size was never the
problem. Two pieces of work were O(n) that did not need to be, and the one the
plan pointed at was the less important:

| | before | after |
|---|---|---|
| Flyout open, 1,000 folders | 227ms | ~140ms |
| Flyout keystroke, 5,000 | 257ms | ~160ms |
| Tree arrow key, 5,000 rows | 177ms | 1.3ms |
| Tree arrow key, 20,000 rows | 654ms | 9.9ms |

The flyout's list box is 168px — six rows — and it rendered one row per folder
every time it opened and again on every character typed into its own search. It
renders 100 now and says how many it is not showing. **Capping, not windowing**:
a checkbox that is not in the DOM cannot be reached with Tab.

The tree's arrow key was O(n) because `Tree` subscribed to `focusedId`, so one
keypress re-created every row beneath it. Rows read focus and selection
themselves now. The filter-row `<select>` renders every folder too and is fine
— 36ms at 5,000 options — so it was left alone.

**The keyboard's missing verb — a second control, not dnd-kit.** `Move to
folder` sits beside `Add to folder`, enabled only when a real folder is being
viewed, and calls the same mutation the drop handler calls. dnd-kit was the
wrong tool here: the draggables are core's own attachment tiles and table rows,
not React components. Folder reordering inside the tree — what the plan
actually had in mind — is still not built in any input mode, and must ship with
its keyboard path when it is.

**The Playwright suite — replaced, and it brings its own WordPress.** See
below.

## Building and testing

```bash
mise install                 # PHP 8.2, Node 20, Composer
mise run deps:install
make build                   # typecheck + esbuild → assets/build/ (gitignored)
make test                    # PHPUnit
yarn test:pipeline           # React on wp-element, in a real browser
yarn test:slot               # the toolbar slot surviving core's re-renders
yarn test:tree               # the tree's roving-tabindex invariant
yarn test:e2e                # end to end, against a WordPress it boots itself
yarn measure:tree            # render cost at 200 / 1k / 5k / 20k folders
```

`make wp-link WP_ROOT=/path/to/wordpress` symlinks the checkout into an install.

### If `composer install` will not run

Some sandboxes cannot reach the GitHub API, which is where Composer resolves
most of these dev dependencies. The unit suite does not need Composer — it
needs PHPUnit, and PHPUnit ships a PHAR:

```bash
curl -sSL -o /tmp/phpunit9.phar https://phar.phpunit.de/phpunit-9.phar
php /tmp/phpunit9.phar -c phpunit-unit.xml.dist
```

Version 9 deliberately: `composer.json` pins `^9.6`, because that is what the
WordPress test library the integration suite runs against requires, and running
the unit suite on 10 or 11 would hide exactly the incompatibility that broke CI
once already. PHPStan is the same story — `phpstan.phar` plus the WordPress
stubs needs no Composer either.

**A stand-in analyser must run with this repository's parameters.** The one
kept outside the checkout had `treatPhpDocTypesAsCertain: false` in a config of
its own while `phpstan.neon` did not, so it reported clean on a commit CI
failed with thirteen errors. It generates its config from `phpstan.neon` now
and overrides only what it physically cannot have: the phpstan-wordpress
extension and the php-stubs package, replaced by stubs on disk. That leaves one
known blind spot — without the extension, `apply_filters()` return types are
not read out of docblocks, so four of those thirteen could not have been
reproduced there under any setting. Level, `phpVersion`,
`treatPhpDocTypesAsCertain` and `ignoreErrors` all come from this file.

### Running the integration suite

It needs Composer, the WordPress test library and a MySQL — but not CI, which
is where it used to run and nowhere else:

```bash
composer run test:integration:setup     # WordPress + the test library into var/
WP_TESTS_DIR=var/wp-tests-lib composer run test:integration
```

The setup script takes `[db-name] [db-user] [db-pass] [db-host] [wp-version]`
and defaults to `wordpress_test / wordpress / wordpress / 127.0.0.1 / latest`.
It matches the library to the WordPress it actually unpacked rather than to
what was asked for, because a library from a different release fails in ways
that read as plugin bugs. **The database is emptied on every run.**

It needs curl and tar and nothing else — **no Subversion**, deliberately. The
usual recipes for this (`install-wp-tests.sh`, and the action CI used to use)
export the library from `develop.svn.wordpress.org`, and svn is not on
GitHub's runner images any more and has not shipped with macOS since
Catalina. The failure mode was worth the change on its own: the action logged
`spawn svn ENOENT`, carried on, and the job died later at a bootstrap that
could not find a library nobody had told it was missing. The same files are
in the wordpress-develop tarball, over HTTPS. CI runs this script too, so the
setup a developer uses and the setup that is tested cannot drift.

The first time it ran, it found three real problems in one go — an undo that
deleted a file the import had not filed, a test file written against an API
that changed underneath it, and a config in PHPUnit 10 spelling. CI runs the
same suite on PHP 8.1 through 8.4.

`test:pipeline`, `test:slot` and `test:tree` are not ordinary unit tests: each
builds a fixture through the **shipping** alias table or the **shipping**
module, runs it in Chromium, and asserts on the DOM. They exist because the
things they check — whether the React alias applied, whether a portal survived
its container being replaced, whether focus came back, whether exactly one row
is tabbable — all fail silently and look fine in a screenshot. Each has
negative controls that were run: break the mechanism and the harness fails.

The unit suite is 107 tests. `test:e2e` is 29, five on the settings screen,
four on the import wizard, one that renders the gallery in three themes, and
two that upload a real PNG through the media grid. Two of those five
cross the line that matters for a settings screen: a value saved on it changing
what the media library does on the next page load.

`test:e2e` needs nothing installed. WordPress Playground runs real WordPress on
php-wasm inside Node, and `tests/e2e/global-setup.ts` boots one, mounts the
**staged plugin** into it, logs in, waits until it is actually serving, and
shuts it down. `WP_BASE_URL` overrides the whole thing to run against a real
install instead.

The boot is ours rather than Playwright's `webServer` block because Playground
accepts connections 510ms in and answers 502 for the next fifteen seconds —
waiting on the port is satisfied by a server that cannot serve a page, and the
URL probe never resolved against it. What that looked like was "Timed out
waiting 180000ms from config.webServer", in CI and nowhere else anybody had
looked.

Mounting the staged plugin rather than the checkout is the other half: the
allowlist lives in `bin/stage-plugin.sh`, which `bin/build-zip.sh` calls too,
so the files the tests run against and the files that ship are the same files
by construction.

## Three rules that shaped most of the code

1. **Nothing reloads the page.** Selecting a folder re-queries in place in both
   library modes. Grid re-queries its Backbone collection; list fetches the page
   WordPress would have served and swaps regions of it (`lib/list-refresh.ts`),
   because the columns of that table are not ours — core owns four, we add one,
   and any other plugin may have added more.
2. **Membership is many-to-many, and the verbs differ.** A drag *moves* files
   out of the folder currently being viewed and *adds* when none is being
   viewed; the bulk flyout only ever adds; imports add and never move. That last
   property is what removes the entire class of failure CatFolders ships — see
   `research/04-catfolders.md`.
3. **No hex literal in component CSS.** Eight admin colour schemes ship with
   core and a literal will be wrong in seven. Every colour resolves through
   `assets/src/core/_tokens.css`, and `tests/Unit/Design/TokensTest.php` guards
   it — including that Midnight overrides every colour it does not deliberately
   share, and that the media modal's block restates Fresh exactly.

## Things that will bite you

Every one of these cost real time and is now load-bearing somewhere.

**Tokens resolve only inside `.folderfolio`.** Anything printed into core's own
markup — a list-table cell, a `<th>`, a node portaled to `<body>` — needs the
bare class as well as its own, or it silently resolves no custom properties.
Four separate bugs so far.

**A surface that is not the rail panel or the page canvas needs its own
tokens.** Borrowing a token whose name describes a different context produced
dark-on-dark on Midnight three times. The undo toast has five of its own; the
media modal is a white sheet in *every* scheme, because media-views.css hardcodes
it and never consults the scheme, so `.media-modal .folderfolio` restates the
light palette.

**Core's input selectors outrank a single class.** `input[type="search"]` is
specificity 0,1,1, so our fields double their class. Inside the media frame core
is `.media-frame input[type="search"]` at 0,2,1, so there they triple it.

**Row geometry is inherited, never declared on the tree.** `--ff-row-h`,
`--ff-indent` and `--ff-switcher` live on `.folderfolio` and are restated by a
*container* for the cramped cases. A declaration on `.folderfolio-tree` would
beat one inherited from its parent, which is exactly what stopped the 240px and
268px variants from working the first time.

**Both library toolbars are replaced out from under you** — list mode's
`.tablenav.top` on every folder change, grid mode's whenever the media frame
re-renders. A React portal into either is destroyed the first time it happens,
React does not notice, and nothing logs an error. `lib/toolbar-slot.ts` owns one
element per control and *moves* it instead.

**`restrict_manage_posts` fires with `$which === 'bar'`** on the media list
table, not `'top'`, and lands in `.wp-filter .actions` — which is **not** inside
`.tablenav.top`. Getting those two confused puts the control below the table.

**Grid's "Bulk select" sets `display: none` inline** on every child of
`.media-toolbar-secondary` bar three, so a control placed there vanishes exactly
when a selection exists. One position survives both states; see `_toolbar.css`.

**Never patch `wp.media.create`.** It is the factory every plugin calls, so two
plugins wrapping it means one wins by enqueue order. 0.2.0 did this, which is
why its modal integration sat unregistered through the whole rebuild. The
replacement (`lib/media-frame.ts`) wraps one view method, additively, purely to
publish a reference.

**`.folderfolio-row` is also the loading skeleton's class.** The rail renders
three ghost rows carrying it while the tree query is in flight, so any wait on
that selector is satisfied by the skeleton and everything after it races the
data. Four of the first seventeen e2e tests failed on exactly that; `waitForTree()`
in `tests/e2e/helpers/folders.ts` is the fix.

**A visually hidden radio sits under its own label.** The segmented control's
inputs are `position: absolute` with a clip path, so their box stays at the
start of the flex row — underneath the *first* label. Clicking the input
directly hits whichever label is on top, which made a Playwright `check()` on
"Direct only" silently target "Inherited" until it started failing outright.
Click the label; it is what a user clicks.

**`rows` is a reserved word in MySQL 8.** `SELECT COUNT(*) AS rows` is a syntax
error there and runs fine on 5.7 — the kind of difference that ships. See
`StatusReport::assignmentSummary()`.

**Three plugins, three different root markers.** FileBird and CatFolders write
`0` for "no parent", Real Media Library writes `-1`, WordPress taxonomy terms
write `0`, and we write `NULL`. Each import reader normalises once, at the
edge; getting it wrong creates a phantom parent rather than an error.

**A source folder can be called `Q1/Q2`.** Which is why the importer walks
parent by parent through `findByName()` rather than going through
`getOrCreateByPath()`, which splits on `/` and would turn one folder into two
with no way to tell afterwards.

**The plugin supports PHP 8.1, and the machine you are on probably does not
run it.** `readonly class` and a standalone `true` return type are both 8.2 —
valid everywhere you will test, and a *parse error* on the 8.1 leg of CI, which
fatals before a single test runs. `phpstan.neon` pins `phpVersion` to the
8.1–8.4 range the plugin claims, so the analyser reports them; PHPStan runs
first in the PHP job for that reason. If you add a language feature, check
which version introduced it.

**The e2e suite's timeouts are 90s and 15s, not Playwright's 30s and 5s, and
that is deliberate.** A wp-admin page load through php-wasm on a two-core
machine is 25–35 seconds. At the defaults, most of the rail and grid-toolbar
specs sat *just* under the line: green on a fast machine, red on a slow one,
green again on retry #2 — ten "failures" that were nothing but the clock, and
an hour spent looking for a bug in the plugin. If a spec starts failing at
almost exactly 30 or 5 seconds, suspect the harness before the code.

**Core puts a block's class on the editor's wrapper too.** `gallery.css`
loads in the editor canvas as well as on the page, so an unqualified
`.wp-block-folderfolio-gallery { display: grid }` turned the editor's wrapper
`<div>` into a three-column grid and squeezed the placeholder into 204px.
Every selector in that file is qualified `ul.` for that reason.

**`ServerSideRender` re-fetches when its `attributes` prop changes identity.**
Passing `{{ ...attributes }}` is a new object every render, so it fetches every
render, and each fetch causes the next one. It shows up as "this block has
encountered an error and cannot be previewed" and React #185 in the console.
Pass the object itself.

**The WordPress test suite makes every table temporary.** `WP_UnitTestCase`
rewrites `CREATE TABLE` into `CREATE TEMPORARY TABLE` so a test's schema dies
with its connection — which means `information_schema` cannot see the plugin's
tables at all, and a query against it comes back 0 whatever the truth is. Ask
`SHOW COLUMNS`. This cost a confusing half hour in `SchemaTest`.

**Undo is provenance, not a time window.** Import assignments carry the run id
in `import_run`; everything a person files has null there. It used to delete by
(folder, `assigned_at` between), which is wrong twice over: the timestamps are
second-granular, and screen 07 invites the user to keep working during a run
whose every write is inside the window. If a future feature needs to know
"what did that operation do", give the rows a column — do not ask the clock.

**A PHPDoc type in WordPress is a claim, not a guarantee**, which is why
`phpstan.neon` sets `treatPhpDocTypesAsCertain: false`. `apply_filters()` is
typed from the `@param` of its own docblock, so without it PHPStan calls the
guards in `Catalog::all()` and `UploadRouter::route()` dead code — and they are
the only thing between a third-party filter returning junk and a white screen.
Errors that come from real PHP types are still reported. If you are told a
check can never be true, look at where the type came from before deleting it.

**PHPUnit 9 ignores attributes, without saying so.** composer.json pins
phpunit ^9.6 because that is what the WordPress test library needs, and 9.6
reads `@dataProvider` and skips `#[DataProvider]` silently — the test runs with
no arguments and errors with "too few arguments". Use annotations. There is no
PHPUnit in the dev sandbox (composer install fails on the GitHub API there), so
the unit suite is run against `phpunit-9.phar` — which is worth doing before
every push, because CI is otherwise the first thing to run it.

**`assignAttachments()` rejects the whole batch** if one id is not an
attachment, and counts ids it did not actually newly file. Both matter to the
importer, which is handed ids from a table with no foreign keys; it filters
before calling rather than after.

**`t()` fills `%s` sequentially.** Positional `%1$s` placeholders now work too,
but that was a silent bug for six steps: the string rendered verbatim, on
screen, in English.

**Row geometry and focus both live in the row, not in the tree.** Beyond the
custom properties above: `Tree` deliberately does not subscribe to `focusedId`
or `selectedId`, because re-rendering it re-creates every row. If you find
yourself reaching for either in `Tree`, read the comment there first — and
`yarn test:tree` is what stops the roving tabindex breaking when you do.

**Swapping a list table's tbody is safe on WP 7.1** only because core binds the
checkbox handlers by delegation. That was read from the shipped `common.min.js`,
not assumed. If a future WordPress moves either binding back onto the elements,
shift-click range selection is what quietly breaks.

**By the time a passive effect's cleanup runs, React has already removed the
node.** So a cleanup cannot ask "was focus still inside this component" —
`document.activeElement` is `<body>` by then, whatever happened. The menus'
focus restore records the reason for closing at the moment of closing instead.
The first version read `activeElement` in the cleanup, looked right, and
restored focus never.

**A colour is not a colour.** A folder stores one of ten swatch *names*;
`--ff-folder-<name>` is what resolves it, and it resolves differently on
Midnight. The list is written in three places — `Support\Swatches::HEX`,
the `--ff-folder-*` block in `_tokens.css`, and `assets/src/lib/swatches.ts`
— and a name in one but not another fails nothing: the custom property
resolves to nothing and the icon renders in the default colour, exactly as
though the folder had never been given one. `TokensTest` is what catches it.
Never interpolate an unchecked value into `var(--ff-folder-…)` either; that
is caller-controlled text inside a declaration.

**A green suite is not a verification — look at the screen.** Every serious
finding in the 18 Sep design audit was something no assertion had been told to
look for. The collapsed rail measured 28px wide, carried a 2px rule, a 14px
top inset, a 10px gap and a 24×44 reopen button — every property correct, every
check passing — while its header, toolbar, fixed rows and search field drew
across the page on top of the media library, because only `__body` and
`__footer` were hidden. One screenshot would have shown it; a property diff
never could. `tests/e2e/responsive.spec.ts` photographs every viewport width
and the collapsed state into `test-results/responsive/` for exactly this
reason, and those images are meant to be looked at after a green run.
**After applying a fix, verify it visually before calling it done.**

**Never call `up.start()` yourself after `addFile()`.** WordPress's own
`FilesAdded` handler is what creates `file.attachment` — the model every later
handler writes to — and it also starts the upload. plupload dispatches that
event asynchronously, so a `start()` called straight after `addFile()` begins
uploading before the model exists, `UploadProgress` throws
`Cannot read properties of undefined (reading 'set')` inside
`wp-plupload.js`, and the uploader is left in STARTED for good. The file
still reaches the server; nothing else ever uploads again on that page,
because `start()` on a STARTED uploader is a no-op. It reads exactly like
"uploads only work once", and it is not — add the file and let WordPress
start it.

**An upload's folder travels on the request, not in the DOM.** `UploadTarget`
reads `folderfolio_folder` from `$_REQUEST` on `add_attachment`, and
`core/upload-target.ts` is what puts it there. Two seams are needed on the
client and neither covers the other: `wp.Uploader.defaults.multipart_params`
is copied by a *new* uploader, `param()` sets one on a live one, and the two
objects are not the same object — so mutating the defaults never reaches an
uploader that already exists. Measured on WP 7.1. Live uploaders are not
enumerable, which is why `wp.Uploader.prototype.init` is wrapped to record
them.

**A module can ship, enqueue and do nothing.** `upload-integration.ts` did,
for the whole life of 0.2.0 and the rebuild: an event listener for an event
nobody dispatches, and a `querySelector` at `DOMContentLoaded` for a container
Backbone had not rendered yet. Neither fails, logs, or shows up in a
screenshot. If a feature is wired to the DOM by timing or to a custom event by
name, prove it fires — grep for the dispatcher, and exercise the seam — before
believing the feature exists.

**The development database is MAMP PRO's**, and phpMyAdmin serves it at
`http://localhost:8888/phpMyAdmin5/` — from a browser on that Mac. A shell in
an isolated VM cannot see that localhost, so anything that needs the database
from outside the browser has to go through WordPress itself. A backup of the
four competitor plugins' folder tables, taken that way on 18 Sep, is at
`var/fixtures/competitor-fixtures-2026-09-18.sql` (gitignored); its row counts
match the import wizard's verified plan, so it is a usable restore point for
that fixture.

**PHPStan in a container needs `assets/build/` to be absent.** CI's PHP job is
a separate job from the Node one: it checks out fresh, never runs
`yarn build`, and so the four `*.asset.php` files are missing when PHPStan
runs there — which is what the `require.fileNotFound` entry in
`phpstan.neon`'s `ignoreErrors` exists for. Copy a built `assets/build/` into
a checkout and that pattern stops matching, and `reportUnmatchedIgnoredErrors`
reports a failure that CI does not have.

## Which document is which

| Document | What it is | Still authoritative? |
|---|---|---|
| `../DESIGN-TO-CODE.md` | The design handoff: screens, measured geometry, states, keyboard and ARIA contract, definition of done | **Yes — the UI spec** |
| `architecture-plan.md` | Decisions, data model, REST surface, importers, developer API, gallery block, the ten phases to 1.0 | **Yes — the roadmap** |
| `m2-importer-matrix.md` | Verified schemas and detection keys per migration source | Yes, when the importers are rewritten |
| `research/01..04-*.md` | FileBird, Real Media Library, Folders, CatFolders — measured live and read from source | Background, and the reason for several decisions |
| `deep-review-2026-09-16.md` | 29 numbered findings against 0.2.0 | Partly — #15, #24, #26, #27, #28 and #29 are still open |
| `design-handoff.md` | The brief that produced the design | History |
| `m1-research-and-design-plan.md` | The plan that produced the research | History |
| `code-analysis-2026-09-16.md`, `merge-status-2026-09-16.md` | The codebase and the `src/` → `includes/` merge, before the rebuild | History |

A step-by-step build log — what was verified live at each step, and the
reasoning behind each decision — is kept outside the repository, in the Claude
project attached to this work. This page is the summary of it that belongs with
the code.

## Keeping this page true

It is a standing instruction on this project that **whenever the assistant's
memory of the project changes, this page and its counterpart in the Claude
project are updated in the same turn**. A note that lives only in a model's
memory is a note a human reading the repository never sees; a page that has
stopped matching the code is worse than no page, because it is believed.
