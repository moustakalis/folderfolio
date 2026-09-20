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
| Design audit of the library screen | 14 findings, plus 5 found alongside — all closed |

**The design conformance backlog is empty**, and as of 19 Sep the plugin is
**version 1.0.0** with a `readme.txt`.

### 19 Sep — readme.txt, and the narrow toolbar

Full account: `claude/progress-2026-09-19-responsive-toolbar.md` in the
project, plus the responsive audit at
`claude.ai/artifact/DvtBqANGSiFtP1XDdFTmUH`.

**Review items #26 and #28 are closed.** `readme.txt` exists, written from a
feature list audited against the code — `README.md` had claimed folder
**icons**, which do not ship, and *"Import from FileBird"* when `Catalog.php`
ships **nine** sources, and had never mentioned the gallery block, the
shortcode, the settings screen, the Status tab or the WP-CLI commands. Both
files say the same thing now, and `README.md` carries a **"Not in 1.0.0"**
section so the icon gap is stated rather than discovered.

**Version 0.2.0 → 1.0.0** in the plugin header, `FOLDERFOLIO_VERSION` and
`package.json`. Historical *"v0.2.0 did X"* comments were left alone.

**The library toolbar got taller as the window got narrower.** Measured, grid
mode, 308px rail: 222px at 1100, 248px at 636, **296px at 400**, against 128px
at 1440 — and at 636 three of the four rows held exactly one control with
415–499px of empty space beside them. Below **700px of library column** the
media type, date and folder filters now collapse behind one `Filter` button.
After: 136px at 1100, 156px at 636 and 400 alike, 109px in list at 636, and
1440px unchanged. The curve no longer rises as the column narrows.

What remains before 1.0 on wp.org: **screenshots** (Nick is supplying them),
the **POT**, **Plugin Check in CI**, an RC tag, and the review items below.

Checks at the end of 18 Sep: **PHPStan clean, 107 unit, 47 integration, 40
e2e, `tsc` clean**, and **no `test.fail()` left anywhere in the suite** — both
annotations came off the moment their fixes landed, which is what they are for.

The integration suite runs again. `github.com` is 403 at the session's egress
proxy, which is where the WordPress test library normally comes from;
`wp-phpunit/wp-phpunit` from packagist, `mariadb-server` from apt and core from
`wordpress.org` is the route around it. Its first real run found that
`UploadTargetTest` **had never created the plugin's tables**: five of its seven
tests failed the moment they could run, and two had been passing *because* the
table was missing.

`DESIGN-TO-CODE.md`'s "Suggested order" is the sequence being followed, and its
screen numbers are referenced throughout the code comments.

### 19 Sep, later — the stress test, and what it changed

Full account: `claude/progress-2026-09-19b-thousand-folders.md` in the project,
plus the artifact at `claude.ai/artifact/WNVH2iM7BPR9hZVdbTFRFn`.

**1,050 folders were created through the REST API** — 45 roots, then 198, 499
and 308 across three more levels — in 11.2 seconds with zero errors. The plugin
is not slow at that size: 333ms to DOMContentLoaded, 106ms for the tree
endpoint, 290ms for a folder search. The fixture is still on the development
site; teardown is 45 cascade deletes recorded in `var/stress-root-ids.json`,
and nothing is assigned to them.

What it broke was the narrow layout, in two places, and the numbers are the
argument for both fixes:

**The toolbar's folder select held 1,055 options and 24,027 characters.** The
narrow layout was justified in writing on the premise that navigation never
needed the rail at this width because that select carried the whole tree. True
at three folders. Below the breakpoint it is now a button opening the same kind
of panel the bulk flyout uses — search, a capped list, and a line saying what
the cap is holding back. The select is never removed: list mode's is the
no-script path, so the rule that hides it is gated on a body class the picker
sets.

**The open sheet gave the tree 190px against a 50,976px tree** — 0.37% of the
content on screen. No cap fixes a ratio like that; raising it from 60vh to
75dvh moved the window from 82px to 190 and the ratio barely noticed. Below
782px the sheet now shows one parent's children with the path as the way out.
On the same fixture a level averages **3.7 rows** and **203 of the 283 possible
screens fit whole**.

**It does not fix the screen you land on.** The fixture's top level is 47 roots
— 2,068px against that 190px window — and a realistic library with fifteen is
still 660px. That is why the rail's search field takes focus as the sheet
opens. Search is the strongest thing in the build at this size and it was a
field you had to notice.

**Virtualising the tree is off the table.** It was the most expensive item on
the list — 2–3 days, and a virtualised `role="tree"` with a roving tabindex is
the pairing that already has its own CI harness because it fails silently.
There is nothing to virtualise in a list of four.

Two things still open from the same measurement: the tree endpoint ships
**310.2KB** for 1,053 folders, every field of every node whether the rail reads
it or not; and the narrow sheet spends about 369px of chrome — header, action
row, the two fixed rows, search, the level header, footer — to show 222px of
list. Both are worth measuring before they are changed.

Checks at the end of the day: **PHPStan clean, 107 unit, 47 e2e** (library 15,
responsive 6, rail 11, upload 2, settings 5, search-submit 3, import 4, gallery
1), **`tsc` clean**. The integration suite was *not* re-run — see below.

### 19 Sep, later still — the wide-viewport sweep

Full account: `claude/progress-2026-09-19b-thousand-folders.md`; the curve is
drawn at `claude.ai/artifact/82rLEX7rdVrzMdGB1nYpaV`.

Asked whether the drill-down work touched bigger viewports, every width was
measured in both modes. **Two things had, and both are fixed:**

**The folder select's `max-width` had never applied.** `.wp-core-ui select` is
(0,1,1) with `max-width: 25rem`; `.folderfolio-folder-select` was (0,1,0). A
native select is as wide as its longest option, so nothing showed it while
trees were small — but the 1,050-folder fixture's longest option is 36
characters, the control measured **257px**, and the grid toolbar went from two
lines to three: **168px at 1440 where the design says 128**. Capped at 11rem
now, and the number is measured: 192px is where it leaves the filter line.

**The list-mode full-width rule wanted the open panel, not the breakpoint.**
It applied closed too, where the filter group is a view switch and one button,
and cost **54px** — a 683px column went from 112px to 166. At viewports as
wide as 1200px.

Everything else was verified unchanged at desktop: the tree keeps
`role="tree"`, one tab stop and its 47 twisties; the rail's search returns the
same 28 matches the picker does, which is the point of their sharing
`searchTree()`; and the bulk flyout is identical in a tall window. In a short
one it is better — clamped to the room it has, with its Apply button on screen
rather than 103px past the bottom edge.

**And the sweep found a third thing — now fixed, and the way it was fixed is
the lesson.** Between **700 and 860px of library column** the grid toolbar was
**182px**, against 128 above it and 96 below: taller on a middling laptop than
on a phone. I put it to Nick as a design question — hide two filters behind a
button? — and his answer was that at those widths there is obviously room, and
that it was a thing to measure rather than ask about. He was right, and I had
already taken the measurement and stopped one step short of it.

