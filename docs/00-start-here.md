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
| Migration wizard and settings screens | not started |

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
| 4 | Frontend foundation | done **except virtualisation** |
| 5 | Interaction | done **except keyboard drag and Playwright** |
| 6 | Modal and settings | **current** — modal done, settings not started |
| 7 | Import | not started |
| 8 | Gallery block | not started; unblocks the 268px inspector tree |
| 9 | Release candidate and hardening | not started |
| 10 | Release | not started |

**Phase 6, half done.** The media picker's folder column is in and landed the
way the plan specified — on `wp.media.view.AttachmentsBrowser`, not the
`wp.media.create` monkey-patch the plan names as the thing to avoid. The
settings screen with its three tabs has not started: the FolderFolio admin menu
has exactly one page, Import, and that page is still 0.2.0's.

### Three debts carried from phases 4 and 5

Why "done" above is qualified. Each was found by re-reading the plan against the
code, not by something breaking.

1. **Virtualisation was skipped** (phase 4 asks for it). The tree renders every
   visible node. Windowing is not free here: the ARIA tree is nested `ul` /
   `role="group"`, and a windowed tree has to become flat `treeitem`s with
   `aria-setsize` and `aria-posinset` — a restructure of the most carefully
   built part of the UI. Measure before building.
2. **No keyboard equivalent for the move a drag performs.** The pointer has two
   verbs — a drag *moves* files out of the folder being viewed, the bulk flyout
   *adds* — and the keyboard only has add. dnd-kit, which the plan assumed, is
   the wrong tool for this drag: the draggables are core's own attachment tiles
   and table rows, not React components. Folder reordering inside the tree —
   what the plan actually had in mind — is not built in *any* input mode yet,
   and must ship with its keyboard path when it is.
3. **The Playwright suite is stale.** `tests/e2e/`'s two specs are 0.2.0's and
   reference markup that no longer exists; they would fail today. Phase 5 said
   "Playwright throughout"; what exists instead is live-browser verification
   plus the two standalone Chromium harnesses.

**The root `README.md` overstates what works.** Its feature list describes
0.2.0's intent, not the current state — folder icons are unwired, and
auto-assigning uploads to the active folder does nothing until something uses
the `folderfolio_default_folder_for_upload` filter. That is open item #28 in
`deep-review-2026-09-16.md`.

## Building and testing

```bash
mise install                 # PHP 8.2, Node 20, Composer
mise run deps:install
make build                   # typecheck + esbuild → assets/build/ (gitignored)
make test                    # PHPUnit
yarn test:pipeline           # React-on-wp-element, in a real browser
yarn test:slot               # the media-frame / toolbar slot, in a real browser
```

`make wp-link WP_ROOT=/path/to/wordpress` symlinks the checkout into an install.

The two `yarn test:*` harnesses are not ordinary unit tests: each builds a
fixture through the **shipping** alias table or the **shipping** module, runs it
in Chromium, and asserts on the DOM. They exist because the things they check —
whether the React alias applied, whether a portal survived its container being
replaced, whether focus came back — all fail silently and look fine in a
screenshot.

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
