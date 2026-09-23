# FolderFolio developer API

Everything here is covered by the compatibility promise: **the `FolderFolio` facade and the
hooks below follow semantic versioning from 1.0.** They will not change shape in a minor
release, and anything removed goes through `_deprecated_function()` with a two-minor-release
window.

Everything *not* here is internal. `FolderFolio\Domain\*`, `FolderFolio\Persistence\*`,
`FolderFolio\Rest\*` and `FolderFolio\Admin\*` may change in any release, including a patch.
If you find yourself reaching into one of those, open an issue — that is a gap in this page,
and we would rather fill it than have you depend on an internal.

There is no licence key, no API key, no telemetry and no remote call of any kind.

---

## PHP — the `FolderFolio` facade

A single global class. Every method is static.

```php
if ( class_exists( 'FolderFolio' ) ) {
    $folder = FolderFolio::getOrCreateByPath( '2026/Campaigns/Spring' );
}
```

Guard with `class_exists()` — your code should not fatal when the plugin is deactivated.

Anything that can fail returns a `WP_Error` rather than throwing, matching WordPress
convention. Check with `is_wp_error()`.

### One tree per kind of content

Folders belong to one **object type**: `attachment` for the media library, or a post type —
`post`, `page`, `product` — whose folders are switched on under *Settings → Folders for*
(Posts and Pages by default). Each type has its own tree; a post is never filed in a media
folder, and a folder never sits inside another type's. Every method below that names no
folder takes an optional `$objectType`, defaulting to `'attachment'` — which is also what
every method did before post-type folders existed. A method that names a folder works in
that folder's tree. Crossing trees is refused with `folderfolio_wrong_type`.

### Folders

#### `createFolder( string $name, ?int $parent = null, ?string $objectType = null ): Folder|WP_Error`

```php
$brand = FolderFolio::createFolder( 'Brand' );
$logos = FolderFolio::createFolder( 'Logos', $brand->id );
$legal = FolderFolio::createFolder( 'Legal', null, 'page' );   // the Pages tree
```

Under a parent, the folder joins the parent's tree whatever `$objectType` says.

Errors: `folderfolio_name_required`, `folderfolio_name_too_long`,
`folderfolio_duplicate_name`, `folderfolio_invalid_parent`, `folderfolio_wrong_type`,
`folderfolio_max_depth_exceeded`, `folderfolio_locked`.

#### `renameFolder( int $id, string $name ): Folder|WP_Error`

#### `moveFolder( int $id, ?int $parent ): Folder|WP_Error`

Moves the folder and its whole subtree. Pass `null` to move it to the root.

```php
FolderFolio::moveFolder( $logos->id, null );   // Logos becomes a root folder
```

Errors: `folderfolio_circular_parent` (a folder cannot move inside its own descendant),
`folderfolio_invalid_parent`, `folderfolio_wrong_type`, `folderfolio_duplicate_name`,
`folderfolio_max_depth_exceeded`, `folderfolio_locked`.

#### `deleteFolder( int $id, string $children = 'reparent' ): int|WP_Error`

`$children` decides what happens to subfolders, and there is no silent default at the HTTP
layer — the REST route requires it explicitly.

| Value | Effect |
|---|---|
| `'reparent'` | Subfolders take the deleted folder's place in the hierarchy |
| `'cascade'` | The folder and everything beneath it are deleted |

Returns the number of folders deleted. Deleting a folder never deletes media — only the
assignment rows that filed media there.

```php
FolderFolio::deleteFolder( $id, 'cascade' );
```

### Reading

#### `getFolder( int $id ): ?Folder`

#### `findFolderByPath( string $path, string $objectType = 'attachment' ): ?Folder`

Human path, not the internal id path. Separator is `/`, matching is case-insensitive on names.

```php
$folder = FolderFolio::findFolderByPath( 'Brand/Logos/Primary' );
```

#### `getOrCreateByPath( string $path, string $objectType = 'attachment' ): Folder|WP_Error`

Resolves as far as it can and creates the rest. This is the method most integrations want —
importers, upload rules, migration scripts — and it is idempotent, so calling it on every
upload is fine.

```php
// Creates Brand, then Logos inside it, then Primary inside that, as needed.
$folder = FolderFolio::getOrCreateByPath( 'Brand/Logos/Primary' );

// The same in the Posts tree.
$news = FolderFolio::getOrCreateByPath( 'Newsroom/2026', 'post' );
```

#### `getTree( ?int $rootId = null, string $objectType = 'attachment' ): array`