Dropping finding 19's declared line break **inside the existing `< 860px`
block** gives **134px** across the band: once the search has taken its own
line the filters have the full column and `Bulk select` fits beside them. It
failed at the narrow end by about ten pixels, which is what made me give up on
it — core's `.view-switch` carries `margin-right: 12px` on top of the
toolbar's own 8px gap, and zeroing it there is exactly the difference between
fitting and not. Measured at a 711px column.

The grid curve is now **128 / 134 / 96**, with no bump at any width.

### 19 Sep, last pass — three answers from Nick, and one leak

**`Filter` → `Filters`.** It opens three of them, and the singular collided
with core's own `Filter` submit in list mode: two adjacent controls inside one
form carrying the same word, one disclosing and one submitting. Note for test
authors — once a folder is filtered the button's accessible name is
"Filters 1 filter active", so an exact-match locator stops finding it.

**The folder cards are a desktop affordance.** Below 782px they are no longer
rendered: on a phone they are a second full-width list of the folders the
sheet already shows, sitting between the toolbar and the files. Measured at
528px against the stress fixture, the block is **1,238px tall** and the first
thumbnail sat at **1,731px**; without it the first thumbnail is at **447px**.
The breadcrumb stays — one line saying where you are does not duplicate the
sheet.

**wp-admin's `dd, li { margin-bottom: 6px }` was in two of our lists.** It is
(0,0,1), and a `<ul>` zeroing its own margin does nothing for its children.

- The **breadcrumb**'s items are `<li>`s, so the row sat off centre in its own
  band. Its padding was `8px 0 10px`, and the undocumented asymmetry was
  almost certainly compensation for exactly this. Now `9px 0`, same box, text
  centred at every width.
- The **tree**'s rows are `<li>`s too, and nobody had noticed: the fixed rows
  above them are buttons in a `<div>` and sit on a **36px** pitch, while the
  folder rows below sat on **42**. Two halves of one column in two rhythms.
  The tree measured 1,968px where its rows account for 1,692.

Both are one pitch now — 36px on the desktop, 44 in the phone sheet.

### 20 Sep — three defects Nick caught in one screenshot

**The sticky level header painted behind its own rows.** It had `z-index: 1`,
and so do `.folderfolio-row__icon`, `__name` and `__count` — each
`position: relative` so it sits above the row's guides. A tie in z-index is
broken by document order, and the rows come after the header, so the header
drew its white background and the row's text drew over it. Confirmed with
`elementFromPoint`, which returned `.folderfolio-row__name` inside the
header's own box. `z-index: 2`.

**And a 7px strip above it leaked anyway.** A sticky offset is measured from
the scrollport's **padding** box; `.folderfolio-rail__body` carries
`padding: 6px 0 12px` over a 1px top border, so `top: 0` pinned the header
seven pixels down from its own top edge. `top: -6px`, tied to that padding by
a comment — and see the next section, which retired the offset by removing the
padding instead.

**The filter badge was a tall block with the digit on its floor.**
`line-height` inherits as a computed **length**, so the button's 32.3px — sized
for its own 14px text — laid an 11px digit out in a 25.4px line box inside a
17px pill. `place-items: center` centred the box it was given, and that box was
8px taller than the pill. `line-height: 1` on any pill whose font-size differs
from its parent's.

**The rail header carries the mark now**, from `assets/brand/mark.svg` inlined
as `BrandMark` in `icons.tsx` — the same shapes as the block icon and the
wordpress.org listing, filled rather than stroked because it is a logo and not
a member of the icon set. Its own 6px gap, not the header's 8px.

### 20 Sep, later — the band meets the hairline, and the edges admit there is more

Full account: `claude/progress-2026-09-20b-flush-band-and-scroll-shadows.md`.

**The selected-path bar looked too short for its row, and the element was
fine.** The inset shadow paints the full padding box — a 4x clone of the band
proved it — and a red reference bar drawn beside the live one measured
identical, 78px against 79. The variable was the *state*. Nick's screenshot,
read pixel by pixel, put the bar 12 device pixels (5.4 CSS px) late at the top
and flush at the bottom: six pixels, which is `.folderfolio-rail__body`'s
`padding-top`. **At the top of the scroll nothing is stuck**, so the band sat
after that padding, and `top: -6px` had only ever fixed the stuck case.

So the padding goes instead, and only where a band is actually there:
`.folderfolio-rail__body:has(.folderfolio-levels__head) { padding-top: 0 }`,
with the sticky offset back to `top: 0`. `:has` on the **header**, not on the
level view, because the level view also renders without one — at the root and
while the tree loads — and there the first thing in the scroller *is* a row,
which wants the six back. Measured after: 1px between scrollport and band in
**both** states, and that pixel is the border. Guarded by *"the pinned level
band meets the hairline, stuck or not"*, which fails if the rule is reverted.

**The lesson under it:** a fix scoped to one state is half a fix, and the half
nobody looks at is the resting one. Sticky elements have two geometries; assert
both.

**Scroll shadows on the rail's scroller**, four background layers and no
script: two covers in the panel colour at `background-attachment: local`, two
`farthest-side` radial shadows at `scroll`. The covers scroll with the content
and hide their shadows at rest; scrolling slides them away. The covers are
taller than the shadows — 14 against 7 — because a cover shorter than its
shadow leaves a rim showing at rest. Measured down the middle of the rail: 255
at rest, **219** at the darkest row when scrolled, over a 7px ramp. One blind
spot, deliberate: in the level view the opaque pinned band sits exactly where
the top shadow is drawn and covers it — the band is the top edge there, and
giving it a shadow of its own needs a sentinel and an observer, which is script.

### 20 Sep, last pass — the root cards, and the bracketed count

Full account: `claude/progress-2026-09-20c-root-cards-and-the-bracketed-count.md`.

**The folders block draws only inside a folder now.** At the root it drew all
47 top-level folders: **510px at 1440 x 900**, first thumbnail at 851px, the
library below the fold — and those 47 cards were the 47 rail rows already on
screen beside them, same names, same counts, and `Row.tsx` makes rail rows drop
targets too. One line in `useFolderContent`: All media and Unassigned both
return no children. First thumbnail at the root is now **295px**. The
`%s top-level folder(s)` eyebrow went with it, and its two i18n entries came
out of `Admin\Rail.php` — finding 8's outcome is now only its second form.

**The count sits in brackets.** `Archive 29` with nothing in it read
`Archive 29 0`, and no reader can tell the folder's number from the library's;
names ending in a number are ordinary (`2024`, `Q3 2025`), and the stress
fixture is made of them. `Archive 29   (0)` — wp-admin's own convention, the
one `walker_category_dropdown` has printed for years. **Both copies**:
`Admin\FolderSelect::label()` for the no-script path and `label()` in
`FolderSelect.tsx`. Only the native select needs it; everywhere else the count
is its own element.

