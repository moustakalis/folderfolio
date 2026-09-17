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

    public function assign(int $folderId, int $attachmentId, int $sortOrder = 0): bool|WP_Error
    {
        $result = $this->wpdb->replace($this->table(), [
            'folder_id' => $folderId,
            'attachment_id' => $attachmentId,
            'sort_order' => $sortOrder,
            'assigned_at' => current_time('mysql', true),
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
     * Direct attachment count for every folder that has at least one.
     *
     * One GROUP BY for the whole tree. Folders with no attachments are absent
     * from the result; callers default them to 0.
     *
     * This is the exact, cheap number. FolderTree::withCounts() rolls these up
     * the tree in PHP for the inherited badge, and subtreeCount() below gives
     * the exact subtree figure for the one folder in view.
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
     * the same subtree, and the roll-up in FolderTree would count it twice.
     * That roll-up is fast and directionally right for the tree badge; this is
     * the number shown above the grid, and where they disagree this one wins.
     */
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
}
