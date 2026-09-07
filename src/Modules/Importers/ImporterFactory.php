<?php

declare(strict_types=1);

namespace FolderFolio\Modules\Importers;

use WP_Error;

/**
 * Factory for creating importer instances.
 */
class ImporterFactory
{
    /**
     * List of supported importers.
     */
    private const IMPORTERS = [
        'filebird' => FileBirdImporter::class,
        // Future: Add more importers here
        // 'wp-real-media-library' => WpRealMediaLibraryImporter::class,
        // 'media-library-folders' => MediaLibraryFoldersImporter::class,
    ];

    /**
     * Get all available importers.
     *
     * @return array<string, array{name: string, installed: bool, folder_count: int, attachment_count: int}>
     */
    public function getAvailableImporters(): array
    {
        $importers = [];

        foreach (self::IMPORTERS as $key => $class) {
            if (!class_exists($class)) {
                continue;
            }

            /** @var ImporterInterface $importer */
            $importer = new $class();

            $importers[$key] = [
                'name' => $importer->getName(),
                'installed' => $importer->isInstalled(),
                'folder_count' => $importer->isInstalled() ? $importer->getFolderCount() : 0,
                'attachment_count' => $importer->isInstalled() ? $importer->getAttachmentCount() : 0,
            ];
        }

        return $importers;
    }

    /**
     * Create importer instance by key.
     *
     * @throws \InvalidArgumentException
     */
    public function create(string $key): ImporterInterface
    {
        if (!isset(self::IMPORTERS[$key])) {
            throw new \InvalidArgumentException("Unknown importer: {$key}");
        }

        $class = self::IMPORTERS[$key];

        if (!class_exists($class)) {
            throw new \InvalidArgumentException("Importer class not found: {$class}");
        }

        return new $class();
    }

    /**
     * Detect which competitor plugins are installed.
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

            /** @var ImporterInterface $importer */
            $importer = new $class();

            if ($importer->isInstalled()) {
                $installed[$key] = $importer->getName();
            }
        }

        return $installed;
    }
}
