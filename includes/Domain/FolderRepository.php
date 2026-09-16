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
 *     name: string,
 *     slug: string|null,
 *     color: string|null,
 *     icon: string|null,
 *     sort_order: int|string,
 *     template_id: int|string|null,
 *     owner_id: int|string|null,
 *     visibility: string,
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
 *     template_id?: int|null,
 *     owner_id?: int|null,
 *     visibility?: string
 * }
 *
 * @phpstan-type FolderUpdateData array{
 *     name?: string,
 *     parent_id?: int|null,
 *     slug?: string|null,
 *     color?: string|null,
 *     icon?: string|null,
 *     sort_order?: int,
 *     template_id?: int|null,
 *     owner_id?: int|null,
 *     visibility?: string,
 *     updated_at?: string
 * }
 */
class FolderRepository
{
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
        'name',
        'slug',
        'color',
        'icon',
        'sort_order',
        'template_id',
        'owner_id',
        'visibility',
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
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table()} WHERE id = %d",
                $id
            ),
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * Get all folders ordered for display.
     *
     * @return list<FolderRow>
     */
    public function all(): array
    {
        return $this->wpdb->get_results(
            "SELECT * FROM {$this->table()} ORDER BY sort_order ASC, name ASC",
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
        return $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table()} WHERE parent_id = %d ORDER BY sort_order ASC, name ASC",
                $parentId
            ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * Is there already a folder with this name alongside the given parent?
     *
     * A unique index cannot express this: MySQL treats every NULL parent_id as
     * distinct, so root folders would slip through it anyway.
     */
    public function siblingNameExists(string $name, ?int $parentId, ?int $ignoreId = null): bool
    {
        $sql = $parentId === null
            ? $this->wpdb->prepare(
                "SELECT id FROM {$this->table()} WHERE parent_id IS NULL AND name = %s",
                $name
            )
            : $this->wpdb->prepare(
                "SELECT id FROM {$this->table()} WHERE parent_id = %d AND name = %s",
                $parentId,
                $name
            );

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
     * @param FolderCreateData $data
     * @return int|WP_Error
     */
    public function create(array $data): int|WP_Error
    {
        $created = $this->wpdb->insert(
            $this->table(),
            [
                'parent_id'  => $data['parent_id'] ?? null,
                'name'       => $data['name'],
                'slug'       => $data['slug'] ?? sanitize_title($data['name']),
                'color'      => $data['color'] ?? null,
                'icon'       => $data['icon'] ?? null,
                'sort_order' => $data['sort_order'] ?? 0,
                'template_id' => $data['template_id'] ?? null,
                'owner_id'   => $data['owner_id'] ?? null,
                'visibility' => $data['visibility'] ?? 'all',
                'created_by' => get_current_user_id(),
                'created_at' => current_time('mysql', true),
                'updated_at' => current_time('mysql', true),
            ]
        );

        if ($created === false) {
            return new WP_Error(
                'folderfolio_folder_create_failed',
                __('Unable to create the folder.', 'folderfolio')
            );
        }

        return (int) $this->wpdb->insert_id;
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

        $data = array_intersect_key($data, array_flip(self::WRITABLE));

        if ($data === []) {
            return true;
        }

        $updated = $this->wpdb->update(
            $this->table(),
            $data,
            ['id' => $id]
        );

        if ($updated === false) {
            return new WP_Error(
                'folderfolio_folder_update_failed',
                __('Unable to update the folder.', 'folderfolio')
            );
        }

        return true;
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
                __('Unable to delete the folder.', 'folderfolio')
            );
        }

        return true;
    }
}
