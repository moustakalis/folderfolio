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

### 22 Sep — what the market charges for, and what we already give away

Four codebases read again, for a different question: what does each rival put
behind a licence, and which of it belongs in 1.0. **No code touched, no
competitor activated** — every gate is readable in source, which is faster and
spends no fixture. Board `claude.ai/artifact/VPQoAc1vY7XTkERCp8ZPYU`; the full
record is `progress-2026-09-22d-the-paywall-read.md` in the Claude project.

**Three of the four sell nested subfolders.** CatFolders opens a *"Want
Subfolders?"* modal, Premio prints *"Sub-folders is a pro feature"* into
`templates/admin/modals.php:147`, and RML Lite throws
`OnlyInProVersionException` from `inc/overrides/lite/folder/Creatable.php` —
a gate at the CRUD layer, not the UI. Only FileBird gives nesting away.

**1.0 already gives away six features this market prices, and mentions none of
them:**

| Feature | Who charges, and how it is gated |
|---|---|
| Nested subfolders | CatFolders · Premio · RML |
| Folder permissions by role | CatFolders · Premio |
| Import from rival plugins | RML — real `disabled` attributes, and the four routes are never registered in Lite |
| Folder colours | FileBird — `disabled: true` on the submenu trigger |
| Upload into the selected folder | Premio — a `.disabled` anchor pointing at the upgrade URL |
| The folder tree in the media picker | RML — Lite gets a dropdown |

Add *move to trash before permanent delete*, which Premio sells and our undo
window answers by another mechanism, and it is seven.

**The only row where all four beat us is folder reordering in the tree** —
~1.5–2 days, because it needs an order column and a migration, drag-and-drop in
*both* renderers, and a rule for what a manual order means while `default_sort`
is `name-asc`. It stays a 1.1 item.

**One feature is recommended into 1.0: export the folder structure**, ~3h — one
REST `GET` serialising the tree and one button on the Import tab, which already
exists. Nine importers in and nothing out is a roach motel.

**Download-as-ZIP was rejected despite three of four charging for it.** The
feature is one function; what ships is temp-file lifecycle, a memory ceiling on
shared hosting, a timeout on any folder large enough to want it, a
path-traversal surface and a cleanup schedule. FileBird ships a
`filebird_saved_downloads` option and a whole `Schedule.php` cron for precisely
this. *"No account, no licence, no telemetry"* also means nobody on the other
end of the ticket.

**And a startup folder cannot be built the way the market builds it.** A
remembered folder forced onto `upload.php` is exactly how FileBird hides 28 of
47 files with nothing on screen saying so. The user choosing it is not the same
as us choosing it for them — but the filter has to be stated, and the
breadcrumb's × is where it would be.

> The research's best output costs no build time: **the readme**. *"Unlimited
> nesting"* is a shrug. *"Unlimited nesting, folder permissions by role, and
> import from nine plugins — all free, all paid features elsewhere"* is the
> product.

**Nick's three answers, same day.** **Export goes into 1.0** (~3h). **The
readme names what is paid, not who** — naming rivals in prose is permitted (the
ban covers readme **tags**, guideline 12, and the **slug**, 17) and he does not
want that fight on his own listing page, so do not re-propose it. And **folder
reordering is built now, with 1.0 slipping two days for it** — overruling the
recommendation to hold it for 1.1, because it is the only row where all four
rivals beat us. The open design question is his and unanswered: **a manual
order and a `default_sort` of `name-asc` cannot both be in force.** Draw it
before building it.

### 21 Sep, the source read — three corrections to the first-minute pass

**No code changed.** Board updated in place:
`claude.ai/artifact/P1U7zC2ofNWQmnHJ8KweLC` (v2). Project record:
`claude/progress-2026-09-21g-the-source-read.md`.

All four rivals had been activated once before, on 18 Sep, so every live
measurement was strictly a **second** activation. All four codebases were read
rather than resetting a fixture. **Three live results were wrong.**

**Do not "drop the tables and reinstall" to get a first run.** First-run state
lives in `wp_options`, never in a plugin's folder tables — and all four ship a
**stub `uninstall.php`** (Premio has none at all), so WordPress's Delete button
removes the files and nothing else. Delete-and-reinstall resets nothing. A true
first run costs a handful of option rows and no data.

- **RML does redirect**, via `<meta http-equiv="refresh">` from `admin_head` on
  the plugins screen (`vendor/devowl-wp/real-utils/src/WelcomePage.php:76-85`)
  — invisible to a network log *and* to a `wp_redirect` grep. Gate: the `raa`
  key inside the `real_utils-transients` JSON blob, one-shot per install,
  already spent. **Two of four redirect, not one.** It is also the best-behaved
  one: it exempts bulk activation and WP-CLI explicitly.
- **RML also opens a blocking licence modal** on a virgin install —
  `mask:{closable:false}` plus a MutationObserver that re-opens it — gated
  purely on `rpm-wpc-code_real-media-library-lite` not existing.
- **FileBird and CatFolders are not silent on a fresh install.** Both add a
  *Create your first folder* notice on every admin screen **except**
  `upload.php`, gated on the **live folder count** (`Core.php:88-94`;
  `Notices.php:22-32`). The fixtures' 20 and 35 folders are what silenced them.
- **Premio's redirect re-arms on every activation** —
  `delete_option` then `add_option("folder_redirect_status", 1)`
  (`folders.class.php:5951-5953`). And the bulk-activate claim was too strong:
  core activates everything in request one and Premio redirects on `admin_init`
  in request two, so **later plugins do still activate**. Its real defect is no
  capability check — a Subscriber can absorb the redirect and hit a permission
  wall with the flag spent.
- **FileBird's review nag arms at +3 days, not day one** — `prepareRun()` calls
  `Review::update_time_display()` before the notice class is constructed. Its
  "Later" snooze is 5 days, not the 3 its comment claims, and it re-arms on
  every plugin update.

**What it changes for us: nothing about the recommendation, everything about
the argument.** On first-run *noise* FolderFolio is not in a majority — it is
alone. And it kills one argument for a notice: of the four notices in those
codebases, **not one dismisses permanently** (CatFolders' cannot be dismissed
at all — its AJAX action is unreachable dead code; two return after 30 days;
one after 365). Option F stays the recommendation.

**Four traps this pass taught** — 37 to 40 in the project's start-here page,
and in *Things that will bite you* below.

### 21 Sep, the first minute — what the market does after activation

**No code changed.** Board: `claude.ai/artifact/P1U7zC2ofNWQmnHJ8KweLC`. Full
record in the project at `claude/progress-2026-09-21f-the-first-minute.md`.
**Six options drawn; nothing is built until Nick chooses.**

Each rival was activated **alone** with FolderFolio deactivated, measured, then
deactivated again. The site was restored: four rivals off, FolderFolio on, **no
fixture data written**. WordPress **7.1.1**. FileBird 6.5.8 · Real Media
Library Lite 4.23.4 · CatFolders 2.5.6 · Folders (Premio) 3.2.0.

- **Three of four do not redirect.** Read from the network log, not the address
  bar: each chain ends at core's own
  `plugins.php?activate=true&plugin_status=all&paged=1&s=`. **Premio's Folders
  does**, into its own settings page, where a **470 × 478px modal** opens
  unbidden listing three rivals with counts and an `Import` button each. Shown
  once; Close persists.
- **Nobody adds a wp-admin notice.** All five score zero. RML's two notices are
  **in-panel** — its own React app inside its rail — and cost **210px of a
  266px-wide rail**, putting its first real folder at **y=460 in a 741px
  viewport**. Its cross-vendor dismissal **persists server-side**: tree top
  **361 → 267px**, and it stayed there across a reload. Its second alert only
  offers *"Hide for 30 days"*, so it comes back.
- **RML's import is paywalled.** `Import (Folders)` and `Import (FileBird)` on
  `options-media.php` carry a real **`disabled` attribute** and compute to
  `#8a8a8a`. The free tier detects a rival, names the offer, then sells it.
- **Premio also ships a permanent `Unlock all Pro features →` link**, 156 × 14px
  at `#ff5983`, in the rail, not dismissible; its `New Folder` button is
  `#f51366`, brand pink rather than core's accent.
- **The market splits two and two, and not by reach.** FileBird (200k) and
  CatFolders (6k) bury the migration two clicks inside a settings page nothing
  points at — **exactly as we do**. RML and Premio put it where the user
  already is. **We are in the majority, not an outlier.**
- **Our Import tab reports 77 folders and 89 file assignments** across the four
  — FileBird 20/28, RML 7/0, CatFolders 35/36, Folders 15/25 — and nothing
  anywhere else says a word about it.

