<?php

declare(strict_types=1);

namespace FolderFolio\Tests\Unit\Domain;

use FolderFolio\Domain\FolderPath;
use PHPUnit\Framework\TestCase;

/**
 * Annotations, not attributes.
 *
 * composer.json pins phpunit ^9.6, because that is what the WordPress test
 * library the integration suite runs against requires. PHPUnit 9 reads
 * `@dataProvider` and ignores `#[DataProvider]` **silently** — the test runs
 * with no arguments and errors with "too few arguments", which is how this was
 * found: green locally on nothing, red in CI on PHPUnit 9.6.
 *
 * @covers \FolderFolio\Domain\FolderPath
 */
final class FolderPathTest extends TestCase
{
    public function test_a_root_folder_path_is_its_own_id(): void
    {
        self::assertSame('/1/', FolderPath::build(null, 1));
        self::assertSame('/1/', FolderPath::build('', 1));
    }

    public function test_a_child_path_extends_its_parent(): void
    {
        self::assertSame('/1/7/', FolderPath::build('/1/', 7));
        self::assertSame('/1/7/12/', FolderPath::build('/1/7/', 12));
    }

    public function test_depth_counts_ancestors_not_segments(): void
    {
        self::assertSame(0, FolderPath::depth('/1/'));
        self::assertSame(1, FolderPath::depth('/1/7/'));
        self::assertSame(2, FolderPath::depth('/1/7/12/'));
        self::assertSame(0, FolderPath::depth(''));
    }

    public function test_ids_are_returned_outermost_first(): void
    {
        self::assertSame([1, 7, 12], FolderPath::ids('/1/7/12/'));
        self::assertSame([], FolderPath::ids(''));
        self::assertSame([], FolderPath::ids('/'));
    }

    public function test_ancestor_ids_exclude_the_folder_itself(): void
    {
        self::assertSame([1, 7], FolderPath::ancestorIds('/1/7/12/'));
        self::assertSame([], FolderPath::ancestorIds('/1/'));
    }

    public function test_parent_path_is_null_for_a_root_folder(): void
    {
        self::assertNull(FolderPath::parentPath('/1/'));
        self::assertSame('/1/', FolderPath::parentPath('/1/7/'));
        self::assertSame('/1/7/', FolderPath::parentPath('/1/7/12/'));
    }

    public function test_a_folder_is_within_its_own_subtree(): void
    {
        self::assertTrue(FolderPath::isWithin('/1/7/', '/1/7/'));
        self::assertTrue(FolderPath::isWithin('/1/7/12/', '/1/7/'));
        self::assertTrue(FolderPath::isWithin('/1/7/12/', '/1/'));
    }

    public function test_an_unrelated_folder_is_not_within_the_subtree(): void
    {
        self::assertFalse(FolderPath::isWithin('/2/', '/1/'));
        self::assertFalse(FolderPath::isWithin('/1/', '/1/7/'));
    }

    /**
     * The whole reason the trailing slash exists.
     *
     * Without it the subtree of folder 7 would be `LIKE '/1/7%'`, and folder
     * 70 — whose path starts `/1/70` — would match it. Every id that is a
     * string-prefix of another would leak into its subtree: filtering by
     * folder 7 would show folder 70's files, moving 7 would re-point 70, and
     * deleting 7 would delete 70. With the trailing slash the pattern demands
     * a literal `/` where `/1/70/` has a `0`, and none of that happens.
     *
     * @dataProvider prefixCollisions
     */
    public function test_an_id_that_is_a_string_prefix_does_not_leak(string $path, string $ancestor): void
    {
        self::assertFalse(
            FolderPath::isWithin($path, $ancestor),
            sprintf('%s must not be treated as inside %s', $path, $ancestor)
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function prefixCollisions(): array
    {
        return [
            '70 is not inside 7'      => ['/1/70/', '/1/7/'],
            '12 is not inside 1'      => ['/12/', '/1/'],
            '100 is not inside 10'    => ['/10/100/', '/10/10/'],
            'deep prefix collision'   => ['/1/7/120/', '/1/7/12/'],
        ];
    }

    public function test_rewriting_swaps_the_prefix_and_keeps_the_tail(): void
    {
        // Moving /1/7/ under /3/9/ turns its descendant /1/7/12/ into /3/9/7/12/.
        self::assertSame(
            '/3/9/7/12/',
            FolderPath::rewrite('/1/7/12/', '/1/7/', '/3/9/7/')
        );
    }

    public function test_rewriting_leaves_paths_outside_the_subtree_alone(): void
    {
        self::assertSame('/2/5/', FolderPath::rewrite('/2/5/', '/1/7/', '/3/9/7/'));
        self::assertSame('/1/70/', FolderPath::rewrite('/1/70/', '/1/7/', '/3/9/7/'));
    }

    public function test_the_subtree_pattern_keeps_the_trailing_slash(): void
    {
        self::assertSame('/1/7/%', FolderPath::subtreePattern('/1/7/'));
    }

    public function test_a_move_to_root_shortens_every_descendant(): void
    {
        $moved = FolderPath::build(null, 7);

        self::assertSame('/7/', $moved);
        self::assertSame('/7/12/', FolderPath::rewrite('/1/7/12/', '/1/7/', $moved));
    }

    public function test_the_depth_filter_is_held_to_what_the_column_holds(): void
    {
        // Review H1: a filter raised past what VARCHAR(255) holds used to make
        // the path write fail and leave the folder with an empty path.
        self::assertSame(FolderPath::DEPTH_CEILING, FolderPath::capDepth(500));
        self::assertSame(12, FolderPath::capDepth(12));
        self::assertSame(12, FolderPath::capDepth('12'));
        self::assertSame(0, FolderPath::capDepth(-3));
        self::assertSame(FolderPath::MAX_DEPTH, FolderPath::capDepth('lots'));
    }

    public function test_the_ceiling_fits_the_column_with_ten_digit_ids(): void
    {
        $path = null;
        for ($depth = 0; $depth <= FolderPath::DEPTH_CEILING; $depth++) {
            $path = FolderPath::build($path, 9_999_999_999);
        }

        self::assertSame(FolderPath::DEPTH_CEILING, FolderPath::depth($path));
        self::assertLessThanOrEqual(FolderPath::MAX_LENGTH, strlen($path));
        self::assertGreaterThan(FolderPath::MAX_LENGTH, strlen(FolderPath::build($path, 9_999_999_999)));
    }
}
