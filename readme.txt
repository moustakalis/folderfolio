=== FolderFolio ===
Contributors: moustakalis
Tags: media library, folders, media folders, media organization, gallery
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Organize the Media Library with unlimited nested folders. Every feature is free: no tiers, no upsells, no telemetry.

== Description ==

FolderFolio gives the WordPress Media Library the thing it has never had: folders.

**Every feature is free.** There is no Pro version, no upgrade prompt and no
feature held back for one. Nesting is unlimited at every level, bulk actions
work on any number of files, and the importer reads every folder plugin it
knows about rather than the one that happens to be cheapest to support. The
plugin makes no outbound network requests of any kind — nothing is measured,
nothing is reported, nothing is sent anywhere.

Folders are **virtual**. Nothing on disk moves, no file is renamed, and no
attachment URL changes. Uploads keep landing in the year/month directories
WordPress has always used, and every link already published keeps working. A
folder is a way of looking at the library, not a place on a hard drive — which
is also why deactivating the plugin cannot break a single image on your site.

= In the Media Library =

* Unlimited nested folders, in both grid and list mode
* Drag files onto a folder to file them
* One file can live in several folders at once
* Bulk assign, move and unassign, with an undo window
* Uploads go straight into the folder you are looking at
* Ten folder colours
* A folder filter on the Media Library toolbar, in both modes
* A folder column inside the media picker, so the block editor, Classic
  Editor, ACF and the Customizer all see your folders
* A keyboard-navigable folder tree

= On the front end =

* A **Folder gallery** block: pick a folder, and every image in it becomes a
  gallery. Add a file to the folder and the page updates itself.
* A `[folderfolio_gallery folder="Brand/Logos"]` shortcode for classic themes
* Rendered on the server. The block ships no JavaScript of ours to visitors —
  one small stylesheet, and the lightbox is the one WordPress already has.
* Private, draft and trashed media are filtered out of every gallery. A folder
  is a way of organising files, never a way of granting access to them.

= Coming from another folder plugin =

The import wizard reads folders and their assignments from nine plugins:

* FileBird (free and Pro)
* Real Media Library
* CatFolders
* Folders (Premio)
* Wicked Folders
* Enhanced Media Library
* Media Library Assistant
* WP Media Folder
* HappyFiles

It shows you exactly what it is about to do before it does anything — every
folder it will create and every file it will file — and it **adds, never
moves**: your existing plugin's data is read and left untouched, so you can run
both side by side until you are satisfied. Every folder and every assignment an
import creates is stamped with the run that made it, so a single click undoes
that run precisely, without a time window and without touching anything you
filed by hand.

= For developers =

* A REST API at `/wp-json/folderfolio/v1/`
* WP-CLI: `wp folderfolio folder list|create|move|delete`, plus `assign`,
  `rebuild-paths` and a `doctor` health check
* A PHP facade, and filters on the capability checks, the import sources and
  the default upload folder
* A **Status** tab under **FolderFolio** reporting the schema, the storage
  engine and anything the health check finds
* `uninstall.php` removes every table, option and transient the plugin created

== Installation ==

1. Upload the plugin to `/wp-content/plugins/folderfolio`, or install it
   through **Plugins → Add New**.
2. Activate it through the **Plugins** screen.
3. Open **Media → Library**. The folder rail is on the left.

Coming from another folder plugin? Open **FolderFolio → Import** in the admin
menu before you deactivate it — the wizard reads the other plugin's own tables,
so its data has to still be there. Nothing is written until you approve the
plan the wizard shows you.

== Frequently Asked Questions ==

= Does this move my files on disk? =

No. Folders are virtual. Nothing is moved or renamed on disk, no attachment URL
changes, and every link you have already published keeps working. Uploads carry
on landing in the usual `uploads/YYYY/MM` directories.

= What happens if I deactivate the plugin? =

Nothing is lost. Your folders and assignments stay in the database, and the
Media Library goes back to looking exactly as it did before. Reactivate and
everything is where you left it.

Uninstalling is the deliberate, destructive step: deleting the plugin runs
`uninstall.php`, which drops the folder tables and the plugin's options. Your
media itself is never touched by either.

= Can a file be in more than one folder? =

Yes. Membership is many-to-many throughout. A drag moves a file, and the bulk
action adds it — so the same logo can sit in both Brand and Press without a
duplicate on disk.

= Is there a Pro version, or a feature I will be asked to pay for? =

No. There is no paid tier, no bundled upsell and no feature that stops at a
limit. Unlimited nesting is the free version, because it is the only version.

= Does the plugin phone home? =

No. It makes no outbound HTTP requests at all — no usage statistics, no
activation ping, no remote asset.

= Who is allowed to create and delete folders? =

Reading folders and filing media needs `upload_files`, which is what the Media
Library itself needs. Creating, renaming, moving and deleting folders needs
`folderfolio_manage_folders`, which falls back to `edit_others_posts` — Editors
and Administrators, not Authors or Contributors. Filing an attachment also
checks `edit_post` on that attachment, so one author cannot file another
author's media. Both checks run through filters if your site needs a different
line.

= Can I put a folder on a page? =

Yes, two ways. The **Folder gallery** block in the editor, or the
`[folderfolio_gallery folder="Brand/Logos"]` shortcode. Both take a folder and
render its images; adding a file to the folder updates the page. The shortcode
resolves a path that already exists and never creates one, so a typo shows
nothing rather than quietly making a folder.

= Does it work inside the block editor's media picker? =

Yes. The picker gets a folder column, which covers the block editor, the
Classic Editor, ACF and the Customizer — anywhere WordPress opens a media
modal.

= Will importing from FileBird break FileBird? =

No. The importer only reads. It creates folders and assignments in
FolderFolio's own tables and changes nothing in the other plugin's, so you can
run both until you are happy and then deactivate the old one. If an import
turns out wrong, undo removes exactly what that run created.

== Screenshots ==

1. The folder rail beside the Media Library, in grid mode.
2. List mode: the folder column, and the folder filter on the toolbar.
3. Filing media by dragging it onto a folder.
4. The bulk folder action, with Add and Move in one control.
5. The import wizard's plan — every folder and file it will touch, shown before anything happens.
6. The settings screen, with the per-role capability matrix.
7. The Folder gallery block in the editor.
8. Narrow screens: the folder rail becomes a bar that opens on a tap.

== Changelog ==

= 1.0.0 =
* First public release.

== Upgrade Notice ==

= 1.0.0 =
First public release.