**What wordpress.org actually forbids.** Activation redirects: **no rule** —
the word appears once in the guidelines, about affiliate links. The real cost
is a bug: an unguarded redirect that `exit`s inside core's bulk-activate loop
leaves every later plugin unactivated (**trac #40252**, open nine years), and
core is moving the other way (**#61040** proposes an opt-in *Open* button).
**Guideline 11 is the one that binds** — notices must be *"limited in scope and
used sparingly, be that contextually **or** only on the plugin's setting
page"*, and the media screen **is** the contextual home for a media-folders
plugin. **A dismissal must persist**: core's `is-dismissible` only removes the
DOM node. **Naming a competitor is permitted** — the ban covers readme *tags*
(guideline 12) and *slugs* (17) and nothing else. **Plugin Check tests none of
it**; its one relevant check is Safe Redirect, so use `wp_safe_redirect()`.

**The recommendation: F, paired with C, holding B in reserve.**
`assets/src/apps/rail/Tree.tsx:331` renders `No folders yet` when the tree is
empty, and its own comment calls that *"a library nobody has filed yet"* — a
sentence that is **false whenever a rival has data**. So this is not a notice
to justify but an untrue sentence to fix: nothing added, nothing to dismiss, no
user meta, no AJAX, no guideline-11 exposure, and it deletes itself the moment
a folder exists. **~3 hours and one guard**, against ~a day and two for a
notice. Its one unavoidable piece of state: `Catalog::detect()` runs nine
sources' queries and is far too heavy for every `upload.php` load, so it needs
a **cached boolean**. **C** is a line on our own plugins-list row via
`after_plugin_row` — ~2 hours, no state at all. **E, redirect on activate, is
rejected on the product's own terms, not the directory's.**

**Method caveat.** All four had been activated before, on 18 Sep, to build the
fixtures, so what was measured is strictly a **second** activation. Premio
still redirected and RML still showed its cross-vendor alert, so those two are
not first-run-gated; FileBird and CatFolders on a genuinely fresh install could
differ, and proving it would mean deleting their options rows in phpMyAdmin.

**Three traps this pass taught** — 34, 35 and 36 in the project's start-here
page, and in *Things that will bite you* below.

### 21 Sep, last of all — A11 takes core's accent, and a guard that had stopped guarding

Commit `a9ee2c6`. **PHPStan clean, 111 unit (309 assertions), 54 e2e, `tsc`
clean.** Full account: `claude/progress-2026-09-21e-a11-and-the-a8-scenario.md`.

**A11, Nick's call.** The checked segment takes `--ff-on-sel` on `--ff-sel` —
the pair it was reaching for before finding 11 sent it to `--ff-ink` on
`--ff-panel`. The tab marker and the checked option are the same colour now, so
the screen has **one language for *chosen***. Measured in all nine schemes:
white on the theme colour is **4.57:1 at worst** (Fresh, Light) and **5.61 at
best** (Modern) — exactly the table `_tokens.css` carries, so it is safe by
construction.

> **A guard can stop guarding without failing.** Writing A11's negative control
> showed that finding 11's original pairing — `--ff-on-sel` on `--ff-bar` —
> **no longer fails the contrast test**: since the theme rework `--ff-on-sel`
> is `#fff` in every scheme, so white on `#1d2327` is 15.89:1. The pairing is
> still wrong, it is just no longer wrong in a way a ratio can see. Four
> instances have shipped and the docs warn about it in three places, and
> nothing was enforcing it —
> `test_the_accent_ink_is_never_painted_on_the_chrome_ground` does now, by
> name. **Re-run the old negative controls after a system-wide change, not
> just the new one.**

**Left behind, raised and not changed:** the wizard's current-step badge is now
the last thing using `--ff-ink` on `--ff-panel` for *you are here*, and on the
import tab it sits directly under a tab marker that is the accent — two
markers, stacked, in two colours. *Chosen from two options* and *step 1 of 4*
are arguably different meanings; Nick's to say.

**A8 was the wrong question, and the code says why.** `Repair folder tree`
calls `backfillPaths(true)`, and that `true` is `$force`, which **skips the
short-circuit the function already has**. With nothing wrong it walks every
folder and issues **one UPDATE per folder — 1,053 on the dev site** — writing
values that are already there: **the button does its maximum work precisely
when there is nothing to do.** It is safe (`path` and `depth` only; `updated_at`
has no `ON UPDATE`), but the notice afterwards claims a repair that did not
happen, and `deleteOrphans()` does the same in past tense. Both functions
return an `int` and **both return values are thrown away**. So the choice is
not disable-or-not: it is **drop `$force` when the report says the tree is in
step, and report the count** — the short-circuit exists and is being
deliberately bypassed. **Nick took it on 25 Sep** — an honest repair: the button
stays live, the force goes, only drifted paths are written, and the notice
reports the real count.

### 21 Sep, after that — the box comes off and the page becomes the surface

Commit `108464c`. **PHPStan clean, 110 unit (307 assertions), 54 e2e, `tsc`
clean.** Options board: `claude.ai/artifact/Hu8kPi9uQUQErsAAK8Mk1e`.

Nick: the boxed layout reads old school. He is right, and **core agrees** —
`options-general.php` has no box at all: a transparent `.wrap`, a transparent
`.form-table`, and not one `.card` or `.postbox`. Ours was a white panel with a
`#8c8f94` border **and** a drop shadow, the only shadow in the plugin.

**Three grounds were drawn and measured before choosing.** Dropping onto core's
grey canvas is the most native-looking and it costs finding 12: every rule here
is `--ff-line`, `#dcdcde`, chosen against `--ff-panel`, and on `#f0f0f0` it goes
**1.37:1 → 1.20:1** — the matrix row rule with it, which is the rule the eye
tracks across four tick columns. And nothing would have said so: `TokensTest`
checks ink against the panel, and **a screen with no panel makes that guard pass
while describing a surface it does not have.** So the canvas goes white instead
and every token keeps the ground it was measured against. Text was never the
question — zero failures, worst pair 5.61:1, in all three.

> **The token had to move, and this is the part that nearly shipped wrong.**
> `background: var(--ff-panel)` on `#wpcontent` resolves to nothing:
> `#wpcontent` is an **ancestor** of `.folderfolio`, and the token block is
> scoped to the component. It computed to `transparent`, so the box came off and
> the ground stayed grey — the other design, silently. **A 0.66-scale screenshot
> cannot tell `#f0f0f0` from `#fff`**; the computed style can, and did.
> `--ff-panel` is on `:root` now, inherited by everything in `.folderfolio`,
> still one value.

Three guards came with it. `TokensTest::colourTokens()` **reads both scopes**
— it read only `.folderfolio` before, so a token on `:root` was guarded by
nothing. A new test fails if a colour is declared in **both** scopes, because
the merge lets `.folderfolio` win and a drift would resolve silently. And an
**e2e guard**, because no unit test can see this class of bug: both files were
correct, only the *pairing* was wrong, and only a browser knows which selector
can see which custom property. Negative control: putting `--ff-panel` back
inside `.folderfolio` fails with *"#wpcontent is rgba(0, 0, 0, 0) — the ground
rule resolved to nothing"*.

Verified on four schemes (Light is the tightest — an `#e5e5e5` menu against
white content), three tabs, twelve widths, **zero overflow in all 36
combinations**. `upload.php` is untouched: the rail is still `#fff` on a grey
canvas at Nick's 310px.

**Finding 10 is re-opened and deliberately left.** The 880 cap leaves 378px to
the right of the column; that was defensible *because it was canvas*, and
painted white it is empty page. Rendered it still reads as a left-aligned
column. The fallback, if it ever stops reading that way, is to let the field
rules run the full width.

### 21 Sep, last pass — three questions of Nick's, and the colour legend goes

Commit `afed1e7`. **PHPStan clean, 109 unit (305 assertions), 53 e2e, `tsc`
clean.**

**The Folder counts help line leads with the option in force.** It named both
options in a fixed order, so on a site set to *Direct only* — the default — it
opened by describing the option you are not using. Describing only the active
one instead fails the other way: you cannot choose between two things when you
are shown one of them. Both, active first, **server-side** — a help line that
re-writes itself on a click would be describing a setting that has not been
saved yet. The guard asserts against the **checked radio**, not a fixed string,
so it holds whichever way the setting is stored, and the existing count-mode
test exercises both orders for free.

**The select's caret was 2.1px from the word and 8px from the edge.** Core
reserves `padding-right: 24px` and paints the caret at `calc(100% - 8px)`, so
the glyph runs 24px to 8px off the right edge. On an **auto-width** select the
text fills its box right up to that padding, and the only thing between the
last letter and the arrow is the browser's intrinsic-width slack. Against 8px
of gutter on the arrow's far side and a 12px inset on the left, it read as the
arrow having slid into the word. `padding-inline-end: 30px` gives 8.1px and
8px. (The caret also sits **0.7px below centre**, because core positions it at
`55%` and we force a 30px box. Left alone — same argument as the admin menu's
arrow.)

**The Folder colours row is gone.** Ten swatches nobody can change, on the tab
for things you change. A folder's colour is picked **on the folder**, in the
rail's More menu, where the ten are already shown by name — and the palette is
fixed on purpose, because a folder stores a swatch *name* and the hex it
resolves to is a property of the admin colour scheme (`Support\Swatches`). The
row went with its constant and its rules, `--last` moved to Undo window, and it
retired a name collision: **`.folderfolio-swatches` was styled in
`_settings.css` for that legend and in `_toolbar.css` for the real picker**, and
only separate bundles kept them apart. **A9 is fully closed.**

Three fields on the tab now, zero page overflow at thirteen widths.

> **Nick's admin colour scheme is Modern again** (`#3858e9`), not Midnight.
> Screenshots he sends are Modern unless he says otherwise.

### 21 Sep, later — A1–A6 closed, the copy pass, and one dead rule

Full account: `claude/progress-2026-09-21b-a1-to-a6-and-the-copy-pass.md`.
Commits `19933c3`, `7d089ee`, `5983d21`, `ac7e10d`. **PHPStan clean, 109 unit
(305 assertions), 53 e2e, `tsc` clean.**

**A1 took two attempts, and the width sweep is what caught the first one.**
`overflow-wrap: anywhere` on `.folderfolio-matrix th, td` removed the overflow
and **starved the role column**: between 521 and ~650 the four ability columns
are declared at 108px, a declared width is a *minimum* in auto table layout,
and with the role column's min-content reduced to one character the algorithm
handed them everything — **27.2px of role column in a 199.5px-tall row**, at
ordinary widths with ordinary names. Scoping the rule below 520 instead leaves
**78px of page overflow at 521**, so A1 is not a phone problem. What holds is
two declarations on the one cell carrying a string we do not control:
`overflow-wrap: anywhere` **and** `min-width: 6.4em` — em so it tracks the
font-size that comes down at 520, and scoped to the role cell because giving
the ability heads `anywhere` takes the tick rhythm at 320 from 47.6/47/46.3 to
42.1/41.6/51.5.

**A2/A3.** `--ff-off` (3.24:1) stops painting text in four places and takes
`--ff-dim`; the three `:disabled` uses stay, because WCAG 1.4.3 exempts
inactive components. `TokensTest::INK` is gone — the ink list is **derived**
from every rule that sets `color: var(--ff-…)` with no background of its own,
checked against the ground it lands on, with one declared `GROUNDS` entry for
the undo toast and a test that fails if that entry stops matching anything.

**A4.** `data-folderfolio-copied` is set now, so `Copied` is translatable.

**A5/A6/A13.** `_wizard.css` joins the house rule: four of its five flex
layouts are grid, two stay flex and **say why**, and the dead `flex-wrap: wrap`
on `.folderfolio-wizard__steps` is gone. Every physical property in both
stylesheets is logical now, so RTL mirrors with **no `-rtl.css` at all**. And
`.folderfolio-seg label + label` **matched nothing for the life of the
component** — the radio inputs are siblings between the labels — and looked
right because the checked fill's own edge painted what the rule was meant to.

**The copy pass (`7d089ee`).** The import wizard's voice, applied to the other
two tabs. *"§4, the market's worst bug"* and *"Two competitors charge for this
table."* are gone; the Folder counts help line names **both** options instead
of only the unselected one; Status has a **Health check** heading and a lede,
and its rows say *Database / Deepest folder / Files in folders / Files pointing
at nothing / Folder tree* rather than *schema version / materialised paths / in
step with the adjacency list*.

> **A copy change is a layout change.** Longer repair-button labels came to
> 525.8px in a 449px content box at 521 and pushed **56px of the page off
> screen**, because `.folderfolio-tools` is `auto auto 1fr` and overflows
> rather than wrapping. A longer swatch note wrapped inside its own track at
> 783 and 521. Neither was visible in a screenshot at 1440.

**Still open, and two of them are Nick's:** **A8** (should a repair with
nothing to repair be disabled, or available and honest?) and **A11** (should
the checked segment take core's accent, or stay ink on panel?) are decisions.
**A12**, and the second halves of **A7** (the report textarea repeats the table
verbatim) and **A9** (a legend that looks like a picker), are open.

### 21 Sep — the settings screen re-inspected cold: twelve held, twelve new

Full account: `claude/progress-2026-09-21-settings-reaudit.md` in the project.
Board, drawn to scale: **`claude.ai/artifact/5ArmiNuLWKzdZ3EnzCMPfS`**.

**All twelve of the first audit's fixes were re-measured live and all twelve
hold** — eleven widths (1440, 961, 960, 783, 782, 521, 520, 390, 375, 360, 320)
and nine admin colour schemes, three tabs, the import wizard driven to its
preview step. The matrix floor is 272px at 320 with zero page overflow; the
status answer beats its label at *every pixel* from 521 to 535 (143.9 / 305.1 at
521), so the 8px band is gone; the steps are 4 above 521 and 2+2 below; copy's
right edge is 1039, flush with the body's inner right.

**Twelve new findings, A1–A12.** The two that break:

- **A1 — a role name with no break opportunity puts finding 02 straight back.**
  One unbreakable token takes the matrix's intrinsic minimum from 272 to
  **458.4px**, and the page scrolls sideways **91px at 390, 121 at 360, 161 at
  320**. `_settings.css` contains **no `overflow-wrap` at all**; `_wizard.css`
  declares `overflow-wrap: anywhere` twice. The status table has the same cause,
  milder — 282.5 against a 272 content box at 320, with page overflow 0 because
  the padding absorbs it.
- **A2 — `.folderfolio-source__none` paints `--ff-off`**, `#8c8f94` on the white
  panel: **3.24:1** at 12px, five instances on the import tab, every scheme.
  `--ff-off` is commented `/* disabled */` and this is not a disabled control;
  it is the row's whole answer.
- **A3 — and this is why A2 was never caught.** `TokensTest::INK` is a
  hand-written list of four tokens. `color: var(--ff-off)` appears at **eight
  sites** across `_content.css`, `_wizard.css`, `_toolbar.css` and `_row.css`
  and is checked by nothing.

The rest: **A4** `"Copied"` in `settings.ts` has no route to translation
(`data-folderfolio-copied` is set nowhere, so the literal always wins, and the
POT would never see it); **A5** six flex layouts remain, all in `_wizard.css`
plus `.folderfolio-settings__tabs`, none saying why, and a dead `flex-wrap:
wrap` on a container that is now a grid; **A6** no RTL build and eleven physical
properties that do not mirror; **A7** the Status tab has no heading, no lede,
and repeats all seven rows verbatim in the report textarea; **A8** both repair
tools are offered when there is nothing to repair; **A9** ten colour chips whose
only name is a `title`; **A10** the Folder counts help line describes the
unselected option; **A11** selection is expressed two ways forty pixels apart;
**A12** the Administrator row's explanation is four rows below it.

**A copy pass is proposed and not applied.** Nick asked for the settings text to
read better and sell better. The import wizard's copy is the model — *"No
account, no licence, no telemetry."* The settings tab ships two developer notes
(*"§4, the market's worst bug"*, *"Two competitors charge for this table."*) and
the status tab is written in database vocabulary (*schema version*, *1 row, 1
distinct file*, *in step with the adjacency list*).

**Nothing has been changed. Nick has the board and has not yet given an order.**
Two of the findings are his to decide rather than measure: **A8** (should a
repair with nothing to do be disabled, or available and honest?) and **A11**
(should the checked segment take core's accent like everything else, or stay
ink-on-panel?).

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

**The admin menu's current-item arrow was measured and deliberately left.**
Core paints `#adminmenu li.current a.menu-top::after` the colour of the canvas
it points into — `#f0f0f0` in seven schemes, `#f5f5f5` in Light. On
`upload.php` we replaced that canvas: the rail starts at x=160 with a **gap of
0**, and the arrow's 8px triangle lands inside `#folderfolio-rail`, which is
`#fff`. A real seam, in all eight schemes, of **15 levels out of 255** — and
rendered both ways it is **not visible at 8px**. Nick's call: leave it. If it
ever comes back it is three lines scoped to `body.folderfolio-has-rail`, the
class we already set on the one screen whose canvas we replace, with
`var(--ff-panel)` covering all eight.

> **A real measurement is not automatically a visible defect.** Render it
> before spending a rule on it.

### Three recorded deviations from the board

Each was a decision the handoff does not contain, taken deliberately:

1. **The eyebrow carries the product's name**, not "Folders" — in the rail and
   in the media modal's folder column. Not run through `t()`; a brand is not
   translated.
2. **Below a 300px rail the indent drops to 16px.** At 300 and up it is
   exactly the board. 298px in the container queries, not 300: a container
   query resolves against the **content** box, and the rail's 2px rule is
   inside its border box. The other half of this — a toolbar going icons-only
   at the same width — went away with the toolbar on 22 Sep.
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

**The colour picker (screen 11, §9.8) is part of `FolderMenu`**, which is
everything you can do to one folder. It was the whole of a fourth toolbar
button called More until 22 Sep; the toolbar is gone. Setting a colour checks
the `rename` ability, because it is the same route.

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
   had slack, did not. The pencil was never faint; it was 0px wide. *(That
   toolbar no longer exists — see 22 Sep — but the lesson does: a flex button
   short of room crushes its contents before it clips them, so asserting
   "nothing is clipped" passes on a button that has crushed its icon to
   nothing.)*
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
actually had in mind — is built now, in three gestures that share one piece of
maths: a drag, <kbd>Alt</kbd>+<kbd>↑</kbd>/<kbd>↓</kbd>, and *Move up* / *Move
down* in the toolbar's More menu.

**The Playwright suite — replaced, and it brings its own WordPress.** See
below.

## Building and testing

```bash
mise install                 # PHP 8.2, Node 20, Composer
mise run deps:install
make build                   # typecheck + esbuild → assets/build/ (gitignored)
make test                    # PHPUnit
yarn test:js                 # the rail's decision functions, node --test (no browser)
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

### Running the browser suite in the container

**Re-running `setup.sh` over a live rig breaks it**: it copies core over the
directory the old `php -S` is still serving, and every page answers 500. Kill
the old servers by PID first — `ps -eo pid,args`, then `kill` — never
`pkill -f router.php`, which matches the shell running it.

**It runs there since 23 Sep (`dab8a6a`), 87 / 87, 106 / 106 at `a6712eb`** —
the first run against
tier 1, and the first anywhere since `9cff4ad`. Playground's CLI does not
install in the container and Playwright cannot run on the device VM, so
`tests/e2e/rig/setup.sh` builds a native WordPress instead: MariaDB,
WordPress 6.8.2, `php -S` with four workers and `tests/e2e/rig/router.php`,
the staged plugin symlinked in, an auto-login mu-plugin
(`tests/e2e/rig/e2e-login.php`, what Playground's `--login` does, with one
session token so the first page's nonces verify) and four PNGs. Then:

```bash
yarn install && node tools/esbuild.mjs
WP_CORE=/path/to/wordpress bash tests/e2e/rig/setup.sh
WP_BASE_URL=http://127.0.0.1:9411 \
  CHROMIUM_PATH=/opt/pw-browsers/chromium-1194/chrome-linux/chrome npx playwright test
```

About five minutes. Its first run found two product bugs — the roles matrix
had zero slack at 320 and ran 3.5px over on Linux fonts, and the export-file
row had a third child — and a dozen tests that were stale since the toolbar
went or wrong from the start, among them a bad-neighbour helper that had
never claimed anything and a collapse spec that left the rail closed for the
rest of the run. Record: `claude/progress-2026-09-23e-the-browser-suite.md`.

### Manual file order (tier 2 item 8, `06eeb7f`)

Custom is a file order: `sort_order` on each assignment, an `ORDER BY` on the
folder join (`folderfolio_order`), written by `FolderService::moveFiles()` /
`POST /folders/{id}/files/order`. Placed by a drop between two tiles of the
folder being viewed (`apps/rail/file-order.ts`, on the existing tile drag) or
by *Move to start / Move to end* in the *Add to folder…* panel; either makes
the folder Custom. New files go first; a filed file keeps its place. The
gallery has *Folder order*. Three things it found, all fixed with it:

- **Core's media collections re-sort in the browser.** The grid's `date`
  comparator undid every folder order the server applied — a folder's own
  file order never showed in grid mode. `keepServerOrder()` (lib/filter.ts)
  stamps each model with its place in the server's answer and sorts a folder
  view by it; the picker too.
- **WP_Query caches ids under its SQL and the posts' last-changed**, and a
  filing touches neither; nothing bumped the `folderfolio` last-changed the
  gallery keyed on. Every write to our tables now bumps it and the folder
  join carries it in its SQL.
- **`wp-plupload.js` opens its "Drop files to upload" sheet on any
  dragover**, over the rail too, so a tile dropped on a folder in grid mode
  filed nothing. Hidden while one of our drags is on.

### Upload a folder structure (tier 2 item 9, `cfba873`; picker fix `133e6c8`)

Drop a directory from the desktop on the media grid (or a picker) and its
folders are made under the selected folder, each file filed into the one it
sat in. **The drop is core's**: `moxie.js` walks `webkitGetAsEntry()`
recursively and puts each file's place on `relativePath`; WordPress used to
upload the lot flat. `core/folder-upload.ts` adds the structure, from inside
the `wp.Uploader.prototype.init` wrapper `core/upload-target.ts` already has.

- **An id on the upload, never a path** — `Support\UploadTarget` still
  refuses a path. The folders are made first through `POST /folders/bulk`
  (`create`), whose rows now carry `folder_id`, and each upload names its
  folder's id.
- **Planned before anything is written**: `/folders/bulk/plan` for every turn
  of 500 paths first, so a drop too deep writes nothing and the notice says
  *why* (the create route's own refusal was written for the Tools preview).
- **The queue is held** by a `BeforeUpload` at priority 100 that returns
  `false` until the folders exist, then `stop()` + `start()`.
- **Litter stays behind** inside a dropped directory — dotfiles, `__MACOSX`,
  `Thumbs.db`, `desktop.ini` (`lib/structure.ts`, JS-unit tested). Most of it
  is refused by plupload's `mime_types` filter *before* `FilesAdded`, so an
  `Error` handler keeps core from listing `.DS_Store` as a failed upload.
- **When the folders cannot be made the files still upload**, into the
  selected folder, and the rail's notice sheet says why. No `create` ability is
  known up front and says so without a request.
- **`getOrCreateByPath()` is idempotent now for a name the sanitiser changes**
  — `splitPath()` sanitises, and a segment that sanitises to nothing is
  refused rather than dropped.

**And a door for people who do not drag** (`2195053`, board
`UzMC1qdGkxa2JQckXu65tW`, Nick's option B): *or select a folder* under core's
*Select Files*, in the library grid's upload panel and the picker's *Upload
files* tab (both `UploaderInline`). `core/select-folder.ts` wraps
`UploaderInline.prototype.ready`, opens a `webkitdirectory` input, and hands the
files to the panel's uploader as moxie files with `relativePath` — the drop's
path from there. Only for `create`. Its ink is `--ff-accent-text` (5.72:1 at
worst on wp-admin's grey; `TokensTest`). **It puts a file input in core's panel
before moxie's** — a test that takes "the first file input" gets ours; ask for
`.moxie-shim input[type="file"]`.

**Found with it, older than it: an upload made while a folder is selected never
appeared in the grid.** `wp.media.model.Query` watches `wp.Uploader.queue` only
when every query arg is one of seven it knows, and `folderfolio_folder` is not
one — present from the first selection on, `''` included. `showUpload()` and
`redrawUpload()` in `lib/filter.ts` put each upload in the grid it was made in,
and rebuild its tile when it finishes (core builds the tile's `aria-label` once,
as "uploading…").

### Lock, pin and star (tier 2 item 10, `581ea30`)

Board `3ZU8VGkJemznTvKp8tNnvY`; Nick took all six recommendations.

- **A lock protects the shape of a folder and its whole subtree** — no rename,
  move, reorder or delete, nothing created, cut or pasted inside. Files still
  go in and out; colour and *Sort inside* stay allowed (they change how a
  folder is shown, not what it is); a copy comes out unlocked.
- **It is a permission, not a safety catch.** A fifth ability, **Lock**, in
  the roles matrix — Administrator only by default, and `legacyFallback`
  refuses it. Its holders lock, unlock and are not stopped. **WP-CLI is
  exempt**: a CLI run has no user, so otherwise every command would be refused.
- **Enforced once, in the domain.** `Domain\FolderLocks` (meta keys
  `state:locked`, `state:pinned` in `folderfolio_folder_meta`) is asked by
  `FolderService` in create, update (a name change only), move, reorder,
  duplicate (the destination) and delete (the folder or anything locked
  under it). Bulk-create, `getOrCreateByPath()`, the importer and a dropped
  directory all end there. `lockingId()` finds the topmost lock in a path;
  the tree carries `locked`, `locked_by` and `pinned` (`FolderTree::withMarks()`).
  The refusal is `folderfolio_locked`, 403: *"“%s” is locked. Someone who can
  lock folders can unlock it."*
- **A reorder of a locked folder's siblings is allowed** unless the locked one
  itself moved — `lockedFolderMoved()` removes it from both lists and compares.
- **Pin is the site's**, needs Organise, and on a locked folder needs Lock.
  **It is applied by the client**, `sortTree()`, first in its level whatever
  the sort. `planSiblingMove()` will not step across the pinned/unpinned line
  and `clampToPinGroup()` lands a drop in its own group — a step across would
  be saved and never seen.
- **Star is each person's**, in the `folderfolio_rail` preference (`stars`,
  positive ids, at most 100), toggled optimistically by `useRail.toggleStar()`,
  and drawn as a **Starred** group above the tree (`Starred.tsx`, five rows
  then a scroll, each with its parent's name).
- **The ⋮ menu has one strip of three labelled toggles** (`.folderfolio-marks`,
  `menuitemcheckbox`) — after Move down for Organise, at the top otherwise.
  It is 497px unlocked (446 before); a blocked person's is 549 with the *why*
  line. **Marks sit beside the count** (`RowMarks.tsx`) in both renderers; an
  inherited lock is fainter. `locks.ts::isBlocked()` decides for the menu,
  the drag and the keyboard; the server decides again.
- **Found with it**: the content cards came out in the wire's order whatever
  the rail showed (`Content.tsx` sorts now), and `.folderfolio-levels__row`'s
  fixed four-track grid wrapped a fifth part onto a second line (two tracks
  stated, the rest flowing).
- **The matrix with a fifth column** floored at 303.8px in a 274px box at 320.
  Phone heads are sentence case below 520 and 9.5px at 360 and below: 267.8px.

**The ⋮ is for every role since `04c4c7b`** (Nick: each action gated inside
it, and one the role does not hold is hidden, not greyed). `hasFolderMenu()`
in `lib/can.ts` is the one question the row and the narrow control ask; every
rail user can star, so an Author's menu holds Star alone. `star` is a
client-only ability a bundle can withhold — the gallery inspector's tree still
has no ⋮. **Star writes one folder** (`POST /folders/{id}/star`,
`RailPreferences::star()`): the picker's config never carried the stars, and a
Star pressed there had sent a one-item list that replaced all of them.

**Known limits**: a
folder dropped into a locked one by someone without Lock is refused by the
server and the notice says so, with no drop-time affordance; an import into a
locked folder stops at that folder; meta rows outlive a deleted folder
(harmless, never read).

### Download a folder as ZIP (tier 2 item 11, `a6712eb`)

Boards `PpiAmXsixk3sG9yygJQnw5` (five decisions) and `XdRT8n1BYg2Pam9uWnLQ5y`
(stream or build, measured both ways — Nick pushed back on 1 and chose
**stream** after the measurements).

- **`Support\ZipWriter`** writes a Stored ZIP to a callable: each file's CRC
  just before its header (first byte in ms), `length()` exact before a byte is
  written, `$skip` for a range, ZIP64 only when needed, UTF-8 names, directory
  entries. Pure; `ZipWriterTest` reads it back with libzip (the CI job now
  asks setup-php for `zip` — the plugin itself needs no zip extension).
- **`Domain\FolderArchive`** — the manifest: directories for every folder
  (empty ones too, parents first from `FolderRepository::subtree()`), files in
  each folder's own order, a file filed twice appears twice,
  `Domain\ArchiveNames` makes every segment safe to extract (no `/`, no `..`,
  nothing Windows refuses, `(2)` on a case-insensitive clash), paths confined
  to `uploads/` by `realpath`, `read_post` per file, the original upload via
  `wp_get_original_image_path()`, and `not-included.txt` naming what could not
  go in (a file the person may not read is counted, not named). CRCs are kept
  in postmeta `_folderfolio_crc32` as `size:mtime:crc` (deleted on uninstall).
- **`Admin\FolderDownload`** — `admin-post.php?action=folderfolio_zip`, a
  **GET** so a browser's Resume can re-send it with `Range`; nonce per folder.
  Clears every output buffer, `zlib.output_compression` off,
  `X-Accel-Buffering: no`, `set_time_limit(0)` where allowed, `ETag`,
  `Accept-Ranges`, one range (`N-`, `N-M`, `-N`), `If-Range` mismatch → whole.
- **`GET /folders/{id}/zip`** — the summary the rail asks first: files, bytes,
  `confirm` (≥ `folderfolio_zip_confirm_bytes`, 1 GB), `refused` (>
  `folderfolio_zip_max_bytes`, 0 = none), `url`.
- **Client**: `apps/rail/download.ts` — summary, then the notice sheet (which
  can now carry one **action**) above the threshold, then a hidden
  `a[download]` click, so a refused response is a failed download in the
  browser's list, never an error page over the library.
- **Download is the sixth ability** (Administrator, Editor, Author).
  `Settings::withNewAbilities()` gives a matrix saved before it existed the
  default; `save()` stores `abilities` — the columns the form showed.

**Found with it:** the roles matrix with six columns overflowed at every phone
width and at 521–590 — **below 782 it stacks**, one role to a block with each
ability named beside its box (`.folderfolio-matrix__ability`), ability columns
74px above (647.7px table, 45px from name to first tick); **the picker's
config carried ~60 fewer strings than the menus it renders** —
`Rail::strings()` is shared now; **the picker had no notice sheet and its undo
toast opened under the media modal** (z-index 100000 < 160000) — the corner
stack is 170000 in `_frame.css`.

**Not verified:** macOS Archive Utility and Windows Explorer (unzip, 7-Zip,
Python, bsdtar and libzip all read it).

### Folders for posts, pages and post types (tier 3 item 12, `cc05f48`)

Board `KZsHhrffzKQYqUjTvdFszK`; Nick took all ten recommendations. This is
12a–12f; 13 and 14 follow.

- **One tree per object type.** `folderfolio_folders.object_type` was always
  there; `Support\PostTypes` now says which types have folders — media
  always, Posts and Pages by default, anything else ticked under **Folders
  for** (`Settings::post_types`), a type whose plugin is off is kept but not
  enabled — plus each type's base capability (`upload_files`, or the type's
  `edit_posts`: `Capabilities` rule 1) and the statuses that count (media
  `inherit`/`private`; others what `edit.php` calls *All*).
- **REST answers for the folder's own type.** `FolderController::objectType()`
  reads the route's folder, then `folder_id`, then `parent_id`, then the first
  of `ids`; only a request that names no folder reads `object_type` (the tree,
  `/counts`, a folder or list made at the top). Every permission callback asks
  about that type, and every write returns that type's tree — a delete asks
  before the folder is gone.
- **Nothing crosses between trees.** `folderfolio_wrong_type` from create,
  move and `FolderBulk::plan()`; paste and a delete's reassignment refuse the
  other type's folder; `guardAttachments()` takes the folder's type.
- **Counts join `wp_posts` only for post types** — media's queries are
  byte-for-byte what they were. `libraryCounts($type)` gives the fixed rows.
- **edit.php**: `Rail::screenType()` decides for the rail, both bundles'
  config (`Rail::typeConfig()`), the column (`FoldersColumn`, hooked on
  `load-edit.php`), the select (`restrict_manage_posts` with `top`) and the
  list filter. The media picker's bundle stays off a list screen with a rail
  (two writers of `window.folderFolio`). Rows are made draggable. The ⋮ hides
  Download as ZIP, Copy with files, Sort inside › Files; the startup toggle
  and "Move to start / end" are media's. Post screens say "items"
  (`Rail::itemStrings()`).
- **`#the-list` keeps its element** — `refreshListTable()` swaps its rows,
  because `inline-edit-post.js` binds Quick Edit to that element. The media
  list has no Quick Edit, which is why swapping the element had never
  mattered; `post-folders.spec.ts` fails without it.
- **The block editor**: `Admin\PostFolders` + `apps/post-folders.tsx`, a
  document-sidebar panel of checkboxes with its own global
  (`folderFolioPost`), many-to-many. **Add New from a folder**: the rail adds
  `folderfolio_folder` to *Add New*, and `fileNewPost()` files the auto-draft.
- **`deleted_post`** removes a deleted post's rows (skipped before the schema
  exists, so the test bootstrap prints no database error).

**Leftovers closed 24 Sep (`cad65cc`):**

- **A list table beside the rail folds into core's narrow shape by its own
  width** — `lib/list-width.ts` sets `#posts-filter.folderfolio-list--narrow`
  below 700px, and `_content.css` carries core's 782 rules under that class.
  Measured: at a 1000px window the Posts table was 493px with seven columns
  and Title / Folders one letter wide; with a rival's rail as well it is
  ~680px at 1502. **A class, not a container query**: `container-type` made
  the form its own formatting context, which stepped past core's floated
  `.subsubsub` and moved the table 134px right at 1440. The label rule skips
  the primary cell — a `<td>` before WordPress 7.1, a `<th>` since.
- **A post list's rows are not made draggable**; the title link is the
  handle. A draggable row blocked text selection and would have started our
  drag from Premio's own handle.
- **The facade names a tree** (`createFolder(…, $objectType)`, a parent's
  type wins; `findFolderByPath`, `getOrCreateByPath`, `getTree` take one), and
  `getTree($rootId)` finds a root in any tree. `docs/api/README.md` rewritten
  for the REST table, the roles matrix and the real filter names.
- **Coexistence on `edit.php`, measured on the dev site with Nick's OK** —
  Premio (on for Posts / Pages / Media by default) and FileBird (Pages ticked
  under its *Which post types*, then unticked). Both rails render beside ours;
  our in-place filter, Quick Edit and the crumb work; a rival's own folder
  filter **composes** with ours (FileBird submits the form, which carries our
  select; Premio keeps our parameter) and the crumb still names ours.
  **Found, and left as it is — Nick's call, 24 Sep:** a rival's per-row drag does not
  survive our in-place filter. FileBird makes each row a jQuery UI draggable
  and Premio its `.wcp-move-file` handle, once, on load; the rows we swap in
  are new elements. A reload restores both. CatFolders and Real Media Library
  touch media only (read from source).

### Smart folders (tier 3 item 13, `36ae7b9`)

Board `KZsHhrffzKQYqUjTvdFszK`, 13a–13c as recommended: saved rules, every
rule must match, the site's (Organise makes and changes them, *use* reads
them), a *Smart* group under Starred, never a drop target, media first.

