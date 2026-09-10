<?php

declare(strict_types=1);

namespace FolderFolio\Modules\Importers;

/**
 * Factory for creating importer instances.
 */
class ImporterFactory
{
    /**
     * List of supported importers.
     *
     * @var array<string, class-string<ImporterInterface>>
     */
    private const IMPORTERS = [
        'filebird' => FileBirdImporter::class,
        // Future: Add more importers here.
        // 'wp-real-media-library' => WpRealMediaLibraryImporter::class,
        // 'media-library-folders' => MediaLibraryFoldersImporter::class,
    ];

    /**
     * Get all available importers.
     *
     * @return array<string, array{
     *     name: string,
     *     installed: bool,
     *     folder_count: int,
     *     attachment_count: int
     * }>
     */
    public function getAvailableImporters(): array
    {
        $importers = [];

        foreach (self::IMPORTERS as $key => $class) {
            if (!class_exists($class)) {
                continue;
            }

            $importer = new $class();

            $installed = $importer->isInstalled();

            $importers[$key] = [
                'name' => $importer->getName(),
                'installed' => $installed,
                'folder_count' => $installed ? $importer->getFolderCount() : 0,
                'attachment_count' => $installed ? $importer->getAttachmentCount() : 0,
            ];
        }

        return $importers;
    }

    /**
     * Create importer instance by key.
     *
     * @throws \InvalidArgumentException When the importer key or class is invalid.
     */
    public function create(string $key): ImporterInterface
    {
        if (!isset(self::IMPORTERS[$key])) {
            throw new \InvalidArgumentException(
                "Unknown importer: {$key}"
            );
        }

        $class = self::IMPORTERS[$key];

        if (!class_exists($class)) {
            throw new \InvalidArgumentException(
                "Importer class not found: {$class}"
            );
        }

        return new $class();
    }

    /**
     * Detect installed competitor plugins.
     *
     * @return array<string, string>
     */
    public function detectInstalled(): array
    {
        $installed = [];

        foreach (self::IMPORTERS as $key => $class) {
            if (!class_exists($class)) {
                continue;
            }

            $importer = new $class();

            if ($importer->isInstalled()) {
                $installed[$key] = $importer->getName();
            }
        }

        return $installed;
    }
}
