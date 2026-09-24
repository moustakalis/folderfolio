<?php

/**
 * The public PHP API.
 *
 * This file is loaded explicitly by the plugin bootstrap rather than through
 * the autoloader: the class lives in the global namespace so integrators can
 * write `FolderFolio::getOrCreateByPath( … )` instead of a fully-qualified
 * name, and the autoloader only handles the `FolderFolio\` prefix.
 *
 * Everything here is covered by the compatibility promise documented in
 * docs/api/README.md. It is deliberately thin — it holds no logic, only
 * delegation — so that the domain layer underneath stays free to change.
 * If a method here starts doing work, that work belongs in FolderService.
 *
 * @package FolderFolio
 */

declare(strict_types=1);

use FolderFolio\Database\Schema;
use FolderFolio\Domain\AttachmentFolderRepository;
use FolderFolio\Domain\Folder;
use FolderFolio\Domain\FolderArchive;
use FolderFolio\Domain\FolderExport;
use FolderFolio\Domain\FolderLocks;
use FolderFolio\Domain\FolderService;
use FolderFolio\Domain\SmartFolders;
use FolderFolio\Support\Settings;

if (!defined('ABSPATH')) {
    exit;
}

if (class_exists('FolderFolio', false)) {
    return;
}

/**
 * FolderFolio's public PHP API.
 *
 * Guard your calls with `class_exists( 'FolderFolio' )` so your code does not
 * fatal when the plugin is deactivated.
 *
 * Failures return a WP_Error rather than throwing, matching WordPress
 * convention. Check with `is_wp_error()`.
 *
 * @since 1.0.0
 */
final class FolderFolio
{
    private static ?FolderService $service = null;

    /**
     * Swap the service the facade delegates to.
     *
     * For tests. Not part of the public API.
     *
     * @internal
     */
    public static function setService(?FolderService $service): void
    {
        self::$service = $service;
    }

    private static function service(): FolderService
    {
        return self::$service ??= new FolderService();
    }

    // ---------------------------------------------------------------- folders

    /**
     * Create a folder.
     *
     * Media's tree unless told otherwise. Under a parent the folder joins the
     * parent's tree whatever `$objectType` says — one tree per type.
     *
     * @since 1.0.0
     * @param string|null $objectType `attachment`, or a post type with folders (since 1.0.0, tier 3).
     * @return Folder|WP_Error
     */
    public static function createFolder(string $name, ?int $parent = null, ?string $objectType = null): Folder|WP_Error
    {
        $parentFolder = $parent === null ? null : self::service()->get($parent);

        return self::service()->create([
            'name'        => $name,
            'parent_id'   => $parent,
            'object_type' => $parentFolder->objectType ?? $objectType ?? 'attachment',
        ]);
    }

    /**
     * Rename a folder.
     *
     * @since 1.0.0
     * @return Folder|WP_Error
     */
    public static function renameFolder(int $id, string $name): Folder|WP_Error
    {
        return self::service()->update($id, ['name' => $name]);
    }

    /**
     * Move a folder, and its whole subtree with it. Null moves it to the root.
     *
     * @since 1.0.0
     * @return Folder|WP_Error
     */
    public static function moveFolder(int $id, ?int $parent): Folder|WP_Error
    {
        return self::service()->move($id, $parent);
    }

    /**
     * Delete a folder.
     *
     * Never deletes media — only the assignment rows that filed media here.
     *
     * @since 1.0.0
     * @param string $children 'reparent' keeps subfolders, 'cascade' deletes them.
     * @return int|WP_Error Number of folders deleted.
     */
    public static function deleteFolder(int $id, string $children = 'reparent'): int|WP_Error
    {
        return self::service()->delete($id, $children);
    }

    // -------------------------------------------------------------- organising

