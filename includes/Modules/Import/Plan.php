<?php

declare(strict_types=1);

namespace FolderFolio\Modules\Import;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * What an import would do, before it does any of it.
 *
 * Screen 07's second step is four lines and a heading that reads "nothing has
 * been written yet". This is the object behind them.
 *
 * It exists because of what running CatFolders' own FileBird import over a
 * hand-built tree did, with no confirmation, preview, progress or undo: five
 * duplicate top-level folders, three of our folders emptied, and Uncategorized
 * down from 14 files to 5. Every field below is a number somebody would have
 * wanted to see first.
 *
 * @phpstan-type Entry array{source_id: int, path: string}
 */
final class Plan
{
    /**
     * @param list<Entry>                                        $create    Folders this import would add.
     * @param list<Entry>                                        $merge     Folders that already exist here by name.
     * @param list<Entry>                                        $reconcile Folders a previous run of this source already made.
     * @param list<Entry>                                        $duplicate Same-named siblings in the source, which become one folder here.
     * @param list<int>                                          $skipped   Attachment ids the source names that no longer exist.
     * @param list<array{id: int, name: string, reason: string}> $unreachable
     */
    public function __construct(
        public readonly string $sourceKey,
        public readonly string $sourceLabel,
        public readonly array $create,
        public readonly array $merge,
        public readonly array $reconcile,
        public readonly array $duplicate,
        public readonly int $filesToAdd,
        public readonly int $filesAlreadyFiled,
        public readonly array $skipped,
        public readonly array $unreachable,
        public readonly int $filesNotImages = 0
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->create && 0 === $this->filesToAdd;
    }

    /**
     * The shape the wizard renders.
     *
     * Samples rather than whole lists: the detail line under each heading is
     * one sentence of examples, and a site with four thousand folders should
     * not send four thousand names to the browser to fill it.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'source' => $this->sourceKey,
            'label' => $this->sourceLabel,
            'counts' => [
                'create' => count($this->create),
                'merge' => count($this->merge),
                'reconcile' => count($this->reconcile),
                'duplicate' => count($this->duplicate),
                'files' => $this->filesToAdd,
                'already' => $this->filesAlreadyFiled,
                // Not images, named for a gallery: left where they are (L4).
                'not_images' => $this->filesNotImages,
                'skipped' => count($this->skipped),
                'unreachable' => count($this->unreachable),
            ],
            'samples' => [
                'create' => self::sample(array_column($this->create, 'path')),
                'merge' => self::sample(array_column($this->merge, 'path')),
                'reconcile' => self::sample(array_column($this->reconcile, 'path')),
                'duplicate' => self::sample(array_column($this->duplicate, 'path')),
                'skipped' => self::sample(array_map('strval', $this->skipped)),
                'unreachable' => self::sample(array_column($this->unreachable, 'name')),
            ],
        ];
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private static function sample(array $values, int $limit = 8): array
    {
        return array_values(array_slice($values, 0, $limit));
    }
}
