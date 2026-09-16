# Competitive teardown 02 — Real Media Library (Free) 4.23.4

Same playground, FileBird deactivated (its tables left in place deliberately). 41 files,
7 folders created via its REST API.

## First run

Activation **redirects to its own welcome page**, whose primary CTA — above the feature list —
is a blue **"Get your PRO license now!"**. The first thing the plugin does after being installed
is ask for money.

## The rail

Header reads **"Folders"**, not the product name. Small thing, better than FileBird's branded
header: it labels the function rather than the vendor.

Below it, a toolbar of **seven icon-only buttons** — settings, move, refresh, rename, delete,
sort, overflow. No labels, no text. Compact, but nothing is discoverable without hovering each
one.

Then **two stacked notices**, together about 180px tall, sitting above the tree:

1. *"It looks like you have already used another plugin for folders in the media library.
   **Start importing** · Dismiss"*
2. *"Thanks for using the free version of Real Media Library. Learn more about PRO ·
   **Hide for 30 days**"*

On a laptop that pushes the actual folder tree most of the way down the rail. The upsell can
only be hidden for 30 days, not permanently.

Tree rows are styled as **links** — blue text, blue outline folder icon, ~28px tall. Reads as
navigation rather than as a list of objects, which is a different stance from FileBird's
grey solid icons and 36px rows. The rail has both a **drag-to-resize handle** and a collapse
chevron.

Fixed rows: **"All files 41"** and **"Unorganized 41"**, both with count pills. User folders
show **no count at all** in Lite.

## The detection notice is the thing to steal for M2

RML noticed FileBird's leftover tables and offered migration **in context, in the sidebar,
the moment you arrive** — not on a separate Tools page you have to find. That is a better
shape than our current Media → Import submenu.

Its routes show how seriously they take it: `/import/filebird`, `/import/mlf`,
`/import/taxonomy`, plus `/notice/import` driving the banner itself.

The execution, though, is a lesson in what not to do. Clicking **Start importing** opens a
consent wall: *"To use all advantages of Real Media Library (Free) you need a free license"*,
with four checkboxes — auto-updates (**pre-checked**), transmit technical data, telemetry, and
a newsletter opt-in. Accept is a large blue button; decline is a small text link labelled
*"Continue without any support and without e.g. discount announcements"*.

So the migration path — the thing that wins a user away from a competitor — is behind a
registration and telemetry gate, worded to make declining feel like a loss. **Our wizard should
just work, with no account, no telemetry, no gate.** That is a real differentiator and it costs
us nothing.

## The paywall, observed

I asked the REST API for a folder `Logos` with `parent: 1` (inside `Brand`). It returned
`parent: -1`, `absolutePath: "logos"` — created at root. **No error, no warning, no upsell.**
The tree then renders `Brand` and `Logos` as siblings.

Their own source says so out loud:

> ignores the `parent` parameter in Lite version as creating subfolders is no longer supported

Two takeaways. For design: a paywalled feature that silently rewrites your input is worse than
one that refuses loudly — the user thinks they made the folder and only later notices it is in
the wrong place. For M2: **an RML Free tree is always flat**, an RML Pro tree can be deep, and
`absolute` gives us the full path so we can reconstruct hierarchy cheaply either way.

## Architecture worth borrowing

- **Materialised path.** Every folder stores `absolute` (`brand/logos`), so "everything under
  X" is a `LIKE 'brand/%'` prefix match instead of recursive traversal. Cheap descendant
  counts, cheap subtree moves. Worth considering if our counts get slow.
- **Counts live in their own endpoint** (`/folders/content/counts`), not in the tree payload.
  FileBird does exactly the same. Two independent implementations reaching the same conclusion
  is a strong signal that we should split counts out too, rather than doing a `GROUP BY` inside
  `/tree`.
- **A `/reset/*` repair suite** — `count`, `folders`, `order`, `relations`, `slugs`. Someone
  built five separate "fix the data" endpoints, which tells you what the support inbox for a
  folder plugin looks like. Worth planning a single integrity-check tool rather than
  discovering the need later.

## No URL state — again

Selecting a folder leaves `location.search` at `?mode=grid` and `history.state` untouched.
That is now **both market leaders** with no deep-linking, no browser back, and nothing
shareable. Our `?folderfolio_folder=` plus `replaceState` is a genuine, if quiet, advantage —
worth keeping deliberately and worth saying out loud in the README.

## Not captured

Drag-and-drop feel, the media modal, and the import wizard past the consent gate — I declined
the licence and telemetry rather than accept on Nick's behalf, so the flow beyond that screen
is unseen. `/attachments/bulk/move` rejected my POST (`rest_no_route`), so no files were filed
and per-folder counts stayed untested; Lite shows no folder counts anyway.
