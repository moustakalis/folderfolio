<?php

declare(strict_types=1);

namespace FolderFolio\Modules\Import;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Where a folder came from.
 *
 * One row in `folderfolio_folder_meta` per source folder that landed here,
 * keyed `import:<source>:<its id>`. That is what makes a second import
 * **reconcile** rather than duplicate, and it is the thing every competitor's
 * importer is missing.
 *
 * ## Why the key carries the ids rather than the value
 *
 * The obvious shape is two rows — `import_source` and `import_source_id` — and
 * it is wrong. The table's primary key is (folder_id, meta_key), so a folder
 * gets exactly one of each, and **several source folders can land in one
 * folder here**: two same-named siblings in the source, or a second plugin
 * imported into the same tree. With the ids in the value, the second one
 * silently overwrote the first, and the folder then claimed to have come from
 * somewhere it half did.
 *
 * With the ids in the key a folder carries as many as it needs, and the
 * relationship is modelled the way it actually is: many to one.
 *
 * ## Why not a per-source flag
 *
 * CatFolders' answer to re-running is an option per source
 * (`catf_<PREFIX>_success_import`) that disables the button afterwards. That
 * stops the second run, which stops the damage, but it also stops the case
 * this is really for: somebody who imported, kept using the old plugin for a
 * fortnight, and wants the twelve folders they have added since.
 *
 * Renaming an imported folder does not break the link, because the link is not
 * the name.
 */
final class Provenance
{
    private const PREFIX = 'import:';

    private function table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'folderfolio_folder_meta';
    }

    private function key(string $sourceKey, int $sourceFolderId): string
    {
        return self::PREFIX . $sourceKey . ':' . $sourceFolderId;
    }

    /**
     * Our folder id for one source folder, if it has been imported before.
     */
    public function folderIdFor(string $sourceKey, int $sourceFolderId): ?int
    {
        global $wpdb;

        // Joined to the folders table because meta rows outlive a folder that
        // was deleted outside FolderService; a stale row would otherwise
        // resolve to an id nothing can be filed into.
        $id = $wpdb->get_var(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT m.folder_id
                 FROM {$this->table()} m
                 INNER JOIN {$wpdb->prefix}folderfolio_folders f ON f.id = m.folder_id
                 WHERE m.meta_key = %s
                 LIMIT 1",
                $this->key($sourceKey, $sourceFolderId)
            )
        );

        return null === $id ? null : (int) $id;
    }

    /**
     * Every source folder id this source has already produced, as
     * `source id => our folder id`.
     *
     * One query for the whole import. The planner asks this once and then
     * decides create-or-merge-or-reconcile in memory, rather than asking the
     * database per folder.
     *
     * @return array<int, int>
     */
    public function mapFor(string $sourceKey): array
    {
        global $wpdb;

        $prefix = self::PREFIX . $sourceKey . ':';

        /** @var list<array{folder_id: string, meta_key: string}> $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT m.folder_id, m.meta_key
                 FROM {$this->table()} m
                 INNER JOIN {$wpdb->prefix}folderfolio_folders f ON f.id = m.folder_id
                 WHERE m.meta_key LIKE %s",
                // esc_like before the wildcard, or a source key containing an
                // underscore matches keys it should not.
                $wpdb->esc_like($prefix) . '%'
            ),
            ARRAY_A
        ) ?: [];

        $map = [];

        foreach ($rows as $row) {
            $sourceId = (int) substr((string) $row['meta_key'], strlen($prefix));

            if ($sourceId > 0) {
                $map[$sourceId] = (int) $row['folder_id'];
            }
        }

        return $map;
    }

    public function record(int $folderId, string $sourceKey, int $sourceFolderId): void
    {
        global $wpdb;

        // REPLACE rather than a read then an insert: the primary key is
        // (folder_id, meta_key), so re-importing a folder whose provenance is
        // already recorded is one statement and no race.
        $wpdb->query(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "REPLACE INTO {$this->table()} (folder_id, meta_key, meta_value) VALUES (%d, %s, %s)",
                $folderId,
                $this->key($sourceKey, $sourceFolderId),
                '1'
            )
        );
    }

    /**
     * Drop every import link on a folder.
     *
     * Used by undo, where the point is that the import never happened.
     */
    public function forget(int $folderId): void
    {
        global $wpdb;

        $wpdb->query(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "DELETE FROM {$this->table()} WHERE folder_id = %d AND meta_key LIKE %s",
                $folderId,
                $wpdb->esc_like(self::PREFIX) . '%'
            )
        );
    }
}