- **Rules** (`Domain\SmartRules::FIELDS['attachment']`): type is / is not
  (image, video, audio, document — `application/*` and `text/*`), uploaded in
  the last N days / after / before, uploaded by (a user, or `me` — whoever is
  looking, so one saved view means something different to each person), size
  larger / smaller, folder is none / any / within (the subtree), name contains
  (title or file path). `sanitize()` drops anything unknown and keeps ten.
  Other object types return no rules yet — the engine is keyed by type so 13c
  can add them without a new shape.
- **Storage**: one autoloaded option, `folderfolio_smart_folders` —
  `{next, items[]}`. Names are unique case-insensitively; fifty at most.
- **Applied** in `MediaLibraryFilter::joinFolderAssignments()` after the
  folder clause: `folderfolio_smart` (a saved id, from `$_GET` in list mode or
  the grid's query) or `folderfolio_smart_rules` (already-sanitised rules,
  internal — the count and the preview). A deleted id adds `AND 1 = 0`.
- **Size** needs an index core does not keep: `_folderfolio_filesize`
  postmeta, written on `wp_update_attachment_metadata` and backfilled in a
  two-second budget the first time a size rule is asked (then rechecked at
  most hourly, `folderfolio_filesizes_checked`). uninstall removes all three.
- **REST** `/smart` (GET, POST), `/smart/preview` (POST — the editor's live
  count), `/smart/{id}` (PATCH/POST, DELETE). Each response carries `count`.
