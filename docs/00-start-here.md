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
| Block inspector tree at 268px | waiting on the gallery block |
| Settings screen — three tabs, and the roles matrix | done |
| Migration wizard — four steps, nine sources, undo | done |
| Gallery block | not started |

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
| 8 | Gallery block | **current**; unblocks the 268px inspector tree |
| 9 | Release candidate and hardening | not started |
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
- **Undo**, which keeps any folder somebody has put their own files into since.

**Phase 8 is next**: the gallery block, which also unblocks design step 9b —
the 268px inspector tree, whose geometry is already in `_row.css`.

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

`test:e2e` is 26 tests, five on the settings screen and four on the import
wizard. Two of those five
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
