<?php

declare(strict_types=1);

namespace FolderFolio\Modules\Importers;

use WP_Error;
use wpdb;

/**
 * Importer for FileBird (free & Pro) by NinjaTeam.
 *
 * FileBird stores folders in:
 * - wp_fbv_folders (free)
 * - wp_fbr_folders (Pro/custom)
 * - wp_fbv_assignments (free)
 * - wp_fbr_assignments (Pro/custom)
 */
class FileBirdImporter implements ImporterInterface
{
    private wpdb $wpdb;
    private array $warnings = [];

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
    }

    public function getName(): string
    {
        return 'FileBird';
    }

    public function isInstalled(): bool
    {
        // Check if FileBird tables exist
        $freeFoldersTable = $this->wpdb->prefix . 'fbv_folders';
        $proFoldersTable = $this->wpdb->prefix . 'fbr_folders';

        $freeExists = $this->wpdb->get_var("SHOW TABLES LIKE '{$freeFoldersTable}'") === $freeFoldersTable;
        $proExists = $this->wpdb->get_var("SHOW TABLES LIKE '{$proFoldersTable}'") === $proFoldersTable;

        return $freeExists || $proExists;
    }

    public function getFolderCount(): int
    {
        $freeFoldersTable = $this->wpdb->prefix . 'fbv_folders';
        $proFoldersTable = $this->wpdb->prefix . 'fbr_folders';

        $freeCount = (int) $this->wpdb->get_var("SELECT COUNT(*) FROM {$freeFoldersTable}");
        $proCount = (int) $this->wpdb->get_var("SELECT COUNT(*) FROM {$proFoldersTable}");

        return $freeCount + $proCount;
    }

    public function getAttachmentCount(): int
    {
        $freeAssignmentsTable = $this->wpdb->prefix . 'fbv_assignments';
        $proAssignmentsTable = $this->wpdb->prefix . 'fbr_assignments';

        $freeCount = (int) $this->wpdb->get_var("SELECT COUNT(*) FROM {$freeAssignmentsTable}");
        $proCount = (int) $this->wpdb->get_var("SELECT COUNT(*) FROM {$proAssignmentsTable}");

        return $freeCount + $proCount;
    }

    public function import(): array|WP_Error
    {
        if (!$this->isInstalled()) {
            return new WP_Error('filebird_not_installed', 'FileBird is not installed or active.');
        }

        $importedFolders = 0;
        $importedAssignments = 0;
        $errors = [];

        // Import free version folders
        $freeFoldersTable = $this->wpdb->prefix . 'fbv_folders';
        if ($this->wpdb->get_var("SHOW TABLES LIKE '{$freeFoldersTable}'") === $freeFoldersTable) {
            $result = $this->importFromTable($freeFoldersTable, $this->wpdb->prefix . 'fbv_assignments');
            $importedFolders += $result['folders'];
            $importedAssignments += $result['assignments'];
            $errors = array_merge($errors, $result['errors']);
        }

        // Import Pro version folders
        $proFoldersTable = $this->wpdb->prefix . 'fbr_folders';
        if ($this->wpdb->get_var("SHOW TABLES LIKE '{$proFoldersTable}'") === $proFoldersTable) {
            $result = $this->importFromTable($proFoldersTable, $this->wpdb->prefix . 'fbr_assignments');
            $importedFolders += $result['folders'];
            $importedAssignments += $result['assignments'];
            $errors = array_merge($errors, $result['errors']);
        }

        return [
            'imported_folders' => $importedFolders,
            'imported_assignments' => $importedAssignments,
            'errors' => $errors,
        ];
    }

    private function importFromTable(string $foldersTable, string $assignmentsTable): array
    {
        $importedFolders = 0;
        $importedAssignments = 0;
        $errors = [];

        // Map to store old folder ID -> new folder ID
        $folderIdMap = [];

        // Import folders
        $folders = $this->wpdb->get_results("SELECT * FROM {$foldersTable} ORDER BY parent_id ASC, sort_order ASC", ARRAY_A);

        foreach ($folders as $folder) {
            $newFolderId = $this->importFolder($folder, $folderIdMap);

            if (is_wp_error($newFolderId)) {
                $errors[] = $newFolderId->get_error_message();
                continue;
            }

            $folderIdMap[(int) $folder['id']] = $newFolderId;
            $importedFolders++;
        }

        // Import assignments
        $assignments = $this->wpdb->get_results("SELECT * FROM {$assignmentsTable}", ARRAY_A);

        foreach ($assignments as $assignment) {
            $oldFolderId = (int) $assignment['folder_id'];
            $attachmentId = (int) $assignment['attachment_id'];

            if (!isset($folderIdMap[$oldFolderId])) {
                $errors[] = "Folder ID {$oldFolderId} not found for assignment";
                continue;
            }

            $newFolderId = $folderIdMap[$oldFolderId];
            $result = $this->importAssignment($newFolderId, $attachmentId);

            if (is_wp_error($result)) {
                $errors[] = $result->get_error_message();
                continue;
            }

            $importedAssignments++;
        }

        return [
            'folders' => $importedFolders,
            'assignments' => $importedAssignments,
            'errors' => $errors,
        ];
    }

    private function importFolder(array $folder, array $folderIdMap): int|WP_Error
    {
        $parentFolderId = $folder['parent_id'] !== null && $folder['parent_id'] !== '0' && $folder['parent_id'] !== 0
            ? ($folderIdMap[(int) $folder['parent_id']] ?? null)
            : null;

        $result = $this->wpdb->insert($this->wpdb->prefix . 'folderfolio_folders', [
            'parent_id' => $parentFolderId,
            'name' => $folder['name'] ?? 'Imported Folder',
            'slug' => sanitize_title($folder['name'] ?? ''),
            'color' => !empty($folder['color']) ? $folder['color'] : null,
            'icon' => !empty($folder['icon']) ? $folder['icon'] : null,
            'sort_order' => (int) ($folder['sort_order'] ?? 0),
            'created_by' => get_current_user_id(),
            'created_at' => current_time('mysql', true),
            'updated_at' => current_time('mysql', true),
        ]);

        if ($result === false) {
            return new WP_Error(
                'folderfolio_import_failed',
                "Failed to import folder: {$folder['name']} ({$this->wpdb->last_error})"
            );
        }

        return (int) $this->wpdb->insert_id;
    }

    private function importAssignment(int $folderId, int $attachmentId): bool|WP_Error
    {
        $result = $this->wpdb->insert($this->wpdb->prefix . 'folderfolio_attachment_folders', [
            'folder_id' => $folderId,
            'attachment_id' => $attachmentId,
            'sort_order' => 0,
            'assigned_at' => current_time('mysql', true),
        ]);

        if ($result === false) {
            return new WP_Error(
                'folderfolio_assignment_failed',
                "Failed to import assignment for attachment {$attachmentId} ({$this->wpdb->last_error})"
            );
        }

        return true;
    }

    public function getWarnings(): array
    {
        return $this->warnings;
    }
}