    /**
     * Copy a folder and everything beneath it into `$parent` — null is the
     * top level; pass `$folder->parentId` for beside the original.
     *
     * With `$withFiles` the copies hold the same files (filed, not
     * duplicated on disk), each checked with `edit_post`.
     *
     * @since 1.0.0
     * @return Folder|WP_Error The copy of the top folder.
     */
    public static function duplicateFolder(int $id, ?int $parent = null, bool $withFiles = false): Folder|WP_Error
    {
        return self::service()->duplicate($id, $parent, $withFiles);
    }

    /**
     * Arrange one level by hand: every folder under `$parent` (null for the
     * top level), in the order they should sit. A folder from another level
     * in the list is moved here first.
     *
     * @since 1.0.0
     * @param list<int> $ids
     * @return int|WP_Error Size of the level arranged.
     */
    public static function reorderFolders(?int $parent, array $ids): int|WP_Error
    {
        return self::service()->reorder($parent, $ids);
    }

    /**
     * A folder's colour: a swatch name (`steel`, `plum`, …) or a hex, snapped
     * to the nearest swatch. Null clears it.
     *
     * @since 1.0.0
     * @return Folder|WP_Error
     */
    public static function setFolderColor(int $id, ?string $color): Folder|WP_Error
    {
        return self::service()->update($id, ['color' => $color]);
    }

    /**
     * How a folder orders what is inside it. `$scope` is `folders` or
     * `files`; `$order` is `name-asc`, `name-desc`, `newest`, `oldest` or
     * `custom`, or null to follow the person's own sort.
     *
     * @since 1.0.0
     * @return Folder|WP_Error
     */
    public static function setFolderSort(int $id, string $scope, ?string $order): Folder|WP_Error
    {
        return self::service()->sort($id, $scope, $order);
    }

    /**
     * Put files in a folder's own order: at the `start` or `end`, or
     * `before` / `after` the `$anchor` file. The folder's files sort
     * becomes `custom`.
     *
     * @since 1.0.0
     * @param list<int> $attachmentIds Files already in the folder.
     * @return int|WP_Error How many positions were written.
     */
    public static function orderFiles(int $folderId, array $attachmentIds, string $place = 'start', ?int $anchor = null): int|WP_Error
    {
        return self::service()->moveFiles($folderId, $attachmentIds, $place, $anchor);
    }

    /**
     * Lock a folder, or unlock it. A locked folder and everything beneath it
     * cannot be renamed, moved, deleted or filed into by anyone without the
     * `lock` ability.
     *
     * @since 1.0.0
     * @return Folder|WP_Error
     */
    public static function lockFolder(int $id, bool $locked = true): Folder|WP_Error
    {
        return self::service()->mark($id, FolderLocks::LOCKED, $locked);
    }

    /**
     * Pin a folder to the top of its level, or unpin it.
     *
     * @since 1.0.0
     * @return Folder|WP_Error
     */
    public static function pinFolder(int $id, bool $pinned = true): Folder|WP_Error
    {
        return self::service()->mark($id, FolderLocks::PINNED, $pinned);
    }

    /**
     * `gallery` or `folder`. A gallery is a media folder that holds images
     * only; refused while the folder holds anything else.
     *
     * @since 1.0.0
     * @return Folder|WP_Error
     */
    public static function setFolderKind(int $id, string $kind): Folder|WP_Error
    {
        return self::service()->setKind($id, $kind);
    }

    // ----------------------------------------------------------- smart folders

    /**
     * Smart folders: `{id, name, object_type, rules, created_by, created_at}`.
     *
     * @since 1.0.0
     * @return list<array<string, mixed>>
     */
    public static function getSmartFolders(string $objectType = 'attachment'): array
    {
        return (new SmartFolders())->all($objectType);
    }

    /**
     * A name and rules, every rule must match — see docs/api for the rules.
     *
     * @since 1.0.0
     * @param list<array{field: string, op: string, value: mixed}> $rules
     * @return array<string, mixed>|WP_Error
     */
    public static function createSmartFolder(string $name, array $rules, string $objectType = 'attachment'): array|WP_Error
    {
        return (new SmartFolders())->create($name, $objectType, $rules);
    }

