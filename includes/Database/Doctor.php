<?php

declare(strict_types=1);

namespace FolderFolio\Database;

if (!defined('ABSPATH')) {
    exit;
}

use FolderFolio\Domain\FolderPath;

/**
 * Data-integrity checks.
 *
 * Real Media Library ships five separate `/reset/*` endpoints and CatFolders a
 * `clean-db`. Two competitors independently concluding that a folder plugin
 * needs repair tooling is enough evidence to build it on day one instead of
 * discovering the need in a support inbox.
 *
 * Read-only. Reporting a problem and fixing it are separate acts: `check()`
 * never writes, and the caller decides.
 *
 * @phpstan-type Finding array{
 *     code: string,
 *     severity: 'error'|'warning',
 *     message: string,
 *     count: int,
 *     ids: list<int>
 * }
 */
final class Doctor
{
    private const SAMPLE = 25;

    /**
     * Run every check.
     *
     * @return list<Finding>
     */
    public function check(): array
    {
        return array_values(array_filter([
            $this->missingParents(),
            $this->pathDrift(),
            $this->depthDrift(),
            $this->cycles(),
            $this->orphanedAssignments(),
            $this->assignmentsWithoutMedia(),
            $this->storageEngine(),
        ]));
    }

    /**
     * Tables that are not InnoDB, and therefore not transactional.
     *
     * `Schema` pins `ENGINE=InnoDB` and converts an older install, so reaching
     * this finding takes a server that could not honour either. MySQL does not
     * fail a `CREATE TABLE … ENGINE=InnoDB` when InnoDB is unavailable — it
     * substitutes the default engine and returns success — so the only way to
     * know is to ask afterwards.
     *
     * It matters because MyISAM accepts `START TRANSACTION` and `ROLLBACK` and
     * ignores both. Every atomic write path in `Database\Transaction` would run
     * without error and protect nothing, which is the failure mode this whole
     * area exists to remove. Reported rather than repaired, in keeping with the
     * rest of this class.
     *
     * @return Finding|null
     */
    private function storageEngine(): ?array
    {
        $wrong = [];

        foreach ([$this->folders(), $this->assignments()] as $table) {
            $engine = Schema::engineOf($table);

            if ($engine !== null && $engine !== 'innodb') {
                $wrong[] = "{$table} ({$engine})";
            }
        }

        if ($wrong === []) {
            return null;
        }

        return [
            'code' => 'storage_engine',
            'severity' => 'error',
            'message' => sprintf(
                /* translators: %s: comma-separated list of table names with their storage engine. */
                __(
                    'These tables are not InnoDB, so folder changes cannot be undone if one fails part-way: %s.',
                    'folderfolio'
                ),
                implode(', ', $wrong)
            ),
            'count' => count($wrong),
            'ids' => [],
        ];
    }

    private function folders(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'folderfolio_folders';
    }

    private function assignments(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'folderfolio_attachment_folders';
    }

    /**
     * Folders whose parent_id points at a folder that no longer exists.
     *
     * These are invisible in the UI — the tree builds from the root down, so a
     * folder whose parent is missing is simply never reached.
     *
     * @return Finding|null
     */
    private function missingParents(): ?array
    {
        global $wpdb;

        $ids = $wpdb->get_col(
            "SELECT f.id FROM {$this->folders()} AS f
             LEFT JOIN {$this->folders()} AS p ON p.id = f.parent_id
             WHERE f.parent_id IS NOT NULL AND p.id IS NULL"
        ) ?: [];

        return $this->finding(
            'missing_parent',
            'error',
            __('Folders whose parent no longer exists. They are unreachable in the tree.', 'folderfolio'),
            $ids
        );
    }

    /**
     * Folders whose stored path disagrees with parent_id.
     *
     * parent_id is the source of truth, so this means the derived column has
     * drifted — a write that bypassed FolderService, or an interrupted move.
     *
     * @return Finding|null
     */
    private function pathDrift(): ?array
    {
        global $wpdb;

        /** @var list<array{id: string, parent_id: string|null, path: string}> $rows */
        $rows = $wpdb->get_results(
            "SELECT id, parent_id, path FROM {$this->folders()}",
            ARRAY_A
        ) ?: [];

        $paths = [];

        foreach ($rows as $row) {
            $paths[(int) $row['id']] = (string) $row['path'];
        }

        $drifted = [];

        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $parentId = $row['parent_id'] === null ? null : (int) $row['parent_id'];

            $expected = FolderPath::build(
                $parentId === null ? null : ($paths[$parentId] ?? null),
                $id
            );

            if ($expected !== (string) $row['path']) {
                $drifted[] = $id;
            }
        }