**A proposal challenged and not taken: clicking the selected row to deselect
it.** Defensible under the tag model — folders here are filters, and membership
is many-to-many. Four costs against it: `.folderfolio-levels__here` already
means the opposite (you press the band's current folder *to* filter to it);
`rail.spec.ts` asserts Enter is the only key that filters, so Enter on your own
row would throw you out; a Finder double-click habit would silently clear; and
the exit is not scarce — `All media` is a fixed row **outside** the scroller,
measured pinned at y=136 while the scroller starts at 269, plus the first crumb
and the select's first option. No precedent in the four competitors either;
jsTree, which Premio uses, keeps a re-clicked node selected and reserves
deselection for ctrl/cmd-click. If the affordance is wanted: cmd/ctrl-click, or
an x on the breadcrumb's last crumb.

### 20 Sep, last of all — one toolbar shape, and a way out of a filter

Full account: `claude/progress-2026-09-20d-toolbar-shape-and-the-crumb-clear.md`.
The three shapes that were weighed are drawn to scale at
`claude.ai/artifact/EhzFV1DooXSJNgXJT8wzcp`.

**`Bulk select` ends the filter line now, at every width.** The declared break —
a zero-height flex item at `flex: 0 0 100%` on the bulk slot's `::before` — kept
it in one place and cost a row: three rows and **128px** above 860 of container,
one of them a 40px line carrying a 94px button with 800px of nothing; and in the
band **1411-1429px of viewport**, four rows and **168px**, because the break
arrived before there was room for the filters and the search on one line and the
folder select was pushed out of the group it belongs to. `margin-left: auto` on
core's own `.select-mode-toggle-button` is deterministic *and* free, and
`#wpbody-content .media-toolbar-secondary { flex: 1 0 100% }` comes out of the
`< 860` container query and applies everywhere. **134px at 1360, 1412, 1456 and
1700** — one shape from 700px upward, and it is the shape the toolbar already
had below 1411.

**The open filter panel pairs the media-type and date selects.** Halves, not
columns: the toolbar's tracks are sized by its first line, so both controls take
the whole row as their grid area and divide it with `calc(50% - 3px)` against
the 6px column gap. List mode needed `display: contents` on `.actions` to get
the two selects into the same grid at all — they live in different parents.
Grid's panel 224 to **184px**, list's 378 to **294px**.

**The search fills its row in that panel.** It measured 188px in a 629px row
because it is not in the panel's grid at all and core *floats* it — and a float
is not a flex item, so `flex-basis: 100%` never claimed the line. `float: none`
plus an explicit width does. List nests `.search-form > p.search-box > input`
and all three shrink-wrap.

**The breadcrumb's last crumb carries a clear (x).** The rejected alternative
was a toggle on the selected rail row; the argument is in the 20c log. 18 x 18
drawn, because the crumb's line box is 18.2px and a 20px button grew the band
from 36 to 38; 28 x 34 to a pointer through a pseudo-element. Only on the
current crumb, and only when that crumb is a filter - Unassigned counts.

**And a placement bug found on the way**: list mode's `Filters` button was
auto-placing two rows below the view switch, because its slot is
`display: contents` and the rule said `> .folderfolio-filter-toggle`. A `>`
selector never reaches a control inside a `display: contents` slot.

### 20 Sep, after that — the corner, and the wordmark

**`Bulk select` was 36px short of the right edge** after the declared position
landed — measured right edge 913 in a 949px row. `margin-left: auto` claims the
free space *before* an item, not after it, and core prints a
`<span class="spinner">` after this button, 20px plus its margins. **`order: 1`**
puts our button last among the line's items so nothing after it reserves room;
the spinner keeps its place inline, where core shows it anyway. 941 of 949 now.

**The eyebrow goes 10px to 12px, the mark 14 to 16.** At 10 the product's name
was the smallest type in the rail — beside a 12px/700 `New folder` button,
above an 11.5px tool row and 13px folder rows. Twelve rather than thirteen,
because twelve is exactly what else is on that row and thirteen would make the
plugin's own name the largest text in the rail. Measured across the drag range,
brand + gap + button against the header's inner width: **234 in 238 at a 240px
rail** (clips by 12), 234 in 278 at 280, 74 to spare at Nick's 310 — so the old
pair comes back below **298**, the breakpoint where the indent already drops to
16 and the tool row is already icons only. No new number.

### Next — the release track

**All fifteen of the settings audit's findings are closed.** The layout pass
(`d3fc3ce`) took 03, 04, 05, 06, 07, 09 and 12 and converted all seven flex
layouts to grid; 02 and 11 went in `9c59c1b`, guarded in `86e26e0`; 13 became
the theme rework (`5ab95ef`); 15 — the four remaining guards, plus the fix the
first of them found — went in `9519946`. **08 and 10 were decisions, not
work.**

**01 is closed as not a defect.** The card is **759px at 961 and 882px at
960**, and **705 at 783 and 760 at 782** — it gets *wider* as the window
narrows, at both of wp-admin's own breakpoints, because the 880px cap is
measured against a column that loses the admin menu's 160px in one step (→ 36
at 960, → 0 at 782). **Core's own `.form-table` on `options-general.php` jumps
by the identical numbers at the identical pixels.** Any plugin that caps a card
at a fixed `max-width` inherits it. Two fixes were built and costed; neither
removes the jump.

Headline numbers from the layout pass, for anything that touches these again:
the matrix's ability columns are **declared at 108px and equalise at 124**, so
the tick gaps are **124 / 124 / 124** and the table takes **593.5 of 832**;
below 520 that width comes off and `width: 100%` goes back on, because **a
specified column width is a minimum in auto table layout** and leaving it would
put finding 02's floor back above 500px.

**`.folderfolio-status th` is `width: min(220px, 38%)`, not a flat 220px.** The
flat value floored rather than capped: at 521px of viewport the table is 449px,
the label took **228px** and the answer **221px**, and the label won in a
**521–528px band** eight pixels wide. The sweep had measured those numbers and
not questioned them; the new guard failed on its first run. Write the rule so
the property holds **by construction**, not at the widths you sampled.

**A guard with an early `continue` can assert nothing and still be green.** The
source-row guard was written against `row.querySelector('button')` — but a row
renders a button only when the source holds data, and the e2e harness installs
no competitors, so every action there is the "Nothing to import" span and every
row was skipped. The negative control came back **3 failed, not 4**. Count the
failures against the number of fixes you reverted.

### Three recorded deviations from the board

Each was a decision the handoff does not contain, taken deliberately:

1. **The eyebrow carries the product's name**, not "Folders" — in the rail and
   in the media modal's folder column. Not run through `t()`; a brand is not
   translated.
2. **Below a 300px rail the toolbar is icons only, and the indent drops to
   16px.** At 300 and up both are exactly the board. 298px in the container
   queries, not 300: a container query resolves against the **content** box,
   and the rail's 2px rule is inside its border box.
