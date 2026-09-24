<?php

declare(strict_types=1);

namespace FolderFolio\Domain;

if (!defined('ABSPATH')) {
    exit;
}

use WP_Error;
use wpdb;

/**
 * @phpstan-type FolderRow array{
 *     id: int|string,
 *     parent_id: int|string|null,
 *     path: string,
 *     depth: int|string,
 *     object_type: string,
 *     name: string,
 *     slug: string|null,
 *     color: string|null,
 *     icon: string|null,
 *     sort_order: int|string,
 *     created_by: int|string|null,
 *     created_at: string,
 *     updated_at: string
 * }
 *
 * @phpstan-type FolderCreateData array{
 *     name: string,
 *     parent_id?: int|null,
 *     slug?: string|null,
 *     color?: string|null,
 *     icon?: string|null,
 *     sort_order?: int,
 *     object_type?: string
 * }
 *
 * @phpstan-type FolderUpdateData array{
 *     name?: string,
 *     parent_id?: int|null,
 *     path?: string,
 *     depth?: int,
 *     slug?: string|null,
 *     color?: string|null,
 *     icon?: string|null,
 *     sort_order?: int,
 *     updated_at?: string
 * }
 */
class FolderRepository
{
    public const DEFAULT_OBJECT_TYPE = 'attachment';

    /**
     * Columns this repository is allowed to write.
     *
     * FolderService sanitizes its input, but the invariant belongs here too:
     * forwarding an arbitrary array to $wpdb->update() is one careless caller
     * away from an arbitrary-column write.
     *
     * @var list<string>
     */
    private const WRITABLE = [
        'parent_id',
        'path',
        'depth',
        'object_type',
        'name',
        'slug',
        'color',
        'icon',
        'sort_order',
        'created_by',
        'created_at',
        'updated_at',
    ];