    /**
     * Null keeps the name, or the rules, as they are.
     *
     * @since 1.0.0
     * @param list<array{field: string, op: string, value: mixed}>|null $rules
     * @return array<string, mixed>|WP_Error
     */
    public static function updateSmartFolder(int $id, ?string $name = null, ?array $rules = null): array|WP_Error
    {
        return (new SmartFolders())->update($id, $name, $rules);
    }

    /**
     * Never touches a file.
     *
     * @since 1.0.0
     * @return true|WP_Error
     */
    public static function deleteSmartFolder(int $id): bool|WP_Error
    {
        return (new SmartFolders())->delete($id);
    }

    /**
     * What a smart folder matches right now, for the current user (a rule of
     * `author is me` is theirs), newest first.
     *
     * @since 1.0.0
     * @return list<int>|WP_Error
     */
    public static function getSmartFolderItemIds(int $id): array|WP_Error
    {
        $smart = (new SmartFolders())->get($id);

        if ($smart === null) {
            return new WP_Error('folderfolio_smart_not_found', __('Smart folder not found.', 'folderfolio'));
        }

        return (new SmartFolders())->ids($smart['rules'], $smart['object_type']);
    }

    /**
     * How many items a smart folder matches now, for the current user — one
     * COUNT, where getSmartFolderItemIds() reads every id.
     *
     * @since 1.0.0
     */
    public static function countSmartFolderItems(int $id): int|WP_Error
    {
        $smart = (new SmartFolders())->get($id);

        if ($smart === null) {
            return new WP_Error('folderfolio_smart_not_found', __('Smart folder not found.', 'folderfolio'));
        }

        return (new SmartFolders())->count($smart['rules'], $smart['object_type']);
    }

    // ---------------------------------------------------------------- reading

    /**
     * @since 1.0.0
     */
    public static function getFolder(int $id): ?Folder
    {
        return self::service()->get($id);
    }

    /**
     * Resolve a human path such as "Brand/Logos/Primary".
     *
     * @since 1.0.0
     */
    public static function findFolderByPath(string $path, string $objectType = 'attachment'): ?Folder
    {
        return self::service()->findByPath($path, $objectType);
    }

    /**
     * Resolve a human path, creating whatever part of it does not exist.
     *
     * Idempotent — safe to call on every upload.
     *
     * @since 1.0.0
     * @return Folder|WP_Error
     */
    public static function getOrCreateByPath(string $path, string $objectType = 'attachment'): Folder|WP_Error
    {
        return self::service()->getOrCreateByPath($path, $objectType);
    }

    /**
     * The folder tree as nested arrays, with `count` and `total_count`.
     *
     * @since 1.0.0
     * @return list<array<string, mixed>>
     */
    public static function getTree(?int $rootId = null, string $objectType = 'attachment'): array
    {
        if ($rootId === null) {
            return self::service()->tree($objectType);
        }

        // A subtree is in its root's tree, whatever was asked.
        $root = self::service()->get($rootId);

        return $root === null ? [] : self::service()->subtree($rootId, $root->objectType);
    }

    /**
     * @since 1.0.0
     * @return list<Folder>
     */
    public static function getChildren(int $id): array
    {
        return self::service()->children($id);
    }

    /**
     * Ancestors, outermost first, excluding the folder itself.
     *
     * Costs no queries for the chain — the answer is in the stored path.
     *
     * @since 1.0.0
     * @return list<Folder>
     */
    public static function getAncestors(int $id): array
    {
        return self::service()->ancestors($id);
    }

    /**
     * Ids of a folder and every folder beneath it.
     *
     * @since 1.0.0
     * @return list<int>
     */
    public static function getDescendantIds(int $id): array
    {
        return self::service()->subtreeIds($id);
    }

    // ------------------------------------------------------------------ media

