<?php

declare(strict_types=1);

namespace FolderFolio\Domain;

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

    public function deleteForFolder(int $folderId): bool|WP_Error
    {
        $result = $this->wpdb->delete($this->table(), ['folder_id' => $folderId]);

        if ($result === false) {
            return new WP_Error('folderfolio_assignment_cleanup_failed', __('Unable to remove folder assignments.', 'folderfolio'));
        }

        return true;
    }
}
