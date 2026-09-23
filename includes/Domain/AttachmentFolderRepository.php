<?php

declare(strict_types=1);

namespace FolderFolio\Domain;

if (!defined('ABSPATH')) {
    exit;
}

use WP_Error;
use wpdb;

class AttachmentFolderRepository
{
    private wpdb $wpdb;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
    }

    public function table(): string
    {
        return $this->wpdb->prefix . 'folderfolio_attachment_folders';
    }

    /**
     * Get attachment IDs assigned to a folder.
     *
     * @return list<int>
     */
    public function attachmentIdsForFolder(int $folderId): array
    {
        return array_map(
            'intval',
            $this->wpdb->get_col(
                $this->wpdb->prepare(
                    "SELECT attachment_id FROM {$this->table()} WHERE folder_id = %d ORDER BY sort_order ASC, attachment_id ASC",
                    $folderId
                )
            ) ?: []
        );
    }

    /**
     * Get folder IDs assigned to an attachment.
     *
     * @return list<int>
     */
    public function folderIdsForAttachment(int $attachmentId): array
    {
        return array_map(
            'intval',
            $this->wpdb->get_col(
                $this->wpdb->prepare(
                    "SELECT folder_id FROM {$this->table()} WHERE attachment_id = %d ORDER BY sort_order ASC, folder_id ASC",
                    $attachmentId
                )
            ) ?: []
        );
    }

    /**
     * Folder ids for many attachments at once.
     *
     * The Folders column in the media list table needs this for every row on
     * the page. Calling folderIdsForAttachment() per row is twenty queries for
     * a screen that can answer the whole question in one, and it is the shape
     * of mistake that only shows up on a site with real traffic.
     *
     * Attachments with no folder are absent from the result rather than
     * present with an empty list: the caller is rendering a table and already
     * has to handle a missing key, and inventing rows here would mean
     * building a map of every id on the page twice.
     *
     * @param list<int> $attachmentIds
     * @return array<int, list<int>> Keyed by attachment id, in path order.
     */
    public function folderIdsForAttachments(array $attachmentIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $attachmentIds))));

        if ($ids === []) {
            return [];
        }

        $folders = $this->wpdb->prefix . 'folderfolio_folders';
        $placeholders = implode(', ', array_fill(0, count($ids), '%d'));

        /*
         * Joined to the folders table, and ordered by path, so the column
         * reads the same way twice — and so an assignment whose folder has
         * been deleted out from under it is dropped here rather than rendering
         * as a blank link. (Deletes clean up after themselves, so that row
         * should not exist; this is the cheap place to be sure.)
         */
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT a.attachment_id, a.folder_id
                 FROM {$this->table()} AS a
                 INNER JOIN {$folders} AS f ON f.id = a.folder_id
                 WHERE a.attachment_id IN ({$placeholders})
                 ORDER BY f.path ASC",
                ...$ids
            ),
            ARRAY_A
        ) ?: [];

        $out = [];

        foreach ($rows as $row) {
            $out[(int) $row['attachment_id']][] = (int) $row['folder_id'];
        }

        return $out;
    }

    public function assign(
        int $folderId,
        int $attachmentId,
        int $sortOrder = 0,
        ?string $importRun = null
    ): bool|WP_Error {
        $result = $this->wpdb->replace($this->table(), [
            'folder_id' => $folderId,
            'attachment_id' => $attachmentId,
            'sort_order' => $sortOrder,
            'assigned_at' => current_time('mysql', true),
            // Null for every row a person creates, which is what undo reads it
            // for. REPLACE rather than INSERT, so a file the import filed and
            // the user has since re-filed by hand loses the marker and stops
            // being the import's to remove — which is the right way round.
            'import_run' => $importRun,
        ]);

        if ($result === false) {
            return new WP_Error('folderfolio_assignment_failed', __('Unable to assign media to the folder.', 'folderfolio'));
        }

        return true;
    }

    public function unassign(int $folderId, int $attachmentId): bool|WP_Error
    {
        $result = $this->wpdb->delete($this->table(), [
            'folder_id' => $folderId,
            'attachment_id' => $attachmentId,
        ]);

        if ($result === false) {
            return new WP_Error('folderfolio_unassignment_failed', __('Unable to remove media from the folder.', 'folderfolio'));
        }

        return true;
    }

    /**
     * Drop every assignment for an attachment.
     *
     * dbDelta cannot express foreign keys, so nothing else removes these rows
     * when the media is deleted - they used to outlive their attachment
     * indefinitely.
     */
    public function deleteForAttachment(int $attachmentId): bool|WP_Error
    {
        $result = $this->wpdb->delete($this->table(), ['attachment_id' => $attachmentId]);

        if ($result === false) {
            return new WP_Error(
                'folderfolio_attachment_cleanup_failed',
                __('Unable to remove folder assignments for the deleted media item.', 'folderfolio')
            );
        }

        return true;
    }

    public function deleteForFolder(int $folderId): bool|WP_Error
    {
        $result = $this->wpdb->delete($this->table(), ['folder_id' => $folderId]);

        if ($result === false) {
            return new WP_Error('folderfolio_assignment_cleanup_failed', __('Unable to remove folder assignments.', 'folderfolio'));
        }

        return true;
    }

    /**
     * Every assignment, as pairs — for the export.
     *
     * One query and no grouping in SQL: the caller wants them grouped by
     * folder and GROUP_CONCAT would cap silently at group_concat_max_len,
     * which on a default MySQL is 1024 characters — about 130 attachment ids,
     * with no error when the 131st is dropped. Grouping in PHP has no such
     * ceiling.
     *
     * Ordered so the file is stable: the same library exports byte-identical
     * twice, which is what makes a diff between two exports mean something.
     *
     * @return list<array{folder_id: int, attachment_id: int}>
     */
    public function all(): array
    {
        $rows = $this->wpdb->get_results(
            "SELECT folder_id, attachment_id FROM {$this->table()}
             ORDER BY folder_id ASC, sort_order ASC, attachment_id ASC",
            ARRAY_A
        ) ?: [];

        return array_map(
            static fn (array $row): array => [
                'folder_id' => (int) $row['folder_id'],
                'attachment_id' => (int) $row['attachment_id'],
            ],
            $rows
        );
    }

    /**
     * Direct attachment count for every folder that has at least one.
     *
     * One GROUP BY for the whole tree. Folders with no attachments are absent
     * from the result; callers default them to 0.
     *
     * This is the exact, cheap number. FolderTree::withCounts() rolls these up
     * the tree in PHP for the inherited badge, less what multiFiled() says it
     * would count twice; subtreeCount() below gives the same figure for one
     * folder.
     *
     * @return array<int, int> folder id => count
     */
    public function directCounts(): array
    {
        $rows = $this->wpdb->get_results(
            "SELECT folder_id, COUNT(*) AS total FROM {$this->table()} GROUP BY folder_id",
            ARRAY_A
        ) ?: [];

        $counts = [];

        foreach ($rows as $row) {
            $counts[(int) $row['folder_id']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Exact attachment count for a folder and everything beneath it.
     *
     * COUNT(DISTINCT) matters here: a file may be filed in two folders inside
     * the same subtree. The tree's roll-up agrees with this number since
     * 23 Sep — `FolderTree::overcount()` takes the second counting off — and
     * this remains the one-folder way to ask.
     */
    /**
     * Every file filed in two or more folders, and those folders.
     *
     * The input to `FolderTree::overcount()`. Only the files the plain
     * roll-up can count twice, so a library where each file sits in one folder
     * returns nothing and costs one GROUP BY over the `attachment_id` index —
     * the same order of work `directCounts()` already does.
     *
     * @return array<int, list<int>> attachment id => folder ids
     */
    public function multiFiled(): array
    {
        $table = $this->table();

        // Identifiers only; nothing here comes from a request.
        $rows = $this->wpdb->get_results(
            "SELECT a.attachment_id, a.folder_id
             FROM {$table} AS a
             INNER JOIN (
                 SELECT attachment_id FROM {$table}
                 GROUP BY attachment_id
                 HAVING COUNT(*) > 1
             ) AS m ON m.attachment_id = a.attachment_id",
            ARRAY_A
        ) ?: [];

        $out = [];

        foreach ($rows as $row) {
            $out[(int) $row['attachment_id']][] = (int) $row['folder_id'];
        }

        return $out;
    }

    /**
     * The two counts the rail's fixed rows show.
     *
     * "Unassigned" is not derivable from the per-folder counts: subtracting
     * assigned from total double-counts anything filed in two folders, and it
     * counts assignments whose attachment has since been deleted. It has to be
     * asked of the media table directly.
     *
     * Trashed attachments are excluded from both, so the numbers agree with
     * what the library actually lists.
     *
     * @return array{all: int, unassigned: int}
     */
    public function libraryCounts(): array
    {
        global $wpdb;

        $table = $this->table();

        // Not prepared: $table is built from $wpdb->prefix, and an identifier
        // cannot be a bound parameter in any case. No user input reaches this.
        $unassigned = (int) $wpdb->get_var(
            "SELECT COUNT(*)
             FROM {$wpdb->posts} p
             WHERE p.post_type = 'attachment'
               AND p.post_status <> 'trash'
               AND NOT EXISTS (
                   SELECT 1 FROM {$table} a WHERE a.attachment_id = p.ID
               )"
        );

        // Core's own, and it is cached — cheaper than a second COUNT(*) and it
        // already applies the same trash rule.
        $all = (int) array_sum((array) wp_count_attachments());

        return [
            'all' => $all,
            'unassigned' => $unassigned,
        ];
    }

    public function subtreeCount(string $path): int
    {
        $folders = $this->wpdb->prefix . 'folderfolio_folders';

        return (int) $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(DISTINCT a.attachment_id)
                 FROM {$this->table()} AS a
                 INNER JOIN {$folders} AS f ON f.id = a.folder_id
                 WHERE f.path LIKE %s",
                $this->wpdb->esc_like($path) . '%'
            )
        );
    }

    /**
     * Attachment ids in a folder and everything beneath it.
     *
     * @return list<int>
     */
    public function subtreeAttachmentIds(string $path): array
    {
        $folders = $this->wpdb->prefix . 'folderfolio_folders';

        $ids = $this->wpdb->get_col(
            $this->wpdb->prepare(
                "SELECT DISTINCT a.attachment_id
                 FROM {$this->table()} AS a
                 INNER JOIN {$folders} AS f ON f.id = a.folder_id
                 WHERE f.path LIKE %s",
                $this->wpdb->esc_like($path) . '%'
            )
        ) ?: [];

        return array_map('intval', $ids);
    }

    /**
     * Remove every assignment for a set of folders.
     *
     * Used when a subtree is deleted: one statement instead of one per folder.
     *
     * @param list<int> $folderIds
     * @return bool|WP_Error
     */
    public function deleteForFolders(array $folderIds): bool|WP_Error
    {
        if ($folderIds === []) {
            return true;
        }

        $placeholders = implode(',', array_fill(0, count($folderIds), '%d'));

        $deleted = $this->wpdb->query(
            $this->wpdb->prepare(
                "DELETE FROM {$this->table()} WHERE folder_id IN ({$placeholders})",
                ...$folderIds
            )
        );

        if ($deleted === false) {
            return new WP_Error(
                'folderfolio_assignment_delete_failed',
                __('Unable to remove the folder assignments.', 'folderfolio')
            );
        }

        return true;
    }

    /**
     * File everything in one folder into another as well — a pasted copy's
     * half of "with files".
     *
     * One INSERT … SELECT, so a folder of ten thousand files is one statement
     * and not ten thousand. Each file keeps its position (`sort_order`), which
     * is what makes the copy a copy rather than the same files in a different
     * order. `import_run` is left null: these rows are a person's work, and an
     * undo of some earlier import must not reach into a folder that did not
     * exist when it ran. INSERT IGNORE, because the destination is a folder
     * created a moment ago in the same transaction and has no rows — a
     * collision would mean something else wrote there first, and the row it
     * wrote is the one to keep.
     *
     * Returns no count on purpose: MySQL's affected-rows here depends on what
     * the destination already held (trap 65). The caller read the source's
     * ids to check them, and that list is the number.
     */
    public function copyFolder(int $fromFolderId, int $toFolderId): bool|WP_Error
    {
        $inserted = $this->wpdb->query(
            $this->wpdb->prepare(
                "INSERT IGNORE INTO {$this->table()}
                     (folder_id, attachment_id, sort_order, assigned_at, import_run)
                 SELECT %d, attachment_id, sort_order, %s, NULL
                 FROM {$this->table()}
                 WHERE folder_id = %d",
                $toFolderId,
                current_time('mysql', true),
                $fromFolderId
            )
        );

        if ($inserted === false) {
            return new WP_Error(
                'folderfolio_assignment_failed',
                __('Unable to assign media to the folder.', 'folderfolio')
            );
        }

        return true;
    }

    /**
     * Remove every assignment one import run created.
     *
     * This is how an import is undone without the run having stored a list of
     * everything it filed — there can be a hundred thousand pairs, and the run
     * lives in an option.
     *
     * It used to be a window on `assigned_at`, and that was wrong. `assigned_at`
     * is second-granular, so a file filed by hand in the same second the run
     * started was inside it; worse, so was anything filed by hand *during* a
     * long import, which screen 07 invites by saying the run continues after
     * you close the tab. Undo promises to keep what the import did not do, and
     * a time window cannot tell the difference. A column on the row can.
     *
     * @return int Rows removed.
     */
    public function deleteAssignedByRun(string $importRun): int
    {
        if ($importRun === '') {
            return 0;
        }

        $deleted = $this->wpdb->query(
            $this->wpdb->prepare(
                "DELETE FROM {$this->table()} WHERE import_run = %s",
                $importRun
            )
        );

        return (int) $deleted;
    }

    /**
     * Drop assignment rows that point at nothing.
     *
     * Two kinds, and they arrive by different routes. A row whose folder is
     * gone is left by a delete that failed part-way — the folder row went, the
     * assignments did not. A row whose attachment is gone is left by anything
     * that removed a post without firing `delete_attachment`: a direct SQL
     * delete, a migration, or a version of this plugin before that hook
     * existed.
     *
     * Doctor reports both and repairs neither; this is the repair, run from
     * the Status tab by somebody who has read what it found. Both statements
     * are NOT EXISTS rather than joins, because a LEFT JOIN … IS NULL delete
     * needs a different syntax on MySQL and MariaDB.
     *
     * @return int Rows removed.
     */
    public function deleteOrphans(): int
    {
        $table = $this->table();
        $folders = $this->wpdb->prefix . 'folderfolio_folders';

        // Identifiers cannot be bound and both are built from $wpdb->prefix.
        $removed = (int) $this->wpdb->query(
            "DELETE FROM {$table}
             WHERE NOT EXISTS (
                 SELECT 1 FROM {$folders} f WHERE f.id = {$table}.folder_id
             )"
        );

        $removed += (int) $this->wpdb->query(
            "DELETE FROM {$table}
             WHERE NOT EXISTS (
                 SELECT 1 FROM {$this->wpdb->posts} p WHERE p.ID = {$table}.attachment_id
             )"
        );

        return $removed;
    }
}