- **Client**: `smartId` in `useRail` is exclusive with `selectedId`. The URL
  keeps `folderfolio_folder=` present-and-empty beside `folderfolio_smart=ID`,
  which is what keeps `StartupFolder`'s redirect quiet. The crumb names the
  view; *Clear filter* leaves it. **A link to a smart folder that no longer
  exists falls back to All media and cleans the address bar** (SmartGroup's
  effect), as a deleted folder's link does. The editor's users list is
  `/wp/v2/users`, so it needs `list_users` to name anyone but *me*.

### A Gallery folder kind (tier 3 item 14, `f08cad9`)

Board `KZsHhrffzKQYqUjTvdFszK`, 14a: a folder can be a **gallery** — images
only, marked, listed first by the gallery block. No *Collection* kind.

- **Stored** as one `kind` row in `folderfolio_folder_meta`
  (`Domain\FolderKinds`); a plain folder has none. "An image" is a
  `post_mime_type` under `image/` — the smart folders' *Type* test.
- **`FolderService::setKind()`**: media folders only; refused under a lock
  unless the person may lock; refused while the folder holds a non-image
  (the sentence gives the count); a new gallery with no file order of its own
  opens in Custom. Route `POST /folders/{id}/kind` (Organise).
- **The rule lives in `assignAttachments()`**, which every filing path ends
  in — drag, Add to folder, move, a delete's reassignment, `UploadRouter`, an
  import. All or nothing, like every batch. **An upload is also refused
  before it is stored** (`UploadTarget::refuseIntoGallery()` on
  `wp_handle_upload_prefilter`), so core's uploader shows the reason on the
  file; the type is WordPress's own reading of the file, not the browser's.
