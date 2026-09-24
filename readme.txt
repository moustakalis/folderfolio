=== FolderFolio ===
Contributors: moustakalis
Tags: media library, folders, media folders, media organization, gallery
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Unlimited nested folders for the Media Library, posts and pages. Every feature is free: no tiers, no upsells, no telemetry.

== Description ==

FolderFolio gives the WordPress Media Library the thing it has never had: folders
— and gives posts, pages and your own post types the same.

**Every feature is free.** There is no Pro version, no upgrade prompt and no
feature held back for one. Nesting is unlimited at every level, bulk actions
work on any number of files, and the importer reads every folder plugin it
knows about rather than the one that happens to be cheapest to support. The
plugin makes no outbound network requests of any kind — nothing is measured,
nothing is reported, nothing is sent anywhere.

**It never filters your library unless you pick a folder.** Activate it and
the Media Library shows every file, exactly as it did before. The library
narrows only when you choose a folder, and whichever folder you are in is named
above the files with a button that takes you back to all of them. If you give
the library a folder to open in, for the whole site or just for yourself, it
opens there and says so in a sentence.

Folders are **virtual**. Nothing on disk moves, no file is renamed, and no
attachment URL changes. Uploads keep landing in the year/month directories
WordPress has always used, and every link already published keeps working. A
folder is a way of looking at the library, not a place on a hard drive — which
is also why deactivating the plugin cannot break a single image on your site.

= Paid elsewhere, free here =

Every one of these is a paid feature in at least one other popular folder
plugin. Here they are all in the free plugin, because the free plugin is the
only one there is:

* Unlimited nested folders
* Folder permissions by role
* Importing from other folder plugins — nine of them, with undo
* Folders for posts, pages and your own post types, nested
* Uploading into a folder, and uploading a whole directory with its subfolders
* The folder tree inside the media picker
* Folder colours
* Sorting: folders and files, per folder, including your own hand-made order
* Cut, copy, paste and duplicate folders
* Lock, pin and star folders
* Smart folders that fill themselves from rules
* Gallery folders
* A folder the library opens in
* Making many folders at once, and exporting and importing the structure
* Downloading a folder as a ZIP

= In the Media Library =

* Unlimited nested folders, in both grid and list mode
* Drag files onto a folder to file them — from inside a folder a drag moves
  them, from All media it adds them
* One file can live in several folders at once
* Add to folder and Move to folder for a whole selection
* Arrange folders by hand or sort them, and give any folder its own order for
  its subfolders and its files — including an order you arrange by hand
* Cut, copy and paste folders, and duplicate one with or without its files
* Uploads go straight into the folder you are looking at — drop a whole
  directory and its subfolders come with it
* Smart folders: saved rules (type, date, who uploaded it, size, filed or not,
  name) that fill themselves
* Gallery folders, which take images only
* Pin, star and lock folders
* Download a folder as a ZIP
* Ten folder colours
* Open the library in a folder of your choosing, for the whole site or just
  for you
* A folder filter on the Media Library toolbar, in both modes
* A folder column inside the media picker, so the block editor, Classic
  Editor, ACF and the Customizer all see your folders
* A keyboard-navigable folder tree
* Deleting a folder can be undone for a few seconds

= Posts, pages and your own post types =

* Folders for Posts and Pages, on by default; any other post type with a list
  screen can be switched on under **Folders for**
* The same folder rail on their list screens, and a Folders panel in the
  editor
* **Add New** from inside a folder files the new post there

= On the front end =

* A **Folder gallery** block: pick a folder, and every image in it becomes a
  gallery. Add a file to the folder and the page updates itself. Gallery
  folders are listed first in its folder picker.
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

= Settings and tools =

* Who can do what, per role: create, organise, delete, assign files, lock and
  download folders
* Paste a list of paths to make many folders at once
* Export the folder structure to a file, and read it back in — on this site
  or another one
* A **Status** tab with a health check and two repairs

= For developers =

* A REST API at `/wp-json/folderfolio/v1/` — everything the screens do,
  settings included
* WP-CLI for everything the rail does — `wp folderfolio folder` (list, get,
  create, rename, move, duplicate, reorder, delete, color, sort, order-files,
  lock, pin, kind, zip), `assign` and `unassign`, `smart`, `export`,
  `import` (the wizard's engine, from a terminal), `settings get|set` for a
  fleet of sites, `rebuild-paths`, `remove-orphans` and a `doctor` health check
* A PHP facade with the same reach, hooks on every change, and filters on the
  capability checks, the import sources and the default upload folder
* `uninstall.php` removes every table, option, transient and meta key the
  plugin created

== Installation ==

1. Upload the plugin to `/wp-content/plugins/folderfolio`, or install it
   through **Plugins → Add New**.
2. Activate it through the **Plugins** screen.
3. Open **Media → Library**. The folder rail is on the left.

Coming from another folder plugin? Open **FolderFolio → Import** in the admin
menu before you delete it. Deactivating a plugin leaves its folders in the
database, where the wizard reads them; deleting it may not. Nothing is written
until you approve the plan the wizard shows you. From a terminal,
`wp folderfolio import run filebird --user=admin` does the same.

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

Yes. Membership is many-to-many throughout. From inside a folder a drag moves
a file; from All media it adds it; and **Add to folder** and **Move to folder**
say which they do — so the same logo can sit in both Brand and Press without a
duplicate on disk.

= Is there a Pro version, or a feature I will be asked to pay for? =

No. There is no paid tier, no bundled upsell and no feature that stops at a
limit. Unlimited nesting is the free version, because it is the only version.

= Does the plugin phone home? =

No. It makes no outbound HTTP requests at all — no usage statistics, no
activation ping, no remote asset.

= Who is allowed to create and delete folders? =

Each role gets its own set, on the **Settings** tab: create, organise (rename,
move, arrange and pin), delete, assign files, lock and download. Out of the
box Administrators have everything; Editors everything but lock; Authors
create, assign and download; Contributors assign; Subscribers nothing.

The table can only narrow WordPress's own permissions. Media folders need
`upload_files`, which is what the Media Library itself needs; folders for posts
and pages need permission to edit them. Filing an item also checks `edit_post`
on it, so one author cannot file another author's media. The checks run
through filters if your site needs a different line.

= Can I put a folder on a page? =

Yes, two ways. The **Folder gallery** block in the editor, or the
`[folderfolio_gallery folder="Brand/Logos"]` shortcode. Make the folder a
gallery from its menu and it takes images only, and the block lists it first. Both take a folder and
render its images; adding a file to the folder updates the page. The shortcode
resolves a path that already exists and never creates one, so a typo shows
nothing rather than quietly making a folder.

= Will the Media Library look different when I activate it? =

Only by the folder rail beside it. FolderFolio never filters the library unless
you pick a folder: every file is shown until you choose one, the folder you are
in is always named above the files, and one click takes you back to all of
them. A starting folder, if you set one, says so every time it is used.

= Does it work on multisite? =

Yes, network-activated or on single sites. Each site has its own folders and
its own settings, a site added to the network later gets its folders when it is
created, and deleting a site removes its folder tables with it. Each person's
stars and starting folder are kept per site.

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