Nested arrays. Each node carries the folder's columns plus `children`, `count` (the folder's
own items) and `total_count` (its subtree), and `sort_folders`, `sort_files`, `locked`,
`locked_by` and `pinned`. With a `$rootId`, the root's own tree. A post folder counts what
its list screen calls *All* — not the trash, not auto-drafts.

#### `getChildren( int $id ): Folder[]`

#### `getAncestors( int $id ): Folder[]`

Outermost first, excluding the folder itself. **Costs no queries for the chain** — the answer
is already in the folder's stored path, which is what makes breadcrumbs free.

```php
$crumbs = FolderFolio::getAncestors( $folder->id );
echo implode( ' / ', array_map( fn ( $f ) => $f->name, $crumbs ) );
```

#### `getDescendantIds( int $id ): int[]`

The folder's own id included. One indexed query, however deep the subtree.

### Media — and posts

The methods say *attachment* because media came first; each one takes post ids too, filed
into that post type's folders.

#### `assign( array $attachmentIds, int $folderId, string $mode = 'add' ): int|WP_Error`

| Mode | Effect |
|---|---|
| `'add'` | Files into this folder and **leaves every other assignment alone** |
| `'move'` | Files into this folder and removes it from all others |

A file can live in several folders. `'add'` is the default because it cannot destroy an
arrangement someone made by hand — which is exactly how competing importers lose data.

```php
FolderFolio::assign( [ 12, 13, 14 ], $folder->id );            // add
FolderFolio::assign( [ 12 ], $folder->id, 'move' );            // move
```

Each item is checked with `current_user_can( 'edit_post', $id )`, so this respects the
current user even when called from your own code, and must be the folder's own kind — an
image into a Posts folder is `folderfolio_invalid_attachment`. Errors:
`folderfolio_invalid_attachment`, `folderfolio_attachment_forbidden`,
`folderfolio_folder_not_found`, `folderfolio_invalid_mode`.

#### `unassign( array $attachmentIds, ?int $folderId = null ): int|WP_Error`

`null` removes them from every folder.

#### `getFoldersOf( int $attachmentId ): Folder[]`

#### `getAttachmentIds( int $folderId, bool $includeDescendants = false ): int[]`

#### `countAttachments( int $folderId, bool $includeDescendants = true ): int`

Exact — `COUNT(DISTINCT attachment_id)`, so a file filed twice inside one subtree counts once.
(The number on the tree badge is a cheaper roll-up that can differ; see
`docs/architecture-plan.md` §4.3.)

### The `Folder` object

Readonly. Public properties:

```php
$folder->id;          // int
$folder->name;        // string
$folder->parentId;    // ?int
$folder->path;        // string  '/1/7/12/' — internal id path
$folder->depth;       // int     0 for a root folder
$folder->objectType;  // string  'attachment', or a post type such as 'page'
$folder->slug;        // ?string
$folder->color;       // ?string a swatch name — 'steel', 'plum' — not a hex
$folder->icon;        // ?string
$folder->sortOrder;   // int
$folder->createdBy;   // ?int
$folder->createdAt;   // string  UTC, 'Y-m-d H:i:s'
$folder->updatedAt;   // string

$folder->ancestorIds();  // int[]  — no queries
$folder->isRoot();       // bool
$folder->toArray();      // array<string, mixed>
```

---

## Actions

```php
do_action( 'folderfolio_folder_created',         Folder $folder );
do_action( 'folderfolio_folder_renamed',         Folder $folder, string $previousName );
do_action( 'folderfolio_folder_moved',           Folder $folder, ?int $previousParentId );
do_action( 'folderfolio_folder_deleted',         int $id, string $children, array $deletedIds );
do_action( 'folderfolio_attachments_assigned',   array $ids, int $folderId, string $mode );
do_action( 'folderfolio_attachments_unassigned', array $ids, ?int $folderId );
do_action( 'folderfolio_folder_duplicated',      Folder $copy, Folder $source, array $idMap, bool $withFiles );
do_action( 'folderfolio_folders_reordered',      array $ids, ?int $parentId );
do_action( 'folderfolio_folder_marked',          Folder $folder, string $mark, bool $on );  // 'state:locked' | 'state:pinned'
```

These fire from the domain layer, not from the REST controllers, so a folder created through
WP-CLI, an importer, the REST API or this facade fires the same hooks as one created by a
click in the media library.

```php
add_action( 'folderfolio_folder_created', function ( $folder ) {
    error_log( "New folder {$folder->name} at depth {$folder->depth}" );
} );
```

