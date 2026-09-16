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

### Folders

#### `createFolder( string $name, ?int $parent = null ): Folder|WP_Error`

```php
$brand = FolderFolio::createFolder( 'Brand' );
$logos = FolderFolio::createFolder( 'Logos', $brand->id );
```

Errors: `folderfolio_name_required`, `folderfolio_name_too_long`,
`folderfolio_duplicate_name`, `folderfolio_invalid_parent`,
`folderfolio_max_depth_exceeded`.

#### `renameFolder( int $id, string $name ): Folder|WP_Error`

#### `moveFolder( int $id, ?int $parent ): Folder|WP_Error`

Moves the folder and its whole subtree. Pass `null` to move it to the root.

```php
FolderFolio::moveFolder( $logos->id, null );   // Logos becomes a root folder
```

Errors: `folderfolio_circular_parent` (a folder cannot move inside its own descendant),
`folderfolio_invalid_parent`, `folderfolio_duplicate_name`, `folderfolio_max_depth_exceeded`.

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

#### `findFolderByPath( string $path ): ?Folder`

Human path, not the internal id path. Separator is `/`, matching is case-insensitive on names.

```php
$folder = FolderFolio::findFolderByPath( 'Brand/Logos/Primary' );
```

#### `getOrCreateByPath( string $path ): Folder|WP_Error`

Resolves as far as it can and creates the rest. This is the method most integrations want —
importers, upload rules, migration scripts — and it is idempotent, so calling it on every
upload is fine.

```php
// Creates Brand, then Logos inside it, then Primary inside that, as needed.
$folder = FolderFolio::getOrCreateByPath( 'Brand/Logos/Primary' );
```

#### `getTree( ?int $rootId = null ): array`

Nested arrays. Each node carries the folder's columns plus `children`, `count` (the folder's
own attachments) and `total_count` (its subtree).

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

### Media

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

Each attachment is checked with `current_user_can( 'edit_post', $id )`, so this respects the
current user even when called from your own code. Errors: `folderfolio_invalid_attachment`,
`folderfolio_attachment_forbidden`, `folderfolio_folder_not_found`, `folderfolio_invalid_mode`.

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
$folder->objectType;  // string  'attachment'
$folder->slug;        // ?string
$folder->color;       // ?string '#3047a8'
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

#### Capabilities

```php
folderfolio_can_use_folders( bool $can ): bool         // default: upload_files
folderfolio_can_manage_folders( bool $can ): bool      // default: the custom cap, else edit_others_posts
folderfolio_can_edit_attachment( bool $can, int $id )  // default: edit_post on that attachment
```

Reading and filing use `upload_files`; creating, renaming, moving and deleting folders need
`folderfolio_manage_folders`, falling back to `edit_others_posts`. That is deliberately
tighter than the market — FileBird and CatFolders both allow folder *deletion* on
`upload_files`, which every Author and Contributor holds.

```php
// Let Authors manage folders too.
add_filter( 'folderfolio_can_manage_folders', fn ( $can ) => $can || current_user_can( 'publish_posts' ) );
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

| Method | Route | Capability |
|---|---|---|
| `GET` | `/folders` — `?counts=inherited\|direct\|none` | use |
| `POST` | `/folders` | manage |
| `GET` | `/folders/{id}` | use |
| `PATCH` | `/folders/{id}` | manage |
| `POST` | `/folders/{id}/move` | manage |
| `DELETE` | `/folders/{id}?children=reparent\|cascade` | manage |
| `GET` | `/folders/{id}/ancestors` | use |
| `GET` | `/folders/{id}/attachments` — `?include_descendants=1` | use |
| `GET` | `/counts` | use |
| `POST` | `/assignments` — `{attachment_ids, folder_id, mode}` | `edit_post` per attachment |
| `DELETE` | `/assignments` — `{attachment_ids, folder_id?}` | `edit_post` per attachment |
| `GET` | `/attachments/{id}/folders` | use |

Every mutation returns the affected folders **with fresh counts**, so a client can update its
badges from the response instead of re-fetching the tree.

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