3. **The cards block renders nothing when a folder has no children.** The board
   draws "No folders in Brand" and "Files in no folder — nothing to drill
   into"; `DESIGN-TO-CODE.md` lists that very question under *Still open*, and
   this is the answer. Its second eyebrow, "41 files here" above the file grid,
   was declined for the same reason plus the count already being in the filter
   row.

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

**Phase 8 is done.** Phase 9, the release candidate, is current — and all of
its work so far has been UI: the four design leftovers, then four passes over
the design audit. The release items are untouched and are now the whole of what
is left.

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

### The 18 Sep design audit — eighteen findings, all closed

The library screen was read against the board area by area, at seven viewport
widths, collapsed and expanded, in every admin colour scheme. Fourteen gaps
came out of it, and the method is the finding: nine of them were spotted from
screenshots, not from measurements. See the note under "Things that will bite
you" about a green suite not being a verification.

Closed:

1. **The collapsed rail.** `.is-collapsed` hid `__body` and `__footer` only,
   so the header, toolbar, fixed rows and search box went on rendering inside
   a 28px column and drew across the media library. It hides
   `.folderfolio-rail__app` now — one element, everything but the tab — with
   `overflow: hidden` as a backstop.
2. **Core's footer lay across the rail's.** `#wpfooter` is
   `position: absolute; bottom: 0` across the whole content area, so at the
   foot of a scrolled page its transparent box took every click aimed at
   Collapse: `elementFromPoint` over that button returned core's "WordPress"
   link. `--ff-rail-gutter` — the width the rail occupies *now*, printed by
   `Rail.php` and kept true by `rail.ts` — indents the footer past it. It is a
   second property because `--ff-rail-w` deliberately keeps the *stored* width
   while collapsed, which is what reopening restores.
3. **All media and Unassigned** carried a picture frame and an inbox. They
   take the board's open and closed folder glyphs, which is what makes them
   read as the two ends of the same list rather than as a different control.
4. **The folders sat above core's filter row.** The board stacks the column
   crumbs → rule → filter row → folders → files. Everything we add went into
   one block after `.wp-header-end`. It is two portals now: the crumbs stay,
   the cards mount after the filter row — `.media-toolbar` in grid mode, the
   bulk-action `.tablenav` in list — which is a Backbone view built after this
   bundle runs, so `useLateMount` waits for it and puts the node back if the
   view is ever torn down. The query is scoped to `#wpbody-content` so a media
   modal's own toolbar is never mistaken for the library's.

Three more came from a second look at the same screen, and are closed too:

5. **The two headers did not share a line.** The rail starts at the top of
   `#wpbody`; core's `.wrap` starts 10px lower and its `Add Media File` sits
   another 8px down inside the heading's line box. The rail header's top
   padding is 20px rather than the board's 12, which puts `New folder`'s
   centre on core's button and the eyebrow's on the page title. The number is
   only correct relative to core's — measure it again after touching that
   file.
6. **The eyebrow reads FolderFolio**, not "Folders". It is the one place in
   wp-admin the plugin says what it is; the collapsed tab already carries the
   generic word, and the rail has an accessible name from its landmark. Not
   run through `t()` — a brand is not translated. The media modal's folder
   column says the same.
7. **The folder filter dropped its indent while shut.** An `<option>` has one
   label for both states, so a folder three levels down showed nine
   non-breaking spaces before its name in the closed control. The indent means
   "inside that one" and needs the other rows on screen to mean anything, so
   it comes off the selected row while the list is shut and goes back on
   `mousedown`/`keydown` — both of which fire before the popup paints. The
   list-mode select is printed by PHP, which now prints the selected row
   un-indented and carries the indented label in `data-folderfolio-indented`,
   so a select that has just come back from a list refresh is already correct
   with no script involved.
8. **A real channel between the columns.** `--ff-rail-gap`, 20px, on `:root`
   rather than in `.folderfolio` — `#wpbody-content` and `#wpfooter` both have
   to read it and neither is inside that class. It carries no colour, so
   `TokensTest`'s sweep does not see it.

A third pass closed six more, and three of them were not what they were filed
as:

9. **The clipped label and the faint pencil were one missing declaration.**
   `.folderfolio-rail__tool svg` had no `flex: 0 0 auto`, so a button short of
   room shrank its **icon to zero width** before clipping anything else —
   which is why Rename and Delete looked iconless while Sort and More, which
   had slack, did not. The pencil was never faint; it was 0px wide. With the
   icon back, four labelled buttons need a 300px rail exactly, so the rail is
   a query container and below 300 the labels are hidden the wp-admin way and
   the icons stay. 298px in the condition, not 300: a container query resolves
   against the content box and the rail's 2px rule is inside its border box.
10. **Drag-over is a frame now**, as both its rules already said in a comment
    while painting a fill — and the fill was `--ff-wash`, the selected row's
    own background, so a row you were about to drop into was indistinguishable
    from the row you were looking at. The count tag's `+3` delta preview, the
    other half of that spec line, turned out to be built already.
11. **The search band closes with a hairline**, on the tree's top edge rather
    than the field's bottom: the field is inset 12px and the rule is full
    bleed like the other three, and a border on a scrolling box stays at its
    edge.
12. **The breadcrumb's leading "/" does not reproduce** — not in the library,
    not in the media modal, at any depth. Every crumb carries the slash that
    precedes it and the first one's is hidden; the audit read the element and
    not its computed display. There is now an assertion so that answer stays
    true.
13. **The 8px head misalignment was the header alignment** already closed
    above, and the list-mode column heads do not reproduce either: our
    `Folders` head sits exactly over its own cells, where core's own sortable
    heads are 2px out. What is left of that finding is the primary button
    being 28px against core's 32 — which is the board's number, in a different
    column, optically centred.

A fourth pass closed the last four, each after a decision rather than a
reading — none of them had an answer in the board:

14. **The rail sits against the admin menu.** `#wpcontent`'s 20px left padding
    is zeroed on this screen above the breakpoint. Safe because the only child
    that padding reaches is `#wpbody`: the admin bar is fixed and the notices
    are inside `#wpbody-content`, which carries its own `--ff-rail-gap`. The
    library column keeps its gutter and gains the 20px.
15. **16px of indent below a 300px rail.** The board measures a depth-5 name at
    110px in a 300px rail and *accepts* it, noting that a smaller indent "buys
    22px of name at the cost of the structure the guide lines carry". That
    trade is wrong at 300px and right at 240, where the same name gets 47px —
    at which point the structure is carrying nothing. Measured on a real
    depth-5 chain: 47px becomes 79px, and 300 and up is untouched.
16. **The eyebrow says how many and where** — "2 top-level folders", "1 folder
    in Screenshots", through `tn()` with the name as `%2$s`. The board's two
    other cases, which put the line alone over empty space, are deliberately
    not built: `Cards` renders nothing there, which is the answer the *Still
    open* list in `DESIGN-TO-CODE.md` was waiting for.