## Filters

#### `folderfolio_default_folder_for_upload( ?int $folderId, int $attachmentId ): ?int`

File new uploads automatically. Return a folder id, or `null` to leave the upload unfiled.

```php
add_filter( 'folderfolio_default_folder_for_upload', function ( $folderId, $attachmentId ) {
    $folder = FolderFolio::getOrCreateByPath( 'Uploads/' . gmdate( 'Y/m' ) );

    return is_wp_error( $folder ) ? $folderId : $folder->id;
}, 10, 2 );
```

#### `folderfolio_max_depth( int $depth ): int`

Default 20. Nesting is unlimited as a product promise; the cap exists so that exceeding it is
a clear error rather than a silent truncation.

#### `folderfolio_count_mode( string $mode ): string`

`'inherited'` (default), `'direct'` or `'none'`. Inherited is the default because a parent
folder reading `0` while its children hold a hundred files is worse than no badge at all.

#### `folderfolio_object_types( array $types ): array`

The object types with folders: `attachment`, then the post types ticked under *Folders
for* that are registered right now. `attachment` is put back if a filter removes it.

#### Capabilities

Who may do what comes from the **roles matrix** on the settings screen — six abilities,
`create`, `rename` (headed *Organise*), `delete`, `assign`, `lock` and `download` — and two
rules the matrix cannot override:

1. **The content's own capability first.** `upload_files` for media; for a post type, that
   type's `edit_posts` (`edit_pages` for Pages). The matrix narrows, it never widens.
2. **Administrators hold everything**, and so does anyone given
   `folderfolio_manage_folders`.

A role the matrix has never heard of keeps what the route asked before the matrix existed:
`assign` and `download` by rule 1, the rest by `edit_others_posts`, never `lock`.

```php
folderfolio_user_can_use_folders( bool $can, string $objectType ): bool
folderfolio_user_can( bool $allowed, string $ability, string $objectType ): bool
folderfolio_user_can_manage_folders( bool $can ): bool      // create, rename or delete — "show the controls"
folderfolio_user_can_edit_attachment( bool $can, int $id )  // default: edit_post on that item
```

```php
// Let Authors organise folders too, on every tree.
add_filter( 'folderfolio_user_can', fn ( $allowed, $ability ) => $allowed || ( 'rename' === $ability && current_user_can( 'publish_posts' ) ), 10, 2 );
```

#### Everything else

```php
folderfolio_settings( array $settings ): array               // the site settings, sanitised
folderfolio_zip_confirm_bytes( int $bytes ): int             // ask before a ZIP this large — 1 GB
folderfolio_zip_max_bytes( int $bytes ): int                 // refuse a ZIP larger — 0, no limit
folderfolio_media_frame_screens( array $hookSuffixes ): array // where the media picker gets folders
folderfolio_import_sources( array $sources ): array          // the importer's sources
folderfolio_import_file_batch_seconds( float $seconds ): float
```

---

## REST

Namespace `folderfolio/v1`. The same routes serve the admin app and your code — there is no
separate "public" API and no API key to generate.

**Authentication is WordPress Application Passwords** (core since 5.6). Per user, individually
revocable from the user's own profile, and routed through the normal capability stack, so the
filters above apply unchanged.

```bash
curl -u 'alice:abcd efgh ijkl mnop qrst uvwx' \
     https://example.com/wp-json/folderfolio/v1/folders
```

We deliberately did not build a bespoke key system. The two plugins in this category that
offer one each store a single long-lived secret in `wp_options`, unscoped, unrotatable and
unattributable to a person — anyone holding that string acts with unbounded rights. Core's
mechanism is better in every respect and already there.

**Which tree.** A route that names a folder — `{id}`, `folder_id`, a `parent_id`, the first
of a level's `ids` — is answered for **that folder's** object type, and its permission is
asked for that type; an `object_type` sent alongside is ignored. Only a request that names no
folder reads `object_type` (default `attachment`): the tree, the counts, a folder or a list
made at the top.