    /**
     * File attachments into a folder.
     *
     * 'add' leaves every other assignment alone; 'move' clears them first.
     * Each attachment is checked with current_user_can( 'edit_post', $id ).
     *
     * @since 1.0.0
     * @param list<int> $attachmentIds
     * @return int|WP_Error Number filed.
     */
    public static function assign(array $attachmentIds, int $folderId, string $mode = 'add'): int|WP_Error
    {
        return self::service()->assignAttachments($folderId, $attachmentIds, $mode);
    }

    /**
     * Remove attachments from a folder, or from every folder when null.
     *
     * @since 1.0.0
     * @param list<int> $attachmentIds
     * @return int|WP_Error
     */
    public static function unassign(array $attachmentIds, ?int $folderId = null): int|WP_Error
    {
        return self::service()->unassignAttachments($folderId, $attachmentIds);
    }

    /**
     * @since 1.0.0
     * @return list<Folder>
     */
    public static function getFoldersOf(int $attachmentId): array
    {
        return self::service()->foldersOf($attachmentId);
    }

    /**
     * @since 1.0.0
     * @return list<int>
     */
    public static function getAttachmentIds(int $folderId, bool $includeDescendants = false): array
    {
        return self::service()->attachmentIds($folderId, $includeDescendants);
    }

    /**
     * Exact attachment count — COUNT(DISTINCT), so a file filed twice inside
     * one subtree counts once.
     *
     * @since 1.0.0
     */
    public static function countAttachments(int $folderId, bool $includeDescendants = true): int
    {
        return self::service()->countAttachments($folderId, $includeDescendants);
    }

    // ------------------------------------------------------------ site upkeep

    /**
     * Every folder as a document — what Settings → Import → Export saves, and
     * what `wp folderfolio import run <file>` reads on another site.
     *
     * @since 1.0.0
     * @return array<string, mixed>
     */
    public static function exportFolders(bool $withAssignments = false): array
    {
        return (new FolderExport())->document($withAssignments);
    }

    /**
     * Write a media folder's ZIP — the download's own bytes, without its size
     * limit — to `$file`, or into `$file` when it is a directory. Never
     * replaces a file. Files the current user may not read are left out and
     * counted in `not-included.txt`.
     *
     * @since 1.0.0
     * @return array{path: string, files: int, bytes: int, left_out: int, length: int}|WP_Error
     */
    public static function zipFolder(int $id, string $file): array|WP_Error
    {
        return (new FolderArchive())->toFile($id, $file);
    }

    /**
     * Recompute every folder's path and depth from its parent. Writes only
     * what has drifted.
     *
     * @since 1.0.0
     * @return int Folders rewritten.
     */
    public static function rebuildPaths(): int
    {
        return (new Schema())->backfillPaths(true);
    }

    /**
     * Forget filings of files that were deleted, and of folders that are
     * gone — Settings → Status → *Remove orphaned entries*.
     *
     * @since 1.0.0
     * @return int Rows removed.
     */
    public static function removeOrphans(): int
    {
        return (new AttachmentFolderRepository())->deleteOrphans();
    }

    // ---------------------------------------------------------------- settings

    /**
     * The site settings, as the settings screen shows them —
     * `count_mode`, `default_sort`, `startup_folder`, `undo_window`,
     * `roles`, `post_types`.
     *
     * @since 1.0.0
     * @return array<string, mixed>
     */
    public static function getSettings(): array
    {
        return Settings::get();
    }

    /**
     * Change some settings and keep the rest. `roles` changes the roles
     * named and keeps the others. A value the settings screen could not send
     * is refused with `folderfolio_setting_invalid`, not replaced.
     *
     * @since 1.0.0
     * @param array<string, mixed> $changes
     * @return array<string, mixed>|WP_Error The settings as saved.
     */
    public static function updateSettings(array $changes): array|WP_Error
    {
        return Settings::change($changes);
    }
}
