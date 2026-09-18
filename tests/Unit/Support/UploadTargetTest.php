<?php

declare(strict_types=1);

namespace FolderFolio\Tests\Unit\Support;

use FolderFolio\Support\UploadTarget;
use PHPUnit\Framework\TestCase;

/**
 * What a request is allowed to say about where an upload goes.
 *
 * This is the whole of the plugin's reading of that parameter, and it is the
 * one place a caller-supplied value turns into a folder id. Everything it
 * lets through is handed to FolderService, which checks the capability; what
 * matters here is that nothing but a folder id gets that far.
 *
 * The path cases are the point. FileBird and CatFolders both accept
 * `12/Brand/Logos` on this parameter and *create* the segments that do not
 * exist, which turns an upload into a way to write folders. Ours returns null
 * for every one of those shapes.
 */
final class UploadTargetTest extends TestCase
{
    public function test_a_folder_id_is_read(): void
    {
        self::assertSame(3, UploadTarget::read(['folderfolio_folder' => '3']));
        self::assertSame(3, UploadTarget::read(['folderfolio_folder' => 3]));
        self::assertSame(12345, UploadTarget::read(['folderfolio_folder' => '12345']));
    }

    public function test_surrounding_whitespace_is_not_a_different_folder(): void
    {
        self::assertSame(3, UploadTarget::read(['folderfolio_folder' => ' 3 ']));
    }

    public function test_the_two_non_folders_are_null(): void
    {
        // 0 is Unassigned and an absent parameter is All media. Neither is a
        // folder, and an upload made while looking at either is exactly the
        // upload that should stay unfiled.
        self::assertNull(UploadTarget::read(['folderfolio_folder' => '0']));
        self::assertNull(UploadTarget::read([]));
    }

    /**
     * @dataProvider rejected
     * @param mixed $value
     */
    public function test_anything_that_is_not_a_folder_id_is_null($value): void
    {
        self::assertNull(UploadTarget::read(['folderfolio_folder' => $value]));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function rejected(): array
    {
        return [
            // The one the market gets wrong.
            'a path' => ['12/Brand/Logos'],
            'a path with a leading slash' => ['/Brand/Logos'],
            'a bare name' => ['Brand'],

            'negative' => ['-1'],
            'a float' => ['3.7'],
            'scientific' => ['1e3'],
            'hex' => ['0x3'],
            'empty' => [''],
            'whitespace' => ['   '],
            'an array' => [['3']],
            'true' => [true],
            'false' => [false],
            'a digit with a suffix' => ['3; DROP TABLE'],
            'a digit with a comma' => ['3,4'],
        ];
    }

    public function test_the_parameter_is_the_one_the_library_filters_by(): void
    {
        // One name for "which folder this request is about". If this ever
        // needs to change, lib/filter.ts and Admin\MediaLibraryFilter change
        // with it.
        self::assertSame('folderfolio_folder', UploadTarget::PARAM);
    }
}
