<?php

declare(strict_types=1);

namespace FolderFolio\Modules\Importers;

use WP_Error;

/**
 * Interface for folder importers from competing plugins.
 */
interface ImporterInterface
{
    /**
     * Plugin name (e.g., 'FileBird').
     */
    public function getName(): string;

    /**
     * Check if this plugin is installed and active.
     */
    public function isInstalled(): bool;

    /**
     * Get folder count from source plugin.
     */
    public function getFolderCount(): int;

    /**
     * Get attachment count from source plugin.
     */
    public function getAttachmentCount(): int;

    /**
     * Import folders and assignments.
     *
     * @return array{imported_folders: int, imported_assignments: int, errors: array}
     */
    public function import(): array|WP_Error;

    /**
     * Get detected issues or warnings.
     */
    public function getWarnings(): array;
}