- A copy of a gallery is a gallery (unlike a lock); the export carries
  `kind`, and an import of it sets it on folders the run creates, before
  their files are filed.
- **Rail**: the ⋮ has a *Gallery* checkbox row among the icon rows (a tick
  beside "images only"); the row carries a picture glyph in `RowMarks`, first
  of the marks, and "gallery" in its accessible name. **A glyph, not the
  board's word tag** — ~50px in a name track 111px wide at depth 3.
- **The gallery block's picker** lists every gallery above its tree, flat,
  with its parent's name; choosing one reveals and selects it in the tree,
  which sets the block's folder.

**One selected state (`47f1d5a`, then `87d71a1`).** Starred and Smart rows
sat 12px left of All media (their shared list had no inset; the fixed rows'
inset was on their container). Then Nick saw three selected patterns: a tree
row full width with its ⋮ in the ring, All media inset 12px, a smart row
whose ring stopped short of its pencil. The handoff's Selected is a
**flush-left** 3px bar, which only the tree honoured. Now every rail row is
full width with the gutter inside it (`.folderfolio-rail__fixed-row`, 20px);
a selected smart item puts its count and pencil where a tree row's count
and ⋮ are, and its ring is an `::after` layer over both buttons. The picker
keeps its own 8px rows.

### The release track, begun (24 Sep — `ce55b40`, `3ced84d`, `7fb0c73`)

Record: `claude/progress-2026-09-24h-the-release-track-begins.md`.