17. **Stacking makes the band full width, not the design.** Below 782px the
    rail's contents cap at its own width, so every stacked width measures what
    783px measures — 83.5/83.5/66.5/66.5 against 83/83/66/66, and a 276px
    search against 274, where 782px used to give 201px buttons and a 748px
    search.
18. **And below 782px the rail is closed by default.** Even unstretched, the
    stacked band was 375px of furniture above the library, which put the first
    thumbnail 960px down a 528px screen — 1.34 screens of scrolling before a
    single file. It is a 44px bar now, which opens on a tap: 631px to the first
    thumbnail, and nothing lost. The market at the same viewport: Real Media
    Library stacks too, at 953px; FileBird and CatFolders keep two columns and
    leave the grid 205px and 225px of the 528; Folders (Premio) drops its tree
    at 640px and reaches the first file in 344px — but the same breakpoint
    hides its reopen button, so at that width the tree cannot be brought back
    at all.

    **Navigation never needed the rail here.** The folder select on the
    toolbar's filter line carries the whole tree, indented, with counts. The
    bar is what you open in order to *manage* folders — new, rename, delete,
    sort, search — which is the one thing the select cannot do.

    Two properties are worth carrying. The state is a **body class**
    (`folderfolio-rail-peek`) and **not the stored collapse preference**: a
    preference set by turning a phone sideways would follow the user back to
    their desktop, so nothing in the narrow path calls `persist()` or assigns
    to `open`. And the closed state is written as
    `:not(.folderfolio-rail-peek)` rather than as a class a script adds, so the
    bar is what the page paints **before any script runs** — there is no frame
    in which a phone shows the 375px band.

    The market at a 528px viewport, measured the same way:

    | | Strategy | Folder panel | Content | To first file |
    |---|---|---|---|---|
    | FolderFolio (before) | stacks | 518 × 375px | 491 full | 958px |
    | **FolderFolio (now)** | **bar, opens on tap** | **518 × 44px** | **491 full** | **631px** |
    | Real Media Library | stacks | full width | 491 full | 953px |
    | FileBird | two columns | 319px (60%) | 205px | 516px |
    | CatFolders | two columns | 300px (57%) | 225px | 516px |
    | Folders (Premio) | drops the tree | none — a toolbar select | 516 full | 344px |

    Premio's `@media screen and (max-width: 640px)` block also sets
    `.wcp-hide-show-buttons { display: none }`, so the breakpoint that removes
    its tree removes the way back to it.

    The ARIA is the part that went wrong twice. `applyOpen` writes both
    `aria-expanded` attributes from the preference, so on a phone `peek` has to
    have the last word on them — in the startup sequence *and* in the
    breakpoint's `change` handler. Written the other way round, a window
    dragged narrow ended up with a closed bar announcing itself as expanded.

**The conformance backlog is empty.** What remains is the release track.

One more came out of asking why the two library modes have different
toolbars. Most of that difference is core's — grid has one Backbone
`.media-toolbar`; list has a PHP `.wp-filter` *and* a separate `.tablenav.top`,
with Filter and Search Media buttons because `#posts-filter` is a GET form,
`Bulk actions ▾` + Apply instead of `Bulk select` because list tables select by
checkbox, and pagination the grid does not have. The board has two screens for
exactly that reason, and our controls go into whichever of core's groups
matches.