        return $this->finding(
            'path_drift',
            'error',
            __('Folders whose stored path disagrees with their parent. Fix with Repair folder tree on the Status tab, or wp folderfolio rebuild-paths.', 'folderfolio'),
            $drifted
        );
    }

    /**
     * @return Finding|null
     */
    private function depthDrift(): ?array
    {
        global $wpdb;

        /** @var list<array{id: string, path: string, depth: string}> $rows */
        $rows = $wpdb->get_results(
            "SELECT id, path, depth FROM {$this->folders()}",
            ARRAY_A
        ) ?: [];

        $drifted = [];

        foreach ($rows as $row) {
            if (FolderPath::depth((string) $row['path']) !== (int) $row['depth']) {
                $drifted[] = (int) $row['id'];
            }
        }

        return $this->finding(
            'depth_drift',
            'warning',
            __('Folders whose depth disagrees with their path. Fix with Repair folder tree on the Status tab, or wp folderfolio rebuild-paths.', 'folderfolio'),
            $drifted
        );
    }

    /**
     * Folders that are their own ancestor.
     *
     * FolderService cannot create one — the cycle check runs on every move —
     * so finding one means something wrote parent_id directly.
     *
     * @return Finding|null
     */
    private function cycles(): ?array
    {
        global $wpdb;

        /** @var list<array{id: string, parent_id: string|null}> $rows */
        $rows = $wpdb->get_results(
            "SELECT id, parent_id FROM {$this->folders()}",
            ARRAY_A
        ) ?: [];

        $parents = [];

        foreach ($rows as $row) {
            $parents[(int) $row['id']] = $row['parent_id'] === null ? null : (int) $row['parent_id'];
        }

        $looping = [];

        foreach (array_keys($parents) as $id) {
            $seen = [];
            $cursor = $id;

            while ($cursor !== null) {
                if (isset($seen[$cursor])) {
                    $looping[] = $id;
                    break;
                }

                $seen[$cursor] = true;
                $cursor = $parents[$cursor] ?? null;
            }
        }

        return $this->finding(
            'cycle',
            'error',
            __('Folders that are their own ancestor. Move them to the top level to break the loop.', 'folderfolio'),
            $looping
        );
    }

    /**
     * Assignment rows pointing at a folder that no longer exists.
     *
     * @return Finding|null
     */
    private function orphanedAssignments(): ?array
    {
        global $wpdb;

        $ids = $wpdb->get_col(
            "SELECT DISTINCT a.folder_id FROM {$this->assignments()} AS a
             LEFT JOIN {$this->folders()} AS f ON f.id = a.folder_id
             WHERE f.id IS NULL"
        ) ?: [];

        return $this->finding(
            'orphaned_assignment',
            'warning',
            __('Assignments pointing at a folder that no longer exists.', 'folderfolio'),
            $ids
        );
    }

    /**
     * Assignment rows for media that no longer exists.
     *
     * The delete_attachment hook prevents new ones; these predate it, or came
     * from a direct database delete.
     *
     * @return Finding|null
     */
    private function assignmentsWithoutMedia(): ?array
    {
        global $wpdb;

        $ids = $wpdb->get_col(
            "SELECT DISTINCT a.attachment_id FROM {$this->assignments()} AS a
             LEFT JOIN {$wpdb->posts} AS p ON p.ID = a.attachment_id
             WHERE p.ID IS NULL"
        ) ?: [];

        return $this->finding(
            'assignment_without_media',
            'warning',
            __('Assignments for media that no longer exists.', 'folderfolio'),
            $ids
        );
    }

    /**
     * @param list<int|string> $ids
     * @return Finding|null
     */
    private function finding(string $code, string $severity, string $message, array $ids): ?array
    {
        if ($ids === []) {
            return null;
        }

        $ids = array_map('intval', $ids);

        return [
            'code' => $code,
            'severity' => $severity,
            'message' => $message,
            'count' => count($ids),
            'ids' => array_slice($ids, 0, self::SAMPLE),
        ];
    }
}