**Multisite (review #27).** `Plugin::activate($networkWide)` installs every
site (a large network installs on each site's first admin load instead);
`Database\Network` installs a site made later (`wp_initialize_site`, 200) when
the plugin is network-active and adds our tables to `wpmu_drop_tables`.
`Schema::TABLES` is the one list, held to `migrate()` and `uninstall.php` by
`SchemaTablesTest`. **A person's rail is a user option** — per site, because a
star and a starting folder are folder ids and user meta is shared across a
network; the unprefixed row is still read as the fallback. `NetworkTest` runs
under `WP_MULTISITE=1`, and CI has a multisite leg.

**#29.** `build-zip.sh` checks names with `zipinfo -1` (the old `awk '{print
$4}'` saw the first word of a name with a space). `.distignore` is gone —
packaging is `stage-plugin.sh`'s allowlist.

**Plugin Check is 0** (493 findings, 255 errors on its first run) and CI runs
it on the built ZIP. Table and column names in SQL are `%i`; an ignore is one
line, names the sniff and says why. The sniff only knows a variable called
`$wpdb`, so methods using `$this->wpdb` alias it. Two placeholders are
`%1$s/%2$s`; a translator comment is a `/* translators: */` line directly above
the call; one English string with two meanings is `_x()`. `array_is_list()` is
spelled `array_values($x) === $x` (Plugin Check counts it as WP 6.5's
polyfill). No `load_plugin_textdomain()` — core loads wp.org language packs.

**`languages/folderfolio.pot`** (528 strings) is committed, and **CI fails if
it is stale** — regenerate it whenever a PHP string changes:

```
wp i18n make-pot . languages/folderfolio.pot --slug=folderfolio --domain=folderfolio \
  --exclude=assets,node_modules,vendor,tests,tools,design,docs,var,dist,phpstan,public,bin \
  --headers='{"Report-Msgid-Bugs-To":"https://github.com/moustakalis/folderfolio/issues"}'
```

**The readme's two claims are written** — *It never filters your library
unless you pick a folder*, and *Paid elsewhere, free here* (fifteen features,
by feature, never by vendor).

**Answered 25 Sep** (`claude/progress-2026-09-25-the-answers.md`), in build
order: ~~(1) drop the unused `folderfolio_user_preferences` table, and A8 as an
honest repair~~ (`f3bb801`); ~~(2) review #24 as C plus a lead-in label~~
(`deea873`, `Admin\FolderViews`) — inside a folder the
status line reads *📁 In Launch: All (3) | Published (2) | Drafts (1)*, the
links keep the folder, nothing changes without one, the date filter stays
core's (board `JNf58KfGsi2o8qdVU5Jjcf`); (3) the narrow sheet as A — Starred,
Smart, the search line and the folders one scroller, the search line sticky,
*Top level* beneath it, both renderers (board `NF7bQktuksgBSi4rCfoLvf`);
(4) plurals on the client before 1.0; (5) keep `Requires at least: 6.4` and
give it a CI leg; (6) the settings leftovers and tier 1's debts;
(7) `v1.0.0-rc.1` once CI is green on Playground too; (8) screenshots, taken
by Claude in Comet on a clean Playground with CC0 photos, last — then
`v1.0.0` and SVN.

**`WP_List_Table::views()` joins its items with `" |</li>"`** — #24's label goes
inside the first view's markup, as real text, not in an `<li>` of its own. Each
item is `white-space: nowrap`, so the name is capped at `min(16em, 45vw)`, and
clipped with `overflow: clip` — an `overflow: hidden` inline-block sits on its
bottom edge (3.8px low). **The rig's server is `php -d … -S 127.0.0.1:9411`**:
kill it with `grep -F -- "-S 127.0.0.1:9411"`, not `grep "php -S"`, before
re-running `setup.sh`.

Checks at `deea873`: PHPStan clean, unit 173, integration 171 / 171 on one site
and on a network, e2e 121 / 121 (rig), Plugin Check 0. The Playground e2e is
owed before the RC.

Checks at `7fb0c73`: PHPStan clean, unit 173, integration 159 / 159 on one
site and on a network, e2e 119 / 119 (rig), JS unit 61, Plugin Check 0.

### Stress tests

`tests/stress/import.php` and `tests/stress/ops.php`, run with `wp
--user=admin eval-file` against the e2e rig. **They empty every FolderFolio
table** and refuse to run without `FOLDERFOLIO_STRESS=rig`. Results of 23 Sep
(`claude/progress-2026-09-23f-the-stress-tests.md`): the import at its
20,000-folder ceiling runs correctly — 800 batches and 307s, and since
`b8a12ca` (100 folders a call for a file, within 5 seconds) 200 and 159s; a file too
large for `max_allowed_packet` used to be reported as read and is now refused
with a reason (`5f9ca0d`); the attachment guard did a query per file and a
2,001-folder copy with 36,000 files took 7.7s, now 3.8s (`9ba3754`);
`tree()`, a 1,000-sibling reorder and the export need nothing. Integration is
89 / 89 since `cfba873` (76 at the stress tests — an earlier "75" counted a
stray copy of `JsonSourceTest` that existed only in the rig), **98 / 98 at
`581ea30`** (FolderLocksTest), 99 at `04c4c7b`, 105 at `a6712eb`, 113 at `cc05f48`, **114 at `cad65cc`**; e2e 112 / 112; unit 167; JS unit 61.

### Running the integration suite

**It runs in the cloud container since 23 Sep (`500f065`), 75 / 75.**
`apt-get update && apt-get install -y mariadb-server subversion`; start
`mariadbd --user=root &`; create `wp_tests` and a `wp`/`wp` user; `svn export`
`https://develop.svn.wordpress.org/tags/6.8.2/tests/phpunit/{includes,data}`
into one directory, WordPress 6.8.2 from wordpress.org into another, a
`wp-tests-config.php` pointing at both, then
`WP_TESTS_DIR=… php vendor/bin/phpunit -c phpunit.xml.dist`. The first run
failed three tests written that day and never run — all three the test's
fault. The rest of this section is the older route.


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

## The 1.0 plan — coexistence and migration

**Both are in 1.0 scope** (Nick, 21 Sep). The authority is the project doc
`claude/plan-1.0-coexistence-and-migration.md`; the cold-start prompt is
`claude/cold-start-prompt-1.0-blockers.md`. Summary:

**Phase 1 is BUILT AND VERIFIED LIVE** — `aa38f06`, plus `49e0993` for a bug
the live check found in it. Record:
`claude/progress-2026-09-21l-phase-1-built.md`.

*(1.1) The uploader race.* The defect in `9cff4ad` was not the number of
writers: it tested `document.readyState === 'loading'`, which is **false in a
`strategy: defer` bundle** — a deferred script runs at `interactive`, parsing
finished and `DOMContentLoaded` still to come. The listener was never
registered, and the whole re-assertion rode on one `setTimeout(…, 0)` queued
*before* the task that fires `DOMContentLoaded` was queued; which of the two
runs first depends on how long the deferred phase took, i.e. on how many
plugins are active. Now `!== 'complete'`, a retry sequence, `window.load`, and
a re-assert on the first pointer or key event.

*(1.2) The rail resurrection.* Placement is idempotent over **identity**: live
nodes captured once and compared by object, `#wpbody-content` re-resolved every
call, copies swept, a `MutationObserver` on `#wpbody` (which survives a
`.load()` of its own contents). Two things it exposed — `[hidden]` is a *UA*
`display: none` that our own `display: flex` had been cancelling for the life of
the component, and `useLateMount` was watching the very node Premio replaces.

*(1.3) The harness.* `tests/e2e/helpers/bad-neighbour.ts` is a synthetic
neighbour — **injected, not a mu-plugin**, because Playground keeps its SQLite
integration in `wp-content/mu-plugins` and mounting over that directory takes
the database with it. Three guards in `tests/e2e/coexistence.spec.ts`, and the
`posts_clauses` negative control in
`tests/Integration/Admin/MediaLibraryFilterTest.php`.

**Phase 2 is BUILT AND VERIFIED LIVE** — `a1ef706`, `152d0d8`. Record:
`claude/progress-2026-09-22-phase-2.md`.

*(2.1)* The rule is off `#wpcontent` entirely. The seam of library finding 5 is
closed by a negative inline-start margin on the rail, sized from two tokens
`Rail.php` publishes after **reading** that padding: `--ff-rail-pull`, what the
rail takes back (zero when somebody has widened it past core's own), and
`--ff-rail-inset`, what it leaves, which `#wpfooter` must clear. Alone the
geometry matches the old rule to the pixel; beside a neighbour claiming 305px
our rail starts where their band ends.

*(2.2)* Our list-table column is **"Media folders"** — distinct by construction
from Premio's *Folders*, with no runtime rival detection.

*(2.3)* Seven `#wpbody-content .wp-filter` selectors are gated on
`body.folderfolio-has-rail`, so `frame.css` stops reaching core's theme and
plugin browsers on the seven picker screens.

**Phase 3 is DONE** (`c3bdcbb`). `refreshListTable()` no longer replaces
`.tablenav.top`, `.tablenav.bottom` or `.wp-filter .actions` — the three places
other plugins put controls, where a server-rendered copy brings their markup
back and cannot bring their JavaScript back. Narrowed to `.tablenav-pages`; the
filter-bar region was redundant because `syncFilterForm()` already sets our
select. Plus a byte-identical skip and a `folderfolio:list-refreshed` event on
`document`, which is the honest answer to `#the-list`: core offers no event for
"the rows changed", which is why every plugin decorates on ready and never
again.

**Phase 4 is DONE** (`a1124bf`). Nick took **F + C**, the **vendor named**, and
**stance 3 — coexist, then retire**, from board
`claude.ai/artifact/9rqG9ogYRKEvsJt3wHA6oc`. F is the rail's empty state telling
the truth; C is a line under our own plugins row via `after_plugin_row`; the
retire line is the import report's last sentence, shown only while the source
plugin is still switched on. `Modules\Import\Elsewhere` gates on our own
folder count, caches for an hour behind that, and needs no invalidation — an
import creates folders here, so the gate closes before the cache is read.

**What is left.** 1.0 grew on 22 Sep from a finished release into a
fourteen-feature list — the Claude project's `plan-1.0-features.md` is the
authority, and `plan-1.0-tier-1.md` is the detail for the seven the tree
needs. **All seven are built, and tier 1 is done** — six on 22 Sep, the last
and 6b on 23 Sep:

| | |
|---|---|
| 1 · Folder reordering | `10efda8` — drag, <kbd>Alt</kbd>+arrows, and menu items both renderers can reach |
| *The toolbar dissolves* | `abbd720` — every folder action into `FolderMenu`, sort onto the search line |
| 2 · Per-folder sort, folders **and** files | `d529165` `3f3210b` — `Domain\FolderSorts`, `folder_meta`, per-node `sortTree()` |
| 3 · Expand all / collapse all | `8db432c` — wide only; 1,053 folders expand in 620ms |
| 4 · Bulk-create folders | `49fbb68` — `Domain\FolderBulk`, plan then run, on the Tools tab |
| 6 · Export the folder structure | `0888ee5` — `Domain\FolderExport`, format 1, assignments opt-in |
| 7 · Startup / default folder | `e9ea158` — `Admin\StartupFolder`, a redirect and not a default, and it says so |
| 7b · A startup folder of your own | `720ae7f` — `RailPreferences::startup`, a toggle on the breadcrumb row; yours beats the site's |
| 6b · Reading the export back in | `8f5eb69` — `Modules\Import\JsonSource`, found by `Catalog::find()` and never detected; keyed by origin site; files only from the same site |
| 5 · Cut / copy / paste | `cdf932f` — `FolderService::duplicate()` (Duplicate folder, brought forward from tier 2), `Domain\FolderCopy`, a clipboard in `useRail`; *Copy* and *Copy with files* are two rows, Nick's call on board `AU6ezv9WsPVHVk7UmzHNGJ` |

Every folder action now lives in `FolderMenu`, opened from a ⋮ on the
**selected row** above 782px and from one control below it; the global sort and
the expand toggle sit next to the search field — and, since 23 Sep, the
clipboard group: Cut, Copy, Copy with files, and while something is held,
*Inside this folder* / *Beside this folder*. **Cut is `/move`, or `/reorder`
when the destination level is in Custom order; copy is `POST
/folders/{id}/duplicate`**, one transaction, everything that can refuse asked
before it opens. **Left: tier 2** (manual file order first), tier 3, the readme's two claims,
and **then phase 5**, the release track.

**Numbers not to re-derive:** FileBird silently hides **28 of 47 files** when
active; our `posts_clauses` bail is **load-bearing** and three of four rivals
lack it; **FolderFolio + Real Media Library is the only clean pair of ten**.

## Things that will bite you

**Core's media Query does not show uploads for a query it cannot filter.**
`media-models.js` observes `wp.Uploader.queue` only when every arg is one of
`s, order, orderby, posts_per_page, post_mime_type, post_parent, author`. Our
folder parameter is not, so a grid on a folder never showed an upload until
`lib/filter.ts`'s `showUpload()` (tier 2 item 9).

**`window.folderFolio` has several writers, and they merge** (`133e6c8`).
Every screen prints its config through `Support\ClientConfig::script()`:
scalars first-writer-wins, `i18n` a union. Before that each printed `x = x ||
{…}` and on the block editor the gallery block's won — its `create / rename /
delete: false` made the picker beside it read-only for an administrator,
untranslated, and a dropped directory uploaded flat. **An ability in the
config is a fact about the user; a screen that offers less narrows its own
bundle** — `restrictAbilities()` in `lib/can.ts` (each esbuild bundle has its
own copy of the module). `block-editor.spec.ts` asserts both halves.

**`window.folderFolio` and `window.folderFolioRail` are different objects.**
`Rail::config()` writes the second — the chrome script's own globals, width
and the drag's bounds. Everything the React app reads is `appConfig()`. A key
added to the wrong one is present in the file, ships alongside strings from
the same edit, and is simply absent on the page.

**TanStack Query drops `mutate()` callbacks for an unmounted observer, without
a word.** A menu closes on press, and the menu is what owns the mutation — so a
paste moved the folder and never opened the destination. Use `mutateAsync()`
and its promise, which belongs to the mutation (`paste.ts`).

**The cascade trap has now bitten four times.** The ⋮ menu's own
`position: fixed` tied with `.folderfolio-menu { position: absolute; top:
100% }` in `_toolbar.css` and lost on import order from `abbd720` until 23 Sep.
Downwards, the hook's inline `top` hid it; upwards it set only `bottom`, and the
menu was a 10px sliver at the bottom of the window. **A panel that flips is two
layouts — test the flipped one.**

**A control the server printed once is a snapshot.** The list table's folder
`<select>` is rendered at page load and never re-rendered — `refreshListTable()`
leaves `.wp-filter .actions` alone on purpose — so until `d0f617d` a folder
created since had no option, and core's *Filter* button submitted the previous
folder. `useNativeFolderSelect(nodes)` now rebuilds the options from the tree
when they differ.

**Every refused rail write is on screen** since `d0f617d`: one `MutationCache`
in `rail.tsx`, a notice sheet in the undo toast's corner. Do not add a per-hook
`onError` to display a failure — the cache already does. **But a write that is
not a mutation escapes it**: the deferred delete was silent on failure until
`1ffcd89`, and now calls `showNotice(errorMessage(e))` itself. The media picker
has its own client and no notice sheet yet.

**An id is only an id where it was issued.** An export's folder ids key
provenance by the origin site (`ff-file-<hash>`), and its attachment ids are
applied nowhere but that site — the same number elsewhere is a different file.
And **the file source is not a catalog entry**: `Catalog::all()` is what gets
*detected*; `JsonSource` is found by `Catalog::find()` as a fallback.

**Count what the sentence claims.** The undo toast said a folder's `count` had
"moved to Unassigned"; under many-to-many only `only_here` — its files filed
nowhere else — does (`1ffcd89`).

**`jsxs` is not `jsx`.** The compiler packs static siblings into one
`props.children` array; `createElement` validates only children passed as
arguments, so `shims/jsx-runtime.ts` spreads them — otherwise every static
sibling list warns as an unkeyed one under `SCRIPT_DEBUG`.

**The inherited badge counts files, not assignments** (`d0f617d`,
`FolderTree::overcount()`), and the plain sum travels up separately: correcting
against children's corrected totals subtracts once per level. **A negative
control has to overlap where the bug lives** — the first test for this put its
overlap at one level, where once and twice agree, and passed the reversion it
was written for.

**One slot means one winner.** `wp.Uploader.prototype.init` is a single
extension point and FileBird, CatFolders and Premio each *assign* it without
chaining. Wrapping politely does not protect you, and **`strategy: defer` runs
before `DOMContentLoaded`**, so we lose by construction unless the wrap is
re-asserted late. Lifecycle stage beats enqueue order.

**A boolean guard on a patch you do not own is a bug.** `wrapped = true`
answers "did I ever wrap?" when the question is "is my wrapper still
installed?".

**A guard can keep passing while the thing it guards stops being true.**
The readme's claim — *never filters your media library unless you pick a
folder* — is defended by a negative control on `posts_clauses`. The startup
folder was one obvious implementation away from making that claim false with
the test still green: defaulting an absent query var inside
`MediaLibraryFilter` would have set the folder *before* the filter ran, so the
filter would have behaved correctly on a request that had already been changed
underneath it. `Admin\StartupFolder` is a redirect for that reason and touches
no query. **When a test asks a component, check that the component is still
the thing that decides.**

**A grid track never shrinks below its content.** The breadcrumb row is
`minmax(0, 1fr) auto` — path, then controls — and without the `minmax(0, …)`
the path would refuse to shrink and push the controls out of the box instead.
The rule the arrangement exists for: **the path may be clipped, the controls
never are.** The × it replaced sat inside the list, so a deep enough path
could scroll the way out of a filter off the end of the row.

**A colour is a pair, and the pair is tested.** `--ff-danger` is a *fill* — the
one drawn behind white ink — and `--ff-danger-text` is its ink; `--ff-off` is
the inactive-control token, which is the one thing WCAG 1.4.3 exempts by name,
and nothing live may use it. `tests/Unit/Design/TokensTest.php` reads every
rule in `assets/src/core/*.css` that paints from a token and checks it against
the ground it lands on in nine admin schemes. Both of 22 Sep's contrast bugs
looked completely fine on screen and were caught only there, so **run
`vendor/bin/phpunit -c phpunit-unit.xml.dist` after any colour change**.

**`admin.css` imports `_row.css`, then `_rail-chrome.css`, then
`_toolbar.css`.** A rule written in the first two that merely ties on
specificity with a `.folderfolio-menu*` rule in the third loses on source
order, and nothing errors — it just renders the other value. Out-specify, do
not match.

**Verifying against one instance of a hazard is not verifying against the
hazard.** The uploader fix passed FileBird, passed CatFolders, and failed both
together.

**A node coming back is not the same as a node surviving.** Premio's `.load()`
re-fetches our shell, so `getElementById` finds a rail and `offsetHeight` is
non-zero — and the React root inside it is empty. **Check for the mounted app,
not the element.**

**Winning a CSS fight with another plugin can be the bug.**

**A rule you add can tie and still lose, and the tie-break is file order.**
`admin.css` imports `_row.css`, then `_rail-chrome.css`, then `_toolbar.css`.
Three separate rules were written in the first two on 22 Sep and all three
silently lost to a same-specificity rule in the third: the row menu's
`position: absolute` (beaten by `.folderfolio-row > *:not(.folderfolio-row__guide)`,
which is 0,2,0 against a bare class's 0,1,0, leaving the button a flex item
50px from the row's edge and 4px over the count); the sort menu's `right: 0`
(beaten by `.folderfolio-menu { left: 0 }`, so it hung 120px over the
library); and Delete's colour (beaten by `.folderfolio-menu__item`, so it
rendered in `--ff-ink`). **Out-specify, do not match** — and none of the three
showed up as an error anywhere, only as a measurement that disagreed with the
stylesheet.

**`requestAnimationFrame` never fires in a backgrounded tab, and a probe that
awaits one reads as a frozen renderer.** Comet is backgrounded for most of a
session — `document.hidden` is true — so `await new Promise(r =>
requestAnimationFrame(r))` hangs forever, CDP times out at 45 seconds, and the
tool reports *the renderer may be frozen or unresponsive*. That is the
instrument, not the page: three separate measurements of expand-all read as
45-second freezes and the real number was **620ms**. Measure a React render
with a `MutationObserver` on the container instead — and note that a discrete
click is **not** flushed synchronously, so the timestamp after `.click()` is
before the render, not after it.

**When the window will not resize, an iframe is a real viewport.** A
full-screen macOS window ignores the extension's resize — it reports success
and nothing moves — which blocks every narrow check. An iframe of the same
admin page at `width: 600px` is a genuine 600px viewport: same origin, same
cookies, same bundle, and `matchMedia` inside it reports the *frame's* width,
so `useIsNarrow()` returns true and `Levels` mounts for real. That is how the
narrow half of *Move up* / *Move down* was verified. Its one limit is
geometry — `getBoundingClientRect` inside an offscreen frame returned zeroes,
so use it for behaviour and measure layout in a window you can actually see.

**`document.hidden` belongs in every layout probe.** A backgrounded tab reports
`innerWidth: 0`, flipping every media query to its narrow branch, so the
component renders its phone layout and the numbers describe a viewport nobody
is looking at.

**A zero that matches your prediction is the most dangerous measurement there
is.** Confirm the fixture can produce a non-zero first — the 1,050 stress
folders are all empty and several are named with trailing numbers.

**Our `posts_clauses` bail is load-bearing.** It returns the clauses untouched
when `folderfolio_folder` is unset, and it is the only reason installing
FolderFolio cannot blank another folder plugin's library. **Do not simplify
it**, and it needs a negative control it has never had.


**`wp.Uploader.prototype.init` is a single slot, and wrapping politely does not
protect you.** FileBird, CatFolders and Premio each assign it without capturing
the previous value, so a later plugin discards your wrapper with no error. We
lost this race by construction — `strategy: defer` runs at parse-complete,
they patch at `wp.domReady` / `DOMContentLoaded`, which is afterwards.
**Closed in `aa38f06`**: the guard is the wrapper's own identity
(`folderfolioUploadTarget`) rather than a boolean, and the wrap is re-asserted
from a `DOMContentLoaded` listener, a retry sequence, `window.load` and the
first user interaction. `9cff4ad` got the identity guard right and the timing
wrong; see the two traps below. Verified live against FileBird **and**
CatFolders together. See `claude/progress-2026-09-21l-phase-1-built.md`.

**`strategy: defer` means `readyState === 'interactive'`, not `'loading'`.** A
deferred script runs after parsing and before `DOMContentLoaded`, so a
`'loading'` test inside one is false on every page load and the listener it
guards is never registered. Test `!== 'complete'` — the only state in which the
event has already fired.

**A macrotask queued before `DOMContentLoaded` is queued may run before it.**
The ordering between the timer queue and the task that fires the event is not
specified, and in practice it depends on how long the deferred-script phase ran
— which depends on how many plugins are active. A race whose outcome varies
with the rest of the site looks exactly like a fix that works.

**`[hidden]` is a UA rule, and your own `display` cancels it.**
`.folderfolio-rail { display: flex }` beat the attribute for the life of the
component. If `hidden` is load-bearing, say `display: none` in your own
stylesheet.

**A placement test that describes half the arrangement re-runs for ever.**
`rail.nextSibling !== content` is false as soon as the handle sits between them,
so `place()` re-inserted on every call — harmless while something called it
twice a page, an infinite loop the moment a `MutationObserver` did, and a frozen
renderer on `upload.php`. Write the test against the finished state, **and make
the repair deaf to its own mutations** so being wrong again cannot freeze a
page.

**`npm install` is not `yarn install`.** This is a Yarn 4 project
(`packageManager: yarn@4.18.0`, `.yarnrc.yml`); npm ignores `yarn.lock` and
silently resolves a different tree. The first symptom was somewhere else
entirely — a Playwright browser binary that no longer existed.

**A zero that agrees with no prediction is still a zero.** `themes.php` on the
dev site renders no `.wp-filter` at all, so the `frame.css` leak measured as
"nothing". The rule was reaching it all the same — injecting a `.wp-filter`
into `#wpbody-content` by hand produced `container-type: inline-size` and our
grid on core's element. **Build the element the rule needs before concluding
the rule is harmless.**

**`position: absolute` does not inherit the padding you are reasoning about.**
`#wpfooter` sits inside `#wpcontent` but resolves against an ancestor further
out, so its box does not move when that padding changes. One term had to be
added to its indent and another had *not* to be subtracted, and both were found
by measuring — after the footer text landed on the 5px resize handle.

**The fixture you need may not be a state your dev site can be in.** F and C
are gated on this library having no folders of ours, and the dev site has
1,053. Both halves still got a real proof — the gate *closing* on the dev site,
the gate *opening* in a WordPress booted for the purpose with
`wp-playground-cli run-blueprint` and a `runPHP` step (whose stdout is only
surfaced on a **failed** step, so the probe ends in `exit(1)`). When the two
states cannot coexist, prove them in two places rather than reasoning about
one.

**Write the comment the measurement supports, not the one that sounds right.**
The list refresh's no-op guard was first commented as "sorting links differ, a
bulk-action row matches". Measured: on a folder change nothing matches, because
every region left in the list embeds the query string in a link. The guard is
still worth having — for the same-URL refresh, which is the one that happens
while somebody is working.

**Fixing an empty state means finding every empty state.** The rail has two
renderers — `Tree` above 782px and `Levels`, the drill-down sheet, below — and
each had its own "No folders yet". Fixing `Tree.tsx` alone left the untrue
sentence shipping at every width where the rail is a band. A component that
answers "what if there is nothing here" is rarely the only one.

**To see an empty state on a library that is not empty, empty the cache, not
the database.** Walk the fiber tree from the app's mount point to the
`QueryClient` and `setQueryData(key, [])`. Nothing is written and a reload puts
it back — which is how F was looked at without deleting the 1,050 stress
folders.

**A boolean guard on a patch you do not own is a bug.** `wrapped = true`
answers *"did I ever wrap?"* when the question is *"is my wrapper still
installed?"* — and it had silently killed the existing re-check on every folder
change for the life of the module. Stamp the function and test for it.

**A zero that matches your prediction is the most dangerous measurement there
is.** A folder rendering 0 files looked exactly like the predicted blank
library; the 1,050 stress folders are all empty and that one was named
*"Campaigns 45"*. **Confirm the fixture can produce a non-zero before believing
a zero.**

**`strategy: defer` runs BEFORE `DOMContentLoaded`, not after.** Enqueueing
late does not make your JS run last — lifecycle stage beats enqueue order.

**Registering at plugin-include time beats every hook priority.** Premio
instantiates in its plugin file rather than on `plugins_loaded`, so it is first
on every shared hook whatever the activation order. Hook priority only orders
callbacks already registered when the hook fires.

**Our `posts_clauses` filter bails when `folderfolio_folder` is unset, and that
is load-bearing.** It is the only reason a second folder plugin's library does
not go blank when ours is installed. Three of four rivals have no such bail and
two of them force a folder on `upload.php` with no user action. **Do not
"simplify" it**, and it deserves a guard with a negative control.

**Premio destroys every other plugin's rail on an ordinary click** —
`jQuery("#wpbody").load(url + " #wpbody-content")` in list mode. Our rail is a
child of `#wpbody` and is placed once by an inline script, so it does not come
back until a reload.


**`uninstall.php` is usually a stub.** Do not assume delete-and-reinstall
resets a plugin. All four folder rivals leave every option row and every table
behind. Read the file before proposing a reinstall.

**A plugin's first-run state is in `wp_options`, never in its data tables.**
Dropping tables destroys the fixture and resets nothing — and can *create*
first-run UI as a side effect, because some gates are a live row count rather
than a flag.

**A redirect does not have to be a redirect.** RML's is
`<meta http-equiv="refresh">` from `admin_head`: no network log entry, no
`wp_redirect` grep hit. When a behaviour is gated, the browser can only tell
you the state of the gate, not the behaviour — read the source.

**A plugin activated once before does not tell the truth about its first run.**
Establish per gate whether it re-arms (Premio: every activation) or is spent
(RML: one-shot) before trusting a live result.


**A notice is not always an `admin_notices` notice.** RML renders its two
alerts from its own React app inside its rail, so a `#wpbody-content` sweep for
`.notice` counts **zero** while the screen plainly has two, costing 210px.
Count wp-admin notices and in-component notices separately, and look at the
screen.

**Read a redirect from the network log, not the address bar.** After a plugin
activation on this site `location.search` shows `plugin_status&paged&s` with
**no `activate` param** — something rewrites the URL after load. The network
log shows core's real chain. Inferring "no redirect" from the visible URL is
right only by luck.

**A disabled button is a DOM fact, not a colour.** RML's paywalled import
buttons carry a real `disabled` attribute; a merely grey style would have
looked identical and meant something else.

**`javascript_tool` refuses a result that looks like a query string.** It
returns `[BLOCKED: Cookie/query string data]` when the value contains
`?k=v&k=v` or a nonce-shaped token. Return `location.pathname` and an array of
param **keys**, never a rebuilt query string.


**A guard with an early `continue` can assert nothing and still be green.** The
source-row guard was written against `row.querySelector('button')`, but a
source row renders a button only when the source **holds data** — and the e2e
harness installs none of the four competitor plugins, so every action there is
a `span.folderfolio-source__none` and every row was skipped. It passed with its
own fix reverted; the negative control came back **3 failed, not 4**. Assert on
the element that is always there, and on the **declared tracks**
(`grid-template-columns`), which is what the shape actually rests on. **Count
negative-control failures against the number of fixes you reverted.**

**A flat pixel width against a proportional container floors rather than caps.**
`.folderfolio-status th` was `width: 220px`: at 521px of viewport the table is
449px, the label took **228** and the answer **221**, and the label won in a
**521–528px band** eight pixels wide that the manual sweep had measured and not
questioned. `min(220px, 38%)` is true by construction at every width. **Write
the rule so the property holds everywhere, not at the widths you sampled.**

**The unit suite needs its own config.** `php vendor/bin/phpunit -c
phpunit-unit.xml.dist` — the bare `phpunit` picks `phpunit.xml.dist`, which is
the *integration* suite, and dies on a missing `wordpress-tests-lib`. PHPStan
is `php vendor/bin/phpstan.phar`, not a phar in the repo root.


Every one of these cost real time and is now load-bearing somewhere.

**`clientWidth` includes padding, so it is the wrong box to measure a child
against.** The roles matrix was declared to fit a 320px screen on a
`clientWidth` reading: it was 277.2 in a content box of 248, running 29.2px
past the content box and 4.2px past the card's own border, with the page not
scrolling because the card's 24px of right padding absorbed it. Use
`clientWidth − paddingLeft − paddingRight`, and remember that **a page which
does not scroll sideways is not proof that a child fits its parent** — only
that the overflow was smaller than the padding around it.

**A guard can stop guarding without failing.** Finding 11's wrong pair —
`--ff-on-sel` on `--ff-bar` — stopped failing the contrast test the day the
theme rework made `--ff-on-sel` `#fff` in every scheme: white on `#1d2327` is
15.89:1. The rule it stood for was unenforced for a week and the suite stayed
green. **Re-run the old negative controls after a system-wide change, not just
the new one** — and when a rule is semantic rather than numeric, assert it by
name.

**A screenshot is evidence of what was painted, not of what was painted with.**
A `var()` that resolves to nothing computes to `transparent`, and a 0.66-scale
JPEG cannot tell `#f0f0f0` from `#fff`. When the change is a colour, read the
colour.

**A custom property is only visible to the element it is scoped to and its
descendants.** `#wpcontent` is an *ancestor* of `.folderfolio`, so a token
declared on the component is invisible to any rule painting the page around it.

**Rendering the document with the scheme is only half of it — the stylesheet
has to land where core's did.** The scheme's `colors.min.css` must *replace*
core's `<link id="colors-css">` in place. Injected at the top of `<head>` it
loses the cascade to core's later sheets: Light then renders with Midnight's
menu (`#1d2327`) and a `#f0f0f1` canvas instead of `#f5f5f5`, and a contrast
sweep comes back clean against the wrong document. Verify the swap took by
reading `#adminmenuback`'s background, not by trusting the body class.

**The extension's synthetic click does not always dispatch a DOM click event.**
A capture-phase listener on the document recorded nothing for three clicks that
the tool reported as successful and that `elementFromPoint` confirmed were on
the button. Before reporting that a control does nothing, prove the event
arrived — then exercise the handler directly (`el.click()`) to test its
branches.

**`overflow-wrap` is not inherited from a sibling stylesheet.** `_wizard.css`
declares `overflow-wrap: anywhere` twice; `_settings.css` declares it nowhere,
and a single unbreakable role name takes the roles matrix from a 272px floor to
**458.4px**, with 161px of page overflow at 320. **A width floor measured with
the fixture's own strings is a floor for those strings.**

**A guard whose scope is a hand-written constant guards only that constant.**
`TokensTest::INK` lists four tokens. `color: var(--ff-off)` appears at eight
sites across four stylesheets and is `#8c8f94` on the panel — **3.24:1** — and
nothing has ever checked it. Derive the list from the stylesheets.

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

**A function present in a bundle is not an ungated feature.** FileBird's free
build ships `updateFolderColor` and `downloadFolder` in full, with working REST
plumbing behind both, and `Tree.php` returns a `color` for every folder. On a
capability grep both read as free. **Both menu triggers carry
`disabled: true`.** The same component serves both tiers, so the gate is the
attribute on the trigger, an unregistered route, or a thrown exception — never
the absence of the code. Grep for the gate, not the capability.

**A vendor's own page can under-report its own paywall.** Real Media Library's
"PRO vs Free" page lists five paid features and was last updated two years ago
against version 4.7; the installed build is 4.23.4 and the source carries
**eight** gates. The startup folder, the picker's tree view and recursive
upload appear on no page anywhere. And one feature the page sells as PRO —
**export** — is registered unconditionally in `inc/rest/Reset.php` and works in
Lite. Read a vendor page for what the free build cannot contain, never for what
it can.

**A comparison table shipped inside the plugin is still marketing.** Premio's
`templates/admin/upgrade-table.php` is a 700-line pricing page inside the
plugin, and FileBird ships a 47-row `{feature, pro, free}` array in its own
bundle. Both are vendor claims that happen to live in the repository. Read them
for the feature **names** — a free list of what the vendor thinks is worth
money — then check each one against its gate. Their `readme.txt` files are the
better source still, and the only good one for a feature the free build does
not contain at all.

**`$wpdb->query()` on an UPDATE returns MySQL's affected-rows, and MySQL does
not count a row whose value did not change.** `applySortOrder` first returned
it: arranging three folders that were all still at the default `0` reported
**2**, because one of them was already in position. Any count built from that
number depends on what the previous state happened to be, which is not a
contract anything should rely on — return the size of the thing you wrote.

**A document-level listener in the capture phase sees every event on the
page.** `drag.ts` now carries two payload kinds, files and folders, and both
are dragged in the same document. Whichever check runs second nulls the
other's payload the moment it fails to match — so the folder check runs first
and the comment says why.

**HTML5 drag events are never fired by touch.** `drag.ts` and `useDropTarget`
are built entirely on `dragstart` / `dragover` / `drop`, so the whole drag
layer is desktop-only by construction. That is a scope fact rather than a
defect, and it has been true of dragging *files* onto a folder since that
feature shipped — unremarked anywhere until now. It is why `Levels`, the
renderer that exists for the phone, was not given the gesture.

**"Does touch get this?" is usually the wrong question — ask which renderer
has it at all.** Reordering was framed as a touch gap for a day. `Levels.tsx`
imports nothing from `drag.ts`, `folder-drop.ts` or `useDropTarget.ts` and has
no key handler, so the gap was *every input below 782px*, mouse and keyboard
included. The two renderers share exactly one folder surface — the toolbar,
which `Rail.tsx` renders above the narrow/wide switch — and that is where an
action belongs when it has to exist everywhere. Check which component draws at
the width before costing the fix.

**In `Levels`, tapping a folder with children selects it *and* walks into it.**
So "the selected folder" can be the header rather than a row, with its siblings
one level back and off screen. An action on the selection can then succeed and
appear to do nothing. `FolderMenu` steps out to the parent before moving the
level it is standing in; `levelId` is null in the wide tree, so the same code
is inert there.

**A saved smart folder has to be in the query cache before it is
selected.** `SmartGroup` lets go of a selected id its list does not have (a
dead link). `useSaveSmart` writes the saved folder into the cache in
`onSuccess`, before the editor calls `selectSmart()`; with only an invalidate
there, a fresh save would be selected and dropped in the same frame.

**The rig's attachments have `post_author` 0.** A rule on *uploaded by me*
matches nothing in the container suite; `smart-folders.spec.ts` filters by
name and type instead. The author rule is covered in `SmartFoldersTest`.

**A role query skips what is hidden.** On a fresh user the block editor's
sidebar sits behind its welcome guide, so `getByRole()` finds nothing inside
the inspector even when the markup is there. `gallery-kind.spec.ts` and
`block-editor.spec.ts` use CSS locators and dispatched clicks there.

**Never commit a patch to the device in the same batch of calls that writes
it.** The calls run together; on 24 Sep the device received the previous
version of `item14-b.patch` and `git apply` refused it. Write, then send.

**A sentence is a claim, and claims go stale.** On 24 Sep the settings
screen still promised a × the crumb row lost a month before, the readme
described 19 Sep's plugin, the no-JavaScript notice named a CLI command that
never existed, and `uninstall.php` kept five things the readme said it
removes (`1fec1b2`, `64193c4`). Three guards now read the source:
`SettingsCopyTest` (the Opens in sentence names the rail's own button
labels; the Status counts per tree), `UninstallTest` (every stored key
constant is removed on uninstall) and `ScreenStringsTest` (every `t()` string
is in a PHP i18n map and its English fallback is that string). **When a
feature changes a control's name or a rule, grep the copy for the old one.**

**`wp folderfolio import` exists since `b4bd033`** — the wizard's engine
from a terminal (`list`, `preview`, `run`, `resume`, `status`, `stop`,
`undo`; a source key or an export file's path). Filing needs `--user=<login>`
because each file is checked against a person; a CLI run has none. The
container can run it: `php /tmp/wp-cli.phar --allow-root` in `~/wp-e2e`
(fetch the phar from github.com/wp-cli/builds). **A WP-CLI option's
description is one `: ` line — a second `: ` line is read as synopsis**
(`CliHelpTest`).

**The facade, WP-CLI and REST reach what the rail does since `dda2580`** —
organising (duplicate, reorder, colour, sort, file order, lock, pin, kind),
smart folders, export, a ZIP written to a file, the two repairs, and the
settings (`wp folderfolio settings get|set`, `GET/POST /settings`, through
`Settings::change()`: the form's sanitiser, refusing what it would quietly
replace). Seven more hooks. **`docs/api/README.md` is held to the code** —
`ApiSurfaceTest` checks every facade method, hook and CLI command is on it,
`RouteDocsTest` checks the REST table against the registered routes both
ways — so adding any of those means adding its line. Every refusal is
`{success: false, error: {code, message}}`, the import routes included; the
old `/attachments/assign|unassign|bulk-move` and `/tree` aliases are gone
(the rail calls `/assignments` and `/assignments/move`). **`--json` is
WP-CLI's own flag** (it becomes `--format=json`), so `settings set` takes
`--values`.

**A straight apostrophe inside a single-quoted PHP string is a parse error
that takes the whole plugin down** — the integration run and all 116 e2e
failed on "file's". The plugin's copy uses ’ throughout; so should a new
string.

**An outline on a parent is painted under a positioned child.** The smart
item's outline computed as solid and showed only round the pencil: the row
button is `position: relative`. Draw a ring that has to cover children as a
positioned `::after` above them — and look at the screen, not the computed
style.

**`device_commit_files` can report "written" and leave the old file.** A
forced re-send of `selected-state.patch` on 24 Sep kept the 154-line version.
Send a changed file under a new name, and check its checksum on the device
before applying it.

**A test that counts a class across a whole menu breaks when the menu gains
a row that is not in its section.** `rail.spec`'s sort-panel test counted
every `.folderfolio-menu__value`; the Gallery row has one too. Scope a count
to the rows it means.

**Inside a WordPress test every CREATE TABLE is a temporary table**, and
`SHOW TABLES` does not list those — ask `SHOW COLUMNS FROM`. **Making a site
commits** (its tables are DDL), so undo a network option after
`parent::tear_down()`, not before. **A network shares one user's meta across
every site** — anything per user that names a site's object is a user option.

**Anything added above the rail's search line takes height from the folder
list.** On the narrow sheet (capped at 75dvh) Starred and Smart left it 31.8px
at a 669px-tall window.

## Which document is which

| Document | What it is | Still authoritative? |
|---|---|---|
| `../DESIGN-TO-CODE.md` | The design handoff: screens, measured geometry, states, keyboard and ARIA contract, definition of done | **Yes — the UI spec** |
| `architecture-plan.md` | Decisions, data model, REST surface, importers, developer API, gallery block, the ten phases to 1.0 | **Yes — the roadmap** |
| `m2-importer-matrix.md` | Verified schemas and detection keys per migration source | Yes, when the importers are rewritten |
| `research/01..04-*.md` | FileBird, Real Media Library, Folders, CatFolders — measured live and read from source | Background, and the reason for several decisions |
| `deep-review-2026-09-16.md` | 29 numbered findings against 0.2.0 | Partly — #24 is the one still open (decided 25 Sep: C plus a lead-in label; to build); #26–#29 closed, #27 and #29 on 24 Sep |
| *(Claude project)* `plan-1.0-features.md` | The fourteen features 1.0 grew to hold, in three tiers | **Yes — the authority for scope** |
| *(Claude project)* `plan-1.0-tier-1.md` | The seven the tree needs, with the source read against each; six built, one left | **Yes — the brief for the current work** |
| *(Claude project)* `progress-2026-09-22e-the-answers.md` | Nick's answers, and the features they put into 1.0 | Yes |
| *(Claude project)* `progress-2026-09-22d-the-paywall-read.md` | What the four rivals gate behind a licence, the two piles, and the cut line | Yes — its *Awaiting Nick* section is superseded by `…-22e` |
| *(Claude project)* `progress-2026-09-22c-f-validated.md` | The rail's empty state, looked at — and the second renderer nobody had looked at | Yes |
| *(Claude project)* `progress-2026-09-22b-phases-3-and-4.md` | Phases 4 and 3, and the 1.0 coexistence plan closed | Yes |
| *(Claude project)* `progress-2026-09-21g-the-source-read.md` | All four competitors' codebases read; three live findings corrected | Yes — the basis of the coexistence work |
| *(Claude project)* `progress-2026-09-19b-thousand-folders.md` | The 1,050-folder stress test, the searchable picker, the drill-down sheet | History |
| *(Claude project)* `progress-2026-09-19-responsive-toolbar.md` | `readme.txt`, the narrow toolbar, the phone band | History |
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