What *was* ours is the grid toolbar's second row. `.media-toolbar-secondary`
wraps, core's five controls need 451px, ours add 395, and there are 628px
beside a 310px rail — so it wrapped wherever the window happened to put the
break. It is declared now, **before the bulk pair**, so that both modes read:

    filter line   view switch · media type · date · folder
    bulk group    [core's bulk control] · Add to folder…

The first version broke before the *folder select*, which pushed it off the
filter line in grid while leaving it on that line in list — the same control in
two places depending on a view toggle, which is worse than the wrap it
replaced. Breaking before the bulk pair costs one extra rule: the break used to
disappear for free in select mode, because core hides the filter slot and a
hidden element generates no pseudo-element, but the bulk slot deliberately
survives that and so the break has to be switched off by hand.

**Verify a toolbar in its totality.** `library.spec.ts` surveys every control
in the library chrome, groups them into lines, classifies each and compares the
shapes in both modes — which is the only reason any of the above was caught.
Three of its own assertions were wrong before it was right, and each mistake is
worth not repeating:

- **Counting core's filters asserts the fixture, not the layout.** Core drops
  the date dropdown when every attachment is from one month — in list mode
  only. A fresh Playground always is. Assert that ours is *last*, not that
  there are two before it.
- **A DOM-presence check does not compare against a visibility survey.** On an
  empty library core still prints the bulk row and hides it, so `count() > 0`
  reported a defect that was not there.
- **Clustering by top and reading in that order is not reading order.** A 40px
  select and a 28px icon on the same line have different tops; cluster on the
  vertical **centre**, and sort each line by `left`, before it means anything.
- **An assertion without its negative control asserts almost nothing.** "The
  rail is a bar below 782px" is satisfied by a rule that hides the rail at
  *every* width, until the spec also asserts that 783px and up get a
  full-height column and no bar. `responsive.spec.ts` asserts both halves.

**And one control, not two.** The bulk group used to hold *Add to folder* and
*Move to folder* side by side, which spent about 250px of a row that has none
to spare on a second trigger that was disabled whenever nothing was selected —
which in grid was always. They are one trigger now: the flyout opens on Add and
carries a `role="radiogroup"` segmented Add/Move pair, with Move disabled and
titled when there is no source folder to move out of. Three smaller things came
with it:

- **The caret came off.** A `…` says the button opens something; a caret said
  it twice. Worth 3px, not the ~21px first claimed — measured 120px to 117px,
  and the code comment was corrected rather than left holding the estimate.
- **`margin: 0 6px 0 0` on the trigger had never applied.** `.wp-core-ui
  .button` is (0,2,0) and a bare class is (0,1,0), so the trigger sat flush
  against `Apply`. `.wp-core-ui .folderfolio-bulk` fixes it.
- **Grid shows the trigger only in select mode**, which is core's own rule:
  grid's normal state cannot produce a selection, so the trigger was disabled
  there one hundred percent of the time.

> **A container cannot query itself.** `@container` resolves against the
> nearest *ancestor* container, so a rule naming `.folderfolio-rail` inside the
> rail's own container query matches nothing — it failed silently and left the
> indent at exactly what it had been, which only a measurement catches. Put
> those rules on a descendant; `__body` and `__tool` are inside the container,
> the rail is the container.

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

### Rebuilding the cloud rigs from nothing (19 Sep)

Neither rig survives a session restart, and both had to be rebuilt to run the
checks for the drill-down step. Recorded because the workarounds are not
obvious and the failures look like plugin bugs.

**Composer will not finish.** `phpstan/phpstan` is a **dist-only** package —
its lock entry has `"source": null` — so `--prefer-source` does not help it,
and its zipball comes from `api.github.com`, which the egress proxy answers
403 (rate limit, dressed as *"Could not authenticate against github.com"*).
Composer rolls the whole install back on one failure, so 31 of 32 packages
arriving is the same as none. `codeload.github.com` is 403 too. What works:
`git clone` **does** reach github.com, and phpstan's repository contains a
built `phpstan.phar`. So the rig is assembled by hand — phpstan's phar and
PHPUnit's from `phar.phpunit.de`, the phpstan-wordpress extension and the
WordPress stubs cloned into `vendor/`, and a ten-line `vendor/autoload.php`
registering the same PSR-4 map `composer.json` declares. PHPStan needs
`--memory-limit=3G` there; 1G is not enough for the 5.7MB stub file.

**The unit suite needs that autoloader, and `vendor/bin/phpunit` will not load
it.** In this rig that path *is* the 5MB PHAR, not a Composer shim, so it never
reads `vendor/autoload.php` — and 43 of the 107 tests fail with *"Class not
found"* for classes `tests/bootstrap-unit.php` deliberately does not `require`.
That is the rig being wrong, not the suite. Prepend the autoloader explicitly:

```
php -d auto_prepend_file=vendor/autoload.php ./vendor/bin/phpunit -c phpunit-unit.xml.dist
```

107 pass, 296 assertions. Do that **before** reading anything into a red unit
run there.

**Playwright cannot run on the device VM at all** — `chrome-headless-shell`
dies with *"error while loading shared libraries: libXdamage.so.1"*, and
installing system libraries there is not available. It runs in the cloud
container, which has Chromium pre-installed under `PLAYWRIGHT_BROWSERS_PATH`
— but at a **different build number** than the pinned `@playwright/test` asks
for, so the launch fails with *"Executable doesn't exist at
…/chromium_headless_shell-1243/…"*. Symlinking the installed build's directory
to the expected name, including the `.pak`/`.so`/`locales` siblings and the
`INSTALLATION_COMPLETE` marker, is enough; `headless_shell` is the binary and
`chrome-headless-shell` is the name Playwright looks for. The suite boots its
own WordPress on php-wasm, so nothing else is needed — and note it stages the
plugin from the checkout, so **`assets/build/` must be built first** or about
fifteen tests fail on a rail that never mounts.

**The development site is not reachable from either.** `https://playground:8890`
resolves only on Nick's Mac. The device VM's shell cannot see it and neither
can the container — which is why live verification goes through the browser
extension and the iframe rig, and why the e2e suite brings its own WordPress.

### Running the integration suite

**It has not been run since 18 Sep.** The 19 Sep drill-down step left it
unrun: it needs a MySQL as well as the test library, and the session's
Composer could not complete at all (above). The PHP touched that day is four
entries added to an i18n label array in `Admin\Rail.php`, which PHPStan reads
and which the e2e suite exercises end to end — so the gap is known and narrow,
and it is still a gap.

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

## The write paths are atomic (review item #15)

Every multi-statement write in `FolderService` — `move`, `delete` (both
branches), `assignAttachments`, `unassignAttachments`, `moveAttachments` — runs
inside `Database\Transaction::run()`. Before this, a `delete` that reparented
children and then failed on its own row left the children reparented.

- **The callback API is the whole design.** `run()` takes a closure; a
  **`WP_Error` return rolls back** and is handed straight to the caller, and a
  thrown `Throwable` rolls back and rethrows. This matters because the domain
  layer signals failure with `WP_Error` and not with exceptions, so a plain
  try/catch wrapper would have committed every failure it was written to
  prevent.
- **Transactions do not nest in MySQL.** A second `START TRANSACTION` commits
  the first silently. `run()` keeps a depth counter and uses **savepoints**
  below the outermost level, and an outermost call that finds a transaction
  already open (someone else's, or `WP_UnitTestCase`'s) takes a savepoint
  rather than committing what it did not start.
- **`Transaction::after()` defers `do_action` until after the commit.** A
  listener that reads the database mid-transaction would see rows that are
  about to vanish, and one that enqueues external work would fire for a write
  that never landed. Queued callbacks are discarded on rollback.
- **The tables are `ENGINE=InnoDB` explicitly.** MyISAM *accepts* `START
  TRANSACTION` and ignores it, so a site whose default engine is MyISAM would
  have run every one of these paths with no atomicity and no error.
  `Schema::ensureInnoDb()` and a `Doctor` finding report any table that is not.
  `SHOW TABLE STATUS`, not `information_schema`, which is not reliably readable
  on shared hosts.
- **Batches are all-or-nothing** — Nick's call, taken while the plugin has no
  installed base to be compatible with.
- **`assumeOpen()`** is how the PHPUnit harness tells `Transaction` that
  `WP_UnitTestCase` already opened one. Its first version set `depth = 1`,
  which would have meant `after()` callbacks never fired in any test.

## Things that will bite you

Every one of these cost real time and is now load-bearing somewhere.

**`clientWidth` includes padding, so it is the wrong box to measure a child
against.** The roles matrix was declared to fit a 320px screen on a
`clientWidth` reading: it was 277.2 in a content box of 248, running 29.2px
past the content box and 4.2px past the card's own border, with the page not
scrolling because the card's 24px of right padding absorbed it. Use
`clientWidth − paddingLeft − paddingRight`, and remember that **a page which
does not scroll sideways is not proof that a child fits its parent** — only
that the overflow was smaller than the padding around it.

**A scheme or theme swapped onto a live document leaves stale colours.**
Rewriting `document.body.className` to drive a colour scheme flips the custom
properties — `--ff-ink` on the element read `#f0f0f1` correctly — while an
anchor's already-resolved `color` stayed at the *light* scheme's value. The
settings tabs measured 1.09:1 and 2.00:1 on Midnight and looked like the
headline finding of the audit; re-rendered they are 12.84 and 10.43. **When the
scheme is the variable, render the document with it**: fetch the HTML, rewrite
the `admin-color-*` token in `<body class>`, inject `<base href>` and that
scheme's `colors.min.css`, and hand the string to `iframe.srcdoc`. This also
avoids writing to Nick's profile, which is where the real setting lives.

**wp-admin's colour schemes leave the content canvas light.** Midnight's own
stylesheet opens `body{background:#f0f0f0}`. Only the menu, the bar and the
accents go dark. Any reasoning of the form "it sits in a dark admin page" holds
for the rail, which abuts the menu, and not for a centred card.

**A stylesheet or script change may not reach the browser.** `admin.css` and
`core/rail.js` are enqueued with `ver=1.0.0`, so a plain reload serves the
cached copy, `location.reload(true)` is ignored by modern Chrome, and
`cmd+shift+r` works but drops the query string — which loses a deep-URL state.
From an automation tool the reliable move is to warm the cache first and then
reload:

```js
await fetch('/wp-content/plugins/folderfolio/assets/build/core/admin.css?ver=1.0.0', { cache: 'reload' });
await fetch('/wp-content/plugins/folderfolio/assets/build/core/rail.js?ver=1.0.0',  { cache: 'reload' });
location.reload();
```

Each asset needs its own bust. A session went a full round of measurements with
fresh CSS and a **stale bundle**, which read as "the JavaScript half of the
change does not work".

**The browser extension cannot resize the window, so drive widths through an
iframe instead.** `resize_window` reports success and `innerWidth` does not
move. What works, and what every number in the 19 Sep audit came from: create a
same-origin `<iframe>` of the wanted width inside the logged-in page, point it
at `upload.php`, and `eval` the survey inside it. Media queries *and* container
queries resolve against the iframe's own viewport, so it measures the real
thing, it needs no permission, and it never touches the window the person is
using. It reproduced a screenshot Nick sent, pixel for pixel.

**Only the Comet browser is authenticated.** Claude in Chrome reaches the dev
site with Nick's session. Playwright and chrome-devtools each connect with
their own profile and land on `wp-login.php`.

**`upload.php` remembers the view mode in user meta.** A probe that loads
`?mode=list` leaves the whole library in list mode for the next visitor, who is
Nick. Pin `?mode=grid` on every probe and restore it afterwards.

**`display: contents` promotes *every* child to a grid or flex item** — a
`.screen-reader-text` label included. One 1px label inside the folder slot was
claiming a whole 38px grid row; the toolbar computed `grid-template-rows: 38px
38px` with a single control on screen. Hide such a slot **as a slot**, not by
naming the control inside it. The same fact bites the other way: a `>` selector
never reaches a control inside one, because it is a grid item and a DOM
grandchild at the same time.

**The rail has two homes, and the inline script picks by the breakpoint.**
Wide it is a column, so it lives in `#wpbody` before `#wpbody-content` — the
only way to get a node between those two elements, since WordPress offers no
hook there. Narrow it is a band, and being first in `#wpbody` put it *above*
WordPress's own screen-meta row: measured, it pushed `Help` to 421px and the
page heading to 481px. Narrow, it moves inside `.wrap` after `.wp-header-end`,
so the order is Help → title → band → filter row → cards → files. The wide home
is taken synchronously; the narrow one on `DOMContentLoaded`, because `.wrap`
has not been parsed when that script runs.

**44px is this plugin's touch target, and `--ff-row-h` is how rows get there.**
The closed phone bar was built as a 44px row precisely because a 24px chevron
was not enough to hit; the *open* sheet then shipped with everything under it —
New folder 28, the four tools 34, every row 36, the search field 30, the tree
chevron 20. All of them are 44 at narrow width now. Rows move through the
`--ff-row-h` token rather than by naming `.folderfolio-row`, so any row added
later follows without anyone remembering.

**A control whose width sets someone else's indentation cannot just be made
wider.** The tree chevron is the case: the folder name's indent is derived from
it. The box keeps its size and a centred `::after` carries a 28 × 44 target —
full row height where the miss happens, 4px either side horizontally, which
stays inside the 6px gap before the folder icon. Verify with
`elementFromPoint` that the enlarged target has not swallowed its neighbours:
the centre of a folder name must still return `.folderfolio-row__name`.

**Two tappable surfaces need a channel between them.** The `Add Media File`
button sat 8px above the narrow band. On a phone that is inside the margin of
error. `--ff-rail-gap` is the token for it — the same 20px that separates rail
from library on the desktop, turned on its side.

**`insertBefore(node, node)` is the trap to remember.** It is legal, does
nothing visible, and still fires a removal and an insertion. That wakes any
MutationObserver watching, which schedules another pass, which does it again —
and a node detached between `mousedown` and `mouseup` fires **no `click` at
all**. It cost a whole debugging session: the `Filter` button looked dead to a
mouse while a scripted `.click()`, synchronous and landing between two churns,
always worked. Both the toolbar slot and the rail's placement script guard
against it explicitly now.

**A media-toolbar button carries `margin: 0 0 4px` from core.** Centre a 34px
box that has 4px below it in a 38px row and it lands 2px high — which is
exactly what happened to the `Filter` disclosure beside `Bulk select`.
`align-self: center` was already correct and was not the cause. Measure the
*centres* of every control on a row before believing one is aligned.

**Do not invent a size for a control that sits beside one of core's.** The
first `Filter` button was given `height: 32px`, `font-size: 12.5px` and
`padding: 0 9px`; `Bulk select` next to it is 34px, 14px and `0 12px`. Let it
be a `.wp-core-ui .button` and the two match by construction instead of by a
number copied off a screenshot.

**`width: 100%` cannot beat its own containing block.** The grid-mode search
field stayed 182px through a stylesheet rule *and* through `width: 100%
!important` set inline — because its containing block was 182px. When a width
declaration appears to do nothing, widen the parent; the failure is one level
up from where it shows.

**An empty grid row costs a whole track.** Closed, the narrow toolbar had
nothing placed in row 2 and still computed `grid-template-rows: 38px 38px`,
spending 46px of panel on it — the gap that appeared above the search field.
Declare `grid-template-rows: auto` so there is exactly one explicit track and
let the rest be implicit.

**Auto-placement will draw two controls on top of one another.** One filter
spanned `1 / -1` while the next kept `grid-column: auto` and was placed into
column 2 of the same row: both at top 338, one 476px wide and one 161px,
overlapping. In a layout that must not move, place by `grid-area` and state the
row.

**Core sizes its toolbar controls against a 60px row.** `.view-switch` is 38px
with `padding: 12px 0`, and the grid-mode toggle computes to 62px to match it.
Invisible inside core's flex row; as grid items they *become* the row.

**wp-admin styles bare elements, and those rules reach inside our components.**
`dd, li { margin-bottom: 6px }` is (0,0,1) and applied to every `<li>` we
render — the breadcrumb's items and, unnoticed for weeks, every row of the
tree, which sat on a 42px pitch beside 36px fixed rows in the same column. A
`<ul>` zeroing its own margin does nothing for its children. When a component
of ours uses a semantic element, check what wp-admin already says about that
element.

**wp-admin's own selectors carry an element; ours usually do not.**
`.wp-core-ui .button` is (0,2,0) and sets `display`; `.wp-core-ui select` is
(0,1,1) and sets `max-width: 25rem`. A bare class of ours is (0,1,0) and loses
to both however late this stylesheet loads — order only decides ties. Six times
now in `_toolbar.css` alone, and the last is the instructive one: the folder
select's `max-width: 14rem` had **never applied in any release**, and nothing
revealed it until a tree large enough to make the select grow. **A cap that has
never been tested against content wide enough to reach it is not a cap.**

**A container cannot query itself.** `@container` resolves against the nearest
*ancestor* container, so a rule naming `.folderfolio-rail` inside the rail's own
container query matches nothing — and it fails **silently**, leaving the
property at exactly what it had been. Put those rules on a descendant:
`__body`, `__tool`, the slots. Only reading the computed value catches it.

**Check a component in its totality, and in every state.** Two of this
project's worst regressions were introduced by a fix that was correct for the
one element it was aimed at: a toolbar break that fixed grid and broke the
agreement with list mode, and a collapsed rail that measured perfectly while
drawing across the page. `library.spec.ts` therefore surveys *every* control in
the library chrome, groups them into lines and compares the shapes in both
modes — and `responsive.spec.ts` photographs every width. Look at the
screenshots of the states side by side; a number from one of them is not the
verification.

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

**`.folderfolio-rail__app` matches two nested elements.** `Rail.php` prints
the shell with that class and `Rail.tsx` renders a second one with the same
class inside it, so a Playwright locator on the class is a strict-mode
violation. Use `#folderfolio-rail-app`. (Worth tidying; nothing depends on the
duplication.)

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

**A check can pass by crushing the thing it was checking.** The toolbar's
"Rename is clipped" spec asserted that no label overflowed its button. A
button can always satisfy that by shrinking its icon to zero width, which is
exactly what flex was doing — so the same defect was filed twice, once as a
clipped label and once as a faint icon, and the assertion could not have
distinguished the fix from the symptom. Assert what should be there, not only
what should not overflow.

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

**`line-height` inherits as a computed length, not as a ratio.** A child with a
smaller font-size gets the *parent's* pixel line-height, which is how an 11px
digit ended up in a 25.4px line box inside a 17px pill. Any badge, pill or
chip whose font-size differs from its parent's needs its own `line-height`.

**A sticky offset is measured from the scrollport's padding box**, not its
border box. `top: 0` inside a scroller with `padding-top` leaves exactly that
much room above the stuck element for content to scroll through — and **that
padding is still there when nothing is stuck**, which is the state a negative
`top` does not reach. Prefer removing the padding to cancelling it: `top: 0`
then means the same thing in both states.

**A scroll shadow drawn as a background layer is behind the content.** The
rail's four-layer shadow works because its rows are `background: none`; a
hover or selected row, or an opaque sticky header, covers it. That is why the
pinned level band has no top shadow of its own.

**`computer{action: "zoom"}` does not magnify.** It returns the region at
roughly its own pixel size, so a 30 x 100 crop comes back 34 x 112 and settles
nothing. For a few-pixel question either **save the screenshot** —
`save_to_disk: true` with `scale: 1`, which lands in the *cloud container*
where Python can read it column by column — or **draw a reference element** in
the page beside the thing in question and compare the two. Both were needed to
find the level band's six pixels. `zoom`'s region coordinates are in the
full-resolution frame, not CSS pixels; on this machine the factor is ~1.795.

**A cloned element loses scoped custom properties.** Appending a clone to
`document.body` to inspect it in isolation collapsed a 176px band to 18px,
because `--ff-row-h` is defined on an ancestor. Append the clone **inside** the
component, then position it.

**`z-index` ties are broken by document order.** The rail's row parts are
`position: relative; z-index: 1`, so anything meant to cover a row needs 2 —
the sticky level header looked transparent for exactly this reason.

**Two toolbar slots must never anchor to each other.** The three slots place
themselves in a chain that ends at a control of core's — bulk before the mode
toggle, the folder select before bulk, the narrow-width picker before the
select — and a chain ending at somebody else's node has exactly one stable
arrangement. Anchoring the picker to `filter.nextElementSibling` instead, to
put it *after* the select, closes the chain into a cycle: the select's slot
then wants to be immediately before bulk and is not, so it moves, which makes
the picker wrong, so it moves. It settled in a different order on two machines,
which is what a race looks like from the outside. This is the second bug in
this file's history caused by a slot naming a position relative to a node that
can be itself; `settle()`'s `insertBefore(node, node)` note is the first.

**A grid never shrinks an auto row below its content to fit a bounded
container.** Only `fr` rows are shrunk. `.folderfolio-flyout` is clamped to the
room between its trigger and the edge of the window, and the scrolling list has
to be the part that gives — so it is a **flex column**, against the standing
grid-first preference, because flex can say "this child absorbs the deficit"
without knowing the child's row index, and the two panels that share the file
have different row counts. Tried with grid first: the panel clamped to 386px
and the list ran 24px past its own bottom border.

**A panel that flips above its trigger is placed by its bottom edge.** The
obvious version — `top: trigger.top - panel.offsetHeight` — reads the height
*before* the clamp that is about to shorten it, so it placed a 386px panel that
then rendered 186px tall and sat 200px too low, overlapping the trigger it was
flipping away from. `bottom: innerHeight - trigger.top` needs no height at all.

**A hidden node reports `top: 0`, which is above everything.** wp-admin prints
`<div class="notice error hide-if-js">` inside `.wrap`, right next to the rail.
A geometry survey that asks "what is below this element" and takes the next
sibling gets that node and concludes the page is upside down. Filter by box —
`width > 0 && height > 0` — before comparing anything.

**The rail's breakpoint is script-readable; the toolbar's is not.** They look
alike and are opposites. The toolbar collapses on the width of the *library
column*, which depends on a rail the user drags, so it is a container query and
`FilterDisclosure` renders unconditionally and lets CSS decide. The rail's own
782px is a media query that `Rail.php`'s placement script already evaluates to
choose between the rail's two homes — so `lib/narrow.ts`'s `useIsNarrow()` may
be read from script, and `Rail.tsx` renders the tree **or** the level view,
never both.

## Which document is which

| Document | What it is | Still authoritative? |
|---|---|---|
| `../DESIGN-TO-CODE.md` | The design handoff: screens, measured geometry, states, keyboard and ARIA contract, definition of done | **Yes — the UI spec** |
| `architecture-plan.md` | Decisions, data model, REST surface, importers, developer API, gallery block, the ten phases to 1.0 | **Yes — the roadmap** |
| `m2-importer-matrix.md` | Verified schemas and detection keys per migration source | Yes, when the importers are rewritten |
| `research/01..04-*.md` | FileBird, Real Media Library, Folders, CatFolders — measured live and read from source | Background, and the reason for several decisions |
| `deep-review-2026-09-16.md` | 29 numbered findings against 0.2.0 | Partly — #15 is closed; #24, #26, #27, #28 and #29 are still open |
| *(Claude project)* `progress-2026-09-19b-thousand-folders.md` | The 1,050-folder stress test, the searchable picker, the drill-down sheet — the most recent build log | **Yes — the newest, and outside this repo** |
| *(Claude project)* `progress-2026-09-19-responsive-toolbar.md` | `readme.txt`, the narrow toolbar, the phone band | Yes |
| *(Claude project)* `progress-2026-09-18h-transactions-toolbar-phone.md` | #15, the merged bulk trigger, the phone bar | History |
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
