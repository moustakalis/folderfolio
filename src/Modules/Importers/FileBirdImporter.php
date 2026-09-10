<?php

declare(strict_types=1);

namespace FolderFolio\Modules\Importers;

use WP_Error;
use wpdb;

/**
 * Importer for FileBird (free and Pro) by NinjaTeam.
 *
 * FileBird stores folders in:
 * - wp_fbv_folders (free)
 * - wp_fbr_folders (Pro/custom)
 * - wp_fbv_assignments (free)
 * - wp_fbr_assignments (Pro/custom)
 *
 * @phpstan-type ImportResult array{
 *     imported_folders: int,
 *     imported_assignments: int,
 *     errors: list<string>
 * }
 *
 * @phpstan-type TableImportResult array{
 *     folders: int,
 *     assignments: int,
 *     errors: list<string>
 * }
 *
 * @phpstan-type FileBirdFolder array{
 *     id: int|string,
 *     parent_id?: int|string|null,
 *     name?: string,
 *     color?: string|null,
 *     icon?: string|null,
 *     sort_order?: int|string
 * }
 *
 * @phpstan-type FileBirdAssignment array{
 *     folder_id: int|string,
 *     attachment_id: int|string
 * }
 */
class FileBirdImporter implements ImporterInterface
{
    private wpdb $wpdb;

    /** @var list<string> */
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
        return $this->tableExists($this->freeFoldersTable())
            || $this->tableExists($this->proFoldersTable());
    }

    public function getFolderCount(): int
    {
        $count = 0;

        foreach ([$this->freeFoldersTable(), $this->proFoldersTable()] as $table) {
            if ($this->tableExists($table)) {
                $count += (int) $this->wpdb->get_var("SELECT COUNT(*) FROM {$table}");
            }
        }

        return $count;
    }

    public function getAttachmentCount(): int
    {
        $count = 0;

        foreach ([$this->freeAssignmentsTable(), $this->proAssignmentsTable()] as $table) {
            if ($this->tableExists($table)) {
                $count += (int) $this->wpdb->get_var("SELECT COUNT(*) FROM {$table}");
            }
        }

        return $count;
    }

    /**
     * Import FileBird folders and attachment assignments.
     *
     * @return ImportResult|WP_Error
     */
    public function import(): array|WP_Error
    {
        if (! $this->isInstalled()) {
            return new WP_Error(
                'filebird_not_installed',
                'FileBird is not installed or active.'
            );
        }

        $importedFolders = 0;
        $importedAssignments = 0;

        /** @var list<string> $errors */
        $errors = [];

        $sources = [
            [
                'folders' => $this->freeFoldersTable(),
                'assignments' => $this->freeAssignmentsTable(),
            ],
            [
                'folders' => $this->proFoldersTable(),
                'assignments' => $this->proAssignmentsTable(),
            ],
        ];

        foreach ($sources as $source) {
            if (! $this->tableExists($source['folders'])) {
                continue;
            }

            $result = $this->importFromTable(
                $source['folders'],
                $source['assignments']
            );

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

    /**
     * Import one FileBird schema variant.
     *
     * @return TableImportResult
     */
    private function importFromTable(
        string $foldersTable,
        string $assignmentsTable
    ): array {
        $importedFolders = 0;
        $importedAssignments = 0;

        /** @var list<string> $errors */
        $errors = [];

        /** @var array<int, int> $folderIdMap */
        $folderIdMap = [];

        /** @var list<FileBirdFolder> $folders */
        $folders = $this->wpdb->get_results(
            "SELECT * FROM {$foldersTable} ORDER BY parent_id ASC, sort_order ASC",
            ARRAY_A
        ) ?: [];

        foreach ($folders as $folder) {
            $newFolderId = $this->importFolder($folder, $folderIdMap);

            if (is_wp_error($newFolderId)) {
                $errors[] = $newFolderId->get_error_message();
                continue;
            }

            $folderIdMap[(int) $folder['id']] = $newFolderId;
            $importedFolders++;
        }

        if (! $this->tableExists($assignmentsTable)) {
            return [
                'folders' => $importedFolders,
                'assignments' => $importedAssignments,
                'errors' => $errors,
            ];
        }

        /** @var list<FileBirdAssignment> $assignments */
        $assignments = $this->wpdb->get_results(
            "SELECT * FROM {$assignmentsTable}",
            ARRAY_A
        ) ?: [];

        foreach ($assignments as $assignment) {
            $oldFolderId = (int) $assignment['folder_id'];
            $attachmentId = (int) $assignment['attachment_id'];

            if (! isset($folderIdMap[$oldFolderId])) {
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

    /**
     * Import one FileBird folder.
     *
     * @param FileBirdFolder $folder
     * @param array<int, int> $folderIdMap
     * @return int|WP_Error
     */
    private function importFolder(array $folder, array $folderIdMap): int|WP_Error
    {
        $oldParentId = $folder['parent_id'] ?? null;

        $parentFolderId = $oldParentId !== null
        && $oldParentId !== ''
        && (int) $oldParentId !== 0
            ? ($folderIdMap[(int) $oldParentId] ?? null)
            : null;

        $folderName = $folder['name'] ?? 'Imported Folder';

        $result = $this->wpdb->insert(
            $this->wpdb->prefix . 'folderfolio_folders',
            [
                'parent_id' => $parentFolderId,
                'name' => $folderName,
                'slug' => sanitize_title($folderName),
                'color' => ! empty($folder['color']) ? $folder['color'] : null,
                'icon' => ! empty($folder['icon']) ? $folder['icon'] : null,
                'sort_order' => (int) ($folder['sort_order'] ?? 0),
                'created_by' => get_current_user_id(),
                'created_at' => current_time('mysql', true),
                'updated_at' => current_time('mysql', true),
            ]
        );

        if ($result === false) {
            return new WP_Error(
                'folderfolio_import_failed',
                sprintf(
                    'Failed to import folder "%s": %s',
                    $folderName,
                    $this->wpdb->last_error
                )
            );
        }

        return (int) $this->wpdb->insert_id;
    }

    /**
     * Import one attachment-folder assignment.
     *
     * @return true|WP_Error
     */
    private function importAssignment(int $folderId, int $attachmentId): bool|WP_Error
    {
        $result = $this->wpdb->insert(
            $this->wpdb->prefix . 'folderfolio_attachment_folders',
            [
                'folder_id' => $folderId,
                'attachment_id' => $attachmentId,
                'sort_order' => 0,
                'assigned_at' => current_time('mysql', true),
            ]
        );

        if ($result === false) {
            return new WP_Error(
                'folderfolio_assignment_failed',
                sprintf(
                    'Failed to import assignment for attachment %d: %s',
                    $attachmentId,
                    $this->wpdb->last_error
                )
            );
        }

        return true;
    }

    /**
     * Get accumulated non-fatal import warnings.
     *
     * @return list<string>
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    private function freeFoldersTable(): string
    {
        return $this->wpdb->prefix . 'fbv_folders';
    }

    private function proFoldersTable(): string
    {
        return $this->wpdb->prefix . 'fbr_folders';
    }

    private function freeAssignmentsTable(): string
    {
        return $this->wpdb->prefix . 'fbv_assignments';
    }

    private function proAssignmentsTable(): string
    {
        return $this->wpdb->prefix . 'fbr_assignments';
    }

    private function tableExists(string $table): bool
    {
        return $this->wpdb->get_var(
                $this->wpdb->prepare('SHOW TABLES LIKE %s', $table)
            ) === $table;
    }
}
