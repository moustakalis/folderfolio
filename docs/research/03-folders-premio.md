# Competitive teardown 03 — Folders by Premio 3.2.0

90,000+ installs, 5.0★ from 1,518 reviews — the best-reviewed plugin in the category.
RML deactivated, tables left in place.

## Storage: WordPress taxonomies, not custom tables

Three registered taxonomies, all REST-exposed:

| Taxonomy | REST base | Applies to |
|---|---|---|
| `folder` | `folder` | page |
| `post_folder` | `post_folder` | post |
| `media_folder` | `media_folder` | attachment |

Consequences worth weighing for our own model:

- **Upside.** Data is standard terms — visible to WP's exporter, queryable with `tax_query`,
  covered by WP's term caching, and survives the plugin being deactivated in a form other tools
  understand. I built the entire tree with `POST /wp/v2/media_folder` and filed media with
  `POST /wp/v2/media/{id}`, no plugin-specific API at all.
- **Downside.** `wp_term_relationships` grows with every assignment, terms are global so folder
  names surface in unrelated term UIs, and a separate taxonomy per post type means three trees
  to keep coherent.
- **For M2.** Importing from Folders means `get_terms('media_folder')` and
  `wp_get_object_terms()`, not table reads. Confirms `ImporterInterface` needs a second shape.

## Nesting is paywalled — through the chevron

This is the sharpest thing in the teardown. The tree renders a disclosure chevron on every
folder that has children. **Clicking it navigates to the pricing page.**

Because the data lives in WP taxonomy terms, and I created them through WordPress's own REST
API, the plugin could not prevent the nesting from existing. The folders *are* nested, the
parent ids are correct, the chevron appears — and the only thing standing between the user and
their own data is a redirect to `admin.php?page=folders-upgrade-to-pro`.

Pricing there: **$69/year, $99/2 years, $175 lifetime**, per single site, with "Unlimited
subfolders (with multilevel support)" as the headline Pro row.

Compare the three approaches to the same paywall:

| Plugin | How nesting is gated |
|---|---|
| FileBird Lite | Not gated. Four levels, free. |
| Real Media Library Lite | `parent` silently ignored; folder is created at root with no error |
| Folders (Premio) | Data nests fine; the **expand affordance** redirects to pricing |
| CatFolders Lite | Pro (per its listing; not yet verified hands-on) |

**Three of the four biggest players monetise subfolders.** FolderFolio's "unlimited nested
folders, no tiers" is not a tagline — it is the single clearest product wedge available, and
the design should make depth feel effortless because that is precisely the surface competitors
have no incentive to polish.

## What it does better than the others

- **It uses WordPress's own toolbar.** Alongside the rail it injects an **"All Folders"
  dropdown** and a **"Bulk Organize"** button directly into the media filter row. That is more
  discoverable than a rail-only interaction and it degrades gracefully when the rail is
  collapsed. Worth copying: a native filter control *and* a tree, not one or the other.
- **A real settings screen** with things the others lack: per-post-type default folders,
  a **keyboard shortcuts** toggle, **"Undo action" with a configurable timeout** (default 5
  seconds), replace-media, and trash-before-delete. The undo is the standout — destructive
  folder operations with a 5-second grace window is a better answer than a confirm dialog.
- **Alphabetical ordering** by default, where FileBird uses creation order.
- The rail **collapses to a thin tab**, giving the grid full width.

## What it does worse

- **Loud branding.** Magenta buttons, magenta selected row, magenta dropdown border, a magenta
  support bubble. It reads as the plugin's screen rather than WordPress's.
- **Post-activation interstitial**: redirects to its own settings page and opens a welcome modal
  with an embedded YouTube video. Third plugin, third interstitial.
- **Counts are direct-only.** `Archive 0`, `Campaigns 0`, `Product 0` while their children hold
  the files. That is now **three out of three** — every plugin examined ships the confusing
  default, and at least two of them have the machinery to do better.

## Running tally across three teardowns

| Question | FileBird | RML | Folders |
|---|---|---|---|
| Rail position | left of `#wpbody-content` | left rail | left rail |
| Fixed top rows | All Files / **Uncategorized** | All files / **Unorganized** | All Files / **Unassigned Files** |
| Counts on parents | direct-only | none shown in Lite | direct-only |
| Counts endpoint | separate | separate | term `count` |
| URL state | none | none | none |
| Nesting free? | yes | no (silent) | no (chevron → pricing) |
| Native toolbar integration | no | no | **yes** |
| Deactivation survey | yes | yes (required field) | not reached |

Three different words for "files in no folder" — Uncategorized, Unorganized, Unassigned. We
should pick one and be consistent; "Unassigned" reads best and matches our schema's folder `0`.

**Nobody has URL state.** Three for three. Ours is a real differentiator.

## Not captured

Drag-and-drop feel, media modal, Bulk Organize flow, Media Cleaning, and the Pro-gated expand
beyond the redirect. Clicking a folder row while the rail was mid-state collapsed the sidebar
rather than filtering, so filter-by-click was not cleanly measured for this plugin.
