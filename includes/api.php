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

use FolderFolio\Domain\Folder;
use FolderFolio\Domain\FolderService;

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
     * @since 1.0.0
     * @return Folder|WP_Error
     */
    public static function createFolder(string $name, ?int $parent = null): Folder|WP_Error
    {
        return self::service()->create([
            'name'      => $name,
            'parent_id' => $parent,
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
    public static function findFolderByPath(string $path): ?Folder
    {
        return self::service()->findByPath($path);
    }

    /**
     * Resolve a human path, creating whatever part of it does not exist.
     *
     * Idempotent — safe to call on every upload.
     *
     * @since 1.0.0
     * @return Folder|WP_Error
     */
    public static function getOrCreateByPath(string $path): Folder|WP_Error
    {
        return self::service()->getOrCreateByPath($path);
    }

    /**
     * The folder tree as nested arrays, with `count` and `total_count`.
     *
     * @since 1.0.0
     * @return list<array<string, mixed>>
     */
    public static function getTree(?int $rootId = null): array
    {
        return $rootId === null
            ? self::service()->tree()
            : self::service()->subtree($rootId);
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
}
