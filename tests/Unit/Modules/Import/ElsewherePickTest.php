<?php

declare(strict_types=1);

namespace FolderFolio\Tests\Unit\Modules\Import;

use FolderFolio\Modules\Import\Elsewhere;
use PHPUnit\Framework\TestCase;

/**
 * Which plugin the rail's empty state names.
 *
 * The queries behind this need four competitors' tables in a database and are
 * proved in a throwaway WordPress; the *choice* needs nine arrays and belongs
 * here, in the suite that runs on every change.
 *
 * What it has to get right: name the one holding most of somebody's work, say
 * how many others there are, and say nothing at all when nobody has anything —
 * because the caller reads null as "there is no true sentence to put here".
 */
final class ElsewherePickTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function source(string $key, array $overrides = []): array
    {
        return array_merge([
            'key' => $key,
            'label' => ucfirst($key),
            'has_data' => true,
            'plugin_active' => false,
            'folders' => 1,
            'assignments' => 1,
            'imported_at' => null,
        ], $overrides);
    }

    public function test_nothing_detected_is_nothing_to_say(): void
    {
        self::assertNull(Elsewhere::pick([]));
    }

    public function test_sources_without_data_are_not_sources(): void
    {
        // Catalog::detect() returns all nine every time, most of them empty.
        // Reading the list rather than the flag would name the first of nine
        // plugins nobody has installed.
        self::assertNull(Elsewhere::pick([
            $this->source('filebird', ['has_data' => false, 'folders' => 0, 'assignments' => 0]),
            $this->source('catfolders', ['has_data' => false, 'folders' => 0, 'assignments' => 0]),
        ]));
    }

    public function test_the_largest_is_named_and_the_rest_are_counted(): void
    {
        $picked = Elsewhere::pick([
            $this->source('catfolders', ['label' => 'CatFolders', 'folders' => 5, 'assignments' => 9]),
            $this->source('filebird', ['label' => 'FileBird', 'folders' => 20, 'assignments' => 28]),
            $this->source('wp-media-folder', ['has_data' => false, 'folders' => 0, 'assignments' => 0]),
            $this->source('real-media-library', ['label' => 'Real Media Library', 'folders' => 12, 'assignments' => 40]),
        ]);

        self::assertSame(
            [
                'key' => 'filebird',
                'label' => 'FileBird',
                'folders' => 20,
                'files' => 28,
                // Two others hold data. The empty one is not an "other".
                'others' => 2,
            ],
            $picked
        );
    }

    /**
     * Folders first, files second.
     *
     * A plugin with one folder holding a thousand files has not organised
     * anything; a plugin with forty folders has. The tie-break only decides
     * between two that organised the same amount.
     */
    public function test_files_break_a_tie_on_folders(): void
    {
        $picked = Elsewhere::pick([
            $this->source('catfolders', ['label' => 'CatFolders', 'folders' => 8, 'assignments' => 3]),
            $this->source('filebird', ['label' => 'FileBird', 'folders' => 8, 'assignments' => 30]),
        ]);

        self::assertSame('FileBird', $picked['label'] ?? null);
        self::assertSame(1, $picked['others'] ?? null);
    }

    public function test_one_source_has_no_others(): void
    {
        $picked = Elsewhere::pick([$this->source('filebird', ['label' => 'FileBird'])]);

        self::assertSame(0, $picked['others'] ?? null);
    }
}