| Method | Route | Ability |
|---|---|---|
| `GET` | `/folders` — `?counts=inherited\|direct\|none&object_type=` | use |
| `POST` | `/folders` — `{name, parent_id?, color?, icon?, object_type?}` | create |
| `GET` | `/folders/{id}` | use |
| `PATCH` | `/folders/{id}` — name, colour, icon | rename |
| `POST` | `/folders/{id}/move` — `{parent_id}` | rename |
| `DELETE` | `/folders/{id}?children=reparent\|cascade&reassign_to=` | delete |
| `POST` | `/folders/reorder` — `{parent_id?, ids}`, the whole level in order | rename |
| `POST` | `/folders/{id}/duplicate` — `{parent_id?, with_files?, order?}` | create + rename (+ assign with files) |
| `POST` | `/folders/{id}/sort` — `{scope: folders\|files, order}` | rename |
| `POST` | `/folders/{id}/files/order` — `{ids, place, anchor?}` | rename |
| `POST` | `/folders/{id}/lock` — `{locked}` | lock |
| `POST` | `/folders/{id}/pin` — `{pinned}` | rename |
| `POST` | `/folders/{id}/star` — `{starred}`, the person's own | use |
| `GET` | `/folders/{id}/zip` — what the ZIP would hold, and its URL; media folders only | download |
| `POST` | `/folders/bulk/plan` — `{text, parent_id?, object_type?}`, writes nothing | create |
| `POST` | `/folders/bulk` — the same, all or nothing | create |
| `GET` | `/folders/{id}/ancestors` | use |
| `GET` | `/folders/{id}/attachments` — `?include_descendants=1` | use |
| `GET` | `/counts` — `?object_type=`; every folder's counts and the fixed rows' | use |
| `POST` | `/assignments` — `{attachment_ids, folder_id, mode}` | assign, and `edit_post` per item |
| `DELETE` | `/assignments` — `{attachment_ids, folder_id?}` | assign, and `edit_post` per item |
| `GET` | `/attachments/{id}/folders` — an item's folders, a file's or a post's | use, for the item's type |
| `GET` | `/smart` — `?object_type=`; smart folders, each with its count for the person asking | use |
| `POST` | `/smart` — `{name, rules, object_type?}` | rename (Organise) |
| `PATCH` | `/smart/{id}` — `{name?, rules?}` | rename (Organise) |
| `DELETE` | `/smart/{id}` — never touches a file | rename (Organise) |
| `POST` | `/smart/preview` — `{rules, object_type?}`, what they would match; writes nothing | use |

**Smart folders** (1.0, media first) are a name and rules, every rule must match. A rule is
`{field, op, value}`: `type` `is`/`is_not` `image|video|audio|document`; `date` `last` (days),
`after` or `before` (`Y-m-d`); `author` `is` a user id or `"me"` — whoever is asking; `size`
`gt`/`lt` bytes; `filed` `none`, `any`, or `in` a folder id (its subfolders included); `name`
`contains` text (title or file name). Anything else is dropped; a smart folder with no rule
left is refused. The library filters by one with `?folderfolio_smart={id}`.

*Use* is rule 1 for the type — see Capabilities. Every folder write returns the whole tree
it touched, with fresh counts, so a client redraws from the response instead of fetching
again; `/folders/bulk` is the exception, returning its plan.

Browser requests from the admin screens use the `wp_rest` nonce as usual; Application
Passwords are for everything outside the browser.

---

## WP-CLI

```
wp folderfolio folder list [--tree] [--parent=<id>] [--format=table|json|csv|ids]
wp folderfolio folder create <path> [--porcelain]
wp folderfolio folder move <id> --parent=<id>
wp folderfolio folder delete <id> --children=reparent|cascade
wp folderfolio assign <attachment-ids> --folder=<id> [--mode=add|move]
wp folderfolio import list
wp folderfolio import preview <source>
wp folderfolio import run <source> [--yes]
wp folderfolio rebuild-paths
wp folderfolio doctor
```

> The `import` subcommands land with the importer rewrite. Everything else on
> this list works today. They are documented here because this page is the 1.0
> contract, not a changelog of what is currently on `main`.

`folder create` takes a human path and creates the whole chain, so provisioning a structure
across a fleet is one line:

```bash
wp folderfolio folder create 'Clients/Acme/2026' --porcelain
```

No other plugin in this category ships WP-CLI commands at all.

---

## Stability

| Surface | Promise |
|---|---|
| `FolderFolio` facade | Semver from 1.0 |
| Actions and filters on this page | Semver from 1.0 |
| REST routes under `folderfolio/v1` | Semver from 1.0 |
| WP-CLI command names and flags | Semver from 1.0 |
| Database schema | **Not** an API. Read it through the facade. |
| `FolderFolio\*` namespaced classes | **Not** an API, except `FolderFolio\Domain\Folder` as a return type |

Every example on this page is lifted from a passing test, so the documentation cannot drift
from the code without the suite going red.