    private wpdb $wpdb;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
    }

    public function table(): string
    {
        return $this->wpdb->prefix . 'folderfolio_folders';
    }

    /**
     * Find a folder by ID.
     *
     * @return FolderRow|null
     */
    public function find(int $id): ?array
    {
        $wpdb = $this->wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- our own table, no core API; read live, and the cached reader (GalleryQuery) keys on the 'folderfolio' last_changed every write bumps.
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE id = %d',
                $this->table(),
                $id
            ),
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * Get all folders of one object type, ordered for display.
     *
     * @return list<FolderRow>
     */
    public function all(string $objectType = self::DEFAULT_OBJECT_TYPE): array
    {
        $wpdb = $this->wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- our own table, no core API; read live, and the cached reader (GalleryQuery) keys on the 'folderfolio' last_changed every write bumps.
        return $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM %i
                 WHERE object_type = %s
                 ORDER BY sort_order ASC, name ASC',
                $this->table(),
                $objectType
            ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * Get direct children of a folder.
     *
     * @return list<FolderRow>
     */
    public function children(int $parentId): array
    {
        $wpdb = $this->wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- our own table, no core API; read live, and the cached reader (GalleryQuery) keys on the 'folderfolio' last_changed every write bumps.
        return $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE parent_id = %d ORDER BY sort_order ASC, name ASC',
                $this->table(),
                $parentId
            ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * Folders sharing a parent, in display order.
     *
     * `children()` cannot answer this for the top level: `parent_id = %d`
     * never matches NULL, and the root folders are exactly the rows whose
     * parent is NULL. Reordering has to validate both levels the same way, so
     * it asks here rather than branching at every call site.
     *
     * @return list<FolderRow>
     */
    public function siblingsOf(
        ?int $parentId,
        string $objectType = self::DEFAULT_OBJECT_TYPE
    ): array {
        $sql = $parentId === null
            ? $this->wpdb->prepare(
                'SELECT * FROM %i
                 WHERE parent_id IS NULL AND object_type = %s
                 ORDER BY sort_order ASC, name ASC',
                $this->table(),
                $objectType
            )
            : $this->wpdb->prepare(
                'SELECT * FROM %i
                 WHERE parent_id = %d AND object_type = %s
                 ORDER BY sort_order ASC, name ASC',
                $this->table(),
                $parentId,
                $objectType
            );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is one of the two prepare()d statements just above.
        return $this->wpdb->get_results($sql, ARRAY_A) ?: [];
    }

    /**
     * Every folder in a subtree, the root folder included.
     *
     * One indexed prefix match — no recursion, no CTE, and so no MySQL 8
     * requirement. See FolderPath for why the pattern keeps its trailing slash.
     *
     * @return list<FolderRow>
     */
    public function subtree(string $path): array
    {
        $wpdb = $this->wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- our own table, no core API; read live, and the cached reader (GalleryQuery) keys on the 'folderfolio' last_changed every write bumps.
        return $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM %i
                 WHERE path LIKE %s
                 ORDER BY depth ASC, sort_order ASC, name ASC',
                $this->table(),
                $wpdb->esc_like($path) . '%'
            ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * Ids of every folder in a subtree, the root folder included.
     *
     * @return list<int>
     */
    public function subtreeIds(string $path): array
    {
        $wpdb = $this->wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- our own table, no core API; read live, and the cached reader (GalleryQuery) keys on the 'folderfolio' last_changed every write bumps.
        $ids = $wpdb->get_col(
            $wpdb->prepare(
                'SELECT id FROM %i WHERE path LIKE %s',
                $this->table(),
                $wpdb->esc_like($path) . '%'
            )
        ) ?: [];

        return array_map('intval', $ids);
    }

    /**
     * Is there already a folder with this name alongside the given parent?
     *
     * A unique index cannot express this: MySQL treats every NULL parent_id as
     * distinct, so root folders would slip through it anyway.
     */
    public function siblingNameExists(
        string $name,
        ?int $parentId,
        ?int $ignoreId = null,
        string $objectType = self::DEFAULT_OBJECT_TYPE
    ): bool {
        $sql = $parentId === null
            ? $this->wpdb->prepare(
                'SELECT id FROM %i
                 WHERE parent_id IS NULL AND name = %s AND object_type = %s',
                $this->table(),
                $name,
                $objectType
            )
            : $this->wpdb->prepare(
                'SELECT id FROM %i
                 WHERE parent_id = %d AND name = %s AND object_type = %s',
                $this->table(),
                $parentId,
                $name,
                $objectType
            );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is one of the two prepare()d statements just above.
        foreach ($this->wpdb->get_col($sql) ?: [] as $id) {
            if ($ignoreId === null || (int) $id !== $ignoreId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Create a folder.
     *
     * The path contains the folder's own id, which only exists after the
     * insert, so this is two statements rather than one. The row is never
     * visible with an empty path to anything but this method.
     *
     * @param FolderCreateData $data
     * @param string|null      $parentPath Parent's path, or null for a root folder.
     * @return int|WP_Error
     */
    public function create(array $data, ?string $parentPath = null): int|WP_Error
    {
        $created = $this->wpdb->insert(
            $this->table(),
            [
                'parent_id'   => $data['parent_id'] ?? null,
                'path'        => '',
                'depth'       => 0,
                'object_type' => $data['object_type'] ?? self::DEFAULT_OBJECT_TYPE,
                'name'        => $data['name'],
                'slug'        => $data['slug'] ?? sanitize_title($data['name']),
                'color'       => $data['color'] ?? null,
                'icon'        => $data['icon'] ?? null,
                'sort_order'  => $data['sort_order'] ?? 0,
                'created_by'  => get_current_user_id(),
                'created_at'  => current_time('mysql', true),
                'updated_at'  => current_time('mysql', true),
            ]
        );

        if ($created === false) {
            return new WP_Error(
                'folderfolio_folder_create_failed',
                __('The folder could not be created.', 'folderfolio')
            );
        }

        $id = (int) $this->wpdb->insert_id;
        $path = FolderPath::build($parentPath, $id);

        $this->wpdb->update(
            $this->table(),
            ['path' => $path, 'depth' => FolderPath::depth($path)],
            ['id' => $id]
        );

        wp_cache_set_last_changed('folderfolio');

        return $id;
    }

    /**
     * Update a folder.
     *
     * @param FolderUpdateData $data
     * @return bool|WP_Error
     */
    public function update(int $id, array $data): bool|WP_Error
    {
        $data['updated_at'] = current_time('mysql', true);

        /** @var array<string, mixed> $writable */
        $writable = array_intersect_key($data, array_flip(self::WRITABLE));

        // A caller passing only non-writable keys is a no-op, not an error.
        if ($writable === []) {
            return true;
        }

        $updated = $this->wpdb->update(
            $this->table(),
            $writable,
            ['id' => $id]
        );

        if ($updated === false) {
            return new WP_Error(
                'folderfolio_folder_update_failed',
                __('The folder could not be changed.', 'folderfolio')
            );
        }

        wp_cache_set_last_changed('folderfolio');

        return true;
    }

    /**
     * Write a whole sibling list's order in one statement.
     *
     * One UPDATE rather than one per folder. A level can hold hundreds of
     * folders and this runs inside a transaction, so N round trips is N row
     * locks held for the length of the slowest one. The CASE is built from the
     * caller's own list, so the number of placeholders is the number of ids
     * and every one of them is still prepared.
     *
     * Position is the index in the list. Gaps are not preserved and do not
     * need to be: the order is the list, and rewriting it whole is what makes
     * the operation idempotent.
     *
     * @param list<int> $idsInOrder
     * @return int|WP_Error Size of the level that was arranged.
     */
    public function applySortOrder(array $idsInOrder): int|WP_Error
    {
        if ($idsInOrder === []) {
            return 0;
        }

        $cases = [];
        $args = [];

        foreach (array_values($idsInOrder) as $position => $id) {
            $cases[] = 'WHEN %d THEN %d';
            $args[] = $id;
            $args[] = $position;
        }

        $args[] = current_time('mysql', true);

        foreach ($idsInOrder as $id) {
            $args[] = $id;
        }

        $placeholders = implode(', ', array_fill(0, count($idsInOrder), '%d'));

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- the
        // interpolated parts are placeholder strings this method builds, never
        // caller data; every value goes through prepare() below.
        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the sniff cannot count the CASE pairs or the %d list, which arrive as one spread.
        $sql = $this->wpdb->prepare(
            'UPDATE %i
             SET sort_order = CASE id ' . implode(' ', $cases) . " END,
                 updated_at = %s
             WHERE id IN ({$placeholders})",
            $this->table(),
            ...$args
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql is prepare()d just above; its only interpolations are placeholder strings.
        if ($this->wpdb->query($sql) === false) {
            return new WP_Error(
                'folderfolio_folder_reorder_failed',
                __('The new folder order could not be saved.', 'folderfolio')
            );
        }

        // The size of the level, deliberately, and not $wpdb's affected-rows
        // count. MySQL does not count a row whose value did not change, so a
        // folder that already sat in its new position is missing from that
        // number — and whether one did depends on what the order happened to
        // be beforehand. Measured live: arranging three folders that were all
        // still at the default 0 reported 2.
        wp_cache_set_last_changed('folderfolio');

        return count($idsInOrder);
    }

    /**
     * Re-point every descendant's path when a subtree moves.
     *
     * One statement for the whole subtree, however deep. The WHERE clause is
     * the same indexed prefix match the reads use.
     *
     * @return bool|WP_Error
     */
    public function rewriteSubtreePaths(
        string $oldPrefix,
        string $newPrefix,
        int $depthDelta
    ): bool|WP_Error {
        $wpdb = $this->wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a write to our own table; it bumps the 'folderfolio' last_changed key below.
        $result = $wpdb->query(
            $wpdb->prepare(
                'UPDATE %i
                 SET path = CONCAT(%s, SUBSTRING(path, %d)),
                     depth = depth + %d
                 WHERE path LIKE %s',
                $this->table(),
                $newPrefix,
                strlen($oldPrefix) + 1,
                $depthDelta,
                $wpdb->esc_like($oldPrefix) . '%'
            )
        );

        if ($result === false) {
            return new WP_Error(
                'folderfolio_folder_move_failed',
                __('The folder could not be moved.', 'folderfolio')
            );
        }

        wp_cache_set_last_changed('folderfolio');

        return true;
    }

    /**
     * Delete a folder and everything beneath it.
     *
     * @return int|WP_Error Number of folders deleted.
     */
    public function deleteSubtree(string $path): int|WP_Error
    {
        $wpdb = $this->wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a write to our own table; it bumps the 'folderfolio' last_changed key below.
        $deleted = $wpdb->query(
            $wpdb->prepare(
                'DELETE FROM %i WHERE path LIKE %s',
                $this->table(),
                $wpdb->esc_like($path) . '%'
            )
        );

        if ($deleted === false) {
            return new WP_Error(
                'folderfolio_folder_delete_failed',
                __('The folder could not be deleted.', 'folderfolio')
            );
        }

        wp_cache_set_last_changed('folderfolio');

        return (int) $deleted;
    }

    /**
     * Delete a folder.
     *
     * @return bool|WP_Error
     */
    public function delete(int $id): bool|WP_Error
    {
        $deleted = $this->wpdb->delete(
            $this->table(),
            ['id' => $id]
        );

        if ($deleted === false) {
            return new WP_Error(
                'folderfolio_folder_delete_failed',
                __('The folder could not be deleted.', 'folderfolio')
            );
        }

        wp_cache_set_last_changed('folderfolio');

        return true;
    }

    /**
     * Find a folder by name among a given parent's children.
     *
     * Case-insensitive, because a human typing a path should not have to match
     * the case someone else used when they made the folder.
     *
     * @return FolderRow|null
     */
    public function findByName(
        string $name,
        ?int $parentId,
        string $objectType = self::DEFAULT_OBJECT_TYPE
    ): ?array {
        $sql = $parentId === null
            ? $this->wpdb->prepare(
                'SELECT * FROM %i
                 WHERE parent_id IS NULL AND name = %s AND object_type = %s
                 LIMIT 1',
                $this->table(),
                $name,
                $objectType
            )
            : $this->wpdb->prepare(
                'SELECT * FROM %i
                 WHERE parent_id = %d AND name = %s AND object_type = %s
                 LIMIT 1',
                $this->table(),
                $parentId,
                $name,
                $objectType
            );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is one of the two prepare()d statements just above.
        $row = $this->wpdb->get_row($sql, ARRAY_A);

        return $row ?: null;
    }
}
