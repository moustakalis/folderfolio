<?php

declare(strict_types=1);

namespace FolderFolio\Modules\Importers;

use WP_Error;

/**
 * Interface for folder importers from competing plugins.
 *
 * @phpstan-type ImportResult array{
 *     imported_folders: int,
 *     imported_assignments: int,
 *     errors: list<string>
 * }
 */
interface ImporterInterface
{
    /**
     * Plugin name (for example, FileBird).
     */
    public function getName(): string;

    /**
     * Check whether this source plugin is installed and active.
     */
    public function isInstalled(): bool;

    /**
     * Get the source plugin's folder count.
     */
    public function getFolderCount(): int;

    /**
     * Get the source plugin's attachment-assignment count.
     */
    public function getAttachmentCount(): int;

    /**
     * Import folders and attachment assignments.
     *
     * @return ImportResult|WP_Error
     */
    public function import(): array|WP_Error;

    /**
     * Get non-fatal detected warnings.
     *
     * @return list<string>
     */
    public function getWarnings(): array;
}
