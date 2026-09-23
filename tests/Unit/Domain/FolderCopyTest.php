<?php

declare(strict_types=1);

namespace FolderFolio\Tests\Unit\Domain;

use FolderFolio\Domain\FolderCopy;
use PHPUnit\Framework\TestCase;

final class FolderCopyTest extends TestCase
{
    private const SINGLE = '%s copy';
    private const NUMBERED = '%1$s copy %2$d';

    /** @param list<string> $names */
    private static function name(string $name, array $names): string
    {
        return FolderCopy::name(
            $name,
            static fn (string $candidate): bool => in_array($candidate, $names, true),
            self::SINGLE,
            self::NUMBERED
        );
    }

    public function test_a_copy_with_nothing_to_collide_with_keeps_its_name(): void
    {
        self::assertSame('Brand', self::name('Brand', ['Archive']));
    }

    public function test_the_first_copy_beside_its_source_is_called_copy(): void
    {
        self::assertSame('Brand copy', self::name('Brand', ['Brand']));
    }

    public function test_a_second_paste_numbers_from_two(): void
    {
        self::assertSame('Brand copy 2', self::name('Brand', ['Brand', 'Brand copy']));
        self::assertSame('Brand copy 4', self::name('Brand', ['Brand', 'Brand copy', 'Brand copy 2', 'Brand copy 3']));
    }

    public function test_a_gap_in_the_numbering_is_filled_rather_than_skipped(): void
    {
        self::assertSame('Brand copy 2', self::name('Brand', ['Brand', 'Brand copy', 'Brand copy 3']));
    }

    public function test_the_translator_owns_the_order_of_name_and_suffix(): void
    {
        $name = FolderCopy::name(
            'Brand',
            static fn (string $c): bool => in_array($c, ['Brand', 'Copie de Brand'], true),
            'Copie de %s',
            'Copie %2$d de %1$s'
        );

        self::assertSame('Copie 2 de Brand', $name);
    }

    public function test_a_name_at_the_column_limit_loses_its_end_not_its_suffix(): void
    {
        $long = str_repeat('é', FolderCopy::MAX_NAME);

        $first = self::name($long, [$long]);
        self::assertSame(FolderCopy::MAX_NAME, mb_strlen($first));
        self::assertStringEndsWith(' copy', $first);

        $second = self::name($long, [$long, $first]);
        self::assertSame(FolderCopy::MAX_NAME, mb_strlen($second));
        self::assertStringEndsWith(' copy 2', $second);
        self::assertNotSame($first, $second);
    }

    public function test_the_placeholder_takes_the_copy_id_in_place(): void
    {
        self::assertSame([4, 99, 7], FolderCopy::place([4, 0, 7], 99));
        self::assertSame([4, 7, 99], FolderCopy::place([4, 7, 0], 99));
    }

    public function test_a_level_without_exactly_one_placeholder_is_refused(): void
    {
        self::assertNull(FolderCopy::place([4, 7], 99));
        self::assertNull(FolderCopy::place([0, 4, 0], 99));
    }

    public function test_a_level_naming_a_folder_twice_or_naming_the_copy_is_refused(): void
    {
        self::assertNull(FolderCopy::place([4, 0, 4], 99));
        self::assertNull(FolderCopy::place([99, 0], 99));
    }
}
